<?php
/**
 * Tech4TIME — contact page data access.
 *
 * Reading and writing the file is lib/store.php; escaping and rich-text
 * sanitising is lib/html.php; the SHAPE of the contact page is
 * lib/contract.php, which the frontend and the backend hold byte-identical.
 * What is left here is this side's own business with that shape.
 *
 *   backend    validation, the flag picker, and the save that publishes
 *   frontend   the ContactPage structured data, the flag <picture>, and how a
 *              reach row turns into a link
 *
 * WHAT THE SHAPE IS
 *   {
 *     "updated":        set on every save
 *     "revision":       monotonic; see contract.php
 *     "footer_synced":  fingerprint of the details as last written into the
 *                       site-wide footer — see contact_fingerprint()
 *     "meta":    { title, description, share_title }
 *     "hero":    { title, subtitle }
 *     "form":    { title, lead, subject_hint, note, service_types[] }
 *     "reach":   { title, items[ { icon, label, type, values[], text } ] }
 *     "offices": { eyebrow, title, lead, items[ { name, flag, address,
 *                  phones[], hours, languages[], status, schema{} } ] }
 *   }
 */

declare(strict_types=1);

require_once __DIR__ . '/contract.php';
require_once __DIR__ . '/store.php';

const CONTACT_FILE = __DIR__ . '/../content/contact.json';
const CONTACT_FLAG_DIR = __DIR__ . '/../assets/images/flags';

/* Raster formats a flag may be supplied in. A matching .webp beside it is used
   automatically when it exists; there is no build step on the host, so one
   dropped into the folder by hand has to work on its own. */
const CONTACT_FLAG_FORMATS = ['jpg', 'jpeg', 'png'];

/* ------------------------------------------------------------------- read */

/**
 * Load the file, or the shipped defaults if it is missing or unreadable.
 *
 * Never throws. A contact page showing the addresses it was deployed with is
 * wrong only if they have since changed; a contact page showing a PHP error
 * gives a visitor no way to reach anyone at all.
 */
function contact_load(): array
{
    return contact_normalise(store_read(CONTACT_FILE) ?? []);
}

/* ------------------------------------------------------------------ write */

/**
 * Stamp the save time, take the next revision, and hand the file to
 * store_write().
 *
 * The revision is minted here rather than by the caller because every write
 * must have one — a save that forgot to advance it is a save the live site
 * will refuse as stale, and it would refuse it silently from the operator's
 * point of view.
 */
function contact_save(array $data): bool
{
    $data['updated']  = gmdate('c');
    $data['revision'] = contract_next_revision($data);

    return store_write(CONTACT_FILE, $data);
}

/* -------------------------------------------------------------- rendering */

/**
 * The href for one of a row's values, or null when it is not a link.
 *
 * tel: wants the number without the spaces a human reads it by, and dialling
 * is the whole point of the link — so the separators come out here while the
 * value stays written the way it should be shown.
 */
function contact_reach_href(array $item, string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    switch ($item['type'] ?? 'text') {
        case 'email':
            return filter_var($value, FILTER_VALIDATE_EMAIL) ? 'mailto:' . $value : null;
        case 'phone':
            return 'tel:' . contact_tel($value);
        case 'url':
            return rt_safe_href($value);
        default:
            return null;
    }
}

/**
 * What one of a row's values reads as.
 *
 * The row's own link text stands in for the value, but only when the row has
 * a single value: three numbers all reading "Tech4TIME" would be three links
 * nobody can tell apart.
 */
function contact_reach_text(array $item, string $value): string
{
    $text = trim((string)($item['text'] ?? ''));
    return ($text !== '' && count($item['values'] ?? []) === 1) ? $text : trim($value);
}

/** The flag images available to choose from, by basename. */
function contact_flags(): array
{
    $found = [];
    foreach (CONTACT_FLAG_FORMATS as $ext) {
        foreach (glob(CONTACT_FLAG_DIR . '/*.' . $ext) ?: [] as $path) {
            $found[pathinfo($path, PATHINFO_FILENAME)] = true;
        }
    }
    ksort($found);
    return array_keys($found);
}

/**
 * The <picture> for one office's flag, or '' when it has none.
 *
 * width and height come from the file itself rather than from the data,
 * because they are the file's business and because a flag added by hand has
 * nobody to type them in. They are not decoration: without them the office
 * cards jump as each image arrives.
 *
 * The .webp source is emitted only when the file is actually there. There is
 * no build step on the host to make one.
 */
function contact_flag_picture(array $office): string
{
    $flag = trim((string)($office['flag'] ?? ''));
    if ($flag === '' || !preg_match('/^[a-z0-9-]+$/', $flag)) {
        return '';
    }

    $raster = '';
    foreach (CONTACT_FLAG_FORMATS as $ext) {
        if (is_file(CONTACT_FLAG_DIR . '/' . $flag . '.' . $ext)) {
            $raster = $flag . '.' . $ext;
            break;
        }
    }
    if ($raster === '') {
        return '';
    }

    $size = @getimagesize(CONTACT_FLAG_DIR . '/' . $raster);
    $dimensions = $size
        ? ' width="' . (int)$size[0] . '" height="' . (int)$size[1] . '"'
        : '';

    $alt = 'Flag of ' . trim((string)($office['name'] ?? ''));

    $webp = is_file(CONTACT_FLAG_DIR . '/' . $flag . '.webp')
        ? '<source srcset="/assets/images/flags/' . h($flag) . '.webp" type="image/webp">'
        : '';

    return '<picture class="office__flag-wrap">' . $webp
         . '<img class="office__flag" src="/assets/images/flags/' . h($raster) . '"'
         . ' alt="' . h($alt) . '"' . $dimensions
         . ' loading="lazy" decoding="async"></picture>';
}

/* -------------------------------------------------------- structured data */

/** One schema.org PostalAddress per shown office that has enough to make one. */
function contact_addresses(array $data): array
{
    $out = [];
    foreach (contact_shown_offices($data) as $office) {
        $s = $office['schema'];
        $address = array_filter([
            '@type'           => 'PostalAddress',
            'streetAddress'   => trim((string)$s['street']),
            'addressLocality' => trim((string)$s['locality']),
            'addressRegion'   => trim((string)$s['region']),
            'postalCode'      => trim((string)$s['postal_code']),
            'addressCountry'  => strtoupper(trim((string)$s['country'])),
        ], static fn(string $v): bool => $v !== '');

        /* A country on its own is not an address anyone can post to. */
        if (count($address) > 2) {
            $out[] = $address;
        }
    }
    return $out;
}

/**
 * One schema.org ContactPoint per shown office that has a phone.
 *
 * Per office rather than one for the company, because areaServed is the field
 * that makes a number useful to a search engine, and it is only true of the
 * office the number rings.
 */
function contact_points(array $data): array
{
    $email = contact_email($data);
    $out = [];

    foreach (contact_shown_offices($data) as $office) {
        if (!$office['phones']) {
            continue;
        }
        $point = [
            '@type'       => 'ContactPoint',
            'telephone'   => contact_tel((string)$office['phones'][0]),
            'contactType' => 'customer service',
        ];
        if ($email !== '') {
            $point['email'] = $email;
        }
        $country = strtoupper(trim((string)$office['schema']['country']));
        if ($country !== '') {
            $point['areaServed'] = $country;
        }
        $point['availableLanguage'] = $office['languages'] ?: ['English'];
        $out[] = $point;
    }

    return $out;
}

/** The ContactPage graph for this page. */
function contact_page_schema(array $data): array
{
    $entity = array_filter([
        '@type' => 'Organization',
        'name'  => 'Tech4TIME',
        'url'   => 'https://tech4time.bd/',
        'email' => contact_email($data),
    ], static fn($v): bool => $v !== '');

    $points = contact_points($data);
    if ($points) {
        $entity['contactPoint'] = $points;
    }
    $addresses = contact_addresses($data);
    if ($addresses) {
        $entity['address'] = $addresses;
    }

    return [
        '@context'   => 'https://schema.org',
        '@type'      => 'ContactPage',
        'url'        => 'https://tech4time.bd/pages/contact/',
        'name'       => 'Contact Tech4TIME',
        'mainEntity' => $entity,
    ];
}

/* ------------------------------------------------------------- validation */

/**
 * Validate the whole document. Returns a list of human-readable problems.
 *
 * Deliberately permissive about prose: an empty lead is a plainer page, not an
 * invalid one. What it does insist on is anything that would render as a
 * broken link or an address a search engine will reject, because those fail
 * silently rather than visibly.
 */
function contact_validate(array $data): array
{
    $errors = [];

    if (trim((string)$data['hero']['title']) === '') {
        $errors[] = 'The page title in the banner is required.';
    }
    if (trim((string)$data['meta']['title']) === '') {
        $errors[] = 'The browser tab title is required.';
    }
    if (strlen(trim((string)$data['meta']['description'])) > 320) {
        $errors[] = 'The search description is longer than 320 characters; Google will cut it off.';
    }

    foreach ($data['reach']['items'] as $i => $item) {
        $where = 'Reach row ' . ($i + 1);
        $type = (string)($item['type'] ?? '');

        if (!isset(CONTACT_REACH_TYPES[$type])) {
            $errors[] = "$where has no valid kind.";
        }
        if (trim((string)($item['label'] ?? '')) === '') {
            $errors[] = "$where needs a label.";
        }
        if (($item['icon'] ?? '') !== '' && !isset(CONTACT_ICONS[$item['icon']])) {
            $errors[] = "$where has an icon that is not in the list.";
        }
        if (!$item['values']) {
            $errors[] = "$where needs at least one value.";
            continue;
        }

        /* Every line, not just the first: a row of four numbers with a typo in
           the third is a row with a dead link in it. */
        foreach ($item['values'] as $value) {
            if ($type === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "$where is marked as an email address but “$value” is not one.";
            }
            if ($type === 'url' && rt_safe_href($value) === null) {
                $errors[] = "$where: “$value” must be a full web address starting with https://";
            }
        }
    }

    foreach ($data['offices']['items'] as $i => $office) {
        $where = 'Office ' . ($i + 1);
        if (trim((string)$office['name']) === '') {
            $errors[] = "$where needs a name.";
        }
        $country = trim((string)$office['schema']['country']);
        if ($country !== '' && !preg_match('/^[A-Za-z]{2}$/', $country)) {
            $errors[] = "$where: the country code must be two letters, like BD or MY.";
        }
        if (!in_array($office['status'], ['shown', 'hidden'], true)) {
            $errors[] = "$where must be either shown or hidden.";
        }
    }

    return $errors;
}
