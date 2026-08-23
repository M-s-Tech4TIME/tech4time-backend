# Content runbook

**Applies to:** backend

Day-to-day content work. Written for whoever maintains the site, not for a developer.

Everything here happens at **https://tech4time.bd/admin/** — no file editing, no deploy.

---

## Signing in

1. Go to `/admin/`
2. Your username and password
3. Six digits from your authenticator app

Sessions last one hour of inactivity, twelve hours at most. Signing out is the button in the top bar.

Trouble getting in: [secrets-recovery.md](secrets-recovery.md).

---

## Job posts

**Admin → Careers**

### Posting a job

1. **Add a job post**
2. Fill in the title, location, type and description
3. Set the application link — usually a Google Form. Job applications do not post to this site at
   all; each role links out to its own form
4. **Save**

It is live immediately. `/pages/careers/` renders straight from what you saved.

### Editing, reordering, removing

Edit any post in place and save. Drag to reorder — the order here is the order on the page. Removing
a post takes it off the site; there is no archive, so if it might come back, keep the text
somewhere.

### Formatting

The description accepts basic formatting: bold, italic, lists, links, and headings.

- **Alignment is a choice from a list**, not free-form styling. The site's security policy blocks
  inline styles, so a pasted style would look right in the editor and do nothing on the page.
- **Pasting from Word or Google Docs** is safe — anything the editor does not recognise is discarded
  rather than carried through.

### The CV link

One link for the whole page, for speculative applications. Admin → Careers, at the top.

---

## Contact details

**Admin → Contact**

Offices, phone numbers, email addresses, the page's headings, and the enquiry form's copy.

### The footer needs a developer

> **Changing a phone number here does not change the footer.**

The contact details repeated at the bottom of every page are part of each page's markup, not
content, so the editor cannot reach them. The admin shows a banner when the two disagree.

Closing the gap needs someone with the repository:

```bash
# 1. the server's copy is the real one — download it first
scp user@tech4time.bd:~/public_html/content/contact.json content/contact.json

# 2. push the details into every page
python3 tools/sync_site_contact.py
python3 tools/check_shared_markup.py

# 3. deploy the pages — and NOT content/
```

So: change it in the admin, then ask for a deploy. The contact page itself is correct immediately;
only the footers lag.

---

## Your account

**Admin → Account**

| | |
|---|---|
| Change your password | ends every other signed-in session |
| Pair a new authenticator | do this when you change phone, **before** you wipe the old one |
| Issue new recovery codes | the old ten stop working |
| Sign out other devices | when you have used a shared machine |
| Recent activity | the last fifteen sign-in events |

Every one of these asks for your password again, even though you are signed in. That is deliberate:
a session left open on a shared machine should not be enough to take the account.

### Changing phone

**Pair the new phone before wiping the old one.** If the old one is already gone, use a recovery code
to sign in, then pair. If both are gone: [rung 3](secrets-recovery.md#3-phone-and-codes-gone).

---

## What cannot be edited here

Two editable pages today: **Careers** and **Contact**. The other fourteen are built into the site and
need a developer and a deploy.

| | |
|---|---|
| Home, About, Services, Company Profile, and the rest | a developer |
| Images anywhere | a developer |
| The footer's contact details | a developer, after you change them here |
| Navigation | a developer |

The admin's Overview page says the same thing, plainly, so nobody has to guess.

Making more pages editable is planned work —
[adding-an-editor.md](../10-development/backend/adding-an-editor.md).

---

## Enquiries

The contact form emails **info@tech4time.bd**. Pressing reply reaches the visitor.

There is no inbox in the admin and no copy stored on the server — the form sends mail and keeps
nothing. If an enquiry is deleted from that mailbox, it is gone.

Five submissions an hour are allowed from one address, which stops a bot flooding the inbox without
inconveniencing a real person.

---

## Habits worth having

**Before a busy period** — check you can still sign in, and that your recovery codes are where you
think they are.

**When posting a job** — check `/pages/careers/` afterwards. The admin shows a *View page* link.

**When changing contact details** — tell whoever deploys, so the footers get updated.

**Once a year** — issue fresh recovery codes and change your password. Both are two minutes on the
Account page.
