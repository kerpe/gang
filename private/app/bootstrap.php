<?php

declare(strict_types=1);

const CITIES = ['vilnius' => 'Vilnius', 'kaunas' => 'Kaunas', 'klaipeda' => 'Klaipėda', 'palanga' => 'Palanga'];
const ROLES = [
    'barmenas' => 'Barmenas',
    'padavejas' => 'Padavėjas',
    'barista' => 'Barista',
    'virejas' => 'Virėjas',
    'sefas' => 'Šefas',
    'plovejas' => 'Indų plovėjas',
    'administratorius' => 'Administratorius',
    'vadovas' => 'Salės ar baro vadovas',
    'someljee' => 'Someljė',
    'pagalbinis' => 'Pagalbinis darbuotojas',
];
const KINDS = [
    'skubu' => 'Skubiai: diena ar kelios',
    'trumpai' => 'Savaitė ar mėnuo',
    'sezonas' => 'Sezonas',
    'nuolat' => 'Nuolatinis darbas',
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
    4 => 'Ne mažiau kaip 3 metai',
    5 => 'Daugiau nei 5 metai',
];
const REVIEW_HIDE_DAYS = 7;
const AVAILABILITY_MONTHS = 6;
const VENUE_ABOUT_MIN = 80;

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

function start_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_set_cookie_params(['lifetime' => 60 * 60 * 24 * 60, 'httponly' => true, 'samesite' => 'Lax', 'secure' => str_starts_with(config()['public_url'], 'https://')]);
        ini_set('session.gc_maxlifetime', (string) (60 * 60 * 24 * 60));
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

function send_mail(string $to, string $subject, string $text, string $replyTo = ''): bool
{
    $c = config();
    $text .= "\n\nPagarbiai\nGANG komanda\n\n--\nGANG.lt – darbas hospitality komandoms\n".url('/');
    if (defined('GANG_MAIL_CAPTURE')) {
        file_put_contents(GANG_MAIL_CAPTURE, "To: $to\nSubject: $subject\n\n$text\n-----\n", FILE_APPEND);
        return true;
    }
    if ($c['resend_key'] !== '') {
        $body = ['from' => $c['mail_from'], 'to' => [$to], 'subject' => $subject, 'text' => $text];
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
        return $res !== false && str_contains($http_response_header[0] ?? '', ' 200');
    }
    $headers = ['From' => $c['mail_from'], 'Content-Type' => 'text/plain; charset=UTF-8', 'MIME-Version' => '1.0'];
    if ($replyTo !== '') {
        $headers['Reply-To'] = $replyTo;
    }
    // Envelope sender on the site's own mailbox, so Hostinger accepts and signs the mail.
    $envelope = preg_match('/[^<\s]+@[^>\s]+/', $c['mail_from'], $m) ? '-f'.$m[0] : '';
    return mail($to, '=?UTF-8?B?'.base64_encode($subject).'?=', $text, $headers, $envelope);
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

function period(array $r): string
{
    $from = date_lt($r['start_date']);
    if (!$r['end_date'] || $r['end_date'] === $r['start_date']) {
        return $r['kind'] === 'nuolat' ? 'nuo '.$from : $from;
    }
    return $from.' – '.date_lt($r['end_date']);
}
