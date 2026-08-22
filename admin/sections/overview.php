<?php
/**
 * Tech4TIME — admin overview.
 *
 * What /admin/ opens on. It edits nothing; it says what can be edited, how
 * much of it there is, and when each part was last changed, so that whoever
 * signs in knows where they are before they change anything.
 *
 * Included by admin/index.php, which has already checked that somebody is
 * signed in and started the session.
 */

declare(strict_types=1);

if (!defined('T4T_ADMIN')) {
    http_response_code(403);
    exit('Not a page.');
}

require_once __DIR__ . '/../../lib/careers.php';
require_once __DIR__ . '/../../lib/contact.php';

/** "3 minutes ago", or the date once that stops being useful. */
function admin_when(string $iso): string
{
    $iso = trim($iso);
    if ($iso === '') {
        return 'never';
    }

    $at = strtotime($iso);
    if ($at === false) {
        return $iso;
    }

    $ago = time() - $at;
    if ($ago < 90) {
        return 'just now';
    }
    if ($ago < 3600) {
        return (int)round($ago / 60) . ' minutes ago';
    }
    if ($ago < 86400) {
        $hours = (int)round($ago / 3600);
        return $hours . ($hours === 1 ? ' hour ago' : ' hours ago');
    }
    if ($ago < 7 * 86400) {
        $days = (int)round($ago / 86400);
        return $days . ($days === 1 ? ' day ago' : ' days ago');
    }
    return date('j F Y', $at);
}

$careers = careers_load();
$contact = contact_load();

$cards = [
    [
        'section' => 'careers',
        'title'   => 'Job posts',
        'lines'   => [
            count($careers['jobs']) . ' post' . (count($careers['jobs']) === 1 ? '' : 's') . ', '
                . count(careers_open_jobs($careers)) . ' live on the site',
            trim((string)($careers['cv_form_url'] ?? '')) !== ''
                ? 'Speculative applications have a form link'
                : 'No form link for speculative applications',
        ],
        'saved'   => (string)($careers['updated'] ?? ''),
        'file'    => 'content/careers.json',
    ],
    [
        'section' => 'contact',
        'title'   => 'Contact page',
        'lines'   => [
            count(contact_shown_offices($contact)) . ' office'
                . (count(contact_shown_offices($contact)) === 1 ? '' : 's') . ' shown, '
                . count($contact['reach']['items']) . ' direct contact row'
                . (count($contact['reach']['items']) === 1 ? '' : 's'),
            count($contact['form']['service_types']) . ' services offered in the enquiry form',
        ],
        'saved'   => (string)($contact['updated'] ?? ''),
        'file'    => 'content/contact.json',
        'warn'    => contact_footer_in_step($contact)
            ? ''
            : 'The site footer is showing older contact details.',
    ],
];

admin_head('overview', $user,
    'Everything on the site that can be changed without a redeploy.');

admin_notices($errors);
?>

<ul class="admin__cards" role="list">
<?php foreach ($cards as $card): ?>
  <li class="admin-tile">
    <div class="admin-tile__head">
      <span class="admin-tile__icon"><?= admin_icon(ADMIN_SECTIONS[$card['section']]['icon']) ?></span>
      <h2 class="admin-tile__title"><?= h($card['title']) ?></h2>
    </div>

    <ul class="admin-tile__facts" role="list">
<?php foreach ($card['lines'] as $line): ?>
      <li><?= h($line) ?></li>
<?php endforeach; ?>
      <li class="admin-tile__saved">Last saved <?= h(admin_when($card['saved'])) ?></li>
    </ul>

<?php if (($card['warn'] ?? '') !== ''): ?>
    <p class="admin-tile__warn"><?= admin_icon('info-circle', 'icon icon--sm') ?> <?= h($card['warn']) ?></p>
<?php endif; ?>

    <div class="admin-tile__actions">
      <a class="btn btn--primary" href="<?= h(admin_url($card['section'])) ?>">Edit</a>
      <a class="btn btn--ghost" href="<?= h(ADMIN_SECTIONS[$card['section']]['view']) ?>"
         target="_blank" rel="noopener">View the page</a>
    </div>

    <p class="admin-tile__file"><code><?= h($card['file']) ?></code></p>
  </li>
<?php endforeach; ?>
</ul>

<section class="admin__block">
  <h2 class="admin__section-title">What is not editable here</h2>
  <p class="admin__blurb">
    Said plainly, so nobody hunts for a screen that does not exist. Everything
    below is part of the pages themselves and changes with a redeploy.
  </p>
  <ul class="admin__notes">
    <li>
      <strong>The other pages.</strong> Home, About, Services, Company Profile
      and the rest are static files. They are also the pages whose wording
      almost never changes.
    </li>
    <li>
      <strong>The footer on every page.</strong> It repeats the email address,
      phone numbers, addresses and opening hours. The project rules out
      fetching shared pieces at run time, so the footer is pasted into each
      page rather than read from a file — the contact editor says so when the
      two have parted.
    </li>
    <li>
      <strong>The enquiry form's own fields</strong> and where it sends. Those
      live in <code>contact-handler.php</code>, which validates each one.
    </li>
    <li>
      <strong>Images</strong>, other than choosing which flag an office uses.
      New ones are uploaded to <code>/assets/images/</code> with cPanel's File
      Manager.
    </li>
  </ul>
</section>

<?php
admin_foot(
    '<p>Signed in as <strong>' . h($account['user']) . '</strong>. '
    . 'Your password, the authenticator app and the recovery codes are on the '
    . '<a href="' . h(admin_url('account')) . '">Account</a> page, along with a '
    . 'record of every attempt to sign in.</p>'
);
