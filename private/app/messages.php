<?php

declare(strict_types=1);

// Messages between the employer and the worker of a GANG contract ("Pokalbis").
// The chat opens once the employer confirms the worker and stays with the contract.
// chat_reads keeps, per contract and member, the last message they saw (read receipts,
// unread badges) and when they last had the chat open or got an email about it.

const MESSAGE_MAX = 2000;
const MESSAGES_PER_HOUR = 60;
// No email while the other side has the chat open (it polls every few seconds).
const CHAT_ACTIVE_SECONDS = 90;
// Without a visit in between, another email about new messages after this many hours.
const CHAT_MAIL_HOURS = 12;

// Lithuanian vocative of a first name for a friendly greeting: Vilius → Viliau, Jonas → Jonai,
// Kęstutis → Kęstuti, Gytys → Gyti, Matėjas → Matėjau, Eglė → Egle. Other endings stay as they are.
function lt_vocative(string $name): string
{
    $name = trim((string) (preg_split('/[\s,]+/u', trim($name))[0] ?? ''));
    if (mb_strlen($name) < 3) {
        return $name;
    }
    $rules = ['ius' => 'iau', 'jas' => 'jau', 'ias' => 'iau', 'as' => 'ai', 'is' => 'i', 'ys' => 'i', 'us' => 'au', 'ė' => 'e'];
    $lower = mb_strtolower($name);
    foreach ($rules as $from => $to) {
        if (str_ends_with($lower, $from)) {
            $stem = mb_substr($name, 0, mb_strlen($name) - mb_strlen($from));
            // Keep NAMES written in capitals in capitals.
            $upper = $name === mb_strtoupper($name);
            return $stem.($upper ? mb_strtoupper($to) : $to);
        }
    }
    return $name;
}

// The contract (with both sides) or null when the user is neither of them.
function chat_contract(int $id, array $u): ?array
{
    $c = sql(CONTRACT_SELECT.' WHERE c.id = ?', [$id])->fetch();
    if (!$c || ((int) $c['worker_id'] !== (int) $u['id'] && (int) $c['manager_id'] !== (int) $u['id'])) {
        return null;
    }
    return $c;
}

function chat_side(array $c, array $u): string
{
    return (int) $c['worker_id'] === (int) $u['id'] ? 'worker' : 'manager';
}

function chat_other_id(array $c, string $side): int
{
    return (int) ($side === 'worker' ? $c['manager_id'] : $c['worker_id']);
}

function chat_read_row(int $contractId, int $userId): array|false
{
    return sql('SELECT * FROM chat_reads WHERE contract_id = ? AND user_id = ?', [$contractId, $userId])->fetch();
}

function chat_mark(int $contractId, int $userId, array $set): void
{
    if (chat_read_row($contractId, $userId)) {
        $cols = implode(', ', array_map(fn ($k) => "$k = ?", array_keys($set)));
        sql("UPDATE chat_reads SET $cols WHERE contract_id = ? AND user_id = ?", [...array_values($set), $contractId, $userId]);
        return;
    }
    insert('chat_reads', ['contract_id' => $contractId, 'user_id' => $userId, 'last_id' => 0] + $set);
}

// The user opened the chat: everything up to the newest message is seen.
function chat_seen(int $contractId, int $userId): void
{
    $last = (int) sql('SELECT MAX(id) FROM messages WHERE contract_id = ?', [$contractId])->fetchColumn();
    chat_mark($contractId, $userId, ['last_id' => $last, 'seen_at' => now()]);
}

function chat_messages(int $contractId): array
{
    return sql('SELECT * FROM messages WHERE contract_id = ? ORDER BY id', [$contractId])->fetchAll();
}

// Messages from the other side the user has not seen yet.
function chat_unread(int $contractId, int $userId): int
{
    $last = (int) (chat_read_row($contractId, $userId)['last_id'] ?? 0);
    return (int) sql('SELECT COUNT(*) FROM messages WHERE contract_id = ? AND sender_id <> ? AND id > ?', [$contractId, $userId, $last])->fetchColumn();
}

// Unread messages across all of the user's contracts (the "Mano GANG" link in the menu).
function unread_messages_total(int $userId): int
{
    return (int) sql('SELECT COUNT(*) FROM messages m JOIN contracts c ON c.id = m.contract_id JOIN venues v ON v.id = c.venue_id
        LEFT JOIN chat_reads cr ON cr.contract_id = m.contract_id AND cr.user_id = ?
        WHERE (c.worker_id = ? OR v.manager_id = ?) AND m.sender_id <> ? AND m.id > COALESCE(cr.last_id, 0)', [$userId, $userId, $userId, $userId])->fetchColumn();
}

// A ready first message, offered until the user has written anything in this chat.
function chat_suggestion(array $c, string $side): string
{
    if ($side === 'manager') {
        $name = lt_vocative($c['worker_name']);
        // "Malonu susipažinti" only when the two have not worked together or written to each other before.
        $before = (bool) sql('SELECT 1 FROM contracts c2 WHERE c2.worker_id = ? AND c2.venue_id IN (SELECT id FROM venues WHERE manager_id = ?) AND c2.id <> ?
            AND (c2.created_at < ? OR EXISTS (SELECT 1 FROM messages m WHERE m.contract_id = c2.id)) LIMIT 1', [$c['worker_id'], $c['manager_id'], $c['id'], $c['created_at']])->fetchColumn();
        return $before
            ? "Labas, $name! Smagu vėl dirbti kartu. Drąsiai kreipkis, jei turėsi klausimų dėl darbo."
            : "Labas, $name! Malonu susipažinti. Drąsiai kreipkis, jei turėsi klausimų dėl darbo.";
    }
    $name = lt_vocative($c['manager_name']);
    return "Laba diena, $name! Ačiū, kad patvirtinote mano kandidatūrą. Jei prieš darbą reikėtų ką nors žinoti ar pasiruošti, parašykite.";
}

function chat_day(string $date): string
{
    return match (substr($date, 0, 10)) {
        today() => 'Šiandien',
        date('Y-m-d', strtotime('-1 day')) => 'Vakar',
        default => date_lt(substr($date, 0, 10)),
    };
}

// The message list. The newest own message the other side has already seen is marked "Perskaityta".
function chat_list_html(array $c, int $userId, array $messages): string
{
    $otherId = chat_other_id($c, chat_side($c, ['id' => $userId]));
    $otherLast = (int) (chat_read_row((int) $c['id'], $otherId)['last_id'] ?? 0);
    $readMine = 0;
    foreach ($messages as $m) {
        if ((int) $m['sender_id'] === $userId && (int) $m['id'] <= $otherLast) {
            $readMine = (int) $m['id'];
        }
    }
    $lastMine = 0;
    foreach ($messages as $m) {
        if ((int) $m['sender_id'] === $userId) {
            $lastMine = (int) $m['id'];
        }
    }
    $html = '';
    $day = '';
    foreach ($messages as $m) {
        $d = substr($m['created_at'], 0, 10);
        if ($d !== $day) {
            $html .= '<p class="chat-day"><span>'.e(chat_day($m['created_at'])).'</span></p>';
            $day = $d;
        }
        $mine = (int) $m['sender_id'] === $userId;
        $status = '';
        if ($mine && (int) $m['id'] === $lastMine) {
            $status = ' · '.((int) $m['id'] <= $readMine ? 'Perskaityta' : 'Išsiųsta');
        }
        $html .= '<div class="msg'.($mine ? ' mine' : '').'"><p>'.nl2br(e($m['body'])).'</p>'
            .'<span class="msg-time">'.e(substr($m['created_at'], 11, 5)).$status.'</span></div>';
    }
    return $html;
}

// Changes when a message arrives or the other side reads one, so polling sends the list only then.
function chat_state(array $c, int $userId, array $messages): string
{
    $last = $messages ? (int) end($messages)['id'] : 0;
    $other = (int) (chat_read_row((int) $c['id'], chat_other_id($c, chat_side($c, ['id' => $userId])))['last_id'] ?? 0);
    return $last.'-'.min($other, $last).'-'.substr(today(), 8);
}

// The chat window under a confirmed worker (employer) or a confirmed job (worker).
function chat_box(array $c, array $u): string
{
    $side = chat_side($c, $u);
    $id = (int) $c['id'];
    $messages = chat_messages($id);
    chat_seen($id, (int) $u['id']);
    $other = $side === 'worker' ? $c['manager_name'] : $c['worker_name'];
    $sub = $side === 'worker' ? $c['venue_name'] : (ROLES[$c['role']] ?? '');
    $wrote = (bool) array_filter($messages, fn ($m) => (int) $m['sender_id'] === (int) $u['id']);
    $suggest = $wrote ? '' : chat_suggestion($c, $side);
    $closed = (bool) $c['cancelled_at'];
    $empty = '<p class="chat-empty">'.($side === 'worker'
        ? 'Čia galite susirašinėti su darbdaviu: aptarti darbo laiką, aprangą, atvykimą ar kitus klausimus.'
        : 'Čia galite susirašinėti su darbuotoju: aptarti darbo laiką, aprangą, atvykimą ar kitus klausimus.').'</p>';
    $form = $closed
        ? '<p class="chat-closed meta">Darbas atšauktas, todėl naujų žinučių siųsti nebegalima.</p>'
        : ($suggest !== '' ? '<button type="button" class="chat-suggest" data-text="'.e($suggest).'"><span>Siūloma žinutė</span>'.e($suggest).'</button>' : '')
            .'<form class="chat-form" method="post" action="/kontraktas/'.$id.'/zinute">'.csrf_field()
            .'<label class="sr-only" for="chat-text-'.$id.'">Žinutė</label>'
            .'<textarea id="chat-text-'.$id.'" name="body" rows="1" maxlength="'.MESSAGE_MAX.'" placeholder="Rašykite žinutę…" required></textarea>'
            .'<button class="chat-send" aria-label="Siųsti"><svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M3.4 20.4 21 12 3.4 3.6 3.4 10.2 15 12 3.4 13.8z" fill="currentColor"/></svg></button></form>';
    return '<section class="chat" id="pokalbis-'.$id.'" data-chat="'.$id.'" data-state="'.e(chat_state($c, (int) $u['id'], $messages)).'">'
        .'<header class="chat-head"><span class="chat-dot" aria-hidden="true"></span><div><strong>'.e(first_name($other)).'</strong><span class="meta">'.e($sub).'</span></div>'
        .'<span class="chat-label">Pokalbis per GANG</span></header>'
        .'<div class="chat-list" aria-live="polite">'.($messages ? chat_list_html($c, (int) $u['id'], $messages) : $empty).'</div>'
        .$form.'</section>';
}

function chat_script(): string
{
    return <<<'HTML'
<script>
document.querySelectorAll('[data-chat]').forEach(function (box) {
  if (box.dataset.bound) return; box.dataset.bound = 1;
  var id = box.dataset.chat, list = box.querySelector('.chat-list'), form = box.querySelector('.chat-form');
  var text = form && form.querySelector('textarea'), send = form && form.querySelector('.chat-send'), busy = false;
  function bottom() { list.scrollTop = list.scrollHeight; }
  function grow() { if (!text) return; text.style.height = 'auto'; text.style.height = Math.min(text.scrollHeight, 160) + 'px'; }
  function apply(d) {
    if (!d || !d.state) return;
    if (d.html !== undefined && d.state !== box.dataset.state) {
      var near = list.scrollHeight - list.scrollTop - list.clientHeight < 80;
      list.innerHTML = d.html; if (near || d.mine) bottom();
    }
    box.dataset.state = d.state;
  }
  bottom();
  var tip = box.querySelector('.chat-suggest');
  if (tip) tip.addEventListener('click', function () { text.value = tip.dataset.text; grow(); text.focus(); text.setSelectionRange(text.value.length, text.value.length); tip.remove(); });
  if (form) {
    text.addEventListener('input', grow);
    text.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey && !e.isComposing && !matchMedia('(pointer: coarse)').matches) { e.preventDefault(); form.requestSubmit(); }
    });
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (busy || !text.value.trim()) return;
      busy = true; send.disabled = true;
      fetch(form.action, { method: 'POST', body: new FormData(form), headers: { 'X-Requested-With': 'fetch' }, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (d.error) { alert(d.error); return; }
          text.value = ''; grow(); if (tip) tip.remove(); d.mine = true; apply(d);
        })
        .catch(function () { alert('Žinutės išsiųsti nepavyko. Patikrinkite interneto ryšį ir bandykite dar kartą.'); })
        .finally(function () { busy = false; send.disabled = false; text.focus(); });
    });
  }
  function poll() {
    if (document.hidden) return;
    fetch('/kontraktas/' + id + '/zinutes?b=' + encodeURIComponent(box.dataset.state), { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; }).then(apply).catch(function () {});
  }
  setInterval(poll, 8000);
  document.addEventListener('visibilitychange', poll);
});
</script>
HTML;
}

function chat_json(array $data): never
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Where the chat lives: the job page (the employer sees it under the confirmed worker).
function chat_url(array $c): string
{
    return '/darbas/'.(int) $c['request_id'].'#pokalbis-'.(int) $c['id'];
}

// /kontraktas/{id}/zinutes: polling from the open chat.
function messages_poll(int $id): never
{
    $u = current_user();
    $c = $u ? chat_contract($id, $u) : null;
    if (!$c) {
        http_response_code(404);
        chat_json(['error' => 'not found']);
    }
    $messages = chat_messages($id);
    chat_seen($id, (int) $u['id']);
    $state = chat_state($c, (int) $u['id'], $messages);
    if ((string) ($_GET['b'] ?? '') === $state) {
        chat_json(['state' => $state]);
    }
    chat_json(['state' => $state, 'html' => chat_list_html($c, (int) $u['id'], $messages)]);
}

// /kontraktas/{id}/zinute: send a message (fetch from the chat, or a plain form post without JavaScript).
function message_send_action(int $id): never
{
    $ajax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
    $u = require_user();
    $fail = function (string $msg) use ($ajax, $id, $u): never {
        if ($ajax) {
            chat_json(['error' => $msg]);
        }
        flash($msg);
        $c = chat_contract($id, $u);
        redirect($c ? chat_url($c) : '/mano');
    };
    if (!hash_equals(csrf_token(), (string) ($_POST['csrf'] ?? ''))) {
        $fail('Puslapio galiojimo laikas baigėsi. Atnaujinkite puslapį ir bandykite dar kartą.');
    }
    $c = chat_contract($id, $u);
    if (!$c) {
        not_found();
    }
    if ($c['cancelled_at']) {
        $fail('Darbas atšauktas, todėl naujų žinučių siųsti nebegalima.');
    }
    $body = trim(str_replace("\r\n", "\n", (string) ($_POST['body'] ?? '')));
    $body = preg_replace("/\n{3,}/", "\n\n", $body);
    if ($body === '') {
        $fail('Parašykite žinutę.');
    }
    if (mb_strlen($body) > MESSAGE_MAX) {
        $fail('Žinutė per ilga: daugiausia '.MESSAGE_MAX.' simbolių.');
    }
    $recent = (int) sql('SELECT COUNT(*) FROM messages WHERE sender_id = ? AND created_at > ?', [$u['id'], date('Y-m-d H:i:s', strtotime('-1 hour'))])->fetchColumn();
    if ($recent >= MESSAGES_PER_HOUR) {
        $fail('Išsiuntėte daug žinučių per trumpą laiką. Pabandykite vėliau.');
    }
    $side = chat_side($c, $u);
    $msgId = insert('messages', ['contract_id' => $id, 'sender_id' => $u['id'], 'body' => $body, 'created_at' => now()]);
    chat_seen($id, (int) $u['id']);
    chat_notify($c, $side, $body, $msgId);
    if ($ajax) {
        $messages = chat_messages($id);
        chat_json(['state' => chat_state($c, (int) $u['id'], $messages), 'html' => chat_list_html($c, (int) $u['id'], $messages)]);
    }
    redirect(chat_url($c));
}

// Email the other side about a new message, unless they have the chat open right now
// or already got an email they have not acted on (one email per batch of messages).
function chat_notify(array $c, string $side, string $body, int $msgId): void
{
    $to = chat_other_id($c, $side);
    $row = chat_read_row((int) $c['id'], $to);
    if ($row && $row['seen_at'] && $row['seen_at'] > date('Y-m-d H:i:s', time() - CHAT_ACTIVE_SECONDS)) {
        return;
    }
    if ($row && $row['mailed_at'] && ($row['seen_at'] === null || $row['seen_at'] < $row['mailed_at'])
        && $row['mailed_at'] > date('Y-m-d H:i:s', strtotime('-'.CHAT_MAIL_HOURS.' hours'))) {
        return;
    }
    $email = sql('SELECT email FROM users WHERE id = ? AND blocked = 0', [$to])->fetchColumn();
    if (!$email) {
        return;
    }
    $role = ROLES[$c['role']] ?? '';
    $from = $side === 'worker' ? first_name($c['worker_name']) : first_name($c['manager_name']).' ('.$c['venue_name'].')';
    $short = mb_strlen($body) > 600 ? rtrim(mb_substr($body, 0, 600)).'…' : $body;
    send_mail($email, 'Nauja žinutė: '.($side === 'worker' ? first_name($c['worker_name']) : $c['venue_name']), [
        'kicker' => 'Nauja žinutė',
        'title' => $role,
        'lead' => '**'.$from.'** parašė jums žinutę dėl darbo ('.period($c).').',
        'note' => ['label' => 'Žinutė', 'text' => $short],
        'cta' => ['Atsakyti', url('/pokalbis/'.(int) $c['id'])],
        'footnote' => 'Į žinutę atsakykite gang.lt pokalbyje: atsakymas į šį laišką žinutės gavėjo nepasieks.',
    ]);
    chat_mark((int) $c['id'], $to, ['mailed_at' => now()]);
}

// /pokalbis/{id}: the email's link. Signs in if needed, then opens the chat on the job page.
function chat_link_page(int $id): never
{
    $u = require_user();
    $c = chat_contract($id, $u);
    if (!$c) {
        flash('Šis pokalbis skirtas kitai paskyrai. Prisijunkite paskyra, kuriai buvo išsiųstas laiškas.');
        redirect('/mano');
    }
    redirect(chat_url($c));
}
