<?php

declare(strict_types=1);

// An employer's first steps: own details, venue, first search ("Žingsnis 1 iš 3").
const ONBOARDING_STEPS = [1 => 'Jūsų duomenys', 2 => 'Darbovietė', 3 => 'Pirmoji paieška'];

function onboarding_step(int $n): string
{
    $dots = '';
    foreach (ONBOARDING_STEPS as $i => $label) {
        $dots .= '<li'.($i < $n ? ' class="done"' : ($i === $n ? ' class="now" aria-current="step"' : '')).'>'.e($label).'</li>';
    }
    return '<p class="eyebrow">Žingsnis '.$n.' iš '.count(ONBOARDING_STEPS).'</p><ol class="onboard" aria-label="Registracijos žingsniai">'.$dots.'</ol>';
}

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
        if (($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $stored = store_photo($_FILES['photo']);
            if (str_starts_with($stored, '!')) {
                $errors['photo'] = substr($stored, 1);
            } else {
                $photo = $stored;
            }
        } elseif (isset($_POST['remove_photo'])) {
            $photo = null;
        }
        if ($errors && $photo !== $u['photo']) {
            delete_photo($photo);
            $photo = $u['photo'];
        }
        if (!$errors) {
            $first = !profile_complete($u);
            $matchedBefore = $worker ? array_column(matching_jobs($u), 'id') : [];
            sql('UPDATE users SET name = ?, phone = ?, about = ?, roles = ?, cities = ?, durations = ?, experience = ?, notify = ?, photo = ? WHERE id = ?', [
                $v['name'], $v['phone'], $v['about'], $worker ? to_list($v['roles'], ROLES) : '', $worker ? to_list($v['cities'], CITIES) : '', $worker ? to_list($v['durations'], KINDS) : '', $xp ? min($xp) : 0, $v['notify'] ? 1 : 0, $photo, $u['id'],
            ]);
            if ($worker) {
                save_role_experience((int) $u['id'], $xp);
                $fresh = sql('SELECT * FROM users WHERE id = ?', [$u['id']])->fetch();
                team_joined_notice($fresh);
                $jobsMailed = notify_worker_of_open_jobs($fresh, $matchedBefore);
            }
            if ($photo !== $u['photo']) {
                delete_photo($u['photo']);
            }
            if (!$worker && !sql('SELECT id FROM venues WHERE manager_id = ?', [$u['id']])->fetchColumn()) {
                redirect('/darboviete/nauja');
            }
            $jobsNote = !empty($jobsMailed) ? ' Jums tinkančius darbo pasiūlymus išsiuntėme el. paštu.' : '';
            flash((!$first ? 'Profilio pakeitimai išsaugoti.' : 'Profilis sukurtas. Pažymėkite dienas, kuriomis galite priimti darbo pasiūlymus.').$jobsNote);
            redirect('/mano');
        }
    }
    $title = profile_complete($u) ? 'Mano profilis' : ($worker ? 'Susikurkite profilį' : 'Jūsų duomenys');
    $workerFields = '';
    $remove = $u['photo'] ? '<label class="check"><input type="checkbox" name="remove_photo"> Pašalinti paveikslėlį</label>' : '';
    $photoField = '<fieldset class="photo-field"><legend>Profilio nuotrauka arba avataras <small>(neprivaloma)</small></legend>'
        .'<div class="photo-row">'.avatar($u, 'large').'<div>'
        .'<p class="hint">'.($worker
            ? 'Jei norite, įkelkite savo nuotrauką arba avatarą, kuris bus rodomas jūsų profilyje.'
            : 'Jei norite, įkelkite savo nuotrauką arba avatarą. Jis bus rodomas pokalbiuose su darbuotojais.')
        .' Neįkėlus paveikslėlio, rodomos jūsų vardo ir pavardės pirmosios raidės.</p>'
        .'<input type="file" name="photo" accept="image/jpeg,image/png,image/webp" aria-label="Profilio nuotrauka arba avataras" data-crop>'
        .'<p class="meta">JPG, PNG arba WEBP formatu, iki '.PHOTO_MAX_MB.' MB. Paveikslėlį mato tik '.($worker ? 'prisijungę darbdaviai' : 'jūsų kandidatai ir komandos nariai').'.</p>'
        .$remove.err($errors, 'photo').'</div></div></fieldset>';
    if ($worker) {
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
            .'<fieldset><legend>Jus dominantis darbas</legend><p class="hint">Pažymėkite, kokios trukmės darbo ieškote. Galite pasirinkti kelis variantus, pavyzdžiui, trumpus pavadavimus ir nuolatinį darbą.</p>'
            .'<div class="choices kind-choices">'.$durationChoices.'</div>'.err($errors, 'durations').'</fieldset>'
            .'<label>Apie save <small>(darbo patirtis, ankstesnės darbovietės, įgūdžiai)</small><textarea name="about" rows="5" maxlength="2000" placeholder="Pavyzdžiui: trejų metų barmeno patirtis kokteilių bare, darbo su kasos sistema įgūdžiai, galiojantis higienos pažymėjimas.">'.e($v['about']).'</textarea>'.err($errors, 'about').'</label>'
            .'<label class="check"><input type="checkbox" name="notify"'.($v['notify'] ? ' checked' : '').'> Gauti el. laiškus apie naujus man tinkančius darbo pasiūlymus</label>';
    }
    $phoneNote = 'Naudojamas tik sisteminiams pranešimams siųsti.';
    $step = !$worker && !profile_complete($u) ? onboarding_step(1) : '';
    page($title, '<section class="wrap narrow">'.$step.'<h1>'.$title.'</h1>'
        .'<form method="post" class="form" enctype="multipart/form-data" novalidate>'.csrf_field()
        .$photoField
        .'<label>Vardas ir pavardė <input name="name" value="'.e($v['name']).'" autocomplete="name" required>'.err($errors, 'name').'</label>'
        .'<label>Telefono numeris <small>'.$phoneNote.'</small><input type="tel" name="phone" value="'.e($v['phone']).'" autocomplete="tel" inputmode="tel" maxlength="24" required placeholder="+370 600 00000" aria-describedby="phone-live">'.err($errors, 'phone').'<span class="err-live" id="phone-live" role="alert" hidden></span></label>'
        .$workerFields
        .'<p class="meta">El. paštas: '.e($u['email']).'</p>'
        .'<button class="btn">Išsaugoti</button></form>'
        .'<h2>Mano duomenys</h2><p class="meta">Galite atsisiųsti viską, ką GANG apie jus saugo, arba ištrinti paskyrą kartu su visais duomenimis.</p>'
        .'<p class="venue-actions"><a class="btn small ghost" href="/profilis/duomenys">Atsisiųsti mano duomenis</a> <a class="btn small ghost" href="/profilis/istrinti">Ištrinti paskyrą</a></p>'
        .'<h2>Prisijungimai</h2><p class="meta">Jei prisijungėte svetimame ar pamestame įrenginyje, atjunkite visus įrenginius. Šiame įrenginyje taip pat reikės prisijungti iš naujo.</p>'
        .'<div class="venue-actions">'.post_button('/atsijungti/visur', 'Atsijungti visuose įrenginiuose', [], 'btn small ghost', 'Atsijungti visuose įrenginiuose, įskaitant šį?').'</div>'
        .'</section>'.phone_check_script().photo_resize_script(), ['noindex' => true]);
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
  // data-crop="logo": a rounded square, and the whole logo may be shrunk to fit on white.
  var logo = input.dataset.crop === 'logo';
  var canCrop = !!(window.DataTransfer && HTMLCanvasElement.prototype.toBlob);
  var box, img, zoom, help, st = null, pointers = {}, pinch = 0;
  function build() {
    box = document.createElement('div');
    box.className = 'photo-crop' + (logo ? ' venue-logo' : '');
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
      help.textContent = logo ? 'Sumažinkite arba priartinkite logotipą ir patempkite jį į vietą.' : 'Patempkite nuotrauką ir pasirinkite, kuri jos dalis bus matoma apskritime.';
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
    var ow = v - st.w * s, oh = v - st.h * s;
    st.x = Math.min(Math.max(0, ow), Math.max(Math.min(0, ow), st.x));
    st.y = Math.min(Math.max(0, oh), Math.max(Math.min(0, oh), st.y));
    img.style.width = st.w * s + 'px';
    img.style.height = st.h * s + 'px';
    img.style.transform = 'translate(' + st.x + 'px,' + st.y + 'px)';
    if (zoom) zoom.value = st.z;
  }
  function setZoom(z, cx, cy) {
    z = Math.min(st.max, Math.max(st.min, z));
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
    var remove = row.querySelector('input[name^=remove_]');
    if (remove) remove.checked = false;
    var url = URL.createObjectURL(file), probe = new Image();
    probe.onload = function () {
      var w = probe.naturalWidth, h = probe.naturalHeight, v = size(), side = Math.min(w, h);
      st = {w: w, h: h, z: 1, base: v / side, min: logo ? side / Math.max(w, h) : 1, max: Math.max(1, Math.min(4, side / MIN))};
      // Same starting point as the server crop: centred, portraits a little above centre.
      st.x = (v - w * st.base) / 2;
      st.y = h > w ? -(h - side) / 4 * st.base : (v - h * st.base) / 2;
      if (logo) { st.z = st.min; st.x = (v - w * st.base * st.z) / 2; st.y = (v - h * st.base * st.z) / 2; }
      if (zoom) { zoom.min = st.min; zoom.max = st.max; zoom.disabled = st.max === st.min; }
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

function dashboard_page(): never
{
    $u = require_user();
    if (!profile_complete($u)) {
        redirect('/profilis');
    }
    $body = ($u['kind'] === 'worker' ? worker_dashboard($u) : manager_dashboard($u));
    $kind = $u['kind'] === 'worker' ? 'Darbuotojo paskyra' : 'Darbdavio paskyra';
    $rating = '';
    if ($u['kind'] === 'worker') {
        [$avg, $n] = worker_rating((int) $u['id']);
        $rating = '<span class="meta account-rating">Jūsų įvertinimas: '.stars($avg, $n).' · '.gang_jobs_text((int) $u['id']).'</span>';
    }
    page('Mano GANG', '<section class="wrap">'
        .'<div class="account-bar">'.avatar($u, 'account-avatar')
        .'<div class="account-who"><span class="eyebrow">'.$kind.'</span><h1 class="account-name">'.e($u['name']).'</h1><span class="meta">'.e($u['email']).'</span>'.$rating.'</div>'
        .'<div class="account-actions"><a class="btn small" href="/profilis">Redaguoti profilį</a></div>'
        .'</div>'.$body.'</section>', ['noindex' => true]);
}

const CONTRACT_SELECT = 'SELECT c.*, r.role, r.kind, '.CONTRACT_DATES.', r.hours, v.name AS venue_name, v.address, v.city, v.manager_id,
        m.name AS manager_name, m.phone AS manager_phone, m.photo AS manager_photo, w.name AS worker_name, w.phone AS worker_phone, w.photo AS worker_photo
    FROM contracts c JOIN requests r ON r.id = c.request_id JOIN venues v ON v.id = c.venue_id JOIN users m ON m.id = v.manager_id JOIN users w ON w.id = c.worker_id';

// Upcoming work first (soonest at the top), then past contracts (latest first).
function sort_contracts(array $contracts): array
{
    $end = fn ($c) => $c['cancelled_at'] ? '0000-00-00' : ($c['kind'] === 'nuolat' ? '9999-12-31' : ($c['end_date'] ?: $c['start_date']));
    $upcoming = array_values(array_filter($contracts, fn ($c) => $end($c) >= today()));
    $past = array_values(array_filter($contracts, fn ($c) => $end($c) < today()));
    usort($upcoming, fn ($a, $b) => strcmp($a['start_date'], $b['start_date']));
    usort($past, fn ($a, $b) => strcmp($b['start_date'], $a['start_date']));
    return [...$upcoming, ...$past];
}

// Street address with the city added unless it is already there.
function full_address(array $c): string
{
    $city = CITIES[$c['city']] ?? '';
    $address = trim((string) $c['address']);
    return $address === '' ? $city : ($city === '' || mb_stripos($address, $city) !== false ? $address : $address.', '.$city);
}

// One GANG contract with what each side needs next: the chat, where to go, the dates in a calendar.
function contract_card(array $c, string $side, bool $reviewed): string
{
    $worker = $side === 'worker';
    $end = contract_end($c);
    $started = $c['start_date'] <= today();
    $over = $end < today();
    if ($c['cancelled_at']) {
        return cancelled_contract_card($c, $side);
    }
    $steps = ['Kandidatūra' => 'done', 'Patvirtinta' => 'done', 'Darbas' => $over || $reviewed ? 'done' : ($started ? 'now' : ''), 'Įvertinimas' => $reviewed ? 'done' : ($over ? 'now' : '')];
    $stepper = '<ol class="stepper">';
    foreach ($steps as $label => $state) {
        $stepper .= '<li'.($state ? ' class="'.$state.'"' : '').'>'.$label.'</li>';
    }
    $stepper .= '</ol>';
    // Phone numbers stay hidden: the two sides talk in the GANG chat and swap numbers there if they want to.
    $place = full_address($c);
    $info = $worker
        ? '<div><dt>Adresas</dt><dd>'.e($place).'</dd></div><div><dt>Kontaktas</dt><dd>'.e($c['manager_name']).'</dd></div>'
        : '<div><dt>Darbuotojas</dt><dd><a href="/darbuotojas/'.(int) $c['worker_id'].'">'.e($c['worker_name']).'</a></dd></div><div><dt>Darbovietė</dt><dd>'.e($c['venue_name']).'</dd></div>';
    $actions = '';
    $me = (int) ($worker ? $c['worker_id'] : $c['manager_id']);
    $unread = chat_unread((int) $c['id'], $me);
    $actions .= '<a class="btn small" href="'.e(chat_url($c)).'" data-chat-link="'.(int) $c['id'].'">Žinutės'.($unread ? ' <span class="count">'.$unread.'</span>' : '').'</a>';
    if ($worker && $place !== '') {
        $query = full_address($c);
        $actions .= '<a class="btn small ghost" href="https://www.google.com/maps/search/?api=1&amp;query='.rawurlencode($query).'" target="_blank" rel="noopener">Maršrutas</a>';
    }
    if (!$over) {
        $actions .= '<a class="btn small ghost" href="/kontraktas/'.(int) $c['id'].'/kalendorius">Į kalendorių</a>';
    }
    $actions .= review_link($c, $reviewed);
    if (!$over) {
        $actions .= '<a class="btn small ghost quiet" href="/kontraktas/'.(int) $c['id'].'/atsaukti">Atšaukti darbą</a>';
    }
    $attendance = '';
    if (!$worker && $started && $end >= date('Y-m-d', strtotime('-'.NO_SHOW_DAYS.' days'))) {
        $attendance = $c['no_show_at']
            ? '<p class="shift-status warn">Pažymėta, kad darbuotojas neatvyko '.post_button('/kontraktas/'.(int) $c['id'].'/neatvyko', 'Pašalinti žymą', ['undo' => '1'], 'btn small ghost').'</p>'
            : '<p class="attendance meta">Darbuotojas neatvyko? '.post_button('/kontraktas/'.(int) $c['id'].'/neatvyko', 'Pažymėti neatvykimą', [], 'btn small ghost',
                'Pažymėti, kad darbuotojas neatvyko? Tai sumažins jo patikimumo rodiklį, o darbuotojas gaus pranešimą el. paštu.').'</p>';
    } elseif ($worker && $c['no_show_at']) {
        $attendance = '<p class="shift-status warn">Darbdavys pažymėjo, kad neatvykote</p>';
    }
    $title = $worker ? e(ROLES[$c['role']] ?? '').' · '.e($c['venue_name']) : e($c['worker_name']).' · '.e(ROLES[$c['role']] ?? '');
    return '<article class="contract'.($over ? ' past' : '').'">'.$stepper
        .'<h3><a href="/darbas/'.(int) $c['request_id'].'">'.$title.'</a></h3>'
        .'<p class="meta">'.e(period($c)).($c['hours'] !== '' ? ' · '.e($c['hours']) : '').'</p>'
        .'<dl class="contract-info">'.$info.'</dl>'.shift_status($c, $side).$attendance
        .'<p class="contract-actions">'.$actions.'</p></article>';
}

function cancelled_contract_card(array $c, string $side): string
{
    $by = $c['cancelled_by'] === $side ? 'Atšaukėte jūs' : ($c['cancelled_by'] === 'worker' ? 'Atšaukė darbuotojas' : 'Atšaukė darbdavys');
    $why = CANCEL_REASONS[$c['cancelled_by']][$c['cancel_reason']] ?? '';
    $title = $side === 'worker' ? e(ROLES[$c['role']] ?? '').' · '.e($c['venue_name']) : e($c['worker_name']).' · '.e(ROLES[$c['role']] ?? '');
    return '<article class="contract past cancelled"><p class="tags"><span class="tag">Atšaukta</span></p>'
        .'<h3><a href="/darbas/'.(int) $c['request_id'].'">'.$title.'</a></h3>'
        .'<p class="meta">'.e(period($c)).'</p>'
        .'<p class="meta">'.e($by.', '.date_lt($c['cancelled_at'])).($why !== '' ? ' · '.e($why) : '').($c['late'] ? ' · likus mažiau nei parai' : '').'</p>'
        .($c['cancel_note'] !== '' ? '<p class="note">'.e($c['cancel_note']).'</p>' : '').'</article>';
}

function contract_ics(int $id): never
{
    $u = require_user();
    $c = sql(CONTRACT_SELECT.' WHERE c.id = ?', [$id])->fetch();
    if (!$c || ((int) $c['worker_id'] !== (int) $u['id'] && (int) $c['manager_id'] !== (int) $u['id'])) {
        not_found();
    }
    $worker = (int) $c['worker_id'] === (int) $u['id'];
    $ranges = request_ranges($c);
    $text = fn (string $s) => str_replace(['\\', ';', ',', "\r", "\n"], ['\\\\', '\;', '\,', '', '\n'], $s);
    $role = ROLES[$c['role']] ?? $c['role'];
    $place = full_address($c);
    $about = ($c['hours'] !== '' ? 'Darbo laikas: '.$c['hours']."\n" : '')
        .($worker ? 'Kontaktas: '.$c['manager_name'] : 'Darbuotojas: '.$c['worker_name'])
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

// Open offers for a worker, not yet answered: [personal invitations, other matching offers].
function worker_open_offers(array $u): array
{
    $jobs = open_requests(['roles' => from_list($u['roles']), 'cities' => from_list($u['cities']), 'kinds' => worker_durations($u)], 30);
    $responded = array_column(sql('SELECT request_id, status FROM responses WHERE worker_id = ?', [$u['id']])->fetchAll(), 'status', 'request_id');
    $jobs = array_filter($jobs, fn ($r) => !isset($responded[$r['id']]));
    // Personal invitations from employers the worker already worked with come first.
    [$where, $params] = open_where();
    $invites = sql(REQUEST_SELECT." JOIN invites i ON i.request_id = r.id WHERE i.worker_id = ? AND $where
        AND NOT EXISTS (SELECT 1 FROM responses s WHERE s.request_id = r.id AND s.worker_id = i.worker_id) ORDER BY r.start_date", array_merge([$u['id']], $params))->fetchAll();
    $inviteIds = array_column($invites, 'id');
    $jobs = array_filter($jobs, fn ($r) => !in_array($r['id'], $inviteIds));
    return [$invites, array_values($jobs)];
}

// "Jums tinkantys darbo pasiūlymai": the full list behind "Peržiūrėti" on Mano GANG.
function worker_offers_page(): never
{
    $u = require_user();
    if ($u['kind'] !== 'worker') {
        redirect('/mano');
    }
    [, $jobs] = worker_open_offers($u);
    $cards = $jobs ? '<div class="cards">'.implode('', array_map('request_card', $jobs)).'</div>'
        : '<div class="empty-state"><span class="empty-mark" aria-hidden="true">0</span><div><h3>Naujų pasiūlymų kol kas nėra</h3>'
        .'<p>'.($u['notify'] ? 'Kai darbdavys paskelbs jūsų pareigas, miestą ir darbo trukmę atitinkantį pasiūlymą, iš karto informuosime jus el. paštu.' : 'Pranešimai el. paštu išjungti, todėl naujus pasiūlymus matysite tik čia. Juos galite įjungti profilio nustatymuose.').'</p>'
        .'<p class="empty-actions"><a class="btn small ghost" href="/darbai">Visi darbo pasiūlymai</a><a class="btn small ghost" href="/profilis">Atnaujinti pareigas ir miestus</a></p></div></div>';
    page('Jums tinkantys darbo pasiūlymai', '<section class="wrap list-page"><p class="meta"><a href="/mano">← Mano GANG</a></p>'
        .'<h1>Jums tinkantys pasiūlymai</h1><p class="meta">Pagal jūsų pareigas, miestus ir darbo trukmę: '.count($jobs).'</p>'.$cards.'</section>', ['noindex' => true]);
}

function worker_dashboard(array $u): string
{
    [$avg, $n] = worker_rating((int) $u['id']);
    $calendar = gang_calendar_panel($u);

    [$invites, $jobs] = worker_open_offers($u);
    $inviteHtml = implode('', array_map(fn ($r) => request_card($r, '<p class="meta new">Asmeninis kvietimas: esate šio darbdavio komandoje</p>'), $invites));

    $contracts = sort_contracts(sql(str_replace(' FROM contracts c', ', (SELECT COUNT(*) FROM reviews rv WHERE rv.contract_id = c.id AND rv.author = \'worker\') AS reviewed FROM contracts c', CONTRACT_SELECT)
        .' WHERE c.worker_id = ? ORDER BY c.created_at DESC LIMIT 50', [$u['id']])->fetchAll());
    $contractHtml = implode('', array_map(fn ($c) => contract_card($c, 'worker', (bool) $c['reviewed']), $contracts));
    $pending = sql(str_replace(' FROM requests r', ', s.id AS response_id FROM requests r', REQUEST_SELECT).' JOIN responses s ON s.request_id = r.id WHERE s.worker_id = ? AND s.status = \'sent\' AND r.status = \'open\' ORDER BY s.created_at DESC', [$u['id']])->fetchAll();
    $pendingHtml = '';
    foreach ($pending as $r) {
        // The employer wrote before deciding: the card says so and opens the chat.
        $unread = chat_unread(-(int) $r['response_id'], (int) $u['id']);
        $add = $unread ? '<p class="card-extra"><a href="'.e(chat_url(['request_id' => $r['id'], 'id' => -(int) $r['response_id']])).'">Nauja žinutė nuo darbdavio'.($unread > 1 ? ' ('.$unread.')' : '').'</a></p>' : '';
        $pendingHtml .= request_card($r, '<p class="meta wait">Laukiama darbdavio sprendimo</p>'.$add, false);
    }
    // The one thing to do now, most urgent first.
    $upcoming = array_values(array_filter($contracts, fn ($c) => ($c['kind'] === 'nuolat' ? $c['start_date'] : ($c['end_date'] ?: $c['start_date'])) >= today()));
    $toReview = array_values(array_filter($contracts, fn ($c) => !$c['reviewed'] && $c['start_date'] <= today()));
    $check = sql(SHIFT_SELECT.' WHERE c.worker_id = ? AND s.shift_date >= ? AND s.confirmed_at IS NULL AND s.declined_at IS NULL ORDER BY s.shift_date LIMIT 1', [$u['id'], today()])->fetch();
    $what = fn ($r) => (ROLES[$r['role']] ?? $r['role']).' · '.$r['venue_name'];
    $next = match (true) {
        (bool) $check => ['warn', ($check['shift_date'] === today() ? 'Šiandien' : 'Rytoj').' dirbate', $what($check).'. Patvirtinkite, kad atvyksite.', '/pamaina/'.$check['token'], 'Patvirtinti atvykimą'],
        (bool) $invites => ['', 'Asmeninis kvietimas', $what($invites[0]).', '.rtrim(period($invites[0]), '.').'. Su šiuo darbdaviu jau dirbote kartu.', '/darbas/'.(int) $invites[0]['id'], 'Peržiūrėti kvietimą'],
        (bool) $toReview => ['', 'Įvertinkite darbą', $what($toReview[0]).'. Jūsų įvertinimas padeda kitiems kolegoms.', '/vertinti/'.(int) $toReview[0]['id'], 'Įvertinti'],
        (bool) $upcoming => ['', 'Kitas darbas', $what($upcoming[0]).', '.rtrim(period($upcoming[0]), '.').'.', '/darbas/'.(int) $upcoming[0]['request_id'], 'Darbo informacija'],
        (bool) $jobs => ['', 'Jums tinkantys pasiūlymai', 'Pasiūlymų pagal jūsų pareigas ir miestus: '.count($jobs).'.', '/mano/pasiulymai', 'Peržiūrėti'],
        // Nothing to do now: no card at all.
        default => null,
    };
    $tiles = summary_tiles([
        ['Artimiausi darbai', (string) count($upcoming), '#kontraktai'],
        ['Pateiktos kandidatūros', (string) count($pending), '#kandidaturos'],
        ['Tinkantys pasiūlymai', (string) count($jobs), '/mano/pasiulymai'],
        ['Įvertinimas', $n ? number_format((float) $avg, 1, ',', '') : '–', ''],
    ]);
    // Name, rating and GANG jobs are on the account card at the top of the page (dashboard_page).
    return ($next ? next_step(...$next) : '').$tiles
        .($inviteHtml ? '<h2>Asmeniniai kvietimai</h2><div class="cards">'.$inviteHtml.'</div>' : '')
        .($contractHtml ? '<h2 id="kontraktai">Mano GANG kontraktai</h2><div class="contracts">'.$contractHtml.'</div>' : '')
        .($pendingHtml ? '<h2 id="kandidaturos">Pateiktos kandidatūros</h2><div class="cards">'.$pendingHtml.'</div>' : '')
        .$calendar
        .invite_box($u);
}

// The manager's open requests as cards, with candidates and confirmed workers. $in: the manager's venue ids, comma-separated.
// Returns [requests, cards html, [[request, candidates], ...]].
function manager_open_requests(string $in): array
{
    [$where, $params] = open_where();
    $open = sql(REQUEST_SELECT." WHERE r.venue_id IN ($in) AND $where ORDER BY r.start_date", $params)->fetchAll();
    $openHtml = '';
    $waiting = [];
    foreach ($open as $r) {
        $n = (int) sql("SELECT COUNT(*) FROM responses WHERE request_id = ? AND status = 'sent'", [$r['id']])->fetchColumn();
        if ($n) {
            $waiting[] = [$r, $n];
        }
        // Confirmed workers stay on the card: a request with some days (or places) still open keeps showing here.
        // No "Pakartoti" anywhere for now, and no "Redaguoti" once someone is confirmed (Vilius 2026-11-08: as few buttons as possible).
        $hired = sql(CONTRACT_SELECT.' WHERE c.request_id = ? AND c.cancelled_at IS NULL ORDER BY c.created_at', [$r['id']])->fetchAll();
        $label = $n ? "<p class=\"meta new\">Gauta kandidatūrų: $n. Peržiūrėkite</p>" : ($hired ? '' : '<p class="meta wait">Kandidatūrų dar negauta</p>');
        // Candidates who wrote in the chat before the decision.
        $news = $n ? (int) sql("SELECT COUNT(*) FROM messages m JOIN responses s ON m.contract_id = -s.id
            LEFT JOIN chat_reads cr ON cr.contract_id = m.contract_id AND cr.user_id = ?
            WHERE m.contract_id < 0 AND s.request_id = ? AND s.status = 'sent' AND m.sender_id = s.worker_id AND m.id > COALESCE(cr.last_id, 0)", [$r['manager_id'], $r['id']])->fetchColumn() : 0;
        $label .= $news ? '<p class="meta new"><a href="/darbas/'.(int) $r['id'].'#kandidatai">Naujos kandidatų žinutės: '.$news.'</a></p>' : '';
        foreach ($hired as $c) {
            $label .= '<p class="meta done">Patvirtinta: '.e(first_name($c['worker_name'])).', '.e(period($c)).'</p>';
        }
        if ($hired) {
            $label .= '<p class="meta wait">Dar ieškome: '.e(open_period($r)).'</p>';
        }
        $openHtml .= request_card($r, $label.($hired ? '' : '<p class="card-actions"><a class="btn small ghost" href="/darbas/'.(int) $r['id'].'/redaguoti">Redaguoti</a></p>'), false);
    }
    return [$open, $openHtml, $waiting];
}

// "Aktyvios paieškos" on their own page: /mano shows only the count (Vilius 2026-11-08: less on one screen).
function manager_requests_page(): never
{
    $u = require_user();
    if ($u['kind'] !== 'manager') {
        redirect('/mano');
    }
    $ids = sql('SELECT id FROM venues WHERE manager_id = ?', [$u['id']])->fetchAll(PDO::FETCH_COLUMN);
    if (!$ids) {
        redirect('/darboviete/nauja');
    }
    [$open, $openHtml] = manager_open_requests(implode(',', array_map('intval', $ids)));
    $cards = $openHtml ? '<div class="cards">'.$openHtml.'</div>'
        : '<div class="empty-state"><span class="empty-mark" aria-hidden="true">0</span><div><h3>Aktyvių paieškų nėra</h3>'
        .'<p>Jei susirgo darbuotojas, artėja sezonas ar didelis renginys, paskelbkite paiešką. Tinkami darbuotojai pranešimą gaus iš karto.</p>'
        .'<p class="empty-actions"><a class="btn small" href="/poreikis/naujas">+ Paskelbti paiešką</a></p></div></div>';
    page('Aktyvios paieškos', '<section class="wrap list-page"><p class="meta"><a href="/mano">← Mano GANG</a></p>'
        .'<h1>Aktyvios paieškos</h1><p class="meta">Iš viso: '.count($open).'</p>'.$cards
        .($openHtml ? '<p class="cta-row"><a class="btn small ghost" href="/poreikis/naujas">+ Nauja paieška</a></p>' : '').'</section>', ['noindex' => true]);
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
        $venueHtml .= '<div class="venue-card"><div class="venue-card-head">'.venue_logo($v, 'small').'<a class="venue-name" href="/darboviete/'.(int) $v['id'].'">'.e($v['name']).'</a></div>'
            .'<span class="meta">'.e(CITIES[$v['city']] ?? '').($v['address'] !== '' ? ' · '.e($v['address']) : '').'</span>'.stars($avg, $n)
            .'<p class="venue-actions"><a class="btn small ghost" href="/darboviete/'.(int) $v['id'].'/redaguoti">Redaguoti</a><a class="btn small ghost" href="/darboviete/'.(int) $v['id'].'">Peržiūrėti</a></p></div>';
    }
    $short = array_filter($venues, fn ($v) => mb_strlen(trim((string) $v['about'])) < VENUE_ABOUT_MIN);
    $aboutTip = $short ? '<p class="notice tip">Papildykite darbovietės aprašymą: kokia tai vieta, kokia komanda ir kokios darbo sąlygos. Tai padeda darbuotojams apsispręsti. <a href="/darboviete/'.(int) reset($short)['id'].'/redaguoti">Papildyti aprašymą</a></p>' : '';
    $ids = array_column($venues, 'id');
    $in = implode(',', array_map('intval', $ids));
    [$open, $openHtml, $waiting] = manager_open_requests($in);
    $openIds = array_column($open, 'id') ?: [0];
    $earlier = sql(REQUEST_SELECT." WHERE r.venue_id IN ($in) AND r.id NOT IN (".implode(',', array_map('intval', $openIds)).') ORDER BY r.created_at DESC LIMIT 6')->fetchAll();
    $earlierHtml = '';
    foreach ($earlier as $r) {
        $earlierHtml .= '<div class="row-item"><div><strong><a href="/darbas/'.(int) $r['id'].'">'.e(ROLES[$r['role']] ?? '').'</a> · '.e($r['venue_name']).'</strong>'
            .'<p class="meta">'.e(period($r)).($r['hours'] !== '' ? ' · '.e($r['hours']) : '').($r['pay'] !== '' ? ' · '.e($r['pay']) : '').'</p></div></div>';
    }
    $contracts = sort_contracts(sql(str_replace(' FROM contracts c', ', (SELECT COUNT(*) FROM reviews rv WHERE rv.contract_id = c.id AND rv.author = \'manager\') AS reviewed FROM contracts c', CONTRACT_SELECT)
        .' WHERE c.venue_id IN ('.$in.') ORDER BY c.created_at DESC LIMIT 50')->fetchAll());
    $contractHtml = implode('', array_map(fn ($c) => contract_card($c, 'manager', (bool) $c['reviewed']), $contracts));
    // No "Mano komanda" section here: the team is shown where it is used, in the new search form (Vilius 2026-11-08: redundant on /mano).
    $teamCount = count(team_members((int) $u['id']));
    $upcoming = array_values(array_filter($contracts, fn ($c) => ($c['kind'] === 'nuolat' ? $c['start_date'] : ($c['end_date'] ?: $c['start_date'])) >= today()));
    $toReview = array_values(array_filter($contracts, fn ($c) => !$c['reviewed'] && $c['start_date'] <= today()));
    $alert = sql(SHIFT_SELECT.' WHERE c.venue_id IN ('.$in.') AND s.shift_date >= ? AND s.confirmed_at IS NULL AND (s.declined_at IS NOT NULL OR s.alerted_at IS NOT NULL) ORDER BY s.shift_date LIMIT 1', [today()])->fetch();
    $candidates = array_sum(array_column($waiting, 1));
    $next = match (true) {
        (bool) $alert => ['warn', ($alert['shift_date'] === today() ? 'Šiandien' : 'Rytoj').': atvykimas dar nepatvirtintas', $alert['worker_name'].' · '.(ROLES[$alert['role']] ?? $alert['role']).'. Parašykite darbuotojui, o prireikus raskite pakaitinį.', replacement_url($alert), 'Rasti pakaitinį'],
        (bool) $waiting => ['', 'Laukia jūsų sprendimo', 'Kandidatūrų: '.$candidates.'. Pirmoji paieška: '.(ROLES[$waiting[0][0]['role']] ?? '').' · '.$waiting[0][0]['venue_name'].', '.rtrim(period($waiting[0][0]), '.').'.', '/darbas/'.(int) $waiting[0][0]['id'], 'Peržiūrėti kandidatus'],
        (bool) $toReview => ['', 'Įvertinkite darbuotoją', $toReview[0]['worker_name'].' · '.(ROLES[$toReview[0]['role']] ?? '').'. Įvertinimas padeda kitiems darbdaviams.', '/vertinti/'.(int) $toReview[0]['id'], 'Įvertinti'],
        !$open => ['', 'Aktyvių paieškų nėra', 'Jei susirgo darbuotojas, artėja sezonas ar didelis renginys, paskelbkite paiešką.', '/poreikis/naujas', '+ Paskelbti paiešką'],
        default => ['calm', 'Paieškos aktyvios', 'Kai darbuotojai pateiks kandidatūras, pranešime el. paštu.', '/mano/paieskos', 'Peržiūrėti'],
    };
    $tiles = summary_tiles([
        ['Aktyvios paieškos', (string) count($open), '/mano/paieskos'],
        ['Laukia sprendimo', (string) $candidates, $waiting ? '/darbas/'.(int) $waiting[0][0]['id'] : ''],
        ['Artimiausi darbai', (string) count($upcoming), '#kontraktai'],
        ['Komanda', (string) $teamCount, '#kviesti'],
    ]);
    return (manager_verified($u) ? next_step(...$next) : verification_note()).$tiles.$aboutTip
        .($next[3] !== '/poreikis/naujas' ? '<p class="cta-row"><a class="btn small ghost" href="/poreikis/naujas">+ Paskelbti paiešką</a></p>' : '')
        .($contractHtml ? '<h2 id="kontraktai">GANG kontraktai</h2><div class="contracts">'.$contractHtml.'</div>' : '')
        .($earlierHtml ? '<h2>Ankstesnės paieškos</h2><div class="rows">'.$earlierHtml.'</div>' : '')
        .'<h2>Mano darbovietės</h2><div class="venue-grid">'.$venueHtml
        .'<a class="venue-card add" href="/darboviete/nauja"><span class="plus" aria-hidden="true">+</span><strong>Pridėti darbovietę</strong><span class="meta">Kitas jūsų restoranas, kavinė, baras ar klubas</span></a></div>'
        .invite_box($u);
}

// "Kitas žingsnis": the one thing to do now, on top of Mano GANG. $tone: '' | warn | calm.
function next_step(string $tone, string $title, string $text, string $href, string $label): string
{
    return '<section class="next-step'.($tone ? ' '.$tone : '').'"><div><p class="eyebrow">Kitas žingsnis</p><h2>'.e($title).'</h2><p>'.e($text).'</p></div>'
        .($href !== '' ? '<a class="btn small" href="'.e($href).'">'.e($label).'</a>' : '').'</section>';
}

// A row of small figures that jump to their section. Each item: [label, value, href or ''].
function summary_tiles(array $items): string
{
    $out = '<div class="sum-tiles">';
    foreach ($items as [$label, $value, $href]) {
        $inner = '<strong>'.e($value).'</strong><span>'.e($label).'</span>';
        $out .= $href !== '' ? '<a class="sum-tile" href="'.e($href).'">'.$inner.'</a>' : '<div class="sum-tile">'.$inner.'</div>';
    }
    return $out.'</div>';
}

// "Mano GANG kalendorius": the worker's confirmed GANG work, filled in automatically from contracts.
// Three months from this one; a day with work links to the job. On phones, months without work fold away.
function gang_calendar_panel(array $u): string
{
    $first = date('Y-m-01');
    $last = date('Y-m-t', strtotime($first.' +2 months'));
    $work = gang_work_days((int) $u['id'], today());
    $names = ['', 'Sausis', 'Vasaris', 'Kovas', 'Balandis', 'Gegužė', 'Birželis', 'Liepa', 'Rugpjūtis', 'Rugsėjis', 'Spalis', 'Lapkritis', 'Gruodis'];
    $months = '';
    for ($m = 0; $m < 3; $m++) {
        $start = strtotime($first.' +'.$m.' months');
        $lead = (int) date('N', $start) - 1;
        $n = (int) date('t', $start);
        $busy = false;
        $cells = str_repeat('<span class="day pad"></span>', $lead);
        for ($d = 1; $d <= $n; $d++) {
            $day = date('Y-m-', $start).sprintf('%02d', $d);
            $cls = ((int) date('N', strtotime($day)) >= 6 ? ' weekend' : '').($day === today() ? ' today' : '').($day < today() ? ' off' : '');
            if (isset($work[$day])) {
                $busy = true;
                $c = $work[$day][0];
                $title = implode('; ', array_map(fn ($x) => $x['venue_name'].', '.(ROLES[$x['role']] ?? ''), $work[$day]));
                $cells .= '<a class="day work'.$cls.'" href="/darbas/'.(int) $c['request_id'].'" title="'.e(date_lt($day).': '.$title).'" aria-label="'.e(date_lt($day).': '.$title).'"><span>'.$d.'</span></a>';
            } else {
                $cells .= '<span class="day'.$cls.'"><span>'.$d.'</span></span>';
            }
        }
        $months .= '<div class="month'.($busy || $m === 0 ? '' : ' idle').'"><h3>'.$names[(int) date('n', $start)].' '.date('Y', $start).'</h3>'
            .'<div class="week"><span>Pr</span><span>An</span><span>Tr</span><span>Kt</span><span>Pn</span><span>Št</span><span>Sk</span></div><div class="days">'.$cells.'</div></div>';
    }
    // Upcoming work as a list: one row per contract and period.
    $rows = [];
    foreach ($work as $day => $cs) {
        foreach ($cs as $c) {
            $rows[(int) $c['id']] ??= $c + ['days' => []];
            $rows[(int) $c['id']]['days'][] = $day;
        }
    }
    $list = implode('', array_map(fn ($c) => '<li><a href="/darbas/'.(int) $c['request_id'].'"><strong>'.e($c['kind'] === 'nuolat' ? 'nuo '.date_lt($c['days'][0]) : days_text($c['days'])).'</strong>'
        .'<span>'.e($c['venue_name'].' · '.(ROLES[$c['role']] ?? '')).($c['hours'] !== '' ? ' · '.e($c['hours']) : '').'</span></a></li>', array_slice($rows, 0, 12)));
    $later = $work && array_key_last($work) > $last ? '<p class="meta">Vėlesni darbai rodomi sąraše.</p>' : '';
    return '<section class="panel gang-cal" id="kalendorius"><h2>Mano GANG kalendorius</h2>'
        .'<p class="meta">Čia automatiškai atsiranda darbai, kuriems darbdavys patvirtino jūsų kandidatūrą. Teikiant kandidatūrą, GANG įspės, jei tomis dienomis jau turite darbą.</p>'
        .'<div class="pick-months n3">'.$months.'</div>'
        .($list ? '<ul class="cal-list">'.$list.'</ul>'.$later : '<p class="empty">Patvirtintų GANG darbų dar nėra.</p>')
        .'</section>';
}

function review_link(array $c, bool $reviewed): string
{
    if ($reviewed) {
        return '<span class="meta done">Įvertinta</span>';
    }
    // Nothing about rating until the work has started: before that it is just noise on the card.
    if ($c['start_date'] > today()) {
        return '';
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
    // Lithuanian national format: 8 600 12345 → +370 600 12345.
    if (preg_match('/^8\d{8}$/', preg_replace('/[\s()\-]/', '', $p))) {
        $p = '+370'.substr(preg_replace('/[\s()\-]/', '', $p), 1);
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
    // Lithuanian 8 600 12345: allowed while typing, turned into +370 600 12345 at 9 digits.
    var l = v.replace(/[\s()\-]/g, '');
    if (/^8\d*$/.test(l)) return l.length === 9 || (!final && l.length < 9) ? '' : 'Lietuvos numerį įveskite su +370 arba 8 pradžioje, pavyzdžiui, 8 600 12345.';
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
    if (/^8\d{8}$/.test(v.replace(/[\s()\-]/g, ''))) v = '+370' + v.replace(/[\s()\-]/g, '').slice(1);
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

// Contracts that still lie ahead (not cancelled, not over): the account cannot be deleted until they are cancelled.
function upcoming_contracts(array $u): array
{
    $where = $u['kind'] === 'worker' ? 'c.worker_id = ?' : 'v.manager_id = ?';
    $rows = sql(CONTRACT_SELECT.' WHERE '.$where.' AND c.cancelled_at IS NULL', [$u['id']])->fetchAll();
    return array_values(array_filter($rows, fn ($c) => contract_end($c) >= today()));
}

// "Atsisiųsti mano duomenis": everything GANG keeps about the member, as JSON (GDPR art. 15 and 20).
function account_export(): never
{
    $u = require_user();
    $id = (int) $u['id'];
    $rows = fn (string $q, ?array $p = null) => sql($q, $p ?? [$id])->fetchAll(PDO::FETCH_ASSOC);
    $profile = array_intersect_key($u, array_flip(['kind', 'email', 'name', 'phone', 'roles', 'cities', 'about', 'durations', 'notify', 'created_at', 'last_login_at', 'terms_version', 'terms_accepted_at', 'verified_at']));
    $venues = $rows('SELECT id, name, type, concept, city, address, about, hours, team_size, perks, website, instagram, facebook, tiktok, created_at FROM venues WHERE manager_id = ?');
    $venueIds = array_map('intval', array_column($venues, 'id')) ?: [0];
    $in = implode(',', $venueIds);
    $contractIds = array_map('intval', array_column($rows("SELECT c.id FROM contracts c WHERE c.worker_id = ? OR c.venue_id IN ($in)"), 'id')) ?: [0];
    $cin = implode(',', $contractIds);
    $data = [
        'eksportuota' => date('c'),
        'profilis' => $profile,
        'pareigos_ir_patirtis' => $rows('SELECT role, experience FROM worker_roles WHERE user_id = ?'),
        'laisvos_dienos' => $rows('SELECT start_date, end_date FROM availability WHERE user_id = ?'),
        'darbovietes' => $venues,
        'paieskos' => $rows("SELECT id, venue_id, role, kind, people, start_date, end_date, dates, pay, description, status, created_at FROM requests WHERE venue_id IN ($in)", []),
        'kandidaturos' => $rows('SELECT request_id, note, dates, status, created_at FROM responses WHERE worker_id = ?'),
        'kandidaturu_papildymai' => $rows('SELECT s.request_id, n.body, n.created_at FROM response_notes n JOIN responses s ON s.id = n.response_id WHERE s.worker_id = ?'),
        'kontraktai' => $rows("SELECT id, request_id, venue_id, start_date, end_date, dates, created_at, cancelled_at, cancelled_by, cancel_reason, cancel_note, no_show_at FROM contracts WHERE id IN ($cin)", []),
        'mano_zinutes' => $rows('SELECT contract_id, body, created_at FROM messages WHERE sender_id = ?'),
        'mano_ivertinimai' => $rows("SELECT contract_id, rating, c1, c2, c3, text, created_at FROM reviews WHERE contract_id IN ($cin) AND author = ?", [$u['kind']]),
        'gauti_ivertinimai' => $rows("SELECT contract_id, rating, c1, c2, c3, text, created_at FROM reviews WHERE contract_id IN ($cin) AND author <> ?", [$u['kind']]),
        'sutikimai' => $rows('SELECT version, accepted_at, ip, user_agent FROM consents WHERE user_id = ?'),
    ];
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="gang-mano-duomenys-'.today().'.json"');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// "Ištrinti paskyrą": the member deletes their own account and all its data (the same as admin delete).
function account_delete_page(): never
{
    $u = require_user();
    $upcoming = upcoming_contracts($u);
    $error = '';
    if (is_post()) {
        check_csrf();
        if ($upcoming) {
            redirect('/profilis/istrinti');
        }
        if (!isset($_POST['confirm'])) {
            $error = 'Pažymėkite, kad suprantate: ištrintos paskyros atkurti nebus galima.';
        } else {
            send_mail($u['email'], 'Jūsų GANG paskyra ištrinta', [
                'kicker' => 'Paskyra',
                'title' => 'Paskyra ištrinta',
                'lead' => 'jūsų prašymu ištrynėme jūsų GANG paskyrą ir su ja susijusius duomenis.',
                'body' => 'Jei paskyrą ištrynėte per klaidą, galite bet kada užsiregistruoti iš naujo. Jei paskyros netrynėte, nedelsdami parašykite mums [hello@gang.lt](mailto:hello@gang.lt).',
            ]);
            admin_delete_user((int) $u['id']);
            $_SESSION = [];
            session_regenerate_id(true);
            flash('Paskyra ištrinta. Ačiū, kad buvote su GANG.');
            redirect('/');
        }
    }
    $worker = $u['kind'] === 'worker';
    $what = $worker
        ? '<li>profilis, nuotrauka, pareigos, miestai ir laisvos dienos;</li><li>kandidatūros, GANG kontraktai, pokalbių žinutės;</li><li>jūsų parašyti ir jums skirti įvertinimai;</li>'
        : '<li>profilis, visos jūsų darbovietės ir jų logotipai;</li><li>visos paieškos, gautos kandidatūros, GANG kontraktai, pokalbių žinutės;</li><li>jūsų parašyti ir jūsų darbovietėms skirti įvertinimai, komandos nariai ir kvietimai;</li>';
    if ($upcoming) {
        $list = '';
        foreach ($upcoming as $c) {
            $who = $worker ? $c['venue_name'] : $c['worker_name'].' · '.$c['venue_name'];
            $list .= '<li>'.e((ROLES[$c['role']] ?? $c['role']).', '.$who.', '.rtrim(period($c), '.')).' – <a href="/kontraktas/'.(int) $c['id'].'/atsaukti">atšaukti</a></li>';
        }
        $body = '<div class="panel"><p><strong>Turite būsimų darbų.</strong> Kad kita pusė nebūtų palikta be žinios, prieš ištrindami paskyrą atšaukite juos:</p><ul>'.$list.'</ul></div>';
    } else {
        $body = '<form method="post" class="form">'.csrf_field()
            .'<label class="check"><input type="checkbox" name="confirm" required> Suprantu, kad ištrintos paskyros ir jos duomenų atkurti nebus galima.</label>'
            .($error ? '<p class="err">'.e($error).'</p>' : '')
            .'<button class="btn danger">Ištrinti paskyrą visam laikui</button></form>';
    }
    page('Ištrinti paskyrą', '<section class="wrap narrow"><h1>Ištrinti paskyrą</h1>'
        .'<p class="lead">Ištrynus paskyrą, iš GANG visam laikui pašalinsime:</p><ul>'.$what.'<li>el. pašto adresą '.e($u['email']).' ir sutikimų įrašus.</li></ul>'
        .'<p>Prieš trindami galite <a href="/profilis/duomenys">atsisiųsti savo duomenis</a>. Išsiųsti el. laiškai lieka gavėjų pašto dėžutėse.</p>'
        .$body.'<p><a href="/profilis">← Grįžti į profilį</a></p></section>', ['noindex' => true]);
}

// /atsisakyti/{id}/{token} from job-offer emails. GET asks first (mail scanners open links);
// POST, including the mail client's own one-click POST (RFC 8058), turns the emails off.
function unsubscribe_page(int $id, string $token): never
{
    header('X-Robots-Tag: noindex, nofollow');
    $u = sql("SELECT * FROM users WHERE id = ? AND kind = 'worker'", [$id])->fetch();
    if (!$u || !hash_equals(unsubscribe_token($id), $token)) {
        not_found();
    }
    if (is_post()) {
        sql('UPDATE users SET notify = 0 WHERE id = ?', [$id]);
        if (($_POST['List-Unsubscribe'] ?? '') === 'One-Click') {
            header('Content-Type: text/plain; charset=utf-8');
            echo 'OK';
            exit;
        }
        page('Laiškų atsisakyta', '<section class="wrap narrow"><h1>Laiškų atsisakyta</h1><p class="lead">Daugiau nesiųsime laiškų apie naujus darbo pasiūlymus adresu <strong>'.e($u['email']).'</strong>.</p>'
            .'<p>Laiškus apie jūsų kandidatūras, patvirtintus darbus ir žinutes gausite toliau. Darbo pasiūlymų laiškus vėl įjungti galite <a href="/profilis">profilyje</a>.</p></section>', ['noindex' => true]);
    }
    $body = (int) $u['notify']
        ? '<p class="lead">Nebesiųsime laiškų apie naujus darbo pasiūlymus adresu <strong>'.e($u['email']).'</strong>. Laiškus apie jūsų kandidatūras, patvirtintus darbus ir žinutes gausite toliau.</p>'
            .'<form method="post" class="form"><button class="btn">Atsisakyti darbo pasiūlymų laiškų</button></form>'
        : '<p class="lead">Laiškų apie naujus darbo pasiūlymus adresu <strong>'.e($u['email']).'</strong> jau atsisakėte. Vėl įjungti galite <a href="/profilis">profilyje</a>.</p>';
    page('Atsisakyti laiškų', '<section class="wrap narrow"><h1>Atsisakyti laiškų</h1>'.$body.'</section>', ['noindex' => true]);
}
