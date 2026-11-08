<?php

declare(strict_types=1);

// Personal invitation links /k/{code}: a member brings a friend to GANG (users.referred_by).

function invite_landing_page(string $code): never
{
    $from = invite_owner($code);
    if (!$from) {
        not_found();
    }
    start_session();
    $u = current_user();
    if (!$u) {
        // Remembered through sign-up and login (see apply_invite()).
        $_SESSION['invite_code'] = $code;
    }
    $who = first_name($from['name']);
    $title = $who.' kviečia jus į GANG';
    $lead = 'GANG padeda restoranams, kavinėms, barams ir klubams rasti patikimus darbuotojus, o hospitality specialistams – papildomų pamainų ir darbo pasiūlymų. '.e($who).' jau yra GANG narys.';
    $action = $u === null
        ? '<p><a class="btn" href="/prisijungti?kaip=darbuotojas">Ieškau darbo</a> <a class="btn ghost" href="/prisijungti?kaip=vadovas">Ieškau darbuotojų</a></p>'
        : ((int) $u['id'] === (int) $from['id'] ? '<p class="notice ok">Taip jūsų kvietimą matys draugai.</p>' : '<p><a class="btn" href="/mano">Mano profilis</a></p>');
    page($title, '<section class="wrap narrow invite-landing"><p class="eyebrow">Asmeninis kvietimas</p><h1>'.e($title).'</h1><p class="lead">'.$lead.'</p>'.$action
        .'<p class="meta"><a href="/kaip-veikia">Kaip veikia GANG</a></p></section>', ['noindex' => true]);
}

// After sign-up: remember who referred the new member.
function apply_invite(int $userId, bool $new): void
{
    $code = $_SESSION['invite_code'] ?? '';
    unset($_SESSION['invite_code']);
    if ($code === '' || !$new) {
        return;
    }
    $from = invite_owner($code);
    if ($from && (int) $from['id'] !== $userId) {
        sql('UPDATE users SET referred_by = ? WHERE id = ? AND referred_by IS NULL', [$from['id'], $userId]);
    }
}

// The invite card, the same on both sides (Vilius 2026-11-08): only the link and "Kopijuoti", no emails from GANG.
function invite_box(array $u): string
{
    $link = url('/k/'.invite_code($u));
    return '<section class="panel invite-box" id="kviesti"><h3>Pakvieskite draugus</h3>'
        .'<p class="meta">Pakvieskite savo draugus prisijungti prie GANG:</p>'
        .share_link($link, 'Prisijunkite prie GANG: darbo pasiūlymai restoranams, kavinėms, barams ir klubams').'</section>';
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
