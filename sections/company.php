<?php
/**
 * Tech4TIME — company profile editor.
 *
 * Everything on /pages/company-profile/ that is words or pictures rather than
 * structure: the banner, the milestone timeline, the figures, the client
 * logos, the photographs, the technology list, the principles, and the copy
 * around all of them. Stored in content/company.json; there is no database.
 *
 * ONE FORM, NOT A LIST AND AN EDIT SCREEN — the same call sections/contact.php
 * makes, for the same reason. This page is a lot of short fields that are
 * usually changed together, so the whole page is one form, and the add, remove
 * and reorder buttons submit it WITHOUT saving so that nothing typed is lost
 * on the way.
 *
 * WHY SO MUCH OF THIS IS A LOOP
 * The page has six repeatable lists. Written out one at a time this file would
 * be four times the length and the fifth copy would be the one that forgot the
 * hidden id field. So the lists are driven from COMPANY_LISTS in the contract,
 * and each row SHAPE has one function that draws it.
 *
 * EVERY BAND CAN BE HIDDEN, and so can every row. Hiding is not deleting: a
 * hidden thing keeps its place and its contents and simply does not render.
 * That is what somebody wants when a client asks to be taken off the page for
 * a quarter, and it is the difference between this editor and a text file.
 *
 * Included by public/index.php, which has already checked the password and
 * started the session.
 */

declare(strict_types=1);

if (!defined('T4T_ADMIN')) {
    http_response_code(403);
    exit('Not a page.');
}

require_once __DIR__ . '/../lib/company.php';

/* ---------------------------------------------------------------- reading */

/**
 * Rebuild the whole document from what the browser sent.
 *
 * Everything is re-trimmed and re-sanitised here rather than trusted: the
 * form's own constraints are a convenience for whoever is typing, and this is
 * the code that decides what gets stored.
 */
function company_from_post(array $current): array
{
    $data = $current;

    foreach (COMPANY_TEXT_FIELDS as $band => $fields) {
        foreach ($fields as $field) {
            $data[$band][$field] = trim((string)($_POST[$band][$field] ?? ''));
        }
    }

    foreach (COMPANY_RICH_FIELDS as $band => $fields) {
        foreach ($fields as $field) {
            $data[$band][$field] = rt_sanitise_html((string)($_POST[$band][$field] ?? ''));
        }
    }

    foreach (COMPANY_BANDS as $band) {
        $data[$band]['status'] =
            ($_POST[$band]['status'] ?? 'shown') === 'hidden' ? 'hidden' : 'shown';
    }

    $data['journey']['interval'] = (int)($_POST['journey']['interval'] ?? 6000);

    /* Rows arrive keyed by their position in the form. Removing one leaves a
       hole in those keys, so they are renumbered rather than trusted. */
    foreach (COMPANY_LISTS as $band => $filler) {
        $data[$band]['items'] = [];
        foreach (array_values((array)($_POST[$band]['items'] ?? [])) as $row) {
            if (is_array($row)) {
                $data[$band]['items'][] = $filler(company_row_from_post($band, $row));
            }
        }
    }

    /* Ids are minted here rather than trusted, so two rows cannot be given the
       same one by editing the page's HTML. */
    return company_identify($data);
}

/** One row, cleaned, in whichever shape its list uses. */
function company_row_from_post(string $band, array $row): array
{
    $common = [
        'id'     => trim((string)($row['id'] ?? '')),
        'status' => ($row['status'] ?? 'shown') === 'hidden' ? 'hidden' : 'shown',
    ];

    return match ($band) {
        'milestones' => $common + [
            'year'  => trim((string)($row['year'] ?? '')),
            'title' => trim((string)($row['title'] ?? '')),
            'text'  => trim((string)($row['text'] ?? '')),
        ],
        'experience' => $common + [
            'figure' => trim((string)($row['figure'] ?? '')),
            'label'  => trim((string)($row['label'] ?? '')),
        ],
        'journey' => $common + [
            'alt'   => trim((string)($row['alt'] ?? '')),
            'image' => company_image_from_post($row['image'] ?? []),
        ],
        'principles' => $common + [
            'icon'  => isset(COMPANY_ICONS[$row['icon'] ?? '']) ? (string)$row['icon'] : '',
            'title' => trim((string)($row['title'] ?? '')),
            'text'  => trim((string)($row['text'] ?? '')),
        ],
        default => $common + [
            'name'  => trim((string)($row['name'] ?? '')),
            'image' => company_image_from_post($row['image'] ?? []),
        ],
    };
}

/**
 * One picture, from the hidden fields the row carries.
 *
 * The paths are checked against COMPANY_IMAGE_ROOTS rather than taken as sent.
 * These are hidden inputs, so the browser is not offering a choice here — but
 * a hidden input is a text field with the label removed, and anything a form
 * posts can be posted with something else in it. A src pointing at another
 * origin would put a third party's server in every visitor's page load.
 */
function company_image_from_post(mixed $image): array
{
    $image = is_array($image) ? $image : [];

    return company_image_defaults([
        'src'    => company_safe_image_path((string)($image['src'] ?? '')),
        'webp'   => company_safe_image_path((string)($image['webp'] ?? '')),
        'width'  => (int)($image['width'] ?? 0),
        'height' => (int)($image['height'] ?? 0),
    ]);
}

/* ---------------------------------------------------------------- actions */

$data = company_load();
$pending = '';   /* an unsaved change made by a row button */

/* Named here, not read inline below. See the note in sections/contact.php:
   a $_POST key that is only ever compared reads exactly like one that was
   assigned, and this was that bug. */
$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_check_csrf();

    /* Sent again by hand, from the notice a failed publish leaves. Nothing is
       re-saved and no revision is minted: the record here is already right,
       and what failed was only getting it to the other host. */
    if ($action === 'republish') {
        publish_note(publish_push('company', $data));

        $note = publish_note();
        admin_redirect('company', ($note['ok'] ?? false)
            ? 'Sent to the live site — it now holds revision '
              . (int)($note['revision'] ?? 0) . '.'
            : '');
    }

    $do = (string)($_POST['do'] ?? 'save');
    $posted = company_from_post($data);

    if ($do === 'save') {
        $errors = company_validate($posted);
        if (!$errors) {
            if (company_save($posted)) {
                admin_redirect('company', 'Saved the company profile.');
            }
            $errors[] = 'Could not write content/company.json. Check the file is '
                      . 'writable by PHP.';
        }
        /* Redraw with what was typed rather than throwing it away. */
        $data = $posted;
    } else {
        $applied = company_apply_row_action($posted, $do);
        $data = $applied[0] ?? $posted;
        $pending = $applied[1] ?? '';
    }
}

/**
 * Apply an add / remove / move button to one of the six lists.
 *
 * The button's value carries what to do and to which row, as "clients-up:3".
 * Returns the new document and a sentence saying what happened, or null when
 * the instruction did not name anything that exists.
 */
function company_apply_row_action(array $data, string $do): ?array
{
    [$verb, $index] = array_pad(explode(':', $do, 2), 2, '');
    $index = (int)$index;

    foreach (COMPANY_LISTS as $band => $filler) {
        if (!str_starts_with($verb, $band . '-')) {
            continue;
        }

        $rows = $data[$band]['items'];
        $what = substr($verb, strlen($band) + 1);

        if ($what === 'add') {
            /* A new row arrives HIDDEN. It has nothing in it yet, and a blank
               card appearing on the live site the moment somebody presses Add
               is not what pressing Add means. */
            $rows[] = $filler(['status' => 'hidden']);
            $data[$band]['items'] = $rows;

            return [company_identify($data),
                    'Added an entry. It is hidden until you show it — fill it in, '
                    . 'then save.'];
        }

        if (!isset($rows[$index])) {
            return null;
        }

        if ($what === 'remove') {
            array_splice($rows, $index, 1);
            $data[$band]['items'] = array_values($rows);

            return [$data, 'Removed. Nothing is written to the site until you save.'];
        }

        if ($what === 'up' || $what === 'down') {
            $to = $index + ($what === 'up' ? -1 : 1);
            if ($to < 0 || $to >= count($rows)) {
                return null;
            }
            [$rows[$index], $rows[$to]] = [$rows[$to], $rows[$index]];
            $data[$band]['items'] = $rows;

            return [$data, 'Moved. Nothing is written to the site until you save.'];
        }
    }

    return null;
}

/* ---------------------------------------------------------------- helpers */

/** One row of buttons: move up, move down, remove. */
function company_row_controls(string $band, int $index, int $total, string $label): void
{
    ?>
        <div class="admin-card__controls">
          <button class="btn btn--ghost" type="submit" name="do" value="<?= h($band) ?>-up:<?= $index ?>"
                  aria-label="Move <?= h($label) ?> up"<?= $index === 0 ? ' disabled' : '' ?>>&uarr;</button>
          <button class="btn btn--ghost" type="submit" name="do" value="<?= h($band) ?>-down:<?= $index ?>"
                  aria-label="Move <?= h($label) ?> down"<?= $index === $total - 1 ? ' disabled' : '' ?>>&darr;</button>
          <button class="btn btn--ghost admin-row__delete" type="submit"
                  name="do" value="<?= h($band) ?>-remove:<?= $index ?>"
                  aria-label="Remove <?= h($label) ?>">Remove</button>
        </div>
    <?php
}

/**
 * The shown/hidden control every band and every row carries.
 *
 * A <select> and not a checkbox, matching sections/contact.php — and for a
 * reason beyond consistency: an unticked checkbox is not posted at all, so
 * "switched off" and "the field was not in the form" arrive identically, and
 * telling them apart needs a hidden companion input that somebody eventually
 * forgets. A select always posts exactly one of two values.
 *
 * Hiding is not deleting. A hidden row keeps its place, its text and its
 * picture, and simply does not render.
 */
function company_status_field(string $name, string $status, string $noun): void
{
    ?>
        <label class="admin__field">
          <span class="admin__label">Shown on the page</span>
          <select class="admin__input" name="<?= h($name) ?>">
            <option value="shown"<?= $status !== 'hidden' ? ' selected' : '' ?>>Shown — visitors see <?= h($noun) ?></option>
            <option value="hidden"<?= $status === 'hidden' ? ' selected' : '' ?>>Hidden — kept, not shown</option>
          </select>
        </label>
    <?php
}

/** The picture a row holds, shown as it is, with its fields carried across. */
function company_image_fields(string $band, int $i, array $image): void
{
    $field = $band . '[items][' . $i . '][image]';
    ?>
        <div class="admin-card__media">
<?php if ($image['src'] !== ''): ?>
          <img class="admin-card__thumb" src="<?= h(public_url($image['src'])) ?>"
               alt="" width="<?= (int)$image['width'] ?>" height="<?= (int)$image['height'] ?>"
               loading="lazy" decoding="async">
          <p class="admin__fineprint">
            <code><?= h(basename($image['src'])) ?></code>
            &middot; <?= (int)$image['width'] ?>&times;<?= (int)$image['height'] ?>
<?php if ($image['webp'] !== ''): ?>
            &middot; with a WebP version
<?php endif; ?>
          </p>
<?php else: ?>
          <p class="admin__hint">No picture yet.</p>
<?php endif; ?>
        </div>
        <?php /* Carried rather than edited. The paths and the size come from
                 the file itself when it is uploaded, and a size typed by hand
                 is a size that is wrong — which moves the page as it loads.
                 company_image_from_post() re-checks all four against
                 COMPANY_IMAGE_ROOTS anyway, because a hidden input is a text
                 field with the label taken off. */ ?>
        <input type="hidden" name="<?= h($field) ?>[src]" value="<?= h($image['src']) ?>">
        <input type="hidden" name="<?= h($field) ?>[webp]" value="<?= h($image['webp']) ?>">
        <input type="hidden" name="<?= h($field) ?>[width]" value="<?= (int)$image['width'] ?>">
        <input type="hidden" name="<?= h($field) ?>[height]" value="<?= (int)$image['height'] ?>">
    <?php
}

/** A band's heading, its show/hide switch, and the blurb under it. */
function company_band_header(array $data, string $band, string $legend, string $blurb): void
{
    ?>
    <legend class="admin__section-title"><?= h($legend) ?></legend>
    <p class="admin__blurb"><?= h($blurb) ?></p>
    <div class="admin__grid">
      <?php company_status_field($band . '[status]',
          (string)($data[$band]['status'] ?? 'shown'), 'this section'); ?>
    </div>
    <?php
}

/** The "Add" button that closes every list. */
function company_add_button(string $band, string $label): void
{
    ?>
      <div class="admin__actions">
        <button class="btn btn--ghost" type="submit" name="do" value="<?= h($band) ?>-add:0">
          <?= h($label) ?>
        </button>
      </div>
    <?php
}

admin_head('company', $user,
    'Editing <code>content/company.json</code>. Changes go live on '
    . '<a href="' . h(public_url('/pages/company-profile/')) . '">the company profile</a> '
    . 'within a second — as soon as the live site accepts the publish.');

admin_notices($errors);

if (!$errors && $pending !== '') {
    echo '<p class="admin__notice admin__notice--ok">' . h($pending) . '</p>';
}
?>

<form class="admin__form" method="post" action="<?= h(admin_url('company')) ?>">
  <?= admin_form_fields('company') ?>

  <?php /* Pressing Enter in a text field submits the form using the first
           submit button in the document, which would otherwise be "Add an
           entry". This is that first button, and it saves. */ ?>
  <button class="visually-hidden" type="submit" name="do" value="save"
          tabindex="-1" aria-hidden="true">Save</button>

  <!-- ========================= the banner ========================= -->
  <fieldset class="admin__block">
    <legend class="admin__section-title">The banner</legend>
    <p class="admin__blurb">The band at the top of the page, with the circuitry around it.</p>

    <div class="admin__grid">
      <label class="admin__field admin__field--wide">
        <span class="admin__label">Page title</span>
        <input class="admin__input" type="text" name="hero[title]" required
               value="<?= h($data['hero']['title']) ?>">
        <span class="admin__hint">The big heading. It is the page's only h1.</span>
      </label>

      <label class="admin__field admin__field--wide">
        <span class="admin__label">Under it</span>
        <input class="admin__input" type="text" name="hero[subtitle]"
               value="<?= h($data['hero']['subtitle']) ?>">
        <span class="admin__hint">Leave empty to show nothing under the heading.</span>
      </label>
    </div>
  </fieldset>

  <!-- ========================= milestones ========================= -->
  <fieldset class="admin__block">
    <?php company_band_header($data, 'milestones', 'Milestones',
        'The timeline. Entries alternate left and right down the page, so the '
        . 'order decides which side each one lands on.'); ?>

    <div class="admin__grid">
      <label class="admin__field">
        <span class="admin__label">Eyebrow</span>
        <input class="admin__input" type="text" name="milestones[eyebrow]"
               value="<?= h($data['milestones']['eyebrow']) ?>">
        <span class="admin__hint">The small line above the heading.</span>
      </label>

      <label class="admin__field">
        <span class="admin__label">Heading</span>
        <input class="admin__input" type="text" name="milestones[title]"
               value="<?= h($data['milestones']['title']) ?>">
      </label>
    </div>

    <?php /* A <div>, not a <label>, and deliberately: a <label> forwards a
             click from anywhere inside it to its first labelable descendant,
             and editor.js puts its toolbar BEFORE the textarea — so every
             click in the text would press Bold. The plain fields above wrap
             their input in a <label> because there the forwarding is exactly
             what you want; here it is a trap. */ ?>
    <div class="admin__field admin__field--wide">
      <label class="admin__label" for="milestones-lead">Introduction</label>
      <textarea class="admin__input admin__textarea" id="milestones-lead"
                name="milestones[lead]" rows="3" data-editor><?= h($data['milestones']['lead']) ?></textarea>
      <span class="admin__hint">Leave empty to show nothing under the heading.</span>
    </div>

<?php $rows = $data['milestones']['items']; $total = count($rows); ?>
<?php foreach ($rows as $i => $row): ?>
    <div class="admin-card<?= $row['status'] === 'hidden' ? ' admin-card--hidden' : '' ?>">
      <input type="hidden" name="milestones[items][<?= $i ?>][id]" value="<?= h($row['id']) ?>">
      <?php company_row_controls('milestones', $i, $total,
          $row['title'] !== '' ? $row['title'] : 'entry ' . ($i + 1)); ?>

      <div class="admin__grid">
        <label class="admin__field">
          <span class="admin__label">Year</span>
          <input class="admin__input" type="text" name="milestones[items][<?= $i ?>][year]"
                 value="<?= h($row['year']) ?>" placeholder="2024">
        </label>

        <label class="admin__field">
          <span class="admin__label">What happened</span>
          <input class="admin__input" type="text" name="milestones[items][<?= $i ?>][title]"
                 value="<?= h($row['title']) ?>">
        </label>

        <label class="admin__field admin__field--wide">
          <span class="admin__label">In a sentence</span>
          <input class="admin__input" type="text" name="milestones[items][<?= $i ?>][text]"
                 value="<?= h($row['text']) ?>">
        </label>
      </div>

      <?php company_status_field("milestones[items][$i][status]",
          (string)$row['status'], 'this entry'); ?>
    </div>
<?php endforeach; ?>

    <?php company_add_button('milestones', 'Add a milestone'); ?>
  </fieldset>

  <!-- ===================== the background band ===================== -->
  <fieldset class="admin__block">
    <?php company_band_header($data, 'background', 'Our Background',
        'The surface the three blocks below sit on. Hiding this hides all '
        . 'three of them, whatever their own switches say.'); ?>

    <div class="admin__grid">
      <label class="admin__field">
        <span class="admin__label">Eyebrow</span>
        <input class="admin__input" type="text" name="background[eyebrow]"
               value="<?= h($data['background']['eyebrow']) ?>">
      </label>

      <label class="admin__field">
        <span class="admin__label">Heading</span>
        <input class="admin__input" type="text" name="background[title]"
               value="<?= h($data['background']['title']) ?>">
      </label>
    </div>
  </fieldset>

  <!-- ========================= the figures ========================= -->
  <fieldset class="admin__block">
    <?php company_band_header($data, 'experience', 'The figures',
        'The four numbers that count up as the block comes into view.'); ?>

    <label class="admin__field admin__field--wide">
      <span class="admin__label">Block heading</span>
      <input class="admin__input" type="text" name="experience[title]"
             value="<?= h($data['experience']['title']) ?>">
    </label>

<?php $rows = $data['experience']['items']; $total = count($rows); ?>
<?php foreach ($rows as $i => $row): ?>
    <div class="admin-card<?= $row['status'] === 'hidden' ? ' admin-card--hidden' : '' ?>">
      <input type="hidden" name="experience[items][<?= $i ?>][id]" value="<?= h($row['id']) ?>">
      <?php company_row_controls('experience', $i, $total,
          $row['label'] !== '' ? $row['label'] : 'figure ' . ($i + 1)); ?>

      <div class="admin__grid">
        <label class="admin__field">
          <span class="admin__label">The number</span>
          <input class="admin__input" type="text" name="experience[items][<?= $i ?>][figure]"
                 value="<?= h($row['figure']) ?>" placeholder="100+">
          <span class="admin__hint">
            Must start with a digit. The animation counts the number off the
            front and keeps whatever follows — “100+” and “99%” work,
            “Over 100” does not.
          </span>
        </label>

        <label class="admin__field">
          <span class="admin__label">What it counts</span>
          <input class="admin__input" type="text" name="experience[items][<?= $i ?>][label]"
                 value="<?= h($row['label']) ?>">
        </label>
      </div>

      <?php company_status_field("experience[items][$i][status]",
          (string)$row['status'], 'this entry'); ?>
    </div>
<?php endforeach; ?>

    <?php company_add_button('experience', 'Add a figure'); ?>
  </fieldset>

  <!-- ========================= the clients ========================= -->
  <fieldset class="admin__block">
    <?php company_band_header($data, 'clients', 'Proud Clients',
        'The wall of logos. The name is what a screen reader announces, so it '
        . 'is not optional.'); ?>

    <label class="admin__field admin__field--wide">
      <span class="admin__label">Block heading</span>
      <input class="admin__input" type="text" name="clients[title]"
             value="<?= h($data['clients']['title']) ?>">
    </label>

<?php $rows = $data['clients']['items']; $total = count($rows); ?>
<?php foreach ($rows as $i => $row): ?>
    <div class="admin-card<?= $row['status'] === 'hidden' ? ' admin-card--hidden' : '' ?>">
      <input type="hidden" name="clients[items][<?= $i ?>][id]" value="<?= h($row['id']) ?>">
      <?php company_row_controls('clients', $i, $total,
          $row['name'] !== '' ? $row['name'] : 'client ' . ($i + 1)); ?>

      <?php company_image_fields('clients', $i, $row['image']); ?>

      <label class="admin__field admin__field--wide">
        <span class="admin__label">Name</span>
        <input class="admin__input" type="text" name="clients[items][<?= $i ?>][name]"
               value="<?= h($row['name']) ?>">
      </label>

      <?php company_status_field("clients[items][$i][status]",
          (string)$row['status'], 'this entry'); ?>
    </div>
<?php endforeach; ?>

    <?php company_add_button('clients', 'Add a client'); ?>
  </fieldset>

  <!-- ======================= the photographs ======================= -->
  <fieldset class="admin__block">
    <?php company_band_header($data, 'journey', 'Our Journey of Growth',
        'The slideshow. One photograph at a time, and the whole row without '
        . 'JavaScript.'); ?>

    <div class="admin__grid">
      <label class="admin__field">
        <span class="admin__label">Block heading</span>
        <input class="admin__input" type="text" name="journey[title]"
               value="<?= h($data['journey']['title']) ?>">
      </label>

      <label class="admin__field">
        <span class="admin__label">Seconds on each photograph</span>
        <input class="admin__input" type="number" name="journey[interval]"
               min="2000" max="60000" step="500"
               value="<?= (int)$data['journey']['interval'] ?>">
        <span class="admin__hint">
          In milliseconds. 6000 is six seconds. It never advances on its own for
          somebody who has asked for reduced motion.
        </span>
      </label>
    </div>

    <div class="admin__field admin__field--wide">
      <label class="admin__label" for="journey-lead">Introduction</label>
      <textarea class="admin__input admin__textarea" id="journey-lead"
                name="journey[lead]" rows="3" data-editor><?= h($data['journey']['lead']) ?></textarea>
    </div>

<?php $rows = $data['journey']['items']; $total = count($rows); ?>
<?php foreach ($rows as $i => $row): ?>
    <div class="admin-card<?= $row['status'] === 'hidden' ? ' admin-card--hidden' : '' ?>">
      <input type="hidden" name="journey[items][<?= $i ?>][id]" value="<?= h($row['id']) ?>">
      <?php company_row_controls('journey', $i, $total, 'photograph ' . ($i + 1)); ?>

      <?php company_image_fields('journey', $i, $row['image']); ?>

      <label class="admin__field admin__field--wide">
        <span class="admin__label">What is in the picture</span>
        <input class="admin__input" type="text" name="journey[items][<?= $i ?>][alt]"
               value="<?= h($row['alt']) ?>">
        <span class="admin__hint">
          Describe it for somebody who cannot see it. Not “photo” or “image” —
          say what is happening.
        </span>
      </label>

      <?php company_status_field("journey[items][$i][status]",
          (string)$row['status'], 'this entry'); ?>
    </div>
<?php endforeach; ?>

    <?php company_add_button('journey', 'Add a photograph'); ?>
  </fieldset>

  <!-- ==================== the excellence band ==================== -->
  <fieldset class="admin__block">
    <?php company_band_header($data, 'excellence', 'Our Professional Excellence',
        'The band holding the technology list and the principles. Hiding this '
        . 'hides both of them.'); ?>

    <div class="admin__grid">
      <label class="admin__field">
        <span class="admin__label">Eyebrow</span>
        <input class="admin__input" type="text" name="excellence[eyebrow]"
               value="<?= h($data['excellence']['eyebrow']) ?>">
      </label>

      <label class="admin__field">
        <span class="admin__label">Heading</span>
        <input class="admin__input" type="text" name="excellence[title]"
               value="<?= h($data['excellence']['title']) ?>">
      </label>
    </div>

    <div class="admin__field admin__field--wide">
      <label class="admin__label" for="excellence-lead">Introduction</label>
      <textarea class="admin__input admin__textarea" id="excellence-lead"
                name="excellence[lead]" rows="3" data-editor><?= h($data['excellence']['lead']) ?></textarea>
    </div>
  </fieldset>

  <!-- ======================== the technology ======================== -->
  <fieldset class="admin__block">
    <?php company_band_header($data, 'technology', 'The Technology We Work With',
        'The logos that become the rotating sphere on a wide screen, and an '
        . 'ordinary grid on a narrow one or with JavaScript off. Position '
        . 'decides where a logo sits on the sphere, so reordering moves '
        . 'everything.'); ?>

    <label class="admin__field admin__field--wide">
      <span class="admin__label">Block heading</span>
      <input class="admin__input" type="text" name="technology[title]"
             value="<?= h($data['technology']['title']) ?>">
    </label>

<?php $rows = $data['technology']['items']; $total = count($rows); ?>
<?php foreach ($rows as $i => $row): ?>
    <div class="admin-card<?= $row['status'] === 'hidden' ? ' admin-card--hidden' : '' ?>">
      <input type="hidden" name="technology[items][<?= $i ?>][id]" value="<?= h($row['id']) ?>">
      <?php company_row_controls('technology', $i, $total,
          $row['name'] !== '' ? $row['name'] : 'entry ' . ($i + 1)); ?>

      <?php company_image_fields('technology', $i, $row['image']); ?>

      <label class="admin__field admin__field--wide">
        <span class="admin__label">Name</span>
        <input class="admin__input" type="text" name="technology[items][<?= $i ?>][name]"
               value="<?= h($row['name']) ?>">
      </label>

      <?php company_status_field("technology[items][$i][status]",
          (string)$row['status'], 'this entry'); ?>
    </div>
<?php endforeach; ?>

    <?php company_add_button('technology', 'Add a technology'); ?>
  </fieldset>

  <!-- ======================== the principles ======================== -->
  <fieldset class="admin__block">
    <?php company_band_header($data, 'principles', 'The Principles That Guide Us',
        'Four cards, each with an icon.'); ?>

    <label class="admin__field admin__field--wide">
      <span class="admin__label">Block heading</span>
      <input class="admin__input" type="text" name="principles[title]"
             value="<?= h($data['principles']['title']) ?>">
    </label>

<?php $rows = $data['principles']['items']; $total = count($rows); ?>
<?php foreach ($rows as $i => $row): ?>
    <div class="admin-card<?= $row['status'] === 'hidden' ? ' admin-card--hidden' : '' ?>">
      <input type="hidden" name="principles[items][<?= $i ?>][id]" value="<?= h($row['id']) ?>">
      <?php company_row_controls('principles', $i, $total,
          $row['title'] !== '' ? $row['title'] : 'principle ' . ($i + 1)); ?>

      <div class="admin__grid">
        <label class="admin__field">
          <span class="admin__label">Icon</span>
          <select class="admin__input" name="principles[items][<?= $i ?>][icon]">
            <option value=""<?= $row['icon'] === '' ? ' selected' : '' ?>>No icon</option>
<?php foreach (COMPANY_ICONS as $name => $label): ?>
            <option value="<?= h($name) ?>"<?= $row['icon'] === $name ? ' selected' : '' ?>><?= h($label) ?></option>
<?php endforeach; ?>
          </select>
        </label>

        <div class="admin__field">
          <span class="admin__label">As it will look</span>
          <p class="admin-card__icon">
            <?= $row['icon'] !== '' ? admin_icon($row['icon'], 'icon') : '' ?>
          </p>
          <span class="admin__hint">Save to change what is drawn here.</span>
        </div>

        <label class="admin__field admin__field--wide">
          <span class="admin__label">Title</span>
          <input class="admin__input" type="text" name="principles[items][<?= $i ?>][title]"
                 value="<?= h($row['title']) ?>">
        </label>

        <label class="admin__field admin__field--wide">
          <span class="admin__label">In a sentence</span>
          <input class="admin__input" type="text" name="principles[items][<?= $i ?>][text]"
                 value="<?= h($row['text']) ?>">
        </label>
      </div>

      <?php company_status_field("principles[items][$i][status]",
          (string)$row['status'], 'this entry'); ?>
    </div>
<?php endforeach; ?>

    <?php company_add_button('principles', 'Add a principle'); ?>
  </fieldset>

  <!-- ========================= the closing band ========================= -->
  <fieldset class="admin__block">
    <?php company_band_header($data, 'cta', 'The closing band',
        'The dark strip at the bottom with the button.'); ?>

    <label class="admin__field admin__field--wide">
      <span class="admin__label">Heading</span>
      <input class="admin__input" type="text" name="cta[title]"
             value="<?= h($data['cta']['title']) ?>">
    </label>

    <div class="admin__field admin__field--wide">
      <label class="admin__label" for="cta-text">The line under it</label>
      <textarea class="admin__input admin__textarea" id="cta-text"
                name="cta[text]" rows="3" data-editor><?= h($data['cta']['text']) ?></textarea>
    </div>

    <div class="admin__grid">
      <label class="admin__field">
        <span class="admin__label">Button</span>
        <input class="admin__input" type="text" name="cta[label]"
               value="<?= h($data['cta']['label']) ?>">
        <span class="admin__hint">Leave empty to show no button at all.</span>
      </label>

      <label class="admin__field">
        <span class="admin__label">Where it goes</span>
        <input class="admin__input" type="text" name="cta[href]"
               value="<?= h($data['cta']['href']) ?>" placeholder="/pages/contact/">
        <span class="admin__hint">A path on this site, or a full https:// address.</span>
      </label>

      <label class="admin__field">
        <span class="admin__label">Button icon</span>
        <select class="admin__input" name="cta[icon]">
          <option value=""<?= $data['cta']['icon'] === '' ? ' selected' : '' ?>>No icon</option>
<?php foreach (COMPANY_ICONS as $name => $label): ?>
          <option value="<?= h($name) ?>"<?= $data['cta']['icon'] === $name ? ' selected' : '' ?>><?= h($label) ?></option>
<?php endforeach; ?>
        </select>
      </label>
    </div>
  </fieldset>

  <!-- ============================ search ============================ -->
  <fieldset class="admin__block">
    <legend class="admin__section-title">How it appears elsewhere</legend>
    <p class="admin__blurb">
      What a search engine shows, and what appears when somebody pastes a link
      to this page into a chat.
    </p>

    <div class="admin__grid">
      <label class="admin__field admin__field--wide">
        <span class="admin__label">Browser tab title</span>
        <input class="admin__input" type="text" name="meta[title]" required
               value="<?= h($data['meta']['title']) ?>">
      </label>

      <label class="admin__field admin__field--wide">
        <span class="admin__label">Search description</span>
        <input class="admin__input" type="text" name="meta[description]"
               value="<?= h($data['meta']['description']) ?>">
        <span class="admin__hint">Aim for 150–160 characters.</span>
      </label>

      <label class="admin__field admin__field--wide">
        <span class="admin__label">Title on a shared link</span>
        <input class="admin__input" type="text" name="meta[share_title]"
               value="<?= h($data['meta']['share_title']) ?>">
      </label>
    </div>
  </fieldset>

  <div class="admin__actions admin__actions--sticky">
    <button class="btn btn--primary btn--lg" type="submit" name="do" value="save">
      Save the company profile
    </button>
  </div>
</form>

<?php
admin_foot(
    '<p>Last saved ' . h((string)($data['updated'] ?: 'never')) . '. '
    . 'A backup of the previous version is kept as '
    . '<code>content/company.json.bak</code>.</p>'
);
