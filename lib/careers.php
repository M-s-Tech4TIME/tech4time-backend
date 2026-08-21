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

/* ---------------------------------------------------------- HTML sanitising

   Everything the editor produces passes through careers_sanitise_html() before
   it is stored, and what comes out is the only HTML the careers page ever
   prints unescaped.

   WHY THIS IS WRITTEN BY HAND
   No DOM extension on this host — DOMDocument does not exist, the same way
   mb_strlen did not. So this parses the markup itself.

   HOW IT STAYS SAFE WITHOUT A PARSER
   It never passes anything through. It walks the input, and for each tag it
   recognises it WRITES A NEW ONE from an allow-list of names and attributes.
   Anything it does not recognise — a tag, an attribute, a stray angle bracket
   — is discarded rather than copied. So the output cannot contain a construct
   this file does not explicitly know how to emit, which is a much smaller
   thing to get right than trying to spot every dangerous input.

   WHY NO style ATTRIBUTE
   The site's CSP is style-src 'self', which blocks inline styles. An editor
   that wrote style="text-align:center" would look correct in the admin and do
   nothing at all on the public page. Alignment is therefore a class from a
   fixed list, which is also why the class attribute is allow-listed by value
   and not merely by name.
   -------------------------------------------------------------------------- */

/** Tag => whether it may contain text (block/inline) rather than being empty. */
const CAREERS_ALLOWED_TAGS = [
    'p' => true, 'br' => false, 'strong' => true, 'em' => true, 'u' => true,
    'ul' => true, 'ol' => true, 'li' => true, 'a' => true,
];

/** Editors emit these; they mean the same thing as the tags we keep. */
const CAREERS_TAG_ALIASES = ['b' => 'strong', 'i' => 'em', 'div' => 'p'];

/**
 * Elements whose CONTENT must go with them.
 *
 * Dropping the tags of <script>alert(1)</script> and keeping what was between
 * them leaves "alert(1)" sitting in the page as visible text. Escaped, so it
 * cannot run — but it is not text anyone wrote, and it should not appear.
 * These are skipped whole, contents included.
 */
const CAREERS_DROP_CONTENT_TAGS = [
    'script', 'style', 'textarea', 'title', 'noscript', 'template',
    'iframe', 'object', 'embed', 'svg', 'math', 'head', 'select', 'option',
];

/** The only class values that survive, and the only ones the CSS styles. */
const CAREERS_ALLOWED_CLASSES = ['ta-left', 'ta-center', 'ta-right', 'ta-justify'];

/** Tags that may carry an alignment class. */
const CAREERS_ALIGNABLE = ['p', 'li', 'ul', 'ol'];

function careers_safe_href(string $href): ?string
{
    $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

    /* Control characters are how "java\tscript:" gets past a naive check. */
    $href = preg_replace('/[\x00-\x20\x7F]/', '', $href) ?? '';

    if ($href === '') {
        return null;
    }
    if (preg_match('#^(https?://|mailto:)#i', $href)) {
        return $href;
    }
    /* Site-relative links are fine; anything else — javascript:, data:, vbscript: — is not. */
    if (str_starts_with($href, '/') && !str_starts_with($href, '//')) {
        return $href;
    }
    return null;
}

function careers_sanitise_html(string $html): string
{
    $out = '';
    $open = [];
    $skip = '';      /* set to a tag name while its content is being discarded */
    $depth = 0;

    /* Split on tags, keeping them, so text and markup alternate.
       The quoted-string alternation matters: without it this splits at the
       first ">" even when it sits inside an attribute value, and the tail of
       the tag spills out as text. It would still be escaped — this parser
       degrades to inert text, never to markup — but it would be text nobody
       typed. */
    $tokens = preg_split(
        '/(<[^>"\']*(?:(?:"[^"]*"|\'[^\']*\')[^>"\']*)*>)/',
        $html,
        -1,
        PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
    ) ?: [];

    foreach ($tokens as $token) {
        /* Inside a dropped element: consume everything to its closing tag. */
        if ($skip !== '') {
            if ($token !== '' && $token[0] === '<'
                && preg_match('#^</?\s*([a-zA-Z][a-zA-Z0-9]*)#', $token, $t)
                && strtolower($t[1]) === $skip
            ) {
                $depth += $token[1] === '/' ? -1 : 1;
                if ($depth <= 0) {
                    $skip = '';
                }
            }
            continue;
        }

        if ($token === '' || $token[0] !== '<') {
            /* Text. Decode first so an already-encoded entity is not encoded
               twice, then re-encode so no raw bracket can survive. */
            $out .= htmlspecialchars(
                html_entity_decode($token, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            );
            continue;
        }

        if (!preg_match('#^</?\s*([a-zA-Z][a-zA-Z0-9]*)([^>]*)>$#', $token, $m)) {
            continue;   /* comment, doctype, malformed — drop it */
        }

        $raw = strtolower($m[1]);
        $name = CAREERS_TAG_ALIASES[$raw] ?? $raw;
        $closing = $token[1] === '/';

        /* Self-closing forms of these carry no content to skip. */
        if (!$closing
            && in_array($raw, CAREERS_DROP_CONTENT_TAGS, true)
            && !str_ends_with(rtrim($token, '>'), '/')
        ) {
            $skip = $raw;
            $depth = 1;
            continue;
        }

        if (!isset(CAREERS_ALLOWED_TAGS[$name])) {
            continue;
        }

        if ($closing) {
            /* Close only if it is actually open, and unwind anything left
               open inside it, so the output stays balanced. */
            $at = array_search($name, $open, true);
            if ($at === false) {
                continue;
            }
            while (count($open) > $at) {
                $out .= '</' . array_pop($open) . '>';
            }
            continue;
        }

        if ($name === 'br') {
            $out .= '<br>';
            continue;
        }

        $out .= '<' . $name . careers_attributes($name, $m[2]) . '>';
        $open[] = $name;
    }

    while ($open) {
        $out .= '</' . array_pop($open) . '>';
    }

    /* An empty paragraph is what a stray Enter leaves behind. */
    $out = preg_replace('#<p[^>]*>(\s|&nbsp;|<br>)*</p>#', '', $out) ?? $out;

    return trim($out);
}

/** Rebuild the attributes a tag is allowed to keep, from scratch. */
function careers_attributes(string $tag, string $raw): string
{
    $attrs = '';

    preg_match_all(
        '/([a-zA-Z-]+)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/',
        $raw,
        $found,
        PREG_SET_ORDER
    );

    $seen = [];
    foreach ($found as $pair) {
        $key = strtolower($pair[1]);
        $value = trim($pair[2], "\"'");

        if (isset($seen[$key])) {
            continue;
        }

        if ($key === 'href' && $tag === 'a') {
            $href = careers_safe_href($value);
            if ($href !== null) {
                $attrs .= ' href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"';
                /* An external link the author cannot vet opens safely or not
                   at all. */
                if (preg_match('#^https?://#i', $href)) {
                    $attrs .= ' target="_blank" rel="noopener noreferrer"';
                }
                $seen[$key] = true;
            }
            continue;
        }

        if ($key === 'class' && in_array($tag, CAREERS_ALIGNABLE, true)) {
            $keep = array_values(array_intersect(
                preg_split('/\s+/', strtolower($value)) ?: [],
                CAREERS_ALLOWED_CLASSES
            ));
            if ($keep) {
                $attrs .= ' class="' . implode(' ', $keep) . '"';
                $seen[$key] = true;
            }
            continue;
        }

        /* Everything else — style, onclick, id, data-*, srcset — is dropped. */
    }

    return $attrs;
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
