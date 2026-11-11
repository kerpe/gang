<?php

declare(strict_types=1);

// English version of the site. Lithuanian stays the default and the source of every text: pages
// are built in Lithuanian as before, and for visitors who chose English the finished page is
// translated on its way out (i18n_html()). The translations live in private/app/lang/en.php,
// keyed by the Lithuanian text exactly as it appears on the page. A text without a translation
// simply stays in Lithuanian, so a new or changed text never breaks a page; locally,
// GANG_I18N_LOG lists the texts still missing (see i18n_miss()). What people write themselves (job descriptions, names, chat
// messages) is not translated.
//
// The choice is kept in the cookie gang_lang for a year; ?lang=en or ?lang=lt on any address
// switches it (the header link „EN“ / „LT“), so an English link can also be shared.

const LANGS = ['lt' => 'LT', 'en' => 'EN'];
const LANG_COOKIE = 'gang_lang';

function lang(): string
{
    static $lang = null;
    if ($lang === null) {
        $lang = ($_COOKIE[LANG_COOKIE] ?? '') === 'en' ? 'en' : 'lt';
    }
    return $lang;
}

// ?lang=en / ?lang=lt: remember the choice and show the same address without the parameter.
function lang_switch(): void
{
    $want = $_GET['lang'] ?? null;
    if (!is_string($want) || !isset(LANGS[$want]) || is_post()) {
        return;
    }
    setcookie(LANG_COOKIE, $want, ['expires' => time() + 365 * 86400, 'path' => '/', 'samesite' => 'Lax', 'secure' => str_starts_with(config()['public_url'], 'https://')]);
    $query = $_GET;
    unset($query['lang']);
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $qs = http_build_query($query);
    // "?registruotis" has no value: keep it that way.
    $qs = preg_replace('/(^|&)([a-z_]+)=(?=&|$)/', '$1$2', $qs);
    header('Cache-Control: no-store');
    header('Location: '.$path.($qs !== '' ? '?'.$qs : ''), true, 302);
    exit;
}

// The link to the other language, for the header and the phone menu.
function lang_link(string $class = ''): string
{
    $other = lang() === 'en' ? 'lt' : 'en';
    $query = $_GET;
    $query['lang'] = $other;
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $title = $other === 'en' ? 'English' : 'Lietuvių';
    // The label is the language code, the same in both languages: the translator leaves it alone.
    return '<a class="lang'.($class !== '' ? ' '.$class : '').'" href="'.e($path.'?'.http_build_query($query)).'" hreflang="'.$other.'" lang="'.$other.'" title="'.$title.'" data-i18n-skip>'.LANGS[$other].'</a>';
}

// Starts translating everything the page prints, when English was chosen.
function i18n_start(): void
{
    // The admin area stays in Lithuanian.
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    if (lang() !== 'en' || str_starts_with($path, '/admin')) {
        return;
    }
    ob_start('i18n_output');
}

function i18n_output(string $out): string
{
    $type = '';
    foreach (headers_list() as $h) {
        if (stripos($h, 'Content-Type:') === 0) {
            $type = strtolower(trim(substr($h, 13)));
        }
    }
    if ($out === '') {
        return $out;
    }
    if (str_starts_with($type, 'application/json')) {
        $data = json_decode($out, true);
        if (!is_array($data)) {
            return $out;
        }
        array_walk_recursive($data, function (&$v, $k) {
            if (is_string($v) && $v !== '' && !in_array($k, ['state', 'b', 'url', 'code'], true)) {
                $v = str_contains($v, '<') ? i18n_html($v) : (i18n_text($v) ?? $v);
            }
        });
        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    if ($type === '' || str_starts_with($type, 'text/html')) {
        return i18n_html($out);
    }
    return $out;
}

/** The dictionary: ['texts' => [lt => en], 'patterns' => [regex => en with {1}, {2}…]]. */
function i18n_dict(): array
{
    static $dict = null;
    if ($dict === null) {
        $dict = require __DIR__.'/lang/en.php';
        $texts = [];
        foreach ($dict['texts'] as $lt => $en) {
            $texts[i18n_key($lt)] = $en;
        }
        $dict['texts'] = $texts;
    }
    return $dict;
}

// Lookup key: one space for any run of white space, no-break spaces and word joiners included.
function i18n_key(string $s): string
{
    return trim(preg_replace('/[\s\x{00A0}\x{2060}]+/u', ' ', $s));
}

// Translates one piece of text (no HTML). null = no translation known.
function i18n_text(string $s, int $depth = 0): ?string
{
    $key = i18n_key($s);
    if ($key === '' || !preg_match('/\pL/u', $key)) {
        return null;
    }
    $dict = i18n_dict();
    if (isset($dict['texts'][$key])) {
        return $dict['texts'][$key];
    }
    // Numbers inside a text: "Liko simbolių: 12" is found as "Liko simbolių: {n}".
    if (preg_match_all('/\d+(?:[.,]\d+)?/', $key, $nums)) {
        $generic = preg_replace('/\d+(?:[.,]\d+)?/', '{n}', $key);
        if (isset($dict['texts'][$generic])) {
            $i = 0;
            return preg_replace_callback('/\{n\}/', function () use ($nums, &$i) {
                return $nums[0][$i++] ?? '';
            }, $dict['texts'][$generic]);
        }
    }
    if ($depth > 3) {
        return null;
    }
    foreach ($dict['patterns'] as $re => $en) {
        if (preg_match($re, $key, $m)) {
            return preg_replace_callback('/\{(\d)\}/', function ($p) use ($m, $depth) {
                $part = $m[(int) $p[1]] ?? '';
                return i18n_text($part, $depth + 1) ?? i18n_dates($part);
            }, $en);
        }
    }
    // "· GANG naujokas", "Vilnius ·": a separator at the edge.
    if (preg_match('/^(· )(.+)$|^(.+)( ·)$/u', $key, $m)) {
        $inner = $m[2] !== '' ? $m[2] : $m[3];
        if (($en = i18n_text($inner, $depth + 1)) !== null) {
            return $m[2] !== '' ? '· '.$en : $en.' ·';
        }
    }
    // "Label: value" where the label is known ("Rasta: 3", "El. paštas: …"); the value is translated
    // when it can be and kept otherwise (a name, an address).
    if (preg_match('/^([^:]{2,60}): (.+)$/u', $key, $m) && ($label = i18n_text($m[1], $depth + 1)) !== null) {
        return $label.': '.(i18n_text($m[2], $depth + 1) ?? i18n_dates($m[2]));
    }
    // Short lists: "Barmenas · Bistro Ąžuolas · lapkričio 7 d.", "Barmenas, Padavėjas". Each part is
    // translated on its own; names and addresses stay.
    foreach ([' · ', ', '] as $sep) {
        if (str_contains($key, $sep) && mb_strlen($key) < 160) {
            $changed = false;
            $parts = array_map(function ($part) use (&$changed, $depth) {
                $en = i18n_text($part, $depth + 1) ?? i18n_dates($part);
                $changed = $changed || $en !== $part;
                return $en;
            }, explode($sep, $key));
            if ($changed) {
                return implode($sep, $parts);
            }
        }
    }
    $dated = i18n_dates($key);
    return $dated !== $key ? $dated : null;
}

// Lithuanian dates in a text: "lapkričio 7 d." → "7 November", "lapkričio 7–8 d." → "7–8 November",
// "šiandien 14:00" → "today 14:00".
function i18n_dates(string $s): string
{
    static $months = ['sausio' => 'January', 'vasario' => 'February', 'kovo' => 'March', 'balandžio' => 'April', 'gegužės' => 'May', 'birželio' => 'June', 'liepos' => 'July', 'rugpjūčio' => 'August', 'rugsėjo' => 'September', 'spalio' => 'October', 'lapkričio' => 'November', 'gruodžio' => 'December'];
    // "2026 m. lapkričio 7 d." → "7 November 2026".
    $s = preg_replace_callback('/(\d{4}) m\. ('.implode('|', array_keys($months)).') (\d{1,2}) d\./u', fn ($m) => $m[3].' '.$months[$m[2]].' '.$m[1], $s);
    $s = preg_replace_callback('/(?<!\pL)('.implode('|', array_keys($months)).')\s(\d{1,2})(?:[\s\x{2060}]*–[\s\x{2060}]*(\d{1,2}))?[\s\x{00A0}]d\.?/iu', function ($m) use ($months) {
        return $m[2].(isset($m[3]) && $m[3] !== '' ? "\u{2060}–\u{2060}".$m[3] : '')."\u{00A0}".$months[mb_strtolower($m[1])];
    }, $s);
    // "šiandien 14:00", "rytoj 9:00" (when_lt()); other words stay as they are.
    return preg_replace_callback('/^(šiandien|rytoj|vakar)(?= \d{1,2}:\d{2}$|$)/u', fn ($m) => ['šiandien' => 'today', 'rytoj' => 'tomorrow', 'vakar' => 'yesterday'][$m[1]], $s);
}

// JavaScript string literals ('…' or "…") whose whole text has a translation.
function i18n_js(string $js): string
{
    return preg_replace_callback('/(["\'])((?:(?!\1)[^\\\\\n]|\\\\.)*)\1/u', function ($m) {
        $inner = $m[2];
        // Only texts people read: with a space, a Lithuanian letter or a capital at the start.
        // Code words ('click', 'nuolat', 'trumpai') never change.
        if (!preg_match('/\pL/u', $inner) || str_contains($inner, '\\u') || !preg_match('/\s|[ąčęėįšųūž]|^\s*\p{Lu}/iu', $inner)) {
            return $m[0];
        }
        $raw = stripcslashes($inner);
        if (str_contains($raw, '<')) {
            $en = i18n_html($raw);
            // Pieces of a tag ('… aria-label="Kitas mėnuo">→'): the attribute texts alone.
            $en = preg_replace_callback('/(aria-label|title|placeholder)="([^"<>]+)"/u', fn ($a) => $a[1].'="'.e(i18n_text(html_entity_decode($a[2], ENT_QUOTES, 'UTF-8')) ?? html_entity_decode($a[2], ENT_QUOTES, 'UTF-8')).'"', $en);
            return $en === $raw ? $m[0] : $m[1].addcslashes($en, '\\'.$m[1]."\n").$m[1];
        }
        $en = i18n_text($raw);
        i18n_miss($raw, $en);
        if ($en === null) {
            return $m[0];
        }
        // Keep the spaces around the text ("Liko simbolių: " + n).
        preg_match('/^\s*/u', $raw, $lead);
        preg_match('/\s*$/u', $raw, $trail);
        $en = $lead[0].$en.$trail[0];
        return $m[1].addcslashes($en, '\\'.$m[1]."\n").$m[1];
    }, $js) ?? $js;
}

// Translates a page or a piece of HTML: text between tags, the attributes people see or hear,
// and texts in scripts. JSON-LD (for search engines) stays as it is.
function i18n_html(string $html): string
{
    $parts = preg_split('/(<script\b[^>]*>.*?<\/script>|<style\b[^>]*>.*?<\/style>|<textarea\b[^>]*>.*?<\/textarea>|<!--.*?-->|<[^>]+>)/is', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($parts === false) {
        return $html;
    }
    $skip = 0;
    foreach ($parts as $i => $part) {
        if ($part === '') {
            continue;
        }
        if ($part[0] !== '<') {
            if ($skip > 0) {
                continue;
            }
            $text = html_entity_decode($part, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $en = i18n_text($text);
            i18n_miss($text, $en);
            if ($en !== null) {
                preg_match('/^[\s\x{00A0}]*/u', $text, $lead);
                preg_match('/[\s\x{00A0}]*$/u', $text, $trail);
                // An English text that starts with a full stop or comma goes right after the word before it.
                $lead[0] = preg_match('/^[.,)]/', $en) ? '' : $lead[0];
                $parts[$i] = htmlspecialchars($lead[0].$en.$trail[0], ENT_NOQUOTES, 'UTF-8');
            }
            continue;
        }
        if (preg_match('/^<script\b([^>]*)>(.*)<\/script>$/is', $part, $m)) {
            if (!str_contains($m[1], 'ld+json')) {
                $parts[$i] = '<script'.$m[1].'>'.i18n_js($m[2]).'</script>';
            }
            continue;
        }
        if (preg_match('/^<textarea\b/i', $part)) {
            // Only the placeholder: the text inside is what the member typed.
            $parts[$i] = preg_replace_callback('/^<textarea\b[^>]*>/i', fn ($t) => i18n_tag($t[0]), $part);
            continue;
        }
        if (str_starts_with($part, '<!--') || preg_match('/^<style\b/i', $part)) {
            continue;
        }
        // Elements marked data-i18n-skip (and what they hold) stay as they are: the language link,
        // texts members wrote.
        if (preg_match('/^<\/?([a-z0-9]+)/i', $part, $tag)) {
            $name = strtolower($tag[1]);
            $void = in_array($name, ['br', 'img', 'input', 'meta', 'link', 'hr', 'source', 'wbr'], true);
            if ($skip > 0) {
                if (!$void && $part[1] !== '/' && !str_ends_with($part, '/>')) {
                    $skip++;
                } elseif ($part[1] === '/') {
                    $skip--;
                }
                continue;
            }
            if (!$void && $part[1] !== '/' && str_contains($part, 'data-i18n-skip')) {
                $skip = 1;
                continue;
            }
        }
        $parts[$i] = i18n_tag($part);
    }
    return implode('', $parts);
}

// Attributes in one tag: placeholder, aria-label, title, alt, a button's value, meta texts,
// confirm() questions in onclick/onsubmit, and the page language itself.
function i18n_tag(string $tag): string
{
    if (preg_match('/^<html\b/i', $tag)) {
        return str_replace('lang="lt"', 'lang="en"', $tag);
    }
    if (preg_match('/^<meta\b/i', $tag)) {
        if (str_contains($tag, 'og:locale')) {
            return str_replace('lt_LT', 'en_GB', $tag);
        }
        if (!preg_match('/(name="description"|property="og:(title|description|image:alt)")/', $tag)) {
            return $tag;
        }
    }
    return preg_replace_callback('/\s(placeholder|aria-label|title|alt|content|value|label|data-confirm|data-label|data-empty|onclick|onsubmit|onchange)="([^"]*)"/i', function ($m) use ($tag) {
        $attr = strtolower($m[1]);
        if ($attr === 'value' && !preg_match('/^<input[^>]*type="(submit|button)"/i', $tag)) {
            return $m[0];
        }
        $val = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $en = str_starts_with($attr, 'on') ? i18n_js($val) : i18n_text($val);
        if (!str_starts_with($attr, 'on')) {
            i18n_miss($val, $en);
        }
        if ($en === null || $en === $val) {
            return $m[0];
        }
        return ' '.$m[1].'="'.htmlspecialchars($en, ENT_QUOTES, 'UTF-8').'"';
    }, $tag) ?? $tag;
}

// Local check: with GANG_I18N_LOG defined (in a local router or config), Lithuanian texts that were
// left untranslated are written to that file, one per line.
function i18n_miss(string $text, ?string $en): void
{
    if ($en !== null || !defined('GANG_I18N_LOG')) {
        return;
    }
    $key = i18n_key($text);
    if (preg_match('/\pL\pL/u', $key)) {
        file_put_contents(GANG_I18N_LOG, $key."\t".(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH))."\n", FILE_APPEND);
    }
}

// Emails: the language the recipient chose in their account ('lt' when not a member).
function mail_lang(string $to): string
{
    try {
        $lang = sql('SELECT lang FROM users WHERE email = ?', [mb_strtolower($to)])->fetchColumn();
    } catch (PDOException) {
        return 'lt';
    }
    return $lang === 'en' ? 'en' : 'lt';
}

// Translates an email built from parts (see mail.php). **bold** and [label](url) are kept; what
// members wrote (the note's text: a message, a job description) stays as written.
function i18n_mail(string $subject, array $mail): array
{
    $subject = i18n_text($subject) ?? i18n_dates($subject);
    foreach (['kicker', 'title', 'lead', 'body', 'after', 'footnote'] as $part) {
        if (isset($mail[$part])) {
            $mail[$part] = is_array($mail[$part]) ? array_map('i18n_markdown', $mail[$part]) : i18n_markdown((string) $mail[$part]);
        }
    }
    if (!empty($mail['facts'])) {
        $facts = [];
        foreach ($mail['facts'] as $k => $v) {
            $facts[i18n_text((string) $k) ?? (string) $k] = i18n_markdown((string) $v);
        }
        $mail['facts'] = $facts;
    }
    if (isset($mail['note']['label'])) {
        $mail['note']['label'] = i18n_text($mail['note']['label']) ?? $mail['note']['label'];
    }
    if (isset($mail['cta'][0])) {
        $mail['cta'][0] = i18n_text($mail['cta'][0]) ?? $mail['cta'][0];
    }
    return [$subject, $mail];
}

// Email text with **bold** and [label](url): the marks become tags for the translator and back.
function i18n_markdown(string $s): string
{
    if ($s === '') {
        return $s;
    }
    $links = [];
    $html = htmlspecialchars($s, ENT_NOQUOTES, 'UTF-8');
    $html = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/u', function ($m) use (&$links) {
        $links[] = $m[2];
        return '<a data-l="'.(count($links) - 1).'">'.$m[1].'</a>';
    }, $html);
    $html = i18n_html(preg_replace('/\*\*(.+?)\*\*/su', '<b>$1</b>', $html));
    $html = preg_replace_callback('/<a data-l="(\d+)">(.*?)<\/a>/su', fn ($m) => '['.$m[2].']('.$links[(int) $m[1]].')', $html);
    return html_entity_decode(preg_replace('/<b>(.*?)<\/b>/su', '**$1**', $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}
