# Scheduled jobs

Two command-line jobs keep PeerConnect's data safe and up to date. Windows Task
Scheduler runs them; both can also be run by hand from the project root.

| Job | Schedule | What it does |
|---|---|---|
| `scripts/backup.php` | Every day at 02:00 | Dumps the whole database with `mysqldump`, and archives `public/uploads` once a week |
| `scripts/maintenance.php` | Every 30 minutes | Closes sessions that ended over an hour ago (completed, or missed by whoever did not join), removes requests the mentor never answered once their time has passed, lifts restrictions that have run out, then refreshes every mentor's score |

Everything they write goes to **`C:\PeerConnectBackups`** (set `BACKUP_DIR` in
`.env` to change it):

```
C:\PeerConnectBackups\
    database\cs_YYYY-MM-DD_HHMMSS.sql       newest 30 kept
    uploads\uploads_YYYY-MM-DD_HHMMSS.zip   one a week, newest 4 kept
    logs\backup.log
    logs\maintenance.log
```

These files hold every member's email and password hash, and the scanned
student IDs from verification. That is why the folder is outside `htdocs` and
outside git. It is still on this laptop's own disk, so copy it somewhere else
regularly: a backup that lives on the same disk as the database does not
survive losing that disk.

`System Settings → Backup & Restore` in the admin panel shows when the last
backup was written, and warns when the newest one is more than 36 hours old or
the last attempt failed.

## Running them by hand

```
php scripts/backup.php                    back up now
php scripts/backup.php --verify=FILE      check a dump is complete, without restoring it
php scripts/maintenance.php --dry-run     list what would be closed or lifted, and how; writes nothing
php scripts/maintenance.php               run the 30-minute job now
```

Both print what they did and exit with 0 on success, 1 on failure.

## Missed sessions

Opening the video call for a session records who joined, in
`session_attendance` (first visit only). **One hour after a session ends**, if
nobody has closed it — the mentor ending the call, or the mentee leaving
feedback — the detector decides from that record:

| Who joined | Recorded as | Who is told |
|---|---|---|
| Both | completed | the mentee, with a link to leave feedback |
| Only the mentor | missed by the mentee | the mentee that they missed it; the mentor that it does not count against them |
| Only the mentee | missed by the mentor | the same, the other way round |
| Nobody | missed by both | both |

A mentor's reliability score counts only sessions missed by the mentor or by
both, so a mentor who shows up is not penalised for an absent mentee. In a
group session the mentor's join counts for every mentee booked into that slot.

"Joined" means opened the call page during its window (15 minutes before the
start until 5 minutes after the end). The call itself runs in an embedded
frame the server cannot see, so a camera or microphone that never connected
still counts as joined.

Change `PC_MISSED_GRACE_HOURS` in `Framework/bootstrap.php` to wait longer;
the buttons below use the same number.

**Nobody closes a session by hand.** Admins can cancel an open session, but
not mark one completed or missed, so this task is the only thing that closes
sessions the call and feedback did not. If it stops, sessions pile up as "Not
closed": once any is still open more than 90 minutes past its hour of grace,
All Sessions shows a warning with a **Run the check now** button. Platform
analytics has the same check as **Detect missed sessions**. Either runs the
same rules as the task; neither fixes the task, so check it in Task
Scheduler.

## Unanswered requests

A request the mentor has not accepted or declined by its start time can no
longer take place as booked. From that moment the mentor pages show it
without an Accept button, and accepting it is refused. The next run
**deletes** it and tells both people: the mentee, with a link to the mentor's
profile to book another time, and the mentor, that the request lapsed. A
deleted request does not count towards anyone's score. `--dry-run` lists the
requests it would remove.

## Setting up Task Scheduler

The tasks run as your own Windows account, in the background, whether you are
signed in or not, with **"do not store password"**. No password is saved. Tasks
that run only while you are signed in run on your desktop, and `mysqldump`
then opens a terminal window at every backup.

Creating tasks of that kind needs administrator rights. Open **PowerShell with
"Run as administrator"** and run:

```
powershell -NoProfile -ExecutionPolicy Bypass -File C:\xampp\htdocs\case\case\scripts\tasks.ps1 -Register
```

`-ExecutionPolicy Bypass` applies to that one command only. Read
`scripts/tasks.ps1` first if you like — it is short.

Check on them at any time (no administrator rights needed):

```
powershell -NoProfile -ExecutionPolicy Bypass -File C:\xampp\htdocs\case\case\scripts\tasks.ps1 -Status
```

`result 0x0` means the last run succeeded. Remove both tasks (administrator
PowerShell again):

```
powershell -NoProfile -ExecutionPolicy Bypass -File C:\xampp\htdocs\case\case\scripts\tasks.ps1 -Remove
```

If the laptop is off or asleep at the scheduled time, the task runs as soon as
it can afterwards. MySQL has to be running for either job to succeed; on this
install it starts automatically as a Windows service.

## Restoring

The database, from a command prompt (no administrator rights needed). This **replaces**
everything currently in `cs` — take a fresh backup first:

```
php scripts/backup.php --verify=C:\PeerConnectBackups\database\cs_2026-09-14_171832.sql
mysql -u root cs < C:\PeerConnectBackups\database\cs_2026-09-14_171832.sql
```

Uploaded files: extract the newest `uploads_*.zip` into `public\uploads`.
