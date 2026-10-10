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

## [0.35.0] – 2026-10-10

**New:**
- **Members as master data of their own:** a person or an organization with a
  record, member number, join and leave dates, no Nextcloud account needed; an
  account is only linked after you confirm it. The payers of the previous
  module become members on update, and a task asks you to check names and
  email addresses (issue #65).
- **Mandates with a lifecycle:** draft, active, suspended, ended – on paper
  (the signature date releases the mandate) or electronically. Change the bank
  details without a new signature, proof stored as a file in Nextcloud, a
  history of every change, and automatic expiry after 36 months without a
  collection, with an advance warning (issues #66, #100).
- **Electronic mandate:** the member grants it via a one-time link in an email,
  and the consent activates the mandate at once. The mandate text is versioned
  (mandatory block plus your own wording) and can be maintained in the settings
  (issues #67, #101).
- **Correct or discard a draft:** a mandate in draft can be corrected or
  discarded with a reason, without activating it first (issue #118).
- **Contribution groups, assignments and claims:** monthly fee with a minimum,
  interval and payment method (direct debit or transfer); partial months count
  in full. Claims are created automatically, plus manual one-off claims
  (issue #68).
- **Contribution year independent of the fiscal year:** it starts in a freely
  chosen month and determines the contribution periods (issues #68, #101).
- **Intake wizard and CSV import:** master data, mandate and contribution in
  three steps, the last two can be skipped. The CSV import creates members
  together with mandate and contribution and shows in a dry run beforehand what
  would be created (issue #69).
- **Collection cycle:** schedule per interval; the daily run creates claims,
  announces the next collection and sends the pre-notification by email
  (14 days ahead by default). A missed deadline blocks nothing, it shows up as
  a task (issue #70).
- **Release and submission:** "Freigeben & Datei erzeugen" freezes amounts and
  bank details and creates the pain.008 file, "Datei ist bei der Bank
  eingereicht" is a step of its own. Until then the run can be discarded or
  postponed; a copy of the file in a Nextcloud folder is optional
  (issues #71, #103).
- **"Collection" tab:** timeline with a preview of the next run, runs, claims
  with dunning status, exceptions, deferral, waiver and cancellation, and the
  bank reconciliation. Auditors see the collection read-only, with the IBAN
  masked (issues #102–#105).
- **Bank reconciliation:** the bank statement import recognizes end-to-end ID,
  mandate reference and return reason (CAMT, MT940, CSV) and suggests, with a
  reason, which items a transaction covers. Nothing is posted before you have
  judged it: collective credits grouped by revenue account, returned debits
  with a fee account, incoming payments as a suggestion (issues #72, #105).
- **Returned debits and dunning:** the return reason is shown in plain
  language, and depending on the reason the mandate is suspended. Payment
  request, payment reminder and dunning letter go out bundled per member
  (interval adjustable, a deferral pauses them), after that a task for the
  board appears; the bank fee can optionally be passed on (issues #72, #73).
- **Dunning emails carry a GiroCode for the first time:** every item is
  attached to the email with its own GiroCode (EPC QR) so it can be paid
  individually with a banking app. Per item the recipient, IBAN, amount and
  payment reference (with the claim number) are also listed in the text; the
  bank reconciliation suggests the claim whose number is named first. The
  emails name the period in month names ("Vollmitglied, November 2026"). If PHP
  lacks the gd extension, the email goes out without a GiroCode and the error is
  logged (issues #73, #120).
- **"My contribution" for members:** members with a linked Nextcloud account
  maintain their contact details, monthly fee (not below the minimum) and
  interval there, and grant, change or revoke their mandate – effective
  immediately, with a preview and a receipt email; returned direct debits
  appear there in plain language, never as a bank code. The area is off by
  default and is switched on under Settings → Fees & SEPA (issues #74–#76,
  #122).
- **Contribution confirmation:** a print-ready, informal confirmation of the
  contributions paid per contribution year, for members under "My
  contribution", for the board in the record. It does not replace a
  donation receipt under § 10b EStG (issue #77).
- **Data protection:** the data overview provides the information required by
  Art. 15 GDPR; the anonymization blacks out name, contact details, bank
  details and free text of a member who has left, ten years after the end of
  the year of the last booking and only on your confirmation (issue #78).
- **Interface and emails in English, informal German for members:** all texts of
  the module are translated, and the source texts use the formal form. Members
  whose Nextcloud account is set to informal German are addressed informally in
  emails and in "My contribution", without an account the formal form applies;
  the mandate text stays German (issue #106).
- **Tasks in the header:** the clipboard (from bookkeeper up) collects what
  needs attention – from a missing mandate to a release that is due; the number
  counts action items only. A task disappears by itself as soon as its cause
  is resolved (issues #99, #117).
- **Settings at a glance:** under Nextcloud settings → Vereinsbuchhaltung →
  Fees & SEPA the whole module can be operated: mandate reference prefix,
  expiry warning, proof folder, contribution year, release lead time, accounts
  for revenue and return fees, passing on fees, dunning interval, XML storage
  and self-service (issue #101).

**Changed:**
- **App Store description:** A section of its own on member management and SEPA
  direct debit, plus two screenshots (timeline, member list).
- **Copy email addresses:** a button above the member list copies the addresses
  of the members shown (without those who have left, each once) for bulk mails;
  in the CSV import the mandate confirmation now sits directly above the import button.
- **Interval with names:** contribution group, assignment and "My contribution"
  say "monthly", "quarterly" or "every 2 months" instead of month numbers; a
  group's allowed intervals are small checkboxes in one row.
- **Manual rendered readably:** the page opened by "Open full manual" in the help now shows
  tables, numbered lists, code spans and a clickable table of contents instead
  of raw Markdown lines. New is "The process at a glance" (13.0) at the start of
  the contributions chapter.
- **Old contributions and SEPA module removed:** the mandates, fees and batch
  collections of the previous flat module are deleted on update and not carried
  over into the new model (issue #107); if you have such data, back it up first.
  The members that arose from it are kept.
- **Claims are read-only in the open-items view:** contribution and fee claims
  can no longer be paid, cancelled, reopened or deleted under Bookings → Open
  items; "Im Einzug bearbeiten" jumps to the "Collection" tab. Free items
  without a member behave as before (issue #121).
- **Deadlines before the collection in one place:** early-warning window,
  pre-notification lead and release lead are found together in the Nextcloud
  settings (Fees & SEPA); an example line converts them to a collection date.
  The schedule in the Collection tab only displays them.
- **Change the fee as an administrator:** amount and interval of an assignment
  can now be changed in the administration (any amount above the lower limit), the
  contribution group can be switched, both with an "effective from" preview and
  right in the ⋯ menu of the member row; before, only the member could change
  them. An assignment that only starts in the future can also be changed or
  withdrawn, and the assignment table shows the status. The member gets the
  change confirmed by email (in the language of their account); the message says
  whether it went out.
- **Fee-free and pauses:** a monthly fee of €0 is possible (group lower limit 0):
  no claims are created, nothing is collected, no mandate is needed; the member
  list shows "fee-free". A "Ruhend" (dormant) group works as a pause or passive
  membership. The CSV import knows amount 0 too: without IBAN, start date and
  frequency; the preview checks the group's lower limit and interval beforehand.
- **Country as a selection:** in the file, the admission dialog and "My
  contribution" the country is chosen from all countries of the world (named in
  your language); new members start in your country. The dialogs for the bank
  details and the mandate use Nextcloud's standard components.
- **Open items with counts:** the filters show their numbers, a new "Overdue"
  filter lists exactly the items the red badge on the tab counts; the table no
  longer clips the due date and the actions.
- **Delete lock for members:** a member can only be deleted as long as nothing
  is attached to them – no mandate (not even a draft or an ended one), no
  assignment, no claim. Otherwise the record names the reason; for data
  protection cases there is anonymization (issues #65, #68).
- **Role hardening:** every endpoint of the contributions module explicitly
  requires a role. Only administrators change the warning window and the
  pre-notification lead time, assignments are readable from bookkeeper up only,
  auditors see an old IBAN masked; a typo in a role name now locks out instead
  of letting everyone in (issue #119).
- **Reset clears the collection too:** "Delete all data" now also removes
  direct-debit runs, collection items, returned direct debits and dunning
  levels that would otherwise point at deleted records; members, mandates,
  contribution groups and assignments stay (issue #123).
- **GiroCode library in the package:** the app now ships `chillerlan/php-qrcode`
  (version 5, PHP 8.1 is enough) in the `vendor/` directory; for the GiroCode
  attachment PHP should have the gd extension (issues #73, #120).

## [0.34.6] – 2026-10-09

**Changed:**
- **Sign and colour on amounts are now a setting:** the tables on the
  overview, under "All entries" and under "To assign" show the amount
  neutrally again by default, as before 0.34.5; sign and colour can be turned
  on in the Nextcloud settings under "Display". The entry cards on mobile stay
  as they were.

## [0.34.5] – 2026-10-09

**New:**
- **Income or expense at a glance:** amounts on the overview, under "All
  entries" and under "To assign" now carry a sign and a colour – income green,
  expenses red, transfers neutral.
- **Account filter by category:** in the journal, "Income", "Expenses" and the
  other categories can be picked directly in the account filter.

**Changed:**
- **Cash report hides spheres without activity:** the sphere overview only
  lists spheres with income or expenses.

**Fixed:**
- **Account filter missed split entries:** every line of an entry counts now,
  not only the first debit and credit line.
- **Group headings in account fields were clickable:** the category headings in
  all account pickers are now locked.
- **"&" in an account name showed up as `&amp;` in the budget view:** translated
  texts with placeholders are no longer encoded twice.

## [0.34.4] – 2026-09-29

**Fixed:**
- **Bank statement import duplicated transactions that were already booked:**
  when a bank export overlapped bookings from the xbuc import, duplicate
  detection missed two cases and added the transaction to "To assign" a second
  time (issue #112). Affected were booking texts with encoded umlauts
  ("M&amp;#252;ller" instead of "Müller") and bank-internal transactions
  without a counterparty, such as the account closing. Both are now recognised
  as existing bookings; transactions imported earlier are not affected.
- **Switching tabs right after opening the app was lost:** clicking another tab
  while the app was still loading jumped back to the tab of the start URL.
- **Amount field reverted to an outdated value:** after quickly focusing,
  typing and leaving, an invalid entry restored the second-to-last instead of
  the last valid amount.

## [0.34.3] – 2026-09-15

**Fixed:**
- **"First steps" forgot entries from other financial years:** switching to a
  financial year without entries made "Record first entry" and "Enter opening
  balance for a cash/bank account" show up as open again, even though entries
  existed in another period (issue #60). The card now counts all periods - it
  describes the state of the association, not that of the selected year.

## [0.34.2] – 2026-09-14

**Fixed:**
- **An account's category could not be cleared:** deleting the text in the
  “Category" field of the account dialog left the previous category in place;
  only a single blank space worked (issue #59). An emptied field now clears
  the category as expected.

## [0.34.1] – 2026-09-13

**Fixed:**
- **Duplicate accounts in the autocomplete:** Frequently used accounts were
  also listed in their category group and showed up twice in a row when
  searching. Now listed once.
- **Search field in the "Pick receipt from folder" popup sometimes didn't
  react:** A fast click could leave focus stuck on the modal backdrop, so
  clicks and typing went nowhere. Fixed – the same fix now applies to the
  account, bank account, and member dialogs.
- **Icon in the "Pick from folder" button was misaligned:** The button was
  too narrow for icon and text together.
- **The getting-started card briefly flashed on load:** It appeared for a
  moment even when everything was already set up.
- **Period selector stayed empty after a load error:** A single failed
  request left it empty with no error and no retry. Now retries up to
  three times, then shows an error.

## [0.34.0] – 2026-09-12

**New:**
- **Watch folder for receipts.** Besides the internal storage and the user
  folder managed by the app there is a third storage type (cog → *Receipts*
  → "Storage type"): a folder in the Files app whose files, including all
  subfolders, can be picked when booking ("Pick from folder", with search
  and preview). The files stay where they are – renaming or reorganising
  them inside the watch folder does not break the link, the app remembers
  the Nextcloud file id. Receipts uploaded or photographed from the app are stored under
  `<folder>/<year>/` and may be reorganised afterwards. The folder has to
  exist in the Files app beforehand and must not overlap with the watched
  folder for bank statements. It is chosen by clicking in the folder tree
  of the user's home ("Choose folder…" next to the path field) – a typo in
  the path is ruled out. The same tree is now available for the watched
  folder for bank statements.
- **The overview reports documents without an entry.** If the watch folder
  holds a file that is not attached to any entry yet, the overview shows
  "x documents not yet assigned to an entry" – an invoice still to be paid,
  for instance. "View" opens the inbox with "Create entry" per file. A second
  tile warns about receipts whose file was deleted or moved out of the watch
  folder; the audit ZIP keeps listing such receipts in `fehlende_dateien.txt`.
- **The app never deletes a file in the watch folder.** "Delete receipt"
  means "Unlink" there – the files also stay when an entry is deleted or
  all data is wiped. The same file may be attached to several entries, e.g.
  a split invoice.
- **When switching on**, receipts the app stored under
  `<folder>/<entry id>/` so far get their file id filled in – the existing
  receipt folder can become the watch folder directly. If a different folder
  is chosen, the old receipts count as missing until they are moved there.

**Changed:**
- The Nextcloud viewer only opens a receipt when the file lives in the home
  of the signed-in user; everyone else gets the app's own view. Until now the
  viewer failed for anyone who did not own the storage folder.
- Opening a receipt no longer creates folders, and receipts from the
  internal storage stay readable after switching the storage type.

## [0.33.0] – 2026-09-10

**New:**
- **The fiscal year no longer has to match the calendar year.** Under the gear
  icon → *Fiscal year* you can now choose when it starts: presets cover the
  calendar year, October–September, the school year August–July and half-year
  periods (semesters), plus a custom rule with any start day and a period length
  of 1 to 12 months (divisors of 12). Clubs with a deviating fiscal year previously had to add up
  their figures outside the app (issue #8).
- **The header's “Year" has become a “Period".** Every period carries a freely
  editable name – suggested as “2026" for a calendar year, “2025/26" for a
  deviating one and “2025/26-1" for a winter semester. Entry numbers, reports,
  the budget, the receipt ZIP and the year-end lock all refer to the period
  rather than to a year number.
- **Periods can be maintained individually.** The boundary between two open
  periods can be moved – the way to a short transitional fiscal year. An empty
  period at either end of the chain can be removed, and the next one created at
  the press of a button.
- **Switching shows what it will do first.** Before a changed rule takes effect,
  a preview lists the resulting periods, how many bookings will change period,
  and which budget figures would be lost – because when two periods merge, an
  account can only keep one of the two figures. Locked periods block the switch:
  what the general meeting approved does not move afterwards.
- **The cash report names the actual key dates.** The asset overview used fixed
  column headers “Balance Jan 1" and “Balance Dec 31"; it now shows the first
  and last day of the selected fiscal year.
- **The monthly chart on the dashboard follows the fiscal year.** It always ran
  from January to December; with October–September it now starts in October,
  and a semester shows six months.

**Changed:**
- The settings section *Year-end closing* is now called *Fiscal year* and holds
  the rule and the list of periods alongside the locking.
- The xbuc import now derives a file's fiscal year from its date range instead
  of the calendar year. A file for a deviating fiscal year could not be assigned
  at all before. Bookings outside the range are dated to the first or last day
  of the period rather than to January 1 / December 31.
- Existing installations are unaffected: the migration creates one period per
  previous calendar year, running Jan 1 to Dec 31 and named after the year.
  Entry numbers and existing locks are left untouched. If you change nothing,
  you notice nothing.

**Fixed:**
- Opening the booking dialog before the account list had loaded left the cash
  account empty – and booking then only reported that it was a required field.
  The preselection is now filled in as soon as the accounts arrive.
- Switching the period right after loading occasionally showed the journal
  and evaluation of the previous period: of several simultaneous requests the
  last to arrive won, not the last one made. A choice made before the periods
  had loaded did not stick either.

## [0.32.0] – 2026-09-07

**New:**
- **Entries can be edited straight from the account statement.** When you spot
  a mistake while going through an account in the *Accounts* tab, you can fix
  it on the spot: the pencil at the end of the row opens the same entry dialog
  as the *Entries* tab – for the description, both accounts, and turning the
  entry into a split entry. Until now you had to note the entry number, switch
  views and search for it there (issue #39).
- **Receipts are visible in the account statement.** If an entry has a receipt
  attached, the row shows the paperclip and a click opens it. That includes
  the auditor role, which cannot change anything otherwise.
- **A tidier row:** *Reassign* and *Delete* now live in the three-dot menu,
  exactly as in the *Entries* tab. Otherwise the row would have wrapped onto a
  second line in narrow windows.

**Fixed:**
- **The account statement refreshes after an entry is edited.** Description,
  contra account and running balance used to sit there outdated until the next
  account switch whenever the entry had been changed elsewhere.

## [0.31.2] – 2026-09-07

**Fixed:**
- **In Firefox the year in date fields is fully visible again.** Since version
  109 Firefox draws a calendar button inside the input, but does not account
  for it in the width Nextcloud gives to every input field – it therefore
  covered the last digits of the year, turning "07/09/2026" into a visible
  "07/09/202" (issue #43). Every date field in the app is now wide enough:
  entry date, next and first due date, mandate date, opening balance, open
  items, SEPA due date and the short report's period.
- **On phones the buttons inside dialogs are thumb-sized again.** The 44px
  minimum for touch targets only applied outside dialogs – Nextcloud attaches
  dialog content to a different place in the page, so the rule never reached
  it. In the entry dialog *Cancel* and *Book* were only 34px tall and the
  *Income*/*Expense* toggles 40px; this now applies in every dialog. The round
  help button next to the spheres is properly round again – it was stretched
  into an oval on phones and on the desktop alike – and grows to full
  touch-target size under a finger.
- **The receipt ZIP export contains all receipts again.** With the receipt
  storage set to *app-internal*, the archive came out empty and listed every
  receipt in `fehlende_dateien.txt` as not found – even though each one opened
  and downloaded perfectly on its own (issue #40). The files were never gone:
  while building the archive the app read them in a way that only exists for
  storage inside the Nextcloud file tree, not for the app-internal one. Only
  the ZIP export was affected, and only with app-internal storage. On top of
  that, `fehlende_dateien.txt` now states the reason for each entry instead of
  reporting every failure as "not found".

## [0.31.1] – 2026-09-06

**Fixed:**
- **The report sub-tabs are fully reachable again on narrow screens.** The bar
  holding *Summary*, *Reporting groups*, *Spheres*, *Reserves*, *Budget* and
  *Log* ran off the screen and could not be swiped – it ended after *Spheres*,
  and the *More exports* button was out of reach as well (issue #38). The bar
  can now be swiped sideways and indicates at its edges that there is more to
  come; jumping straight to one of the later tabs from the help or the bottom
  bar scrolls it into view automatically. The same applies to the sub-tabs
  under *Entries* and *Contributions* and – with the Nextcloud sidebar open or
  with longer labels – on the desktop too.
- **Amount fields now show the amount the way it appears next to them:
  "20.000,00 €" instead of "20000".** In the budget, the *Plan* column was a
  bare number sitting next to the formatted *Actual* and *Difference* columns –
  with four- and five-digit figures it was hard to tell 5,000 from 50,000
  (issue #34). The same applies to every other amount field: entry amount and
  split lines, opening balance, open items, membership fees and the default
  fee. While editing, the field still shows the bare value so that typing does
  not fight a live format; both notations are accepted – "20000", "20.000,00"
  or "20000.5". Anything unreadable restores the previous value instead of
  silently setting it to 0.
- **The treasurer's report and the short report moved into the *More exports*
  menu on phones.** As separate buttons they no longer fitted into the row and
  pushed the menu off screen. The short report's date field is now part of that
  menu; nothing changes on the desktop.

## [0.31.0] – 2026-09-04

**New:**
- **The header now shows the funds across all cash accounts, no longer just
  the first one.** Until now the top right only ever showed the first cash
  account by account number – anyone running a cash box (1000) and a bank
  account (1200) therefore saw the cash box of all things, while the bank
  account stayed invisible (issue #31). The figure is now called *Funds* and
  adds up every cash account; the tooltip breaks it down by account and names
  the transactions not yet assigned. If the club runs only one cash account,
  its name still appears there – nothing changes for those clubs.
- **Individual cash accounts can be left out of that figure.** For cash
  accounts the account dialog offers a new *Counts towards the funds shown in
  the header* flag (default: on). A fixed-term deposit account can thus stay
  out of the day-to-day figure without disappearing from the books: **the
  cash report, the assets overview and the trial balance keep counting every
  cash account.** The flag is display only and is therefore not locked by the
  finalization of a fiscal year either.
- **The cash-account table on the dashboard and in the evaluation has a
  total row** – as soon as the club runs more than one cash account; with a
  single one it would merely repeat the row above it. If at least one account
  is excluded from the funds figure, an *of which funds (header)* row appears
  below it, and the accounts concerned are marked as such in the list – so it
  stays clear how the figure at the top comes about.

**Changed:**
- **Clicking an account that has sub-accounts now expands them.** Previously
  this only worked through the small arrow in front of it, which was easy to
  miss. Collapsing still goes through the arrow – a second click on the row
  deliberately does not collapse it again, otherwise you could not select a
  parent account without closing it.
- **The posting text now grows with its content.** Longer text wraps and is
  shown in full instead of running off the side of the field – when creating
  a posting as well as when editing one. The value itself stays single-line,
  so nothing changes for the journal, exports or the API.
- **Dialogs have a little more room to the edge.** Content and buttons used to
  sit rather close to it.

**Fixed:**
- **The charts ignored dark mode.** Axis labels and grid lines stayed black
  and were barely visible on a dark background – both on the overview and in
  the reports. The cause was a check for a `theme--dark` class that Nextcloud
  never sets, so the condition was always false. The charts now read their
  colours straight from Nextcloud's design variables and therefore follow any
  theme – light, dark, high contrast, and club colours set through the
  theming app. Changing the theme while the app is open redraws them.
- **Success and error messages (toasts) appeared at the bottom left.**
  Nextcloud still shows its own messages at the top right, so one screen ended
  up with two kinds of toast in two different corners. `@nextcloud/dialogs`
  7.5.0 moved them to the bottom left and dropped the position option; the app
  now puts them back where they belong.
- **The active button had a white border.** Visible on the *Income/Expense*
  switch in the posting dialog, on active filter chips, on the underline of
  the active sub-tab and on the side selector when reassigning. Nextcloud
  claims `button.active` for its own pressed state and overrode the app's
  border colour.
- **The focus ring was clipped in the posting dialog.** Tabbing through the
  dialog showed only a sliver of the ring around *Income* and *Expense* –
  the switch cut it off.
- **The posting dialog could be scrolled sideways.** The hidden receipt file
  input claimed its full intrinsic width and pushed the dialog past its edge,
  producing a scrollbar.
- **Accounts with sub-accounts broke out of the account tree.** They were
  bold and noticeably taller than their siblings, and their columns sat a few
  pixels further right. The cause was the expand arrow: on parent accounts it
  is a button, and Nextcloud gives every button a minimum height of 34px plus
  a margin – which grew the row to 46px while leaf rows stayed at 29px. All
  rows are now the same height and weight; the arrow alone marks an account
  as having children. The faint placeholder dot in front of accounts without
  sub-accounts is gone as well – it looked like a bullet point; the
  placeholder still keeps the column aligned.
- **Text hugged the edge in the "What's new" dialog.** It now uses the same
  inner spacing as every other dialog.

## [0.30.0] – 2026-08-31

**New:**
- **Receipts can now be attached while creating a posting on the desktop,
  too.** On the desktop the *New posting* dialog shows the same *Receipts*
  section as the edit dialog: the selected files sit in a list (with a remove
  button) and are uploaded as soon as the posting is saved. Until now this
  only existed in the mobile view (windows up to 640 px) – on the desktop you
  had to save the posting first and then open it again (issue #29).
  File type and size are checked **as soon as you pick a file**: anything the
  server will not take (only PDF, JPG, PNG, GIF, WebP; 20 MB at most) is
  reported by name right away instead of after the posting has been saved. If
  an upload fails anyway – a dropped connection, say – the files concerned stay
  in the dialog and can be sent to the meanwhile created posting via *Upload
  again*, while the other receipts are already attached. While saving and
  uploading, the button is disabled so that a second click cannot turn into a
  second posting.

**Fixed:**
- **The *Attach* button could only be reached with the mouse.** The file field
  behind it was hidden via `hidden` and therefore dropped out of the tab order –
  anyone operating the app from the keyboard could not get to the receipts at
  all. The field is now focusable and shows the focus on the button, on mobile
  as well as on the desktop.

## [0.29.1] – 2026-08-30

**Fixed:**
- **Success and error messages (toasts) rendered as bare text in the
  top-left corner.** Since 0.29.0, confirmations such as "Settings saved."
  sat unstyled across the header instead of appearing as a box at the
  bottom left. The cause was updating `@nextcloud/dialogs` to 7.5.0: the
  library's toasts now carry hashed CSS classes whose styling only its
  bundled stylesheet knows – up to 7.4.1, Nextcloud's server CSS had
  quietly styled the then-global Toastify class names, so the app's missing
  stylesheet import never showed. Both script entry points now load the
  stylesheet themselves; a static test and a sharpened e2e test guard this
  (the previous one only checked that the message text appears, not that
  it is styled).

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
