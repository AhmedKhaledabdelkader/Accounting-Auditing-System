# Prompt: run one client's financial statements end to end

Give this whole file to a fresh agent, with the two input paths filled in. It holds no client data; the agent's outputs and
notes about the client go only to gitignored folders.

---

You are preparing one client's financial statements with the app in `/Users/gomaa/accounting/Accounting-System/accounting-import`
(Laravel, Arabic UI, SQLite). You have no other context. Your inputs:

- `INPUT_TB` = <path to this year's trial balance, .xlsx or .csv>
- `INPUT_PRIOR` = <path to last year's approved financial statements, PDF (scanned is fine) or .xlsx>

Your job: run the app's whole flow, from uploading these two files to the exported files, verify that everything ties, and report.
Read `docs/accountant-guide.md` (the flow and the rules) and `docs/plans/prior-year-ingestion-progress.md` §1–§3 (the rules
behind the prior-year reading) before you start.

## Ground rules (do not break these)

1. **Never guess.** An unexplained difference, an uncertain classification or line, or any judgment call (what an amount
   *is*, which line an account belongs to when the name and amount disagree, a policy choice, an answer only the company knows)
   is left pending and listed. You don't resolve it, and you don't make it go away by acknowledging a warning, typing a
   figure, moving an account or posting an entry.
2. **Proposals stay proposals.** The app proposes some things without posting them: depreciation on additions
   (`ppe_additions_depreciation`), suggested answers (badge «إجابة مقترحة»), account move proposals in step 2
   («حسابات على سطر غير سطرها في السنة السابقة»), the zakat base derived from last year (`zakat_base_derived`). Never turn one
   into an adjusting entry, a saved answer or a moved account. List them for the accountant.
3. **What you may do without asking:** start the app; upload the two files; accept what the app read when its own checks
   pass (a prior-year row marked «مطابق» or «مطابق من المرادفات»; a page's kind or number the classifier found and you
   verified by opening the page); save steps; confirm a movement suggestion in step 4 *only* when its basis is the trial
   balance's own movement columns for those accounts and its schedule rolls (opening + movements = closing) with no
   warning on it; save a **draft** version; download the files.
4. **Never:** issue a final version («إصدار نسخة نهائية»), acknowledge a warning («حفظ التأكيدات»), edit a note's wording,
   change taxonomy/rules/synonyms pages, push or commit anything in git, or write the client's name or figures into any
   tracked file. Client outputs go to `docs/reference/output/<run-folder>/` (gitignored; check with
   `git check-ignore -q <path>`).
5. If a step can't be completed without a judgment (e.g. a prior-year row the app can't place and you can't place with
   certainty, a page of the PDF that reads wrong after its re-read), stop that step, record it as a blocker with the page
   and figures, and go on with whatever doesn't depend on it.

## 0. Start the app

```bash
cd /Users/gomaa/accounting/Accounting-System/accounting-import
git status --short && git log --oneline -1          # note the commit you ran on; don't change the code
php artisan migrate --force
grep -E '^(CLASSIFIER_PROVIDER|ANTHROPIC_API_KEY|GEMINI_API_KEY|QPDF_BINARY)=' .env | sed 's/=.*/=<set?>/'   # keys must be set (never print them)
which qpdf pdftoppm                                  # PDF repair and page images
composer run dev                                     # server + queue listener + logs; leave it running (background)
```

The app is at `http://127.0.0.1:8001` (`APP_URL`, port `SERVER_PORT`). If no user exists:
`php artisan users:create-admin admin --name="المدقق"` (it asks for a password). Sign in at `/login`.

For the checks you will read the report's state through the API (`/api/v1/...`, Sanctum token). Make a token at `/account`
(«مفاتيح الواجهة البرمجية» → «إنشاء مفتاح»), or:
`php artisan tinker --execute="echo App\Models\User::where('username','admin')->first()->createToken('e2e')->plainTextToken;"`
Then `curl -s -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' http://127.0.0.1:8001/api/v1/...`.
The API is documented in `public/docs/openapi.yaml` (and `/api/docs`).

Use a browser for the UI steps (the built-in browser, or Chrome). Every page is in Arabic; the labels below are the exact
button and heading texts.

## 1. Classify the trial balance — page `/classifier` («مصنّف ميزان المراجعة»)

1. Choose or create the company (it is carried to the report), upload `INPUT_TB`.
2. If the run stops at the column check (status `needs_confirmation`), verify each column the app mapped (opening, movement,
   closing, debit/credit) against the file and press «أكّد وكمّل التصنيف». Don't change a mapping you aren't sure of: blocker.
3. Wait for `done` (`GET /api/v1/classify/jobs/{run}`; note `cost_usd`).
4. Accounts marked for review: look at each. Leave any you can't decide for certain *as they are* and list them
   (classification is the accountant's). Do not use «طبّق على المجموعة».
5. Press «🧾 إعداد القوائم المالية» → `/financial-reports/new?run=<run>`: check the company chip and the period (default: the last
   full financial year), press «إنشاء التقرير». Note the report id (the URL `/financial-reports/<id>`).

## 2. The wizard — `/financial-reports/<id>`, seven steps (hash in the URL)

The side panel lists what blocks a final version; each item links to its step.

1. **«الشركة والفترة»** (`#company`): the company's details. Fill nothing by guess; step 2 offers the details it reads from
   last year's notes («بيانات الشركة من إيضاحات السنة السابقة») — accept those that match the PDF.
2. **«السنة السابقة»** (`#prior_year`):
   - Source: «ملف القوائم المالية المصدرة للسنة السابقة». Attach `INPUT_PRIOR`, press «ارفع الملف واقراه».
   - PDF: the pages table shows each page's kind. Open each statement page with «افتح الصفحة» and correct a kind, number or
     rotation only when the page plainly shows otherwise. A file with no independent auditor's report page is refused
     (accountant's rule): if the report is there but missed, mark that page; if it isn't there, stop: blocker. Press
     «اقرا القوائم» (AI reading; note its cost).
   - Review «مراجعة ملف القوائم المصدرة»: every row with its page, amount and proposed line. Keep «مطابق» / «مطابق من المرادفات».
     «مقترح — راجعه» / «اختار سطر»: choose a line only when the wording is beyond doubt and the statement's totals still tie;
     «تجاهل» only for a printed total or heading. «راجع الرقم ده», wording flags («حاجات محتاجة تبص عليها في الملف»),
     year-typo flags, total choices: record each with its page; choose a total only where the app shows one option ties.
   - Leave «ابدأ السنة من أرصدة آخر السنة السابقة في القوائم المعتمدة…» ON (the default: this year's openings are last
     year's approved closing). Read its panel «أرصدة أول السنة من القوائم المعتمدة» (totals, «بندًا بندًا», PPE by category):
     every difference stays shown; don't remove it.
   - «حسابات على سطر غير سطرها في السنة السابقة»: proposals only. Don't press any move button; list them.
   - Last year's notes' figures (the grid of note items): leave every cell as shown; type nothing. A shown cell is saved only
     if changed, and a typed figure overrides last year's approved note.
   - Press «حفظ السنة السابقة».
3. **«الحسابات والقيود»** (`#accounts`): accounts with no line, possible parent accounts, periodic stock: decide only what is
   certain from the classification; list the rest. Read the line totals (D1) and the trial balance's debit and credit totals.
   The disposal panel (`disposal_unmatched` / `disposal_pending`) is a judgment: don't press it. **No adjusting entries.**
4. **«الحركات والأرقام الناقصة»** (`#movements`): confirm a suggestion only under ground rule 3; never «تأكيد كل المقترحات» blindly.
   The retained-earnings panel (`retained_unexplained`) is the accountant's: don't choose. The zakat charge and OCI are the
   accountant's unless the trial balance has them.
5. **«الإيضاحات والنصوص»** (`#notes`): last year's answers are prefilled as suggestions (approving body, policies, useful lives,
   partners, related parties). Don't press «حفظ الإجابات» in a way that accepts suggestions: when the box asks, choose
   «احفظ تعديلاتي بس» or cancel. Leave notes' text as built.
6. **«المراجعة»** (`#review`): read errors, warnings, the prior-year cells counted as 0, and the statements and notes as they
   will print. Press «حفظ مسودة» (a draft version). Don't issue a final version.
7. **«الإصدارات»** (`#versions`): the draft is listed. Download its three files («تحميل ملف إكسل», «تحميل الإيضاحات (وورد)»,
   «تحميل القوائم والإيضاحات (PDF)»), or by API:

```bash
OUT=docs/reference/output/$(date +%F)-<report-id-first-8>      # gitignored
mkdir -p "$OUT"; git check-ignore -q "$OUT" || echo "NOT IGNORED — stop"
# xlsx = the statements (+ PPE note, draft and journal sheets); docx = the notes; pdf = statements and notes
for x in xlsx docx pdf; do curl -s -H "Authorization: Bearer $TOKEN" -o "$OUT/report.$x" \
  "http://127.0.0.1:8001/api/v1/financial-reports/<id>/versions/<n>/export.$x"; done
curl -s -H "Authorization: Bearer $TOKEN" http://127.0.0.1:8001/api/v1/financial-reports/<id> > "$OUT/state.json"
```

The uploaded PDF is kept under `storage/app/private/prior-statements/<report-id>/`. Optionally render the files to check them
visually: `soffice --headless --convert-to pdf --outdir "$OUT/render" "$OUT/report.xlsx" "$OUT/report.docx"` then
`pdftoppm -png -r 80 …` (Sakkal Majalla may be missing: a font substitution is not a layout error).

## 3. Verify — every check pass or fail, with the figures

Use `state.json` (`GET /api/v1/financial-reports/<id>`): `statements` (each line: `key`, `label_ar`, `note`, `current`,
`comparative`, `shown` = the whole riyals printed, `rows`), `checks` (`code`, `severity`, `scope`, `line_key`, `message`,
amounts), `missing`, `blocking`, `notes` (`key`, `number`, `visible`, `blocks`), `comparative.approved_opening`,
`comparative.upload` (the PDF as read: `blocks[].rows`, `opening_check`), `mapping`, `suggestions`, `required_inputs`.
Compare in whole riyals (`shown`); ±1 is rounding and is still reported. For each check give: PASS/FAIL, the figures on both
sides, the difference, and the app's own check code if it raised one.

1. **Trial balance:** total debits = total credits (step 3 «[data-tb-totals]», no `tb_unbalanced` / `tb_partial`), and every
   account classified: no `unclassified_rows`, no `unmapped_accounts`; list `rows_need_review` and `wrong_side_balance` with
   their accounts.
2. **Openings = last year's approved closing:** for each balance-sheet line, `comparative.approved_opening.lines`
   (`tb_opening`, `approved`, `difference`, `kind`, `presented`) and `.totals` (assets, equity, liabilities) and `.ppe`
   (by category and side). Every difference listed with its explanation from the app (`remainder`, `moved` accounts,
   `rounding_kept`, `approved_opening_sign`) or marked UNEXPLAINED. Nothing hidden.
3. **Comparatives = last year's approved statements:** every line's `comparative` (origin `prior_year_statements`) equals the
   PDF's figure in `comparative.upload.blocks[].rows` for the row placed on it, in all four statements; every visible note's
   comparative column equals last year's note as printed (checks `note_total_mismatch` with `scope` = comparative,
   `note_comparative_remainder` = a riyal of the file's rounding put on the largest line). A provision's note (zakat, end of
   service) gives last year's closing = last year's approved line, even while a move of its account is only proposed.
4. **Balance sheet:** `sofp.total_assets` = `sofp.total_equity_and_liabilities` (= total equity + total liabilities), both
   years; no `sofp_unbalanced`.
5. **Profit:** `is.net_profit` (current) = the year's result in the changes in equity (`statements.changes_in_equity.current`,
   row `net_profit`, retained earnings column); no `net_income_mismatch`. Same for the comparative year.
6. **Cash:** the cash flow's closing cash = `sofp.cash` (less an overdraft only if the report counts it in cash), both years;
   the net change = closing − opening; no `cash_flow_closing_mismatch` / `cash_flow_opening_mismatch`.
7. **Notes:** every statement line with a `note` number has a visible note with that number; every visible note's table
   total = its line(s) in both years, in whole riyals (no `note_total_mismatch`, no `note_reference_missing`); visible notes are numbered
   1…n with no gap and no duplicate (also in the Word file: list `report.docx`'s note headings in order).
8. **Equity:** for each equity column, opening + the year's movements = closing, closing = the balance-sheet line, and the
   opening = last year's approved closing (no `soce_closing_mismatch`, `soce_opening_mismatch`, `re_roll`); the retained
   earnings movement other than the result (`retained_unexplained`) listed, not named.

Also report every other `error` and `warning` in `checks`, and everything in `blocking` and `missing`.

Expected, not faults (report them, don't call them bugs):
- A result label shows both words («صافي الدخل (الخسارة)…») while one year's figure is still missing (e.g. OCI not answered).
- Last year's PPE note whose printed totals don't agree with each other: the app uses the combination that ties
  (`total_group_ties`) or the only consistent one (`total_group_only`, possibly a riyal or two off), flagged in step 2 with
  its page. List it for the accountant.
- `partners_review`: the partners' names and figures as read; a scan can pair a name with the next row's figures. Compare
  them with the PDF page and report any shift; don't save the table.
- `related_party_line_on_one`: last year's related-party line (no note) put whole on the one related-party account; the
  warning gives the line, the account's own opening and the difference. List it; don't save the table.

## 4. What only the accountant can give

One short list, each item with where it is entered:

- Figures and answers the app asks for (`required_inputs`, `missing`), typically, all in step 5 «الإيضاحات والنصوص» unless
  said: supplier finance («فيه ترتيبات تمويل موردين؟», then «جهة تمويل الموردين», «شروط ترتيبات تمويل الموردين», «القيمة الدفترية
  للالتزامات الخاضعة للترتيبات», «منها ما سدده مقدم التمويل للموردين», «نطاق تواريخ استحقاق السداد»); the zakat base's
  additions («إجمالي الإضافات إلى الوعاء») and the zakat charge («مصروف الزكاة للسنة» or «سبب إن الزكاة صفر», step 4). If the
  accountant says there is no zakat, the reason is the only zakat entry: unentered base lines then print «-», the note prints
  the reason, and the provision's movement is one info line (`zakat_zero_movements`) instead of `zakat_used_no_charge` and
  `provision_without_pl_counterpart`. Don't enter either yourself. The
  approval date («تاريخ اعتماد القوائم المالية»); related parties' relationship, nature and volumes («الأطراف ذات العلاقة»);
  the partners' table review («الشركاء وحصصهم», `partners_review`); useful lives and policies prefilled from last year
  (confirm).
- Open questions: each pending judgment from §2 and §3 (unexplained differences, uncertain classifications, `disposal_pending`,
  `retained_unexplained`, `approved_opening_sign`, `related_party_line_on_one`, `partners_review`, move proposals,
  `ppe_additions_depreciation`, prior-year rows you couldn't
  place, wording flags), each with its figures, page and the step that settles it.
- Warnings to acknowledge once settled (step 6 «حفظ التأكيدات»), then the final version (step 6 «إصدار نسخة نهائية»).

## 5. Your final report (short)

1. The commit the app ran on, the report id, the AI cost (classification + PDF reading).
2. Files produced, with full paths (`docs/reference/output/<run-folder>/report.xlsx`, `report.docx`, `report.pdf`, `state.json`,
   and any renders), and the version number they came from (a draft).
3. The eight checks: a table of PASS/FAIL with the figures and differences.
4. The accountant's list (§4): manual entries, then open questions, each with where to enter it.
5. Blockers, if any, and anything you couldn't verify.

Write the same report, with the client's figures, to `$OUT/report.md` (gitignored). Don't put it anywhere tracked.
