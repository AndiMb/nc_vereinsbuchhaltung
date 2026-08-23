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
