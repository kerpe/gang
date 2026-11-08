<?php

declare(strict_types=1);

// Personal invitation links /k/{code}. A manager's link brings their own staff into "Mano komanda";
// a worker's link brings a colleague to GANG (users.referred_by).

function invite_landing_page(string $code): never
{
    $from = invite_owner($code);
    if (!$from) {
        not_found();
    }
    start_session();
    $u = current_user();
    // Without the team feature a manager's link is a plain invitation to GANG, like a worker's.
    $manager = TEAM_ENABLED && $from['kind'] === 'manager';
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
        $lead = 'GANG padeda restoranams, kavinėms, barams ir klubams rasti patikimus darbuotojus, o hospitality specialistams – papildomų pamainų ir darbo pasiūlymų. Jūsų kolega '.e($who).' jau yra GANG narys.';
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
    $from = invite_owner($code);
    if (!$from || (int) $from['id'] === $userId) {
        return;
    }
    if ($new) {
        sql('UPDATE users SET referred_by = ? WHERE id = ? AND referred_by IS NULL', [$from['id'], $userId]);
    }
    $kind = sql('SELECT kind FROM users WHERE id = ?', [$userId])->fetchColumn();
    if (TEAM_ENABLED && $from['kind'] === 'manager' && $kind === 'worker') {
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

// The invite card, the same on both sides (Vilius 2026-11-08): only the link and "Kopijuoti", no emails from GANG.
// Both links bring friends to GANG (a manager's also into the team when TEAM_ENABLED).
function invite_box(array $u): string
{
    $link = url('/k/'.invite_code($u));
    $share = TEAM_ENABLED && $u['kind'] === 'manager'
        ? 'Prisijunkite prie mūsų komandos GANG platformoje'
        : 'Prisijunkite prie GANG: darbo pasiūlymai restoranams, kavinėms, barams ir klubams';
    return '<section class="panel invite-box" id="kviesti"><h3>Pakvieskite draugus</h3>'
        .'<p class="meta">Pakvieskite savo draugus prisijungti prie GANG:</p>'
        .share_link($link, $share).'</section>';
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
