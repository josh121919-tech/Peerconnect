# Deploying PeerConnect

What to set when PeerConnect moves off the local XAMPP install onto the server
other people use. The code already switches its protections on from `.env`;
this is the list of things only the person installing it can do.

This guide is written for the install it is actually going to:

| | |
|---|---|
| Domain | `neustpeerconnect.org` (and `www.`, both on one certificate) |
| Host | Namecheap Stellar Plus, cPanel, LiteSpeed |
| cPanel account | `neusgamo` |
| Home directory | `/home/neusgamo` |
| Web root | `/home/neusgamo/public_html` |

Substitute your own values if it ever moves again. Once the site is up, run the
smoke test against it (see the last section).

## 0. What is already done

Recorded here so nobody repeats it:

- The domain is registered and **ACTIVE**, with the ICANN alert cleared.
- Nameservers are **Namecheap Web Hosting DNS**, so DNS records are edited in
  **cPanel → Zone Editor**, not in Namecheap's Advanced DNS tab. That tab
  looking empty is correct.
- `neustpeerconnect.org` and `www.` both resolve to the hosting server.
- **HTTPS already works.** Namecheap issued the certificate automatically when
  DNS started resolving — there is no *SSL/TLS Status* tile in this cPanel and
  no AutoSSL to run. The certificate covers both names.
- The Namecheap parking page (`parking-page.shtml`, `nc_assets/`) has been
  deleted from `public_html`. `.well-known/` and `cgi-bin/` were kept.

## 1. HTTPS

The certificate is in place, so the only step left is telling the app:

```
APP_URL=https://neustpeerconnect.org
```

With an `https://` `APP_URL`, the app:

- sends any plain-HTTP request to the same page on HTTPS (301; 308 for a form
  post, so the post is not lost)
- sends `Strict-Transport-Security` for one year, so browsers stop trying HTTP
- marks the session and "Remember me" cookies `Secure`
- builds every emailed link — password reset, address confirmation, and the
  "View in PeerConnect" button on notification emails — from this address

**Before you switch:** HSTS cannot be taken back for a year in browsers that
saw it. Load the site over HTTPS and click through it first.

Pick one canonical name and use it consistently. This guide uses the apex,
`neustpeerconnect.org`, without `www`.

## 2. Secrets in `.env`

Copy `.env.example` to `.env` on the server and fill in every line. Do not
reuse the local values:

| Setting | What to do |
|---|---|
| `APP_URL` | `https://neustpeerconnect.org` |
| `DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME` | The cPanel database and its user from section 3. Both names carry the `neusgamo_` prefix cPanel forces on them. Never `root`. |
| `ROUTE_SECRET_KEY` | See the warning below before you change it. |
| `ADMIN_INVITE_KEY` | A new long random string; it is what lets someone create an admin account. The current one is seven characters — fine behind the 10-tries-per-15-minutes lockout on a laptop, not on a public domain. |
| `BACKUP_DIR` | `/home/neusgamo/peerconnect_backups` — inside the home directory, **outside** `public_html`, so it can never be downloaded. |
| `GOOGLE_REDIRECT_URI` | `https://neustpeerconnect.org/google-login.php`. Add it, and the Calendar callback `https://neustpeerconnect.org/gcal-callback.php`, to the OAuth client's authorised redirect URIs in Google Cloud Console. Both paths are deliberately stable and survive a `ROUTE_SECRET_KEY` change. |
| `RECAPTCHA_SITE_KEY`, `RECAPTCHA_SECRET_KEY` | Add `neustpeerconnect.org` to the reCAPTCHA site's domain list, or both forms stop accepting anyone. The CAPTCHA is always required and has no off switch. |
| `SMTP_*`, `MAIL_FROM` | The real mail account. |
| `JAAS_*`, `DAILY_API_KEY` | The video-call keys, and `App/config/jaas_private_key.pem` copied separately — it is not in git. |

### `ROUTE_SECRET_KEY` and the links already in the database

Every page address is `md5(page name + ROUTE_SECRET_KEY)`, and **79 rows in
`notifications` store addresses built with the current key and the `/case/case`
folder**. If you carry the existing data over:

- keeping the same key, the tokens still match, but the stored `/case/case`
  prefix does not — those links 404 at the domain root
- changing the key, both halves are wrong

Neither breaks the app; it is 79 old "View" buttons on past notifications.
Either accept that, or rewrite them once after the import:

```sql
UPDATE notifications SET link = REPLACE(link, '/case/case/', '/') WHERE link LIKE '/case/case/%';
```

(and keep the same `ROUTE_SECRET_KEY` if you run it, or the tokens still miss).
Decide before people start using the site, not after.

## 3. The database account

The app reads and writes rows in its own database and nothing more. It never
creates or changes tables while running.

In **cPanel → MySQL Databases**, create the database and a user, then grant
that user only:

```
SELECT, INSERT, UPDATE, DELETE, LOCK TABLES, SHOW VIEW, TRIGGER, EVENT
```

(The last four are for the nightly backup's `mysqldump`.) cPanel's "All
Privileges" checkbox grants far more than this — untick it and pick the eight.
`restrict_app_db_user.sql` shows the same thing as SQL.

Import the local dump through **phpMyAdmin** or, over SSH:

```
mysql -u neusgamo_user -p neusgamo_cs < cs_backup.sql
```

## 4. PHP settings

In **cPanel → MultiPHP Manager**, set the domain to **PHP 8.2** (or 8.3).

In **Select PHP Version → Extensions**, confirm all of these are ticked:

```
mysqli   curl   zip   openssl   mbstring   fileinfo
```

`zip` matters more than it looks — without it the nightly backup fails
outright rather than quietly skipping the archive. `gd` and `intl` are *not*
used by this app.

In **Select PHP Version → Options**:

| Setting | Value | Why |
|---|---|---|
| `display_errors` | `Off` | The app turns it off too, but a fatal during start-up happens before that |
| `date.timezone` | `Asia/Manila` | The app sets it on every request; this covers anything that runs earlier |
| `upload_max_filesize` | at least `8M` | Verification documents are photographs of student IDs |
| `post_max_size` | above `upload_max_filesize` | |
| `opcache.enable` | `On` if offered | Pure speed; nothing depends on it |

### The database clock

`App/config/db.php` pins every connection to `+08:00` so MySQL's `NOW()` and
PHP's `date()` cannot drift apart. This matters because the server is almost
certainly not on Manila time and the app writes both kinds of timestamp into
the same columns. Confirm it took effect — in **phpMyAdmin**, run:

```sql
SELECT NOW() AS should_be_manila, @@session.time_zone AS should_be_0800;
```

If `NOW()` is not Philippine time, stop and fix it before anyone books a
session; every session time, the missed-session detector and the activity log
depend on it.

## 5. Files

Upload into `/home/neusgamo/public_html`, so `public/index.php` ends up at
`/home/neusgamo/public_html/public/index.php`.

**Do not upload:** `.git/`, `.claude/` (it holds stale copies of the whole
project), your local `.env`, or `tests/` and `__seed_*.php` unless you want the
sample data.

**Do upload, and then check it arrived:** `.htaccess`. File Manager and FTP
clients hide dotfiles by default — in cPanel's File Manager, **Settings → Show
Hidden Files**. Without that one file:

- every request 404s instead of reaching `public/index.php`
- directory listing stays on, so the web root lists your whole project
- `App/`, `Framework/`, `storage/`, `scripts/`, `tests/`, `.env` and `.git`
  become downloadable by anyone who guesses the path

It is the single most important file to verify after an upload.

`vendor/` is not in git. With **cPanel → Terminal** (SSH is enabled on this
account):

```
cd ~/public_html && composer install --no-dev -o
```

Without SSH, include `vendor/` in the upload instead.

Then:

- `public/uploads/` must be writable by the web server (755 on the folders).
  Nothing in it may run as PHP — its own `.htaccess` sees to that.
- Copy `public/uploads/` and `storage/` from the laptop. Neither is in git, and
  `storage/verification` holds the scanned student IDs.

### Pushing a change after it is live

The first upload is a zip. Afterwards it is quicker to copy the handful of
files that actually changed. Over SSH, from the laptop:

```
scp -P 21098 helpers.php routes.php neusgamo@premium24.web-hosting.com:public_html/
```

`premium24.web-hosting.com` is the server behind `neustpeerconnect.org` — both
answer on `68.65.122.202`. The port is **21098**, not 22; Namecheap moves it,
and a plain `scp file host:` will sit there and time out.

The destination is a directory, and it must already exist — `scp` will not
create one. For a new folder, make it first with `ssh` or File Manager.

Two things to know afterwards:

- **OPcache is on.** PHP's default is to recheck a file's timestamp, in which
  case a replaced file is picked up within `opcache.revalidate_freq` seconds.
  That default was never confirmed on this server, so if an upload refuses to
  appear, check `opcache.validate_timestamps` in **cPanel -> MultiPHP INI
  Editor** before hunting for the cause anywhere else. A file whose contents
  change while its mtime does not will serve the stale version either way;
  `touch` it.
- **Permissions.** Files arrive as the account's user. PHP runs as the same
  user here, so 644 is right and there is nothing to change in the normal
  case; only tighten `.env`, which stays 600.

Which files changed is a question git can answer, if you commit before each
upload:

```
git diff --name-only <last-deployed-commit> HEAD
```

Without that, compare modification times against the zip you last uploaded.

## 6. Scheduled jobs

**cPanel → Cron Jobs.** Use the full path to PHP, not a bare `php`:

```
*/30 * * * *  /usr/local/bin/php /home/neusgamo/public_html/scripts/maintenance.php
0 2 * * *     /usr/local/bin/php /home/neusgamo/public_html/scripts/backup.php
```

This server runs CloudLinux, so the usual cPanel paths do not apply: there is
no `/usr/local/bin/ea-php82` (the `ea-php*` list stops at 81), and
`/usr/local/bin/php` is the per-account selector that resolves to whichever
version the account is set to — PHP 8.2.33, at `/opt/alt/php82/usr/bin/php`.

Use the selector path above so the jobs follow the version you pick in cPanel.
Pin `/opt/alt/php82/usr/bin/php` instead only if you want them held on 8.2
whatever the panel says — and remember it will need changing by hand if the
account is ever moved to a newer PHP.

The 30-minute job closes sessions that ended over an hour ago, expires requests
the mentor never answered, lifts restrictions that have run out and refreshes
mentor scores. The nightly job dumps the database and, once a week, archives
`public/uploads` **and** `storage` into one zip.

Run each by hand once and read the output before trusting the schedule:

```
php scripts/maintenance.php --dry-run
php scripts/backup.php
```

`mysqldump` is occasionally restricted on shared hosting. If the backup
complains it cannot find or run it, set `MYSQLDUMP_PATH` in `.env`. Do not
leave this unresolved — it is the only thing protecting the data.

Copy the backup folder off the server regularly. A backup on the same disk as
the database does not survive losing that disk.

## 7. After it is up

On the server itself (the test signs in by writing PHP session files and reads
the database directly), with the sample accounts loaded:

```
php tests/smoke.php --host=https://neustpeerconnect.org
```

Then check by hand:

- `http://` redirects to `https://`
- the browser shows the session cookie as `Secure`
- `https://neustpeerconnect.org/.env` answers 403, and so do `/helpers.php`,
  `/DEPLOYMENT.md`, `/storage/verification/` and `/.git/config`
- the web root does not show a directory listing
- signing in with a password works, signing in with Google works, and the
  CAPTCHA appears on sign-up
- book a session and confirm the time it shows is the time you picked
- a notification email's "View in PeerConnect" button opens
  `https://neustpeerconnect.org/…`, not `localhost`

## 8. Keeping it alive

- **Hosting renewal.** Auto-renew was off at purchase and the plan expires
  **20 October 2026**. The domain itself runs to 21 September 2027.
- **Certificate.** Valid to **7 April 2027**, renewed by Namecheap
  automatically. Check it a month before.
- **Backups.** The admin panel's *System Settings → Backup & Restore* warns
  when the newest backup is more than 36 hours old or the last attempt failed.
  Look at it occasionally rather than assuming.
