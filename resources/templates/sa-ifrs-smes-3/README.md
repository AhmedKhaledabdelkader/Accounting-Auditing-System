# Template `sa-ifrs-smes-3`

Saudi LLC, IFRS for SMEs **3rd edition (2025)** wording. Cleaned copies of the two reference files (plan §2, §8): same statements, lines, notes and order, with the §2 errors fixed and the approved §8 items added. Every line, column and note is hidden when it is zero in both years. The exporters apply that rule (PRs 11, 14 and 15); the templates only carry the items.

| File | Made from | Filled by |
|---|---|---|
| `statements.xlsx` | `القوائم المالية لشركة SMEs .xlsx` | PR 14 (PhpSpreadsheet) |
| `notes.docx` | `الإيضاحات المتممة - نسخة منقحة.docx` | PR 15 (`NotesDocx`, the document's XML edited directly) |

Neither file holds client data, names or document properties. Comments in both files are `[review]` marks only (see the end of this file); the exporters drop them, all of them, including any whose anchor was in a dropped block.

## `statements.xlsx`

- **`data` sheet**: one input per row. Column C (hidden) holds the key the exporter fills by. Dates are real dates; the texts used in titles (`period_end_text`, …) are formulas over them, with Arabic month names and Western digits (Q27).
- **Line keys**: each statement row carries its key in a hidden column: `J` on the SOFP, IS and cash flow; `A` on the SOCE and the PPE note. Column keys sit in hidden row 5 on the SOCE and the PPE note. The keys match the line catalogue (PR 4).
- **Amount cells are empty**. The exporter writes every line amount; formulas exist only for totals, cross-statement links and checks. Amounts are signed as shown: income +, expenses −. On the SOCE, unpaid capital (column G) is entered positive and subtracted.
- **Checks** sit outside the print area and must be 0: the SOFP balance (`sofp.check_balance`), SOCE closing vs SOFP equity (column S), and cash-flow closing cash vs SOFP cash (`cf.check_difference`), less the overdraft when `overdraft_in_cash` = نعم (Q25).
- **Footers** read `last_note_number` from `data`. The PPE note title reads `ppe_note_number`.
- **Other assets** (2026-10-19, the accountant's): the SOFP row `sofp.other_assets` right under PPE, and the cash-flow rows `cf.amortisation_other_assets` (operating, after the intangibles' amortisation) and `cf.other_assets_payments` (investing, after the intangibles' disposals). Added with PhpSpreadsheet; the other sheets' references to the moved rows were moved with them.
- **Other assets' note** (2026-10-25): `notes.docx` has the block `note_other_assets` before the intangibles' note, a copy of the cash note's (title «أصول أخرى», rows `other_assets_label`/`_cy`/`_py`, totals `other_assets_total_cy`/`_py`): the cost and its accumulated amortisation by class, the net = the line.

## `notes.docx`

- **Placeholders** are `${name}`, each inside one text run. Values: company and period (`company_name`, `legal_form`, `city`, `period_end_text`, `cy` / `py` = «2025م», `cy_year`, `cy_start_date`, …), note numbers used in text (`note_no_*`), and the capital's share count in words and its noun (`capital_shares_words`, `capital_shares_noun`: «أربعة آلاف ومائتي (4,200) حصة», «تسع (9) حصص»), amounts (`<note>_<row>_cy|py`; PPE `ppe_cy|py_<row>_<column>`).
- **Item rows**: tables whose rows come from the data have one item row, repeated per item (keyed by its label placeholder): `inventory_label`, `other_receivables_label`, `cash_label`, `partner_name`, `trade_payables_label`, `other_payables_label`, `rp_from_name`, `rp_to_name`, `useful_life_label`, `zakat_base_label`, `revenue_label`, `cost_of_revenue_label`, `selling_label`, `general_admin_label`, `other_income_label`, `finance_cost_label`.
- **Blocks** are `${name}` … `${/name}` paragraphs, each marker a paragraph of its own: the markers always go; what is between them goes with a dropped block. No placeholder shares a block's name. Blocks and their conditions are listed below.
- **Note numbers** are Word auto-numbering, so a deleted note renumbers itself. The basis's and the policies' headings are the same list's level 1, «%1-%2» (2026-10-28): «5-1», renumbered with their note and with any dropped heading. PR 11 computes the same numbers for the statements' «إيضاح» column and the `note_no_*` placeholders.
- **Text fragments** (built by `NotesBuilder`, PR 11, from the wording below; nothing else is written in code):
  - `branches_clause`: «، وللشركة الفروع التالية: …», or empty.
  - `going_concern_indicators`: one or more of these clauses, joined with «، »:
    1. «خلال السنة، تكبدت الشركة صافي خسارة بمبلغ (…) ريال سعودي»
    2. «وبلغت الخسائر المتراكمة كما في ${period_end_text} مبلغ (…) ريال سعودي بما تجاوز نصف رأس المال»
    3. «كما تجاوزت الالتزامات المتداولة الأصول المتداولة بمبلغ (…) ريال سعودي»
    4. «وتجاوز إجمالي الالتزامات إجمالي الأصول بمبلغ (…) ريال سعودي»

    The paragraph's first clause drops its connector («بلغت», «تجاوزت», «تجاوز»). The sentence then ends with «.».
  - `going_concern_mitigation`: by default «ومع ذلك، فقد قرر الشركاء اتخاذ التدابير النظامية والمالية المناسبة ومواصلة أعمال الشركة،».
- **Filling (PR 15)**: `NotesDocx` walks `document.xml` as `NotesTemplate` reads it and edits the XML (DOM): placeholders are replaced in the run that holds them, paragraphs and tables kept or removed as the version shows them. PhpWord's `TemplateProcessor`, first planned, is not used: it can't keep a reworded paragraph, hide zero rows and columns or drop a placeholder's row, and its block regex fails silently on this 1.4 MB `document.xml` unless PCRE limits are raised.
- **Sections**: the PPE tables sit in a landscape section. When `note_ppe` is dropped, that section is empty, and PR 15 removes it.

### Blocks

"≠ 0" means ≠ 0 in either year (Q13); "or forced" means the user may force the note or policy on or off (`PATCH …/notes`). The conditions are applied by `NotesBuilder` ([docs/14](../../../docs/14-financial-statements.md#notes-and-narrative-pr-11)).

| Block | Shown when |
|---|---|
| `early_application` | period_start < 2027-01-01 (E1, Q6) |
| `early_application_reference` | inside `early_application`: the first-application note is shown (its sentence refers to it by number) |
| `note_restatement` | the comparatives are marked restated (Q9): `${restatement_text}` is the restatement note |
| `note_first_application` | the 3rd edition is applied for the first time in this period (E2) |
| `first_application_no_effect` | first application had no material effect on the amounts presented |
| `first_application_effect` | first application changed amounts: describe the effect and any restatement |
| `first_application_reliefs` | transition reliefs were used |
| `note_going_concern` | a going-concern indicator is detected or confirmed (Q14) |
| `policies_consistent` | the report has comparatives (the policies "applied consistently to all periods presented") |
| `policies_first_period` | a first year: the company's first financial statements (§5) |
| `policy_ppe` | PPE line ≠ 0 or forced |
| `policy_intangibles` | intangible assets line ≠ 0 or forced |
| `policy_investment_property` | investment property line ≠ 0 or forced |
| `policy_murabaha_deposits` | murabaha deposits line ≠ 0 or forced |
| `policy_inventory` | inventory line ≠ 0 or forced |
| `inventory_general` | trading or service company (default) |
| `inventory_manufacturing` | manufacturing company |
| `policy_associates` | associates line ≠ 0 or forced |
| `policy_subsidiaries` | the company has subsidiaries (hidden for a standalone company; E6) |
| `policy_foreign_currency` | foreign-currency balances or transactions, or forced |
| `cf_overdraft_in_cash` | overdrafts count in cash and cash equivalents (`cash_flow.overdraft_in_cash`, Q25) |
| `cf_murabaha_finance_cost` | murabaha loans ≠ 0: finance cost paid on them is in financing activities |
| `policy_cwip` | capital work in progress ≠ 0 or forced |
| `policy_leases_lessee` | the company is a lessee |
| `policy_leases_lessor` | the company is a lessor |
| `eosb_actuarial` | end-of-service benefits measured actuarially (projected unit credit) |
| `eosb_labour_law` | end-of-service benefits described per the Saudi Labour Law (default) |
| `revenue_goods` | sale of goods |
| `revenue_services` | short-term services, property management and marketing |
| `revenue_contracting` | construction contracts |
| `revenue_leasing` | rental income (operating leases; Section 20, unchanged) |
| `revenue_hospitality` | hospitality and hotel services |
| `revenue_murabaha` | income from murabaha deposits |
| `revenue_property_sales` | sale of property units |
| `revenue_investment_income` | interest, royalty or dividend income |
| `revenue_education` | educational services |
| `note_ppe` | PPE line ≠ 0 or forced |
| `note_intangibles` | intangible assets line ≠ 0 or forced |
| `note_investment_property` | investment property line ≠ 0 or forced |
| `note_inventory` | inventory line ≠ 0 |
| `note_trade_receivables` | trade receivables line ≠ 0 |
| `note_other_receivables` | prepayments and other receivables line ≠ 0 |
| `note_other_assets` | other assets line ≠ 0, or last year's file printed a note for it |
| `note_cash` | cash line ≠ 0 |
| `note_loans` | murabaha loans ≠ 0 (Q36) |
| `note_employee_benefits` | employee benefits line ≠ 0 |
| `note_trade_payables` | trade payables line ≠ 0 |
| `supplier_finance` | the user flags supplier finance arrangements (E7) |
| `note_other_payables` | accruals and other payables line ≠ 0 |
| `note_related_parties` | related-party balances or transactions exist |
| `related_parties_due_from` | due from related parties ≠ 0 |
| `related_parties_due_to` | due to related parties ≠ 0 |
| `note_zakat` | zakat provision or charge ≠ 0, or last year's file printed a note for one of its lines (R11) |
| `zakat_zero` | inside `note_zakat`, after the rate sentence: no zakat for the year, with its reason (`zakat.zero_reason`): «لم يُكوَّن مخصص للزكاة عن السنة المنتهية في ${period_end_text}، ويرجع ذلك إلى ${zakat_zero_reason}.» (the reason ends the sentence; a trailing «.» dropped) (2026-10-26) |
| `note_other_provisions` | other provisions line ≠ 0 (Q37) |
| `note_revenue` | revenue ≠ 0 (L9, E4) |
| `contract_balances` | contract assets or contract liabilities ≠ 0 (E4) |
| `note_cost_of_revenue` | cost of revenue ≠ 0 |
| `cost_periodic_inventory` | periodic inventory pair found (Q32) |
| `note_selling` | selling and marketing ≠ 0 (L9) |
| `note_general_admin` | general and administrative ≠ 0 |
| `note_other_income` | other income ≠ 0 (L9) |
| `note_finance_cost` | finance cost ≠ 0 (L9) |
| `risk_no_interest_bearing` | no murabaha loans and no lease liabilities |
| `risk_no_foreign_currency` | the foreign-currency policy is not shown |
| `subsequent_events_none` | no subsequent events (default) |
| `subsequent_events` | the user enters subsequent events |
| `note_significant_events` | the user enters significant events |
| `approval_hijri` | the Hijri approval date is entered: «بتاريخ (هجري) الموافق (ميلادي)» |
| `approval_gregorian` | no Hijri approval date: «بتاريخ (ميلادي)» |

Notes with no block are always shown: incorporation, basis of preparation, policies without a condition, estimates, capital, financial risk management, subsequent events, approval.

## What changed from the references

### `statements.xlsx` (plan §2.1)

| § | Fix |
|---|---|
| 1–3 | `data` holds real start and end dates for both years; titles and headers are built from them. |
| 4–7 | Every `#REF!` and the other company's hard-coded figures removed. |
| 8–9 | Trade payables appear once, and the current-liabilities total covers every line. |
| 10 | Both balance checks are assets − (equity + liabilities). |
| 11 | The footer reads the last note number from `data`; the «إيضاح» column is filled by the exporter. |
| 12–13 | Lines added: intangible assets (L1), lease liabilities (L2), income tax provision (L3), other reserves (L5), partners' current accounts in equity or liabilities (Q23), other provisions (Q37). |
| 14 | Typos fixed («إجمالي الأصول», «كما في», «لأطراف», …). |
| 15 | FVOCI and amortised-cost lines kept (reference layout); hidden at zero. |
| 16–18, 20, 22 | One OCI block, without the intangible revaluation line; OCI total and total comprehensive income have formulas. |
| 19 | Finance cost line (L4); income tax line (L3). |
| 21 | Sign convention documented above. |
| 23, 25, 26 | SOCE net profit links to IS net profit; stray labels removed; unpaid capital is an input column. |
| 24 | SOCE: other reserves and partners' current columns (L5, Q23); transfer-to-reserve and partners' current rows (L6). |
| 27, 31 | Duplicate cash-flow lines and the biological-assets line removed. |
| 28–30, 32 | No reference to the note column; comparative opening cash is an input; L7 lines added; working-capital lines are exporter inputs, with no formulas. |
| 33–36 | PPE: comparative NBV = opening cost − opening depreciation; «مجمع الإهلاك» heading; CWIP column (L8) with a transfers row; note number from `data`. PR 14 (approved 2026-10-14): a «فائض إعادة التقويم» row after transfers (`ppe.revaluation`, as in notes.docx since PR 11), in the closing cost's formula; the print area takes the total column and the comparative NBV row. |
| 37 | The auditor's report appears on the cover and index only when `has_auditor_report` = نعم; city and notes pages come from `data`. |
| — | Phase 9 (2026-10-25): the sheets named exactly as the reference («المركز المالى», «ايضاح الاصول والممتلكات»; «قائمة التغيرات» without the reference's trailing space); the exporter's own sheets (the draft's notes, the journal entries) come after the eight. |
| — | Phase 9: every keyed row's amount, note and label cells in Sakkal Majalla 14 (the reference's empty amount cells were Arial 10, so the amounts written into them showed in Arial). |
| — | Phase 9: the PPE sheet's amounts all centred vertically (the reference has C–E at the top, F–I centred); its category columns are the note's (last year's categories as printed, any number: the exporter names them in row 7 and inserts columns before the last when there are more than seven). |

Depreciation and amortisation (the accountant's rule R5, 2026-10-23): the income statement's «مصروف الإهلاك والإطفاء» row is «مصروف الإهلاك» (`is.depreciation`), with «مصروف الإطفاء» (`is.amortisation`) right under it; inserted with PhpSpreadsheet, and the other sheets' references to the moved rows moved with them (the cash flow's profit before zakat, the SOCE's net profit and OCI). The cash flow and the notes already had them apart.

Retained earnings' movement besides the result and the transfers (the user, 2026-10-24: not a dividend by guess): the changes in equity's «تسويات سنوات سابقة» (`prior_year_adjustments`) and «حركات أخرى في الأرباح المبقاة» (`other_retained_movements`) rows right after «توزيعات أرباح», in both years' blocks, and the cash flow's non-cash «تسويات سنوات سابقة على الأرباح المبقاة» (`cf.prior_year_adjustments`) and «حركات أخرى غير نقدية في الأرباح المبقاة» (`cf.other_retained_movements`) after the associates' share, inside the operating total; inserted with PhpSpreadsheet (the totals' ranges grew with them). An unnamed movement is written on `other_retained_movements` with its own label («حركة غير مفسرة في الأرباح المبقاة»).

Cash-flow lines added because an approved line needs them to reconcile (not in L7; approved 2026-10-13): intangibles amortisation and purchases (L1), lease payments (L2), income tax paid (L3), other provisions movement (Q37). The «تحويلات» row that comes with the CWIP column and the PPE note heading were approved at the same time.

Cash-flow rows added in PR 10 so that every balance-sheet line has one (approved 2026-10-13), each hidden at zero: due from related parties (working capital); investment property purchases and disposal proceeds, intangible disposal proceeds, equity-investment purchases, amortised-cost investments (investing); non-current due to related parties and the bank overdraft when it isn't part of cash (financing). The SOCE gains the L6 row «صافي الحركة في الحسابات الجارية للشركاء» in both years (`soce.*.partners_current_movements`), for the partners' drawings and contributions when their accounts are in equity.

### `notes.docx` (plan §2.2)

| § | Fix |
|---|---|
| 1–3 | Dates, fiscal year, city, CR details, approval date (Hijri and Gregorian) and the headers are placeholders. |
| 4–6 | Alternative wordings are blocks: inventory (general / manufacturing), end-of-service (actuarial / Labour Law), revenue per industry. |
| 7–8 | Payables tables have one item row with a label placeholder, so no duplicates and no unlabelled rows. |
| 9 | The cash note's rows come from the data (cash on hand, current accounts, deposits of 3 months or less); deposits over 3 months stay on their own line. |
| 10 | Intangibles: «مجمع الإطفاء» / «إطفاء العام»; column for trademarks and other rights. |
| 11 | Related-party captions use `${note_no_related_parties}`; a comparative volume column is added. |
| 12 | Zakat movement: prior-year adjustments, paid, and reversal are separate rows. The end-of-service movement has its reversal row too (PR 11, approved 2026-10-13; reversals are not payments, 26c). |
| 13 | Zakat rate sentence rewritten (`[review]`). |
| 14 | Proposed dividends: the note is removed (the accountant's rule R4, 2026-10-23). |
| 15 | The other company's MoU text replaced by `${significant_events_text}` in a block. |
| 16–17 | Bracket inversions and the stray «ج» fixed. PR 15: the two «۱۲» in the current/non-current policy in Western digits (Q27). |
| 18–21 | Subsidiaries policy is a hidden-by-default block (E6); fair value (E5), ECL (E9) and revenue (E3) reworded. |
| 22 | Policies without a line are blocks tied to the new lines (intangibles L1, CWIP L8) or to user choices (lessor, subsidiaries). |
| 23 | Notes for revenue, selling and marketing, other income and finance cost (L9). |
| 24 | The section is headed «التقديرات والافتراضات المحاسبية الهامة», matching its content. |
| 25 | Note numbers stay Word auto-numbering (see above). |
| — | The useful-life table (policies, PPE) has one item row, `useful_life_label` / `useful_life_years`, repeated per category: last year's PPE categories as printed, each with its own life (100 / its rate), or the template's five rows without a prior-year file (Step A, 2026-10-24). |
| — | The PPE note had no heading paragraph, so it had no note number; one is added. |
| — | Phase 9: the two PPE tables have one category column, `${ppe_category}` over `${ppe_cy_<row>_c}` / `${ppe_py_<row>_c}`, repeated per category by the exporter (NarrativeRenderer::repeats; `narrative.columns`), instead of the reference's fixed six (seven with CWIP). |
| — | Phase 9: the landscape section's tables (PPE, intangibles, investment property) were wider than the page's text area (14835 + an indent of 1298 against 14230): fitted to it, no indent, their grid scaled. |
| — | Phase 9: the zakat note's base table («الوعاء الزكوي:»), before the provision's movement, in its shape: one item row `${zakat_base_label}` / `${zakat_base_cy}` / `${zakat_base_py}`, repeated per line of last year's base (ZakatBase); none without one. |
| — | Phase 9: the page break before the PPE comparative table moved inside its block (`ppe_comparative`), so no blank landscape page is left without it. |
| — | 2026-10-28 (the accountant's review of a draft): the comparative-year PPE movement table and its block `ppe_comparative` removed; the note has one movement table, the current year's (IFRS for SMEs 17.31: the prior-period reconciliation need not be presented). |
| — | PR 11 (approved 2026-10-13): a restatement note after the basis of preparation (Q9); the policies' introduction for a first year (§5); the cash-flow policy's overdraft sentence conditional (Q25) and a sentence on murabaha finance cost paid in financing; the risk note's "no interest-bearing" and "no foreign-currency" sentences conditional; the early-application sentence that refers to the first-application note in its own block; the approval sentence with or without the Hijri date; a revaluation row in both PPE tables (the revaluation surplus is a row of the note since PR 10). Every added row is hidden at zero in both years. |

§8 items: E1 (early-application paragraph), E2 (first-application note), E3 (revenue five-step), E4 (contract labels on the SOFP, revenue note with contract balances), E5, E6, E7 (supplier finance in the payables note), E8 (goodwill amortised, at most 10 years when the life can't be estimated reliably; also in the associates policy), E9. Approved data items: loans note with gross and deferred finance cost (Q36), other provisions note (Q37), periodic-inventory build-up in the cost note (Q32).

## `[review]` marks

Best wording written; please confirm.

| Where | Item |
|---|---|
| SOFP «أصول العقود (إيرادات غير مفوترة)», «التزامات العقود (إيرادات مؤجلة)» | E4 labels |
| Notes: first-application note | E2 wording and which transition reliefs apply |
| Policies: fair value | E5, Section 2A |
| Policies: revenue general paragraph | E3 five-step wording |
| Policies: contracting revenue | E3 over-time / input method; onerous-contract sentence |
| Estimates: receivables impairment | E9 incurred-loss model |
| Trade payables: supplier finance | E7 required items |
| Zakat note: rate sentence | §2.2 #13, confirm with the zakat adviser |
| Revenue note | E4 disaggregation and contract balances, extent for SMEs |
| Policies: introduction, first year | first-year wording (PR 11) |
| Policies: cash flow, murabaha finance cost | financing classification sentence (PR 11) |
