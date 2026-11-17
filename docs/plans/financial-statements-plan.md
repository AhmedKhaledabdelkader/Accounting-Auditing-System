# Financial Statements & Notes Generator — plan (no code yet)

Status: **draft for review, 2026-10-13. Revised the same day with the sample TB analysis (§0, test fixture only) and Q32–Q41. **All questions Q1–Q41 are decided (§7).** The only open items are the template additions in §8, which wait for your approval.** Nothing here is implemented, and no code is written until you approve. `[Qn]` tags point to the decision behind a design choice.

Legend used in tables:
**A** auto from the trial balance (closing) · **O** auto from the TB's own opening column · **S** suggestion from movement columns (user confirms) · **D** derived by PHP from other figures · **U** user input · **P** prior-year source · **—** not available / not applicable.

---

## 0. Sample trial balance (test fixture only)

> **Scope rule.** The reference files, including this trial balance, are **examples only**. Nothing in §§1–7 depends on this company's account numbers, names, parties or figures. Every rule and check is defined on classes, balances and movements, so it works on any client's file. This section records what the current code does with the sample, so it can become a test fixture (Q1). It also shows **generic** failure modes, each of which has a generic rule elsewhere in the plan. Account numbers below identify fixture rows only.

File: `docs/reference/ميزان المراجعه 31-12-2025.xlsx` (the name has spaces, not underscores).

- **Company:** "ميزان مراجعة شركة نموذجية 2025" (anonymised; the real name is in the ignored `docs/reference/` file only).
- **Sheet:** one, «ميزان المراجعه».
- **Rows:** 282 accounts and a total row.
- **Columns:**
  - `A` account number
  - `B` بنود الميزانية (the client's statement line)
  - `C` إيضاحات الميزانية (the client's note sub-line)
  - `D` name
  - `E/F` opening Dr/Cr, headed only «مدين/دائن»
  - `G/H` movement Dr/Cr
  - `I/J` adjustment Dr/Cr, **all empty**
  - `K/L` closing Dr/Cr
  - `M` «إيضاح», the client's **note number**
- **Formatting:** zeros are typed as `-`. Rows 1–2 hold `SUBTOTAL` totals and Dr−Cr checks.

### 0.1 Live run through the existing pipeline
How it was run:
- Run on 2026-10-13 in-process, with `ClassificationService::detect()` then `classifyDetected()`.
- Provider `anthropic`, model `claude-sonnet-5`.
- **Cache turned off for the run**, so nothing was written to the DB.
- Usage: 9 requests; 40,000 input, 18,627 cache-write, 130,389 cached and 17,148 output tokens.

**Detected structure.** Every amount column was mapped correctly. The AI read `E/F` as opening even though the header says only «مدين/دائن».

| Role | Column |
|---|---|
| `account_number` / `account_name` | A / D |
| `opening_debit/credit` | E / F |
| `period_debit/credit` | G / H |
| `adjustment_debit/credit` | I / J |
| `closing_debit/credit` | K / L |
| `existing_classification` / `existing_note_classification` | B / C |
| **`notes`** | **M** ← wrong: M holds note numbers, not remarks |

The one structure issue was a `text_numeric` doubt: "العمود M اتقرا كـ«الملاحظات» بس 100% من قيمه أرقام". It is a doubt, so a queued run would not pause.

**`meta.groups`** = `["opening","period","adjustment","closing"]`.
- `adjustment` is listed although I/J are empty in every row. The generator must treat a present-but-all-zero group as "no adjustments", not as data.

**Results**

| Item | Value |
|---|---|
| Accounts classified | 282 |
| Unclassified rows | **0** |
| Skipped rows | 1 (the total row) |
| `partial` | false |
| Row reconciliation flags | **0**: every row satisfies opening + movement = closing |
| Σ Dr = Σ Cr | holds |
| All 5 families present | yes, so this is a full TB, not partial |
| Pre-closing | yes: P&L accounts are open and the SOFP net equals the P&L result |
| **`needs_review`** | **181 of 282** |
| …of which only because column M became a `source_note` | 144 |
| …for real reasons (low confidence or a non-info flag) | 37 |

**Flags raised**

| Flag | Count | Rows |
|---|---|---|
| `classification_mismatch` | 27 | Mostly **function-vs-nature noise**: B says «تكلفة إيرادات» while the taxonomy main class is a nature (إيجارات, أجور, رسوم…). Also customer/supplier advances, where the client nets supplier advances into «ذمم مدينة تجارية», note 6. |
| `abnormal_closing` | 3 | 2101MCG001 (supplier, Dr 125,489.55); 2101MCM013 (supplier advance, Cr 374,333.30); **901 «بضاعة آخر المدة» (Cr 16,324,291.95 under inventory)** |
| `abnormal_opening` | 1 | 21020204 «الشريك أ» (opening Dr 102,446.49, closing Cr 240,311.03) |
| `prefix_mismatch` | 1 | 8203 «حسم مسموح به» (contra-revenue coded in the 8xxx expense range; confidence capped at 50) |
| `statement_mismatch` ⛔ | 1 | 120706 «مصاريف رسوم اصدار شهادات منتجات»: a **false positive** from the expense-name rule. It is a prepayment, and its closing balance is 0. |

**Low confidence (< 70), 12 rows**
- 114 «انظمة الدفاع المدني» → `عدد و ادوات`, 60
- 8110, 8118, 8232, 8404, 8510, 8511, 901, 2101MCG001, 21020204, 2101MCM013 → 60–65
- 8203 → 50

**Misclassifications with no flag at all** (the feature must catch these):

| Row | Account | Classified as | Should be |
|---|---|---|---|
| R189 | **61 «بضاعة بالمستودع»** (Dr 10,772,867.65) | SOFP › المخزون › مخزون بضاعة, conf 90, **needs_review = N** | Opening stock in cost of sales (see 0.3) |
| R285 | **901 «بضاعة آخر المدة»** (Cr 16,324,291.95) | SOFP › المخزون (flagged only as wrong-side) | Closing stock credit in cost of sales |
| R178 | 2305 «مخصص صيانه بضاعه» (Cr 21,823.18) | SOFP › المخزون › **مخصص هبوط أسعار المخزون** (a contra asset) | A **warranty provision** (liability). The taxonomy has no sub-class for it. |
| R180 | 2402 «فوائد قروض مؤجلة» (Dr 367,239.73) | prepaid › مصروفات مدفوعة مقدماً | Deferred murabaha profit; normally **netted against the loan** (Q36) |
| R123 | 120508 «سلفة طرف ذو علاقة» | AI overrode the file's «مستحق من اطراف ذات علاقة» with سلف العاملين (flagged `classification_mismatch`) | Depends on whether he is key management. His salary is in G&A (810101, 246,000). User decision. |

### 0.2 Effect on the statements if built from the run as-is

| | As classified | With 61/901 moved to cost of sales |
|---|---|---|
| Inventory on SOFP | **10,751,044.47** (= opening stock − warranty provision) | **16,324,291.95** (120901 only, warranty provision moved to liabilities) |
| Net profit before zakat | **179,588.87** | **5,731,013.17** |

The difference is **5,551,424.30**, the change in inventory. This is the single biggest risk in the file: a silent, high-confidence misclassification that moves profit by 5.55 M.

### 0.3 The §5 anomalies and the periodic-inventory accounts

**Periodic inventory (61 / 901 / 120901 / 42).** The movements match to the halala:

| Account | Opening | Dr movement | Cr movement | Closing |
|---|---|---|---|---|
| 120901 بضاعة موجودة بالمستودع | Dr 10,772,867.65 | 84,682,177.71 | 79,130,753.41 | Dr 16,324,291.95 |
| 61 بضاعة بالمستودع | 0 | 10,772,867.65 | 0 | Dr 10,772,867.65 |
| 901 بضاعة آخر المدة | 0 | 68,357,885.76 | 84,682,177.71 | Cr 16,324,291.95 |
| 42 المشتريات الاجلة | 0 | 26,387,649.17 | 0 | Dr 26,387,649.17 |

The arithmetic:
- 61 Dr = 120901 opening.
- 901 Cr movement = 120901 Dr movement.
- 120901 Cr movement = 901 Dr movement + 61 Dr movement (68,357,885.76 + 10,772,867.65 = 79,130,753.41).
- 901 closing = −120901 closing.

So 61 and 901 are the **periodic-system opening and closing stock entries**, posted against 120901.
- **Correct treatment:** 120901 is the SOFP inventory; 61 (+) and 901 (−) belong in **cost of sales**; 42 is purchases.
- **The client's own column B labels 61 and 901 «المخزون»**, which is why the AI followed it.
- **The taxonomy has no sub-classes** for "opening stock" or "closing stock" in cost of sales (Q32).
- A side observation, not resolved: goods received into 120901 (84.68 M) far exceed credit purchases (26.39 M) plus landed costs. The value of 16.32 M rests on the client's stock valuation; the TB can't verify it.

**Zakat — the anomaly holds.**
- 2304 «مخصص للذكاه»: opening Cr 134,455.96, Dr movement 134,455.96, closing 0.
- There is **no zakat expense account anywhere in the P&L**, and no closing provision.
- So the 2024 provision was used (presumably paid), and **no 2025 zakat is recorded at all**.
- The system cannot compute zakat (Q38). The 2025 charge must be entered by the user.
- If it is entered, it changes profit, the provision and the equity statement, which the TB doesn't reflect: the TB would have to be adjusted, or the entry handled as a report-level adjustment (Q39).

**Retained earnings cleared to partners' current accounts — consistent, not provable.**
- 3406 «ارباح وخسائر 2024»: opening Cr 5,011,518.20, Dr movement 5,011,518.20, closing 0.
- There is no dividend or dividends-payable account.
- Partners' current Cr movements total 5,016,653.61:

  | Account | Cr movement |
  |---|---|
  | 3301 | 2,528,561.51 |
  | 3302 | 1,776,209.31 |
  | 3303 | 711,882.79 |

- That covers the RE amount plus 5,135.41 of other credits. The per-partner split doesn't match a simple percentage of 5,011,518.20.
- **A TB can't show the counter-entry.** The generator should flag it (`re_cleared_no_dividend`) and ask whether it is a declared distribution credited to the partners. If it is, the SOCE shows dividends of 5,011,518.20, with no cash flow unless paid.
- Partners' current **Dr** movements of 594,735 (3301: 40,000; 3302: 554,735) are candidate financing outflows, to be confirmed.

**Depreciation — checks out.**
- Accumulated-depreciation Cr movements: 2201 85,548.44 + 220302 2,423.30 + 220303 2,047.75 + 2204 1,869.41 = **91,888.90**.
- That equals expense 8121 **91,888.90**.
- There are no disposals: no Cr movement on cost, no Dr movement on accumulated depreciation.
- PPE additions (cost Dr movements) = 231,571.74 + 32,061.37 + 17,110.83 = **280,743.94**, a cash-flow suggestion.
- **Category granularity differs:** cost has 3 accounts (111 cars, **113 furniture + office equipment + computers combined**, 114 civil-defence systems), but accumulated depreciation has 4 (cars, furniture, office equipment, civil defence). The PPE note can't pair them one-to-one without a user mapping (Q8).

**Other movement facts that make "suggestions to confirm" essential**
- **EOSB 2301:**
  - opening 63,425.39 + Cr 53,240.67 − Dr 975.34 = closing 115,690.72
  - expense 8122: Dr 53,240.67, Cr 975.34
  - The 975.34 Dr is a **reversal, not a payment**. A naive "Dr movement = paid" suggestion would show 975.34 of EOSB paid; the true figure appears to be 0.
- **Doubtful debts 2302:**
  - Dr 1,225 (write-off) and Cr 74.70
  - **There is no bad-debt expense account in the P&L**, so the 74.70 charge has no visible counterpart.
- **Warranty provision 2305:**
  - Cr 200,354.25, but warranty expense 8221 is 160,320.00, a **40,034.25 difference** with no visible counterpart.
  - Dr 191,451.59 used.
- **Loan 2401:**
  - repaid Dr 2,239,671.99, drawn Cr 1,237,200.00, closing 3,567,555.43
  - The current portion is unknown (user input).
- **Deferred interest 2402:**
  - amortised Cr 349,601.26
  - finance cost 8602 is 351,889.76, a 2,288.50 difference (info)
  - new deferred interest Dr 206,200
- **Sales:** account 52 carries returns as a Dr movement of 2,821,644.68. Discounts allowed (8203) sit in the 8xxx range.

### 0.4 Generic signals this file illustrates
Each bullet is a kind of input any client's file may have. The rules for it are generic (Q33, Q34, Q40). The specifics here only describe the fixture.

- **A client classification column can carry the *function*** (cost / G&A / selling / depreciation / finance), not only the statement line. Here it is column B: «تكلفة إيرادات», «المصروفات العمومية والإدارية», «المصروفات البيع و التوزيع», «مصروفات الإهلاك», «مصاريف التمويل». When present, it is the default for the function split (Q4, Q34).
- **A client note-classification column** gives note sub-lines. Here it is column C (e.g. «عهد وسلف موظفين», «عملاء دفعات مقدمة»).
- **A note-number column can exist.** Here it is column M, and the fixture's numbering is:

  | No. | Note | No. | Note | No. | Note | No. | Note |
  |---|---|---|---|---|---|---|---|
  | 5 | PPE | 9 | cash | 13 | loans | 17 | zakat |
  | 6 | receivables | 10 | capital | 14 | payables | 21 | inventory |
  | 7 | prepaid | 11 | reserve | 15 | accruals | 22 | provisions |
  | 8 | due from RP | 12 | EOSB | 16 | due to RP | 23 | RE |

  P&L rows and some BS rows have no number. The classifier currently misreads such a column as remarks (Q33).
- **Related parties can hide in several classes:** RP classes, partners' current accounts, and accounts or salaries whose names match a partner. The fixture has examples of each. The generic rule is in Q40.
- **Partners' current accounts can dwarf capital** (in the fixture, capital is 15,000 against 14.32 M in partners' current). This is why the presentation choice (Q23) matters.
- **Period dates are often absent from the file**, so the period is always user input.

---

## 1. Current-state findings

### 1.1 Your understanding (brief §1) vs the code

| # | Your point | Code says |
|---|---|---|
| 1 | Any layout or language | ✅ `SpreadsheetReader` + AI structure detection (`AccountClassifierRepository::detectStructure`) + `StructureChecks`. |
| 2 | AI detects which column holds what | ✅ Roles in `AccountExtractor::FIELDS`: `account_number/name`, `{opening,period,adjustment,closing}_{debit,credit,net,side}`, `notes`, `audit_request`, `existing_classification`, `existing_note_classification`. There are no date or currency roles. |
| 2b | …and the result keeps those roles | ⚠️ **Partly.** The mapping is saved in `classification_runs.structure`, but it is **not** in the done-job API response. Only `meta.groups` (the groups really present) is exposed. **Missing groups are filled by copying another group** (`AccountExtractor::extract`, fallback order period → closing → opening; adjustments default to 0). In a closing-only file, every row's `opening_*` and `period_*` equal the closing values. The generator **must** trust `meta.groups`, never the per-row values of an absent group. |
| 3 | PHP reads every amount, AI never writes numbers | ✅ The AI tools (`submit_structure`, `submit_rows`) carry no amounts. |
| 4 | `classification_confidence` / `data_flags` / `source_note` separate; `needs_review` derived | ✅ `finalizeRow()`: confidence < 70 OR any non-`info` flag OR a `source_note`. |
| 5 | Cache, accountant import, RTL Blade, background jobs, `/api/v1`, Swagger | ✅ Swagger is a static `public/docs/openapi.yaml`, enforced by `OpenApiSpecTest` and `ApiContractTest` (an undocumented field fails the tests). |
| 6 | No auth | ✅ None at all: no middleware, no throttle, no CORS config. Runs are reachable by UUID only and have no owner. |

Other facts that matter here:

- **Per-row amounts are kept**: all four groups as separate Dr/Cr pairs in `classification_runs.result` (longText JSON, `{data, meta}`). There is no per-row table.
- **The original upload is deleted** when the run ends. The generator can only use `result` and cannot re-read cells. This is fine: `result` already holds PHP-read cell values.
- **Only `account` rows survive.** Parents, totals and sections are dropped, so no account hierarchy is available.
- **Manual corrections rewrite `result` in place** (`ClassificationCacheController::applyCorrections`). There is no history and no version counter on the run.
- **No company, period, fiscal-year or currency** anywhere on a run.
- `meta.warnings` / `meta.info` are flat Arabic strings, not codes. The new checks should use structured codes, and may also emit the same string style.
- "Partial TB" (`fileChecks`) = not all 5 families present (assets, liabilities, equity, income, expenses). This differs from `meta.partial`, which means unclassified rows exist.
- **No PDF or DOCX library** is installed. PhpSpreadsheet 1.30 is, with RTL used in `CacheTransfer::exportXlsx`. `maatwebsite/excel` is installed but unused.
- **Frontend**: three standalone Blade pages with inline CSS and JS and no shared layout. They use `#app-config` data attributes and `fetch` with polling.
- **Tests**: SQLite `:memory:` by default; MySQL is a manual docker run (`docs/08`). There is no CI. The AI is faked with `Http::fake` and `preventStrayRequests`.
- **From the sample run (§0):**
  - There is **no column role for a note-reference column**, so a «إيضاح» column of note numbers is read as `notes`, which turns every numbered row into `needs_review`.
  - `existing_classification` already carries the client's function split.
  - `classification_mismatch` is noisy when the file's classes are functions and the taxonomy's are natures.
  - `meta.groups` lists a group whose columns are all empty.

### 1.2 What this feature can reuse

| Need | Reuse |
|---|---|
| Account rows with amounts and labels | `classification_runs.result.data[]` via `ClassificationRun` |
| Which balance columns really exist | `result.meta.groups` |
| Normal side, contra accounts, families | `TaxonomyRepository::normalSide()`, `familyOf()`, `map()` |
| Wrong-side balances | `abnormal_opening` / `abnormal_closing` flags |
| Opening + movement = closing per row | `reconciliation` flag + `matched_adjustment` |
| TB balance / partial-TB detection | `fileChecks` output, which needs exposing as a code (see §4.3) |
| Correcting a class | existing `PATCH /classify/jobs/{id}/rows/{row}` |
| Excel writing with RTL | PhpSpreadsheet, pattern in `CacheTransfer::exportXlsx` |
| Migration conventions | `string` statuses (no enums), JSON as `text` + array cast, short index names |
| Tests | `TrialBalanceFiles` trait (builds xlsx in code), `OpenApiContract`, `PageConfig` |

### 1.3 Exact gaps

1. **No statement-line layer.** The taxonomy is by nature (≈70 main classes, ≈145 sub-classes). The statements need a function-based, current/non-current line set. See §3.1.
2. **No function split for expenses**: salaries and rent can belong to cost of revenue, selling or G&A [Q4].
3. **Accumulated depreciation is one sub-class** (`مجمع إهلاك الأصول الثابتة`), not one per asset category. The PPE note needs it per category [Q8].
4. **No company, period or prior-year entities.**
5. **No account-level tags** for murabaha deposits vs current accounts, loan current portion, or partners' current account presentation.
6. The column mapping is not exposed in the API. This is not needed if the generator reads the run server-side.
7. There is no PDF/DOCX library and no report persistence.
8. **No periodic-inventory sub-classes** (opening and closing stock inside cost of sales). When a client labels those accounts as inventory, the classifier follows the label and puts them on the balance sheet (seen in the fixture, §0.3) [Q32].
9. **No warranty / other-provisions sub-class**: such provisions land in whatever class is closest, e.g. an inventory contra account in the fixture [Q37].
10. **No note-reference column role** [Q33].

### 1.4 Why `prefix_mismatch` missed the periodic stock accounts (investigated)
**Code:** `ClassificationService::flagNumberPrefixes`, config `prefix_check` = `{min_accounts: 5, min_share: 0.8, min_length: 2}`.

**How it works**
1. For each **first digit** of an all-numeric account code, it counts the classified accounts per **family**: assets, liabilities, equity, revenue, expenses (cost is folded into expenses).
2. The family comes from the rows' own AI classes.
3. A digit gets a scheme only if it has at least 5 accounts and one family holds at least 80% of them.
4. Accounts under that digit in another family are flagged.

Fixture distribution (numeric codes only):

| Digit | 1 | 2 | 3 | 4 | 5 | 6 | 7 | 8 | 9 |
|---|---|---|---|---|---|---|---|---|---|
| Accounts | 32 | 19 | 6 | 1 | 1 | 1 | 1 | 94 | 1 |
| Statement | BS | BS | BS | P&L | P&L | BS (61) | P&L | P&L | BS (901) |

**Your guess is right in part:**
- **Immediate cause: too little evidence per digit.** Digits 6 and 9 hold one account each, below `min_accounts`, so they get no scheme and are never checked (the same goes for 4, 5 and 7).
- **Your cause, latent: self-confirming evidence.** The evidence is the file's own classified rows, *including the row being tested*. Lowering `min_accounts` would not help: for digit 6 the only evidence is 61 itself, already misclassified, so the scheme would learn "assets" from the mistake.
- **Wrong granularity.** The file's real scheme is **statement-level and range-based** (1–3 balance sheet, 4–9 P&L). The check works per single digit and per family, so it can't see a range boundary.
- **Codes with letters are skipped.** The check requires all-numeric codes, so accounts like `1204MBA009` give no evidence at all.

**Generic fix (PR 1)**
1. **Statement-level split.** Find the single split point *k* over leading digits that best separates balance-sheet from P&L accounts (BS ≤ k < P&L, or the reverse).
   - Use every classified account whose code *starts* with a digit; letters after it are allowed.
   - Accept the scheme only if agreement ≥ 0.95 and each side has at least `min_accounts`.
   - Mixed numbering gives no scheme and no flag.
2. **Leave-one-out.** When testing an account, compute the scheme without it (counts minus one), so an account never counts as evidence for its own classification. Apply the same to the existing per-digit family check.
3. **Flag accounts on the wrong side of *k*.**
   - Reuse the existing `prefix_mismatch` type with `severity: high`. Both values already exist in the OpenAPI `DataFlag` enums, so the contract doesn't change.
   - It caps confidence like today and sets `needs_review`.
   - The message names the statement scheme.
4. **On the fixture:** *k* = 3. Accounts 61 and 901 are flagged; 42, 52 and 72 are consistent.

---

## 2. Issues in the reference files

### 2.1 `القوائم المالية لشركة SMEs .xlsx`

**Dates and periods**
1. `data!C6:E6` current year end is **31 January 2025**, but its start (`C7:E7`) is 1 January 2025. That is a one-month "year", next to a comparative year ending 31 December 2024. Every title and column header inherits this.
2. Day, month and year are free-text cells (`يناير`, `2025 م`), so period length cannot be validated. The new design uses real dates.
3. The docx uses year end **31 December 2025**, so the two references contradict each other.

**`#REF!` errors and leftovers**
4. `قائمة الدخل الشامل!I11` `=I9+#REF!`, `I23` `=SUM(#REF!)`.
5. `قائمة التغيرات`: `Q3`, `O5`, `O11`, `V11`, `O15`, `Q15`, `Q23`, `P29`, `P31`, `P33` are all `#REF!`.
6. `قائمة التدفقات النقدية!H51` `='المركز المالى'!#REF!`, and `H52` depends on it.
7. **Hard-coded numbers from another company** in the equity sheet: `O4 =1600000+297550`, `O6 451912`, `V6 −131887`, `S15/V15/S23/V23/N25 131887`, `W11 −371125`, `N27 83541`, `N28 287584`.

**Statement of financial position (`المركز المالى`)**
8. **Trade payables appear twice**: `A36 ذمم تجارية دائنة` and `A41 الذمم التجارية الدائنة`.
9. **Current-liabilities total omits row 36**: `E45 =SUM(E37:E44)`.
10. The balance check has opposite signs: `E52 =E23−E47` but `H52 =H47−H23`.
11. Column `B` "ايضاح" has no note references, and the footer says `إيضاح (..)`.
12. Lines in the taxonomy are missing from the statement: intangible assets, lease liabilities and income tax provision. The SOCE has an unpaid-capital column, but the SOFP has no line for it.
13. Equity has no line for OCI reserves (revaluation surplus, fair-value reserve), although OCI items exist. It also has no line for partners' current accounts.
14. Typos: missing space in `إجماليالأصول` and similar totals; `كما في31`; `لإطراف`.
15. Lines that don't fit IFRS for SMEs (see 2.4): `استثمارات فى أدوات حقوق الملكية بالقيمة العادلة من خلال الدخل الشامل الآخر` and `استثمارات بالتكلفة المطفأة` use IFRS 9 categories.

**Statement of comprehensive income (`قائمة الدخل الشامل`)**
16. **The OCI block is duplicated**: rows 26–38 and rows 40–51 have the same items with different wording.
17. **`E51 =SUM(E25:E50)`** adds net profit **plus both OCI blocks** and is labelled "إجمالي الدخل الشامل الآخر". It double-counts, and the label is wrong.
18. Rows 37 and 38 (OCI total, total comprehensive income) have no formulas.
19. **There is no finance-cost line**, although the cash flow has "صافي تكلفة التمويل" and the SOFP has murabaha loans.
20. `التغييرات في فائض إعادة التقويم للاصول الغير ملموسة`: IFRS for SMEs does not allow revaluing intangibles (cost model only).
21. Signs rely on expenses being typed as negatives (`SUM` everywhere). This is undocumented.
22. There is a stray `.` in `H15`. The title is missing a space (`فى31`).

**Statement of changes in equity (`قائمة التغيرات`)**
23. **`K20` (net income for the year) `='قائمة الدخل الشامل'!E51`**: it takes the broken total from item 17, not net profit (`E25`).
24. There is no OCI-reserve column, no transfer-to-reserve row and no partners' current-account movement.
25. Stray labels `C7 راس المال المدفوع` and `C8 صافي (خسارة)الفترة`, with formulas in `L7` and `L8`.
26. `M = E−G+I+K` subtracts unpaid capital (correct in sign), but the unpaid-capital column has no source.

**Cash flow (`قائمة التدفقات النقدية`)**
27. **Duplicated lines**:
    - `B16 الخسارة من بيع ممتلكات` vs `B18 أرباح أو خسائر بيع ممتلكات`
    - `B13 صافي الحركة في مخصص الإنخفاض…المدينين` vs `B21 مخصص الإنخفاض…المدينين`
    - `B14` vs `B20` (inventory)
28. `C48 ='المركز المالى'!B21` points at the **note column**, not the amount.
29. There is no comparative opening cash (`H47` is empty).
30. These lines are missing:
    - finance cost paid
    - movement in murabaha deposits
    - capital increase
    - partners' current-account movements
    - unbilled revenue and deferred revenue working-capital lines
31. `أصول حيوية بغرض البيع` appears in the cash flow but nowhere else (leftover).
32. Only `E30` has a formula. Every other working-capital line is empty.

**PPE note (`ايضاح الاصول والممتلكات`)**
33. **`C19:I19 =C12−C18`**: row 12 is empty, so the comparative NBV is the negative of the current NBV.
34. `B13` heading `الاطفاء المتراكم` means amortisation, not depreciation.
35. There is no capital-work-in-progress column, although the policy and the cash flow mention projects under construction.
36. The note number "5-" is hard-coded.

**Cover / index**
37. The index lists the **independent auditor's report** and pages "19-5". The city "الرياض" is hard-coded.

### 2.2 `الإيضاحات المتممة - نسخة منقحة.docx`

**Dates and fixed values**
1. Year end is hard-coded "31 ديسمبر 2025" in the header and the going-concern paragraph. The PPE tables use `1/1/2025` and `31/12/2025`. Calendar year is hard-coded ("تبدأ السنة المالية … يناير … ديسمبر").
2. **The approval date doesn't convert**: "17 شوال 1447هـ الموافق 15 فبراير 2026م". 15 Feb 2026 falls in late Sha'ban 1447; 17 Shawwal 1447 is about early April 2026.
3. Riyadh is hard-coded in incorporation and the head office.

**Alternative or duplicated wording kept side by side**
4. Inventory policy appears twice: general, then manufacturing. A Word comment says "في حالة الشركات الصناعية".
5. Employee benefits appear twice: actuarial (PUC), then Saudi Labour Law wording. Comments mark the alternatives.
6. The revenue policy holds seven industry variants: services/real estate, contracting, leasing, hospitality, murabaha, property sales, education. These are conditional; the comments say so.

**Tables**
7. `دائنون وأرصدة دائنة أخرى` lists `مخصص اجازات العاملين` **twice**.
8. `الذمم التجارية الدائنة` and `تكلفة النشاط` tables have **no row labels**.
9. The cash note has no "cash on hand" row, but it includes **murabaha deposits inside cash and cash equivalents**. That conflicts with the murabaha-deposit policy (more than 3 months, shown separately) and with the separate SOFP line.
10. Intangibles table: `مجمع الاستهلاك` / `استهلاك العام` should say إطفاء. Its policy also lists "العلامات / الحقوق الأخرى: [●]", which has no column.
11. Related-party tables: hard-coded labels "15/أ" and "15/ب". "حجم المعاملات" has no comparative column.
12. Zakat movement merges "المستخدم خلال السنة ورد المخصص". Paid amounts are a cash flow, but a reversal is not, so they must be separate.

**Zakat and statutory wording**
13. **The zakat rate sentence is wrong or garbled**: "2.5٪ من الربح المعدّل و2.8% من الوعاء". Zakat is 2.5% of the zakat base (about 2.5776% for a Gregorian year), with adjusted profit as a floor. Confirm the wording with your zakat adviser.
14. The proposed-dividends wording "أقر الشركاء بتوزيع … مليون" mixes "proposed" with "approved", and puts "مليون" next to a placeholder amount.

**Leftovers**
15. The significant-events note is real text from another company: an MoU with 38.75% and a 6-month exclusivity.
16. RTL bracket inversions: "ستة) 6(", ") المذكرة (", "الضرائب الأخرى) … (", and others.
17. A stray "ج" ends the fair-value paragraph. Several headings run into their paragraph (`المنافع قصيرة الأجلتُثبت`).

**Content that doesn't fit IFRS for SMEs or a standalone LLC**
18. A subsidiaries/consolidation policy, with IFRS 10 control wording, for a standalone LLC.
19. IFRS 13 fair-value hierarchy wording ("principal or most advantageous market").
20. IFRS 9 expected-credit-loss wording ("نموذج قياس الخسائر الائتمانية"), which contradicts the incurred-loss policy (Section 11) a few paragraphs earlier.
21. IFRS 15 control-transfer wording in the property-sales revenue policy. Which edition applies is Q6.

**Structure**
22. Policies with no matching statement line: intangibles, CWIP, investment in subsidiaries, leases as lessor.
23. **Notes with no source in the statements**: revenue, selling and marketing, other income, finance cost.
24. The estimates section is headed "الأحكام والتقديرات" but contains only estimates, no judgements.
25. Note numbers are Word auto-numbering (140 `numPr`), so there is nothing to sync with the statements' note column.

### 2.3 Inconsistencies between the two files
- Year end: 31 Jan 2025 (xlsx) vs 31 Dec 2025 (docx).
- The xlsx PPE note has **no comparative-year table**. The docx has full 2025 and 2024 tables. IFRS for SMEs 17.31 does not require the prior-period reconciliation.
- The docx has inventory, receivables, cash, capital, EOSB, payables, RP, zakat, cost and G&A notes. The xlsx statements reference none of them.

### 2.4 Framework fit (decided: IFRS for SMEs **3rd edition** wording, Q6)
- The policy wording follows the **3rd edition (2025)**. Some items flagged above as "full IFRS" become appropriate under it:
  - five-step revenue (Section 23)
  - fair-value measurement (Section 2A)
  - IFRS 10-style control (Section 9)

  Their wording is re-checked in §8, not deleted.
- Items that still don't fit and are fixed as errors:
  - revaluation of intangibles (2.1 #20)
  - the ECL sentence (2.2 #20, pending the check in §8 E9)
  - the duplicated and wrong totals
- The FVOCI and amortised-cost **lines** stay in the layout (same lines as the reference) and are auto-hidden at zero (Q13).
- The **statutory reserve** is no longer mandatory for LLCs under the 2022 Companies Law. Keep the line, but don't assume a 10% transfer (Q24).
- The layout, lines, notes and order stay exactly as in the references. Only the §2 errors are fixed; anything new goes through §8.
- Any prior-year figures in the references are examples only. Comparatives always come from the user (Q7).

---

## 3. Coverage matrix

**Columns**
- **Layouts:** **C** = closing only · **OC** = opening + closing · **OMC** = opening + movement + closing (± adjustments).
- **Current**: how the current-year figure is obtained in each layout.
- **Comparative**: the balance-sheet comparative equals the TB's **opening** column when present (O). It is checked against the prior source when both exist. P&L comparatives always need **P**.

> Rows marked **(missing in ref)** are not in the reference layout. They are used only if already approved (Q23, Q32, Q36, Q37, Q39) or once you approve them in §8. Until then, accounts that need them are listed as unmapped: final is blocked, and nothing is ever dropped silently.

### 3.1 Line mapping status (feeds Q3)
- **mapped**: an existing main or sub class covers it.
- **split**: a sub-class exists but the line needs an account-level tag or rule.
- **gap**: no sub-class.

### 3.2 Statement of financial position

| Line | Taxonomy source | Map | Current (C / OC / OMC) | Comparative | User | Checks |
|---|---|---|---|---|---|---|
| الممتلكات والآلات والمعدات (net) | main `الممتلكات و الالات و المعدات` incl. contra | mapped | A/A/A | O or P | — | PPE note NBV = line; wrong-side contra |
| استثمارات عقارية | — | **gap** | U-map | O/P | tag accounts | IP note = line |
| أصول غير ملموسة (missing in ref) | main `اصول غير ملموسة` | mapped | A | O/P | — | note = line |
| شركات زميلة ومشروعات مشتركة | `استثمارات طويلة الأجل` (ambiguous) | **split** | A after tag | O/P | tag | — |
| استثمارات طويلة الأجل / بالتكلفة المطفأة غير متداولة | `استثمارات طويلة الأجل` | split | A | O/P | tag | — |
| FVOCI equity | — | **gap** + framework issue | — | — | [Q6] | — |
| المخزون | main `المخزون` net of `مخصص هبوط…`. **Exclude periodic opening/closing-stock accounts**, found by `periodic_inventory_pair` (balances and movements), never by account number or name | mapped + **rule** | A | O/P | confirm periodic pair [Q32] | note = line; `periodic_inventory_pair` |
| إيرادات غير مفوترة | `إيرادات مستحقة` (inside prepaid main) | **split** | A after rule | O/P | confirm | — |
| الذمم التجارية المدينة | main `الذمم المدينة التجارية` net of `مخصص ديون…` | mapped | A | O/P | — | note = line; contra side |
| مصاريف مقدمة وأرصدة مدينة أخرى | main `مصروفات مدفوعة مقدما…` | mapped | A | O/P | — | note = line; side pairs |
| استثمارات قصيرة الأجل | `استثمارات قصيرة الأجل` | mapped | A | O/P | — | — |
| مستحق من أطراف ذات علاقة | main `اطراف ذات علاقة مدينة` | mapped | A | O/P | RP details U | RP note = line |
| ودائع مرابحة لأجل | `نقدية لدى البنوك` | **split** | A after tag | O/P | tag deposit accounts | cash note |
| النقد ومعادلات النقد | main `النقد ومافي حكمه` (debit balances) | mapped | A | O/P | — | CF closing cash = line |
| رأس المال (± unpaid) | main `رأس المال` | mapped | A | O/P | partners U | capital note Σ = line |
| الاحتياطي النظامي | main `احتياطي نظامي` | mapped | A | O/P | — | SOCE |
| أرباح مبقاة | main `الارباح المبقاه` **+ current-year result** if the TB is pre-closing | mapped + rule | **D** | O/P | — | SOCE RE roll |
| جاري الشركاء | `جاري الشريك` (under RP payable) | **split** | A | O/P | [Q23] | — |
| قروض مرابحة (non-current / current portion) | `قروض طويلة الأجل`, `قروض قصيرة الأجل` | split | A, current portion U | O/P | current portion | split sums to total |
| مخصص التزامات منافع الموظفين | main `مخصص نهاية الخدمة` | mapped | A | O/P | — | EOSB roll-forward |
| مستحق لأطراف ذات علاقة طويلة الأجل | — | **split** | A after tag | O/P | tag | — |
| ذمم تجارية دائنة (once) | main `الذمم الدائنة التجارية` | mapped | A | O/P | — | note = line |
| سحب على المكشوف | credit-balance bank accounts | rule | **D** | O/P | [Q25] | CF cash definition |
| إيرادات مؤجلة | `دفعات مقدمة من العملاء` (?) | **split** | A after rule | O/P | confirm | — |
| توزيعات أرباح مستحقة | — | **gap** | U-map | O/P | tag | dividends roll |
| مصاريف مستحقة وأرصدة دائنة أخرى | main `مصروفات مستحقةو…` | mapped | A | O/P | — | note = line |
| مستحق لأطراف ذات علاقة | main `اطراف ذات علاقة دائنة` minus partners' current | mapped | A | O/P | — | RP note |
| مخصص الزكاة | main `مخصص الزكاة الشرعية` | mapped | A, **+ user zakat charge if the TB has none** [Q38/Q39] | O/P | current-year charge | zakat roll-forward; `zakat_used_no_charge` |
| مخصصات أخرى (ضمان…) (missing in ref and taxonomy) | — | **gap** | A after map | O/P | map | provision roll; `provision_without_pl_counterpart` |
| قروض مرابحة, net of deferred finance cost | loan classes − a deferred-finance-cost account (tagged) | **split** | D | O/P | [Q36] | — |
| دفعات مقدمة من العملاء / للموردين | existing side pairs (customers with Cr, suppliers with Dr balances) | mapped | A | O/P | presentation [Q35] | — |
| مخصص ضريبة الدخل (missing in ref) | main `مخصص ضريبة الدخل` | mapped | A | O/P | — | — |
| التزامات عقود الإيجار (missing in ref) | main `التزامات عقود الإيجار` | mapped | A | O/P | current portion U | — |
| Totals, assets = equity + liabilities | — | — | D | D | — | **balance check** |

### 3.3 Statement of comprehensive income
P&L account closing balances are year-to-date in **every** layout, so the current year is **A** for all three layouts. If the TB is **post-closing** (P&L accounts are zero and RE holds the result), current-year P&L cannot be built and must be flagged [Q22].

| Line | Taxonomy source | Map | Current | Comparative | User | Checks |
|---|---|---|---|---|---|---|
| إيرادات النشاط | main `إيرادات النشاط` net of contra | mapped | A | **P** | — | revenue note |
| تكلفة النشاط | `تكلفة البضاعة المباعة` + nature items assigned to cost + **opening stock (+), closing stock (−)** in periodic systems | **split** [Q4, Q32] | A after function map | P | function map (default from the file's column B when present [Q34]) | cost note = line; `periodic_inventory_pair` |
| مصاريف البيع والتسويق | nature mains (دعاية, عمولات بيع, تسويق…) + assigned | split [Q4] | A | P | function map | — |
| المصروفات العمومية والإدارية | remaining nature mains | split [Q4] | A | P | — | G&A note = line |
| مصروف الإهلاك | `إهلاك…` and `إطفاء…` mains | mapped | A | P | — | = PPE/intangible/IP charge |
| مصروفات أخرى / إيرادات أخرى | `غرامات…`, `مصروفات متنوعة` / `إيرادات أخرى`, `ارباح و خسائر رأسمالية` | mapped | A | P | — | — |
| خسائر / رد خسائر الانخفاض في الذمم | `مصروف ديون مشكوك…` / — (no reversal sub-class) | split / **gap** | A / U-map | P | — | impairment roll |
| تكاليف التمويل (missing in ref) | `فوائد قروض`, `فوائد تسهيلات`, `عمولات تمويلية`, `خسائر فروق عملة`(?) | mapped | A | P | — | — |
| توزيعات أرباح من أدوات ملكية / حصة في زميلة | `إيرادات استثمارات` (ambiguous) | split/gap | A after tag | P | tag | — |
| الزكاة | main `مصروف الزكاة الشرعية` | mapped | A, or **U** when the TB has no charge [Q38] | P | amount from the zakat computation | zakat roll; **no-charge anomaly** |
| ضريبة الدخل (missing in ref) | `مصروف ضريبة الدخل`, `ضريبة الدخل المؤجلة` | mapped | A | P | — | — |
| OCI items (one block only) | — | gap | **U** | P | amounts | OCI reserve roll |
| Net profit, total comprehensive income | — | — | D | D | — | = SOCE |

### 3.4 Statement of changes in equity (both years shown)

| Row | C | OC | OMC | User | Checks |
|---|---|---|---|---|---|
| Opening balance per column, current year | P | O | O | — | = prior closing (P) when both exist |
| Opening balance, comparative year (prior-prior) | P | P | P | — | — |
| Net profit | D | D | D | — | = IS |
| OCI | U | U | U | — | = IS OCI |
| Capital increase | U | D (net change) → U confirms | **S** (capital Cr movement) | confirm | capital column roll |
| Dividends | U | U | **S** (RE Dr movement) | confirm | dividends roll |
| Transfer to reserve (missing in ref) | U | D/U | S | confirm | — |
| Transfer RE → partners' current (if equity) | U | U | S | confirm | **anomaly: RE cleared, no dividend** |
| Closing per column | D | D | D | — | **= SOFP equity lines** |

### 3.5 Cash flow (indirect)
Without opening balance-sheet figures (layout C) the cash flow needs the **prior SOFP (P)**. Without it, the whole statement is missing.

| Line | C | OC | OMC | User | Checks |
|---|---|---|---|---|---|
| Profit before zakat | D | D | D | — | = IS |
| Depreciation and amortisation | D (expense) | D | D | — | vs acc. dep. Cr movement (OMC) |
| EOSB charge | A (`مصروف مكافأة…`) | A | A / S (provision Cr movement) | — | EOSB roll |
| Impairment / write-downs, gains or losses on disposal | A | A | A | — | — |
| Finance cost (if shown separately) | D | D | D | — | — |
| Working-capital changes per line | needs P | D (open − close) | D | — | — |
| EOSB paid | U | D (open + charge − close) | S (Dr movement, **minus reversals matched to a Cr on the related expense account**, see `reversal_as_payment`) | confirm | roll |
| Zakat paid | U | D (open + charge − close) | S | confirm | roll |
| PPE purchases / CWIP payments | U | U (split of net change) | **S** (cost Dr movement) | confirm | additions − disposals = Δcost |
| **Proceeds from PPE sales** | U | U | **U always** (never in a TB) | required if disposals ≠ 0 | gain/loss = proceeds − NBV disposed |
| Investments, murabaha deposits | U | D/U | S | confirm | — |
| Loans drawn / repaid | U | D (net) / U split | S (Cr / Dr movements) | confirm | — |
| Dividends paid | U | D (open payable + declared − close payable) | S | confirm | — |
| Distributions credited to partners' current accounts (non-cash) | U | U | S (RE Dr movement matched to partners' current Cr movements) | confirm | non-cash disclosure |
| Partners' current, capital paid (missing in ref) | U | D | S | confirm | — |
| Net change, opening, closing cash | D | D | D | — | **closing = SOFP cash (per Q25 definition)**; opening = prior closing |
| Non-cash transactions disclosure | U | U | U | — | — |

### 3.6 Notes

| Note | Content | Source | Checks |
|---|---|---|---|
| Incorporation and activity | CR, unified no., city, branches, activity, head office, fiscal year | U (company profile) | fiscal-year text from dates |
| Basis of preparation, currency | framework, measurement, currency | template + U | — |
| Going concern | 4 indicators | **D** (detect) + U (confirm, mitigation text) | indicator amounts = statements |
| Accounting policies | conditional blocks | template + U toggles [Q11] | policy shown iff relevant line ≠ 0 or forced |
| Useful lives table | per PPE category, intangibles | U | — |
| Estimates and judgements | fixed + toggles | template | — |
| PPE movement (current year, plus comparative if the docx style is chosen) | per category: cost opening/additions/disposals/closing; acc. dep. opening/charge/disposals/closing | cost closing A per sub-class; opening O (OC/OMC) or P; additions/disposals S (OMC) or U; acc. dep. **by category U** (single contra in taxonomy) [Q8] | closing NBV = SOFP; charge = IS; opening + add − disp = closing |
| Intangibles, investment property movement | same | same | same |
| Inventory | per sub-class | A; comparative O/P | Σ = line |
| Trade receivables | gross − provision | A | Σ = line |
| Prepayments and other receivables | per sub-class | A | Σ = line |
| Cash and cash equivalents | cash on hand, current accounts, deposits ≤ 3m | A after tags | Σ = line; consistent with CF |
| Capital | shares, value, partners table | **U**; capital amount A | Σ partners = capital; % = 100 |
| EOSB movement | opening, charge, paid, closing | O + A + S/D | roll |
| Trade payables | (labels missing in ref) per sub-class | A | Σ = line |
| Accruals and other payables | per sub-class (one leave row) | A | Σ = line |
| Related parties | name, relationship, nature, volume; balances | balances A (per account); rest **U** | Σ = SOFP lines |
| Zakat | basis text, movement (opening, charge, prior-year adj., paid, reversal, closing) | O + A + S/D + U; the charge is U when the TB has none | roll; **charge = 0 & provision used → anomaly** |
| Provisions (warranty…) (missing in ref) | movement: opening, charge, used, reversed, closing | O + S + U | roll; charge vs the related P&L expense |
| Inventory (periodic) | closing stock; cost of sales build-up (opening + purchases + landed costs − closing) | A after Q32 | opening-stock account = inventory opening; closing-stock account = −inventory closing |
| Cost of revenue, G&A, selling | per nature item | A after function map | Σ = IS line |
| Revenue, other income, finance cost (missing in ref) | per sub-class | A | Σ = IS line |
| Financial risk management | fixed text | template | — |
| Proposed dividends | amount, date | U | — |
| Subsequent events, significant events | text | U (default "none") | — |
| Approval | date (Hijri + Gregorian), body | U | approval date > period end |

---

## 4. Proposed design

### 4.1 Data model (SQLite + MySQL conventions)

**`companies`** [Q12]

| Column | Type / notes |
|---|---|
| `id` | uuid |
| `name_ar`, `name_en` | `name_en` nullable |
| `legal_form` | string, e.g. `llc` |
| `country` | char 2, default `SA` |
| `framework` | string, default `ifrs_for_smes` |
| `cr_number`, `cr_date_hijri`, `cr_date` | `cr_date_hijri` is a string, stored exactly as entered |
| `unified_number`, `city`, `head_office`, `activity` | `activity` is text |
| `branches` | text, array cast |
| `fiscal_year_end_month`, `fiscal_year_end_day` | — |
| `currency` | char 3, default `SAR` |
| `profile` | text, array cast: policy choices and useful lives that carry over between years |
| timestamps | — |

**`financial_reports`**

| Column | Type / notes |
|---|---|
| `id` | uuid |
| `company_id` | nullable, FK cascade |
| `classification_run_id` | uuid FK |
| `prior_report_id` | nullable self FK |
| `prior_run_id` | nullable |
| `status` | string: `draft` \| `final` |
| `period_start`, `period_end` | date |
| `comparative_start`, `comparative_end` | date, nullable (first year) |
| `template_version` | string |
| `inputs` | longText, array: every user-entered figure, text, toggle and confirmation |
| `account_overrides` | text, array: `row → line_key`, plus tags |
| `adjustments` | text, array: report-level adjusting entries [Q39] |
| `prior_figures` | longText, array: `line_key → amount`, with origin |
| `run_result_hash` | sha1 of `classification_runs.result` at last compute, to detect later corrections |
| `last_computed_at`, `finalized_at`, timestamps | — |

**`financial_report_versions`** [Q19]

| Column | Type / notes |
|---|---|
| `id` | — |
| `report_id` | FK cascade |
| `version` | int; unique (`report_id`, `version`), index name `frv_report_version_unique` |
| `kind` | `draft` \| `final` |
| `payload` | longText: the full report JSON (§4.4) |
| `inputs_hash`, `run_result_hash` | — |
| `created_at` | — |

- **Exports**: generated on demand from a version's `payload` [Q16]. There is no table unless stored exports are chosen.
- **Line mapping** lives in code, not a table: `config/financial_statements.php`. It holds the line catalogue, `sub_class → line_key` rules, side rules (e.g. a bank credit balance goes to overdraft) and note definitions. It is versioned like `cache.taxonomy_version`, and reviewed by an accountant through PRs [Q3].

### 4.2 Services, in run order
Everything is pure PHP. There is no AI anywhere in this pipeline [Q11].

1. **`ReportSource`**
   - Loads `classification_runs.result` and builds a `LayoutProfile`: the real groups from `meta.groups`, partial TB, unclassified count, needs-review count, and a pre/post-closing guess.
   - Ignores per-row values of absent groups.
2. **`LineMapper`**
   - Maps each account row to a `line_key`, in priority order: per-report override → side rule → sub-class rule → main-class rule.
   - Outputs mapped rows plus `unmapped[]`. An unmapped row with a non-zero balance blocks the final version.
3. **`LineAggregator`**
   - Builds per line and per column group (closing, opening if real, movement Dr/Cr if real, adjustments): an amount plus a list of contributing rows.
   - Normalises the sign by the line's normal side.
   - Wrong-side rows are surfaced, never netted silently.
4. **`PriorYearResolver`**
   - Fills comparative figures from the chosen source (Q7), and compares the prior closing with the current opening line by line.
5. **`IncomeStatementBuilder`**, then **`BalanceSheetBuilder`**
   - The balance sheet runs second because it needs the current-year result rolled into RE when the TB is pre-closing.
6. **`MovementSuggester`**
   - For OMC: proposes PPE additions and disposals, provision charges and uses, loan draws and repayments, capital and dividend movements.
   - Each is a `Suggestion {key, amount, basis, status: pending|confirmed|edited|rejected}`.
   - For OC: computes net changes and asks for splits.
   - For C: asks for everything.
7. **`AssetNotesBuilder`** (PPE / intangibles / IP) and **`ProvisionRollforwards`** (EOSB, zakat, impairment, dividends payable).
8. **`EquityStatementBuilder`**.
9. **`CashFlowBuilder`** (indirect), last among the statements because it consumes all of the above.
10. **`NotesBuilder`**: tables, visibility and numbering (Q13), and the statement note-reference column.
11. **`NarrativeRenderer`**: template blocks + placeholders + conditions, plus user text overrides.
12. **`ReportChecks`**: runs every check in §4.3.
13. **`ReportAssembler`**: builds the JSON (§4.4) and saves a version.

Every figure is a `Figure` value object: `{amount, origin, status, refs[]}`. Totals are computed and never typed. A total's origin is `derived`, with `refs` pointing to its parts.

### 4.3 Cross-checks
Shape: `{code, severity: error|warning|info, scope, line_key?, expected, actual, difference, message_ar}`.
- `error` blocks a final version.
- `warning` needs an explicit acknowledgement.
- `info` is shown only.

**Input integrity**
1. `tb_unbalanced`: Σ Dr ≠ Σ Cr per real group. Reuses the `fileChecks` logic, as a code.
2. `tb_partial`: not all 5 families present → final blocked [Q20].
3. `unclassified_rows`, `rows_need_review`, `unmapped_accounts`: counts and amounts.
4. `row_reconciliation`: the classifier's `reconciliation` flags, carried per line.
5. `wrong_side_balance`: the classifier's `abnormal_*` flags per line, with amounts.
6. `post_closing_tb`: P&L accounts are all zero while RE moved.

**Statements**

7. `sofp_unbalanced`: assets ≠ equity + liabilities, per year.
8. `net_income_mismatch`: IS net profit ≠ Σ P&L accounts.
9. `soce_closing_mismatch`: per column vs SOFP.
10. `soce_opening_mismatch`: vs prior closing.
11. `re_roll`: opening RE + profit − dividends ± transfers ≠ closing RE.
12. `cash_flow_closing_mismatch`: opening + net flow ≠ SOFP cash.
13. `cash_flow_opening_mismatch`: opening cash ≠ prior closing.

**Notes and roll-forwards**

14. `note_total_mismatch`: each note table total vs its statement line.
15. `asset_rollforward`: opening + additions − disposals ≠ closing, per category (PPE, intangibles, IP), cost and acc. dep.
16. `depreciation_mismatch`: charge in note ≠ IS expense ≠ acc. dep. Cr movement (OMC).
17. `provision_rollforward`: EOSB, zakat, impairment, dividends payable.
18. `disposal_without_proceeds`: disposals entered but no proceeds or gain/loss.
19. `split_total_mismatch`: user splits (loan current portion, additions vs disposals) vs the net change.
20. `capital_note_mismatch`: Σ partners ≠ capital; % ≠ 100.

**Comparatives and period**

21. `opening_vs_prior_closing`: per line, when both the TB opening and a prior source exist.
22. `period_invalid`:
    - length ≠ 12 months (warning, a short or long period allowed with confirmation)
    - comparative end ≠ start − 1 day
    - approval date ≤ period end

**Anomalies (warnings, never auto-resolved)**

23. `zakat_used_no_charge`: zakat provision decreased or was used and the zakat expense is 0.
24. `re_cleared_no_dividend`: RE Dr movement with a matching partners' current Cr and no dividend entered.
25. `going_concern_indicator`: one per indicator (§Q14).
26. `rounding_difference`: displayed rounded totals vs the rounded sum of parts.
All checks below use only classes, balances and movements, never account numbers, names or a specific client. Fixture cases that exercise each one are listed in §0.

26a. `periodic_inventory_pair`: an account classified under inventory or cost of sales either:
- has a balance equal to the opening balance (or minus the closing balance) of another inventory account, or
- has movements that mirror that account's movements.

Error until the user confirms the treatment.

26b. `provision_without_pl_counterpart`: a provision's Cr movement (layout OMC) ≠ the charge on the P&L class mapped to it.
26c. `reversal_as_payment`: a provision's Dr movement is matched by an equal Cr movement on its mapped expense class, so it is a reversal, not a payment.
26d. `empty_group`: `meta.groups` lists a group whose amounts are all zero. Info only.
26e. `same_party_multiple_accounts`: the same normalised counterparty name appears in more than one account across RP, partners' current, receivable/payable or salary classes. Info only; it feeds RP candidates (Q40).

**Presentation**

27. `note_reference_missing`: a statement line with a note reference to a hidden note.
28. `empty_required_input`: per field.

### 4.4 Draft report JSON (per version)

```jsonc
{
  "report_id": "uuid", "version": 3, "kind": "draft", "template_version": "sa-ifrs-smes-1",
  "company": { "name_ar": "…", "legal_form": "llc", "…": "…" },
  "periods": {
    "current":     { "start": "2025-01-01", "end": "2025-12-31", "label_ar": "31 ديسمبر 2025م" },
    "comparative": { "start": "2024-01-01", "end": "2024-12-31", "label_ar": "…" } // null in first year
  },
  "layout": { "groups": ["opening","period","closing"], "partial_tb": false, "post_closing": false },
  "statements": {
    "financial_position": {
      "sections": [{
        "key": "non_current_assets", "label_ar": "الأصول غير المتداولة",
        "lines": [{
          "key": "ppe", "label_ar": "الممتلكات والآلات والمعدات", "note": 5,
          "current":     { "amount": 120000.00, "origin": "trial_balance", "status": "ok",
                           "refs": [{ "type": "row", "run_id": "…", "row": 41 }] },
          "comparative": { "amount": 95000.00, "origin": "trial_balance_opening", "status": "ok", "refs": [] },
          "check_codes": []
        }],
        "total": { "current": { "amount": 0, "origin": "derived" }, "comparative": { } }
      }]
    },
    "comprehensive_income": { },
    "changes_in_equity": { "columns": ["capital","unpaid_capital","statutory_reserve","retained_earnings","total"], "rows": [ ] },
    "cash_flows": { }
  },
  "notes": [{
    "key": "ppe", "number": 5, "visible": true, "visibility": "auto|forced_on|forced_off",
    "title_ar": "…", "blocks": [
      { "type": "text", "template_key": "ppe.policy", "text_ar": "…", "edited": false },
      { "type": "table", "columns": [ ], "rows": [{ "label_ar": "إضافات خلال العام",
          "cells": [{ "amount": 5000, "origin": "suggested_from_movement", "status": "confirmed" }] }] }
    ]
  }],
  "suggestions": [{ "key": "ppe.additions.vehicles", "amount": 5000, "basis": "period_debit of rows 41,42",
                    "status": "pending" }],
  "checks": [{ "code": "sofp_unbalanced", "severity": "error", "scope": "comparative",
               "expected": 0, "actual": 1250.5, "difference": 1250.5, "message_ar": "…" }],
  "missing": [{ "key": "cash_flows.ppe_disposal_proceeds", "reason": "never_in_trial_balance", "input_key": "…" }],
  "can_finalize": false
}
```

- **`origin`** enum:
  - `trial_balance` (closing)
  - `trial_balance_opening`
  - `derived_from_movement` (confirmed suggestion)
  - `suggested_from_movement` (pending)
  - `derived` (computed from other figures)
  - `user_input`
  - `prior_year_report`
  - `prior_year_trial_balance`
  - `prior_year_manual`
  - `missing`
- **`status`** enum: `ok` | `pending_confirmation` | `missing` | `check_failed`.
- A `missing` figure has `amount: null` and is **never 0**.

### 4.5 API endpoints (all new, under `/api/v1`)
Additive only, and each is documented in `openapi.yaml` and `docs/api-reference.md`. Errors keep the existing shapes: `{message}`, 422 `{message, errors}`, 404 `{message}`.

**Companies** [Q12]

| Method | Path | Request | Response |
|---|---|---|---|
| POST | `/companies` | profile fields | 201 Company |
| GET | `/companies` | `?search=`, paginated | Paginated<Company> |
| GET / PATCH | `/companies/{id}` | partial profile | Company |

**Reports**

| Method | Path | Request | Response |
|---|---|---|---|
| POST | `/financial-reports` | `{classification_run_id, company_id?, period_start, period_end}` | 201 ReportState. 409 if the run is not `done`. |
| GET | `/financial-reports/{id}` | — | ReportState: inputs, layout, `required_inputs[]`, `missing[]`, `checks[]`, `suggestions[]`, `run_changed: bool` |
| GET | `/financial-reports/{id}/intake` | — | field definitions for this report: key, type, required, label, source, current value. Driven by layout and balances, so only relevant fields appear. |
| PATCH | `/financial-reports/{id}/inputs` | `{inputs:{key:value}}` (partial) | ReportState (recomputed) |
| PUT | `/financial-reports/{id}/account-overrides` | `{overrides:[{row, line_key, tags[]}]}` | ReportState |
| POST | `/financial-reports/{id}/suggestions` | `{decisions:[{key, action: confirm\|edit\|reject, amount?}]}` | ReportState |
| PUT | `/financial-reports/{id}/adjustments` [Q39] | `{entries:[{lines:[{row?\|line_key?, debit, credit}], reason}]}`, each entry balanced | ReportState. 422 if unbalanced. |
| PUT | `/financial-reports/{id}/prior-year` | `{source: report\|run\|manual, prior_report_id?, prior_run_id?, figures?{line_key: amount}}` | ReportState |
| POST | `/financial-reports/{id}/versions` | `{kind: draft\|final}` | 201 Version (payload §4.4). 422 `{message, checks}` if final is blocked. |
| GET | `/financial-reports/{id}/versions` | — | list `{version, kind, created_at}` |
| GET | `/financial-reports/{id}/versions/{v}` | — | Version |
| GET | `/financial-reports/{id}/versions/{v}/export.{xlsx\|docx\|pdf}` | — | file [Q15–Q17] |

Optional additive change to an existing endpoint: `GET /classify/jobs/{id}` adds a nullable `structure` when the job is done. This is **not required**, because the generator reads the run server-side.

Computing is synchronous (pure PHP over at most a few thousand rows). There is no queue unless export rendering needs one (PDF).

### 4.6 Frontend [Q18]
- **Entry**: a "إعداد القوائم المالية" button on `/classifier` once a run is done. It is disabled while rows are unclassified, and links to the run.
- **New page `/financial-reports/{id}`**: a step wizard with a persistent side panel of checks (errors, warnings, pending suggestions).
  1. Company and period: pick or create a company, period dates, framework (fixed for now).
  2. Prior year: source choice; for manual entry, a grid of line keys; shows opening-vs-prior differences.
  3. Account mapping: unmapped and ambiguous accounts with a line picker; tags (deposit, current portion, partners' current, long-term RP).
  4. Movements and missing figures: suggestions to confirm, splits, proceeds, OCI.
  5. Notes and text: policy toggles, useful lives, RP details, capital table, events, dividends, approval date, going concern.
  6. Review: the four statements and notes as they will print. Each figure has an origin badge, and a click shows its source rows.
  7. Versions and export.
- **Also**: a `/financial-reports` list page.
- **Stack**: stays Blade + vanilla JS. Recommend extracting the shared head/CSS into a Blade layout first; the new page will be several times larger than `classifier.blade.php` (781 lines). JS goes in `public/js/financial-report/*.js` modules (no build step) instead of inline.

### 4.7 Export [Q15–Q17]

| Format | Approach | RTL/Arabic fidelity | Notes |
|---|---|---|---|
| xlsx | Server, PhpSpreadsheet (installed) | Good: `setRightToLeft`, fonts by name | Values only, or values + formulas for totals |
| docx | Server, **PhpWord `TemplateProcessor` on a cleaned copy of the reference docx** (placeholders, `cloneRow`, `cloneBlock` for conditional sections) | Keeps the reference's Sakkal Majalla, RTL paragraphs, landscape sections and headers | Building docx from scratch with PhpWord has weak RTL tables (`bidiVisual`). Templating avoids that. Adds `phpoffice/phpword`. |
| pdf | Server: **mPDF** (pure PHP, proper Arabic shaping) from a Blade HTML view; or headless Chrome (best fidelity, needs Node + Chromium on the server) | mPDF good; dompdf poor for Arabic shaping | **Sakkal Majalla is a Microsoft font** and can't be embedded server-side without a licence. Use Amiri / Noto Naskh / Cairo for PDF. |
| client-side | Browser print-to-PDF of the review page | Acceptable | Zero server deps, but layout depends on the browser. Fine as a stopgap. |

Recommendation: **xlsx + docx server-side first**, then PDF via mPDF. Generate on demand from a stored version, so the same version always gives the same file.

---

## 5. Edge cases

| Case | Behaviour |
|---|---|
| **First-year company** | `comparative_*` null. Statements show one column. The SOCE starts from incorporation (capital introduced = movement). The cash flow's opening cash is 0. The going-concern and policy text changes from "consistently applied to all periods" to the first-year wording. The intake asks for the incorporation date; a long or short first period is allowed (check 22 → warning). |
| **No prior source, not first year** | The SOFP comparative comes from the TB opening columns when they exist (OC/OMC). The P&L, SOCE and cash flow comparatives are `missing` until the user supplies a prior-year source (Q7). A report with any missing comparative can only be exported as a draft (watermarked); final is blocked [Q20]. *(Confirmed by you, answering Q41.)* |
| **Prior closing ≠ current opening** | `opening_vs_prior_closing` per line with the difference. No auto-fix. The user either fixes the input, or marks the comparatives as restated (`restated: true` + explanation input) [Q9]. |
| **Unmapped accounts** | Listed with balances in step 3. The draft shows them under a visible "حسابات غير مُسندة" line so totals still balance. The final is blocked. |
| **Wrong-side balances** | Kept on their line with the classifier's flag. Specific rules: a bank account with a credit balance becomes a **suggested** overdraft reclass [Q25], and a customer with a credit balance a suggested advance (the existing side pairs). Never moved silently. |
| **Contra accounts** | Netted into their parent line (acc. dep., provisions). Shown gross in notes. |
| **Non-calendar fiscal year** | Dates drive all labels; nothing assumes January–December. The fiscal-year sentence in note 1 is generated from the dates. Hijri and Gregorian dates are stored as entered, with no conversion [Q26]. |
| **Short or long period** (incorporation, year-end change) | Allowed with warning and confirmation. The label reads "للفترة من … إلى …" instead of "للسنة المنتهية". Comparatives are labelled as not comparable. |
| **Partial TB** | Draft allowed, final blocked [Q20]. The SOFP will not balance; this is shown, not hidden. |
| **Closing-only file** | Current-year SOFP and IS are fully built. Everything movement-based (asset notes, provision roll-forwards, SOCE movements, cash flow) is U or P. Without a prior SOFP the cash flow is entirely `missing`. |
| **Opening + closing** | Net changes are derived. Splits are user input, checked against the net change. |
| **Movement columns** | Suggestions only, pending until confirmed. Disposal proceeds are always user input. |
| **Post-closing TB** (P&L already closed to RE) | Detected (check 6). The IS can't be built from the TB; the user must supply a pre-closing TB or enter the P&L [Q22]. Since 2026-10-14: built from the P&L movements when retained earnings' credit movement (profit) or debit movement (loss) equals their net exactly (info `pl_from_movements`). |
| **Classification corrected after a report was made** | `run_result_hash` differs, so `run_changed: true`, a banner, and recompute on the next action. Earlier versions stay unchanged. |
| **Run expired, failed, or still needs confirmation** | Report creation returns 409. |
| **Duplicate account numbers or rows** | Rows are keyed by Excel row (as now). Duplicates get `duplicate_account_number` info, carried to the line. |
| **Foreign currency** | SAR only for now. A `currency` field exists, but nothing converts. |
| **Rounding** | Whole riyals for display [Q27]. Totals use the sum of rounded parts, and any difference is reported as `rounding_difference`, never pushed into a line silently. |

---

## 6. Phased delivery: final PR order
**Order:** 1 → 2 → 3 → 4 → … → 17, in number order. (On 2026-10-13 PR 3 briefly moved to just before PR 11 while E0 was open; E0 is now decided, so it is back in place.)

**Status:** PRs 1–4 and 4b merged (#11–#15; 4b: five taxonomy sub-classes for Q3, taxonomy version 3; `سحب على المكشوف` gets no sub-class, the side rule covers it). PR 5 merged (#16). PR 6 merged (#17). PR 7 merged (#18). PR 8 merged (#19). PR 9 merged (#20). PR 10 merged (#21). PR 11 merged (#22: notes and narrative; the notes' prior-year movement columns deferred from PR 9; checks 14, 20, 25, 26e, 27; approved template additions: restatement note, first-year policies wording, conditional overdraft / murabaha cash-flow sentences, conditional risk sentences, the early-application reference and the approval date as blocks, end-of-service reversal and PPE revaluation rows). PR 12 (versions and the final gate; decided 2026-10-13: a final is also blocked by anything in `missing` and anything pending — suggestions and mappings; an acknowledgement is tied to the warning's amounts; the report stays editable after a final, superseded derived; `run_changed` is a warning to acknowledge; plus the first year's notes in one year) merged (#23). PR 13 split in three (decided 2026-10-14): 13a layout, list, new report, wizard steps 1–3, Dusk; 13b steps 4–5; 13c review (incl. the manual prior year's cells taken as 0, via an additive `comparative.defaulted`) and versions, old preview removed. Additive API for 13a: `PATCH /financial-reports/{id}`, `GET /classify/jobs`, `comparative.entry`. 13a merged (#24). 13b (steps 4–5; accounts shown as «number — name» everywhere; suggested answers saved only when accepted in a confirmation box; the classifier's file rows written «الصف 10 في الملف»; approved 2026-10-14) merged (#25). 13c (review, versions, `comparative.defaulted`; the old previews removed, their addresses redirected to the review step) merged (#26). PR 14 (xlsx export from a version's payload; draft notes sheet; journal-entries sheet with balance checks; download per version in step 7) in review.
Every PR:
- is green on SQLite and MySQL (CI from PR 2 on);
- updates `openapi.yaml` and `docs/api-reference.md`;
- fakes the AI;
- tests each rule with generic `TrialBalanceFiles` builders, with the sample TB as one extra fixture (Q1).

No auth (Q21, local-only): every new endpoint carries the "No auth yet" note.

| # | PR | Contents | Tests |
|---|---|---|---|
| 1 | **Classifier fixes** (was 0b) | (a) `note_reference` column role, plus a nullable per-row field `note_reference`, excluded from `source_note` and `needs_review` (Q33). (b) Cost-of-sales sub-classes «مخزون أول المدة» (Dr) and «مخزون آخر المدة» (Cr) (Q32a). (c) Liability main class «مخصصات أخرى»: «مخصص ضمان», «مخصصات أخرى» (Q37). (d) A function label in `existing_classification` no longer raises `classification_mismatch` (Q34). (e) Statement-level number scheme with leave-one-out evidence (§1.4). (f) Taxonomy version bump, `familyOf()`, prompt rules and docs. | One generic builder per rule; the fixture's note-number column gives no `source_note`; the periodic stock accounts land in cost of sales or get a high `prefix_mismatch`; warranty is a liability; contract test for `note_reference`. |
| 2 | **CI** (Q31) | GitHub Actions: `composer test` on SQLite, MySQL 8, and SQLite with the Gemini wire format | Current suite green on all three. |
| 3 | **Clean templates** | Cleaned copies of both reference files under `resources/templates/`: §2 errors fixed; same statements, lines, notes and order; 3rd-edition policy wording; only §8 items you approved; placeholders marked; `template_version` = `sa-ifrs-smes-3`. No PHP. | — (reviewed by you) |
| 4 | **Line catalogue + mapper** | `config/financial_statements.php` keyed by `template_version`; `LayoutProfile` (real groups, empty groups); `LineMapper` with the `periodic_inventory_pair` safety net (proposes a re-map, never applies it; Q32b); function precedence (Q34); `LineAggregator`; `Figure`. | Unit tests per rule across layouts C / OC / OMC; copied groups ignored; client labels never trusted over checks. |
| 5 | **Persistence + create/read API** | `companies`, `financial_reports` (no owner columns, Q21); `POST/GET /financial-reports`, `/companies` | Feature + contract tests; 409 on a run that isn't done. |
| 6 | **Current-year SOFP + IS** | Builders, RE roll-in, checks 1–8, 26, 26a, 26d. **Plus a minimal read-only preview page** for browser testing: the current-year SOFP and IS of one classified run, with the checks, server-rendered (Blade), no editing. PR 13 replaces it. | Balanced/unbalanced, post-closing, partial TB; the preview page renders for a done run and returns 404/409 otherwise. |
| 7 | **Intake, overrides, adjustments** | Intake definition, `PATCH inputs`, `PUT account-overrides`, `PUT adjustments` (balanced entries with a reason, Q39); zakat charge rule (Q38) | Required fields per layout; unbalanced entry → 422; zakat error until entered or confirmed zero with a reason. |
| 8 | **Prior year** | `PriorYearResolver`: prior report, prior run, manual; check 21; restated flag (Q9) | Mismatch, first year, restated. |
| 9 | **Movements, asset notes, roll-forwards** | `MovementSuggester`, suggestions endpoint, PPE/intangibles/IP notes (per-category split, Q8), EOSB/zakat/provisions/impairment roll-forwards, loans note gross vs deferred (Q36); checks 15–19, 23, 26b, 26c | OMC suggestions, OC splits, C all-user; reversal is not a payment. |
| 10 | **SOCE + cash flow** | Builders, partners' current choice (Q23); checks 9–13, 24 | Cash flow closing = SOFP cash; RE cleared with no dividend. |
| 11 | **Notes + narrative** | `NotesBuilder` (visibility, renumbering, references), `NarrativeRenderer` (template + user overrides, no AI), going-concern detection (Q14), 3rd-edition early-application disclosure (Q6); the notes' prior-year columns (from PR 9); checks 14, 20, 25, 26e, 27 | Zero-balance notes hidden and renumbered; references in sync; the disclosure appears only before 2027-01-01. |
| 12 | **Versions + finalize** | `financial_report_versions`, draft vs final gating (Q20), superseding a final (Q19), `run_changed` | Final blocked by errors, unacknowledged warnings or missing comparatives; immutability. |
| 13 | **Frontend** | Shared Blade layout, `/financial-reports` list + wizard + review with origin badges, JS modules | `PageConfig`, relative URLs. |
| 14 | **xlsx export** | PhpSpreadsheet, cleaned layout, Western digits (Q27), draft watermark; **adjustments schedule as journal entries** (account, Dr, Cr, reason) (Q39) | Cells, RTL, sheet names, journal sheet balances. |
| 15 | **docx export** | PhpWord `TemplateProcessor` on the cleaned template. *(Built by editing the template's XML directly instead, so the file matches the wizard: reworded paragraphs, zero rows and columns; no PhpWord.)* | No leftover placeholders; hidden blocks removed. |
| 16 | **PDF export** | mPDF + Blade print view + an open Arabic font (Q17). *(Font: XB Riyaz, OFL, shipped with mPDF; mPDF can't read Amiri's or Noto Naskh Arabic's tables.)* | File produced, font embedded. |
| 17 | **Prior issued statements upload (Excel)** | Later (Q7). *(Built: read by PHP, reviewed row by row in step 2, source `statements`; checked like the other sources plus the file's own totals.)* | Same rules as the TB pipeline. |

---

## 7. Questions and decisions
**All decided (2026-10-13).** Where you accepted my recommendation, the **Rec** text below is the decision. The table is the summary.

| Q | Decision |
|---|---|
| 1 | Sample TB becomes a fixture (anonymised), with recorded AI answers |
| 2 | Drafts are always allowed; final is blocked by unclassified or unmapped rows; `needs_review` → acknowledge |
| 3 | Line mapping in config, plus per-report overrides and tags; add true-nature sub-classes only |
| 4 | Function default per nature class, with per-account override and allocation % |
| 5 | Saudi LLC + IFRS for SMEs only; `framework`, `legal_form`, `country`, `template_version` kept as data |
| 6 | **3rd edition (2025) policy wording, as a `template_version` setting**; reference layout unchanged except for §2 fixes; 3rd-edition additions listed in §8 for approval; early-application disclosure before 2027-01-01; comparatives always from the user |
| 7 | Prior-year sources: prior report → prior TB → manual → later Excel statements; no PDF |
| 8 | PPE note: cost per category; accumulated depreciation per category entered by the user when the TB doesn't split it, checked to the total |
| 9 | Prior ≠ opening: reported; the user fixes it or marks the comparatives restated with a note; never auto-adjusted |
| 10 | First-year behaviour as in §5 |
| 11 | Template text + user overrides; no AI in v1 |
| 12 | `companies` table; profile reused across years; per-year data pre-filled from the last report |
| 13 | Auto-hide zero notes, force on/off, renumber, references from one map |
| 14 | Detect the 4 indicators; the user confirms the paragraph; switching it off needs a reason |
| 15 | xlsx + docx first, then PDF |
| 16 | On demand from immutable versions |
| 17 | Server-side; PDF via mPDF with an open font |
| 18 | Step wizard with free navigation + review; Blade + vanilla JS |
| 19 | Immutable versions; a new final supersedes the old one |
| 20 | Draft exports are watermarked; final needs no errors, all warnings acknowledged, no missing comparatives |
| 21 | **Local-only for now**; "No auth yet" on every new endpoint; no owner columns |
| 22 | Pre-closing TB required; post-closing is detected |
| 23 | Partners' current: per-report choice, default liability |
| 24 | Keep the reserve line; transfers are user input; no automatic 10% |
| 25 | Overdraft is a liability; a cash-flow inclusion toggle, default off |
| 26 | Gregorian dates as dates, Hijri as entered text; no auto-conversion |
| 27 | Whole riyals, parentheses for negatives, "-" for zero, **Western digits** |
| 28 | Auditor's report out of scope |
| 29 | Arabic only; `label_ar` keys |
| 30 | RP volumes are user input; balances from the RP accounts |
| 31 | **Add CI** (SQLite + MySQL 8), PR 2 |
| 32 | (c): taxonomy sub-classes + the `periodic_inventory_pair` safety net; never re-map silently; plus the statement-level prefix fix (§1.4) |
| 33 | `note_reference` role in PR 1 |
| 34 | Function precedence: client column → config default → user; the client column is **a default, never trusted as correct**; checks override it and need confirmation |
| 35 | Advances presented as prepayments / other payables; the client's grouping shown in review |
| 36 | Net deferred finance cost against the loan; gross and deferred in the note |
| 37 | «مخصصات أخرى» class, SOFP line and provisions note |
| 38 | Zakat charge is user input; error until entered or confirmed zero with a reason |
| 39 | (b) report-level adjusting entries, plus **export as journal entries** (account, Dr, Cr, reason) |
| 40 | RP candidates proposed; the user confirms; name match is a hint only |
| 41 | Generic: SOFP comparative from the opening columns; the rest missing until supplied; draft only |

**Q1. ✅ Sample trial balance.** The file was added and analysed (§0). Should it become a test fixture?
**Rec:** yes, add an anonymised copy to `tests/Fixtures/`, with the classifier's answers recorded as a fake-AI mapping (like `tests/Fixtures/mappings/*.json`). The live run in §0.1 can seed it. It then becomes the end-to-end fixture for PRs 6–12.

**Q2. ✅ Scope of the generator's input.** Must the run be fully classified with no `needs_review` rows before a report can be created?
**Rec:**
- Allow creating it and drafting.
- Block **final** while there are unclassified or unmapped rows.
- `needs_review` rows → warning to acknowledge, not a block. Many will be legitimate wrong-side balances.

**Q3. ✅ Mapping layer (taxonomy coverage).** The taxonomy doesn't map one-to-one: see §3.1 for the gap and split lines, plus four taxonomy items missing from the reference statements (intangibles, income tax, lease liabilities, finance cost).
**Rec:**
- A separate **line mapping in config** (`config/financial_statements.php`), maintained by developers with accountant sign-off in PRs.
- Plus **per-report account overrides and tags** in the UI.
- Do **not** grow the classifier taxonomy for presentation-only distinctions (deposits vs current accounts, current portion), because that would invalidate the cache via `taxonomy_version`.
- **Do** add sub-classes where the nature truly differs: `استثمارات عقارية`, `استثمارات في شركات زميلة`, `توزيعات أرباح مستحقة`, `إيرادات مؤجلة`, `رد خسائر الانخفاض` (done in PR 4b, taxonomy version 3). `سحب على المكشوف` dropped: the side rule already moves a bank in credit to the overdraft line per year; a sub-class would only duplicate it.
- **Trade-off:** taxonomy additions bump the cache version, and the AI prompt grows.

**Q4. ✅ Expenses by function.** The taxonomy is by nature; the reference IS is by function (cost / selling / G&A).
**Rec:**
- A default function per nature main class in the config (e.g. `دعاية وإعلان` → selling, `أجور ورواتب` → G&A).
- A per-account override in step 3, plus an allocation % per account if needed (e.g. salaries split).
- Allocations are user input and are recorded as origin `user_input`.
- **Alternative:** offer a by-nature IS, which IFRS for SMEs allows. That needs a second IS layout and changes the policy text.

**Q5. ✅ Standards, legal forms, countries.**
**Rec:**
- Build only Saudi LLC + IFRS for SMEs now.
- Keep `framework`, `legal_form`, `country` and `template_version` on company and report, and key the line catalogue and templates by `template_version`. That costs almost nothing now and avoids a migration later.
- Don't build abstractions for other frameworks yet.

**Q6. ✅ Which IFRS for SMEs edition?** The 2015 edition, or the 3rd edition (Feb 2025; effective 2027, early adoption allowed if SOCPA endorses it)? This decides the revenue (§2.2 #21), financial-instrument (#20) and fair-value (#19) wording.
**Decision:**
- Use the **3rd edition (2025)** for the policy wording.
- Keep exactly the reference layout and structure: same statements, lines and notes, in the same order. Fix only the §2 errors.
- Anything the 3rd edition needs that the references lack goes to **§8 for your approval**, never added silently.
- The edition is a setting (`template_version`), not hard-coded.
- When a period starts before **1 January 2027**, the early-application disclosure is included.
- Comparatives always come from the user (Q7).
- E0 decided 2026-10-13: the 3rd edition is used; nothing waits for SOCPA confirmation.

**Q7. ✅ Prior-year sources and order.**
**Rec:**
1. A previous report in this system: exact comparatives, free.
2. A prior-year TB through the existing classifier: a P&L comparative needs this or option 3.
3. Manual entry per line.
4. Later: upload of prior issued statements as Excel.
5. **PDF: not planned.** Parsing it needs OCR/AI to locate figures, which conflicts with the "PHP reads every number" rule unless a human confirms every figure.

**Q8. ✅ Accumulated depreciation per category.** The taxonomy has one `مجمع إهلاك الأصول الثابتة`.
**Rec:**
- Default the PPE note to a cost-per-category / acc.-dep.-total layout when the TB doesn't split it.
- Let the user enter the per-category split, checked to sum to the TB.
- **Alternative:** add per-category acc. dep. sub-classes. That bumps the taxonomy version, and many TBs don't split it anyway.

**Q9. ✅ Prior closing ≠ current opening.**
**Rec:**
- Report it per line (error for the SOFP comparative, warning per line).
- The user either fixes the prior figures or marks the comparatives "restated" (معدلة), with a mandatory explanation that becomes a note.
- The system never adjusts.

**Q10. ✅ First-year company:** confirm the behaviour in §5.
**Rec:** as described there.

**Q11. ✅ Narrative text and AI.**
**Rec:**
- **Fixed template text with placeholders and conditional blocks** (the docx comments already define the conditions).
- Plus **user-editable overrides** per block, stored per report and flagged as edited in the review.
- **No AI in v1.**
- If wanted later: AI may only **draft free text the user types anyway** (activity description, significant events). Its output is plain text with any digits rejected, and the user must accept it. It never touches amounts, dates or tables.

**Q12. ✅ Company entity.** There is none today.
**Rec:**
- Add a `companies` table now, and reuse the profile across years: identity, policy choices, useful lives.
- Per-year data (partners table, RP details, events, approval) lives on the report and is **pre-filled from the previous year's report**.
- This is also the natural owner once auth exists.

**Q13. ✅ Note visibility and numbering.**
**Rec:**
- Auto-hide a note when every figure is zero in both years and it has no forced text.
- Policies tied to a hidden line are hidden too.
- User override: force on / off.
- Renumber sequentially at every compute.
- Statement note references and the "(1) to (N)" footer are rendered from the same numbering map, never typed.
- Basis and policy notes are always shown.

**Q14. ✅ Going concern.**
**Rec:**
- Auto-detect the 4 indicators: net loss; accumulated losses > 50% of capital; current liabilities > current assets; total liabilities > total assets.
- When any is hit, propose the paragraph with the amounts derived. The user must confirm or reword the mitigation sentence.
- Turning it off when an indicator is hit needs a reason, recorded in the version.
- Also: accumulated losses ≥ 50% of capital has Companies Law consequences for an LLC. Show it as a warning, not legal advice.

**Q15. ✅ Export formats first.**
**Rec:** xlsx + docx (they mirror the references the accountants already use), then PDF.

**Q16. ✅ Stored vs on-demand exports.**
**Rec:** on demand from an immutable version payload. Deterministic, no storage. Store files only if you need a "what exactly was sent to the client" record. Final versions already give that.

**Q17. ✅ Server vs client rendering.**
**Rec:**
- Server-side for all formats (§4.7), because client-side DOCX/XLSX libraries handle Arabic RTL tables worse.
- PDF via mPDF with an open font.
- Is installing Chromium on the server acceptable? If yes, headless Chrome gives the best PDF fidelity.

**Q18. ✅ Frontend form.**
**Rec:**
- A step wizard (§4.6) with free navigation between steps (not a forced linear flow), plus a review screen before export.
- Stays Blade + vanilla JS, with a shared layout and JS split into files.
- A SPA framework is not justified yet.

**Q19. ✅ Persistence and versioning.**
**Rec:**
- Save inputs continuously.
- Each "generate" creates an immutable version (draft or final).
- After classification corrections or input edits, regenerate to a new version. Older versions are kept.
- Can a final version be superseded? Recommend yes, by a new final, with the earlier one marked superseded.

**Q20. ✅ Draft vs final.**
**Rec:**
- Drafts are always exportable and watermarked "مسودة", with a cover page listing missing figures and failing checks.
- Final requires zero `error` checks and every `warning` acknowledged.

**Q21. ✅ Security.** The feature stores company identity data (CR, unified number, partners and their shares).
**Rec:** don't ship this outside a local or trusted network without auth. Either:
- make minimal auth (Sanctum tokens + an owner on companies, reports and runs) **a PR before this feature**, or
- accept it is local-only for now and keep the "No auth yet" warning on every new endpoint.

**Decision: local-only for now.** No owner columns. Every new endpoint and the API reference carry the "No auth yet" warning, as the existing cache endpoints do.

**Q22. ✅ Pre- vs post-closing TBs.** Must the TB be pre-closing (P&L accounts still open)?
**Rec:** yes, require pre-closing. Detect post-closing (check 6) and ask for the pre-closing TB.
**Changed 2026-10-14:** when the P&L closing balances are empty or zero but their movement columns aren't, the P&L is derived from the net movement **only if** the closing entry shows on retained earnings (all its accounts, or the current-year profit account alone): a derived net profit equals their credit movement exactly, a net loss their debit movement (refined the same day: the net change fails whenever there are distributions), or, when the entry was posted after the movement columns, the closing gap on retained earnings (closing − (opening + movements)) is a credit equal to the profit or a debit equal to the loss; then an info check (`pl_from_movements`) says the figures come from the movements. Otherwise check 6 stays an error asking for a pre-closing TB.

**Q23. ✅ Partners' current account (جاري الشريك).** **Decided:** a per-report choice, defaulting to a liability ("مستحق لأطراف ذات علاقة" / a separate line). If shown in equity, the SOCE gets a column for it.

**Q24. ✅ Statutory reserve.** The 2022 Companies Law removed the mandatory LLC reserve.
**Rec:** keep the line and SOCE column when there is a balance or a transfer; transfers are user input per the articles of association; no automatic 10%.

**Q25. ✅ Overdrafts and cash definition.** Overdrafts are banks with a credit balance.
**Rec:** present them as a liability on the SOFP. For the cash flow, follow the reference policy (overdrafts that form part of cash management are included in cash equivalents) as a per-report toggle, default off.

**Q26. ✅ Dates.**
**Rec:**
- Store Gregorian dates as dates.
- Store Hijri dates (CR date, approval) as entered text. Don't auto-convert, because conversions are off by ±1 day (see §2.2 #2).
- Optional: a convert helper that pre-fills, but the user confirms.

**Q27. ✅ Presentation.**
**Rec:**
- Whole riyals.
- Negatives in parentheses.
- Zero as "-".
- Arabic labels with **Western digits** (decided).

**Q28. ✅ Auditor's report and cover.** The index lists the independent auditor's report.
**Rec:** out of scope. Generate the cover and index without that line, or with a placeholder page the auditor replaces.

**Q29. ✅ Language.**
**Rec:** Arabic only in v1. Keep `label_ar` keys so `label_en` can be added later.

**Q30. ✅ Related-party transaction volumes.** These are never in a TB.
**Rec:** user input per party; balances auto from the RP accounts, with an RP-to-account link set in step 3.

**Q31. ✅ MySQL in CI.** No CI exists today, and this feature adds many migrations and JSON columns.
**Decision: yes.** A GitHub Actions job with a SQLite + MySQL 8 matrix, as PR 2.

### Raised by the sample file (each question is generic; the sample only revealed it)

**Q32. ✅ Periodic inventory.** Some clients run a periodic system, with opening and closing stock accounts that belong in cost of sales. When the client's own labels call them inventory, the classifier puts them on the balance sheet. That silently misstates profit by the change in inventory; in the fixture, by 5.55 M with no review flag.

Options:
- (a) Add cost-of-sales sub-classes «مخزون أول المدة» (debit) and «مخزون آخر المدة» (credit) to the taxonomy, so the classifier can label them. Bumps the cache taxonomy version.
- (b) Leave the classifier alone and have the generator detect the pair (`periodic_inventory_pair`) and propose the re-mapping for the user to confirm.
- (c) Both.

**Rec:** (c).
- (a) fixes the classification at the source, and is an internal change (taxonomy and prompt), so it is API-additive.
- (b) is the safety net for runs and caches made before (a), and for files where the AI still follows the client's label.
- Never re-map silently.

**Q33. ✅ Note-reference column.** A column of note numbers (e.g. «إيضاح») is read as `notes` today, so every numbered row gets `needs_review`. In the fixture that is 144 extra rows.

**Rec:**
- Add a column role `note_reference` to structure detection (internal change). Expose it per row as a new nullable field `note_reference` (additive).
- Exclude it from `source_note` and `needs_review`.
- Use it in the generator to pre-fill note numbering and as a prior-year continuity hint.
- **Trade-off:** a classifier change outside this feature's PRs. **Decision:** it ships in PR 1.

**Q34. ✅ Function split source.** A client's own classification column may already say cost / G&A / selling / depreciation / finance per account.

**Rec:**
- Default precedence for the function split: **(1) the file's `existing_classification` when it names a function**, (2) the config default per nature main class, (3) user override.
- Also treat a function label in that column as **not** contradicting a nature main class. In the fixture, most of the 27 `classification_mismatch` flags are this. It's a classifier change.
- **Decision, with a condition:** the client's column is **a default, never trusted as correct**. In the fixture the same column labelled the periodic stock accounts as inventory.
- Whenever a check disagrees with it (`periodic_inventory_pair`, the statement-level `prefix_mismatch`, wrong-side balances, `statement_mismatch`), the check wins as a pending proposal that the user must confirm, and the row shows both.

**Q35. ✅ Customer and supplier advances presentation.** Clients often group supplier advances (Dr) with trade receivables and customer advances (Cr) with trade payables. The classifier moves them to prepayments and accruals.

**Rec:**
- Present them as prepayments/other receivables and contract liabilities/other payables (IFRS for SMEs: advances are not trade receivables or payables).
- Show the client's original grouping in the review screen so the accountant sees the difference.
- Make it a per-report choice only if you want to allow the client's presentation.

**Q36. ✅ Deferred murabaha / finance cost carried as a debit balance.**

**Rec:**
- Net it against the related loan on the SOFP (amortised cost), via an account tag that links the two.
- Show gross and deferred amounts in the loans note.
- The current/non-current split of the net amount is user input.
- **Alternative:** keep it as a separate asset (the client's presentation). That overstates assets and liabilities.

**Q37. ✅ Warranty and other provisions.** There is no taxonomy sub-class, so they land in the nearest class (an inventory contra account in the fixture).

**Rec:**
- Add a liability main class «مخصصات أخرى», with sub-classes such as «مخصص ضمان» and «مخصصات أخرى» (internal taxonomy change).
- Add a SOFP line and a provisions note.
- `provision_without_pl_counterpart` covers charge-vs-expense gaps.

**Q38. ✅ Zakat when the TB has no charge.** The system can't derive the zakat charge: the base needs adjustments outside the TB.

**Rec:**
- The current-year charge is **user input** (from the zakat computation), with origin `user_input`.
- When profit > 0 and no charge exists → error until entered or explicitly confirmed as zero with a reason.
- Computing zakat in-app is out of scope.

**Q39. ✅ Figures the user adds that the TB doesn't contain** (zakat charge, warranty true-up, a reclassification). How do they reach the statements?
- (a) The user fixes the books and uploads a new TB.
- (b) The generator accepts **report-level adjusting entries**: balanced Dr/Cr lines by account or line key, each with a reason. They are shown in an "adjustments" schedule and carried with origin `user_adjustment`.

**Rec:**
- (b) with strict rules: entries must balance, every one is listed in the review and the export's working papers, and none is ever created automatically.
- This keeps "the AI never produces an amount" and "never guess" intact, while making the statements possible without re-uploading.
- **Trade-off:** the statements then differ from the client's ledger. The adjustments schedule is the audit trail.
- **Decision:** (b). The schedule **also exports as journal entries** (account number and name, Dr, Cr, reason, entry number), so the accountant can book them and next year's opening balances match. It is a sheet in the xlsx export (PR 14), and a check confirms that every exported entry balances.

**Q40. ✅ Related-party candidates.** Related parties can sit in RP classes, in partners' current accounts, or in other classes under a partner's name (receivables, payables, salaries).

**Rec:**
- The generator proposes RP candidates from:
  - accounts in RP classes
  - partners' current accounts
  - accounts whose normalised name matches a partner entered in the capital note (`same_party_multiple_accounts`)
- The user confirms the list, relationship and transaction nature.
- Name matching is a hint only, never automatic.

**Q41. ✅ Resolved (generic):** see §5 "No prior source, not first year". The SOFP comparative comes from the TB opening columns when present. The P&L, SOCE and cash-flow comparatives are `missing` until a prior-year source is supplied (Q7). A report with missing comparatives exports as a draft only.

---

## 8. Items for your approval (Q6)
**Rule:** the templates keep the reference layout. Each item below is needed either by the 3rd edition or by data the references can't hold. **Nothing here is added until you approve it.**

**Decided 2026-10-13:** E0 settled (3rd edition; nothing waits for SOCPA). E1–E9 approved, E8 included. The priority is the shape: the output looks like the reference files, with only the §2 errors fixed and L1–L9 hidden when zero in both years. For the *verify* items (E2, E4, E5, E7, E9) PR 3 writes the best wording from the published 3rd-edition text; any wording I'm not certain of is marked `[review]` in the template and listed in the PR summary. They don't block the PR.

### 8.1 Driven by the 3rd edition

| # | Item | Where | Note |
|---|---|---|---|
| E0 | ~~Confirm SOCPA endorsement~~ **Decided:** the 3rd edition is used. | — | `template_version` = `sa-ifrs-smes-3`. |
| E1 | **Early-application disclosure**: the company has applied the third edition of IFRS for SMEs (issued 2025, effective 1 January 2027) early, plus the nature of the change | Basis of preparation | Shown only when `period_start` < 2027-01-01. |
| E2 | **First-application / change-in-policy note**: effect on comparatives, or a statement that there is none, and the transition reliefs used (*verify* which reliefs apply, especially for revenue) | New note after basis of preparation | Section 10 + transition provisions. |
| E3 | **Revenue policy reworded to the five-step model** (revised Section 23), keeping the reference's per-industry conditional blocks | Policies | Wording only; same blocks. |
| E4 | **Revenue disaggregation and contract balances** disclosure (*verify* the extent for SMEs). Possibly relabel «إيرادات غير مفوترة» / «إيرادات مؤجلة» as contract assets / contract liabilities. | New revenue note; label change | — |
| E5 | **Fair-value measurement** wording aligned to new Section 2A. The reference paragraph is close; light edit (*verify*). | Policies | — |
| E6 | **Control / consolidation** wording aligned to revised Section 9. Stays conditional, hidden for a standalone LLC. | Policies | — |
| E7 | **Supplier finance arrangements** disclosure, if the 3rd edition requires it (*verify*) | Payables note | Shown only when the user flags such arrangements. |
| E8 | **Goodwill wording.** IFRS for SMEs amortises goodwill (10 years at most when the life can't be estimated reliably). The reference says only "tested for impairment". | Policies | A wording error under both editions; listed here because it wasn't in §2. |
| E9 | **Impairment of financial assets.** The 3rd edition keeps the incurred-loss model (*verify*), so the ECL sentence in the estimates section is removed (§2.2 #20). | Estimates | — |

### 8.2 Needed by the data, missing from the references

**Already approved:**
- provisions line and note (Q37)
- loans net of deferred finance cost, with gross/deferred in a loans note (Q36)
- partners' current presentation choice (Q23)
- periodic-inventory build-up in the cost-of-sales note (Q32)
- adjustments schedule and journal export (Q39)

**Pending approval:**

| # | Item | Statement / note |
|---|---|---|
| L1 | Intangible assets line (the docx already has the note table) | SOFP |
| L2 | Lease liabilities, current and non-current | SOFP |
| L3 | Income tax provision line; income tax expense line | SOFP, IS |
| L4 | Finance cost line (the cash flow already refers to it) | IS |
| L5 | OCI reserve line(s) and SOCE column | SOFP, SOCE |
| L6 | Transfer-to-reserve row; partners' current movements row (if presented in equity) | SOCE |
| L7 | Cash-flow lines: finance cost paid; murabaha deposits movement; capital increase; partners' current movements; unbilled/deferred revenue working capital; non-cash distributions disclosure | CF |
| L8 | Capital-work-in-progress column | PPE note |
| L9 | Notes for revenue, selling and marketing, other income, finance cost | Notes |

Until an item is approved, accounts needing it appear as **unmapped** (final blocked, draft shows them on a visible line). Reference lines that don't apply (e.g. FVOCI investments) stay in the layout and auto-hide at zero (Q13).
