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

## [Unreleased]

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
