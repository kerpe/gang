<?php

declare(strict_types=1);

function venue_form_page(?int $id = null): never
{
    $u = require_user('manager');
    $venue = $id ? sql('SELECT * FROM venues WHERE id = ? AND manager_id = ?', [$id, $u['id']])->fetch() : null;
    if ($id && !$venue) {
        not_found();
    }
    $v = $venue ?: ['name' => '', 'city' => '', 'address' => '', 'about' => ''];
    $errors = [];
    if (is_post()) {
        check_csrf();
        $v = ['name' => input('name'), 'city' => input('city'), 'address' => input('address'), 'about' => input('about')];
        if (mb_strlen($v['name']) < 2 || mb_strlen($v['name']) > 120) {
            $errors['name'] = 'Įrašykite įstaigos pavadinimą.';
        }
        if (!isset(CITIES[$v['city']])) {
            $errors['city'] = 'Pasirinkite miestą.';
        }
        if (mb_strlen($v['address']) > 190) {
            $errors['address'] = 'Per ilgas adresas.';
        }
        if (mb_strlen($v['about']) > 2000) {
            $errors['about'] = 'Iki 2000 simbolių.';
        }
        if (!$errors) {
            if ($venue) {
                sql('UPDATE venues SET name = ?, city = ?, address = ?, about = ? WHERE id = ?', [$v['name'], $v['city'], $v['address'], $v['about'], $venue['id']]);
                flash('Įstaiga išsaugota.');
                redirect('/mano');
            }
            insert('venues', ['manager_id' => $u['id'], 'name' => $v['name'], 'city' => $v['city'], 'address' => $v['address'], 'about' => $v['about'], 'created_at' => now()]);
            flash('Įstaiga užregistruota. Dabar galite ieškoti žmonių.');
            redirect('/mano');
        }
    }
    $title = $venue ? 'Įstaigos duomenys' : 'Užregistruokite įstaigą';
    page($title, '<section class="wrap narrow"><h1>'.$title.'</h1>'
        .'<form method="post" class="form" novalidate>'.csrf_field()
        .'<label>Pavadinimas <input name="name" value="'.e($v['name']).'" maxlength="120" placeholder="pvz. Baras „Gintaras“" required>'.err($errors, 'name').'</label>'
        .'<div class="row"><label>Miestas <select name="city" required>'.options(['' => 'Pasirinkite'] + CITIES, $v['city']).'</select>'.err($errors, 'city').'</label>'
        .'<label>Adresas <small>(darbuotojas jį matys po patvirtinimo)</small><input name="address" value="'.e($v['address']).'" maxlength="190" autocomplete="street-address">'.err($errors, 'address').'</label></div>'
        .'<label>Apie įstaigą <small>(nebūtina)</small><textarea name="about" rows="4" maxlength="2000" placeholder="Kokia tai vieta, kokia komanda, kuo jums gera dirbti">'.e($v['about']).'</textarea>'.err($errors, 'about').'</label>'
        .'<button class="btn">Išsaugoti</button></form></section>', ['noindex' => true]);
}

function venue_page(int $id): never
{
    $u = require_user();
    $venue = sql('SELECT * FROM venues WHERE id = ?', [$id])->fetch();
    if (!$venue) {
        not_found();
    }
    if ($u['kind'] !== 'worker' && (int) $venue['manager_id'] !== (int) $u['id'] && !is_admin($u)) {
        flash('Įstaigų įvertinimus mato darbuotojai.');
        redirect('/mano');
    }
    [$avg, $n] = venue_rating($id);
    $reviews = reviews_html(venue_reviews($id), fn ($r) => 'Darbuotojas');
    $jobs = open_requests([], 200);
    $jobs = array_filter($jobs, fn ($r) => (int) $r['venue_id'] === $id);
    $jobsHtml = $jobs ? '<h2>Dabar ieško</h2><div class="cards">'.implode('', array_map('request_card', $jobs)).'</div>' : '';
    page($venue['name'], '<section class="wrap narrow"><h1>'.e($venue['name']).'</h1><p class="meta">'.e(CITIES[$venue['city']] ?? '').' · '.stars($avg, $n).'</p>'
        .($venue['about'] ? '<div class="desc">'.nl2br(e($venue['about'])).'</div>' : '')
        .$jobsHtml.'<h2>Darbuotojų įvertinimai</h2>'.$reviews.'</section>', ['noindex' => true]);
}

function request_form_page(): never
{
    $u = require_user('manager');
    $venues = sql('SELECT * FROM venues WHERE manager_id = ? ORDER BY id', [$u['id']])->fetchAll();
    if (!$venues) {
        redirect('/istaiga/nauja');
    }
    $venueOptions = array_column($venues, 'name', 'id');
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $v = ['venue_id' => (string) $venues[0]['id'], 'role' => '', 'kind' => 'skubu', 'people' => '1', 'start_date' => $tomorrow, 'end_date' => '', 'hours' => '', 'pay' => '', 'description' => ''];
    $errors = [];
    if (is_post()) {
        check_csrf();
        foreach ($v as $k => $_) {
            $v[$k] = input($k);
        }
        if (!isset($venueOptions[(int) $v['venue_id']])) {
            $errors['venue_id'] = 'Pasirinkite įstaigą.';
        }
        if (!isset(ROLES[$v['role']])) {
            $errors['role'] = 'Pasirinkite pareigas.';
        }
        if (!isset(KINDS[$v['kind']])) {
            $errors['kind'] = 'Pasirinkite, kiek laiko reikia.';
        }
        $people = (int) $v['people'];
        if ($people < 1 || $people > 20) {
            $errors['people'] = 'Nuo 1 iki 20.';
        }
        $start = DateTime::createFromFormat('!Y-m-d', $v['start_date']);
        if (!$start || $v['start_date'] < today() || $start > new DateTime('+1 year')) {
            $errors['start_date'] = 'Nurodykite pradžios datą (ne praeityje).';
        }
        if ($v['kind'] === 'nuolat') {
            $v['end_date'] = '';
        } elseif ($v['end_date'] !== '') {
            $end = DateTime::createFromFormat('!Y-m-d', $v['end_date']);
            if (!$end || $v['end_date'] < $v['start_date'] || $end > new DateTime('+18 months')) {
                $errors['end_date'] = 'Pabaiga turi būti ne anksčiau už pradžią.';
            }
        } elseif ($v['kind'] !== 'skubu') {
            $errors['end_date'] = 'Nurodykite, iki kada reikia žmogaus.';
        }
        if (mb_strlen($v['hours']) > 120) {
            $errors['hours'] = 'Iki 120 simbolių.';
        }
        if (mb_strlen($v['pay']) > 80) {
            $errors['pay'] = 'Iki 80 simbolių.';
        }
        if (mb_strlen($v['description']) < 10 || mb_strlen($v['description']) > 3000) {
            $errors['description'] = 'Parašykite bent trumpai, ką reikės daryti.';
        }
        if (!$errors) {
            $id = insert('requests', [
                'venue_id' => (int) $v['venue_id'], 'role' => $v['role'], 'kind' => $v['kind'], 'people' => $people,
                'start_date' => $v['start_date'], 'end_date' => $v['end_date'] ?: null, 'hours' => $v['hours'], 'pay' => $v['pay'],
                'description' => $v['description'], 'created_at' => now(),
            ]);
            $sent = notify_workers(find_request($id));
            flash($sent ? "Paskelbta. Laišką gavo $sent tinkami darbuotojai – atsiliepimus matysite čia ir gausite el. paštu." : 'Paskelbta. Tinkamų darbuotojų sąraše dar nėra, bet skelbimą matys visi lankytojai.');
            redirect('/darbas/'.$id);
        }
    }
    $kinds = radios('kind', KINDS, $v['kind']);
    page('Ieškoti žmogaus', '<section class="wrap narrow"><h1>Ko jums reikia?</h1>'
        .'<form method="post" class="form request-form" novalidate>'.csrf_field()
        .(count($venues) > 1 ? '<label>Įstaiga <select name="venue_id">'.options($venueOptions, $v['venue_id']).'</select>'.err($errors, 'venue_id').'</label>' : '<input type="hidden" name="venue_id" value="'.(int) $venues[0]['id'].'">')
        .'<div class="row"><label>Pareigos <select name="role" required>'.options(['' => 'Pasirinkite'] + ROLES, $v['role']).'</select>'.err($errors, 'role').'</label>'
        .'<label>Kiek žmonių <input type="number" name="people" min="1" max="20" value="'.e($v['people']).'">'.err($errors, 'people').'</label></div>'
        .'<fieldset><legend>Kiek laiko</legend>'.$kinds.err($errors, 'kind').'</fieldset>'
        .'<div class="row"><label>Nuo <input type="date" name="start_date" min="'.today().'" value="'.e($v['start_date']).'" required>'.err($errors, 'start_date').'</label>'
        .'<label class="end">Iki <small class="opt">(jei daugiau nei viena diena)</small><input type="date" name="end_date" min="'.today().'" value="'.e($v['end_date']).'">'.err($errors, 'end_date').'</label></div>'
        .'<div class="row"><label>Darbo laikas <input name="hours" value="'.e($v['hours']).'" maxlength="120" placeholder="pvz. 10–22 val. arba 2/2 grafikas">'.err($errors, 'hours').'</label>'
        .'<label>Atlygis <small>(rekomenduojame)</small><input name="pay" value="'.e($v['pay']).'" maxlength="80" placeholder="pvz. 10 €/val. į rankas">'.err($errors, 'pay').'</label></div>'
        .'<label>Ką reikės daryti <textarea name="description" rows="5" maxlength="3000" required placeholder="pvz. Susirgo virėjas, reikia žmogaus karštajai virtuvei. Meniu paprastas, padės su-šefas.">'.e($v['description']).'</textarea>'.err($errors, 'description').'</label>'
        .'<button class="btn">Paskelbti ir pranešti darbuotojams</button></form></section>', ['noindex' => true]);
}

function request_page(int $id): never
{
    $r = find_request($id);
    if (!$r) {
        not_found();
    }
    $u = current_user();
    $owner = $u && (int) $r['manager_id'] === (int) $u['id'];
    [$where, $params] = open_where();
    $isOpen = (bool) sql("SELECT 1 FROM requests r WHERE r.id = ? AND $where", array_merge([$id], $params))->fetchColumn();
    if (!$isOpen && !$owner && !($u && sql('SELECT 1 FROM responses WHERE request_id = ? AND worker_id = ?', [$id, $u['id']])->fetchColumn()) && !is_admin($u)) {
        not_found();
    }
    $role = ROLES[$r['role']] ?? $r['role'];
    $city = CITIES[$r['city']] ?? '';
    [$avg, $n] = venue_rating((int) $r['venue_id']);
    $facts = '<ul class="facts"><li><b>Kada</b>'.e(period($r)).'</li>'
        .($r['hours'] !== '' ? '<li><b>Darbo laikas</b>'.e($r['hours']).'</li>' : '')
        .($r['pay'] !== '' ? '<li><b>Atlygis</b>'.e($r['pay']).'</li>' : '')
        .'<li><b>Tipas</b>'.e(KINDS[$r['kind']] ?? '').'</li>'
        .((int) $r['people'] > 1 ? '<li><b>Reikia</b>'.(int) $r['people'].' žm.</li>' : '').'</ul>';
    $venueLink = $u && $u['kind'] === 'worker' ? '<a href="/istaiga/'.(int) $r['venue_id'].'">'.e($r['venue_name']).'</a> · '.stars($avg, $n) : e($r['venue_name']);
    $action = $owner ? owner_panel($r, $isOpen) : worker_panel($r, $u, $isOpen);
    $desc = nl2br(e($r['description']));
    $urgent = $r['kind'] === 'skubu' ? '<span class="tag urgent">Skubu</span> ' : '';
    $e = 'e';
    $ld = '';
    if ($isOpen) {
        $ld = '<script type="application/ld+json">'.json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'JobPosting',
            'title' => $role,
            'description' => nl2br(e($r['description'])),
            'datePosted' => substr($r['created_at'], 0, 10),
            'validThrough' => ($r['end_date'] ?: ($r['kind'] === 'nuolat' ? date('Y-m-d', strtotime($r['created_at'].' +30 days')) : $r['start_date'])).'T23:59:00+02:00',
            'employmentType' => ['skubu' => 'PER_DIEM', 'trumpai' => 'TEMPORARY', 'sezonas' => 'TEMPORARY', 'nuolat' => 'FULL_TIME'][$r['kind']],
            'hiringOrganization' => ['@type' => 'Organization', 'name' => $r['venue_name']],
            'jobLocation' => ['@type' => 'Place', 'address' => ['@type' => 'PostalAddress', 'addressLocality' => $city, 'addressCountry' => 'LT']],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG).'</script>';
    }
    page("$role – {$r['venue_name']}, $city", <<<HTML
<article class="wrap narrow job">
  <p class="back"><a href="/darbai">← Visi darbai</a></p>
  <p>$urgent</p>
  <h1>{$e($role)}</h1>
  <p class="meta">$venueLink · {$e($city)}</p>
  $facts
  <div class="desc">$desc</div>
  $action
</article>
HTML, [
        'description' => mb_substr("$role, {$r['venue_name']}, $city: ".preg_replace('/\s+/', ' ', $r['description']), 0, 155),
        'canonical' => '/darbas/'.$id,
        'head' => $ld,
        'noindex' => !$isOpen,
    ]);
}

function worker_panel(array $r, ?array $u, bool $isOpen): string
{
    if (!$isOpen) {
        return '<div class="panel"><p>Šis darbas jau nebeaktualus.</p></div>';
    }
    if (!$u) {
        return '<div class="panel"><h2>Tinka?</h2><p>Atsiliepti gali GANG nariai. Registracija užtrunka minutę.</p><p><a class="btn" href="/prisijungti?kaip=darbuotojas">Susikurti profilį ir atsiliepti</a> <a href="/prisijungti">Jau turiu paskyrą</a></p></div>';
    }
    if ($u['kind'] !== 'worker') {
        return '';
    }
    $mine = sql('SELECT * FROM responses WHERE request_id = ? AND worker_id = ?', [$r['id'], $u['id']])->fetch();
    if ($mine) {
        return match ($mine['status']) {
            'accepted' => '<div class="panel ok"><h2>Jūs patvirtintas! 🎉</h2><p>Kontaktai ir adresas – <a href="/mano">jūsų paskyroje</a>.</p></div>',
            'declined' => '<div class="panel"><p>Vadovas šį kartą pasirinko kitą žmogų. Sėkmės kitur!</p></div>',
            'withdrawn' => '<div class="panel"><p>Atšaukėte savo atsiliepimą.</p></div>',
            default => '<div class="panel"><h2>Atsiliepėte ✓</h2><p>Vadovas mato jūsų profilį ir įvertinimus. Kai patvirtins, gausite laišką su kontaktais.</p>'
                .post_button('/darbas/'.$r['id'].'/atsaukti', 'Atšaukti atsiliepimą', [], 'btn small ghost', 'Tikrai atšaukti?').'</div>',
        };
    }
    return '<div class="panel" id="atsiliepti"><h2>Tinka? Atsiliepkite</h2>'
        .'<form method="post" action="/darbas/'.(int) $r['id'].'/atsiliepti" class="form">'.csrf_field()
        .'<label>Žinutė vadovui <small>(nebūtina)</small><textarea name="note" rows="3" maxlength="1000" placeholder="pvz. Galiu atvykti per valandą, turiu 2 metų patirtį"></textarea></label>'
        .'<button class="btn">Galiu dirbti</button></form>'
        .'<p class="meta">Vadovas matys jūsų vardą, profilį ir įvertinimus. Telefoną – tik jus patvirtinęs.</p></div>';
}

function owner_panel(array $r, bool $isOpen): string
{
    $responses = sql("SELECT s.*, w.name, w.about, w.roles, w.available_until, w.phone FROM responses s JOIN users w ON w.id = s.worker_id
        WHERE s.request_id = ? AND s.status IN ('sent', 'accepted') ORDER BY CASE WHEN s.status = 'accepted' THEN 0 ELSE 1 END, s.created_at", [$r['id']])->fetchAll();
    $list = '';
    foreach ($responses as $s) {
        [$avg, $n] = worker_rating((int) $s['worker_id']);
        $done = completed_count((int) $s['worker_id']);
        $free = is_available($s) ? ' · <span class="free">laisvas</span>' : '';
        $buttons = $s['status'] === 'accepted'
            ? '<p class="ok-text">Patvirtinta ✓ · <a href="tel:'.e(preg_replace('/[^0-9+]/', '', $s['phone'])).'">'.e($s['phone']).'</a></p>'
            : post_button('/atsiliepimas/'.(int) $s['id'].'/patvirtinti', 'Patvirtinti', [], 'btn small').post_button('/atsiliepimas/'.(int) $s['id'].'/atmesti', 'Ne šį kartą', [], 'btn small ghost');
        $list .= '<div class="responder"><div><strong><a href="/darbuotojas/'.(int) $s['worker_id'].'">'.e(first_name($s['name'])).'</a></strong> '.stars($avg, $n).'<p class="meta">Darbų per GANG: '.$done.$free.'</p>'
            .($s['note'] ? '<p class="note">„'.e($s['note']).'“</p>' : '').($s['about'] ? '<details><summary>Apie save</summary><p>'.nl2br(e($s['about'])).'</p></details>' : '')
            .'</div><div class="actions">'.$buttons.'</div></div>';
    }
    [$total, $freeNow] = matching_workers_count($r['role'], $r['city']);
    $reach = '<p class="meta">Laišką gavo: '.(int) $r['notified'].' · Tinkamų darbuotojų mieste: '.$total.', iš jų laisvi dabar: '.$freeNow.'</p>';
    $status = ['open' => 'Aktyvus', 'filled' => 'Užpildytas', 'cancelled' => 'Atšauktas', 'expired' => 'Pasibaigęs'][$r['status']] ?? $r['status'];
    $close = $isOpen ? post_button('/darbas/'.$r['id'].'/uzdaryti', 'Uždaryti poreikį', [], 'btn small ghost', 'Uždaryti šį poreikį? Darbuotojai jo nebematys.') : '';
    return '<div class="panel owner"><h2>Atsiliepę darbuotojai</h2><p class="meta">Būsena: '.e($isOpen ? 'Aktyvus' : $status).'</p>'.$reach
        .($list ?: '<p class="empty">Dar niekas neatsiliepė. Gausite laišką, kai kas nors atsilieps.</p>').$close.'</div>';
}

function respond_action(int $id): never
{
    $u = require_user('worker');
    check_csrf();
    $r = find_request($id);
    [$where, $params] = open_where();
    if (!$r || !sql("SELECT 1 FROM requests r WHERE r.id = ? AND $where", array_merge([$id], $params))->fetchColumn()) {
        not_found();
    }
    if (!sql('SELECT 1 FROM responses WHERE request_id = ? AND worker_id = ?', [$id, $u['id']])->fetchColumn()) {
        $note = mb_substr(input('note'), 0, 1000);
        insert('responses', ['request_id' => $id, 'worker_id' => $u['id'], 'note' => $note, 'created_at' => now()]);
        $manager = sql('SELECT * FROM users WHERE id = ?', [$r['manager_id']])->fetch();
        [$avg, $n] = worker_rating((int) $u['id']);
        $rating = $n ? number_format($avg, 1, ',', '')."/5 ($n įvert.)" : 'dar neįvertintas';
        send_mail($manager['email'], 'Atsiliepė: '.first_name($u['name']).' – '.(ROLES[$r['role']] ?? ''),
            first_name($u['name'])." atsiliepė į jūsų poreikį „".(ROLES[$r['role']] ?? '')."“ (".period($r).").\n"
            ."Įvertinimas: $rating\n".($note !== '' ? "\nŽinutė: $note\n" : '')
            ."\nPeržiūrėkite ir patvirtinkite:\n".url('/darbas/'.$id));
    }
    flash('Atsiliepėte! Vadovas gaus pranešimą.');
    redirect('/darbas/'.$id);
}

function withdraw_action(int $id): never
{
    $u = require_user('worker');
    check_csrf();
    sql("UPDATE responses SET status = 'withdrawn' WHERE request_id = ? AND worker_id = ? AND status = 'sent'", [$id, $u['id']]);
    redirect('/darbas/'.$id);
}

function close_request_action(int $id): never
{
    $u = require_user('manager');
    check_csrf();
    $r = find_request($id);
    if (!$r || (int) $r['manager_id'] !== (int) $u['id']) {
        not_found();
    }
    $accepted = (int) sql("SELECT COUNT(*) FROM responses WHERE request_id = ? AND status = 'accepted'", [$id])->fetchColumn();
    sql('UPDATE requests SET status = ?, filled_at = COALESCE(filled_at, ?) WHERE id = ?', [$accepted ? 'filled' : 'cancelled', $accepted ? now() : null, $id]);
    decline_waiting($r);
    flash('Poreikis uždarytas.');
    redirect('/mano');
}

function decide_response_action(int $responseId, bool $accept): never
{
    $u = require_user('manager');
    check_csrf();
    $s = sql('SELECT * FROM responses WHERE id = ?', [$responseId])->fetch();
    $r = $s ? find_request((int) $s['request_id']) : null;
    if (!$r || (int) $r['manager_id'] !== (int) $u['id']) {
        not_found();
    }
    if ($s['status'] !== 'sent') {
        redirect('/darbas/'.$r['id']);
    }
    $worker = sql('SELECT * FROM users WHERE id = ?', [$s['worker_id']])->fetch();
    $role = ROLES[$r['role']] ?? '';
    if (!$accept) {
        sql("UPDATE responses SET status = 'declined' WHERE id = ?", [$responseId]);
        send_mail($worker['email'], 'Dėl darbo: '.$role.' – '.$r['venue_name'], "Sveiki, ".first_name($worker['name']).",\n\n{$r['venue_name']} šį kartą pasirinko kitą žmogų. Ačiū, kad atsiliepėte – kiti darbai: ".url('/darbai'));
        redirect('/darbas/'.$r['id']);
    }
    sql("UPDATE responses SET status = 'accepted' WHERE id = ?", [$responseId]);
    insert('contracts', ['request_id' => $r['id'], 'worker_id' => $worker['id'], 'venue_id' => $r['venue_id'], 'created_at' => now()]);
    send_mail($worker['email'], 'Patvirtinta: '.$role.' – '.$r['venue_name'],
        "Sveiki, ".first_name($worker['name']).",\n\n{$r['venue_name']} patvirtino jus darbui!\n\n"
        ."Kada: ".period($r).($r['hours'] !== '' ? ", {$r['hours']}" : '')."\n"
        ."Kur: ".($r['address'] ?: CITIES[$r['city']])."\n"
        ."Kontaktas: {$u['name']}, {$u['phone']}\n\n"
        ."Rekomenduojame paskambinti ir susitarti dėl detalių. Darbo sąlygas ir sutartį sutariate tiesiogiai su įstaiga.\n\n"
        ."Po darbo galėsite įvertinti įstaigą: ".url('/mano'), $u['email']);
    $accepted = (int) sql("SELECT COUNT(*) FROM responses WHERE request_id = ? AND status = 'accepted'", [$r['id']])->fetchColumn();
    if ($accepted >= (int) $r['people']) {
        sql("UPDATE requests SET status = 'filled', filled_at = ? WHERE id = ?", [now(), $r['id']]);
        decline_waiting($r);
        flash('Patvirtinta! Visos vietos užpildytos. '.first_name($worker['name']).' telefonas: '.$worker['phone']);
    } else {
        flash('Patvirtinta! '.first_name($worker['name']).' telefonas: '.$worker['phone']);
    }
    redirect('/darbas/'.$r['id']);
}

// Lets everyone still waiting know the place is taken.
function decline_waiting(array $r): void
{
    $waiting = sql("SELECT w.email, w.name FROM responses s JOIN users w ON w.id = s.worker_id WHERE s.request_id = ? AND s.status = 'sent'", [$r['id']])->fetchAll();
    sql("UPDATE responses SET status = 'declined' WHERE request_id = ? AND status = 'sent'", [$r['id']]);
    foreach ($waiting as $w) {
        send_mail($w['email'], 'Dėl darbo: '.(ROLES[$r['role']] ?? '').' – '.$r['venue_name'], "Sveiki, ".first_name($w['name']).",\n\nšis darbas jau užpildytas. Ačiū, kad atsiliepėte – kiti darbai: ".url('/darbai'));
    }
}

function worker_profile_page(int $id): never
{
    $u = require_user();
    $w = sql("SELECT * FROM users WHERE id = ? AND kind = 'worker'", [$id])->fetch();
    if (!$w) {
        not_found();
    }
    if ($u['kind'] !== 'manager' && (int) $u['id'] !== $id && !is_admin($u)) {
        flash('Darbuotojų profilius mato įstaigų vadovai.');
        redirect('/mano');
    }
    [$avg, $n] = worker_rating($id);
    $reviews = reviews_html(worker_reviews($id), fn ($r) => 'Įstaiga, '.(CITIES[$r['city']] ?? ''));
    $free = is_available($w) ? '<p class="status on">● Laisvas iki '.e(date_lt($w['available_until'])).'</p>' : '';
    page(first_name($w['name']), '<section class="wrap narrow"><h1>'.e(first_name($w['name'])).'</h1>'
        .'<p class="meta">'.stars($avg, $n).' · Darbų per GANG: '.completed_count($id).'</p>'.$free
        .'<ul class="facts"><li><b>Pareigos</b>'.e(labels($w['roles'], ROLES)).'</li><li><b>Miestai</b>'.e(labels($w['cities'], CITIES)).'</li></ul>'
        .($w['about'] ? '<div class="desc">'.nl2br(e($w['about'])).'</div>' : '')
        .'<h2>Įvertinimai</h2>'.$reviews.'</section>', ['noindex' => true]);
}
