# Lost and Found — Holy Child College of Davao

PHP + MySQL + vanilla JS web app (XAMPP, LAN). Students and staff report lost / found items, browse and
message each other; admins manage reports and users. Emails go out through PHPMailer.

## Install
1. Copy the folder into `htdocs/` (e.g. `C:\xampp\htdocs\lost-and-found`) and start **Apache** + **MySQL**.
2. Import `sql/schema.sql` in phpMyAdmin. It creates the database, every table, the default admin and the
   built-in System Notifications account.
3. `composer install` in the folder (PHPMailer; needs `extension=zip`).
4. `config/db.php`: DB login and `BASE_URL`. Links in emails follow how the page was opened: via `localhost` they use this computer's network IP automatically, via an IP or the `BASE_URL` host they use that address (so set `BASE_URL` to your public address, e.g. ngrok, if people are off the LAN).
5. Email: copy `config/mail.local.example.php` to `config/mail.local.php` and fill in your own values:
   `SMTP_USERNAME`, `SMTP_PASSWORD` (e.g. a Gmail app password) and `APP_SECRET` (a long random string that signs the
   one-click email links; `php -r "echo bin2hex(random_bytes(32));"` makes one). The example file is the template that
   is committed to GitHub; `mail.local.php` holds the real credentials, is listed in `.gitignore` and must never be
   pushed. Or set the same names as environment variables instead. Also set `ADMIN_INBOX_EMAIL`: the admin account
   (`lostandfound@holychild.edu.ph`) is not a real mailbox, so every email meant for it is delivered to this address
   instead (if you leave it out, it goes to `SMTP_USERNAME`).
6. Open `http://localhost/lost-and-found/`. Default admin: `lostandfound@holychild.edu.ph` / `Admin123!` — change it.

Live updates hold one PHP worker per open page, so use Apache/XAMPP (not single-threaded `php -S`).

## Features
- **Accounts:** register with a `firstname.lastname@holychild.edu.ph` email (the name is read from the email),
  verify with a 6-digit code (15 min), forgot-password by emailed code. The verification pop-up opens over the
  Register / Login page; a "verified" toast shows on the login page afterwards.
  - Registering again with an email that is still unverified: the password is changed to the newly typed one; if its
    code is still valid the pop-up just reopens (no new email), if it expired a new code is sent.
  - Logging in to an unverified account (correct password): same — valid code, pop-up only; expired, new code. The
    password is not changed.
  - Codes allow 5 wrong guesses, then they stop working and a new one must be requested. A new code can be requested
    once a minute. Login says "Incorrect email or password" for both mistakes, and the forgot-password pop-up answers
    the same for every address, so neither reveals which emails have accounts.
- **Reports:** lost / found, category, photo (JPG/PNG/GIF, 5 MB, checked by content, not just file name), edit,
  delete (to trash), restore. Date can't be in the future; location max 150 and description max 2000 characters.
  Statuses: **Active → Resolved → Closed**. Marking Resolved asks who found / claimed it: a full-width dropdown
  listing each person the owner talked to about that report, a text box for someone else (the popup's
  **Save** button saves the typed name; an empty box shows an error toast and saves nothing), or Skip. The email link
  opens the same choices on its own page. The server checks a picked person against the real conversation list. Active reports close by themselves after 30 days
  (`REPORT_CLOSE_DAYS`); a Resolved report never auto-closes. Every 14 days an Active report's owner is asked
  "has it been resolved?" (System Notification + email with a one-click button).
- **Possible matches:** a new or edited (type / category changed) report is scored against active reports of the
  opposite type and same category. The item names must agree first (shared word, one contains the other, or ≥75%
  alike), then location and date add to the score; 60+ is suggested. Both owners get a notification and email with a
  side-by-side "Compare reports" view (the possible match on the left, your report on the right). Tunable constants are at the top of that section in `includes/functions.php`.
- **Report popup buttons:** an owner sees *Edit Report* (none once the report is Closed). Other users see *Chat
  Reporter* on Active reports, nothing on Resolved ones, and *Chat Admin* on Closed ones. Admins see *Chat Reporter*
  plus *Edit Report* (no edit once Closed). Status changes are made from the dropdowns in My Reports / Manage Reports.
- **Popups:** every popup that is a form (report, edit, import CSV, settings, verification, password reset, the
  "who found it" name box, the admin password check) asks "Discard what you've entered?" before closing by a click
  outside / Escape / X if something was typed or an image / CSV was chosen. The Save / Submit button of the form popups
  stays pinned at the bottom while the form scrolls, and all popup buttons share one style.
- **Messaging:** Messenger-style page, photos, deletable messages (max 2000 characters), a pinned System Notification
  thread, and a search box over the conversation list. The three-dot menu (shown on hover) has the other person's
  email, the report, and delete conversation.
- **Admin:** dashboard counts, Manage Reports (filters, sort, bulk actions, trash, CSV export / import), Manage
  Users (verify, delete, bulk). Deleting a user permanently removes the account row, keeps reports with
  `reports.user_id = NULL`, and preserves conversations/messages with the deleted user's saved display name.

## How it works
**Live updates (Server-Sent Events).** Every logged-in page opens one `EventSource` to `ajax/stream.php` (opened at
the bottom of `js/main.js`; handlers register with `Live.on()`). The page tells the stream what it shows
(`?convo=`, `?list=1`, `?watch=browse|mine|manage|item:ID|users|dash`). The stream is a PHP loop that releases the
session lock and turns off output buffering first (`includes/sse.php`), then once a second runs one cheap
"fingerprint" query over the user's messages and conversations (and every 2 s one for watched reports / users), and
pushes only when it changed: new / deleted messages, the conversation list, bell and Messages badges, toasts, report
and user changes, online marks. The page re-fetches just its own table or cards and merges the change in, keeping
filters, sort, ticks and scroll. A connection is recycled every ~55 s; the browser reconnects by itself and
`Last-Event-ID` stops messages being sent twice. Because each open page holds one PHP worker, use Apache/XAMPP (not
`php -S`). `ajax/stream_check.php` is a self-test for buffering and session locking.

**Online detection and tab-close logout.** Each open page has a row in `presence`, refreshed every ~10 s. A user is
**online** if any row was refreshed in the last 25 s. When a tab closes, the page sends a "leave" beacon
(`ajax/leave.php`) so the person looks offline within seconds. A login session whose pages have all left is ended on
its next request after a 15 s grace (reload / quick reopen is fine). If the beacon never arrives, the safety net
is the timeout: no request for 60 s and no live page ends the session. Every request is checked on the server
(account still exists, role read from the database); JavaScript is never the security.

**Automatic process settings.** Automatic intervals are centralized in
[`config/timing.php`](config/timing.php): session abandonment (60 seconds), leave grace (15 seconds), online
presence (25 seconds), automatic report closing (30 days), stale conversation deletion (30 days), and resolved
check-ins (14 days). Change the values there instead of editing individual functions.

**Emails.** Nothing makes a page wait for the mail server. `mail/emails.php` hands each email to a background
process (`mail/send_cli.php`), else to `mail/send_job.php` over HTTP, else sends inline. A failed send leaves a toast
for the person who caused it. Users switch types off in Settings: possible matches, "is it resolved?", new
conversations, report updates (edited / closed / reopened). Verification and password-reset emails always send.
Every email uses the same branded layout. Emails for the admin account go to `ADMIN_INBOX_EMAIL`
(`mail_destination()` in `mail/emails.php`).

**Links in emails.** They are built from how the page was opened (`app_url()` in `includes/functions.php`): opened as
`localhost` → this computer's network IP, so the link works on a phone on the same Wi-Fi; opened by an IP or by the
host in `BASE_URL` → that address; any other `Host` header is ignored and `BASE_URL` is used. Away from the school
network, set `BASE_URL` to the public address (e.g. ngrok).

**Notifications.** Automatic messages come from the built-in System account into each user's pinned System
Notification thread: matches, status changes, admin edits, automatic closing, deleted conversations.

**Concurrency and data safety (ACID).** All tables are InnoDB. Anything that changes more than one row runs in
`db_transaction()`: all-or-nothing (atomic), deadlocks are retried up to 3 times, and notifications, emails and photo
deletion are queued with `after_commit()` so they only happen if the transaction really committed. Rows are locked with
`SELECT … FOR UPDATE` and re-checked before they are changed (status, edit, delete, restore, bulk actions, resolve-by-email,
the 14-day "is it resolved?" ask). Status changes carry the status the person saw (`expected`) and edits carry a
`version`, so two people can't silently overwrite each other — the second gets a "changed by someone else" message.
Starting a conversation locks the starter's user row first, because MySQL's UNIQUE key can't stop duplicates when
`report_id` is NULL. Verification and password-reset codes are checked and used up in one `UPDATE … WHERE code = ?`, and
wrong guesses are counted with an atomic `verify_attempts = verify_attempts + 1`. Plain single-statement changes (a
message insert, marking read, presence) are atomic by themselves. Not covered: two people registering the same email at
the same instant (the UNIQUE key stops it, but one of them gets an error page).

**Cleanup.** A photo file is deleted only when no report or message uses it any more (replaced photo, deleted
message, deleted conversation, permanently deleted report). Conversations are removed with their photos and the
other person is notified: when deleted, when an account or report is permanently deleted, or after 30 days with no
messages (`CONVERSATION_EXPIRE_DAYS`). Hourly housekeeping runs from normal page loads; there is no cron job.

## Security notes
Passwords are bcrypt; sessions are cookie-only with SameSite=Lax; every POST is checked for a same-site Origin;
admin actions on accounts and CSV import need the admin's password (checked again on the server); `includes/`,
`config/`, `sql/` and most of `mail/` are blocked from the browser by `.htaccess`. Not included: CSRF tokens,
login rate limiting (verification and reset codes are limited, passwords are not).

## Files
`sql/` schema · `config/` settings · `includes/functions.php` shared logic · `ajax/` stream, presence,
helpers · `mail/` email sending · `admin/` admin pages · `js/main.js`, `css/style.css` · top-level `*.php` pages.
