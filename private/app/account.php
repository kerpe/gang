<?php

declare(strict_types=1);

function profile_page(): never
{
    $u = require_user();
    $worker = $u['kind'] === 'worker';
    $v = ['name' => $u['name'], 'phone' => $u['phone'], 'about' => (string) $u['about'], 'roles' => from_list($u['roles']), 'cities' => from_list($u['cities']), 'notify' => (bool) $u['notify']];
    $errors = [];
    if (is_post()) {
        check_csrf();
        $v = ['name' => input('name'), 'phone' => input('phone'), 'about' => input('about'),
            'roles' => (array) ($_POST['roles'] ?? []), 'cities' => (array) ($_POST['cities'] ?? []), 'notify' => isset($_POST['notify'])];
        if (mb_strlen($v['name']) < 2 || mb_strlen($v['name']) > 120) {
            $errors['name'] = 'Įrašykite vardą ir pavardę.';
        }
        if (!preg_match('/^\+?[0-9 ()-]{8,20}$/', $v['phone'])) {
            $errors['phone'] = 'Įrašykite telefono numerį, pvz. +370 600 00000.';
        }
        if ($worker && to_list($v['roles'], ROLES) === '') {
            $errors['roles'] = 'Pažymėkite bent vienas pareigas.';
        }
        if ($worker && to_list($v['cities'], CITIES) === '') {
            $errors['cities'] = 'Pažymėkite bent vieną miestą.';
        }
        if (mb_strlen($v['about']) > 2000) {
            $errors['about'] = 'Iki 2000 simbolių.';
        }
        if (!$errors) {
            $first = !profile_complete($u);
            sql('UPDATE users SET name = ?, phone = ?, about = ?, roles = ?, cities = ?, notify = ? WHERE id = ?', [
                $v['name'], $v['phone'], $v['about'], $worker ? to_list($v['roles'], ROLES) : '', $worker ? to_list($v['cities'], CITIES) : '', $v['notify'] ? 1 : 0, $u['id'],
            ]);
            if (!$worker && !sql('SELECT id FROM venues WHERE manager_id = ?', [$u['id']])->fetchColumn()) {
                redirect('/istaiga/nauja');
            }
            flash($first ? 'Profilis sukurtas. Kai būsite laisvi, pažymėkite tai čia.' : 'Profilis išsaugotas.');
            redirect('/mano');
        }
    }
    $e = 'e';
    $title = profile_complete($u) ? 'Mano profilis' : ($worker ? 'Susikurkite profilį' : 'Jūsų duomenys');
    $workerFields = '';
    if ($worker) {
        $workerFields = '<fieldset><legend>Pareigos, kurias galite dirbti</legend>'.checkboxes('roles', ROLES, $v['roles']).err($errors, 'roles').'</fieldset>'
            .'<fieldset><legend>Miestai, kuriuose galite dirbti</legend>'.checkboxes('cities', CITIES, $v['cities']).err($errors, 'cities').'</fieldset>'
            .'<label>Apie save <small>(patirtis, kur dirbote, ką mokate)</small><textarea name="about" rows="5" maxlength="2000" placeholder="pvz. 3 metai barmenu kokteilių bare, moku dirbti su kasa, turiu higienos pažymėjimą">'.e($v['about']).'</textarea>'.err($errors, 'about').'</label>'
            .'<label class="check"><input type="checkbox" name="notify"'.($v['notify'] ? ' checked' : '').'> Siųsti man laiškus apie naujus tinkamus darbus</label>';
    }
    $phoneNote = $worker ? 'Vadovas jį matys tik patvirtinęs jus darbui.' : 'Darbuotojas jį matys tik po jūsų patvirtinimo.';
    page($title, '<section class="wrap narrow"><h1>'.$title.'</h1>'
        .'<form method="post" class="form" novalidate>'.csrf_field()
        .'<label>Vardas ir pavardė <input name="name" value="'.e($v['name']).'" autocomplete="name" required>'.err($errors, 'name').'</label>'
        .'<label>Telefonas <small>'.$phoneNote.'</small><input type="tel" name="phone" value="'.e($v['phone']).'" autocomplete="tel" required>'.err($errors, 'phone').'</label>'
        .$workerFields
        .'<p class="meta">El. paštas: '.e($u['email']).'</p>'
        .'<button class="btn">Išsaugoti</button></form></section>', ['noindex' => true]);
}

function availability_action(): never
{
    $u = require_user('worker');
    check_csrf();
    $until = match (input('for')) {
        'today' => today(),
        'week' => date('Y-m-d', strtotime('+6 days')),
        'month' => date('Y-m-d', strtotime('+1 month')),
        'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', input('until')) && input('until') >= today() ? input('until') : null,
        default => null,
    };
    sql('UPDATE users SET available_until = ? WHERE id = ?', [$until, $u['id']]);
    flash($until ? 'Pažymėta: laisvas iki '.date_lt($until).'.' : 'Pažymėta: šiuo metu neieškote darbo.');
    redirect('/mano');
}

function dashboard_page(): never
{
    $u = require_user();
    if (!profile_complete($u)) {
        redirect('/profilis');
    }
    $body = $u['kind'] === 'worker' ? worker_dashboard($u) : manager_dashboard($u);
    $admin = is_admin($u) ? ' · <a href="/admin">Administravimas</a>' : '';
    page('Mano GANG', '<section class="wrap">'.$body
        .'<p class="account-links"><a href="/profilis">Redaguoti profilį</a>'.$admin.' · '.post_button('/atsijungti', 'Atsijungti', [], 'linkish').'</p></section>', ['noindex' => true]);
}

function worker_dashboard(array $u): string
{
    $available = is_available($u);
    [$avg, $n] = worker_rating((int) $u['id']);
    $status = $available
        ? '<p class="status on">● Laisvas iki '.e(date_lt($u['available_until'])).'</p>'
        : '<p class="status off">● Šiuo metu pažymėta, kad neieškote darbo</p>';
    $quick = '';
    foreach (['today' => 'Šiandien', 'week' => 'Šią savaitę', 'month' => 'Mėnesį'] as $k => $label) {
        $quick .= post_button('/laisvas', $label, ['for' => $k], 'btn small'.($available ? ' ghost' : ''));
    }
    $off = $available ? post_button('/laisvas', 'Nebe laisvas', ['for' => 'off'], 'btn small ghost') : '';
    $availability = '<div class="panel avail"><h2>Ar esate laisvas?</h2>'.$status
        .'<div class="quick">'.$quick.$off.'</div>'
        .'<form method="post" action="/laisvas" class="inline until">'.csrf_field().'<input type="hidden" name="for" value="date"><label>Laisvas iki <input type="date" name="until" min="'.today().'"></label><button class="btn small ghost">Pažymėti</button></form>'
        .'<p class="meta">Vadovai mato, kas laisvas, todėl skubiais atvejais pirmiausia renkasi jus.</p></div>';

    $jobs = open_requests(['roles' => from_list($u['roles']), 'cities' => from_list($u['cities'])], 30);
    $responded = array_column(sql('SELECT request_id, status FROM responses WHERE worker_id = ?', [$u['id']])->fetchAll(), 'status', 'request_id');
    $jobs = array_filter($jobs, fn ($r) => !isset($responded[$r['id']]));
    $jobCards = $jobs ? '<div class="cards">'.implode('', array_map('request_card', $jobs)).'</div>' : '<p class="empty">Dabar jums tinkančių naujų darbų nėra. Kai atsiras, gausite laišką.</p>';

    $contracts = sql('SELECT c.*, r.role, r.kind, r.start_date, r.end_date, r.hours, v.name AS venue_name, v.address, v.city, m.name AS manager_name, m.phone AS manager_phone,
            (SELECT COUNT(*) FROM reviews rv WHERE rv.contract_id = c.id AND rv.author = \'worker\') AS reviewed
        FROM contracts c JOIN requests r ON r.id = c.request_id JOIN venues v ON v.id = c.venue_id JOIN users m ON m.id = v.manager_id
        WHERE c.worker_id = ? ORDER BY c.created_at DESC LIMIT 50', [$u['id']])->fetchAll();
    $contractHtml = '';
    foreach ($contracts as $c) {
        $review = review_link($c, (bool) $c['reviewed']);
        $contractHtml .= '<div class="row-item"><div><strong>'.e(ROLES[$c['role']] ?? '').' · '.e($c['venue_name']).'</strong>'
            .'<p class="meta">'.e(period($c)).($c['hours'] !== '' ? ' · '.e($c['hours']) : '').'<br>'.e($c['address'] ?: CITIES[$c['city']] ?? '')
            .' · '.e($c['manager_name']).' · <a href="tel:'.e(preg_replace('/[^0-9+]/', '', $c['manager_phone'])).'">'.e($c['manager_phone']).'</a></p></div>'.$review.'</div>';
    }
    $pending = sql(REQUEST_SELECT.' JOIN responses s ON s.request_id = r.id WHERE s.worker_id = ? AND s.status = \'sent\' AND r.status = \'open\' ORDER BY s.created_at DESC', [$u['id']])->fetchAll();
    $pendingHtml = '';
    foreach ($pending as $r) {
        $pendingHtml .= request_card($r, '<p class="meta wait">Laukiama vadovo atsakymo</p>');
    }
    return '<h1>Sveiki, '.e(first_name($u['name'])).'</h1>'
        .'<p class="meta">Jūsų įvertinimas: '.stars($avg, $n).' · Darbų per GANG: '.count($contracts).'</p>'
        .$availability
        .($contractHtml ? '<h2>Mano darbai (GANG kontraktai)</h2><div class="rows">'.$contractHtml.'</div>' : '')
        .($pendingHtml ? '<h2>Laukia atsakymo</h2><div class="cards">'.$pendingHtml.'</div>' : '')
        .'<h2>Jums tinkantys darbai</h2>'.$jobCards;
}

function manager_dashboard(array $u): string
{
    $venues = sql('SELECT * FROM venues WHERE manager_id = ? ORDER BY id', [$u['id']])->fetchAll();
    if (!$venues) {
        redirect('/istaiga/nauja');
    }
    $venueHtml = '';
    foreach ($venues as $v) {
        [$avg, $n] = venue_rating((int) $v['id']);
        $venueHtml .= '<li><a href="/istaiga/'.(int) $v['id'].'">'.e($v['name']).'</a> · '.e(CITIES[$v['city']] ?? '').' · '.stars($avg, $n).' · <a href="/istaiga/'.(int) $v['id'].'/redaguoti">Redaguoti</a></li>';
    }
    $ids = array_column($venues, 'id');
    $in = implode(',', array_map('intval', $ids));
    [$where, $params] = open_where();
    $open = sql(REQUEST_SELECT." WHERE r.venue_id IN ($in) AND $where ORDER BY r.start_date", $params)->fetchAll();
    $openHtml = '';
    foreach ($open as $r) {
        $n = (int) sql("SELECT COUNT(*) FROM responses WHERE request_id = ? AND status = 'sent'", [$r['id']])->fetchColumn();
        $label = $n ? "<p class=\"meta new\">Atsiliepė: $n – peržiūrėkite</p>" : '<p class="meta wait">Atsiliepusių dar nėra</p>';
        $openHtml .= request_card($r, $label);
    }
    $contracts = sql('SELECT c.*, r.role, r.kind, r.start_date, r.end_date, r.hours, v.name AS venue_name, w.name AS worker_name, w.phone AS worker_phone,
            (SELECT COUNT(*) FROM reviews rv WHERE rv.contract_id = c.id AND rv.author = \'manager\') AS reviewed
        FROM contracts c JOIN requests r ON r.id = c.request_id JOIN venues v ON v.id = c.venue_id JOIN users w ON w.id = c.worker_id
        WHERE c.venue_id IN ('.$in.') ORDER BY c.created_at DESC LIMIT 50')->fetchAll();
    $contractHtml = '';
    foreach ($contracts as $c) {
        $contractHtml .= '<div class="row-item"><div><strong><a href="/darbuotojas/'.(int) $c['worker_id'].'">'.e($c['worker_name']).'</a> · '.e(ROLES[$c['role']] ?? '').'</strong>'
            .'<p class="meta">'.e($c['venue_name']).' · '.e(period($c)).' · <a href="tel:'.e(preg_replace('/[^0-9+]/', '', $c['worker_phone'])).'">'.e($c['worker_phone']).'</a></p></div>'
            .review_link($c, (bool) $c['reviewed']).'</div>';
    }
    return '<h1>Sveiki, '.e(first_name($u['name'])).'</h1>'
        .'<p class="cta-row"><a class="btn" href="/poreikis/naujas">+ Ieškoti žmogaus</a></p>'
        .'<h2>Aktyvūs poreikiai</h2>'.($openHtml ? '<div class="cards">'.$openHtml.'</div>' : '<p class="empty">Aktyvių poreikių nėra. Susirgo darbuotojas ar artėja sezonas? Paskelbkite poreikį – laisvi žmonės gaus laišką iškart.</p>')
        .($contractHtml ? '<h2>GANG kontraktai</h2><div class="rows">'.$contractHtml.'</div>' : '')
        .'<h2>Mano įstaigos</h2><ul class="plain">'.$venueHtml.'</ul><p><a href="/istaiga/nauja">+ Pridėti kitą įstaigą</a></p>';
}

function review_link(array $c, bool $reviewed): string
{
    if ($reviewed) {
        return '<span class="meta done">Įvertinta ✓</span>';
    }
    if ($c['start_date'] > today()) {
        return '<span class="meta">Įvertinti galėsite po darbo</span>';
    }
    return '<a class="btn small" href="/vertinti/'.(int) $c['id'].'">Įvertinti</a>';
}
