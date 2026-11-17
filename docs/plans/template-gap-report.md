# Gap report: current output vs the reference templates

Phase 0 of [prior-year-ingestion-progress.md](prior-year-ingestion-progress.md). The target spec is [docs/reference/template-spec.md](../reference/template-spec.md).

## Starting point

The exporters already fill cleaned copies of both reference files:

- `resources/templates/sa-ifrs-smes-3/statements.xlsx`, filled by `StatementsXlsx`.
- `resources/templates/sa-ifrs-smes-3/notes.docx`, filled by `NotesDocx`.

Those copies already fix most of the reference bugs: the `#REF!` cells, the other company's figures, the duplicate trade payables, the double OCI block, the PPE comparative NBV and the hard-coded city. The template README lists every fix. So the gaps below are mostly about **where the content comes from** (the prior-year PDF, R11) and the **new rules**, not the layout.

## Excel (`StatementsXlsx`)

| # | Area | Reference / rule | Current | Action | Phase |
|---|---|---|---|---|---|
| G1 | Sheet names | `المركز المالى`, `قائمة التغيرات ` (trailing space), `ايضاح الاصول والممتلكات` | `المركز المالي`, `قائمة التغيرات`, `إيضاح الممتلكات والآلات` | Rename to the reference names. **Needs a decision on the trailing space** (see the questions below). | 9 |
| G2 | Number format | Reference mixed: SOFP, IS, SOCE and PPE use a minus sign; CF uses parentheses. Brief: parentheses, and «-» for zero | One format `#,##0;(#,##0);"-"` everywhere | Keep the current format: it matches R1 and the brief. Only the fonts and sizes follow the reference. | none |
| G3 | Zero as «-» (R1) | | Done in the xlsx | None | none |
| G4 | Depreciation and amortisation (R5) | IS «مصروف الأهلاك»; CF «استهلاك ممتلكات…» | IS has one line, `is.depreciation_amortisation` «مصروف الإهلاك والإطفاء». CF has separate lines already | Split the IS line into `is.depreciation` and `is.amortisation`; the template gets a row; notes/checks follow | 1 |
| G5 | PPE categories | 6 fixed columns in the reference; the reference client has 8 | 7 fixed keyed columns (land … vehicles, CWIP) | Dynamic columns from the prior-year PDF plus the TB. The sheet is built from the category list, not fixed keys. | 6 / 9 |
| G6 | Extra sheets | 8 sheets | Adds a journal-entries sheet (Q39) and, for drafts, a «مسودة» sheet | Keep both **after** the 8 reference sheets. They are needed for review. | 9 |
| G7 | Line labels (R6) | Template names | Catalogue `label_ar` (mostly the template's) | Check every label against the spec; one name per line everywhere | 1 |
| G8 | `data` sheet | Month typed (with the «يناير» bug) | Real dates plus derived text | None (already fixed) | none |
| G9 | Cover and index auditor's report | Always shown | Shown only when `has_auditor_report` | None | none |
| G10 | Formula errors | Reference has `#REF!` | Clean | Add a test: the output opens with zero formula errors after recalculation | 9 |

## Word (`NotesDocx`)

| # | Area | Reference / rule | Current | Action | Phase |
|---|---|---|---|---|---|
| W1 | Note item rows (R11) | Prior-year lines, exact wording | One row per sub-class from our classification (`*_label`) | Rows = the prior-year PDF lines plus new TB items (D3); amounts mapped (R12) | 5 |
| W2 | Revenue and expense notes (R11) | Prior-year lines | Sub-class rows | Same as W1 | 5 |
| W3 | Proposed dividends (R4) | Section present | Block `note_proposed_dividends`, plus the input `dividends.proposed` | Remove the block, the input and the fact | 1 |
| W4 | Approving body (R3) | «الإدارة» | Required input, default only from a prior report | Default «الإدارة» | 1 |
| W5 | Shares in words (R7) | Words in the capital sentence | Manual input `capital.shares_in_words` | Arabic number-to-words in code; the input is dropped | 1 |
| W6 | Partners (R14) | Table with share % | Manual table input | Prefill from the PDF capital note; compute the amount and % | 7 |
| W7 | Related parties (R15) | 7-column tables with volume | User list, or one proposal per related-party account | Prefill from the PDF note, or from the TB names when the PDF has none | 8 |
| W8 | Useful lives table | One row per category | Fixed 5 rows, manual `useful_life.*` inputs | Rows = PPE categories; rates from the PDF policy table | 6 |
| W9 | PPE note tables | Columns per category | Fixed keyed columns | Dynamic columns (as G5) | 6 / 9 |
| W10 | Company info (note 1, header, approval) | Filled text | Manual fields | Prefill from the PDF (name, form, CR and date, activity, city, approval) | 5 |
| W11 | Going concern | Computed numbers | Computed by `NotesBuilder` (four indicators), with a decision input | Keep; check the numbers against the reference client | 5 |
| W12 | Zero as «-» (R1) | | Done | None | none |
| W13 | Year typos (R16) | | None | Flags with the page number | 3 |

## UI and flow

| # | Item | Current | Action | Phase |
|---|---|---|---|---|
| U1 | Search in every select (R2) | Native `<select>` in 10 views plus the JS | One shared searchable component | 1 |
| U2 | Totals in the accounts step (D1) | Per account | Totals per statement line and group, plus overall debit and credit | 1 |
| U3 | Auditor's report not read (R9) | The locate step ignores it, but it isn't marked as skipped | Classified as «تقرير المراجع» and skipped | 1 / 3 |
| U4 | Prior statements only with opening balances (R8) | Always asked | Detect opening balances; skip with an override | 2 |
| U5 | PDF reading | Locate plus one call per statement (SOFP, IS) | Per-page classification and extraction, checks, retries, cache by hash | 3–4 |

## Fixture notes

- The reference client's PDF has **16 pages**. The brief's page numbers are the printed numbers: printed 3 (equity) = PDF page 7, and printed 8 (PPE) = PDF page 12. The PDF also has a damaged xref table; qpdf repairs it with warnings, which we already accept.
- Client data (names, CR number, amounts) stays out of git. The PDF and templates are in `docs/reference/`, which is gitignored. See the progress file for how the regression tests handle this.
