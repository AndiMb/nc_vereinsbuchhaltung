# Vereinsbuchhaltung Manual

[Deutsch](HANDBUCH.md) · **English**

A practical manual for treasurers – from initial setup to the year-end
closing. It describes app version **0.35.0** and follows the actual annual
cycle rather than the menu structure: what do I need to do, and when, and
what should I watch out for?

---

## Table of contents

1. [What this is about – and a little bookkeeping](#1-what-this-is-about--and-a-little-bookkeeping)
2. [Initial setup (one-time)](#2-initial-setup-one-time)
3. [Getting data into the system](#3-getting-data-into-the-system)
4. [Day-to-day work: posting and assigning](#4-day-to-day-work-posting-and-assigning)
5. [Understanding the reports](#5-understanding-the-reports)
6. [Financial plan (budget)](#6-financial-plan-budget)
7. [Reports, exports and the treasurer's report](#7-reports-exports-and-the-treasurers-report)
8. [Fiscal year and finalization](#8-fiscal-year-and-finalization)
9. [Preparing for and accompanying the annual audit](#9-preparing-for-and-accompanying-the-annual-audit)
10. [Several people working on the books (collaboration)](#10-several-people-working-on-the-books-collaboration)
11. [On the go: the app on your smartphone](#11-on-the-go-the-app-on-your-smartphone)
12. [When something goes wrong – help and safety](#12-when-something-goes-wrong--help-and-safety)
13. [Membership fees and SEPA direct debit](#13-membership-fees-and-sepa-direct-debit)
14. [Appendix: roles, account types, keyboard shortcuts, glossary](#14-appendix-roles-account-types-keyboard-shortcuts-glossary)
15. [Writing invoices (optional)](#15-writing-invoices-optional)

---

## 1. What this is about – and a little bookkeeping

Vereinsbuchhaltung is an app **inside Nextcloud**. It replaces the
spreadsheet for your club's finances and keeps the books according to the
rules of **double-entry bookkeeping** – clean, traceable and audit-ready.

**Why double-entry bookkeeping?** Every posting is recorded on two accounts,
once on the *debit* side (left, "where does the money go?") and once on the
*credit* side (right, "where does it come from?"). Example: *membership fee
of €25 into the checking account*:

| Debit (expense/asset) | Credit (income/liability) | Amount |
|---|---|---|
| 1200 Bank | 4000 Membership fees | €25 |

This keeps the books internally consistent at all times: the sum of all
debit postings equals the sum of all credit postings, and the cash-account
balance ends up matching the bank statement. If you've never consciously
applied this principle before, don't worry: **the app's simple mode takes
the debit/credit thinking off your hands** – you simply say "income,
membership fee, into the checking account," and the app builds the correct
posting.

**What the app is not:** not payroll or fixed-asset accounting, not member
management, not tax software. It is the core of your club's finances –
accounts, postings, receipts, reports – documented in an audit-ready way.

---

## 2. Initial setup (one-time)

The initial setup is done once by an **administrator**. Without that role,
only reading or posting is possible (see appendix 14.1).

### 2.0 The setup wizard and the checklist

On the very first open – as long as not a single account exists yet – a
small **wizard** greets you with three options:

- **"I have data from 'zero Buchhaltung'"** → opens the xbuc import
  directly (chapter 3.1).
- **"I'm starting fresh"** → creates the proven standard chart of accounts
  (chapter 2.2, path B).
- **"Try it out with sample data first"** → creates a complete **sample
  club**: accounts, postings, receipts, plan figures. Everything can be
  clicked through risk-free. As long as the sample data is active, a banner
  *"sample data active"* is shown at the top with a button **"Reset & start
  with real data"** – which clears everything again (chapter 12.1).

The wizard only appears once per device; "Skip" is always possible.
Afterwards, the **setup checklist** on the dashboard takes over: it lists
the steps still open (name the club, chart of accounts, opening balance,
permissions, first posting, assign spheres), checks off what's already done
automatically, and jumps to the right place with a click. Anyone who doesn't
need it can hide it.

> **Tip:** The sample data is the fastest way to get to know the app –
> especially before handing over to a successor in the treasurer role.

### 2.1 Assigning permissions

Gear icon (settings) → **Permissions** section. There you assign a role to
Nextcloud users or groups:

- **Administrator** – can do everything, including permissions, the fiscal
  year, delete-all-data and the settings of the fees module (chapter 13.1).
- **Bookkeeper** – reads and writes postings, receipts, assignments, as
  well as members, SEPA mandates and fee collection (chapters 13.2–13.10).
- **Auditor** – read-only (for the annual audit); in the fees module, the
  collection with the IBAN masked.

Members who are meant to maintain their own details under "My contribution"
(chapter 13.11) need **no** role – what counts for them is the link between
their Nextcloud account and the member record.

> **Note:** Nextcloud administrators are *always* administrators of this
> app, regardless of this list. Usually two administrators and any number of
> bookkeepers are enough; auditors get the "Auditor" role.

### 2.2 Creating the chart of accounts

There are two paths:

**Path A – import from "zero Buchhaltung" (recommended, if available):**
Gear icon → *Data* → *From "zero Buchhaltung" (.xbuc)*. This takes over the
complete account tree including hierarchy and existing postings. See
chapter 3.1 for details.

**Path B – standard chart of accounts or manual:**
**Accounts** tab → *Create standard chart of accounts* button creates a
proven chart (bank, cash, membership fees, donations, insurance …).
Individual accounts can then be created, renamed or moved (parent/child
accounts via the "Parent" field).

Every account has:
- a **number** (free-form, e.g. 1200),
- a **name**,
- a **type** (income, expenses, fixed/current asset, liability, equity),
- optionally the **bank account** flag (for cash accounts – only these
  accumulate across the fiscal-year boundary),
- for cash accounts, optionally the **IBAN**. Anyone running just one bank
  account doesn't need it. With several accounts, it decides which cash
  account an imported transaction is posted on. This also works with
  statements that name only the account number or "bank code/account number"
  instead of the IBAN (MT940, some CSV files) – both are part of the IBAN.
  Once a cash account has an IBAN, a transaction that matches no cash account
  or several is refused with an explanation instead of a guess; the import
  preview points this out beforehand. Without any IBAN, everything ends up on
  the first bank account. Spaces don't matter, the app stores it
  consistently. Removing the bank-account flag again also removes the IBAN,
- for cash accounts, the **"Counts towards the funds shown in the header"**
  flag (default: on). It only governs the single figure above the interface –
  the cash report, the assets overview and the trial balance keep counting
  every cash account. Turning it off makes sense for a fixed-term deposit
  account, say, that is not part of day-to-day business,
- an **opening balance** (starting balance, e.g. the account balance as of
  01/01).

> **Deleting accounts:** Only possible as long as **nothing** has been
> posted on them and they have no sub-accounts. Otherwise the app declines
> the deletion with an explanation – otherwise the posted amounts would
> disappear from the trial balance and the treasurer's report without anyone
> noticing.
>
> **Deactivate accounts instead of deleting:** For exactly this case, the
> account dialog has the **"Account active"** toggle. An account you switch
> off disappears from all selection lists (posting, assigning, rebooking) –
> the posted amounts, all reports and the history stay unchanged. It still
> shows in the account tree, in italics with an *inactive* note, and can be
> switched back on at any time. This way you can get accounts you no longer
> need out of the way, without being allowed to delete them.

### 2.3 Entering opening balances

Anyone not starting fresh at €0 enters the starting balances of the cash
accounts (checking, savings, cash box) as an opening balance – **Accounts**
tab → click an account → *Opening balance*. The app automatically creates
the opening posting against the equity account. This way the balance is
correct from day one.

> **Caution:** The opening balance affects the balance. Anyone later
> importing from an actual accounting file (xbuc) should *not* enter the
> balances a second time – the import brings them along.

### 2.4 Setting up receipt storage (administrator)

Gear icon → *Receipts* → *Storage type*. Three options:

- **internal (AppData):** visible only through the app.
- **Nextcloud folder managed by the app:** the app creates one subfolder
  per posting below the chosen path (e.g.
  "Vereinsbuchhaltung/Belege/<posting id>/"). The receipts are then also
  searchable in Nextcloud.
- **Watch folder (archive in the Files app):** a folder you create and
  maintain yourselves in the Files app – with any subfolders, per year or
  supplier, say. Every receipt file in it (PDF, JPG, PNG, GIF, WebP) can be
  picked when posting, and the overview reports which ones are not yet
  assigned to a posting. The app remembers the Nextcloud file id: renaming
  and moving within the folder do no harm. Receipts you upload or
  photograph in the app are stored under `<folder>/<year>/`. Nothing is
  ever deleted there – "Delete receipt" only removes the link.

The watch folder has to exist beforehand and must not overlap with the
watched folder for bank statements (chapter 3.3). As soon as a user is
chosen, "Choose folder…" opens the folder tree of their home; click the
folder and "Apply" – in watch mode the path cannot be typed by hand. When
switching over, the app
fills in the file id for receipts it stored in the user folder itself so
far – the existing receipt folder can thus become the watch folder
directly. If you choose a different folder, the old receipts count as
missing until you move them there. Receipts in the internal storage stay
where they are and remain readable.

### 2.5 Naming the club (administrator)

Gear icon → *Club* → enter the club name. It appears in the header of the
treasurer's report (chapter 7). A small thing with a big effect at the
general assembly.

### 2.6 Corporate design (optional)

Gear icon → *Club* (second card on the same page): upload a **club logo**
(PNG, JPG or WebP) and choose an **accent color**. Both appear automatically
in the **short report for board meetings** (chapter 7.3) – the treasurer's
report itself deliberately stays plain and neutral. Entirely optional: the
short report works just as well without a logo, just without brand
recognition.

### 2.7 Defining the fiscal year (only if it isn't the calendar year)

Out of the box the app works in the **calendar year** (1 January to
31 December). Anyone keeping it that way can skip this step – there is
nothing to configure.

If your fiscal year runs differently – October to September, the school
year from August to July, or by semester – set that up **once at the
start**: gear icon → *Fiscal year* (administrators only). How it works and
what happens to existing postings is described in **chapter 8.1**.

> **Best done first:** Switching the rule reassigns every existing posting
> to its new period and renumbers the postings. That is intended and
> harmless – but the less has been posted, the less there is to check. As
> soon as one period is finalized, switching is no longer possible at all
> (chapter 8.1).

---

## 3. Getting data into the system

### 3.1 xbuc import from "zero Buchhaltung"

Anyone who has worked with *zero Buchhaltung* so far can take over accounts
and postings completely: gear icon → *Data* → *From "zero Buchhaltung"
(.xbuc)* → choose file.

- **Merge mode (default):** Only missing accounts are created, postings
  already present are recognized via a fingerprint and skipped. This lets
  you import several yearly files **one after another** without creating
  duplicates.
- **Fiscal year:** The app reads the file's date range and looks for the
  period it falls into (chapter 8.1). If none fits, or it should be a
  different one, pick it from the list of your periods. Postings outside
  that period are reported and can be dated to its first or last day – with
  a fiscal year running October to September that means 01/10 or 30/09, not
  01/01 or 31/12.
- **Opening balances** on a multi-year import: recognized and skipped when
  they're already covered by prior-year postings – the app warns on
  deviations.
- **Reset mode** ("delete all data first," administrators only): replaces
  all data completely. **Caution:** irreversible (see 12.1).

> **Important:** The merge import is blocked if an affected period is
> already **closed** (chapter 8). A closed period is finalized and can no
> longer be changed – not even by an import.

**Umlauts and special characters:** zero Buchhaltung sometimes writes them as
codes (`M&#252;ller`, `M#252;ller`, also multiply encoded). The import turns
every one of these forms back into the character. Texts that older versions
of the app stored with such codes were repaired once by the update to 0.41.2;
the changes are listed under *Reports* → *Log* as "Special characters
repaired", with the old and new text. This also applies to closed periods –
only the spelling was changed.

### 3.2 Importing bank statements (transactions)

For ongoing transactions: **Bookings** tab → *Import transactions* → drag
the bank's file here or choose it.

**Which format?** The app recognizes three and determines the format from
the content – it doesn't care about the file extension:

| Format | Usually named like this in online banking | Recommendation |
|---|---|---|
| **CSV-CAMT** | "CSV-CAMT format", "transactions as CSV" | works, but every bank builds the columns differently |
| **CAMT.053** (XML) | "CAMT", "ISO 20022", "XML" | **best choice**, if offered |
| **MT940** | "MT940", "SWIFT", file often ends in `.sta` | also good |

Why CAMT.053 is the best choice: sign, date and payment parties are
explicitly tagged there. With CSV, the app has to guess the columns from
their headers – that works with the common banks, but not with certainty.

> **Several bank accounts?** Then it also matters that the statement names
> its own account and that every cash account has its **IBAN** – that's how
> the app tells which cash account to post on (chapter 2.2). CAMT.053 always
> carries the IBAN, MT940 often only "bank code/account number", and CSV
> depending on the bank; the app matches account number and bank code
> against the IBAN on the cash account. A statement without any account
> details ends up on the first bank account – when in doubt, use CAMT.053.

- **Duplicate check:** postings already imported are automatically
  recognized – also against those previously imported via xbuc, and **also
  across format boundaries**. So you can safely reload the same file, and
  likewise the same statement once as CSV and once as CAMT.
- **Pending transactions** (shown as "PDNG" in CAMT, recognizable by
  "transaction pending" in the *Info* column in CSV) are skipped. They often
  still change before being posted for good – taking them over now would
  mean getting them a second time later.
- **Zero-amount postings** (e.g. ABSCHLUSS) and bank-internal postings are
  handled sensibly (skipped, or left bookable, as appropriate).
- **Batch postings** (a single direct-debit submission with many individual
  items) remain *one* posting – the same way the bank posted it. The
  purpose text carries a note about the number of items.
- **SEPA references (fees module):** if you use the fees module, you get an
  additional evaluation. The app reads end-to-end ID, mandate reference and
  return reason from the statement (most precisely with CAMT.053) and files
  suggestions for collection credits, returned debits and matching incoming
  payments in the *Bank reconciliation* segment (chapter 13.10). The
  transactions themselves stay under *To assign*; nothing is posted before you
  have judged it.
- After the import, a preview shows *new* / *duplicates* / *total*.

### 3.3 Reading in bank statements automatically (watch folder)

Anyone doing the same thing every month can skip the upload: gear icon →
*Bank data* (administrators only). There you enter a Nextcloud user and a
folder in their files, for example `Vereinsbuchhaltung/Kontoauszüge`.
"Choose folder…" next to the path field opens the folder tree of the
user's home; click the folder and "Apply".

From then on, it's enough to drop the statement downloaded from online
banking into this folder – also from a phone or directly from the Nextcloud
app. The app checks hourly and reads in new files. Afterwards the file moves
to `verarbeitet/`; if it couldn't be read, to `fehler/`, with a text file
next to it naming the reason. **Nothing is deleted.**

> **What this is not:** a fetch from the bank. Downloading the statement
> still has to be done by a human – the app does not fetch it itself.

> **Requirement:** The Nextcloud instance must run background jobs via
> **system cron** (Administration → Basic settings). If it says "AJAX"
> there, they only run as long as someone has Nextcloud open – the watch
> folder then behaves unreliably. When in doubt, ask your administrator.

The process is then recorded in the change log (chapter 9.2), recognizable
by the actor "automatic (watch folder)".

The imported transactions land in the **Bookings → To assign** tab and wait
there for their assignment (chapter 4.1).

> **Note:** Importing a bank statement does *not yet* create postings, only
> the raw bank transactions – regardless of which format, and whether by
> hand or via the watch folder. Only the **assignment** to a counter-account
> turns it into a posting. This is intentional: it keeps you in control of
> what's actually posted.

---

## 4. Day-to-day work: posting and assigning

You'll spend most of your time on two activities: **assigning bank
transactions** and recording **manual postings** (cash expenses, transfers
without a CSV, internal rebookings).

### 4.1 Assigning bank transactions

**Bookings → To assign** tab. Every open bank transaction gets assigned a
**counter-account** – via dropdown or (on mobile) a selection sheet. The
posting is created automatically from the assignment:

- *Incoming payment* (membership fee in): debit Bank / credit Membership
  fees.
- *Outgoing payment* (insurance out): debit Insurance / credit Bank.

**Conveniences:**

- **Assignment suggestions:** The app suggests a counter-account as soon as
  there's a matching rule or a previous assignment for this payment
  partner. One click on "✓ apply suggestion" is enough.
- **Auto-assignment rules:** Recurring postings (e.g. "rent, landlord
  Müller → 5100 Rent") can be automated. A rule can be created directly
  from an already-posted transaction via the **lightning-bolt button**, or
  maintained in the *Rules* sub-tab (Bookings tab, administrators/
  bookkeepers only). During import, rules can be applied automatically
  (checkbox "apply auto-assignment rules"); via the watch folder
  (chapter 3.3) this always happens.
- **Search by text or amount:** The search field above the list finds
  payment partner and purpose – and with a number such as `12,50`, `1.250`
  or `18,75 €` also transactions of exactly that amount. A dot separates
  thousands, the comma the cents (German notation). The same search applies
  under *All entries*; there an amount also finds a single part of a split
  posting, and text or entry number match as before.
- **Hand over receipts right away:** With a watch folder set up (chapter
  2.4) every row has a **paperclip**. Through it you queue one or more
  receipts from the folder; they appear as small chips below the
  transaction, an × takes one out again. As soon as you assign the
  transaction – via the dropdown, a suggestion or "Split…" – the queued
  receipts are attached to the new posting. A queued receipt is no longer
  offered for the other transactions.

Anyone who assigned something by mistake can remove it again at any time
("– not assigned –") – as long as the period is still open.

**A transaction that contains more than one thing at once: "Split…"**

Sometimes a single transfer contains more than one thing – someone pays
their annual fee and adds a donation on top, or an invoice belongs half to
two projects. Such a transaction doesn't belong on *one* counter-account.

Click **"Split…"** in the row. A window opens with the transaction at the
top and a list below: one account and one partial amount per row, "+ add
row" for more. Top right always shows how much is still open ("remaining:
€70.00") or "✓ balanced". **Assigning** only works once the split adds up –
this way no amount can get lost. "Apply remainder" writes the open amount
into the last row.

Example: an incoming payment of €250.00 from Ms. Meier → €180.00 to
*Membership fees*, €70.00 to *Donations*. This creates **one** posting with
three lines; in the treasurer's report and the reporting-group report, both
amounts appear separately.

> A split transaction shows "Split across several accounts" in the list
> instead of an account name – there is no longer a single account to show
> there. For suggestions, the app deliberately does **not** remember such an
> assignment: a suggestion "account X" would be wrong for a split
> transaction. Removing and re-assigning works as usual.

### 4.2 Creating manual postings

**"+ New"** button (top right, or on mobile the large "+" button), then **Entry**. Depending on role and on whether the invoice module is on, the menu also offers **Member** (contributions on) and **Invoice**. Recurring invoices are created in the invoice list under "Recurring" or in the invoice editor.

**Simple mode** (default): you only choose *income* or *expense*, a
**category** (what for? e.g. "Insurance") and a **cash account** (bank or
cash box), plus date, amount and description. The app assembles the correct
debit/credit posting.

**Expert mode** ("Expert mode" toggle): for choosing debit and credit
directly – needed for postings that aren't clearly income or expense (e.g.
internal rebookings from checking to savings, provisions).

Every posting can be assigned a **receipt number** (e.g. the invoice
number) – optional, but helpful for the audit.

**Splitting an amount (split posting):** the *Split amount* toggle turns
the single category into a list – the same as when assigning
(chapter 4.1), just for a posting you record yourself. The **total amount**
is shown at the top, the split below with a running remainder display; the
cash account stays a single line for the full amount. In expert mode you
can additionally choose **which side** is split (debit or credit).

> On the very first opening of the posting dialog on desktop, a brief
> **three-step tour** walks through the most important fields (income/
> expense, category, cash account). It only appears once and can be
> skipped.

### 4.3 Attaching receipts

**Receipts** can be attached to every posting (PDF, JPG, PNG, GIF, WebP;
max. 20 MB per file). Five ways:

- **When creating:** in the *New posting* dialog under *Receipts* via
  "attach" – on mobile also via "photograph" straight from the camera. The
  files are uploaded as soon as the posting is saved.
- **Afterwards:** open the posting (pencil icon) → *Receipts* section →
  "attach".
- **From the watch folder** (if set up, chapter 2.4): "Pick from folder"
  lists the files of the folder with a search across file name, subfolder
  and entry number and a preview before assigning. By default only the ones
  not yet assigned ("open only"); the same file may, however, be attached
  to several postings (e.g. a split invoice).
- **While assigning** a bank transaction, via the paperclip in its row
  (chapter 4.1) – no detour through *All entries*.
- **To several postings at once** (with the watch folder, on desktop): in
  *All entries*, select the postings with the boxes at the front – shift-click
  for a whole range, the box in the header for all shown –, then "Assign
  receipt…" in the bar below the list. The chosen file is then attached to
  every selected posting; where it was already attached, it stays a single
  link. Handy together with "no receipt only" and the search, e.g. for
  all debits of an annual contract.
- **Several files** at once are possible.

**Folder tree or list:** The files of the watch folder appear as a **folder
tree**. Each folder shows how many receipts in it are still open and how
many are already assigned (subfolders included); on opening, only folders
with open receipts are expanded. Each file shows its status – *open*, or
*assigned* with the entry number; clicking the number opens the posting.
If you prefer the plain list of all files, untick **"Show folders"**; the
folder then appears in small print below the file name. The browser
remembers the choice.

With a watch folder the **overview** also shows "x documents not yet
assigned to a posting": everything that lies in the folder but is not
attached to any posting yet – an invoice still to be paid, for instance.
"View" opens the inbox, "Create posting" takes the file straight into a new
posting. A second tile warns when the file of a receipt was deleted in the
Files app or moved out of the watch folder; back in the folder or restored
from the trash, it is back.

The **paperclip indicator** in the posting list immediately shows whether,
and how many, receipts are present – missing receipts are thus visible at a
glance (important for chapter 9).

> **Tip:** Get into the habit of attaching receipts *immediately* when
> posting. Gathering them afterwards is the most common time sink before
> the annual audit.

### 4.4 Correcting and deleting postings

As long as the period is **open**, postings can be changed at any time
(pencil icon) or deleted (trash icon). While editing, the app always shows
the current state – if someone else has changed the same posting in the
meantime, a conflict message appears instead of a silent overwrite
(chapter 10).

**Split postings** can also be edited: the dialog opens with the existing
split, amounts can be moved and rows removed or added. Here too, saving
only works once the split adds up. Only postings that have several accounts
on **both** sides are excluded – the app doesn't create those itself, they
could at most come from external data; the app shows them and warns when
editing.

**Comment on a posting.** Below the accounts, the entry dialog has a
**Comment** field – for notes that belong to the posting but not into its
description: "receipt handed in later", "agreed with the board", a question
from the audit. A posting with a comment carries a speech-bubble icon in the
lists (**Entries** tab and account statement); hovering shows the text, on
mobile it is shown right on the card. The comment is **not part of the
posting itself**: it can still be added or changed in a **closed** fiscal
year (the dialog then only offers *Save comment*). Accountants and
administrators may change it, auditors can read it. Every change is recorded
in the **change log** with the old and new text. Comments do not appear in
the exports.

### 4.5 Open items (unpaid receivables)

**Bookings → Open items** tab. A lean list for receivables that haven't
been paid yet – e.g. an outstanding membership fee or an invoice sent.
The debtor (name of the person or entity owing payment) is entered here as
free text; there is no member master data behind it. The claims against
**members** (fees and charges from the contributions module, chapter 13)
appear in this list as well, with the member's name as the debtor; they are
created and managed in the *Contributions* tab (chapters 13.4 and 13.8).

A new item needs: **debtor**, **amount**, optionally a **due date** and an
**account** (for the later posting). If the due date has passed, the app
marks the item as "overdue" – the dashboard then also shows an "overdue
open items" tile with a direct link to the list. The **Open items** tab also
carries a red **badge** with the number of overdue items; hovering over it
spells it out ("3 overdue open items").

**Filter chips with counts** above the list narrow the view: *Open*, *Overdue*
(appears only while there are overdue items; they are a subset of *Open* and
exactly the number on the badge), *Paid*, *Waived* (only when something has been
waived), *Cancelled* and *All*. When the last overdue item is paid, the chip
disappears and the list falls back to *Open* instead of an empty view.

Once the money has arrived, the item is manually **marked as paid**
("Paid" button). The actual incoming payment is imported and assigned as a
bank transaction as usual (chapter 4.1) or posted manually (chapter 4.2) –
the app currently does **not automatically** reconcile open items against
bank transactions. An item can also be **cancelled** (resolved another way,
e.g. a fee waiver) or, if needed, **reopened**.

**You only see claims against members here.** Contribution and fee claims
(chapter 13.8) appear in the same list, carry the tag *Contribution* or *Fee*
and cannot be changed at this spot: instead of *Paid*, *Cancel* and *Reopen*
there is the button **"Edit in Collection"** (for auditors "View in
Collection"). It jumps to *Contributions → Collection → Claims*, narrowed to the
member. Only there do the rules of claims apply: the "paid" mark records your
name and a note, a cancellation needs a reason and works only before
submission, giving up a claim is recorded as a waiver – and a claim is never deleted. The
free items without a member (invoices and the like) you keep editing here, as
described above.

---

## 5. Understanding the reports

All reports relate to the **period chosen in the header** – that is your
fiscal year, so "2026" with a calendar year, or "2025/26" with a
non-calendar fiscal year (chapter 8.1). "All periods" is possible too.
Balance-sheet accounts (bank, cash) show the cumulative account balance,
income/expense accounts only the movement of the selected period.

### 5.1 Overview (dashboard)

KPI tiles: **income**, **expenses**, **result** for the period – each
compared with the **previous period** (with semesters, that is the semester
before, not "year minus one"). Plus a notice about *unassigned* bank
transactions ("assign now" jumps directly there) and a monthly income/
expense chart. It runs across the selected period: with a fiscal year from
October to September it starts in October, with a semester it shows six
bars. The dashboard is the first thing you see after logging in: does
everything look roughly right?

### 5.2 Trial balance

**Reports → Evaluation** tab. Lists all accounts with debit, credit and
balance – hierarchical, optionally including sub-accounts. Here you see at
a glance what happened on each account during the selected period. Also
exportable as CSV.

### 5.3 Account statement

Clicking an account (in the trial balance or the Accounts tab) shows the
**account statement**: every posting with a running balance and the balance
carried forward from the first day of the selected period. Ideal for
reconciling a single bank or cash balance against the bank statement.

**Sorting.** Clicking a column header (No., date, description, contra account,
debit, credit, balance) sorts the table by it; a second click reverses the
order. Each row keeps its running balance from the chronological order, and
the balance carried forward stays on top. Switching to another account
returns to chronological order.

**Exporting the account sheet.** Next to the totals are **CSV** and
**Print**: they produce the account sheet for the selected period – with the
balance carried forward, running balance and a totals row, and with "incl.
sub-accounts" also the postings of the sub-accounts. *Print* opens a
print-ready page in a new tab (print or save as PDF there), e.g. to file for
the audit. Exports are always in chronological order.

The account list on the left shortens long account names; hovering shows the
full name.

**Editing a posting.** If you notice a mistake while reviewing, correct it
right there – without switching to the journal and without noting down the
entry number. The ✎ pencil at the end of the row opens the same entry dialog
as the **Entries** tab: description, date, amount, both accounts, and turning
the entry into a split entry. After saving, the statement and its running
balance refresh immediately.

If a **receipt** is attached to the posting, the 📎 paperclip sits next to it –
a click shows it. That includes the auditor role, which cannot change anything
otherwise.

**Rebooking a wrongly assigned posting.** If only the account assignment needs
to change, this is quicker than the entry dialog: the three-dot menu ⋮ at the
end of the row holds *Rebook* (mobile: *"Wrongly assigned? Rebook to a
different account…"*), which opens the account selection. If several sides are
involved, first choose which one should be rebooked – the account currently
open is preselected, the counter-account is also available to choose. The same
menu holds *Delete*.

This only changes **the account assignment of this one side**: amount,
date, description, receipts and the other side remain unchanged, so debit
and credit can never drift apart. Postings from a closed fiscal year cannot
be rebooked, and every rebooking is recorded in the **change log** with the
source and target account.

### 5.4 Reporting groups

**Reports → Reporting groups** tab. Income, expenses and the result per
**reporting group** (e.g. departments, projects, events) with drill-down down
to individual postings. Names can be adjusted directly here.

> **A reporting group bundles accounts – it is not a second dimension on the
> posting.** Every account belongs to at most one group, so a single amount
> cannot be distributed across several groups. To split €1,000 for jerseys
> between two teams, create two accounts for it (ideally as sub-accounts of a
> shared "Equipment" account), assign each to its group, and split the posting
> across them with **"Split…"** or *Split amount* – see chapter 4.1. In the
> trial balance, *including sub-accounts* rolls the children back up into one
> total. Up to version 0.28.0 a reporting group was called a "cost center";
> that term was dropped deliberately, because in cost accounting a cost center
> is precisely the per-line second dimension that this app does not keep.

How the app groups accounts into reporting groups is decided by the
**reporting-group mode** (the "grouping" selector in the report header,
administrators only; described further below in this chapter):

| Mode | Reporting group is … | Fits when … |
|---|---|---|
| 2nd digit group of the account number | the second digit group, e.g. `111 51 2021` → `51` | the chart of accounts carries the reporting group in the number |
| Each account its own | the account itself | every income/expense account should be evaluated on its own |
| **Freely defined reporting groups** | the reporting group stored on the account | the reporting group doesn't follow from the account number |

The third mode makes no assumption about the chart of accounts: cost
centers are created via the **"Manage reporting groups"** button top right in
the **Reports → Reporting groups** report (code + name) and accounts are
explicitly assigned to them (administrators/bookkeepers only, changing the
mode itself administrators only) – this way even accounts with completely
different numbers can be bundled into one project. Assignment works two
ways:

- individually in the **account dialog** (Accounts tab → edit account →
  *Reporting group*); a new sub-account takes over the reporting group of its
  parent account,
- for many accounts at once in the **"Manage reporting groups"** dialog (check
  boxes, choose the reporting group, *Assign*). At the "– unassigned" tree row,
  the *Assign accounts* button opens the same dialog directly.

Reporting groups that have been created appear in the report even before any
account is assigned to them – so a forgotten assignment stands out. If a
reporting group is deleted, its accounts only lose the assignment; **postings
remain unchanged**, a reporting group itself doesn't carry any amounts.

### 5.5 Cash-account reconciliation

On the dashboard and in the evaluation: **account balance** (from the
journal) vs. **open** (not yet assigned) bank transactions. This lets you
immediately see: "My bank balance is correct, but there are still €X of
unassigned transactions I still need to work through."

If the club runs more than one cash account, the **total** across all of
them stands below the table. If at least one of them is excluded from the
funds figure (see chapter 2.2), an additional *of which funds (header)* row
appears – so you can see how the figure at the top of the page comes about
and which money is not part of it.

**Funds in the header:** At the top right the app shows the combined balance
of **all** cash accounts, not just one. If the club runs exactly one cash
account, its name still appears there. Hovering over the figure shows the
breakdown by account as a tooltip; screen readers read it out directly.

### 5.6 Tax spheres

Nonprofit clubs must separate their income and expenses into up to four tax
spheres. This determines whether taxes are due and whether the club's
nonprofit status itself is at risk. The app helps make this separation
visible – **it does not replace tax advice.**

| Sphere | Examples | Tax treatment |
|---|---|---|
| **Ideational sphere** | membership fees, genuine donations, grants without consideration | not taxable |
| **Asset management** | interest, rental income from club premises, income from investments | generally not taxable |
| **Purpose-related business** | admission to concerts/sports events, course fees | tax-privileged despite being "commercial" |
| **Commercial business** | club restaurant, advertising with consideration, sale of goods | generally taxable above the exemption threshold |

**Assigning:** In the account dialog (Accounts tab) there's the "Tax
sphere" field – for all income/expense accounts (cash accounts and equity
are excluded). For many accounts at once: the **"Assign spheres"** button
top right in the **Reports → "Spheres"** report opens a dialog with
multi-select and name suggestions (administrators/bookkeepers only).

**Evaluating:** Reports tab → "Spheres" shows income/expenses/result per
sphere, including an "unassigned" bucket (there, the *Assign accounts*
button opens the assignment dialog directly). The treasurer's report
contains the same section, the multi-year overview an additional matrix.

> **Commercial-business exemption threshold:** currently €45,000 gross
> income per year (§ 64 (3) AO, German tax code, as of 2020) – as a sum
> across all commercial activities combined. If it's exceeded, the
> **entire** commercial business becomes taxable, not just the amount above
> the threshold. The dashboard shows a traffic light (green/yellow/red) as
> soon as there is income in the commercial business.

### 5.7 Reserves

Nonprofit clubs are allowed (and encouraged) to set aside part of their
funds as a **reserve** instead of spending everything in the same year
(§ 62 AO, German tax code). The app distinguishes three types:

| Reserve type | Purpose |
|---|---|
| **Free reserve** | general reserve, permitted up to a statutory limit |
| **Earmarked reserve** | for a specific, not-yet-implemented project (e.g. "clubhouse renovation reserve") |
| **Replacement reserve** | for the foreseeable replacement of fixed assets (e.g. club minibus) |

**Setting up:** A dedicated **equity account** is created for the reserve
(Accounts tab → type "Equity") and the desired **reserve type** is chosen
in the account dialog.

**Allocating:** There's no dedicated button for this – a reserve allocation
is a perfectly normal posting in **expert mode** (chapter 4.2): debit the
reserve account, credit the account the funds come from (usually the
general equity account).

**Evaluating:** Reports tab → "Reserves" shows the current balance per type
as well as the accounts involved – so it's visible at a glance how much has
already been set aside.

---

## 6. Financial plan (budget)

**Reports → Financial plan** tab. A **planned amount** per period can be
entered for every income and expense account – with a semester rule, that
means per semester. The app shows the **actual value** next to it and the
color-coded **deviation** – so you can see early whether, for example,
insurance is over budget.

**Budgeting on parent and sub-accounts.** The financial plan follows the
account hierarchy (parent account in the chart of accounts). Often the
budget sits on a summary account ("544 01 Membership fees", €50,000)
while entries are posted to its sub-accounts ("544 01 02 Umbrella association"). That is
why:

- **Actual and difference include all sub-accounts.** The summary account
  shows the total of its sub-accounts and compares it with its budget. If
  entries were also posted directly to the summary account, "of which
  directly …" appears below.
- **Each branch is budgeted on exactly one level.** Once an account has a
  budget figure, the budget fields of its parent and sub-accounts are
  locked; they show which account carries the budget instead. A parent
  account without a budget of its own shows the total of the budgets
  below it. A budget figure of 0 does not count as a budget.
- **Expanding:** on opening, every branch is expanded down to the account
  that carries the budget. Use the arrow in front of the account name,
  "Expand all" and "Collapse all" to see more or less detail.
- **Items needing attention:** "over budget" marks expenses above the
  budget, "unbudgeted" marks entries on accounts with no budget on the
  account itself or any parent. A collapsed account with such entries
  below it shows "unbudgeted below". The **Only items needing attention**
  switch hides everything else. Income below budget deliberately does not
  count – during the year it is the normal case; the colour of the
  difference still shows it.
- **Budget conflict:** if budget figures from before this rule sit on
  several levels of one branch, a notice points this out and the totals
  count both. Set one of the figures to 0.
- **Print / PDF:** the budget vs. actual comparison opens as a
  print-ready page for the board – down to the budget level, down to a
  fixed level, or with all sub-accounts.
- **Comparison with the previous year:** above the table, choose another
  period under **Compare with** – the period before is preselected – and
  whether to compare with its **actual** or its **plan**. Three columns
  then show the comparison value, the change Δ (plan minus comparison
  value, so an increase is positive) and Δ in percent; the total tiles
  mention the comparison too. Green means more income or less expense.
  Below a budget item only the comparison value is shown; the comparison
  happens on the budget item. The print view and the CSV export use the
  selected comparison (printed in landscape; in the CSV the three columns
  come last). Inactive accounts stay visible with their comparison value,
  are marked "inactive" and no longer get a budget field.

- **Note per plan figure:** record the rationale, e.g. "40 members ×
  €25". Makes the plan traceable and defensible at the general assembly.
- **Plan snapshots:** freeze the entire plan as a named, dated snapshot –
  typically "resolved at the general assembly." The frozen snapshot can
  later be compared against the current plan, for example if the plan was
  adjusted during the year.

---

## 7. Reports, exports and the treasurer's report

### 7.1 CSV exports

The **Bookings** and **Reports** tabs each have download buttons
(down-arrow icon):

- **Journal** (all postings of the selected period)
- **Trial balance**
- **Income/expense overview**
- **Plan/actual comparison** (financial plan, including notes; with a
  "Level" column for outlining in Excel, budget/actual per account and
  summed over the sub-accounts, and the items needing attention as text)
- **Multi-year overview** (matrix: income statement + assets + cost
  centers + tax spheres across all periods)

The CSV files are suitable for handing over to your tax advisor or the
audit, or for your own analysis in Excel. Format: semicolon-separated,
UTF-8 with BOM (Excel-compatible), German number format.

> **The file names carry the period's label**, no longer a year number:
> `journal_2025-26.csv` instead of `journal_2025.csv` (the slash in
> "2025/26" becomes a hyphen, because it has no place in a file name). The
> same goes for the receipt ZIP (chapter 9.2).

> **Split postings in the journal export:** A posting whose amount is
> spread across several counter-accounts occupies several rows there – each
> with the same posting number and its partial amount. This is the usual
> way a journal shows split postings; the sum of the rows equals the
> posting amount.

> **Multi-year trend as a chart:** In Reports → Evaluation, a line chart
> shows income, expenses and result across all periods – at a glance
> instead of as a table. Handy for presenting to the board or the general
> assembly.

### 7.2 Treasurer's report (print-ready)

**Reports → Evaluation** tab → **"Treasurer's report"** button (only with a
period selected). Opens a dedicated, print-optimized page with:

- club name, the fiscal year's label (e.g. "2025/26") and creation date
- **asset overview** of the cash accounts (balance on the first and the
  last day of the fiscal year, and the change). The columns name the actual
  reference dates: with a fiscal year from October to September that is
  *balance 01/10/2025* and *balance 30/09/2026*, not 01/01 and 31/12.
- **income/expense statement** by account with totals and the result
- **plan/actual comparison**, if plan figures exist – down to the budget
  level: an account with a budget shows plan and actual including all its
  sub-accounts; items needing attention are marked as in the financial-plan
  print
- **completeness notice** (posting count, number range, gap/duplicate
  check)
- **closing note** ("closed on … by …" or "not yet closed")
- signature lines for the treasurer and the auditor

Print or "save as PDF" via the browser (**Ctrl+P** or **⌘+P** on Mac). This
report is the document for the general assembly.

### 7.3 Short report for board meetings (print-ready)

**Reports → Evaluation** tab → **"Short report"** button. Unlike the
treasurer's report (chapter 7.2, always a whole fiscal year), the short
report relates to a freely selectable period **"since …"** – typically
since the last board meeting. The app remembers the last chosen date
device-locally as a suggestion for next time.

Content: cash-account balances as of the reference date and today,
movements since the reference date (income/expenses/result), as well as a
short financial-plan summary for the current period (plan vs. actual so
far).
If a logo and an accent color are set under gear icon → *Club*
(chapter 2.6), both appear automatically in the header of the report. As
with the treasurer's report: print or "save as PDF" via the browser.

### 7.4 Structured report by template (print-ready)

Tab **Reports → Structured report**. The same budget and postings as in the
financial plan, but structured by a **template** – for instance the way a
funding body wants to see the budget or the proof of use, or the way the
board wants to see what the projects cost the club.

The app creates **two standard templates** the first time the tab is opened:

- **Budget by title groups (funding body):** income first, then expenses;
  within each, the non-profit area following the account hierarchy and then
  the projects per analysis group with "Total of the title group". At the
  end a summary by main groups (top account level). Columns: previous year's
  budget and the budget of the selected period; budget notes appear as an
  explanation below the account.
- **Result by area and project:** the non-profit area with income, expenses
  and result, then income, expenses and difference per project and finally
  the balance – so you can see at a glance how much the club contributes to
  its projects from its own funds. Columns: budget and actual of the previous
  year and of the selected period.

Both separate the non-profit area from the projects by the **sphere** of the
accounts (chapter 5.6); projects are the analysis groups of the accounts with
the sphere purpose-related operations (chapter 5.4). Without spheres,
everything appears under "No sphere" – the report points this out.

**Adapting templates.** The standard templates are ordinary templates: they
can be **edited**, **copied** and **deleted** (role bookkeeper or
administrator). Deleted templates do not come back by themselves; **More
actions → Add standard templates** creates both again in their current
version – existing templates stay untouched. **New template** starts from
scratch: the skeleton shows all accounts with budget, actual and balance and
only needs a name. If there is no template, the tab offers both ways directly.

The dialog for creating and editing has four areas:

- **General:** name, budget notes as explanation, grand total at the end.
- **Structure:** sections narrow the accounts down by account type, sphere
  or group and can contain further sections. Grouping levels split the
  accounts of a section further – by account type, sphere, group or
  individually by account (as a tree, down to the planning level, to a fixed
  level or completely). ↑/↓ move sections and levels; a preview on the right
  shows the skeleton of the report.
- **Columns:** up to eight columns of budget, actual or "actual − budget",
  each for the selected period or up to three periods earlier.
- **Groups:** rename analysis groups, merge several into one group or assign
  individual accounts including their sub-accounts to another group –
  without changing the accounts or analysis groups themselves.

Whether a total line shows a **total** or a **result** (income − expenses)
follows automatically: if only income or only expenses are allowed at its
position, it is a total, otherwise a result.

**Nothing gets lost:** if no section covers an account with amounts, it
appears at the end under "Accounts not covered" and a notice names it. An
account counted in several sections is marked. The grand total always
covers all income and expenses and matches the financial plan.

**Print / PDF** opens the report as a print-ready page (landscape from five
columns on), **CSV** delivers the same lines with a "level" column for
outlining in a spreadsheet.

---

## 8. Fiscal year and finalization

A core piece of clean club accounting: a **closed** fiscal year is
**finalized** – its postings, receipts and assignments can no longer be
changed or deleted afterwards. This keeps what the general assembly has
discharged immutable.

But first it has to be clear *what* the fiscal year even is. The app no
longer necessarily works in the calendar year: a fiscal year is a named
**period** with a from and a to date. It may deviate from the calendar year
and be shorter than twelve months.

### 8.1 Defining the fiscal year

Gear icon → *Fiscal year* (administrators only). The page has two cards: at
the top the **rule** by which periods come about, below it the **periods**
themselves.

**Card 1: the rule.** There are four templates plus one of your own:

| Template | Period | typical for |
|---|---|---|
| **Calendar year** (default) | 1 January – 31 December | most clubs |
| **October – September** | 1 October – 30 September | sports clubs whose season starts in autumn |
| **August – July (school year)** | 1 August – 31 July | kindergartens, school support associations |
| **Semester** | 1 October and 1 April, six months each | student clubs, university groups |
| **Custom rule** | start day, start month and length, freely | everything else |

For a custom rule you give the **start day** (1–31), the **start month**
and the **length**. Only lengths that divide 12 can be chosen: 1, 2, 3, 4,
6 or 12 months. The reason is simple: with any other length – five months,
say – the fiscal year would drift against the calendar year after year and
after a few periods would start in a completely different month than at the
beginning.

A start day that doesn't exist in the target month is clamped to the last
day of that month: start day 31 yields 28 or 29 February. The app still
computes with the original start day, though – March starts on the 31st
again, not on the 28th.

**See beforehand what will happen.** The **"Preview"** button shows, before
anything is saved:

- the future periods with label, from and to,
- how many postings will change period,
- how many **plan figures will be lost**. That happens when two existing
  periods merge into one new one: an account can only hold one planned
  amount there, the other is discarded and cannot be restored.

Only in this dialog is there an **"Apply"** button. Switching then assigns
every posting to its new period and renumbers the postings per period (by
date, gap-free from 1). The action is recorded in the change log.

> **Locked as soon as anything is finalized:** If even a single period is
> closed, the app refuses to switch and names the periods concerned. They
> would have to be reopened first (chapter 8.4). This is deliberate: a
> finalized fiscal year should not get different boundaries after the fact.

**Card 2: the periods.** A list with **label**, **from–to**, **status** and
the actions.

- The app proposes the **label** – "2026" for a calendar year, "2025/26"
  for a non-calendar fiscal year, "2025/26-1" and "2025/26-2" for
  semesters. Click it to edit; "Season 25/26" or "Winter semester 2025/26"
  works just as well. It only has to stay unique, because it shows up all
  over the app and in the file names.
- The **boundary between two open periods** can be moved via the date field
  on the to date; the following period then starts the next day. This is
  the way to a **short fiscal year** when switching over: if you move to an
  October–September fiscal year as of 1 October 2026, you let the year 2026
  end on 30 September – what remains is a nine-month stub that is accounted
  for as its own, short fiscal year.
- **"Create next period"** (button above the list) appends one more period
  at the end according to the current rule. This is rarely needed – the app
  creates a period by itself as soon as a posting falls into it.
- **"Remove"** is offered only for an empty period at the beginning or the
  end of the chain (no postings and no plan figures). In the middle it
  would leave a hole that no posting could belong to.
- **"Close"** and **"Reopen"** as before – see the following sections.

> **Existing books don't change with the update.** For every calendar year
> so far, a period 01/01–31/12 is created with the year number as its
> label. Posting numbers and years already closed stay untouched. Anyone
> working in the calendar year notices nothing of the rebuild except the
> new word "period" in the header.

**What follows the period** – and what doesn't. Following the selected
period are: the selector in the header, the posting numbers, all reports
and CSV exports including their file names, the financial plan and the plan
snapshots, the receipt ZIP, finalization, the balance carried forward in
the account statement, the monthly chart on the overview and the comparison
of the key figures with the previous period.

Three things are deliberately *not* switched over:

- **Membership fees and SEPA** (chapter 13) follow their own
  **contribution year** (Nextcloud settings → Vereinsbuchhaltung →
  Fees & SEPA) and the schedule, not the start of the fiscal year:
  when a period falls due depends on the collection day of the interval and
  on the start of the assignment.
- The **monthly grouping in the posting journal** stays calendar-based: the
  group "October 2025" is called October 2025, wherever in the fiscal year
  it happens to sit.
- The **reserves report** (chapter 5.7) is still cumulative and has no
  period filter – a reserve is a stock, not an annual result.

### 8.2 Closing a period

Gear icon → *Fiscal year* → *Periods* card (administrators only). Confirm
"Close" as needed; the dialog names the label and the from–to dates so the
wrong period doesn't get caught. The period is then marked with a 🔒 in the
selector in the header.

### 8.3 What's locked – and what isn't

After closing, the following are **no longer possible** for the period in
question: creating/changing/deleting postings, assigning bank transactions
or removing assignments, attaching or deleting receipts, changing opening
balances, the xbuc import (merge). The app shows closed postings read-only;
write attempts are rejected with a clear message.

**Still possible:** all reading, all reports, exports and the treasurer's
report. Importing *raw* bank transactions also still works – only the
assignment would be blocked.

Also locked are the **account properties that feed into the figures**:
account type, bank-account flag, sphere, reserve type and reporting group. This
only affects accounts that actually have postings in the closed year. The
reason: turning an income account into an expense account flips the sign in
every report – the treasurer's report of the closed year would look
different afterwards, without anyone having touched a posting. **Freely
changeable remain** the number, name, category, parent account and the
active toggle; they only change the label and sorting. Anyone who still
needs to change a locked property reopens the year (chapter 8.4) and closes
it again afterwards.

> **For the watch folder, this means:** if someone drops a statement that
> falls into a closed year, the transactions are read in but stay
> unassigned – even where a rule would apply. The statement still ends up
> in `verarbeitet/`; the number of unassigned transactions is noted in the
> change log (chapter 9.2) at the "watch-folder import" entry.

### 8.4 Reopening a year (exceptional case)

Administrators only, only in exceptional cases (e.g. a correction before
the audit). The action is recorded in the **change log**. Normally, a year
is closed for good.

### 8.5 When to close?

Typical order:

1. All bank transactions for the year imported and assigned.
2. Receipts complete (check chapter 9.1).
3. Audit carried out.
4. **Only then** close the year – usually shortly after the general
   assembly at which discharge was granted.

> **Recommendation:** Close the *second-to-last* year as soon as the audit
> is done, and leave the current year and the one right before it open
> until the general assembly has granted discharge.

---

## 9. Preparing for and accompanying the annual audit

The app supports the annual audit specifically. Auditors get the
**Auditor** role (read-only) and can view everything without accidentally
changing anything. On first login with this role, a short welcome notice
appears naming the three most important places to look.

### 9.0 The audit guide to hand out

Reports → Evaluation → **"Audit guide"** button. This is a print-ready
**one-page quick guide for auditors** – with the club name in the header,
an explanation of the auditor role, the recommended audit steps and where
to find what. Printing it or handing it over as a PDF (Ctrl+P or ⌘P) saves
the auditor from having to read through this entire manual.

### 9.1 Before the audit: establishing completeness

- **"Only without receipt" filter** in the Bookings tab (journal): shows
  all postings without an attached receipt. Clearing these beforehand saves
  follow-up questions during the audit.
- **Gap check:** a warning automatically appears above the journal if
  posting numbers are missing or duplicated. The treasurer's report shows
  the same as a completeness line. In an open fiscal year, the app itself
  keeps the numbering gap-free (deleting a posting shifts the following
  numbers down); a notice here therefore means something was changed
  outside the normal data flow.
- **Open bank transactions:** dashboard → "unassigned" – should be at 0
  before the audit.

### 9.2 During the audit: everything at hand

- Print the **treasurer's report** (chapter 7.2) – the basis of the audit.
- **Receipt ZIP** ("Receipt ZIP" button in Reports → Evaluation):
  downloads all receipts for the year as a ZIP, one folder per posting
  (`NNNN_date_description/`). This lets receipts be browsed in order
  without the app. Missing files are noted in a `fehlende_dateien.txt`
  instead of aborting the export.
- **Account statements** for the cash accounts, to reconcile against the
  bank statements.
- **Change log** (Reports tab → **Log**): who changed what, and when –
  postings, assignments, receipts, permissions, year-end closings. Visible
  to everyone with read access. The log deliberately survives even "delete
  all data" – it is the tamper-proof chronicle.

### 9.3 After the audit

Go through the log together with the auditors if needed. If there are
objections: leave the year open, correct it, then close it (chapter 8). On
discharge: close the year.

---

## 10. Several people working on the books (collaboration)

Several people can work on the same books **at the same time** – all
authorized users see the same dataset. Typical scenario: the treasurer
posts while a deputy assigns transactions in parallel.

**How the synchronization works:**

- The app checks every 20 seconds (and whenever the browser window becomes
  active) whether anything has changed. If so, your own view is updated
  automatically – with a notice if a *different* person made the change.
- Your own changes update the view silently, without a notice.
- **Optimistic locking:** if two people edit the *same* posting at the same
  time, the first save wins. The second gets a conflict message ("changed
  by someone else in the meantime") and can reopen the posting – **no**
  change is ever lost, nothing is silently overwritten.

> **Practical tip:** For big actions (a full yearly import, delete-all-data)
> it's better to coordinate briefly with the others – the app does
> synchronize, but intermediate states can be confusing.

---

## 11. On the go: the app on your smartphone

On mobile devices (up to 640 px wide) the app automatically switches to a
**touch-optimized view**:

- **Bottom navigation bar** with the main tabs and a central **"+"
  button** for new postings.
- **Cards instead of tables:** the journal (grouped by month), bank
  transactions, trial balance, reporting groups, account statement, as well as
  – where used – the member list and fee collection (the "Contributions"
  tab, chapter 13), appear as cards instead of a wide table. Handy for a
  quick check during choir practice or a board meeting whether a fee was
  collected. Accounts and reporting groups have a list/detail view with a
  "‹ Back" bar.
- **Selection sheet for accounts/categories:** instead of a dropdown, a
  searchable sheet opens from the bottom. It remembers the **"recently
  used"** accounts (max. 5, device-local) and suggests assignments. Swiping
  down closes it.
- **Quick entry:** a large amount field, native date picker, and receipts
  photographed directly with the **camera** – ideal for a receipt at the
  gas station or the supermarket.

The desktop view is unaffected by this; the data is the same either way.
Anything not primarily needed on mobile (maintaining the chart of accounts,
financial plan, permissions, import) is deliberately only reachable on
desktop – that's where it belongs.

---

## 12. When something goes wrong – help and safety

### 12.0 Help right inside the app

There's a **help button (?)** at the top of the header. It opens a small
help window with a quick summary of the currently open tab (initial setup,
posting & assigning, accounts, reports, contributions & SEPA, spheres); help
icons in the individual views also lead there directly. From every chapter,
a link leads **directly into this manual** – the app itself serves it as a
readable page, so nothing needs to be looked up on GitHub.

### 12.1 "Delete all data" / reset

Gear icon → *Data* → *Delete all data* (administrators only, with a
confirmation dialog) removes accounts, postings, imports, receipts, open items
(including the claims against members), **all invoices including their PDF and e-invoice** (invoices must be kept for eight years: back them up first with the receipts ZIP under Reports → Evaluation), the templates of the
structured report (afterwards the two standard templates are available
again) and the year-end closing markers.
**The change log is kept.** Members, mandates, contribution groups and
assignments (chapter 13) are not part of the posting records and stay untouched
(the collection is cleared with it, see below). The same applies to
reset mode during the xbuc import. Both are irreversible – so only after
checking with others, and never by accident.

**Fees and SEPA (chapter 13):** The reset takes the collection with it – the
**direct-debit runs** with their items (including the IBANs in plain text), the
**returned direct debits** and the **dunning status** of the claims. They hang
on the claims that disappear with the open items and would otherwise be left
without a reference; everything is deleted together or not at all. **Members,
mandates with their history, contribution groups, assignments, the legal text
and the settings stay.** On the mandates only references to deleted data go:
a **suspension after a returned direct debit stays** (as always, you lift it by
hand, 13.7) but loses its reference to the deleted return, and an **account
change** that a collection had already reported counts as still to be reported
again and goes along with the next collection once more. The date of the **last
presentation** stays, so the 36-month period keeps running from the last
collection that actually took place.

Two consequences to keep in mind: the **assignments keep running**, so the daily
run (13.4) creates their claims again – from the period in which the respective
assignment starts, including periods that had already been billed. If you don't
want that, end the assignments at least a day before the reset (an assignment
ended today still counts as running until the evening) and recreate them
afterwards with today's start date. The **XML copies** of the runs in the
storage folder (13.5) stay in the Nextcloud folder: the app doesn't know which
files belong to them and deletes nothing there – you can remove them by hand.

The same button is the harmless way out of the **sample data**
(chapter 2.0): as long as the "sample data active" banner is showing,
there's nothing to lose.

### 12.2 Wrong posting – what to do?

As long as the year is open: open the posting (pencil) and correct it, or
delete it and create a new one. On conflicts with another person: reopen
and save again. A closed year can only be corrected after reopening
(administrators, chapter 8.4).

### 12.3 Database backup before updates

Before every update that includes a database migration, a database backup
(mysqldump) should be made – the app deployment only restores the program
code, not the database schema. When in doubt, ask your administrator.

A complete backup includes Nextcloud's `config.php` as well as the database.
Members' IBANs are stored encrypted in the database, and the key depends on the
`secret` in `config.php` (13.13). A database restored without the matching
`config.php` loads fine, but the bank details can no longer be read. So back
up `config.php`, but keep it **separate** from the database dump: if both are
stored together, the encryption offers no protection when the backup goes
missing.

---

## 13. Membership fees and SEPA direct debit

An **optional add-on module**. Anyone who receives fees by bank transfer or
doesn't collect any at all can skip this chapter – without a member set up,
the app behaves exactly as before.

Members, mandates, fees and collection (13.2–13.10) may be maintained by
**administrators and bookkeepers** – a mandate does link a person to their
bank details, but that's no bigger a responsibility than any other posting.
**Auditors** see the collection (runs, claims, bank reconciliation) read-only,
with the IBAN masked. All **settings** of the module (13.1: creditor ID,
collecting account, lead times, accounts and intervals, mandate text, the
toggles for the tab and for "My contribution") remain reserved for
administrators – those are one-time decisions for the whole club, not ongoing
work. The **members themselves** need no role: their "My contribution" area is
described in 13.11, the contribution confirmation in 13.12, the data-protection
tools in 13.13.

### 13.0 The process at a glance

Anyone introducing the module goes through these steps in order; each points to
the section that describes it in detail.

| Step | What happens | Where | Section |
|---|---|---|---|
| **1. Set up** | creditor ID, collecting account, lead times before collection, legal text of the mandate | *Nextcloud settings → Vereinsbuchhaltung → Contributions & SEPA* | 13.1 |
| **2. Create contribution groups** | the rules per kind of member: lower limit, default fee, allowed intervals | *Contributions → Contribution groups* tab | 13.4 |
| **3. Add members** | one by one with the intake wizard (master data → mandate → contribution) or as a list via CSV | *Contributions → Members* | 13.2, 13.3 |
| **4. Obtain mandates** | signature on paper or via one-time link; only an **active** mandate can be collected | menu ⋯ → *Manage mandate* | 13.2 |
| **5. Assign and maintain fees** | assignment to a contribution group; later change the fee, switch the group, end it – also fee-free for pauses | menu ⋯ on the member row, *Contribution groups* | 13.4 |
| **6. Collect** | date, pre-notification to members, release, submission of the SEPA file to the bank | *Contributions → Collection* | 13.5 |
| **7. Reconcile the money** | assign incoming payments to the runs, handle returned debits | *Collection*, bank reconciliation | 13.6, 13.7, 13.10 |
| **8. Follow up** | open claims, dunning status, exceptions, tasks | *Collection → Claims*, tasks in the header | 13.8, 13.9 |
| **9. As needed** | contribution confirmation, data overview, anonymization | the member's record or *My contribution* | 13.12, 13.13 |

If members are to maintain their own details, also switch on "My contribution"
(13.11). The rest of the chapter is meant for looking things up: you don't have
to read it in order.

### 13.1 What you need beforehand

1. A **creditor identification number**. Issued free of charge by the
   Deutsche Bundesbank on request; it looks like `DE98ZZZ09999999999` and
   identifies your club as the payee on every collection.
2. A **written mandate per member**. The app manages the details, but does
   not replace the signed direct-debit authorization – that belongs in your
   own records.
3. A **cash account with an IBAN on file** in the account list. This is the
   account collections are made into.

Enter the creditor ID and the collecting account under *Nextcloud settings →
Vereinsbuchhaltung → Fees & SEPA → Basic settings*. That's also
where the toggle is that shows the **"Contributions"** tab in the main
navigation (see 13.2) – if a member has already been created, it appears
automatically, even without the toggle.

> **Do almost all members pay the same fee** (e.g. €8 monthly, the normal
> case for a choir or sports club)? Then create a **contribution group** for
> it (13.4): its "default fee" and "default interval" are what "Add a member"
> (13.2) will suggest from then on, instead of you typing them in again for
> every single member. The group's default fee also applies during the
> CSV import (13.3) when a row has a start date but no amount of its own –
> deviating individual cases (reduced fee, honorary member) simply get
> their own amount entered.

**All settings at a glance.** What the module makes configurable is found under
*Nextcloud settings → Vereinsbuchhaltung* – and is accessible to administrators
only:

| Card / section | What you set there |
|---|---|
| *Fees & SEPA → Basic settings* | **SEPA creditor ID**, **Collecting account**, the toggle for the "Contributions" tab, the toggle for the "My contribution" self-service and the additional toggle that lets members **switch their payment method to bank transfer** themselves there (13.11) |
| *… → Contribution year and collection cycle* (*Beitragsjahr und Einzugszyklus*) | **Contribution year begins in** (month, default January – independent of the fiscal year; determines the contribution periods and the contribution confirmation, 13.12) and the three **deadlines before the collection** – **warning window** (default 21 days), **pre-notification lead time** (default 14) and **release lead time** (default 5; all 13.5). An example line converts them to a collection date, and a note appears for an unusual order |
| *… → Storage of the collection file (XML)* (*Ablage der Einzugsdatei*) | an additional copy of the pain.008 file in a Nextcloud folder (13.5), off by default |
| *… → Mandates* (*Mandate*) | **Mandate reference prefix**, **Expiry warning** (days before expiry, default 180), **Proof folder** and **Point out mandates without proof** (13.2, 13.9) |
| *… → Returned debits and dunning* (*Rücklastschriften und Mahnwesen*) | **Account for return fees (expense)**, **Default revenue account for contribution claims (income)**, **Pass return fees on to the member** (off by default) and **Dunning interval (days)** (13.7, 13.8, 13.10) |
| *Mandate legal text* (*Mandats-Rechtstext*, a section of its own) | the text on the mandate form and the consent page (13.2) |

The proof folder and the XML storage live in the home of the user you chose
under *Receipts* for storage in the Nextcloud file tree (2.4); without one,
no proofs can be uploaded and the XML storage can't be switched on.

The **schedule** (collection day per interval, individual periods with a
custom date) can also be adjusted by bookkeepers – that is the rescheduling.
You find it in the "Contributions" → Collection tab at the timeline (13.5).
The **deadlines before the collection**, on the other hand, can only be
changed by administrators, here in the Nextcloud settings: *Claims are
created* (early-warning window), *Pre-notification to members*
(pre-notification lead) and *Release the run by* (release lead time), each in
days before the collection. They determine when tasks and pre-notification
emails are triggered and from when a period is locked against changes. A small
deadline strip turns the values into an example date; the schedule shows the
same strip for information only.

### 13.2 Members, mandates and contributions

In the **"Contributions" tab → Members** (main navigation, not the Nextcloud
settings – that's ongoing work, not a setting) you keep the members, as far
as the app needs them for the money: name (person or organization), contact
details, member number, and join and leave dates. For the address you choose the **country** from the
list of all countries; it is preset to the country of the person currently using
the app (from their Nextcloud language, otherwise the browser, otherwise
Germany), and an existing member keeps the stored value. This is not full member
management, and a member doesn't need a Nextcloud account; an existing one
can be linked in the member's record ("Nextcloud account" (*Nextcloud-Konto*)
→ "Find suggestions" (*Vorschläge suchen*), only after your confirmation –
the email address merely supplies the suggestion).

The member list shows in the **"Nextcloud account?"** column (*Nextcloud-Konto?*)
whether an account is linked: "Yes", a link **"Link suggestion …"** (exactly one
account carries the member's email address; it is linked after a
confirmation), a button into the record when there are several matches,
otherwise "No".

When **admitting** a member you can **create a Nextcloud account** right away
(*Create a Nextcloud account for this member*): account name (suggested from
the name), display name, email, password – empty means Nextcloud sends an
invitation with a link to set the password –, groups, storage and language,
the same details as in the user management. Only Nextcloud administrators,
delegated administrators and group administrators (for their groups) see the
section; the rights and password rules are Nextcloud's. The new account is
linked to the record. If creating it fails, the member is admitted anyway – it
can be done later in the record under *Nextcloud account* → *Create a new
Nextcloud account …*.

The **welcome email** goes to the email address of the record, regardless of
whether the member has an account. The template (subject and text with
placeholders such as `{name}`, `{vorname}`, `{verein}`, `{mitgliedsnummer}`,
`{beitritt}` and `{cloud}`) is maintained by administrators in the Nextcloud
settings under *Welcome email* (*Willkommens-Mail*). When admitting, it is
collapsed and can be adapted for the individual member; from the record,
*Send welcome email …* sends it later.

Two more pieces of information attach to the member, both optional and
possible to add at any time:

| Field | What goes there |
|---|---|
| **Mandate** | IBAN, optionally BIC, account holder, signature type (paper or electronic) and the date the mandate was signed |
| **Contribution** | the assignment to a contribution group: monthly fee, interval, payment method and start (see 13.4) |

**The IBAN lives on the mandate, not on the member** – a member without a
mandate simply has no bank details in the app. The **"+ Member"**
(*＋ Mitglied*) button ("Contributions" tab → Members) opens the three-step
**intake wizard**: master data → mandate → contribution. Steps 2 and 3 can
be skipped and added later – the mandate in the member's record (menu ⋯ →
**"Manage mandate"** (*Mandat verwalten*)), the contribution in the *Contribution
groups* tab (13.4):

- **member only** – without bank details and without a contribution.
- **contribution without mandate** – for members paying by transfer or cash
  (payment method *Bank transfer*). The app still creates a claim when it's
  due, then simply as a reminder.
- **mandate without contribution** – if you only collect something
  occasionally (via a single claim, see 13.4).

> **Enter the email address.** Without it, the app cannot send the legally
> required pre-notification, and you'll have to notify every member
> yourself. The list flags every row without an address; via *problems only*
> you see them all at once. A direct debit cannot be set up without an
> address: the wizard then sets the payment method to *Bank transfer*.

**Bulk email to the members.** The **"Copy email addresses"** button above the
list puts the addresses of the members currently shown on the clipboard,
separated by semicolons – paste them into your email program, best into the
"Bcc" field so the recipients don't see each other. Members who have left are
left out, each address appears only once (families often share one), and the
message says how many members are missing for lack of an address. Use the
search or *problems only* beforehand to narrow down the recipients.

The **mandate reference** is assigned by the app itself: prefix and running
number (e.g. `M-17`; the prefix is set in the administration settings under
"Mandates" (*Mandate*), and in the wizard and in the mandate the reference
can also be specified by hand). It appears on the payer's bank statement –
share it with them together with the mandate form.

> A mandate is **revoked, not deleted**. Collections already generated refer
> to it, and that proof has to be preserved. A mandate that was never
> effective can be discarded as a draft (see below); that too remains
> traceable under "Former mandates" (*Frühere Mandate*).

**Managing the mandate in the member's record.** Via the row's menu (⋯) →
**"Manage mandate"** the record opens at the *SEPA mandate* section. It shows
the state in plain words, reference, account holder, IBAN/BIC, signature type
and date, the expiry date under the 36-month rule and whether proof is on
file – plus notices saying what needs doing right now ("Signature missing"
(*Unterschrift fehlt*), "Clarification pending" (*Klärung offen*), "Obtain a
new mandate" (*neues Mandat einholen*), "Mandate without proof" (*Mandat ohne
Nachweis*), "Mandate expires in … days" (*Mandat läuft in … Tagen ab*)).
The "History" (*Verlauf*) names, for every change, who made it and when;
under *Former mandates* are the ended ones.

| State | Meaning | What you can do |
|---|---|---|
| **Draft** | Details are on file, the signature is missing | Paper: enter the signature date and **Activate** (*Aktivieren*) – the date is mandatory and is the gate. Electronic: **Send one-time link** (*Einmal-Link senden*) or send it again; the record shows when and to whom it went and whether it has expired. For typos or if the mandate doesn't come about: **Correct draft** (*Entwurf korrigieren*) or **Discard draft** (*Entwurf verwerfen*) |
| **Active** (*Aktiv*) | eligible for collection | **Change bank details** (*Bankverbindung ändern*), **Block** (*Sperren*), **Revoke mandate** |
| **Suspended** (*Ausgesetzt*) | temporarily not eligible for collection | **Unblock** (*Entsperren*), **Revoke mandate** |
| **Lapsed** (*Erloschen*) | revoked, replaced, expired, ended or discarded as a draft | **Create mandate** (*Mandat anlegen*) for a new one |

**Blocking and unblocking** each require a note; it appears in the history.
A block ends nothing, open claims stay open. A block after a returned direct
debit is set by the app itself and marked as such – you have to unblock by
hand once the case is resolved.

**Change bank details** covers three cases: only the **IBAN** has changed
(the same mandate stays, a new signature isn't needed; the app reports the
change to the bank with the next collection, until then it is listed as
"open" under "Changes of bank details" (*Änderungen der Bankverbindung*)),
only the **name** was misspelled (silent correction), or the **account holder
changes** (a new mandate comes into being, the old one is "replaced"; with a
signature date it is active immediately, without one it stays a draft).

**Correcting or discarding a draft.** As long as a mandate is a draft,
nothing has ever been collected through it – so you need neither a
revocation nor an amendment. **Correct draft** changes IBAN, BIC and account
holder directly (the dialog is pre-filled with the existing values, every
correction is in the history, the IBAN there only masked). For an
**electronic** draft, the correction invalidates the one-time link already
sent – send a new one afterwards; for a **paper** draft, the details must
match the signed form. **Discard draft** ends the draft permanently; the
reason is mandatory and appears in the history. The mandate then appears
under *Former mandates* as "Draft discarded" (*Entwurf verworfen*) (not a
revocation – it was never effective, so no payment request goes out either),
and a new mandate can be created for the member. The member can also discard
their own draft under "My contribution", but not correct it – afterwards
they grant the mandate again with the right details.

**Revocation is final** – a revoked mandate cannot be reactivated. The
dialog shows the sum still open and offers as the first choice what is
usually meant: "I only have a new account → change IBAN" (*Ich habe nur ein
neues Konto → IBAN ändern*). For open claims the member receives a payment
request.

**Proof and form:** You upload the signed mandate as a file (it lands in the
proof folder in Nextcloud) and can download it again; **Open mandate form**
(*Mandatsformular öffnen*) shows the print-ready form (Ctrl+P or ⌘P).

**The electronic mandate – what the member sees.** **Send one-time link**
sends the member an email at their address on file (without an address it
can't be sent); if the email doesn't arrive, the record shows the link right
after sending so you can pass it on directly. The link is valid for 14 days and
can be used once; it opens, without signing in, a page with the
mandate text, the account details and the button **"Ich stimme zu und erteile
das Mandat"** ("I agree and grant the mandate"). With the consent the mandate
is active at once – no paper form is needed. As proof, the mandate records when
consent was given, from which IP address and with which browser, and under which
version of the mandate text. If the link has expired or got lost, send a new
one; the old one becomes invalid.

**The mandate text.** The text on every mandate form and on the consent page
consists of a **mandatory block**, which the direct-debit scheme prescribes and
which can't be changed, and a **framing text** you can add to – for example
notes on fee collection or on data protection. Administrators maintain it under
*Nextcloud settings → Vereinsbuchhaltung → Mandate legal text* (*Mandats-
Rechtstext*): the preview shows the text with your club's name, **"Save new
version"** (*Neue Version speichern*) creates a new version. Earlier versions
stay in the version history; existing mandates keep the version that was shown
to them when they were granted. If an app update changes the mandatory block,
the app creates a new version itself and carries over your framing text. The
mandate text is available in German only.

**Leaving and deleting.** In the record, **"Declare leaving"** (*Austritt
erklären*) sets the member's leaving as of a date (also in the future); it
can be withdrawn as long as it doesn't have to take effect yet. A member can
only be **deleted** as long as nothing is attached to them: no mandate (not
even a draft or an ended one – they are kept as proof), no assignment and no
claim. Otherwise the record names the reason instead of the button. For
data-protection cases there is anonymization instead of deletion (13.13).

### 13.3 Adding many members at once

For a choir with 200 voices, the form is the wrong way. In the
"Contributions" tab → Members, use the **"Import list"** button: a **CSV
file**, one row per member.

The following columns are expected – **order and spelling don't matter**,
and extra columns (voice part …) are simply ignored:

| Column | Example | Required? |
|---|---|---|
| Name *or* account *or* first name + last name *or* organization | `Katrin Brunner`, `k.brunner`, `Katrin` + `Brunner`, `Musikhaus Beispiel GmbH` | yes – one of them; with first/last name, the last name is required |
| Member number | `0815` | no, but a hard duplicate key (see below) |
| Joined | `01.03.2019` | no – may lie in the past, the import day applies if empty |
| Street, ZIP, City, Phone | `Musterweg 12`, `12345`, `Musterstadt`, `0123 456789` | no |
| Email | `k.brunner@example.org` | no, but strongly recommended |
| IBAN | `DE02 1203 0000 0000 2020 51` | only if collections are made |
| BIC | usually empty | no |
| Account holder | `Peter Brunner` | no, otherwise the member's display name |
| Mandate on | `15.01.2026` | yes, as soon as there's an IBAN |
| Mandate reference | `ALT-0001` | no, otherwise the app assigns one |
| Contribution group | `Choir members` | yes, as soon as a fee should be created – if there is only one group, the column may be missing |
| Amount | `8.00` | only if a fee should be created – the **monthly amount**, regardless of the interval. **`0`** means fee-free (passive, honorary, supporting members, pauses). Empty but with a start: the default fee of the contribution group |
| Frequency | `monthly` | no – **yearly** applies if not specified, or the default interval of the contribution group when the amount is empty; only sets the interval, not the amount |
| Start | `01.02.2026` | yes, as soon as there's an amount above 0 – must not lie in the past. For **0 €** it may be missing: the assignment then applies from the import day |

A row without an IBAN and without an amount is valid – only the member is
created then, a mandate and a fee can be added any time later through the
member's record. A **fee-free** row (amount `0`) needs only the contribution
group – e.g. "Ruhend" (dormant) with a 0 € lower limit (13.4): no IBAN or
mandate, start date or frequency is needed, and nothing is ever collected. The
preview checks beforehand whether the amount fits the lower limit and the
interval fits the group.

The name is read like this: if **Organization** is filled, an organization is
created; otherwise a person with exactly the **First name**/**Last name** fields.
If only **Name** is there, it is split at the first space – a name without a
space or with a legal form (GmbH, e. V., eG, Stiftung …) counts as an
organization. The preview marks organizations; "Last name" alone without a
"First name" column acts like "Name".

Dates may be given as `15.01.2026` or `2026-01-15`, amounts as `42,50` or
`42.50`. A **template** to fill in can be downloaded directly.

German column headings are understood just as well (`Name`, `E-Mail`, `IBAN`,
`BIC`, `Mandat`, `Betrag`, `Frequenz`, `Start`, `Vorname`, `Nachname`,
`Organisation`, `Straße`, `PLZ`, `Ort`, `Telefon`, `Eintritt`) – useful when the list comes
out of a German program. The same goes for the frequency: `monatlich`,
`vierteljährlich`, `halbjährlich` and `jährlich` work alongside the English
words.

> **Contribution group with a default fee (13.4)?** Then the amount column
> may stay empty for rows on the same rate – as long as a start date is
> present, the app automatically takes over the default fee and default
> interval of the row's contribution group. Only special cases then need
> their own amount. If no group can be determined (several groups, but no
> "Contribution group" column), no fee is created; the preview names the
> reason.

The process is two-stage: **"Check"** changes nothing and shows you, for
every row, what would be created and what's wrong. Only afterwards do you
apply it. Faulty rows are skipped and listed individually – a typo in row
143 doesn't invalidate the 142 rows before it.
The check also reports an invalid IBAN format, a fee start in the past and a
member number that already appears further up in the file.

**The import only creates, it never reconciles:** a row whose member number
or Nextcloud account already exists is skipped entirely (no duplicate
member, no second mandate). A row with the same name as an existing member
only gets a warning – names are too often ambiguous in clubs to serve as a
duplicate key. A row without an email address automatically lands on the
"bank transfer" payment method rather than direct debit (the preview warns
about this for rows with an IBAN).

**Every mandate created by the import activates immediately.** As soon as
at least one row would create a mandate, the app requires confirming "the
signed mandates are on file" before you can apply the import – this replaces
the usual per-mandate approval and assumes you actually hold the paper
mandates.

### 13.4 Contribution groups, assignments and due dates

What a member pays is stored in an **assignment** to a **contribution
group** (**"Contributions" tab → Contribution groups**):

- The **contribution group** carries the rules: *name*, "lower limit"
  (*Untergrenze*) and "default fee" (*Standardbeitrag*) (each per month),
  the "allowed intervals" (*erlaubte Turnusse*) in months together with the
  "default interval" (*Standard-Turnus*), and whether the group is *active*.
  A higher lower limit is set via **"Raise lower limit"** (*Untergrenze
  anheben*): the preview names the assignments affected beforehand, and
  periods already collected are never recalculated.
- The **assignment** connects a member with a group: "monthly fee"
  (*Monatsbeitrag*), "interval" (*Turnus*), "payment method"
  (*Zahlungsart*) – "direct debit" (*Lastschrift*) or *Bank transfer* – as
  well as "valid from" (*Gültig ab*) and optionally "valid until" (*Gültig
  bis*). The **monthly fee is the base value**: the amount per period
  results from monthly fee × interval (€10 a month with an interval of 3
  months means €30 per quarter). **"Preview"** names the first period, the
  collection amount and the expected collection date before you create it.
  **"Change fee"** (*Beitrag ändern*; the ⋯ menu of the assignment or the member's ⋯
  menu → *Manage fee*) sets the monthly fee, interval and payment method anew: any
  amount above the lower limit, for example when a member wants to give more this
  once. The preview appears by itself and says from when the change applies –
  after an already announced collection only from then on; saving is possible
  only once it matches the fields. **The payment method takes effect at once**
  and has no lock window: switch to *Bank transfer* and nothing is collected
  from now on, not even a collection already announced – the member transfers
  open and future claims themselves; the payment request with GiroCode goes out
  with the next daily run (13.8). Only a run that is already released still goes
  ahead. An existing **mandate stays valid** and is
  simply no longer used; you can switch back to *Direct debit* at any time
  (without a collectible mandate a task then appears, 13.9). This is how you
  switch individual people without ending and re-creating the assignment. **The
  member gets the change confirmed by email** (amount, interval, contribution
  group or payment method, from when it applies, first affected collection), in
  the language of their account; the message after saving says
  whether the email went out – a member without an address gets none, and the
  change applies anyway. **"End"** (*Beenden*) ends an assignment as of today; claims already
  generated remain unchanged. An assignment that only starts in the future can be
  changed as well; **"Withdraw assignment"** (*Zuweisung zurücknehmen*) withdraws
  it, and it then never takes effect. The table shows the **status** (active, from …,
  ended …, withdrawn); ended and withdrawn assignments have no actions left.
- **Fee-free and pauses:** a monthly fee of **€0** is allowed (the group's
  lower limit must be 0 for that). Such an assignment creates **no claims**,
  nothing is collected, and no mandate is needed; the member list shows
  "fee-free". This covers passive, supporting or pause periods: create a
  contribution group "Ruhend" (dormant) with a lower limit and default fee of
  €0. For a pause, end the running assignment at the start of the pause and
  create a new one in that group, and switch back at the end. Membership and
  mandate stay untouched. The app records fee-free periods in the background
  (they show up in no list) so that a later fee increase does not claim them
  retroactively.
- **Assignments are never retroactive:** *valid from* must not lie in the
  past. Whatever is still to be claimed for a past period you create as a
  **single claim**: **"+ Single claim"** (*+ Einzelforderung*) offers a free
  amount with its own collection date, also without a mandate, e.g. for a
  back payment or a special fee.

The **claims** arise from the assignments by themselves: the app's daily run
creates a claim as soon as the collection date of its period falls into the
**early-warning window** (*Vorwarnfenster*) (default 21 days beforehand, see
13.5). A claim is the same row as an open item and therefore also appears
under *Bookings → Open items*; its state, dunning status and exceptions are
shown by the "Claims" segment (*Forderungen*) in Collection (13.8).

**The member list** (*Contributions → Members* tab) shows for each member
the mandate (IBAN, marked *Draft* or *suspended* where applicable) and
the assignment: *Amount* is the amount per period (monthly fee × interval),
*Frequency* the interval, *Active* names the state of the assignment
(*active*, *from …*, *ended …*), and *Next due date* is the earliest date
among the member's still-due claims (open, in collection or returned; a
running deferral counts with its end). Members who pay by bank transfer show
"Bank transfer" instead of "no mandate". Everything else about a member is in
the menu (⋯) at the end of the row: **"Edit member"** (*Mitglied bearbeiten*)
opens the file (master data, Nextcloud account, leaving), **"Manage mandate"**
opens the file at the mandate, and **"Manage fee"** opens **"Change fee"**
right in the list (amount and interval; without a fee: **"Create assignment"**
with the member preselected). With several assignments the entry leads to the
*Contribution groups* tab. Clicking the name also opens the file.
*problems only* shows members without an email address and those whose direct-debit
assignment has no mandate.

### 13.5 Collection: date, release and submission

In the **"Contributions" → Collection** tab, the **"Timeline & runs"**
(*Zeitstrahl & Läufe*) segment shows the contribution year with all
**collection dates** and a marker for TODAY (*HEUTE*). The dates come from
the **schedule** (the "Schedule" (*Terminplan*) button at the timeline, see
below) and from single claims with their own date. A click on a date shows its
milestones and – as long as nothing has been released – a **preview**: how
many claims with what total would be collected and which exceptions (13.9)
are pending. Before release there is no run, the preview saves nothing.

**Schedule.** The "Schedule" button at the timeline opens it as a dialog. For each
interval the collection day reads as a sentence: "Collection **14** days
**after** the start of each period". Periods start on the first of a month; to
collect on the 15th, enter 14, and the view names the day ("on day 15 of the
first month of the period"). "before" moves the collection ahead of the period
start. Below, the periods of a contribution year ("Quarter 1 · Jan–Mar") are listed
with their collection date and weekday; the dates update as you type. The
dialog starts with the year the timeline is showing, and the arrows page
through the years. **Change date** gives a single period a custom date,
picked as a date. It applies to the same period in every contribution year,
because what is stored is the distance from the period start – if the end of
February lies in between, the day can therefore shift by one in leap years, and
the view points this out. The app checks two rules before saving and names the
affected periods with their dates: no date may fall on or before that of the
previous period, and each stays within the contribution year of its period.
**Save** in the dialog's footer saves all changed intervals and closes it;
**Discard** on an interval brings back its saved state. Closing with unsaved
changes (Cancel, the X, Esc) first asks whether to save or discard them.
Intervals that no member uses are collapsed. If a member joins in the middle of
a period, the days for their first claim count from the joining date. At the
bottom, a deadline strip shows when, for the next collection, the claims are
created, the pre-notification goes out and the run should be released.

**A run belongs to exactly one collection date** and bundles all collectable
claims of that date. The sequence, with the default values of the adjustable
lead times:

| When | What happens |
|---|---|
| 21 days before collection – "early-warning window" (*Vorwarnfenster*) | The claims are created, the task list (13.9) announces the next run: number, total, exceptions |
| 14 days before collection – "pre-notification lead time" (*Vorabinfo-Vorlauf*) | The app sends the **pre-notification** by email – bundled per member, with amount, date, mandate reference, creditor ID and the earliest collection day. From then on the period is "closed": its amount no longer changes |
| 5 days before collection – "release lead time" (*Freigabe-Vorlauf*) | You **release the run and submit it**; from here "Release due" (*Freigabe fällig*) appears in the task list |
| Collection date | The bank debits, no earlier than that day; shifting to a business day is calculated by the bank |

The app sends the pre-notification only to members **with an email
address**; you have to give everyone else the pre-notification yourself, the
task list tracks them. Members paying by bank transfer get claims, but never
collection items, pre-notification or exceptions. You set the lead times:
*early-warning window*, *pre-notification lead time* and *release lead time*
are found together in the Nextcloud settings under *Fees & SEPA* →
"Contribution year and collection cycle" (*Beitragsjahr und
Einzugszyklus*). SEPA requires the pre-notification at least 14 days before the
collection unless the mandate agrees a shorter period.

Release and submission are two steps for the chosen date:

- **Step 1 of 2 – "Release & create file"** (*Freigeben & Datei erzeugen*):
  amounts, IBAN and account holder of the items are frozen and the
  **pain.008 file** is created. Each item gets its own end-to-end ID, which
  is never reused. Exceptions do not block the release: the claims affected
  stay open and do not enter the run. A date in the past doesn't block
  anything either.
- **Step 2 of 2 – "File has been submitted to the bank"** (*Datei ist bei der
  Bank eingereicht*): with **"Download XML"** you get the file and upload it
  in online banking, then you confirm the submission. This is final: from
  then on the run can neither be discarded nor moved, and the mandates
  involved record the collection – the 36-month period and the type of
  collection (first or recurring) depend on it.

> **Before the first real collection**, test the file against your bank's
> validation tool. The exact format varies slightly by institution.

As long as the file has **not been submitted**, the run can still be changed:

- **"Discard run"** (*Lauf verwerfen*) (a reason is mandatory) dissolves it:
  the items remain as history, the claims are free again and go into a new
  run, the end-to-end IDs are never used again. If mandate data has changed
  since the release, the run points this out – discard it then and release
  the date again, the new file contains the current data.
- **"Move date"** (*Termin verschieben*) only works to a later date (an
  earlier date would fall below the lead times); the file then carries the
  new date, identifier and end-to-end IDs stay. Download it again
  afterwards.

If the file is already at the bank, do **not** discard it but confirm the
submission: discarding changes nothing at the bank, and the freed claims
could otherwise be collected a second time. A collection that has already
been submitted you recall at the bank.

On request, the app puts a **copy of the file** in a Nextcloud folder – "XML
storage" (*XML-Ablage*) under Nextcloud settings → *Fees & SEPA*;
off by default because the file contains all IBANs in plain text. In the run
view, by contrast, the IBAN is always masked.

### 13.6 When the money has arrived

As soon as the batch credit is on your club's account, you import the bank
statement as usual (chapter 3.2). The credit then appears in the **"Bank
reconciliation"** (*Bankabgleich*) segment (13.10): the app suggests which
items of the run it covers, you judge the rows and **post** the transaction
in one step – the claims it contains are settled as paid in the process, so
with 80 members that is one posting (after checking) instead of eighty
clicks. Returned rows stay open: that money hasn't arrived.

A posting for a collection never arises by itself: the bank statement is the
truth, and only your posting turns it into the entry (bank in debit, the
revenue accounts in credit).

### 13.7 Returned direct debits

If a collection comes back (account not covered, mandate disputed), the app
detects this on the next **bank-statement import**: the returned direct
debit appears in the **"Bank reconciliation"** segment (*Bankabgleich*)
(13.10), matched to the item it belongs to, with the **reason in plain
words**. After the import, the import dialog reports how many possible
returned direct debits were detected; a watch-folder run notes it in the
log.

Only your **judgment and posting** triggers what the row and the preview
announce: the claim becomes open again, the mandate is blocked depending on
the reason, and the member receives a payment request (table of consequences
in 13.10, dunning levels in 13.8). After a returned direct debit, a claim is
**not collected again by direct debit**.

The detection uses the structured details of the bank statement (CAMT:
end-to-end ID, mandate reference, return reason); for formats without these
details it reads the purpose text if need be, and is then occasionally wrong.
That is why none of it is automatic: you judge every row – **"Reject"**
(*Ablehnen*) or **"Not assignable"** (*Nicht zuordenbar*) – and a judgment
can be changed until posting. A return often only arrives **after** the
batch credit has already been posted – it is detected then too, and the
claim becomes open again.

### 13.8 Claims, dunning status and exceptions

In the **"Contributions" → Collection** tab, the **"Claims"** segment
(*Forderungen*) – next to "Timeline & runs" (*Zeitstrahl & Läufe*) – shows
all claims against members: description, type (either "contribution"
(*Beitrag*) or "fee" (*Gebühr*)), due date, amount, **state**, **dunning
status** and possible **exceptions**. The general open items without a
member (invoices and the like) still appear under *Bookings → Open items*.
Everyone from auditor up may read it; marking as paid, deferring, waiving
and cancelling are for bookkeepers and administrators.

**The state** is derived and stated in plain words: *open*, "in collection"
(*im Einzug*) (in a released or submitted run, the date still lies ahead of
us), "collected" (*eingezogen*) (date passed, no return), "returned"
(*zurückgegeben*) (returned direct debit), "settled (paid)" (*erledigt
(bezahlt)*) or "settled (waived)" (*erledigt (erlassen)*) and "cancelled"
(*storniert*). A **deferred** claim additionally carries the badge "deferred
until …" (*gestundet bis …*). The list defaults to the **unsettled** claims
(open, in collection, returned); the filters "State" (*Zustand*),
"Exception" (*Störfall*), *Member* (part of a name) and "Due from/to"
(*Fällig von/bis*) narrow it down. **Details** unfolds a claim. **"By
member"** (*Je Mitglied*) summarizes the same selection per member – the
dunning levels go out bundled per member.

**The dunning status** shows the level reached – one of "payment request"
(*Zahlungsaufforderung*), "payment reminder" (*Zahlungserinnerung*),
"dunning notice" (*Mahnung*) and "escalated to the board" (*An Vorstand
eskaliert*) – plus when it was sent and when the **next level falls due**
(interval: "dunning interval" (*Mahnabstand*) in the administration
settings, preset to 14 days). A **deferral pauses** payment reminder,
dunning notice and escalation up to and including its last day; after that
the dunning clock continues from the last level reached. Direct-debit claims
get the payment request only after a returned direct debit or a revocation,
bank-transfer claims shortly before the due date.

**The emails** go out bundled per member – one email with one line per open
claim, only to members with an email address. Each item carries its own
**GiroCode** as an image attachment (EPC QR): the member's banking app scans it
and prefills payee, IBAN, amount and purpose, so every claim is paid
individually instead of as one lump sum. The payee is the *Collecting account*
(13.1) – if it has no IBAN on file, there are no codes. If the server lacks the
PHP gd extension, the email goes out without a GiroCode (the email text then
doesn't promise one), and the error is written to the Nextcloud log.

**Exceptions** have two severity levels, "action required"
(*Handlungsbedarf*) and "notice" (*Hinweis*), and name the cause in plain
words – such as "Pre-notification could not be sent in time" (*Vorabinfo
konnte nicht rechtzeitig verschickt werden*), "no collectable mandate" (*kein
einzugsfähiges Mandat*) or a returned direct debit. Nobody acknowledges
them; they disappear as soon as the cause is fixed. **"Open member record"**
(*Mitglieder-Akte öffnen*) jumps to the member's record. For a returned
direct debit the **reason is in plain words**; the bank's return code is
seen only by bookkeepers and administrators, not by an auditor.

**Waiver and cancellation are different** – the dialogs say so, too:

| | Waiver | Cancellation |
|---|---|---|
| Meaning | The claim **was justified**, we waive it | The claim **should never have existed** (duplicate, by mistake) |
| When | **any time**, even after submission | **only before submission** |
| Required | Reason | Reason |

If a claim is already in a **submitted** run, there is only the waiver (or
marking it *paid*); in a **released** run you first discard the run
(*Timeline & runs*), because the generated file would otherwise still
contain the claim. The app points out both at the relevant place.

**"Mark as paid"** (*Als bezahlt markieren*) records the time, your name and
an optional note – no posting arises from it, you assign the payment to the
bank transaction as usual. **"Defer"** (*Stunden*) requires the "until" date
and a reason; each claim has at most one running deferral, **"Lift
deferral"** (*Stundung aufheben*) ends it early. A deferral (and a paid mark)
does not stop a collection that is already in a file or at the bank – the
app warns you then. **"+ Single claim"** creates a manual claim (free
amount, own collection date, also without a mandate), the same input as in
the *Contribution groups* tab. You can pick **several members at once**: each
gets its own claim with the same details.

### 13.9 Tasks and notices in the header

The button with the clipboard in the header (from bookkeeper up, as long as
the contributions module is in use) gathers everything that needs attention
regarding members, mandates and claims. The number on the button counts only
the **action required** (*Handlungsbedarf*); **notices** (*Hinweise*) appear
in the window but don't draw attention to themselves. There is no
acknowledging: a task disappears by itself as soon as its cause is fixed.
**"To the record"** (*Zur Akte*) or **"To collection"** (*Zum Einzug*) take
you to where you fix it.

| Task | Severity | How it disappears |
|---|---|---|
| Direct debit wanted, but **no mandate** created yet | Action required | Create and activate a mandate, or switch the assignment to bank transfer (*Change fee* → payment method, 13.4) |
| **Paper mandate in draft**, signature missing | Action required | Enter the signature date and activate the mandate |
| Electronic mandate: link expired (notice as long as it is still valid) | Action required / notice | Send the link again or switch to paper |
| **Mandate blocked**, clarification pending – for a block after a returned direct debit with the reason in plain words | Action required | Resolve the case, unblock the mandate (note mandatory) |
| **Mandate lapsed**, but the assignment still requires direct debit | Action required | Obtain a new mandate or switch to bank transfer (*Change fee* → payment method, 13.4) |
| Mandate **without proof** (only active paper mandates; can be switched off with "Notify about mandates without proof" (*Auf Mandate ohne Nachweis hinweisen*) in the administration settings) | Notice | Upload the signed document in the mandate |
| Mandate **expires in N days** (N: "Expiry warning (days before expiry)" (*Ablauf-Vorwarnung (Tage vor Verfall)*) in the administration settings, preset to 180) | Notice | A submitted collection restarts the 36-month period |
| **Left**, but claims still open – the mandate stays active | Notice | Settle or waive the claims; the mandate then ends by itself |
| Returned direct debit **without re-collection** (one line with count and total) | Notice; **action required** as soon as a cause is urgent (account not usable, objection, deceased, technical, unknown – only *insufficient funds* (*Deckung fehlt*) stays a notice) | Claim paid or waived |
| Claims open **after revocation** of the mandate (one line) | Notice | Claim paid or waived; a new mandate takes them back into the collection |
| **Bank-transfer claims overdue** (one line) | Notice | Assign the payment or settle the claim |
| Pre-notification not sent in time, release due, submission overdue, dunning level escalated to the board, next run, member due for anonymization | depending on the case | see 13.5, 13.8 and 13.13 (member due for anonymization: a notice, never on its own) |

For a member whose mandate is in draft, blocked or lapsed, only the more
precise task is in the list instead of the general "no collectable mandate"
(*kein einzugsfähiges Mandat*) – the same problem doesn't appear twice.

Two notices arise from an **event** rather than a state: "Name: Nextcloud
account was deleted, the email address was taken over – please check"
(*Name: Nextcloud-Konto wurde gelöscht, die Mailadresse wurde übernommen – bitte
prüfen*) and "N members taken over — check names/email addresses" (*N Mitglieder
übernommen — Namen/Mailadressen prüfen*) (after the switch to member
management). These too don't have to be clicked away: they disappear after
**30 days**, earlier as soon as the member has a Nextcloud account again or
no longer exists (deleted or anonymized).

### 13.10 Bank reconciliation: reviewing and posting suggestions

**The bank statement is the truth.** A posting for a collection arises only
once the bank has booked the money – and even then not by itself: in the
**"Contributions" → Collection** tab, the **"Bank reconciliation"**
(*Bankabgleich*) segment shows which suggestions have arisen from the
bank-statement import and are waiting for your judgment. Everyone from
auditor up may read it; judging and posting are for bookkeepers and
administrators.

Two views, each with the number of waiting transactions:

- **Collections and returns** (*Einzüge und Rückgaben*): the batch credit of
  one of your own collections and returned direct debits.
- **Incoming payments** (*Zahlungseingänge*): credits without SEPA reference
  (an ordinary transfer) whose amount matches an open claim.

**Collection credit and returned direct debit.** Each transaction carries
amount, type and state at the top (*awaiting judgment* (*wartet auf Urteil*),
*ready to post* (*bereit zum Verbuchen*), *judged without assignment* (*ohne
Zuordnung beurteilt*)) as well as the progress, for a batch credit e.g.
**"4 of 12 judged"** (*4 von 12 beurteilt*). **"Review rows"** (*Zeilen
prüfen*) unfolds the rows: per row, what the bank reports (amount,
end-to-end ID, mandate reference), and the **suggestions** – all matching
items with member, claim, amount and collection date, plus the **reason in
plain words**:

| Reason | Means |
|---|---|
| Same end-to-end ID (*Gleiche End-to-End-ID*) | The ID the app wrote into the file comes back from the bank: the surest match. |
| Same mandate reference and same amount (*Gleiche Mandatsreferenz und gleicher Betrag*) | No match via the ID, but mandate and amount fit. If there are several items of this mandate with the same amount, all are offered for selection. |
| Same amount and same payer IBAN (*Gleicher Betrag und gleiche Zahler-IBAN*) | Only for a returned direct debit, only without references: the weakest match, the app tells you to check carefully. |

There is no figure like "92 % sure" (*92 % sicher*). Where several items
match, **none is preselected** – you choose. Per row you have three options:
**Assign** or **Confirm** (*Bestätigen*) (when there is exactly one
suggestion), **Reject** (*Ablehnen*) (the suggestion is wrong) or **Not
assignable** (*Nicht zuordenbar*) (there is no matching item). A judgment can
be changed again with **"Change judgment"** (*Urteil ändern*) as long as the
transaction isn't posted. An item belongs to at most **one** row: once a row
has it, the others no longer offer it.

For a batch credit, **"Confirm unambiguous suggestions"** (*Eindeutige
Vorschläge bestätigen*) saves the clicks: the button confirms all rows that
have exactly one match via end-to-end ID or mandate reference. Ambiguous
ones and the weakest match are left to you, and nothing is posted yet.

**Posting** only works once **all** rows are judged and at least one is
assigned to an item – the note beside the button says how many are still
missing. **"Post…"** (*Verbuchen…*) opens the **posting preview** (*Vorschau
der Buchung*):

- **Batch credit:** the bank in debit, the revenue in credit, **grouped by
  revenue account** ("4000 Membership fees · 80 items" (*4000
  Mitgliedsbeiträge · 80 Posten*)). The claims contained are settled as paid
  and linked to the posting. The revenue account comes from the claim,
  otherwise the **default revenue account** (*Standard-Erlöskonto*) applies
  (Nextcloud settings → Vereinsbuchhaltung → Fees & SEPA).
- **Returned direct debit:** two counter-account lines in debit – the
  **revenue back** (*Erlös zurück*) to the claim's revenue account and the
  **bank fee** (*Bankgebühr*) to the "account for returned-debit fees"
  (*Konto für Rücklastschriftgebühren*), set in the same place – and the
  bank in credit.

**The posting date is the date of the bank transaction**, not of the
original collection. If the transaction falls in a **closed fiscal year**,
the preview says so before you click, and the button stays off; an
administrator can reopen the fiscal year in the Nextcloud settings
(Vereinsbuchhaltung → Fiscal year). The same applies to a total that doesn't
add up: if you have rejected rows, the rest doesn't cover the transaction –
the preview names the difference, and you then post the transaction by hand
under *Bookings → To assign*. A transaction that has already been posted
cannot be posted again.

**Returned direct debit: reason and consequences.** The **reason is stated in
plain words** ("Returned for lack of funds" (*Mangels Kontodeckung
zurückgegeben*), "Account not usable" (*Konto nicht nutzbar*), "Disputed by
the payer or without a valid mandate" (*Vom Zahlungspflichtigen
widersprochen oder ohne gültiges Mandat*) …); the **bank's ISO code** is
seen only by bookkeepers and administrators. Already at the row, and once
more in the preview, it says what happens **automatically** on posting, so
you aren't surprised:

| Reason | Mandate | Payment request | Fee |
|---|---|---|---|
| Insufficient funds | stays | immediately by email | fee claim if passing the fee on is switched on |
| Account not usable | is blocked | immediately by email | fee claim if passing the fee on is switched on |
| Objection, no mandate | is blocked | immediately by email | – |
| Payer deceased | is blocked | none | – |
| Technical error, unknown | stays | none | – |

In every case the claim becomes open again and is **not collected again by
direct debit**. If the mandate is already blocked or lapsed, it stays as it
is. The payment request only goes to members with an email address.

**Incoming payments.** Each suggestion reads "this credit matches claim X"
(*diese Gutschrift passt auf Forderung X*) with a reason (amount matches; if
the name is also in the payment text, the app says so) and the posting that
would arise: **bank to revenue account** (*Bank an Erlöskonto*). **"Confirm
and post"** (*Bestätigen und verbuchen*) posts the transaction to the claim's
revenue account and closes the claim as paid. **"Reject"** remembers that
this credit doesn't belong to this claim – the suggestion doesn't come back;
you then post the transaction by hand under *Bookings → To assign*. Both ask
for confirmation first. If the claim has no revenue account and no default
revenue account is set, the suggestion can only be confirmed once an
administrator sets one.

### 13.11 "My contribution": the area for members

Members don't have to bother the board for their own details: under **"My
contribution"** (*Mein Beitrag*) – a tab of its own in the app – they maintain
their contact details, change their fee and manage their direct-debit mandate
themselves. **No bookkeeping role** is needed for that, there is no application,
and every change takes effect at once; the only brake is a preview before
saving.

**Switching it on.** Two things have to come together:

1. An administrator switches on **"Self-service "Mein Beitrag" for linked
   Nextcloud accounts"** under *Nextcloud settings → Vereinsbuchhaltung → Fees &
   SEPA → Basic settings*. It is off by default.
2. The member is linked to their Nextcloud account in the record (13.2) – after
   your confirmation, never on its own.

Optionally, right below it: **"Members may switch their payment method to bank
transfer themselves under 'My contribution'"** (off by default). Only with this
additional toggle do members see the switch *Transfer it myself from now on
instead of direct debit* under *My contribution*; without it the payment method
stays with the board (13.4).

Whoever meets both sees "My contribution", even without any role in the app. If
the same person also has a role (say, the treasurer who is a member too), the
tab sits next to the others. A member only ever sees **their own** details there,
never those of other members; the record's internal note stays invisible to the
member.

| Area | The member can | The member cannot |
|---|---|---|
| **My master data** (*Meine Stammdaten*) | change name, email, phone and address (**"Edit"**; the country is chosen from the country list). If the email address changes, the previous address also gets an email about it for safety | change member number or join and leave dates – those stay with the board |
| **My contribution** (*Mein Beitrag*) | raise the monthly fee or lower it down to the minimum, change the interval (only the allowed ones). The **"Preview"** (*Vorschau*) states "takes effect from … · first collection on … · amount …"; only then can they save. If the additional toggle is set (above), also: **switch the payment method from direct debit to bank transfer** (*Transfer it myself from now on instead of direct debit*) – the mandate stays in place and is simply no longer used; the member transfers open and future fees themselves | change the contribution group, skip a month, declare leaving, change their own minimum or see its reason; switch the payment method back to direct debit – the board does that via *Change fee* (13.4) |
| **My SEPA direct-debit mandate** (*Mein SEPA-Lastschriftmandat*) | record a mandate and grant it electronically (**"Mandat jetzt erteilen"**), confirm (**"Jetzt bestätigen"**) or discard an electronic draft you created, **change the bank details**, **change the account holder**, **revoke** the mandate | activate or suspend a mandate – that stays with the board |

The IBAN is always shown to the member masked. **Changing the bank details**
involves the same distinction as in the record (13.2): if only the IBAN changed
(same account, same person), the mandate stays; if the account holder changes, a
new mandate is granted electronically and the old one ends as "replaced".

**The lock window.** Amount and interval no longer change once the
pre-notification (13.5) has been sent for a period – the member was told an
amount. A change that therefore could only take effect later is rejected by the
app with an explanation – the message states the date from which it would be
possible – instead of being silently postponed; the board can make it in the
record (13.4) – for periods already announced, nothing changes anyway.
The **payment method** has none either: if a member switches to bank transfer
after the pre-notification, the announced collection is dropped, the claim stays
open, and the next daily run sends the payment request with GiroCode (13.8). If
the claim is already in a released run, that collection still goes ahead.
The **IBAN** has no lock window: the pre-notification doesn't name an IBAN, and
the run only freezes it at release – so it can be changed until then.

**Revocation.** The dialog shows the same things as in the record: the
revocation is final, the still-open total is named (the member gets a payment
request for it), and the first choice offered is "I only have a new account →
change IBAN". For a **suspended** mandate the IBAN can't be changed; the dialog
then refers the member to the club. A **draft** can be discarded by the member
but not corrected (13.2).

**Receipt and trail.** The app confirms every change to the member by email:
what changed, from when, and which collection is the first one affected – also
when the board changes the fee (13.4; the email then names it as the
originator). The
email is the copy; the area has no event list of its own. In addition, an entry
appears in the member's Nextcloud **Activity** (Nextcloud only sends an email for
it on revocation, unless the member turned that off in their personal
settings). A mandate's history (13.2) shows the route of every change:
*Member* (via "My contribution"), *Club* (via the record, also on one's own) or
*System* (automatic) – for a person wearing both hats, the route counts, not the
person.

Members without a Nextcloud account can't reach "My contribution"; for them the
the board maintains the details in the record (13.2) and under *Contribution
groups* (13.4).

### 13.12 Contribution confirmation

An **informal confirmation** of the contributions paid in a contribution year –
for instance for a member's own records. It is a **print-ready page** (Ctrl+P or
⌘P, also to save as PDF), not a stored document: it is generated afresh from the
current state every time it is opened. **It is not an official donation receipt
under § 10b EStG** and has no tax effect – the page says so explicitly.

**Where.** The member opens it themselves under *My contribution → My
contribution confirmation* (*Meine Beitragsbestätigung*, 13.11): choose the
**contribution year**, **"Open"** (*Öffnen*). The board opens it in the
record (13.2) in the **"Contribution confirmation"** (*Beitragsbestätigung*)
section – the way for members without a Nextcloud account. There is no bulk run
for all members, no sending from the app and no link without signing in. The
choice offers the years with at least one paid contribution and the current one.

**What it contains.** The member's name, address and member number, below it for
each **paid contribution claim** the due period, the description and the
amount, finally the **total**. The following applies:

- **Contribution year, not fiscal year.** The contribution year begins in the
  month an administrator sets under *Contribution year and collection cycle*
  (13.1) – independent of the bookkeeping's fiscal year.
- **By due period, not by payment date.** A contribution for a period of the old
  year that is only paid in the new year counts towards the old year.
- **Paid contributions only.** Fees (such as passed-on return fees), cancelled
  and waived claims never count.

If the member's address is missing, a notice on the screen ("Adresse jetzt
hinterlegen" – "enter the address now") points it out; it doesn't appear in the
printout.

### 13.13 Data protection: data overview and anonymization

The module stores names, contact details and bank details. Two tools help meet
the obligations of the GDPR – both in the record (13.2), both for bookkeepers
and administrators.

**Data overview (information under Art. 15 GDPR).** A print-ready page with
everything stored about a member: master data, SEPA direct-debit mandates (IBAN
masked) including returned debits, claims and contribution assignments. In the
record: section **"Data overview (Art. 15 GDPR)"** (*Datenübersicht (Art. 15
DSGVO)*) → **"Open data overview"** (*Datenübersicht öffnen*); the member
themselves finds it under *My contribution → My data* (*Meine Daten*, 13.11).
Like the contribution confirmation, it is generated afresh on every opening and
isn't stored. The module offers no **structured export** under Art. 20.

**Anonymization (Art. 17 GDPR).** Whatever is an accounting record, the books
may not delete – commercial law requires that. Instead of deleting, the app
therefore blacks out the person and leaves the figures:

- **When.** A member is **due for anonymization** ten years after the end of the
  calendar year of their last related posting (paid claim, posted returned
  debit): a posting in 2026 makes them due from 1 January 2037. The period is
  fixed and not a setting. In addition the member must have **left** and may have
  **no live mandate** any more (draft, active or suspended). Without any posting
  no period runs.
- **Who decides.** The app proposes: the task list (13.9) shows "… due for
  anonymization". A bookkeeper or administrator confirms **member by member**
  with **"Jetzt anonymisieren"** (*Anonymize now*) – after a clear confirmation
  prompt, because it can't be undone. Nothing happens automatically. The
  **"Anonymization (Art. 17 GDPR)"** section of the record states whether and
  from when the member is due.
- **What is blacked out.** The member's name, contact details and internal note;
  IBAN, BIC and account holder of all their mandates, also in the frozen
  collection items of the runs; IP address and browser of the electronic
  consent; the mandate's uploaded proof file, as far as it can be removed; the
  free text of the history – reasons for suspension, deferral, waiver and
  cancellation, the reason for an individual minimum, representation notes and
  the free text of a returned debit. The claims' debtor then reads "Anonymisiertes
  Mitglied" ("anonymized member").
- **What stays.** Amounts, dates, states, return codes and mandate references –
  what the books document. The record is read-only afterwards and carries the
  anonymization note.

A deleted Nextcloud account doesn't trigger the anonymization, and the
anonymization doesn't dissolve the account link: the two are independent (when
an account is deleted, the member stays, the email address is taken over and a
task asks for a check, 13.9).

**Encrypted bank details.** The IBANs of members and payers – on mandates, in
collection items and in bank transactions – and the search values of the
assignment rules are stored encrypted in the database (AES-256-GCM). A database dump or backup that falls into the
wrong hands without the Nextcloud configuration doesn't reveal them. Nothing
changes in the app: whoever may see an IBAN sees it as before. The key is
derived from the `secret` in Nextcloud's `config.php`. Two things follow from
that: whoever controls the server itself can still read the IBANs – encryption
is no substitute for securing the server. And a backup is only complete with
the matching `config.php` (12.3). The club's own IBANs (money accounts,
creditor IBAN) stay unencrypted; they are printed on every mandate form anyway.

---

## 14. Appendix: roles, account types, keyboard shortcuts, glossary

### 14.1 Roles and permissions

| Role | Read | Post/receipts | Operate members, mandates, fees, collection (13.2–13.10) | Fees module settings (13.1), permissions, year-end closing, reset |
|---|:---:|:---:|:---:|:---:|
| Auditor | ✓ | – | – | – |
| Bookkeeper | ✓ | ✓ | ✓ | – |
| Administrator | ✓ | ✓ | ✓ | ✓ |
| Nextcloud admin | ✓ | ✓ | ✓ | ✓ (always) |

Within the **fees module** the boundaries are finer. The "Read" column applies
there to the **collection** only; what goes beyond it is listed here:

| In the fees module | Auditor | Bookkeeper | Administrator |
|---|:---:|:---:|:---:|
| View the collection: timeline, runs, claims, bank reconciliation (IBAN masked) | ✓ | ✓ | ✓ |
| View and edit members, mandates, contribution groups and assignments (the "Members" and "Contribution groups" tabs) | – | ✓ | ✓ |
| Release, submit, discard, reschedule, edit claims, judge and post in the bank reconciliation, anonymize | – | ✓ | ✓ |
| Change collection days in the schedule (rescheduling) | – | ✓ | ✓ |
| Tasks in the header (13.9) | – | ✓ | ✓ |
| Warning window and pre-notification lead time, all settings (13.1), mandate legal text | – | – | ✓ |
| "My contribution" (13.11) | independent of the role: the account link and the administration's toggle decide | | |

### 14.2 Account types and what they mean

| Type | Meaning | Nature | Cumulative? |
|---|---|---|---|
| Income | earnings (membership fees, donations) | credit | no (year-specific) |
| Expenses | expenditures (rent, insurance) | debit | no (year-specific) |
| Fixed/current asset | assets (other than bank/cash) | debit | no |
| Liability | debts | credit | no |
| Equity | equity / reserves | credit | – |
| Bank account (flag) | cash account (checking, savings, cash box) | debit | **yes** (account balance) |

"Cumulative" means: the account carries its balance across the year
boundary and shows the real account balance, not just the year's movement.
This only applies to cash accounts (bank flag).

### 14.3 Keyboard shortcuts (desktop)

- **N** – create a new posting
- **/** – focus search (the account-tree search in the Accounts tab,
  otherwise the posting search)
- **Esc** – closes the topmost open dialog, even from within an input field

### 14.4 Glossary

- **Debit / credit** – the two sides of a posting ("where to" / "where
  from").
- **Counter-account** – the account a bank transaction is assigned to (the
  "other side" next to the bank account).
- **Fiscal year / period** – the named accounting period with a from and to
  date, to which all reports refer. The default is the calendar year, but
  October–September, the school year or a semester work too (chapter 8.1). In
  the header the selection field is called "Period".
- **Posting number** – a continuous number per posting, restarting at 1
  every calendar year. Important for the gap check. As long as a year is
  still open, the numbers are provisional: if a posting is deleted, the
  following numbers automatically shift down so no gap remains. With the
  year-end closing they become final and no longer change.
- **Opening balance** – the starting balance of an account (e.g. the
  account balance as of 01/01).
- **Funds** – the balances of all cash accounts added up, the figure at the
  top right of the header. Individual accounts can be excluded from it
  (chapter 2.2); this has no effect on the cash report, the assets overview
  or the trial balance.
- **Reporting group** – a grouping (department, project), reported separately.
- **Finalization** – a closed, immutable fiscal year.
- **Short fiscal year** – a shortened fiscal year that arises when switching
  from one fiscal year to another (chapter 8.1).
- **Snapshot (plan snapshot)** – a frozen state of the financial plan at a
  point in time (e.g. "resolved at the general assembly").
- **Log (audit log)** – tamper-proof chronicle of all changes.
- **Open item** – a receivable not yet paid (e.g. a fee, an invoice) with a
  due date; not a posting, but a memo list until payment.
- **Reserve** – club funds set aside (free, earmarked, or for replacement),
  kept as a flagged equity account.
- **CSV-CAMT / CAMT.053 / MT940** – the three formats banks offer a
  statement for download in. CAMT.053 (an XML file) is the most explicit
  and therefore the best choice; CSV is the most common, but every bank
  names its columns differently. See chapter 3.2.
- **Watch folder** – a Nextcloud folder from which the app reads in
  deposited bank statements on its own (chapter 3.3). It doesn't fetch
  anything from the bank; downloading remains manual work.
- **Pending transaction** – a payment shown by the bank but not yet posted
  for good. The app skips such transactions because the amount or text can
  still change before the final posting.
- **Split posting** – a posting whose amount is spread across several
  counter-accounts: a transfer covering a fee *and* a donation, an invoice
  split across two projects. Debit and credit stay equal in total, only one
  side has several rows (chapters 4.1 and 4.2).
- **Inactive account** – an account removed from all selection lists, whose
  posted amounts and history remain unchanged. The way to deal with
  accounts no longer needed but that can't be deleted because of existing
  postings (chapter 2.2).
- **Member** – a person or organization in the fees module's member list,
  also without a Nextcloud account (chapter 13.2).
- **Mandate** – a member's direct-debit authorization with IBAN, account holder
  and signature (paper or electronic); states *draft*, *active*, *suspended*,
  *ended*. Revoked, not deleted (chapter 13.2).
- **Mandate reference** – the identifier of a mandate (e.g. `M-17`) that appears
  on the payer's bank statement (chapter 13.2).
- **Contribution group / assignment** – the group carries the rules (minimum,
  allowed intervals), the assignment links a member to it: monthly fee,
  interval, payment method, validity (chapter 13.4).
- **Contribution year** – the frame of the contribution periods; begins in a
  configurable month and is independent of the fiscal year (chapter 13.1).
- **Interval** – the distance between two collections in months (1, 2, 3, 4, 6
  or 12; chapter 13.4).
- **Claim** – a contribution or fee a member owes; technically an open item with
  a member. The app derives its state (open, in collection, collected, returned,
  settled, cancelled) (chapter 13.8).
- **Pre-notification** – the email with which the app announces the collection;
  from its dispatch the period's amount is locked (chapter 13.5).
- **Run** – the collection for one date: items frozen at release together with
  the pain.008 file (chapter 13.5).
- **Returned debit** – a direct debit returned by the bank with a return reason;
  the app recognizes it in the statement and posts it after your judgement
  (chapters 13.7, 13.10).
- **GiroCode** – a QR code (EPC QR) in the attachment of the dunning emails that
  the banking app scans to prefill the transfer (chapter 13.8).
- **Anonymization** – blacking out a member's personal details after the
  retention period; amounts and dates stay (chapter 13.13).

---

## 15. Writing invoices (optional)

With the invoice module the association issues invoices in its own name – for hall rental, course fees, sponsoring or an anniversary booklet. Every finalized invoice is an **open item** in the app and is assigned to its payment automatically when the bank statement is imported. The module is optional and appears as the **Invoices** tab once it is turned on.

> **What it is not:** Membership fees stay in the membership module (chapter 13). A genuine membership fee is not a supply for consideration in VAT terms; an "invoice" for it is usually not needed.

### 15.1 Turning it on and the association's details

*Nextcloud settings → Vereinsbuchhaltung → Invoices* (administrators only):

1. **Turn on the invoice module.**
2. Enter the **details of the association**. Section 14 (4) UStG requires the name and address and the **tax number** (from the tax office) or the VAT ID. Register, board, phone and email additionally appear in the footer of the invoice. You maintain the name of the association further up under "Club".
3. **Tax and payment:** Most small associations are **small businesses** (section 19 UStG) and do not show value added tax; the invoice then carries the required notice. Whether this applies to your association is determined by the tax office or your tax adviser. Also choose the **account for payment details and GiroCode** (a cash account with an IBAN), the payment term and, if you like, default texts.
4. **Invoice numbers:** The default is `RE-{JJJJ}-{NNNN}` (e.g. `RE-2026-0042`), restarting at 1 every year; credit notes are counted separately (`ST-…`). Numbers are consecutive and unique – change the pattern only before you write the first invoice.

As long as mandatory details are missing, no invoice can be finalized; the app tells you what is missing.

### 15.2 Recipients

When writing an invoice you choose the recipient from the recipients already created or from the **members** (the address comes from the member record); **New** creates a recipient, optionally with an email address for sending and – for authorities – the **Leitweg ID**. The address is copied into the invoice when it is finalized: later changes to the recipient never change a finished invoice.

### 15.3 Writing an invoice

**Invoices → New invoice.** The draft saves itself shortly after every change.

- **Invoice details:** invoice date, payment term and the **time of service** – a date or a period; either is mandatory.
- **Line items:** description, quantity, unit, unit price, optionally a discount and a description. Every line needs a **revenue account** (an income account); the payment is booked to it later. Use the arrows to reorder. The total updates as you type.
- **Tax and texts:** For small businesses the notice under section 19 UStG is added to the invoice automatically. If you are tax-exempt, enter the exemption notice (e.g. "Tax-exempt under section 4 no. 22 UStG"). Introduction, closing text and payment terms are free.
- **Preview** shows the PDF with a "DRAFT" watermark.

Accountants may create drafts; **finalizing, sending and cancelling** are reserved for administrators.

### 15.4 Finalizing

**Finalize …** checks the mandatory details (address and tax number of the association, recipient, time of service, line items, amount). Under **Please note** the editor lists hints that do not prevent finalizing: a line on an account of the non-material sphere (**donations and genuine membership fees are not invoiced** – use the donation receipt or the contributions module instead), a registered association without "e. V." in its name, and a missing bank account. Optionally the invoice prints a **note on non-profit status** (Settings → Invoices → Club details); it does not replace a VAT notice. After that:

- the invoice receives its **number** and is **immutable** – it can only be corrected by cancelling it;
- the **PDF** is created (PDF/A-3b, with a GiroCode to scan in the banking app) and stored as a file in the folder of the receipt storage under `Vereinsbuchhaltung/Rechnungen/<year>/`; the checksum (SHA-256) later shows whether the file was changed;
- the **open item** is created with the invoice number as its reference.

**Nothing is booked yet**: the app works on a cash basis, booking happens when the money arrives.

### 15.5 Sending

In the invoice details: **Send by email**. Recipient address, subject and text are prefilled and can be changed before sending; the PDF is attached. You maintain the template and its placeholders (`{nummer}`, `{betrag}`, `{faellig}`, `{verein}`, `{empfaenger}`) in the settings. Alternatively download the PDF and send or print it yourself.

**Copy to board:** Enter the board's email address in the settings under **Invoices → Emails and templates**. The send dialog then shows a **Copy to board** switch; when on, the email also goes as a blind copy to that address, and the recipient does not see it. Without an address the switch does not appear.

### 15.6 Assigning a payment

- **On import:** If the invoice number appears in the payment reference (also as `RE 2026 0042` or `RE20260042`) and the amount matches exactly, the import books the payment **on its own**: cash account to revenue account, with the invoice number as document reference. You can turn this off in the settings.
- **In Entries → To assign:** For everything else an incoming payment shows a **"✓ Invoice RE-…"** chip – number in the text, or amount and name match, or only the amount with exactly one open invoice. One click books it. If the amount is lower the chip reads "Partial payment on …" and the invoice stays open; if it is higher, split the transaction ("Split…") and assign the rest to another account.
- **From the invoice:** **Record payment** assigns a transaction or books a **cash payment** (cash account/cash box to revenue accounts).
- **Partial payments** are spread proportionally over the revenue accounts of the line items; with the last payment the totals per account match exactly.
- **Reverting:** If a payment's assignment is removed or its booking is deleted, the invoice opens again. In the details you can also do it with the arrow next to the payment.

### 15.7 Cancelling and duplicating

**Cancel …** (administrator) creates a **credit note** with its own number that reverses all line items and refers to the invoice; the invoice counts as cancelled. A reason is required. If it was already paid, the booking stays: you assign the refund to the revenue account like any other outgoing payment, the amount is shown in the credit note.

**Duplicate** copies an invoice as a new draft – handy for recurring services.

### 15.8 Retention

Invoices have to be kept for **eight years**. The PDFs are files in Nextcloud; include them in your backup. A finalized invoice can no longer be changed in the app; the checksum in the document list shows if the file was changed outside the app.

### 15.9 Standard taxation (showing VAT)

If the association is not a small business (or has waived the small-business rule), switch the settings under **Tax** to **Standard taxation** and define:

- **VAT accounts** for 19 % and 7 % (liability accounts, for instance 1776 and 1771 in SKR 42). Without them an invoice with tax cannot be finalized.
- **Default rates** by sphere of the revenue account: ideal-purpose operation 7 % or 0 %, commercial operation 19 %. The rate is only a suggestion.

In the editor every line item then has a tax choice: **19 %**, **7 %**, **0 %** or **tax-exempt** (with exemption notice). The invoice shows the totals per tax rate; tax is calculated once per rate from the net total and rounded commercially, as the e-invoice requires. When a payment arrives, the amount is split proportionally over the **revenue accounts and the VAT accounts**; with the last payment all accounts match to the cent. A credit note reverses net, tax and gross.

The change only applies to **new** invoices. Finalized invoices keep their tax mode.

> **Note:** Whether and at which rate a supply is taxable depends on the sphere and the individual case. The tax office or your tax adviser decides that; the app only calculates with what you set.

### 15.10 E-invoice (ZUGFeRD and XRechnung)

Since 2025 businesses must be able to **receive** e-invoices; **issuing** them is becoming mandatory step by step for supplies between businesses (from 2027 above EUR 800,000 prior-year turnover, from 2028 for everyone). Small businesses and invoices to private persons are exempt (section 34a UStDV). If your association invoices businesses or public authorities, the app can create the matching e-invoice right away. A finalized invoice offers three formats in the **Documents** section:

| Format | File | For whom |
| --- | --- | --- |
| **ZUGFeRD PDF (EN 16931)** | PDF with embedded XML; an ordinary PDF to a human | Businesses that read invoices automatically |
| **XRechnung (CII XML)** | plain XML file | Authorities and municipalities |
| **XRechnung (UBL XML)** | plain XML file | Recipients that require UBL (for example via Peppol) |

- **Suggestion by recipient:** authorities → XRechnung, businesses and associations → ZUGFeRD, private persons → none. With *Create the e-invoice when issuing* ticked in the settings (default), the suggested format is created when you finalize, provided all details are there.
- **Mandatory details:** ZUGFeRD needs a complete address of the recipient. **XRechnung** additionally needs the **Leitweg ID** (on the recipient) or a buyer reference (on the invoice), phone number and email address of the association, the recipient's email address and the account with IBAN. The *Create e-invoice* choice lists what is missing before you create.
- **Checked and unchangeable:** every XML is checked against the schema of its format before it is stored; an invalid document is not saved. Like the PDF it is stored with a checksum in the receipt storage, one document per invoice and format (the XML is the original, section 14 UStG). The development team additionally checks the app's sample invoices in CI with the official KoSIT validator against the EN 16931 and XRechnung 3.0 rules.
- **Sending:** the send dialog can attach existing e-invoices. A ZUGFeRD PDF replaces the plain PDF, XRechnung XML is attached in addition. Authorities usually receive XRechnungen through a portal; download the XML file for that.
- **Credit note:** it is created as a credit note document (type 381) referring to the original invoice.

### 15.11 Line item templates

*Nextcloud settings → Vereinsbuchhaltung → Invoices → Line item templates* (administrator): create recurring services such as "Hall rental" or "Course fee" once, with description, unit, price, revenue account and tax. In the invoice editor, **Insert from template …** below the line items adds the chosen template as a **copy**; price, quantity and text stay editable afterwards, and later changes to the template do not affect existing invoices. Bookkeepers can use the templates but not change them.

### 15.12 Recurring invoices

For services at a fixed interval (hall rental, sponsoring, annual fee) a **plan** creates the invoices by itself. A plan is created where you otherwise write an invoice: **Invoices → New invoice**, then choose **Recurring invoice** under **Type** at the top (administrator only). The same editor shows the **Repetition** instead of the invoice data; you enter recipient, line items (also from templates), tax and texts as for an invoice. Alternatively **Recurring …** in the details of an existing invoice takes over recipient, line items and texts as a template for the plan.

Under **Repetition** you choose:

- **Interval:** monthly, quarterly, half-yearly or yearly, with **first invoice on** and an optional **end**. Dates are always counted from the start; the 31st becomes the 28th or 29th in February but goes back to the 31st afterwards.
- **Service period:** the period of the interval that starts with the invoice (for a monthly invoice on 1 June that is 1 to 30 June), or service date equal to invoice date.
- **Payment term** in days (empty = default from the settings).
- **Finalizing and sending:** Without the tick the plan creates **drafts** that you check and finalize. With **Finalize the invoice right away** the plan finalizes, creates the PDF and e-invoice and can **send the invoice by email**. If details are missing when finalizing, the draft stays and the plan states the reason in its overview.

In names and texts the run replaces `{monat}`, `{jahr}`, `{quartal}` and `{zeitraum}`: "Hall rental {monat} {jahr}" becomes "Hall rental June 2027".

All plans are in the **Recurring** tab of the invoice list: interval, next date, last run with a link to the invoice, errors. **Edit** opens the plan in the same editor – the line items can be changed too. Plans can also be **paused** and **deleted** (invoices already created remain). A paused or newly created plan **does not catch up on past dates**; it starts with the next date from today. The Nextcloud background job checks hourly; **Run due plans now** triggers it by hand.

### 15.13 Dunning

Invoices that are unpaid after the payment term are dunned in **three stages**: **payment reminder**, **1st dunning letter** and **2nd dunning letter** (final). Every stage is a **dunning letter as a PDF** with the open invoice and a **GiroCode for the remaining amount**, sent by email and stored unchangeably like the invoice.

- **Deadlines:** the reminder is due 7 days after the payment term, the 1st dunning letter 14 days after the reminder, the 2nd 14 days after the 1st. You set all deadlines and the payment deadline in the letter (7 days) under **Dunning** in the settings (subject and text per stage under **Emails and templates**). While a field is empty the default text shown in grey applies; **Use default text** copies it into the field so you can edit it (the same goes for the invoice email template). Placeholders: `{stufe}`, `{nummer}`, `{rechnungsdatum}`, `{betrag}`, `{rest}`, `{faellig}`, `{frist}`, `{verein}`, `{empfaenger}`.
- **In the list** an invoice shows its **dunning stage** and **Dunning due**; the filter **Dunning due** collects them.
- **Dun** (administrator): in the details click **Dun …**. The dialog suggests the next stage, address, subject and text; everything can be changed before sending. A stage cannot be repeated but can be skipped. The history is in the **Reminders and dunning letters** section, the PDFs are with the documents.
- **Automatic:** With **Send due dunning letters automatically by email** (off until you turn it on) the daily background job sends due stages by itself. Invoices whose recipient has no email address are skipped; they stay in the list as "Dunning due".
- **Copy to the board:** With **Mail copy to board** (off until you turn it on; needs the board's address right above it, both under **Emails and templates**) every dunning email also goes as a blind copy to the board, in the automatic run too. When dunning by hand, the **Copy to board** switch in the dialog can be changed per reminder.
- **Locks:** paid, cancelled and not yet due invoices, credit notes and drafts are never dunned. Partial payments reduce the amount in the letter. After the 2nd dunning letter the app stops; the association decides how to proceed (court order, waiver).

The app does not calculate dunning fees or default interest and does not give legal advice: texts and deadlines should suit your association.

### 15.14 Payment reference (RF) and audit

- **RF reference (ISO 11649):** On request (setting, on by default) the invoice prints a **structured payment reference** such as `RF18 RE20 2600 42` next to the payment reference. It contains the invoice number and two check digits and is part of the **GiroCode**. The bank matching recognizes it as the most reliable feature: if the reference is in the payment reference field and the amount matches, the import books by itself, even if the payer inserted spaces.
- **Audit:** with the invoice module on, the receipts ZIP (button "Beleg-ZIP" in *Reports → Evaluation*, chapter 9.2) contains the folder `rechnungen/`: one subfolder per invoice with all documents (PDF, ZUGFeRD PDF, XRechnung XML, dunning letters), plus `uebersicht.csv` (number, date, recipient, net, tax, gross, paid, open, status, dunning stage) and `pruefsummen.txt` with the SHA-256 of every file and the finding `ok`, `changed` or `missing`. The invoices of a period are chosen by invoice date.

---

*As of app version 0.35.0. For questions, contact your administrator.*
