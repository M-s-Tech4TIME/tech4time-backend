# Shared markup — the header, footer and script block

**Applies to:** frontend

The one place where a careless edit does damage that is invisible until a check catches it.

---

## The problem this solves

Runtime `fetch()` partials are forbidden — every page must be a complete, self-contained file that a
crawler receives in one request. So the `<head>` block, the site header, the footer, the dock and the
script tags exist as **literal markup in all sixteen pages**.

That is sixteen copies of the same header, free to drift apart.

Three tools close the gap:

| | |
|---|---|
| `tools/templates/` | the single source of truth for those blocks |
| `tools/propagate_shared.py` | pushes a template change out to every page |
| `tools/check_shared_markup.py` | proves no page has drifted |

**`tools/templates/` is never deployed.** It is a build input.

---

## The rule

> **Never edit a header or footer in a page file.** Edit the template, then propagate.

A hand edit to one page's footer will pass every visual check and be silently reverted by the next
propagate — or, worse, survive as the one page that differs.

```bash
# 1. edit the template
$EDITOR tools/templates/footer.html

# 2. see what would change
python3 tools/propagate_shared.py --dry-run

# 3. apply
python3 tools/propagate_shared.py

# 4. prove it
python3 tools/check_shared_markup.py
```

---

## The templates

| File | What it is |
|---|---|
| `head.html` | meta, SEO, Open Graph, favicons, stylesheets, `theme-init.js`. Contains `{{PLACEHOLDERS}}` filled in per page |
| `header.html` | skip link, sticky header, nav drawer, theme toggle |
| `footer.html` | the footer, **including the contact details**, and the back-to-top control |
| `dock.html` | the floating dock |
| `scripts.html` | the deferred script tags, in dependency order |
| `hero-circuit.html` | the animated circuit band on pages with a title band |
| `jsonld-base.html` | Organization + WebSite + ProfessionalService schema |

### Placeholders in `head.html`

Filled in per page by `assemble_page.py` when a page is created.

| Placeholder | Example |
|---|---|
| `{{TITLE}}` | `Cybersecurity Services \| Tech4TIME` |
| `{{DESCRIPTION}}` | 150–160 characters, unique per page |
| `{{CANONICAL}}` | `https://tech4time.bd/pages/services/cybersecurity/` |
| `{{OG_TITLE}}` | usually `{{TITLE}}` without the brand suffix |
| `{{OG_TYPE}}` | `website` for every current page |

---

## The one thing that is not copied

`aria-current="page"`.

That marker is the single legitimate per-page difference in shared markup, and a blind copy would
wipe it from every page and mark the active link nowhere. `propagate_shared.py` reads it out of each
page first — as the set of hrefs that page marks — and re-applies it afterwards. A page with no
marker, like `404.html`, keeps none.

If you add another legitimate per-page difference, it has to be taught to the propagator the same
way. Prefer not to.

---

## The footer's contact details

The footer repeats the company's phone number, email and address. **The admin cannot reach them** —
they are markup, not content, and the editor writes `content/contact.json`.

So after changing contact details in `/admin/?s=contact`:

```bash
python3 tools/sync_site_contact.py     # push them from the JSON into every page
python3 tools/check_shared_markup.py   # confirm
```

Then redeploy the pages. The admin shows a banner when the JSON and the pages have drifted, so the
gap is never invisible — but closing it is a deploy, not a save.

> On the host, the server's `content/contact.json` is the real one. Download it before running the
> sync, or you will push stale details into every page.
> [content-runbook.md](../../30-operations/content-runbook.md)

---

## Navigation

The header carries six routes. Three ported pages (Branding & Advertisement, Resource
Certifications, Privacy Policy) and the six services sub-pages are reachable from the footer and
from the services hub — so no page is orphaned while the header stays legible.

`audit_pages.py` checks that every page is reachable from somewhere.

---

## If `check_shared_markup.py` fails

It names the page and the block. Almost always: somebody edited a page directly.

```bash
python3 tools/propagate_shared.py --dry-run   # see what differs
python3 tools/propagate_shared.py             # put it back in step
```

If the *intended* change was in the page rather than the template, move it into
`tools/templates/` first — otherwise the next propagate discards it.
