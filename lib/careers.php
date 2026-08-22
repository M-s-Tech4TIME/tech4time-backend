<?php
/**
 * Tech4TIME — careers data access.
 *
 * Shared by the public page (pages/careers/index.php) and the admin editor
 * (admin/index.php). Not reachable over HTTP: .htaccess forbids /lib/.
 *
 * Reading and writing the file is lib/store.php; escaping and rich-text
 * sanitising is lib/html.php. What is left here is the shape of a job post.
 *
 * WHAT THE SHAPE IS
 *   {
 *     "cv_form_url": "https://forms.gle/…",   speculative applications
 *     "updated":     "2026-08-21T…",          set on every save
 *     "jobs": [ { …see FIELDS below… } ]
 *   }
 */

declare(strict_types=1);

require_once __DIR__ . '/html.php';
require_once __DIR__ . '/store.php';

const CAREERS_FILE = __DIR__ . '/../content/careers.json';

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
   changes it on the page; changing a key would orphan existing data. */
const CAREERS_SECTIONS = [
    'about'            => 'About the Role',
    'responsibilities' => 'Key Responsibilities',
    'requirements'     => 'Required Skills & Experience',
    'must_have'        => 'Must Have',
    'nice_to_have'     => 'Nice to Have',
    'certifications'   => 'Certifications',
    'offers'           => 'What We Offer',
];

/* ------------------------------------------------------------------- read */

/**
 * Load the file, or a usable empty structure if it is missing or unreadable.
 *
 * Never throws. A careers page that renders "no openings" because the data
 * file is unreadable is wrong, but a careers page that renders a PHP error is
 * worse — and the visitor can act on the first one.
 */
function careers_load(): array
{
    $empty = ['cv_form_url' => '', 'updated' => '', 'jobs' => []];

    $data = store_read(CAREERS_FILE);
    if ($data === null) {
        return $empty;
    }

    $data += $empty;
    $data['jobs'] = is_array($data['jobs'] ?? null) ? array_values($data['jobs']) : [];
    $data['jobs'] = array_map('careers_migrate', $data['jobs']);

    return $data;
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

/* ------------------------------------------------------------------ write */

/** Stamp the save time and hand the file to store_write(). */
function careers_save(array $data): bool
{
    $data['updated'] = gmdate('c');

    return store_write(CAREERS_FILE, $data);
}

/* ---------------------------------------------------------- HTML sanitising

   Everything the editor produces passes through careers_sanitise_html() before
   it is stored, and what comes out is the only HTML the careers page ever
   prints unescaped.

   The parser itself lives in lib/html.php, because the contact editor needs
   exactly the same guarantees. These names stay so that every caller here and
   in the admin reads the way it always did.
   -------------------------------------------------------------------------- */

/** The class values the editor may write. Kept as an alias: admin.css and
    careers.css both mirror this list, and both name it. */
const CAREERS_ALLOWED_CLASSES = RT_ALLOWED_CLASSES;

function careers_sanitise_html(string $html): string
{
    return rt_sanitise_html($html);
}

function careers_safe_href(string $href): ?string
{
    return rt_safe_href($href);
}

/* ------------------------------------------------------------------ legacy */

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

/**
 * Validate one job. Returns a list of human-readable problems.
 *
 * Deliberately permissive about the body fields: an empty responsibilities
 * list is a thin job post, not an invalid one, and blocking a save over it
 * would just teach whoever is editing to type a placeholder.
 */
function careers_validate(array $job): array
{
    $errors = [];

    if (trim((string)($job['title'] ?? '')) === '') {
        $errors[] = 'A job title is required.';
    }

    $url = trim((string)($job['apply_url'] ?? ''));
    if ($url === '') {
        $errors[] = 'An apply link is required — without one the post has no way to apply.';
    } elseif (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
        $errors[] = 'The apply link must be a full URL starting with https://';
    }

    foreach (['posted' => 'Posted date', 'closes' => 'Closing date'] as $key => $label) {
        $value = trim((string)($job[$key] ?? ''));
        if ($value !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $errors[] = "$label must be written as YYYY-MM-DD.";
        }
    }

    if (!in_array($job['status'] ?? 'open', ['open', 'draft'], true)) {
        $errors[] = 'Status must be either open or draft.';
    }

    return $errors;
}

/* -------------------------------------------------------------- rendering */

/** The one-line summary a listing shows: "Full-Time · On-site · Dhaka". */
function careers_meta_line(array $job): array
{
    return array_values(array_filter([
        trim((string)($job['employment_type'] ?? '')),
        trim((string)($job['work_arrangement'] ?? '')),
        trim((string)($job['location'] ?? '')),
    ], static fn(string $v): bool => $v !== ''));
}

/**
 * Google's JobPosting schema for one role.
 *
 * This is what puts a post into Google Jobs rather than only into ordinary
 * results, so it is worth keeping honest: validThrough is only emitted when a
 * closing date is actually set, because a wrong one gets the post dropped.
 */
function careers_job_posting(array $job): array
{
    /* Google wants the description as HTML, and the stored markup is already
       sanitised, so it goes in as it is. */
    $description = [];
    foreach (CAREERS_SECTIONS as $key => $label) {
        $body = trim((string)($job[$key] ?? ''));
        if ($body === '') {
            continue;
        }
        $description[] = '<h3>' . h($label) . '</h3>' . $body;
    }

    $posting = [
        '@context' => 'https://schema.org',
        '@type' => 'JobPosting',
        'title' => (string)($job['title'] ?? ''),
        'description' => implode('', $description),
        'identifier' => [
            '@type' => 'PropertyValue',
            'name' => 'Tech4TIME',
            'value' => (string)($job['id'] ?? ''),
        ],
        'hiringOrganization' => [
            '@type' => 'Organization',
            'name' => 'Tech4TIME',
            'sameAs' => 'https://tech4time.bd',
            'logo' => 'https://tech4time.bd/assets/images/logo/logo-light-360.png',
        ],
        'jobLocation' => [
            '@type' => 'Place',
            'address' => [
                '@type' => 'PostalAddress',
                'streetAddress' => '278/3, Manikdi',
                'addressLocality' => 'Dhaka',
                'postalCode' => '1206',
                'addressCountry' => 'BD',
            ],
        ],
        'directApply' => false,
    ];

    if (($job['posted'] ?? '') !== '') {
        $posting['datePosted'] = (string)$job['posted'];
    }
    if (($job['closes'] ?? '') !== '') {
        $posting['validThrough'] = (string)$job['closes'] . 'T23:59:59+06:00';
    }

    /* Schema.org expects the enumerated form, not the prose one. */
    $type = strtoupper(str_replace([' ', '-'], '_', trim((string)($job['employment_type'] ?? ''))));
    if (in_array($type, ['FULL_TIME', 'PART_TIME', 'CONTRACTOR', 'TEMPORARY',
                         'INTERN', 'VOLUNTEER', 'PER_DIEM', 'OTHER'], true)) {
        $posting['employmentType'] = $type;
    }

    if (stripos((string)($job['work_arrangement'] ?? ''), 'remote') !== false) {
        $posting['jobLocationType'] = 'TELECOMMUTE';
    }

    return $posting;
}
