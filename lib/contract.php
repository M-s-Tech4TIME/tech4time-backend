<?php
/**
 * Tech4TIME — the contract between the two repositories.
 *
 * SHARED FILE. Byte-identical in tech4time-website-frontend and tech4time-website-backend.
 * Change it in one and you must change it in the other in the same breath;
 * tools/check_shared_lib.py compares the two against a committed digest and
 * fails the build in both when they part.
 *
 * WHAT BELONGS HERE
 * The shape of a document, and nothing else. Field lists, the defaults a
 * missing key falls back to, the normalising that turns whatever arrived into
 * that shape, and the queries that read it. Both sides must agree on all of
 * this or they are not describing the same job post.
 *
 * WHAT DOES NOT BELONG HERE
 *   authoring   validation with human-readable messages, the form model, the
 *               editor's pickers — the backend's business, and the frontend
 *               has no form to validate
 *   rendering   JobPosting and ContactPage structured data, flag pictures,
 *               tel: hrefs — the frontend's business, and the backend does
 *               not render the public page
 *
 * The line is: if the two sides disagreeing about it would corrupt a document,
 * it is here. If disagreeing would only make one side's own page look wrong,
 * it is not.
 *
 * CONTRACT_VERSION
 * Every published payload carries it, and the receiving side refuses a version
 * it does not implement — see lib/publish.php. That runtime check is the real
 * guarantee, because it fires on the real path on the day, and it refuses
 * rather than writing a document it would then mis-render.
 *
 * The digest comparison is hygiene for accidental local edits and no more. It
 * cannot catch a deliberate change: bump the version in both repositories and
 * both digests agree again while the two hold different code. Only the
 * receiver checking what it was actually sent can catch that.
 *
 * BUMP IT when a change would make a document written by one version render
 * wrongly under the other: a field renamed, a field's meaning changed, a list
 * that becomes a scalar. Do NOT bump it for a new optional field that older
 * code simply ignores, or for anything in the two lists above.
 *
 * Not reachable over HTTP: the frontend's .htaccess forbids /lib/, and the
 * backend's lib/ sits outside its document root.
 */

declare(strict_types=1);

require_once __DIR__ . '/html.php';

/** The shape both repositories implement. See the header before changing it. */
const CONTRACT_VERSION = 1;

/** Every document that is published, by name. The endpoint refuses any other. */
const CONTRACT_DOCUMENTS = ['careers', 'contact'];

/**
 * Fields a document keeps about itself, rather than about the page.
 *
 * No form posts them and no page renders them: they are how a document
 * describes its own history. Named once because three separate checks would
 * otherwise each carry a copy of the list, and the one that forgets a new
 * entry reports it as "a field nobody edits" — which is true, and not the
 * point. That is exactly how 'revision' announced itself.
 */
const CONTRACT_BOOKKEEPING = ['updated', 'revision', 'footer_synced'];

/* ==========================================================================
   1. Careers — the shape of a job post
   ========================================================================== */

/* Free-text single-line fields. */
const CAREERS_TEXT_FIELDS = [
    'id', 'title', 'employment_type', 'work_arrangement',
    'location', 'salary', 'posted', 'closes', 'status', 'apply_url',
];

/* Body fields, each stored as one sanitised HTML string. They were arrays of
   plain text until the editor gained formatting; careers_migrate() below still
   understands the old shape, so an older backup loads without ceremony. */
const CAREERS_RICH_FIELDS = [
    'about', 'responsibilities', 'requirements',
    'must_have', 'nice_to_have', 'certifications', 'offers',
];

/* Which of the old fields were bullets rather than paragraphs. Only used when
   migrating; nothing writes this shape any more. */
const CAREERS_LEGACY_LIST_FIELDS = [
    'responsibilities', 'requirements', 'must_have', 'nice_to_have', 'offers',
];

/* Section heading -> field, in the order a job renders. Changing a label here
   changes it on the page; changing a key would orphan existing data, which is
   why a key change is a CONTRACT_VERSION bump. */
const CAREERS_SECTIONS = [
    'about'            => 'About the Role',
    'responsibilities' => 'Key Responsibilities',
    'requirements'     => 'Required Skills & Experience',
    'must_have'        => 'Must Have',
    'nice_to_have'     => 'Nice to Have',
    'certifications'   => 'Certifications',
    'offers'           => 'What We Offer',
];

/** The document as it is when there is nothing in it. */
function careers_defaults(): array
{
    return [
        'cv_form_url' => '',
        'updated'     => '',
        'revision'    => 0,
        'jobs'        => [],
    ];
}

/**
 * Bring a document to the current shape, whatever it arrived as.
 *
 * Called by both sides on load, and by the frontend again on receipt — a
 * payload's origin says nothing about the shape of what is inside it.
 */
function careers_normalise(array $data): array
{
    $data += careers_defaults();

    $data['revision'] = max(0, (int)($data['revision'] ?? 0));
    $data['jobs'] = is_array($data['jobs'] ?? null) ? array_values($data['jobs']) : [];
    $data['jobs'] = array_map(
        'careers_migrate',
        array_filter($data['jobs'], 'is_array')
    );
    $data['jobs'] = array_values($data['jobs']);

    return $data;
}

/**
 * Bring a job forward from the plain-text schema.
 *
 * Runs on every load rather than as a one-off script, so an older
 * careers.json.bak restored by hand still works. Idempotent: a field that is
 * already a string is left exactly as it is.
 */
function careers_migrate(array $job): array
{
    foreach (CAREERS_RICH_FIELDS as $field) {
        $value = $job[$field] ?? '';

        if (is_string($value)) {
            continue;
        }
        if (!is_array($value) || !$value) {
            $job[$field] = '';
            continue;
        }

        $items = array_map(static fn($v): string => h((string)$v), $value);

        $job[$field] = in_array($field, CAREERS_LEGACY_LIST_FIELDS, true)
            ? '<ul><li>' . implode('</li><li>', $items) . '</li></ul>'
            : '<p>' . implode('</p><p>', $items) . '</p>';
    }

    return $job;
}

/** Only the posts a visitor should see. */
function careers_open_jobs(array $data): array
{
    return array_values(array_filter(
        $data['jobs'],
        static fn(array $job): bool => ($job['status'] ?? 'open') === 'open'
    ));
}

function careers_find(array $data, string $id): ?array
{
    foreach ($data['jobs'] as $job) {
        if (($job['id'] ?? '') === $id) {
            return $job;
        }
    }
    return null;
}

/** The one-line summary a listing shows: "Full-Time · On-site · Dhaka". */
function careers_meta_line(array $job): array
{
    return array_values(array_filter([
        trim((string)($job['employment_type'] ?? '')),
        trim((string)($job['work_arrangement'] ?? '')),
        trim((string)($job['location'] ?? '')),
    ], static fn(string $v): bool => $v !== ''));
}

/** A URL-safe id from a title, unique against the ids already in use. */
function careers_slug(string $title, array $taken = []): string
{
    $slug = strtolower(trim($title));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-') ?: 'role';

    $base = $slug;
    $n = 2;
    while (in_array($slug, $taken, true)) {
        $slug = $base . '-' . $n++;
    }
    return $slug;
}

/* ==========================================================================
   2. Contact — the shape of the contact page
   ========================================================================== */

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
 * to that scan, so every icon offered here is also listed in a comment in the
 * frontend's pages/contact/index.php where the scanner can see it. Adding one
 * here means adding it there too, and inject_icons.py --check will say so if
 * it is forgotten.
 *
 * Shared rather than backend-only because the backend offers the choice and
 * the frontend has to be able to draw whatever was chosen. A row carrying an
 * icon the frontend has never heard of renders as an empty box.
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

/* Fields stored as sanitised HTML, so they can carry a link or emphasis. This
   is also the list the frontend re-sanitises on receipt: a signature proves
   where a payload came from, not that what is in it is safe. */
const CONTACT_RICH_FIELDS = [
    'form'    => ['lead'],
    'offices' => ['lead'],
];

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
        'revision'      => 0,
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
 * Bring a document to the current shape, whatever it arrived as.
 *
 * One level of merge per section, which is all the shape has: scalars fall
 * back individually, lists are taken whole or not at all.
 */
function contact_normalise(array $data): array
{
    $defaults = contact_defaults();

    foreach ($defaults as $key => $value) {
        if ($key === 'revision') {
            $data[$key] = max(0, (int)($data[$key] ?? 0));
            continue;
        }
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

/** A phone number as a dialler wants it: digits, and a leading + if it had one. */
function contact_tel(string $number): string
{
    $digits = preg_replace('/[^0-9]/', '', $number) ?? '';
    return (str_starts_with(trim($number), '+') ? '+' : '') . $digits;
}

/* ------------------------------------------------------------ footer drift

   The same email, phone numbers, addresses and opening hours appear in the
   site footer, which is pasted into every page as literal markup — the project
   forbids runtime partials, so there is no include to point at contact.json.

   The contact page updates the moment it is saved; the footer does not, and
   cannot, until the frontend's pages are rebuilt and deployed. Rather than let
   that difference go unnoticed, the details that appear in both places are
   fingerprinted here.

   AFTER THE SPLIT the two halves of that comparison live on different hosts.
   The frontend's tools/sync_site_contact.py rebuilds the footers and writes
   the fingerprint into lib/footer.php, which deploys with the site; the
   frontend reports it back in every publish response; the backend records what
   it was told and the editor compares. So the warning is still answered by the
   side that actually knows, rather than by the side that would like to.
   -------------------------------------------------------------------------- */

/**
 * A stable digest of exactly the facts the site-wide footer repeats.
 *
 * Deliberately a delimited string rather than json_encode(): the same digest
 * has to be computed by the frontend's tools/sync_site_contact.py in Python,
 * and the two languages do not agree on how a JSON document is spelled — PHP
 * escapes the slash in "278/3" by default and Python does not. A string with
 * fixed separators is the same bytes in both.
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

/* ==========================================================================
   3. Revisions
   ========================================================================== */

/**
 * The revision a save should carry: one past whatever is on file.
 *
 * Monotonic per document, and the only thing standing between the live site
 * and a reordered or replayed publish. The receiving side accepts a payload
 * strictly greater than what it holds and refuses everything else, so a retry
 * of an older save cannot roll the public page backwards — which is the
 * failure a signature alone does not prevent, because a replayed request is
 * signed perfectly well.
 *
 * A count, not a clock. Two saves inside the same second are two revisions;
 * two servers with drifting clocks are not a consideration because only one
 * side ever mints these.
 */
function contract_next_revision(array $data): int
{
    return max(0, (int)($data['revision'] ?? 0)) + 1;
}

/* ==========================================================================
   4. Re-sanitising on receipt
   ========================================================================== */

/**
 * Run every rich field of a document back through the sanitiser.
 *
 * The receiving side calls this on a payload it has just verified, because a
 * signature proves where something came from and not what is inside it. If the
 * backend is ever compromised, the public site should still not render script.
 *
 * Driven off CAREERS_RICH_FIELDS and CONTACT_RICH_FIELDS rather than a list of
 * its own, so a rich field added to the contract is sanitised on receipt by
 * having been added — not by somebody also remembering to add it here. That is
 * the whole reason this lives in the contract and not in the endpoint.
 *
 * Idempotent: rt_sanitise_html() over already-sanitised markup returns it
 * unchanged, which is what makes it safe for the sender to call as well.
 */
function contract_sanitise(string $document, array $data): array
{
    if ($document === 'careers') {
        foreach ($data['jobs'] as $i => $job) {
            foreach (CAREERS_RICH_FIELDS as $field) {
                $data['jobs'][$i][$field] =
                    rt_sanitise_html((string)($job[$field] ?? ''));
            }
        }
        return $data;
    }

    if ($document === 'contact') {
        foreach (CONTACT_RICH_FIELDS as $section => $fields) {
            foreach ($fields as $field) {
                $data[$section][$field] =
                    rt_sanitise_html((string)($data[$section][$field] ?? ''));
            }
        }
        return $data;
    }

    throw new RuntimeException('Unknown document: ' . $document);
}
