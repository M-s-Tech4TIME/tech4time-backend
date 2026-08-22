<?php
/**
 * Tech4TIME — the admin shell.
 *
 * Everything every section of /admin/ needs before it can do anything: the
 * check that somebody is signed in, the session and CSRF token, the section
 * registry the icon rail is drawn from, and the page furniture around whichever
 * section is showing.
 *
 * Not reachable over HTTP: .htaccess forbids /lib/.
 *
 * PROTECTING THE ADMIN
 * This used to be cPanel's job. Directory Privacy put HTTP Basic auth in front
 * of the directory and admin_require_auth() checked only that Apache had filled
 * in REMOTE_USER — PHP never saw a password and never verified one.
 *
 * It is the application's own job now: lib/auth.php holds the accounts, the
 * password hashes and the second factor, and /admin/login.php is the way in.
 * What survives from before is the principle, not the mechanism —
 * admin_require_auth() still refuses to run at all when the thing protecting it
 * is not in working order, because an editor that quietly works without a
 * password is worse than one that visibly does not work.
 *
 * What counts as "not in working order" has moved with the mechanism: it is now
 * a missing or web-reachable private store, or a page being served over plain
 * http, rather than an absent REMOTE_USER. See auth_problem().
 */

declare(strict_types=1);

require_once __DIR__ . '/html.php';
require_once __DIR__ . '/auth.php';

/**
 * What the rail lists, in the order it lists them.
 *
 * Adding a page to the admin is adding a row here and a file beside
 * admin/sections/. Nothing else in the shell needs to know about it.
 *
 *   label  the name in the rail
 *   icon   a symbol id from assets/icons/sprite.svg
 *   desc   one line, shown when the rail is wide
 *   view   the public page this section edits, or '' for none
 */
const ADMIN_SECTIONS = [
    'overview' => [
        'label' => 'Overview',
        'icon'  => 'home',
        'desc'  => 'What can be changed here',
        'view'  => '',
    ],
    'careers' => [
        'label' => 'Careers',
        'icon'  => 'briefcase',
        'desc'  => 'Job posts and the CV link',
        'view'  => '/pages/careers/',
    ],
    'contact' => [
        'label' => 'Contact',
        'icon'  => 'envelope',
        'desc'  => 'Offices, numbers, the form',
        'view'  => '/pages/contact/',
    ],
    'account' => [
        'label' => 'Account',
        'icon'  => 'user-shield',
        'desc'  => 'Your password and sign-in',
        'view'  => '',
    ],
];

/**
 * Sections that edit a page of the website, in rail order.
 *
 * ADMIN_SECTIONS also carries the ones that do not — the overview and the
 * account — so anything counting or listing "the pages you can edit" asks here
 * rather than filtering the registry by hand in three places.
 */
const ADMIN_PAGE_SECTIONS = ['careers', 'contact'];

/* Symbols inlined into every admin page: the rail, the controls, and every
   icon the contact editor offers, since it renders a live preview of them. */
const ADMIN_ICONS = [
    'home', 'briefcase', 'envelope', 'sun', 'moon', 'chevron-left',
    'chevron-right', 'arrow-up', 'arrow-down', 'arrow-right', 'link', 'user',
    'times', 'check', 'eye', 'lock', 'cogs', 'info-circle',
    'phone', 'mobile-alt', 'clock', 'map-marker-alt', 'building', 'globe',
    'headset', 'comment-alt', 'paper-plane', 'calendar-alt', 'linkedin',
    'github',
    /* Signing in: the rail's account entry, the sign-out control, and the
       enrolment and recovery panels on the account page. */
    'user-shield', 'user-lock', 'shield-alt', 'check-circle',
    'exclamation-circle', 'question-circle',
];

/* ------------------------------------------------------------------- auth */

/**
 * Get ready to serve an admin page: check the setup, then start the session.
 *
 * Every page under /admin/ calls this, signed in or not — the login page needs
 * a session for its CSRF token just as much as the editors do.
 */
function admin_start_session(): void
{
    $problem = auth_problem();

    if ($problem !== '') {
        admin_refuse($problem);
    }

    auth_boot();

    /* A signed-in page left in a shared browser's cache is a signed-in page
       somebody else can press Back into. */
    header('Cache-Control: no-store, max-age=0');
}

/**
 * The account editing this, or a redirect to the login page.
 *
 * Returns the whole record rather than a name: sections want the display name,
 * the account page wants the second-factor state, and passing the record round
 * beats looking it up again in each of them.
 */
function admin_require_auth(): array
{
    admin_start_session();

    /* Nobody has been created yet — on a fresh install that is the setup page's
       job, not a login failure, and saying so beats a login form no password
       can ever satisfy. */
    if (!auth_has_accounts()) {
        header('Location: ' . ADMIN_BASE . 'setup.php');
        exit;
    }

    $account = auth_session_user();

    if ($account === null) {
        admin_go_to_login();
    }

    return $account;
}

/** Send an unauthenticated visitor to the login page, and back here after. */
function admin_go_to_login(): never
{
    $next = (string)($_SERVER['REQUEST_URI'] ?? ADMIN_BASE);

    header('Location: ' . ADMIN_BASE . 'login.php?next=' . rawurlencode($next));
    exit;
}

/**
 * Where the login page may send somebody once they are in.
 *
 * Only a path inside the admin, and never one starting "//", which a browser
 * reads as another host entirely. Without this check the next= parameter is an
 * open redirect: a link to our own login page that lands on somebody else's
 * copy of it, with our domain in the part of the URL people look at.
 */
function admin_safe_next(string $next): string
{
    $next = trim($next);

    if ($next === '' || !str_starts_with($next, ADMIN_BASE) || str_starts_with($next, '//')) {
        return ADMIN_BASE;
    }

    if (str_contains($next, "\r") || str_contains($next, "\n")) {
        return ADMIN_BASE;
    }

    return $next;
}

/**
 * Stop, and say what is wrong, when the admin is not safe to run.
 *
 * The Directory Privacy check used to live here and did the same thing for a
 * different reason. What is checked has changed; that it refuses rather than
 * degrades has not.
 */
function admin_refuse(string $problem): never
{
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<meta name="robots" content="noindex, nofollow">'
       . '<title>The admin cannot start</title>'
       . '<link rel="stylesheet" href="/assets/css/base.css">'
       . '<link rel="stylesheet" href="/assets/css/theme.css">'
       . '<link rel="stylesheet" href="/assets/css/layout.css">'
       . '<link rel="stylesheet" href="/assets/css/components.css">'
       . '<link rel="stylesheet" href="/assets/css/admin.css">'
       . '</head><body class="page"><main class="admin"><div class="admin__inner">'
       . '<div class="admin__notice admin__notice--error">'
       . '<h1>The admin cannot start safely</h1>'
       . '<p>It has refused to load rather than let anyone edit your website.</p>'
       . '<p><strong>' . h($problem) . '</strong></p>'
       . '<p class="admin__fineprint">The private directory holds the password '
       . 'hashes and the sign-in sessions, and must sit beside the document root '
       . 'rather than inside it, writable by PHP. Set <code>T4T_PRIVATE</code> to '
       . 'its full path if it is anywhere other than <code>t4t-private</code> next '
       . 'to <code>public_html</code>. Upload <code>tools/host-probe.php</code>, '
       . 'load it once and delete it to see what this host reports.</p>'
       . '</div></div></main></body></html>';

    exit;
}

/* ------------------------------------------------------------------- CSRF */

/* The session and its token belong to lib/auth.php now, which is what sets the
   cookie flags and regenerates the id on sign-in. These stay as the names the
   editors have always called, so nothing in admin/sections/ had to change. */

function admin_csrf(): string
{
    return auth_csrf();
}

/**
 * Being signed in proves who you are, not that you meant to click this. Without
 * a token, a page on another site could post here using the browser's live
 * session and delete a job post.
 */
function admin_check_csrf(): void
{
    auth_check_csrf();
}

/**
 * The hidden inputs every form in the admin needs: the token, and which
 * section is posting, so the router knows where to send it.
 */
function admin_form_fields(string $section): string
{
    return '<input type="hidden" name="csrf" value="' . h(admin_csrf()) . '">'
         . '<input type="hidden" name="s" value="' . h($section) . '">';
}

/* ---------------------------------------------------------------- routing */

/** Which section is showing. Anything unrecognised falls back to the overview. */
function admin_section(): string
{
    $name = (string)($_GET['s'] ?? $_POST['s'] ?? 'overview');
    return isset(ADMIN_SECTIONS[$name]) ? $name : 'overview';
}

/** A link within the admin: admin_url('careers', ['action' => 'new']). */
function admin_url(string $section, array $params = []): string
{
    return '?' . http_build_query(['s' => $section] + $params);
}

/** Finish a POST by redirecting, so a reload does not repeat it. */
function admin_redirect(string $section, string $message = '', array $params = []): never
{
    if ($message !== '') {
        $params['saved'] = $message;
    }
    header('Location: ' . admin_url($section, $params));
    exit;
}

/* ------------------------------------------------------------------ icons */

/**
 * Inline the sprite symbols the admin uses.
 *
 * Pages under pages/ get theirs from tools/inject_icons.py, which does not
 * walk this directory. Reading them straight from the sprite keeps them in
 * step with it without adding the admin to a build step, and a <use href>
 * pointing at an external file does not resolve cross-document in Chromium or
 * WebKit — which is why they have to be inlined at all.
 */
function admin_icons(array $names): string
{
    $sprite = @file_get_contents(__DIR__ . '/../assets/icons/sprite.svg');
    if ($sprite === false) {
        return '';
    }

    $symbols = '';
    foreach (array_unique($names) as $name) {
        $pattern = '#<symbol id="' . preg_quote((string)$name, '#') . '".*?</symbol>#s';
        if (preg_match($pattern, $sprite, $m)) {
            $symbols .= $m[0];
        }
    }

    return $symbols === ''
        ? ''
        : '<svg class="icon-sprite" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
          . $symbols . '</svg>';
}

function admin_icon(string $name, string $class = 'icon'): string
{
    return '<svg class="' . h($class) . '" aria-hidden="true" focusable="false">'
         . '<use href="#' . h($name) . '"></use></svg>';
}

/* --------------------------------------------------------------- the page */

/**
 * Everything from <!DOCTYPE> down to the opening of the section's own markup:
 * the head, the icon rail, and the header strip above the section.
 */
function admin_head(string $section, string $user, string $lede = ''): void
{
    $meta = ADMIN_SECTIONS[$section];
    $title = $meta['label'] . ' | Tech4TIME admin';
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($title) ?></title>
<link rel="icon" href="/assets/images/favicon/favicon.ico" sizes="any">
<link rel="stylesheet" href="/assets/css/base.css">
<link rel="stylesheet" href="/assets/css/theme.css">
<link rel="stylesheet" href="/assets/css/layout.css">
<link rel="stylesheet" href="/assets/css/components.css">
<link rel="stylesheet" href="/assets/css/admin.css">
<script src="/assets/js/theme-init.js"></script>
</head>
<body class="page admin-page">
<?= admin_icons(ADMIN_ICONS) ?>

<a class="skip-link" href="#admin-main">Skip to the editor</a>

<div class="admin-shell">

  <?php /* The rail. Its default state is the wide one, so it is fully
           labelled with no JavaScript at all; admin-nav.js adds the button
           that narrows it to icons and remembers the choice. Below 60em the
           CSS turns it into a strip across the top instead — a fixed column
           down the side of a phone leaves no room for the thing being
           edited. */ ?>
  <aside class="rail" data-rail id="admin-rail">
    <div class="rail__head">
      <a class="rail__brand" href="/" aria-label="Tech4TIME — view the site">
        <picture class="rail__logo-wrap theme-swap--light">
          <source srcset="/assets/images/logo/logo-light-180.webp" type="image/webp">
          <img class="rail__logo" src="/assets/images/logo/logo-light-180.png"
               alt="Tech4TIME" width="180" height="64" decoding="async">
        </picture>
        <picture class="rail__logo-wrap theme-swap--dark">
          <source srcset="/assets/images/logo/logo-dark-180.webp" type="image/webp">
          <img class="rail__logo" src="/assets/images/logo/logo-dark-180.png"
               alt="Tech4TIME" width="180" height="64" loading="lazy" decoding="async">
        </picture>
      </a>
      <span class="rail__kicker">Admin</span>
    </div>

    <nav class="rail__nav" aria-label="Pages you can edit">
      <ul class="rail__list" role="list">
<?php foreach (ADMIN_SECTIONS as $key => $item): ?>
<?php $current = $key === $section; ?>
        <li>
          <a class="rail__item" href="<?= h(admin_url($key)) ?>"<?= $current ? ' aria-current="page"' : '' ?>>
            <span class="rail__icon"><?= admin_icon($item['icon']) ?></span>
            <span class="rail__text">
              <span class="rail__label"><?= h($item['label']) ?></span>
              <span class="rail__desc"><?= h($item['desc']) ?></span>
            </span>
          </a>
        </li>
<?php endforeach; ?>
      </ul>
    </nav>

    <div class="rail__foot">
<?php if ($meta['view'] !== ''): ?>
      <a class="rail__link" href="<?= h($meta['view']) ?>" target="_blank" rel="noopener">
        <span class="rail__icon"><?= admin_icon('eye') ?></span>
        <span class="rail__text"><span class="rail__label">View the page</span></span>
      </a>
<?php endif; ?>
      <a class="rail__link" href="/" target="_blank" rel="noopener">
        <span class="rail__icon"><?= admin_icon('link') ?></span>
        <span class="rail__text"><span class="rail__label">Open the site</span></span>
      </a>
    </div>
  </aside>

  <div class="admin-shell__body">
    <header class="admin-bar">
      <div class="admin-bar__titles">
        <h1 class="admin-bar__title"><?= h($meta['label']) ?></h1>
<?php if ($lede !== ''): ?>
        <p class="admin-bar__lede"><?= $lede ?></p>
<?php endif; ?>
      </div>

      <div class="admin-bar__actions">
        <?php /* The narrow/wide control sits here rather than in the rail so
                 that it is in the same place whichever shape the rail is in,
                 including the horizontal strip on a phone. admin-nav.js
                 unhides it; without script the rail stays wide and there is
                 nothing to press. */ ?>
        <button class="btn btn--icon rail-toggle" type="button" hidden
                data-rail-toggle aria-controls="admin-rail" aria-expanded="true">
          <?= admin_icon('chevron-left', 'icon rail-toggle__icon--narrow') ?>
          <?= admin_icon('chevron-right', 'icon rail-toggle__icon--wide') ?>
          <span class="visually-hidden">Narrow the menu</span>
        </button>
<?php if ($user !== ''): ?>
        <p class="admin-bar__user">
          <?= admin_icon('user', 'icon icon--sm') ?>
          <span><?= h($user) ?></span>
        </p>
        <?php /* A form, not a link. A GET that ends a session can be fired by
                 any <img src> on any page the browser happens to load, so
                 signing out is a POST with a token like every other action. */ ?>
        <form class="admin-bar__signout" method="post" action="<?= h(ADMIN_BASE) ?>logout.php">
          <input type="hidden" name="csrf" value="<?= h(admin_csrf()) ?>">
          <button class="btn btn--ghost admin-bar__signout-btn" type="submit">
            <?= admin_icon('lock', 'icon icon--sm') ?>
            <span>Sign out</span>
          </button>
        </form>
<?php endif; ?>
        <button class="btn btn--icon" type="button" data-theme-toggle
                aria-label="Switch to dark mode" aria-pressed="false">
          <?= admin_icon('moon', 'icon theme-toggle__icon--moon') ?>
          <?= admin_icon('sun', 'icon theme-toggle__icon--sun') ?>
        </button>
      </div>
    </header>

    <main class="admin" id="admin-main">
      <div class="admin__inner">
<?php
}

/** The notice strip: whatever the last redirect said, or what just failed. */
function admin_notices(array $errors): void
{
    $saved = trim((string)($_GET['saved'] ?? ''));

    if ($errors) {
        echo '<div class="admin__notice admin__notice--error"><p><strong>Not saved.</strong></p><ul>';
        foreach ($errors as $error) {
            echo '<li>' . h((string)$error) . '</li>';
        }
        echo '</ul></div>';
        return;
    }

    if ($saved !== '') {
        echo '<p class="admin__notice admin__notice--ok">' . h($saved) . '</p>';
    }
}

function admin_foot(string $note = ''): void
{
    ?>
<?php if ($note !== ''): ?>
        <footer class="admin__footer"><?= $note ?></footer>
<?php endif; ?>
      </div>
    </main>
  </div>
</div>

<script src="/assets/js/theme-toggle.js" defer></script>
<script src="/assets/js/admin-nav.js" defer></script>
<script src="/assets/js/editor.js" defer></script>
<script src="/assets/js/admin-init.js" defer></script>
</body>
</html>
<?php
}

/* ------------------------------------------------------- signed-out pages */

/**
 * The page around signing in, asking for a reset code, or first-run setup.
 *
 * A separate shell from admin_head() because these have no rail: there is
 * nothing to navigate to until somebody is signed in, and offering a menu of
 * pages that all bounce back here is worse than offering none. It is also why
 * admin_head() cannot serve — it looks its section up in ADMIN_SECTIONS and
 * there is no section to be on.
 *
 * $title is the heading, and the <title>. $lede is one line under it.
 */
function admin_shell_head(string $title, string $lede = '', string $icon = 'user-lock'): void
{
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($title) ?> | Tech4TIME admin</title>
<link rel="icon" href="/assets/images/favicon/favicon.ico" sizes="any">
<link rel="stylesheet" href="/assets/css/base.css">
<link rel="stylesheet" href="/assets/css/theme.css">
<link rel="stylesheet" href="/assets/css/layout.css">
<link rel="stylesheet" href="/assets/css/components.css">
<link rel="stylesheet" href="/assets/css/admin.css">
<script src="/assets/js/theme-init.js"></script>
</head>
<body class="page admin-page">
<?= admin_icons(ADMIN_ICONS) ?>

<main class="signin">
  <div class="signin__card">

    <div class="signin__top">
      <a class="signin__brand" href="/" aria-label="Tech4TIME — view the site">
        <picture class="signin__logo-wrap theme-swap--light">
          <source srcset="/assets/images/logo/logo-light-180.webp" type="image/webp">
          <img class="signin__logo" src="/assets/images/logo/logo-light-180.png"
               alt="Tech4TIME" width="180" height="64" decoding="async">
        </picture>
        <picture class="signin__logo-wrap theme-swap--dark">
          <source srcset="/assets/images/logo/logo-dark-180.webp" type="image/webp">
          <img class="signin__logo" src="/assets/images/logo/logo-dark-180.png"
               alt="Tech4TIME" width="180" height="64" loading="lazy" decoding="async">
        </picture>
      </a>
      <button class="btn btn--icon" type="button" data-theme-toggle
              aria-label="Switch to dark mode" aria-pressed="false">
        <?= admin_icon('moon', 'icon theme-toggle__icon--moon') ?>
        <?= admin_icon('sun', 'icon theme-toggle__icon--sun') ?>
      </button>
    </div>

    <div class="signin__head">
      <span class="signin__mark"><?= admin_icon($icon, 'icon') ?></span>
      <h1 class="signin__title"><?= h($title) ?></h1>
<?php if ($lede !== ''): ?>
      <p class="signin__lede"><?= h($lede) ?></p>
<?php endif; ?>
    </div>
<?php
}

/** Close the signed-out shell. $note is trusted markup, like admin_foot()'s. */
function admin_shell_foot(string $note = ''): void
{
    ?>
<?php if ($note !== ''): ?>
    <footer class="signin__foot"><?= $note ?></footer>
<?php endif; ?>
  </div>
</main>

<script src="/assets/js/theme-toggle.js" defer></script>
<script src="/assets/js/admin-init.js" defer></script>
</body>
</html>
<?php
}

/**
 * The error strip on a signed-out page.
 *
 * Separate from admin_notices() because these pages have no ?saved= flash and
 * because what goes wrong here is a sentence rather than a list of fields.
 */
function admin_shell_error(string $message): void
{
    if ($message === '') {
        return;
    }

    echo '<div class="admin__notice admin__notice--error signin__notice">'
       . '<p>' . admin_icon('exclamation-circle', 'icon icon--sm') . ' ' . h($message) . '</p>'
       . '</div>';
}

function admin_shell_note(string $message): void
{
    if ($message === '') {
        return;
    }

    echo '<div class="admin__notice admin__notice--ok signin__notice">'
       . '<p>' . admin_icon('check-circle', 'icon icon--sm') . ' ' . h($message) . '</p>'
       . '</div>';
}
