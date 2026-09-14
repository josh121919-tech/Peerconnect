# Deploying PeerConnect

What to set when PeerConnect moves off a local XAMPP install onto a server
other people use. The code already switches its protections on from `.env`;
this is the list of things only the person installing it can do.

Once the site is up, run the smoke test against it (see the last section).

## 1. HTTPS

- Install a TLS certificate for the domain (most hosts offer Let's Encrypt).
- Set `APP_URL` in `.env` to the `https://` address, including the folder if
  the app is not at the domain's root:

  ```
  APP_URL=https://peerconnect.example.edu
  APP_URL=https://www.example.edu/peerconnect
  ```

With an `https://` `APP_URL`, the app:

- sends any plain-HTTP request to the same page on HTTPS (301; 308 for a form
  post, so the post is not lost)
- sends `Strict-Transport-Security` for one year, so browsers stop trying HTTP
- marks the session and "Remember me" cookies `Secure`

The folder in `APP_URL` is also where every link, redirect, the app manifest
and the offline service worker take their paths from. Nothing else names a
folder.

**Before you switch:** HSTS cannot be taken back for a year in browsers that
saw it. Only set an `https://` `APP_URL` once HTTPS works on every page.

## 2. Secrets in `.env`

Copy `.env.example` and fill in every line. Do not reuse the local values:

| Setting | What to do |
|---|---|
| `ROUTE_SECRET_KEY` | A new long random string. It changes every page address, so do it before people bookmark pages, not after. |
| `ADMIN_INVITE_KEY` | A new long random string; it is what lets someone create an admin account. |
| `DB_USER`, `DB_PASS` | The limited database account from section 3, never `root`. |
| `GOOGLE_REDIRECT_URI` | The `https://` address of `google-login.php`. Add it, and the Google Calendar callback (`gcal-callback.php`), to the OAuth client's authorised redirect URIs in Google Cloud Console. |
| `RECAPTCHA_SITE_KEY`, `RECAPTCHA_SECRET_KEY` | Add the new domain to the reCAPTCHA site's domain list. Keep CAPTCHA switched on in System Settings → Security. |
| `SMTP_*`, `MAIL_FROM` | The real mail account. |
| `JAAS_*` | The video-call keys, and `App/config/jaas_private_key.pem` copied separately (it is not in git). |

`.env`, `.git`, `.sql` files, `App/`, `Framework/`, `scripts/`, `tests/`,
`vendor/`, `helpers.php`, `routes.php` and `.md` files are refused by
`.htaccess`. If the host lets you, put the whole project outside the public
web folder and point the domain at it, so that protection is not the only one.

## 3. The database account

The app needs to read and write rows in its own database, and nothing more. It
never creates or changes tables while running.

On a shared host, create the database user in the host's control panel with
only these rights on the PeerConnect database:

```
SELECT, INSERT, UPDATE, DELETE, LOCK TABLES, SHOW VIEW, TRIGGER, EVENT
```

(The last four are for the nightly backup's `mysqldump`.) On a server you run
yourself, `restrict_app_db_user.sql` shows the exact statements. Keep a
separate, stronger account for schema changes such as a new drop or migration
file.

Also on your own server:

- give MySQL's `root` a password
- if nothing outside the machine needs the database, set
  `bind-address = 127.0.0.1` in MySQL's `my.ini` / `my.cnf` and restart it

## 4. PHP settings

In the host's `php.ini` (or its PHP settings page):

| Setting | Value | Why |
|---|---|---|
| `display_errors` | `Off` | The app also turns it off, but a fatal during start-up happens before that. |
| `expose_php` | `Off` | The app removes `X-Powered-By`; this stops PHP adding it anywhere. |
| `session.save_path` | a folder only PHP can read | Session files hold who is signed in. |
| `date.timezone` | `Asia/Manila` | The app sets it too; this covers anything that runs before. |

## 5. Files, jobs and backups

- `public/uploads/` must be writable by the web server. Nothing in it can run
  as PHP.
- Run `composer install --no-dev` on the server.
- Schedule the two jobs described in `scripts/README.md`: the nightly backup
  and the 30-minute maintenance run (missed sessions and mentor scores). On
  Linux, use cron with the same PHP commands.
- Set `BACKUP_DIR` to a folder outside the web root, and copy backups off the
  server regularly.

## 6. After it is up

On the server itself (the test signs in by writing PHP session files and reads
the database directly), with the sample accounts loaded:

```
php tests/smoke.php --host=https://peerconnect.example.edu
```

Then check by hand:

- `http://` redirects to `https://`
- the browser shows the session cookie as `Secure`
- `/.env`, `/helpers.php` and `/DEPLOYMENT.md` answer 403
- signing in with Google and with a password both work, and CAPTCHA appears on
  sign-up
