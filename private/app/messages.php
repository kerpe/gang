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
// "Dabar pokalbyje": the other side's open chat polled within this many seconds (it polls every 8 s).
const CHAT_HERE_SECONDS = 25;

// GANG's own emoji: put into a message as "[gang:kava]" (alone it shows as a sticker) or on a message as a reaction.
// Icons are 24×24 line drawings in currentColor; "chef" is the GANG knives.
const GANG_EMOJI = [
    'chef' => ['Yes, chef!', ''],
    'dega' => ['Viskas dega', '<path d="M12 3c.9 3.3 5.2 5.4 5.2 10.2a5.2 5.2 0 0 1-10.4 0c0-2.6 1.4-4.2 2.6-5.3.3 1.8 1.2 2.9 2.4 3.3C11.3 9 11 6 12 3z"/><path d="M12 20.4c-1.5 0-2.4-1.1-2.4-2.4 0-1.6 1.4-2.4 2.4-3.6 1 1.2 2.4 2 2.4 3.6 0 1.3-.9 2.4-2.4 2.4z"/>'],
    'baigesi' => ['86: baigėsi', '<circle cx="12" cy="12" r="9"/><text x="12" y="15.6" text-anchor="middle" font-size="10" font-weight="800" font-family="system-ui, sans-serif" fill="currentColor" stroke="none">86</text>'],
    'veluoju' => ['Vėluoju 5 min. (tikrai 5)', '<circle cx="12" cy="13" r="8"/><path d="M12 9v4l2.6 1.8M9.5 2.8h5M12 2.8V5M19.2 5.6l1.4 1.4"/>'],
    'kava' => ['Be kavos nekalbu', '<path d="M4.5 9.5h11v4.5a5 5 0 0 1-5 5h-1a5 5 0 0 1-5-5z"/><path d="M15.5 10.5h1.3a2.6 2.6 0 0 1 0 5.2h-1.6M8.5 3.5c-.9 1.1.9 2.1 0 3.4M12 3.5c-.9 1.1.9 2.1 0 3.4M3.5 21h14"/>'],
    'leksciu' => ['Lėkštės pačios neišsineš', '<ellipse cx="12" cy="8" rx="9" ry="3"/><path d="M3 8v2.6c0 1.7 4 3 9 3s9-1.3 9-3V8M3 12.4V15c0 1.7 4 3 9 3s9-1.3 9-3v-2.6"/><path d="M8 20.5h8"/>'],
    'arbatpinigiai' => ['Arbatpinigiai? Juokauji', '<path d="M7.5 6.5h9v2.2a2.4 2.4 0 0 1 1.5 2.2v7.6a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2v-7.6a2.4 2.4 0 0 1 1.5-2.2z"/><path d="M7.5 8.7h9"/><circle cx="12" cy="14.6" r="2.4"/><path d="M12 2.5v2.2"/>'],
    'service' => ['Service!', '<path d="M3 19.5h18M5 17a7 7 0 0 1 14 0v2.5H5z"/><path d="M12 10V8.4"/><circle cx="12" cy="6.8" r="1.6"/>'],
    'maciau' => ['Mačiau', '<circle cx="7.5" cy="12" r="4"/><circle cx="16.5" cy="12" r="4"/><circle cx="9.6" cy="12.6" r="1.5" fill="currentColor" stroke="none"/><circle cx="18.6" cy="12.6" r="1.5" fill="currentColor" stroke="none"/><path d="M3.5 6.6l5-1.2M20.5 6.6l-5-1.2"/>'],
    'islaiste' => ['Kas išlaistė?', '<path d="M12 3s-4.2 4.6-4.2 7.6a4.2 4.2 0 0 0 8.4 0C16.2 7.6 12 3 12 3z"/><path d="M3.5 19.5c2.3-1.6 5.3-1.2 8.5-.6s6 .4 8.5-.9"/><path d="M14 18.2l1.6 2.5"/>'],
    'dar' => ['Dar vieną?', '<path d="M4 4.5h16l-8 8.5z"/><path d="M12 13v6.5M8 19.5h8M15.2 2.5l-4 7"/><circle cx="10.6" cy="8.2" r="1.2" fill="currentColor" stroke="none"/>'],
    'ledas' => ['Ledo neliko', '<path d="M12 3.5l7.5 4.2v8.6L12 20.5l-7.5-4.2V7.7z"/><path d="M4.5 7.7 12 12l7.5-4.3M12 12v8.5"/><path d="M8 21.5v.01M16 21.5v.01" stroke-width="2.4"/>'],
];

const CHAT_SMILE = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.5"/><path d="M8.6 14.2c.9 1.2 2 1.8 3.4 1.8s2.5-.6 3.4-1.8"/><path d="M9.2 9.6v.01M14.8 9.6v.01" stroke-width="2.4"/></svg>';

function gang_emoji_icon(string $code): string
{
    if ($code === 'chef') {
        return knives('ge');
    }
    return '<svg class="ge" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.(GANG_EMOJI[$code][1] ?? '').'</svg>';
}

// "[gang:kava]" → "kava" when the message is one GANG emoji sent as a sticker.
function sticker_code(string $body): ?string
{
    return preg_match('/^\[gang:([a-z0-9]+)\]$/', $body, $m) && isset(GANG_EMOJI[$m[1]]) ? $m[1] : null;
}

// The message as plain text (emails): a sticker becomes its caption, an emoji in the text "(caption)".
function chat_plain(string $body): string
{
    $code = sticker_code($body);
    if ($code) {
        return GANG_EMOJI[$code][0];
    }
    return preg_replace_callback('/\[gang:([a-z0-9]+)\]/', fn ($m) => isset(GANG_EMOJI[$m[1]]) ? '('.GANG_EMOJI[$m[1]][0].')' : $m[0], $body);
}

// One GANG emoji inside the text of a message or in the field.
function gang_emoji_inline(string $code): string
{
    return '<span class="ge-in" data-emoji="'.e($code).'" role="img" aria-label="'.e(GANG_EMOJI[$code][0]).'" title="'.e(GANG_EMOJI[$code][0]).'">'.gang_emoji_icon($code).'</span>';
}

// The message text as HTML: GANG emoji drawn where they were put, line breaks kept.
function chat_body_html(string $body): string
{
    $html = preg_replace_callback('/\[gang:([a-z0-9]+)\]/', fn ($m) => isset(GANG_EMOJI[$m[1]]) ? gang_emoji_inline($m[1]) : $m[0], e($body));
    return nl2br($html);
}

// A message of GANG emoji only (two or more): drawn bigger, without a bubble.
function chat_emoji_only(string $body): bool
{
    return preg_match('/^(\s*\[gang:[a-z0-9]+\]\s*){2,}$/', $body) === 1;
}

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
    $set = ['last_id' => $last, 'seen_at' => now()];
    // read_at moves only when a message from the other side is read for the first time.
    $before = (int) (chat_read_row($contractId, $userId)['last_id'] ?? 0);
    if ($last > $before && sql('SELECT 1 FROM messages WHERE contract_id = ? AND sender_id <> ? AND id > ? LIMIT 1', [$contractId, $userId, $before])->fetchColumn()) {
        $set['read_at'] = now();
    }
    chat_mark($contractId, $userId, $set);
}

function chat_messages(int $contractId): array
{
    return sql('SELECT * FROM messages WHERE contract_id = ? ORDER BY id', [$contractId])->fetchAll();
}

// The worker's note sent with the application and the messages added to it before the decision
// open the chat (id 0, not stored in messages).
function chat_notes(array $c): array
{
    $s = sql('SELECT id, note, created_at FROM responses WHERE request_id = ? AND worker_id = ? ORDER BY id DESC LIMIT 1', [$c['request_id'], $c['worker_id']])->fetch();
    if (!$s) {
        return [];
    }
    $notes = [];
    if (trim((string) $s['note']) !== '') {
        $notes[] = ['id' => 0, 'sender_id' => $c['worker_id'], 'body' => trim((string) $s['note']), 'created_at' => $s['created_at'], 'note' => 'Žinutė su kandidatūra'];
    }
    foreach (response_notes((int) $s['id']) as $n) {
        $notes[] = ['id' => 0, 'sender_id' => $c['worker_id'], 'body' => $n['body'], 'created_at' => $n['created_at'], 'note' => 'Kandidatūros papildymas'];
    }
    return $notes;
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

// Reactions per message id: [code => [user ids]].
function chat_reactions(int $contractId): array
{
    $out = [];
    foreach (sql('SELECT message_id, user_id, code FROM message_reactions WHERE contract_id = ? ORDER BY id', [$contractId])->fetchAll() as $r) {
        $out[(int) $r['message_id']][$r['code']][] = (int) $r['user_id'];
    }
    return $out;
}

function chat_time(?string $at): string
{
    if (!$at) {
        return '';
    }
    return substr($at, 0, 10) === today() ? substr($at, 11, 5) : chat_day($at).' '.substr($at, 11, 5);
}

// The message list. Own messages carry ✓ (sent) or ✓✓ (the other side has seen them);
// the newest one also says "Išsiųsta" or "Perskaityta 18:32".
function chat_list_html(array $c, int $userId, array $messages): string
{
    $messages = [...chat_notes($c), ...$messages];
    $otherRow = chat_read_row((int) $c['id'], chat_other_id($c, chat_side($c, ['id' => $userId])));
    $otherLast = (int) ($otherRow['last_id'] ?? 0);
    $lastMine = 0;
    foreach ($messages as $m) {
        if ((int) $m['sender_id'] === $userId) {
            $lastMine = (int) $m['id'];
        }
    }
    $reactions = chat_reactions((int) $c['id']);
    $open = !$c['cancelled_at'];
    $one = '<svg class="tick" viewBox="0 0 16 12" aria-hidden="true"><path d="M1.5 6.5 5 10l7-8.5"/></svg>';
    $two = '<svg class="tick" viewBox="0 0 20 12" aria-hidden="true"><path d="M1.5 6.5 5 10l7-8.5M8.6 9.4l.6.6 7-8.5"/></svg>';
    $html = '';
    $day = '';
    foreach ($messages as $m) {
        $d = substr($m['created_at'], 0, 10);
        if ($d !== $day) {
            $html .= '<p class="chat-day"><span>'.e(chat_day($m['created_at'])).'</span></p>';
            $day = $d;
        }
        $id = (int) $m['id'];
        $mine = (int) $m['sender_id'] === $userId;
        $status = '';
        if ($mine && $id > 0) {
            $read = $id <= $otherLast;
            $status = '<span class="msg-seen'.($read ? ' read' : '').'" title="'.($read ? 'Perskaityta' : 'Išsiųsta').'">'.($read ? $two : $one);
            if ($id === $lastMine) {
                $status .= $read ? 'Perskaityta'.(($t = chat_time($otherRow['read_at'] ?? null)) !== '' ? ' '.e($t) : '') : 'Išsiųsta';
            }
            $status .= '</span>';
        }
        $code = sticker_code((string) $m['body']);
        $body = $code
            ? '<span class="sticker-art">'.gang_emoji_icon($code).'</span><p>'.e(GANG_EMOJI[$code][0]).'</p>'
            : '<p>'.chat_body_html((string) $m['body']).'</p>';
        $only = !$code && chat_emoji_only((string) $m['body']);
        $chips = '';
        foreach ($reactions[$id] ?? [] as $rc => $users) {
            if (!isset(GANG_EMOJI[$rc])) {
                continue;
            }
            $by = in_array($userId, $users, true);
            $chips .= '<button type="button" class="react-chip'.($by ? ' by-me' : '').'" data-react="'.e($rc).'" title="'.e(GANG_EMOJI[$rc][0]).($by ? ' (paspauskite, kad nuimtumėte)' : '').'"'.($open ? '' : ' disabled').'>'
                .gang_emoji_icon($rc).(count($users) > 1 ? '<small>'.count($users).'</small>' : '').'</button>';
        }
        $html .= '<div class="msg'.($mine ? ' mine' : '').($code ? ' sticker' : '').($only ? ' emoji-only' : '').'"'.($id > 0 ? ' data-id="'.$id.'"' : '').'>'
            .(!empty($m['note']) ? '<span class="msg-tag">'.e($m['note']).'</span>' : '').$body
            .'<span class="msg-time">'.e(substr($m['created_at'], 11, 5)).$status.'</span>'
            .($chips !== '' ? '<span class="msg-reacts">'.$chips.'</span>' : '')
            .($open && $id > 0 ? '<button type="button" class="react-add" aria-label="Pridėti GANG emoji" title="GANG emoji">'.CHAT_SMILE.'</button>' : '')
            .'</div>';
    }
    return $html;
}

// Is the other side looking at the chat right now?
function chat_here(array $c, int $userId): bool
{
    $at = chat_read_row((int) $c['id'], chat_other_id($c, chat_side($c, ['id' => $userId])))['seen_at'] ?? null;
    return $at && $at > date('Y-m-d H:i:s', time() - CHAT_HERE_SECONDS);
}

// Changes when a message arrives, the other side reads one, a reaction changes or the other side
// opens or leaves the chat, so polling sends the list only then.
function chat_state(array $c, int $userId, array $messages): string
{
    $last = $messages ? (int) end($messages)['id'] : 0;
    $other = (int) (chat_read_row((int) $c['id'], chat_other_id($c, chat_side($c, ['id' => $userId])))['last_id'] ?? 0);
    $r = sql('SELECT COUNT(*), COALESCE(MAX(id), 0) FROM message_reactions WHERE contract_id = ?', [$c['id']])->fetch(PDO::FETCH_NUM);
    return $last.'-'.min($other, $last).'-'.$r[0].'.'.$r[1].'-'.(chat_here($c, $userId) ? 1 : 0).'-'.substr(today(), 8);
}

// The panel of GANG emoji (one per chat): into the message from the field's button, a reaction from a message's.
function chat_emoji_panel(): string
{
    $out = '';
    foreach (GANG_EMOJI as $code => [$label]) {
        $out .= '<button type="button" data-emoji="'.e($code).'" title="'.e($label).'">'.gang_emoji_icon($code).'<span>'.e($label).'</span></button>';
    }
    return '<div class="emoji-panel" role="menu" hidden><p class="emoji-head"><span class="emoji-for">GANG emoji</span><button type="button" class="emoji-close" aria-label="Uždaryti">×</button></p><div class="emoji-grid">'.$out.'</div></div>';
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
        : '<form class="chat-form" method="post" action="/kontraktas/'.$id.'/zinute">'.csrf_field()
            .'<label class="sr-only" for="chat-text-'.$id.'">Žinutė</label>'
            .'<textarea id="chat-text-'.$id.'" name="body" rows="1" maxlength="'.MESSAGE_MAX.'" '
            .($suggest !== '' ? 'placeholder="'.e($suggest).'" data-suggest="'.e($suggest).'" title="Siūloma žinutė: spauskite „Siųsti“ arba Tab, kad ją panaudotumėte"' : 'placeholder="Rašykite žinutę…" required').'></textarea>'
            .'<button type="button" class="chat-emoji" aria-label="GANG emoji" title="GANG emoji" aria-haspopup="true">'.CHAT_SMILE.'</button>'
            .'<button class="chat-send" aria-label="Siųsti"><svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M3.4 20.4 21 12 3.4 3.6 3.4 10.2 15 12 3.4 13.8z" fill="currentColor"/></svg></button></form>';
    $here = chat_here($c, (int) $u['id']);
    return '<section class="chat'.($here ? ' here' : '').'" id="pokalbis-'.$id.'" data-chat="'.$id.'" data-state="'.e(chat_state($c, (int) $u['id'], $messages)).'">'
        .'<header class="chat-head"><span class="chat-dot" aria-hidden="true"></span><div><strong>'.e(first_name($other)).'</strong><span class="meta">'.e($sub)
        .'<span class="chat-presence"> · Dabar pokalbyje</span></span></div>'
        .'<span class="chat-label">Pokalbis per GANG</span></header>'
        .'<div class="chat-list" aria-live="polite">'.($messages || chat_notes($c) ? chat_list_html($c, (int) $u['id'], $messages) : $empty).'</div>'
        .$form.($closed ? '' : chat_emoji_panel()).'</section>';
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
  function apply(d) {
    if (!d || !d.state) return;
    if (d.html !== undefined && d.state !== box.dataset.state) {
      var near = list.scrollHeight - list.scrollTop - list.clientHeight < 80;
      list.innerHTML = d.html; if (near || d.mine) bottom();
    }
    box.dataset.state = d.state;
    if (d.here !== undefined) box.classList.toggle('here', !!d.here);
  }
  bottom();
  // The field: text with GANG emoji inside it. It is written to the hidden textarea as "[gang:kava]" when sent.
  var ed = null, saved = null, uid = 0;
  if (text) {
    ed = document.createElement('div');
    ed.className = 'chat-input is-empty'; ed.contentEditable = 'true'; ed.spellcheck = true;
    ed.setAttribute('role', 'textbox'); ed.setAttribute('aria-multiline', 'true'); ed.setAttribute('aria-label', 'Žinutė');
    ed.dataset.placeholder = text.placeholder;
    if (text.title) ed.title = text.title;
    text.required = false; text.hidden = true; text.tabIndex = -1;
    text.parentNode.insertBefore(ed, text);
  }
  function value(node) {
    var s = '';
    node.childNodes.forEach(function (n) {
      if (n.nodeType === 3) { s += n.nodeValue; return; }
      if (n.nodeType !== 1) return;
      if (n.dataset && n.dataset.emoji) { s += '[gang:' + n.dataset.emoji + ']'; return; }
      if (n.tagName === 'BR') { s += '\n'; return; }
      if (/^(DIV|P)$/.test(n.tagName) && s && s.slice(-1) !== '\n') s += '\n';
      s += value(n);
    });
    return s;
  }
  function body() { return value(ed).replace(/ /g, ' ').replace(/[​]/g, ''); }
  function changed() { var empty = !body().trim(); if (empty && ed.innerHTML !== '') ed.innerHTML = ''; ed.classList.toggle('is-empty', empty); }
  function caretEnd() { var r = document.createRange(); r.selectNodeContents(ed); r.collapse(false); var sel = getSelection(); sel.removeAllRanges(); sel.addRange(r); saved = r.cloneRange(); }
  // The suggested first message is the field's grey placeholder: Tab puts it in for editing, sending an empty field sends it as is.
  function useTip() { ed.textContent = text.dataset.suggest; changed(); ed.focus(); caretEnd(); }
  function dropTip() { if (!text.dataset.suggest) return; delete text.dataset.suggest; ed.removeAttribute('title'); ed.dataset.placeholder = 'Rašykite žinutę…'; }
  function putEmoji(code, btn) {
    var node = document.createElement('span');
    node.className = 'ge-in'; node.contentEditable = 'false'; node.dataset.emoji = code;
    node.title = btn.title; node.setAttribute('role', 'img'); node.setAttribute('aria-label', btn.title);
    var svg = btn.querySelector('svg').cloneNode(true), mask = svg.querySelector('mask');
    if (mask) { var mid = 'gk-in-' + id + '-' + (++uid); mask.id = mid; svg.querySelectorAll('[mask]').forEach(function (g) { g.setAttribute('mask', 'url(#' + mid + ')'); }); }
    node.appendChild(svg);
    ed.focus();
    var r = saved && ed.contains(saved.startContainer) ? saved : null;
    if (!r) { r = document.createRange(); r.selectNodeContents(ed); r.collapse(false); }
    r.deleteContents();
    var gap = document.createTextNode('\u00a0');
    r.insertNode(gap); r.insertNode(node);
    r = document.createRange(); r.setStartAfter(gap); r.collapse(true);
    var sel = getSelection(); sel.removeAllRanges(); sel.addRange(r); saved = r.cloneRange();
    dropTip(); changed();
  }
  if (form) {
    document.addEventListener('selectionchange', function () {
      var sel = getSelection();
      if (sel.rangeCount && ed.contains(sel.getRangeAt(0).startContainer)) saved = sel.getRangeAt(0).cloneRange();
    });
    ed.addEventListener('input', changed);
    ed.addEventListener('paste', function (e) {
      e.preventDefault();
      var t = (e.clipboardData || window.clipboardData).getData('text/plain');
      if (!document.execCommand('insertText', false, t)) { ed.appendChild(document.createTextNode(t)); caretEnd(); }
      changed();
    });
    ed.addEventListener('drop', function (e) { e.preventDefault(); });
    ed.addEventListener('keydown', function (e) {
      if (e.key === 'Tab' && !e.shiftKey && text.dataset.suggest && !body().trim()) { e.preventDefault(); useTip(); return; }
      if (e.key !== 'Enter' || e.isComposing) return;
      if (!e.shiftKey && !matchMedia('(pointer: coarse)').matches) { e.preventDefault(); form.requestSubmit(); return; }
      e.preventDefault();
      if (!document.execCommand('insertLineBreak')) document.execCommand('insertHTML', false, '<br>');
      changed();
    });
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!busy && !body().trim() && text.dataset.suggest) useTip();
      var v = body().trim();
      if (busy || !v) { if (!busy) ed.focus(); return; }
      text.value = v;
      busy = true; send.disabled = true;
      fetch(form.action, { method: 'POST', body: new FormData(form), headers: { 'X-Requested-With': 'fetch' }, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (d.error) { alert(d.error); return; }
          ed.innerHTML = ''; text.value = ''; saved = null; changed(); dropTip(); d.mine = true; apply(d);
        })
        .catch(function () { alert('Žinutės išsiųsti nepavyko. Patikrinkite interneto ryšį ir bandykite dar kartą.'); })
        .finally(function () { busy = false; send.disabled = false; ed.focus(); });
    });
  }
  // GANG emoji: the field's button puts one into the message, a message's button puts one on it as a reaction.
  var panel = box.querySelector('.emoji-panel'), target = null, opener = null;
  function closePanel() { if (!panel || panel.hidden) return; panel.hidden = true; target = null; if (opener) opener.setAttribute('aria-expanded', 'false'); }
  function openPanel(btn, msg) {
    if (!panel) return;
    if (!panel.hidden && opener === btn) { closePanel(); return; }
    target = msg; opener = btn; btn.setAttribute('aria-expanded', 'true');
    panel.querySelector('.emoji-for').textContent = msg ? 'Reakcija į žinutę' : 'GANG emoji į žinutę';
    panel.hidden = false;
    var b = box.getBoundingClientRect(), r = btn.getBoundingClientRect();
    var top = msg ? r.bottom - b.top + 6 : r.top - b.top - panel.offsetHeight - 8;
    if (msg && top + panel.offsetHeight > box.clientHeight) top = Math.max(8, r.top - b.top - panel.offsetHeight - 6);
    panel.style.top = top + 'px';
    if (msg || !matchMedia('(pointer: coarse)').matches) panel.querySelector('[data-emoji]').focus({ preventScroll: true });
  }
  function post(url, data) {
    data.append('csrf', form.querySelector('[name=csrf]').value);
    return fetch(url, { method: 'POST', body: data, headers: { 'X-Requested-With': 'fetch' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) { if (d.error) { alert(d.error); return null; } return d; })
      .catch(function () { alert('Nepavyko. Patikrinkite interneto ryšį ir bandykite dar kartą.'); return null; });
  }
  function react(msgId, code) {
    var f = new FormData(); f.append('message', msgId); f.append('code', code);
    post('/kontraktas/' + id + '/reakcija', f).then(apply);
  }
  if (panel && form) {
    form.querySelector('.chat-emoji').addEventListener('mousedown', function (e) { e.preventDefault(); });
    form.querySelector('.chat-emoji').addEventListener('click', function () { openPanel(this, null); });
    panel.addEventListener('mousedown', function (e) { if (!target && e.target.closest('[data-emoji]')) e.preventDefault(); });
    list.addEventListener('click', function (e) {
      var add = e.target.closest('.react-add'), chip = e.target.closest('.react-chip');
      if (add) openPanel(add, add.closest('.msg'));
      else if (chip && !chip.disabled) react(chip.closest('.msg').dataset.id, chip.dataset.react);
    });
    panel.addEventListener('click', function (e) {
      if (e.target.closest('.emoji-close')) { closePanel(); return; }
      var b = e.target.closest('[data-emoji]'); if (!b) return;
      var code = b.dataset.emoji, msg = target; closePanel();
      if (msg) { react(msg.dataset.id, code); return; }
      putEmoji(code, b);
    });
    document.addEventListener('click', function (e) { if (!panel.hidden && !panel.contains(e.target) && e.target !== opener && !opener.contains(e.target)) closePanel(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !panel.hidden) { var o = opener; closePanel(); o.focus(); } });
    list.addEventListener('scroll', closePanel);
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
    $here = chat_here($c, (int) $u['id']);
    if ((string) ($_GET['b'] ?? '') === $state) {
        chat_json(['state' => $state, 'here' => $here]);
    }
    chat_json(['state' => $state, 'here' => $here, 'html' => chat_list_html($c, (int) $u['id'], $messages)]);
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
    // A GANG emoji sticker comes as its code from the panel.
    $sticker = (string) ($_POST['sticker'] ?? '');
    if ($sticker !== '' && isset(GANG_EMOJI[$sticker])) {
        $body = '[gang:'.$sticker.']';
    }
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
        chat_json(['state' => chat_state($c, (int) $u['id'], $messages), 'here' => chat_here($c, (int) $u['id']), 'html' => chat_list_html($c, (int) $u['id'], $messages)]);
    }
    redirect(chat_url($c));
}

// /kontraktas/{id}/reakcija: put a GANG emoji on a message, swap it for another, or take it off (same one again).
function message_react_action(int $id): never
{
    $u = require_user();
    if (!hash_equals(csrf_token(), (string) ($_POST['csrf'] ?? ''))) {
        chat_json(['error' => 'Puslapio galiojimo laikas baigėsi. Atnaujinkite puslapį ir bandykite dar kartą.']);
    }
    $c = chat_contract($id, $u);
    if (!$c) {
        http_response_code(404);
        chat_json(['error' => 'not found']);
    }
    if ($c['cancelled_at']) {
        chat_json(['error' => 'Darbas atšauktas, todėl pokalbis uždarytas.']);
    }
    $code = (string) ($_POST['code'] ?? '');
    $msgId = (int) ($_POST['message'] ?? 0);
    if (!isset(GANG_EMOJI[$code]) || !sql('SELECT 1 FROM messages WHERE id = ? AND contract_id = ?', [$msgId, $id])->fetchColumn()) {
        chat_json(['error' => 'Nepavyko. Atnaujinkite puslapį ir bandykite dar kartą.']);
    }
    $had = sql('SELECT code FROM message_reactions WHERE message_id = ? AND user_id = ?', [$msgId, $u['id']])->fetchColumn();
    sql('DELETE FROM message_reactions WHERE message_id = ? AND user_id = ?', [$msgId, $u['id']]);
    if ($had !== $code) {
        insert('message_reactions', ['contract_id' => $id, 'message_id' => $msgId, 'user_id' => $u['id'], 'code' => $code, 'created_at' => now()]);
    }
    $messages = chat_messages($id);
    chat_json(['state' => chat_state($c, (int) $u['id'], $messages), 'here' => chat_here($c, (int) $u['id']), 'html' => chat_list_html($c, (int) $u['id'], $messages)]);
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
    $body = chat_plain($body);
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
