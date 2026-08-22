<?php
/**
 * Tech4TIME — contact page editor.
 *
 * Everything on /pages/contact/ that is words rather than structure: the
 * banner, the copy around the form, the list of services the form offers, the
 * "Reach Us Directly" rows, and the offices with their addresses, numbers,
 * opening hours and flags. Stored in content/contact.json; there is no
 * database.
 *
 * WHY ONE FORM RATHER THAN A LIST AND AN EDIT SCREEN
 * The careers editor lists posts and opens one at a time, because a job post
 * is a page's worth of writing. A contact page is a handful of short fields
 * that are usually changed together — a new office arrives with its address,
 * its numbers and its flag at once — so the whole page is one form, and the
 * add, remove and reorder buttons submit it without saving so that nothing
 * typed is lost on the way.
 *
 * THE SHAPE OF THIS FORM FOLLOWS THE SHAPE OF THE PAGE
 * Each fieldset below is one band of /pages/contact/, in the order a visitor
 * meets them. If that page gains or loses a band, this form and lib/contact.php
 * change with it — tools/check_content_model.py fails if one of the three is
 * left behind.
 *
 * Included by admin/index.php, which has already checked the password and
 * started the session.
 */

declare(strict_types=1);

if (!defined('T4T_ADMIN')) {
    http_response_code(403);
    exit('Not a page.');
}

require_once __DIR__ . '/../../lib/contact.php';

/* ---------------------------------------------------------------- reading */

/**
 * Rebuild the whole document from what the browser sent.
 *
 * Everything is re-trimmed and re-sanitised here rather than trusted: the
 * form's own constraints are a convenience for whoever is typing, and this is
 * the code that decides what gets stored.
 */
function contact_from_post(array $current): array
{
    $data = $current;

    foreach (CONTACT_TEXT_FIELDS as $section => $fields) {
        foreach ($fields as $field) {
            $data[$section][$field] = trim((string)($_POST[$section][$field] ?? ''));
        }
    }

    foreach (CONTACT_RICH_FIELDS as $section => $fields) {
        foreach ($fields as $field) {
            $data[$section][$field] = rt_sanitise_html((string)($_POST[$section][$field] ?? ''));
        }
    }

    $data['form']['service_types'] =
        contact_string_list((string)($_POST['form']['service_types'] ?? ''));

    /* Rows arrive keyed by their position in the form. Removing one leaves a
       hole in those keys, so they are renumbered rather than trusted. */
    $data['reach']['items'] = [];
    foreach (array_values((array)($_POST['reach']['items'] ?? [])) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $data['reach']['items'][] = contact_reach_defaults([
            'icon'   => isset(CONTACT_ICONS[$row['icon'] ?? '']) ? (string)$row['icon'] : '',
            'label'  => trim((string)($row['label'] ?? '')),
            'type'   => isset(CONTACT_REACH_TYPES[$row['type'] ?? '']) ? (string)$row['type'] : 'text',
            'values' => contact_string_list((string)($row['values'] ?? '')),
            'text'   => trim((string)($row['text'] ?? '')),
        ]);
    }

    $taken = [];
    $data['offices']['items'] = [];
    foreach (array_values((array)($_POST['offices']['items'] ?? [])) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $name = trim((string)($row['name'] ?? ''));
        $id = trim((string)($row['id'] ?? ''));
        if ($id === '' || in_array($id, $taken, true)) {
            $id = contact_slug($name, $taken);
        }
        $taken[] = $id;

        $flag = trim((string)($row['flag'] ?? ''));

        $data['offices']['items'][] = contact_office_defaults([
            'id'        => $id,
            'name'      => $name,
            'flag'      => in_array($flag, contact_flags(), true) ? $flag : '',
            'address'   => trim((string)($row['address'] ?? '')),
            'phones'    => contact_string_list((string)($row['phones'] ?? '')),
            'hours'     => trim((string)($row['hours'] ?? '')),
            'languages' => contact_string_list(
                str_replace(',', "\n", (string)($row['languages'] ?? ''))
            ),
            'status'    => ($row['status'] ?? 'shown') === 'hidden' ? 'hidden' : 'shown',
            'schema'    => [
                'street'      => trim((string)($row['schema']['street'] ?? '')),
                'locality'    => trim((string)($row['schema']['locality'] ?? '')),
                'region'      => trim((string)($row['schema']['region'] ?? '')),
                'postal_code' => trim((string)($row['schema']['postal_code'] ?? '')),
                'country'     => strtoupper(trim((string)($row['schema']['country'] ?? ''))),
            ],
        ]);
    }

    return $data;
}

/**
 * Apply an add / remove / move button to one of the two lists.
 *
 * The button's value carries what to do and to which row, as "reach-up:2".
 * Returns the new document and a sentence saying what happened, or null when
 * the instruction did not name anything that exists.
 */
function contact_apply_row_action(array $data, string $do): ?array
{
    [$verb, $index] = array_pad(explode(':', $do, 2), 2, '');
    $index = (int)$index;

    $lists = [
        'reach'  => ['reach', 'items'],
        'office' => ['offices', 'items'],
    ];

    foreach ($lists as $prefix => [$outer, $inner]) {
        if (!str_starts_with($verb, $prefix . '-')) {
            continue;
        }
        $rows = $data[$outer][$inner];
        $what = substr($verb, strlen($prefix) + 1);

        if ($what === 'add') {
            $rows[] = $prefix === 'reach'
                ? contact_reach_defaults(['icon' => 'envelope', 'type' => 'text'])
                : contact_office_defaults(['name' => '', 'status' => 'hidden']);
            $data[$outer][$inner] = $rows;
            return [$data, $prefix === 'reach'
                ? 'Added a row. Fill it in and save.'
                : 'Added an office. It is hidden until you show it — fill it in, then save.'];
        }

        if (!isset($rows[$index])) {
            return null;
        }

        if ($what === 'remove') {
            array_splice($rows, $index, 1);
            $data[$outer][$inner] = array_values($rows);
            return [$data, 'Removed. Nothing is written to the site until you save.'];
        }

        $to = $index + ($what === 'up' ? -1 : 1);
        if ($what === 'up' || $what === 'down') {
            if ($to < 0 || $to >= count($rows)) {
                return null;
            }
            [$rows[$index], $rows[$to]] = [$rows[$to], $rows[$index]];
            $data[$outer][$inner] = $rows;
            return [$data, 'Moved. Nothing is written to the site until you save.'];
        }
    }

    return null;
}

/* ---------------------------------------------------------------- actions */

$data = contact_load();
$pending = '';   /* an unsaved change made by a row button */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_check_csrf();

    $do = (string)($_POST['do'] ?? 'save');
    $posted = contact_from_post($data);

    if ($do === 'save') {
        $errors = contact_validate($posted);
        if (!$errors) {
            if (contact_save($posted)) {
                admin_redirect('contact', 'Saved the contact page.');
            }
            $errors[] = 'Could not write content/contact.json. Check the file is writable by PHP.';
        }
        /* Redraw with what was typed rather than throwing it away. */
        $data = $posted;
    } else {
        $applied = contact_apply_row_action($posted, $do);
        $data = $applied[0] ?? $posted;
        $pending = $applied[1] ?? '';
    }
}

/* ---------------------------------------------------------------- helpers */

/** One row of buttons: move up, move down, remove. */
function contact_row_controls(string $prefix, int $index, int $total, string $label): void
{
    ?>
        <div class="admin-card__controls">
          <button class="btn btn--ghost" type="submit" name="do" value="<?= h($prefix) ?>-up:<?= $index ?>"
                  aria-label="Move <?= h($label) ?> up"<?= $index === 0 ? ' disabled' : '' ?>>↑</button>
          <button class="btn btn--ghost" type="submit" name="do" value="<?= h($prefix) ?>-down:<?= $index ?>"
                  aria-label="Move <?= h($label) ?> down"<?= $index === $total - 1 ? ' disabled' : '' ?>>↓</button>
          <button class="btn btn--ghost admin-row__delete" type="submit"
                  name="do" value="<?= h($prefix) ?>-remove:<?= $index ?>">Remove</button>
        </div>
    <?php
}

$inStep = contact_footer_in_step($data);

admin_head('contact', $user,
    'Editing <code>content/contact.json</code>. Changes go live on '
    . '<a href="/pages/contact/">the contact page</a> immediately.');

admin_notices($errors);

if (!$errors && $pending !== '') {
    echo '<p class="admin__notice admin__notice--ok">' . h($pending) . '</p>';
}
?>

<?php /* ----------------------------------------------------------- drift */ ?>
<?php if (!$inStep): ?>
  <div class="admin__notice admin__notice--warn">
    <p><?= admin_icon('info-circle', 'icon icon--sm') ?>
       <strong>The site footer is showing older details.</strong></p>
    <p>
      Every page repeats the email address, phone numbers, addresses and opening
      hours in its footer. Those are part of each page's own file, so this
      editor cannot reach them — the contact page below is correct, the footers
      elsewhere are not.
    </p>
    <p class="admin__fineprint">
      To bring them into line, whoever maintains the site runs
      <code>python3 tools/sync_site_contact.py</code> against this
      <code>content/contact.json</code> and re-uploads the pages. This notice
      clears itself once that is done.
    </p>
  </div>
<?php endif; ?>

<form class="admin__form" method="post" action="<?= h(admin_url('contact')) ?>">
  <?= admin_form_fields('contact') ?>

  <?php /* Pressing Enter in a text field submits the form using the first
           submit button in the document, which would otherwise be "Add a row".
           This is that first button, and it saves. */ ?>
  <button class="visually-hidden" type="submit" name="do" value="save"
          tabindex="-1" aria-hidden="true">Save</button>

  <!-- ========================= banner ========================= -->
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
        <span class="admin__label">Tagline</span>
        <input class="admin__input" type="text" name="hero[subtitle]"
               value="<?= h($data['hero']['subtitle']) ?>">
        <span class="admin__hint">The line under it. Leave empty to drop it.</span>
      </label>
    </div>
  </fieldset>

  <!-- ========================== form ========================== -->
  <fieldset class="admin__block">
    <legend class="admin__section-title">The enquiry form</legend>
    <p class="admin__blurb">
      The words around the form. The fields themselves — name, phone, email,
      service, message — are part of the page and of
      <code>contact-handler.php</code>, which validates them; they are not
      editable here.
    </p>

    <div class="admin__grid">
      <label class="admin__field admin__field--wide">
        <span class="admin__label">Heading</span>
        <input class="admin__input" type="text" name="form[title]"
               value="<?= h($data['form']['title']) ?>">
      </label>
    </div>

    <div class="admin__field admin__field--wide">
      <label class="admin__label" for="field-form-lead">Introduction</label>
      <textarea class="admin__input admin__textarea" id="field-form-lead"
                name="form[lead]" rows="4" data-editor><?= h($data['form']['lead']) ?></textarea>
    </div>

    <div class="admin__grid">
      <label class="admin__field admin__field--wide">
        <span class="admin__label">Services offered in the “Type of Service” box</span>
        <textarea class="admin__input admin__textarea" name="form[service_types]"
                  rows="7"><?= h(implode("\n", $data['form']['service_types'])) ?></textarea>
        <span class="admin__hint">
          One per line. They are suggestions, not a fixed list — a visitor can
          type anything, which is deliberate: a closed list turns away work we
          do but have not named.
        </span>
      </label>

      <label class="admin__field admin__field--wide">
        <span class="admin__label">Hint under that box</span>
        <input class="admin__input" type="text" name="form[subject_hint]"
               value="<?= h($data['form']['subject_hint']) ?>">
      </label>

      <label class="admin__field admin__field--wide">
        <span class="admin__label">Reassurance under the Send button</span>
        <input class="admin__input" type="text" name="form[note]"
               value="<?= h($data['form']['note']) ?>">
        <span class="admin__hint">Shown beside a padlock. Leave empty to drop it.</span>
      </label>
    </div>
  </fieldset>

  <!-- ========================== reach ========================== -->
  <fieldset class="admin__block">
    <legend class="admin__section-title">Reach us directly</legend>
    <p class="admin__blurb">
      The short list beside the form. A row becomes a link when its kind says
      it should — an email address opens a mail app, a phone number dials.
    </p>

    <div class="admin__grid">
      <label class="admin__field admin__field--wide">
        <span class="admin__label">Heading</span>
        <input class="admin__input" type="text" name="reach[title]"
               value="<?= h($data['reach']['title']) ?>">
      </label>
    </div>

<?php $rows = $data['reach']['items']; $total = count($rows); ?>
<?php if (!$rows): ?>
    <p class="admin__empty">No rows. The panel beside the form is empty.</p>
<?php endif; ?>

<?php foreach ($rows as $i => $row): ?>
    <div class="admin-card">
      <div class="admin-card__head">
        <span class="admin-card__index"><?= $i + 1 ?></span>
        <span class="admin-card__preview">
          <?php if (isset(CONTACT_ICONS[$row['icon']])): ?>
            <?= admin_icon($row['icon'], 'icon') ?>
          <?php endif; ?>
          <strong><?= h($row['label'] !== '' ? $row['label'] : 'Untitled row') ?></strong>
          <span class="admin-card__value"><?= h(implode(' · ', $row['values'])) ?></span>
        </span>
        <?php contact_row_controls('reach', $i, $total, $row['label'] !== '' ? $row['label'] : 'row ' . ($i + 1)); ?>
      </div>

      <div class="admin__grid">
        <label class="admin__field">
          <span class="admin__label">Label</span>
          <input class="admin__input" type="text" name="reach[items][<?= $i ?>][label]"
                 value="<?= h($row['label']) ?>" placeholder="Email">
        </label>

        <label class="admin__field">
          <span class="admin__label">Kind</span>
          <select class="admin__input" name="reach[items][<?= $i ?>][type]">
<?php foreach (CONTACT_REACH_TYPES as $key => $label): ?>
            <option value="<?= h($key) ?>"<?= $row['type'] === $key ? ' selected' : '' ?>><?= h($label) ?></option>
<?php endforeach; ?>
          </select>
        </label>

        <label class="admin__field">
          <span class="admin__label">Icon</span>
          <select class="admin__input" name="reach[items][<?= $i ?>][icon]">
            <option value="">No icon</option>
<?php foreach (CONTACT_ICONS as $key => $label): ?>
            <option value="<?= h($key) ?>"<?= $row['icon'] === $key ? ' selected' : '' ?>><?= h($label) ?></option>
<?php endforeach; ?>
          </select>
        </label>

        <label class="admin__field admin__field--wide">
          <span class="admin__label">Value</span>
          <textarea class="admin__input admin__textarea" rows="3"
                    name="reach[items][<?= $i ?>][values]"><?= h(implode("\n", $row['values'])) ?></textarea>
          <span class="admin__hint">
            The address, number, web address or sentence — whichever the kind
            says. <strong>One per line if there is more than one</strong>: three
            numbers here become three dialling links under a single “Phone”
            heading, rather than three separate rows. Use “Add a row” instead
            when the numbers deserve headings of their own.
          </span>
        </label>

        <label class="admin__field admin__field--wide">
          <span class="admin__label">Link text</span>
          <input class="admin__input" type="text" name="reach[items][<?= $i ?>][text]"
                 value="<?= h($row['text']) ?>">
          <span class="admin__hint">
            Optional, and only useful for a web address with one value:
            “Tech4TIME” reads better than the whole LinkedIn URL. Ignored when
            the row has several values, since they would all read alike.
          </span>
        </label>
      </div>
    </div>
<?php endforeach; ?>

    <div class="admin__actions">
      <button class="btn btn--secondary" type="submit" name="do" value="reach-add">Add a row</button>
    </div>
  </fieldset>

  <!-- ========================= offices ========================= -->
  <fieldset class="admin__block">
    <legend class="admin__section-title">Our offices</legend>
    <p class="admin__blurb">The band at the foot of the page, one card per office.</p>

    <div class="admin__grid">
      <label class="admin__field">
        <span class="admin__label">Eyebrow</span>
        <input class="admin__input" type="text" name="offices[eyebrow]"
               value="<?= h($data['offices']['eyebrow']) ?>">
        <span class="admin__hint">The small line above the heading.</span>
      </label>

      <label class="admin__field">
        <span class="admin__label">Heading</span>
        <input class="admin__input" type="text" name="offices[title]"
               value="<?= h($data['offices']['title']) ?>">
      </label>
    </div>

    <div class="admin__field admin__field--wide">
      <label class="admin__label" for="field-offices-lead">Introduction</label>
      <textarea class="admin__input admin__textarea" id="field-offices-lead"
                name="offices[lead]" rows="3" data-editor><?= h($data['offices']['lead']) ?></textarea>
    </div>

<?php $flags = contact_flags(); ?>
<?php $rows = $data['offices']['items']; $total = count($rows); ?>
<?php if (!$rows): ?>
    <p class="admin__empty">No offices. The band at the foot of the page is empty.</p>
<?php endif; ?>

<?php foreach ($rows as $i => $office): ?>
    <div class="admin-card">
      <div class="admin-card__head">
        <span class="admin-card__index"><?= $i + 1 ?></span>
        <span class="admin-card__preview">
          <strong><?= h($office['name'] !== '' ? $office['name'] : 'Untitled office') ?></strong>
          <span class="admin-card__value"><?= h($office['address']) ?></span>
        </span>
        <span class="admin-row__status admin-row__status--<?= $office['status'] === 'shown' ? 'open' : 'draft' ?>">
          <?= $office['status'] === 'shown' ? 'Shown' : 'Hidden' ?>
        </span>
        <?php contact_row_controls('office', $i, $total, $office['name'] !== '' ? $office['name'] : 'office ' . ($i + 1)); ?>
      </div>

      <input type="hidden" name="offices[items][<?= $i ?>][id]" value="<?= h($office['id']) ?>">

      <div class="admin__grid">
        <label class="admin__field">
          <span class="admin__label">Name</span>
          <input class="admin__input" type="text" name="offices[items][<?= $i ?>][name]"
                 value="<?= h($office['name']) ?>" placeholder="Bangladesh">
          <span class="admin__hint">The card's heading, and the flag's alt text.</span>
        </label>

        <label class="admin__field">
          <span class="admin__label">Flag</span>
          <select class="admin__input" name="offices[items][<?= $i ?>][flag]">
            <option value="">No flag</option>
<?php foreach ($flags as $flag): ?>
            <option value="<?= h($flag) ?>"<?= $office['flag'] === $flag ? ' selected' : '' ?>><?= h(ucfirst(str_replace('-', ' ', $flag))) ?></option>
<?php endforeach; ?>
          </select>
          <span class="admin__hint">
            Add a new one by dropping a .jpg or .png into
            <code>/assets/images/flags/</code>; it appears in this list on the
            next reload.
          </span>
        </label>

        <label class="admin__field">
          <span class="admin__label">Shown on the page</span>
          <select class="admin__input" name="offices[items][<?= $i ?>][status]">
            <option value="shown"<?= $office['status'] === 'shown' ? ' selected' : '' ?>>Shown — visitors see it</option>
            <option value="hidden"<?= $office['status'] === 'hidden' ? ' selected' : '' ?>>Hidden — kept, not shown</option>
          </select>
        </label>

        <label class="admin__field admin__field--wide">
          <span class="admin__label">Address as it should read</span>
          <input class="admin__input" type="text" name="offices[items][<?= $i ?>][address]"
                 value="<?= h($office['address']) ?>" placeholder="278/3, Manikdi, Dhaka - 1206">
          <span class="admin__hint">One line, exactly as it appears on the card.</span>
        </label>

        <label class="admin__field">
          <span class="admin__label">Phone numbers</span>
          <textarea class="admin__input admin__textarea" rows="4"
                    name="offices[items][<?= $i ?>][phones]"><?= h(implode("\n", $office['phones'])) ?></textarea>
          <span class="admin__hint">
            One per line, written the way they should be read. The dialling
            link strips the spaces itself.
          </span>
        </label>

        <label class="admin__field">
          <span class="admin__label">Opening hours</span>
          <input class="admin__input" type="text" name="offices[items][<?= $i ?>][hours]"
                 value="<?= h($office['hours']) ?>" placeholder="Sun – Thu: 9:00 AM – 6:00 PM">
          <span class="admin__hint">Optional. Leave empty to drop the line.</span>
        </label>
      </div>

      <?php /* Closed by default. These change once, when an office opens, and
               a visitor never sees them — putting them in line with the
               address would make every card look like a form to fill in. */ ?>
      <details class="admin__details">
        <summary class="admin__summary">Address for search engines</summary>
        <p class="admin__blurb">
          Not shown to visitors. Google reads these to put the office on a map
          and into a knowledge panel, and it wants the parts separately —
          which is why they are here as well as in the line above.
        </p>
        <div class="admin__grid">
          <label class="admin__field">
            <span class="admin__label">Street</span>
            <input class="admin__input" type="text" name="offices[items][<?= $i ?>][schema][street]"
                   value="<?= h($office['schema']['street']) ?>">
          </label>
          <label class="admin__field">
            <span class="admin__label">City</span>
            <input class="admin__input" type="text" name="offices[items][<?= $i ?>][schema][locality]"
                   value="<?= h($office['schema']['locality']) ?>">
          </label>
          <label class="admin__field">
            <span class="admin__label">Region or state</span>
            <input class="admin__input" type="text" name="offices[items][<?= $i ?>][schema][region]"
                   value="<?= h($office['schema']['region']) ?>">
          </label>
          <label class="admin__field">
            <span class="admin__label">Postcode</span>
            <input class="admin__input" type="text" name="offices[items][<?= $i ?>][schema][postal_code]"
                   value="<?= h($office['schema']['postal_code']) ?>">
          </label>
          <label class="admin__field">
            <span class="admin__label">Country code</span>
            <input class="admin__input" type="text" name="offices[items][<?= $i ?>][schema][country]"
                   value="<?= h($office['schema']['country']) ?>" maxlength="2" placeholder="BD">
            <span class="admin__hint">Two letters: BD, MY, BE.</span>
          </label>
          <label class="admin__field">
            <span class="admin__label">Languages spoken</span>
            <input class="admin__input" type="text" name="offices[items][<?= $i ?>][languages]"
                   value="<?= h(implode(', ', $office['languages'])) ?>" placeholder="English, Bengali">
            <span class="admin__hint">Comma separated. Defaults to English.</span>
          </label>
        </div>
      </details>
    </div>
<?php endforeach; ?>

    <div class="admin__actions">
      <button class="btn btn--secondary" type="submit" name="do" value="office-add">Add an office</button>
    </div>
  </fieldset>

  <!-- ==================== search and sharing ==================== -->
  <fieldset class="admin__block">
    <legend class="admin__section-title">Search results and shared links</legend>
    <p class="admin__blurb">
      What Google shows, and what appears when someone pastes the address of
      this page into a chat. Nobody sees these on the page itself.
    </p>

    <div class="admin__grid">
      <label class="admin__field admin__field--wide">
        <span class="admin__label">Browser tab and search result title</span>
        <input class="admin__input" type="text" name="meta[title]" required
               value="<?= h($data['meta']['title']) ?>">
        <span class="admin__hint">Around 60 characters before Google cuts it off.</span>
      </label>

      <label class="admin__field admin__field--wide">
        <span class="admin__label">Search description</span>
        <textarea class="admin__input" name="meta[description]" rows="3"><?= h($data['meta']['description']) ?></textarea>
        <span class="admin__hint">Around 155 characters. Longer is not an error, it is just not shown.</span>
      </label>

      <label class="admin__field admin__field--wide">
        <span class="admin__label">Title on a shared link</span>
        <input class="admin__input" type="text" name="meta[share_title]"
               value="<?= h($data['meta']['share_title']) ?>">
        <span class="admin__hint">
          Used by WhatsApp, LinkedIn and the rest. Usually shorter and warmer
          than the search title.
        </span>
      </label>
    </div>
  </fieldset>

  <div class="admin__actions admin__actions--sticky">
    <button class="btn btn--primary btn--lg" type="submit" name="do" value="save">Save the contact page</button>
    <a class="btn btn--ghost btn--lg" href="<?= h(admin_url('contact')) ?>">Discard changes</a>
  </div>
</form>

<?php
admin_foot(
    '<p>Last saved ' . h($data['updated'] !== '' ? $data['updated'] : 'never') . '. '
    . 'A backup of the previous version is kept as '
    . '<code>content/contact.json.bak</code>.</p>'
);
