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
require_once __DIR__ . '/publish_client.php';

/**
 * What the rail lists, in the order it lists them.
 *
 * Adding a page to the admin is adding a row here and a file in
 * sections/. Nothing else in the shell needs to know about it.
 *
 *   label  the name in the rail
 *   icon   a symbol id from public/assets/icons/sprite.svg
 *   desc   one line, shown when the rail is wide
 *   view   the public page this section edits, as a PATH ON THE PUBLIC SITE,
 *          or '' for none. Root-relative used to be right and stopped being:
 *          on this host '/' is the admin, so '/pages/careers/' would link the
 *          editor to a page of itself that does not exist. public_url() puts
 *          the other half's origin in front. See ADR 0011.
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
        'view'  => '/pages/careers/',   // resolved through public_url()
    ],
    'contact' => [
        'label' => 'Contact',
        'icon'  => 'envelope',
        'desc'  => 'Offices, numbers, the form',
        'view'  => '/pages/contact/',
    ],
    'company' => [
        'label' => 'Company Profile',
        'icon'  => 'building',
        'desc'  => 'Milestones, clients, technology',
        'view'  => '/pages/company-profile/',
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
const ADMIN_PAGE_SECTIONS = ['careers', 'contact', 'company'];

/* The marker admin_form_tail() writes and admin_form_truncated() looks for. */
const ADMIN_TAIL_FIELD = '__tail';

/* Symbols inlined into every admin page: the rail, the controls, and every
   icon the contact and company editors offer, since both render a live
   preview of them. */
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
    'exclamation-circle', 'question-circle', 'angle-right',
    /* The company profile: the icons a principle card may carry, plus the one
       its picture rows use for "upload". Kept in step with COMPANY_ICONS —
       every name there must be inlined here, or the editor's live preview
       draws an empty box for it. */
    'lightbulb', 'handshake', 'calendar-check', 'cloud-upload-alt',
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
 * Only a path on this host. Without this check the next= parameter is an open
 * redirect: a link to our own login page that lands on somebody else's copy of
 * it, with our domain in the part of the URL people look at.
 *
 * THIS GOT WEAKER WHEN THE ADMIN MOVED, AND HAD TO BE REBUILT.
 * While the editor was a folder of the public site, ADMIN_BASE was '/admin/'
 * and "starts with ADMIN_BASE" did most of the work — a value had to begin
 * with those seven characters to survive. On admin.tech4time.bd the admin IS
 * the document root, ADMIN_BASE is '/', and that same test now accepts every
 * absolute path there is. What was a narrow allow-list quietly became "starts
 * with a slash", which "//evil.example" also does.
 *
 * So the shape of the check is different here: an explicit refusal of every
 * form a browser can read as another host, and then a positive test that what
 * is left looks like a path.
 */
function admin_safe_next(string $next): string
{
    $next = trim($next);

    /* A positive shape rather than a list of things to refuse: one leading
       slash, then a character that is NOT another slash or a backslash, then
       nothing a header could be split with.

       "//evil.example" and "/\evil.example" are both read as another host by a
       browser and fail the lookahead. "https://evil.example" has no leading
       slash. A value carrying CR or LF fails the character class. And bare "/"
       matches, which is what an empty next should become. */
    if (!preg_match('~^/(?![/\\\\])[^\x00-\x1f\x7f]*$~', $next)) {
        return ADMIN_BASE;
    }

    /* The encoded spellings of those same two characters. This value goes into
       a Location header and is decoded by the browser, not here, so "/%2f" is
       "//" by the time it matters. */
    $lower = strtolower($next);
    if (str_starts_with($lower, '/%2f') || str_starts_with($lower, '/%5c')) {
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
       . 'its full path if it is anywhere other than <code>t4t-private-admin</code> '
       . 'beside the repository, two levels up from the document root. '
       . 'Upload <code>tools/host-probe.php</code>, '
       . 'load it once and delete it to see what this host reports.</p>'
       . '</div></div></main></body></html>';

    exit;
}

/* --------------------------------------------------------- truncated forms */

/**
 * The last control in a long form, and the check that it arrived.
 *
 * PHP stops parsing a request body after max_input_vars fields and DOES NOT
 * SAY SO to the script — it drops the rest and carries on. For a form the size
 * of the company profile that is a real limit rather than a theoretical one:
 * it posts around five hundred and fifty fields today, against a default of a
 * thousand, and every row added moves it closer.
 *
 * What makes it dangerous is the shape of the failure. The editor would rebuild
 * the document from a truncated $_POST, decide the missing rows had been
 * removed, save that, publish it, and report success. Somebody would find out
 * when a client noticed their logo was gone.
 *
 * So the last thing every long form renders is this marker. Truncation always
 * drops the tail, so its absence is exactly the condition — no counting, no
 * guessing at what should have arrived, and nothing to keep in step.
 */
function admin_form_tail(): string
{
    return '<input type="hidden" name="' . ADMIN_TAIL_FIELD . '" value="1">';
}

/** Whether this POST was cut short before the form ended. */
function admin_form_truncated(): bool
{
    return ($_POST[ADMIN_TAIL_FIELD] ?? '') !== '1';
}

/** What to tell somebody whose save was refused for that reason. */
function admin_truncated_message(): string
{
    return 'This page is too big for the server to accept in one go: it sent '
         . 'more fields than PHP\'s max_input_vars setting allows, so the end of '
         . 'the form was silently dropped. NOTHING HAS BEEN SAVED, which is the '
         . 'right outcome — saving would have deleted whatever came after the '
         . 'cut. Remove some entries, or ask whoever runs the server to raise '
         . 'max_input_vars (it is currently ' . (int)ini_get('max_input_vars')
         . ').';
}

/* ------------------------------------------------------------------- CSRF */

/* The session and its token belong to lib/auth.php now, which is what sets the
   cookie flags and regenerates the id on sign-in. These stay as the names the
   editors have always called, so nothing in sections/ had to change. */

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

/**
 * Finish a POST by redirecting, so a reload does not repeat it.
 *
 * It also carries the publish outcome across the redirect. Every save
 * publishes, and a save that reached this file but not the live site must not
 * report itself as done — so the check is here, where every section already
 * ends up, rather than in each of them.
 *
 * The failure goes in the session rather than the query string: the message is
 * a sentence, sometimes two, and a URL is not where a person should meet it.
 */
function admin_redirect(string $section, string $message = '', array $params = []): never
{
    if ($message !== '') {
        $params['saved'] = $message;
    }

    $note = publish_note();

    if ($note !== null && ($note['ok'] ?? false) !== true) {
        $_SESSION['publish_failed'] = [
            'section' => $section,
            'code'    => (string)($note['code'] ?? 'refused'),
            'error'   => (string)($note['error'] ?? publish_reason((string)($note['code'] ?? ''))),
        ];
    } else {
        unset($_SESSION['publish_failed']);
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
    $sprite = @file_get_contents(__DIR__ . '/../public/assets/icons/sprite.svg');
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
function admin_head(string $section, string $user, string $lede = '',
                    array $outline = [], array $save = []): void
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
    <?php /* THE BRAND AND THE CONTROL THAT NARROWS THE RAIL SHARE THIS ROW.

             The control used to live in the bar across the top, on the
             reasoning that it would then be in one place whatever shape the
             rail was in. In use that reads as a button belonging to the page
             rather than to the menu it operates, and it is nowhere near the
             thing it changes. It is here now, at the rail's own top edge,
             which is where a rail's collapse control is looked for.

             Below 60em the whole head is display:none — the rail is a
             horizontal strip there and there is no width to narrow. */ ?>
    <div class="rail__head">
      <a class="rail__brand" href="<?= h(public_url('/')) ?>" aria-label="Tech4TIME — view the site">
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
        <span class="rail__kicker">Admin</span>
      </a>

      <?php /* Starts hidden and admin-nav.js unhides it: with no script the
               rail cannot narrow, and a control that does nothing is worse
               than no control. */ ?>
      <button class="rail__toggle" type="button" hidden
              data-rail-toggle aria-controls="admin-rail" aria-expanded="true">
        <?= admin_icon('chevron-left', 'icon rail-toggle__icon--narrow') ?>
        <?= admin_icon('chevron-right', 'icon rail-toggle__icon--wide') ?>
        <span class="visually-hidden">Narrow the menu</span>
      </button>
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
<?php if ($current && $outline): ?>
          <?php /* WHAT THIS PAGE CONTAINS, LISTED WHERE IT CAN BE SEEN.

                   The company editor is a quarter of a megabyte of form: ten
                   bands, 282 rows, 448 fields, one under the next. All of it
                   was always there and none of it was visible, because the
                   only way to learn that a section existed was to scroll far
                   enough to reach it — and every button press put you back at
                   the top. Somebody reasonably concluded the data was
                   missing.

                   These are plain in-page anchors, so they work with script
                   off and they cost the editing column no height at all. */ ?>
          <ul class="rail__sub" role="list">
<?php foreach ($outline as $anchor => $label): ?>
            <li>
              <a class="rail__subitem" href="#<?= h((string)$anchor) ?>"><?= h((string)$label) ?></a>
            </li>
<?php endforeach; ?>
          </ul>
<?php endif; ?>
        </li>
<?php endforeach; ?>
      </ul>
    </nav>

    <?php /* THE FOOT OF THE RAIL IS WHO YOU ARE.

             It held the two links to the public site, which are about the page
             being edited and now sit beside its heading where that is what
             they read as. What belongs at the bottom of a rail is the account:
             it is the one thing on this screen that is about the person rather
             than the page, and the bottom of the menu is where every tool that
             has one puts it.

             The menu opens UPWARDS, because there is nothing below it. */ ?>
    <div class="rail__foot">
<?php if ($user !== ''): ?>
      <?php /* A <details>, not a scripted dropdown. The browser opens it,
               moves focus into it and closes it on Escape with no JavaScript
               whatsoever, which is what lets a menu exist at all under the
               rule that every page works with script off. admin-nav.js adds
               only the one thing <details> does not do by itself: closing when
               a click lands outside. Take the script away and it still opens
               and still signs out; it merely waits to be pressed again. */ ?>
      <details class="account" data-account>
        <summary class="account__toggle" title="<?= h($user) ?>">
          <span class="account__avatar"><?= admin_icon('user', 'icon') ?></span>
          <span class="account__text">
            <span class="account__label"><?= h($user) ?></span>
            <span class="account__hint">Signed in</span>
          </span>
          <?= admin_icon('angle-right', 'icon account__caret') ?>
          <span class="visually-hidden">Account menu</span>
        </summary>

        <div class="account__menu">
          <div class="account__who">
            <span class="account__name"><?= h($user) ?></span>
            <span class="account__hint">Signed in</span>
          </div>

          <a class="account__item" href="<?= h(admin_url('account')) ?>">
            <?= admin_icon('user-shield', 'icon icon--sm') ?>
            <span>Your account</span>
          </a>

          <?php /* A form, not a link. A GET that ends a session can be fired
                   by any <img src> on any page the browser happens to load, so
                   signing out is a POST with a token like every other
                   action. */ ?>
          <form class="account__signout" method="post" action="<?= h(ADMIN_BASE) ?>logout.php">
            <input type="hidden" name="csrf" value="<?= h(admin_csrf()) ?>">
            <button class="account__item account__item--danger" type="submit">
              <?= admin_icon('lock', 'icon icon--sm') ?>
              <span>Sign out</span>
            </button>
          </form>
        </div>
      </details>
<?php endif; ?>
    </div>
  </aside>

  <div class="admin-shell__body">
    <header class="admin-bar">
      <?php /* The heading only. The line under it used to be here too, and it
               is two lines of wrapped text on every editor — pinned to the top
               of the viewport, on a page whose whole problem is that there is
               no room to see the form. It is read once and it never changes,
               so it now scrolls with the page, below. */ ?>
      <?php /* The heading, and the two ways to look at what is being edited.

               Those two links were at the foot of the rail, where they sat
               under the section list and read as more navigation. They are
               about THIS page, so they belong beside its name. */ ?>
      <div class="admin-bar__titles">
        <h1 class="admin-bar__title"><?= h($meta['label']) ?></h1>

        <div class="admin-bar__views">
<?php if ($meta['view'] !== ''): ?>
          <a class="admin-bar__view" href="<?= h(public_url($meta['view'])) ?>"
             target="_blank" rel="noopener">
            <?= admin_icon('eye', 'icon icon--sm') ?>
            <span>View the page</span>
          </a>
<?php endif; ?>
          <a class="admin-bar__view" href="<?= h(public_url('/')) ?>"
             target="_blank" rel="noopener">
            <?= admin_icon('link', 'icon icon--sm') ?>
            <span>Open the site</span>
          </a>
        </div>
      </div>

      <?php /* SAVE LIVES HERE, AND THE FORM IS SEVERAL SCREENS BELOW.

               It was a bar pinned across the foot of the editing column, which
               cost 101px of every screen to hold one button — on a page whose
               difficulty is that there is not enough room to see the form. Up
               here it costs nothing: the bar was already pinned, and the right
               end of it was holding a name that does not change.

               The button reaches its form with the `form` attribute, which is
               plain HTML and needs no script: a submit button anywhere in the
               document may name the form it belongs to. So this still works
               with JavaScript off, and admin-forms.js does not have to know
               that the button is not inside the form it submits. */ ?>
      <div class="admin-bar__actions">
<?php if ($save): ?>
        <p class="admin__status" data-form-status role="status" aria-live="polite"></p>
<?php if (!empty($save['discard'])): ?>
        <a class="btn btn--ghost" href="<?= h($save['discard']) ?>">Discard</a>
<?php endif; ?>
        <?php /* Two labels, one shown. "Save the company profile" is 200px of
                 a 320px screen and the bar cannot wrap a single word, so at
                 that width the button says "Save" instead. display:none on
                 the other means a screen reader is offered one name, not
                 two. */ ?>
        <button class="btn btn--primary" type="submit" name="do" value="save"
                form="<?= h($save['form']) ?>">
          <span class="admin-bar__save-long"><?= h($save['label']) ?></span>
          <span class="admin-bar__save-short"><?= h($save['short'] ?? 'Save') ?></span>
        </button>
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
<?php if ($lede !== ''): ?>
        <p class="admin__lede"><?= $lede ?></p>
<?php endif; ?>
<?php
}

/** The notice strip: whatever the last redirect said, or what just failed. */
function admin_notices(array $errors): void
{
    $saved = trim((string)($_GET['saved'] ?? ''));

    admin_publish_notice();

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

/**
 * Say so when the live site did not take the last save, and offer to send it
 * again.
 *
 * Shown ABOVE the ordinary "Saved" notice and not instead of it, because both
 * are true: the record here is written, and the public site does not have it.
 * Telling somebody only the first is how a gap goes uninvestigated — nothing
 * asked them to look.
 *
 * The retry is a form with a token, not a link. It writes to another host.
 */
function admin_publish_notice(): void
{
    $failed = $_SESSION['publish_failed'] ?? null;

    if (!is_array($failed)) {
        return;
    }

    unset($_SESSION['publish_failed']);

    $section = (string)($failed['section'] ?? '');
    ?>
<div class="admin__notice admin__notice--warn">
  <p><strong>Saved here, but the live site does not have it yet.</strong></p>
  <p><?= h((string)($failed['error'] ?? '')) ?></p>
<?php if (in_array($section, CONTRACT_DOCUMENTS, true)): ?>
  <form method="post" data-async action="<?= h(admin_url($section)) ?>">
    <?= admin_form_fields($section) ?>
    <input type="hidden" name="action" value="republish">
    <button class="btn btn--primary" type="submit">Publish again</button>
  </form>
<?php endif; ?>
  <p class="admin__hint">
    Nothing was lost. This record is the one that counts, and it can be sent
    again at any time — from here, or with
    <code>python3 tools/reconcile.py</code> on this server.
  </p>
</div>
    <?php
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
<script src="/assets/js/admin-forms.js" defer></script>
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
      <a class="signin__brand" href="<?= h(public_url('/')) ?>" aria-label="Tech4TIME — view the site">
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
