<?php
/**
 * Tech4TIME — careers data access.
 *
 * Reading and writing the file is lib/store.php; escaping and rich-text
 * sanitising is lib/html.php; the SHAPE of a job post is lib/contract.php,
 * which the frontend and the backend hold byte-identical. What is left here is
 * this side's own business with that shape.
 *
 * On THIS side that is: validation, and the save that publishes. The
 * JobPosting structured data is the frontend's, because the frontend is what
 * renders the page a search engine reads.
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
require_once __DIR__ . '/publish_client.php';

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
 * Write the record, then publish it. Returns whether the WRITE succeeded.
 *
 * THIS RECORD IS WRITTEN FIRST, ALWAYS.
 * It is the system of record; the live site holds a replica. If the push then
 * fails, the edit is safe here and can be sent again. Publishing first would
 * mean a live site ahead of the thing it is supposed to be a copy of.
 *
 * THE PUBLISH IS IN HERE RATHER THAN AT THE CALL SITES.
 * The job post editor alone calls this six times — save, delete, toggle, move,
 * settings — and a publish added to five of them is a publish somebody forgets
 * at the sixth. It cannot be forgotten from in here.
 *
 * The revision is minted here for the same reason. A save that forgot to
 * advance it is a save the live site refuses as stale, silently from the
 * operator's point of view.
 *
 * The outcome goes to publish_note(), which admin_redirect() reads. The return
 * value stays "did the write work", because that is the question every caller
 * was already asking and a publish failure is not a reason to redraw a form
 * somebody has just filled in.
 */
function careers_save(array $data): bool
{
    $data['updated']  = gmdate('c');
    $data['revision'] = contract_next_revision($data);

    if (!store_write(CAREERS_FILE, $data)) {
        return false;
    }

    publish_note(publish_push('careers', $data));

    return true;
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
