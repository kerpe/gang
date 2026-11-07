# GANG.lt

Darbo birža hospitality komandoms: darbdaviai (restoranai, kavinės, barai ir klubai) skelbia poreikius (skubus pavadavimas, savaitė ar mėnuo, sezonas, nuolatinis darbas), tinkami darbuotojai gauna laišką ir atsiliepia, darbdavys patvirtina (GANG kontraktas), po darbo abu vienas kitą įvertina.

Plain PHP 8.3 + MySQL, no build step. Live: https://gang.lt (Hostinger).

## Layout

| Path | What it is |
|---|---|
| `public_html/` | Web root: `index.php` (router), `style.css`, `.htaccess`, icons. |
| `private/app/` | Server code: `bootstrap.php` (config, db, mail, lists of cities/roles), `schema.php` (tables, created on first request), `auth.php` (email-code login), `public.php`, `account.php` (profile, dashboard, availability), `requests.php` (venues, requests, responses, contracts), `reviews.php`, `admin.php`, `data.php` (queries, ratings, notifications), `views.php` (layout, helpers). |
| `private/cron.php` | Hourly job: expires old requests, asks for reviews after the work, cleans login codes. |

## Rules worth knowing

- Login has no passwords: a 6-digit code is emailed (15 min, 5 attempts).
- Phones and the venue address are shown to the other side only after the manager accepts a worker (a contract).
- Reviews only after a contract, both directions; a review becomes visible when both sides have rated or after 7 days (`REVIEW_HIDE_DAYS`). Worker reviews are visible to managers only, venue reviews to workers only. Authors are never shown.
- Either side can cancel a contract (`/kontraktas/{id}/atsaukti`, reason required, the other side is emailed). Less than a day before the work counts as late, unless the reason is "agreed by both". The employer can mark a no-show for `NO_SHOW_DAYS` after the work. "Patikimumas" = completed jobs against late cancellations and no-shows (`worker_reliability()`, `venue_reliability()`).
- Team = past GANG contracts plus people who joined through the manager's link `/k/{code}` (`team_links`). "Pirmiausia pasiūlyti komandai" sets `requests.public_at`; until then only invited team members see the request, and `release_team_requests()` (cron and page views) sends it to everyone else. A worker's `/k/{code}` is a referral link (`users.referred_by`).
- Events: one form, several roles; each role is its own request sharing `event_id` and `event_title`.
- Reviews have three criteria (`REVIEW_CRITERIA`); `rating` is their rounded average. Pay hints use `PAY_GUIDE` until a role and city have `PAY_GUIDE_MIN` GANG requests in `PAY_GUIDE_DAYS`; pay at or above the upper end gets "Geras atlygis".
- Admin = the user whose email equals `admin_email` in config.
- Schema changes: bump `SCHEMA_VERSION` in `schema.php` and add the statements; the marker file in `private/data/` makes it run once.
- Worker photos are stored in `private/data/photos/` (outside the web root, never overwritten by deploys) and served by `/nuotrauka/{id}` only to managers, the worker and the admin.
- Home page photos: run `tools/cctv.sh photo.png 02` (needs ImageMagick) to make `public_html/img/cam-02.jpg` in the CCTV style; cameras 01 salė, 02 virtuvė, 03 aptarnavimas.

## Site lock

GANG is open to everyone and sign-up works straight away (`SITE_OPEN = true` in `private/app/preview.php`, since 2026-11-07). Setting `SITE_OPEN = false` locks the site again: every visitor without the shared team password (`PREVIEW_PASSWORD` secret) sees one "Netrukus" page, while `/admin` stays reachable. Accounts made before launch get a one-time "GANG jau veikia" email from admin → Laukiantieji.

## Deploy

Every push to `main` is uploaded by `.github/workflows/deploy.yml` over FTPS, and `private/config.php` is written from repository secrets (see the comments at the top of the workflow). Hostinger's own Git deployment must stay off.

One-time setup in hPanel: create the MySQL database, the mailbox `hello@gang.lt` (PHP `mail()` sends from it), and a cron job `php /home/<user>/domains/gang.lt/private/cron.php` every hour.

## Testing locally

`private/config.php` with an SQLite DSN works for a quick run (`'db_dsn' => 'sqlite:/tmp/gang.db'`), then `php -S localhost:8080 router.php` from `public_html`, where the router serves existing files and otherwise includes `index.php`. Defining `GANG_MAIL_CAPTURE` (a file path) in the router writes outgoing mails to that file instead of sending them; `GANG_MAIL_HTML_DIR` (a folder) also saves each HTML version for a look in the browser. Emails are built from parts in `private/app/mail.php` (dark card, lime button, logo `public_html/img/mail-logo.png`) and always go out as HTML with a plain-text alternative.
