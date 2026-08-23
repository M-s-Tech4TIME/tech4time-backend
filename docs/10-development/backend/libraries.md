# The libraries — `lib/`

**Applies to:** both

Eleven PHP files. What each owns, and which one to open.

None of them is reachable over HTTP: `.htaccess` has `RewriteRule ^lib/ - [F,L]`, and the private
store they read from is outside the document root entirely.

---

## At a glance

| File | Owns | Depends on |
|---|---|---|
| [`html.php`](#htmlphp) | escaping, and the rich-text sanitiser | — |
| [`store.php`](#storephp) | reading and writing JSON atomically | — |
| [`careers.php`](#careersphp) | the shape of a job post | `html`, `store` |
| [`contact.php`](#contactphp) | the shape of the contact page | `html`, `store` |
| [`private.php`](#privatephp) | where the secrets are, and key derivation | — |
| [`totp.php`](#totpphp) | RFC 6238 authenticator codes | — |
| [`throttle.php`](#throttlephp) | counting attempts | `private`, `store` |
| [`mailer.php`](#mailerphp) | the one place mail leaves this site | — |
| [`auth.php`](#authphp) | accounts, hashing, sessions, the audit log | `private`, `totp`, `store` |
| [`reset.php`](#resetphp) | the emailed one-time code | `auth`, `mailer`, `throttle` |
| [`admin.php`](#adminphp) | the section registry and page furniture | `auth`, `html` |

Roughly bottom-up: `html` and `store` know nothing about anything; `admin` sits on top of all of it.

---

## Content and rendering

### `html.php`

`h()` · `rt_sanitise_html()` · `rt_safe_href()` · `rt_plain()`

Escaping on output, and the sanitiser that decides what HTML an editor may write.

**Written by hand because there is no DOM extension on this host** — `DOMDocument` does not exist.
So it parses the markup itself, and the way it stays safe without a parser is worth understanding:

> It never passes anything through. It walks the input and, for each tag it recognises, **writes a
> new one** from an allow-list of names and attributes. Anything unrecognised — a tag, an attribute,
> a stray angle bracket — is discarded rather than copied.

So the output cannot contain a construct this file does not explicitly know how to emit. That is a
much smaller thing to get right than trying to spot every dangerous input.

**No `style` attribute, ever.** The CSP is `style-src 'self'`, so an editor that wrote
`style="text-align:center"` would look correct in the admin and do nothing on the public page.
Alignment is a class from a fixed list — which is why `class` is allow-listed *by value*, not merely
by name.

`h()` is what you call on every value you print. Always. Do not assume something was cleaned earlier.

### `store.php`

`store_read()` · `store_write()` · `store_edit()`

Reading and writing a JSON file.

`store_write()` is **atomic**: it writes a temp file in the same directory and renames it over the
target. A rename within a filesystem is atomic, so a visitor loading the page mid-save reads either
the old file or the new one, never half of one. It also keeps one generation of `.bak`.

`store_edit()` is read-modify-write **under a single exclusive lock**. Use it for anything that
counts.

> `store_read()` then `store_write()` is two steps with a gap. That is fine for a person saving a
> form and wrong for a counter: two failures landing together would each read 3, each write 4, and
> one would vanish. That is not a rounding error — it is the attacker's best move.

`store_read()` returns `null` for a missing file **and** for malformed JSON — the right shape for
site copy, where both mean "fall back to defaults" and the page still renders. Callers that must
tell them apart use **`store_state()`**, which answers `ok`, `missing`, `unreadable` or `corrupt`.

`auth_problem()` uses it to refuse rather than present a damaged account file as a fresh install,
and `store_write()` uses it to make sure a damaged file never becomes the `.bak` — the copy that
damage is recovered from. `tools/test_store.py` covers both.

### `careers.php`

`careers_load()` · `careers_save()` · `careers_validate()` · `careers_slug()` · `careers_job_posting()` · …

The shape of a job post, its validation, its slug, and the `JobPosting` structured data the careers
page emits. `careers_sanitise_html()` and `careers_safe_href()` are one-line aliases kept from before
that code moved to `html.php`, so the move changed no caller.

### `contact.php`

`contact_defaults()` · `contact_load()` · `contact_save()` · `contact_validate()` · `contact_page_schema()` · `contact_footer_in_step()` · …

The shape of the contact page: offices, reach methods, the form's copy, and the `ContactPage`
schema.

`contact_defaults()` and `contact_office_defaults()` are **the definition of the shape** —
`check_content_model.py` reads the field list out of those functions rather than out of
`content/contact.json`, because the file is one instance of the shape and an optional field that
happens to be absent from it is still a field.

`contact_footer_in_step()` is what powers the drift banner: it fingerprints the contact details in
the JSON and compares them with what the pages' footers actually say.

---

## The sign-in

Full design: [authentication.md](authentication.md).

### `private.php`

`t4t_private_dir()` · `t4t_private_path()` · `t4t_master_key()` · `t4t_key()` · `t4t_assert_outside_document_root()`

Where the secrets are, and where every key comes from.

`t4t_private_dir()` resolves the store, **refuses if it is inside the document root**, creates it
0700, and caches the result. The containment check runs *before* `mkdir` — a safety check that
leaves a new folder in the web root on its way out is doing the opposite of its job — and again on
the resolved path, because `realpath()` follows symlinks.

`t4t_master_key()` creates `secret.key` with `fopen(…, 'x')`, which fails if the file exists. That
makes "create only if absent" one atomic step, and the creation path is written to **lose** a race
rather than win one: regenerating the key would invalidate every stored password at a stroke.

`t4t_key($purpose)` derives a per-purpose key by HMAC. The key that peppers passwords is not the key
that hashes reset codes, and neither is the key that will sign a publish request — so a weakness in
how one is used cannot be carried into another.

### `totp.php`

`totp_secret()` · `totp_code()` · `totp_verify()` · `totp_uri()` · `totp_format()` · base32 both ways

RFC 6238, about ninety lines: base32, HMAC-SHA1 dynamic truncation, a 30-second step, 6 digits, and
one step of drift either side for a phone clock that is slightly out.

Hand-written for the same reason `html.php` is — there is nothing to install on this host and no
build step to install it with. **It is checked against all six test vectors published in the RFC**,
including the one past 2^32 that catches a 32-bit counter. That is the only reason to trust an
implementation like this one.

### `auth.php`

The largest file here. Accounts, hashing, sessions, the audit log, and the setup token.

```
accounts    auth_accounts  auth_find  auth_put  auth_defaults  auth_has_accounts
passwords   auth_pepper  auth_password_hash/verify/needs_rehash/dummy/problem
recovery    auth_recovery_make/hash/use
sessions    auth_boot  auth_session_user  auth_login  auth_logout
            auth_invalidate_sessions  auth_sweep_sessions  auth_end_session
requests    auth_csrf  auth_check_csrf  auth_fingerprint
            auth_is_https  auth_is_local  auth_is_loopback
the log     auth_log  auth_recent
setup       auth_setup_token  auth_setup_token_check  auth_setup_done
gates       auth_problem  auth_attempt  auth_second_factor
```

> **`auth_second_factor()` takes the account by reference.** It spends a recovery code and advances
> the TOTP counter on the caller's copy. It took it by value once, and `auth_login()` wrote its own
> stale copy over the top one line later — silently restoring the spent code and the old counter.
> Recovery codes worked forever and a captured code could be replayed. If you refactor here, keep
> the reference.

### `throttle.php`

`throttle_ip()` · `throttle_key()` · `throttle_fail()` · `throttle_retry_after()` · `throttle_quota()` · `throttle_clear()`

Counting attempts, so guessing costs something. Five failures are free, then each waits longer than
the last, capped at `THROTTLE_MAX_BLOCK` (one hour).

`throttle_ip()` reads `REMOTE_ADDR` and **never** `X-Forwarded-For`, which a stranger sets.
`throttle_key()` HMACs the identifier, so usernames never land on disk in the counter file.

### `reset.php`

`reset_begin()` · `reset_verify()` · `reset_finish()` · `reset_forget()` · `reset_tries_left()`

The emailed one-time code: ten minutes, five guesses, single use, and bound to the browser that
asked for it. Rationed three times an hour per account, five per address, twenty overall — the last
because cPanel caps outbound mail per hour and somebody hammering the page could use the allowance
up, stopping the genuine reset from being delivered.

### `mailer.php`

`mail_send()` · `mail_problem()` · `mail_header_safe()`

The one place mail leaves this site, so the envelope sender is set in one place. It sends with
`-f no-reply@tech4time.bd` and retries once without it, because some hosts refuse the flag outright.

> The `-f` envelope sender is what SPF and DMARC are checked against. The `From:` header is not.

---

## The admin shell

### `admin.php`

`admin_start_session()` · `admin_require_auth()` · `admin_section()` · `admin_head()` / `admin_foot()` · `admin_shell_head()` / `admin_shell_foot()` · `admin_icons()` · `admin_csrf()`

The section registry, the icon rail, the page furniture, and the gate.

`ADMIN_SECTIONS` is the registry the rail draws itself from — adding an editable page is a row here
plus a file beside the others. `ADMIN_PAGE_SECTIONS` names the subset that edits a page of the
website, so anything counting "the pages you can edit" asks here rather than filtering the registry
by hand in three places.

`admin_shell_*` are the furniture for the pages that have **no** session yet — login, forgot, reset,
setup. They exist because `admin_head()` fatals on a section that is not in the registry, and those
pages are not sections.

[adding-an-editor.md](adding-an-editor.md)

---

## Adding a library

Rare. Most things belong in an existing file.

If you do: a header comment saying **what it owns and why it exists**, `declare(strict_types=1)`,
functions prefixed with the file's concern, no global state beyond a `static` cache, and no output.
Then add it to the table at the top of this page — `check_docs.py` fails until you do.
