# Prior-year ingestion: progress

A new session continues from this file alone. Read it whole before doing anything.

The brief and every reply of the user, verbatim, are in `docs/reference/brief-and-replies.md` (gitignored: it names the
reference client and its figures). This file restates them without client data.

## 1. Working rules (from the brief and the user's replies)

- **Phases:** work phase by phase. After each phase STOP, give the phase summary (§4) and wait for «continue». Never start the next phase alone.
- **Push:** do NOT push anything until the user explicitly says so, including the 2 older unpushed commits on `main` (`8f972b6`, `2356b38`). Pushing happens only at the very end, on the user's instruction.
- **Branch:** `feature/prior-year-full-ingestion`, from local `main`. Never rewrite `main`'s history; never force-push.
- **Commits:** one per numbered item of a phase; clear messages (`feat(notes): …`). Never commit secrets or API keys. Ask before committing client reference files not already tracked.
- **Client data stays out of git (user, 2026-10-23):** no client name, CR, figures, account names or people's names in committed files (code, tests, docs, commit messages). The client's extraction and expected values live in the gitignored `docs/reference/`; generic tests use made-up data.
- **Tests:** during a phase run ONLY the related tests (and add tests for new logic), always through `scripts/related-tests.sh <extra test files>`, which also runs the cross-cutting guards: UI words (`FinancialReportPagesTest`), one name per statement (`StatementNamesTest`), a title for every check code (`CheckTitlesTest`), the API contract (`ApiContractTest`, `OpenApiSpecTest`). Browser tests: `scripts/dusk.sh` (set `DUSK_CHROME_BINARY` to the chrome-for-testing binary). The FULL suite runs once, in the final phase. Don't commit before the related tests (including browser tests when touched) have finished green. **Never commit while `scripts/related-tests.sh` (and the browser tests when touched) is red or still running, and never amend to hide a red commit** (Step E.4: a Step D commit went in with two failing contract tests and was amended afterwards).
- **API:** additive only (existing fields and endpoints keep working); `openapi.yaml` documents every new field.
- **UI:** Arabic only, no codes on any page; every UI key a literal (no template-literal keys: the words test reads them).
- **Phase summary (§4 of the brief), in English:** what was done; commits (hash + message); related tests run and results; the reference-client fixture result (which acceptance checks pass/fail, with numbers), stating explicitly whether the reference-client regression tests actually RAN or were skipped (a skipped run is not a pass); fields still manual and why (P2); notes for the accountant (flags, assumptions); the next phase and what it needs from the user.
- **This file:** update it after every phase (phases done, commits, decisions, known issues, exact next step).

## 2. The brief (2026-10-23), without client data

### Reference files (`docs/reference/`, gitignored except `template-spec.md`)
- The reference client's approved prior-year statements (scanned PDF, 16 pages): the main test fixture.
- `القوائم المالية لشركة SMEs .xlsx`: THE target format of the Excel output (statements + PPE note).
- `الإيضاحات المتممة - نسخة منقحة.docx`: THE target format of the Word notes.
- Two current-year trial balances (the reference client's 2025 TB and another).
- The most important requirement: the Excel and Word outputs match the two templates in layout, sheet names, section order, fonts, RTL, number formats and headings.

### Principles
- **P1. Read everything from the prior-year file:** all five statements (financial position, comprehensive income, changes in equity, cash flow, and the totals for verification), ALL notes (numeric and text), and the company information. Only the auditor's report is skipped.
- **P2. Minimise accountant input.** Sources in order: (a) the current-year classified TB for current figures; (b) the prior-year PDF for comparatives, wording, line items, categories, partners, related parties, company info, policies, depreciation rates; (c) values computed from (a) and (b) (opening = prior closing, roll-forwards, going-concern figures); (d) manual input as a last resort. Every auto-filled field stays editable. Every phase summary lists the fields still manual and why.
- **P3. Never guess silently.** When uncertain (a failed check, an unclear page, an inferred year), fill the best value AND show a visible flag with the page number.

### Accountant rules
- **R1.** Zero is «-» everywhere: screens, Excel, Word, PDF exports.
- **R2.** Every dropdown has search (one shared component).
- **R3.** The approving body (جهة الاعتماد) defaults to «الإدارة».
- **R4.** Remove «الأرباح المقترح توزيعها (بعد نهاية الفترة)» from all outputs (the Word template has the section: remove it).
- **R5.** Depreciation and amortisation are separate lines everywhere: income statement, cash flow, notes.
- **R6.** One fixed name per statement and line, used everywhere (UI, Excel, Word), the template's names: «قائمة المركز المالي», «قائمة الدخل الشامل», «قائمة التغيرات في حقوق الملكية», «قائمة التدفقات النقدية». A synonym map reads the PDF (الموجودات = الأصول, حقوق الشركاء = حقوق الملكية, قائمة الأرباح أو الخسائر والدخل الشامل الآخر = قائمة الدخل الشامل, إستهلاك ممتلكات وآلات ومعدات = اهلاك الموجودات الثابتة).
- **R7.** The number of shares in words is generated by deterministic code (Arabic number-to-words), not AI.
- **R8.** Ask for the prior-year statements ONLY if the TB has opening balances; otherwise skip the step. The accountant can override.
- **R9.** Never read or output the prior-year auditor's report (تقرير المراجع المستقل).
- **R10.** Prior-year subtotals/totals are not stored as line items, and main headings are not items (مجمل الربح, مجموع المصروفات, صافي الدخل قبل التغيرات في رأس المال العامل): verification only. A total = a line equal to the sum of the lines above it, NOT detected by the word «إجمالي» (the zakat note's «إجمالي الإضافات إلى الوعاء» and «إجمالي الحسميات من الوعاء» are real lines). Empty headings with no items («يضاف:») are dropped.
- **R11.** Every note includes the comparative year from the prior-year PDF with EXACTLY the same line items and wording, no adding or removing, revenue and expense notes included. Prior-year numbers copied as-is.
- **R12.** Current-year numbers come from the classified TB, mapped to the same note lines; the system proposes the mapping, the accountant confirms or changes it with a searchable dropdown.
- **R13.** PPE: read the movement table by category from the PDF; categories come from the PDF. Opening cost and accumulated depreciation per category = prior-year closing. Don't ask a category for accumulated-depreciation accounts the prior year covers. Current-year depreciation distributed normally. Remove the false «إهلاك السنة [ناقص]» warning: a category fully depreciated last year (NBV 0 or 1) has zero depreciation, shown «-», no warning. Depreciation rates per category from the PDF's policy table.
- **R14.** Partners: read the names, the number of shares and the share value from the prior-year capital note; compute the amount and the percentage; prefill the table; the accountant only reviews.
- **R15.** Related parties: same format as the prior-year note, with current figures. From the PDF: names, nature of relationship, nature of transactions, prior-year volume, prior-year balance. ~~The prior closing balance comes from the PDF only if the TB has no opening balances, else from the TB.~~ Replaced by Step B: always from the PDF. Current balances from the classified TB; current volume from the TB's movement columns if they exist, else manual. If the PDF has NO related-party note (the reference client has none, only the balance-sheet line «مستحق إلى أطراف ذات علاقة»): prior balance from that line, names from the TB's related-party account names, the template's related-party table layout; only the nature of relationship/transactions stays manual.
- **R16.** Year typos: the years on the main statements are the truth. In every table the first numeric column = the most recent year. A page showing other years (e.g. 2023/2022 in a 2024 file) is taken as the statements' years with a visible flag («صفحة 9: مكتوب 2023/2022 واتعامل على أنه 2024/2023»). Numbers confirm (a note total = a statement line) but are never the only signal. Year typos inside text are flagged too.

### Decisions (implement; list them in the final summary)
- **D1.** Accounts step: the total of the mapped accounts per statement line and group, and the overall debit/credit totals.
- **D2.** The statements show one net PPE line; category detail only in the PPE note. In the accounts step PPE accounts are grouped with one total, no per-account category questions when the prior year covers them.
- **D3.** A new current-year note item with no prior line is added with «-» as its prior value.
- **D4.** Template rows empty/zero in BOTH years are hidden.
- **D5.** The auditor's report pages are skipped (R9).

### Output format rules (from the templates; detail in `docs/reference/template-spec.md`)
- Excel sheets, in order: data, الغلاف, الفهرس, المركز المالى, قائمة الدخل الشامل, قائمة التغيرات, قائمة التدفقات النقدية, ايضاح الاصول والممتلكات. RTL. Font Sakkal Majalla (template sizes). Template number formats: thousands separator, negatives in parentheses, zero «-». Headers and dates driven by the `data` sheet.
- Don't copy the template's bugs: the `data` sheet's «31 يناير 2025» must be «31 ديسمبر»; several cells hold #REF!. Totals are live SUM formulas where the template has formulas; the file opens with zero formula errors.
- The template rows are the layout; the actual line items and PPE categories come from the PDF + the TB (the reference client has 8 PPE categories, the template 6: output 8).
- Word notes: the docx structure, numbering, headings and table layouts (two-column year tables, a PPE movement table per year, partners table with the percentage, related-party tables, zakat movement table). The prior-year line items and wording replace the template's placeholder rows (R11).
- Text notes filled from the PDF, never «......»: company name, legal form, CR number and date, activity, city/address (from the PDF, not the template's الرياض), financial year, the useful-life/depreciation-rate table, the approving body and date. The `data` sheet (name, entity, dates) from the same source.
- Going concern: numbers computed from current figures (net loss, accumulated losses vs half of capital, current liabilities − current assets, total liabilities − total assets); included only when the conditions are met, flagged for review.
- Any placeholder not fillable from the PDF or TB is listed in the phase summary.

### PDF reading strategy (accuracy over cost; several AI calls are fine)
1. Classify each page: cover / index / auditor's report (skip) / balance sheet / income statement / equity / cash flow / text note / numeric note. Detect rotation (landscape pages are rotated before reading). The classification table is editable by the accountant.
2. Extract each page separately at high resolution into structured JSON (lines, note refs, columns, years, text).
3. Verify by arithmetic: subtotals, statement-to-note ties, BS balance, equity roll-forward, PPE net = BS line, CF closing cash = BS cash, partners' shares = capital. A failed check re-extracts ONLY that page (up to 2 retries), then is flagged with the page.
4. Note linking by number AND title AND amount together (a misnumbered sub-heading inside a note, e.g. «5 النقد وما في حكمه» inside note 4, must not be taken for note 5).
5. Nested notes keep their levels (e.g. a sub-note «مصاريف تشغيلية*»).
6. Equity: when both years are in one table, the earlier year's block is the comparative.
7. Cache extraction results per file hash so re-runs don't re-pay.

### Phases (stop after each)
- **0:** show the unpushed commits (no push), create the branch, write `template-spec.md`, gap report. No feature changes.
- **1:** quick fixes R1, R2, R3, R4, R5, R6, R7, R9, D1.
- **2:** R8 (conditional prior-year request + manual override).
- **3:** PDF pipeline foundation (strategy above): page classification incl. all notes, note linking, years (R16), totals/headings exclusion (R10).
- **4:** all five statements read, mapped and verified, incl. cash flow and changes in equity.
- **5:** numeric notes with exact prior items (R11, R12, D3), text notes and company info.
- **6:** PPE and accumulated depreciation (R13, D2).
- **7:** capital and partners (R14).
- **8:** related parties (R15).
- **9:** Excel + Word output exactly per `template-spec.md` (output rules, D4). Generate the reference client's 2025 output, render it (LibreOffice → PDF → images), compare visually with the templates, report every difference.
- **Final:** run the FULL suite, fix failures, update this file, final summary (D1–D5, remaining manual fields, known issues). Wait for the push instruction.

### Acceptance checks
The brief's acceptance checks on the reference client (comparative column = 2024: balance sheet, income statement, equity, cash flow, PPE accumulated depreciation by category, other assets, partners, company info, year-typo flags, exact note line counts) are in `docs/reference/brief-and-replies.md` and encoded in `docs/reference/fixtures/prior-pdf-client.json`; `tests/Feature/ReferencePdfFixtureTest.php` runs them against the cached extraction (`docs/reference/<client>-extraction/answers`) and is skipped when those files are absent.

## 3. The user's later decisions

- **Phase 0 replies (2026-10-23):**
  - Client data in tests: approved as above; every summary says whether the reference-client tests RAN.
  - Sheet names exactly as the reference («المركز المالى», «ايضاح الاصول والممتلكات», …), without the trailing space of «قائمة التغيرات ».
  - Journal-entries and draft sheets stay, after the 8 template sheets.
  - Negatives in parentheses everywhere for now, one setting to switch (`FS_NEGATIVES` = `parentheses` | `minus`, `App\Support\Amounts`), pending the accountant.
  - Fewer manual fields: useful life = 100 / rate (Phase 6); policy choices from the prior policies note (Phase 5); going concern computed and included automatically, flagged (Phase 5); zakat base with last year's note lines and current figures where derivable (Phase 5); company info and approval from notes 1 and 19, approval date manual, body defaults to «الإدارة».
  - R5: the notes follow the depreciation/amortisation split too.
- **Phase 1 replies:** shares in words agree with the counted noun and case (مائتا/مائتي, ألفا/ألفي, اثنتا/اثنتي; genitive default after «مقسم إلى»); retired questions (`dividends.proposed`, `capital.shares_in_words`) accepted and ignored, not refused; old saved versions with combined depreciation stay (known limitation).
- **Partners (Phase 1 reply):** if the name count ≠ the share-row count, never assign by guessing: flag it. Validate: shares × share value = each row's amount, and the sum = the capital line. (A 7th text line is likely the header or the total row; the scan offsets names vertically from numbers.)
- **Partners name-to-row (Phase 4 reply):** shares × value only checks the numbers, not which name is on which row (all names shifted by one row still pass). If the TB has partner current or capital accounts by name, match names to shares through them (by name, and by amount where the account shows it). Otherwise mark the partners table «requires accountant review» and block approval (the final version) until it is reviewed.
- **Phase 2 replies:**
  - A closing-only TB infers prior years from its balances (non-zero accumulated depreciation/amortisation or retained earnings/losses): decided automatically, reason shown, override kept; ask only without a signal.
  - Cross-source check: every TB opening balance = the matching prior closing in the PDF, per line, matches and differences shown; a difference is a reading error (re-read that page) or a real difference (flagged). Part of the reference regression tests.
  - Match TB accounts to PDF lines by amount as well as name.
  - The cached extraction lives in gitignored `docs/reference/`; report actual AI cost in summaries.
- **Phase 3 replies:**
  - Cash-flow misalignment solved generally: read labels and amounts as two ordered lists (every «-» included), choose the alignment for which all subtotals and the cash roll hold; exactly one → use it, flag «aligned by arithmetic» with the page; several or none → flag for the accountant.
  - Wording accuracy: cross-check repeated terms inside the PDF (two readings differ → flag); flag words found nowhere (TB names, templates, taxonomy, rest of the PDF). Flags in step 2 with the page.
  - Opening vs prior closing: show every difference, ±1 too, labelled «probably rounding», never hidden.
  - Client data: this branch's commits rewritten to remove it (done); main's history never rewritten.
  - Fixture assertions for notes 12 (zakat, both «إجمالي» lines, movement table), 13 (nested sub-note), 5, 6, 10 line by line (done).
  - **Final phase:** two fresh, uncached live reads of the reference PDF, compared with each other and with the expected values (stability), with their cost and every difference.
- **Phase 4 replies:**
  - **Misprints** in the source (e.g. a misspelled heading) are handled like year typos (R16): output the corrected word, flag with the page and the original text, one-click revert. An AI reading slip is corrected to the printed word, also flagged.
  - **Remembered words:** a word the accountant dismisses as correct (or keeps as printed) joins the known words (`known_words`) and is never flagged again.
  - Client data already on `origin/main` removed by a plain commit on THIS branch (done: `10aba9e`, `890ea91`); purging main's history is the user's decision. Backup branch deleted (done).
- **Session prompt of 2026-10-24 (Step A, before Phase 7; it wins over this file where they differ):**
  - **P4. A generic product, not one client's:** every client's PDF differs in layout, note order and numbering, table shapes, wording and scan quality. The AI says where each thing is and its shape; the code takes its structured answer, verifies it by arithmetic, and flags what doesn't fit: never a crash, never a silently wrong value.
  - **Useful lives:** never folded into the template's categories; one row per prior-year category with its own life; the template's fixed categories only without a prior-year file.
  - **PPE total:** the categories' sum is used; a printed total differing from it is a source misprint, flagged with its page.
  - **Generic audit:** the Phase 3–6 code, the Phase 1 and 4 synonyms and migrations, and the known words: nothing tied to one file or layout; terms only one client uses (an account name, its typos) belong to the user's data, learned at runtime, not to code or migrations; client data committed through them replaced by general terms in a new commit.
  - **Phase 7 rule restated:** names unconfirmed, or name count ≠ row count → the table «requires accountant review», the final version blocked until reviewed; never assigned by guessing.
  - **Final phase:** also find and fix the intermittent wizard browser test if it comes back.
- **Step A replies (2026-10-24):**
  - **Printed total vs its columns' sum:** never the sum unconditionally. Use the one that ties to the balance sheet (directly for a net book value; cost − accumulated depreciation for a balance). Neither or both: flag both figures with the page and let the accountant choose. The Word PPE comparative table needs the right prior-year net book value.
  - **Migrations never delete user data:** remove only rows we seeded (a seed marker, or exact seeded values never edited); keep anything a user created or edited.
- **Phase 7 replies (the user's own run on the reference client, 2026-10-24):** opening vs prior closing compared in whole riyals (0 equal, ±1 rounding); one year-typo flag per note; a slip repeated in the file still corrected to the reading known outside it; words split by the font's spacing rejoined; a general Arabic word list so common words are never flagged, people's (partners') names not checked word by word; move proposals by last year's note lines (name, amount, a set of accounts adding up), from any line, never against the account's side; a year end's PPE balances chosen together, only consistent options. Then pending with the accountant, both since settled by Step B: (a) the comparative column always from the approved PDF (yes); (b) an opening adjustment entry for the differences between the TB's openings and last year's approved closing (no input step; the differences only as an information-only entry on the journal sheet).
- **Session prompt of 2026-10-24 (Step B, before Phase 9; it wins over this file where they differ):**
  - **The approved PDF is the source of truth** (the accountant, for now; may be revisited, so a setting): the comparative column always from it, and this year's openings too even where the TB's differ: closing per line = last year's approved closing + this year's TB movement (closing − opening per account, on its line). This replaces R15's «prior balance from the TB opening when it has openings»: the related parties' prior balance comes from the PDF (the note, or the statement line without one). No extra accountant input; the implied differences only as an information-only entry on the journal sheet. A reconciliation per line (TB closing vs presented; difference = TB opening − approved) plus the totals apart. Move proposals still apply. A closing-only TB: said, never a silent fallback. One setting, default on, per report.
  - **Everything downstream** follows the setting: notes (each line's opening and closing), the PPE table (opening = approved per category, movements from the TB), the cash flow, the equity statement, the zakat base, the going-concern figures; the notes tie to the presented lines in both years.
  - **Amount-only move proposals (B.2, accountant):** «إيرادات مقدمة» = last year's VAT line by amount is a coincidence. An amount-only match (one account or a set) is never proposed when the account's name/class and the target line are of different natures; the rest are labelled «matched by amount only, names differ», left out of «move everything», moved by their own click.
  - **Word list (B.3):** the CC BY-SA 4.0 list stays a separate data file with its attribution and licence; a permissive one preferred if comparable (none found).
- **Session prompt of 2026-10-24 (Step C, before Phase 9; it wins over this file where they differ):**
  - **Accounts with no line:** standard classes the reference draft left unmapped («عُهد وسُلف دائنة», «مصروفات مستحقة») go to «مصاريف مستحقة وأرصدة دائنة أخرى» by general rules, editable; check the taxonomy for other classes with no rule.
  - **Disposal results:** a gain or loss on disposal with no PPE disposal and no proceeds is flagged with its amount for the accountant to confirm; never reclassified automatically.
  - **Duplicate keys** in the controller: fixed now (not carried into Phase 9).
  - **Raw TB under approved openings:** anything in the statements, notes or the files uses the presented figures; the raw TB only feeds the movements and the reconciliation.
  - **Phase 9 addition:** save the reference client's generated Excel and Word files, with each page's render images, in `docs/reference/output/` (gitignored) and give the paths (for the accountant's review).
- **Step C replies (the accountant, 2026-10-24):**
  - **Negatives:** parentheses confirmed. Default stays parentheses; `FS_NEGATIVES` stays as the switch.
  - **The reference client's gain on disposal with no proceeds:** «put it anywhere for now; the final classification comes later». On the reference file it is other income (not a disposal result), with a visible «classification pending accountant confirmation» flag and its amount, changed with one click; the general check stays; after the change the cash flow must close (net change = closing − opening cash), or the reason is reported, never forced.
- **Reply to Step C (2026-10-24), the correction:** an unexplained movement in retained earnings is never labelled dividends (P3): shown with its amount and accounts, flagged, one-click choices (dividends paid / prior-year adjustment / other), presented accordingly in the equity statement and the cash flow (a prior-year adjustment or a non-cash entry is no cash outflow). Pending with the accountant.
- **Step E (session prompt of 2026-10-25; it wins over this file where they differ):**
  - **E.1 Dividends declared are not dividends paid:** a dividend in the changes in equity (named, or prefilled because dividends payable or the partners' current accounts were credited as much) is declared. The cash flow's «توزيعات أرباح مدفوعة» = declared − the increase in dividends payable − the part credited to the partners' current accounts (a non-cash transfer to a liability, shown under «معاملات غير نقدية»); what is later paid out of those accounts is a financing outflow in its year («صافي الحركة في الحسابات الجارية للشركاء»). The cash flow must still close, no double counting of the payable's movement; if it can't close, check 12 says why, never forced.
  - **E.2 Prior-year adjustment:** the «تسوية سنوات سابقة» choice stays; when chosen, a visible warning `prior_year_adjustment_pending` (IFRS for SMEs Section 10 would restate the comparatives, which conflicts with the comparatives coming from the approved PDF). No restatement built; pending the accountant; listed as pending in every summary.
  - **E.3** this file corrected (stale statements in §2, §3, §7, §8). **E.4** the commit rule in §1.
  - **Phase 9 conditions:** tools may be installed (LibreOffice, anything for rendering; say what). Sakkal Majalla: check `fc-list | grep -i majalla` and Office's fonts folder; never download it from unofficial sites; without it, render with the substitution noted and label every font-caused difference (glyphs, row heights, line breaks, pagination) apart from real layout differences. Separate three groups: real differences from the templates; likely LibreOffice-only; can't tell. The user opens the final files in Excel and Word. Generate the reference output with the current defaults (retained-earnings movement unexplained, disposal gain as other income) and say which figures change with each pending choice.
- **Phase 9 as built (2026-10-25):** the reference's sheet names, the exporter's sheets (draft notes, journal) after the eight; amounts in Sakkal Majalla 14 (the reference's empty amount cells were Arial 10); the PPE tables by last year's categories, any number (one category column repeated per set, `narrative.columns`; Excel inserts columns past seven); each of last year's categories alone on one of the note's own takes its figures, several must add up, else the note's own categories with a warning `ppe_columns_own`; last year's PPE totals as Step A chose them; the comparative-year PPE table shown by default when last year's PPE note was read (`ppe.comparative_table` from PdfDefaults); the zakat base table in the zakat note; a note last year printed no table for takes last year's figure from its approved line (the line «-»: all «-»; one row: the line; several rows: manual); the head office from last year's address note; the template's yellow highlights (the reference's fill marks) left out of the Word file; the landscape tables fitted to the page (the reference's were wider than the text area); the page break before the PPE comparative table moved inside its block. The reference output is written by `ReferencePdfFixtureTest::test_the_reference_clients_files` to `docs/reference/output/` (gitignored).
- **Step D (2026-10-24):**
  - **D.1, the accountant's rule:** a prior-year statements file with no independent auditor's report is rejected with a clear message («الملف مفيهوش تقرير المراجع المستقل…»), found by the page classification (any position), never read (R9); the accountant marking the page in the pages table is the only way past.
  - **D.2:** a second real prior-year PDF (full IFRS, 39 pages, gitignored; no TB): read live, report every point (pages, three-column balance sheet, equity row labels a year behind, Arabic-Indic digits, «المنفصلة», PPE rate ranges, right-of-use, leases, employee benefits, subsidiary, contract balances, partners by year with shares in names and a company partner, a full related-parties note, a zakat note with its movement only, a first-time adoption note not carried, the framework from the PDF with a flag that the output template is the SMEs'). Fix failures generally (P4) with synthetic tests; its expected figures stay in docs/reference only.
- **Phase 5 replies:**
  - **Moving accounts back to last year's line (comparability):** when this year's TB puts accounts on another line than last year (e.g. accruals inside trade payables, leaving «مصاريف مستحقة وأرصدة دائنة أخرى» empty), use the opening check to PROPOSE moving them back, per line with amounts, accepted one click each or all at once.
  - **Profit/loss wording:** R11 keeps last year's lines, not a wrong sign. Switch ربح/خسارة pairs («صافي الخسارة/الربح قبل الزكاة», «صافي خسارة/ربح السنة», …) by the sign of the figure, in the notes AND the statements, flagged; the comparative's wording follows its own sign.
  - **Tests:** fix the intermittent wizard browser test before the final phase; always run the guard tests (UI words, statement names, API contract; check titles added since) in every related run.
  - **Zakat derivation:** derive the base's additions and deductions from last year's balance-sheet components that reconcile last year's figures (within ±1), apply the same composition to this year's figures as a prefilled suggestion showing its components, flagged; unreconcilable → manual.

- **Before the Final phase (user, 2026-10-25):** rounding never lands on a capital line: retained earnings when under the total, else the largest other line; share capital shows the registered capital exactly, not even by the user's choice (`WholeRiyals::NEVER`, `PREFERRED`). PPE category names follow the misprint rule like every term: a word printed twice in the PDF or found in the general word list (which holds common misspellings) is corrected when a word known outside the PDF is a slip away (a long vowel put back; for the PDF-only word also two letters swapped) and the corrected term is known outside it; a short word of a known two-word term printed with a letter changed («حاسب الى») is corrected to the term; each flagged with its page and the original, one click to revert. A related party named from a TB account: tree dots and marks dropped, a role after «*» becomes its relationship (other text stays in the name), a warning `related_party_name_cleaned` until the accountant saves the list.

- **Before any push (user, 2026-10-25, after another session ran the reference client's real files through the system):** (1) the branch rewritten locally to drop the client's figures from two old commits (backup branch `backup/prior-year-before-rewrite` and filter-branch's `refs/original` kept until the user confirms; `main` and `43618b2` untouched). (2) Under Step B, a difference caused by accounts that moved lines follows them (ComparableLines' proposals): the line is compared without them, last year's line with them; a line with no account opens at nothing where last year's has a figure; a presented balance the differences turn over or bring to nothing is a warning (`approved_opening_sign`) with the line, amounts and cause; check 21 drops what moved accounts explain. (3) Step 2's notes grid shows the figures each note uses: read from the PDF (read-only, with its page) or typed / from the approved line (editable); saving twice loses nothing. (4) Last year's related-party balance is signed (owed to the party −) as proposed; the note ties to its line or `note_total_mismatch` flags it. (5) An account's line chosen in «حسب التصنيف» switches to «سطر واحد» and says so; errors appear where they happen (the account editor, each note). (6) The changes in equity print the balance sheet's whole riyals (`shown`); a stray «true» under the statements removed. (7) Rounding never on a line tied to an outside source (`WholeRiyals::NEVER`: capital, cash, bank overdraft, murabaha deposits, related parties, zakat payable); retained earnings first; under Step B a sub-riyal difference on such a line is left on retained earnings (`rounding_kept`) so the line keeps its own amount, the cash flow moving it from its actual opening. (8) Checks on the mapped result and the PPE note as one: `ppe_tie`, `company_cr_date`, `line_missing` (gross profit where revenue and its cost are printed; a cash flow's closing cash and net change), `cf_net_change`; a failing page read again (only it), then flagged. (9) The reference tests compare labels as words (PrintedLabels), numbers exactly.

- **Second real-files review (user, 2026-10-26; the other session's run was on `c8b9acd`, server and queue started after it):** the local draft's own decisions put back to pending (data only, no commit): the retained-earnings movement unexplained again (input and the confirmed dividends suggestion removed), «إيرادات مقدمة» back on its own line (the VAT match was a coincidence, Step B.2), the first-application answer unset; none of the three was prefilled by the system. Fixed, each with a synthetic test: the comparative note a riyal off its line (remainder on the largest line, info `note_comparative_remainder`); no wrong-side warning on other assets' amortisation (a contra even when its class's sides tie) or on accumulated losses (`either_side_classes`); an approved-opening row named «فرق من القوائم المعتمدة للسنة السابقة», once, never «حساب من غير رقم ولا اسم»; a note last year's file printed for its line stays though the line is «-» in both years (the zakat note); the going-concern paragraph's first clause without «و»/«كما»; an other-assets note (`note_other_assets`, cost less amortisation, number on the line; a note with no table to copy takes its accounts' openings when they add up to last year's line); UI: the wizard's side panel under the step up to 1440 px (no sideways scroll), one save at a time (no second box), the classifier page's company carried to a new report, a check on a note links to step 5, no heading left with nothing under it in the accounts step's line totals. Proposed, never posted: depreciation on additions with no depreciation at last year's rate (`ppe_additions_depreciation`, warning, routed to the accounts step); «نعم» suggested for supplier finance when an account named as supplier finance (config `supplier_finance_words`) has a balance. Checked, not changed: the two partners classified as suppliers don't explain the related-parties difference (figures in `docs/reference/accountant-questions.md`). The accountant's questions, the disposal gain first with each choice's effect on profit, equity, zakat base and going concern: `docs/reference/accountant-questions.md` (gitignored).

- **After the second review (user, 2026-10-26):** the zakat-provision account stays where it is (no zakat entered is expected); a self-contained prompt for a fresh agent to run a client end to end (`docs/plans/end-to-end-run-prompt.md`); still no push, the backup branch kept.

- **Third review (user, 2026-10-26; a fresh agent ran `docs/plans/end-to-end-run-prompt.md` on the reference client, report draft v1):** fixed, each with a synthetic test: step 2's notes grid saved every figure it showed (from the source in use, e.g. the TB's openings) as typed, which then ranked above last year's lines (now only a changed cell is saved); a provision's comparative closing is last year's approved line, not this year's opening (a pending move kept the TB's); a note ties to its line in whole riyals; a result line whose figure is still missing keeps both words (a first year's absent comparative doesn't count); the partners' review names the half-row risk of a scan; last year's related-party line put on the one party whose account opened otherwise is flagged (`related_party_line_on_one`, until the table is saved; R15's rule unchanged). Not bugs: the PPE note's ±1/±2 against last year's printed totals (Step A's year-end groups: the combination that ties is used, the other flagged with its page in step 2); the half-row partners pairing can't be detected from the reading (no positions; every pairing adds up) and is already a blocking review. The fresh agent's report keeps its saved typed notes (data, untouched). Zakat with none entered: proposal only, not built (see the final summary).

- **After the third review (user, 2026-10-26):** zakat with none entered built as proposed: «سبب إن الزكاة صفر» is the one switch (with no zakat charged): the zakat base's unanswered lines are «-» (not asked or proposed; a typed line stays), the zakat note prints the reason (block `zakat_zero`), and the provision's movement is one info line (`zakat_zero_movements`) instead of `zakat_used_no_charge` and `provision_without_pl_counterpart`. The fresh agent's report is not touched (the user will run a clean end to end). The end-to-end prompt updated for the third review's fixes and the switch. Still no push, the backup branch kept.

## 4. Current state (2026-10-26)

- Branch `feature/prior-year-full-ingestion`, 121 commits ahead of `origin/main` (incl. `8f972b6`, `2356b38`), **nothing pushed**; rewritten locally (2026-10-25) to drop the client figures from two old commits, every hash in this file mapped to its new commit. Working tree clean after this commit.
- All phases done, the nine fixes after the real-files run, then the second review's fixes (§3). Waiting for the user's push instruction and the confirmation to delete the backup branch.
- After the third review: the zakat switch, its 3 tests (fail without it); FULL suite 901 passed, 1 skipped, 0 failed. AI cost $0.
- Third review: 6 commits, related tests (+ guards) green before each, browser 28/28 after the JS change; FULL suite 898 passed, 1 skipped, 0 failed. AI cost $0.
- Second review: 14 commits, each with its related tests (+ guards) green and the browser tests when JS/CSS changed (last full browser run 18/18 in `FinancialReportPagesTest`, 27/27 overall); the reference fixture RAN in every related run (one expectation moved: last year's other-receivables riyal is now a remainder, not a mismatch). AI cost $0. `ba671be` went in with `StatementTemplatesTest` red (the template's block count; not in its related run), found by the FULL suite and fixed by `3181b3e` (no amend). FULL suite after it: 896 passed, 1 skipped, 0 failed.
- Fixes round: FULL suite 889 passed, 1 skipped, 0 failed; browser 22/22; the reference fixture 16/16 RAN on the cached read. One new uncached live read of the reference PDF: $0.3539 (whole file) + $0.0170 (income statement page re-read: gross profit) + $0.0239 (cash flow page re-read: closing cash) = **$0.3948**, claude-sonnet-5.
- Final phase: FULL suite 879 passed, 1 skipped (`ApiContractTest`'s whole-class-only case; it runs inside the class), 0 failed; browser 21/21; the reference fixture 16/16 RAN on the cached read. Two fresh, uncached live reads of the reference PDF: $0.3593 and $0.3651 (14 requests each, claude-sonnet-5), see §8. A first attempt of both in parallel failed to connect before any answer (no cost).
- Phase 9 related runs: every commit green before it (largest: 171 related + guards, browser 21/21; the reference fixture 44/44 RAN). AI cost $0.
- Step E related runs: E.1 92 passed (guards + equity/cash flow, sample TB, approved openings, integrity, the reference fixture RAN); E.2 66 passed + browser 3. AI cost $0.
- Last related runs (Step D): correction 154 + browser 12; D.1 97 + 16 + browser 4; D.2 135 (related + guards, the reference fixture 15/15 RAN) + browser 4. AI cost: the second file's live read $2.18 (71 requests); re-reads from its cache $0.

| Phase | Scope | Status |
|---|---|---|
| 0 | Branch, template spec, gap report | **done** |
| 1 | R1, R2, R3, R4, R5, R6, R7, R9, D1 | **done** |
| 2 | R8: prior statements only with opening balances, with an override | **done** |
| 3 | PDF pipeline: page classification, rotation, per-page extraction, note linking, years (R16), totals (R10), cache | **done** |
| 4 | All five statements read, mapped and checked; alignment by arithmetic; wording checks; opening vs prior closing | **done** |
| 5 | Numeric notes with exact prior lines (R11, R12, D3), company info, policy choices, going concern, zakat base; then comparability, profit/loss wording, zakat derivation, browser-test fix, guards | **done** |
| 6 | PPE and accumulated depreciation (R13, D2); useful lives = 100 / rate | **done** (columns by last year's categories in the files: Phase 9) |
| A | Useful lives per prior category; PPE total = categories' sum, misprint flagged; generic audit (P4) with synthetic layouts; client-specific terms out of seeds | **done** |
| 7 | Capital and partners (R14) | **done** |
| 7+ | Review fixes from the user's own run (9 points) | **done** |
| 8 | Related parties (R15) | **done** |
| B | This year's openings from the approved PDF (setting), reconciliation; amount-only proposals guarded; word-list licence | **done** |
| C | Respelled classes keep their rule; disposal result with nothing disposed flagged, «other income» by a click; duplicate keys; raw-TB review | **done** |
| C+ | Unexplained retained-earnings movement, never dividends by guess | **done** |
| D | Auditor's report required; a second real layout (full IFRS) read generally | **done** |
| E | Dividends declared vs paid; prior-year adjustment flagged (Section 10); this file corrected; commit rule | **done** |
| 9 | Excel and Word output per the spec; render and compare | **done** |
| Final | Three fixes (capital rounding, PPE misprints, related-party names); full suite, two live reads, client-data scan, final summary | **done** |
| Fixes | Nine fixes from the real-files run (§3); full suite, a live read, outputs, this file | **done** |
| Review 2 | Draft decisions back to pending; 12 fixes; 2 proposals; partners/related-parties check; the accountant's list | **done** |
| Review 3 | Six points from a fresh agent's end-to-end run; zakat-with-none proposal | **done** |
| Review 3+ | Zakat with none entered (the reason as the one switch); the prompt updated | **done**; waiting for the push instruction |

## 5. Key files

| What | Where |
|---|---|
| Brief and replies, verbatim (client data) | `docs/reference/brief-and-replies.md` (gitignored) |
| Output spec (from the templates) | `docs/reference/template-spec.md` (force-added; the folder is gitignored) |
| Gap report | `docs/plans/template-gap-report.md` |
| End-to-end run prompt (for a fresh agent: TB + last year's statements → files, checks, the accountant's list) | `docs/plans/end-to-end-run-prompt.md` |
| Reference files (not in git) | `docs/reference/`: the reference client's PDF and TB, the templates, another TB; `fixtures/prior-pdf-client.json` (expected values); `<client>-extraction/answers` (kept AI answers) |
| Output templates in use | `resources/templates/sa-ifrs-smes-3/` (+ README with every change from the references) |
| Exporters | `app/Services/Exports/StatementsXlsx.php`, `NotesDocx.php` |
| PDF reading | `app/Services/Statements/PriorStatementsPdf.php`; `app/Services/Statements/PriorPdf/` (PageClassifier, PageExtractor, Llm cache, Tables, Sections, Checks, PriorPdfReader, PriorPdfIssues, Alignment, Wording, Vocabulary, EquityBlock, CompanyFacts, PdfDefaults, OpeningCheck); `app/Jobs/ReadPriorStatementsPdf.php`; `app/Support/Pdf/Qpdf.php`; `php artisan prior-pdf:extract` |
| Prior-year features | `app/Services/Statements/`: `PriorNoteItems`, `ComparableLines`, `ZakatBase`, `ProfitLossWording`, `PriorPpe`, `StatementSynonyms`, `PriorYearResolver` |
| Notes, inputs, statements | `NotesBuilder.php`, `ReportIntake.php`, `ReportStatements.php`, `CurrentYearStatements.php` |
| API | `app/Http/Controllers/Api/FinancialReportController.php`, `openapi.yaml` |
| Front end | `resources/js/prior-year.js`, `notes.js`; words in `lang/ar/report_messages.php`, `financial_reports.php`, `statements.php` |
| Tests | `scripts/related-tests.sh`, `scripts/dusk.sh`; `tests/Feature/Prior*Test.php`, `ReferencePdfFixtureTest.php`, `NotesTest`, `XlsxExportTest`, `tests/Unit/CheckTitlesTest.php` |

## 6. Commits

| Phase | Commit | Message |
|---|---|---|
| 0 | `7f88176` | docs(reference): output spec read from the Excel and Word reference templates |
| 0 | `4e9fa3d` | docs(plans): gap report and this progress file |
| 1 | `d90fd6d` | feat(amounts): one setting for negatives; zero as «-» on every screen and file (R1) |
| 1 | `9437cb7` | feat(ui): every dropdown can be searched, one shared component (R2) |
| 1 | `80937ca` | feat(notes): the approving body defaults to «الإدارة» (R3) |
| 1 | `b6453ab` | feat(notes): no proposed-dividends note in any output (R4) |
| 1 | `821b78a` | feat(statements): depreciation and amortisation on separate income-statement lines (R5) |
| 1 | `f0b7563` | feat(names): one name per statement everywhere, and last year's words read as ours (R6) |
| 1 | `86e9837` | feat(notes): the number of shares in Arabic words, by code (R7) |
| 1 | `68d6ce9` | feat(prior-pdf): last year's auditor's report is named and never read (R9) |
| 1 | `7ebe647` | feat(accounts): every line's accounts total next to the line, and the debit and credit totals (D1) |
| 1 | `ff5fc42` | docs(plans): Phase 1 progress |
| 1 | `ae86251` | fix(notes): shares in words agree with their noun and case (R7) |
| 1 | `5e758d7` | fix(inputs): the retired questions are accepted and ignored, not refused |
| 1 | `eb70e0a` | docs(plans): partners rule and old-versions limitation |
| 2 | `03607dd` | feat(prior-year): last year's statements asked for only with opening balances (R8) |
| 2 | `12493f4` | docs(plans): Phase 2 progress |
| 2 | `88974e5` | feat(prior-year): a closing-only trial balance tells from its balances whether there was a prior year (R8) |
| 3 | `9c2d329` | docs(plans): opening-vs-prior-closing check and matching by amount, for Phases 3–6 |
| 3 | `23d1094` | feat(prior-pdf): every page described, corrected by the accountant, and the AI's answers kept by file (Phase 3.1) |
| 3 | `56e56e2` | feat(prior-pdf): years by the years printed, totals by their arithmetic (R16, R10; Phase 3.4–3.5) |
| 3 | `1d5e7c5` | feat(prior-pdf): the notes as printed, and each statement line linked to its note (Phase 3.6) |
| 3 | `30caf9d` | feat(prior-pdf): each page read alone, checked, and read again when its arithmetic fails (Phase 3.3, 3.7) |
| 3 | `a4ec7f9` | feat(prior-pdf): the app reads last year's PDF page by page, and says what it couldn't settle (Phase 3) |
| 3 | `96ab202` | test(prior-pdf): a real client's file read offline against its expected values (Phase 3.9) |
| 3 | `92a9339` | docs: no client name or figure in the committed plans |
| 3 | `8254c13` | docs(plans): Phase 3 progress |
| 4 | `bf2db7b` | feat(prior-pdf): a statement's amounts printed off their lines placed by its arithmetic |
| 4 | `68df87b` | feat(prior-pdf): wording read two ways, or known nowhere, flagged with its page (R11) |
| 4 | `48811d7` | feat(prior-year): this year's opening against last year's closing, line by line, every difference shown (Phase 4.3) |
| 4 | `6748612` | feat(prior-pdf): a page whose line differs from this year's opening is read once more (Phase 4.3) |
| 4 | `2e6a23c` | feat(prior-pdf): the statements mapped and checked against each other, last year's equity prefilled (Phase 4.1, 4.2, 4.5) |
| 4 | `e39738d` | test(prior-pdf): notes 5, 6, 10, 12 and 13 line by line, the statements mapped (Phase 4.6) |
| 4 | `04ea9d8` | fix(prior-year): step 2 draws again (a parenthesis too many in the opening check's table) |
| 4 | `a9bc345` | docs(plans): Phase 4 progress |
| 5 | `10aba9e` | docs: no client name, account or report id in the reviews or the rules guide |
| 5 | `890ea91` | test: made-up names instead of a client's employees in the rules tests and comments |
| 5 | `753bfc8` | feat(prior-pdf): misprints and reading slips corrected, revertible, and right words remembered (R11, R16) |
| 5 | `4d47a61` | docs: wording corrections, the known words, and the partners' name-to-row rule for Phase 7 |
| 5 | `177c8af` | test(ui): the words check knows the PDF steps' keys (rotations, skipped parts, opening signals, page kinds) |
| 5 | `d825be9` | feat(notes): each note's lines as last year printed them, this year's accounts placed on them (R11, R12, D3; Phase 5.1) |
| 5 | `6af362b` | feat(prior-pdf): the company and the approving body read from last year's notes (Phase 5.2) |
| 5 | `61da7bc` | feat(notes): the policy choices taken from last year's policies note (Phase 5.3) |
| 5 | `b0fe97e` | feat(notes): going concern computed and included by itself, flagged, never blocking (Phase 5.4) |
| 5 | `a7536be` | feat(notes): the zakat base as last year's zakat note computed it (Phase 5.5) |
| 5 | `08f239f` | docs(plans): Phase 5 progress |
| 5+ | `4e4123e` | feat(prior-year): accounts on another line than last year proposed back, one click each or all (comparability) |
| 5+ | `a489a12` | feat(statements): profit or loss in a label by its figures' sign, in the statements and the notes, flagged |
| 5+ | `5592029` | feat(notes): the zakat base's additions and deductions proposed from last year's components (Phase 5 follow-up) |
| 5+ | `e465c04` | fix(review): the new checks have titles; a guard test says every check code has one (UI words) |
| 5+ | `feb6ebd` | test(browser): wait for the page's state up to 10 s, and for the review's messages after a save |
| 6 | `f17d8d2` | feat(ppe): the PPE note by last year's categories: rates, useful lives, accounts placed, last year's movement as the comparative (Phase 6, R13, D2) |
| 6 | `15ea223` | docs(plans): Phase 6 progress |
| — | `2291c01` | docs(plans): the full brief, every later decision, the state and the next step |
| A | `dd07e32` | feat(notes): the useful-life table by last year's categories, each with its own life (Step A.1) |
| A | `4a3129b` | feat(prior-pdf): note numbers and the company note read by what the file prints (P4) |
| A | `a647805` | fix(wording): a word printed with its article is corrected only to a word known with one |
| A | `ebfb9cb` | feat(prior-pdf): totals and table layouts read by their arithmetic, not by one file's layout (Step A.2, P4) |
| A | `5bbee0f` | chore(data): general accounting terms only in seeds, examples and tests; a client's own wording learned at runtime |
| A | `27e21dc` | docs(plans): Step A progress |
| A fix | `cc8efa7` | fix(prior-pdf): a printed total or its columns' sum, whichever ties to the statements; neither: the accountant chooses |
| A fix | `3fd4adf` | fix(migrations): the starting-list cleanup removes only seeded, untouched rows; a user's stay |
| 7 | `df6ca61` | feat(prior-pdf): the partners read from last year's capital note by the table's arithmetic (Phase 7.1, R14) |
| 7 | `a45062b` | feat(notes): partners prefilled, confirmed by the trial balance or reviewed before the final version (Phase 7.2, R14) |
| 7 | `30c7cd2` | docs(plans): Phase 7 progress |
| 7+ | `9ef79d1` | fix(prior-year): opening vs last year's closing compared in whole riyals; no riyal apart is equal and counted |
| 7+ | `878d19d` | fix(prior-pdf): one year-typo flag per note, its tables counted inside it |
| 7+ | `7a4a507` | fix(wording): a slip repeated in the file is corrected to the reading known outside it |
| 7+ | `df8cea8` | fix(wording): words split by the font's spacing rejoined when the joined form is known, not flagged |
| 7+ | `db217da` | feat(wording): general Arabic words never flagged; partners' names not checked word by word |
| 7+ | `cb228fd` | feat(prior-year): accounts proposed back by last year's note lines, by name, amount or a set, from any line |
| 7+ | `d56085b` | fix(prior-pdf): a year end's PPE balances chosen together, only in combinations that agree |
| 7+ | `21be93c` | fix(prior-year): a line last year's statements don't print, with an opening balance, compared with «-» |
| 7+ | `900ce30` | fix(prior-pdf): a note named by a line's number isn't also linked to another line by words and an amount |
| 8 | `f0a77fb` | fix(prior-pdf): words in a table's cell are text, not an amount misread |
| 8 | `1e1a439` | feat(notes): related parties from last year's note in its own shape, with this year's accounts (Phase 8, R15) |
| 8 | `d61fc3f` | docs(plans): Phase 8 progress |
| B | `c3bbe47` | feat(prior-year): this year's openings from last year's approved statements, everywhere, reconciled with the trial balance (Step B.1) |
| B | `ff341e7` | fix(prior-year): an account matched by its amount alone never goes to a line of another nature; said, and moved only by its own click (Step B.2) |
| B | `0246118` | docs(wordlists): the shared-alike Arabic word list keeps its licence and attribution beside it, a separate data file (Step B.3) |
| B | `123a440` | docs(plans): Step B progress |
| B | `7f4556d` | docs(plans): Step B decisions and known issues; next: Step C, then Phase 9 |
| C | `9924bad` | fix(accounts): a class respelled in the taxonomy keeps its line rule (Step C.1) |
| C | `4cda44c` | feat(checks): a gain or loss on disposal with nothing disposed is flagged; kept as other income by the user's click, pending confirmation (Step C.2) |
| C | `86541c2` | fix(api): last year's upload gives the equity and the company once; a guard says no array literal repeats a key (Step C.3) |
| C | `a04b94b` | fix(accounts): the accounts step's line totals show the approved openings' part apart (Step C.4) |
| C | `9067c07` | docs(plans): Step C progress; the accountant's answers; next: Phase 9 |
| C+ | `929373d` | fix(equity): a retained earnings movement with no dividend behind it is unexplained until the user names it (P3) |
| D | `f7a767e` | feat(prior-pdf): last year's statements without the auditor's report are refused until its page is marked (Step D.1) |
| D | `e4f23b1` | feat(prior-pdf): a second layout read by what it prints, not by the first file's (Step D.2, P4) |
| D | `13993e9` | feat(prior-pdf): what last year's file says that the accountant should know first (Step D.2) |
| D | `4549d0b` | docs(plans): Step D progress, the retained earnings correction; next: Phase 9 |
| E | `ceb6e43` | fix(cash-flow): a dividend declared is not a dividend paid; partners' credits are non-cash (Step E.1) |
| E | `f894d68` | feat(equity): a prior-year adjustment stays a current-year line, flagged pending the accountant (Section 10; Step E.2) |
| E | `d4f43eb` | docs(plans): Step E progress; stale statements corrected; the commit rule (Step E.3, E.4) |
| 9 | `9a3f7df` | fix(notes): a note last year's file printed no table for takes last year's figure from its approved line (Phase 9, P2) |
| 9 | `248bc2b` | feat(prior-pdf): the head office from last year's address note, offered with the company's other facts (Phase 9) |
| 9 | `2187d9f` | feat(xlsx): the reference's sheet names, the exporter's sheets after its eight (Phase 9, G1, G6) |
| 9 | `4982b3f` | test(reference): the reference client's Excel and Word files written to the gitignored output folder and checked (Phase 9) |
| 9 | `bf34479` | fix(xlsx): amounts and note numbers in Sakkal Majalla at the statements' size, not the reference's Arial 10 (Phase 9) |
| 9 | `7d276af` | feat(notes): the PPE tables by last year's categories, any number, in the notes, the Word file and the Excel sheet (Phase 9, R13, G5, W9) |
| 9 | `a0a6b03` | feat(notes): the zakat base table in the zakat note, last year's lines with both years (Phase 9) |
| 9 | `6ed2ffb` | fix(docx): the template's yellow marks left out of the notes file (Phase 9) |
| 9 | `cbc137d` | feat(notes): last year's PPE movement read, the comparative year's PPE table shows by default; no blank page without it (Phase 9) |
| 9 | `8461fb4` | docs(plans): Phase 9 progress; next: the Final phase |
| Final | `1373b7e` | fix(statements): rounding never lands on a capital line |
| Final | `90c3c01` | fix(prior-pdf): misprints the file repeats, a word list holds, or in a short word corrected |
| Final | `c6c2ca4` | fix(notes): a related party named from an account is cleaned and flagged |
| Final | `8e35a2f` | docs(plans): the Final phase; waiting for the push instruction |
| Fixes | `b5cb1a5` | fix(approved-opening): a difference follows the accounts that moved lines; a line it turns over is a warning |
| Fixes | `b1511a1` | fix(prior-year): step 2 shows the note figures in use; saving twice loses nothing |
| Fixes | `efcf3a1` | fix(notes): last year's related-party balance is signed, so its proposal can be saved and the note ties |
| Fixes | `519778b` | fix(ui): an account's line chosen in the classification mode is applied and said; errors shown where they happen |
| Fixes | `2c1d8c1` | fix(statements): the changes in equity in the balance sheet's whole riyals; no stray «true» under the statements |
| Fixes | `523a662` | fix(rounding): no rounding difference on a line tied to an outside source |
| Fixes | `04e2cb2` | feat(prior-pdf): the reading checked on the placed lines and the PPE note as one; a failed check reads its page again |
| Fixes | `5532d83` | fix(prior-pdf): the balance sheet's totals left to the reading's own balance check |
| Fixes | `bcead6c` | fix(prior-pdf): a required line is not another line's row |
| Fixes | `5623b50` | fix(prior-pdf): a cash flow must end on its closing cash |
| Fixes | `4071f19` | test(reference): labels compared as their words, numbers exactly |
| Fixes | `c8b9acd` | docs(plans): the fixes from the real-files run; waiting for the push instruction |
| Review 2 | `e8ceaf4` | fix(checks): accumulated amortisation and accumulated losses are not on the wrong side |
| Review 2 | `f67d899` | fix(notes): the going-concern paragraph's first clause starts without its connector |
| Review 2 | `4ef5daf` | fix(notes): last year's note a riyal off its line takes the riyal on its largest line, said, not blocking |
| Review 2 | `4330d1b` | fix(notes): a note last year's file printed for its line stays, though the line is «-» in both years (R11) |
| Review 2 | `ba671be` | feat(notes): other assets get their note (cost less amortisation), numbered on their line |
| Review 2 | `de2e3f8` | fix(names): a difference from last year's approved statements is named as one, once, not an account with no name |
| Review 2 | `4de9357` | fix(ui): a second click on save opens no second box |
| Review 2 | `bd3964b` | fix(ui): the company chosen on the classifier page is carried to the new report |
| Review 2 | `370c771` | fix(ui): a check on a note (the partners' table) links to the notes step, not the movements |
| Review 2 | `e0e08d7` | fix(ui): no heading left with nothing under it once its accounts moved away |
| Review 2 | `8e69ef3` | fix(ui): the wizard's side panel goes under the step up to 1440 px, so its tables need no sideways scroll |
| Review 2 | `1a0ba5a` | feat(ppe): additions with no depreciation get a proposal at last year's rate, flagged, never posted |
| Review 2 | `f625cde` | feat(notes): a supplier-finance account with a balance suggests «نعم» to the supplier-finance question |
| Review 2 | `3181b3e` | test(templates): the notes template's blocks counted with the other assets' note |
| Review 2 | `2355194` | docs(plans): the second review; waiting for the push instruction |
| — | `76368f6` | docs(plans): a prompt for a fresh agent to run a client end to end and verify it |
| Review 3 | `66a693e` | fix(notes): a provision's comparative closing is last year's approved line, not this year's opening |
| Review 3 | `0900d27` | fix(prior-year): a note figure shown in step 2 from what's in use is saved only when changed, never as typed |
| Review 3 | `889c2d5` | fix(notes): a note's table and its line tie in whole riyals, as both print |
| Review 3 | `10cd673` | fix(statements): a result line whose figure is still missing keeps both words, not the other year's sign |
| Review 3 | `d15e434` | fix(notes): the partners' review says a scan can read the names a row off their figures |
| Review 3 | `3191986` | fix(notes): last year's related-party line put on the one party whose account opened at another amount is flagged |
| Review 3 | `d87acd8` | docs(plans): the third review |
| Review 3+ | `e8c968e` | feat(zakat): a zero reason is the one switch for a year with no zakat |
| Review 3+ | (this commit) | docs(plans): the end-to-end prompt follows the third review's fixes and the zakat switch |

## 7. Implementation notes

- **D1–D5 as built:** D1 account totals per line and group in the accounts step; D2 one net PPE line, detail in the note; D3 new items «-» last year; D4 rows zero in both years hidden; D5 auditor's report skipped.
- **R8:** `comparative.opening_balances` = `present` (asked), `absent` (an opening column, all zero: first year, `automatic`, user can still give a prior year) or `unknown` (no opening column: inferred from balances, else asked). «أول سنة» saved with opening balances is the user's override (`skip_opening`).
- **R5 data:** `is.depreciation_amortisation` → `is.depreciation` (migration `2026_10_23_000001`); saved versions export their figure on the depreciation line (`FinancialReportVersion::RENAMED_LINES`).
- **R6 reading:** `StatementSynonyms::wording` reads الموجودات = الأصول, المطلوبات = الالتزامات, حقوق الشركاء = حقوق الملكية, استهلاك = إهلاك, and a joined «و» (migration `2026_10_23_000002`; synonyms `2026_10_24_000001`; known words `2026_10_24_000002`).
- **Retired inputs:** `dividends.proposed`, `capital.shares_in_words` accepted and ignored (`ReportIntake::RETIRED`).
- **Shares in words:** `ArabicNumberWords::counted` with the noun «حصة», genitive after «إلى».
- **Page numbers:** the brief's page numbers for the reference file are the printed ones; PDF page = printed page + 4.
- **Wording:** `Wording` (check, flags, apply, variants): never corrects a word to a shorter one; only the longest prefix variant; the notes' text goes through `Wording::apply` with the upload's `wording_choices` (`PUT …/statements-file/wording`).
- **Note lines (R11, R12, D3):** `PriorNoteItems`: a note's rows are last year's lines; accounts placed by choice, name (or class), opening = last year's amount, words; else new items with «-». Choices in `notes.items` (`PATCH …/notes`), searchable dropdown per account.
- **Company facts:** `CompanyFacts` (note 1 and the approval note) offered to step 2's empty fields; `PdfDefaults` (approving body, policies: labour law/actuarial, manufacturing/general inventory, FX, lessor, subsidiaries, revenue streams).
- **Going concern:** included by itself with its figures, a warning says so.
- **Comparability:** `ComparableLines::proposals()` / `strays()`; account overrides hold a line_key or a category.
- **Profit/loss wording:** `ProfitLossWording` in the statements (result lines, retained earnings), changes in equity, the notes' lines and the zakat base; flagged `label_by_sign`; combined forms first, gender agreement; «ضريبة الدخل» excluded.
- **Zakat base:** `ZakatBase` (COMPONENTS, derive within ±1, up to 4 components, a unique nearest set only); profit before zakat, provisions formed, zakat due from this year's statements; the rest asked as `zakat.base.<n>`, derived ones prefilled and flagged (`zakat_base_derived`).
- **PPE:** `PriorPpe` (categories, place, build): categories as printed, rates from the policies note (life = 100 / rate), last year's movement per category as the comparative, accounts placed by choice (`notes.ppe`), name, opening, or a word of one category; depreciation accounts follow their category; per-category opening vs last year's closing (±1 rounding). Excel/Word columns by these categories: Phase 9.
- **Useful lives (Step A):** the notes' useful-life table is one repeated row (`useful_life_label` / `useful_life_years`) per prior-year category as printed, each its own life (100 / its rate), prefilled until answered (`useful_life.c<n>`); without a prior-year file, the template's five rows (`useful_life.<buildings…>`, labels in `lang/ar/statements.php` `useful_lives`). Intangibles' lives stay in the text.
- **Generic layouts (Step A, P4):** `Tables::totalColumn` (the column adding up the others, at most a third of rows off; differences flagged `total_misprint`, the sum used), `Tables::transpose` (a movement table with categories as rows turned; parts from header words, the title, or the balances' order), `Tables::plainHeaders` (currency-only headers: years by position); `PriorPdfReader::conform` (a statement's later tables in the first's column order: by year, or the equity components' words); `PriorPpe` merges a separate depreciation table and finds the note by title; `Sections` reads «a/b» numbers by the note they sit in; `CompanyFacts` finds the company note by title; `Checks` ties a note only in the years it prints and finds the equity total column by arithmetic; `Wording` corrects a word printed with its article only to a word known with one.
- **Totals (Step A fix):** `PriorPdf\Totals::resolve` (after the links): each printed total differing from its columns' sum set against the note's statement line (`Sections::lineOf`) at the row's date (a net book value as is; a cost or depreciation balance as cost − depreciation at that date): `sum_ties` → flag `total_misprint`, `printed_ties` → `total_columns_differ`, else `total_choice`; `Totals::choice` (the accountant's `total_choices`, else the tying one, else the sum); `PUT …/statements-file/totals` (`use`: id → printed|sum|null); step 2 one-click switch; `PriorPpe::totals` (the note's total per row, for Phase 9's total column).
- **Seeded rows:** `statement_synonyms.seeded` (backfilled by `2026_10_24_000003`: seeded values, never edited, inserted with the starting list; cleared when a user edits one). A cleanup removes only `seeded` rows; a rule loses a seeded word only while its words are exactly the seeded ones.
- **Partners (Phase 7):** `PriorPdf\Partners::read` (capital note by link or title; columns by shares × value = amount in any order, the value column the same on every row / the text's value / headed «قيمة»; names corrected; `count_mismatch` → rows without names, the names listed); `PdfDefaults` prefills `partners`; `NotesBuilder` shows them until saved; `Partners::confirm` (a capital account in one partner's name — at least two telling words, no other partner as many — holding that row's amount at the year's start, else end); `partners_confirmed` (info) or `partners_review` (warning: `unconfirmed`, `count_mismatch`, `disagrees`; blocks the final version until acknowledged; its key holds the table); `partners_prior_capital` when last year's shares don't add up to last year's capital.
- **Year-end groups (Phase 7+):** `Totals::resolve` also returns `total_groups` (per movement table and year end: cost, accumulated, net book value balances; options = combinations of printed totals and columns' sums with cost − accumulated = net; status ties/only/choose/unlinked/none; default index); one flag `total_group_<status>` per group; a group's choice is an option index in `total_choices`; `Totals::choice/option/used` follow it.
- **Wording (Phase 7+):** decided by the vocabulary outside the file first; `split` corrections (silent) rejoin font-split words in terms and paragraphs (pieces ≥ 2 letters, apart by a space, one not a word on its own); `Vocabulary::general()` (`resources/wordlists`: FrequencyWords OpenSubtitles 2018, CC BY-SA 4.0, freq ≥ 20, normalised; plus `ar-accounting.txt`) never flagged, never a correction target; partners' names skipped.
- **Comparability (Phase 7+):** `ComparableLines::proposals` per line with a note: accounts from any differing line named as a note line (≥ 2/3 of its words in name or class, the best line) or the one equal by amount; then the one set (≤ 6) adding up to a note line or to what the line is short; side guard; the account's own line must come nearer. `OpeningCheck` compares in whole riyals (`kind()`), and a line last year's file doesn't print, with an opening balance, against «-» (unless a balance-sheet row of the file still waits to be placed).
- **Related parties (Phase 8):** `PriorPdf\RelatedParties::read` (note by link or title; tables per part under the part's title: volumes vs balances by words, side by «مستحق من/لـ», columns by header words: علاقة / طبيعة / حجم / the year; relationship from «name (relationship)» in the text) and `::proposals` (match by telling words, else the one opening = last year's balance; volume_cy = the balance side's movements, null without movement columns; new accounts as new parties; no note + one account + no openings: balance_py = the statement line); `NotesBuilder` uses them as the default table; `related_parties_unmatched` warning. Table cells of words are `text` (PageExtractor).
- **Approved openings (Step B):** `ApprovedOpening` (setting `prior_figures.approved_opening`, default on; `PUT …/prior-year/approved-opening`; kept by `PUT …/prior-year`). `CurrentYearStatements::build` builds on the TB as it is, `ApprovedOpening::plan` turns each difference (approved closing − TB opening) into rows of their own (numbers from 1,000,000; opening = closing = the difference, no movement; never accounts: no mapping, proposal or party): per note line of last year's (`notes.items` choices; accounts on no line, «new», start from nothing; what the note doesn't add up to its line goes on its largest line, shown as `remainder`), per PPE category and side (`notes.ppe` + own category; an account on no category starts from nothing, keeps its movement), the rest on the line; then builds again. TB-only reads stay raw: its checks, D1 line totals, R8 opening signal, `opening` (step 2's OpeningCheck), the PPE per-category opening check. Check 21 is replaced by an info `approved_opening` (the totals); `approved_opening_unavailable` (warning) without TB openings. State `comparative.approved_opening` {enabled, status applied|equal|no_opening|off, lines, totals, ppe}; step 2 panel (toggle, totals, lines, PPE); notes page shows the rows as «من القوائم المعتمدة» without a dropdown. Related parties: prior = the party's PDF balance (`balance_py`; the line for the one party of a file without a note), this year = it + the accounts' movement. Journal sheet: an information-only entry of the differences (not an adjusting entry, outside the totals and print area).
- **Word list licence (Step B.3):** `resources/wordlists/ar-common.txt` (FrequencyWords, OpenSubtitles 2018, CC BY-SA 4.0, adapted) stays a separate data file read at runtime by `Vocabulary::general`, with `LICENSE-ar-common.txt` beside it (source, author, links, changes made, the adaptation's licence and its scope); `WordListLicenceTest` keeps both and no words in code. No permissive list of comparable quality exists (the one MIT list is words harvested from classical books with merged and misspelled ones: it would hide reading slips); `ar-accounting.txt` is the project's own.
- **Amount-only proposals (Step B.2):** `ComparableLines::NATURES` (tax/zakat, revenue, expense, receivable, payable, related, employee, cash, inventory, fixed, loan) by words of the account's name + class and of the target note line (or line): disjoint → no amount or set match. Each proposed account carries `amount_only` (`by` ≠ name); step 2: a badge, its own button (per account or set), never in «move everything matched by name».
- **Class names (Step C.1):** `LineCatalogue::rule` / `sideRule` match a class by its exact name, else by the one rule name spelled the same but for spaces, punctuation, diacritics and letter forms (`ClassificationKey::name`); two alike → none (no guess). Cause on the reference draft: the user's taxonomy renamed the accruals main class (a missing space) and «عُهد وسُلف دائنه» → «…دائنة»; the run carries those names; where the rules carry the config names (the seeded taxonomy, tests, another installation) its accounts had no rule. Former names stay `ReportSource`'s. Every taxonomy class has a rule (the accruals class was the only gap); the reference fixture's 18 manual accrual overrides are gone (mapped by the rules).
- **Disposal results (Step C.2):** check `disposal_unmatched` (warning): the disposal results (config `disposal_results`) ≠ 0, no asset schedule with disposals, no proceeds entered; amount and accounts; never moved. Tag `not_disposal` (account override `tags`): the account stays on its line (other income / other expenses) but out of the disposal result (no cash-flow adjustment, no proceeds looked for); check `disposal_pending` (warning, «تصنيف في انتظار تأكيد المحاسب», amount, accounts) until acknowledged. Accounts step panel `[data-disposal]`: one click to tag (all the check's accounts), one click to undo. Reference: the account tagged in the fixture and in the user's local draft (the user's request).
- **Array keys (Step C.3):** `tests/Unit/ArrayKeysTest.php` parses `app/` and `config/` (nikic/php-parser, a dev dependency through PHPUnit) and fails on a literal key given twice; part of `scripts/related-tests.sh`'s guards.
- **Raw TB review (Step C.4):** under approved openings the raw TB is read only by: its own checks (`ReportChecks::run`: balance, classification, unmapped, wrong side; `sofp_unbalanced` / `net_income_mismatch` read the presented figures), the state's `mapping` (accounts step), D1 `line_totals`, R8's opening signal, step 2's `opening_check` and the move proposals (`ComparableLines`), the PPE per-category opening check, partners' name confirmation (identifies accounts, no figure out), and the movements. The exporters read `statements`, `schedules`, `notes`, `narrative`, `checks`/`missing`/`blocking` (draft sheet), `adjustments`, `inputs` and `comparative.approved_opening` (journal sheet's information block): presented figures only. One exception fixed: D1 showed each line's approved-opening difference as an unexplained «الفرق»; now `line_totals.<line>.approved_opening` (presented − TB) is its own column «من القوائم المعتمدة».
- **Retained earnings (C+):** EquityStatementBuilder: what retained earnings lose besides the result and the transfers (suggestion `soce.dividends`) goes on the row the input `soce.retained_movement` names (`dividends` | `prior_year_adjustment` | `other`; RETAINED_NATURES); unnamed, a dividend only when dividends payable or the partners' current accounts were credited as much (`declared`), else `other_retained_movements` labelled «حركة غير مفسرة في الأرباح المبقاة» (`label_set`), check `retained_unexplained` (warning, amount, accounts, `input_key`). Cash flow: dividends → `cf.dividends_paid` as paid = declared − Δ dividends payable − the partners' credits (`cf.noncash_distributions_to_partners`: their credit movement, or without movement columns as much as their balance grew, a suggestion; Step E.1); the others the non-cash operating `cf.prior_year_adjustments` / `cf.other_retained_movements`. A prior-year adjustment chosen: warning `prior_year_adjustment_pending` (Step E.2; the movements step's `[data-retained]` panel stays with it). New template rows (SOCE both blocks, CF), README. Movements step: one-click panel `[data-retained]`.
- **Auditor's report (D.1):** `PriorStatementsPdf::hasAuditorReport` (a PDF needs a page of kind `auditor_report`; Excel not concerned); `PUT …/statements-file/pages` 422 and `PUT …/prior-year` 422 without it (`pdf_no_auditor_report`); `upload.rejected` (step 2 `[data-pdf-rejected]`). The contract tests' fake AI gives a report page.
- **Second layout (D.2):** `Tables::roles` (a 1 January column = the year before's end), `Tables::adds` (≥ 3 columns: one off allowed, `off`; «-» under «-» counts for nothing), `PageExtractor::cell` (`percent`, `range`, words with digits `text`), `Tables::isRates` by cells, `Tables::isBalance` («كما في <date>»), `Tables::NBV`, `Tables::isMovement` (movements between balances, or an opening balance rolling to the last row), `Checks::cashEnd` (cut only before a heading), `PriorPdfReader::badness` (re-read kept by unreadable rows), `PriorPpe::rate` (ranges → `rate_range`, `life_range`; notes print «5 - 10»), `Partners::byYear` (shares in names, latest year's amount column, `former`; value from «حصة بقيمة»), `RelatedParties::sideways` (a party per column; side «مطلوب من/إلى»; one row a side), `EquityBlock` (a closing is the next opening; «بعد الزكاة», «الشاملة الأخرى»), `CompanyFacts` (name without the sentence; framework from the compliance note: `ifrs` | `ifrs_for_smes`), `Wording::names` (partners, former partners, related parties never corrected word by word; figures no wording), `FileFacts::flags` (`framework_full_ifrs`, `year_typo_rows`, `transition_note`, `partners_changed`). The extract command checks wording as the app does. All digit maps → `AmountParser::asciiDigits`.
- **Figures** carry an origin: `user_input`, `derived`, `prior_year_statements`, ….

## 8. Known issues

- The reference PDF has a damaged xref table; qpdf repairs it with warnings (exit 3, treated as OK).
- **Reference-client results (details in the fixture):** statements 23/23 checks; cash flow aligned by arithmetic; 2 wording corrections; 0 mapping rows waiting (one cash-flow wording is the client's own synonym, added by the test from the fixture as the accountant would add it once; not seeded); the PPE total column differs from its categories on 4 rows: one where the sum ties (misprint), one where the printed net book value ties (used), two where neither ties (the accountant chooses); partners 6/6 read, the trial balance has one capital account, so the table needs review; no related parties' note (the TB's one account proposed, last year's balance from the statement line since Step B); the TB's openings differ from last year's approved closing in total (likely unposted audit adjustments; since Step B the approved closing is used and the differences are reconciled and shown, information only); comparability proposals on the accruals and 15 unplaced trade-payables accounts (the rest is a real difference); zakat deductions derived exactly, additions manual; PPE 8 categories, 16/16 accounts placed, one category's accumulated depreciation differs by 2 (a real difference), the printed categories sum one riyal over the printed total (source rounding).
- **Wording flags on the reference client:** 13 (1 `wording_differs`, 12 `wording_unknown`): the two real slips, partners' names (4), and words no source has. Names are worth a look (R14 copies them).
- **Column order:** the AI sometimes lists a table's columns left to right instead of nearest-the-labels first. Harmless: years are ranked by the year printed, category columns read by header.
- **Notes' titles** still differ from some line names as the reference has them (note «الذمم التجارية الدائنة» vs line «ذمم تجارية دائنة»).
- The classifier's own statement values («قائمة الدخل» in the taxonomy and AI prompts) are classification data, not output; left as they are.
- **Known limitation (R5):** a version saved before the depreciation/amortisation split keeps one combined figure, shown on «مصروف الإهلاك». Versions are immutable.
- **Phase 9 render (LibreOffice 26.8.1, Sakkal Majalla absent → Noto Naskh Arabic by a replacement table in a scratch profile; never downloaded):** files and page images in `docs/reference/output/` (`reference-statements.xlsx/.pdf`, `reference-notes.docx/.pdf`, `reference-*-pages/`, `templates/` for the references' own renders, `reference-state.json`). Left as differences: the `data` sheet's cleaned layout and the cover/index without the auditor's report (Q28) are by design; the index's notes pages «5» not «5-19» (the Word file's pagination is unknown to the Excel export); share capital now shows its registered amount (the riyal on retained earnings, Final phase); narrow amount columns (two-year tables, zakat base, PPE with 8 categories, one SOFP comparative «###») sized for Sakkal Majalla: overflow with the substitute font, to check in Excel/Word; note numbers on their own line from 10 and a stray «0» under a split table row: LibreOffice (the references render the same); the related-party captions sit after the first table in the reference too.
- **Required inputs prefilled but still counted missing** until saved: the useful lives (100 / last year's rate), the partners (review rule), the related parties' table: one confirmation each before a final version.
- **Step A audit, left as is:** `PdfDefaults` reads policies and `EquityBlock` reads rows by general accounting words (unknown: flagged or manual); legal form: only the LLC is supported product-wide (`CompanyController`: `in:llc`), another form leaves the name and form manual; the partners table is only read as printed (the assignment is Phase 7); the classifier's sample fixtures on main (another client's anonymised TB, no client name) predate this branch.
- **Removed from seeds (Step A):** a client's typo in the pre-operating rules' words and a client's cash-flow wording from the starting synonyms, by migration `2026_10_24_000003` (it deletes that synonym label wherever it is, a user-added one too).
- **History:** client data already on `origin/main` (two 2026-10-19 review docs and one line of `docs/17-classification-rules.md`) is removed on this branch by plain commits; main's history is not purged (the user's decision).
- **Browser test:** the Phase 5 intermittent wizard failure never reproduced in 18+ runs; hardened (10 s waits, waits after saves). Watch for it in the final full run.
- **AI cost so far:** Phase 3 first full read ≈ $0.39 (classify $0.0711, extract 12 pages $0.2835, cash-flow re-reads ≈ $0.10); Phase 4 $0.0066; Phases 5–6 $0. A re-read of the same file costs $0 (cache).

- **Step B on the reference client** (figures in the gitignored fixture, `approved_opening`): setting applied; the TB's openings' differences from the approved closing in total (assets, equity, liabilities) equal the user's own figures once the draft's unmapped accounts sit on accruals; each line's presented closing = TB closing − difference; the BS balances; equity rolls from the approved opening; related parties at the approved line in both years; the PPE opens at the approved categories' closing, only one category still depreciates; the only note mismatch left is last year's own other-receivables note a riyal off its line (put on its largest line, shown). The deferred-revenue → VAT proposal is gone.
- **Step B, limits as built:** a note covering several statement lines gets its difference per line, not per note line; a PPE category with no account of the template's own category gets its rows without one (the PPE schedule then asks for a category); a related party with no balance in last year's file shows «-» last year (new); several parties of a file without a note can't share the line's balance (the note's tie check flags it); a party table saved before Step B keeps its typed prior balances (the tie check flags a mismatch); the approved openings' rows show on the notes page as «من القوائم المعتمدة» and can't be moved; each statements build runs twice (≈ 0.02 s each on the reference client).
- **The reference client's gain on disposal (Step C.2):** other income classified as a disposal gain with no PPE disposal and no proceeds; tagged other income pending the accountant's final classification (`disposal_pending` stays until acknowledged). With it, the cash flow closes (figures in the gitignored fixture). Its retained earnings move by more than the year's result: since C+ the rest is an unexplained retained-earnings movement (equity row and a non-cash operating cash-flow line, flagged `retained_unexplained`), not distributions paid; dividends paid «-». Pending with the accountant (dividends paid / prior-year adjustment / other).
- **Second file (D.2), as read (details in docs/reference/brief-and-replies.md):** the AI misreads some digits on this scan (e.g. due from related parties, due to related parties, comparative liabilities); reading again gives the same digits; the arithmetic checks flag each page (P3). The equity page's capital and reserve columns are unreadable to it (flagged). A sideways related-parties table's sub-column («payments on behalf») comes out as a party of its own. A one-line OCI subtotal followed by the grand total isn't taken for a total (the statement's last check fails, flagged).
- **Tools:** poppler (pdftoppm) and LibreOffice 26.8.1 (`brew install --cask libreoffice`, 2026-10-25) installed with Homebrew; renders use a scratch LibreOffice profile replacing Sakkal Majalla by Noto Naskh Arabic (the files keep Sakkal Majalla).
- **The user's local reference draft** has no prior-year PDF attached: its figures use the TB's openings (the fixture test attaches the PDF and uses the approved ones).
- **Class names elsewhere (Step C.1, not changed):** the schedules', related parties' and periodic-cost class lists still match exact names (renames follow through `TaxonomyRules`, former names through `ReportSource`); only a run from another taxonomy with a respelled class would miss them.

- **Live reads vary.** Final phase: two fresh reads against the cached one; every statement amount the same; read 2 split the PPE note into three tables, lost the gross-profit label, the net change's mapping and the CR's Hijri date, and no check flagged it. Fixes round: one more live read (read 3) through the app's path. It kept the PPE note whole and read the Hijri date, but lost gross profit's label and read the cash flow a row off its lines: the new checks re-read those two pages once each (first after two of the checks were corrected: `bcead6c`, `5623b50`), and both came back right (gross profit on its line; the cash flow placed by its arithmetic). The reference tests on the reads' own answers still differ where the reads do (a reading slip's word, the fixture's cached misprint «صباريج» against a correct «صهاريج», the approval body's wording).
- **Reference client after the fixes** (figures in the gitignored fixture and output): cash shows the bank's amount (the approved sub-riyal on retained earnings); capital its registered amount; the changes in equity close at the balance sheet's equity; deferred revenue still zeroed by the approved openings (its account's nature differs from last year's other-payables line, so no proposal moves it): `approved_opening_sign` says so; related parties' note flagged until the party's signed balance is saved (`note_total_mismatch`). The other session's real report in the local database has no comparability proposal: its related-parties line carries an approved-opening row with no party until the accountant saves the proposed balance.
- **Client data in history:** the two old commits rewritten locally (2026-10-25); a full rescan of every branch commit found only a code constant and a general word list entry. `43618b2` (already on `origin/main`) still holds a client account name and number: not rewritten (`main`).

- **Second review, limits as built:** the additions' depreciation proposal reads the trial balance's movement columns (a closing-only TB gives none) and only categories with no depreciation at all this year, at a full year's rate; the supplier-finance suggestion matches account names by whole words from `supplier_finance_words`; the other-assets note has no movement table (cost and accumulated amortisation by class, as the cash note's layout); the local draft keeps the other session's saved «لا» for supplier finance (the suggestion shows only once it is cleared) and its zakat-provision account moved to other receivables (left as it is, the user's decision).

## 9. Next step

**Waiting for the user:** the push instruction (nothing pushed; `8f972b6`, `2356b38` included), and the confirmation to delete `backup/prior-year-before-rewrite` and `refs/original/…` (kept until then). The accountant's questions, in order (with figures in `docs/reference/accountant-questions.md`): (1) what the disposal-gain account's credit is: income, a gain on a disposal (then the asset and proceeds), partners' money or a liability, each changing profit, equity, the zakat base and the going-concern indicators; (2) the retained-earnings movement's nature (a prior-year adjustment under Section 10 conflicts with approved comparatives); (3) the deferred revenue's line (`approved_opening_sign`); (4) first application of the third edition; (5) which accounts made last year's related-parties balance; (6) the proposed depreciation on additions; (7) supplier finance details.
