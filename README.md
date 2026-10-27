# GANG.lt

Darbo birža hospitality komandoms: įstaigos skelbia poreikius (skubus pavadavimas, savaitė ar mėnuo, sezonas, nuolatinis darbas), tinkami darbuotojai gauna laišką ir atsiliepia, vadovas patvirtina (GANG kontraktas), po darbo abu vienas kitą įvertina.

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
- Admin = the user whose email equals `admin_email` in config.
- Schema changes: bump `SCHEMA_VERSION` in `schema.php` and add the statements; the marker file in `private/data/` makes it run once.

## Preview lock

Until launch every page asks for one shared password (`preview_password`, from the `PREVIEW_PASSWORD` secret) and is sent with `noindex`. Without a password nobody gets past the "Greitai" page. To launch, set the repository variable `SITE_PUBLIC` to `true` and deploy again.

## Deploy

Every push to `main` is uploaded by `.github/workflows/deploy.yml` over FTPS, and `private/config.php` is written from repository secrets (see the comments at the top of the workflow). Hostinger's own Git deployment must stay off.

One-time setup in hPanel: create the MySQL database, the mailbox `info@gang.lt` (PHP `mail()` sends from it), and a cron job `php /home/<user>/domains/gang.lt/private/cron.php` every hour.

## Testing locally

`private/config.php` with an SQLite DSN works for a quick run (`'db_dsn' => 'sqlite:/tmp/gang.db'`), then `php -S localhost:8080 router.php` from `public_html`, where the router serves existing files and otherwise includes `index.php`. Defining `GANG_MAIL_CAPTURE` (a file path) in the router writes outgoing mails to that file instead of sending them.
