# Content schemas

**Applies to:** both

The two JSON files the dynamic pages render from, field by field.

**The defaults functions are the definition of the shape**, not these files —
`careers_load()` in `lib/careers.php`, and `contact_defaults()` / `contact_office_defaults()` /
`contact_reach_defaults()` in `lib/contact.php`. A JSON file is one instance of a shape, and an
optional field that happens to be absent from it is still a field.

Changing a shape means changing three things together — the model, the form and the renderer.
[content-model.md](../10-development/backend/content-model.md).

---

## `content/careers.json`

```json
{
  "cv_form_url": "https://forms.gle/…",
  "updated": "2026-08-23T02:10:00+00:00",
  "jobs": [ { … } ]
}
```

| Field | Type | |
|---|---|---|
| `cv_form_url` | string | one link for the whole page, for speculative applications |
| `updated` | string | ISO 8601, written on save. Bookkeeping — nothing renders it |
| `jobs` | array | job posts, **in display order** |

### A job

| Field | Type | |
|---|---|---|
| `id` | string | generated on creation, never typed |
| `title` | string | the role |
| `employment_type` | string | full-time, part-time, contract… |
| `work_arrangement` | string | on-site, hybrid, remote |
| `location` | string | |
| `salary` | string | free text — may be a range or blank |
| `posted` | string | date |
| `closes` | string | date; drives `careers_open_jobs()` |
| `status` | string | `shown` or hidden |
| `apply_url` | string | the role's own application form — applications never post to this site |
| `about` | rich text | the description |
| `responsibilities` | rich text | |
| `requirements` | rich text | |
| `must_have` | rich text | |
| `certifications` | rich text | |
| `offers` | rich text | what the company offers |

Rich-text fields go through `rt_sanitise_html()` on save. `careers_job_posting()` emits `JobPosting`
structured data from these.

---

## `content/contact.json`

```json
{
  "updated": "…",
  "footer_synced": "…",
  "meta":    { "title": "…", "description": "…", "share_title": "…" },
  "hero":    { "title": "…", "subtitle": "…" },
  "form":    { "title": "…", "lead": "…", "subject_hint": "…", "note": "…",
               "service_types": [] },
  "reach":   { "title": "…", "items": [] },
  "offices": { "eyebrow": "…", "title": "…", "lead": "…", "items": [] }
}
```

| Field | |
|---|---|
| `updated` | ISO 8601, written on save. Bookkeeping |
| `footer_synced` | the fingerprint of the contact details as last pushed into the pages' footers. Drives the drift banner — [shared-markup.md](../10-development/frontend/shared-markup.md) |
| `meta` | `<title>`, meta description, Open Graph title |
| `hero` | the page's heading and subheading |
| `form` | the enquiry form's copy, and `service_types` — the subject options offered |
| `reach` | direct contact methods |
| `offices` | the office list |

### A reach item

| Field | |
|---|---|
| `icon` | an icon name from `ADMIN_ICONS` |
| `label` | "Phone", "Email", … |
| `type` | `phone`, `email`, `url` or `text` — decides how `contact_reach_href()` links it |
| `values` | array of strings; several numbers under one label |
| `text` | free text, when `type` is `text` |

### An office

| Field | |
|---|---|
| `id` | generated on creation, never typed |
| `name` | the city or office name |
| `flag` | a country code, rendered by `contact_flag_picture()` |
| `address` | |
| `phones` | array of strings |
| `hours` | opening hours |
| `languages` | array of strings |
| `status` | `shown` or hidden — `contact_shown_offices()` filters on it |
| `schema` | `street`, `locality`, `region`, `postal_code`, `country` — for `PostalAddress` structured data |

`contact_page_schema()` emits `ContactPage` and `PostalAddress` from these.

---

## Rules that apply to both

**Written atomically.** `store_write()` writes a temp file and renames it over the target, keeping
one `.bak`. A visitor loading the page mid-save reads either the old file or the new one.

**Rich text is sanitised on save** by `rt_sanitise_html()`, which writes new tags from an allow-list
rather than passing anything through. There is no `style` attribute — the CSP blocks inline styles,
so alignment is a class from a fixed list.

**Everything is escaped on output** with `h()`, regardless of having been sanitised on the way in.

**Bookkeeping fields** — `updated`, `footer_synced` — are exempt from the content-model check in
both directions. Nothing renders them and the form does not write them.

**Ids are generated, not typed.** `careers_slug()` and `contact_slug()` make them.

---

## On the host, these files are the real data

Written by people through `/admin/`. **Never upload them to a live server** —
[routine-deploys.md](../20-deployment/routine-deploys.md).

The repository's copies are development data, kept deliberately rich because an empty file exercises
no renderer. [environments.md](../20-deployment/environments.md)
