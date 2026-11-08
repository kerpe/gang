<?php

declare(strict_types=1);

function review_page(int $contractId): never
{
    $u = require_user();
    $c = sql('SELECT c.*, r.role, r.kind, '.CONTRACT_DATES.', v.name AS venue_name, v.manager_id, w.name AS worker_name
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
    if ($c['cancelled_at']) {
        flash('Atšaukto darbo įvertinti negalima.');
        redirect('/mano');
    }
    if ($c['start_date'] > today()) {
        flash('Įvertinti galėsite pradėjus darbą.');
        redirect('/mano');
    }
    if (sql('SELECT 1 FROM reviews WHERE contract_id = ? AND author = ?', [$contractId, $author])->fetchColumn()) {
        flash('Šį darbą jau įvertinote.');
        redirect('/mano');
    }
    $errors = [];
    $criteria = REVIEW_CRITERIA[$author];
    $scores = array_fill_keys(array_keys($criteria), 0);
    $text = '';
    if (is_post()) {
        check_csrf();
        foreach ($criteria as $key => $_) {
            $scores[$key] = (int) input($key);
            if ($scores[$key] < 1 || $scores[$key] > 5) {
                $errors[$key] = 'Įvertinkite nuo 1 iki 5.';
            }
        }
        $text = input('text');
        if (mb_strlen($text) > 1000) {
            $errors['text'] = 'Komentaras gali būti ne ilgesnis nei 1000 simbolių.';
        }
        if (!$errors && min($scores) <= 2 && mb_strlen($text) < 10) {
            $errors['text'] = 'Pateikdami žemą įvertinimą, trumpai nurodykite jo priežastį.';
        }
        if (!$errors) {
            // The overall rating stays one number (average of the criteria) for lists and older reviews.
            $rating = (int) round(array_sum($scores) / count($scores));
            try {
                insert('reviews', ['contract_id' => $contractId, 'author' => $author, 'rating' => $rating, 'text' => $text, 'created_at' => now()] + $scores);
            } catch (PDOException) {
                // Sent twice at once (two tabs): the unique index kept the first one.
                flash('Šį darbą jau įvertinote.');
                redirect('/mano');
            }
            flash('Dėkojame. Įvertinimas bus paskelbtas, kai įvertins ir kita šalis arba po '.REVIEW_HIDE_DAYS.' dienų.');
            redirect('/mano');
        }
    }
    $who = $author === 'manager' ? first_name($c['worker_name']) : $c['venue_name'];
    $question = $author === 'manager' ? 'Įvertinkite darbuotoją: '.e($who) : 'Įvertinkite darbdavį: '.e($who);
    $hint = $author === 'manager' ? 'ar atvyko laiku, kaip atliko darbą, ar kviestumėte dar kartą' : 'ar sąlygos atitiko sutartas, ar atlygis sumokėtas laiku, ar rekomenduotumėte šį darbdavį kitiems';
    $starsInput = '';
    foreach ($criteria as $key => $label) {
        $starsInput .= '<div class="criterion"><span class="criterion-name">'.e($label).'</span><div class="star-input" role="radiogroup" aria-label="'.e($label).'">';
        for ($i = 5; $i >= 1; $i--) {
            $starsInput .= '<input type="radio" name="'.$key.'" id="'.$key.'s'.$i.'" value="'.$i.'"'.($scores[$key] === $i ? ' checked' : '').'><label for="'.$key.'s'.$i.'" title="'.$i.'">'.knives().'</label>';
        }
        $starsInput .= '</div>'.err($errors, $key).'</div>';
    }
    page('Įvertinimas', '<section class="wrap narrow"><h1>'.$question.'</h1>'
        .'<p class="meta">'.e(ROLES[$c['role']] ?? '').' · '.e(period($c)).'</p>'
        .'<form method="post" class="form" novalidate>'.csrf_field()
        .'<fieldset class="criteria-input"><legend>Įvertinimas</legend><p class="hint">Įvertinkite kiekvieną kriterijų nuo 1 iki 5.</p>'.$starsInput.'</fieldset>'
        .'<label>Komentaras <small>(neprivaloma; pavyzdžiui, '.e($hint).')</small><textarea name="text" rows="4" maxlength="1000">'.e($text).'</textarea>'.err($errors, 'text').'</label>'
        .'<p class="fine">Jūsų vardas prie įvertinimo nerodomas. Įvertinimas bus paskelbtas, kai įvertins ir kita šalis arba po '.REVIEW_HIDE_DAYS.' dienų. Vertinkite objektyviai ir nenurodykite asmeninės informacijos.</p>'
        .'<button class="btn">Siųsti įvertinimą</button></form></section>', ['noindex' => true]);
}

function reviews_html(array $reviews, callable $who): string
{
    if (!$reviews) {
        return '<p class="empty">Įvertinimų dar nėra.</p>';
    }
    $out = '<div class="reviews">';
    foreach ($reviews as $r) {
        $parts = [];
        foreach (REVIEW_CRITERIA[$r['author']] ?? [] as $key => $label) {
            if (isset($r[$key]) && $r[$key] !== null) {
                $parts[] = e($label).' '.(int) $r[$key];
            }
        }
        $out .= '<div class="review"><p><span class="stars">'.str_repeat(knives(), (int) $r['rating']).'<span class="dim">'.str_repeat(knives(), 5 - (int) $r['rating']).'</span></span> · <span class="meta">'.e($who($r)).', '.e(date_lt($r['created_at'])).' '.date('Y', strtotime($r['created_at'])).'</span></p>'
            .($parts ? '<p class="meta review-criteria">'.implode(' · ', $parts).'</p>' : '')
            .($r['text'] ? '<p>'.nl2br(e($r['text'])).'</p>' : '').'</div>';
    }
    return $out.'</div>';
}

// Average per criterion ("Punktualumas 4,8"), above the list of reviews.
function criteria_html(string $author, string $column, int $id): string
{
    [$avg, $n] = criteria_averages($author, $column, $id);
    if (!$n) {
        return '';
    }
    $out = '<ul class="criteria">';
    foreach (REVIEW_CRITERIA[$author] as $key => $label) {
        if ($avg[$key] !== null) {
            $out .= '<li><span>'.e($label).'</span><span class="bar" aria-hidden="true"><i style="width:'.round($avg[$key] / 5 * 100).'%"></i></span><b>'.number_format($avg[$key], 1, ',', '').'</b></li>';
        }
    }
    return $out.'</ul>';
}
