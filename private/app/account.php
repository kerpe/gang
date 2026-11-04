<?php

declare(strict_types=1);

function profile_page(): never
{
    $u = require_user();
    $worker = $u['kind'] === 'worker';
    $v = ['name' => $u['name'], 'phone' => $u['phone'], 'about' => (string) $u['about'], 'roles' => from_list($u['roles']), 'cities' => from_list($u['cities']), 'xp' => array_map('strval', array_filter(role_experience((int) $u['id']))), 'durations' => worker_durations($u), 'notify' => (bool) $u['notify']];
    $errors = [];
    if (is_post()) {
        // A file over post_max_size empties $_POST, so say that instead of "form expired".
        if (!$_POST && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            flash('Nuotrauka per didelė. Įkelkite iki '.PHOTO_MAX_MB.' MB dydžio failą.');
            redirect('/profilis');
        }
        check_csrf();
        $v = ['name' => input('name'), 'phone' => input('phone'), 'about' => input('about'),
            'roles' => (array) ($_POST['roles'] ?? []), 'cities' => (array) ($_POST['cities'] ?? []), 'xp' => array_map('strval', (array) ($_POST['xp'] ?? [])), 'durations' => (array) ($_POST['durations'] ?? []), 'notify' => isset($_POST['notify'])];
        if (mb_strlen($v['name']) < 2 || mb_strlen($v['name']) > 120) {
            $errors['name'] = 'Įrašykite vardą ir pavardę.';
        }
        [$phone, $phoneError] = check_phone($v['phone']);
        if ($phoneError !== '') {
            $errors['phone'] = $phoneError;
        } else {
            $v['phone'] = $phone;
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
        if ($worker && to_list($v['durations'], KINDS) === '') {
            $errors['durations'] = 'Pažymėkite bent vieną jus dominančią darbo trukmę.';
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
            sql('UPDATE users SET name = ?, phone = ?, about = ?, roles = ?, cities = ?, durations = ?, experience = ?, notify = ?, photo = ? WHERE id = ?', [
                $v['name'], $v['phone'], $v['about'], $worker ? to_list($v['roles'], ROLES) : '', $worker ? to_list($v['cities'], CITIES) : '', $worker ? to_list($v['durations'], KINDS) : '', $xp ? min($xp) : 0, $v['notify'] ? 1 : 0, $photo, $u['id'],
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
            .'<input type="file" name="photo" accept="image/jpeg,image/png,image/webp" data-crop>'
            .'<p class="meta">JPG, PNG arba WEBP formatu, iki '.PHOTO_MAX_MB.' MB. Paveikslėlį mato tik prisijungę darbdaviai.</p>'
            .$remove.err($errors, 'photo').'</div></div></fieldset>';
        $roleRows = '';
        foreach (ROLES as $role => $label) {
            $roleRows .= '<div class="role-xp"><label class="choice"><input type="checkbox" name="roles[]" value="'.$role.'"'.(in_array($role, $v['roles'], true) ? ' checked' : '').'> '.e($label).'</label>'
                .'<select name="xp['.$role.']" aria-label="'.e($label).': patirtis">'.options(['' => 'Nurodykite patirtį'] + EXPERIENCE, $v['xp'][$role] ?? '').'</select></div>';
        }
        $durationChoices = '';
        foreach (KINDS as $kind => $label) {
            $durationChoices .= '<label class="choice"><input type="checkbox" name="durations[]" value="'.$kind.'"'.(in_array($kind, $v['durations'], true) ? ' checked' : '').'>'
                .'<span><span class="choice-name">'.e($label).'</span><small>'.e(KIND_HINTS[$kind]).'</small></span></label>';
        }
        $workerFields = '<fieldset><legend>Pareigos ir patirtis</legend><p class="hint">Pažymėkite dominančias pareigas ir prie kiekvienų nurodykite savo patirtį. Darbo pasiūlymus gausite iš darbdavių, kurių reikalavimus atitinkate.</p>'
            .'<div class="role-list">'.$roleRows.'</div>'.err($errors, 'roles').'</fieldset>'
            .'<fieldset><legend>Miestai, kuriuose galite dirbti</legend>'.checkboxes('cities', CITIES, $v['cities']).err($errors, 'cities').'</fieldset>'
            .'<fieldset><legend>Jus dominantis darbas</legend><p class="hint">Pažymėkite, kokios trukmės darbo ieškote. Galite pasirinkti kelis variantus, pavyzdžiui, skubius pavadavimus ir nuolatinį darbą.</p>'
            .'<div class="choices kind-choices">'.$durationChoices.'</div>'.err($errors, 'durations').'</fieldset>'
            .'<label>Apie save <small>(darbo patirtis, ankstesnės darbovietės, įgūdžiai)</small><textarea name="about" rows="5" maxlength="2000" placeholder="Pavyzdžiui: trejų metų barmeno patirtis kokteilių bare, darbo su kasos sistema įgūdžiai, galiojantis higienos pažymėjimas.">'.e($v['about']).'</textarea>'.err($errors, 'about').'</label>'
            .'<label class="check"><input type="checkbox" name="notify"'.($v['notify'] ? ' checked' : '').'> Gauti el. laiškus apie naujus man tinkančius darbo pasiūlymus</label>';
    }
    $phoneNote = $worker ? 'Numerį darbdavys matys tik patvirtinęs jus darbui.' : 'Numerį darbuotojas matys tik po jūsų patvirtinimo.';
    $soon = prelaunch() && !profile_complete($u)
        ? '<p class="notice tip">'.($worker
            ? launch_headline().'. Dabar susikurkite profilį, o nuo starto dienos kalendoriuje pažymėsite laiką, kada galite dirbti, ir gausite darbo pasiūlymus.'
            : launch_headline().'. Dabar užpildykite savo ir darbovietės duomenis, o nuo starto dienos galėsite skelbti darbuotojų paieškas.').'</p>'
        : '';
    page($title, '<section class="wrap narrow"><h1>'.$title.'</h1>'.$soon
        .'<form method="post" class="form" enctype="multipart/form-data" novalidate>'.csrf_field()
        .$photoField
        .'<label>Vardas ir pavardė <input name="name" value="'.e($v['name']).'" autocomplete="name" required>'.err($errors, 'name').'</label>'
        .'<label>Telefono numeris <small>'.$phoneNote.'</small><input type="tel" name="phone" value="'.e($v['phone']).'" autocomplete="tel" inputmode="tel" maxlength="24" required placeholder="+370 600 00000" aria-describedby="phone-live">'.err($errors, 'phone').'<span class="err-live" id="phone-live" role="alert" hidden></span></label>'
        .$workerFields
        .'<p class="meta">El. paštas: '.e($u['email']).'</p>'
        .'<button class="btn">Išsaugoti</button></form></section>'.phone_check_script().($worker ? photo_resize_script() : ''), ['noindex' => true]);
}

// Shows the chosen photo in the circle right away and lets the worker zoom and drag it into place.
// On submit the visible square is drawn to a 600 × 600 JPEG that replaces the file; the server still
// validates and re-encodes it. Without DataTransfer support the photo is only previewed and cropped server-side.
function photo_resize_script(): string
{
    return <<<'HTML'
<script>
document.querySelectorAll('input[data-crop]').forEach(function (input) {
  var row = input.closest('.photo-row'), form = input.form, OUT = 600, MIN = 200;
  var canCrop = !!(window.DataTransfer && HTMLCanvasElement.prototype.toBlob);
  var box, img, zoom, help, st = null, pointers = {}, pinch = 0;
  function build() {
    box = document.createElement('div');
    box.className = 'photo-crop';
    img = document.createElement('img');
    img.alt = '';
    img.draggable = false;
    box.appendChild(img);
    var wrap = document.createElement('div');
    wrap.className = 'photo-crop-wrap';
    wrap.appendChild(box);
    if (canCrop) {
      var lab = document.createElement('label');
      lab.className = 'photo-zoom';
      lab.innerHTML = '<span>Priartinti</span><input type="range" min="1" max="4" step="0.01" value="1">';
      zoom = lab.querySelector('input');
      wrap.appendChild(lab);
      help = document.createElement('p');
      help.className = 'meta photo-help';
      help.textContent = 'Patempkite nuotrauką ir pasirinkite, kuri jos dalis bus matoma apskritime.';
      input.insertAdjacentElement('afterend', help);
      zoom.addEventListener('input', function () { setZoom(+zoom.value); });
      box.addEventListener('pointerdown', down);
      box.addEventListener('pointermove', move);
      ['pointerup', 'pointercancel'].forEach(function (t) { box.addEventListener(t, up); });
      box.addEventListener('wheel', function (e) {
        e.preventDefault();
        setZoom(st.z * (e.deltaY < 0 ? 1.08 : 1 / 1.08));
      }, {passive: false});
    }
    var old = row.querySelector('.avatar');
    old.style.display = 'none';
    old.insertAdjacentElement('afterend', wrap);
  }
  function size() { return box.clientWidth; }
  function render() {
    var s = st.base * st.z, v = size();
    st.x = Math.min(0, Math.max(v - st.w * s, st.x));
    st.y = Math.min(0, Math.max(v - st.h * s, st.y));
    img.style.width = st.w * s + 'px';
    img.style.height = st.h * s + 'px';
    img.style.transform = 'translate(' + st.x + 'px,' + st.y + 'px)';
    if (zoom) zoom.value = st.z;
  }
  function setZoom(z, cx, cy) {
    z = Math.min(st.max, Math.max(1, z));
    var v = size(), old = st.base * st.z, s = st.base * z;
    cx = cx === undefined ? v / 2 : cx; cy = cy === undefined ? v / 2 : cy;
    st.x = cx - (cx - st.x) * s / old;
    st.y = cy - (cy - st.y) * s / old;
    st.z = z;
    render();
  }
  function down(e) {
    box.setPointerCapture(e.pointerId);
    pointers[e.pointerId] = {x: e.clientX, y: e.clientY};
    box.classList.add('moving');
  }
  function move(e) {
    var p = pointers[e.pointerId];
    if (!p) return;
    var ids = Object.keys(pointers);
    if (ids.length === 2) {
      var a = pointers[ids[0]], b = pointers[ids[1]];
      var d0 = Math.hypot(a.x - b.x, a.y - b.y);
      p.x = e.clientX; p.y = e.clientY;
      var d1 = Math.hypot(a.x - b.x, a.y - b.y), r = box.getBoundingClientRect();
      if (d0 > 0) setZoom(st.z * d1 / d0, (a.x + b.x) / 2 - r.left, (a.y + b.y) / 2 - r.top);
      return;
    }
    st.x += e.clientX - p.x; st.y += e.clientY - p.y;
    p.x = e.clientX; p.y = e.clientY;
    render();
  }
  function up(e) {
    delete pointers[e.pointerId];
    if (!Object.keys(pointers).length) box.classList.remove('moving');
  }
  input.addEventListener('change', function () {
    var file = input.files[0];
    st = null;
    if (!file || !/^image\//.test(file.type)) return;
    if (!box) build();
    var remove = row.querySelector('input[name=remove_photo]');
    if (remove) remove.checked = false;
    var url = URL.createObjectURL(file), probe = new Image();
    probe.onload = function () {
      var w = probe.naturalWidth, h = probe.naturalHeight, v = size(), side = Math.min(w, h);
      st = {w: w, h: h, z: 1, base: v / side, max: Math.max(1, Math.min(4, side / MIN))};
      // Same starting point as the server crop: centred, portraits a little above centre.
      st.x = (v - w * st.base) / 2;
      st.y = h > w ? -(h - side) / 4 * st.base : (v - h * st.base) / 2;
      if (zoom) { zoom.max = st.max; zoom.disabled = st.max === 1; }
      img.src = url;
      render();
    };
    probe.onerror = function () { URL.revokeObjectURL(url); };
    probe.src = url;
  });
  window.addEventListener('resize', function () {
    if (!st) return;
    var v = size(), k = v / (st.base * Math.min(st.w, st.h));
    if (Math.abs(k - 1) < 0.001) return;
    st.base *= k; st.x *= k; st.y *= k;
    render();
  });
  if (!canCrop) return;
  var sending = false;
  form.addEventListener('submit', function (e) {
    if (!st || sending) return;
    e.preventDefault();
    var s = st.base * st.z, c = document.createElement('canvas');
    c.width = c.height = OUT;
    var g = c.getContext('2d');
    g.fillStyle = '#fff';
    g.fillRect(0, 0, OUT, OUT);
    g.imageSmoothingQuality = 'high';
    g.drawImage(img, -st.x / s, -st.y / s, size() / s, size() / s, 0, 0, OUT, OUT);
    c.toBlob(function (blob) {
      if (blob) {
        var dt = new DataTransfer();
        dt.items.add(new File([blob], 'nuotrauka.jpg', {type: 'image/jpeg'}));
        input.files = dt.files;
      }
      sending = true;
      form.submit();
    }, 'image/jpeg', 0.9);
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
        .'<dl class="contract-info">'.$info.'</dl>'.shift_status($c, $side)
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

    $jobs = open_requests(['roles' => from_list($u['roles']), 'cities' => from_list($u['cities']), 'kinds' => worker_durations($u)], 30);
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
        .'<p>'.($u['notify'] ? 'Kai darbdavys paskelbs jūsų pareigas, miestą ir darbo trukmę atitinkantį pasiūlymą, iš karto informuosime jus el. paštu.' : 'Pranešimai el. paštu išjungti, todėl naujus pasiūlymus matysite tik šiame puslapyje. Juos galite įjungti profilio nustatymuose.').'</p>'
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
        .'<p class="meta">Jūsų įvertinimas: '.stars($avg, $n).' · '.gang_jobs_text((int) $u['id']).'</p></div></div>'
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
        .'<section class="panel ok launch"><h2>'.e(launch_headline()).'</h2>'
        .'<p>Jūsų profilis išsaugotas. Starto dieną jį matys barai, restoranai ir kavinės jūsų miestuose, o mes jums pranešime el. paštu. Tada galėsite pažymėti laisvas dienas kalendoriuje ir priimti darbo pasiūlymus.</p></section>'
        .$tipHtml
        .'<h2>Taip jus matys darbdaviai</h2>'
        .'<section class="profile-preview"><dl class="contract-info">'
        .'<div><dt>Pareigos ir patirtis</dt><dd><ul class="xp-list">'.$xp.'</ul></dd></div>'
        .'<div><dt>Miestai</dt><dd>'.e(labels($u['cities'], CITIES)).'</dd></div>'
        .'<div><dt>Domina darbas</dt><dd>'.e(durations_text($u)).'</dd></div>'
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
        .'<section class="panel ok launch"><h2>'.e(launch_headline()).'</h2>'
        .'<p>Jūsų darbovietės duomenys išsaugoti. Starto dieną pranešime jums el. paštu: nuo tada galėsite skelbti darbuotojų paieškas pavadavimui, sezonui ar nuolatiniam darbui, o tinkami darbuotojai jas gaus iš karto.</p></section>'
        .'<h2>Ką galėsite daryti nuo '.e(launch_day() ?: 'starto dienos').'</h2><ol class="steps compact">'
        .'<li><strong>Paskelbti paiešką</strong>Pareigos, datos, darbo laikas ir atlygis.</li>'
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
        .($openHtml ? '<p class="cta-row"><a class="btn" href="/poreikis/naujas">+ Paskelbti paiešką</a></p>' : '')
        .'<h2>Aktyvios paieškos</h2>'.($openHtml ? '<div class="cards">'.$openHtml.'</div>' : '<div class="empty-state"><span class="empty-mark" aria-hidden="true">0</span><div><h3>Aktyvių paieškų nėra</h3>'
            .'<p>Jei susirgo darbuotojas, artėja sezonas ar didelis renginys, paskelbkite paiešką. Tinkami darbuotojai pranešimą gaus iš karto.</p>'
            .'<p class="empty-actions"><a class="btn small" href="/poreikis/naujas">+ Paskelbti paiešką</a></p></div></div>')
        .$team
        .($contractHtml ? '<h2>GANG kontraktai</h2><div class="contracts">'.$contractHtml.'</div>' : '')
        .($earlierHtml ? '<h2>Ankstesnės paieškos</h2><p class="meta">Paspaudę „Pakartoti“ gausite užpildytą formą: liks pasirinkti tik datas.</p><div class="rows">'.$earlierHtml.'</div>' : '')
        .'<h2>Mano darbovietės</h2><div class="venue-grid">'.$venueHtml
        .'<a class="venue-card add" href="/darboviete/nauja"><span class="plus" aria-hidden="true">+</span><strong>Pridėti darbovietę</strong><span class="meta">Kitas jūsų baras, restoranas ar kavinė</span></a></div>';
}

// The same calendar employers use for requests, always open: marked days, quick picks and save in one place.
function availability_panel(array $u): string
{
    $ranges = availability_ranges((int) $u['id']);
    $status = is_available((int) $u['id'])
        ? '<p class="status on">● Šiandien galite priimti darbo pasiūlymus</p>'
        : '<p class="status off">● Šiandien laisvos dienos nepažymėtos</p>';
    $value = implode(',', array_map(fn ($r) => max($r['start_date'], today()).':'.$r['end_date'], $ranges));
    return '<section class="panel avail" id="laisvumas"><h2>Mano laisvos dienos</h2>'.$status
        .'<p class="meta">Pažymėkite dienas per artimiausius 12 mėnesių, kuriomis galite priimti darbo pasiūlymus. Darbdaviai, ieškantys darbuotojo konkrečiai datai, matys, kad tą dieną esate laisvi.</p>'
        .'<form method="post" action="/laisvas" class="calendar-form">'.csrf_field()
        // First submit button handles Enter: save, not "today".
        .'<button class="offscreen" tabindex="-1" aria-hidden="true">Išsaugoti</button>'
        .'<div class="quick"><span class="meta">Greitai pažymėti:</span>'
        .'<button class="btn small ghost" name="add" value="today">Šiandien</button>'
        .'<button class="btn small ghost" name="add" value="week">Artimiausios 7 dienos</button>'
        .'<button class="btn small ghost" name="add" value="month">Artimiausias mėnuo</button></div>'
        .'<p class="hint">Spustelėkite dieną, kad pažymėtumėte vieną dieną. Spustelėjus kitą dieną, pažymimas laikotarpis nuo–iki. Galite pažymėti kelis laikotarpius. Pažymėtą dieną ar laikotarpį pašalinsite spustelėję jį dar kartą arba ×.</p>'
        .'<div class="picker" data-min="'.today().'" data-max="'.availability_last_day().'" data-limit="60" data-empty="Laisvų dienų dar nepažymėjote."><noscript><p class="notice">Kalendoriui reikalingas „JavaScript“. Įjunkite jį naršyklėje.</p></noscript></div>'
        .'<input type="hidden" name="dates" value="'.e($value).'">'
        .'<div class="cal-actions"><button class="btn">Išsaugoti laisvas dienas</button>'
        .'<span class="unsaved" hidden>Pakeitimai dar neišsaugoti</span>'
        .($ranges ? '<button class="btn small ghost" name="add" value="clear" onclick="return confirm(\'Pašalinti visas pažymėtas laisvas dienas?\')">Pašalinti visas</button>' : '').'</div>'
        .'</form></section>';
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

// Phone numbers: international format only. Country code => [country, min, max digits after the code].
const PHONE_CODES = [
    '370' => ['Lietuva', 8, 8], '371' => ['Latvija', 8, 8], '372' => ['Estija', 7, 8], '48' => ['Lenkija', 9, 9],
    '49' => ['Vokietija', 7, 12], '44' => ['Jungtinė Karalystė', 10, 10], '353' => ['Airija', 7, 9], '33' => ['Prancūzija', 9, 9],
    '34' => ['Ispanija', 9, 9], '39' => ['Italija', 6, 11], '31' => ['Nyderlandai', 9, 9], '32' => ['Belgija', 8, 9],
    '45' => ['Danija', 8, 8], '46' => ['Švedija', 7, 9], '47' => ['Norvegija', 8, 8], '358' => ['Suomija', 6, 10],
    '351' => ['Portugalija', 9, 9], '30' => ['Graikija', 10, 10], '43' => ['Austrija', 7, 13], '41' => ['Šveicarija', 9, 9],
    '420' => ['Čekija', 9, 9], '421' => ['Slovakija', 9, 9], '36' => ['Vengrija', 8, 9], '40' => ['Rumunija', 9, 9],
    '359' => ['Bulgarija', 8, 9], '385' => ['Kroatija', 8, 9], '386' => ['Slovėnija', 8, 8], '352' => ['Liuksemburgas', 6, 11],
    '356' => ['Malta', 8, 8], '357' => ['Kipras', 8, 8], '354' => ['Islandija', 7, 7], '380' => ['Ukraina', 9, 9],
    '375' => ['Baltarusija', 9, 9], '373' => ['Moldova', 8, 8], '7' => ['Rusija / Kazachstanas', 10, 10], '995' => ['Sakartvelas', 9, 9],
    '374' => ['Armėnija', 8, 8], '994' => ['Azerbaidžanas', 9, 9], '998' => ['Uzbekistanas', 9, 9], '996' => ['Kirgizija', 9, 9],
    '992' => ['Tadžikija', 9, 9], '993' => ['Turkmėnistanas', 8, 8], '381' => ['Serbija', 8, 9], '382' => ['Juodkalnija', 8, 8],
    '383' => ['Kosovas', 8, 8], '387' => ['Bosnija ir Hercegovina', 8, 8], '389' => ['Šiaurės Makedonija', 8, 8], '355' => ['Albanija', 9, 9],
    '90' => ['Turkija', 10, 10], '1' => ['JAV / Kanada', 10, 10], '52' => ['Meksika', 10, 10], '55' => ['Brazilija', 10, 11],
    '61' => ['Australija', 9, 9], '64' => ['Naujoji Zelandija', 8, 10], '91' => ['Indija', 10, 10], '92' => ['Pakistanas', 10, 10],
    '880' => ['Bangladešas', 10, 10], '86' => ['Kinija', 11, 11], '81' => ['Japonija', 9, 10], '82' => ['Pietų Korėja', 9, 10],
    '84' => ['Vietnamas', 9, 10], '66' => ['Tailandas', 8, 9], '63' => ['Filipinai', 10, 10], '62' => ['Indonezija', 9, 12],
    '972' => ['Izraelis', 8, 9], '971' => ['JAE', 8, 9], '20' => ['Egiptas', 9, 10], '27' => ['PAR', 9, 9], '234' => ['Nigerija', 8, 10],
];
const PHONE_FORMAT_ERROR = 'Netinkamai įvestas telefono numeris: įrašykite jį su šalies kodu, pavyzdžiui, +370 600 00000.';

// Returns [normalized number, ''] or ['', error message].
function check_phone(string $raw): array
{
    $p = trim($raw);
    if (str_starts_with($p, '00')) {
        $p = '+'.substr($p, 2);
    }
    if (!preg_match('/^\+[0-9 ()\-]+$/', $p)) {
        return ['', PHONE_FORMAT_ERROR];
    }
    $digits = preg_replace('/\D/', '', $p);
    foreach ([3, 2, 1] as $len) {
        $code = substr($digits, 0, $len);
        if (isset(PHONE_CODES[$code])) {
            [$country, $min, $max] = PHONE_CODES[$code];
            $n = strlen($digits) - $len;
            if ($n < $min || $n > $max) {
                $need = $min === $max ? $min : "$min–$max";
                return ['', "Netinkamai įvestas telefono numeris: skaitmenų po šalies kodo +$code ($country) turi būti $need, o įvesta $n."];
            }
            return [format_phone($code, substr($digits, $len)), ''];
        }
    }
    return ['', 'Netinkamai įvestas šalies kodas. Patikrinkite numerio pradžią, pavyzdžiui, +370 (Lietuva).'];
}

// Digit groups after the country code, so a number reads like +370 605 16846.
const PHONE_GROUPS = ['370' => [3, 5], '371' => [4, 4], '48' => [3, 3, 3], '44' => [4, 6], '1' => [3, 3, 4], '353' => [2, 3, 4], '49' => [3, 4, 5], '7' => [3, 3, 2, 2]];

function phone_groups(string $code, int $n): array
{
    if (isset(PHONE_GROUPS[$code])) {
        return PHONE_GROUPS[$code];
    }
    if ($n <= 6) {
        return [3, 3];
    }
    if ($n <= 8) {
        return [$n - 4, 4];
    }
    return $n % 3 === 1 ? array_merge(array_fill(0, intdiv($n, 3) - 1, 3), [4]) : array_fill(0, intdiv($n + 2, 3), 3);
}

// "+370 605 16846"; digits beyond the pattern stay in the last group.
function format_phone(string $code, string $national): string
{
    $parts = [];
    $groups = phone_groups($code, strlen($national));
    foreach ($groups as $i => $size) {
        if ($national === '') {
            break;
        }
        $take = $i === count($groups) - 1 ? strlen($national) : $size;
        $parts[] = substr($national, 0, $take);
        $national = substr($national, $take);
    }
    return '+'.$code.($parts ? ' '.implode(' ', $parts) : '');
}

// Same check in the browser, so a mistake shows while typing.
function phone_check_script(): string
{
    $codes = json_encode(PHONE_CODES, JSON_UNESCAPED_UNICODE);
    $format = json_encode(PHONE_FORMAT_ERROR, JSON_UNESCAPED_UNICODE);
    $groups = json_encode(PHONE_GROUPS);
    return <<<HTML
<script>
(function () {
  var input = document.querySelector('input[name=phone]'), msg = document.getElementById('phone-live');
  if (!input || !msg) return;
  var codes = $codes, formatError = $format;
  function problem(v, final) {
    v = v.trim();
    if (v === '') return final ? formatError : '';
    if (v.indexOf('00') === 0) v = '+' + v.slice(2);
    if (!/^\+[0-9 ()\-]*$/.test(v)) return formatError;
    var d = v.replace(/\D/g, '');
    if (d.length < 3 && !final) return '';
    for (var len = 3; len >= 1; len--) {
      var c = d.slice(0, len), r = codes[c];
      if (!r) continue;
      var n = d.length - len;
      if (n > r[2] || (final && n < r[1])) return 'Netinkamai įvestas telefono numeris: skaitmenų po šalies kodo +' + c + ' (' + r[0] + ') turi būti ' + (r[1] === r[2] ? r[1] : r[1] + '–' + r[2]) + ', o įvesta ' + n + '.';
      return '';
    }
    return 'Netinkamai įvestas šalies kodas. Patikrinkite numerio pradžią, pavyzdžiui, +370 (Lietuva).';
  }
  var groupsFor = $groups;
  function groups(c, n) {
    if (groupsFor[c]) return groupsFor[c];
    if (n <= 6) return [3, 3];
    if (n <= 8) return [n - 4, 4];
    var g = [], k = Math.floor((n + 2) / 3);
    if (n % 3 === 1) k = Math.floor(n / 3);
    for (var i = 0; i < k; i++) g.push(i === k - 1 && n % 3 === 1 ? 4 : 3);
    return g;
  }
  // Spaces are added while typing: +37060516846 shows as +370 605 16846.
  function pretty(v) {
    if (v.indexOf('00') === 0) v = '+' + v.slice(2);
    if (!/^\+[0-9 ]*$/.test(v)) return null;
    var d = v.replace(/\D/g, '');
    for (var len = 3; len >= 1; len--) {
      var c = d.slice(0, len);
      if (!codes[c]) continue;
      var rest = d.slice(len), g = groups(c, rest.length), out = '+' + c;
      for (var i = 0; i < g.length && rest !== ''; i++) {
        var take = i === g.length - 1 ? rest.length : g[i];
        out += ' ' + rest.slice(0, take);
        rest = rest.slice(take);
      }
      return out;
    }
    return null;
  }
  function reformat() {
    var v = input.value, pos = input.selectionStart == null ? v.length : input.selectionStart;
    var f = pretty(v);
    if (f === null || f === v) return;
    var before = v.slice(0, pos).replace(/\D/g, '').length, at = 0, seen = 0;
    if (before === 0) at = pos > 0 ? 1 : 0;
    else for (; at < f.length && seen < before; at++) if (/\d/.test(f[at])) seen++;
    input.value = f;
    if (document.activeElement === input) input.setSelectionRange(at, at);
  }
  function show(final) { var p = problem(input.value, final); msg.textContent = p; msg.hidden = !p; return !p; }
  // Backspace or Delete next to an inserted space removes the digit beside it.
  input.addEventListener('beforeinput', function (e) {
    var s = input.selectionStart, v = input.value;
    if (s == null || s !== input.selectionEnd) return;
    var cut = e.inputType === 'deleteContentBackward' && v[s - 1] === ' ' && s > 1 ? s - 2
      : e.inputType === 'deleteContentForward' && v[s] === ' ' ? s + 1 : -1;
    if (cut < 0) return;
    e.preventDefault();
    input.value = v.slice(0, cut) + v.slice(cut + 1);
    input.setSelectionRange(Math.min(cut, s), Math.min(cut, s));
    reformat();
    show(false);
  });
  input.addEventListener('input', function () {
    var clean = input.value.replace(/[^0-9+ ()\-]/g, '');
    if (clean !== input.value) input.value = clean;
    reformat();
    show(false);
  });
  input.addEventListener('blur', function () { if (input.value.trim() !== '') show(true); });
  input.form.addEventListener('submit', function (e) { if (!show(true)) { e.preventDefault(); input.focus(); } });
  reformat();
})();
</script>
HTML;
}
