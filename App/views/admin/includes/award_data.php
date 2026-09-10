<?php

/**
 * award_data.php — the vocabulary Badges and Certificates share.
 *
 * Both screens hand out recognition, both are filtered and paged the same way,
 * and both need the same small set of icons and colours. Defining that once
 * here keeps a badge's amber on the Badges page the same amber it is in the
 * award list on the Certificates page.
 *
 * The certificate renderer lives here too, because a certificate has to look
 * identical in three places: the thumbnail on the template card, the preview in
 * the issue dialog, and the full page the recipient opens and prints. One
 * function draws all three at different sizes.
 */

if (defined('AD_AWARD_DATA')) return;
define('AD_AWARD_DATA', true);

/* ─────────────────────────────── Badges ─────────────────────────────── */

/** The icons a badge can be given, as line art. */
function aw_badge_icons(): array
{
    return [
        'medal'   => 'Medal',
        'star'    => 'Star',
        'crown'   => 'Crown',
        'rosette' => 'Rosette',
        'trend'   => 'Rising',
        'people'  => 'Community',
        'chat'    => 'Feedback',
        'book'    => 'Knowledge',
        'target'  => 'Goal',
        'spark'   => 'Spark',
        'shield'  => 'Shield',
        'heart'   => 'Heart',
    ];
}

/** name => [tint, ink] — a soft ground and a legible foreground on it. */
function aw_colors(): array
{
    return [
        'amber'  => ['#FEF3D6', '#8A6400'],
        'blue'   => ['#E4EEFB', '#1A5C9A'],
        'green'  => ['#E1F3EA', '#17654B'],
        'violet' => ['#EEE9F8', '#5A3E96'],
        'teal'   => ['#DFF1F3', '#0E6C77'],
        'rose'   => ['#FBE4E7', '#9B2C43'],
        'slate'  => ['#EDEFF3', '#414A5C'],
        'orange' => ['#FCE9DC', '#9A4A16'],
    ];
}

/** A colour pair, falling back rather than throwing on an unknown name. */
function aw_color(string $name): array
{
    $all = aw_colors();
    return $all[$name] ?? $all['slate'];
}

/** One badge icon, drawn at whatever size the caller's CSS says. */
function aw_icon(string $key): string
{
    $paths = [
        'medal'   => '<circle cx="12" cy="9" r="5.5"/><path stroke-linecap="round" stroke-linejoin="round" d="m8.5 13.5-1 7 4.5-2.4 4.5 2.4-1-7"/>',
        'star'    => '<path stroke-linejoin="round" d="m12 3.6 2.6 5.3 5.9.9-4.3 4.1 1 5.8-5.2-2.7-5.2 2.7 1-5.8-4.3-4.1 5.9-.9L12 3.6Z"/>',
        'crown'   => '<path stroke-linecap="round" stroke-linejoin="round" d="M4 17.5h16M4.5 6.5l3.6 3.4L12 4.5l3.9 5.4 3.6-3.4-1.3 8.5H5.8L4.5 6.5Z"/>',
        'rosette' => '<circle cx="12" cy="9" r="5.5"/><path stroke-linecap="round" stroke-linejoin="round" d="M9 13.8 7 20.5l5-2.2 5 2.2-2-6.7"/><circle cx="12" cy="9" r="2"/>',
        'trend'   => '<path stroke-linecap="round" stroke-linejoin="round" d="M4 16.5 9.5 11l3.5 3.4L20 7.5m0 0h-4.6M20 7.5v4.6"/>',
        'people'  => '<circle cx="9" cy="8.5" r="3"/><path stroke-linecap="round" d="M3.5 20a5.5 5.5 0 0 1 11 0M16 5.6a3 3 0 0 1 0 5.8M17.5 14.4A5.5 5.5 0 0 1 20.5 20"/>',
        'chat'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M20 12a7 7 0 0 1-7 7H8.5L5 21.5V18A7 7 0 0 1 12 5h1a7 7 0 0 1 7 7Z"/>',
        'book'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 6.5S10 4.5 4 4.5v13c6 0 8 2 8 2s2-2 8-2v-13c-6 0-8 2-8 2Zm0 0v13"/>',
        'target'  => '<circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="4.6"/><circle cx="12" cy="12" r="1.2"/>',
        'spark'   => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 3v3m0 12v3M4.5 12h3m9 0h3M6.7 6.7l2.1 2.1m6.4 6.4 2.1 2.1m0-10.6-2.1 2.1m-6.4 6.4-2.1 2.1"/><circle cx="12" cy="12" r="3"/>',
        'shield'  => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 3.5 5 6v6c0 4.4 3 7.4 7 8.5 4-1.1 7-4.1 7-8.5V6l-7-2.5Z"/>',
        'heart'   => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 20s-7-4.3-7-9a4 4 0 0 1 7-2.6A4 4 0 0 1 19 11c0 4.7-7 9-7 9Z"/>',
    ];
    return '<svg fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24">'
         . ($paths[$key] ?? $paths['medal']) . '</svg>';
}

/**
 * How a badge is earned, in words — and whether the platform awards it on its
 * own. The four criteria_type values are what action_check_badges.php can act
 * on, so this is a description of behaviour, not a label.
 */
function aw_criteria(array $b): array
{
    $v = (int)($b['criteria_value'] ?? 0);
    switch ($b['criteria_type'] ?? '') {
        case 'sessions_completed':
            return [$v . ' completed session' . ($v === 1 ? '' : 's'), true, 'Sessions'];
        case 'avg_rating':
            return [number_format($v / 10, 1) . '★ average, 5+ sessions', true, 'Rating'];
        case 'top_mentor':
            return ['Top mentor', false, 'Top mentor'];
        case 'community':
            return ['Community impact, awarded by hand', false, 'Community'];
        default:
            return ['Awarded by hand', false, 'Manual'];
    }
}

/** The criteria types an admin can pick, and what each one means. */
function aw_criteria_types(): array
{
    return [
        'sessions_completed' => 'Completed sessions — awarded automatically',
        'avg_rating'         => 'Average rating — awarded automatically',
        'community'          => 'Community impact — you award it',
        'top_mentor'         => 'Top mentor — you award it',
        'manual'             => 'Anything else — you award it',
    ];
}

/* ──────────────────────────── Certificates ──────────────────────────── */

/** What a certificate can be for. Drives the tabs and the usage chart. */
function aw_cert_categories(): array
{
    return ['Achievement', 'Participation', 'Appreciation', 'Completion', 'Milestone', 'Leadership'];
}

/**
 * The certificate designs.
 *
 * Each is a real set of colours the renderer draws with, so what a template
 * card shows is the certificate that will actually be issued — a scaled-down
 * render of the same markup, not a picture of one.
 */
function aw_cert_designs(): array
{
    return [
        'classic' => ['Classic gold',  '#FFFDF7', '#1A1508', '#B08428', '#7A5A12'],
        'azure'   => ['Azure',         '#F8FBFF', '#0A1B33', '#1B6FD1', '#12447F'],
        'emerald' => ['Emerald',       '#F7FCFA', '#06231A', '#17654B', '#0E4835'],
        'navy'    => ['Deep navy',     '#F8F9FD', '#050B26', '#020547', '#1B2B6B'],
        'parchment' => ['Parchment',   '#FDFAF1', '#2A2113', '#8A6400', '#5E4400'],
        'violet'  => ['Violet',        '#FBF9FE', '#1E1233', '#5A3E96', '#3E2A6B'],
    ];
}

function aw_cert_design(string $key): array
{
    $all = aw_cert_designs();
    return $all[$key] ?? $all['classic'];
}

/**
 * One certificate, drawn.
 *
 * $scale multiplies every dimension, so the same markup is a 260px-wide card
 * thumbnail, a dialog preview and a full page ready to print. The caller
 * supplies real values; nothing here invents a name, a date or a signatory.
 *
 * @param array $c  title, category, recipient, achievement, date, issuer, ref
 */
function aw_cert_html(array $c, string $design, float $scale = 1.0, bool $print = false): string
{
    [$label, $paper, $ink, $accent, $deep] = aw_cert_design($design);

    $px = fn(float $n) => round($n * $scale, 2) . 'px';
    $e  = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

    $title     = $c['title']       ?? 'Certificate';
    $recipient = trim((string)($c['recipient'] ?? ''));
    $body      = $c['achievement'] ?? '';
    $date      = $c['date']        ?? '';
    $issuer    = $c['issuer']      ?? '';
    $ref       = $c['ref']         ?? '';
    $platform  = $c['platform']    ?? 'PeerConnect';

    // A template with nobody attached yet is previewed with the placeholder
    // shown as a placeholder, never as a made-up person.
    $isSample  = $recipient === '';
    if ($isSample) $recipient = 'Recipient name';

    $out  = '<div class="cert-paper' . ($print ? ' cert-print' : '') . '" style="'
          . 'width:' . $px(720) . ';height:' . $px(510) . ';background:' . $paper . ';color:' . $ink . ';'
          . 'border:' . $px(1) . ' solid ' . $accent . '33;'
          . 'position:relative;overflow:hidden;box-sizing:border-box;'
          . 'font-family:Georgia,\'Times New Roman\',serif;">';

    // Frame: two rules and four corner marks, drawn from the design's accent.
    $out .= '<div style="position:absolute;inset:' . $px(16) . ';border:' . $px(2) . ' solid ' . $accent . ';"></div>';
    $out .= '<div style="position:absolute;inset:' . $px(23) . ';border:' . $px(1) . ' solid ' . $accent . '66;"></div>';
    foreach ([['top' => 16, 'left' => 16], ['top' => 16, 'right' => 16], ['bottom' => 16, 'left' => 16], ['bottom' => 16, 'right' => 16]] as $corner) {
        $pos = '';
        foreach ($corner as $side => $v) $pos .= $side . ':' . $px($v) . ';';
        $out .= '<div style="position:absolute;' . $pos . 'width:' . $px(34) . ';height:' . $px(34) . ';background:' . $accent . ';opacity:.16;"></div>';
    }

    $out .= '<div style="position:absolute;inset:' . $px(23) . ';display:flex;flex-direction:column;align-items:center;'
          . 'justify-content:center;text-align:center;padding:' . $px(26) . ' ' . $px(44) . ';box-sizing:border-box;">';

    $out .= '<div style="font-family:system-ui,-apple-system,\'Segoe UI\',sans-serif;font-size:' . $px(11)
          . ';letter-spacing:' . $px(2.4) . ';text-transform:uppercase;font-weight:700;color:' . $deep . ';">'
          . $e($platform) . '</div>';

    $out .= '<div style="width:' . $px(46) . ';height:' . $px(2) . ';background:' . $accent . ';margin:' . $px(12) . ' 0 ' . $px(14) . ';"></div>';

    $out .= '<div style="font-size:' . $px(30) . ';letter-spacing:' . $px(3) . ';text-transform:uppercase;line-height:1.15;">'
          . $e($title) . '</div>';

    $out .= '<div style="font-family:system-ui,-apple-system,\'Segoe UI\',sans-serif;font-size:' . $px(11.5)
          . ';color:' . $deep . 'CC;margin-top:' . $px(14) . ';">This certificate is awarded to</div>';

    $out .= '<div style="font-size:' . $px(38) . ';font-style:italic;color:' . $deep . ';margin:' . $px(8) . ' 0 ' . $px(6)
          . ';line-height:1.2;max-width:100%;word-break:break-word;'
          . ($isSample ? 'opacity:.42;' : '') . '">' . $e($recipient) . '</div>';

    $out .= '<div style="width:' . $px(280) . ';max-width:80%;height:' . $px(1) . ';background:' . $accent . '88;margin-bottom:' . $px(14) . ';"></div>';

    if ($body !== '') {
        $out .= '<div style="font-family:system-ui,-apple-system,\'Segoe UI\',sans-serif;font-size:' . $px(12.5)
              . ';line-height:1.65;max-width:' . $px(500) . ';color:' . $ink . 'CC;">' . $e($body) . '</div>';
    }

    $out .= '<div style="position:absolute;left:' . $px(44) . ';right:' . $px(44) . ';bottom:' . $px(30)
          . ';display:flex;justify-content:space-between;align-items:flex-end;'
          . 'font-family:system-ui,-apple-system,\'Segoe UI\',sans-serif;">';

    $out .= '<div style="text-align:center;min-width:' . $px(150) . ';">'
          . '<div style="height:' . $px(1) . ';background:' . $ink . '55;margin-bottom:' . $px(5) . ';"></div>'
          . '<div style="font-size:' . $px(11) . ';font-weight:600;">' . $e($date !== '' ? $date : '—') . '</div>'
          . '<div style="font-size:' . $px(9.5) . ';color:' . $ink . '99;letter-spacing:' . $px(.6) . ';text-transform:uppercase;">Date</div>'
          . '</div>';

    if ($ref !== '') {
        $out .= '<div style="font-size:' . $px(9) . ';color:' . $ink . '77;letter-spacing:' . $px(.8) . ';padding-bottom:' . $px(3) . ';">'
              . $e($ref) . '</div>';
    }

    $out .= '<div style="text-align:center;min-width:' . $px(150) . ';">'
          . '<div style="height:' . $px(1) . ';background:' . $ink . '55;margin-bottom:' . $px(5) . ';"></div>'
          . '<div style="font-size:' . $px(11) . ';font-weight:600;">' . $e($issuer !== '' ? $issuer : '—') . '</div>'
          . '<div style="font-size:' . $px(9.5) . ';color:' . $ink . '99;letter-spacing:' . $px(.6) . ';text-transform:uppercase;">Issued by</div>'
          . '</div>';

    $out .= '</div></div></div>';
    return $out;
}

/**
 * The reference printed on a certificate.
 *
 * Derived from the row's own id and issue date, so the same certificate always
 * carries the same reference and two of them never collide.
 */
function aw_cert_ref(int $id, ?string $when): string
{
    $y = $when ? date('Y', strtotime($when)) : date('Y');
    return 'PC-' . $y . '-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT);
}

/* ────────────────────────────── Shared ──────────────────────────────── */

/** Page size options both screens offer. */
function aw_per_page(int $requested, array $allowed = [6, 10, 20, 50]): int
{
    return in_array($requested, $allowed, true) ? $requested : $allowed[0];
}

/** "3 days ago", from a timestamp. */
function aw_ago(?string $ts): string
{
    if (!$ts) return '';
    $diff = time() - strtotime($ts);
    if ($diff < 60)     return 'just now';
    if ($diff < 3600)   return floor($diff / 60) . ' min ago';
    if ($diff < 86400)  return floor($diff / 3600) . ' hour' . (floor($diff / 3600) === 1.0 ? '' : 's') . ' ago';
    if ($diff < 604800) return floor($diff / 86400) . ' day' . (floor($diff / 86400) === 1.0 ? '' : 's') . ' ago';
    return date('M j, Y', strtotime($ts));
}
