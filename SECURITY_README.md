# PeerConnect – Security Implementation Guide
NEUST · College of Education · Web Security Project

---

## 1. Input Validation – Front-End & Back-End (15 pts)

### Back-End (PHP)
**File: `welcomepage.php`**
- `preg_match()` validates names: only letters, numbers, spaces, common punctuation allowed
- `filter_var($email, FILTER_VALIDATE_EMAIL)` — PHP built-in email format check
- `in_array($role, ['mentee','mentor'])` — whitelist check; rejects any other role value
- Password regex: `(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@#$%^&*!?]){8,20}` enforces complexity
- All fields checked for `empty()` before DB access

**File: `menteepage/verification.php`**
- Full name, student ID, course, year level, club all validated server-side before DB write
- File mime type validated with `mime_content_type()` — checks actual file content, not just extension
- File size limited to 3MB server-side

**File: `admin/action_block.php`, `admin/action_verify.php`**
- Action value whitelisted: `in_array($raw, ['approve','reject'])` — prevents arbitrary status injection
- All IDs cast to `(int)` — eliminates SQL injection via integer fields

### Why back-end validation is essential:
Front-end validation (HTML `required`, JS checks) runs in the browser and can be bypassed by disabling JavaScript, using browser dev tools, or sending raw HTTP requests with tools like Postman or curl. Back-end validation is the true security layer.

---

## 2. Data Sanitization – SQL Injection & XSS (15 pts)

### SQL Injection Prevention
**All database queries use prepared statements (parameterised queries).**

```php
// SAFE — parameter bound separately, never concatenated into SQL
$stmt = $con->prepare("SELECT * FROM users WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();

// UNSAFE (old pattern, removed from project) — attacker can inject SQL
$result = $con->query("SELECT * FROM users WHERE email = '$email'");
```

Files using prepared statements: `welcomepage.php`, `session-check.php`, `admin/action_block.php`, `admin/action_verify.php`, `admin/verify.php`, `Messages/send_message.php`, and all other DB-touching files.

`PDO::ATTR_EMULATE_PREPARES => false` in `db.php` forces the MySQL driver to use TRUE prepared statements — not client-side string substitution.

### XSS Prevention
All user data echoed into HTML is wrapped with `htmlspecialchars()`:
```php
echo htmlspecialchars($v['full_name']);  // e.g. converts <script> to &lt;script&gt;
```
The helper `e()` in `helpers.php` provides a shorthand:
```php
function e(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}
```

---

## 3. Hashing – Passwords & Sensitive Data (15 pts)

**File: `welcomepage.php` (signup)**
```php
$hashed = password_hash($password, PASSWORD_BCRYPT);
```

**File: `welcomepage.php` (login)**
```php
if (password_verify($password, $password_hash)) { ... }
```

### Why hashing is necessary:
- Passwords must **never** be stored in plain text. If the database is breached, plain-text passwords expose every user's credentials — and since users reuse passwords, this compromises their accounts on other sites too.
- **BCRYPT** is a slow, adaptive hashing algorithm. "Slow" is intentional — it makes brute-force and dictionary attacks computationally expensive (milliseconds per hash × millions of guesses = years).
- `password_hash()` automatically generates a unique **salt** per password, so two users with the same password have different hashes. This defeats rainbow table attacks.
- **MD5 and SHA1 are NOT used** — they are fast (attackers can test billions/second) and unsalted implementations are trivially reversible via rainbow tables.

---

## 4. Security Configuration (15 pts)

### `.htaccess` (root)
| Directive | Security Purpose |
|---|---|
| `display_errors Off` | Hides PHP errors from users — errors reveal file paths and DB structure |
| `expose_php Off` | Removes `X-Powered-By: PHP/x.x` header — hides PHP version |
| `disable_functions` | Blocks `exec`, `shell_exec`, etc. — prevents RCE if code injection occurs |
| `Options -Indexes` | Prevents directory listing — attackers can't enumerate files |
| `X-Frame-Options: SAMEORIGIN` | Blocks clickjacking via iframe embedding |
| `X-XSS-Protection: 1; mode=block` | Activates browser XSS filter |
| `X-Content-Type-Options: nosniff` | Stops MIME-type sniffing (prevents disguised file execution) |
| `Content-Security-Policy` | Restricts script/style sources — strongest XSS defence |
| `Referrer-Policy` | Prevents internal URLs with tokens from leaking to external sites |

### `uploads/.htaccess`
Blocks PHP execution inside the uploads directory. Without this, an attacker who uploads a PHP file disguised as a PDF could execute arbitrary server-side code (Remote Code Execution).

### `db.php`
- `PDO::ATTR_EMULATE_PREPARES => false` — forces real prepared statements
- `PDO::ERRMODE_EXCEPTION` — errors are caught and logged, not printed
- `mysqli_set_charset($con, 'utf8mb4')` — prevents charset-based SQL injection

---

## 5. Session Management (15 pts)

**File: `session-check.php`**

| Measure | Code | Purpose |
|---|---|---|
| `cookie_httponly` | `ini_set('session.cookie_httponly', '1')` | JavaScript cannot read session cookie — mitigates XSS session theft |
| `cookie_secure` | `ini_set('session.cookie_secure', '1')` | Cookie only sent over HTTPS — prevents network sniffing |
| `cookie_samesite` | `ini_set('session.cookie_samesite', 'Strict')` | Cookie not sent on cross-site requests — CSRF mitigation |
| `use_strict_mode` | `ini_set('session.use_strict_mode', '1')` | Rejects session IDs not created by server — prevents session fixation |
| Session regeneration | `session_regenerate_id(true)` | New session ID on each authenticated request — limits fixation window |
| Token DB check | Prepared statement against `tokens` table | Server validates token hasn't expired or been revoked |

**File: `logout.php`**
Full secure logout: DB token deleted → session variables cleared → cookie expired → session destroyed.

---

## 6. Additional Security Features (10 pts)

### a) Login Rate Limiting (Brute-Force Protection)
**Files: `App/views/auth/login.php`, `App/views/admin/login.php`, `helpers.php`**
- Attempt ceiling and lockout length come from System Settings → Security
  (`login_max_attempts`, `login_lockout_mins`), so an admin can change them
  without a code change. Both sign-in handlers read the same values.
- Failures are counted in the **`auth_throttle` table**, keyed on the account
  address *and* the caller's IP. They used to be counted in `$_SESSION`, which
  meant an attacker who discarded the session cookie between attempts was never
  limited at all — the lockout only ever delayed honest users who mistyped.
- The window slides: once the oldest recorded failure ages out, one attempt is
  handed back. A successful sign-in clears the bucket outright.
- Remaining attempts shown to user; countdown shown during lockout.
- The shared `rate_limit()` helper (forgot-password, admin sign-up, and the
  authenticated actions) uses the same table.

### b) Google reCAPTCHA v2
**File: `welcomepage.php`** — both login and signup forms
- Server-side verification via `google.com/recaptcha/api/siteverify`
- Blocks automated bots and credential stuffing attacks

### c) Token-Based Session Authentication
**Files: `session-check.php`, `logout.php`**
- Random 64-character token (`bin2hex(random_bytes(32))`) generated on login
- Stored in DB with 30-minute expiry; validated on every page load
- Deleted on logout — prevents session replay after sign-out

### d) CSRF Protection
**File: `helpers.php`** — `csrf_token()`, `csrf_field()`, `verify_csrf()`
- Cryptographically random token embedded in all forms
- `hash_equals()` used for comparison (timing-attack safe)

### e) Role-Based Access Control
Admin pages check `$_SESSION['role'] === 'admin'` before any action.
Mentor/mentee pages redirect to verification if not approved.
