<?php

declare(strict_types=1);

// Personal invitation links /k/{code}. A manager's link brings their own staff into "Mano komanda";
// a worker's link brings a colleague to GANG (users.referred_by).

const TEAM_MAIL_PER_DAY = 50;

function invite_landing_page(string $code): never
{
    $from = sql('SELECT * FROM users WHERE invite_code = ? AND blocked = 0', [$code])->fetch();
    if (!$from) {
        not_found();
    }
    start_session();
    $u = current_user();
    $manager = $from['kind'] === 'manager';
    $venues = $manager ? sql('SELECT * FROM venues WHERE manager_id = ? ORDER BY id', [$from['id']])->fetchAll() : [];
    $venueNames = implode(', ', array_column($venues, 'name'));
    if ($manager && is_post()) {
        check_csrf();
        if ($u && $u['kind'] === 'worker') {
            flash(join_team((int) $from['id'], (int) $u['id']) ? 'Prisijungėte prie komandos: '.$venueNames.'.' : 'Jūs jau esate šios komandos narys.');
        }
        redirect('/mano');
    }
    if (!$u) {
        // Remembered through sign-up and login (see apply_invite()).
        $_SESSION['invite_code'] = $code;
    }
    $who = first_name($from['name']);
    $logo = $venues ? venue_logo($venues[0], 'large') : '';
    if ($manager) {
        $title = ($venueNames !== '' ? $venueNames : $who).' kviečia jus į savo komandą';
        $lead = $who.' kviečia jus prisijungti prie '.($venueNames !== '' ? '<strong>'.e($venueNames).'</strong>' : 'savo').' komandos GANG platformoje. Komandos nariai pirmieji gauna asmeninius kvietimus į pamainas, o kiekvienas darbas per GANG kaupia jūsų darbų istoriją ir įvertinimus.';
        $action = match (true) {
            $u === null => '<p><a class="btn" href="/prisijungti?kaip=darbuotojas">Susikurti profilį ir prisijungti</a> <a href="/prisijungti">Jau turiu paskyrą</a></p>',
            $u['kind'] === 'worker' => '<form method="post" class="form">'.csrf_field().'<button class="btn">Prisijungti prie komandos</button></form>',
            (int) $u['id'] === (int) $from['id'] => '<p class="notice ok">Taip jūsų kvietimą matys darbuotojai.</p>',
            default => '<p class="notice">Prisijungti prie komandos gali tik darbuotojų paskyros.</p>',
        };
    } else {
        $title = $who.' kviečia jus į GANG';
        $lead = 'GANG padeda barams, restoranams ir kavinėms rasti patikimus darbuotojus, o hospitality specialistams – papildomų pamainų ir darbo pasiūlymų. Jūsų kolega '.e($who).' jau yra GANG narys.';
        $action = $u === null
            ? '<p><a class="btn" href="/prisijungti?kaip=darbuotojas">Ieškau darbo</a> <a class="btn ghost" href="/prisijungti?kaip=vadovas">Ieškau darbuotojų</a></p>'
            : ((int) $u['id'] === (int) $from['id'] ? '<p class="notice ok">Taip jūsų kvietimą matys kolegos.</p>' : '<p><a class="btn" href="/mano">Mano GANG</a></p>');
    }
    page($title, '<section class="wrap narrow invite-landing">'.($logo ? '<div class="invite-logo">'.$logo.'</div>' : '')
        .'<p class="eyebrow">Asmeninis kvietimas</p><h1>'.e($title).'</h1><p class="lead">'.$lead.'</p>'.$action
        .'<p class="meta"><a href="/kaip-veikia">Kaip veikia GANG</a></p></section>', ['noindex' => true]);
}

// After sign-up or login: join the inviting manager's team, remember who referred a new member.
function apply_invite(int $userId, bool $new): void
{
    $code = $_SESSION['invite_code'] ?? '';
    unset($_SESSION['invite_code']);
    if ($code === '') {
        return;
    }
    $from = sql('SELECT * FROM users WHERE invite_code = ? AND blocked = 0', [$code])->fetch();
    if (!$from || (int) $from['id'] === $userId) {
        return;
    }
    if ($new) {
        sql('UPDATE users SET referred_by = ? WHERE id = ? AND referred_by IS NULL', [$from['id'], $userId]);
    }
    $kind = sql('SELECT kind FROM users WHERE id = ?', [$userId])->fetchColumn();
    if ($from['kind'] === 'manager' && $kind === 'worker') {
        // The team link waits until the profile is complete (team_members() skips empty profiles).
        if (!sql('SELECT 1 FROM team_links WHERE manager_id = ? AND worker_id = ?', [$from['id'], $userId])->fetchColumn()) {
            insert('team_links', ['manager_id' => $from['id'], 'worker_id' => $userId, 'created_at' => now()]);
            $_SESSION['team_joined'] = (int) $from['id'];
        }
    }
}

// Once a worker who came through a team link finishes the profile, the manager is told.
function team_joined_notice(array $u): void
{
    $managerId = (int) ($_SESSION['team_joined'] ?? 0);
    if (!$managerId || !profile_complete($u)) {
        return;
    }
    unset($_SESSION['team_joined']);
    sql('DELETE FROM team_links WHERE manager_id = ? AND worker_id = ?', [$managerId, $u['id']]);
    join_team($managerId, (int) $u['id']);
}

// The link and the email form, on the manager's account page.
function team_invite_box(array $u): string
{
    $link = url('/k/'.invite_code($u));
    return '<section class="panel invite-box"><h3>Pakvieskite savo darbuotojus</h3>'
        .'<p class="meta">Nusiųskite šią nuorodą savo komandai, pavyzdžiui, į darbuotojų pokalbių grupę. Prisijungę žmonės atsiras skiltyje „Mano komanda“, ir juos galėsite kviesti į pamainas vienu paspaudimu.</p>'
        .share_link($link, 'Prisijunkite prie mūsų komandos GANG platformoje')
        .'<details class="invite-mail"><summary>Pakviesti el. paštu</summary>'
        .'<form method="post" action="/komanda/kviesti" class="form">'.csrf_field()
        .'<label>El. pašto adresai <small>(po vieną eilutėje arba atskirti kableliais, iki 20)</small><textarea name="emails" rows="3" placeholder="vardas@pavyzdys.lt"></textarea></label>'
        .'<button class="btn small">Siųsti kvietimus</button></form></details></section>';
}

// A worker's referral link, on the worker's account page.
function referral_box(array $u): string
{
    $link = url('/k/'.invite_code($u));
    return '<section class="panel invite-box"><h3>Pakvieskite kolegas</h3>'
        .'<p class="meta">Kuo daugiau patikimų žmonių GANG, tuo greičiau darbdaviai randa pamainas, o jūs – darbo pasiūlymus. Pasidalykite savo nuoroda su kolegomis.</p>'
        .share_link($link, 'Prisijunk prie GANG: darbo pasiūlymai barams, restoranams ir kavinėms').'</section>';
}

function share_link(string $link, string $text): string
{
    return '<div class="share-link"><input readonly value="'.e($link).'" aria-label="Kvietimo nuoroda" onclick="this.select()">'
        .'<button type="button" class="btn small" data-copy="'.e($link).'" data-share="'.e($text).'">Kopijuoti</button></div>'
        .'<script>document.querySelectorAll("[data-copy]").forEach(function (b) { if (b.dataset.bound) return; b.dataset.bound = 1;'
        .' if (navigator.share && matchMedia("(pointer: coarse)").matches) b.textContent = "Dalintis";'
        .' b.addEventListener("click", function () { if (navigator.share && matchMedia("(pointer: coarse)").matches) { navigator.share({title: "GANG", text: b.dataset.share, url: b.dataset.copy}).catch(function () {}); return; }'
        .' (navigator.clipboard ? navigator.clipboard.writeText(b.dataset.copy) : Promise.reject()).then(function () { b.textContent = "Nukopijuota"; setTimeout(function () { b.textContent = "Kopijuoti"; }, 2000); }, function () { b.previousElementSibling.select(); }); }); });</script>';
}

function team_mail_action(): never
{
    $u = require_user('manager');
    check_csrf();
    $emails = array_unique(array_filter(array_map(fn ($x) => mb_strtolower(trim($x)), preg_split('/[\s,;]+/', input('emails')))));
    $valid = array_slice(array_values(array_filter($emails, fn ($x) => filter_var($x, FILTER_VALIDATE_EMAIL))), 0, 20);
    $today = (int) sql('SELECT COUNT(*) FROM team_mails WHERE manager_id = ? AND created_at > ?', [$u['id'], date('Y-m-d H:i:s', strtotime('-1 day'))])->fetchColumn();
    if (!$valid) {
        flash('Įrašykite bent vieną teisingą el. pašto adresą.');
        redirect('/mano#komanda');
    }
    $venues = implode(', ', sql('SELECT name FROM venues WHERE manager_id = ? ORDER BY id', [$u['id']])->fetchAll(PDO::FETCH_COLUMN));
    $link = url('/k/'.invite_code($u));
    $sent = 0;
    foreach ($valid as $email) {
        if ($today + $sent >= TEAM_MAIL_PER_DAY) {
            break;
        }
        // One invitation per address and manager.
        if (sql('SELECT 1 FROM team_mails WHERE manager_id = ? AND email = ?', [$u['id'], $email])->fetchColumn()) {
            continue;
        }
        insert('team_mails', ['manager_id' => $u['id'], 'email' => $email, 'created_at' => now()]);
        send_mail($email, first_name($u['name']).' kviečia jus į komandą'.($venues !== '' ? ': '.$venues : ''), [
            'kicker' => 'Asmeninis kvietimas',
            'title' => 'Prisijunkite prie komandos',
            'lead' => '**'.$u['name'].'**'.($venues !== '' ? " ($venues)" : '').' kviečia jus prisijungti prie savo komandos GANG platformoje.',
            'body' => 'Komandos nariai pirmieji gauna asmeninius kvietimus į pamainas, o kiekvienas darbas per GANG kaupia jūsų darbų istoriją ir įvertinimus. Profilio sukūrimas užtrunka kelias minutes.',
            'cta' => ['Priimti kvietimą', $link],
            'footnote' => 'Šį laišką gavote, nes darbdavys nurodė jūsų el. pašto adresą. Jei kvietimas jums neaktualus, laišką galite ištrinti: daugiau laiškų negausite.',
        ], $u['email']);
        $sent++;
    }
    flash($sent ? 'Išsiųsta kvietimų: '.$sent.'.' : 'Šiais adresais kvietimai jau buvo išsiųsti arba pasiektas dienos limitas.');
    redirect('/mano#komanda');
}
