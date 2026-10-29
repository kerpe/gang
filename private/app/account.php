<?php

declare(strict_types=1);

function profile_page(): never
{
    $u = require_user();
    $worker = $u['kind'] === 'worker';
    $v = ['name' => $u['name'], 'phone' => $u['phone'], 'about' => (string) $u['about'], 'roles' => from_list($u['roles']), 'cities' => from_list($u['cities']), 'notify' => (bool) $u['notify']];
    $errors = [];
    if (is_post()) {
        // A file over post_max_size empties $_POST, so say that instead of "form expired".
        if (!$_POST && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            flash('Nuotrauka per didelė. Įkelkite iki '.PHOTO_MAX_MB.' MB dydžio failą.');
            redirect('/profilis');
        }
        check_csrf();
        $v = ['name' => input('name'), 'phone' => input('phone'), 'about' => input('about'),
            'roles' => (array) ($_POST['roles'] ?? []), 'cities' => (array) ($_POST['cities'] ?? []), 'notify' => isset($_POST['notify'])];
        if (mb_strlen($v['name']) < 2 || mb_strlen($v['name']) > 120) {
            $errors['name'] = 'Įrašykite vardą ir pavardę.';
        }
        if (!preg_match('/^\+?[0-9 ()-]{8,20}$/', $v['phone'])) {
            $errors['phone'] = 'Įrašykite telefono numerį, pavyzdžiui, +370 600 00000.';
        }
        if ($worker && to_list($v['roles'], ROLES) === '') {
            $errors['roles'] = 'Pažymėkite bent vienas pareigas.';
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
            sql('UPDATE users SET name = ?, phone = ?, about = ?, roles = ?, cities = ?, notify = ?, photo = ? WHERE id = ?', [
                $v['name'], $v['phone'], $v['about'], $worker ? to_list($v['roles'], ROLES) : '', $worker ? to_list($v['cities'], CITIES) : '', $v['notify'] ? 1 : 0, $photo, $u['id'],
            ]);
            if ($photo !== $u['photo']) {
                delete_photo($u['photo']);
            }
            if (!$worker && !sql('SELECT id FROM venues WHERE manager_id = ?', [$u['id']])->fetchColumn()) {
                redirect('/istaiga/nauja');
            }
            flash($first ? 'Profilis sukurtas. Pažymėkite dienas, kuriomis galite priimti darbo pasiūlymus.' : 'Profilio pakeitimai išsaugoti.');
            redirect('/mano');
        }
    }
    $title = profile_complete($u) ? 'Mano profilis' : ($worker ? 'Susikurkite profilį' : 'Jūsų duomenys');
    $workerFields = $photoField = '';
    if ($worker) {
        $remove = $u['photo'] ? '<label class="check"><input type="checkbox" name="remove_photo"> Pašalinti nuotrauką</label>' : '';
        $photoField = '<fieldset class="photo-field"><legend>Profilio nuotrauka</legend>'
            .'<div class="photo-row">'.avatar($u, 'large').'<div>'
            .'<p class="hint">Rekomenduojame įkelti aiškią, šviesią nuotrauką, kurioje gerai matyti jūsų veidas. Profiliai su nuotrauka paprastai sulaukia daugiau įstaigų vadovų dėmesio ir darbo pasiūlymų.</p>'
            .'<input type="file" name="photo" accept="image/jpeg,image/png,image/webp" data-resize="1600">'
            .'<p class="meta">JPG, PNG arba WEBP formatu, iki '.PHOTO_MAX_MB.' MB. Nuotrauką mato tik prisijungę įstaigų vadovai.</p>'
            .$remove.err($errors, 'photo').'</div></div></fieldset>';
        $workerFields = '<fieldset><legend>Pareigos, kurias galite eiti</legend>'.checkboxes('roles', ROLES, $v['roles']).err($errors, 'roles').'</fieldset>'
            .'<fieldset><legend>Miestai, kuriuose galite dirbti</legend>'.checkboxes('cities', CITIES, $v['cities']).err($errors, 'cities').'</fieldset>'
            .'<label>Apie save <small>(darbo patirtis, ankstesnės darbovietės, įgūdžiai)</small><textarea name="about" rows="5" maxlength="2000" placeholder="Pavyzdžiui: trejų metų barmeno patirtis kokteilių bare, darbo su kasos sistema įgūdžiai, galiojantis higienos pažymėjimas.">'.e($v['about']).'</textarea>'.err($errors, 'about').'</label>'
            .'<label class="check"><input type="checkbox" name="notify"'.($v['notify'] ? ' checked' : '').'> Gauti el. laiškus apie naujus man tinkančius darbo pasiūlymus</label>';
    }
    $phoneNote = $worker ? 'Numerį įstaigos vadovas matys tik patvirtinęs jus darbui.' : 'Numerį darbuotojas matys tik po jūsų patvirtinimo.';
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
        'range' => preg_match('/^\d{4}-\d{2}-\d{2}$/', input('from')) && preg_match('/^\d{4}-\d{2}-\d{2}$/', input('to') ?: input('from'))
            && (input('to') ?: input('from')) >= input('from')
            ? $add(max(input('from'), today()), min(input('to') ?: input('from'), availability_last_day()))
            : $error = 'Nurodykite laikotarpio pradžios ir pabaigos datas.',
        'clear' => $days = [],
        default => null,
    };
    if ($error !== '') {
        flash($error);
        redirect('/mano#laisvumas');
    }
    save_availability((int) $u['id'], $days);
    flash(availability_ranges((int) $u['id']) ? 'Laisvos dienos išsaugotos.' : 'Laisvos dienos pašalintos. Įstaigų vadovai matys, kad šiuo metu darbo pasiūlymų nepriimate.');
    redirect('/mano#laisvumas');
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
        .'<div class="account-links"><a href="/profilis">Redaguoti profilį</a>'.$admin.' · '.post_button('/atsijungti', 'Atsijungti', [], 'linkish').'</div></section>', ['noindex' => true]);
}

function worker_dashboard(array $u): string
{
    [$avg, $n] = worker_rating((int) $u['id']);
    $availability = availability_panel($u);

    $jobs = open_requests(['roles' => from_list($u['roles']), 'cities' => from_list($u['cities'])], 30);
    $responded = array_column(sql('SELECT request_id, status FROM responses WHERE worker_id = ?', [$u['id']])->fetchAll(), 'status', 'request_id');
    $jobs = array_filter($jobs, fn ($r) => !isset($responded[$r['id']]));
    $jobCards = $jobs ? '<div class="cards">'.implode('', array_map('request_card', $jobs)).'</div>' : '<p class="empty">Šiuo metu jums tinkančių naujų darbo pasiūlymų nėra. Kai jų atsiras, informuosime jus el. paštu.</p>';

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
        $pendingHtml .= request_card($r, '<p class="meta wait">Laukiama įstaigos vadovo sprendimo</p>');
    }
    $photoTip = $u['photo'] ? '' : '<p class="notice tip">Įkelkite profilio nuotrauką: profiliai su nuotrauka paprastai sulaukia daugiau darbo pasiūlymų. <a href="/profilis">Įkelti nuotrauką</a></p>';
    return '<div class="greeting">'.avatar($u).'<div><p class="eyebrow">Darbuotojo paskyra</p><h1>'.e(first_name($u['name'])).'</h1>'
        .'<p class="meta">Jūsų įvertinimas: '.stars($avg, $n).' · Darbų per GANG: '.count($contracts).'</p></div></div>'
        .$photoTip.$availability
        .($contractHtml ? '<h2>Mano GANG kontraktai</h2><div class="rows">'.$contractHtml.'</div>' : '')
        .($pendingHtml ? '<h2>Pateiktos kandidatūros</h2><div class="cards">'.$pendingHtml.'</div>' : '')
        .'<h2>Jums tinkantys darbo pasiūlymai</h2>'.$jobCards;
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
    return '<p class="eyebrow">Įstaigos vadovo paskyra</p><h1>'.e(first_name($u['name'])).'</h1>'
        .'<p class="cta-row"><a class="btn" href="/poreikis/naujas">+ Paskelbti poreikį</a></p>'
        .'<h2>Aktyvūs poreikiai</h2>'.($openHtml ? '<div class="cards">'.$openHtml.'</div>' : '<p class="empty">Aktyvių poreikių nėra. Jei susirgo darbuotojas ar artėja sezonas, paskelbkite poreikį: tinkami darbuotojai pranešimą gaus iš karto.</p>')
        .($contractHtml ? '<h2>GANG kontraktai</h2><div class="rows">'.$contractHtml.'</div>' : '')
        .'<h2>Mano įstaigos</h2><ul class="plain">'.$venueHtml.'</ul><p><a href="/istaiga/nauja">+ Pridėti kitą įstaigą</a></p>';
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
        .'<p>Pažymėkite dienas per šį ir penkis ateinančius mėnesius, kuriomis galite priimti darbo pasiūlymus. Įstaigų vadovai, ieškantys darbuotojo konkrečiai datai, matys, kad tą dieną esate laisvi.</p>'
        .$status.$summary
        .'<form method="post" action="/laisvas" class="calendar-form">'.csrf_field()
        // First submit button handles Enter in the date fields: save, not "today".
        .'<button class="offscreen" tabindex="-1" aria-hidden="true">Išsaugoti</button>'
        .'<div class="quick"><span class="meta">Greitai pažymėti:</span>'
        .'<button class="btn small ghost" name="add" value="today">Šiandien</button>'
        .'<button class="btn small ghost" name="add" value="week">Artimiausios 7 dienos</button>'
        .'<button class="btn small ghost" name="add" value="month">Artimiausias mėnuo</button></div>'
        .'<div class="range"><label>Nuo <input type="date" name="from" min="'.today().'" max="'.$last.'"></label>'
        .'<label>Iki <input type="date" name="to" min="'.today().'" max="'.$last.'"></label>'
        .'<button class="btn small ghost" name="add" value="range">Pažymėti laikotarpį</button></div>'
        .'<p class="meta cal-hint">Spustelėkite dieną, kad ją pažymėtumėte arba atšauktumėte. Norėdami pažymėti laikotarpį, spustelėkite pirmąją ir, laikydami nuspaudę „Shift“, paskutinę dieną.</p>'
        .'<div class="calendar">'.$cal.'</div>'
        .'<div class="cal-actions"><button class="btn">Išsaugoti laisvas dienas</button>'
        .($ranges ? '<button class="btn small ghost" name="add" value="clear" onclick="return confirm(\'Pašalinti visas pažymėtas laisvas dienas?\')">Pašalinti visas</button>' : '').'</div>'
        .'</form></div>'.calendar_script();
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
