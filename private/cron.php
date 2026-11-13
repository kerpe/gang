<?php

// Hourly job (hPanel -> Advanced -> Cron Jobs): php /home/<user>/domains/gang.lt/private/cron.php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit;
}
foreach (['bootstrap', 'data', 'views', 'auth', 'admin', 'account', 'shifts', 'contracts', 'messages'] as $f) {
    require __DIR__."/app/$f.php";
}
// A failed run is written to errors.log (admin → Nustatymai); the cron's own output nobody reads.
set_exception_handler(function (Throwable $t): void {
    gang_log('CRON '.get_class($t).' '.$t->getMessage().' @ '.basename($t->getFile()).':'.$t->getLine());
    exit(1);
});

// Close requests whose time has passed.
[$where, $params] = open_where('requests');
sql("UPDATE requests SET status = 'expired' WHERE status = 'open' AND NOT ($where)", $params);

// Ask both sides for a review once the work is over (permanent jobs: after 30 days).
$due = sql("SELECT c.id, r.role, v.name AS venue_name, w.email AS worker_email, w.name AS worker_name, m.email AS manager_email, m.name AS manager_name
    FROM contracts c JOIN requests r ON r.id = c.request_id JOIN venues v ON v.id = c.venue_id JOIN users w ON w.id = c.worker_id JOIN users m ON m.id = v.manager_id
    WHERE c.review_mail_at IS NULL AND c.cancelled_at IS NULL AND ((r.kind <> 'nuolat' AND ".CONTRACT_TO." < ?) OR (r.kind = 'nuolat' AND c.created_at < ?))
    LIMIT 200", [today(), date('Y-m-d H:i:s', strtotime('-30 days'))])->fetchAll();
foreach ($due as $c) {
    $link = url('/vertinti/'.$c['id']);
    send_mail($c['worker_email'], 'Įvertinkite darbdavį: '.$c['venue_name'], [
        'kicker' => 'Įvertinimas',
        'title' => 'Kaip sekėsi darbas?',
        'lead' => "kviečiame įvertinti, kaip sekėsi dirbti pas **{$c['venue_name']}**. Tai užtruks mažiau nei minutę ir padės kitiems darbuotojams priimti pagrįstus sprendimus.",
        'cta' => ['Įvertinti darbdavį', $link],
        'footnote' => 'Įvertinimas tampa matomas, kai įvertina abi pusės arba praėjus '.REVIEW_HIDE_DAYS.' dienoms. Autorius nerodomas.',
    ]);
    send_mail($c['manager_email'], 'Įvertinkite darbuotoją: '.first_name($c['worker_name']), [
        'kicker' => 'Įvertinimas',
        'title' => 'Kaip sekėsi darbas?',
        'lead' => 'kviečiame įvertinti darbuotoją: **'.first_name($c['worker_name']).'**, '.mb_strtolower(ROLES[$c['role']] ?? '').'. Jūsų įvertinimas padės kitiems vadovams rasti patikimus darbuotojus.',
        'cta' => ['Įvertinti darbuotoją', $link],
        'footnote' => 'Įvertinimas tampa matomas, kai įvertina abi pusės arba praėjus '.REVIEW_HIDE_DAYS.' dienoms. Autorius nerodomas.',
    ]);
    sql('UPDATE contracts SET review_mail_at = ? WHERE id = ?', [now(), $c['id']]);
}

// "Užbaikite registraciją": a day and a week after an unfinished sign-up (see account.php).
send_signup_reminders();

// Arrival confirmation for tomorrow's shifts (10:00 reminder, 18:00 heads-up to the employer).
run_shift_checks();

// Bulk email the pages did not finish (normally empty).
process_mail_queue(240);

sql('DELETE FROM login_codes WHERE expires_at < ?', [date('Y-m-d H:i:s', strtotime('-1 day'))]);
