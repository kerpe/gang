<?php

declare(strict_types=1);

// Admin is whoever logs in with config admin_email.
function admin_page(): never
{
    $u = require_user();
    if (!is_admin($u)) {
        not_found();
    }
    if (is_post()) {
        check_csrf();
        $id = (int) input('id');
        match (input('action')) {
            'block' => sql('UPDATE users SET blocked = 1 WHERE id = ? AND id <> ?', [$id, $u['id']]),
            'unblock' => sql('UPDATE users SET blocked = 0 WHERE id = ?', [$id]),
            'delete_review' => sql('DELETE FROM reviews WHERE id = ?', [$id]),
            'close_request' => sql("UPDATE requests SET status = 'cancelled' WHERE id = ?", [$id]),
            'invite_waitlist' => flash(invite_waitlist().' laiškų išsiųsta.') ?: redirect('/admin?tab=waitlist'),
            default => null,
        };
        flash('Pakeitimas atliktas.');
        redirect('/admin'.(isset($_GET['tab']) ? '?tab='.urlencode((string) $_GET['tab']) : ''));
    }
    $tab = $_GET['tab'] ?? 'stats';
    $tabs = ['stats' => 'Rodikliai', 'requests' => 'Paieškos', 'users' => 'Nariai', 'reviews' => 'Įvertinimai', 'waitlist' => 'Laukiantieji'];
    $nav = '<nav class="tabs">';
    foreach ($tabs as $k => $label) {
        $nav .= '<a href="/admin?tab='.$k.'"'.($tab === $k ? ' class="active"' : '').'>'.$label.'</a>';
    }
    $nav .= '</nav>';
    $body = match ($tab) {
        'requests' => admin_requests(),
        'users' => admin_users(),
        'reviews' => admin_reviews(),
        'waitlist' => admin_waitlist(),
        default => admin_stats(),
    };
    page('Administravimas', '<section class="wrap"><h1>Administravimas</h1>'.$nav.$body.'</section>', ['noindex' => true]);
}

function admin_stats(): string
{
    $count = fn (string $q, array $p = []) => (int) sql($q, $p)->fetchColumn();
    $since = date('Y-m-d H:i:s', strtotime('-30 days'));
    $tiles = [
        'Darbuotojų' => $count("SELECT COUNT(*) FROM users WHERE kind = 'worker'"),
        'Laisvi šiandien' => $count("SELECT COUNT(DISTINCT user_id) FROM availability WHERE start_date <= ? AND end_date >= ?", [today(), today()]),
        'Darboviečių' => $count('SELECT COUNT(*) FROM venues'),
        'Paieškų (30 d.)' => $count('SELECT COUNT(*) FROM requests WHERE created_at > ?', [$since]),
        'Kontraktų (30 d.)' => $count('SELECT COUNT(*) FROM contracts WHERE created_at > ?', [$since]),
        'Įvertinimų' => $count('SELECT COUNT(*) FROM reviews'),
    ];
    $html = '<div class="tiles">';
    foreach ($tiles as $label => $n) {
        $html .= '<div class="tile"><span>'.$label.'</span><strong>'.$n.'</strong></div>';
    }
    $html .= '</div>';
    // Fill rate and time to first contract, per kind: the numbers that later sell subscriptions.
    $rows = sql('SELECT r.kind, r.created_at, r.status, (SELECT MIN(c.created_at) FROM contracts c WHERE c.request_id = r.id) AS first_contract FROM requests r WHERE r.created_at > ?', [date('Y-m-d H:i:s', strtotime('-90 days'))])->fetchAll();
    $table = '<h2>Kaip greitai užpildoma (90 d.)</h2><table class="data"><tr><th>Tipas</th><th>Paieškų</th><th>Užpildyta</th><th>Mediana iki patvirtinimo</th></tr>';
    foreach (KINDS as $k => $label) {
        $mine = array_filter($rows, fn ($r) => $r['kind'] === $k);
        $times = [];
        foreach ($mine as $r) {
            if ($r['first_contract']) {
                $times[] = (strtotime($r['first_contract']) - strtotime($r['created_at'])) / 3600;
            }
        }
        sort($times);
        $median = $times ? $times[intdiv(count($times), 2)] : null;
        $medianText = $median === null ? '–' : ($median < 1 ? round($median * 60).' min.' : number_format($median, 1, ',', '').' val.');
        $filledPct = $mine ? round(count($times) / count($mine) * 100).' %' : '–';
        $table .= '<tr><td>'.e($label).'</td><td>'.count($mine).'</td><td>'.$filledPct.'</td><td>'.$medianText.'</td></tr>';
    }
    return $html.$table.'</table>';
}

function admin_requests(): string
{
    $rows = sql('SELECT r.*, v.name AS venue_name, v.city, (SELECT COUNT(*) FROM responses s WHERE s.request_id = r.id) AS responses, (SELECT COUNT(*) FROM contracts c WHERE c.request_id = r.id) AS contracts
        FROM requests r JOIN venues v ON v.id = r.venue_id ORDER BY r.created_at DESC LIMIT 200')->fetchAll();
    $html = '<table class="data"><tr><th>Sukurta</th><th>Darbovietė</th><th>Pareigos</th><th>Tipas</th><th>Kada</th><th>Laiškų</th><th>Atsil.</th><th>Kontr.</th><th>Būsena</th><th></th></tr>';
    foreach ($rows as $r) {
        $html .= '<tr><td>'.e(substr($r['created_at'], 0, 16)).'</td><td>'.e($r['venue_name']).', '.e(CITIES[$r['city']] ?? '').'</td><td><a href="/darbas/'.(int) $r['id'].'">'.e(ROLES[$r['role']] ?? '').'</a></td>'
            .'<td>'.e($r['kind']).'</td><td>'.e(period($r)).'</td><td>'.(int) $r['notified'].'</td><td>'.(int) $r['responses'].'</td><td>'.(int) $r['contracts'].'</td><td>'.e($r['status']).'</td>'
            .'<td>'.($r['status'] === 'open' ? post_button('/admin?tab=requests', 'Uždaryti', ['action' => 'close_request', 'id' => $r['id']], 'btn small ghost', 'Ar tikrai norite uždaryti šią paiešką?') : '').'</td></tr>';
    }
    return '<div class="scroll">'.$html.'</table></div>';
}

function admin_users(): string
{
    $rows = sql('SELECT u.*, (SELECT COUNT(*) FROM contracts c WHERE c.worker_id = u.id) AS contracts FROM users u ORDER BY u.created_at DESC LIMIT 500')->fetchAll();
    $html = '<table class="data"><tr><th>Registruotas</th><th>Tipas</th><th>Vardas</th><th>El. paštas</th><th>Telefonas</th><th>Miestai / pareigos</th><th>Kontr.</th><th></th></tr>';
    foreach ($rows as $r) {
        $btn = $r['blocked']
            ? post_button('/admin?tab=users', 'Atblokuoti', ['action' => 'unblock', 'id' => $r['id']], 'btn small ghost')
            : post_button('/admin?tab=users', 'Blokuoti', ['action' => 'block', 'id' => $r['id']], 'btn small ghost', 'Ar tikrai norite užblokuoti šį narį?');
        $name = $r['kind'] === 'worker' ? '<a href="/darbuotojas/'.(int) $r['id'].'">'.e($r['name']).'</a>' : e($r['name']);
        $html .= '<tr'.($r['blocked'] ? ' class="blocked"' : '').'><td>'.e(substr($r['created_at'], 0, 10)).'</td><td>'.($r['kind'] === 'worker' ? 'Darbuotojas' : 'Darbdavys').'</td><td>'.$name.'</td><td>'.e($r['email']).'</td><td>'.e($r['phone']).'</td>'
            .'<td>'.e(labels($r['cities'], CITIES)).'<br><small>'.e(labels($r['roles'], ROLES)).'</small></td><td>'.(int) $r['contracts'].'</td><td>'.$btn.'</td></tr>';
    }
    return '<div class="scroll">'.$html.'</table></div>';
}

function admin_reviews(): string
{
    $rows = sql('SELECT rv.*, w.name AS worker_name, v.name AS venue_name FROM reviews rv JOIN contracts c ON c.id = rv.contract_id JOIN users w ON w.id = c.worker_id JOIN venues v ON v.id = c.venue_id ORDER BY rv.created_at DESC LIMIT 300')->fetchAll();
    $html = '<p class="meta">Čia matomi visi įvertinimai, įskaitant dar nepaskelbtus. Trinkite tik įžeidžiančius, melagingus ar asmeninę informaciją atskleidžiančius įvertinimus.</p><table class="data"><tr><th>Data</th><th>Kas ką</th><th>Balas</th><th>Komentaras</th><th></th></tr>';
    foreach ($rows as $r) {
        $who = $r['author'] === 'manager' ? e($r['venue_name']).' → '.e($r['worker_name']) : e($r['worker_name']).' → '.e($r['venue_name']);
        $html .= '<tr><td>'.e(substr($r['created_at'], 0, 10)).'</td><td>'.$who.'</td><td>'.(int) $r['rating'].'</td><td>'.nl2br(e($r['text'])).'</td><td>'
            .post_button('/admin?tab=reviews', 'Ištrinti', ['action' => 'delete_review', 'id' => $r['id']], 'btn small ghost', 'Ar tikrai norite ištrinti šį įvertinimą?').'</td></tr>';
    }
    return '<div class="scroll">'.$html.'</table></div>';
}

function admin_waitlist(): string
{
    $rows = sql('SELECT w.*, u.id AS user_id FROM waitlist w LEFT JOIN users u ON u.email = w.email ORDER BY w.created_at DESC LIMIT 2000')->fetchAll();
    $profiles = (int) sql("SELECT COUNT(*) FROM users WHERE kind = 'worker' AND blocked = 0 AND roles <> '' AND name <> ''")->fetchColumn();
    $public = !empty(config()['public']);
    [$invites, $launchMails] = waitlist_pending();
    $pending = $invites + ($public ? $launchMails : 0);
    $label = $public ? 'Siųsti paleidimo laiškus ('.$pending.')' : 'Pakviesti užsiregistruoti ('.$pending.')';
    $confirm = $public
        ? 'Pranešti visiems užsiregistravusiems, kad GANG jau veikia?'
        : 'Išsiųsti kvietimą užsiregistruoti visiems sąraše esantiems, kurie paskyros dar nesusikūrė ir kvietimo negavo?';
    $invite = $pending ? post_button('/admin?tab=waitlist', $label, ['action' => 'invite_waitlist', 'id' => 0], 'btn small', $confirm) : '<p class="meta">Visi laiškai išsiųsti.</p>';
    $note = $public
        ? '<p class="meta">Paleidimo laiškai: darbuotojams – kvietimas žymėti laisvas dienas, darbdaviams – kvietimas paskelbti pirmą paiešką; dar neužsiregistravusiems iš sąrašo – kvietimas registruotis. Kiekvienas laiškas siunčiamas tik kartą.</p>'
        : '<p class="meta">Iki paleidimo darbuotojai jau gali susikurti profilį, o darbdaviai – užregistruoti darbovietę. Senojo sąrašo nariams galite išsiųsti kvietimą užsiregistruoti. Kai svetainė bus atverta visiems (SITE_PUBLIC = true), čia galėsite išsiųsti paleidimo laiškus ('.$launchMails.').</p>';
    $venues = (int) sql('SELECT COUNT(DISTINCT manager_id) FROM venues')->fetchColumn();
    $html = '<div class="tiles"><div class="tile"><span>Darbuotojų profilių</span><strong>'.$profiles.'</strong></div><div class="tile"><span>Darbdavių su darboviete</span><strong>'.$venues.'</strong></div>'
        .'<div class="tile"><span>Sąraše be paskyros</span><strong>'.count(array_filter($rows, fn ($r) => !$r['user_id'])).'</strong></div></div>'.$note.$invite
        .'<table class="data"><tr><th>Data</th><th>El. paštas</th><th>Ieško</th><th>Miestas</th><th>Pakviesta</th><th>Paskyra</th></tr>';
    foreach ($rows as $r) {
        $html .= '<tr><td>'.e(substr($r['created_at'], 0, 16)).'</td><td>'.e($r['email']).'</td><td>'.($r['kind'] === 'worker' ? 'Darbo' : 'Darbuotojų').'</td>'
            .'<td>'.e(CITIES[$r['city']] ?? '–').'</td><td>'.e($r['invited_at'] ? substr($r['invited_at'], 0, 10) : '–').'</td><td>'.($r['user_id'] ? 'Yra' : '–').'</td></tr>';
    }
    return '<div class="scroll">'.$html.'</table></div>';
}

// [invitations to people on the old email-only list, launch emails to accounts made before launch] still to send.
function waitlist_pending(): array
{
    return [
        (int) sql('SELECT COUNT(*) FROM waitlist w WHERE w.invited_at IS NULL AND NOT EXISTS (SELECT 1 FROM users u WHERE u.email = w.email)')->fetchColumn(),
        (int) sql('SELECT COUNT(*) FROM users WHERE blocked = 0 AND launch_mail_at IS NULL')->fetchColumn(),
    ];
}

// People on the old email-only list are invited to sign up (before launch: to prepare their profile or venue).
// Launch day (public): everyone who signed up early hears that GANG is live.
// Every email is marked as sent first, so a second click never repeats it.
function invite_waitlist(): int
{
    $public = !empty(config()['public']);
    $sent = 0;
    foreach (sql('SELECT w.* FROM waitlist w WHERE w.invited_at IS NULL AND NOT EXISTS (SELECT 1 FROM users u WHERE u.email = w.email) ORDER BY w.id LIMIT 500')->fetchAll() as $r) {
        sql('UPDATE waitlist SET invited_at = ? WHERE id = ?', [now(), $r['id']]);
        $worker = $r['kind'] === 'worker';
        $intro = $public ? 'GANG jau veikia. Kaip ir žadėjome, kviečiame jus vienus pirmųjų ' : 'dėkojame, kad užsiregistravote GANG laukiančiųjų sąraše. Kviečiame jus jau dabar ';
        $text = "Laba diena,\n\n".$intro.($worker
            ? ($public
                ? 'susikurti profilį, pažymėti laisvas dienas ir gauti darbo pasiūlymus iš barų, restoranų ir kavinių.'
                : "susikurti profilį: nurodyti pareigas, patirtį ir miestus, kuriuose galite dirbti. ".(launch_when() !== '' ? launch_headline().'. Starto dieną' : 'Paleidimo dieną')." jūsų profilis jau bus matomas darbdaviams.\n\nNuo starto dienos kalendoriuje pažymėsite laiką, kada galite dirbti, ir gausite jums tinkančius darbo pasiūlymus.")
            : ($public
                ? 'užregistruoti savo barą, restoraną ar kavinę ir rasti patikimų žmonių savo komandai pavadavimui, sezonui ar nuolatiniam darbui.'
                : "užregistruoti savo barą, restoraną ar kavinę.\n\n".(launch_when() !== '' ? launch_headline().'. Nuo starto dienos' : 'Nuo paleidimo dienos')." galėsite skelbti darbuotojų paieškas, o tinkami darbuotojai jas gaus iš karto."))
            ."\n\nRegistracija trunka kelias minutes:\n".url('/prisijungti?kaip='.($worker ? 'darbuotojas' : 'vadovas'))
            ."\n\nŠį laišką gavote, nes užsiregistravote GANG laukiančiųjų sąraše. Jei daugiau laiškų gauti nenorite, atsakykite į šį laišką.";
        $subject = $public ? 'GANG jau veikia: kvietimas registruotis' : ($worker ? 'GANG: susikurkite profilį jau dabar' : 'GANG: užregistruokite darbovietę jau dabar');
        if (send_mail($r['email'], $subject, $text)) {
            $sent++;
        }
    }
    if (!$public) {
        return $sent;
    }
    foreach (sql('SELECT * FROM users WHERE blocked = 0 AND launch_mail_at IS NULL ORDER BY id LIMIT 2000')->fetchAll() as $u) {
        sql('UPDATE users SET launch_mail_at = ? WHERE id = ?', [now(), $u['id']]);
        $ready = profile_complete($u) && ($u['kind'] === 'worker' || sql('SELECT id FROM venues WHERE manager_id = ?', [$u['id']])->fetchColumn());
        if (!$ready) {
            $text = "Laba diena,\n\nGANG jau veikia. Jūsų registracija dar neužbaigta. Užbaikite ją: tai trunka kelias minutes.\n\n".url('/profilis');
        } elseif ($u['kind'] === 'worker') {
            $text = "Laba diena,\n\nGANG jau veikia. Jūsų profilis dabar matomas barams, restoranams ir kavinėms jūsų miestuose.\n\nKalendoriuje pažymėkite dienas, kuriomis galite dirbti: darbdaviai, ieškantys žmogaus konkrečiai datai, matys, kad esate laisvi, o tinkamus pasiūlymus gausite el. paštu.\n\nPažymėti laisvas dienas:\n".url('/mano#laisvumas');
        } else {
            $text = "Laba diena,\n\nGANG jau veikia. Nuo šiandien galite skelbti darbuotojų paieškas: pavadavimui, sezonui ar nuolatiniam darbui. Paskelbta paieška iš karto pasiekia tinkamus darbuotojus jūsų mieste.\n\nPaskelbti pirmą paiešką:\n".url('/poreikis/naujas');
        }
        if (send_mail($u['email'], 'GANG jau veikia', $text."\n\nŠį laišką gavote, nes užsiregistravote GANG.")) {
            $sent++;
        }
    }
    return $sent;
}
