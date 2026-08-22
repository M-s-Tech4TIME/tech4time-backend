<?php
/**
 * Tech4TIME — contact page data access.
 *
 * Shared by the public page (pages/contact/index.php) and the admin editor
 * (admin/sections/contact.php). Not reachable over HTTP: .htaccess forbids
 * /lib/.
 *
 * Reading and writing the file is lib/store.php; escaping and rich-text
 * sanitising is lib/html.php. What is left here is the shape of the contact
 * page, and the two derived things only this file knows how to build: the
 * ContactPage structured data, and the fingerprint that says whether the site
 * footer is still telling visitors the same phone number.
 *
 * WHAT THE SHAPE IS
 *   {
 *     "updated":        set on every save
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

require_once __DIR__ . '/html.php';
require_once __DIR__ . '/store.php';

const CONTACT_FILE = __DIR__ . '/../content/contact.json';
const CONTACT_FLAG_DIR = __DIR__ . '/../assets/images/flags';

/* Raster formats a flag may be supplied in. A matching .webp beside it is used
   automatically when it exists; there is no build step on the host, so one
   dropped into the folder by hand has to work on its own. */
const CONTACT_FLAG_FORMATS = ['jpg', 'jpeg', 'png'];

/**
 * How a "Reach Us Directly" row turns each of its values into a link.
 *
 * text is the one that deliberately makes no link — "Within one working day"
 * is a fact, not a destination.
 */
const CONTACT_REACH_TYPES = [
    'email' => 'Email address',
    'phone' => 'Phone number',
    'url'   => 'Web address',
    'text'  => 'Plain text (no link)',
];

/**
 * The icons a reach row may use.
 *
 * A fixed list rather than the whole sprite, for a reason that is easy to
 * miss: tools/inject_icons.py inlines the symbols a page references by
 * scanning it for literal href="#name". A name chosen at run time is invisible
 * to that scan, so every icon offered here is also listed in a comment in
 * pages/contact/index.php where the scanner can see it. Adding one here means
 * adding it there too, and inject_icons.py --check will say so if it is
 * forgotten.
 */
const CONTACT_ICONS = [
    'envelope'        => 'Envelope',
    'phone'           => 'Phone',
    'mobile-alt'      => 'Mobile',
    'clock'           => 'Clock',
    'map-marker-alt'  => 'Map pin',
    'building'        => 'Building',
    'globe'           => 'Globe',
    'headset'         => 'Headset',
    'comment-alt'     => 'Speech bubble',
    'paper-plane'     => 'Paper plane',
    'calendar-alt'    => 'Calendar',
    'info-circle'     => 'Information',
    'linkedin'        => 'LinkedIn',
    'github'          => 'GitHub',
];

/* Free-text single-line fields, by section. */
const CONTACT_TEXT_FIELDS = [
    'meta'    => ['title', 'description', 'share_title'],
    'hero'    => ['title', 'subtitle'],
    'form'    => ['title', 'subject_hint', 'note'],
    'reach'   => ['title'],
    'offices' => ['eyebrow', 'title'],
];

/* Fields stored as sanitised HTML, so they can carry a link or emphasis. */
const CONTACT_RICH_FIELDS = [
    'form'    => ['lead'],
    'offices' => ['lead'],
];

/* ------------------------------------------------------------------- read */

/**
 * The page as it ships, and the fallback for anything missing from the file.
 *
 * Every key the renderer reads exists here, so a truncated or hand-edited
 * contact.json degrades to the shipped copy field by field rather than
 * emptying the page.
 */
function contact_defaults(): array
{
    return [
        'updated'       => '',
        'footer_synced' => '',
        'meta' => [
            'title'       => 'Contact Us | Tech4TIME',
            'description' => 'Get in touch with Tech4TIME.',
            'share_title' => 'Ask for a quote or just contact us',
        ],
        'hero' => [
            'title'    => 'Contact Us',
            'subtitle' => 'Ask for a quote, or just get in touch',
        ],
        'form' => [
            'title'         => 'Ask for a quote or just contact us',
            'lead'          => '',
            'subject_hint'  => 'Pick one of ours or describe your own.',
            'note'          => 'Sent over an encrypted connection and used only to answer your enquiry.',
            'service_types' => [],
        ],
        'reach' => [
            'title' => 'Reach Us Directly',
            'items' => [],
        ],
        'offices' => [
            'eyebrow' => 'Where We Are',
            'title'   => 'Our Offices',
            'lead'    => '',
            'items'   => [],
        ],
    ];
}

/**
 * Load the file, or the shipped defaults if it is missing or unreadable.
 *
 * Never throws. A contact page showing the addresses it was deployed with is
 * wrong only if they have since changed; a contact page showing a PHP error
 * gives a visitor no way to reach anyone at all.
 */
function contact_load(): array
{
    $data = store_read(CONTACT_FILE) ?? [];
    $defaults = contact_defaults();

    /* One level of merge per section, which is all the shape has: scalars fall
       back individually, lists are taken whole or not at all. */
    foreach ($defaults as $key => $value) {
        if (!is_array($value)) {
            $data[$key] = is_string($data[$key] ?? null) ? $data[$key] : $value;
            continue;
        }
        $data[$key] = is_array($data[$key] ?? null) ? $data[$key] + $value : $value;
    }

    $data['form']['service_types'] = contact_string_list($data['form']['service_types'] ?? []);
    $data['reach']['items'] = array_map(
        'contact_reach_defaults',
        array_values(array_filter(
            is_array($data['reach']['items']) ? $data['reach']['items'] : [],
            'is_array'
        ))
    );
    $data['offices']['items'] = array_map(
        'contact_office_defaults',
        array_values(array_filter(
            is_array($data['offices']['items']) ? $data['offices']['items'] : [],
            'is_array'
        ))
    );

    return $data;
}

/**
 * Fill in a reach row, whatever it arrived with.
 *
 * A row holds a LIST of values, so that three numbers can sit under one
 * "Phone" heading rather than as three rows each headed "Phone" — which is
 * how the office cards already read, and the two should not disagree.
 *
 * Rows were a single "value" before that, so one is migrated here rather than
 * by a script: an older contact.json restored by hand still loads. Idempotent,
 * and a row that already has a list is left exactly as it is.
 */
function contact_reach_defaults(array $item): array
{
    $item += [
        'icon'   => '',
        'label'  => '',
        'type'   => 'text',
        'values' => [],
        'text'   => '',
    ];

    if (!$item['values'] && isset($item['value'])) {
        $item['values'] = [(string)$item['value']];
    }
    unset($item['value']);

    $item['values'] = contact_string_list($item['values']);

    return $item;
}

/** Fill in an office record, whatever it arrived with. */
function contact_office_defaults(array $office): array
{
    $office += [
        'id'      => '',
        'name'    => '',
        'flag'    => '',
        'address' => '',
        'phones'  => [],
        'hours'   => '',
        'status'  => 'shown',
        'languages' => [],
    ];

    $office['phones'] = contact_string_list($office['phones']);
    $office['languages'] = contact_string_list($office['languages']);
    $office['schema'] = (is_array($office['schema'] ?? null) ? $office['schema'] : []) + [
        'street'      => '',
        'locality'    => '',
        'region'      => '',
        'postal_code' => '',
        'country'     => '',
    ];

    if ($office['id'] === '') {
        $office['id'] = contact_slug($office['name']);
    }

    return $office;
}

/** Trim a list of strings and drop the blanks, whatever shape it arrived in. */
function contact_string_list(mixed $value): array
{
    if (is_string($value)) {
        $value = preg_split('/\r\n|\r|\n/', $value) ?: [];
    }
    if (!is_array($value)) {
        return [];
    }

    return array_values(array_filter(
        array_map(static fn($v): string => trim((string)$v), $value),
        static fn(string $v): bool => $v !== ''
    ));
}

/** Only the offices a visitor should see. */
function contact_shown_offices(array $data): array
{
    return array_values(array_filter(
        $data['offices']['items'],
        static fn(array $o): bool => ($o['status'] ?? 'shown') === 'shown'
    ));
}

function contact_find_office(array $data, string $id): ?array
{
    foreach ($data['offices']['items'] as $office) {
        if (($office['id'] ?? '') === $id) {
            return $office;
        }
    }
    return null;
}

/** A URL-safe id from a name, unique against the ids already in use. */
function contact_slug(string $name, array $taken = []): string
{
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-') ?: 'office';

    $base = $slug;
    $n = 2;
    while (in_array($slug, $taken, true)) {
        $slug = $base . '-' . $n++;
    }
    return $slug;
}

/* ------------------------------------------------------------------ write */

/** Stamp the save time and hand the file to store_write(). */
function contact_save(array $data): bool
{
    $data['updated'] = gmdate('c');

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

/** A phone number as a dialler wants it: digits, and a leading + if it had one. */
function contact_tel(string $number): string
{
    $digits = preg_replace('/[^0-9]/', '', $number) ?? '';
    return (str_starts_with(trim($number), '+') ? '+' : '') . $digits;
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

/** The email address the page publishes, taken from the reach rows. */
function contact_email(array $data): string
{
    foreach ($data['reach']['items'] as $item) {
        if (($item['type'] ?? '') === 'email' && $item['values']) {
            return trim((string)$item['values'][0]);
        }
    }
    return '';
}

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

/* ------------------------------------------------------------ footer drift

   The same email, phone numbers, addresses and opening hours appear in the
   site footer, which is pasted into every page as literal markup — the project
   forbids runtime partials, so there is no include to point at contact.json.

   This page updates the moment it is saved; the footer does not, and cannot,
   until the pages are rebuilt and uploaded. Rather than let that difference go
   unnoticed, the details that appear in both places are fingerprinted here.
   tools/sync_site_contact.py stores the fingerprint as it rebuilds the footer,
   and the admin compares the two and says plainly when they have parted.
   -------------------------------------------------------------------------- */

/**
 * A stable digest of exactly the facts the site-wide footer repeats.
 *
 * Deliberately a delimited string rather than json_encode(): the same digest
 * has to be computed by tools/sync_site_contact.py in Python, and the two
 * languages do not agree on how a JSON document is spelled — PHP escapes the
 * slash in "278/3" by default and Python does not. A string with fixed
 * separators is the same bytes in both.
 */
function contact_fingerprint(array $data): string
{
    $parts = ['email=' . contact_email($data)];

    foreach (contact_shown_offices($data) as $office) {
        $parts[] = implode('|', [
            trim((string)$office['name']),
            trim((string)$office['address']),
            implode(';', $office['phones']),
            trim((string)$office['hours']),
            trim((string)$office['schema']['street']),
            trim((string)$office['schema']['locality']),
            trim((string)$office['schema']['region']),
            trim((string)$office['schema']['postal_code']),
            strtoupper(trim((string)$office['schema']['country'])),
        ]);
    }

    return hash('sha256', implode("\n", $parts));
}

function contact_footer_in_step(array $data): bool
{
    return trim((string)($data['footer_synced'] ?? '')) === contact_fingerprint($data);
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
