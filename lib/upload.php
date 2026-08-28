<?php
/**
 * Tech4TIME — turning a file somebody chose into a picture this site will show.
 *
 * BACKEND ONLY. The frontend has no upload form and must never gain one.
 *
 * THE ONE RULE HERE: NOTHING THE BROWSER SENT IS EVER WRITTEN.
 *
 * An upload is not a file to be checked and then saved. It is untrusted input
 * to be *read* and then *replaced*. Every accepted picture is decoded by gd and
 * re-encoded from the pixel data, so what lands on disk is bytes that library
 * produced. That single step is what removes:
 *
 *   - EXIF, including GPS coordinates somebody did not know were in a photo
 *   - anything appended after the image data, which is how a polyglot is built
 *   - a file that is a valid JPEG *and* a valid PHP script or ZIP archive
 *   - colour profiles and comment blocks nobody asked for
 *
 * A validator that inspects and approves cannot do any of that: it can only
 * decide it did not find anything it knew to look for.
 *
 * WHAT IS ACCEPTED
 * JPEG, PNG and WebP, decided from the file's own header and never from its
 * name, its extension or the Content-Type the browser attached. No SVG: an SVG
 * is a document, it can carry script and external references, and re-encoding
 * does not make it not a document. No GIF or BMP, because nothing needs them
 * and a format nobody uses is an attack surface nobody watches.
 *
 * WHERE IT GOES
 * public/uploads/, under a name computed from the re-encoded bytes. This host
 * is the system of record (ADR 0010); the public site holds a replica, sent by
 * publish_asset() in lib/publish_client.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/publish.php';

/** Where the canonical copy of every uploaded picture lives. */
const UPLOAD_DIR = __DIR__ . '/../public/uploads';

/** The path the public site will serve it from. */
const UPLOAD_URL_ROOT = '/uploads/';

/** Bigger than this and it is refused before anything decodes it. */
const UPLOAD_MAX_BYTES = 5242880;

/**
 * The longest side a stored picture may have.
 *
 * A phone photograph is four thousand pixels across and is shown here at three
 * hundred. Storing the original costs the visitor the whole download for no
 * visible gain, and costs this account the disk. 1600 is comfortably above any
 * size the site displays, including on a 2x screen.
 */
const UPLOAD_MAX_DIMENSION = 1600;

/** Quality for the two lossy encoders. High enough that a logo stays crisp. */
const UPLOAD_WEBP_QUALITY = 82;
const UPLOAD_JPEG_QUALITY = 86;

/**
 * Why uploads cannot work right now, or '' if they can.
 *
 * Said plainly and early, the way auth_problem() is, because "the picture did
 * not appear" and "this server has no image library" are different problems
 * with different fixes, and only one of them is the operator's.
 */
function upload_problem(): string
{
    if (!extension_loaded('gd')) {
        return 'This server has no GD image library, so pictures cannot be '
             . 'accepted. Everything else on this page still works.';
    }

    foreach (['imagecreatefromstring', 'imagewebp', 'imagejpeg', 'imagepng'] as $fn) {
        if (!function_exists($fn)) {
            return 'This server\'s GD build is missing ' . $fn . '(), so pictures '
                 . 'cannot be accepted.';
        }
    }

    if (!is_dir(UPLOAD_DIR) && !@mkdir(UPLOAD_DIR, 0755, true) && !is_dir(UPLOAD_DIR)) {
        return 'The uploads directory does not exist and could not be created.';
    }

    if (!is_writable(UPLOAD_DIR)) {
        return 'The uploads directory is not writable by PHP.';
    }

    return '';
}

/**
 * Accept one uploaded picture: read it, replace it, store it.
 *
 * Returns the image record the content model wants — src, webp, width, height
 * — or a one-sentence error under 'error'. Never throws, and never leaves a
 * partial file behind.
 *
 * TWO FILES ARE WRITTEN, NOT ONE. A WebP, which is what nearly every visitor
 * will be served, and a fallback in the original raster family for the ones
 * that will not. Both come from the same decoded pixels, so they cannot
 * disagree about what the picture is.
 *
 * @param array $file one entry of $_FILES
 */
function upload_accept(array $file): array
{
    $problem = upload_problem();
    if ($problem !== '') {
        return ['error' => $problem];
    }

    $code = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($code !== UPLOAD_ERR_OK) {
        return ['error' => upload_error_reason($code)];
    }

    $tmp = (string)($file['tmp_name'] ?? '');

    /* The one thing that makes this an upload rather than any file on the
       server. Without it, a tmp_name of /etc/passwd would be read and
       published. */
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['error' => 'That did not arrive as an upload.'];
    }

    if ((int)($file['size'] ?? 0) > UPLOAD_MAX_BYTES) {
        return ['error' => 'That picture is larger than '
                         . (int)(UPLOAD_MAX_BYTES / 1048576) . ' MB.'];
    }

    $bytes = (string)@file_get_contents($tmp, false, null, 0, UPLOAD_MAX_BYTES + 1);
    if ($bytes === '' || strlen($bytes) > UPLOAD_MAX_BYTES) {
        return ['error' => 'That picture is larger than '
                         . (int)(UPLOAD_MAX_BYTES / 1048576) . ' MB.'];
    }

    return upload_store($bytes);
}

/**
 * Re-encode bytes and store the pair. Separated from upload_accept() so that
 * the part worth testing does not need a real HTTP upload to reach.
 */
function upload_store(string $bytes): array
{
    /* Decided from the header, never from a name or a Content-Type. */
    $kind = publish_asset_type($bytes);
    if ($kind === null) {
        return ['error' => 'That file is not a JPEG, PNG or WebP picture. '
                         . 'An SVG cannot be accepted: it is a document, not '
                         . 'an image, and can carry script.'];
    }

    [$ext] = $kind;

    /* The decode. Everything that was not pixel data stops existing here. */
    $image = @imagecreatefromstring($bytes);
    if ($image === false) {
        return ['error' => 'That picture could not be read. It may be damaged.'];
    }

    try {
        $image = upload_fit($image);

        /* Transparency survives the copy in upload_fit(); these tell the two
           encoders that can carry it to do so. */
        imagealphablending($image, false);
        imagesavealpha($image, true);

        $webp = upload_encode($image, 'webp');
        $raster = upload_encode($image, $ext === 'webp' ? 'png' : $ext);

        $width  = imagesx($image);
        $height = imagesy($image);
    } finally {
        imagedestroy($image);
    }

    if ($webp === null || $raster === null) {
        return ['error' => 'That picture could not be re-encoded.'];
    }

    if (strlen($webp) > PUBLISH_ASSET_MAX_BYTES
            || strlen($raster) > PUBLISH_ASSET_MAX_BYTES) {
        return ['error' => 'That picture is still too large after being '
                         . 'reduced. Try a smaller one.'];
    }

    $names = [
        'webp' => publish_asset_name($webp, 'webp'),
        'src'  => publish_asset_name($raster, $ext === 'webp' ? 'png' : $ext),
    ];

    foreach (['src' => $raster, 'webp' => $webp] as $which => $blob) {
        if (!upload_write($names[$which], $blob)) {
            return ['error' => 'The picture could not be saved on this server.'];
        }
    }

    return [
        'src'    => UPLOAD_URL_ROOT . $names['src'],
        'webp'   => UPLOAD_URL_ROOT . $names['webp'],
        'width'  => $width,
        'height' => $height,
    ];
}

/**
 * Scale to fit UPLOAD_MAX_DIMENSION, or return the image untouched.
 *
 * Aspect ratio is preserved, so the stored width and height are always the
 * real ones — which is what lets the page reserve the right box and keeps
 * Cumulative Layout Shift at zero.
 */
function upload_fit(GdImage $image): GdImage
{
    $w = imagesx($image);
    $h = imagesy($image);
    $longest = max($w, $h);

    if ($longest <= UPLOAD_MAX_DIMENSION) {
        return $image;
    }

    $scale = UPLOAD_MAX_DIMENSION / $longest;
    $scaled = imagescale($image, (int)round($w * $scale), (int)round($h * $scale));

    if ($scaled === false) {
        return $image;
    }

    imagedestroy($image);
    return $scaled;
}

/** One encoding, as bytes. */
function upload_encode(GdImage $image, string $ext): ?string
{
    ob_start();

    $ok = match ($ext) {
        'webp' => imagewebp($image, null, UPLOAD_WEBP_QUALITY),
        'jpg'  => imagejpeg($image, null, UPLOAD_JPEG_QUALITY),
        'png'  => imagepng($image, null, 8),
        default => false,
    };

    $bytes = (string)ob_get_clean();

    return ($ok && $bytes !== '') ? $bytes : null;
}

/** Write one file, atomically, unless it is already there byte for byte. */
function upload_write(string $name, string $bytes): bool
{
    $path = UPLOAD_DIR . '/' . $name;

    if (is_file($path) && hash_file('sha256', $path) === hash('sha256', $bytes)) {
        return true;
    }

    $tmp = UPLOAD_DIR . '/.' . bin2hex(random_bytes(8)) . '.tmp';

    if (@file_put_contents($tmp, $bytes) !== strlen($bytes) || !@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }

    @chmod($path, 0644);
    return true;
}

/** What PHP's own upload failure codes mean, for a person. */
function upload_error_reason(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
            'That picture is larger than this server accepts.',
        UPLOAD_ERR_PARTIAL   => 'The upload was cut off. Try again.',
        UPLOAD_ERR_NO_FILE   => 'No picture was chosen.',
        UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE =>
            'This server could not hold the upload while it was being read.',
        UPLOAD_ERR_EXTENSION => 'A server extension stopped the upload.',
        default              => 'The upload did not arrive.',
    };
}

/* --------------------------------------------------------------- house-keeping */

/** Every stored picture, by name. */
function upload_held(): array
{
    $found = [];
    foreach (glob(UPLOAD_DIR . '/*') ?: [] as $path) {
        $name = basename($path);
        if (is_file($path) && publish_asset_name_valid($name)) {
            $found[] = $name;
        }
    }
    sort($found);
    return $found;
}

/**
 * The stored pictures nothing in $used points at.
 *
 * $used is the list of web paths a document references — company_images().
 * Never swept automatically: a reference count taken from a document somebody
 * is halfway through editing is not a fact, and deleting on it would remove a
 * picture whose row is about to be saved.
 */
function upload_unused(array $used): array
{
    $referenced = [];
    foreach ($used as $path) {
        $referenced[basename((string)$path)] = true;
    }

    return array_values(array_filter(
        upload_held(),
        static fn(string $name): bool => !isset($referenced[$name])
    ));
}

/** Delete one stored picture, by name. Refuses any name it did not mint. */
function upload_delete(string $name): bool
{
    if (!publish_asset_name_valid($name)) {
        return false;
    }

    $path = UPLOAD_DIR . '/' . $name;

    return is_file($path) && @unlink($path);
}
