# Vereinsbuchhaltung Manual

[Deutsch](HANDBUCH.md) · **English**

A practical manual for treasurers – from initial setup to the year-end
closing. It describes app version **0.22.2** and follows the actual annual
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
  year, delete-all-data.
- **Bookkeeper** – reads and writes postings, receipts, assignments, as
  well as members, SEPA mandates and fee collection (chapters 13.2–13.7).
- **Auditor** – read-only (for the annual audit).

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
  account an imported transaction is posted on – without it, everything
  ends up on the first bank account in the chart of accounts. Spaces don't
  matter, the app stores it consistently. Removing the bank-account flag
  again also removes the IBAN,
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

> **Several bank accounts?** Then it also matters that the statement carries
> the account's **IBAN** – that's the only way the app can tell which cash
> account to post on (chapter 2.2). CAMT.053 always carries it, MT940 often
> only the account number, and CSV depending on the bank. When in doubt, use
> CAMT.053.

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

**"+ Posting"** button (top right, or on mobile the large "+" button).

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
max. 20 MB per file). Four ways:

- **When creating:** in the *New posting* dialog under *Receipts* via
  "attach" – on mobile also via "photograph" straight from the camera. The
  files are uploaded as soon as the posting is saved.
- **Afterwards:** open the posting (pencil icon) → *Receipts* section →
  "attach".
- **From the watch folder** (if set up, chapter 2.4): "Pick from folder"
  lists the files of the folder, newest first, with a search across file
  name and subfolder and a preview before assigning. By default only the
  ones not yet assigned; the same file may, however, be attached to several
  postings (e.g. a split invoice).
- **Several files** at once are possible.

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

### 4.5 Open items (unpaid receivables)

**Bookings → Open items** tab. A lean list for receivables that haven't
been paid yet – e.g. an outstanding membership fee or an invoice sent.
**Important: this is not member management** – the debtor (name of the
person or entity owing payment) is entered as free text, there's no member
master data behind it.

A new item needs: **debtor**, **amount**, optionally a **due date** and an
**account** (for the later posting). If the due date has passed, the app
marks the item as "overdue" – the dashboard then also shows an "overdue
open items" tile with a direct link to the list.

Once the money has arrived, the item is manually **marked as paid**
("Paid" button). The actual incoming payment is imported and assigned as a
bank transaction as usual (chapter 4.1) or posted manually (chapter 4.2) –
the app currently does **not automatically** reconcile open items against
bank transactions. An item can also be **cancelled** (resolved another way,
e.g. a fee waiver) or, if needed, **reopened**.

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
- **Plan/actual comparison** (financial plan, including notes)
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
- **plan/actual comparison**, if plan figures exist
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

- **Membership fees, open items and SEPA** (chapter 13) still calculate
  from the **member's start date**, not from the beginning of the fiscal
  year. "Annually" therefore means twelve months from that date – whoever
  joins on 15 March keeps paying every 15 March.
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
confirmation dialog) removes accounts, postings, imports, receipts and the
year-end closing markers. **The change log is kept.** The same applies to
reset mode during the xbuc import. Both are irreversible – so only after
checking with others, and never by accident.

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

---

## 13. Membership fees and SEPA direct debit

An **optional add-on module**. Anyone who receives fees by bank transfer or
doesn't collect any at all can skip this chapter – without a mandate set
up, the app behaves exactly as before.

Members, mandates, fees and collection (13.2–13.7) may be maintained by
**administrators and bookkeepers** – a mandate does link a person to their
bank details, but that's no bigger a responsibility than any other posting.
The **basic settings** (13.1: creditor ID, collecting account, default fee,
the toggle for the tab) remain reserved for administrators – those are
one-time decisions for the whole club, not ongoing work.

### 13.1 What you need beforehand

1. A **creditor identification number**. Issued free of charge by the
   Deutsche Bundesbank on request; it looks like `DE98ZZZ09999999999` and
   identifies your club as the payee on every collection.
2. A **written mandate per member**. The app manages the details, but does
   not replace the signed direct-debit authorization – that belongs in your
   own records.
3. A **cash account with an IBAN on file** in the account list. This is the
   account collections are made into.

Enter the creditor ID and the collecting account under *gear icon →
Contributions & SEPA → basic settings*. That's also where the toggle is
that shows the **"Contributions"** tab in the main navigation (see 13.2) –
if a mandate or a fee already exists, it appears automatically, even
without the toggle.

> **Do almost all members pay the same fee** (e.g. €8 monthly, the normal
> case for a choir or sports club)? Then the **"default fee"** card on the
> same page is worth using: enter the amount and frequency once, and "add
> member" (13.2) will suggest both from then on, instead of you typing them
> in again for every single member. The default also applies during the
> CSV import (13.3) when a row has a start date but no amount of its own –
> deviating individual cases (reduced fee, honorary member) simply get
> their own amount entered.

### 13.2 Where members' bank details live

**There is no member-management system in this app.** That's intentional:
an accounting app is not a member database, and most clubs keep their
members elsewhere anyway. What the app needs is only what belongs to the
money.

A "member" here therefore consists of two pieces of information, both of
which live in the **"Contributions" tab → Members** (main navigation, not
the gear icon – that's ongoing work, not a setting):

| Field | What goes there |
|---|---|
| **Mandate** | IBAN, optionally BIC, email address and the date the mandate was signed |
| **Fee** | amount, payment frequency and the first due date |

**The IBAN lives on the mandate, not on the member** – a member without a
mandate simply has no bank details in the app. Both are created in one step
via the **"+ Member"** button ("Contributions" tab → Members); each is also
possible on its own:

- **fee only, no IBAN** – for members paying by transfer or cash. The app
  still creates an open item when it's due, then simply as a reminder.
- **mandate only, no amount** – if you only collect something occasionally.

As the payer, choose either a **Nextcloud user** of this instance or enter
a **free-text name**. The latter is the normal case: hardly any club sets
up a Nextcloud account for every member.

> **Enter the email address.** Without it, the app cannot send the legally
> required pre-notification, and you'll have to notify every member
> yourself. The list flags every row without an address; via *only flagged
> items* you see them all at once.

The **mandate reference** is assigned by the app itself (e.g.
`M20260813-2DE3C1`). It appears on the payer's bank statement – share it
with them together with the mandate form.

> A mandate is **revoked, not deleted**. Batches already generated
> reference it, and that record has to be kept. That's why the delete
> button disappears once a mandate has been used.

### 13.3 Adding many members at once

For a choir with 200 voices, the form is the wrong way. In the
"Contributions" tab → Members, use the **"Import list"** button: a **CSV
file**, one row per member.

The following columns are expected – **order and spelling don't matter**,
and extra columns (member number, join date, voice part …) are simply
ignored:

| Column | Example | Required? |
|---|---|---|
| Name *or* account | `Katrin Brunner` or `k.brunner` | yes |
| Email | `k.brunner@example.org` | no, but strongly recommended |
| IBAN | `DE02 1203 0000 0000 2020 51` | only if collections are made |
| BIC | usually empty | no |
| Mandate on | `15.01.2026` | yes, as soon as there's an IBAN |
| Amount | `42.50` | only if a fee should be created |
| Frequency | `monthly` | no – **yearly** applies if not specified |
| Start | `01.02.2026` | yes, as soon as there's an amount |

Dates may be given as `15.01.2026` or `2026-01-15`, amounts as `42,50` or
`42.50`. A **template** to fill in can be downloaded directly.

German column headings are understood just as well (`Name`, `E-Mail`, `IBAN`,
`BIC`, `Mandat`, `Betrag`, `Frequenz`, `Start`) – useful when the list comes
out of a German program. The same goes for the frequency: `monatlich`,
`vierteljährlich`, `halbjährlich` and `jährlich` work alongside the English
words.

> **Default fee set (13.1)?** Then the amount column may stay empty for
> rows on the same rate – as long as a start date is present, the app
> automatically takes over amount and frequency from the settings. Only
> special cases then need their own amount.

The process is two-stage: **"Check"** changes nothing and shows you, for
every row, what would be created and what's wrong. Only afterwards do you
apply it. Faulty rows are skipped and listed individually – a typo in row
143 doesn't invalidate the 142 rows before it.

Among other things, the check flags: a payer already listed further up in
the same file (usually a copied row), an IBAN that already has an active
mandate, and a Nextcloud account that doesn't exist.

### 13.4 Fees, due dates and backlog

When due, the app automatically creates an **open item** – the same one you
see under *Reports → Open items*. Only items *with* a mandate are eligible
for direct-debit collection.

If the next due date is in the past – for example because you created a fee
retroactively – the daily job catches up **one period per day**, instead of
creating two years' worth of receivables at once. How much is still
outstanding is shown in the *Next due date* column; via **"Catch up"** you
create the entire backlog immediately.

If you simply got the start date wrong, correct the next due date via
*Edit* instead of catching up.

### 13.5 Generating and submitting a collection

In the **"Contributions" → Collection** tab you choose the **due date**.
The preview shows all open items with an active mandate that are due by
then and aren't part of a running collection.

> **Set the date at least 14 days in the future.** The SEPA rulebook
> requires you to inform the payer of the amount and date beforehand
> ("pre-notification"). The app handles this by email for every payer with
> an address on file, stating the amount, date, mandate reference and your
> creditor ID. It warns you if the lead time is shorter. You have to inform
> payers without an address yourself – the collection's row view flags
> this.

"Generate collection" creates the batch; via "Download XML" you get the
**pain.008 file**, which you upload in your bank's online banking.

> **Before the first real collection**, test the file against your bank's
> validation tool. The exact format varies slightly by institution.

An accidentally generated collection can be dissolved again via
**"Discard"**, as long as no returned direct debit has come in; the items
it contained become available again. If the file was already submitted,
discarding naturally doesn't change that – then you have to recall the
collection at the bank.

### 13.6 When the money has arrived

As soon as the batch credit is on your club's account, click "Post as
executed" on the collection. This closes all open items it contains as
paid in one step – with 80 members, that's one click instead of eighty.

Returned items stay explicitly open in the process: that money hasn't
arrived. An executed collection can no longer be discarded.

You assign the bank posting of the batch credit to an account like any
other posting.

### 13.7 Returned direct debits

If a collection comes back (account not covered, mandate disputed), the app
detects this on the next **bank-statement import** and reopens the
associated open item. You assign the returned bank transaction itself to an
account like any other – typically bad debts and bank fees.

The detection works with whatever the bank supplies in the purpose text,
and occasionally gets it wrong. In the collection's row view, you can
undo a wrongly detected return via **"Undo return"**.

A return often only arrives **after** you've already posted the collection
as executed. Even then it's detected: the affected item is reopened, and
the mandate is again treated as not yet redeemed – so the next attempt runs
again as a first-time collection.

---

## 14. Appendix: roles, account types, keyboard shortcuts, glossary

### 14.1 Roles and permissions

| Role | Read | Post/receipts | Members/SEPA collection (13.2–13.7) | Fee basic settings (13.1), permissions, year-end closing, reset |
|---|:---:|:---:|:---:|:---:|
| Auditor | ✓ | – | – | – |
| Bookkeeper | ✓ | ✓ | ✓ | – |
| Administrator | ✓ | ✓ | ✓ | ✓ |
| Nextcloud admin | ✓ | ✓ | ✓ | ✓ (always) |

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

### 14.4 Glossary

- **Debit / credit** – the two sides of a posting ("where to" / "where
  from").
- **Counter-account** – the account a bank transaction is assigned to (the
  "other side" next to the bank account).
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

---

*As of app version 0.22.2. For questions, contact your administrator.*
