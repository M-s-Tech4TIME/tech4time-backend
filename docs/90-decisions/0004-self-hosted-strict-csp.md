# 0004 — Self-hosted assets, strict CSP

**Status:** accepted · **Applies to:** both

## Decision

Every asset is served from this domain. The Content-Security-Policy is:

```
default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; form-action 'self'
```

No inline `<style>`, no `style=` attribute, no inline `<script>`, no `onclick`, no CDN.

## Context

CDN links are the usual way to load fonts and icons. They also leak every visitor's IP to a third
party, add a dependency on someone else's uptime, and widen what a page is allowed to execute.

An inline style or script is indistinguishable from an injected one. Forbidding the whole category
means the browser rejects an XSS payload without having to tell the two apart.

## Consequences

**Good.** No third-party requests, so no third-party tracking and no third-party outage. XSS is
substantially defanged: even a successful injection cannot execute.

**Costs.** Fonts, icons and images must be fetched and committed — `fetch_fonts.py`,
`build_icon_sprite.py`, `build_images.py` exist for this. Styling must go in a stylesheet and
behaviour in a script file, always.

**Forbids.** The rich-text sanitiser cannot allow `style` — an editor writing
`style="text-align:center"` would look right in the admin and do nothing on the page. Alignment is
therefore a class from a fixed list, which is why `class` is allow-listed *by value*.
