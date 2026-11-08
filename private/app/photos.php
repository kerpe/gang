<?php

declare(strict_types=1);

const PHOTO_MAX_MB = 10;
const PHOTO_SIZE = 600;

// Photos live in private/data/photos (outside the web root, kept across deploys)
// and are served by photo_page() to the same people who may see the worker profile (or talk to the employer).
function photo_dir(): string
{
    return private_root().'/data/photos';
}

function photo_url(array $u): string
{
    return $u['photo'] ? '/nuotrauka/'.(int) $u['id'].'?v='.substr($u['photo'], 0, 8) : '';
}

function avatar(array $u, string $class = ''): string
{
    $name = trim((string) $u['name']);
    if (!empty($u['photo'])) {
        return '<img class="avatar '.$class.'" src="'.e(photo_url($u)).'" alt="'.e(first_name($name)).'" width="96" height="96" loading="lazy">';
    }
    $initials = '';
    foreach (array_slice(preg_split('/\s+/', $name) ?: [], 0, 2) as $part) {
        $initials .= mb_strtoupper(mb_substr($part, 0, 1));
    }
    return '<span class="avatar none '.$class.'" aria-hidden="true">'.e($initials ?: '?').'</span>';
}

// Validates an upload, crops it to a square and re-encodes it as JPEG (which also drops EXIF and location data).
// With $fit (logos) the whole image is kept and centred on white instead of being cropped.
// Returns the new file name, or an error message prefixed with "!".
function store_photo(array $file, bool $fit = false): string
{
    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE || $file['size'] > PHOTO_MAX_MB * 1024 * 1024) {
        return '!Nuotrauka per didelė. Įkelkite iki '.PHOTO_MAX_MB.' MB dydžio failą.';
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        return '!Nuotraukos įkelti nepavyko. Bandykite dar kartą.';
    }
    $info = @getimagesize($file['tmp_name']);
    $src = match ($info[2] ?? null) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($file['tmp_name']),
        IMAGETYPE_PNG => @imagecreatefrompng($file['tmp_name']),
        IMAGETYPE_WEBP => @imagecreatefromwebp($file['tmp_name']),
        default => false,
    };
    if (!$src) {
        return '!Įkelkite JPG, PNG arba WEBP formato nuotrauką.';
    }
    // Phone cameras store rotation in EXIF instead of turning the pixels.
    if (($info[2] ?? null) === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $angle = [3 => 180, 6 => -90, 8 => 90][(int) (@exif_read_data($file['tmp_name'])['Orientation'] ?? 1)] ?? 0;
        if ($angle) {
            $src = imagerotate($src, $angle, 0);
        }
    }
    $w = imagesx($src);
    $h = imagesy($src);
    if (($fit ? max($w, $h) : min($w, $h)) < 200) {
        return '!Nuotrauka per maža. Įkelkite bent 200 × 200 taškų nuotrauką.';
    }
    $out = imagecreatetruecolor(PHOTO_SIZE, PHOTO_SIZE);
    imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
    if ($fit) {
        $k = PHOTO_SIZE / max($w, $h);
        $dw = (int) round($w * $k);
        $dh = (int) round($h * $k);
        imagecopyresampled($out, $src, intdiv(PHOTO_SIZE - $dw, 2), intdiv(PHOTO_SIZE - $dh, 2), 0, 0, $dw, $dh, $w, $h);
    } else {
        $side = min($w, $h);
        // Portraits are cropped a little above centre so the face stays in frame.
        imagecopyresampled($out, $src, 0, 0, intdiv($w - $side, 2), $h > $w ? intdiv($h - $side, 4) : 0, PHOTO_SIZE, PHOTO_SIZE, $side, $side);
    }
    @mkdir(photo_dir(), 0700, true);
    $name = bin2hex(random_bytes(12)).'.jpg';
    if (!imagejpeg($out, photo_dir().'/'.$name, 85)) {
        return '!Nuotraukos išsaugoti nepavyko. Bandykite dar kartą.';
    }
    return $name;
}

function delete_photo(?string $name): void
{
    if ($name && preg_match('/^[0-9a-f]{24}\.jpg$/', $name)) {
        @unlink(photo_dir().'/'.$name);
    }
}

function photo_page(int $id): never
{
    $u = is_admin() ? current_user() : require_user();
    $w = sql('SELECT id, kind, photo FROM users WHERE id = ?', [$id])->fetch();
    // An employer's photo is seen by the workers the employer talks to: candidates, past workers and the team.
    $allowed = $w && ($w['kind'] === 'worker' ? sees_worker_profile($u, $id)
        : is_admin() || ($u && ((int) $u['id'] === $id || ($u['kind'] === 'worker' && manager_knows_worker($id, (int) $u['id'])))));
    $file = $w && $w['photo'] && preg_match('/^[0-9a-f]{24}\.jpg$/', $w['photo']) ? photo_dir().'/'.$w['photo'] : '';
    if (!$allowed || $file === '' || !is_file($file)) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: image/jpeg');
    header('Content-Length: '.filesize($file));
    header('Cache-Control: private, max-age=2592000');
    header('X-Content-Type-Options: nosniff');
    readfile($file);
    exit;
}

// Venue logos are not personal data: anyone may load them (job pages are public).
function venue_logo_url(array $v): string
{
    return !empty($v['logo']) ? '/darboviete/'.(int) $v['id'].'/logotipas?v='.substr($v['logo'], 0, 8) : '';
}

// The venue's logo, or its initials in the same rounded square.
function venue_logo(array $v, string $class = ''): string
{
    if (!empty($v['logo'])) {
        return '<img class="avatar venue-logo '.$class.'" src="'.e(venue_logo_url($v)).'" alt="'.e($v['name']).'" width="96" height="96" loading="lazy">';
    }
    $initials = '';
    foreach (array_slice(preg_split('/\s+/', preg_replace('/[^\p{L}\p{N}\s]/u', '', (string) $v['name'])) ?: [], 0, 2) as $part) {
        $initials .= mb_strtoupper(mb_substr($part, 0, 1));
    }
    return '<span class="avatar venue-logo none '.$class.'" aria-hidden="true">'.e($initials ?: 'G').'</span>';
}

function venue_logo_page(int $id): never
{
    $logo = (string) sql('SELECT logo FROM venues WHERE id = ?', [$id])->fetchColumn();
    $file = preg_match('/^[0-9a-f]{24}\.jpg$/', $logo) ? photo_dir().'/'.$logo : '';
    if ($file === '' || !is_file($file)) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: image/jpeg');
    header('Content-Length: '.filesize($file));
    header('Cache-Control: public, max-age=2592000');
    header('X-Content-Type-Options: nosniff');
    readfile($file);
    exit;
}
