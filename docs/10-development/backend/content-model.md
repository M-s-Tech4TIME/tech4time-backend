# The content model

**Applies to:** both

How a piece of editable content stays consistent across the three places it lives. Read this before
adding or changing a field on the careers or contact page.

---

## The three layers

Every editable field exists in three places, and all three must agree:

```
    lib/contact.php                the MODEL
    contact_defaults()             fields, defaults, validation
           │
           ├──────────────────────────────────┐
           ▼                                  ▼
    admin/sections/contact.php          pages/contact/index.php
    the FORM                            the RENDERER
    what an editor can type             what a visitor sees
           │                                  ▲
           ▼                                  │
    content/contact.json ─────────────────────┘
    the DATA
```

**The page renders straight from the JSON.** There is no intermediate representation and no second
copy of the structure to keep in step. That is what makes the model workable: change the shape and
exactly three files move together.

---

## The model is the definition

`contact_defaults()` and `contact_office_defaults()` are the shape. Not `content/contact.json`.

That distinction matters: the JSON file is **one instance** of the shape. An optional field that
happens to be absent from today's data is still a field. So `check_content_model.py` reads the field
list out of the defaults functions, and anything relying on the JSON to discover the shape would
miss exactly the fields most likely to be forgotten.

---

## Adding a field

Say you are adding a WhatsApp number to each office.

### 1. The model — `lib/contact.php`

```php
function contact_office_defaults(): array
{
    return [
        'id'       => '',
        'city'     => '',
        'address'  => '',
        'phone'    => '',
        'whatsapp' => '',      // ← new
        …
    ];
}
```

Add validation to `contact_validate()` if the value has rules.

### 2. The form — `admin/sections/contact.php`

Add the input. Match the surrounding markup exactly — the editors' fields carry classes the CSS and
`editor.js` both rely on.

```php
<div class="admin__field">
  <label class="admin__label" for="office-<?= $i ?>-whatsapp">WhatsApp</label>
  <input class="admin__input" id="office-<?= $i ?>-whatsapp"
         name="offices[<?= $i ?>][whatsapp]" type="text"
         value="<?= h($office['whatsapp']) ?>">
</div>
```

And read it back where the section handles its POST.

### 3. The renderer — `pages/contact/index.php`

```php
<?php if ($office['whatsapp'] !== ''): ?>
  <a href="<?= h(contact_reach_href('whatsapp', $office['whatsapp'])) ?>">
    <?= h($office['whatsapp']) ?>
  </a>
<?php endif; ?>
```

**Always `h()` on output.** Never assume a value was cleaned on the way in.

### 4. Prove it

```bash
python3 tools/check_content_model.py
python3 tools/test_contact_admin.py
```

---

## What the check enforces

`check_content_model.py` compares the three layers **in both directions**:

| It fails when | Because |
|---|---|
| the model has a field the form does not write | an editor cannot set it — the field is unreachable |
| the model has a field the page does not render | it is stored and never shown |
| the form writes a field the model does not define | it is saved and then silently dropped by the defaults |
| the page renders a field the model does not define | it will be empty forever |

The second direction is the one people forget. A field removed from the page but left in the form
produces an editor with a control that does nothing, which looks fine and is discovered by a
confused person months later.

### The exemptions, and why they exist

```python
"page_indirect": {"updated", "footer_synced"},
"form_exempt":   {"updated", "footer_synced", "offices.items.id"},
```

- `updated` and `footer_synced` are the store's own bookkeeping. Nothing renders them and nothing
  should; the admin prints them as status rather than content.
- `offices.items.id` is generated when an office is created, not typed.

Add an exemption only when the field genuinely should not appear in that layer — never to quiet a
failure that is telling you something true.

---

## A known gap

**`check_content_model.py` covers the contact page only.** Its `SUBJECTS` list has one entry.

The careers page has the same three-layer structure — `lib/careers.php`, `admin/sections/careers.php`,
`pages/careers/index.php` — and is **not** checked. A field added to a job post can drift between
those three files without anything failing.

Adding it is a matter of writing a second `SUBJECTS` entry and whatever field-extraction the job
post shape needs. Worth doing before the careers shape changes again; until then, change those three
files with more care than the check would demand.

---

## Sanitising

Rich-text fields go through `rt_sanitise_html()` from `lib/html.php` **on save**, and are printed
with `h()` or emitted as already-sanitised HTML on render.

The sanitiser writes new tags from an allow-list rather than passing anything through, so the output
cannot contain a construct it does not know how to emit.

**There is no `style` attribute**, ever. The CSP blocks inline styles, so an editor that wrote one
would look right in the admin and do nothing on the page. Alignment is a class from a fixed list,
which is why `class` is allow-listed by value and not merely by name.

---

## Writing to disk

Always through `lib/store.php`.

```php
store_write($path, $data);              // atomic, keeps one .bak
store_edit($path, function (array &$d) { … });   // locked read-modify-write
```

`store_write()` writes a temp file and renames it over the target, so a visitor loading the page
mid-save reads either the old file or the new one, never half of one.

Use `store_edit()` for anything that counts — a plain read-then-write has a gap in which two updates
can each read the same value and one can vanish.
