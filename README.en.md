# Vereinsbuchhaltung – Nextcloud App

[Deutsch](README.md) · **English**

A lightweight accounting app for nonprofit clubs, integrated directly into Nextcloud. Chart of accounts and postings can be imported from a **"zero Buchhaltung" `.xbuc` file**, bank transactions arrive as **CSV-CAMT, CAMT.053 or MT940** from your bank – either uploaded manually or fully automatically from a watched Nextcloud folder. The app follows the rules of **double-entry bookkeeping** (debit/credit) with a freely definable chart of accounts. Optionally it can collect membership fees via **SEPA direct debit** – from a CSV bulk import for 80–100 members to a ready-made pain.008 XML file for the bank.

## 🎬 The app in two minutes

[![Vereinsbuchhaltung app for Nextcloud – watch video](https://img.youtube.com/vi/eaF-tAQ_OOM/maxresdefault.jpg)](https://youtu.be/eaF-tAQ_OOM)

**[Vereinsbuchhaltung app for Nextcloud](https://youtu.be/eaF-tAQ_OOM)** (German narration) – import bank statements, assign transactions, evaluate and produce the annual treasurer's report.

> 📖 **Beginner's guide:** The included **[HANDBUCH.md](HANDBUCH.md)** ([English: HANDBUCH.en.md](HANDBUCH.en.md)) walks treasurers step by step through the app – from initial setup through day-to-day bookkeeping to the year-end closing and the annual audit.

## Feature overview

### Getting started & help
- **Setup wizard** on first start (no accounts yet): three options to choose from – take over an xbuc file, create the standard chart of accounts, or try it out with sample data first
- **Sample club** (administrator): a complete dataset to try everything out risk-free, with a banner and a reset button; "delete all data" turns it back into an empty set of books
- **Setup checklist** on the dashboard: open steps (name the club, chart of accounts, opening balance, permissions, first posting, spheres) with direct links, can be hidden
- **In-app help**: the help button in the header opens the matching chapter for the active tab; from there a link leads to the included manual, which the app itself serves as a readable page (`/api/help/handbuch`) – in English once the user's Nextcloud language is set to English, otherwise in German
- **First-posting tour**: a one-time three-step highlight of the fields in the posting dialog (desktop, simple mode)
- **Welcome notice for auditors** on first login with that role, including a printable audit guide

### Import
- **xbuc import** (zero Buchhaltung): takes over the account tree and all postings from a `.xbuc` file
  - **Merge mode** (default): only missing accounts are created, postings already present are skipped via fingerprint – several yearly files can be imported one after another
  - **Fiscal year check**: year taken from the file or chosen manually; postings outside the fiscal year are reported and can be dated automatically to 01/01 or 12/31
  - **Opening balances**: recognized and skipped during a multi-year import when they are already covered by prior-year postings (with a deviation warning)
  - Postings without a counter-account end up as open bank transactions in the "to assign" tab
  - Reset mode (administrators only): deletes all data beforehand
- **Bank statements** in three formats – the format is detected from the **content**, the file extension doesn't matter:
  - **CSV-CAMT** (Sparkasse, Volksbank/VR-NetWorld, …): automatic detection of delimiter and character set, German number and date format
  - **CAMT.053 (XML)**: the ISO 20022 standard format – sign, date and payment parties are explicitly tagged instead of guessed; pending transactions (`PDNG`) are skipped, batch postings stay a single line with an item-count note
  - **MT940** (SWIFT, often as `.sta`): multi-part purpose lines and names are assembled, reversals (`RC`/`RD`) flip the direction
  - **Duplicate detection** via SHA-256 hash, additionally against postings already imported via xbuc and – across formats – against existing bank transactions by date/amount/text (even with a differing value date). The same statement can therefore be safely re-imported in a different format
  - Zero-amount postings (e.g. ABSCHLUSS) are skipped; bank-internal postings without a payment party (ENTGELTABSCHLUSS …) remain bookable
  - Import directly in the "Bookings" tab with drag & drop, preview and a success summary
- **Watch folder** (gear icon → *Bank data*): just drop the downloaded statement into a Nextcloud folder – an hourly background job reads it, moves it to `verarbeitet/`, and moves faulty files together with a reason to `fehler/`. Nothing is deleted. Requires system cron

### Bookkeeping
- **Double-entry bookkeeping**: postings with debit/credit accounts and a continuous posting number (restarting at 1 each calendar year)
- **Chart of accounts** freely maintainable with hierarchy (parent/sub-accounts), account types, bank-account flag and opening balance
- **Posting dialog with simple mode** (income/expense + category + cash account) and expert mode (debit/credit directly)
- **Split postings**: one amount across several counter-accounts – in the posting dialog via *Split amount*, and when assigning a bank transaction via *Split…*. The cash account stays a single line for the full amount; the other side is split, a remainder display shows what's still missing, and saving is only possible once the split adds up. All reports calculate line by line and show the split amounts separately
- **Deactivate accounts**: accounts with postings can't be deleted, but can be deactivated – they disappear from selection lists while amounts and history remain; they stay visibly marked in the account tree
- **Multiple bank accounts**: an IBAN can be stored on a cash account; when assigning, the app then picks the cash account the transaction was actually posted on (without an IBAN match, the first bank account, as before)
- **Assign bank transactions**: every imported bank transaction gets assigned a counter-account, which automatically creates a posting
  - **Assignment suggestions** from rules and past assignment history, applicable with one click
  - **Auto-assignment rules** (payment partner / purpose text / IBAN contains search text → counter-account): manageable in the "Rules" sub-tab (Bookings tab), or via a lightning-bolt button directly from a posted bank transaction
- **Attachments** (PDF/images, max. 20 MB) on postings – stored internally (AppData) or in a configurable Nextcloud folder; while creating the posting or afterwards, on mobile photographed straight from the camera
- **Open items** (Bookings tab → Open items): a lean ad-hoc list of unpaid receivables (e.g. membership fees, invoices) with debtor, amount, due date and optional account; status open/paid/cancelled, dashboard notice for overdue items – deliberately not a full member-management system
- **Reserves** (§ 62 AO: free / earmarked / replacement reserve): equity accounts can be flagged accordingly, own report with balance by type; allocations are normal postings (expert mode)
- **Year filter**: all reports relate to the calendar year chosen in the header; balance-sheet accounts cumulative, income/expense accounts year-specific
- **Year-end closing (finalization)**: administrators close a fiscal year – postings, attachments and assignments for that year become immutable afterwards (write attempts return HTTP 423); reopening only by administrators, both actions are logged

### Reports & export
- **Overview (dashboard)**: KPI tiles with year-over-year comparison, a notice about unassigned postings and overdue open items, monthly income/expense chart
- **Trial balance**: all accounts with debit/credit/balance, hierarchical display, optionally including sub-accounts
- **Account statement**: posting history per account including running balance and carried-forward balance; wrongly assigned postings can be **rebooked** to a different account right there (either side of the posting, only the account assignment changes, logged, locked in closed years)
- **Reporting groups**: income/expenses/result per reporting group with posting drill-down; three modes (2nd digit group of the account number, per account, or **freely defined reporting groups** with explicit account assignment individually or via multi-select), names editable in the UI
- **Tax spheres** (ideational sphere, asset management, purpose-related business, commercial business): assignable per account (individually or via multi-select in the "Spheres" report), own report with income/expenses/result per sphere; dashboard warning banner when approaching the exemption threshold for commercial business (§ 64 (3) AO, German tax code) – does not replace tax advice
- **Financial plan**: planned amounts per account and year, plan/actual comparison with color-coded deviation
  - **Notes per plan figure** (e.g. rationale "40 members × €25")
  - **Plan snapshots**: freeze the entire plan as a named, dated snapshot (e.g. "resolved at the general assembly") and compare it later against the current plan
- **Treasurer's report (print-ready)**: a standalone print page for the general assembly – club name, cash-account overview (balance on 01/01 and 12/31), income/expense statement, plan/actual comparison, tax-sphere overview with an exemption-threshold notice, completeness note, closing note and signature lines; print or save as PDF via the browser
- **Short report for board meetings (print-ready)**: a compact print page with a selectable reference date – account balances since then, movements, short financial-plan summary; optionally in your corporate design (club logo + accent color, configurable under gear icon → Club)
- **CSV exports** (for the audit / Excel): journal, trial balance, income/expense overview, plan/actual comparison (including notes)
- **Multi-year overview** (CSV matrix, columns = years): income statement by account (income/expenses/result) + assets at year-end, plus result per reporting group/project and by tax sphere across all years; also as a line chart (Reports → Evaluation) for meeting presentations
- **Cash-account reconciliation**: account balance (journal) vs. open (unassigned) bank transactions, with a total across all cash accounts
- **Funds in the header**: the balances of all cash accounts as a single figure; individual accounts (a fixed-term deposit, say) can be left out of it without disappearing from the cash report, the assets overview or the trial balance

### Audit support
- **Change log** (Reports → Log, for everyone with read access): who created/changed/deleted, assigned, imported postings, changed attachments or permissions, or closed years, and when; deliberately survives even "delete all data"
- **Receipt ZIP**: all receipts for a year as a ZIP download, one folder per posting (`NNNN_date_description/`); missing files are listed instead of aborting the export
- **"Only without receipt" filter** in the journal: shows postings without an attached receipt
- **Gap check**: warning above the journal for missing or duplicate posting numbers in the selected year (also as a completeness line in the treasurer's report). Within an open fiscal year the app keeps the numbering itself gap-free – deleting a posting shifts the following numbers down; the year-end closing then locks them in
- **Audit guide** (Reports → Evaluation): a print-ready one-page quick guide for auditors – role, audit steps, where to find what; with the club name in the header

### Membership fees & SEPA direct debit
An optional add-on module (the "Contributions" tab, appears automatically once used, or can be switched on under gear icon → Contributions & SEPA), available to administrators **and bookkeepers** – only the basic settings (creditor ID, collecting account, default fee) remain reserved for administrators.
- **Members** consist of two independent pieces of information instead of a dedicated member-management system: a **SEPA mandate** (IBAN, BIC, email, signature date) and/or a **fee** (amount, payment frequency, first due date); the payer is a Nextcloud account or a free-text name
- **Default fee**: an amount/frequency stored once pre-fills "add member" and is also used in the CSV import when a row has a start date but no amount of its own – with 80–100 members on the same rate, otherwise the same value would need to be entered by hand 80–100 times
- **CSV bulk import**: a dry run shows, row by row, what would be created before anything is actually created; column names in German/English, any order, unknown columns are ignored; email validation accepts umlauts in the local part (e.g. `m.müller@gmx.de`)
- **Fee due dates**: automatically create open items; a backlog (retroactively created fees) can be caught up in one step with "catch up" instead of waiting one period at a time
- **SEPA batch collection**: preview of all due open items with an active mandate, generation, XML export (**pain.008**), pre-notification by email (14-day notice per the SEPA rulebook, with a warning if the lead time is shorter), posting as executed (closes all included open items in one step); returned direct debits are detected automatically on the next bank-statement import and the item is reopened
- **Revoke a mandate instead of deleting it**: generated batches stay traceable; changing a bank account correctly re-links existing fees and open items to the new mandate

### Organization & security
- **Permission roles**: administrator – bookkeeper – auditor (read-only); Nextcloud admins are always administrators; roles for users and groups
- **Shared dataset** (`user_id = '__verein__'`): all authorized users work on the same data
- **Collaboration**: changes made by other people are detected via polling (every 20 s and on window focus) and the view refreshes automatically; **optimistic locking** when editing postings prevents silent overwrites (conflict message instead of data loss)
- Destructive actions (delete everything, xbuc reset) are administrator-only, each with a confirmation dialog

### Mobile use
- **Bottom navigation with a "+" button** (new posting) on mobile devices (≤ 640 px); the desktop view stays unchanged
- **Cards instead of tables**: journal (grouped by month), bank transactions, trial balance, reporting groups, account statement as well as the member list and SEPA batches as cards with drill-down and back bars
- **Selection sheet** for accounts/categories: searchable, with an assignment suggestion, a "recently used" group (device-local), and swipe-down to close
- **Quick entry**: a large amount field, native date picker, photograph a receipt with the camera right when creating a posting

## Architecture

```
vereinsbuchhaltung/
├── appinfo/           info.xml, routes.php
├── lib/
│   ├── AppInfo/       Application.php (DI, middleware registration)
│   ├── Controller/    Page, Account, Transaction, Import, Journal, Report,
│   │                  Budget, Permission, Rule, Export, Settings, Attachment,
│   │                  OpenItem, Branding (logo/color), Help (manual,
│   │                  audit guide), Demo (sample club),
│   │                  Sync (collaboration), Year (year-end closing), Audit,
│   │                  CostCenter, SepaMandate, MembershipFee, SepaBatch,
│   │                  MemberImport (fees & SEPA, bookkeeper role or above)
│   ├── Db/            Entities + QBMapper (accounts, bank_tx, journal, journal_line,
│   │                  costcenters, budgets, budget_snapshots, open_items,
│   │                  permissions, rules, attachments, year_close, audit_log,
│   │                  sepa_mandates, membership_fees, sepa_batches,
│   │                  sepa_batch_items)
│   │                  + TransactionRunner (DB transaction wrapper)
│   ├── Middleware/    PermissionMiddleware (permission checks, 403/423),
│   │                  RevisionMiddleware (change state for polling),
│   │                  RequiresRole (attribute for per-method permission checks)
│   ├── Migration/     schema migrations (vbh_* tables)
│   ├── BackgroundJob/ ImportWatchFolderJob (hourly check of the watch folder)
│   ├── Service/       CamtCsvParser, ImportService, WatchFolderService,
│   │                  XbucParser, XbucImportService, AccountService,
│   │                  BookingService, JournalService, EntryNumberService,
│   │                  OpeningBalanceService, ReportService, ResetService,
│   │                  PermissionService, AttachmentStorageService,
│   │                  BudgetSnapshotService, OpenItemService, RevisionService,
│   │                  YearCloseService, AuditService, BrandingService,
│   │                  CostCenterService, CsvFormatter, DemoDataService,
│   │                  EmailValidator, IbanValidator, BillingPeriod (fees:
│   │                  due-date/backlog calculation), SepaMandateService,
│   │                  MembershipFeeService, SepaBatchService,
│   │                  SepaNotificationService (pre-notification by email),
│   │                  SepaReturnDetectionService (returned direct debits),
│   │                  MemberImportService
│   ├── Service/Sepa/  MemberCsvParser, PainXmlBuilder (pain.008 XML),
│   │                  SepaCreditor, SepaReference (mandate reference),
│   │                  SepaText
│   └── Service/Statement/
│                      transaction sources: StatementParser (interface),
│                      Camt053Parser, Mt940Parser, StatementParserRegistry
│                      (content-based format detection), RowNormalizer
│                      (canonical row shape + dedup hash for all sources)
├── src/               Vue 3 frontend (Composition API via setup(), reactive() as
│   │                  composable singletons instead of Vuex/Pinia)
│   ├── App.vue        shell: header/navigation/year picker, tab router,
│   │                  top-level modals, composable bootstrap in mounted()
│   ├── composables/   shared state as reactive() singletons per domain
│   │                  (useAuth, useYears, useAccounts, useBalances, useJournal,
│   │                  useOpenItems, usePermissions, useSync, useCostCenters,
│   │                  useRules, useSort, useConfirm, useMembershipFees,
│   │                  useSepaMandates, useSepaBatches)
│   ├── components/    tabs (DashboardTab/BookingsTab/AccountsTab/ReportsTab/
│   │                  ContributionsTab), dialogs (BookingDialog/
│   │                  SplitAssignDialog/AccountDialog/ImportDialog/
│   │                  BudgetSnapshotModal/HelpModal/SetupWizard),
│   │                  fees & SEPA (MembersList/MemberDialog/
│   │                  MemberImportDialog/MemberCard/SepaBatchPanel/
│   │                  BankAccountChangeDialog), report maintenance
│   │                  (RulesPanel/CostCenterPanel/SphereAssignPanel),
│   │                  Settings-* (Club/Attachments/StatementWatch/
│   │                  SepaBasics/Permissions/XbucImport/YearClose),
│   │                  mobile (MobileNav/BookingCard/AccountPickerSheet),
│   │                  SetupChecklist
│   ├── lib/           stateless helpers (format.js, split.js – the rules for
│   │                  split postings, frequency.js – fee frequencies,
│   │                  shared by App.vue and the dialogs)
│   ├── styles.css     global .vbh-* utility styles
│   ├── api.js         API client (axios + @nextcloud/router)
│   └── main.js        entry point
├── templates/         main.php
├── tests/             unit tests + sample files
├── deploy/            vbh-deploy.sh (server update from the GitHub release)
├── img/               app.svg + screenshots for the App Store
└── .github/workflows/ ci.yml (lint, build, PHPUnit, schema check on every push),
                       release.yml (tag v* → signed package, GitHub release,
                       App Store submission)
```

### Data model

| Table | Purpose |
|---|---|
| `vbh_accounts` | chart of accounts (number, name, type, hierarchy, opening balance, IBAN for cash accounts, reporting group, funds flag) |
| `vbh_bank_tx` | imported bank transactions incl. dedup hash and assignment status |
| `vbh_journal` | postings (date, description, receipt no., posting no.) |
| `vbh_journal_line` | debit/credit lines per posting (amount in cents) |
| `vbh_costcenters` | reporting groups (code, name); accounts reference them via `vbh_accounts.cost_center_id`. Table and column names date from when a reporting group was called a "cost center" – they stay so existing installations keep working without a migration |
| `vbh_budgets` | financial plan (account × year × amount in cents + note) |
| `vbh_budget_snapshots` | frozen plan snapshots (year, label, timestamp) |
| `vbh_budget_snap_items` | line items of a plan snapshot (incl. frozen account master data) |
| `vbh_open_items` | open items (debtor, amount, due date, status, optional account/posting) |
| `vbh_rules` | auto-assignment rules (field, search text, counter-account, priority) |
| `vbh_attachments` | receipts per posting (file name, MIME type, size) |
| `vbh_permissions` | permissions (principal_type, principal_id, role) |
| `vbh_year_close` | closed (finalized) fiscal years (year, when, by whom) |
| `vbh_audit_log` | change log (timestamp, user, action, object, details) |
| `vbh_sepa_mandates` | SEPA direct debit mandates (IBAN, BIC, email, mandate reference, status: active/revoked) |
| `vbh_membership_fees` | membership fees (amount in cents, frequency, next due date, optionally linked mandate/account) |
| `vbh_sepa_batches` | generated SEPA batch collections (due date, creditor details at generation time, status) |
| `vbh_sepa_batch_items` | line items of a batch (amount, mandate, open item, return-debit status) – kept even after payment/cancellation |

Amounts are stored consistently as **integers in cents** (no float rounding errors).

## Installation

The app is published in the **Nextcloud App Store** (signed): in Nextcloud, go to *Apps → Your apps / Office* and search for "Vereinsbuchhaltung". This is the recommended way – updates then arrive via the usual App Store mechanism.

Alternatively, unpack the tarball from a [GitHub release](https://github.com/AndiMb/nc_vereinsbuchhaltung/releases) into `<nextcloud>/apps/` (see also [`deploy/README.md`](deploy/README.md) for server automation).

**Supported:** Nextcloud 31–35, PHP 8.1–8.5, SQLite/MySQL/PostgreSQL.

## Development

**Requirements:** PHP ≥ 8.1, Node ≥ 22 / npm ≥ 10, a Nextcloud instance (≥ 31).

```bash
# 1. Build the frontend
npm install
npm run build           # produces js/vereinsbuchhaltung-main.js

# 2. Bring the app into Nextcloud
#    Copy or symlink the "vereinsbuchhaltung" folder into <nextcloud>/apps/,
#    then enable it under Nextcloud "Apps".

# 3. Run the database migration (after updates)
php occ upgrade         # or: php occ migrations:migrate vereinsbuchhaltung
```

> **No local Nextcloud?** The fastest way to try it out:
> ```bash
> docker run -d -p 8080:80 \
>   -v "$PWD":/var/www/html/custom_apps/vereinsbuchhaltung \
>   nextcloud
> ```
> Then open `http://localhost:8080`, set up Nextcloud and enable the app.

### CI

`.github/workflows/ci.yml` runs on every push and pull request: ESLint, production build, PHP syntax check on 8.1 and 8.4, `composer validate`, PHPUnit, and validation of `info.xml` against the App Store schema.

### Release & deployment

A Git tag `v<version>` (must match `<version>` in `appinfo/info.xml` and have a matching `## [x.y.z]` section in [CHANGELOG.md](CHANGELOG.md)) triggers `release.yml`. The workflow builds the frontend, signs the app with the certificate issued by Nextcloud (`appinfo/signature.json`), publishes `vereinsbuchhaltung-<version>.tar.gz` (+ SHA-256) as a GitHub release, and submits it to the Nextcloud App Store.

The screenshots shown in the App Store are loaded by URL from `img/screenshots/` on `main` – new images therefore need to be pushed **before** the release.

On your own servers, `deploy/vbh-deploy.sh` fetches the latest release, verifies the checksum, and runs `occ upgrade`. **Before releases with a database migration: take a database backup** – the script's rollback only restores the app folder, not the schema.

## Getting started

On the very first start, a **setup wizard** greets you with three options (take over xbuc / start fresh / sample data). Anyone who skips it works through the following steps – the **setup checklist** on the dashboard keeps showing what's still open.

1. **Permissions** (gear icon → Settings → Permissions) → assign users or groups the bookkeeper or administrator role.
2. **Gear icon → Data → Import from "zero Buchhaltung"** → choose the `.xbuc` file → check the preview → import.
   - Import several yearly files one after another: merge mode (default) only takes over missing accounts and new postings.
   - Alternatively: tab **Accounts** → *Create standard chart of accounts* and create accounts manually.
3. Tab **Bookings** → *Import bank transactions* → upload your bank statement (CSV-CAMT, CAMT.053 or MT940). Doing this regularly: gear icon → *Bank data* saves you the manual upload.
4. Tab **Bookings → To assign** → assign each bank transaction a counter-account (apply suggestions with one click; rules automate recurring postings).
5. Tab **Overview** → dashboard with KPI tiles and monthly chart.
6. Tab **Reports** → evaluation (incl. treasurer's report, short report, receipt ZIP and audit guide), reporting groups, financial plan (incl. plan notes, plan snapshots and CSV export), spheres, reserves, log.
7. If membership fees are collected via SEPA direct debit: the **"Contributions"** tab (appears after gear icon → Contributions & SEPA → toggle, or automatically on the first mandate).
8. After the audit and formal discharge: **gear icon → Year-end closing** → close (finalize) the year.

## Roadmap

- **Fetch transactions directly from the bank (FinTS/HBCI)** instead of downloading them. The MT940 parser needed for this already exists, since that's the format FinTS returns. What remains open are mainly the non-technical questions: product registration with the German banking industry (Deutsche Kreditwirtschaft), storage of bank access credentials, and the TAN dialog. Going through an aggregator remains explicitly out of scope – that would route account data through a third party's cloud
- Budget traffic light ("How are we doing against the plan?") on the dashboard
- Automatic payment matching for open items (suggestions via payment-partner matching, similar to the auto-assignment rules)

## License

AGPL-3.0-or-later
