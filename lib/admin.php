<?php
/**
 * Tech4TIME — the admin shell.
 *
 * Everything every section of /admin/ needs before it can do anything: the
 * check that the directory really is password protected, the session and CSRF
 * token, the section registry the icon rail is drawn from, and the page
 * furniture around whichever section is showing.
 *
 * Not reachable over HTTP: .htaccess forbids /lib/.
 *
 * PROTECTING THE ADMIN
 * admin/ must be locked down in cPanel: Directory Privacy -> /admin -> add a
 * user with a password. Apache then asks for it before any of this code runs,
 * which is a great deal safer than a login form written here.
 *
 * admin_require_auth() refuses to run if it cannot see that protection, so the
 * admin cannot be left open by accident. If your host does not pass the
 * authenticated user through to PHP — some FastCGI setups do not — the page
 * will refuse even though the password prompt works. That is the one case for
 * setting ADMIN_REQUIRE_HTTP_AUTH to false, and only once you have confirmed
 * the prompt genuinely appears.
 */

declare(strict_types=1);

require_once __DIR__ . '/html.php';

const ADMIN_REQUIRE_HTTP_AUTH = true;

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
];

/* Symbols inlined into every admin page: the rail, the controls, and every
   icon the contact editor offers, since it renders a live preview of them. */
const ADMIN_ICONS = [
    'home', 'briefcase', 'envelope', 'sun', 'moon', 'chevron-left',
    'chevron-right', 'arrow-up', 'arrow-down', 'arrow-right', 'link', 'user',
    'times', 'check', 'eye', 'lock', 'cogs', 'info-circle',
    'phone', 'mobile-alt', 'clock', 'map-marker-alt', 'building', 'globe',
    'headset', 'comment-alt', 'paper-plane', 'calendar-alt', 'linkedin',
    'github',
];

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

/**
 * Refuse to run unless Apache has already asked for a password.
 *
 * Returns the authenticated user. Sends a 503 and exits when there is none,
 * because an editor that quietly works without a password is worse than one
 * that visibly does not work at all.
 */
function admin_require_auth(): string
{
    $user = admin_http_user();

    if (!ADMIN_REQUIRE_HTTP_AUTH || $user !== '') {
        return $user;
    }

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
       . '</head><body class="page"><main class="admin"><div class="admin__inner">'
       . '<div class="admin__notice admin__notice--error">'
       . '<h1>This editor is not password protected</h1>'
       . '<p>It has refused to load rather than let anyone edit your website.</p>'
       . '<p>In cPanel, open <strong>Directory Privacy</strong>, select the '
       . '<strong>admin</strong> folder, tick "Password protect this directory", '
       . 'and add a user. Then reload this page.</p>'
       . '<p class="admin__fineprint">If the browser already asks you for a password '
       . 'and you still see this, your host is not passing the authenticated user '
       . 'through to PHP. Set ADMIN_REQUIRE_HTTP_AUTH to false at the top of '
       . 'lib/admin.php — but only once you have confirmed the prompt appears.</p>'
       . '</div></div></main></body></html>';
    exit;
}

/* ------------------------------------------------------------------- CSRF */

function admin_start_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}

function admin_csrf(): string
{
    return (string)($_SESSION['csrf'] ?? '');
}

/**
 * A password prompt proves who you are, not that you meant to click this.
 * Without a token, a page on another site could post here using the browser's
 * stored credentials and delete a job post.
 */
function admin_check_csrf(): void
{
    $sent = (string)($_POST['csrf'] ?? '');
    if (!hash_equals(admin_csrf(), $sent)) {
        http_response_code(400);
        exit('Session expired. Go back, reload the page and try again.');
    }
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
