<?php
/**
 * tests/smoke.php — PeerConnect smoke test.
 *
 * Run it after any change, from the project root:
 *
 *     php tests/smoke.php                   everything (a few minutes)
 *     php tests/smoke.php --only=flows      just one part: lint | routes | flows
 *     php tests/smoke.php --skip-lint       everything except the syntax pass
 *     php tests/smoke.php -v                also print every route's status
 *     php tests/smoke.php --host=http://localhost
 *
 * Exit code 0 means every check passed, 1 means something failed, 2 means the
 * run could not start (bad option, web server down, no sample accounts).
 *
 * WHAT IT CHECKS
 *   1. Syntax   php -l on every PHP file outside vendor/.
 *   2. Routes   signs in as a mentee, a mentor and an admin and requests every
 *               route. Fails on a server error; on the "Something went wrong
 *               further down this page" banner, which a fatal after output
 *               leaves inside an HTTP 200; on a new PHP line in the error logs;
 *               and when all three roles get identical results, which means the
 *               sign-in silently failed and every page just bounced.
 *   3. Flows    drives four features end to end and checks the database after
 *               each: save a profile, send a message, book a session, submit
 *               feedback. Each also checks the guard that should refuse a bad
 *               request (missing CSRF token, duplicate booking, second review).
 *
 * WHAT IT DELIBERATELY DOES NOT DO
 *   - Sign in through a login form. Both forms carry reCAPTCHA, and that
 *     stays. Instead this script writes PHP session files straight into the
 *     session directory. That needs shell access to this machine, so nothing
 *     new is reachable from a browser — unlike the throwaway login shims that
 *     earlier sweeps had to drop into the web root.
 *   - Touch real accounts. Everything it writes belongs to the @example.com
 *     sample cohort from __seed_sample.php (run that first), plus a
 *     password-less admin it creates for the run and removes afterwards.
 *   - Send email. SMTP on this install is live, so email_enable is switched
 *     off for the run and put back exactly as it was.
 *   - Cover Google sign-in, Google Calendar, video calls or load.
 *
 * CLEANUP
 *   Before anything is written, every table the run can touch is snapshotted:
 *   its highest id, and how many rows the sample cohort owns. Afterwards rows
 *   newer than the snapshot that belong to the cohort are deleted, the profile,
 *   mentor score and email setting are restored, and the counts compared — the
 *   run has to leave the database as it found it. The snapshot is also written
 *   to a state file, so if the process is killed halfway the next run finishes
 *   the cleanup before doing anything else.
 */

if (PHP_SAPI !== 'cli') {
    // .htaccess blocks tests/ as well; this is the second lock.
    http_response_code(404);
    exit;
}

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__);
require $ROOT . '/App/config/db.php';   // $con, plus bootstrap and helpers
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* ── Options ──────────────────────────────────────────────────────────── */

$opts    = getopt('v', ['host:', 'only:', 'skip-lint', 'skip-routes', 'skip-flows']);
$HOST    = rtrim((string)($opts['host'] ?? 'http://localhost'), '/');
$VERBOSE = isset($opts['v']);
$only    = (string)($opts['only'] ?? '');

if ($only !== '' && !in_array($only, ['lint', 'routes', 'flows'], true)) {
    fwrite(STDERR, "--only must be one of: lint, routes, flows\n");
    exit(2);
}
$RUN = [];
foreach (['lint', 'routes', 'flows'] as $part) {
    $RUN[$part] = ($only === '' || $only === $part) && !isset($opts['skip-' . $part]);
}

/* ── Output ───────────────────────────────────────────────────────────── */

$FAILS   = [];
$WARNS   = [];
$STARTED = microtime(true);

function say(string $s = ''): void { echo $s, PHP_EOL; }
function section(string $t): void  { say(); say($t); say(str_repeat('-', strlen($t))); }
function pass(string $m): void     { say("  PASS  $m"); }
function note(string $m): void     { say("        $m"); }
function fail(string $m): void
{
    global $FAILS;
    $FAILS[] = $m;
    say("  FAIL  $m");
}
/**
 * Worth reading, but not a failure of the app — typically something another
 * person using the site at the same time could also have caused.
 */
function warn(string $m): void
{
    global $WARNS;
    $WARNS[] = $m;
    say("  WARN  $m");
}
/** Stops the run before any test has written anything. */
function abort(string $m): never
{
    say();
    say("  CANNOT RUN  $m");
    exit(2);
}

/* ── Database helpers ─────────────────────────────────────────────────── */

/** Runs a prepared statement; returns the result set, or null for a write. */
function db(string $sql, string $types = '', ...$args): ?mysqli_result
{
    global $con;
    $st = $con->prepare($sql);
    if ($types !== '') {
        $st->bind_param($types, ...$args);
    }
    $st->execute();
    $res = $st->get_result();
    $st->close();
    return $res ?: null;
}

function db_one(string $sql, string $types = '', ...$args)
{
    $row = db($sql, $types, ...$args)?->fetch_row();
    return $row[0] ?? null;
}

function db_row(string $sql, string $types = '', ...$args): ?array
{
    return db($sql, $types, ...$args)?->fetch_assoc() ?: null;
}

/** A SQL fragment matching rows owned by any of $ids through any of $cols. */
function owned(array $cols, array $ids): string
{
    $in = implode(',', array_map('intval', $ids));
    return '(' . implode(' OR ', array_map(fn($c) => "$c IN ($in)", $cols)) . ')';
}

/**
 * Read straight from the table. pc_setting() caches settings for the life of
 * the process, so it would keep reporting whatever was true when the run began.
 */
function maintenance_on(): bool
{
    return db_one("SELECT setting_value FROM settings WHERE setting_key = 'maintenance_mode'") === '1';
}

function sql_list(array $strings): string
{
    global $con;
    return implode(',', array_map(fn($s) => "'" . $con->real_escape_string($s) . "'", $strings));
}

/* ── HTTP client ──────────────────────────────────────────────────────── */

/**
 * A cookie-keeping client for one signed-in account.
 *
 * Cookies are handled here rather than in a curl cookie jar. Pages rotate the
 * session id with session_regenerate_id(), and a jar seeded by hand ends up
 * sending two PHPSESSID cookies once the server sets its own. Tracking one
 * value per name avoids that, and every id the client has held is kept so
 * cleanup can delete the session files.
 */
final class Http
{
    /** @var array<string,string> */
    private array $cookies = [];
    /** @var string[] */
    public array $sessionIds = [];

    public function __construct(private string $host, string $sessionId = '')
    {
        if ($sessionId !== '') {
            $this->cookies['PHPSESSID'] = $sessionId;
            $this->sessionIds[] = $sessionId;
        }
    }

    public function get(string $path): array
    {
        return $this->send('GET', $path);
    }

    public function post(string $path, array $fields): array
    {
        return $this->send('POST', $path, http_build_query($fields), 'application/x-www-form-urlencoded');
    }

    public function postJson(string $path, array $data): array
    {
        return $this->send('POST', $path, json_encode($data), 'application/json');
    }

    private function send(string $method, string $path, ?string $body = null, string $type = ''): array
    {
        $raw = [];
        $headers = ['Expect:'];
        if ($this->cookies) {
            $pairs = [];
            foreach ($this->cookies as $k => $v) {
                $pairs[] = "$k=$v";
            }
            $headers[] = 'Cookie: ' . implode('; ', $pairs);
        }

        $ch = curl_init($this->host . $path);
        $opt = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_USERAGENT      => 'PeerConnect-smoke-test',
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$raw): int {
                $raw[] = $line;
                return strlen($line);
            },
        ];
        if ($method === 'POST') {
            $opt[CURLOPT_POST]       = true;
            $opt[CURLOPT_POSTFIELDS] = $body ?? '';
            $headers[] = 'Content-Type: ' . $type;
        }
        $opt[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opt);

        $out  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);

        $location = '';
        foreach ($raw as $line) {
            if (stripos($line, 'Location:') === 0) {
                $location = trim(substr($line, 9));
            }
            if (stripos($line, 'Set-Cookie:') !== 0) {
                continue;
            }
            $parts = array_map('trim', explode(';', trim(substr($line, 11))));
            [$name, $value] = array_pad(explode('=', (string)array_shift($parts), 2), 2, '');
            $gone = $value === '' || $value === 'deleted';
            foreach ($parts as $p) {
                if (stripos($p, 'max-age=') === 0 && (int)substr($p, 8) <= 0) {
                    $gone = true;
                }
            }
            if ($gone) {
                unset($this->cookies[$name]);
                continue;
            }
            $this->cookies[$name] = $value;
            if ($name === 'PHPSESSID' && !in_array($value, $this->sessionIds, true)) {
                $this->sessionIds[] = $value;
            }
        }

        return ['status' => $code, 'body' => (string)$out, 'location' => $location, 'error' => $err];
    }
}

function json_of(array $response): array
{
    $j = json_decode($response['body'], true);
    return is_array($j) ? $j : [];
}

/** Server response trimmed for a failure message. */
function excerpt(array $response): string
{
    $b = trim(preg_replace('/\s+/', ' ', strip_tags($response['body'])));
    return 'HTTP ' . $response['status'] . ($b !== '' ? ': ' . mb_substr($b, 0, 160) : '');
}

/* ── Sessions ─────────────────────────────────────────────────────────── */

function session_dir(): string
{
    $p = (string)ini_get('session.save_path');
    if (str_contains($p, ';')) {
        $p = substr($p, strrpos($p, ';') + 1);   // "N;MODE;/path" form
    }
    return $p !== '' ? $p : sys_get_temp_dir();
}

/**
 * Writes a session file that the web server accepts as a signed-in visitor.
 *
 * The contents use PHP's 'php' serialize handler — key|serialized-value, back
 * to back — and session_create_id() reads the same php.ini as Apache, so the id
 * has the length and alphabet that strict mode checks for.
 *
 * session_start() is avoided on purpose. From the command line it runs PHP's
 * session garbage collector against the CLI's own lifetime of 24 minutes, not
 * the app's 20 days, and one unlucky roll would delete every real visitor's
 * session file older than that — signing people out of the live app.
 */
function make_session(array $data): string
{
    $id = session_create_id();
    $payload = '';
    foreach ($data as $k => $v) {
        $payload .= $k . '|' . serialize($v);
    }
    $file = session_dir() . DIRECTORY_SEPARATOR . 'sess_' . $id;
    if (file_put_contents($file, $payload, LOCK_EX) === false) {
        throw new RuntimeException("Could not write session file $file");
    }
    return $id;
}

/** A signed-in client for $account, registered for cleanup. */
function sign_in(array $account): array
{
    global $HOST, $STATE;
    $csrf = bin2hex(random_bytes(32));
    $sid  = make_session([
        'user_id'    => (int)$account['user_id'],
        'role'       => $account['role'],
        'email'      => $account['email'],
        'firstname'  => $account['firstname'],
        'csrf_token' => $csrf,
        // Copied along when a page rotates the session id, so cleanup can
        // find the rotated files too — including after a killed run, whose
        // state file only knows the ids it started with.
        'smoke_test' => true,
    ]);
    $client = new Http($HOST, $sid);
    $STATE['clients'][] = $client;
    return [$client, $csrf];
}

/* ── Snapshot, cleanup and recovery ───────────────────────────────────── */

/**
 * Every table the run can write to: its auto-increment key and the columns
 * tying a row to an account. Listed children first, which is the delete order
 * the foreign keys need (feedback points at session_requests).
 */
const TRACKED = [
    'feedback'          => ['feedback_id',     ['mentee_id', 'mentor_id']],
    'mentee_reviews'    => ['review_id',       ['mentee_id', 'mentor_id']],
    'feedback_drafts'   => ['draft_id',        ['author_id']],
    'messages'          => ['id',              ['sender_id', 'receiver_id']],
    'notifications'     => ['notification_id', ['user_id']],
    'user_badges'       => ['user_badge_id',   ['user_id']],
    'user_certificates' => ['cert_id',         ['user_id']],
    'session_attendance'=> ['attendance_id',   ['user_id']],
    'session_requests'  => ['request_id',      ['mentee_id', 'mentor_id']],
    'availability'      => ['availability_id', ['mentor_id']],
];

const ADMIN_FIXTURE_EMAIL = 'smoke.admin@example.com';
const MARK = '[smoke-test]';

/** Throttle buckets are "<key>_<user id>|<address>". */
function throttle_pattern(array $ids): string
{
    return '_(' . implode('|', array_map('intval', $ids)) . ')[|]';
}

function snapshot(array $ids, array $emails): array
{
    $s = ['max' => [], 'count' => []];
    foreach (TRACKED as $t => [$pk, $cols]) {
        $s['max'][$t]   = (int)db_one("SELECT COALESCE(MAX($pk), 0) FROM $t");
        $s['count'][$t] = (int)db_one("SELECT COUNT(*) FROM $t WHERE " . owned($cols, $ids));
    }
    $s['max']['logs']            = (int)db_one("SELECT COALESCE(MAX(log_id), 0) FROM logs");
    $s['count']['logs']          = (int)db_one("SELECT COUNT(*) FROM logs WHERE email IN (" . sql_list($emails) . ")");
    $s['max']['auth_throttle']   = (int)db_one("SELECT COALESCE(MAX(throttle_id), 0) FROM auth_throttle");
    $s['count']['auth_throttle'] = (int)db_one("SELECT COUNT(*) FROM auth_throttle WHERE bucket REGEXP ?", 's', throttle_pattern($ids));
    return $s;
}

/** Every mentor_scores row, keyed by mentor. */
function all_scores(): array
{
    $rows = [];
    foreach (db("SELECT * FROM mentor_scores")->fetch_all(MYSQLI_ASSOC) as $r) {
        $rows[(string)$r['mentor_id']] = $r;
    }
    return $rows;
}

/**
 * A row checksum for every table in the database.
 *
 * The tracked-table counts above only see what this script knows it writes.
 * This catches the rest: the first version of this test left five real
 * mentors' score timestamps changed, because opening the leaderboard refreshes
 * stale scores, and only a full database diff noticed.
 */
function table_checksums(): array
{
    global $con;
    $tables = array_column(db("SELECT TABLE_NAME FROM information_schema.TABLES
                               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'")->fetch_all(), 0);
    $sums = [];
    $res  = $con->query('CHECKSUM TABLE `' . implode('`, `', $tables) . '`');
    while ($r = $res->fetch_row()) {
        $sums[substr($r[0], strrpos($r[0], '.') + 1)] = (string)$r[1];
    }
    return $sums;
}

function save_state(): void
{
    global $STATE, $STATE_FILE;
    $copy = $STATE;
    $copy['sessions'] = all_session_ids();
    unset($copy['clients']);
    file_put_contents($STATE_FILE, json_encode($copy, JSON_PRETTY_PRINT));
}

function all_session_ids(): array
{
    global $STATE;
    $ids = $STATE['sessions'] ?? [];
    foreach ($STATE['clients'] ?? [] as $c) {
        $ids = array_merge($ids, $c->sessionIds);
    }
    return array_values(array_unique($ids));
}

/**
 * Puts the database back the way the snapshot found it. Safe to run twice,
 * and safe to run from a previous, interrupted run's state file.
 */
function cleanup(array $st): void
{
    $ids    = $st['ids'];
    $emails = $st['emails'];
    $snap   = $st['snap'];

    foreach (TRACKED as $t => [$pk, $cols]) {
        db("DELETE FROM $t WHERE $pk > ? AND " . owned($cols, $ids), 'i', $snap['max'][$t]);
    }
    db("DELETE FROM logs WHERE log_id > ? AND email IN (" . sql_list($emails) . ")", 'i', $snap['max']['logs']);
    db("DELETE FROM auth_throttle WHERE throttle_id > ? AND bucket REGEXP ?", 'is', $snap['max']['auth_throttle'], throttle_pattern($ids));

    if (!empty($st['profile'])) {
        $p = $st['profile'];
        db("UPDATE profile SET full_name = ?, student_id = ?, course = ?, year_level = ?, club = ? WHERE user_id = ?",
            'sssssi', $p['full_name'], $p['student_id'], $p['course'], $p['year_level'], $p['club'], $p['user_id']);
    }

    if (!empty($st['mentor_id'])) {
        if ($st['score'] === null) {
            db("DELETE FROM mentor_scores WHERE mentor_id = ?", 'i', $st['mentor_id']);
        } else {
            $cols = array_diff(array_keys($st['score']), ['score_id', 'mentor_id']);
            $set  = implode(', ', array_map(fn($c) => "`$c` = ?", $cols));
            $vals = array_map(fn($c) => $st['score'][$c], $cols);
            db("UPDATE mentor_scores SET $set WHERE mentor_id = ?", str_repeat('s', count($vals)) . 'i', ...[...$vals, $st['mentor_id']]);
        }
    }

    // Opening the leaderboard refreshes every mentor's score once the newest is
    // over an hour old. That is normal for any visitor, but here it is the
    // sweep that opened it, so real mentors' rows get rewritten. Where only the
    // timestamp moved, put it back. Where the values changed, keep them: the
    // stored score was stale, and restoring it would bring back a wrong number.
    foreach ($st['scores_all'] ?? [] as $mid => $old) {
        if ((int)$mid === (int)($st['mentor_id'] ?? 0)) {
            continue;
        }
        $now = db_row("SELECT * FROM mentor_scores WHERE mentor_id = ?", 'i', (int)$mid);
        if (!$now) {
            continue;
        }
        $a = $old;
        $b = $now;
        unset($a['last_calculated'], $b['last_calculated']);
        if ($a == $b && $now['last_calculated'] !== $old['last_calculated']) {
            db("UPDATE mentor_scores SET last_calculated = ? WHERE mentor_id = ?", 'si', $old['last_calculated'], (int)$mid);
        }
    }

    if (!empty($st['admin_id'])) {
        db("DELETE FROM users WHERE user_id = ? AND email = ?", 'is', $st['admin_id'], ADMIN_FIXTURE_EMAIL);
    }

    foreach ($st['sessions'] ?? [] as $sid) {
        if (preg_match('/^[A-Za-z0-9,-]+$/', $sid)) {
            @unlink(session_dir() . DIRECTORY_SEPARATOR . 'sess_' . $sid);
        }
    }
    foreach (glob(session_dir() . DIRECTORY_SEPARATOR . 'sess_*') ?: [] as $file) {
        $head = @file_get_contents($file, false, null, 0, 4096);
        if ($head !== false && str_contains($head, 'smoke_test|b:1;')) {
            @unlink($file);
        }
    }

    if (isset($st['email_setting'])) {
        $e = $st['email_setting'];
        db("UPDATE settings SET setting_value = ?, updated_by = ?, updated_at = ? WHERE setting_key = 'email_enable'",
            'sss', $e['setting_value'], $e['updated_by'], $e['updated_at']);
    }
}

$STATE_FILE = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'peerconnect-smoke-state.json';
$STATE      = null;
$CLEANED    = false;

say('PeerConnect smoke test');
say('Host ' . $HOST . '   Started ' . date('Y-m-d H:i:s'));

/* ── 0. Preflight ─────────────────────────────────────────────────────── */

section('Preflight');

if (is_file($STATE_FILE)) {
    $old = json_decode((string)file_get_contents($STATE_FILE), true);
    if (is_array($old) && isset($old['snap'])) {
        note('A previous run did not finish. Completing its cleanup first.');
        cleanup($old);
    }
    @unlink($STATE_FILE);
    pass('Recovered from an interrupted run');
}

// A fixture admin with no state file means a run died in the instant between
// creating it and recording it. It owns nothing yet, so it simply goes.
$stray = db_one("SELECT user_id FROM users WHERE email = ?", 's', ADMIN_FIXTURE_EMAIL);
if ($stray) {
    db("DELETE FROM notifications WHERE user_id = ?", 'i', $stray);
    db("DELETE FROM users WHERE user_id = ?", 'i', $stray);
    note('Removed a leftover fixture admin from an earlier run.');
}

if (!function_exists('curl_init')) {
    abort('the PHP curl extension is not enabled.');
}

$probe = (new Http($HOST))->get(url('welcomepage'));
if ($probe['error'] !== '' || $probe['status'] === 0) {
    abort("the web server at $HOST did not answer ({$probe['error']}). Is Apache running?");
}
pass("Web server answers at $HOST (HTTP {$probe['status']})");

if (maintenance_on()) {
    abort('maintenance mode is on, so every member page would return 503. Turn it off first.');
}
pass('Maintenance mode is off');

if (!is_writable(session_dir())) {
    abort('the session directory ' . session_dir() . ' is not writable.');
}
pass('Session directory is writable: ' . session_dir());

$mentee = db_row("
    SELECT u.user_id, u.role, u.email, u.firstname, p.full_name, p.student_id, p.course, p.year_level, p.club
    FROM users u JOIN profile p ON p.user_id = u.user_id
    WHERE u.email LIKE '%@example.com' AND u.role = 'mentee' AND u.status = 'active'
      AND p.full_name <> '' AND p.student_id <> '' AND p.course <> '' AND p.year_level <> ''
    ORDER BY u.user_id LIMIT 1");
$mentor = db_row("
    SELECT user_id, role, email, firstname FROM users
    WHERE email LIKE '%@example.com' AND role = 'mentor' AND status = 'active' AND verified = 1
    ORDER BY user_id LIMIT 1");
if (!$mentee || !$mentor) {
    abort('no sample accounts found. Create them with:  php __seed_sample.php');
}
pass("Sample mentee #{$mentee['user_id']} ({$mentee['email']}), mentor #{$mentor['user_id']} ({$mentor['email']})");

/* ── 1. Syntax ────────────────────────────────────────────────────────── */

if ($RUN['lint']) {
    section('1. Syntax');
    $files = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($ROOT, FilesystemIterator::SKIP_DOTS),
        function (SplFileInfo $f, $key, RecursiveDirectoryIterator $it): bool {
            if ($it->hasChildren()) {
                return !in_array($f->getFilename(), ['vendor', '.git', 'node_modules', 'uploads'], true);
            }
            return strtolower($f->getExtension()) === 'php';
        }
    ));
    $checked = 0;
    $broken  = 0;
    foreach ($files as $f) {
        $checked++;
        $out = [];
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($f->getPathname()) . ' 2>&1', $out, $code);
        if ($code !== 0) {
            $broken++;
            // php -l prints the error twice (log line, then display); one is enough.
            $first = preg_replace('/^PHP\s+/', '', trim($out[0] ?? 'unknown error'));
            $first = preg_replace('/ in \S+ on line (\d+)$/', ' (line $1)', $first);
            fail('Syntax error in ' . substr($f->getPathname(), strlen($ROOT) + 1) . ': ' . $first);
        }
    }
    if ($broken === 0) {
        pass("All $checked PHP files parse");
    }
}

if (!$RUN['routes'] && !$RUN['flows']) {
    goto summary;
}

/* ── Set-up for everything that makes requests ────────────────────────── */

// Taken before the fixture admin exists, so removing it later reads as no change.
$checksumsBefore = table_checksums();

db("INSERT INTO users (firstname, lastname, role, status, verified, email) VALUES ('Smoke', 'Test Admin', 'admin', 'active', 1, ?)",
    's', ADMIN_FIXTURE_EMAIL);
$admin = [
    'user_id'   => (int)$con->insert_id,
    'role'      => 'admin',
    'email'     => ADMIN_FIXTURE_EMAIL,
    'firstname' => 'Smoke',
];

$ids    = [(int)$mentee['user_id'], (int)$mentor['user_id'], $admin['user_id']];
$emails = [$mentee['email'], $mentor['email'], ADMIN_FIXTURE_EMAIL];

$STATE = [
    'ids'           => $ids,
    'emails'        => $emails,
    'admin_id'      => $admin['user_id'],
    'mentor_id'     => (int)$mentor['user_id'],
    'snap'          => snapshot($ids, $emails),
    'profile'       => [
        'user_id'    => (int)$mentee['user_id'],
        'full_name'  => $mentee['full_name'],
        'student_id' => $mentee['student_id'],
        'course'     => $mentee['course'],
        'year_level' => $mentee['year_level'],
        'club'       => $mentee['club'],
    ],
    'score'         => db_row("SELECT * FROM mentor_scores WHERE mentor_id = ?", 'i', $mentor['user_id']),
    'scores_all'    => all_scores(),
    'checksums'     => $checksumsBefore,
    'email_setting' => db_row("SELECT setting_value, updated_by, updated_at FROM settings WHERE setting_key = 'email_enable'"),
    'clients'       => [],
    'sessions'      => [],
];
save_state();

// Whatever happens from here — a failed check, an exception, a fatal — the
// database is put back and email is switched on again if it was on before.
register_shutdown_function(function (): void {
    global $STATE, $CLEANED, $STATE_FILE;
    if ($STATE === null || $CLEANED) {
        return;
    }
    $STATE['sessions'] = all_session_ids();
    cleanup($STATE);
    @unlink($STATE_FILE);
    say();
    say('  Cleanup ran after an early exit. The database has been restored.');
});

db("UPDATE settings SET setting_value = '0' WHERE setting_key = 'email_enable'");
note('Email sending switched off for the run (was ' . ($STATE['email_setting']['setting_value'] ?? 'unset') . ').');

// Where PHP errors land: the log php.ini names, and Apache's error log, which
// is where they go when that file cannot be written.
$logFiles = array_filter([
    (string)ini_get('error_log'),
    dirname(PHP_BINARY, 2) . DIRECTORY_SEPARATOR . 'apache' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'error.log',
]);
$logStart = [];
foreach ($logFiles as $lf) {
    clearstatcache(true, $lf);
    $logStart[$lf] = is_file($lf) ? filesize($lf) : 0;
}

const SHUTDOWN_BANNER = 'Something went wrong further down this page';

/* ── 2. Routes ────────────────────────────────────────────────────────── */

if ($RUN['routes']) {
    section('2. Routes');

    $routes = require $ROOT . '/routes.php';
    unset($routes['logout']);   // would end the session partway through

    $failsBefore = count($FAILS);
    $accounts = ['mentee' => $mentee, 'mentor' => $mentor, 'admin' => $admin];
    $home     = ['mentee' => 'mentee-dashboard', 'mentor' => 'mentor-dashboard', 'admin' => 'admin-dashboard'];
    $tallies  = [];

    foreach ($accounts as $role => $acct) {
        [$client] = sign_in($acct);
        save_state();

        $first = $client->get(url($home[$role]));
        if ($first['status'] !== 200) {
            fail("$role sign-in was not accepted: " . url($home[$role]) . ' returned ' . excerpt($first));
            continue;
        }

        $t = ['2xx' => 0, '3xx' => 0, '4xx' => 0, '5xx' => 0];
        foreach ($routes as $name => $file) {
            $r    = $client->get(url($name));
            $band = $r['status'] >= 500 ? '5xx' : ($r['status'] >= 400 ? '4xx' : ($r['status'] >= 300 ? '3xx' : '2xx'));
            $t[$band]++;

            if ($VERBOSE) {
                note(sprintf('%-7s %3d  %s', $role, $r['status'], $name));
            }
            if ($r['status'] === 0) {
                fail("$role  $name: no response ({$r['error']})");
            } elseif ($r['status'] >= 500) {
                if ($r['status'] === 503 && maintenance_on()) {
                    fail('Maintenance mode was switched on during the run, so the route results are not meaningful. Run again.');
                    break 2;
                }
                fail("$role  $name  ($file): " . excerpt($r));
            } elseif (str_contains($r['body'], SHUTDOWN_BANNER)) {
                fail("$role  $name  ($file): a fatal error cut the page short (HTTP {$r['status']})");
            }
        }
        $tallies[$role] = $t;
        say(sprintf('  %-6s  2xx %3d   3xx %3d   4xx %3d   5xx %3d', $role, $t['2xx'], $t['3xx'], $t['4xx'], $t['5xx']));
    }

    if (count($tallies) === 3) {
        if (count(array_unique(array_map('serialize', $tallies))) === 1) {
            fail('All three roles got identical results. That means the sessions were not recognised and every page bounced.');
        } elseif (count($FAILS) === $failsBefore) {
            pass(count($routes) . ' routes x 3 roles, no server errors, results differ by role as they should');
        }
    }
}

/* ── 3. Flows ─────────────────────────────────────────────────────────── */

if ($RUN['flows']) {
    section('3. Flows');

    [$asMentee, $csrf] = sign_in($mentee);
    save_state();
    $menteeId = (int)$mentee['user_id'];
    $mentorId = (int)$mentor['user_id'];
    $snap     = $STATE['snap']['max'];

    /* Profile ─ write a marker, read it back, put the original back. */
    try {
        $fields = [
            'full_name'  => $mentee['full_name'],
            'student_id' => $mentee['student_id'],
            'course'     => $mentee['course'],
            'year_level' => $mentee['year_level'],
        ];
        $path = url('mentee-update-profile');

        $r = $asMentee->post($path, $fields + ['club' => MARK . ' club']);   // no token
        $club = db_one("SELECT club FROM profile WHERE user_id = ?", 'i', $menteeId);
        if ($r['status'] === 403 && $club === $mentee['club']) {
            pass('Profile: a save without a CSRF token is refused and changes nothing');
        } else {
            fail('Profile: a save without a CSRF token was not refused (' . excerpt($r) . ')');
        }

        $r = $asMentee->post($path, $fields + ['club' => MARK . ' club', 'csrf_token' => $csrf]);
        $club = db_one("SELECT club FROM profile WHERE user_id = ?", 'i', $menteeId);
        if ((json_of($r)['success'] ?? false) === true && $club === MARK . ' club') {
            $r = $asMentee->post($path, $fields + ['club' => (string)$mentee['club'], 'csrf_token' => $csrf]);
            $club = db_one("SELECT club FROM profile WHERE user_id = ?", 'i', $menteeId);
            $rows = (int)db_one("SELECT COUNT(*) FROM profile WHERE user_id = ?", 'i', $menteeId);
            if ((json_of($r)['success'] ?? false) === true && $club === $mentee['club'] && $rows === 1) {
                pass('Profile: saved, read back from the database, and saved back to the original');
            } else {
                fail("Profile: restoring the original value failed (club now '$club', $rows profile rows)");
            }
        } else {
            fail('Profile: save did not reach the database (' . excerpt($r) . ')');
        }
    } catch (Throwable $e) {
        fail('Profile: ' . $e->getMessage());
    }

    /* Message ─ mentee writes to mentor; it is stored, fetchable, notified. */
    try {
        $text = MARK . ' message ' . bin2hex(random_bytes(4));
        $r    = $asMentee->post(url('messages-send'), ['receiver_id' => $mentorId, 'message' => $text, 'csrf_token' => $csrf]);
        $j    = json_of($r);
        $msg  = !empty($j['message_id'])
            ? db_row("SELECT sender_id, receiver_id, content FROM messages WHERE id = ?", 'i', (int)$j['message_id'])
            : null;

        if (($j['success'] ?? false) === true && $msg
            && (int)$msg['sender_id'] === $menteeId && (int)$msg['receiver_id'] === $mentorId && $msg['content'] === $text) {
            pass("Message: sent and stored as message #{$j['message_id']}");

            // From just before this message, as the page's own polling asks:
            // the endpoint returns at most 50, oldest first.
            $thread   = json_of($asMentee->get(url('messages-get') . '?chat_id=' . $mentorId . '&last_id=' . ((int)$j['message_id'] - 1)));
            $inThread = in_array($text, array_column($thread['messages'] ?? [], 'content'), true);
            $inThread ? pass('Message: returned when the thread is fetched') : fail('Message: not returned by messages-get');

            $notified = (int)db_one("SELECT COUNT(*) FROM notifications WHERE notification_id > ? AND user_id = ?", 'ii', $snap['notifications'], $mentorId);
            $notified > 0 ? pass('Message: the mentor was notified') : fail('Message: no notification reached the mentor');
        } else {
            fail('Message: send failed (' . excerpt($r) . ')');
        }
    } catch (Throwable $e) {
        fail('Message: ' . $e->getMessage());
    }

    /* Booking ─ a fixture slot well in the future, booked once, then refused. */
    try {
        $subject = 'Smoke test booking';
        $start   = '05:00:00';

        // A day the mentee has nothing on, so the clash guard stays out of it.
        $day = null;
        for ($offset = 45; $offset < 60 && $day === null; $offset++) {
            $d = db_one("SELECT DATE(NOW() + INTERVAL ? DAY)", 'i', $offset);
            $busy = (int)db_one("SELECT COUNT(*) FROM session_requests WHERE mentee_id = ? AND DATE(session_date) = ? AND status IN ('pending','approved')", 'is', $menteeId, $d);
            if ($busy === 0) {
                $day = $d;
            }
        }
        if ($day === null) {
            throw new RuntimeException('could not find a free day for the sample mentee in the next two months');
        }

        db("INSERT INTO availability (mentor_id, subject, date, start_time, duration, capacity, session_type, about)
            VALUES (?, ?, ?, ?, 30, 1, '1v1', ?)", 'issss', $mentorId, $subject, $day, $start, MARK . ' fixture slot');

        $booking = [
            'csrf_token'   => $csrf,
            'mentor_id'    => $mentorId,
            'subject'      => $subject,
            'session_type' => '1v1',
            'date'         => $day,
            'time'         => substr($start, 0, 5),
            'message'      => MARK . ' booking',
        ];
        $r = $asMentee->postJson(url('save-booking'), $booking);
        $request = db_row("SELECT request_id, status FROM session_requests
                           WHERE request_id > ? AND mentee_id = ? AND mentor_id = ? AND subject = ? AND session_date = ?",
                          'iiiss', $snap['session_requests'], $menteeId, $mentorId, $subject, "$day $start");

        if ((json_of($r)['success'] ?? false) === true && $request && $request['status'] === 'pending') {
            pass("Booking: session #{$request['request_id']} created as pending for $day");

            $notified = (int)db_one("SELECT COUNT(*) FROM notifications WHERE notification_id > ? AND user_id = ? AND type = 'session_requested'", 'ii', $snap['notifications'], $mentorId);
            $notified > 0 ? pass('Booking: the mentor was notified') : fail('Booking: no session_requested notification for the mentor');

            $again = json_of($asMentee->postJson(url('save-booking'), $booking));
            $count = (int)db_one("SELECT COUNT(*) FROM session_requests WHERE request_id > ? AND mentee_id = ? AND subject = ?", 'iis', $snap['session_requests'], $menteeId, $subject);
            if (!empty($again['error']) && $count === 1) {
                pass('Booking: booking the same slot twice is refused ("' . $again['error'] . '")');
            } else {
                fail("Booking: a duplicate booking was not refused ($count requests exist)");
            }
        } else {
            fail('Booking: request not created (' . excerpt($r) . ')');
        }
    } catch (Throwable $e) {
        fail('Booking: ' . $e->getMessage());
    }

    /* Feedback ─ a finished, approved fixture session, reviewed once. */
    try {
        $subject = 'Smoke test feedback';
        db("INSERT INTO session_requests (mentee_id, mentor_id, subject, message, session_date, status)
            VALUES (?, ?, ?, ?, NOW() - INTERVAL 2 DAY, 'approved')", 'iiss', $menteeId, $mentorId, $subject, MARK . ' fixture session');
        $sessionId = (int)$con->insert_id;

        $review = [
            'csrf_token'    => $csrf,
            'session_id'    => $sessionId,
            'communication' => 4,
            'efficiency'    => 4,
            'knowledge'     => 5,
            'skill'         => 4,
            'rating'        => 5,
            'comment'       => MARK . ' feedback',
        ];
        $r = $asMentee->post(url('feedback-save'), $review);
        $fb = db_row("SELECT rating FROM feedback WHERE session_id = ? AND mentee_id = ?", 'ii', $sessionId, $menteeId);
        $sr = db_row("SELECT status, completed_at FROM session_requests WHERE request_id = ?", 'i', $sessionId);

        if ($r['status'] === 302 && $fb && (int)$fb['rating'] === 5) {
            pass("Feedback: review stored for session #$sessionId");

            ($sr['status'] === 'completed' && $sr['completed_at'] !== null)
                ? pass('Feedback: the session moved from approved to completed, with a completion time')
                : fail("Feedback: the session is '{$sr['status']}', expected completed with completed_at set");

            db_row("SELECT score_id FROM mentor_scores WHERE mentor_id = ?", 'i', $mentorId)
                ? pass("Feedback: the mentor's score was recalculated")
                : fail('Feedback: no mentor_scores row after the review');

            $asMentee->post(url('feedback-save'), $review);
            $reviews = (int)db_one("SELECT COUNT(*) FROM feedback WHERE session_id = ?", 'i', $sessionId);
            $reviews === 1
                ? pass('Feedback: a second review of the same session is refused')
                : fail("Feedback: the session now has $reviews reviews");
        } else {
            fail('Feedback: review not stored (' . excerpt($r) . ($r['location'] ? ' -> ' . $r['location'] : '') . ')');
        }
    } catch (Throwable $e) {
        fail('Feedback: ' . $e->getMessage());
    }

    // SMTP is live. Nothing written during the run may have been emailed.
    $sent = (int)db_one("SELECT COUNT(*) FROM notifications WHERE notification_id > ? AND " . owned(['user_id'], $ids) . " AND email_status = 'sent'", 'i', $snap['notifications']);
    $sent === 0
        ? pass('No notification created during the run was emailed')
        : fail("$sent notification(s) created during the run were emailed despite email being switched off");
}

/* ── Error logs ───────────────────────────────────────────────────────── */

section('Error logs');
$newErrors = [];
foreach ($logStart as $lf => $size) {
    clearstatcache(true, $lf);
    if (!is_file($lf) || filesize($lf) <= $size) {
        continue;
    }
    $fh = fopen($lf, 'rb');
    fseek($fh, $size);
    while (($line = fgets($fh)) !== false) {
        if (preg_match('/PHP (Fatal|Parse|Warning|Notice|Deprecated|Recoverable)/', $line)) {
            $newErrors[] = trim($line);
        }
    }
    fclose($fh);
}
if ($newErrors) {
    fail(count($newErrors) . ' new PHP error line(s) while the run was going. Someone else using the app at the same time can also cause these.');
    foreach (array_slice($newErrors, 0, 10) as $line) {
        note(mb_substr($line, 0, 220));
    }
} else {
    pass('No new PHP errors in ' . implode(' or ', array_keys($logStart)));
}

/* ── Cleanup ──────────────────────────────────────────────────────────── */

section('Cleanup');
$STATE['sessions'] = all_session_ids();
cleanup($STATE);
$CLEANED = true;
@unlink($STATE_FILE);

$after = snapshot($STATE['ids'], $STATE['emails']);
$drift = [];
foreach ($STATE['snap']['count'] as $table => $before) {
    if ($after['count'][$table] !== $before) {
        $drift[] = "$table {$before} -> {$after['count'][$table]}";
    }
}
if ($drift) {
    fail('Rows left behind for the sample accounts: ' . implode(', ', $drift));
} else {
    pass('Sample-account rows in every tracked table match the snapshot');
}

$p = $STATE['profile'];
$nowProfile = db_row("SELECT full_name, student_id, course, year_level, club FROM profile WHERE user_id = ?", 'i', $p['user_id']);
unset($p['user_id']);
$nowProfile == $p ? pass('Profile restored') : fail('Profile differs from before the run');

$nowScore = db_row("SELECT * FROM mentor_scores WHERE mentor_id = ?", 'i', $STATE['mentor_id']);
$nowScore == $STATE['score'] ? pass('Mentor score restored') : fail('Mentor score differs from before the run');

$refreshed = [];
foreach ($STATE['scores_all'] as $mid => $old) {
    if (db_row("SELECT * FROM mentor_scores WHERE mentor_id = ?", 'i', (int)$mid) != $old) {
        $refreshed[] = '#' . $mid;
    }
}
$added = count(all_scores()) - count($STATE['scores_all']);
if ($refreshed || $added > 0) {
    warn('Mentor scores changed for ' . ($refreshed ? implode(', ', $refreshed) : '')
        . ($added > 0 ? ($refreshed ? ' and ' : '') . "$added new mentor(s)" : '')
        . '. The leaderboard recalculates stale scores when it is opened, for any visitor; the new values were kept.');
} else {
    pass('Every mentor score row matches the snapshot');
}

$changedTables = array_keys(array_diff_assoc($STATE['checksums'], table_checksums()));
if ($refreshed || $added > 0) {
    $changedTables = array_values(array_diff($changedTables, ['mentor_scores']));
}
$changedTables
    ? warn('Contents changed during the run in: ' . implode(', ', $changedTables)
        . '. Either someone else was using the app, or a page writes something this test does not know to undo.')
    : pass('Every table matches its checksum from before the run');

db_one("SELECT COUNT(*) FROM users WHERE email = ?", 's', ADMIN_FIXTURE_EMAIL) == 0
    ? pass('Fixture admin removed')
    : fail('Fixture admin is still in the users table');

$left = array_filter(glob(session_dir() . DIRECTORY_SEPARATOR . 'sess_*') ?: [],
    fn($f) => str_contains((string)@file_get_contents($f, false, null, 0, 4096), 'smoke_test|b:1;'));
$left ? fail(count($left) . ' session file(s) could not be deleted') : pass('Session files deleted');

$email = db_one("SELECT setting_value FROM settings WHERE setting_key = 'email_enable'");
$email === ($STATE['email_setting']['setting_value'] ?? null)
    ? pass("Email sending restored (email_enable = $email)")
    : fail("email_enable is '$email', expected '" . ($STATE['email_setting']['setting_value'] ?? '') . "'");

/* ── Summary ──────────────────────────────────────────────────────────── */

summary:
$secs = round(microtime(true) - $STARTED);
say();
if ($FAILS) {
    say(sprintf('FAILED  %d problem(s) in %ds', count($FAILS), $secs));
    foreach ($FAILS as $f) {
        say('  - ' . $f);
    }
    exit(1);
}
say($WARNS ? sprintf('PASSED  in %ds, with %d warning(s) above', $secs, count($WARNS)) : "PASSED  in {$secs}s");
exit(0);
