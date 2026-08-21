<?php
/**
 * Tech4TIME — careers data access.
 *
 * Shared by the public page (pages/careers/index.php) and the admin editor
 * (admin/index.php). Not reachable over HTTP: .htaccess forbids /lib/.
 *
 * WHY A JSON FILE AND NOT A DATABASE
 * A handful of job posts, edited a few times a year, read on every page view.
 * A file read is faster than a database connection at this size, there is
 * nothing to provision on the host, and the whole dataset can be backed up by
 * downloading one file. The cost is that concurrent writes would clobber each
 * other — which matters for a comment system and does not matter for one
 * person editing job posts.
 *
 * WHAT THE SHAPE IS
 *   {
 *     "cv_form_url": "https://forms.gle/…",   speculative applications
 *     "updated":     "2026-08-21T…",          set on every save
 *     "jobs": [ { …see FIELDS below… } ]
 *   }
 */

declare(strict_types=1);

const CAREERS_FILE = __DIR__ . '/../content/careers.json';

/* Free-text single-line fields. */
const CAREERS_TEXT_FIELDS = [
    'id', 'title', 'employment_type', 'work_arrangement',
    'location', 'salary', 'posted', 'closes', 'status', 'apply_url',
];

/* Fields stored as an array of bullet points. */
const CAREERS_LIST_FIELDS = [
    'responsibilities', 'requirements', 'must_have', 'nice_to_have', 'offers',
];

/* Fields stored as an array of paragraphs. */
const CAREERS_PROSE_FIELDS = ['about', 'certifications'];

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

    if (!is_readable(CAREERS_FILE)) {
        return $empty;
    }

    $raw = file_get_contents(CAREERS_FILE);
    if ($raw === false) {
        return $empty;
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return $empty;
    }

    $data += $empty;
    $data['jobs'] = is_array($data['jobs'] ?? null) ? array_values($data['jobs']) : [];

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

/**
 * Write the file, atomically, keeping one generation of backup.
 *
 * The write goes to a temp file in the same directory and is then renamed
 * over the target. rename() within a filesystem is atomic, so a visitor
 * loading the page mid-save reads either the old file or the new one, never
 * a half-written one.
 */
function careers_save(array $data): bool
{
    $data['updated'] = gmdate('c');

    $json = json_encode(
        $data,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    if ($json === false) {
        return false;
    }

    if (is_file(CAREERS_FILE)) {
        @copy(CAREERS_FILE, CAREERS_FILE . '.bak');
    }

    $tmp = CAREERS_FILE . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
        return false;
    }

    if (!rename($tmp, CAREERS_FILE)) {
        @unlink($tmp);
        return false;
    }

    return true;
}

/* ----------------------------------------------------------- normalisation */

/** Turn a textarea into a list, one item per line, blanks dropped. */
function careers_lines(string $raw): array
{
    $out = [];
    foreach (preg_split('/\R/', $raw) ?: [] as $line) {
        $line = trim($line);
        if ($line !== '') {
            $out[] = $line;
        }
    }
    return $out;
}

/** Turn a textarea into paragraphs, split on blank lines. */
function careers_paragraphs(string $raw): array
{
    $out = [];
    foreach (preg_split('/\R\s*\R/', $raw) ?: [] as $para) {
        $para = trim(preg_replace('/\s+/', ' ', $para) ?? '');
        if ($para !== '') {
            $out[] = $para;
        }
    }
    return $out;
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

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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

/**
 * Google's JobPosting schema for one role.
 *
 * This is what puts a post into Google Jobs rather than only into ordinary
 * results, so it is worth keeping honest: validThrough is only emitted when a
 * closing date is actually set, because a wrong one gets the post dropped.
 */
function careers_job_posting(array $job): array
{
    $description = [];
    foreach (CAREERS_SECTIONS as $key => $label) {
        $values = $job[$key] ?? [];
        if (!is_array($values) || !$values) {
            continue;
        }
        $description[] = '<h3>' . h($label) . '</h3>';
        if (in_array($key, CAREERS_PROSE_FIELDS, true)) {
            foreach ($values as $para) {
                $description[] = '<p>' . h((string)$para) . '</p>';
            }
        } else {
            $description[] = '<ul><li>' . implode('</li><li>',
                array_map(static fn($v): string => h((string)$v), $values)) . '</li></ul>';
        }
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
