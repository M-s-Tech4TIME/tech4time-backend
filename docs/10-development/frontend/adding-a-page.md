# Adding a page

**Applies to:** frontend

From nothing to a page that passes every check. About twenty minutes.

---

## Decide first: static or dynamic?

**Static `.html`** — the right answer for almost everything. Fourteen of the sixteen pages are
static. Content changes by editing the file and redeploying.

**Dynamic `.php`** — only when the page says something that changes on its own schedule, without a
redeploy. Two pages qualify: job posts appear and expire, and contact details change. Making a page
dynamic means building an editor for it, a content model, and a renderer — see
[adding-an-editor.md](../backend/adding-an-editor.md).

The rest of this page covers a static page.

---

## 1. Write the `<main>`

Just the `<main>` element — the head, header, footer and scripts come from the templates.

```html
<main id="main">
  <section class="page-band">
    <div class="container">
      <h1>Managed Detection and Response</h1>
      <p class="lede">…</p>
    </div>
  </section>
  …
</main>
```

Rules the audit will hold you to: exactly one `<h1>`, headings in order with no level skipped, an
`alt` on every image, and an accessible name on every control.

Save it somewhere temporary — `/tmp/mdr-main.html`.

## 2. Write a spec

```json
{
  "out":         "pages/services/managed-detection/index.html",
  "main":        "/tmp/mdr-main.html",
  "title":       "Managed Detection and Response | Tech4TIME",
  "og_title":    "Managed Detection and Response",
  "description": "Round-the-clock threat monitoring and response for Bangladeshi businesses, delivered by Tech4TIME's security operations team.",
  "canonical":   "https://tech4time.bd/pages/services/managed-detection/",
  "og_type":     "website",
  "page_css":    "service-detail",
  "nav_current": "/pages/services/"
}
```

`description` must be 150–160 characters and unique across the site — `audit_pages.py` checks both.
`page_css` is optional and names a file in `assets/css/pages/`. `nav_current` marks the header link
that should show as active.

## 3. Assemble

```bash
python3 tools/assemble_page.py /tmp/mdr-spec.json
```

This composes the page from `tools/templates/` so the shared blocks are byte-identical by
construction, and `check_shared_markup.py` passes on the first try.

> **Once the page exists, edit the file directly.** Re-running `assemble_page.py` discards hand
> edits to `<main>`. It is for creating a page, not maintaining one.

## 4. Icons and reveals

```bash
python3 tools/inject_icons.py           # inline the symbols the page references
python3 tools/apply_reveals.py --write  # mark the scroll-reveal targets
```

## 5. Link it up

A page nothing links to is a page nobody finds, and `audit_pages.py` reports it as orphaned.

- **A services sub-page** → add it to the services hub, and to the footer via
  `tools/templates/footer.html` + `propagate_shared.py`
- **A top-level page** → the header nav in `tools/templates/header.html`, if it belongs there. The
  header carries six routes and stays legible on purpose; the footer is where the rest live.

Then add it to `sitemap.xml`.

## 6. Check

```bash
python3 tools/audit_pages.py            # SEO, a11y, structure, links
python3 tools/check_shared_markup.py    # no drift
python3 tools/inject_icons.py --check
python3 tools/check_contrast.py
python3 tools/check_docs.py             # the repository map lists every page
```

Then look at it:

```bash
python3 tools/serve.py
python3 tools/check_dark_mode.py        # both themes
python3 tools/test_motion.py            # nothing left hidden
```

## 7. Document it

Add the page to the table in
[00-orientation/repository-map.md](../../00-orientation/repository-map.md). `check_docs.py` fails
until you do — deliberately, because an undocumented page is one nobody knows to maintain.

---

## Page-specific CSS

Only when the styles are genuinely used on one page.

```
assets/css/pages/<name>.css      ← create
```

Link it via `page_css` in the spec. It loads **last**, after `animations.css`, so it can override
anything. Shared furniture belongs in `components.css` instead — a second page needing the same card
is the signal to move it.

---

## The checklist

- [ ] One `<h1>`, headings in order
- [ ] `alt` on every image, accessible names on every control
- [ ] Description 150–160 characters and unique
- [ ] Canonical URL correct
- [ ] Linked from the hub, the footer, or the header
- [ ] Added to `sitemap.xml`
- [ ] Icons injected, reveals applied
- [ ] Listed in `repository-map.md`
- [ ] Every check passes
