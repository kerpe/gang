# GANG.lt

Darbo birža hospitality komandoms: darbdaviai (restoranai, kavinės, barai ir klubai) skelbia poreikius (skubus pavadavimas, savaitė ar mėnuo, sezonas, nuolatinis darbas), tinkami darbuotojai gauna laišką ir atsiliepia, darbdavys patvirtina (GANG kontraktas), po darbo abu vienas kitą įvertina.

Plain PHP 8.3 + MySQL, no build step. Live: https://gang.lt (Hostinger).

## Layout

| Path | What it is |
|---|---|
| `public_html/` | Web root: `index.php` (router), `style.css`, `.htaccess`, icons. |
| `private/app/` | Server code: `bootstrap.php` (config, db, mail, lists of cities/roles), `schema.php` (tables, created on first request), `auth.php` (email-code login), `public.php`, `account.php` (profile, dashboard, GANG calendar), `requests.php` (venues, requests, responses, contracts), `reviews.php`, `admin.php`, `data.php` (queries, ratings, notifications), `views.php` (layout, helpers). |
| `private/cron.php` | Hourly job: expires old requests, asks for reviews after the work, cleans login codes. |

## Rules worth knowing

- Login has no passwords: a 6-digit code is emailed (15 min, 5 attempts).
- Phones and the venue address are shown to the other side only after the manager accepts a worker (a contract).
- Reviews only after a contract, both directions; a review becomes visible when both sides have rated or after 7 days (`REVIEW_HIDE_DAYS`). Worker reviews are visible to managers only, venue reviews to workers only. Authors are never shown.
- Either side can cancel a contract (`/kontraktas/{id}/atsaukti`, reason required, the other side is emailed). Less than a day before the work counts as late, unless the reason is "agreed by both". The employer can mark a no-show for `NO_SHOW_DAYS` after the work. "Patikimumas" = completed jobs against late cancellations and no-shows (`worker_reliability()`, `venue_reliability()`).
- Team = past GANG contracts plus people who joined through the manager's link `/k/{code}` (`team_links`). "Pirmiausia pasiūlyti komandai" sets `requests.public_at`; until then only invited team members see the request, and `release_team_requests()` (cron and page views) sends it to everyone else. A worker's `/k/{code}` is a referral link (`users.referred_by`).
- Some days only: on a job of several days the worker ticks the days they can work (`responses.dates`). Approving gives a contract for those days that are still open (`contracts.start_date/end_date/dates`, NULL = the whole job); `request_needs()` counts how many people each day still needs, the job page and cards show only the open days, and the request is filled once every day is covered. After a partial approval the employer is asked whether to keep the remaining days in the ad.
- Editing a search (`/darbas/{id}/redaguoti`): while it is open the employer can change dates, pay and description (not role, city or duration). No candidates = no emails. Waiting candidates get one email about new dates or pay and must confirm (`responses.recheck_at`, `/darbas/{id}/tinka`) before they can be approved. Once a worker is confirmed, dates and pay are locked. An event's dates and description change for all its open roles.
- GANG calendar: the worker's dashboard shows confirmed GANG work (contracts not cancelled) per day (`gang_work_days()`); there is no manual "free days" calendar any more (the `availability` table is kept, unused). Applying for a job on days the worker already works through GANG shows a warning to the worker only; employers never see the worker's other GANG work.
- Chat (`messages.php`): opens on a confirmed contract. Own messages show ✓ (sent) or ✓✓ (seen), the newest one "Perskaityta 18:32" (`chat_reads.read_at`); the header says "Dabar pokalbyje" while the other side has the chat open (`CHAT_HERE_SECONDS`). GANG's own emoji (`GANG_EMOJI`, 16 kitchen and bar symbols without captions; codes of the first set map to new ones via `GANG_EMOJI_OLD`) go into the message field next to the text (the field is a contenteditable box that writes `[gang:kava]` into the hidden textarea; a message of one emoji only shows as a sticker, emails show captions) or put on a message as a reaction (`message_reactions`, one per member and message).
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
