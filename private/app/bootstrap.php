<?php

declare(strict_types=1);

// Dates, "today" and the 10:00 / 18:00 shift reminders follow Lithuanian time.
date_default_timezone_set('Europe/Vilnius');

const CITIES = ['vilnius' => 'Vilnius', 'kaunas' => 'Kaunas', 'klaipeda' => 'Klaipėda', 'siauliai' => 'Šiauliai', 'panevezys' => 'Panevėžys', 'palanga' => 'Palanga'];
const ROLES = [
    'barmenas' => 'Barmenas',
    'barback' => 'Barback',
    'padavejas' => 'Padavėjas',
    'runneris' => 'Runner',
    'barista' => 'Barista',
    'sefas' => 'Šefas',
    'virejas' => 'Virėjas',
    'pagalbinis' => 'Virtuvės pagalbinis darbuotojas',
    'plovejas' => 'Indų plovėjas',
    'administratorius' => 'Administratorius',
    'vadovas' => 'Baro vadovas',
    'someljee' => 'Restorano vadovas',
];
// Roles by team, as on the home page (Baras / Virtuvė / Aptarnavimas); the worker's profile form groups them so.
const ROLE_GROUPS = [
    'Baras' => ['barmenas', 'barback', 'barista', 'vadovas'],
    'Virtuvė' => ['sefas', 'virejas', 'pagalbinis', 'plovejas'],
    'Aptarnavimas' => ['padavejas', 'runneris', 'administratorius', 'someljee'],
];
// Photos shown on a job ad by role (public_html/img/job-{key}.jpg, made by tools/jobimg.sh): key => camera label.
const JOB_PHOTOS = [
    'bar' => 'Main floor', 'club' => 'Club bar', 'wedding' => 'Event bar', 'stock' => 'Stock room',
    'cafe' => 'Coffee bar', 'roastery' => 'Roastery', 'pass' => 'Chef\'s pass', 'kitchen' => 'Kitchen',
    'streetfood' => 'Open kitchen', 'dish' => 'Dish pit', 'dining' => 'Dining room', 'briefing' => 'Briefing',
    'vip' => 'VIP lounge', 'festival' => 'Festival bar', 'sportsbar' => 'Sports bar', 'barprep' => 'Bar prep',
    'window' => 'Coffee window',
];
// A role with several photos: ads take turns by id, so a list of the same role does not repeat one picture.
const ROLE_PHOTOS = [
    'barmenas' => ['sportsbar', 'bar', 'club', 'wedding'],
    'barback' => ['festival', 'club', 'stock'],
    'barista' => ['window', 'cafe', 'roastery'],
    'padavejas' => ['dining', 'briefing'],
    'runneris' => ['dining', 'pass'],
    'sefas' => ['pass', 'kitchen'],
    'virejas' => ['kitchen', 'pass'],
    'pagalbinis' => ['streetfood', 'kitchen'],
    'plovejas' => ['dish'],
    'administratorius' => ['vip', 'dining', 'briefing'],
    'vadovas' => ['barprep', 'stock', 'bar'],
    'someljee' => ['briefing', 'dining'],
];
const KINDS = [
    'trumpai' => 'Trumpam',
    'sezonas' => 'Sezonui',
    'nuolat' => 'Nuolat',
];
// Shown under each duration option in the request form.
const KIND_HINTS = [
    'trumpai' => 'Iki mėnesio',
    'sezonas' => 'Keli mėnesiai',
    'nuolat' => 'Ilgalaikis darbas',
];
// "Darbo paieška": how openly the worker is looking (users.job_search), chosen on the account form.
// looking: employers the worker applies to see "Ieško darbo dabar". watching: offers as usual, no tag.
// hidden: not counted in "tinkami darbuotojai mieste". Job emails follow users.notify in every case.
const JOB_SEARCH = [
    'looking' => 'Ieškau darbo dabar',
    'watching' => 'Tik stebiu pasiūlymus',
    'hidden' => 'Nerodyti manęs darbdaviams',
];
const JOB_SEARCH_HINTS = [
    'looking' => 'Darbdaviai, kuriems pateikiate kandidatūrą, matys žymą „Ieško darbo dabar“.',
    'watching' => 'Pasiūlymus matote kaip įprasta, bet darbdaviams nerodome, kad ieškote darbo.',
    'hidden' => 'Darbdaviai jūsų nematys, kol patys nepateiksite kandidatūros.',
];
// Levels are ordered: a worker matches a request when their experience in the requested
// role (worker_roles.experience) >= min_experience. users.experience keeps the lowest
// level across the worker's roles; 0 means not stated yet.
const EXPERIENCE = [
    1 => 'Be patirties',
    2 => 'Iki 1 metų',
    3 => '1–3 metai',
    4 => '3–5 metai',
    5 => 'Daugiau nei 5 metai',
];
const EXPERIENCE_REQUIRED = [
    1 => 'Patirtis nebūtina',
    2 => 'Bent kelių mėnesių patirtis',
    3 => 'Ne mažiau kaip 1 metai',
];
const REVIEW_HIDE_DAYS = 7;
// How far ahead job dates and worker availability can be planned.
const PLANNING_MONTHS = 12;
const VENUE_ABOUT_MIN = 80;
// Worker profile „Apie save“: required, as long as the venue description (Vilius 2026-11-09).
const WORKER_ABOUT_MIN = 80;
const REQUEST_DESC_MIN = 20;
// Venue profile (darbovietės duomenys). Keys are stored in venues.type / team_size / perks.
const VENUE_TYPES = [
    'restoranas' => 'Restoranas',
    'baras' => 'Baras',
    'kokteiliu-baras' => 'Kokteilių baras',
    'vyno-baras' => 'Vyno baras',
    'alaus-baras' => 'Alaus baras',
    'kavine' => 'Kavinė',
    'bistro' => 'Bistro',
    'klubas' => 'Naktinis klubas',
    'viesbutis' => 'Viešbučio restoranas ar baras',
    'kateringas' => 'Kateringas ir renginiai',
    'kita' => 'Kita',
];
const TEAM_SIZES = [
    '1-5' => 'Iki 5 žmonių',
    '6-15' => '6–15 žmonių',
    '16-30' => '16–30 žmonių',
    '31-60' => '31–60 žmonių',
    '60+' => 'Daugiau nei 60 žmonių',
];
// What a job offers its staff; ticked in every job ad and shown there under "Darbuotojams siūlome".
const VENUE_PERKS = [
    'maitinimas' => 'Personalo maitinimas',
    'arbatpinigiai' => 'Arbatpinigiai',
    'mokymai' => 'Apmokymai vietoje',
    'apranga' => 'Darbo apranga',
    'grafikas' => 'Lankstus darbo grafikas',
    'transportas' => 'Parvežimas po vėlyvos pamainos',
    'apgyvendinimas' => 'Apgyvendinimas sezono metu',
    'nuolaida' => 'Nuolaida personalui',
];
// Why a GANG contract is cancelled, per side. "susitarta" (agreed by both) never counts against reliability.
const CANCEL_REASONS = [
    'worker' => [
        'liga' => 'Liga',
        'aplinkybes' => 'Asmeninės aplinkybės',
        'susitarta' => 'Susitarėme su darbdaviu',
        'kita' => 'Kita priežastis',
    ],
    'manager' => [
        'renginys' => 'Renginys atšauktas',
        'nuolatinis' => 'Radome nuolatinį darbuotoją',
        'nebereikia' => 'Darbuotojo nebereikia',
        'pasikeite' => 'Pasikeitė darbo planai',
        'susitarta' => 'Susitarėme su darbuotoju',
        'kita' => 'Kita priežastis',
    ],
];
// After the work ends, the employer can mark a no-show for this many days.
const NO_SHOW_DAYS = 7;
// A job that starts within this many days counts as urgent: listed and emailed first.
const URGENT_DAYS = 2;
// Sample venues made before launch (Orbita, Kopa, Kampas): their jobs stay visible on gang.lt
// but are kept out of Google (no sitemap entry, no JobPosting, noindex). Remove ids once deleted.
const SEO_HIDDEN_VENUES = [3, 4, 5];
// Review criteria by author: the employer rates the worker, the worker rates the employer.
const REVIEW_CRITERIA = [
    'manager' => ['c1' => 'Punktualumas', 'c2' => 'Darbo kokybė', 'c3' => 'Bendradarbiavimas'],
    'worker' => ['c1' => 'Atsiskaitė kaip sutarta', 'c2' => 'Darbas atitiko aprašymą', 'c3' => 'Požiūris į darbuotojus'],
];
// Usual hourly pay "į rankas" (€) per role, shown while posting a request until GANG has enough
// requests of its own (PAY_GUIDE_MIN in the last PAY_GUIDE_DAYS for the role and city).
const PAY_GUIDE = [
    'barmenas' => [6, 9],
    'barback' => [5, 6.5],
    'padavejas' => [5, 7],
    'runneris' => [5, 6.5],
    'barista' => [5.5, 7.5],
    'virejas' => [7, 10],
    'sefas' => [10, 15],
    'plovejas' => [5, 6.5],
    'administratorius' => [6.5, 9],
    'vadovas' => [8, 12],
    'someljee' => [8, 12],
    'pagalbinis' => [5, 6.5],
];
// For the pay hint: "Vilniuje barmenams dažniausiai siūloma …".
const ROLES_DATIVE = [
    'barmenas' => 'barmenams', 'barback' => 'barbackams', 'padavejas' => 'padavėjams', 'runneris' => 'runneriams', 'barista' => 'baristoms', 'virejas' => 'virėjams', 'sefas' => 'šefams',
    'plovejas' => 'indų plovėjams', 'administratorius' => 'administratoriams', 'vadovas' => 'baro vadovams',
    'someljee' => 'restorano vadovams', 'pagalbinis' => 'virtuvės pagalbiniams darbuotojams',
];
const CITIES_IN = ['vilnius' => 'Vilniuje', 'kaunas' => 'Kaune', 'klaipeda' => 'Klaipėdoje', 'palanga' => 'Palangoje', 'siauliai' => 'Šiauliuose', 'panevezys' => 'Panevėžyje'];
const PAY_GUIDE_MIN = 5;
const PAY_GUIDE_DAYS = 180;
// Order and labels of the venue's links on its page.
const VENUE_LINKS = ['website' => 'Svetainė', 'instagram' => 'Instagram', 'facebook' => 'Facebook', 'tiktok' => 'TikTok'];

function private_root(): string
{
    return dirname(__DIR__);
}

function config(): array
{
    static $c;
    if ($c === null) {
        $file = private_root().'/config.php';
        if (!is_file($file)) {
            http_response_code(503);
            exit('Svetainė ruošiama.');
        }
        $c = (require $file) + ['resend_key' => '', 'mail_from' => 'GANG <hello@gang.lt>', 'request_days' => 30];
    }
    return $c;
}

// One line in private/data/errors.log (shown on /admin → Nustatymai, "Serverio klaidos").
function gang_log(string $message): void
{
    @file_put_contents(private_root().'/data/errors.log', date('Y-m-d H:i:s').' '.str_replace("\n", ' ', $message)."\n", FILE_APPEND | LOCK_EX);
}

function db(): PDO
{
    static $db;
    if ($db === null) {
        $c = config();
        $db = new PDO($c['db_dsn'], $c['db_user'] ?? null, $c['db_password'] ?? null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        require_once __DIR__.'/schema.php';
        migrate($db);
    }
    return $db;
}

function sql(string $query, array $params = []): PDOStatement
{
    $st = db()->prepare($query);
    $st->execute($params);
    return $st;
}

function insert(string $table, array $row): int
{
    $cols = array_keys($row);
    sql("INSERT INTO $table (".implode(', ', $cols).') VALUES ('.implode(', ', array_fill(0, count($cols), '?')).')', array_values($row));
    return (int) db()->lastInsertId();
}

function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function today(): string
{
    return date('Y-m-d');
}

function url(string $path = '/'): string
{
    return rtrim(config()['public_url'], '/').$path;
}

function redirect(string $path): never
{
    header('Location: '.$path, true, 303);
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function input(string $key): string
{
    return trim((string) ($_POST[$key] ?? ''));
}

// Comma-wrapped lists (",vilnius,kaunas,") so one LIKE finds a member.
function to_list(array $values, array $allowed): string
{
    $values = array_values(array_intersect(array_keys($allowed), $values));
    return $values ? ','.implode(',', $values).',' : '';
}

function from_list(?string $list): array
{
    return array_values(array_filter(explode(',', (string) $list)));
}

function labels(?string $list, array $names): string
{
    return implode(', ', array_map(fn ($k) => $names[$k] ?? $k, from_list($list)));
}

// Visitor statistics. Both load only after the visitor presses „Sutinku“ on the cookie banner
// (see analytics_consent() in views.php). An empty id switches that service off.
const GOOGLE_TAG_ID = 'GTM-TQRBP4RH';
const CLARITY_ID = 'zax0thfuru';

// Signed-in members stay signed in this long after their last visit (see keep_signed_in()).
const SESSION_DAYS = 60;

function session_cookie_options(): array
{
    return ['path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => str_starts_with(config()['public_url'], 'https://')];
}

function start_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_set_cookie_params(['lifetime' => SESSION_DAYS * 86400] + session_cookie_options());
        ini_set('session.gc_maxlifetime', (string) (SESSION_DAYS * 86400));
        // Never adopt a session id the server did not create (session fixation).
        ini_set('session.use_strict_mode', '1');
        session_start();
    }
}

function flash(?string $message = null): string
{
    start_session();
    if ($message !== null) {
        $_SESSION['flash'] = $message;
        return '';
    }
    $m = $_SESSION['flash'] ?? '';
    unset($_SESSION['flash']);
    return $m;
}

function csrf_token(): string
{
    start_session();
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="'.e(csrf_token()).'">';
}

function check_csrf(): void
{
    if (!hash_equals(csrf_token(), (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Formos galiojimo laikas baigėsi. Grįžkite atgal, atnaujinkite puslapį ir bandykite dar kartą.');
    }
}

// $mail: the email's parts (see mail.php); sent as HTML with a plain-text alternative.
// $headers: extra headers, e.g. List-Unsubscribe on job-offer emails (job_mail_headers()).
// $lang: '' = the language the recipient chose in their account (Lithuanian by default).
function send_mail(string $to, string $subject, array $mail, string $replyTo = '', array $headers = [], string $lang = ''): bool
{
    $c = config();
    $lang = $lang !== '' ? $lang : mail_lang($to);
    if ($lang === 'en') {
        [$subject, $mail] = i18n_mail($subject, $mail);
    }
    $text = mail_text($mail, $lang);
    $html = mail_html($mail, $subject, $lang);
    if (defined('GANG_MAIL_CAPTURE')) {
        $extra = implode('', array_map(fn ($k, $v) => "$k: $v\n", array_keys($headers), $headers));
        file_put_contents(GANG_MAIL_CAPTURE, "To: $to\nSubject: $subject\n$extra\n$text\n-----\n", FILE_APPEND);
        if (defined('GANG_MAIL_HTML_DIR')) {
            file_put_contents(GANG_MAIL_HTML_DIR.'/'.date('His').'-'.substr(md5($to.$subject.microtime()), 0, 6).'.html', $html);
        }
        return true;
    }
    if ($c['resend_key'] !== '' && resend_mail($c, $to, $subject, $text, $html, $replyTo, $headers)) {
        return true;
    }
    // PHP mail() on Hostinger: also the fallback when Resend is down or refuses the mail.
    $from = preg_match('/[^<\s]+@[^>\s]+/', $c['mail_from'], $m) ? $m[0] : 'hello@gang.lt';
    $boundary = 'gang-'.bin2hex(random_bytes(12));
    $headers = $headers + [
        'From' => $c['mail_from'],
        'Date' => date(DATE_RFC2822),
        'Message-ID' => '<'.bin2hex(random_bytes(16)).'@'.substr(strrchr($from, '@'), 1).'>',
        'MIME-Version' => '1.0',
        'Content-Type' => 'multipart/alternative; boundary="'.$boundary.'"',
    ];
    if ($replyTo !== '') {
        $headers['Reply-To'] = $replyTo;
    }
    $part = fn (string $type, string $content) => "--$boundary\r\nContent-Type: $type; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        .chunk_split(base64_encode($content))."\r\n";
    $message = $part('text/plain', $text).$part('text/html', $html)."--$boundary--\r\n";
    // Envelope sender on the site's own mailbox, so Hostinger accepts and signs the mail.
    $ok = mail($to, '=?UTF-8?B?'.base64_encode($subject).'?=', $message, $headers, '-f'.$from);
    if (!$ok) {
        gang_log("MAIL PHP mail() failed for $to");
    }
    return $ok;
}

// Resend API (DKIM-signed for gang.lt, bounces via send.gang.lt). False on any error, so the caller can fall back.
function resend_mail(array $c, string $to, string $subject, string $text, string $html, string $replyTo, array $headers = []): bool
{
    $body = ['from' => $c['mail_from'], 'to' => [$to], 'subject' => $subject, 'text' => $text, 'html' => $html];
    if ($headers) {
        $body['headers'] = $headers;
    }
    if ($replyTo !== '') {
        $body['reply_to'] = $replyTo;
    }
    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Authorization: Bearer {$c['resend_key']}\r\nContent-Type: application/json\r\n",
        'content' => json_encode($body),
        'timeout' => 15,
        'ignore_errors' => true,
    ]]);
    $res = @file_get_contents('https://api.resend.com/emails', false, $ctx);
    $status = $http_response_header[0] ?? '';
    if ($res !== false && preg_match('#^HTTP/\S+ 2\d\d#', $status)) {
        return true;
    }
    gang_log('MAIL Resend failed for '.$to.': '.$status.' '.substr((string) $res, 0, 300));
    return false;
}

function date_lt(?string $date): string
{
    if (!$date) {
        return '';
    }
    $months = ['', 'sausio', 'vasario', 'kovo', 'balandžio', 'gegužės', 'birželio', 'liepos', 'rugpjūčio', 'rugsėjo', 'spalio', 'lapkričio', 'gruodžio'];
    $t = strtotime($date);
    return $months[(int) date('n', $t)].' '.date('j', $t).' d.';
}

// "šiandien 14:00", "rytoj 9:00" or "lapkričio 7 d. 14:00".
function when_lt(string $datetime): string
{
    $t = strtotime($datetime);
    $day = date('Y-m-d', $t);
    $label = $day === date('Y-m-d') ? 'šiandien' : ($day === date('Y-m-d', strtotime('+1 day')) ? 'rytoj' : date_lt($day));
    return $label.' '.date('G:i', $t);
}

function period(array $r): string
{
    if (!empty($r['dates'])) {
        return implode(', ', array_map(fn ($p) => span_lt(...explode(':', $p)), explode(',', $r['dates'])));
    }
    if (!$r['end_date'] || $r['end_date'] === $r['start_date']) {
        return $r['kind'] === 'nuolat' ? 'nuo '.date_lt($r['start_date']) : date_lt($r['start_date']);
    }
    return span_lt($r['start_date'], $r['end_date']);
}

// "lapkričio 7 d.", "lapkričio 7–8 d." or "lapkričio 30 d. – gruodžio 2 d."
// Word joiners and a no-break space keep "7–8 d." on one line.
function span_lt(string $from, string $to): string
{
    if ($from === $to) {
        return date_lt($from);
    }
    if (substr($from, 0, 7) === substr($to, 0, 7)) {
        return substr(date_lt($from), 0, -3)."\u{2060}–\u{2060}".(int) substr($to, 8)."\u{00A0}d.";
    }
    return date_lt($from).' – '.date_lt($to);
}

require_once __DIR__.'/mail.php';
require_once __DIR__.'/i18n.php';
