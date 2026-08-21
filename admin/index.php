<?php
/**
 * Tech4TIME — job post editor.
 *
 * Add, edit, reorder and remove the posts that appear on /pages/careers/.
 * Everything is stored in content/careers.json; there is no database.
 *
 * PROTECTING THIS PAGE
 * This directory must be locked down in cPanel: Directory Privacy -> /admin ->
 * add a user with a password. Apache then asks for it before any of this code
 * runs, which is a great deal safer than a login form written here.
 *
 * The check below refuses to run if it cannot see that protection, so this
 * cannot be left open by accident. If your host does not pass the
 * authenticated user through to PHP — some FastCGI setups do not — the page
 * will refuse even though the password prompt works. That is the one case for
 * setting ADMIN_REQUIRE_HTTP_AUTH to false, and only once you have confirmed
 * the prompt genuinely appears.
 */

declare(strict_types=1);

require __DIR__ . '/../lib/careers.php';

const ADMIN_REQUIRE_HTTP_AUTH = true;

/* ------------------------------------------------------------------- auth */

/** Apache exposes the authenticated user differently under mod_php and CGI. */
function admin_http_user(): string
{
    foreach (['REMOTE_USER', 'REDIRECT_REMOTE_USER', 'PHP_AUTH_USER'] as $key) {
        $value = trim((string)($_SERVER[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

$user = admin_http_user();

if (ADMIN_REQUIRE_HTTP_AUTH && $user === '') {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<meta name="robots" content="noindex, nofollow">'
       . '<title>Admin is not protected</title>'
       . '<link rel="stylesheet" href="/assets/css/base.css">'
       . '<link rel="stylesheet" href="/assets/css/theme.css">'
       . '<link rel="stylesheet" href="/assets/css/layout.css">'
       . '<link rel="stylesheet" href="/assets/css/components.css">'
       . '<link rel="stylesheet" href="/assets/css/admin.css">'
       . '</head><body class="page"><main class="admin"><div class="container">'
       . '<div class="admin__notice admin__notice--error">'
       . '<h1>This editor is not password protected</h1>'
       . '<p>It has refused to load rather than let anyone edit your job posts.</p>'
       . '<p>In cPanel, open <strong>Directory Privacy</strong>, select the '
       . '<strong>admin</strong> folder, tick "Password protect this directory", '
       . 'and add a user. Then reload this page.</p>'
       . '<p class="admin__fineprint">If the browser already asks you for a password '
       . 'and you still see this, your host is not passing the authenticated user '
       . 'through to PHP. Set ADMIN_REQUIRE_HTTP_AUTH to false at the top of '
       . 'admin/index.php — but only once you have confirmed the prompt appears.</p>'
       . '</div></div></main></body></html>';
    exit;
}

/* ------------------------------------------------------------------- CSRF */

session_start();

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

/**
 * A password prompt proves who you are, not that you meant to click this.
 * Without a token, a page on another site could post here using the browser's
 * stored credentials and delete a job post.
 */
function admin_check_csrf(): void
{
    $sent = (string)($_POST['csrf'] ?? '');
    if (!hash_equals((string)$_SESSION['csrf'], $sent)) {
        http_response_code(400);
        exit('Session expired. Go back, reload the page and try again.');
    }
}

/* ---------------------------------------------------------------- actions */

$data = careers_load();
$notice = '';
$noticeType = 'ok';
$errors = [];

/** Collect one job from the submitted form. */
function admin_job_from_post(array $existing = []): array
{
    $job = $existing;

    foreach (CAREERS_TEXT_FIELDS as $field) {
        if ($field === 'id') {
            continue;
        }
        $job[$field] = trim((string)($_POST[$field] ?? ''));
    }

    foreach (CAREERS_LIST_FIELDS as $field) {
        $job[$field] = careers_lines((string)($_POST[$field] ?? ''));
    }

    foreach (CAREERS_PROSE_FIELDS as $field) {
        $job[$field] = careers_paragraphs((string)($_POST[$field] ?? ''));
    }

    return $job;
}

$action = (string)($_POST['action'] ?? $_GET['action'] ?? 'list');
$editId = (string)($_GET['id'] ?? $_POST['id'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_check_csrf();

    if ($action === 'save') {
        $existing = $editId !== '' ? careers_find($data, $editId) : null;
        $job = admin_job_from_post($existing ?? []);
        $errors = careers_validate($job);

        if (!$errors) {
            if ($existing) {
                $job['id'] = $existing['id'];
                foreach ($data['jobs'] as $i => $row) {
                    if (($row['id'] ?? '') === $existing['id']) {
                        $data['jobs'][$i] = $job;
                        break;
                    }
                }
                $notice = 'Saved “' . $job['title'] . '”.';
            } else {
                $taken = array_map(static fn($j) => (string)($j['id'] ?? ''), $data['jobs']);
                $job['id'] = careers_slug($job['title'], $taken);
                if (($job['posted'] ?? '') === '') {
                    $job['posted'] = gmdate('Y-m-d');
                }
                $data['jobs'][] = $job;
                $notice = 'Added “' . $job['title'] . '”.';
            }

            if (careers_save($data)) {
                header('Location: ?saved=' . rawurlencode($notice));
                exit;
            }
            $errors[] = 'Could not write content/careers.json. Check the file is writable by PHP.';
        }

        /* Fall through and redraw the form with what was typed. */
        $action = 'edit';
        $formJob = $job;
    }

    if ($action === 'delete') {
        $job = careers_find($data, $editId);
        $data['jobs'] = array_values(array_filter(
            $data['jobs'],
            static fn($j) => ($j['id'] ?? '') !== $editId
        ));
        careers_save($data);
        header('Location: ?saved=' . rawurlencode('Deleted “' . ($job['title'] ?? $editId) . '”.'));
        exit;
    }

    if ($action === 'toggle') {
        foreach ($data['jobs'] as $i => $row) {
            if (($row['id'] ?? '') === $editId) {
                $now = ($row['status'] ?? 'open') === 'open' ? 'draft' : 'open';
                $data['jobs'][$i]['status'] = $now;
                careers_save($data);
                header('Location: ?saved=' . rawurlencode(
                    '“' . ($row['title'] ?? '') . '” is now ' .
                    ($now === 'open' ? 'live on the site' : 'a draft, hidden from visitors') . '.'
                ));
                exit;
            }
        }
    }

    if ($action === 'move') {
        $step = ($_POST['direction'] ?? '') === 'up' ? -1 : 1;
        foreach ($data['jobs'] as $i => $row) {
            if (($row['id'] ?? '') === $editId) {
                $to = $i + $step;
                if ($to >= 0 && $to < count($data['jobs'])) {
                    [$data['jobs'][$i], $data['jobs'][$to]] =
                        [$data['jobs'][$to], $data['jobs'][$i]];
                    careers_save($data);
                }
                break;
            }
        }
        header('Location: ?');
        exit;
    }

    if ($action === 'settings') {
        $url = trim((string)($_POST['cv_form_url'] ?? ''));
        if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
            $errors[] = 'The CV form link must be a full URL starting with https://';
        } else {
            $data['cv_form_url'] = $url;
            careers_save($data);
            header('Location: ?saved=' . rawurlencode('Saved the CV form link.'));
            exit;
        }
    }
}

if (isset($_GET['saved'])) {
    $notice = (string)$_GET['saved'];
}

if ($errors) {
    $noticeType = 'error';
}

/* ---------------------------------------------------------------- helpers */

function admin_textarea_value(array $job, string $field): string
{
    $values = $job[$field] ?? [];
    if (!is_array($values)) {
        return '';
    }
    /* Paragraphs are separated by a blank line so they survive a round trip
       through the textarea; bullets are one per line. */
    $glue = in_array($field, CAREERS_PROSE_FIELDS, true) ? "\n\n" : "\n";
    return implode($glue, array_map('strval', $values));
}

$editing = null;
if ($action === 'edit' || $action === 'new') {
    $editing = $formJob ?? ($editId !== '' ? careers_find($data, $editId) : null) ?? [];
}

$fieldLabels = [
    'about'            => ['About the Role', 'One paragraph per block, separated by a blank line.'],
    'responsibilities' => ['Key Responsibilities', 'One bullet per line.'],
    'requirements'     => ['Required Skills & Experience', 'One bullet per line.'],
    'must_have'        => ['Must Have', 'One bullet per line. Leave empty to hide this section.'],
    'nice_to_have'     => ['Nice to Have', 'One bullet per line. Leave empty to hide this section.'],
    'certifications'   => ['Certifications', 'One paragraph per block, separated by a blank line.'],
    'offers'           => ['What We Offer', 'One bullet per line.'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Job posts | Tech4TIME admin</title>
<link rel="icon" href="/assets/images/favicon/favicon.ico" sizes="any">
<link rel="stylesheet" href="/assets/css/base.css">
<link rel="stylesheet" href="/assets/css/theme.css">
<link rel="stylesheet" href="/assets/css/layout.css">
<link rel="stylesheet" href="/assets/css/components.css">
<link rel="stylesheet" href="/assets/css/admin.css">
<script src="/assets/js/theme-init.js"></script>
</head>
<body class="page">
<main class="admin">
  <div class="container">

    <header class="admin__header">
      <div>
        <h1 class="admin__title">Job posts</h1>
        <p class="admin__subtitle">
          Editing <code>content/careers.json</code>.
          Changes go live on <a href="/pages/careers/">the careers page</a> immediately.
        </p>
      </div>
      <?php if ($user !== ''): ?>
        <p class="admin__user">Signed in as <strong><?= h($user) ?></strong></p>
      <?php endif; ?>
    </header>

    <?php if ($notice !== '' && !$errors): ?>
      <p class="admin__notice admin__notice--ok"><?= h($notice) ?></p>
    <?php endif; ?>

    <?php if ($errors): ?>
      <div class="admin__notice admin__notice--error">
        <p><strong>Not saved.</strong></p>
        <ul>
          <?php foreach ($errors as $error): ?>
            <li><?= h($error) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

<?php if ($editing !== null): ?>
    <!-- ============================ editor ============================ -->
    <form class="admin__form" method="post" action="?">
      <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= h((string)($editing['id'] ?? '')) ?>">

      <h2 class="admin__section-title">
        <?= ($editing['id'] ?? '') !== '' ? 'Edit post' : 'New post' ?>
      </h2>

      <div class="admin__grid">
        <label class="admin__field admin__field--wide">
          <span class="admin__label">Job title</span>
          <input class="admin__input" type="text" name="title" required
                 value="<?= h((string)($editing['title'] ?? '')) ?>">
        </label>

        <label class="admin__field">
          <span class="admin__label">Employment type</span>
          <input class="admin__input" type="text" name="employment_type"
                 list="employment-types" placeholder="Full-Time"
                 value="<?= h((string)($editing['employment_type'] ?? '')) ?>">
          <span class="admin__hint">Full-Time, Part-Time, Contractor, Intern…</span>
        </label>
        <datalist id="employment-types">
          <option value="Full-Time"><option value="Part-Time"><option value="Contractor">
          <option value="Temporary"><option value="Intern">
        </datalist>

        <label class="admin__field">
          <span class="admin__label">Work arrangement</span>
          <input class="admin__input" type="text" name="work_arrangement"
                 placeholder="On-site"
                 value="<?= h((string)($editing['work_arrangement'] ?? '')) ?>">
        </label>

        <label class="admin__field">
          <span class="admin__label">Location</span>
          <input class="admin__input" type="text" name="location"
                 placeholder="Dhaka, Bangladesh"
                 value="<?= h((string)($editing['location'] ?? 'Dhaka, Bangladesh')) ?>">
        </label>

        <label class="admin__field">
          <span class="admin__label">Salary</span>
          <input class="admin__input" type="text" name="salary" placeholder="Negotiable"
                 value="<?= h((string)($editing['salary'] ?? '')) ?>">
        </label>

        <label class="admin__field">
          <span class="admin__label">Posted</span>
          <input class="admin__input" type="date" name="posted"
                 value="<?= h((string)($editing['posted'] ?? gmdate('Y-m-d'))) ?>">
        </label>

        <label class="admin__field">
          <span class="admin__label">Applications close</span>
          <input class="admin__input" type="date" name="closes"
                 value="<?= h((string)($editing['closes'] ?? '')) ?>">
          <span class="admin__hint">Optional. Google drops a posting once this date passes, so leave it empty rather than guessing.</span>
        </label>

        <label class="admin__field admin__field--wide">
          <span class="admin__label">Apply link</span>
          <input class="admin__input" type="url" name="apply_url" required
                 placeholder="https://forms.gle/…"
                 value="<?= h((string)($editing['apply_url'] ?? '')) ?>">
          <span class="admin__hint">The Google Form for this role. Applicants go straight here.</span>
        </label>

        <label class="admin__field">
          <span class="admin__label">Status</span>
          <select class="admin__input" name="status">
            <option value="open"<?= ($editing['status'] ?? 'open') === 'open' ? ' selected' : '' ?>>Open — visible on the site</option>
            <option value="draft"<?= ($editing['status'] ?? '') === 'draft' ? ' selected' : '' ?>>Draft — hidden from visitors</option>
          </select>
        </label>
      </div>

      <?php foreach ($fieldLabels as $field => [$label, $hint]): ?>
        <label class="admin__field admin__field--wide">
          <span class="admin__label"><?= h($label) ?></span>
          <textarea class="admin__input admin__textarea" name="<?= h($field) ?>" rows="7"><?= h(admin_textarea_value($editing, $field)) ?></textarea>
          <span class="admin__hint"><?= h($hint) ?></span>
        </label>
      <?php endforeach; ?>

      <div class="admin__actions">
        <button class="btn btn--primary btn--lg" type="submit">Save post</button>
        <a class="btn btn--ghost btn--lg" href="?">Cancel</a>
      </div>
    </form>

<?php else: ?>
    <!-- ============================= list ============================= -->
    <div class="admin__toolbar">
      <a class="btn btn--primary" href="?action=new">Add a job post</a>
      <span class="admin__count">
        <?= count($data['jobs']) ?> post<?= count($data['jobs']) === 1 ? '' : 's' ?>,
        <?= count(careers_open_jobs($data)) ?> live
      </span>
    </div>

    <?php if (!$data['jobs']): ?>
      <p class="admin__empty">
        No posts yet. The careers page is showing its “Stay Tuned for
        Opportunities” state and inviting visitors to send a CV instead.
      </p>
    <?php endif; ?>

    <ul class="admin__list">
      <?php foreach ($data['jobs'] as $index => $job): ?>
        <li class="admin-row">
          <div class="admin-row__main">
            <h2 class="admin-row__title"><?= h((string)($job['title'] ?? 'Untitled')) ?></h2>
            <p class="admin-row__meta">
              <?= h(implode(' · ', careers_meta_line($job))) ?>
              <?php if (($job['closes'] ?? '') !== ''): ?>
                · closes <?= h((string)$job['closes']) ?>
              <?php endif; ?>
            </p>
          </div>

          <span class="admin-row__status admin-row__status--<?= h((string)($job['status'] ?? 'open')) ?>">
            <?= ($job['status'] ?? 'open') === 'open' ? 'Live' : 'Draft' ?>
          </span>

          <div class="admin-row__actions">
            <a class="btn btn--secondary" href="?action=edit&amp;id=<?= h(rawurlencode((string)($job['id'] ?? ''))) ?>">Edit</a>

            <form method="post" action="?">
              <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?= h((string)($job['id'] ?? '')) ?>">
              <button class="btn btn--ghost" type="submit">
                <?= ($job['status'] ?? 'open') === 'open' ? 'Unpublish' : 'Publish' ?>
              </button>
            </form>

            <form method="post" action="?">
              <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
              <input type="hidden" name="action" value="move">
              <input type="hidden" name="id" value="<?= h((string)($job['id'] ?? '')) ?>">
              <button class="btn btn--ghost" type="submit" name="direction" value="up"
                      aria-label="Move up"<?= $index === 0 ? ' disabled' : '' ?>>↑</button>
              <button class="btn btn--ghost" type="submit" name="direction" value="down"
                      aria-label="Move down"<?= $index === count($data['jobs']) - 1 ? ' disabled' : '' ?>>↓</button>
            </form>

            <form method="post" action="?"
                  onsubmit="return confirm('Delete this post permanently?');">
              <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= h((string)($job['id'] ?? '')) ?>">
              <button class="btn btn--ghost admin-row__delete" type="submit">Delete</button>
            </form>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>

    <!-- =========================== settings =========================== -->
    <form class="admin__settings" method="post" action="?">
      <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
      <input type="hidden" name="action" value="settings">

      <h2 class="admin__section-title">Speculative applications</h2>
      <label class="admin__field admin__field--wide">
        <span class="admin__label">CV form link</span>
        <input class="admin__input" type="url" name="cv_form_url"
               placeholder="https://forms.gle/…"
               value="<?= h((string)($data['cv_form_url'] ?? '')) ?>">
        <span class="admin__hint">
          Where “Ready to take the chance?” sends people. Shown on the careers
          page whether or not any roles are open — and it is the only thing on
          the page when none are.
        </span>
      </label>
      <div class="admin__actions">
        <button class="btn btn--secondary" type="submit">Save link</button>
      </div>
    </form>
<?php endif; ?>

    <footer class="admin__footer">
      <p>
        Last saved <?= h((string)($data['updated'] ?? 'never')) ?>.
        A backup of the previous version is kept as
        <code>content/careers.json.bak</code>.
      </p>
    </footer>

  </div>
</main>
</body>
</html>
