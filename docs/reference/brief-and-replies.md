# The user's brief and replies, verbatim (client data: gitignored, never commit)

# Task: Prior-year PDF ingestion + accountant fixes + output matching the reference templates

You are working in Accounting-System/accounting-import. Read this whole prompt before doing anything.
Work phase by phase. After each phase STOP, give me the summary described in Section 3, and wait for me
to say "continue". Do not start the next phase on your own.

## 0. Reference files (docs/reference/)
- قوائم تاج العوازل 2024 المعتمدة.pdf: last year's approved statements (scanned images, 12 pages). This is our main test fixture.
- القوائم المالية لشركة SMEs .xlsx: THE target format for the Excel output (statements + PPE note).
- الإيضاحات المتممة - نسخة منقحة.docx: THE target format for the Word notes output.
- ميزان مراجعة تاج العوازل 2025.xlsx and ميزان المراجعة 2025-12-31.xlsx: current-year trial balances.

The most important requirement: the final Excel and Word outputs must match the two template files in
the layout, the sheet names, the order of the sections, the fonts, the RTL direction, the number formats, and the headings.

## 1. Core principles (apply to every phase)
P1. READ EVERYTHING FROM THE PRIOR-YEAR FILE. All five statements (financial position, comprehensive income,
    changes in equity, cash flow, and the balance sheet totals for verification), ALL notes (numeric AND text),
    and the company information. Nothing in the prior-year file is skipped except the auditor's report pages.
P2. MINIMIZE ACCOUNTANT INPUT. Any value we can get, we fill automatically, and the accountant only reviews it. The sources are, in order:
    (a) the current-year classified trial balance (our classification step) for current-year figures,
    (b) the prior-year PDF for comparatives, wording, line items, categories, partners, related parties,
        company info, policies, and depreciation rates,
    (c) values computed from (a) and (b) (e.g. opening balances = prior closing balances, roll-forwards, going concern figures),
    (d) manual input, only as a last resort.
    Every auto-filled field stays editable. In every phase summary, list the fields that STILL require manual input
    and say why, so we can keep reducing them.
P3. NEVER GUESS SILENTLY. If something is uncertain (a failed check, an unclear page, an inferred year), auto-fill your best value
    AND show a visible flag to the accountant with the page number.

## 2. Git rules
1. Do NOT push anything, at any point, until I explicitly tell you to push. This includes the 2 existing
   unpushed commits on main. Pushing happens only at the very end, on my instruction.
2. First, show me `git log origin/main..main` so I see the 2 unpushed commits, then create a new local branch from
   the current main, e.g. `feature/prior-year-full-ingestion`.
3. One commit per logical item (each numbered item in a phase = its own commit). Clear messages, e.g.
   `feat(notes): read prior-year notes with original line items`.
4. Never commit secrets or API keys. Ask me before committing client reference files that are not already tracked.

## 3. Tests
- During each phase, run ONLY the tests related to the files/behaviour you changed, and add tests for new logic.
- Do NOT run the full suite during the phases. Run the FULL test suite once, in the final phase.

## 4. Phase summary (in English, at the end of every phase)
- What was done (a short list)
- Commits (hash + message)
- The related tests that were run and their results
- The result on the Taj Al-Awazel fixture: which acceptance checks pass and which fail, with numbers
- The fields that still need manual accountant input (P2), and why
- Anything worth telling the accountant (flags, assumptions)
- What the next phase will do, and anything you need from me first
Then stop and wait.

Also maintain `docs/plans/prior-year-ingestion-progress.md`, updated after every phase with: the phases done,
the commits, the decisions, the known issues, and the exact next step. A new session must be able to continue from this file alone.

## 5. Accountant rules (all must be implemented)
R1. Zero is shown as a dash "-" everywhere: screens, Excel, Word, PDF exports.
R2. Every dropdown/select in the UI has search (one shared component used everywhere).
R3. The approving body (جهة الاعتماد) defaults to "الإدارة".
R4. Remove "الأرباح المقترح توزيعها بعد نهاية الفترة" / "الأرباح المقترح توزيعها" from all outputs (the Word template has this section, remove it).
R5. Depreciation and amortization are separate lines everywhere: income statement, cash flow, notes.
R6. One fixed name per statement and per line item, used everywhere (UI, Excel, Word). Use the template names:
    "قائمة المركز المالي", "قائمة الدخل الشامل", "قائمة التغيرات في حقوق الملكية", "قائمة التدفقات النقدية".
    Build a synonym map for reading the PDF, e.g. الموجودات = الأصول, حقوق الشركاء = حقوق الملكية,
    قائمة الأرباح أو الخسائر والدخل الشامل الآخر = قائمة الدخل الشامل,
    إستهلاك ممتلكات وآلات ومعدات = اهلاك الموجودات الثابتة.
R7. The number of shares in words is generated by deterministic code (Arabic number-to-words), not by AI.
R8. Request the prior-year statements ONLY if the trial balance has opening balances; otherwise skip that step. The accountant can override manually.
R9. Do not read or output the prior-year auditor's report (تقرير المراجع المستقل).
R10. Prior-year subtotals/totals are NOT stored as line items, and main headings are not written as items
    (e.g. مجمل الربح, مجموع المصروفات, صافي الدخل قبل التغيرات في رأس المال العامل). They are used only for verification.
    A total = a line that equals the sum of the lines above it. Do NOT detect totals by the word "إجمالي":
    the zakat note has "إجمالي الإضافات إلى الوعاء" and "إجمالي الحسميات من الوعاء", which are real line items to keep.
    Empty headings with no items under them (e.g. "يضاف:") are dropped.
R11. Every note includes the comparative year, taken from the prior-year PDF with EXACTLY the same line items and
    wording: no adding, no removing. This includes the revenue and expense notes. Prior-year numbers are copied as-is.
R12. Current-year numbers come from the classified TB, mapped to the same note lines. The system suggests the mapping,
    and the accountant confirms or changes it through a searchable dropdown.
R13. Fixed assets: read the movement table by category from the PDF; the categories come from the PDF.
    The opening cost and the opening accumulated depreciation per category = the prior-year closing values. Do NOT ask the accountant
    to assign a category to accumulated-depreciation accounts when the prior year covers it. Current-year
    depreciation is distributed normally. Remove the false "إهلاك السنة [ناقص]" warning: a category fully
    depreciated last year (net book value 0 or 1) has zero depreciation this year, shown as "-", with no warning.
    Auto-fill the depreciation rates per category from the PDF's policy table.
R14. Partners: read the names, the number of shares, and the share value from the prior-year capital note; compute the amount and the share percentage;
    prefill the table; the accountant only reviews it.
R15. Related parties note: same format as the prior-year note, with current-year numbers.
    From the PDF: the names, the nature of the relationship, the nature of the transactions, the prior-year transaction volume, and the prior-year balance.
    The prior-year closing balance comes from the PDF only if the TB has no opening balances; otherwise from the TB.
    Current-year balances come from the classified TB. The current-year volume comes from the TB movement columns if they exist; otherwise manual.
    If the PDF has NO related-party note (Taj has none, only the balance sheet line "مستحق إلى أطراف ذات علاقة"):
    take the prior balance from that line, auto-fill the names from the TB related-party account names, and use the template's
    related-party table layout. Only the nature of the relationship/transactions stays manual in that case.
R16. Year typos in the PDF: the years on the main statements are the truth (Taj: 2024 current, 2023 comparative).
    In every table, the first numeric column = the most recent year. If a page shows other years (e.g. 2023/2022),
    continue with 2024/2023 and show a visible flag, e.g. "صفحة 9: مكتوب 2023/2022 واتعامل على أنه 2024/2023".
    Numbers CONFIRM (a note total = a statement line), but are never the only signal, because many notes have identical
    values in both columns. Also flag year typos inside the text (Taj note 15 mentions 2021).

## 6. Decisions already made (do not ask; implement and mention them in the final summary)
D1. "في الحساب والقيود عاوزه يقولي الإجمالي": in the accounts & entries step, show the total of the mapped accounts
    for every statement line and every group, plus the overall debit/credit totals, so the accountant can see each
    line ties to the statements.
D2. "في الأصول الثابتة خد بس الإجمالي": the statements show one net PPE line; the full category detail is in the
    PPE note only. In the accounts step, PPE accounts are grouped with a single total, with no per-account category questions when the prior year covers them.
D3. A new current-year note item with no prior-year line is added, with "-" as its prior-year value.
D4. Template rows that are empty/zero in BOTH years are hidden.
D5. The auditor's report pages are skipped (R9).

## 7. Output format rules (from the templates)
- Excel sheets, in this order: data, الغلاف, الفهرس, المركز المالى, قائمة الدخل الشامل, قائمة التغيرات,
  قائمة التدفقات النقدية, ايضاح الاصول والممتلكات. RTL. Font Sakkal Majalla (sizes as in the template).
  Keep the template's number formats: thousands separator, negatives in parentheses, zero as "-".
  The headers and dates are driven by the `data` sheet, as in the template.
- Do NOT copy the template's bugs: the `data` sheet says "31 يناير 2025" where it must be "31 ديسمبر", and several
  cells contain #REF!. Totals must be live SUM formulas where the template uses formulas, and the output must open with
  zero formula errors.
- Line items: the template rows are the layout; the actual line items and the PPE categories come from the prior-year
  PDF + the TB (Taj has 8 PPE categories, while the template shows 6, so output 8).
- Word notes: follow the docx structure, numbering style, headings, and table layouts (two-column 2025/2024 tables,
  a PPE movement table per year, partners table with the share percentage, related-party tables, zakat movement table).
  The prior-year line items and wording replace the template's placeholder rows (R11).
- The text notes are filled from the prior-year PDF, never left as "......": the company name, the legal form, the CR number and
  date, the activity, the city/address (Taj = الدمام, not the template's الرياض), the financial year, the useful-life /
  depreciation-rate table, and the approving body and date. The `data` sheet (company name, entity, dates) is filled from the same source.
- Going concern paragraph: compute its numbers from the current-year figures (net loss, accumulated losses vs half of
  capital, current liabilities minus current assets, total liabilities minus total assets). Include it only if the conditions are met,
  and flag it for review.
- Any placeholder that cannot be filled from the PDF or the TB must be listed in the phase summary.
- In Phase 0, inspect both templates programmatically (openpyxl / python-docx) and write
  `docs/reference/template-spec.md`: the sheets, the rows, the fonts, the number formats, the merged cells, and the note sections. All output code follows this spec.

## 8. PDF reading strategy (accuracy over cost; multiple AI calls are fine)
The PDF is scanned images. Do not read it in one call. Pipeline:
1. Classify each page: cover / index / auditor report (skip) / balance sheet / income statement / equity /
   cash flow / text note / numeric note. Detect rotation: pages 3 (equity) and 8 (PPE) of the Taj file are landscape
   and must be rotated before reading. The page-classification table is editable by the accountant.
2. Extract each page separately at high resolution into structured JSON (lines, note refs, columns, years, text).
3. Verify with arithmetic: subtotals, statement-to-note ties, BS balance, equity roll-forward, PPE net = BS line,
   cash flow closing cash = BS cash, partners' shares = capital. If a check fails, re-extract ONLY that page (up to 2 retries),
   then flag it with the page number.
4. Note linking: by the note number AND the title AND the amount together. Taj has a numbering error inside note 4
   ("5 النقد وما في حكمه" between 4.5 and 4.6), which must not be confused with note 5 (PPE).
5. Nested notes keep their levels (Taj note 13 has a sub-note "مصاريف تشغيلية*").
6. Equity: Taj has BOTH years in one table; take the 2024 block as the comparative.
Cache the extraction results per file hash so re-runs don't re-pay.

## 9. Phases (stop after each one)
Phase 0: Show the unpushed commits (no push), create the branch, write template-spec.md, and write a gap report of
         how the current output differs from the templates. No feature changes yet.
Phase 1: Quick fixes: R1, R2, R3, R4, R5, R6, R7, R9, D1.
Phase 2: R8 (conditional prior-year request + manual override).
Phase 3: PDF pipeline foundation (Section 8): page classification incl. all notes, note linking,
         year handling (R16), totals/headings exclusion (R10).
Phase 4: All five statements read, mapped, and verified, including the cash flow and the changes in equity.
Phase 5: Numeric notes with exact prior-year items (R11, R12, D3), plus the text notes and company info (Section 7).
Phase 6: PPE and accumulated depreciation (R13, D2).
Phase 7: Capital and partners (R14).
Phase 8: Related parties (R15).
Phase 9: Excel + Word output exactly per template-spec.md (Section 7, D4). Generate the Taj 2025 output, render it
         (LibreOffice → PDF → images), compare it visually with the templates, and report every difference.
Final:   Run the FULL test suite, fix any failures, update the progress file, and give a final summary
         (all decisions D1–D5, the remaining manual fields, the known issues). Then wait for my instruction to push.

## 10. Acceptance checks on the Taj fixture (comparative column = 2024)
Balance sheet: PPE 2,128,891; other assets 1; inventory 2,090,686; trade receivables 3,977,196;
prepayments 37,665; cash 204,389; total assets 8,438,828; capital 16,000,000; retained (15,817,129);
equity 182,871; trade payables 4,258,873; accruals 2,896,479; due to related parties 1,100,605;
zakat provision "-"; total liabilities 8,255,957.
Income statement: revenue "-"; operating costs (11,826); G&A (392,866); depreciation (356,756);
amortization (13,099); net loss before zakat (774,547); zakat "-"; net loss (774,547).
Equity: 1 Jan 2024 = 957,418; loss (774,547); 31 Dec 2024 = 182,871.
Cash flow: change in due to related parties 404,692; closing cash 204,389.
PPE closing accumulated depreciation 31/12/2024 (= the 2025 opening): buildings 2,434,609; furniture 460,863;
computers 175,152; tools 699,520; scale 53,499; cars 1,070,990; machinery 3,822,176; tanks 1,853,637;
total 10,570,445. Cost total 12,699,337. Only the buildings still depreciate (228,175/yr).
Other assets: NBV 1, so the 2025 amortization = "-".
Partners: 6 partners, 3,200 shares × 5,000 = 16,000,000 (shares 100, 460, 546, 100, 500, 1,494; verify the names against the PDF).
Company info: CR 2050078341, Dammam, IFRS for SMEs, approved by الإدارة on 2026/07/19.
Year-typo flags are raised for notes 7, 8, 9, 11, 12, 13 (labelled 2023/2022) and the text of note 15 (2021).
Notes reproduce the exact prior-year lines: inventory = 4 lines, prepayments = 4, cash = 2, accruals = 5,
G&A = 3, zakat base incl. the two "إجمالي" lines.
Write these as automated regression tests against the cached extraction.

Start with Phase 0 now.

---

Good work on Phase 0. Answers and adjustments:

1. Client data in tests: approved. Keep the Taj extraction and expected values in docs/reference/ (gitignored),
   and use made-up data for the generic tests. In every phase summary, state explicitly whether the Taj regression tests
   actually RAN locally (and their results) or were skipped. A skipped run does not count as passing.

2. Sheet names: use the reference names exactly («المركز المالى», «ايضاح الاصول والممتلكات», etc.),
   and drop the trailing space in «قائمة التغيرات ».

3. Extra sheets: OK, keep the journal-entries and draft sheets after the 8 template sheets.

4. Negative numbers: keep parentheses everywhere for now (this matches last year's PDF). I'll confirm with
   the accountant; make it a single setting so it's easy to switch.

5. Reduce the manual fields you listed. Add these to the relevant phases:
   - Useful lives: derive them from the PDF's depreciation rates (useful life = 100 / rate, e.g. 5% → 20 years). Phase 6.
   - Policy choices (inventory, end-of-service, revenue, provisions): prefill them from the prior-year
     policy notes (note 4). Phase 5.
   - Going concern: not a manual decision. Compute the conditions from the figures (accumulated losses vs half
     of capital, current liabilities vs current assets, total liabilities vs total assets), and include the paragraph automatically
     when they are met, flagged for review. Taj meets the half-of-capital condition. Phase 5.
   - Zakat: prefill the zakat base structure with the same lines as last year's note 12, with current-year
     figures where they can be derived from the TB; only the remaining adjustments stay manual. Phase 5.
   - Company info and approval: from note 1 and note 19 (approval date manual, body defaults to الإدارة).
   Keep listing what is still manual after each phase.

6. R5: when you split depreciation and amortisation, make sure the notes follow the same split, not only the
   income statement.

Continue with Phase 1.

---

Phase 1 looks good. Four points before Phase 2:

1. Partners (for Phase 7, note it in the progress file now): I read 6 partner names on that page, matching the 6
   share rows (100, 460, 546, 100, 500, 1,494 = 3,200). The 7th is likely the «الشركاء» header or the «الإجمالي» row.
   Also, in the scan the names are slightly offset vertically from the numbers, so a row-alignment error is possible.
   Rule: if the name count ≠ the share-row count, never assign by guessing; flag it for the accountant.
   Validate the assignment by the total: shares × 5,000 must equal each row's amount, and the sum must be 16,000,000.

2. Shares in words: «ثلاثة آلاف ومائتان حصة» is grammatically wrong. When مائتان is followed by a counted noun, the ن drops:
   «مائتا حصة» (nominative) / «مائتي حصة» (genitive). In the capital note the context is «مقسم إلى ... حصة»,
   so the correct form is «ثلاثة آلاف ومائتي حصة». Make the converter aware of the following noun and the case
   (at least the dual/idafa forms: مائتا/مائتي, ألفا/ألفي, اثنتا/اثنتي), with tests. Default to the genitive for this note.

3. Removed questions returning 422: confirm that nothing still sends `dividends.proposed` or `capital.shares_in_words`
   (the frontend, saved drafts, any API client). If unsure, accept and ignore them, with a deprecation note,
   instead of rejecting them.

4. Old saved versions with combined depreciation + amortisation: fine to leave as-is, but record it in the progress file
   as a known limitation.

Fix points 2 and 3 as separate commits (related tests only), then continue with Phase 2.

---

Phase 2 is good. Three additions, then continue with Phase 3:

1. Closing-only trial balance: don't always ask. Infer from the balances: if the TB has a non-zero balance on
   accumulated depreciation/amortisation or on retained earnings (or accumulated losses), the company has prior years,
   so the prior-year statements are needed. Decide automatically, show the reason on screen, and keep the manual override.
   Ask only when there is no signal either way. Separate commit, related tests only.

2. Add a cross-source check to Phase 3/4: every opening balance in the current TB must equal the matching
   prior-year closing figure read from the PDF (e.g. accumulated depreciation on buildings: TB opening 2,434,609 =
   PDF closing 31/12/2024 2,434,609). Show matches and differences per line, and treat a difference as either a reading
   error (re-extract that page) or a real difference (flag it for the accountant). Use this check as extra
   validation of the extraction, and add it to the Taj regression tests.

3. Match TB accounts to PDF lines by amount as well as by name, because the names differ. Example: the TB account
   «مجمع اطفاء مصاريف ما قبل التشغييل» corresponds to note 6 «أصول أخرى» (accumulated amortisation 238,167).

Phase 3 cost is fine. Make sure the cached extraction lives in the gitignored docs/reference/ area, and report
the actual cost of the run in the Phase 3 summary.

---

Phase 3 is strong. Five points, then continue with Phase 4:

1. Cash flow misalignment: instead of only proposing the implied value, solve it generally. Read the label column and the
   amount column as two ordered lists (including every «-»), then choose the alignment for which all the subtotals and the cash roll
   hold. If exactly one alignment satisfies all checks, use it and flag it as "aligned by arithmetic" with the page;
   if several or none do, flag it for the accountant. This should fix page 8 and any similar scan.

2. Wording accuracy (R11 copies text exactly, so OCR slips would be reproduced):
   - Cross-check repeated terms inside the same PDF (e.g. the PPE category «صهاريج وتنكيات» also appears in note 4's rate table).
     If two readings of the same term differ, flag it.
   - Flag words not found in the TB account names, the templates, the taxonomy, or the rest of the PDF.
   Show these flags in step 2 with the page.

3. Small differences in opening vs prior closing: show every difference to the accountant, including ±1. Label ±1 as
   "probably rounding", but never hide it.

4. Client data in git:
   - Rewrite/squash this branch's unpushed commits to remove the client data before any push: approved.
   - Also check the 2 older unpushed commits on main (2356b38, 8f972b6) for client data.
   - For the docs already on main from 2026-10-19: tell me whether they are already pushed to the remote. Do NOT rewrite
     main's history or force-push anything; propose a plain removal commit, and I'll decide about purging the history.

5. Fixture coverage:
   - Add explicit assertions for note 12 (zakat, including the two «إجمالي» lines kept as real lines and the movement table) and
     note 13 (the nested «مصاريف تشغيلية*» sub-note), plus notes 5, 6 and 10 line by line.
   - In the final phase, run two fresh, uncached live reads of the reference PDF and compare them with each other and with the
     expected values, to measure the reading stability. Report the cost and any differences.

---

Phase 4 is excellent. Points, then continue with Phase 5:

1. Partners (important): the shares × 5,000 = amount check validates the numbers only, NOT the name-to-row assignment.
   If every name shifted by one row, the check would still pass. Add a name-level signal:
   - If the TB has partner current accounts or capital accounts by name, match the names to the shares with them.
   - Otherwise, mark the partners table "requires accountant review" and block approval until it is reviewed.
   Record this in the progress file for Phase 7.

2. Misprints in the source PDF (e.g. «للمتلكات» printed in note 3.1): treat them like the year typos (R16). Output the
   corrected word, show a flag with the page and the original text, and let the accountant revert it with one click.
   An AI reading slip (e.g. «صباريج») is simply corrected to the printed word, also flagged.

3. Wording flags: when the accountant dismisses a flag on a correct word or name, remember it (add it to a
   vocabulary/allow-list), so the same word is not flagged again next year. This reduces the manual work over time.

4. Removal of the client data already on main: yes, make the plain removal commit (delete the two review files and generalise
   the line in docs/17-classification-rules.md), but on THIS feature branch, not on main, so it goes out with
   everything else. Don't rewrite main's history and don't force-push.

5. Backup branch `backup/pre-scrub-ingestion`: you verified the file tree is identical, so delete it now rather than
   keeping it until the push. That way it can't be pushed by mistake (e.g. `git push --all`).

---

Phase 5 is good. Four points, then continue with Phase 6:

1. Classification differences vs last year (important): when this year's TB puts accounts on a different line than last year's
   statements (e.g. the accruals in trade payables, leaving «مصاريف مستحقة وأرصدة دائنة أخرى» empty), use the opening-balance check
   to PROPOSE moving those accounts back to last year's line, so the statements stay comparable. Show it as a suggestion per line,
   with the amounts, accepted with one click (or all at once). Don't leave it for the accountant to discover.

2. Profit/loss wording: R11 is about keeping last year's line items, not keeping a wrong sign. Switch ربح/خسارة (and similar
   pairs like «صافي الخسارة قبل الزكاة» / «صافي الربح قبل الزكاة», «صافي خسارة السنة» / «صافي ربح السنة») automatically by the sign
   of this year's figure, in the notes AND the statements, and flag the change. Keep the comparative wording consistent with its own sign.

3. Tests:
   - Identify and fix the intermittent wizard browser test before the final phase; a flaky test can hide real failures.
   - Since a failing test was missed for two phases, always include the cross-cutting guard tests (UI words, statement-name
     guard, API contract) in every "related" run from now on.

4. Zakat base: try to derive the additions and deductions from the statements, using last year as the reference. Example:
   last year's deductions 17,171,474 ≈ accumulated losses at the start of 2024 (15,042,582) + PPE net (2,128,891) = 17,171,473.
   Work out which components reconcile last year's additions and deductions (within ±1), apply the same composition to this year's
   figures as a prefilled suggestion showing its components, and flag it. If last year's figures can't be reconciled, keep them manual.

---

(Session prompt, 2026-10-24: Step A before Phase 7)

NEXT: Step A (do this first, then stop and report)
1. Useful lives must not be merged into the template's categories. "Machinery: 6.67 - 20" means 4 of last year's categories
   were folded into one template category. The useful-life table lists every prior-year category with its own life
   (the reference file: 8 categories: 20, 5, 4, 6.67, 10, 5, 20, 10). The template's fixed categories are only a fallback when there is no prior-year file.
2. PPE total: the 8 categories sum to 10,570,446; cost 12,699,337 − 10,570,446 = 2,128,891 = the balance sheet. The printed total 10,570,445
   is a source misprint: flag it with the page, like any other misprint, and use the category sum.
3. Generic audit (P4):
   a. Audit the code from Phases 3–6 for anything tied to the reference file or to one layout, e.g. hard-coded values (the share value
      5,000, note numbers, page positions, rotations); layout assumptions (equity with both years in one table, PPE categories as columns,
      notes numbered 1..N in order, the amounts column position, fixed category lists like the useful-life merge); synonyms or
      corrections that fit only this file. Make each one general or driven by what the AI reads.
   b. Robustness tests with synthetic data (a made-up company, made-up figures, mocked AI answers): notes in a different order/numbering,
      PPE categories as rows instead of columns, equity as two separate tables, a portrait PPE page, a share value other than 5,000,
      a note with no comparative column, a related-parties note present, a different number of PPE categories, a closing-only TB.
   c. List everything found and changed in the summary.
P4. THIS IS A GENERIC PRODUCT, NOT A TAJ PROJECT. Every client's PDF will differ in layout, note order and numbering,
    table shapes, wording and scan quality. The AI decides where each thing is and what shape it has; the code must not
    assume a fixed layout. The code takes the AI's structured answer, verifies it with arithmetic, and flags what doesn't fit,
    never a crash and never a silently wrong value.
Phase 7 addition: if the names can't be confirmed, or the name count ≠ the row count, mark the table «requires accountant review»,
block the final version until it's reviewed, and never assign by guessing.
Final addition: find and fix the intermittent wizard browser test if it reappears.

---

Your understanding is correct, and so is your Step A plan. One addition to the audit (part a):

Also cover the synonyms and the migrations from Phases 1 and 4 (the reading synonyms and any seeded synonym/taxonomy rows), and any
words seeded into the known-words list. Check them for two things:
1. Terms that only fit the reference file, e.g. a TB account name copied from it, including its typos like «التشغييل». Keep only
   general accounting terms; client-specific terms belong in the user's data (learned at runtime), not in code or migrations.
2. Client data committed to git through them. If you find any, replace it with general terms in a new commit (no history rewrite),
   and tell me.

Continue.

---

Step A is good. Two fixes before Phase 7:

1. "Use the category sum" must not be unconditional. Check your own table: for 2024 the category sum ties to the balance sheet
   (2,128,891), so the sum is right and the printed total is the misprint. But for the 2023 net book value, the category sum is
   2,485,647 while the 2023 balance sheet PPE line is 2,485,649, the same as the printed total. There, the sum does NOT tie.
   Rule: when the printed total and the category sum differ, use the one that ties to the balance sheet (or to cost − accumulated
   depreciation and the statement line). If neither ties, or both do, flag both figures with the page and let the accountant choose.
   This matters because the Word PPE comparative table includes the 31/12/2023 net book value. Add a test for each case.

2. Migrations must never delete user data. Removing the client-only synonym and the «قبل التشغييل» rule "even if a user added it
   themselves" is wrong. Delete only the rows we seeded (identify them by a seed marker/source, or by exact seeded values that
   were never edited); keep anything a user created or edited. Fix both migrations (new commit, no history rewrite), with tests.

Then continue with Phase 7.

---

Phase 7 is good. Before Phase 8: I tested the system on the reference file myself. Fix these first (one commit each,
related + guard tests), report each point in your summary, then continue with Phase 8.

Bugs
1. Opening vs prior closing: a zero difference is labelled «غالبًا تقريب», and the footer says "1 من 12 سطر متساويين" although at least
   3 lines are equal (other assets, receivables, cash). A zero difference must be "equal"; only ±1 is «غالبًا تقريب». Fix the counter.
2. Duplicate flags: notes 12 and 13 each show the year-typo flag twice (one per table). One flag per note (mention the tables inside it).
3. Inconsistent correction: «الدقترية» (page 12) vs «الدفترية» (page 13) was only flagged, while «صباريج» was corrected in the same
   situation. Apply the same rule (one letter apart, the other reading is a known word → correct + flag with revert).
4. Words split by the font spacing («المو افقة», «القو ائم»): rejoin the fragments when the joined form is a known word; don't flag them.

Noise
5. 10 unknown-word flags are on correct words (عنوان، عبدالله، إبراهيم، سيعاد، يعاد، الحسميات، نبذه...). Too much noise hides real
   problems. Add a general Arabic word list as a known source, so common words are never flagged, and skip the word-level check
   on person names (the partners table already has its table-level review flag from Phase 7).

Missing move proposals
6. «مخصص الزكاة» shows (14,946) in the TB opening, which equals the «الزكاة» line of last year's note 8 (prepayments and other
   receivables). That's an obvious move proposal, but none was made. Propose it.
7. Accruals are short 2,775,182, and only one account (2,003) was proposed. Match last year's note-11 lines by name as well
   (VAT, accrued rent, employee custody), and for the remaining lines (e.g. «دائنون متنوعون» 2,747,398) search for a SET of accounts
   whose opening balances sum to the line (±1). Same for related parties. Propose; never move automatically.

PPE 2023
8. The 2023 choices are currently independent: the accountant picks the 31/12/2023 accumulated depreciation (printed or sum), while the
   printed 2023 NBV is used because it ties to the balance sheet. Some combinations make the 2023 table inconsistent with itself
   (cost − accumulated ≠ NBV, by up to 2). Show this as ONE flag that explains the source inconsistency, offer only options that keep
   the table consistent (cost − accumulated = NBV), and let the accountant choose. If no consistent option exists, say so clearly.

For the accountant (don't change yet; report it)
9. The TB openings don't match last year's approved closing: accumulated losses differ by 471,998, and total liabilities by about 453k.
   This is likely last year's audit adjustments never posted to the books. Show a clear summary of the total differences, separate
   from the line-level ones. I'll confirm with the accountant: (a) the comparative column always comes from the approved PDF, and
   (b) whether the system should propose an opening adjustment entry for the differences. Prepare a proposal for (b), but don't build it yet.

---

(Session prompt, 2026-10-24: Step B before Phase 9)

# Continuing task: Prior-year PDF ingestion + accountant fixes + output matching the reference templates

You are continuing an in-progress task in Accounting-System/accounting-import, on the local branch
`feature/prior-year-full-ingestion`. Phases 0–8 are done (plus Step A and the nine test points). Previous sessions ran out of context.

## First steps (do these before anything else)
1. Read `docs/plans/prior-year-ingestion-progress.md` (state + decisions), `docs/reference/brief-and-replies.md` (gitignored;
   my brief and replies word for word), `docs/reference/template-spec.md`, and `docs/plans/template-gap-report.md`.
2. Run `git status` and `git log --oneline -60`, and confirm the branch and the latest commits match the progress file.
3. If anything in the progress file conflicts with this prompt, this prompt wins; tell me about the conflict.
4. Give me a short summary (in English) of where we are and what you'll do next. Then wait for my "continue".

## How we work
- Work step by step. After each step/phase, STOP, give me the summary below, and wait for "continue".
- Summary (in English): what was done; commits (hash + message); tests run and results; whether the reference-file (Taj) tests
  actually RAN locally or were skipped (a skipped run is not a pass); reference-file results with numbers; fields still needing manual
  accountant input and why; anything to tell the accountant; the next step and anything you need from me.
- Update `docs/plans/prior-year-ingestion-progress.md` after every step.

## Git rules
- Do NOT push anything until I explicitly say so. Pushing happens only at the very end.
- One commit per logical item, with clear messages.
- Client data (names, CR numbers, figures, account names of real clients) never goes into git: not in code, tests, comments, docs,
  seeds or migrations. Client files, the cached AI extraction and the expected values live in `docs/reference/` (gitignored).
  Scan every commit for client data before reporting.
- Never rewrite main's history and never force-push.
- Migrations never delete or change user data; they only touch rows we seeded and that are untouched (`seeded` marker).

## Tests
- Per step: related tests via `scripts/related-tests.sh` (always includes the guard tests: UI words, statement names, API contract,
  check-code titles). Add tests for new logic. The full suite runs only in the final phase.
- Reference-file tests run offline from the cache and skip when the client data is missing (as on CI).

## Reference files (docs/reference/)
- قوائم تاج العوازل 2024 المعتمدة.pdf: an example prior-year PDF (16 pages, scanned). It's our TEST EXAMPLE only.
- القوائم المالية لشركة SMEs .xlsx: the target Excel format.
- الإيضاحات المتممة - نسخة منقحة.docx: the target Word notes format.
- ميزان مراجعة تاج العوازل 2025.xlsx and ميزان المراجعة 2025-12-31.xlsx: current-year trial balances.

## Core principles
P1. READ EVERYTHING from the prior-year file: all statements, all notes (numeric and text), and the company info. Only the auditor's report is skipped.
P2. MINIMIZE ACCOUNTANT INPUT. The sources, in order: (a) the current classified TB, (b) the prior-year PDF, (c) values computed from (a) and (b),
    (d) manual input as a last resort. Everything stays editable. List the remaining manual fields in every summary.
P3. NEVER GUESS SILENTLY: auto-fill the best value AND flag it with the page number.
P4. THIS IS A GENERIC PRODUCT, NOT A TAJ PROJECT. The AI decides where each thing is and what shape it has; the code must not assume
    a fixed layout. The code verifies the AI's answer with arithmetic and flags what doesn't fit, never a crash and never a silently wrong value.

## Accountant rules (R1–R16), implemented; must not regress
R1. Zero shown as "-" everywhere; amounts compared in whole riyals (0 = equal, ±1 = «غالبًا تقريب»). Negatives in parentheses, switchable by FS_NEGATIVES.
R2. Every dropdown is searchable (one shared component).
R3. Approving body defaults to «الإدارة».
R4. No «الأرباح المقترح توزيعها» anywhere.
R5. Depreciation and amortisation on separate lines everywhere.
R6. One name per statement and per line everywhere (the template names), plus a synonym map for reading.
R7. Shares in words by code, agreeing with the counted noun and the case.
R8. Prior-year statements requested only when there are opening balances (a closing-only TB infers prior years from its balances). Manual override.
R9. The auditor's report is never read.
R10. Totals detected by arithmetic, not by the word «إجمالي»; headings/totals are not items; empty headings dropped.
R11. Notes keep last year's line items and wording exactly, with last year's amounts.
R12. Current-year figures from the classified TB, mapped to the same note lines (searchable dropdown).
R13. PPE categories and rates from the PDF, each with its own useful life (100 ÷ rate); no manual category step; fully depreciated → "-".
R14. Partners from the capital note, read by the table's arithmetic; the name-to-row assignment is confirmed by named capital accounts in the TB,
     otherwise a "requires review" flag blocks the final version.
R15. Related parties in last year's note shape, with this year's accounts (see Step B for the prior-year balance source).
R16. Year typos: the main statements' years are the truth; first numeric column = most recent; one flag per note.

## Later decisions (implemented; must not regress)
- Opening vs last year's closing: checked per line and per PPE category; every difference shown; a totals summary separate from the line-level ones.
- Statements printed off their lines: aligned by arithmetic (unique fit used and flagged).
- Wording: AI slips and source misprints corrected (decided by the TB, templates, taxonomy and known words first; never by dropping a letter)
  and flagged with one-click revert; words split by font spacing rejoined when the joined form is known; a general Arabic word list is a
  known source (never flagged, never a correction target); partner names are not checked word by word; dismissed words are remembered.
- Printed total vs the sum of its columns: use the one that ties to the statements; a year end's PPE cost/accumulated/NBV are chosen together,
  only in internally consistent combinations; if none ties, flag it and the accountant chooses.
- Accounts on a different line than last year: proposals (by name, amount, or a set of up to 6 accounts summing to the line), one click
  per line or all; never moved automatically.
- Profit/loss wording follows each figure's sign; flagged.
- Zakat base: last year's lines; deductions derived from last year's composition and prefilled; additions manual when they can't be reconciled.
- Going concern: computed and included automatically, flagged. Company info, policy choices and approving body from the PDF; the approval date manual.
- Retired API questions (`dividends.proposed`, `capital.shares_in_words`) are accepted and ignored.
- Known limitation: versions saved before the depreciation/amortisation split show the combined figure on «مصروف الإهلاك».
- Excel: reference sheet names exactly (no trailing space); the extra sheets after the 8 template sheets.

## Decisions D1–D5 (implemented)
D1. Totals per statement line and group in the accounts step. D2. One net PPE line in the statements. D3. A new note item gets "-" for last year.
D4. Template rows empty in both years are hidden. D5. The auditor's report is skipped.

## NEXT: Step B (do this first, then stop and report before Phase 9)
1. The accountant decided (for now; it may be revisited in a meeting, so build it as a setting): the approved prior-year PDF is the source of truth
   for the comparative column AND for this year's opening balances, even when the TB openings differ.
   Confirmed formula: this year's closing = opening from the approved statements + this year's movement from the TB.
   - Comparative column: always from the PDF, never from the TB openings. Apply it everywhere, including Phase 8: the related parties' prior-year
     balance comes from the PDF (the note, or the statement line when there's no note: 1,100,605 on the reference file), never from the TB opening.
   - This year's figures: closing per line = the PDF's prior-year closing for that line + this year's movement from the TB (TB closing − TB opening,
     per account, mapped to the line). This keeps the equity statement rolling forward and the balance sheet balancing.
   - NO extra accountant input: don't ask for an account per line. The earlier opening-entry proposal is not an input step; you may show
     the implied per-line differences as an information-only entry in the journal-entries sheet, clearly labelled, if it fits naturally.
   - A reconciliation per line: TB closing vs the closing we present, with the difference (= TB opening − PDF closing), plus the totals summary
     (assets +48,806, equity +471,998, liabilities −423,192 on the reference file).
   - The move proposals still apply, so each account's movement lands on the right line.
   - A closing-only TB has no movement: this method can't be applied. Say so clearly; never fall back silently.
   - One clearly named setting (default: on), stored per report or company.
   - Tests: the equity roll-forward, the balance-sheet balance and the reconciliation, on synthetic data and on the reference file.
2. Proposals matched by amount only (e.g. «إيرادات مقدمة» 44,914.49 → last year's VAT line 44,914): label them "matched by amount only,
   names differ", so the accountant doesn't accept them blindly.
3. Word list licence (CC BY-SA 4.0): keep it as a separate data file with its attribution and licence, never merged into our code.
   Prefer a comparable list under a permissive licence (public domain, MIT, CC0) if you can find one; otherwise keep this one.

## Remaining phases (stop after each)
Phase 9: Excel + Word output:
  - Excel sheets in this order: data, الغلاف, الفهرس, المركز المالى, قائمة الدخل الشامل, قائمة التغيرات, قائمة التدفقات النقدية,
    ايضاح الاصول والممتلكات. RTL, Sakkal Majalla, the template's number formats, the headers driven by the `data` sheet.
  - Don't copy the template bugs: «31 يناير 2025» must be «31 ديسمبر», and no #REF!. Totals are live SUM formulas; zero formula errors.
  - The template rows are the layout; the line items and the PPE categories (any number) come from the prior-year PDF + the TB.
  - Word notes follow the docx structure, numbering, headings and table layouts; last year's lines replace the placeholders; text placeholders
    are filled from the PDF (never "......"); the zakat base table is added; the useful-life table has one row per prior-year category.
    List anything unfillable.
  - Render the output (LibreOffice → PDF → images), compare it visually with the templates, and report every difference.
Final:
  (1) find and fix the intermittent wizard browser test if it reappears; (2) run the FULL test suite and fix failures;
  (3) run two fresh, uncached live reads of the reference PDF, compare them with each other and with the expected values, and report the
  stability and the cost; (4) scan the whole branch for client data; (5) update the progress file; (6) give a final summary (all decisions,
  the remaining manual fields, the known issues). Then wait for my instruction to push.

## Reference-file acceptance checks (comparative column = 2024)
BS: PPE 2,128,891; other assets 1; inventory 2,090,686; receivables 3,977,196; prepayments 37,665; cash 204,389; total assets 8,438,828;
capital 16,000,000; retained (15,817,129); equity 182,871; payables 4,258,873; accruals 2,896,479; due to related parties 1,100,605;
zakat provision "-"; total liabilities 8,255,957.
IS: revenue "-"; operating costs (11,826); G&A (392,866); depreciation (356,756); amortisation (13,099); loss before zakat (774,547); net loss (774,547).
Equity: 957,418 → (774,547) → 182,871. Cash flow: due to related parties 404,692; closing cash 204,389.
PPE: 8 categories, lives 20, 5, 4, 6.67, 10, 5, 20, 10. Closing accumulated depreciation 2024: category sum 10,570,446 (printed 10,570,445 = misprint);
cost 12,699,337. 2023: the only consistent combination is the category sums (NBV 2,485,647, 2 off the BS's 2,485,649): flagged.
Only buildings still depreciate (228,175/yr). Other assets NBV 1.
Partners: 6 names, 6 rows, 3,200 shares × 5,000 = 16,000,000; requires review (single capital account in the TB).
Related parties: prior-year balance 1,100,605 (from the statement line; no note in the PDF).
After Step B: this year's opening = the approved 2024 closing; the equity statement rolls forward; the BS balances; the reconciliation shows
accumulated losses differing by 471,998.

---

Your understanding and plan are correct. One addition to Step B.1:

Apply the setting to EVERYTHING derived from this year's figures, not only the statements: the notes (each line's opening and
closing), the PPE movement table (opening from the approved closing per category, then this year's additions, disposals and
depreciation from the TB), the cash flow (working-capital changes computed from the presented openings and closings), the equity
statement, the zakat base and the going-concern figures. Nothing downstream may still use the raw TB openings or closings.
Add a check that the notes tie to the presented statement lines in both years after the change.

Continue.

---

(Sent during Step B)

Update to Step B.2: the accountant confirmed that «إيرادات مقدمة» (44,914.49) and last year's VAT line (44,914) are a numeric
coincidence, not the same balance. Labelling isn't enough:

- Don't propose an amount-only match when the account's name/class clearly conflicts with the target line's nature
  (e.g. deferred revenue vs a tax line, receivable vs payable, revenue vs expense). Use the taxonomy/class and keywords to detect the conflict.
- Amount-only matches without a name conflict may still be proposed, labelled "matched by amount only, names differ", and never
  accepted by the "move everything proposed" button; they need an individual click.
- Remove the «إيرادات مقدمة» → VAT proposal on the reference file, and add a test for this case with synthetic data.

---

(After the Step B summary)

Before I start a new session: update docs/plans/prior-year-ingestion-progress.md and docs/reference/brief-and-replies.md with
everything since your last update (Step B, the accountant's decisions, the amount-only rule, the word list licence), including every
decision and known issue. Mark the next step as "Step C (see the new session's prompt), then Phase 9". Commit the progress file
(no push), scan it for client data, and tell me the last commit hash.

---

(Step B as built and reported, 2026-10-24; reference figures, for the next session)

- Commits: 872befb (B.1), 7b7238b (B.2), 2489bf0 (B.3), 2ec4be9 (progress), then the progress commit of this request.
- Reference result with the setting on (the 18 unmapped custody/accrued-rent accounts on accruals, as in fixtures/approved-opening-client.json):
  TB openings − approved closing: assets +48,806.49, equity +471,998.04, liabilities −423,191.56 (matches the user's figures); retained
  earnings +471,998.04; prepayments +48,805.42; trade payables +3,399,789.18; accruals −2,775,181.66; related parties −1,077,767.53;
  contract liabilities +44,914.49. Each line's presented closing = TB closing − difference; the BS balances; equity 182,871 + profit −
  distributions = BS equity; related parties 1,100,605 both years; PPE opens at cost 12,699,337, accumulated 10,570,446, NBV 2,128,891,
  only buildings depreciate (228,175). The only note mismatch left: last year's prepayments note 37,664 vs its line 37,665 (the 1 riyal on
  «مدينون متنوعون», shown as a remainder). «إيرادات مقدمة» → VAT proposal gone; «مخصص الزكاة» → «الزكاة» kept (by name); «دائنو أصول ثابته»
  (2,003) remains, amount-only.
- Word list: the only MIT list found (MustafaLinux/arabic-words-list) is ~3.5M words from ~7,000 classical books (Shamela), ~90% correct by
  its author's estimate, with merged words; rejected (it would hide real slips). Kept FrequencyWords (CC BY-SA 4.0) with
  resources/wordlists/LICENSE-ar-common.txt.
- Not Step B, in the user's local draft (report 01a1642f…): 18 accounts with no line (classes «عُهد وسُلف دائنة», «مصروفات مستحقة» have no
  catalogue rule; 121,297.34 at opening); the cash flow is 8,000,000 off (8,000,000 of other income classified as a disposal gain with no
  proceeds). Tests: related 181 passed, none skipped; browser 19 passed; reference fixture 15/15 RAN. AI cost $0.

---

(Session prompt, 2026-10-24: Step C before Phase 9)

NEXT: Step C (do this first, then stop and report)
1. Accounts with no line: on the reference file, 18 accounts in the classes «عُهد وسُلف دائنة» and «مصروفات مستحقة» have no mapping rule.
   These are standard accounting classes whose natural line is «مصاريف مستحقة وأرصدة دائنة أخرى». Add general default mapping rules for them,
   and check the taxonomy for other standard classes with no rule, so they're placed automatically and stay editable.
   General accounting terms only; no client data. Separate commit, related + guard tests.
2. Disposal gains/losses: on the reference file, 8,000,000 of other income is classified as a gain on disposal with no proceeds, which puts the
   cash flow 8,000,000 off. I'm asking the accountant what it is. Meanwhile, add a general check: a gain or loss on disposal with no matching PPE
   disposal and no proceeds gets a flag with the amount, asking the accountant to confirm the classification. Never reclassify it automatically.
3. The progress file lists "a harmless duplicate-keys bug in the controller" as a known issue. Fix it now (separate commit, with a test),
   rather than carrying it into Phase 9.
4. The progress file has a section on "what stays on the raw TB" under the approved-openings setting. Review it: anything that ends up in
   the statements, notes or Excel/Word output must use the presented (approved-openings) figures. Raw TB figures may only feed the
   movement calculation and the reconciliation. List what you checked in the summary, and fix any exception.
Phase 9 addition: save the reference client's generated Excel and Word files, with the render images of each page, in
docs/reference/output/ (gitignored), and give the paths so they can go to the accountant for review.
Reference acceptance addition: the reconciliation totals vs the TB: assets +48,806, equity +471,998, liabilities −423,192 (with the
accrual-class accounts mapped).

---

(Reply to the Step C plan, 2026-10-24)

Your understanding is correct, and so is your Step C plan. Go ahead, with two answers from the accountant added:

1. Negative numbers: the accountant confirmed parentheses. Keep parentheses as the default and keep the FS_NEGATIVES switch.
   Nothing to change; just record the confirmation in the progress file.

2. The 8,000,000 classified as a gain on disposal with no proceeds: the accountant says to put it anywhere for now; the final
   classification will come later. Add this to Step C.2:
   - For the reference file, classify it as «إيرادات أخرى» (other income), not a disposal gain, so the cash flow doesn't look for
     disposal proceeds.
   - Keep a visible flag "classification pending accountant confirmation" with the amount, changeable with one click.
   - Keep the general check you planned (a disposal gain/loss with no matching PPE disposal and no proceeds is flagged; never
     reclassified automatically).
   - After the change, confirm on the reference file that the cash flow closes (net change = closing cash − opening cash) and report
     the figures. If it still doesn't close, find out why and report it; don't force it.

Then do Step C items 1–4 as you listed them (one commit each, related + guard tests), run the reference-file checks (confirm they ran),
scan the commits for client data, update the progress file, and stop to report.

---

(Step C as built and reported, 2026-10-24; reference figures, for the next session)

- Commits: 08628d7 (C.1), aec392e (C.2), 9a3c635 (C.3), e4270cf (C.4), cedbf22 (progress).
- C.1: the 18 unmapped accounts were a spelling mismatch (user's taxonomy renamed «مصروفات مستحقةو ارصدة دائنة اخرى» → «مصروفات مستحقة و ارصدة دائنة اخرى»,
  «عُهد وسُلف دائنه» → «عُهد وسُلف دائنة»); now matched by spelling; fixture's 18 manual accrual overrides removed; unmapped = 0; reconciliation unchanged
  (assets +48,806.49, equity +471,998.04, liabilities −423,191.56).
- C.2: row 90 «أرباح بيع أصول ثابتة» 8,000,000 tagged not_disposal (fixture + local draft 01a1642f…). Fixture (approved openings): net change 3,083,553.64 =
  closing 3,287,942.64 − opening 204,389. Local draft (no PDF attached, TB openings): 3,083,553.64 = 3,287,942.17 − 204,388.53. Before: closing cash off by
  −8,000,000. Observation: the cash flow shows dividends paid 144,792.53 (retained earnings moved by more than the year's result).

---

(Reply to the Step C summary, 2026-10-24: correction + Step D)

Step C is good, especially the root cause on C.1. One correction, then a new step before Phase 9.

## Correction: the 144,792.53 shown as «توزيعات أرباح مدفوعة»
Inferring dividends from an unexplained movement in retained earnings is a guess (P3). It could be dividends, a prior-year adjustment
posted to retained earnings, or another entry. Show it as an unexplained retained-earnings movement with the amount and the accounts,
flagged for the accountant, with one-click choices (dividends paid / prior-year adjustment / other), and present it accordingly in the
equity statement and the cash flow (a prior-year adjustment or a non-cash entry is not a cash outflow). Until the accountant chooses,
don't label it as dividends. I'm asking the accountant. Separate commit, related + guard tests.

## Step D: a second real prior-year PDF and a new rule (do this before Phase 9, then stop and report)

### D.1: the auditor's report is required (accountant's rule)
- A prior-year statements file with no independent auditor's report must be rejected, with a clear error saying why
  (e.g. «الملف مفيهوش تقرير المراجع المستقل، فمش هينفع يتقبل كقوائم معتمدة»). It's usually on page 3, but don't rely on the position.
- Use the existing page classification (the "auditor report" page kind). The report is still never READ (R9); we only check that it exists.
- If the AI misses it, the accountant can correct the page kind in the pages table, and the file is then accepted. There is no other override.
- Tests: a file with the report (accepted), without it (rejected with the message), and a missed page corrected by the accountant (accepted).

### D.2: live run on the second real file
The file is docs/reference/Proven SA - Signed FS - FY23 (ARB).pdf (gitignored; client data never goes into git). We have NO trial balance
for it, so test the reading side only. Run a live read (report the cost) and report, without tuning the code to this file (P4):
- the page classification (39 pages; the auditor's report is on pages 3–5; landscape pages: the PPE note and the related-parties note);
- the statements: the balance sheet has THREE columns (31/12/2023, 31/12/2022 restated, 1/1/2022); the most recent is the comparative for a
  2024 report, and the extra column must not break anything;
- the equity statement: its row labels say 2021/2021/2022 where they should be 2022/2022/2023 (R16 applied to row labels);
- digits: the whole file uses Arabic-Indic digits (٠١٢٣٤٥٦٧٨٩); confirm they're normalised everywhere, including the arithmetic checks;
- statement names with «المنفصلة» (separate statements) must map to our names;
- the notes: PPE with rate ranges («15% - 33%»): the useful life must handle a range, not only a single rate; right-of-use assets, lease
  liabilities, employee benefits, investment in a subsidiary, contract assets/liabilities;
- the partners: the capital note has 2023 and 2022 columns with DIFFERENT partners (an ownership transfer during the year), a company as a
  partner, a nationality column, the share count inside the name cell («(حصه 45,000)»), and a share value of 10 stated in the text.
  The current partners are the 2023 column;
- the related parties: a full note with relationship, nature, transaction amounts and balances (the case the first file didn't have);
- the zakat note has only the movement, no base table: this must not break the zakat base logic;
- note 6 is a first-time adoption note (IFRS transition reconciliations with 4 columns). It belongs only to the transition year, so propose
  NOT carrying it into the new year, flagged for the accountant, instead of copying it under R11;
- the framework: this company reports under full IFRS, not IFRS for SMEs. The framework must come from the PDF; flag that the output
  template is the SMEs one.
For every point, report what worked, what failed and what you changed. Fix failures generally (P4), with synthetic tests, never with
anything specific to this file. Expected figures for checking (2023 column): total assets 49,449,508; equity 21,669,741; total liabilities
27,779,767; revenue 209,903,425; profit after zakat 12,949,965; total comprehensive income 12,913,189; closing cash 2,291,702;
capital 500,000 (50,000 shares × 10); PPE net 167,224; due from related parties 27,380,529; due to related parties 9,125,842; zakat 972,180.
Keep these figures out of git (docs/reference only).

Then stop and report before Phase 9.

---

(Step D as built, 2026-10-25; the second file's figures, for the next session)

- Commits: 150702c (retained earnings correction), 34d74b6 (D.1), b0b13dd + 1a8cb75 (D.2).
- Retained earnings on the Taj reference: 144,792.53 shown as «حركة غير مفسرة في الأرباح المبقاة» (equity + non-cash CF line), dividends paid «-».
- Proven (docs/reference/proven-extraction, live read $2.1756, 71 requests, claude-sonnet-5; re-runs $0): pages 3–5 auditor's report, 6 BS, 7 IS, 8 SOCE, 9 CF,
  10–39 notes; 25 and 36 rotated 90. 2023 column: total assets 49,449,508 ✓; equity 21,669,741 ✓; total liabilities 27,779,767 ✓; revenue 209,903,425 ✓;
  profit after zakat 12,949,965 ✓; TCI 12,913,189 ✓; closing cash 2,291,702 ✓; capital 500,000 (50,000 × 10) ✓; PPE 167,224 ✓; zakat 972,180 ✓;
  due from RP read 27,380,029 (scan 27,380,529: misread); due to RP read 9,125,847 (user 9,125,842: misread). Comparative misreads: trade payables 2,710,890 (scan
  2,605,890), total liabilities 22,943,203 (scan 22,308,008). Partners 2023: سنابل 45,000 / عبدالعزيز 2,500 / مشاري 1,250 / تركي 1,250 × 10 = 500,000; former:
  زيد، نايف. RP: 10 due-from, 5 due-to parties + one sub-column «دفعات نيابة». PPE categories 4 (ranges 15–33 → 3.03–6.67; 15–25 → 4–6.67; 25 → 4; vehicles
  no rate). Flags: framework_full_ifrs (p.11), year_typo_rows (SOCE 2022/2021 → 2023/2022), transition_note (note 6, pp.20–24), partners_changed (note 13).
