<?php

// Transactional emails in the site's look: dark card, lime accent, GANG logo, one clear button.
// Every email is described as parts and sent both as HTML and as a plain-text alternative.
//
// Parts (all optional):
//   kicker   small label above the heading, e.g. "Nauja kandidatūra"
//   title    big condensed heading, e.g. the role
//   lead     paragraph(s) after "Laba diena,"
//   facts    [label => value] rows
//   note     ['label' => ..., 'text' => ...] a framed block (a message, a job description)
//   code     a login code shown large
//   body     paragraph(s) before the button
//   cta      [label, url] the main button
//   after    paragraph(s) after the button
//   footnote small print: why the email was sent
// Paragraph text may use **bold** and [label](url).

function mail_paras(string|array|null $p): array
{
    return array_values(array_filter((array) $p, fn ($s) => trim((string) $s) !== ''));
}

function mail_inline_text(string $s): string
{
    $s = preg_replace('/\[([^\]]+)\]\((https?:[^)\s]+)\)/u', '$1 ($2)', $s);
    return str_replace('**', '', $s);
}

function mail_inline_html(string $s, string $link = '#f3f0e8'): string
{
    $s = htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $s = preg_replace('/\*\*(.+?)\*\*/u', '<strong style="color:#ffffff;font-weight:700">$1</strong>', $s);
    $s = preg_replace('/\[([^\]]+)\]\((https?:[^)\s]+)\)/u', '<a href="$2" style="color:'.$link.';text-decoration:underline">$1</a>', $s);
    return nl2br($s);
}

function mail_text(array $m): string
{
    $out = ['Laba diena,'];
    foreach (mail_paras($m['lead'] ?? null) as $p) {
        $out[] = mail_inline_text($p);
    }
    if (!empty($m['code'])) {
        $out[] = $m['code'];
    }
    if (!empty($m['facts'])) {
        $out[] = implode("\n", array_map(fn ($k, $v) => "$k: ".mail_inline_text((string) $v), array_keys($m['facts']), $m['facts']));
    }
    if (!empty($m['note']['text'])) {
        $out[] = $m['note']['label'].":\n".$m['note']['text'];
    }
    foreach (mail_paras($m['body'] ?? null) as $p) {
        $out[] = mail_inline_text($p);
    }
    if (!empty($m['cta'])) {
        $out[] = $m['cta'][0].":\n".$m['cta'][1];
    }
    foreach (mail_paras($m['after'] ?? null) as $p) {
        $out[] = mail_inline_text($p);
    }
    $out[] = "Pagarbiai\nGANG komanda";
    if (!empty($m['footnote'])) {
        $out[] = mail_inline_text($m['footnote']);
    }
    $out[] = "--\nSuburk savo komandą GANG.LT\n".url('/');
    return implode("\n\n", $out);
}

function mail_html(array $m, string $subject): string
{
    $font = "'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
    $display = "'Anton','Bebas Neue',Impact,'Arial Narrow Bold','Helvetica Neue',Arial,sans-serif";
    $p = fn (string $s, string $extra = '') => '<p style="margin:0 0 16px;font-family:'.$font.';font-size:16px;line-height:1.6;color:#e9e6de;'.$extra.'">'.mail_inline_html($s).'</p>';
    $e = fn (string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

    $preheader = mail_inline_text(mail_paras($m['lead'] ?? null)[0] ?? $subject);
    $rows = '';
    if (!empty($m['kicker'])) {
        $rows .= '<p style="margin:0 0 10px;font-family:'.$font.';font-size:12px;line-height:1.4;letter-spacing:3px;text-transform:uppercase;color:#d8ff36;font-weight:700">'.$e($m['kicker']).'</p>';
    }
    if (!empty($m['title'])) {
        $rows .= '<h1 style="margin:0 0 24px;font-family:'.$display.';font-size:40px;line-height:1.18;font-weight:400;text-transform:uppercase;letter-spacing:.5px;color:#f3f0e8">'.$e($m['title']).'</h1>';
    }
    $rows .= $p('Laba diena,');
    foreach (mail_paras($m['lead'] ?? null) as $s) {
        $rows .= $p($s);
    }
    if (!empty($m['code'])) {
        $rows .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 24px"><tr>'
            .'<td style="background:#262522;border:1px solid #4a4740;border-radius:10px;padding:16px 16px 16px 26px;font-family:Menlo,Consolas,\'Courier New\',monospace;font-size:34px;line-height:1;letter-spacing:10px;font-weight:700;color:#d8ff36">'.$e($m['code']).'</td>'
            .'</tr></table>';
    }
    if (!empty($m['facts'])) {
        $facts = '';
        $last = array_key_last($m['facts']);
        foreach ($m['facts'] as $k => $v) {
            $border = $k === $last ? '' : 'border-bottom:1px solid #34322e;';
            $facts .= '<tr><td valign="top" style="'.$border.'padding:12px 16px 12px 0;width:38%;font-family:'.$font.';font-size:13px;line-height:1.5;color:#a39f95">'.$e((string) $k).'</td>'
                .'<td valign="top" style="'.$border.'padding:12px 0;font-family:'.$font.';font-size:15px;line-height:1.5;color:#f3f0e8;font-weight:600">'.mail_inline_html((string) $v).'</td></tr>';
        }
        $rows .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:4px 0 24px;border-top:2px solid #d8ff36">'.$facts.'</table>';
    }
    if (!empty($m['note']['text'])) {
        $rows .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px"><tr>'
            .'<td style="background:#262522;border-left:3px solid #d8ff36;border-radius:0 8px 8px 0;padding:14px 18px">'
            .'<p style="margin:0 0 6px;font-family:'.$font.';font-size:12px;line-height:1.4;letter-spacing:2px;text-transform:uppercase;color:#a39f95">'.$e($m['note']['label']).'</p>'
            .'<p style="margin:0;font-family:'.$font.';font-size:15px;line-height:1.6;color:#f3f0e8">'.nl2br($e($m['note']['text'])).'</p>'
            .'</td></tr></table>';
    }
    foreach (mail_paras($m['body'] ?? null) as $s) {
        $rows .= $p($s);
    }
    if (!empty($m['cta'])) {
        [$label, $href] = $m['cta'];
        $rows .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 28px"><tr>'
            .'<td bgcolor="#d8ff36" style="background:#d8ff36;border-radius:8px">'
            .'<a href="'.$e($href).'" style="display:inline-block;padding:15px 28px;font-family:'.$font.';font-size:16px;line-height:1.2;font-weight:700;color:#151515;text-decoration:none;border-radius:8px">'.$e($label).' &rarr;</a>'
            .'</td></tr></table>';
    }
    foreach (mail_paras($m['after'] ?? null) as $s) {
        $rows .= $p($s);
    }
    $rows .= $p("Pagarbiai\n**GANG komanda**", 'margin-bottom:0');

    $foot = !empty($m['footnote'])
        ? '<p style="margin:0 0 12px;font-family:'.$font.';font-size:12px;line-height:1.6;color:#8a867d">'.mail_inline_html($m['footnote'], '#a39f95').'</p>'
        : '';
    $home = url('/');
    $logo = url('/img/mail-logo.png');

    return '<!doctype html><html lang="lt"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        .'<meta name="color-scheme" content="dark light"><meta name="supported-color-schemes" content="dark light"><title>'.$e($subject).'</title>'
        .'<style>@font-face{font-family:Anton;src:url('.$e(url('/fonts/anton-latin-ext.woff2')).') format("woff2");unicode-range:U+0100-02BA}'
        .'@font-face{font-family:Anton;src:url('.$e(url('/fonts/anton-latin.woff2')).') format("woff2");unicode-range:U+0000-00FF,U+2000-206F}'
        .'@media (max-width:620px){.gang-pad{padding-left:22px!important;padding-right:22px!important}.gang-h1{font-size:32px!important}}</style></head>'
        .'<body style="margin:0;padding:0;background:#0e0e0e;-webkit-text-size-adjust:100%">'
        .'<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:#0e0e0e">'.$e(mb_substr($preheader, 0, 140)).'</div>'
        .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#0e0e0e" style="background:#0e0e0e"><tr><td align="center" style="padding:28px 12px">'
        .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px">'
        // Header: logo on the site background.
        .'<tr><td bgcolor="#171717" style="background:#171717;border:1px solid #34322e;border-bottom:0;border-radius:14px 14px 0 0;padding:20px 40px 16px" class="gang-pad">'
        .'<a href="'.$e($home).'" style="text-decoration:none"><img src="'.$e($logo).'" width="150" height="56" alt="GANG.lt" style="display:block;border:0;width:150px;height:auto;color:#d8ff36;font-family:'.$display.';font-size:28px"></a></td></tr>'
        .'<tr><td bgcolor="#d8ff36" style="background:#d8ff36;height:4px;line-height:4px;font-size:0">&nbsp;</td></tr>'
        // Card.
        .'<tr><td bgcolor="#1e1d1b" style="background:#1e1d1b;border:1px solid #34322e;border-top:0;border-radius:0 0 14px 14px;padding:36px 40px 34px" class="gang-pad">'
        .str_replace('<h1 style=', '<h1 class="gang-h1" style=', $rows)
        .'</td></tr>'
        // Footer.
        .'<tr><td style="padding:22px 40px 0" class="gang-pad">'.$foot
        .'<p style="margin:0;font-family:'.$font.';font-size:12px;line-height:1.6;color:#8a867d">Suburk savo komandą <a href="'.$e($home).'" style="color:#d8ff36;text-decoration:none;font-weight:700">GANG.LT</a></p>'
        .'</td></tr>'
        .'</table></td></tr></table></body></html>';
}
