# Shared markup templates

**These files are not deployed.** They are the single source of truth for the
markup that has to be byte-identical on every page: the `<head>` block, the site
header, the site footer, and the script tags.

The project rules forbid runtime `fetch()`-based partials — every page must be a
complete, self-contained `.html` file — so this markup is pasted directly into
each page rather than included at runtime.

That creates a real maintenance risk: thirteen copies of the same header, free to
drift apart. `tools/check_shared_markup.py` closes it by asserting that every
page in the site still contains these blocks verbatim. Run it after touching any
page, and as part of the Phase 5 audit.

## Files

| File | Purpose |
|---|---|
| `head.html` | Meta, SEO, Open Graph, favicons, stylesheets, `theme-init.js`. Contains `{{PLACEHOLDERS}}` filled in per page. |
| `header.html` | Skip link, sticky site header, nav drawer, theme toggle. Identical on every page except the `aria-current` marker. |
| `footer.html` | Site footer, including contact details and the back-to-top control. Identical on every page. |
| `scripts.html` | Deferred script tags, in dependency order. Identical on every page. |
| `jsonld-base.html` | Organization + WebSite + ProfessionalService schema, identical on every page. Per-page BreadcrumbList is added separately. |

## Placeholders in `head.html`

| Placeholder | Example |
|---|---|
| `{{TITLE}}` | `Cybersecurity Services \| Tech4TIME` |
| `{{DESCRIPTION}}` | 150–160 characters, unique per page |
| `{{CANONICAL}}` | `https://tech4time.bd/pages/services/cybersecurity/` |
| `{{OG_TITLE}}` | Usually the same as `{{TITLE}}` without the brand suffix |
| `{{OG_TYPE}}` | `website` for all current pages |
| `{{ASSET_PREFIX}}` | `` at the site root, unused elsewhere — all asset paths are root-relative |

## Editing rules

1. Change the template here first.
2. Propagate to every page.
3. Run `python3 tools/check_shared_markup.py` to confirm nothing drifted.

## Nav structure

The header carries the six routes the NextJS source defines. The three pages
ported from the live site (Branding & Advertisement, Resource Certifications,
Privacy Policy) and the four services sub-pages are reachable from the footer and
from the services hub, so no page is orphaned while the header stays legible.
