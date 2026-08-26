<?php
/**
 * Tech4TIME — careers data access.
 *
 * Reading and writing the file is lib/store.php; escaping and rich-text
 * sanitising is lib/html.php; the SHAPE of a job post is lib/contract.php,
 * which the frontend and the backend hold byte-identical. What is left here is
 * this side's own business with that shape.
 *
 *   backend    validation, and the save that publishes
 *   frontend   the JobPosting structured data the public page emits
 *
 * WHAT THE SHAPE IS
 *   {
 *     "cv_form_url": "https://forms.gle/…",   speculative applications
 *     "updated":     "2026-08-21T…",          set on every save
 *     "revision":    12,                      monotonic; see contract.php
 *     "jobs": [ { …see CAREERS_TEXT_FIELDS and CAREERS_RICH_FIELDS… } ]
 *   }
 */

declare(strict_types=1);

require_once __DIR__ . '/contract.php';
require_once __DIR__ . '/store.php';

const CAREERS_FILE = __DIR__ . '/../content/careers.json';

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
    return careers_normalise(store_read(CAREERS_FILE) ?? []);
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
function careers_save(array $data): bool
{
    $data['updated']  = gmdate('c');
    $data['revision'] = contract_next_revision($data);

    return store_write(CAREERS_FILE, $data);
}

/* ---------------------------------------------------------- HTML sanitising

   Everything the editor produces passes through careers_sanitise_html() before
   it is stored, and what comes out is the only HTML the careers page ever
   prints unescaped. The frontend runs it again on receipt: a signature proves
   a payload's origin, not its safety.

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

/* ------------------------------------------------------------- validation */

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
