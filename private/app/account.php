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
            flash($first ? 'Profilis sukurtas. Pažymėkite dienas, kuriomis galite priimti darbo pasiūlymus.' : 'Profilio pakeitimai išsaugoti.');
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
    page($title, '<section class="wrap narrow"><h1>'.$title.'</h1>'
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
    $days = (array) ($_POST['d'] ?? []);
    $add = function (string $from, string $to) use (&$days): void {
        for ($t = strtotime($from); $t <= strtotime($to); $t = strtotime('+1 day', $t)) {
            $days[] = date('Y-m-d', $t);
        }
    };
    $error = '';
    match (input('add')) {
        'today' => $add(today(), today()),
        'week' => $add(today(), date('Y-m-d', strtotime('+6 days'))),
        'month' => $add(today(), date('Y-m-d', strtotime('+1 month -1 day'))),
        'range' => ($from = picked_date('from')) !== '' && ($to = picked_date('to') ?: $from) >= $from
            ? $add(max($from, today()), min($to, availability_last_day()))
            : $error = 'Nurodykite laikotarpio pradžios ir pabaigos datas.',
        'clear' => $days = [],
        default => null,
    };
    if ($error !== '') {
        flash($error);
        redirect('/mano#laisvumas');
    }
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
    $body = $u['kind'] === 'worker' ? worker_dashboard($u) : manager_dashboard($u);
    $kind = $u['kind'] === 'worker' ? 'Darbuotojo paskyra' : 'Darbdavio paskyra';
    $admin = is_admin($u) ? '<a class="btn small ghost" href="/admin">Administravimas</a>' : '';
    page('Mano GANG', '<section class="wrap">'.$body
        .'<div class="account-bar">'.avatar($u, 'account-avatar')
        .'<div class="account-who"><span class="eyebrow">'.$kind.'</span><strong>'.e($u['name']).'</strong><span class="meta">'.e($u['email']).'</span></div>'
        .'<div class="account-actions"><a class="btn small" href="/profilis">Redaguoti profilį</a>'.$admin.post_button('/atsijungti', 'Atsijungti', [], 'btn small ghost').'</div>'
        .'</div></section>', ['noindex' => true]);
}

function worker_dashboard(array $u): string
{
    [$avg, $n] = worker_rating((int) $u['id']);
    $availability = availability_panel($u);

    $jobs = open_requests(['roles' => from_list($u['roles']), 'cities' => from_list($u['cities'])], 30);
    $responded = array_column(sql('SELECT request_id, status FROM responses WHERE worker_id = ?', [$u['id']])->fetchAll(), 'status', 'request_id');
    $jobs = array_filter($jobs, fn ($r) => !isset($responded[$r['id']]));
    $jobCards = $jobs ? '<div class="cards">'.implode('', array_map('request_card', $jobs)).'</div>' : '<div class="empty-state"><span class="empty-mark" aria-hidden="true">0</span><div><h3>Naujų pasiūlymų kol kas nėra</h3>'
        .'<p>'.($u['notify'] ? 'Kai darbdavys paskelbs jūsų pareigas ir miestą atitinkantį pasiūlymą, iš karto informuosime jus el. paštu.' : 'Pranešimai el. paštu išjungti, todėl naujus pasiūlymus matysite tik šiame puslapyje. Juos galite įjungti profilio nustatymuose.').'</p>'
        .'<p class="empty-actions"><a class="btn small ghost" href="/darbai">Visi darbo pasiūlymai</a><a class="btn small ghost" href="/profilis">Atnaujinti pareigas ir miestus</a></p></div></div>';

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
        $pendingHtml .= request_card($r, '<p class="meta wait">Laukiama darbdavio sprendimo</p>');
    }
    return '<div class="greeting">'.avatar($u).'<div><p class="eyebrow">Darbuotojo paskyra</p><h1>'.e(first_name($u['name'])).'</h1>'
        .'<p class="meta">Jūsų įvertinimas: '.stars($avg, $n).' · Darbų per GANG: '.count($contracts).'</p></div></div>'
        .$availability
        .($contractHtml ? '<h2>Mano GANG kontraktai</h2><div class="rows">'.$contractHtml.'</div>' : '')
        .($pendingHtml ? '<h2>Pateiktos kandidatūros</h2><div class="cards">'.$pendingHtml.'</div>' : '')
        .'<h2>Jums tinkantys darbo pasiūlymai</h2>'.$jobCards;
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
    return '<p class="eyebrow">Darbdavio paskyra</p><h1>'.e(first_name($u['name'])).'</h1>'.$aboutTip
        .($openHtml ? '<p class="cta-row"><a class="btn" href="/poreikis/naujas">+ Paskelbti poreikį</a></p>' : '')
        .'<h2>Aktyvūs poreikiai</h2>'.($openHtml ? '<div class="cards">'.$openHtml.'</div>' : '<div class="empty-state"><span class="empty-mark" aria-hidden="true">0</span><div><h3>Aktyvių poreikių nėra</h3>'
            .'<p>Jei susirgo darbuotojas, artėja sezonas ar didelis renginys, paskelbkite poreikį. Tinkami darbuotojai pranešimą gaus iš karto.</p>'
            .'<p class="empty-actions"><a class="btn small" href="/poreikis/naujas">+ Paskelbti poreikį</a></p></div></div>')
        .($contractHtml ? '<h2>GANG kontraktai</h2><div class="rows">'.$contractHtml.'</div>' : '')
        .'<h2>Mano darbovietės</h2><div class="venue-grid">'.$venueHtml
        .'<a class="venue-card add" href="/darboviete/nauja"><span class="plus" aria-hidden="true">+</span><strong>Pridėti darbovietę</strong><span class="meta">Kitas jūsų baras, restoranas ar kavinė</span></a></div>';
}

// Six-month calendar of days the worker is open to offers. Works as a plain form; the script only adds range selection.
function availability_panel(array $u): string
{
    $marked = availability_days((int) $u['id']);
    $ranges = availability_ranges((int) $u['id']);
    $last = availability_last_day();
    $months = ['', 'Sausis', 'Vasaris', 'Kovas', 'Balandis', 'Gegužė', 'Birželis', 'Liepa', 'Rugpjūtis', 'Rugsėjis', 'Spalis', 'Lapkritis', 'Gruodis'];
    $cal = '';
    $first = strtotime(date('Y-m-01'));
    for ($i = 0; $i < AVAILABILITY_MONTHS; $i++) {
        $m = strtotime("+$i months", $first);
        $lead = (int) date('N', $m) - 1;
        $cells = str_repeat('<span class="day pad"></span>', $lead);
        for ($d = 1; $d <= (int) date('t', $m); $d++) {
            $date = date('Y-m-', $m).sprintf('%02d', $d);
            $weekend = (int) date('N', strtotime($date)) >= 6 ? ' weekend' : '';
            if ($date < today() || $date > $last) {
                $cells .= '<span class="day off'.$weekend.'">'.$d.'</span>';
                continue;
            }
            $cells .= '<label class="day'.$weekend.($date === today() ? ' today' : '').'"><input type="checkbox" name="d[]" value="'.$date.'"'.(isset($marked[$date]) ? ' checked' : '')
                .' aria-label="'.e(date_lt($date)).'"><span>'.$d.'</span></label>';
        }
        // Always six week rows, so every month block has the same height.
        $cells .= str_repeat('<span class="day pad"></span>', 42 - $lead - (int) date('t', $m));
        $cal .= '<div class="month"><h3>'.$months[(int) date('n', $m)].' '.date('Y', $m).'</h3>'
            .'<div class="week"><span>Pr</span><span>An</span><span>Tr</span><span>Kt</span><span>Pn</span><span>Št</span><span>Sk</span></div>'
            .'<div class="days">'.$cells.'</div></div>';
    }
    $status = is_available((int) $u['id'])
        ? '<p class="status on">● Šiandien galite priimti darbo pasiūlymus</p>'
        : '<p class="status off">● Šiandien laisvos dienos nepažymėtos</p>';
    $summary = $ranges
        ? '<p class="meta">Pažymėti laikotarpiai: '.e(implode('; ', array_map('range_lt', $ranges))).'</p>'
        : '<p class="meta">Laisvų dienų dar nepažymėjote.</p>';
    return '<div class="panel avail" id="laisvumas"><h2>Mano laisvos dienos papildomam darbui</h2>'
        .'<p>Pažymėkite dienas per šį ir penkis ateinančius mėnesius, kuriomis galite priimti darbo pasiūlymus. Darbdaviai, ieškantys darbuotojo konkrečiai datai, matys, kad tą dieną esate laisvi.</p>'
        .$status.$summary
        .'<form method="post" action="/laisvas" class="calendar-form">'.csrf_field()
        // First submit button handles Enter in the date fields: save, not "today".
        .'<button class="offscreen" tabindex="-1" aria-hidden="true">Išsaugoti</button>'
        .'<div class="quick"><span class="meta">Greitai pažymėti:</span>'
        .'<button class="btn small ghost" name="add" value="today">Šiandien</button>'
        .'<button class="btn small ghost" name="add" value="week">Artimiausios 7 dienos</button>'
        .'<button class="btn small ghost" name="add" value="month">Artimiausias mėnuo</button></div>'
        .'<div class="range">'.date_picker('from', 'Nuo', $months).date_picker('to', 'Iki', $months)
        .'<button class="btn small ghost" name="add" value="range">Pažymėti laikotarpį</button></div>'
        .'<p class="meta cal-hint">Spustelėkite dieną, kad ją pažymėtumėte arba atšauktumėte. Norėdami pažymėti laikotarpį, spustelėkite pirmąją ir, laikydami nuspaudę „Shift“, paskutinę dieną.</p>'
        .'<div class="calendar">'.$cal.'</div>'
        .'<div class="cal-actions"><button class="btn">Išsaugoti laisvas dienas</button>'
        .($ranges ? '<button class="btn small ghost" name="add" value="clear" onclick="return confirm(\'Pašalinti visas pažymėtas laisvas dienas?\')">Pašalinti visas</button>' : '').'</div>'
        .'</form></div>'.calendar_script();
}

// Month and day selects instead of <input type=date>: the browsers' own pickers
// make it awkward to get back to the days after choosing a month.
function date_picker(string $name, string $label, array $months): string
{
    $monthOptions = ['' => 'Mėnuo'];
    for ($i = 0; $i < AVAILABILITY_MONTHS; $i++) {
        $t = strtotime(date('Y-m-01').' +'.$i.' month');
        $monthOptions[date('Y-m', $t)] = $months[(int) date('n', $t)].' '.date('Y', $t);
    }
    $days = ['' => 'Diena'] + array_combine(range(1, 31), array_map('strval', range(1, 31)));
    return '<fieldset class="date-pick"><legend>'.e($label).'</legend>'
        .'<select name="'.$name.'_m" aria-label="'.e($label).': mėnuo">'.options($monthOptions, '').'</select>'
        .'<select name="'.$name.'_d" aria-label="'.e($label).': diena">'.options($days, '').'</select></fieldset>';
}

// Y-m-d from the month and day selects; a day past the month's end means its last day.
function picked_date(string $name): string
{
    $month = input($name.'_m');
    $day = (int) input($name.'_d');
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) || $day < 1 || $day > 31) {
        return '';
    }
    return $month.'-'.sprintf('%02d', min($day, (int) date('t', strtotime($month.'-01'))));
}

function calendar_script(): string
{
    return <<<'HTML'
<script>
(function () {
  var boxes = [].slice.call(document.querySelectorAll('.calendar input[type=checkbox]')), last = null;
  boxes.forEach(function (box, i) {
    box.addEventListener('click', function (ev) {
      if (ev.shiftKey && last !== null) {
        var a = Math.min(last, i), b = Math.max(last, i);
        for (var k = a; k <= b; k++) boxes[k].checked = box.checked;
      }
      last = i;
    });
  });
})();
</script>
HTML;
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
