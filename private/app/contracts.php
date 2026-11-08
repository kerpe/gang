<?php

declare(strict_types=1);

// Cancelling a GANG contract and marking a no-show. Both feed the reliability shown next to
// workers ("Patikimumas") and employers: a cancellation less than a day before the work (late)
// and a no-show count against the side that caused it; a cancellation agreed by both does not.

// Last day of the work (a permanent job: its first day).
function contract_end(array $c): string
{
    return $c['kind'] === 'nuolat' ? $c['start_date'] : ($c['end_date'] ?: $c['start_date']);
}

// Late = the work has started or starts tomorrow at the latest.
function contract_is_late(array $c): bool
{
    return $c['start_date'] <= tomorrow();
}

// The contract with both sides, or 404 when the user is neither of them.
function contract_for(int $id, array $u): array
{
    $c = sql(CONTRACT_SELECT.' WHERE c.id = ?', [$id])->fetch();
    if (!$c || ((int) $c['worker_id'] !== (int) $u['id'] && (int) $c['manager_id'] !== (int) $u['id'])) {
        not_found();
    }
    return $c;
}

// /kontraktas/{id}/atsaukti: either side cancels, with a reason the other side is told.
function contract_cancel_page(int $id): never
{
    $u = require_user();
    $c = contract_for($id, $u);
    $side = (int) $c['worker_id'] === (int) $u['id'] ? 'worker' : 'manager';
    if ($c['cancelled_at'] || contract_end($c) < today()) {
        flash($c['cancelled_at'] ? 'Šis darbas jau atšauktas.' : 'Pasibaigusio darbo atšaukti nebegalima.');
        redirect('/mano');
    }
    $reasons = CANCEL_REASONS[$side];
    $late = contract_is_late($c);
    $errors = [];
    $reason = $note = '';
    if (is_post()) {
        check_csrf();
        $reason = input('reason');
        $note = mb_substr(input('note'), 0, 500);
        if (!isset($reasons[$reason])) {
            $errors['reason'] = 'Pasirinkite atšaukimo priežastį.';
        }
        if ($reason === 'kita' && mb_strlen($note) < 5) {
            $errors['note'] = 'Trumpai nurodykite priežastį.';
        }
        if (!$errors) {
            cancel_contract($c, $side, $reason, $note);
            redirect('/mano');
        }
    }
    $worker = $side === 'worker';
    $other = $worker ? $c['venue_name'] : first_name($c['worker_name']);
    $warning = $late && $worker
        ? 'Iki darbo pradžios liko mažiau nei para. Toks atšaukimas, kaip ir neatvykimas, mažina jūsų patikimumo rodiklį, kurį mato darbdaviai. Jei susitarėte su darbdaviu, pasirinkite atitinkamą priežastį.'
        : ($late ? 'Iki darbo pradžios liko mažiau nei para. Toks atšaukimas mažina darbovietės patikimumo rodiklį, kurį mato darbuotojai. Jei susitarėte su darbuotoju, pasirinkite atitinkamą priežastį.'
        : ($worker ? 'Atšaukus likus daugiau nei parai iki darbo pradžios, jūsų patikimumo rodiklis nemažėja.' : 'Atšaukus likus daugiau nei parai iki darbo pradžios, darbovietės patikimumo rodiklis nemažėja.'));
    $after = $worker
        ? 'Darbdavys gaus pranešimą el. paštu, o paieška vėl taps aktyvi, kad jis galėtų rasti kitą darbuotoją. Jei norite, parašykite jam ir GANG žinutėse.'
        : 'Darbuotojas gaus pranešimą el. paštu. Jei norite, parašykite jam ir GANG žinutėse.';
    page('Atšaukti darbą', '<section class="wrap narrow"><p class="eyebrow">GANG kontraktas</p><h1>Atšaukti darbą</h1>'
        .'<p class="meta">'.e(ROLES[$c['role']] ?? '').' · '.e($other).' · '.e(period($c)).'</p>'
        .'<p class="notice '.($late ? 'tip' : 'ok').'">'.e($warning).'</p>'
        .'<form method="post" class="form" novalidate>'.csrf_field()
        .'<fieldset><legend>Priežastis</legend>'.radios('reason', $reasons, $reason).err($errors, 'reason').'</fieldset>'
        .'<label>Komentaras <small>(neprivaloma, bus perduotas '.($worker ? 'darbdaviui' : 'darbuotojui').')</small><textarea name="note" rows="3" maxlength="500"'.($worker ? '' : ' placeholder="Pvz.: renginys atšauktas dėl techninių kliūčių"').'>'.e($note).'</textarea>'.err($errors, 'note').'</label>'
        .'<p class="fine">'.e($after).'</p>'
        .'<div class="form-actions"><button class="btn">Atšaukti darbą</button><a class="btn ghost" href="/mano">Grįžti</a></div></form></section>', ['noindex' => true]);
}

function cancel_contract(array $c, string $side, string $reason, string $note): void
{
    $late = contract_is_late($c) && $reason !== 'susitarta' ? 1 : 0;
    sql('UPDATE contracts SET cancelled_at = ?, cancelled_by = ?, cancel_reason = ?, cancel_note = ?, late = ? WHERE id = ? AND cancelled_at IS NULL',
        [now(), $side, $reason, $note, $late, $c['id']]);
    sql("UPDATE responses SET status = 'cancelled' WHERE request_id = ? AND worker_id = ? AND status = 'accepted'", [$c['request_id'], $c['worker_id']]);
    $role = ROLES[$c['role']] ?? '';
    $why = CANCEL_REASONS[$side][$reason] ?? '';
    $facts = array_filter(['Pareigos' => $role, 'Kada' => period($c), 'Priežastis' => $why]);
    $noteBlock = $note !== '' ? ['label' => 'Komentaras', 'text' => $note] : null;
    if ($side === 'worker') {
        $reopened = reopen_request((int) $c['request_id'], (int) $c['worker_id']);
        send_mail(sql('SELECT email FROM users WHERE id = ?', [$c['manager_id']])->fetchColumn(), 'Darbuotojas atšaukė darbą: '.first_name($c['worker_name']).' – '.$c['venue_name'], array_filter([
            'kicker' => 'GANG kontraktas atšauktas',
            'title' => 'Darbuotojas neatvyks',
            'lead' => '**'.first_name($c['worker_name']).'** atšaukė darbą jūsų darbovietėje **'.$c['venue_name'].'**.',
            'facts' => $facts,
            'note' => $noteBlock,
            'body' => $reopened
                ? 'Paieška vėl aktyvi: apie laisvą vietą informavome kitus tinkamus darbuotojus.'
                : 'Jei reikalingas pakaitinis darbuotojas, paskelbkite naują paiešką. Forma bus užpildyta pagal šį darbą.',
            'cta' => $reopened ? ['Peržiūrėti paiešką', url('/darbas/'.$c['request_id'])] : ['Rasti pakaitinį darbuotoją', url('/poreikis/naujas?pagal='.(int) $c['request_id'])],
        ]));
        flash('Darbas atšauktas. Darbdavys informuotas el. paštu.');
        return;
    }
    send_mail(sql('SELECT email FROM users WHERE id = ?', [$c['worker_id']])->fetchColumn(), 'Darbdavys atšaukė darbą: '.$role.' – '.$c['venue_name'], array_filter([
        'kicker' => 'GANG kontraktas atšauktas',
        'title' => 'Darbas atšauktas',
        'lead' => "**{$c['venue_name']}** atšaukė jūsų darbą.",
        'facts' => $facts,
        'note' => $noteBlock,
        'body' => 'Kviečiame peržiūrėti kitus aktualius darbo pasiūlymus.',
        'cta' => ['Darbo pasiūlymai', url('/darbai')],
    ]));
    flash('Darbas atšauktas. Darbuotojas informuotas el. paštu.');
}

// After a worker cancels: a filled request opens again and goes to the other matching workers.
function reopen_request(int $requestId, int $cancelledWorker): bool
{
    $r = find_request($requestId);
    if (!$r || !in_array($r['status'], ['open', 'filled'], true)) {
        return false;
    }
    $last = $r['kind'] === 'nuolat' ? $r['start_date'] : ($r['end_date'] ?: $r['start_date']);
    if ($last < today()) {
        return false;
    }
    $wasFilled = $r['status'] === 'filled';
    sql("UPDATE requests SET status = 'open', filled_at = NULL WHERE id = ?", [$requestId]);
    if ($wasFilled) {
        // A new request date window: the posting date restarts the "nuolat" visibility period.
        if ($r['kind'] === 'nuolat') {
            sql('UPDATE requests SET created_at = ? WHERE id = ?', [now(), $requestId]);
        }
        $skip = array_map('intval', sql('SELECT worker_id FROM responses WHERE request_id = ?', [$requestId])->fetchAll(PDO::FETCH_COLUMN));
        notify_workers(find_request($requestId), [...$skip, $cancelledWorker]);
    }
    return true;
}

// The employer marks that the worker did not come (or takes the mark back), up to NO_SHOW_DAYS after the work.
function no_show_action(int $id): never
{
    $u = require_user('manager');
    check_csrf();
    $c = contract_for($id, $u);
    if ($c['cancelled_at'] || $c['start_date'] > today() || contract_end($c) < date('Y-m-d', strtotime('-'.NO_SHOW_DAYS.' days'))) {
        redirect('/mano');
    }
    if (input('undo') === '1') {
        sql('UPDATE contracts SET no_show_at = NULL WHERE id = ?', [$id]);
        flash('Žyma pašalinta: darbas bus įskaitytas kaip įvykęs.');
        redirect('/mano');
    }
    if (!$c['no_show_at']) {
        sql('UPDATE contracts SET no_show_at = ? WHERE id = ?', [now(), $id]);
        $worker = sql('SELECT email FROM users WHERE id = ?', [$c['worker_id']])->fetchColumn();
        send_mail($worker, 'Pažymėta, kad neatvykote: '.$c['venue_name'], [
            'kicker' => 'GANG kontraktas',
            'title' => 'Pažymėta, kad neatvykote',
            'lead' => "**{$c['venue_name']}** pažymėjo, kad neatvykote į sutartą darbą (".period($c).').',
            'body' => [
                'Neatvykimas mažina jūsų patikimumo rodiklį, kurį mato darbdaviai. Jei negalite atvykti, visada atšaukite darbą savo paskyroje kuo anksčiau.',
                'Jei manote, kad tai klaida, parašykite darbdaviui GANG žinutėse ([Mano profilis]('.url('/mano').')). Darbdavys žymą gali pašalinti per '.NO_SHOW_DAYS.' dienas. Taip pat galite parašyti mums [hello@gang.lt](mailto:hello@gang.lt).',
            ],
            'cta' => ['Mano profilis', url('/mano')],
        ]);
    }
    flash('Pažymėta, kad darbuotojas neatvyko. Darbuotojas informuotas el. paštu.');
    redirect('/mano');
}
