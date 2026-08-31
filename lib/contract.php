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
const CONTRACT_DOCUMENTS = ['careers', 'contact', 'company', 'about', 'home'];

/**
 * Where a document's record lives, on either host.
 *
 * content/<name>.json, and the same in both repositories -- the backend's copy
 * is the system of record and the frontend's is the replica it is sent
 * (ADR 0010), but the path is one rule. lib/careers.php, lib/contact.php and
 * lib/company.php each still write their own constant, because they are read
 * far more often than this is; what this exists for is everything that has to
 * work over ALL the documents without knowing their names in advance.
 *
 * The deploy is the reason it exists. A new document gets a model, an editor, a
 * renderer, tests and documentation, and the one line that seeds it onto a
 * fresh host is in a file nobody opens for any of that -- so it gets left out,
 * and the failure is silent: the editor comes up showing defaults, which look
 * like a page nobody has filled in yet rather than like a missing file. That
 * happened to the company profile. It reached production with the admin
 * offering an empty form over a live page holding seventy-seven rows, and one
 * press of Save would have published the empty one over it.
 *
 * @throws RuntimeException on a name CONTRACT_DOCUMENTS does not list.
 */
function contract_path(string $document): string
{
    if (!in_array($document, CONTRACT_DOCUMENTS, true)) {
        throw new RuntimeException('unknown document: ' . $document);
    }

    return __DIR__ . '/../content/' . $document . '.json';
}

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
            'status' => 'shown',
            'title'  => 'Reach Us Directly',
            'items'  => [],
        ],
        'offices' => [
            'status'  => 'shown',
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

    /* Clamped the same way COMPANY_BANDS are, and for the same reason: this
       arrives from a file as often as from a form, and "banana" is not a
       visibility. Anything that is not the word 'hidden' shows. */
    foreach (CONTACT_BANDS as $band) {
        $data[$band]['status'] =
            ($data[$band]['status'] ?? 'shown') === 'hidden' ? 'hidden' : 'shown';
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
        'status' => 'shown',
    ];

    /* Anything that is not the word 'hidden' is shown. A row that arrives from
       an older document has no status at all and must not vanish because of
       it -- which is the whole reason this defaults the way round it does. */
    $item['status'] = $item['status'] === 'hidden' ? 'hidden' : 'shown';

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
        'image'   => [],
    ];

    $office['status'] = $office['status'] === 'hidden' ? 'hidden' : 'shown';

    /* THE FLAG, TWICE OVER, AND BOTH ARE NEEDED.

       'flag' is a slug -- 'bangladesh', 'belgium' -- naming a file that ships
       with the public site in assets/images/flags/. It works, and it is why
       the three offices that exist have flags at all. What it cannot do is let
       somebody add a fourth office: there is no file for their country and no
       way to put one there without a developer and a deploy.

       'image' is an uploaded picture, the same record shape the company
       profile's logos use, travelling the same signed asset channel. When it
       is set it wins; when it is not, the slug still renders. So nothing that
       works today stops working, and a new office is no longer a request to
       somebody with a git remote. */
    $office['image'] = contract_image_defaults($office['image']);

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

/**
 * The bands of the contact page a visitor can be shown or not shown.
 *
 * The banner and the enquiry form are not here on purpose: a contact page with
 * no way to make contact is not a page anybody meant to publish, and a switch
 * that can produce one is a switch somebody will eventually flip by accident.
 * Everything below the form is optional; the form is the page.
 */
const CONTACT_BANDS = ['reach', 'offices'];

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
    if (($data['offices']['status'] ?? 'shown') === 'hidden') {
        return [];
    }

    return array_values(array_filter(
        $data['offices']['items'],
        static fn(array $o): bool => ($o['status'] ?? 'shown') === 'shown'
    ));
}

/**
 * The reach rows a visitor should see, and none when the band is switched off.
 *
 * The band's own switch is checked HERE rather than only where the markup is
 * written, because a hidden band must also be absent from the structured data
 * — and the JSON-LD is built from a different function in a different file. A
 * band that disappears visually and goes on being advertised to search engines
 * is not hidden, it is only invisible. contact_shown_offices() above answers
 * for the same reason.
 */
function contact_shown_reach(array $data): array
{
    if (($data['reach']['status'] ?? 'shown') === 'hidden') {
        return [];
    }

    return array_values(array_filter(
        $data['reach']['items'] ?? [],
        static fn(array $r): bool => ($r['status'] ?? 'shown') === 'shown'
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
   3. Company profile — the shape of the company page
   ========================================================================== */

/**
 * The icons a principle card may use.
 *
 * Fixed for the same reason CONTACT_ICONS is: tools/inject_icons.py inlines the
 * symbols a page references by scanning it for a literal href="#name", and a
 * name chosen at run time is invisible to that scan. Every icon offered here is
 * therefore also listed in a comment in the frontend's
 * pages/company-profile/index.php, where the scanner can see it. Add one here
 * and add it there; inject_icons.py --check says so if it is forgotten.
 */
const COMPANY_ICONS = [
    'shield-alt'     => 'Shield',
    'lightbulb'      => 'Lightbulb',
    'handshake'      => 'Handshake',
    'clock'          => 'Clock',
    'cogs'           => 'Cogs',
    'building'       => 'Building',
    'globe'          => 'Globe',
    'headset'        => 'Headset',
    'comment-alt'    => 'Speech bubble',
    'calendar-check' => 'Calendar tick',
    'check-circle'   => 'Tick in a circle',
    'user-shield'    => 'Person and shield',
    'eye'            => 'Eye',
    'info-circle'    => 'Information',
];

/* Free-text single-line fields, by band. */
const COMPANY_TEXT_FIELDS = [
    'meta'       => ['title', 'description', 'share_title'],
    'hero'       => ['title', 'subtitle'],
    'milestones' => ['eyebrow', 'title'],
    'background' => ['eyebrow', 'title'],
    'experience' => ['title'],
    'clients'    => ['title'],
    'journey'    => ['title'],
    'excellence' => ['eyebrow', 'title'],
    'technology' => ['title'],
    'principles' => ['title'],
    'cta'        => ['title', 'label', 'href', 'icon'],
];

/* Fields stored as sanitised HTML, so a lead can carry a link or emphasis.
   This is also the list the frontend re-sanitises on receipt. */
const COMPANY_RICH_FIELDS = [
    'milestones' => ['lead'],
    'journey'    => ['lead'],
    'excellence' => ['lead'],
    'cta'        => ['text'],
];

/**
 * Every band of the page that can be hidden whole, in the order it renders.
 *
 * Two of these CONTAIN others on the page: 'background' is the surface the
 * experience, clients and journey blocks sit on, and 'excellence' is the one
 * holding technology and principles. Hiding a container hides what is inside
 * it; hiding one of the inner blocks leaves the others where they were. The
 * shape here is flat because the form is flat — the nesting is the renderer's,
 * and company_band_shown() is what both sides ask.
 */
const COMPANY_BANDS = [
    'milestones', 'background', 'experience', 'clients', 'journey',
    'excellence', 'technology', 'principles', 'cta',
];

/**
 * The bands that hold a list, and the function that fills one of its rows.
 *
 * Named once so company_normalise() can drive itself off it. A list added to
 * the page is normalised by being added here, rather than by somebody also
 * remembering to add a line further down — the same argument CONTRACT_BOOKKEEPING
 * makes, for the same reason.
 */
const COMPANY_LISTS = [
    'milestones' => 'company_milestone_defaults',
    'experience' => 'company_stat_defaults',
    'clients'    => 'company_logo_defaults',
    'journey'    => 'company_photo_defaults',
    'technology' => 'company_logo_defaults',
    'principles' => 'company_principle_defaults',
];

/**
 * The page as it ships, and the fallback for anything missing from the file.
 *
 * Every scalar the renderer reads exists here, so a truncated or hand-edited
 * company.json degrades to the shipped headings rather than emptying the page.
 * The lists default to empty, which is the same bargain the contact page makes:
 * the page still has a shape, it just has nothing in it.
 */
function company_defaults(): array
{
    return [
        'updated'  => '',
        'revision' => 0,
        'meta' => [
            'title'       => 'Company Profile | Tech4TIME',
            'description' => 'Our milestones, the clients we serve, and the technology our engagements are built on.',
            'share_title' => 'Milestones in Technological Excellence',
        ],
        'hero' => [
            'title'    => 'Company Profile',
            'subtitle' => 'Milestones, Clients and the Technology We Work With',
        ],
        'milestones' => [
            'status'  => 'shown',
            'eyebrow' => 'Our Journey',
            'title'   => 'Milestones in Technological Excellence',
            'lead'    => '',
            'items'   => [],
        ],
        'background' => [
            'status'  => 'shown',
            'eyebrow' => 'Who We Are',
            'title'   => 'Our Background',
        ],
        'experience' => [
            'status' => 'shown',
            'title'  => 'Experience',
            'items'  => [],
        ],
        'clients' => [
            'status' => 'shown',
            'title'  => 'Proud Clients',
            'items'  => [],
        ],
        'journey' => [
            'status'   => 'shown',
            'title'    => 'Our Journey of Growth',
            'lead'     => '',
            'interval' => 6000,
            'items'    => [],
        ],
        'excellence' => [
            'status'  => 'shown',
            'eyebrow' => 'Work & Expertise',
            'title'   => 'Our Professional Excellence',
            'lead'    => '',
        ],
        'technology' => [
            'status' => 'shown',
            'title'  => 'The Technology We Work With',
            'items'  => [],
        ],
        'principles' => [
            'status' => 'shown',
            'title'  => 'The Principles That Guide Us',
            'items'  => [],
        ],
        'cta' => [
            'status' => 'shown',
            'title'  => 'Want to be the next name on this page?',
            'text'   => '',
            'label'  => 'Talk to Us',
            'href'   => '/pages/contact/',
            'icon'   => 'calendar-check',
        ],
    ];
}

/**
 * Bring a document to the current shape, whatever it arrived as.
 *
 * One level of merge per band, as the contact page does, then every list
 * through its own row-filler. Rows are renumbered with array_values() because
 * the editor posts them keyed by position and a removed row leaves a hole.
 */
function company_normalise(array $data): array
{
    $defaults = company_defaults();

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

    foreach (COMPANY_BANDS as $band) {
        $data[$band]['status'] =
            ($data[$band]['status'] ?? 'shown') === 'hidden' ? 'hidden' : 'shown';
    }

    /* A slideshow that advances every 40 milliseconds is not a slideshow, and
       one that waits an hour has stopped. Clamped rather than refused: this
       arrives from a file as often as from a form. */
    $data['journey']['interval'] =
        min(60000, max(2000, (int)($data['journey']['interval'] ?? 6000)));

    foreach (COMPANY_LISTS as $band => $filler) {
        $rows = is_array($data[$band]['items'] ?? null) ? $data[$band]['items'] : [];
        $data[$band]['items'] = array_map(
            $filler,
            array_values(array_filter($rows, 'is_array'))
        );
    }

    return company_identify($data);
}

/**
 * Give every row an id, unique within its own list.
 *
 * Rows are addressed by position in the form and by id everywhere else — a
 * fragment link, an upload that has to find the row it belongs to, a test that
 * wants to name one. Minted here rather than in the editor so a row that
 * arrived from a hand-edited file has one too.
 */
function company_identify(array $data): array
{
    foreach (COMPANY_LISTS as $band => $_filler) {
        $taken = [];
        foreach ($data[$band]['items'] as $i => $row) {
            $id   = trim((string)($row['id'] ?? ''));
            $name = company_row_name($band, $row);

            /* A row added by the Add button has nothing in it yet, so there is
               nothing to name it after and it gets the placeholder. Once it
               HAS a name, the placeholder is replaced — otherwise every row
               ever created through the editor would be called "row", "row-2",
               "row-3" for the rest of its life. A real id is never re-minted:
               it is the handle everything else holds the row by. */
            $provisional = $id === ''
                || preg_match('/^' . COMPANY_ID_PLACEHOLDER . '(-\d+)?$/', $id) === 1;

            if (($provisional && $name !== '') || in_array($id, $taken, true)) {
                $id = company_slug($name, $taken);
            } elseif ($id === '') {
                $id = company_slug('', $taken);
            }

            $data[$band]['items'][$i]['id'] = $id;
            $taken[] = $id;
        }
    }

    return $data;
}

/**
 * What a row's id is minted from, whichever list it is in.
 *
 * A photograph is named after its file rather than its alt text: alt is a
 * sentence, and a sentence makes an id nobody can read or type. The file is
 * short, already unique, and describes the same thing.
 */
function company_row_name(string $band, array $row): string
{
    if ($band === 'journey') {
        $file = pathinfo((string)($row['image']['src'] ?? ''), PATHINFO_FILENAME);
        return $file !== '' ? $file : 'photo';
    }

    return trim((string)match ($band) {
        'milestones' => ($row['year'] ?? '') . ' ' . ($row['title'] ?? ''),
        'experience' => $row['label'] ?? '',
        'principles' => $row['title'] ?? '',
        default      => $row['name'] ?? '',
    });
}

function company_milestone_defaults(array $row): array
{
    return $row + [
        'id' => '', 'year' => '', 'title' => '', 'text' => '', 'status' => 'shown',
    ];
}

function company_stat_defaults(array $row): array
{
    return $row + [
        'id' => '', 'figure' => '', 'label' => '', 'status' => 'shown',
    ];
}

/** A logo: the clients grid and the technology sphere hold the same shape. */
function company_logo_defaults(array $row): array
{
    $row += ['id' => '', 'name' => '', 'status' => 'shown'];
    $row['image'] = contract_image_defaults($row['image'] ?? []);

    return $row;
}

function company_photo_defaults(array $row): array
{
    $row += ['id' => '', 'alt' => '', 'status' => 'shown'];
    $row['image'] = contract_image_defaults($row['image'] ?? []);

    return $row;
}

function company_principle_defaults(array $row): array
{
    return $row + [
        'id' => '', 'icon' => '', 'title' => '', 'text' => '', 'status' => 'shown',
    ];
}

/**
 * Where a picture may live, as path prefixes.
 *
 * assets/ is artwork that ships with the site and changes with a deploy;
 * uploads/ is what the editor put there. Nothing else is a picture this site
 * will point at.
 *
 * THIS IS ENFORCED ON BOTH SIDES, and that is the point of it being here. The
 * editor checks it because a hidden input is a text field with the label taken
 * off. The frontend checks it AGAIN on receipt, because a signature proves
 * where a document came from and not what is inside it — the same argument
 * contract_sanitise() makes about rich text. An <img src> pointing somewhere
 * else would put a third party's server into every visitor's page load, and
 * tell them who is reading the page.
 */
/* Named for the contract rather than for the company profile, because the
   contact page's offices carry a picture too now. A picture record is one
   shape, checked one way, wherever it hangs. */
const CONTRACT_IMAGE_ROOTS = ['/assets/images/', '/uploads/'];

/* What a row is called before it is called anything. See company_identify(). */
const COMPANY_ID_PLACEHOLDER = 'row';

/**
 * A picture path, or '' if it is not one this site will publish.
 *
 * Rejects anything with a backslash, a control character or "..", before the
 * prefix test rather than after: "/assets/images/../../etc/passwd" starts with
 * an allowed prefix and is not an allowed path.
 */
function contract_safe_image_path(string $path): string
{
    $path = trim($path);

    if ($path === '' || preg_match('~[\\x00-\\x1f\\x7f\\\\]|\\.\\.~', $path)) {
        return '';
    }

    foreach (CONTRACT_IMAGE_ROOTS as $root) {
        if (str_starts_with($path, $root) && strlen($path) > strlen($root)) {
            return $path;
        }
    }

    return '';
}

/**
 * Fill in a picture, whatever it arrived with.
 *
 * width and height are not decoration. They are what lets the browser reserve
 * the right box before the bytes arrive, and this site's Cumulative Layout
 * Shift is zero rather than nearly zero. A row whose dimensions are missing or
 * nonsense renders without them, which is honest; a row that guessed would
 * move the page.
 *
 * webp is optional and empty is meaningful: it says "there is no WebP sibling,
 * emit a bare <img> and no <picture> wrapper". That is how the SVG and AVIF
 * entries have always rendered.
 */
function contract_image_defaults(mixed $image): array
{
    $image = is_array($image) ? $image : [];
    $image += ['src' => '', 'webp' => '', 'width' => 0, 'height' => 0];

    $image['src']    = contract_safe_image_path((string)$image['src']);
    $image['webp']   = contract_safe_image_path((string)$image['webp']);
    $image['width']  = max(0, (int)$image['width']);
    $image['height'] = max(0, (int)$image['height']);

    return $image;
}

/** Only the rows of a list a visitor should see. */
function company_shown(array $data, string $band): array
{
    return array_values(array_filter(
        $data[$band]['items'] ?? [],
        static fn(array $row): bool => ($row['status'] ?? 'shown') !== 'hidden'
    ));
}

/** Whether a band of the page is shown at all. */
function company_band_shown(array $data, string $band): bool
{
    return ($data[$band]['status'] ?? 'shown') !== 'hidden';
}

function company_find(array $data, string $band, string $id): ?array
{
    foreach ($data[$band]['items'] ?? [] as $row) {
        if (($row['id'] ?? '') === $id) {
            return $row;
        }
    }
    return null;
}

/** Every picture the document points at, as web paths, without duplicates. */
function company_images(array $data): array
{
    $seen = [];

    foreach (COMPANY_LISTS as $band => $_filler) {
        foreach ($data[$band]['items'] ?? [] as $row) {
            foreach ([$row['image']['src'] ?? '', $row['image']['webp'] ?? ''] as $path) {
                $path = trim((string)$path);
                if ($path !== '') {
                    $seen[$path] = true;
                }
            }
        }
    }

    return array_keys($seen);
}

/** A URL-safe id from a name, unique against the ids already in use. */
function company_slug(string $name, array $taken = []): string
{
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-') ?: COMPANY_ID_PLACEHOLDER;

    $base = $slug;
    $n = 2;
    while (in_array($slug, $taken, true)) {
        $slug = $base . '-' . $n++;
    }
    return $slug;
}

/* ==========================================================================
   4. About page — the shape of the about page
   ========================================================================== */

/**
 * The icons a specialty card, a why-us card or the closing button may use.
 *
 * Fixed for the same reason CONTACT_ICONS and COMPANY_ICONS are:
 * tools/inject_icons.py inlines the symbols a page references by scanning it
 * for a literal href="#name", and a name chosen at run time is invisible to
 * that scan. Every icon offered here is therefore also listed in a comment in
 * the frontend's pages/about/index.php, where the scanner can see it. Add one
 * here and add it there; inject_icons.py --check says so if it is forgotten.
 *
 * Every name here must also be in ADMIN_ICONS in the backend's lib/admin.php,
 * or the editor's live preview draws an empty box for it.
 */
const ABOUT_ICONS = [
    'shield-alt'      => 'Shield',
    'code'            => 'Code',
    'cloud'           => 'Cloud',
    'users'           => 'People',
    'server'          => 'Server',
    'graduation-cap'  => 'Graduation cap',
    'trophy'          => 'Trophy',
    'layer-group'     => 'Stacked layers',
    'lightbulb'       => 'Lightbulb',
    'handshake'       => 'Handshake',
    'cogs'            => 'Cogs',
    'lock'            => 'Padlock',
    'project-diagram' => 'Project diagram',
    'eye'             => 'Eye',
    'arrow-right'     => 'Arrow',
    'check-circle'    => 'Tick in a circle',
];

/**
 * How a story row draws its picture.
 *
 * 'logo' draws a light/dark pair rather than one picture. The Tech4TIME
 * lockup that ships with the site is the fallback, so a row switched to this
 * layout works with nothing uploaded — but a new logo CAN be uploaded, per
 * row, because a company that changes its mark should not need a deploy to
 * show it.
 *
 * WHAT THIS DOES NOT CHANGE: the logo in the header, the footer, the browser
 * tab, the social share card and the Organization structured data. Those are
 * shared markup and build artefacts, not content — see
 * docs/40-reference/content-schemas.md.
 */
const ABOUT_LAYOUTS = [
    'photograph' => 'A photograph',
    'logo'       => 'The Tech4TIME logo lockup',
];

/** Which side of a story row the picture sits on. */
const ABOUT_SIDES = [
    'left'  => 'Picture on the left',
    'right' => 'Picture on the right',
];

/* Free-text single-line fields, by band. The story band has none: every
   heading on that part of the page belongs to a row, not to the band. */
const ABOUT_TEXT_FIELDS = [
    'meta'        => ['title', 'description', 'share_title'],
    'hero'        => ['title', 'subtitle'],
    'specialties' => ['title'],
    'whyus'       => ['title'],
    'cta'         => ['title', 'label', 'href', 'icon'],
];

/**
 * Rich fields that live on a ROW rather than on a band.
 *
 * The about page has no band-level rich text — its one rich field is a story
 * section's prose, and there are five of those. Careers is shaped the same way
 * (CAREERS_RICH_FIELDS is applied per job), and contract_sanitise() already
 * knows how to walk a list; this just says which list and which fields.
 */
const ABOUT_ROW_RICH_FIELDS = ['story' => ['body']];

/* Every band of the page that can be hidden whole, in the order it renders.
   The hero is not here, for the reason the contact page's hero is not in
   CONTACT_BANDS: a page with no title is not a page with a section switched
   off, it is a broken page. */
const ABOUT_BANDS = ['story', 'specialties', 'whyus', 'cta'];

/**
 * The bands that hold a list, and the function that fills one of its rows.
 *
 * Named once so about_normalise() can drive itself off it, exactly as
 * COMPANY_LISTS does. A list added to the page is normalised by being added
 * here rather than by somebody also remembering a line further down.
 */
const ABOUT_LISTS = [
    'story'       => 'about_story_defaults',
    'specialties' => 'about_specialty_defaults',
    'whyus'       => 'about_reason_defaults',
];

/* What a row is called before it is called anything. Deliberately the same
   value as COMPANY_ID_PLACEHOLDER and deliberately a separate constant: each
   document owns its own id vocabulary, and one changing must not move the
   other. See about_identify(). */
const ABOUT_ID_PLACEHOLDER = 'row';

/**
 * The page as it ships, and the fallback for anything missing from the file.
 *
 * Every scalar the renderer reads exists here, so a truncated or hand-edited
 * about.json degrades to the shipped headings rather than emptying the page.
 * The lists default to empty, which is the same bargain the contact and
 * company pages make: the page still has a shape, it just has nothing in it.
 */
function about_defaults(): array
{
    return [
        'updated'  => '',
        'revision' => 0,
        'meta' => [
            'title'       => 'About Tech4TIME | Trusted IT & Cybersecurity Solutions',
            'description' => 'Founded in 2018, Tech4TIME delivers cybersecurity, software development, cloud infrastructure, HRaaS and IT training — orchestrating technology with time.',
            'share_title' => 'About Tech4TIME',
        ],
        'hero' => [
            'title'    => 'About Us',
            'subtitle' => 'Orchestrating Technology with Time',
        ],
        'story' => [
            'status' => 'shown',
            'items'  => [],
        ],
        'specialties' => [
            'status'   => 'shown',
            'title'    => 'Our Specialities',
            'interval' => 10000,
            'items'    => [],
        ],
        'whyus' => [
            'status' => 'shown',
            'title'  => 'Why Us?',
            'items'  => [],
        ],
        'cta' => [
            'status' => 'shown',
            'title'  => 'Curious About our Services?',
            'label'  => 'Explore All Services',
            'href'   => '/pages/services/',
            'icon'   => 'arrow-right',
        ],
    ];
}

/**
 * Bring a document to the current shape, whatever it arrived as.
 *
 * One level of merge per band, then every list through its own row-filler.
 * Rows are renumbered with array_values() because the editor posts them keyed
 * by position and a removed row leaves a hole.
 */
function about_normalise(array $data): array
{
    $defaults = about_defaults();

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

    foreach (ABOUT_BANDS as $band) {
        $data[$band]['status'] =
            ($data[$band]['status'] ?? 'shown') === 'hidden' ? 'hidden' : 'shown';
    }

    /* Clamped rather than refused, for the reason journey.interval is: this
       arrives from a file as often as from a form. */
    $data['specialties']['interval'] =
        min(60000, max(2000, (int)($data['specialties']['interval'] ?? 10000)));

    foreach (ABOUT_LISTS as $band => $filler) {
        $rows = is_array($data[$band]['items'] ?? null) ? $data[$band]['items'] : [];
        $data[$band]['items'] = array_map(
            $filler,
            array_values(array_filter($rows, 'is_array'))
        );
    }

    return about_identify($data);
}

/**
 * Give every row an id, unique within its own list.
 *
 * Same contract as company_identify(): a row added by the Add button has
 * nothing to be named after and gets the placeholder; once it has a name the
 * placeholder is replaced; a real id is never re-minted, because it is the
 * handle a fragment link, an upload and a test all hold the row by.
 */
function about_identify(array $data): array
{
    foreach (ABOUT_LISTS as $band => $_filler) {
        $taken = [];
        foreach ($data[$band]['items'] as $i => $row) {
            $id   = trim((string)($row['id'] ?? ''));
            $name = about_row_name($band, $row);

            $provisional = $id === ''
                || preg_match('/^' . ABOUT_ID_PLACEHOLDER . '(-\d+)?$/', $id) === 1;

            if (($provisional && $name !== '') || in_array($id, $taken, true)) {
                $id = about_slug($name, $taken);
            } elseif ($id === '') {
                $id = about_slug('', $taken);
            }

            $data[$band]['items'][$i]['id'] = $id;
            $taken[] = $id;
        }
    }

    return $data;
}

/** What a row's id is minted from, whichever list it is in. */
function about_row_name(string $band, array $row): string
{
    return trim((string)match ($band) {
        'story' => $row['heading'] ?? '',
        default => $row['title'] ?? '',
    });
}

/**
 * One image-and-prose section of the page.
 *
 * 'body' is sanitised HTML, not plain text: it is one or two paragraphs and
 * the editor writes it with the rich-text control. See ABOUT_ROW_RICH_FIELDS.
 */
function about_story_defaults(array $row): array
{
    $row += [
        'id'      => '',
        'heading' => '',
        'body'    => '',
        'layout'  => 'photograph',
        'side'    => 'left',
        'alt'     => '',
        'status'  => 'shown',
    ];

    $row['layout'] = isset(ABOUT_LAYOUTS[$row['layout']]) ? $row['layout'] : 'photograph';
    $row['side']   = isset(ABOUT_SIDES[$row['side']]) ? $row['side'] : 'left';
    $row['image']  = contract_image_defaults($row['image'] ?? []);

    /* The second half of a logo lockup. Only a row laid out as 'logo' draws
       it, and it is kept rather than cleared on a row that is not, for the
       same reason the first picture is: the layout can be switched back. */
    $row['image_dark'] = contract_image_defaults($row['image_dark'] ?? []);

    return $row;
}

/** A specialty card: the slider holds these. */
function about_specialty_defaults(array $row): array
{
    return $row + [
        'id' => '', 'icon' => '', 'title' => '', 'text' => '', 'status' => 'shown',
    ];
}

/** A why-us card. Same shape as a specialty, one line of text rather than a paragraph. */
function about_reason_defaults(array $row): array
{
    return $row + [
        'id' => '', 'icon' => '', 'title' => '', 'text' => '', 'status' => 'shown',
    ];
}

/** Only the rows of a list a visitor should see. */
function about_shown(array $data, string $band): array
{
    return array_values(array_filter(
        $data[$band]['items'] ?? [],
        static fn(array $row): bool => ($row['status'] ?? 'shown') !== 'hidden'
    ));
}

/** Whether a band of the page is shown at all. */
function about_band_shown(array $data, string $band): bool
{
    return ($data[$band]['status'] ?? 'shown') !== 'hidden';
}

function about_find(array $data, string $band, string $id): ?array
{
    foreach ($data[$band]['items'] ?? [] as $row) {
        if (($row['id'] ?? '') === $id) {
            return $row;
        }
    }
    return null;
}

/**
 * Every picture the document points at, as web paths, without duplicates.
 *
 * A row laid out as the logo lockup still has its picture record counted: the
 * layout can be switched back, and a sweep that deleted the file the moment
 * somebody chose 'logo' would lose it for good.
 */
function about_images(array $data): array
{
    $seen = [];

    foreach ($data['story']['items'] ?? [] as $row) {
        foreach ([$row['image']['src'] ?? '',      $row['image']['webp'] ?? '',
                  $row['image_dark']['src'] ?? '', $row['image_dark']['webp'] ?? ''] as $path) {
            $path = trim((string)$path);
            if ($path !== '') {
                $seen[$path] = true;
            }
        }
    }

    return array_keys($seen);
}

/** A URL-safe id from a name, unique against the ids already in use. */
function about_slug(string $name, array $taken = []): string
{
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-') ?: ABOUT_ID_PLACEHOLDER;

    $base = $slug;
    $n = 2;
    while (in_array($slug, $taken, true)) {
        $slug = $base . '-' . $n++;
    }
    return $slug;
}

/* ==========================================================================
   5. Home page — the shape of the home page
   ========================================================================== */

/**
 * The icons a badge, a tag, a capability, a service card or a button may use.
 *
 * Fixed for the same reason CONTACT_ICONS, COMPANY_ICONS and ABOUT_ICONS are:
 * tools/inject_icons.py inlines the symbols a page references by scanning it
 * for a literal href="#name", and a name chosen at run time is invisible to
 * that scan. Every icon offered here is therefore also listed in a comment in
 * the frontend's index.php, where the scanner can see it. Add one here and add
 * it there; inject_icons.py --check says so if it is forgotten.
 *
 * Every name here must also be in ADMIN_ICONS in the backend's lib/admin.php,
 * or the editor's live preview draws an empty box for it.
 *
 * This is the longest of the four lists because the home page is the widest
 * summary of what the company does: the hero alone offers thirteen tags.
 */
const HOME_ICONS = [
    'shield-alt'          => 'Shield',
    'shield-halved'       => 'Shield, half filled',
    'shield-virus'        => 'Shield with a virus',
    'bug'                 => 'Bug',
    'search'              => 'Magnifying glass',
    'crosshairs'          => 'Crosshairs',
    'desktop'             => 'Monitor',
    'first-aid'           => 'First-aid kit',
    'cogs'                => 'Cogs',
    'server'              => 'Server',
    'network-wired'       => 'Network',
    'file-contract'       => 'Document',
    'graduation-cap'      => 'Graduation cap',
    'laptop-code'         => 'Laptop with code',
    'mobile-alt'          => 'Mobile phone',
    'code'                => 'Code',
    'cloud'               => 'Cloud',
    'users'               => 'People',
    'boxes'               => 'Boxes',
    'chalkboard-teacher'  => 'Teacher at a board',
    'sitemap'             => 'Sitemap',
    'clipboard-check'     => 'Clipboard with a tick',
    'lightbulb'           => 'Lightbulb',
    'eye'                 => 'Eye',
    'rocket'              => 'Rocket',
    'arrow-right'         => 'Arrow',
];

/**
 * What a line of the hero terminal is.
 *
 * A 'command' line is typed out character by character by
 * assets/js/terminal.js and carries a prompt in front of it; an 'output' line
 * arrives whole, the way a shell prints. That distinction is the whole effect,
 * so it is a field and not a guess made from the text.
 */
const HOME_LINE_KINDS = [
    'command' => 'A typed command',
    'output'  => 'Output from the command',
];

/**
 * How a line of output is coloured. Ignored on a 'command' line.
 *
 * The tick and the exclamation mark that begin the success and alert lines are
 * part of the text, not added by CSS, so an operator writes them and can write
 * something else. This only picks the colour.
 */
const HOME_LINE_TONES = [
    'plain'   => 'Plain',
    'success' => 'Success',
    'alert'   => 'Alert',
];

/* The prompt a command line shows when it has none of its own. */
const HOME_PROMPT_DEFAULT = 'tech4time@soc:~$';

/* Free-text single-line fields, by band. The list bands carry only their own
   headings here; everything inside them belongs to a row. */
const HOME_TEXT_FIELDS = [
    'meta'         => ['title', 'description', 'share_title'],
    'hero'         => ['title', 'accent', 'cta_label', 'cta_href'],
    'terminal'     => ['title', 'summary'],
    'capabilities' => ['title', 'lead'],
    'services'     => ['eyebrow', 'title', 'lead', 'schema_name', 'schema_description'],
    'destinations' => ['eyebrow', 'title', 'lead'],
    'cta'          => ['title', 'text', 'label', 'href', 'icon'],
];

/**
 * The home page has NO rich text, deliberately.
 *
 * Every lead and every card body on it is a single styled <p> — .section__lead,
 * .service-card__text, .destination-card__text. A rich field would emit a <div>
 * full of paragraphs where one <p> is styled, so the control would offer
 * formatting the page cannot show. The one field that needs two lines is the
 * closing title, and it gets them from a newline rather than from markup; see
 * home_cta_title() in the frontend's lib/home.php.
 *
 * Named as an empty constant rather than left out so that the question "where
 * is the home page's rich text?" has an answer in the file.
 */
const HOME_ROW_RICH_FIELDS = [];

/* Every band of the page that can be hidden whole, in the order it renders.
   The hero itself is not here, for the reason the contact and about heroes are
   not: a page with no title is not a page with a section switched off, it is a
   broken page. Its badges, tags and terminal ARE here — they decorate the hero
   and the hero reads perfectly well without any of them. */
const HOME_BANDS = [
    'badges', 'tags', 'terminal', 'capabilities', 'services', 'destinations', 'cta',
];

/**
 * The bands that hold a list, and the function that fills one of its rows.
 *
 * Six of them — the most of any document here. Named once so home_normalise()
 * drives itself off it, exactly as ABOUT_LISTS and COMPANY_LISTS do.
 *
 * The hero's badges and tags are top-level bands rather than nested under
 * 'hero' so that every list on the page has the same $data[$band]['items']
 * shape. Nesting two of the six would mean home_identify(), home_shown() and
 * home_find() each carrying a special case for exactly those two, which is
 * three places for the same exception to be forgotten.
 */
const HOME_LISTS = [
    'badges'       => 'home_badge_defaults',
    'tags'         => 'home_tag_defaults',
    'terminal'     => 'home_line_defaults',
    'capabilities' => 'home_capability_defaults',
    'services'     => 'home_service_defaults',
    'destinations' => 'home_destination_defaults',
];

/* What a row is called before it is called anything. Deliberately the same
   value as ABOUT_ID_PLACEHOLDER and deliberately a separate constant: each
   document owns its own id vocabulary. See home_identify(). */
const HOME_ID_PLACEHOLDER = 'row';

/**
 * The page as it ships, and the fallback for anything missing from the file.
 *
 * Every scalar the renderer reads exists here, so a truncated or hand-edited
 * home.json degrades to the shipped headings rather than emptying the site's
 * front door. The lists default to empty, which is the same bargain the other
 * three pages make: the page still has a shape, it just has nothing in it.
 */
function home_defaults(): array
{
    return [
        'updated'  => '',
        'revision' => 0,
        'meta' => [
            'title'       => 'Tech4TIME | Orchestrating Technology with Time',
            'description' => 'Enterprise-grade cybersecurity, software development, cloud infrastructure and HR solutions from Tech4TIME. Orchestrate, build, maintain and protect your business.',
            'share_title' => 'Tech4TIME | Orchestrating Technology with Time',
        ],
        'hero' => [
            'title'     => 'Orchestrating Technology with Time',
            /* The phrase drawn in the accent colour. See home_hero_title(). */
            'accent'    => 'Technology',
            'cta_label' => 'Explore All Services',
            'cta_href'  => '/pages/services/',
        ],
        'badges' => [
            'status' => 'shown',
            'items'  => [],
        ],
        'tags' => [
            'status' => 'shown',
            'items'  => [],
        ],
        'terminal' => [
            'status'  => 'shown',
            'title'   => 'tech4time@soc:~',
            /* The one-line description that stands in for the panel, which is
               aria-hidden. It is not decoration: it is what a screen reader
               reads instead of the whole session. */
            'summary' => 'Illustration: a security operations console showing twelve connected agents and two high-severity alerts in the last 24 hours.',
            'items'   => [],
        ],
        'capabilities' => [
            'status' => 'shown',
            'title'  => 'Our Technical Domains',
            'lead'   => 'The principles and practices we cherish from our roots throughout the endeavour of time.',
            'items'  => [],
        ],
        'services' => [
            'status'  => 'shown',
            'eyebrow' => 'Our Services',
            'title'   => 'Complete Technology Solutions',
            'lead'    => 'Tech4TIME provides end-to-end IT services from Software Development, Cybersecurity and Cloud Infrastructure, along with Human Resource Provisioning — which we also call Human Resource as a Service (HRaaS).',
            /* What the Service ItemList in the <head> calls itself. Separate
               from the band's own heading because it is addressed to a search
               engine rather than to a reader, and the two have never said the
               same thing. Each Service node inside the list takes its name,
               description and url from the card — one source, so the six cards
               and the six schema entries cannot drift apart again. They had:
               the card read "SOC & CIRT" while the schema read "SOC and
               CIRT". */
            'schema_name'        => 'Tech4TIME technology services',
            'schema_description' => 'End-to-end IT services from software development, cybersecurity and cloud infrastructure to human resource provisioning.',
            'items'   => [],
        ],
        'destinations' => [
            'status'  => 'shown',
            'eyebrow' => 'Explore Tech4TIME',
            'title'   => 'Get to Know Us',
            'lead'    => 'Three ways in — who we are, what we deliver, and the track record behind it.',
            'items'   => [],
        ],
        'cta' => [
            'status' => 'shown',
            'icon'   => 'rocket',
            /* Two lines. The newline becomes a <br>; see home_cta_title(). */
            'title'  => "Transform Your Digital Landscape\nwith Expert Technology Solutions",
            'text'   => 'From software development to cybersecurity — complete IT services for your business growth.',
            'label'  => 'Start Your Project',
            'href'   => '/pages/contact/',
        ],
    ];
}

/**
 * Bring a document to the current shape, whatever it arrived as.
 *
 * One level of merge per band, then every list through its own row-filler.
 * Rows are renumbered with array_values() because the editor posts them keyed
 * by position and a removed row leaves a hole.
 */
function home_normalise(array $data): array
{
    $defaults = home_defaults();

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

    foreach (HOME_BANDS as $band) {
        $data[$band]['status'] =
            ($data[$band]['status'] ?? 'shown') === 'hidden' ? 'hidden' : 'shown';
    }

    foreach (HOME_LISTS as $band => $filler) {
        $rows = is_array($data[$band]['items'] ?? null) ? $data[$band]['items'] : [];
        $data[$band]['items'] = array_map(
            $filler,
            array_values(array_filter($rows, 'is_array'))
        );
    }

    return home_identify($data);
}

/**
 * Give every row an id, unique within its own list.
 *
 * Same contract as about_identify(): a row added by the Add button has nothing
 * to be named after and gets the placeholder; once it has a name the
 * placeholder is replaced; a real id is never re-minted, because it is the
 * handle a fragment link, an upload and a test all hold the row by.
 */
function home_identify(array $data): array
{
    foreach (HOME_LISTS as $band => $_filler) {
        $taken = [];
        foreach ($data[$band]['items'] as $i => $row) {
            $id   = trim((string)($row['id'] ?? ''));
            $name = home_row_name($band, $row);

            $provisional = $id === ''
                || preg_match('/^' . HOME_ID_PLACEHOLDER . '(-\d+)?$/', $id) === 1;

            if (($provisional && $name !== '') || in_array($id, $taken, true)) {
                $id = home_slug($name, $taken);
            } elseif ($id === '') {
                $id = home_slug('', $taken);
            }

            $data[$band]['items'][$i]['id'] = $id;
            $taken[] = $id;
        }
    }

    return $data;
}

/** What a row's id is minted from, whichever list it is in. */
function home_row_name(string $band, array $row): string
{
    return trim((string)match ($band) {
        'badges', 'tags' => $row['label'] ?? '',
        'terminal'       => $row['text'] ?? '',
        default          => $row['title'] ?? '',
    });
}

/** A hero badge: the four headline disciplines under the title. */
function home_badge_defaults(array $row): array
{
    return $row + [
        'id' => '', 'icon' => '', 'label' => '', 'status' => 'shown',
    ];
}

/** A hero tag: the smaller pills under the badges. Same shape. */
function home_tag_defaults(array $row): array
{
    return $row + [
        'id' => '', 'icon' => '', 'label' => '', 'status' => 'shown',
    ];
}

/**
 * One line of the hero terminal.
 *
 * The blinking caret at the end of the session is NOT one of these. It is
 * emitted by the renderer after the last line, so an operator cannot delete it
 * or end up with two — see home_terminal_lines() in the frontend's lib/home.php.
 */
function home_line_defaults(array $row): array
{
    $row += [
        'id'     => '',
        'kind'   => 'output',
        'tone'   => 'plain',
        'prompt' => HOME_PROMPT_DEFAULT,
        'text'   => '',
        'status' => 'shown',
    ];

    $row['kind'] = isset(HOME_LINE_KINDS[$row['kind']]) ? $row['kind'] : 'output';
    $row['tone'] = isset(HOME_LINE_TONES[$row['tone']]) ? $row['tone'] : 'plain';

    return $row;
}

/** A capability card: an icon and a title, nothing else. */
function home_capability_defaults(array $row): array
{
    return $row + [
        'id' => '', 'icon' => '', 'title' => '', 'status' => 'shown',
    ];
}

/**
 * A service card.
 *
 * 'link_hint' is the visually-hidden tail on the card's link — "for
 * Cybersecurity" — which turns six identical "View Services" links into six
 * distinguishable ones for anyone listing the links on the page. It is a field
 * rather than something derived from the title because the wording differs
 * from it: the card titled "IT Consultancy & Training" reads "and", not "&".
 */
function home_service_defaults(array $row): array
{
    return $row + [
        'id'        => '',
        'icon'      => '',
        'title'     => '',
        'text'      => '',
        'href'      => '',
        'label'     => 'View Services',
        'link_hint' => '',
        'status'    => 'shown',
    ];
}

/**
 * A "Get to Know Us" card: a picture, a title, a line and a button.
 *
 * Two picture records, not one. The illustrations are black line art on white
 * and the page keeps them on a light plate in both colour modes, so the dark
 * half is usually empty and the light one is used for both — exactly the
 * fallback a story row's logo takes. Uploading a dark half is what switches
 * that off, per card. See home_theme_pair() in the frontend's lib/home.php.
 */
function home_destination_defaults(array $row): array
{
    $row += [
        'id'        => '',
        'title'     => '',
        'text'      => '',
        'href'      => '',
        'label'     => 'Learn more',
        'link_hint' => '',
        'alt'       => '',
        'status'    => 'shown',
    ];

    $row['image']      = contract_image_defaults($row['image'] ?? []);
    $row['image_dark'] = contract_image_defaults($row['image_dark'] ?? []);

    return $row;
}

/** Only the rows of a list a visitor should see. */
function home_shown(array $data, string $band): array
{
    return array_values(array_filter(
        $data[$band]['items'] ?? [],
        static fn(array $row): bool => ($row['status'] ?? 'shown') !== 'hidden'
    ));
}

/** Whether a band of the page is shown at all. */
function home_band_shown(array $data, string $band): bool
{
    return ($data[$band]['status'] ?? 'shown') !== 'hidden';
}

function home_find(array $data, string $band, string $id): ?array
{
    foreach ($data[$band]['items'] ?? [] as $row) {
        if (($row['id'] ?? '') === $id) {
            return $row;
        }
    }
    return null;
}

/**
 * Every picture the document points at, as web paths, without duplicates.
 *
 * Both halves of every destination card, for the reason about_images() counts
 * both halves of a logo row: an unused-file sweep that ignored the dark half
 * would offer to delete a dark image the moment it was uploaded, because
 * nothing else in the document mentions it.
 */
function home_images(array $data): array
{
    $seen = [];

    foreach ($data['destinations']['items'] ?? [] as $row) {
        foreach ([$row['image']['src'] ?? '',      $row['image']['webp'] ?? '',
                  $row['image_dark']['src'] ?? '', $row['image_dark']['webp'] ?? ''] as $path) {
            $path = trim((string)$path);
            if ($path !== '') {
                $seen[$path] = true;
            }
        }
    }

    return array_keys($seen);
}

/** A URL-safe id from a name, unique against the ids already in use. */
function home_slug(string $name, array $taken = []): string
{
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-') ?: HOME_ID_PLACEHOLDER;

    $base = $slug;
    $n = 2;
    while (in_array($slug, $taken, true)) {
        $slug = $base . '-' . $n++;
    }
    return $slug;
}

/* ==========================================================================
   6. Revisions
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
   7. Normalising and re-sanitising on receipt
   ========================================================================== */

/**
 * Bring a document of any kind to the current shape.
 *
 * THIS IS A MATCH AND NOT A TERNARY, DELIBERATELY. What stood here was
 *
 *     $document === 'careers' ? careers_normalise(...) : contact_normalise(...)
 *
 * written three times over in the frontend's api/publish.php, and it had a
 * default: anything that was not careers was treated as contact. A third
 * document would have passed every check the endpoint makes -- signature,
 * timestamp, revision, contract version -- and then overwritten the contact
 * page with itself. The refusal has to be the default, not the fallthrough.
 *
 * @throws RuntimeException on a name CONTRACT_DOCUMENTS does not list.
 */
function contract_normalise(string $document, array $data): array
{
    return match ($document) {
        'careers' => careers_normalise($data),
        'contact' => contact_normalise($data),
        'company' => company_normalise($data),
        'about'   => about_normalise($data),
        'home'    => home_normalise($data),
        default   => throw new RuntimeException('Unknown document: ' . $document),
    };
}

/**
 * Run every rich field of a document back through the sanitiser.
 *
 * The receiving side calls this on a payload it has just verified, because a
 * signature proves where something came from and not what is inside it. If the
 * backend is ever compromised, the public site should still not render script.
 *
 * Driven off CAREERS_RICH_FIELDS, CONTACT_RICH_FIELDS, COMPANY_RICH_FIELDS and
 * ABOUT_ROW_RICH_FIELDS rather than a list of
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

    if ($document === 'company') {
        foreach (COMPANY_RICH_FIELDS as $section => $fields) {
            foreach ($fields as $field) {
                $data[$section][$field] =
                    rt_sanitise_html((string)($data[$section][$field] ?? ''));
            }
        }
        return $data;
    }

    /* The about page's rich text hangs off rows, not bands — one prose block
       per story section. Same walk as careers, one level deeper because the
       list it belongs to is named rather than assumed. */
    if ($document === 'about') {
        foreach (ABOUT_ROW_RICH_FIELDS as $band => $fields) {
            foreach ($data[$band]['items'] ?? [] as $i => $row) {
                foreach ($fields as $field) {
                    $data[$band]['items'][$i][$field] =
                        rt_sanitise_html((string)($row[$field] ?? ''));
                }
            }
        }
        return $data;
    }

    /* The home page has no rich text at all — see HOME_ROW_RICH_FIELDS. It
       still needs a branch, and the branch still has to be explicit: the
       default below is a refusal, so "nothing to sanitise" and "document I do
       not know" must not arrive at the same line. A publish of a document this
       file has never heard of is a bug or an attack, and is refused; a publish
       of the home page is neither, and passes through untouched. */
    if ($document === 'home') {
        return $data;
    }

    throw new RuntimeException('Unknown document: ' . $document);
}
