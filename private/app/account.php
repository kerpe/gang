<?php

declare(strict_types=1);

function profile_page(): never
{
    $u = require_user();
    $worker = $u['kind'] === 'worker';
    $v = ['name' => $u['name'], 'phone' => $u['phone'], 'about' => (string) $u['about'], 'roles' => from_list($u['roles']), 'cities' => from_list($u['cities']), 'xp' => array_map('strval', array_filter(role_experience((int) $u['id']))), 'notify' => (bool) $u['notify']];
    $errors = [];
    if (is_post()) {
        // A file over post_max_size empties $_POST, so say that instead of "form expired".
        if (!$_POST && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            flash('Nuotrauka per didelė. Įkelkite iki '.PHOTO_MAX_MB.' MB dydžio failą.');
            redirect('/profilis');
        }
        check_csrf();
        $v = ['name' => input('name'), 'phone' => input('phone'), 'about' => input('about'),
            'roles' => (array) ($_POST['roles'] ?? []), 'cities' => (array) ($_POST['cities'] ?? []), 'xp' => array_map('strval', (array) ($_POST['xp'] ?? [])), 'notify' => isset($_POST['notify'])];
        if (mb_strlen($v['name']) < 2 || mb_strlen($v['name']) > 120) {
            $errors['name'] = 'Įrašykite vardą ir pavardę.';
        }
        if (!preg_match('/^\+?[0-9 ()-]{8,20}$/', $v['phone'])) {
            $errors['phone'] = 'Įrašykite telefono numerį, pavyzdžiui, +370 600 00000.';
        }
        if ($worker && to_list($v['roles'], ROLES) === '') {
            $errors['roles'] = 'Pažymėkite bent vienas pareigas.';
        }
        $roles = $worker ? from_list(to_list($v['roles'], ROLES)) : [];
        $xp = [];
        foreach ($roles as $role) {
            if (!isset(EXPERIENCE[(int) ($v['xp'][$role] ?? 0)])) {
                $errors['roles'] = 'Prie kiekvienų pažymėtų pareigų nurodykite savo patirtį.';
            }
            $xp[$role] = (int) ($v['xp'][$role] ?? 0);
        }
        if ($worker && to_list($v['cities'], CITIES) === '') {
            $errors['cities'] = 'Pažymėkite bent vieną miestą.';
        }
        if (mb_strlen($v['about']) > 2000) {
            $errors['about'] = 'Tekstas gali būti ne ilgesnis nei 2000 simbolių.';
        }
        $photo = $u['photo'];
        if ($worker && ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $stored = store_photo($_FILES['photo']);
            if (str_starts_with($stored, '!')) {
                $errors['photo'] = substr($stored, 1);
            } else {
                $photo = $stored;
            }
        } elseif ($worker && isset($_POST['remove_photo'])) {
            $photo = null;
        }
        if ($errors && $photo !== $u['photo']) {
            delete_photo($photo);
            $photo = $u['photo'];
        }
        if (!$errors) {
            $first = !profile_complete($u);
            sql('UPDATE users SET name = ?, phone = ?, about = ?, roles = ?, cities = ?, experience = ?, notify = ?, photo = ? WHERE id = ?', [
                $v['name'], $v['phone'], $v['about'], $worker ? to_list($v['roles'], ROLES) : '', $worker ? to_list($v['cities'], CITIES) : '', $xp ? min($xp) : 0, $v['notify'] ? 1 : 0, $photo, $u['id'],
            ]);
            if ($worker) {
                save_role_experience((int) $u['id'], $xp);
            }
            if ($photo !== $u['photo']) {
                delete_photo($u['photo']);
            }
            if (!$worker && !sql('SELECT id FROM venues WHERE manager_id = ?', [$u['id']])->fetchColumn()) {
                redirect('/darboviete/nauja');
            }
            flash(!$first ? 'Profilio pakeitimai išsaugoti.' : (prelaunch()
                ? 'Profilis sukurtas.'
                : 'Profilis sukurtas. Pažymėkite dienas, kuriomis galite priimti darbo pasiūlymus.'));
            redirect('/mano');
        }
    }
    $title = profile_complete($u) ? 'Mano profilis' : ($worker ? 'Susikurkite profilį' : 'Jūsų duomenys');
    $workerFields = $photoField = '';
    if ($worker) {
        $remove = $u['photo'] ? '<label class="check"><input type="checkbox" name="remove_photo"> Pašalinti paveikslėlį</label>' : '';
        $photoField = '<fieldset class="photo-field"><legend>Profilio nuotrauka arba avataras <small>(neprivaloma)</small></legend>'
            .'<div class="photo-row">'.avatar($u, 'large').'<div>'
            .'<p class="hint">Jei norite, įkelkite savo nuotrauką arba avatarą, kuris bus rodomas jūsų profilyje. Neįkėlus paveikslėlio, rodomos jūsų vardo ir pavardės pirmosios raidės.</p>'
            .'<input type="file" name="photo" accept="image/jpeg,image/png,image/webp" data-resize="1600">'
            .'<p class="meta">JPG, PNG arba WEBP formatu, iki '.PHOTO_MAX_MB.' MB. Paveikslėlį mato tik prisijungę darbdaviai.</p>'
            .$remove.err($errors, 'photo').'</div></div></fieldset>';
        $roleRows = '';
        foreach (ROLES as $role => $label) {
            $roleRows .= '<div class="role-xp"><label class="choice"><input type="checkbox" name="roles[]" value="'.$role.'"'.(in_array($role, $v['roles'], true) ? ' checked' : '').'> '.e($label).'</label>'
                .'<select name="xp['.$role.']" aria-label="'.e($label).': patirtis">'.options(['' => 'Nurodykite patirtį'] + EXPERIENCE, $v['xp'][$role] ?? '').'</select></div>';
        }
        $workerFields = '<fieldset><legend>Pareigos ir patirtis</legend><p class="hint">Pažymėkite pareigas, kurias galite eiti, ir prie kiekvienų nurodykite savo patirtį. Darbo pasiūlymus gausite iš darbdavių, kurių reikalavimus atitinkate.</p>'
            .'<div class="role-list">'.$roleRows.'</div>'.err($errors, 'roles').'</fieldset>'
            .'<fieldset><legend>Miestai, kuriuose galite dirbti</legend>'.checkboxes('cities', CITIES, $v['cities']).err($errors, 'cities').'</fieldset>'
            .'<label>Apie save <small>(darbo patirtis, ankstesnės darbovietės, įgūdžiai)</small><textarea name="about" rows="5" maxlength="2000" placeholder="Pavyzdžiui: trejų metų barmeno patirtis kokteilių bare, darbo su kasos sistema įgūdžiai, galiojantis higienos pažymėjimas.">'.e($v['about']).'</textarea>'.err($errors, 'about').'</label>'
            .'<label class="check"><input type="checkbox" name="notify"'.($v['notify'] ? ' checked' : '').'> Gauti el. laiškus apie naujus man tinkančius darbo pasiūlymus</label>';
    }
    $phoneNote = $worker ? 'Numerį darbdavys matys tik patvirtinęs jus darbui.' : 'Numerį darbuotojas matys tik po jūsų patvirtinimo.';
    $soon = prelaunch() && !profile_complete($u)
        ? '<p class="notice tip">'.($worker
            ? 'GANG pradės veikti netrukus. Dabar susikurkite profilį, o nuo paleidimo dienos kalendoriuje pažymėsite laiką, kada galite dirbti, ir gausite darbo pasiūlymus.'
            : 'GANG pradės veikti netrukus. Dabar užpildykite savo ir darbovietės duomenis, o nuo paleidimo dienos galėsite skelbti darbuotojų poreikius.').'</p>'
        : '';
    page($title, '<section class="wrap narrow"><h1>'.$title.'</h1>'.$soon
        .'<form method="post" class="form" enctype="multipart/form-data" novalidate>'.csrf_field()
        .$photoField
        .'<label>Vardas ir pavardė <input name="name" value="'.e($v['name']).'" autocomplete="name" required>'.err($errors, 'name').'</label>'
        .'<label>Telefono numeris <small>'.$phoneNote.'</small><input type="tel" name="phone" value="'.e($v['phone']).'" autocomplete="tel" required>'.err($errors, 'phone').'</label>'
        .$workerFields
        .'<p class="meta">El. paštas: '.e($u['email']).'</p>'
        .'<button class="btn">Išsaugoti</button></form></section>'.($worker ? photo_resize_script() : ''), ['noindex' => true]);
}

// Shrinks large phone photos in the browser before upload; the server still validates and re-encodes.
function photo_resize_script(): string
{
    return <<<'HTML'
<script>
document.querySelectorAll('input[data-resize]').forEach(function (input) {
  input.addEventListener('change', function () {
    var file = input.files[0], max = +input.dataset.resize;
    if (!file || !window.DataTransfer || file.size < 1.5e6) return;
    var img = new Image();
    img.onload = function () {
      var k = Math.min(1, max / Math.max(img.width, img.height));
      var c = document.createElement('canvas');
      c.width = Math.round(img.width * k); c.height = Math.round(img.height * k);
      c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
      c.toBlob(function (blob) {
        if (!blob) return;
        var dt = new DataTransfer();
        dt.items.add(new File([blob], 'nuotrauka.jpg', {type: 'image/jpeg'}));
        input.files = dt.files;
      }, 'image/jpeg', 0.9);
      URL.revokeObjectURL(img.src);
    };
    img.src = URL.createObjectURL(file);
  });
});
</script>
HTML;
}

function availability_action(): never
{
    $u = require_user('worker');
    check_csrf();
    $last = availability_last_day();
    $days = [];
    $add = function (string $from, string $to) use (&$days, $last): void {
        for ($t = strtotime(max($from, today())); $t <= strtotime(min($to, $last)); $t = strtotime('+1 day', $t)) {
            $days[] = date('Y-m-d', $t);
        }
    };
    // The calendar sends "from:to,…"; a page left open overnight may still hold yesterday, so ranges are clamped, not rejected.
    foreach (array_filter(explode(',', input('dates'))) as $part) {
        if (preg_match('/^(\d{4}-\d{2}-\d{2}):(\d{4}-\d{2}-\d{2})$/', $part, $m) && $m[1] <= $m[2]) {
            $add($m[1], $m[2]);
        }
    }
    match (input('add')) {
        'today' => $add(today(), today()),
        'week' => $add(today(), date('Y-m-d', strtotime('+6 days'))),
        'month' => $add(today(), date('Y-m-d', strtotime('+1 month -1 day'))),
        'clear' => $days = [],
        default => null,
    };
    save_availability((int) $u['id'], $days);
    flash(availability_ranges((int) $u['id']) ? 'Laisvos dienos išsaugotos.' : 'Laisvos dienos pašalintos. Darbdaviai matys, kad šiuo metu darbo pasiūlymų nepriimate.');
    redirect('/mano#laisvumas');
}

function dashboard_page(): never
{
    $u = require_user();
    if (!profile_complete($u)) {
        redirect('/profilis');
    }
    $body = prelaunch()
        ? ($u['kind'] === 'worker' ? prelaunch_worker_dashboard($u) : prelaunch_manager_dashboard($u))
        : ($u['kind'] === 'worker' ? worker_dashboard($u) : manager_dashboard($u));
    $kind = $u['kind'] === 'worker' ? 'Darbuotojo paskyra' : 'Darbdavio paskyra';
    $admin = is_admin($u) ? '<a class="btn small ghost" href="/admin">Administravimas</a>' : '';
    page('Mano GANG', '<section class="wrap">'.$body
        .'<div class="account-bar">'.avatar($u, 'account-avatar')
        .'<div class="account-who"><span class="eyebrow">'.$kind.'</span><strong>'.e($u['name']).'</strong><span class="meta">'.e($u['email']).'</span></div>'
        .'<div class="account-actions"><a class="btn small" href="/profilis">Redaguoti profilį</a>'.$admin.post_button('/atsijungti', 'Atsijungti', [], 'btn small ghost').'</div>'
        .'</div></section>'.date_picker_script(), ['noindex' => true]);
}

const CONTRACT_SELECT = 'SELECT c.*, r.role, r.kind, r.start_date, r.end_date, r.dates, r.hours, v.name AS venue_name, v.address, v.city, v.manager_id,
        m.name AS manager_name, m.phone AS manager_phone, w.name AS worker_name, w.phone AS worker_phone
    FROM contracts c JOIN requests r ON r.id = c.request_id JOIN venues v ON v.id = c.venue_id JOIN users m ON m.id = v.manager_id JOIN users w ON w.id = c.worker_id';

// Upcoming work first (soonest at the top), then past contracts (latest first).
function sort_contracts(array $contracts): array
{
    $end = fn ($c) => $c['kind'] === 'nuolat' ? '9999-12-31' : ($c['end_date'] ?: $c['start_date']);
    $upcoming = array_values(array_filter($contracts, fn ($c) => $end($c) >= today()));
    $past = array_values(array_filter($contracts, fn ($c) => $end($c) < today()));
    usort($upcoming, fn ($a, $b) => strcmp($a['start_date'], $b['start_date']));
    usort($past, fn ($a, $b) => strcmp($b['start_date'], $a['start_date']));
    return [...$upcoming, ...$past];
}

function tel_link(string $phone, string $label, string $class = 'btn small'): string
{
    return '<a class="'.$class.'" href="tel:'.e(preg_replace('/[^0-9+]/', '', $phone)).'">'.e($label).'</a>';
}

// Street address with the city added unless it is already there.
function full_address(array $c): string
{
    $city = CITIES[$c['city']] ?? '';
    $address = trim((string) $c['address']);
    return $address === '' ? $city : ($city === '' || mb_stripos($address, $city) !== false ? $address : $address.', '.$city);
}

// One GANG contract with what each side needs next: who to call, where to go, the dates in a calendar.
function contract_card(array $c, string $side, bool $reviewed): string
{
    $worker = $side === 'worker';
    $end = $c['kind'] === 'nuolat' ? $c['start_date'] : ($c['end_date'] ?: $c['start_date']);
    $started = $c['start_date'] <= today();
    $over = $end < today();
    $steps = ['Kandidatūra' => 'done', 'Patvirtinta' => 'done', 'Darbas' => $over || $reviewed ? 'done' : ($started ? 'now' : ''), 'Įvertinimas' => $reviewed ? 'done' : ($over ? 'now' : '')];
    $stepper = '<ol class="stepper">';
    foreach ($steps as $label => $state) {
        $stepper .= '<li'.($state ? ' class="'.$state.'"' : '').'>'.$label.'</li>';
    }
    $stepper .= '</ol>';
    $place = trim($c['address'] !== '' ? $c['address'] : (CITIES[$c['city']] ?? ''));
    $info = $worker
        ? '<div><dt>Adresas</dt><dd>'.e($place).'</dd></div><div><dt>Kontaktas</dt><dd>'.e($c['manager_name']).' · '.e($c['manager_phone']).'</dd></div>'
        : '<div><dt>Darbuotojas</dt><dd><a href="/darbuotojas/'.(int) $c['worker_id'].'">'.e($c['worker_name']).'</a> · '.e($c['worker_phone']).'</dd></div><div><dt>Darbovietė</dt><dd>'.e($c['venue_name']).'</dd></div>';
    $actions = tel_link($worker ? $c['manager_phone'] : $c['worker_phone'], 'Skambinti');
    if ($worker && $place !== '') {
        $query = full_address($c);
        $actions .= '<a class="btn small ghost" href="https://www.google.com/maps/search/?api=1&amp;query='.rawurlencode($query).'" target="_blank" rel="noopener">Maršrutas</a>';
    }
    if (!$over) {
        $actions .= '<a class="btn small ghost" href="/kontraktas/'.(int) $c['id'].'/kalendorius">Į kalendorių</a>';
    }
    $actions .= review_link($c, $reviewed);
    $title = $worker ? e(ROLES[$c['role']] ?? '').' · '.e($c['venue_name']) : e($c['worker_name']).' · '.e(ROLES[$c['role']] ?? '');
    return '<article class="contract'.($over ? ' past' : '').'">'.$stepper
        .'<h3><a href="/darbas/'.(int) $c['request_id'].'">'.$title.'</a></h3>'
        .'<p class="meta">'.e(period($c)).($c['hours'] !== '' ? ' · '.e($c['hours']) : '').'</p>'
        .'<dl class="contract-info">'.$info.'</dl>'
        .'<p class="contract-actions">'.$actions.'</p></article>';
}

function contract_ics(int $id): never
{
    $u = require_user();
    $c = sql(CONTRACT_SELECT.' WHERE c.id = ?', [$id])->fetch();
    if (!$c || ((int) $c['worker_id'] !== (int) $u['id'] && (int) $c['manager_id'] !== (int) $u['id'])) {
        not_found();
    }
    $worker = (int) $c['worker_id'] === (int) $u['id'];
    $ranges = $c['dates'] !== '' && $c['dates'] !== null
        ? array_map(fn ($p) => explode(':', $p), explode(',', $c['dates']))
        : [[$c['start_date'], $c['kind'] === 'nuolat' ? $c['start_date'] : ($c['end_date'] ?: $c['start_date'])]];
    $text = fn (string $s) => str_replace(['\\', ';', ',', "\r", "\n"], ['\\\\', '\;', '\,', '', '\n'], $s);
    $role = ROLES[$c['role']] ?? $c['role'];
    $place = full_address($c);
    $about = ($c['hours'] !== '' ? 'Darbo laikas: '.$c['hours']."\n" : '')
        .($worker ? 'Kontaktas: '.$c['manager_name'].', '.$c['manager_phone'] : 'Darbuotojas: '.$c['worker_name'].', '.$c['worker_phone'])
        ."\n".url('/darbas/'.$c['request_id']);
    $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//GANG//gang.lt//LT', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH'];
    foreach ($ranges as $i => [$from, $to]) {
        $lines = [...$lines, 'BEGIN:VEVENT', 'UID:gang-kontraktas-'.$id.'-'.$i.'@gang.lt', 'DTSTAMP:'.gmdate('Ymd\THis\Z'),
            'DTSTART;VALUE=DATE:'.date('Ymd', strtotime($from)), 'DTEND;VALUE=DATE:'.date('Ymd', strtotime($to.' +1 day')),
            'SUMMARY:'.$text('GANG: '.$role.' – '.$c['venue_name']), 'LOCATION:'.$text($place), 'DESCRIPTION:'.$text($about), 'END:VEVENT'];
    }
    $lines[] = 'END:VCALENDAR';
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="gang-kontraktas-'.$id.'.ics"');
    echo implode("\r\n", $lines)."\r\n";
    exit;
}

function worker_dashboard(array $u): string
{
    [$avg, $n] = worker_rating((int) $u['id']);
    $hasDays = (bool) availability_ranges((int) $u['id']);
    $availability = availability_panel($u);

    $jobs = open_requests(['roles' => from_list($u['roles']), 'cities' => from_list($u['cities'])], 30);
    $responded = array_column(sql('SELECT request_id, status FROM responses WHERE worker_id = ?', [$u['id']])->fetchAll(), 'status', 'request_id');
    $jobs = array_filter($jobs, fn ($r) => !isset($responded[$r['id']]));
    // Personal invitations from employers the worker already worked with come first.
    [$where, $params] = open_where();
    $invites = sql(REQUEST_SELECT." JOIN invites i ON i.request_id = r.id WHERE i.worker_id = ? AND $where
        AND NOT EXISTS (SELECT 1 FROM responses s WHERE s.request_id = r.id AND s.worker_id = i.worker_id) ORDER BY r.start_date", array_merge([$u['id']], $params))->fetchAll();
    $inviteIds = array_column($invites, 'id');
    $jobs = array_filter($jobs, fn ($r) => !in_array($r['id'], $inviteIds));
    $inviteHtml = implode('', array_map(fn ($r) => request_card($r, '<p class="meta new">Asmeninis kvietimas: anksčiau jau dirbote kartu</p>'), $invites));
    $jobCards = $jobs ? '<div class="cards">'.implode('', array_map('request_card', $jobs)).'</div>' : '<div class="empty-state"><span class="empty-mark" aria-hidden="true">0</span><div><h3>Naujų pasiūlymų kol kas nėra</h3>'
        .'<p>'.($u['notify'] ? 'Kai darbdavys paskelbs jūsų pareigas ir miestą atitinkantį pasiūlymą, iš karto informuosime jus el. paštu.' : 'Pranešimai el. paštu išjungti, todėl naujus pasiūlymus matysite tik šiame puslapyje. Juos galite įjungti profilio nustatymuose.').'</p>'
        .'<p class="empty-actions"><a class="btn small ghost" href="/darbai">Visi darbo pasiūlymai</a><a class="btn small ghost" href="/profilis">Atnaujinti pareigas ir miestus</a></p></div></div>';

    $contracts = sort_contracts(sql(str_replace(' FROM contracts c', ', (SELECT COUNT(*) FROM reviews rv WHERE rv.contract_id = c.id AND rv.author = \'worker\') AS reviewed FROM contracts c', CONTRACT_SELECT)
        .' WHERE c.worker_id = ? ORDER BY c.created_at DESC LIMIT 50', [$u['id']])->fetchAll());
    $contractHtml = implode('', array_map(fn ($c) => contract_card($c, 'worker', (bool) $c['reviewed']), $contracts));
    $pending = sql(REQUEST_SELECT.' JOIN responses s ON s.request_id = r.id WHERE s.worker_id = ? AND s.status = \'sent\' AND r.status = \'open\' ORDER BY s.created_at DESC', [$u['id']])->fetchAll();
    $pendingHtml = '';
    foreach ($pending as $r) {
        $pendingHtml .= request_card($r, '<p class="meta wait">Laukiama darbdavio sprendimo</p>');
    }
    // Until the first days are marked, the calendar comes first; after that, work and offers do.
    return '<div class="greeting">'.avatar($u).'<div><p class="eyebrow">Darbuotojo paskyra</p><h1>'.e(first_name($u['name'])).'</h1>'
        .'<p class="meta">Jūsų įvertinimas: '.stars($avg, $n).' · Darbų per GANG: '.count($contracts).'</p></div></div>'
        .($inviteHtml ? '<h2>Asmeniniai kvietimai</h2><div class="cards">'.$inviteHtml.'</div>' : '')
        .($hasDays ? '' : $availability)
        .($contractHtml ? '<h2>Mano GANG kontraktai</h2><div class="contracts">'.$contractHtml.'</div>' : '')
        .($pendingHtml ? '<h2>Pateiktos kandidatūros</h2><div class="cards">'.$pendingHtml.'</div>' : '')
        .'<h2>Jums tinkantys darbo pasiūlymai</h2>'.$jobCards
        .($hasDays ? $availability : '');
}

// Before launch there are no offers or contracts yet: the worker sees their profile as employers
// will see it, what would make it stronger, and what happens on launch day.
function prelaunch_worker_dashboard(array $u): string
{
    $xp = '';
    foreach (role_experience((int) $u['id']) as $role => $level) {
        $xp .= '<li><strong>'.e(ROLES[$role] ?? $role).'</strong><span>'.e(EXPERIENCE[$level] ?? '').'</span></li>';
    }
    $about = trim((string) $u['about']);
    $tips = [];
    if (!$u['photo']) {
        $tips[] = 'Įkelkite nuotrauką arba avatarą: profilis su nuotrauka atrodo patikimiau.';
    }
    if (mb_strlen($about) < 80) {
        $tips[] = 'Skiltyje „Apie save“ aprašykite ankstesnes darbovietes ir įgūdžius: tai pirmas dalykas, kurį darbdavys skaito po pareigų.';
    }
    if (!$u['notify']) {
        $tips[] = 'Įjunkite pranešimus el. paštu, kad nepraleistumėte jums tinkančių darbo pasiūlymų.';
    }
    $tipHtml = $tips
        ? '<section class="panel"><h2>Sustiprinkite savo profilį</h2><ul class="tips"><li>'.implode('</li><li>', array_map('e', $tips)).'</li></ul>'
            .'<p><a class="btn small" href="/profilis">Papildyti profilį</a></p></section>'
        : '';
    return '<div class="greeting">'.avatar($u).'<div><p class="eyebrow">Darbuotojo paskyra</p><h1>'.e(first_name($u['name'])).'</h1>'
        .'<p class="meta">Profilis paruoštas</p></div></div>'
        .'<section class="panel ok launch"><h2>GANG pradės veikti netrukus</h2>'
        .'<p>Jūsų profilis išsaugotas. Paleidimo dieną jį matys barai, restoranai ir kavinės jūsų miestuose, o mes jums pranešime el. paštu. Tada galėsite pažymėti laisvas dienas kalendoriuje ir priimti darbo pasiūlymus.</p></section>'
        .$tipHtml
        .'<h2>Taip jus matys darbdaviai</h2>'
        .'<section class="profile-preview"><dl class="contract-info">'
        .'<div><dt>Pareigos ir patirtis</dt><dd><ul class="xp-list">'.$xp.'</ul></dd></div>'
        .'<div><dt>Miestai</dt><dd>'.e(labels($u['cities'], CITIES)).'</dd></div>'
        .'<div><dt>Apie save</dt><dd>'.($about !== '' ? nl2br(e($about)) : '<span class="meta">Neužpildyta</span>').'</dd></div>'
        .'</dl><p class="meta">Telefono numerį darbdavys matys tik patvirtinęs jus darbui.</p></section>';
}

// Before launch an employer registers venues; requests open on launch day.
function prelaunch_manager_dashboard(array $u): string
{
    $venues = sql('SELECT * FROM venues WHERE manager_id = ? ORDER BY id', [$u['id']])->fetchAll();
    if (!$venues) {
        redirect('/darboviete/nauja');
    }
    $venueHtml = '';
    foreach ($venues as $v) {
        $venueHtml .= '<div class="venue-card"><strong class="venue-name">'.e($v['name']).'</strong>'
            .'<span class="meta">'.e(CITIES[$v['city']] ?? '').($v['address'] !== '' ? ' · '.e($v['address']) : '').'</span>'
            .'<p class="venue-about">'.e(mb_strimwidth((string) $v['about'], 0, 180, '…')).'</p>'
            .'<p class="venue-actions"><a class="btn small ghost" href="/darboviete/'.(int) $v['id'].'/redaguoti">Redaguoti</a></p></div>';
    }
    return '<p class="eyebrow">Darbdavio paskyra</p><h1>'.e(first_name($u['name'])).'</h1>'
        .'<section class="panel ok launch"><h2>GANG pradės veikti netrukus</h2>'
        .'<p>Jūsų darbovietės duomenys išsaugoti. Paleidimo dieną pranešime jums el. paštu: nuo tada galėsite skelbti darbuotojų poreikius pavadavimui, sezonui ar nuolatiniam darbui, o tinkami darbuotojai juos gaus iš karto.</p></section>'
        .'<h2>Ką galėsite daryti nuo paleidimo dienos</h2><ol class="steps compact">'
        .'<li><strong>Paskelbti poreikį</strong>Pareigos, datos, darbo laikas ir atlygis.</li>'
        .'<li><strong>Gauti kandidatūras</strong>Matysite kandidatų patirtį ir įvertinimus.</li>'
        .'<li><strong>Patvirtinti darbuotoją</strong>Abi šalys gauna viena kitos kontaktus.</li></ol>'
        .'<h2>Mano darbovietės</h2><div class="venue-grid">'.$venueHtml
        .'<a class="venue-card add" href="/darboviete/nauja"><span class="plus" aria-hidden="true">+</span><strong>Pridėti darbovietę</strong><span class="meta">Kitas jūsų baras, restoranas ar kavinė</span></a></div>';
}

function manager_dashboard(array $u): string
{
    $venues = sql('SELECT * FROM venues WHERE manager_id = ? ORDER BY id', [$u['id']])->fetchAll();
    if (!$venues) {
        redirect('/darboviete/nauja');
    }
    $venueHtml = '';
    foreach ($venues as $v) {
        [$avg, $n] = venue_rating((int) $v['id']);
        $venueHtml .= '<div class="venue-card"><a class="venue-name" href="/darboviete/'.(int) $v['id'].'">'.e($v['name']).'</a>'
            .'<span class="meta">'.e(CITIES[$v['city']] ?? '').($v['address'] !== '' ? ' · '.e($v['address']) : '').'</span>'.stars($avg, $n)
            .'<p class="venue-actions"><a class="btn small ghost" href="/darboviete/'.(int) $v['id'].'/redaguoti">Redaguoti</a><a class="btn small ghost" href="/darboviete/'.(int) $v['id'].'">Peržiūrėti</a></p></div>';
    }
    $short = array_filter($venues, fn ($v) => mb_strlen(trim((string) $v['about'])) < VENUE_ABOUT_MIN);
    $aboutTip = $short ? '<p class="notice tip">Papildykite darbovietės aprašymą: kokia tai vieta, kokia komanda ir kokios darbo sąlygos. Tai padeda darbuotojams apsispręsti. <a href="/darboviete/'.(int) reset($short)['id'].'/redaguoti">Papildyti aprašymą</a></p>' : '';
    $ids = array_column($venues, 'id');
    $in = implode(',', array_map('intval', $ids));
    [$where, $params] = open_where();
    $open = sql(REQUEST_SELECT." WHERE r.venue_id IN ($in) AND $where ORDER BY r.start_date", $params)->fetchAll();
    $openHtml = '';
    foreach ($open as $r) {
        $n = (int) sql("SELECT COUNT(*) FROM responses WHERE request_id = ? AND status = 'sent'", [$r['id']])->fetchColumn();
        $label = $n ? "<p class=\"meta new\">Gauta kandidatūrų: $n. Peržiūrėkite</p>" : '<p class="meta wait">Kandidatūrų dar negauta</p>';
        $openHtml .= request_card($r, $label.'<p class="card-actions"><a class="btn small ghost" href="/poreikis/naujas?pagal='.(int) $r['id'].'">Pakartoti</a></p>');
    }
    $openIds = array_column($open, 'id') ?: [0];
    $earlier = sql(REQUEST_SELECT." WHERE r.venue_id IN ($in) AND r.id NOT IN (".implode(',', array_map('intval', $openIds)).') ORDER BY r.created_at DESC LIMIT 6')->fetchAll();
    $earlierHtml = '';
    foreach ($earlier as $r) {
        $earlierHtml .= '<div class="row-item"><div><strong><a href="/darbas/'.(int) $r['id'].'">'.e(ROLES[$r['role']] ?? '').'</a> · '.e($r['venue_name']).'</strong>'
            .'<p class="meta">'.e(period($r)).($r['hours'] !== '' ? ' · '.e($r['hours']) : '').($r['pay'] !== '' ? ' · '.e($r['pay']) : '').'</p></div>'
            .'<a class="btn small ghost" href="/poreikis/naujas?pagal='.(int) $r['id'].'">Pakartoti</a></div>';
    }
    $contracts = sort_contracts(sql(str_replace(' FROM contracts c', ', (SELECT COUNT(*) FROM reviews rv WHERE rv.contract_id = c.id AND rv.author = \'manager\') AS reviewed FROM contracts c', CONTRACT_SELECT)
        .' WHERE c.venue_id IN ('.$in.') ORDER BY c.created_at DESC LIMIT 50')->fetchAll());
    $contractHtml = implode('', array_map(fn ($c) => contract_card($c, 'manager', (bool) $c['reviewed']), $contracts));
    $teamHtml = '';
    foreach (team_members((int) $u['id']) as $w) {
        [$avg, $n] = worker_rating((int) $w['id']);
        $teamHtml .= '<div class="team-card">'.avatar($w).'<div class="who"><a class="venue-name" href="/darbuotojas/'.(int) $w['id'].'">'.e(first_name($w['name'])).'</a>'
            .'<span class="meta">'.e(labels($w['roles'], ROLES)).'</span>'.stars($avg, $n)
            .'<p class="badges"><span class="badge">Kartu dirbta: '.(int) $w['jobs'].'</span>'.(is_available((int) $w['id']) ? '<span class="badge on">Laisvas šiandien</span>' : '').'</p>'
            .'<p class="venue-actions"><a class="btn small" href="/poreikis/naujas?kviesti='.(int) $w['id'].'">Pakviesti į darbą</a></p></div></div>';
    }
    $team = '<h2>Mano komanda</h2>'.($teamHtml
        ? '<p class="meta">Žmonės, su kuriais jau dirbote per GANG. Pakviestas žmogus gaus asmeninį kvietimą, o darbas bus užfiksuotas kaip GANG kontraktas su abipusiais įvertinimais.</p><div class="venue-grid team-grid">'.$teamHtml.'</div>'
        : '<p class="meta">Čia matysite žmones, su kuriais dirbote per GANG. Kitą kartą juos galėsite pakviesti vienu paspaudimu.</p>');
    return '<p class="eyebrow">Darbdavio paskyra</p><h1>'.e(first_name($u['name'])).'</h1>'.$aboutTip
        .($openHtml ? '<p class="cta-row"><a class="btn" href="/poreikis/naujas">+ Paskelbti poreikį</a></p>' : '')
        .'<h2>Aktyvūs poreikiai</h2>'.($openHtml ? '<div class="cards">'.$openHtml.'</div>' : '<div class="empty-state"><span class="empty-mark" aria-hidden="true">0</span><div><h3>Aktyvių poreikių nėra</h3>'
            .'<p>Jei susirgo darbuotojas, artėja sezonas ar didelis renginys, paskelbkite poreikį. Tinkami darbuotojai pranešimą gaus iš karto.</p>'
            .'<p class="empty-actions"><a class="btn small" href="/poreikis/naujas">+ Paskelbti poreikį</a></p></div></div>')
        .$team
        .($contractHtml ? '<h2>GANG kontraktai</h2><div class="contracts">'.$contractHtml.'</div>' : '')
        .($earlierHtml ? '<h2>Ankstesni poreikiai</h2><p class="meta">Paspaudę „Pakartoti“ gausite užpildytą formą: liks pasirinkti tik datas.</p><div class="rows">'.$earlierHtml.'</div>' : '')
        .'<h2>Mano darbovietės</h2><div class="venue-grid">'.$venueHtml
        .'<a class="venue-card add" href="/darboviete/nauja"><span class="plus" aria-hidden="true">+</span><strong>Pridėti darbovietę</strong><span class="meta">Kitas jūsų baras, restoranas ar kavinė</span></a></div>';
}

// Two weeks at a glance; the full calendar (the same one employers use for requests) opens on demand.
function availability_panel(array $u): string
{
    $ranges = availability_ranges((int) $u['id']);
    $marked = availability_days((int) $u['id']);
    $wd = ['Pr', 'An', 'Tr', 'Kt', 'Pn', 'Št', 'Sk'];
    $strip = '';
    for ($i = 0; $i < 14; $i++) {
        $t = strtotime(today().' +'.$i.' days');
        $d = date('Y-m-d', $t);
        $strip .= '<span class="strip-day'.(isset($marked[$d]) ? ' on' : '').((int) date('N', $t) >= 6 ? ' weekend' : '').($i === 0 ? ' today' : '').'" title="'.e(date_lt($d)).'">'
            .'<small>'.$wd[(int) date('N', $t) - 1].'</small>'.(int) date('j', $t).'</span>';
    }
    $status = is_available((int) $u['id'])
        ? '<p class="status on">● Šiandien galite priimti darbo pasiūlymus</p>'
        : '<p class="status off">● Šiandien laisvos dienos nepažymėtos</p>';
    $labels = array_map('range_lt', $ranges);
    $summary = $ranges
        ? '<p class="meta">Pažymėta: '.e(implode('; ', array_slice($labels, 0, 4))).(count($labels) > 4 ? ' ir dar '.(count($labels) - 4) : '').'</p>'
        : '<p class="meta">Pažymėkite dienas per šį ir penkis ateinančius mėnesius, kuriomis galite priimti darbo pasiūlymus. Darbdaviai, ieškantys darbuotojo konkrečiai datai, matys, kad tą dieną esate laisvi.</p>';
    $value = implode(',', array_map(fn ($r) => max($r['start_date'], today()).':'.$r['end_date'], $ranges));
    return '<section class="panel avail" id="laisvumas"><h2>Mano laisvos dienos</h2>'.$status
        .'<div class="strip" aria-label="Artimiausios dvi savaitės">'.$strip.'</div>'.$summary
        .'<details class="avail-edit"'.($ranges ? '' : ' open').'><summary class="btn small'.($ranges ? ' ghost' : '').'">Tvarkyti laisvas dienas</summary>'
        .'<form method="post" action="/laisvas" class="calendar-form">'.csrf_field()
        // First submit button handles Enter: save, not "today".
        .'<button class="offscreen" tabindex="-1" aria-hidden="true">Išsaugoti</button>'
        .'<div class="quick"><span class="meta">Greitai pažymėti:</span>'
        .'<button class="btn small ghost" name="add" value="today">Šiandien</button>'
        .'<button class="btn small ghost" name="add" value="week">Artimiausios 7 dienos</button>'
        .'<button class="btn small ghost" name="add" value="month">Artimiausias mėnuo</button></div>'
        .'<p class="hint">Spustelėkite dieną, kad pažymėtumėte vieną dieną. Spustelėjus kitą dieną, pažymimas laikotarpis nuo–iki. Pažymėtą dieną ar laikotarpį pašalinsite spustelėję jį dar kartą arba ×.</p>'
        .'<div class="picker" data-min="'.today().'" data-max="'.availability_last_day().'" data-limit="60" data-empty="Laisvų dienų dar nepažymėjote."><noscript><p class="notice">Kalendoriui reikalingas „JavaScript“. Įjunkite jį naršyklėje.</p></noscript></div>'
        .'<input type="hidden" name="dates" value="'.e($value).'">'
        .'<div class="cal-actions"><button class="btn">Išsaugoti laisvas dienas</button>'
        .($ranges ? '<button class="btn small ghost" name="add" value="clear" onclick="return confirm(\'Pašalinti visas pažymėtas laisvas dienas?\')">Pašalinti visas</button>' : '').'</div>'
        .'</form></details></section>';
}

function review_link(array $c, bool $reviewed): string
{
    if ($reviewed) {
        return '<span class="meta done">Įvertinta</span>';
    }
    if ($c['start_date'] > today()) {
        return '<span class="meta">Įvertinti galėsite pradėjus darbą</span>';
    }
    return '<a class="btn small" href="/vertinti/'.(int) $c['id'].'">Įvertinti</a>';
}
