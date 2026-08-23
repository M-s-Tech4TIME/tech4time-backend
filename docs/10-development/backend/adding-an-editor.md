# Making a page editable

**Applies to:** backend

Turning a static page into one the admin can manage. This is the recipe for the thirteen pages that
are still hand-edited HTML.

**Do this only when the page genuinely changes without a redeploy.** Two pages qualify today: job
posts appear and expire, and contact details change. A page whose copy is revised twice a year is
better as HTML — an editor is a form, a model, a renderer and a test suite to maintain forever.

---

## What you are building

Five pieces:

| | |
|---|---|
| a **model** | `lib/<name>.php` — the fields, their defaults, their validation |
| **data** | `content/<name>.json` |
| a **renderer** | `pages/<name>/index.php` — replaces `index.html` |
| a **form** | `admin/sections/<name>.php` |
| a **registry entry** | a row in `ADMIN_SECTIONS` |

The shell needs to know nothing else. The rail draws itself from the registry.

---

## 1. The model — `lib/<name>.php`

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/html.php';

function about_defaults(): array
{
    return [
        'updated' => '',
        'hero'    => ['title' => '', 'lede' => ''],
        'values'  => ['items' => []],
    ];
}

function about_value_defaults(): array
{
    return ['id' => '', 'title' => '', 'body' => '', 'icon' => ''];
}

function about_load(): array   { /* store_read + defaults */ }
function about_save(array $d): bool { /* validate + store_write */ }
function about_validate(array $d): array { /* → field => message */ }
```

`*_defaults()` **is** the shape. Everything else reads it — see
[content-model.md](content-model.md).

## 2. The data — `content/<name>.json`

Seed it with the page's current content, so the first render is identical to what was there before.

## 3. The renderer — `pages/<name>/index.php`

Rename `index.html` to `index.php` and replace the editable copy with values from the model.

```php
<?php
require_once __DIR__ . '/../../lib/about.php';
$data = about_load();
?>
…
<h1><?= h($data['hero']['title']) ?></h1>
```

**Everything through `h()`.** Rich text is emitted already sanitised by `rt_sanitise_html()` on save.

Keep the markup otherwise identical — `check_shared_markup.py` still applies, and the head, header
and footer must stay byte-identical to the templates.

## 4. The form — `admin/sections/<name>.php`

Start by copying `admin/sections/contact.php`. It is the fuller of the two and demonstrates
repeatable rows, reordering, validation display and the save cycle.

The obligations:

```php
<?php
if (!defined('T4T_ADMIN')) { http_response_code(403); exit; }   // required
```

- `admin_check_csrf()` on every POST
- validate through the model, never in the form
- `admin_redirect()` after a successful save, so a refresh does not re-post
- render errors beside the field they belong to

## 5. The registry — `lib/admin.php`

```php
const ADMIN_SECTIONS = [
    …
    'about' => [
        'label' => 'About',
        'icon'  => 'building',
        'desc'  => 'The about page',
        'view'  => '/pages/about/',
    ],
    …
];

const ADMIN_PAGE_SECTIONS = ['careers', 'contact', 'about'];
```

If the icon is not already in `ADMIN_ICONS`, add it there too — the admin inlines that whole list on
every page.

Order in the registry is rail order. `ADMIN_PAGE_SECTIONS` is the subset that edits a page of the
website, and is what anything counting "the pages you can edit" asks.

## 6. Teach the checks

**`tools/check_content_model.py`** — add a `SUBJECTS` entry:

```python
{
    "name":  "about",
    "model": ROOT / "lib" / "about.php",
    "form":  ROOT / "admin" / "sections" / "about.php",
    "page":  ROOT / "pages" / "about" / "index.php",
    "page_indirect": {"updated"},
    "form_exempt":   {"updated", "values.items.id"},
}
```

The check has to be told what to check — deliberately, so a new editor is never silently unverified.
It reads `ADMIN_PAGE_SECTIONS` and fails until the new section appears in `SUBJECTS` or in
`COVERED_ELSEWHERE`, so you cannot get past this step by forgetting it.

**If the form or the page consumes its fields in a loop, take `COVERED_ELSEWHERE` instead.** The
extraction here is regex over source: a `name="<?= h($field) ?>"` gives it `h` and `field`, not the
field names, and exempting the difference would leave the loop-driven fields — the ones most likely
to drift — unchecked while the check reported success. Name the round-trip test instead, and write
the reason beside it. `test_careers_admin.py` is the worked example.

**`tools/test_<name>_admin.py`** — copy `test_contact_admin.py`. It signs in through
`tools/admin_session.py`, so you inherit a real sign-in rather than faking one.

## 7. Document it

- [00-orientation/repository-map.md](../../00-orientation/repository-map.md) — the page is now `.php`
- [40-reference/content-schemas.md](../../40-reference/content-schemas.md) — the new schema
- [10-development/where-to-change-things.md](../where-to-change-things.md) — "change X" now means the admin
- [30-operations/content-runbook.md](../../30-operations/content-runbook.md) — how to use it

`check_docs.py` fails until the section appears in the docs.

---

## The checklist

- [ ] `lib/<name>.php` with `*_defaults()`, `*_load()`, `*_save()`, `*_validate()`
- [ ] `content/<name>.json` seeded with the current content
- [ ] `pages/<name>/index.html` → `index.php`, rendering from the model, everything through `h()`
- [ ] `admin/sections/<name>.php` with the `T4T_ADMIN` guard and CSRF on POST
- [ ] `ADMIN_SECTIONS` and `ADMIN_PAGE_SECTIONS` updated; icon in `ADMIN_ICONS`
- [ ] `check_content_model.py`: a `SUBJECTS` entry, or a `COVERED_ELSEWHERE` one naming the test
- [ ] `test_<name>_admin.py`
- [ ] Docs updated
- [ ] `.gitignore` covers `content/<name>.json.bak`

---

## Two things that will catch you out

**The deploy must not overwrite the new JSON.** The moment a page becomes editable, its
`content/*.json` on the host is live data written by other people. Add it to the exclude list —
[routine-deploys.md](../../20-deployment/routine-deploys.md).

**The footer problem, if the page carries contact details.** Anything repeated in every page's
footer is markup, not content, and the editor cannot reach it. That is what
`tools/sync_site_contact.py` exists for, and it needs a deploy to take effect.
[shared-markup.md](../frontend/shared-markup.md)
