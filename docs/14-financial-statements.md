# Financial statements generator

Turns a classified run into the financial statements and notes. Built in steps ([plan](plans/financial-statements-plan.md) §6); this page describes what exists so far. Pure PHP, no AI (plan Q11): every amount comes from the run's cells, the user, or a computation.

| Step | Status |
|---|---|
| Templates `resources/templates/sa-ifrs-smes-3` ([README](../resources/templates/sa-ifrs-smes-3/README.md)) | done (PR 3) |
| Line catalogue, mapping, aggregation (this page) | done (PR 4) |
| Taxonomy sub-classes for statement lines (Q3, taxonomy version 3) | done (PR 4b) |
| Companies, reports, API (create and read) | done (PR 5) |
| Current-year SOFP and income statement, checks 1–8, 26, 26a, 26d, preview page (replaced by the wizard's review, PR 13c) | done (PR 6) |
| Intake, inputs (zakat, OCI, partners), account overrides, adjusting entries | done (PR 7) |
| Prior year: comparatives, checks 21–22, restated, first year | done (PR 8) |
| Movement suggestions, asset notes, provision roll-forwards, loans note, checks 15–19, 23, 26b, 26c | done (PR 9) |
| Statement of changes in equity, cash flow, checks 9–13, 24 | done (PR 10) |
| Notes and their wording, going concern, early application, checks 14, 20, 25, 26e, 27 | done (PR 11) |
| Versions, the final gate, warning acknowledgements, `run_changed` | done (PR 12) |
| Pages: list, new report, wizard steps 1–3 (PR 13a); steps 4–5 (13b); review and versions (13c) | done (PR 13) |
| Excel export of a version, with the adjusting entries as journal entries | done (PR 14) |
| Word and PDF exports | PRs 15–16 |

## Pipeline so far

```
run.result ──► LayoutProfile ──► LineMapper (+ PeriodicInventoryPairs) ──► LineAggregator ──► per-line Figures
                 real groups,      row → line(s), unmapped,                   closing / opening /
                 layout C/OC/OMC   excluded, proposals                        movements, wrong side

per-line Figures ──► LineAdjustments ──► IncomeStatementBuilder ──► BalanceSheetBuilder ──► MovementSchedules ──► EquityStatementBuilder ──► CashFlowBuilder ──► ReportChecks
                     entries, zakat,     incl. OCI inputs           retained earnings          asset notes,          changes in equity          indirect method     checks 1–13, 15–19,
                     loans' current                                 + net profit (pre-closing)  roll-forwards,                                                         21–24, 26, 26a–d,
                     portion                                                                    loans note                                                             28, zakat

report ──► ReportStatements: the above with the prior year (PriorYearResolver) ──► NoteBreakdowns + NoteComparatives (note tables, both years)
       ──► NotesBuilder (visibility, numbers, going concern, values, checks 14, 20, 25, 26e, 27) ──► NarrativeRenderer (notes.docx wording, filled)
       ──► checks keyed, acknowledgements, `run_changed`, what blocks a final ──► POST …/versions (immutable snapshot)
```

The report's own part (overrides, inputs, adjusting entries) goes in at the mapper and at `LineAdjustments`; the run itself is never changed.

`CurrentYearStatements` runs the whole chain for a run and a template version; the report state uses it.

All classes are in `app/Services/Statements/`; the rules are in `config/financial_statements.php`, keyed by `template_version` (`FS_TEMPLATE_VERSION`, default `sa-ifrs-smes-3`).

## Companies and reports (API)

`companies` holds a profile reused across years (Q12); `financial_reports` holds one set of statements built from a run (plan §4.1). Endpoints: [API reference](api-reference.md#companies).

- **A report starts from a `done` run only** (409 otherwise): nothing is built from a run still waiting, failed, cancelled or expired. Drafts are allowed with unclassified or unmapped rows; they block only a final version (Q2, [versions](#versions-and-the-final-pr-12)).
- **Its state is read from the run as it is now** (`ReportSource`): the layout profile and the line mapping, under the report's own `template_version` (fixed at creation). `run_result_hash` is the sha1 of the run's result the report last took in; when a row is corrected later, `run_changed` turns true and check `run_changed` (a warning) asks the user to review; acknowledging it takes the run as it is now (PR 12).
- **Scope for now** (Q5): Saudi LLC under IFRS for SMEs in SAR; each of these is one allowed value, kept as data so others can be added. The Hijri CR date is text, stored as entered (Q26).
- The comparative period, inputs, overrides, adjustments and prior figures have columns but are filled by later steps.

## Current-year statements (PR 6)

- **Layout** comes from `statements` in `config/financial_statements.php`: headings, lines and totals in the template's order. A test keeps it identical to `statements.xlsx` (same keys, same order, each total adding the rows its formula adds) and checks that every catalogue line is on exactly one statement.
- **Totals** are `Figure::sum` of their parts: missing when a part is, pending when a part is. Nothing is forced to balance.
- **Signs:** balance-sheet lines are positive on their own side. The income statement is shown as in the template: income +, expenses −.
- **Retained earnings:** in a pre-closing trial balance (Q22) the year's result still sits in the P&L accounts, so the line is the account balance + net profit (origin `derived`, refs to the rows and `is.net_profit`).
- **Post-closing:** every income-statement figure is missing (never 0), `missing` lists `comprehensive_income`, and retained earnings are the balance as it stands. **Unless the movements give the P&L** (Q22, changed 2026-10-14): the P&L closing balances are zero (or empty) while their movement columns aren't, and the closing entry shows on retained earnings to the halala: a net profit equals their **credit** movement, a net loss their **debit** movement (all the accounts on that line together, or one alone: a current-year profit account). Distributions sit on the other side, so they don't get in the way. When the entry was posted after the movement columns, it shows only in the closing balance: the closing gap, closing − (opening + movements), is then a credit equal to the profit or a debit equal to the loss (again all the accounts or one alone; exact). Without movement columns there is no P&L to derive. Then each P&L account's figure is its net movement (period + adjustments), retained earnings are the balance as it stands (the profit is already in it, so it isn't added), `layout.pl_from_movements` is true and info check `pl_from_movements` says so. Anything else stays check 6, an error.
- **A line with no account** is 0 from the trial balance (`trial_balance`, no refs).
- **Note numbers:** each balance-sheet and income-statement line carries `note`, the number of its note when shown ([notes](#notes-and-narrative-pr-11)).

### Checks (`ReportChecks`)

`{key, code, title_ar, severity, scope, line_key, rows, expected, actual, difference, message_ar, acknowledged}`, plus `group` or `amount` where they apply. `error` blocks a final version, `warning` needs an acknowledgement (by `key`, [PR 12](#versions-and-the-final-pr-12)), `info` is shown only. Each line lists the codes about it in `check_codes`.

| # | Code | Severity | When |
|---|---|---|---|
| 1 | `tb_unbalanced` | error; info up to the materiality threshold (rounding) | Debits ≠ credits in a real column group (parents left out, as the classifier's file check does: `TrialBalanceTotals`). Not raised on a partial trial balance |
| 2 | `tb_partial` | error | Fewer than the five families |
| 3 | `unclassified_rows` / `rows_need_review` / `unmapped_accounts` | error / warning / error | Rows with no class; rows flagged for review; unmapped rows with a closing balance (with their total) |
| 4 | `row_reconciliation` | warning | The classifier's `reconciliation` flags, per line |
| 5 | `wrong_side_balance` | warning | Closing balances on the wrong side of their line, per line. Taken from the aggregation, so side rules and contra accounts are already applied (a bank in credit on the overdraft line is not reported) |
| 6 | `post_closing_tb` | error | Every P&L account is zero, and their movements don't give the P&L (below) |
| 6a | `pl_from_movements` | info | Post-closing, and the P&L comes from the movements: a profit equals retained earnings' credit movement (a loss their debit movement), or their closing gap on that side (the closing entry found in the closing balance) |
| 7 | `sofp_unbalanced` | error | Total assets ≠ total equity and liabilities |
| 8 | `net_income_mismatch` | error | Net profit ≠ the P&L accounts' closing balances (credit − debit, parents left out) |
| 26 | `rounding_difference` | info | Whole riyals (Q27): a total's rounded amount ≠ the sum of its parts' rounded amounts. The difference goes on one line under it (`plugged_into`): the user's choice (input `rounding.<total key>`, optional), else the largest line directly under it, else the largest under it (`WholeRiyals`). Every total then shows the sum of its parts and its own rounded amount, and the balance sheet balances. Each row carries `shown` {current, comparative}: the whole riyals the wizard, Excel and PDF show |
| 26f | `comparative_by_opening` | info | R8: last year's statements are needed only when the trial balance has opening balances (`comparative.opening_balances` `present` / `absent` / `unknown`, decided by `opening_signal`: the opening column; without one, closing − movements of the balance-sheet accounts; closing balances only, retained earnings ≠ 0 before closing or accumulated depreciation and amortisation above this year's charge; nothing of these: `unknown`, asked). `present`: a `first_year` source isn't applied (effective `none`) unless the user saved it with `skip_opening` (the override). `absent` with no source chosen: the report is a first year by itself (`comparative.automatic`); the user can still give a prior year |
| 26e | `rounding_over_limit` | error | A total's rounding difference above `rounding_limit` (5, `FS_ROUNDING_LIMIT`): put nowhere, the total shows the sum of its parts |
| 26a | `periodic_inventory_pair` | error | One per proposal, until the user decides |
| 26d | `empty_group` | info | A column group the file has, all zero |
| — | `run_changed` | warning | The run was corrected after the report last took it in (PR 12); acknowledging it takes the run as it is now |

On the sample trial balance: balanced at 26,981,374.81, net profit 179,588.87 rolled into retained earnings, both periodic stock accounts as errors, and a 1-riyal rounding difference on total assets.

## Arabic names (`lang/ar/statements.php`)

Every code the statements produce has its Arabic name in one file: checks, severities, figure statuses and origins, layouts, column groups, missing and unmapped reasons, and the choices of intake fields. The API carries them where a client needs them (`title_ar` on a check, `option_labels` on a choice field); the pages read them from the file. **No page shows a code**: `ArabicPagesTest` renders the classifier, cache and import review pages, and the browser test the wizard's steps (a report with most checks raised, inputs, overrides, entries, notes and versions), and fails on any code in the visible text; `ArabicPagesTest` also fails on any code without a name. The classification pages put API values on screen through Arabic maps that never fall back to the code.

**Messages in the request's language.** The interface wording the API builds (check names and messages, intake questions and help, suggestion bases, what blocks a final, validation and conflict messages) comes from `lang/{ar,en}/report_messages.php` (identical keys, `ReportMessagesLanguageTest`) through `App\Support\Phrase` (key + params, rendered per locale). Every `*_ar` field stays Arabic (exports read it); a sibling without the suffix (`title`, `message`, `label`, `help`, `basis`) is in the request's language (`Accept-Language`). Statement and note content quoted in a message (line labels, note titles, account names) stays Arabic. A check's `key` leaves out its wording, so it is the same in every language and acknowledgements still match. The pages read the new fields and fall back to `*_ar` for versions saved before them. `mapping.unmapped[].message` (a `choose` rule's `reason`, `report_messages.choose_reasons` by its `reason_key`) and `mapping.parent_proposals[].message` (the run's flag) stay Arabic, with `message_text` in the request's language beside them.

## The report's own part (PR 7)

Endpoints: [API reference](api-reference.md#financial-reports). Every change returns the recomputed state.

- **Intake** (`ReportIntake`, `GET …/intake`): only the fields the run makes relevant.

  | Key | Type | Asked when | Required |
  |---|---|---|---|
  | `partners_current` | `liability` \| `equity` (default liability, Q23) | a row sits on a partners' current line | no |
  | `zakat.charge` / `zakat.zero_reason` | number > 0 / text, 3–500 characters (a blank reason is refused) | the trial balance has no zakat charge (not post-closing) | the charge, when there is a profit before zakat and no zero reason or entry already charges zakat (Q38) |
  | `oci.has_items` | boolean | not post-closing | yes: OCI is missing until answered |
  | `oci.fvoci` … `oci.associates` | number (gain +) | `oci.has_items` is true | no (empty = 0) |

  `PATCH …/inputs` validates against these fields (once the update applies), drops values of fields no longer asked, and never builds with an invalid value.
- **Zakat (Q38):** the charge is user input: Dr zakat expense, Cr zakat provision, origin `user_input`, ref `{type: input, key: zakat.charge}`. With a profit before zakat and no charge anywhere (trial balance, input or an entry on the zakat line), `zakat_charge_missing` is an error until the charge is entered or zero is confirmed with a reason. Computing zakat is out of scope.
- **OCI:** each item is `user_input`; with `oci.has_items: false` every item is 0 on the user's word. Until answered, OCI and total comprehensive income are missing (`missing: {key: is.oci_total, reason: input_required, input_key: oci.has_items}`).
- **Account overrides** (`PUT …/account-overrides`): `{row, line_key}` or `{row, shares}` (two or more lines, adding up to 1); since PR 9 also a note `category` and `tags` ([movement schedules](#movement-schedules-pr-9)); or `{row, exclude: true, reason}` (a reason of 3–500 characters, nothing else on the row): the account is left out of the statements and of check 1 (`mapping.excluded`, `reason: user`, with its `note` and `original` line), step 3's «استبعاد من القوائم» and the review's «تعديلاتك على الأرقام». An override is the user's decision: it wins over every rule and settles a pending row, so confirming the periodic stock proposals is an override of those rows to cost of revenue (or to inventory, to keep them). Check 8 counts an overridden account by its income-statement share.
- **Adjusting entries** (`PUT …/adjustments`, Q39): balanced, with a reason, numbered in order; each line an account (`row`, following that account's line or shares) or a statement line (`line_key`), with a debit or a credit. Applied to the closing figure of their lines with origin `user_adjustment` and refs `{type: adjustment, entry}`; never made by the system. An entry on an account with no line gives `adjustment_unmapped_row` (error), and the balance sheet shows the gap. The journal export of the schedule is PR 14.
- **Check 28** `empty_required_input`: an error per required field still empty (the zakat charge has its own check).

## Prior year (PR 8)

`PUT …/prior-year` sets where the comparatives come from (Q7, Q9, Q10, Q41, §5); `PriorYearResolver` reads them and `ReportStatements` builds a report with them. Each statement row gets a `comparative` figure, absent in a first year.

| Source | Balance sheet | Income statement | Period |
|---|---|---|---|
| `none` (no source yet) | the trial balance's opening columns (`trial_balance_opening`; P&L accounts still open at the start roll into opening retained earnings), or missing without them | missing (`prior_year_source_required`) | — |
| `first_year` | none: one column | none | none |
| `report` (a previous report, same company, ending before this period) | its own statements, with its overrides, inputs and entries (`prior_year_report`) | the same | the prior report's |
| `run` (a classified prior-year trial balance) | its statements (`prior_year_trial_balance`) | the same; OCI missing until the user gives the prior OCI figures (`{}` for none; an item not given is then 0) | the year before, or the dates given |
| `manual` | the figures entered per line, as displayed; a line not given is 0 (`prior_year_manual`) | the same | the year before, or the dates given |
| `statements` (PR 17) | an Excel or PDF file of last year's issued statements, read by PHP (a PDF's tables copied by the AI first) and confirmed row by row (`prior_year_statements`); a row the file couldn't read: missing; a line with no row: 0 | the same; the cash flow from the file too, when it has one | the year before, or the dates given |

- **Check 21** `opening_vs_prior_closing` (scope `comparative`): with opening columns and a prior source, each balance-sheet line's opening must equal the prior closing. A difference is an **error** per line until the user fixes the prior figures, or a **warning** once the comparatives are marked `restated` with a mandatory note (shown as «معدلة»; the note feeds the restatement note, PR 11). A riyal either way is a **warning** said to be «probably rounding»: never hidden. Before a file of last year's statements is confirmed, step 2 lists the same comparison line by line (`comparative.upload.opening_check`: `equal`, `rounding`, `difference`, the lines the file places). Nothing is adjusted: the comparatives stay as the source gives them.
- **File of issued statements** (PR 17, `PriorStatementsFile`, no AI). `POST …/prior-year/statements-file` reads it and keeps it for review (`comparative.upload`); the comparatives change only when step 2 saves `source: statements`.
  - *Statements*: each under a title row «قائمة …» (financial position, comprehensive income, cash flows; changes in equity only ends the block before it, its comparative stays typed). A sheet with no such title isn't read.
  - *Year column*: the header under the title naming the comparative year («2024», «31 ديسمبر 2024م», a date, a year typed as a number) where amounts sit below; without one, the columns of amounts are offered and the user picks (`column_certain: false`). A note column (only small whole numbers) is never an amount column.
  - *Amounts*: numbers, «(1,234)», «1,234», «-», Western or Arabic-Indic digits, read exactly; anything else is `unread` and its line is **missing**, never 0.
  - *Lines*: a row whose wording equals one line's or total's is `exact`; one that equals a synonym's is `synonym`; both are placed, and the review can still change them. A close one is `probable`, the rest `none`: those wait for the user's line, a total to check against, or «تجاهل». Two lines with the same wording (the partners' current accounts) make it `probable`.
  - *Synonyms* (`StatementSynonyms`, table `statement_synonyms`): other wordings of a line or total, kept by statement, each wording once, never a line's own label. Started with common wordings of issued statements; the user sees, adds, edits and deletes them on «مرادفات البنود» (`/statement-synonyms`, API `…/statement-synonyms`). Wordings compare after normalising (spelling variants, «ال», «و», word order), exactly; no AI. A file waiting for review is matched with the list as it is now; a file already confirmed keeps its matches, so editing the list never moves confirmed figures.
  - *Expenses*: shown positive throughout, they are turned negative only once the user confirms (`expenses_shown_positive`).
  - *PDF* (`PriorStatementsPdf`, job `ReadPriorStatementsPdf`, scanned or not): the AI copies, PHP reads. (1) The whole file goes once and the AI describes every page (`PriorPdf\PageClassifier`, `comparative.upload.page_kinds`): its kind (cover, contents, auditor's report, one of the four statements, notes, other), its printed number, how far it must be turned clockwise to read upright, and the notes that start on it (`status` `locating` → `pages`). Each statement's pages follow (`found`, `pages`); the auditor's report's pages are never read (`skipped.auditor_report`, R9). Every AI answer is kept by the file's content, the call and the prompts' version (`PriorPdf\Llm`, `PRIOR_PDF_CACHE`): reading the same file again sends nothing it already asked. (2) The accountant sees one row per page, each a link to the file (`GET …/statements-file`), corrects a kind or a turn, and sends them (`PUT …/statements-file/pages` with `page_kinds`, or `pages` for a file uploaded before; `reading` → `ready`). Pages are cut out turned upright (qpdf `--rotate`). (3) Each statement and notes page is cut out alone, turned upright, and copied by the AI into blocks in reading order (`PriorPdf\PageExtractor`): headings (a note's number as printed and its title), paragraphs, and tables (each column's header as printed, each row's label, its note reference and its cells character for character; «?» for a digit it can't read: `unread`). The AI never decides anything. (4) PHP reads the document (`PriorPdf\PriorPdfReader`, kept on the upload as `document`): the statements' years from their headers are the truth, and a note's columns take the statements' years by rank of the years printed, so a note headed 2023/2022 in 2024's statements is 2024/2023, flagged with its page (`Tables::roles`, R16); a year in a note's text before the comparative year is flagged. Totals are rows equal to the sum of the rows above them, never found by their words (`Tables::totals`, R10); headings are never items. The notes are numbered sections across pages, with unnumbered parts nested and a number out of sequence inside a note kept in it, flagged (`Sections`); each statement line is linked to its note by number, title and amount together. (5) The arithmetic is checked (`Checks`): unread digits, each statement ending on a total, assets = equity and liabilities, the cash flow's closing = opening + net change, movement tables rolling forward, the changes in equity's total column, each note tying to its line. A page whose checks fail is read again, alone, up to twice, the AI told what failed; a reading is kept only when it fails less. Before that, a statement whose own checks fail (its end, the cash roll, the balance) has its amount columns read again as ordered lists, every «-» included (`PageExtractor::columns`), and placed on the rows by its arithmetic (`Alignment`): exactly one placement that holds is used and flagged `aligned_by_arithmetic`; several or none change nothing and are flagged. A balance-sheet line differing from this year's opening by more than a riyal has its page read once more; the reading with fewer differences is kept. The wording is checked before R11 copies it (`Wording`): a term read two ways one letter apart (`wording_differs`, both pages), a term's words known nowhere (trial balance, templates, taxonomy, rest of the file: `wording_unknown`). An AI's slip (the other reading a known word) and a misprint (one known word a letter away, never shorter) are corrected instead, each flagged with its page and the text as read (`corrected_reading_slip`, `corrected_misprint`); the accountant keeps the original with one click (`PUT …/statements-file/wording`, `keep_original`, kept on the upload as `wording_choices`). A word marked as right, or kept as printed, joins the known words (`known_words` table) and is never flagged again. Once placed, the statements are checked against each other (`check_cross_*`: equity closing = balance-sheet equity, profit = the income statement's, closing cash = balance-sheet cash), and last year's block of the changes in equity (`PriorPdf\EquityBlock`, `comparative.upload.equity`) prefills step 2's comparative grid. A PDF row's `kind` says what its arithmetic makes it: a `total`, or a row of nothing, needs no line from the accountant. What still fails, and every flag, is listed in step 2 with its pages (`comparative.upload.issues`). The statements' tables become sheets for the Excel reader (`PriorStatementsFile::fromSheets`), and a row matched to a total that the arithmetic doesn't support gets its `check` (the sum of the rows on the total's parts). A real client's file, its kept answers and the values expected of them live in `docs/reference/` (gitignored; `php artisan prior-pdf:extract`, `docs/reference/fixtures/*.json`): `ReferencePdfFixtureTest` reads them offline and is skipped without them. A failure (`failed`, with `error`) keeps the pages, to correct and read again. Limits: 20 MB, 150 pages (`PRIOR_PDF_MAX_*`), within both providers' per-request limits (Anthropic 32 MB / 600 pages, Gemini 50 MB / 1000 pages), so the whole file always fits one request. Cost: `cost_usd` on the upload.
  - *Checks*: the figures go through every comparative check (21, the comparative balance sheet, rounding, the changes in equity and cash flow against the balance sheet), and each total in the file is compared with the same total built from its lines (`prior_file_total`, an error naming the file's row).
  - A new file waits for review while the confirmed one stays in use.
- **Check 22** `period_invalid`, one per problem (`kind`), only for what is given:
  - `length`: the period, or the comparative period (scope `comparative`), isn't 12 months: a **warning** (a short or long period, e.g. a first year, is allowed once confirmed);
  - `comparative_gap`: the comparative period doesn't end the day before this period starts: an **error**;
  - `approval_date`: the approval date (input `approval.date`, Gregorian, optional; always in the intake) isn't after the period end: an **error**.
- The comparative balance sheet must balance (`sofp_unbalanced`, scope `comparative`), and whole-riyal rounding is reported for it too.
- A comparative statement that can't be shown is in `missing` (`comparative.financial_position` / `comparative.comprehensive_income`), never 0: a report with one can only be a draft (Q20, PR 12).
- On the sample trial balance (no source), the opening columns give a balanced comparative balance sheet at 23,897,950.48.

## Changes in equity and cash flow (PR 10)

`EquityStatementBuilder` and `CashFlowBuilder` run after the movement schedules and share their `MovementSuggester`, so their movements are suggestions decided with `POST …/suggestions` (keys `soce.<row>` and `cf.<line>`). State: `statements.changes_in_equity` (`{columns, current, comparative}`) and `statements.cash_flows` (rows like the other statements). Layouts: `config/financial_statements.php` `statements.cash_flows` and `changes_in_equity`, checked against `statements.xlsx` (rows, order, totals, columns).

**Changes in equity** (§3.4, Q23, Q24). Columns: capital, unpaid capital (0: the capital line is net), statutory reserve, other reserves (takes OCI), retained earnings, the partners' current accounts when presented in equity, total.

- Opening: the opening columns; else the comparative balance sheet; a first year starts from 0 (unless the trial balance has opening balances). Without either, the year waits for the prior year (`missing: changes_in_equity`).
- Net profit and OCI from the income statement.
- Movements, suggested: with movement columns, capital credits − debits (capital increase), reserve credits − debits (transfer to the reserve), the retained earnings' debits less that transfer (dividends; or, with the partners' accounts in equity and their credits covering it, a transfer to them, and the rest of their movement as their net movement). Without movement columns, from each column's net change (dividends: what is left of the change in retained earnings after profit and transfers).
- Closing = opening + the year; checks 9 and 11 compare it with the balance sheet.
- Comparative year: the prior report's (source `report`), or typed from last year's issued statements with `run` / `manual` (`PUT …/prior-year` `changes_in_equity`: row → column → amount; a cell not given is 0; closing computed); otherwise missing (`comparative.changes_in_equity`). Its closing is checked against the comparative balance sheet (9, 11, scope `comparative`).

**Cash flow** (§3.5, indirect). Every balance-sheet line's change between the start of the year (the comparative balance sheet; 0 in a first year) and its end lands on one row:

| Rows | From |
|---|---|
| Profit before zakat | the income statement |
| Depreciation, amortisation | the asset notes' charge |
| End-of-service, receivables impairment | the roll-forwards' charge − reversal |
| Finance cost, (gain) loss on disposal, share of associates | the income statement, the disposal results in the trial balance |
| Working capital | each line's change (receivables before the impairment charge; inventory write-downs and released provisions inside their lines) |
| End-of-service, zakat, income tax paid | the provision's change less its charge (end-of-service: + actuarial OCI) |
| Finance cost paid | finance cost − deferred finance cost amortised (suggested; the user's when deferred cost exists without movement columns) |
| PPE, CWIP, investment property, intangibles | the notes' additions (the PPE revaluation surplus is a non-cash row of the note, never an addition); disposal proceeds as entered |
| Equity investments, associates, amortised cost, murabaha deposits | the net change (less OCI and the share of results) |
| Loans (Q36, profit paid in financing) | drawn = gross credits − new deferred cost; repaid = gross debits (suggested; the user's without movement columns) |
| Capital, partners' current accounts, dividends | the changes-in-equity movements; partners (a liability): their change less dividends credited to them; dividends paid = declared − credited to the partners − the increase in dividends payable |
| Leases, non-current due to related parties, overdraft | the net change; the overdraft only when not in cash |
| Non-cash: dividends credited to the partners | suggested from their credits (a liability), or the transfer (equity) |

Cash = the cash line, less the overdraft when the user counts it in cash (`cash_flow.overdraft_in_cash`, Q25, asked only with an overdraft; default no). Without the balance sheet at the start of the year (closing balances and no prior source) the changes are missing (`missing: cash_flows`). Comparative year: the prior report's, or typed from last year's issued statements with `run` / `manual` (`cash_flows`: `cf.*` line → amount; a line not given is 0; totals computed); otherwise missing (`comparative.cash_flows`). Its closing cash is checked against the comparative balance sheet's cash (12, scope `comparative`).

| # | Code | Severity | When |
|---|---|---|---|
| 9 | `soce_closing_mismatch` | error | A column's closing ≠ its balance-sheet line (`column`) |
| 10 | `soce_opening_mismatch` | error / warning when restated | A column's opening (opening columns) ≠ the prior closing (a prior source) |
| 11 | `re_roll` | error | Retained earnings: opening + profit − dividends − transfers ≠ the balance sheet |
| 12 | `cash_flow_closing_mismatch` | error | Closing cash ≠ the balance sheet's cash |
| 13 | `cash_flow_opening_mismatch` | error / warning when restated | The opening columns' cash ≠ the prior closing cash |
| 24 | `re_cleared_no_dividend` | warning | With movement columns: retained earnings debited, matched by the partners' current credits, and no distribution confirmed |
| — | `retained_unexplained` | warning | Retained earnings lost more than the result and the transfers, with no dividend's other side (dividends payable or the partners' current accounts credited as much): shown as «حركة غير مفسرة في الأرباح المبقاة» (equity: `other_retained_movements`; cash flow: a non-cash operating line, not dividends paid) until the user names it (`soce.retained_movement`: dividends paid → financing; prior-year adjustment or another entry → non-cash) |

Template: the approved cash-flow rows (so every line has one) and the L6 partners' movements row are in `statements.xlsx` ([README](../resources/templates/sa-ifrs-smes-3/README.md)).

On the sample trial balance (deferred interest tagged): the 2024 result of 5,011,518.20 as dividends credited to the partners (non-cash, 24 until confirmed), the partners' net movement −589,599.59, loans drawn 1,031,000.00 and repaid 2,239,671.99, finance cost paid 2,288.50; the cash flow goes from 108,838.39 to 34,728.95, the balance sheet's cash.

## Notes and narrative (PR 11)

`ReportStatements` builds the notes of a report after its statements (plan §4.2 steps 10–11): `NotesBuilder` decides what shows and fills every placeholder; `NarrativeRenderer` puts the template's wording together. The wording lives in `notes.docx` only (`NotesTemplate` reads it); nothing of it is written in code, and no AI is involved (Q11). Config: `notes`, `policies`, `periodic_cost`, `foreign_currency`, `edition_effective` in `config/financial_statements.php`. State: `notes`, `note_options`, `narrative`, `going_concern`, and `note` on the statement lines. Endpoint: `PATCH …/notes` ([API reference](api-reference.md#patch-financial-reportsidnotes)).

**Which notes show, and their numbers (Q13).** The config lists the template's 32 notes in order (a test keeps them in step with `notes.docx`).
- A note without a block (incorporation, basis, policies, capital, financial risk, subsequent events, approval) always shows.
- A note with a block shows when one of its `lines` is ≠ 0 this year or last year, or for its own reason: restated comparatives, the first application of the edition, a going-concern indicator, related-party transactions, proposed dividends, significant events. A missing current figure shows it (not known to be 0). A missing comparative doesn't: a report with one can only be a draft (Q20), and once every comparative is in, the rule is exact.
- The user forces a note on or off (`visibility`); `auto` goes back to the rule.
- The basis's and the policies' headings are numbered under their note, «2-1», «5-1» …, counting the shown ones (the accountant's review, 2026-10-28): in `notes.docx` they are the notes' list at level 1, so Word numbers them; the wizard and the PDF print each text block's `number`.
- Shown notes are numbered 1…N in template order at every compute. The statements' «إيضاح» column (`note` on each line), `${note_no_*}` in the wording and `narrative.note_numbers` / `last_note_number` (the statements' footer, PR 14) all read the same numbers.
- Policies tied to a line follow it the same way (`policies` in the config; `ppe:cwip` = the CWIP column of the PPE note), and can be forced too.

**The wording.** `NarrativeRenderer` keeps or drops each block and fills the placeholders:

| Placeholders | From |
|---|---|
| `company_name`, `city`, `cr_number`, `cr_date_hijri`, `unified_number`, `activity`, `head_office`, `branches_clause` | the company (none: «[ناقص]» and `missing: notes.company`) |
| `period_end_text`, `cy`, `py`, `*_start_date`, `*_end_date`, `fiscal_year_*_text` | the report's periods (Arabic month names, Western digits) |
| `note_no_*` | the notes' numbers |
| `restatement_text` | the restatement note (`PUT …/prior-year`, Q9) |
| `going_concern_indicators`, `going_concern_mitigation` | the indicators' clauses with their amounts; the user's mitigation or the template's sentence |
| `useful_life_*`, `capital_*`, `partner_*`, `rp_*`, `provisions_description`, `supplier_finance_*`, `dividends_amount`, `*_events_text`, `approval_*`, `first_application_*` | the user's answers (intake) |
| `revenue_disaggregation_basis` | «نوع الإيراد»: the revenue note lists revenue by type (class) |
| table amounts | the statements, the schedules, the note tables (below) |

A placeholder with no value shows «[ناقص]» and puts the note in `missing` (`notes.<note>`, `note_incomplete`; its prior-year figures are reported with the comparatives when those are missing). A useful life of a category the company doesn't have drops its row.

**Blocks** (`narrative.blocks`; conditions in the [template README](../resources/templates/sa-ifrs-smes-3/README.md#blocks)):
- `early_application` (Q6, E1): the period starts before `edition_effective` (2027-01-01). Its sentence referring to the first-application note (`early_application_reference`) shows only with that note, so a later year of early application has no dangling reference.
- `approval_hijri` / `approval_gregorian`: the approval sentence with the Hijri date when entered, else the Gregorian date alone.
- `note_first_application` and its parts (E2): `first_application.this_period` (not asked when last year's report here used the same edition), `first_application.effect` (`none` / `effect` + its text), `first_application.reliefs`.
- `policies_consistent` / `policies_first_period`: comparatives or a first year (§5).
- Inventory, end-of-service and revenue wording: `policies.inventory` (default general), `policies.employee_benefits` (default Labour Law), `policies.revenue` (one or more, required with revenue). Lessor, subsidiaries, foreign currency: `policies.lessor`, `policies.subsidiaries`, `policies.foreign_currency` (default: accounts of the foreign-currency classes).
- Cash-flow policy: `cf_overdraft_in_cash` when `cash_flow.overdraft_in_cash` is yes (Q25); `cf_murabaha_finance_cost` (finance cost paid on murabaha loans in financing, `[review]`) with murabaha loans in either year.
- Risk note: «لا يوجد … خاضعة للفائدة» without murabaha loans or lease liabilities; «… بالعملة الأجنبية» without the foreign-currency policy.
- `contract_balances`, `related_parties_due_from` / `_due_to`, `cost_periodic_inventory`, `supplier_finance`, `subsequent_events(_none)`: from their figures or answers.

**The user's wording (Q11).** `PATCH …/notes` `texts` replaces a shown paragraph (`<note>.<n>`: its place among the note's paragraphs in the template) with the user's text, shown as edited. The template's wording it replaced is kept; when that wording later changes (an amount or a date moved), the paragraph is `outdated` and `edited_text_outdated` warns. `null` goes back to the template.

**Going concern (Q14).** Indicators this year: a net loss; accumulated losses of half the capital or more (the Companies Law threshold); current liabilities above current assets; total liabilities above total assets. Each is check 25 (info since 2026-11-09: the paragraph is added by itself, so nothing is acknowledged and the statement line carries no mark; the accumulated-losses one says the Companies Law attaches consequences, not legal advice). With any indicator the paragraph is proposed with the amounts, and `going_concern.decision` is required: `include` (optionally `going_concern.mitigation`, the partners' sentence) or `exclude` with `going_concern.exclude_reason`. No indicator: no question; the note shows only if forced.

**Tables.**
- Item tables (`NoteBreakdowns`): a note's lines split by class: by sub-class, or by nature (main class) for the expense notes (cost of revenue, selling, general and administrative, finance cost). Entries by account sit in their account's class; entries by line in a row «قيود التسوية». Trade receivables: gross less the impairment (the contra classes). Cost of revenue with periodic stock (Q32): the build-up (opening stock + purchases + purchase costs − closing stock = cost of goods sold), taking the stock accounts the user moved to cost of revenue by their side, and one «تكلفة البضاعة المباعة» row in the item table.
- Movement tables: the schedules (PR 9); a category with no accounts is 0. End-of-service has its reversal row; PPE its revaluation row (approved template rows).
- Related parties (Q30, Q40): the user's list `related_parties` (name, relationship, nature, accounts by row, transactions this year and last); proposed from the related-party accounts with a balance (with last year's relationship and nature for the same name). A party is in the due-from and/or due-to table where its accounts have a balance in either year (an account can change side).
- Capital: the partners (`partners`: name, shares, share value), the percentage from the shares; the capital from the balance sheet; the number of shares in words by code, with its noun in the form the count gives it, genitive after «إلى» (`App\Support\ArabicNumberWords::counted`, R7: «أربعة آلاف ومائتي (4,200) حصة», «تسع (9) حصص»), never typed.
- In a table, a row whose amounts are 0 in both years is left out; in a table of categories (the asset notes), a column of zeros too.
- In a first year a table has one year (§5): its prior-year column (headed `${py}`) and the empty spacer beside it go, so nothing in the notes is missing for want of a prior year (PR 12).

**Last year's columns** (`NoteComparatives`; the `_py` columns deferred from PR 9):

| Source | Item tables | Movement tables |
|---|---|---|
| `first_year` | none | none |
| `none` | balance-sheet notes: the opening columns (missing without them); income-statement notes: missing | closing rows only (= this year's opening) |
| `report` | the prior report's tables | the prior report's schedules |
| `run` | the prior trial balance's tables | typed (`schedules`); closing rows = this year's opening |
| `manual` | typed (`notes`) | typed (`schedules`); closing rows = this year's opening |

A class or cell not typed is 0 once its note (or any cell) is typed; until then missing.

| # | Code | Severity | When |
|---|---|---|---|
| 14 | `note_total_mismatch` | error | A shown note's table total ≠ its line(s), per year (the related parties' tables too) |
| 17 | `provision_rollforward` (scope `comparative`) | error | Typed prior-year movements don't roll to the prior year's closing |
| 20 | `capital_note_mismatch` | error | Σ partners' shares × share value ≠ the capital line |
| 25 | `going_concern_indicator` | warning | One per indicator (`indicator`) |
| 26e | `same_party_multiple_accounts` | info | A related party's or partner's name in more than one account across the related-party, partners', receivable, payable, prepayment, accrual and salary classes: a hint, never acted on |
| 27 | `note_reference_missing` | warning | A line ≠ 0 whose note is hidden; shown wording that refers to a hidden note by number (a paragraph the user forced or reworded) |
| — | `edited_text_outdated` | warning | An edited paragraph whose template wording changed since |

Notes' questions (intake, only when relevant): `approval.date` (required: the approval note always shows), `approval.body` (optional: last year's answer, else «الإدارة», which the note uses when it is left empty; R3), `approval.date_hijri` (optional), `partners`, the first-application, going-concern and policy answers above, `useful_life.<category>` (for categories with assets), `related_parties`, `other_provisions.description`, `supplier_finance.*`, `events.subsequent`, `events.significant`. A default is the prior report's answer (Q12) or the template's default wording; a required field needs a value. Retired questions, still accepted on `PATCH …/inputs` and ignored (deprecated 2026-10-23, `ReportIntake::RETIRED`): `dividends.proposed` (R4), `capital.shares_in_words` (R7) and `ppe.comparative_table` (2026-10-28: the PPE note has the current year's movement table only, IFRS for SMEs 17.31).

On the sample trial balance (periodic stock confirmed to cost of revenue, accumulated depreciation paired, deferred interest tagged): 23 notes shown, every table ties to its line this year, the cost note's build-up, vehicles' net book value 776,316.43, loans 3,567,555.43 gross, 367,239.73 deferred, 3,200,315.70 net, end-of-service 53,240.67 charged and 975.34 reversed, no going-concern indicator, the murabaha finance-cost sentence shown.

## Pages (PR 13)

Server-rendered shells (`FinancialReportPagesController`, `resources/views/financial-reports/*`, the shared layout `layouts/app.blade.php` in the shared frame `<x-shell>` ([07](07-frontend.md#shared-look)), `public/css/app.css`) whose scripts (`public/js/financial-report/*.js`, ES modules, no build step) read and write through the API only.

- `/financial-reports`: the reports, newest first, by company.
- `/financial-reports/new?run=`: a new report from a finished run (from the classifier's «إعداد القوائم المالية» button, or picked from `GET /classify/jobs?status=done`), a company (picked or created) and the period (default: the last full fiscal year). A run with unclassified rows is allowed, with a note that only a draft can be made (Q2).
- `/financial-reports/{id}`: the wizard (§4.6), steps `#company`, `#prior_year`, `#accounts`, `#movements`, `#notes`, `#review`, `#versions`, free to move between. Every save returns the state; the side panel follows it: what blocks a final, then errors, warnings, info, missing figures and pending suggestions, each opening the step that deals with it.
  1. Company and period: the profile the notes read (`PATCH /companies/{id}`), the company and the period (`PATCH /financial-reports/{id}`), the edition (fixed).
  2. Prior year: the source, the prior report or run, the comparative dates, the grids of what can be typed (`comparative.entry`): statement lines, changes in equity, cash flow, the notes' item tables, the movement tables; restated with its reason. A cell left empty is not entered. Or (PR 17) the Excel file of last year's issued statements: uploaded, then reviewed per statement (its year column, each row's amount and line: «مطابق», «مقترح — راجعه», «اختار سطر», unread amounts named as staying missing; a box to confirm expenses shown positive), saved with the rest of the step; a file waiting for review keeps that choice selected.
  3. Accounts and entries: accounts without a line (with the rule's candidates first), the probable parents (exclude with a reason, or keep), the rows left out of the statements and why (a parent by the file's levels or numbers, or the user's exclusion, which can be undone), the periodic-stock proposals (move or keep), every account with its line and the user's override (one line, shares, a note category, tags), the adjusting entries (balanced, with a reason).
  4. Movements and missing figures (13b): the questions on figures (`GET …/intake`, every field but the notes'), the movement suggestions by table (asset notes, provisions, changes in equity, cash flow) with their basis and accounts, each confirmed, edited, rejected or reset (`POST …/suggestions`), «confirm all» after a confirmation box, the cells only the user can give (`movement_required`, `suggestion_rejected`), and the movement tables as they stand. Accumulated depreciation by category points to step 3.
  5. Notes and text (13b): the going-concern indicators, the notes' questions (approval, partners, policies, useful lives, related parties with their accounts picked by name, events, …), the policies forced on / off, and every note as it will print: shown or hidden (auto / on / off), each paragraph reworded or back to the template's, with «نص القالب اتغير» when its template wording moved (`PATCH …/notes`).
  6. Review (13c): what blocks a final, the errors (each with the step that fixes it), the warnings to acknowledge, each with an optional note (`PUT …/acknowledgements`; acknowledging `run_changed` takes the run as it is), the prior-year cells typed by hand and left empty, which count as 0 (`comparative.defaulted`; lines with a figure this year marked), what changes the figures (every adjusting entry in full, Q39: entry, accounts as «number — name» or lines, debit, credit, reason; every overridden account with the line the classification gives it and its line or split now, from `mapping.overridden`, plus its category and tags; each links to step 3), then the statements and notes as they will print: the four statements (rows zero in both years, and headings over nothing, behind a toggle: the rule of the Excel file and the PDF; each line opening to its accounts, the note column) and the visible notes, numbered. «Save a draft» and «Issue a final» (asked first; disabled while something blocks it).
  7. Versions (13c): every version, newest first, draft or final, superseded marked, with whether the report changed since the latest final (`changed_since_final`); `#versions/<n>` shows version n's payload as it was, read-only, with the same view.

  Questions render by type (yes / no, number, date, text, a choice, several choices, a table); a suggested default (last year's answer, the proposed related parties, a policy's usual answer) is marked «مقترح». Saving never accepts one silently: when the form has suggestions the user left as they are, a box lists them, and the user accepts them with their changes, saves only their own changes, or goes back. Only changed (or accepted) answers are sent.

**Accounts by number and name** (13b). Wherever an account shows, on the pages and in the messages the API builds (suggestion bases, periodic-stock proposals, the panel's checks), it is «account number — account name» (`AccountNames`; a list shows five and counts the rest), never an internal row number.

**Arabic only.** Every word comes from `PageConfig`: `ui` (`lang/ar/financial_reports.php`) and `labels` (`lang/ar/statements.php`, the codes' names); a name not found shows «بند غير معروف», never the code. The scripts send `Accept-Language: ar`, so each validation error comes back in Arabic, naming its field and saying what is wrong (`lang/ar/validation.php`, middleware `SetLocaleFromHeader`), and shows under its input; on an Arabic page a message with no Arabic in it (a technical one) is replaced by a general Arabic one. Numbers in Western digits (Q27). URLs in `PageConfig` are relative.

**Old previews** (13c). The read-only previews (PRs 6–12) are gone; their addresses redirect so old links and bookmarks still work: `/financial-reports/{id}/preview` → `/financial-reports/{id}#review` (301), `/financial-statements/preview/{run}` → the review step of the run's latest report, or a new report from the run when it has none (302). The classifier links only to «إعداد القوائم المالية».

**Tests.** `FinancialReportPagesTest` (shells, `PageConfig`, every `ui()` key in the lang file, the old previews' redirects) and the browser test in `tests/Browser` (Dusk; [docs/08](08-setup-and-operations.md#browser-tests-dusk-pr-13)): every step and a version drawn, Arabic only, including a report with most checks, inputs, overrides, entries, notes and versions.

## Versions and the final (PR 12)

A version freezes the report's state (`POST …/versions`, `{kind: draft | final}`): the payload is the state as `GET` returns it, plus `report_id`, `version` and `kind` (plan §4.4), stored as written. Exports (PRs 14–16) are made from it, so a version always gives the same file (Q16). Versions are numbered 1, 2, … per report, drafts and finals together, and never change: the model refuses an update or a delete (Q19).

**Draft** — always allowed.

**Final** — only when nothing blocks it (Q20; `blocking` and `can_finalize` in the state; otherwise 422 with the same list and the checks in the way):

| Reason | What |
|---|---|
| `errors` | any error check (unclassified or unmapped rows, a partial trial balance, an unbalanced statement, a required input, …) |
| `warnings_unacknowledged` | a warning not acknowledged |
| `missing` | anything in `missing`, not only comparatives: a final never prints «[ناقص]» |
| `pending_suggestions` | a movement suggestion not decided |
| `pending_mappings` | a row whose mapping waits for the user (e.g. a periodic-stock proposal) |

A final sets the report's `status` to `final` and `finalized_at`. The report stays editable; a later final supersedes the earlier (`superseded_by`, derived, so no version row is touched). `changed_since_final` compares a hash of what the statements and notes show (company, periods, statements, schedules, notes, narrative, going concern, comparatives) with the latest final's (`content_hash`).

**Acknowledgements** (`PUT …/acknowledgements`, replacing the list). Every check has a `key`: its code and a hash of every field but the wording, amounts included. A warning is acknowledged by its key, with an optional note; when its amounts change, its key changes and it needs a new acknowledgement. An acknowledgement kept from one request to the next keeps its time. One whose warning has gone stays stored and matches nothing. Errors and info can't be acknowledged.

**Run corrected** (`run_changed`, plan §5): the state is always built from the run as it is now; check `run_changed` (warning) says the figures may have moved since the report last took the run in. Acknowledging it stores the run's new hash, so it goes; it isn't kept as an acknowledgement.

Going concern switched off with a reason (Q14) is in the version through its inputs.

## Excel export (PR 14)

`GET /api/v1/financial-reports/{id}/versions/{n}/export.xlsx` (the «تحميل ملف إكسل» button on each version in step 7). `StatementsXlsx` fills the template's `statements.xlsx` from the version's **stored payload**, never the report as it is now, so the same version always gives the same file, byte for byte: the file's dates and the zip's times are the version's (Q16). Where the template keeps things is in `xlsx` in `config/financial_statements.php`; rows and columns are found by the template's hidden keys.

- **Amounts** in whole riyals, negatives in parentheses, zero «-», Western digits (`[$-409]`) (Q27). Each line takes its settled whole riyals (`shown`); totals keep the template's formulas, so a total is the sum of the lines written, the same figure as the PDF and the wizard (check `rounding_difference`). A missing figure reads «[ناقص]», never 0, and so does any total over it; a total the state knows although a part is missing (the PPE total column while the categories wait for a split) takes the state's figure. The statements' «إيضاح» column takes the note numbers; `data` the company, city, dates, the last note's number and the PPE note's, and whether overdrafts count as cash.
- **Hidden at zero** (Q13), the same rule in the Excel file, the PDF and the wizard (`ShownRows`, mirrored in `statements-view.js`; a browser test compares the three): a line or a total zero in both years (whatever accounts sit under it; a missing figure isn't zero, so a line whose prior year is missing shows «[ناقص]»), a heading with no line showing under it; on the changes in equity a movement row zero in both years (in both years' tables) and a column with nothing in it (with its spacer; the total always shows); on the PPE note a row or a category the same way (its «فائض إعادة التقويم» row too; the closing cost's formula includes it, so the table adds up as shown). A first year hides the comparative columns, the comparative changes in equity and the comparative net book value. The PPE note's sheet is hidden when the note is. Comparative dates left empty mean the full year before. Restated comparatives say «(معدلة)» on their column. The template's «[review]» comments are dropped.
- **A draft** is marked «مسودة» on the cover and in every page's header, and opens on «ملاحظات المسودة»: what blocks a final (`blocking`), every missing figure by name with its reason, every error and unacknowledged warning with its message.
- **Journal entries** (Q39), sheet «قيود التسوية» in every version: entry number, account «number — name» (or the statement line), debit, credit (to the halala) and reason; per entry its totals and the difference, which must be 0; the grand total; «كل القيود متوازنة؟». Entries in the state carry each account's number and name (`adjustments[].lines[].account_number|account_name`), so a version needs no run; older versions take them from the run while it is there.

## Word export (PR 15)

`GET /api/v1/financial-reports/{id}/versions/{n}/export.docx` (the «تحميل الإيضاحات (وورد)» button on each version in step 7). `NotesDocx` fills the template's `notes.docx` from the version's **stored payload**, like the Excel file: the same version always gives the same bytes (the file's dates and the zip's times are the version's, Q16). The file shows what the wizard shows for that version:

- **Notes and numbers**: a hidden note goes with its title; Word's own numbering then numbers the shown notes 1…N, the numbers the version gives them (the statements' «إيضاح» column and `${note_no_*}`).
- **Paragraphs**: the template walked the way `NotesTemplate` reads it (`NotesTemplate::walk`), so each paragraph is the wizard's `<note>.<n>`. A shown paragraph has its placeholders filled where they stand (each sits in one run, so its format stays); a paragraph the user reworded takes their text, in its first run's format; dropped blocks, block markers and paragraphs the wizard drops (e.g. a useful life of a category the company doesn't have) go.
- **Tables**: laid out by `NarrativeRenderer::layout()`, the wizard's own rules: item rows repeated per item, rows zero in both years hidden, a category column zero throughout hidden (the remaining columns widened to the table's width), a first year's prior-year column and its spacer gone.
- **Figures**: whole riyals, negatives in parentheses, zero «-», Western digits (Q27); «[ناقص]» for a missing figure, never 0. Every word and figure is the version's; the export computes nothing but the layout.
- **Layout**: right to left as in the template (Sakkal Majalla). Each table's words and figures in one size, its figures' (and one weight), where the template mixed 12, 13 and 14 pt (the accountant's review, 2026-10-28). The landscape PPE section goes when nothing is left in it; page numbers run on from the template's first page (5). The header carries the company, legal form and period end. The template's «[review]» comments are dropped.
- **A draft** says «مسودة» at the top of every page's header and starts with a page of its own (no header or page number): what blocks a final, every missing figure with its reason, every error and unacknowledged warning (the same list as the Excel file's).

## PDF export (PR 16)

`GET /api/v1/financial-reports/{id}/versions/{n}/export.pdf` (the «تحميل القوائم والإيضاحات (PDF)» button on each version in step 7). `StatementsPdf` draws the version's **stored payload** with the print view `resources/views/exports/report-pdf.blade.php` and renders it with mPDF (Q17). The same version always gives the same bytes: mPDF's creation time and file id are replaced by the version's (Q16). It shows what the wizard shows for that version (`statements-view.js`):

- **Pages**: a cover (company, legal form, period, version; a draft's mark), a draft's own page (`DraftNotes`, the same list as the Word file's), then each statement on its own page, the changes in equity on a landscape page, and the notes. Every page after the cover has the header (company, period, «جميع المبالغ بالريال السعودي…») and a page number from 1.
- **Statements**: the rows the Excel file and the wizard show (`ShownRows`, see Excel export above). Columns: the «إيضاح» number (not on the cash flow), this year, and last year unless it is a first year («(معدلة)» when restated). A figure still pending confirmation and a line's failing checks are marked beside its name, as in the wizard.
- **Notes**: the shown notes, numbered «n-» as the version numbers them (their numbered headings «n-k»), with the version's paragraphs (the user's wording kept) and tables as the wizard lays them out. For print, the template's spacer columns (empty in every row) go and a column of amounts is aligned as numbers, its year heading too (as words, «2025م»). Every amount column of a table has one width (30 mm, less when the page can't hold it), the same in every note, so the figures line up page to page; a first row without amounts is the heading and a row of amounts with no words or «الإجمالي…» a total, both bold with a rule as in the statements. The notes run on in portrait, so no page is left part empty before a turned one (the accountant's review, 2026-10-28): a table wider than 6 columns is in 9 pt, wider than 8 (the PPE note per category) in 7.5 pt.
- **Figures**: whole riyals, negatives in parentheses, zero «-», Western digits (Q27); «[ناقص]» (in red) for a missing figure, never 0. Every word and figure is the version's.
- **Font**: XB Riyaz, under the SIL Open Font License and shipped with mPDF, embedded as a subset. The template's Sakkal Majalla is a Microsoft font and can't be embedded without a licence; Amiri and Noto Naskh Arabic, the plan's candidates, use font tables mPDF can't read (it fails on their GPOS lookups).
- **A draft** says «مسودة» on its cover, in every page's header and as a faint watermark.

mPDF caches font metrics in `storage/framework/cache/mpdf` (created on first use).

## Movement schedules (PR 9)

The notes' movement tables (plan §3.6, §4.2 steps 6–7): `MovementSchedules` runs `AssetNotesBuilder` and `ProvisionRollforwards` over the report's rows and postings; `MovementSuggester` holds the cells the user decides. Config: `schedules`, `tags`, `disposal_results` in `config/financial_statements.php`. State: `schedules`, `suggestions`, `movement_decisions`. Every amount is the trial balance's, a suggestion the user confirms, a sum of those, or the user's own; a cell the user still owes is missing, never 0.

| Schedule | Rows | Columns |
|---|---|---|
| `ppe`, `intangible_assets`, `investment_property` (asset) | cost: `cost_opening`, `additions`, `transfers` (PPE), `disposals`, `cost_closing`; accumulated depreciation: `acc_opening`, `depreciation`, `acc_disposals`, `acc_closing`; `nbv_closing`, `nbv_opening` | the categories with accounts (template columns), `total` |
| `employee_benefits`, `zakat`, `other_provisions`, `receivables_impairment`, `dividends_payable` (provision) | `opening`, the increase (`charge` / `declared`), `prior_year_adjustments` (zakat), the decrease (`paid` / `used` / `written_off`), `reversal`, `closing` | `amount` |
| `loans` (Q36) | `gross`, `deferred_finance_cost`, `net`, `non_current`, `current` | `current`, `comparative` |

- **Suggestions** (layout OMC): cost debits are additions and credits disposals; CWIP credits are transfers out; accumulated-depreciation credits are the charge and debits the disposals; a provision's credits are the increase and debits the decrease. Each is `suggested_from_movement`, pending, until the user decides (`POST …/suggestions`): `confirm` (origin `derived_from_movement`; a confirmation is of an amount, so a different suggestion later is pending again), `edit` (`user_input`, ref `{type: decision, key}`), `reject` (missing until an amount is entered), `reset`. Nothing moved (0) is a fact, not a suggestion. Keys: `<schedule>.<row>.<column>` (asset) or `<schedule>.<row>` (provision).
- **Reversal, not a payment** (26c): a debit on a provision matched by credits on its P&L counterpart (the charge classes credited back, or a reversal class) is suggested as a reversal, the rest as the decrease.
- **Without movement columns** (OC, C): asset movements are the user's (`edit`), except, with one column of accumulated depreciation, the charge (the depreciation expense) and the depreciation on disposals (opening + charge − closing). A provision's increase is its P&L counterpart (the user's when it has none), its reversal sits in it unless the P&L has a reversal class, and the decrease follows (opening + increase − reversal − closing). Openings: the opening columns; else, for a provision, the comparative balance sheet's line; else the user's.
- **Revaluation (PPE):** with an OCI revaluation surplus, the PPE note has a `revaluation` row (non-cash), and the cost debits suggested as additions leave it out. One category takes the surplus; with several, the user splits it (`ppe.revaluation.<category>`), checked against the surplus (19, `kind: revaluation`).
- **Categories (Q8):** a row's category is the user's (account override `category`) or its sub-class's (config). Accumulated depreciation is one sub-class, so its accounts have no category until the user assigns one: a side with any account of no category shows the total only (`split` false, `missing: {key: ppe.accumulated, reason: category_split_required}`). Investment property has one sub-class, so both its sides wait for the user.
- **Tags** (account override `tags`): `deferred_finance_cost` nets the row against the loans (on the long-term loans line unless the override gives a line), as a contra (no wrong-side warning); `provision_charge` makes an expense account the counterpart of the other provisions; `not_disposal` keeps a gain or loss on disposal on its line (other income or expenses) but out of the disposal results: no cash-flow adjustment, no proceeds looked for, flagged `disposal_pending` until the accountant confirms. A disposal result with no asset disposed and no proceeds is flagged `disposal_unmatched` (a warning, with the amount and accounts; the accounts step offers the tag with one click, never applied by itself).
- **The report's postings:** entries by account count in their row's cell (a credit to accumulated depreciation is charge); the zakat charge input and entries on a provision line count in the provision; entries by line on an asset line can't be placed in a category, and check 15 reports the note against the line.
- **Intake:** `<schedule>.disposal_proceeds` (required when the note has disposals; never in a trial balance) and `loans.current_portion` (required with long-term loans; moved to the current loans line as `user_input`). Both 0 or more.

| # | Code | Severity | When |
|---|---|---|---|
| 15 | `asset_rollforward` | error | Opening + additions + transfers − disposals ≠ closing, per category and side (`kind`: `cost` / `accumulated`); or the note's net book value ≠ the line (`kind: statement_line`) |
| 16 | `depreciation_mismatch` | warning | The note's charge ≠ the depreciation expense (PPE: the depreciation classes; intangibles: amortisation) |
| 17 | `provision_rollforward` | error | Opening + increase + prior-year adjustments − decrease − reversal ≠ closing |
| 18 | `disposal_without_proceeds` | error / warning | Disposals and no proceeds entered; with proceeds, proceeds − net book value disposed ≠ the gains and losses on disposal in the trial balance (`kind: gain_loss`, warning) |
| 19 | `split_total_mismatch` | error | Layout OC: 15 or 17 when the movements are the user's split of the net change; the loans' current portion above the long-term loans; the revaluation surplus split across categories ≠ the surplus |
| 23 | `zakat_used_no_charge` | warning | The zakat provision was paid or used, and the year has no zakat charge |
| 26b | `provision_without_pl_counterpart` | warning | With movement columns: a provision's credits ≠ its P&L counterpart's debits, or no counterpart account (other provisions until one is tagged) |
| 26c | `reversal_as_payment` | info | A debit on a provision read as a reversal |
| — | `zakat_zero_movements` | info | No zakat for the year, with its reason (`zakat.zero_reason`): the zakat provision's opening, recognised, used and closing, said once, in place of 23 and 26b on the zakat provision; the zakat base's unanswered lines are «-» and the note prints the reason (`zakat_zero`) |

On the sample trial balance: PPE additions 280,743.94 and depreciation 91,888.90 (= the expense), no disposals; the four accumulated-depreciation accounts wait for their categories; end-of-service charge 53,240.67 with 975.34 reversed and nothing paid (26c); 74.70 charged to doubtful debts with no expense (26b); the zakat provision used with no 2025 charge (23). With the categories assigned and the deferred interest tagged: vehicles' net book value 776,316.43, loans 3,567,555.43 gross, 367,239.73 deferred, 3,200,315.70 net.

The comparative year's movements (the notes' `_py` columns) are PR 11's ([notes](#notes-and-narrative-pr-11)); loan draws and repayments, capital and dividend movements are PR 10's.

### Preview page

Removed in PR 13c: the wizard's review step replaces it, and its addresses redirect there ([pages](#pages-pr-13)).

## `LayoutProfile`

Built from a run's stored result (`fromResult($result, $taxonomy)`), as `ReportSource::result()` gives it: each row's classes under their current names (a run stored before a rename on the taxonomy page resolves through the former names, [16](16-taxonomy.md)).

- **Real groups only.** `meta.groups` lists the column groups the file has. The classifier copies values into the groups a file lacks, so `opening()`, `movement()` and `net()` return `null` for an absent group, never the copy.
- **Layout.** `C` = closing only; `OC` = opening + closing; `OMC` = opening + movement (+ adjustments) + closing. Without closing columns, closing is opening + movement + adjustments (`closingDerived`).
- **Parents count for nothing:** rows flagged `parent_level` / `possible_parent` and the user's exclusions are left out of every fact below, the P&L from movements included (counted, a parent would double the profit).
- **Facts for the checks.** `emptyGroups` (listed but all zero, check 26d), `families` / `isPartial()` (check 2), `unclassified`, `needsReview`, `postClosing` (every P&L account is zero: plan Q22, check 6).

## `config/financial_statements.php`

| Key | What it holds |
|---|---|
| `other_assets_amortisation` | The accumulated amortisation of other assets (sub classes): its increase is the year's amortisation in the cash flow (`cf.amortisation_other_assets`, operating); the rest of `sofp.other_assets`' change is `cf.other_assets_payments` (investing) |
| `lines` | Every statement line that takes account balances: `label_ar` and `side` (where a normal balance sits). The keys are the template's line keys (hidden column `J` of `statements.xlsx`); a test keeps them identical. OCI items are user input, not lines here. |
| `main_classes`, `sub_classes` | Rules per taxonomy class; a sub-class rule wins over its main class. Every taxonomy sub-class resolves to a rule (tested); one added on the taxonomy page gets its chosen line here (`TaxonomyRules`). Category names are the config names, rewritten to the current ones after a rename ([16](16-taxonomy.md)). |
| `side_rules` | A main class whose balance sits on the given side goes to another line: a bank in credit is an overdraft (Q25); a related-party account on the other side moves between due-from and due-to. |
| `functions`, `function_labels` | Expenses by function (Q4): the line of each function, and the word stems that name a function in the file's own classification column (Q34), checked in order. Depreciation (`is.depreciation`) and amortisation (`is.amortisation`) are separate functions and lines (R5); «اطفاء» is checked before «اهلاك/استهلاك». A version saved before the split exports its combined figure on the depreciation line (`FinancialReportVersion::RENAMED_LINES`). |
| `partners_current` | Partners' current account: liability (default) or equity, per report (Q23). |
| `pending_flags` | Classifier flags that put a row's mapping in doubt: `statement_mismatch`, a high `prefix_mismatch`. |

Rule forms: a line key; `{line, confirm}` (mapped, pending until confirmed, with the reason); `{function}` (an expense by nature); `{choose: [lines], reason}` (unmapped until the user picks: e.g. «استثمارات طويلة الأجل» may be associates, investment property, FVOCI or amortised cost); `{partners_current}`.

Taxonomy version 3 added sub-classes for lines the old classes could not reach: `استثمارات عقارية` → investment property, `استثمارات في شركات زميلة` → associates, `توزيعات أرباح مستحقة` → dividends payable, `إيرادات مؤجلة` → contract liabilities, `رد خسائر الانخفاض` → impairment reversals. A bank overdraft has no sub-class on purpose: `side_rules` moves a bank in credit to `sofp.bank_overdraft` for each year separately, which a fixed class could not do for an account that swings between years.

Change the rules through a PR reviewed by an accountant (plan Q3).

## `LineMapper`

`map($rows, $profile, ['overrides' => [row => line | [line => share]], 'excluded' => [row => reason], 'partners_current' => 'liability'|'equity'])` returns `rows` (row → mapping), `unmapped`, `excluded`, `proposals` and `parent_proposals`.

1. **Override** (per report, from the user): wins over everything, may split an account by shares that add up to 1. An overridden or contra-tagged row keeps `original` `{lines, reason}`: where steps 2–5 alone put it (the review shows both, PR 13c).
0. **The user's exclusion** (an override with a reason): left out, `reason: user`.
2. **Excluded:** a parent the classifier found ([parent rows](03-classification-pipeline.md#parent-rows)): `possible_parent` (its number prefixes the accounts under it and its balance is their sum) or `parent_level` (the file's levels). Its balance is the sum of accounts already counted.
6. **Probable parent** (`probable_parent`): mapped by the rules above but pending, and listed in `parent_proposals` `{row, rows, current_line, message}`; it blocks a final (`pending_mappings`) until the user excludes it (with a reason) or keeps it (a line override).
3. **Unmapped:** unclassified rows, classes with no rule, and `choose` rules (with their candidates). An unmapped row with a balance will block a final report.
4. **Side rule**, per column group: an account can be an overdraft last year and cash this year.
5. **Sub-class rule**, then **main-class rule**. An expense by nature takes the line of its function: the file's own label when it names one (`function_client`), else the config default (`function_default`). Both are kept on the mapping (`function`, `default_function`, `client_function`).

A mapping is `{row, lines: {closing: {line: share}, opening: {…}}, source, status: ok|pending_confirmation, reasons: [{code, message}]}`. Nothing is re-mapped silently: when a check doubts a row (`pending_flags`, a `confirm` rule, a periodic-inventory pair), the row keeps its line and becomes pending. The classifier's labels and the client's column are defaults, never trusted over a check (Q34).

## `PeriodicInventoryPairs` (check 26a)

In a periodic system the opening and closing stock accounts belong in cost of sales. When a client labels them inventory, they land on the balance sheet and profit is misstated by the change in stock. Found from balances and movements only, never from account numbers or names:

- **Opening stock:** an account on the inventory line whose debit balance (or period debit) equals another inventory account's opening balance, or all of them together.
- **Closing stock:** an account on the inventory line whose credit balance (or period credit) equals another inventory account's closing balance, or all of them together.

Each match is a proposal `{code: periodic_inventory_pair, kind, row, matches, amount, current_line, proposed_line: is.cost_of_revenue, basis, status: pending, message}`. It is never applied; overridden rows are never second-guessed. On the sample trial balance it finds both stock accounts that the classifier had labelled inventory.

## `LineAggregator`

`aggregate($rows, $mapping, $profile)` → per line: `closing`, `opening` (only with opening columns), `period` and `adjustment` (`{debit, credit}`, only when the file has them) as `Figure`s, the contributing `rows`, and `wrong_side`.

- Amounts are signed by the line's side: a liability's credit balance is positive. Movements follow the closing line.
- A row on the wrong side of its line is counted and listed in `wrong_side`, never netted away silently. A contra account (an allowance, purchase returns: the minority side of its main class) is expected on the other side.
- A pending mapping makes the figure `pending_confirmation`; shares carry into the amounts and the refs.

## `Figure`

`{amount, origin, status, refs}` (plan §4.4). `origin`: `trial_balance`, `trial_balance_opening`, `derived_from_movement`, `suggested_from_movement`, `derived`, `user_input`, `user_adjustment`, `prior_year_report`, `prior_year_trial_balance`, `prior_year_manual`, `missing`. `status`: `ok`, `pending_confirmation`, `missing`, `check_failed`. A missing figure has no amount, never 0. `Figure::sum()` is missing when any part is, and pending when any part is.


## Last year's notes from a PDF (Phase 5)

With last year's statements confirmed from a PDF (`statements` source, the file's `document`):

- **Note lines (R11, R12, D3):** `PriorNoteItems` makes a note's rows last year's note lines, in order and as printed (wording corrections applied), with last year's amounts as the comparative. Each trial-balance account on the note's lines is placed on a line by the accountant's choice (`PATCH …/notes` `items`: note → account → line key or `new`), its name or class (the line's wording), its opening equal to the line's amount last year (one account, or a class's accounts together), or most of the line's words; else it is a new item by its class, with «-» last year. `note_items` in the state lists, per note, the PDF note, its lines and the accounts with where each went and why. Trade receivables keep their gross/impairment table.
- **Company and approval:** `PriorPdf\CompanyFacts` reads the first note (name, legal form, registry number and Hijri date, city, activity, framework) and the approval note (who approved); step 2 offers the company's facts to its empty fields (`comparative.upload.company`), and the approving body is the notes' default (`PriorPdf\PdfDefaults`). The approval date stays the accountant's.
- **Policy choices:** `PdfDefaults::policies` reads the policies note (end-of-service method, manufacturing inventory, foreign currency, lessor, subsidiaries, revenue streams) as defaults.
- **Going concern:** with an indicator hit the paragraph is included by itself with its figures and flagged (`going_concern_indicator`, «اتضافت لوحدها»); the accountant may exclude it with a reason. Not a required question.
- **Zakat base:** `ZakatBase` (state `zakat_base`) takes last year's zakat note's base computation: its lines as printed and last year's amounts, this year's profit before zakat, provisions formed and zakat due from the statements, the rest asked under last year's wording (`zakat.base.<n>`), totals by their arithmetic.

- **Comparability:** `ComparableLines` (state `comparative.upload.comparable`) proposes accounts this year's trial balance puts on another line than last year's statements back on last year's line (`proposals`: by last year's note lines, or a few accounts adding up to what is short; only when every line touched comes nearer), and lists a line's accounts that weren't on it last year (`strays`: when exactly one set of its accounts equals last year's figure). Accepted as account overrides.
- **Profit or loss by sign:** `ProfitLossWording` names a result line, retained earnings, the changes in equity's result rows (each year's block by its own sign), last year's note lines and the zakat base by their figures' sign: one sign, its word; signs differing, both («الربح (الخسارة)»); an info check `label_by_sign` says so; the Excel file writes the label.
- **Zakat base, derived lines:** an addition or deduction line is matched to last year's balance-sheet components (within a riyal, the nearest set, none when ambiguous) and proposed with this year's figures (`from` `derived`, `components`; warning `zakat_base_derived`).

## PPE by last year's categories (Phase 6)

`PriorPpe` (state `ppe_prior`) reads last year's PPE note: its categories as printed, their depreciation rates (the policies note's rate table; useful life = 100 / rate), last year's movement per category. Each PPE account is placed on one (the accountant's choice in `notes.ppe`, its name, its opening equal to last year's closing on its side, or a word only one category has); a depreciation account with no category of its own takes the note's category of the one it is on (no question). Per category, this year's opening is checked against last year's closing. Last year's movement is the note's comparative (where nothing is typed) on the note's own categories, and the useful lives the notes' defaults. The files' PPE columns follow the template's categories until Phase 9.

## Fewer questions (the accountant's review of 2026-11-07)

What the books or last year's statements already say is no longer asked; it is the answer, shown as derived or suggested, and the user's own answer still wins.

- **OCI** (`OciDerivation`, note 7): with no equity reserve (other than the statutory one) that moved in the year, and last year's statements showing none (or no reserve at all), `oci.has_items` is false by derivation: the OCI lines are 0 with origin `derived` and the question is answered. A reserve that moved, a post-closing trial balance, or OCI last year leave the question to the user.
- **Revenue streams** (`RevenueStreams`, note 14): `policies.revenue` is the user's answer, else last year's policy note (PDF) or report, else what the company's activity and the names of its revenue accounts say. The question is asked only when none of these says; otherwise the field carries the suggestion.
- **Useful lives** (note 15): a category whose life last year's statements give (100 / the rate, or the prior report's answer) is not a required question any more; the derived life is the default and prints.
- **Articles of association** (`ArticlesOfAssociation`, notes 10–12): a PDF uploaded for the company (`POST /companies/{id}/articles`, step 1). The AI copies the partners (name, nationality as printed, shares, share value, percentage printed); PHP groups each nationality (`saudi`, `gulf`, `foreign`, `unknown`), works out each partner's share of the capital and the Saudi and Gulf owners' share (`zakat_share`, null when a nationality or a share is unclear). It is kept in `companies.profile.articles`. The capital note's partners default to last year's statements', else to these (the `partners` question is asked only without either); the zakat dialog's `saudi_share` defaults to `zakat_share` (`zakat_tax.saudi_share_from`: `user`, `articles` or `default`), so zakat falls on the Saudi and Gulf share and income tax on the foreign one.
- **No empty line in the changes in equity** (note 3): `ShownRows::equity`, the same in the Excel file and the PDF: a movement row zero in its own year's table is left out of it (the opening, result, total and closing rows stay), and a column zero in both tables is left out (the total stays). The wizard's review still shows every row.
- **Nothing derived is silent (P3):** a derived OCI answer raises info check `oci_derived` (on the OCI total) and revenue streams worked out from the activity (not from last year or the user) raise info check `revenue_streams_suggested`. Useful lives stay a question with last year's life as the default, confirmed by saving it. The partners question is asked only when neither last year's statements nor the articles give them.
- **Zakat charged by itself** (\`FinancialReportController::autoCharge\`, config \`auto_charge\` / \`FS_AUTO_CHARGE\`, default on; the older tests run with it off): on every state the calculation's zakat and income tax are charged as the dialog's «charge» does (\`zakat_tax.applied\`), wherever nothing else charges them, and follow the answers and the figures; a charge that falls to nothing goes. «Remove» in the zakat step records \`zakat_tax.off\` and keeps that charge out until it is charged there again. The review step shows the same calculation read-only under the statements (\`calculation()\` of \`steps/zakat.js\`). The method is the accountants' sheet and the regulation (arts. 15, 20–31, 36–41); the 2024 return form (\`نموذج اقرار الزكاة\`) has the same flow: adjusted profit, base, minimum, zakat = base × 2.5% ÷ 354 × days.


## The notes from last year's statements, and the freedom to arrange them (2026-11-21)

The accountant's notes of 2026-11-21: the notes come from last year's statements, not from the trial balance; no «[ناقص]» where a figure can be had; items merged where they can be.

- **PPE by category, never the total alone** (`AssetNotesBuilder::contraCategory`): an accumulated depreciation account with no category of its own («مجمع اهلاك السيارات» in a class «مجمع إهلاك الأصول الثابتة») takes the category of the cost account its name names (shared words, a word matching another's start), or the only cost category. `category_split_required` is left only when the name says nothing among several categories.
- **Useful lives, never «[ناقص]»** (`NotesBuilder::usefulLives`): last year's life, else 100 / the rate the year's depreciation implies (`PriorPpe::impliedRate`), else the usual life of the kind (`financial_statements.useful_life_defaults`); flagged by the info check `useful_life_estimated` and proposed as the question's default. A rate printed without the straight-line method stays the accountant's (`useful_life_not_derived`).
- **Reading last year's PDF** (`PriorPdf\Sections`): a note's lettered parts («أ- احتساب المخصص», «ب- حركة المخصص») and numbers printed with a comma («٣,٢») sit inside their note; files read before are fixed where they are loaded (`App\Casts\PriorFile` on `prior_figures` and `prior_upload`). A total read as a line («الإجمالي») is never a note item (`PriorNoteItems::isTotal`, or a last row equal to the rows above it).
- **Provisions' movement last year** (`PriorPdf\Provisions`): end of service, zakat, other provisions and impairment take last year's movement table from the PDF (opening, formed, paid, reversed, prior years' differences; it must roll to the closing), where nothing is typed.
- **Trade receivables last year**: gross = last year's line + the impairment last year's note prints (nil when it prints none), else the contra accounts' openings.
- **Zakat base**: the additions, deductions and net base lines of last year's note take this year's zakat calculation (`from: calculation`, still a question the accountant may answer); not with `zakat.zero_reason`. The zakat note is the zakat-titled note with a table (the policy «الزكاة» has none).
- **Company facts**: the incorporation note's city, registration date, activity and head office come from last year's note 1 when the company's profile lacks them (`CompanyFacts` also reads «مؤسسة فردية», «ويتمثل غرض الشركة في :-» and «عنوان الشركة :-» with the text on the next line), only when the file is the company's own (`CompanyIdentity`). A sole proprietorship's incorporation sentence says «مؤسسة فردية».
- **Merging into last year's lines** (`PriorNoteItems::place`, `financial_statements.note_merge`): after name, amount and words, an account goes on the one line of last year's note of its family (salaries and what goes with them, government fees, rent, utilities, communications, professional fees, office and hospitality, …; by its class first, then its name; `falls_to` a parent family): `by: family`. Small kinds left over (≤ 5% of the note together) go on last year's «متنوعة / أخرى» line: `by: misc`. An income-statement line printed without a note puts last year's figure on this year's main row (info `prior_line_on_main_row`).
- **Related parties** (`PriorPdf\RelatedParties`): a table printed sideways as the party's account movement (opening, debit, credit, the year-end balance) is read as the party (its role from the header, «شريك تشغيلي», as the relationship; last year's volume = the movement that raised the balance); its headings are never parties. This year's balance on a table is 0 when none of the party's accounts is on its lines; a party of last year's note with an account on another line this year raises `related_party_elsewhere` (move it with the comparability proposal). A partner's or owner's current account defaults the relationship «شريك» and the nature «حساب جاري». In `notes.docx` the captions «/أ مستحق من …» and «/ب مستحق لـ …» are above their tables.
- **The entry after zakat** (`zakat_tax.entry`, the zakat step and the review): the year's zakat (and tax) entry on the trial balance's own accounts, what the books carry, the adjusting (or reversing) entry the statements post, the result before and after, and the provision's movement.
- **The freedom to choose the note** (`PATCH …/notes`): `order` (the note keys in the order wanted: the shown notes are numbered in it; a note left out keeps its place after the one before it; the wizard, the PDF and the Word file follow, `NotesDocx::reorder` moving each note's part of the document, the PPE note with its own landscape section) and `moves` (account row → {note, line?, item?}: an account override onto the note's line, and its line of last year's note; null moves it back). `note_targets` in the state lists the notes with item tables, their lines and accounts.
