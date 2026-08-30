# Changelog (English)

[Deutsch](CHANGELOG.md) · **English**

This file tracks changes **from version 0.27.0 onward**. Older versions are
documented in German only – see [CHANGELOG.md](CHANGELOG.md) for the full
history.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
versioning follows [Semantic Versioning](https://semver.org/).

Do **not** use `###` sub-headings within a version section (nor `##`) –
Nextcloud's own "what's new" popup (`apps/updatenotification`) renders
Markdown headings as "[object Object]" since `marked` v18 (wrong renderer
callback signature, a Nextcloud core bug, reproduced 2026-08-23). Use a
**bold lead-in** at the start of a line instead, e.g. `**New:**`.

## [0.29.0] – 2026-08-30

**Changed:**
- **"Cost center" is now "reporting group".** The report, the *Manage
  reporting groups* button, the field in the account dialog, all messages,
  the in-app help and the manual now use this term; in German
  *Auswertungsgruppe*. The trigger was a question from an accountant (issue
  #7): in cost accounting, a cost center is a **second dimension on each
  posting line** – an amount is allocated across cost centers independently of
  the account it is posted to. This app does something else: it groups
  **accounts**, and an account belongs to at most one group. The old name
  therefore promised a capability that does not exist – and strictly speaking
  there is no such thing as a result per cost center anyway, because a cost
  center carries costs, not income.
  **Nothing about the functionality changes**: the same three groupings, the
  same assignments, the same figures. Groups you created, their codes and
  names are kept unchanged; no conversion is needed. To split one amount
  across two groups, you still create two accounts for it (ideally as
  sub-accounts of a shared parent) and split the posting across them with
  *Split…*; manual chapter 5.4 now says so explicitly.
  Internally everything stays as it was – table `vbh_costcenters`, column
  `cost_center_id`, setting `cost_center_mode` and the `/api/costcenters`
  route are unchanged, and there is no migration. Older entries in the audit
  log keep their original wording.

**Fixed:**
- **A deleted collecting account blocked the entire settings page.** If a
  collecting account had been selected under "Contributions & SEPA" and the
  data was then wiped via "Delete all data" (or an import with reset), the
  setting pointed at an account that no longer existed. From then on **every**
  save on the settings page failed with "The selected collecting account was
  not found." – including the club name or the receipt storage, because the
  page always sends the full set of fields. Resetting the data and deleting a
  single account now clear that setting as well, and it is only validated when
  it actually changes, so an account that became invalid later (after removing
  its IBAN, say) no longer paralyses the other sections.

- **A deleted Nextcloud user blocked the entire settings page.** If a Nextcloud
  user was configured under "Receipts" (receipt storage) or "Bank data"
  (watched folder) and that user was later deleted in Nextcloud, **every** save
  on the settings page failed with "The specified Nextcloud user for the … does
  not exist." – including the club name, because the page always sends the full
  set of fields. Deleting a Nextcloud user now clears both settings as well:
  the receipt storage falls back to the app-internal storage and the watched
  folder is switched off. The user is also only validated when it actually
  changes – for the deletions the app never learns about (a foreign user
  backend, a restored database dump, a deletion while the app was disabled).

- **No Nextcloud user could be selected in the settings any more.** This
  affected "Receipts" (receipt storage) and "Bank data" (watched folder):
  both dropdowns only offered "— internal (AppData) —" resp. "— off —", the
  list of Nextcloud users stayed empty. When the settings moved into the
  Nextcloud settings (0.25.0), the page lost its binding to the user list –
  the list was still being loaded, but no longer passed on to those two
  sections. As a result, receipts could no longer be stored in a user's folder
  and the watched folder could not be set up.

- **Illegible labels and wrongly coloured buttons.** The app used Nextcloud's
  light status background tones as text and accent colours: "Expense" in the
  posting dialog was white on pale pink, the remainder shown while splitting
  was barely readable, edge markers and warning stripes faded to pastels. The
  dark theme was also tied to the operating system setting instead of the
  choice in the Nextcloud profile – picking "Dark theme" there while the
  system was light produced light colours on a dark background. And several
  of the app's buttons (the income/expense toggle, the checklist links,
  "Skip", the suggestion chips) were painted blue by Nextcloud's generic
  button rule. An automated contrast sweep across twelve views in light and
  dark theme found nine WCAG AA violations before and none after; Nextcloud's
  high-contrast themes are now picked up correctly as well. Two superfluous
  separator lines in the header area are gone, too.

## [0.28.0] – 2026-08-24

**Fixed:**
- **The interface stayed German even with Nextcloud set to English.** Until
  now the app loaded its translation bundle straight from the app directory as
  a file (`l10n/<language>.json`). Nextcloud's shipped `.htaccess` only serves
  files with certain extensions from there – `.json` is not among them, so the
  request ends up in `index.php` and comes back as a 404. The attempt
  therefore failed silently in **every** normal installation and the app kept
  showing its German source strings (typical nginx configurations behave the
  same way). Translations now come from a dedicated endpoint
  (`/api/l10n/<language>`), which is not affected by that restriction.
  Server-side text – manual, audit guide, error messages – was never affected.

- **The confirmation for posting a SEPA collection showed a red "Delete"
  button.** Two dialogs were affected – "Mark collection as executed" and
  "Undo return" – both left the button label to the dialog's default, which is
  meant for deleting actions. They now read "Post" and "Undo" and are no longer
  red. The default label is translatable as well; until now it read "Löschen"
  even in the English interface.

- **The buttons in the SEPA collection list were cut off.** The action column
  is fixed at 160 pixels – a width meant for three icon buttons. The four text
  buttons ("Show rows", "Download XML", "Mark as collected", "Discard") wrapped
  onto separate lines there, the widest one stuck out of the column, and that
  made the table wider than its frame: the buttons sat half outside on the
  right, and the "Created" column was clipped on the left. From 900 pixels of
  window width they now sit side by side; below that they still stack, but
  inside the column. The same applied to "Undo return" in the expanded
  collection list.

- **"The accounting was changed by another person" – after your own entry.**
  The app compares its state with the server every 20 seconds, but a change
  detected that way only counted as your own for 15 seconds. Since the
  comparison runs less often than that window – and is deferred further while
  an import is running – the app regularly reported your own larger actions as
  someone else's change. The measure is no longer a fixed window but the moment
  your state last provably matched the server: anything you wrote after that
  explains the difference. Genuine changes by other people are still reported.

- **Sphere, reserve and cost center names stayed German.** They came from
  fixed strings in `ReportService` and never went through translation, so the
  English interface read "Ideeller Bereich" instead of "Non-profit purpose".
  This affected the sphere overview, the reserves overview and the built-in
  cost center names.

- **22 missing English translations added.** Among them the default-fee hint
  in the member import, the labels on the SEPA collection cards ("Total",
  "Due", "created"), the "What's new" dialog and the first-run hints in the
  help – all of which showed up in German in the English interface. A sweep
  of all 850 strings in the code against `l10n/en.json` now comes back
  without a gap.

**New:**
- **Member lists with English column headings.** The CSV import now recognises
  English column names alongside the German ones (`Name`, `Email`, `IBAN`,
  `BIC`, `Mandate`, `Amount`, `Frequency`, `Start date` plus common variants)
  as well as English frequency values (`monthly`, `quarterly`, `semiannual`,
  `yearly`, `annually`). The English manual already described these column
  names – now they actually work. Existing German lists keep working
  unchanged.

## [0.27.2] – 2026-08-23

**Fixed:**
- **"What's new" popup from Nextcloud showed "[object Object]" instead of
  text.** The 0.27.1 entry still had a `### Fixed` sub-heading in its
  changelog entry; Nextcloud's popup renderer (`marked` v18) can no longer
  process Markdown headings correctly (see note above) and shows
  "[object Object]" in its place, while the rest of the text renders fine.
  From this version on, changelog entries avoid `###` headings.

## [0.27.1] – 2026-08-23

**Fixed:**
- **Broken "what's new" popup from Nextcloud itself.** After updating to
  0.27.0, Nextcloud's own app-update popup (independent of this app's
  in-app dialog) showed "What's new in {app} 0.27.0" followed by
  "[object Object]" instead of actual text. Cause: since 0.27.0, `info.xml`
  carried the app name in two `<name>` elements (German/English) for a
  localized App Store title – but Nextcloud's update notification reads the
  name at that point without a language code and can't handle multiple
  `<name>` elements (a Nextcloud core bug). `<name>` is single-language
  again; `<summary>`/`<description>` remain bilingual.

## [0.27.0] – 2026-08-22

### New
- **README, manual and audit guide are now also available in English.**
  README.md, CHANGELOG.md (from this version onward) and HANDBUCH.md are now
  also available as `*.en.md`, with a language switcher at the top of each
  file. `info.xml` now carries `<name>`/`<summary>`/`<description>` in both
  languages, so the App Store automatically shows the right one. The manual
  served in-app (`/api/help/handbuch`) and the printable audit guide for
  auditors (`/api/help/pruefleitfaden`) now detect the user's Nextcloud
  language setting and serve English instead of German once it's set to
  English. The chapter deep links from the in-app help (HelpModal) now point
  to language-independent anchors (`section-<chapter>`) instead of slugs
  derived from the (then translated) heading text.
