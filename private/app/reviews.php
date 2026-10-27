<?php

declare(strict_types=1);

function review_page(int $contractId): never
{
    $u = require_user();
    $c = sql('SELECT c.*, r.role, r.kind, r.start_date, r.end_date, v.name AS venue_name, v.manager_id, w.name AS worker_name
        FROM contracts c JOIN requests r ON r.id = c.request_id JOIN venues v ON v.id = c.venue_id JOIN users w ON w.id = c.worker_id WHERE c.id = ?', [$contractId])->fetch();
    if (!$c) {
        not_found();
    }
    $author = match (true) {
        (int) $c['worker_id'] === (int) $u['id'] => 'worker',
        (int) $c['manager_id'] === (int) $u['id'] => 'manager',
        default => null,
    };
    if ($author === null) {
        not_found();
    }
    if ($c['start_date'] > today()) {
        flash('Įvertinti galėsite, kai darbas prasidės.');
        redirect('/mano');
    }
    if (sql('SELECT 1 FROM reviews WHERE contract_id = ? AND author = ?', [$contractId, $author])->fetchColumn()) {
        flash('Jau įvertinote.');
        redirect('/mano');
    }
    $errors = [];
    $rating = 0;
    $text = '';
    if (is_post()) {
        check_csrf();
        $rating = (int) input('rating');
        $text = input('text');
        if ($rating < 1 || $rating > 5) {
            $errors['rating'] = 'Pasirinkite nuo 1 iki 5 žvaigždučių.';
        }
        if (mb_strlen($text) > 1000) {
            $errors['text'] = 'Iki 1000 simbolių.';
        }
        if ($rating > 0 && $rating <= 2 && mb_strlen($text) < 10) {
            $errors['text'] = 'Prie žemo įvertinimo trumpai parašykite, kas nutiko.';
        }
        if (!$errors) {
            insert('reviews', ['contract_id' => $contractId, 'author' => $author, 'rating' => $rating, 'text' => $text, 'created_at' => now()]);
            flash('Ačiū! Įvertinimas bus matomas, kai įvertins ir kita pusė arba po '.REVIEW_HIDE_DAYS.' dienų.');
            redirect('/mano');
        }
    }
    $who = $author === 'manager' ? first_name($c['worker_name']) : $c['venue_name'];
    $question = $author === 'manager' ? 'Kaip sekėsi dirbti su '.e($who).'?' : 'Kaip sekėsi dirbti '.e($who).'?';
    $hint = $author === 'manager' ? 'Atvyko laiku? Dirbo gerai? Kviestumėte vėl?' : 'Ar sąlygos atitiko sutartas? Ar laiku sumokėjo? Ar rekomenduotumėte kitiems?';
    $starsInput = '<div class="star-input">';
    for ($i = 5; $i >= 1; $i--) {
        $starsInput .= '<input type="radio" name="rating" id="s'.$i.'" value="'.$i.'"'.($rating === $i ? ' checked' : '').'><label for="s'.$i.'" title="'.$i.'">★</label>';
    }
    $starsInput .= '</div>';
    page('Įvertinimas', '<section class="wrap narrow"><h1>'.$question.'</h1>'
        .'<p class="meta">'.e(ROLES[$c['role']] ?? '').' · '.e(period($c)).'</p>'
        .'<form method="post" class="form" novalidate>'.csrf_field()
        .'<fieldset><legend>Įvertinimas</legend>'.$starsInput.err($errors, 'rating').'</fieldset>'
        .'<label>Komentaras <small>(nebūtina; '.e($hint).')</small><textarea name="text" rows="4" maxlength="1000">'.e($text).'</textarea>'.err($errors, 'text').'</label>'
        .'<p class="fine">Jūsų vardas prie įvertinimo nerodomas. Įvertinimas bus matomas, kai įvertins ir kita pusė arba po '.REVIEW_HIDE_DAYS.' dienų. Rašykite sąžiningai ir be asmeninės informacijos.</p>'
        .'<button class="btn">Siųsti įvertinimą</button></form></section>', ['noindex' => true]);
}

function reviews_html(array $reviews, callable $who): string
{
    if (!$reviews) {
        return '<p class="empty">Įvertinimų dar nėra.</p>';
    }
    $out = '<div class="reviews">';
    foreach ($reviews as $r) {
        $out .= '<div class="review"><p><span class="stars">'.str_repeat('★', (int) $r['rating']).'<span class="dim">'.str_repeat('★', 5 - (int) $r['rating']).'</span></span> · <span class="meta">'.e($who($r)).', '.e(date_lt($r['created_at'])).' '.date('Y', strtotime($r['created_at'])).'</span></p>'
            .($r['text'] ? '<p>'.nl2br(e($r['text'])).'</p>' : '').'</div>';
    }
    return $out.'</div>';
}
