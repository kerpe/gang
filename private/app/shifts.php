<?php

declare(strict_types=1);

// Arrival confirmation before a shift. The day before each work period starts, at SHIFT_REMIND_HOUR,
// the worker gets an email and an SMS with a link to confirm. If nothing is confirmed by
// SHIFT_ALERT_HOUR, the employer gets a calm heads-up (write to the worker first, replacement if needed).
// The worker never sees a deadline: they may be at work and confirm later (Vilius 2026-11-08).
const SHIFT_REMIND_HOUR = 10;
const SHIFT_ALERT_HOUR = 18;

// Told to the worker before applying and again when accepted.
function shift_notice(): string
{
    return 'Darbo išvakarėse gausite priminimą su nuoroda atvykimui patvirtinti. Patvirtinimas padeda darbdaviui ramiai planuoti pamainą. '
        .'Jei planai pasikeistų, tame pačiame puslapyje praneškite, kad negalėsite atvykti.';
}

// The contract's work periods as [from, to] pairs; a permanent job has one, its first day.
function contract_ranges(array $c): array
{
    return request_ranges($c);
}

function tomorrow(): string
{
    return date('Y-m-d', strtotime('+1 day'));
}

// "+37061234567" from "861234567", "+370 612 34567", "0037061234567"; '' when it does not look like a phone.
function sms_phone(string $phone): string
{
    $p = preg_replace('/[^0-9+]/', '', $phone);
    $p = preg_replace('/^00/', '+', $p);
    if (preg_match('/^8(\d{8})$/', $p, $m)) {
        $p = '+370'.$m[1];
    } elseif (preg_match('/^370\d{8}$/', $p)) {
        $p = '+'.$p;
    }
    return preg_match('/^\+\d{9,15}$/', $p) ? $p : '';
}

// SMS through Twilio (sms_provider = twilio, sms_sid, sms_token, sms_from). Without them, nothing is sent.
function send_sms(string $phone, string $text): bool
{
    $c = config() + ['sms_provider' => '', 'sms_token' => '', 'sms_sid' => '', 'sms_from' => 'GANG'];
    $to = sms_phone($phone);
    if ($to === '') {
        return false;
    }
    if (defined('GANG_MAIL_CAPTURE')) {
        file_put_contents(GANG_MAIL_CAPTURE, "SMS to: $to\n\n$text\n-----\n", FILE_APPEND);
        return true;
    }
    if ($c['sms_provider'] === 'twilio' && $c['sms_token'] !== '' && $c['sms_sid'] !== '') {
        $res = http_post('https://api.twilio.com/2010-04-01/Accounts/'.rawurlencode($c['sms_sid']).'/Messages.json', ['To' => $to, 'From' => $c['sms_from'], 'Body' => $text],
            'Authorization: Basic '.base64_encode($c['sms_sid'].':'.$c['sms_token']));
        return $res !== null && str_contains($res, '"sid"');
    }
    return false;
}

function http_post(string $url, array $fields, string $auth): ?string
{
    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "$auth\r\nContent-Type: application/x-www-form-urlencoded\r\n",
        'content' => http_build_query($fields),
        'timeout' => 15,
        'ignore_errors' => true,
    ]]);
    $res = @file_get_contents($url, false, $ctx);
    return $res === false || !preg_match('/ 2\d\d /', $http_response_header[0] ?? '') ? null : $res;
}

const SHIFT_SELECT = 'SELECT s.*, c.request_id, c.worker_id, r.role, r.kind, v.name AS venue_name, v.address, v.city, v.manager_id,
        w.name AS worker_name, w.email AS worker_email, w.phone AS worker_phone, m.name AS manager_name, m.email AS manager_email, m.phone AS manager_phone
    FROM shift_checks s JOIN contracts c ON c.id = s.contract_id JOIN requests r ON r.id = c.request_id JOIN venues v ON v.id = c.venue_id
    JOIN users w ON w.id = c.worker_id JOIN users m ON m.id = v.manager_id';

// Link that opens a new urgent request for tomorrow, filled in from this job, without the worker who is not coming.
function replacement_url(array $s): string
{
    return '/poreikis/naujas?pagal='.(int) $s['request_id'].'&pakeisti='.(int) $s['worker_id'];
}

// Hourly (cron): reminders between SHIFT_REMIND_HOUR and SHIFT_ALERT_HOUR, warnings after SHIFT_ALERT_HOUR.
function run_shift_checks(?int $hour = null): void
{
    $hour ??= (int) date('G');
    if ($hour >= SHIFT_REMIND_HOUR && $hour < SHIFT_ALERT_HOUR) {
        // Contracts agreed today need no reminder: both sides have just talked.
        $contracts = sql('SELECT c.*, r.kind, '.CONTRACT_DATES.", r.status FROM contracts c JOIN requests r ON r.id = c.request_id
            WHERE c.cancelled_at IS NULL AND c.created_at < ? AND ".CONTRACT_FROM.' <= ? AND '.CONTRACT_TO." >= ?
            AND NOT EXISTS (SELECT 1 FROM shift_checks s WHERE s.contract_id = c.id AND s.shift_date = ?)", [today().' 00:00:00', tomorrow(), tomorrow(), tomorrow()])->fetchAll();
        foreach ($contracts as $c) {
            if ($c['status'] === 'cancelled' || !in_array(tomorrow(), array_column(contract_ranges($c), 0), true)) {
                continue;
            }
            $id = insert('shift_checks', ['contract_id' => $c['id'], 'shift_date' => tomorrow(), 'token' => bin2hex(random_bytes(16)), 'reminded_at' => now()]);
            $s = sql(SHIFT_SELECT.' WHERE s.id = ?', [$id])->fetch();
            $link = url('/pamaina/'.$s['token']);
            send_mail($s['worker_email'], 'Patvirtinkite rytojaus pamainą: '.$s['venue_name'], [
                'kicker' => 'Rytojaus pamaina',
                'title' => 'Patvirtinkite atvykimą',
                'lead' => 'rytoj, '.date_lt(tomorrow()).", jūsų laukia **{$s['venue_name']}**.",
                'facts' => ['Pareigos' => ROLES[$s['role']] ?? '', 'Data' => date_lt(tomorrow()), 'Adresas' => full_address($s)],
                'body' => 'Patvirtinkite atvykimą, kai turėsite laisvą minutę. Jei negalėsite atvykti, praneškite tame pačiame puslapyje, kad darbdavys spėtų rasti pakaitinį darbuotoją.',
                'cta' => ['Patvirtinti atvykimą', $link],
            ]);
            send_sms($s['worker_phone'], 'GANG: rytoj dirbate '.ascii($s['venue_name']).'. Patvirtinkite atvykima: '.$link);
        }
    }
    if ($hour >= SHIFT_ALERT_HOUR) {
        $open = sql(SHIFT_SELECT.' WHERE s.shift_date = ? AND s.confirmed_at IS NULL AND s.alerted_at IS NULL', [tomorrow()])->fetchAll();
        foreach ($open as $s) {
            alert_manager($s, false);
        }
    }
}

// Tells the employer that tomorrow's worker has not confirmed (or said they cannot come).
function alert_manager(array $s, bool $declined): void
{
    sql('UPDATE shift_checks SET alerted_at = ? WHERE id = ?', [now(), $s['id']]);
    $who = first_name($s['worker_name']);
    $role = mb_strtolower(ROLES[$s['role']] ?? '');
    $link = url(replacement_url($s));
    $what = $declined ? "$who pranešė, kad rytoj (".date_lt($s['shift_date']).") negalės atvykti į pamainą" : "$who kol kas nepatvirtino rytojaus pamainos";
    send_mail($s['manager_email'], ($declined ? 'Darbuotojas neatvyks rytoj: ' : 'Dar nepatvirtinta rytojaus pamaina: ').$who.' – '.$s['venue_name'], [
        'kicker' => 'Rytojaus pamaina',
        'title' => $declined ? 'Darbuotojas neatvyks' : 'Atvykimas dar nepatvirtintas',
        'lead' => "$what.",
        'facts' => ['Darbuotojas' => "$who, $role", 'Darbovietė' => $s['venue_name']],
        'body' => [
            $declined ? '' : 'Tai nebūtinai reiškia, kad darbuotojas neatvyks, galbūt šiuo metu dirba ir patvirtins vėliau. Rekomenduojame parašyti GANG žinutėse. Kai darbuotojas atvykimą patvirtins, apie tai jus informuosime.',
            ($declined ? 'Jei reikalingas' : 'Jei paaiškės, kad reikalingas').' pakaitinis darbuotojas, skubią paiešką rytojui paskelbsite vienu paspaudimu. Forma bus užpildyta pagal šį darbą, o jūsų komandos nariai gaus asmeninį kvietimą.',
        ],
        'cta' => ['Rasti pakaitinį darbuotoją', $link],
    ]);
    send_sms($s['manager_phone'], 'GANG: '.ascii($who).($declined ? ' rytoj neatvyks. Pakaitinis darbuotojas: '.$link : ' dar nepatvirtino rytojaus pamainos. Pakaitinis: '.$link));
}

// SMS text without Lithuanian letters fits one 160-character message.
function ascii(string $s): string
{
    return strtr($s, ['ą' => 'a', 'č' => 'c', 'ę' => 'e', 'ė' => 'e', 'į' => 'i', 'š' => 's', 'ų' => 'u', 'ū' => 'u', 'ž' => 'z',
        'Ą' => 'A', 'Č' => 'C', 'Ę' => 'E', 'Ė' => 'E', 'Į' => 'I', 'Š' => 'S', 'Ų' => 'U', 'Ū' => 'U', 'Ž' => 'Z', '„' => '"', '“' => '"', '–' => '-', "\u{2060}" => '', "\u{00A0}" => ' ']);
}

// /pamaina/{token}: the worker confirms or declines tomorrow's shift. The link itself is the key, no login needed.
function shift_page(string $token): never
{
    $s = sql(SHIFT_SELECT.' WHERE s.token = ?', [$token])->fetch();
    if (!$s) {
        not_found();
    }
    $past = $s['shift_date'] < today();
    if (is_post() && !$past) {
        check_csrf();
        if (input('answer') === 'yes' && !$s['confirmed_at']) {
            sql('UPDATE shift_checks SET confirmed_at = ?, declined_at = NULL WHERE id = ?', [now(), $s['id']]);
            if ($s['alerted_at']) {
                send_mail($s['manager_email'], 'Pamaina patvirtinta: '.first_name($s['worker_name']).' – '.$s['venue_name'], [
                    'kicker' => 'Rytojaus pamaina',
                    'title' => 'Atvykimas patvirtintas',
                    'lead' => '**'.first_name($s['worker_name']).'** patvirtino, kad atvyks į pamainą '.date_lt($s['shift_date'])." ({$s['venue_name']}).",
                    'cta' => ['Mano profilis', url('/mano')],
                ]);
            }
            flash('Ačiū. Atvykimas patvirtintas, darbdavys tai matys savo paskyroje.');
        } elseif (input('answer') === 'no' && !$s['declined_at']) {
            sql('UPDATE shift_checks SET declined_at = ?, confirmed_at = NULL WHERE id = ?', [now(), $s['id']]);
            alert_manager($s, true);
            flash('Darbdavys informuotas, kad negalėsite atvykti. Jei norite, parašykite jam ir GANG žinutėse.');
        }
        redirect('/pamaina/'.$token);
    }
    $status = match (true) {
        (bool) $s['confirmed_at'] => '<p class="notice ok">Atvykimą patvirtinote. Iki pasimatymo darbe.</p>',
        (bool) $s['declined_at'] => '<p class="notice tip">Pranešėte, kad negalėsite atvykti. Darbdavys informuotas.</p>',
        $past => '<p class="notice">Ši pamaina jau praėjo.</p>',
        default => '',
    };
    $buttons = $past ? '' : '<div class="shift-answer">'
        .($s['confirmed_at'] ? '' : post_button('/pamaina/'.$token, 'Patvirtinu, atvyksiu', ['answer' => 'yes'], 'btn'))
        .($s['declined_at'] ? '' : post_button('/pamaina/'.$token, 'Negalėsiu atvykti', ['answer' => 'no'], 'btn ghost', 'Ar tikrai negalėsite atvykti? Darbdavys bus informuotas iš karto.'))
        .'</div>';
    page('Pamainos patvirtinimas', '<section class="wrap narrow"><p class="eyebrow">Pamainos patvirtinimas</p><h1>'.e(ROLES[$s['role']] ?? '').'</h1>'
        .'<ul class="facts"><li><b>Kada</b>'.e(date_lt($s['shift_date'])).'</li><li><b>Kur</b>'.e($s['venue_name']).', '.e(full_address($s)).'</li>'
        .'<li><b>Kontaktas</b>'.e($s['manager_name']).'</li></ul>'
        .$status.$buttons.'<p class="meta"><a href="/darbas/'.(int) $s['request_id'].'">Darbo pasiūlymo informacija</a></p></section>', ['noindex' => true]);
}

// The next confirmation for a contract (today or tomorrow), shown on the contract card.
function shift_status(array $c, string $side): string
{
    $s = sql(SHIFT_SELECT.' WHERE s.contract_id = ? AND s.shift_date >= ? ORDER BY s.shift_date LIMIT 1', [$c['id'], today()])->fetch();
    if (!$s) {
        return '';
    }
    $day = $s['shift_date'] === today() ? 'šiandien' : 'rytoj';
    if ($s['confirmed_at']) {
        return '<p class="shift-status ok">Atvykimas '.$day.' patvirtintas</p>';
    }
    if ($side === 'worker') {
        return $s['declined_at']
            ? '<p class="shift-status warn">Pranešėte, kad '.$day.' neatvyksite</p>'
            : '<p class="shift-status wait">Patvirtinkite, ar atvyksite '.$day.' <a class="btn small" href="/pamaina/'.e($s['token']).'">Patvirtinti</a></p>';
    }
    $replace = '<a class="btn small" href="'.e(chat_url(['request_id' => $s['request_id'], 'id' => $s['contract_id']])).'">Parašyti</a>'
        .'<a class="btn small ghost" href="'.e(replacement_url($s)).'">Rasti pakaitinį</a>';
    if ($s['declined_at']) {
        return '<p class="shift-status warn">Darbuotojas pranešė, kad '.$day.' neatvyks '.$replace.'</p>';
    }
    return $s['alerted_at']
        ? '<p class="shift-status warn">Atvykimas '.$day.' dar nepatvirtintas '.$replace.'</p>'
        : '<p class="shift-status wait">Laukiama, kol darbuotojas patvirtins atvykimą</p>';
}
