# Output template spec

Read programmatically (openpyxl, python-docx, `word/numbering.xml`) from the two reference files in this folder:

- `القوائم المالية لشركة SMEs .xlsx` (Excel)
- `الإيضاحات المتممة - نسخة منقحة.docx` (Word)

All Excel and Word output code follows this spec. «Fix» marks a reference bug that we must not copy.

## 1. Excel

### 1.1 Workbook

| # | Sheet name (exact) | Print area | Orientation | Zoom |
|---|---|---|---|---|
| 1 | `data` | A1:J10 | portrait | 85 |
| 2 | `الغلاف` | A1:J16 | portrait | 85 |
| 3 | `الفهرس` | A1:H27 | portrait | 85 |
| 4 | `المركز المالى` (final «ى») | A1:H48 | portrait | 85 |
| 5 | `قائمة الدخل الشامل` | A1:H53 | portrait | 85 |
| 6 | `قائمة التغيرات ` (trailing space in the reference) | A1:M27 | landscape | 85 |
| 7 | `قائمة التدفقات النقدية` | B1:H49 | portrait | 85 |
| 8 | `ايضاح الاصول والممتلكات` | A1:I19 | landscape | 80 |

Rules for every sheet:

- Right-to-left.
- Paper size 9 (A4).
- No defined names.
- Font: Sakkal Majalla everywhere.
  - Size 14 on the statements and `data`.
  - Size 16 on the cover titles and on the whole PPE sheet.
  - Size 18 for the index city line.
- The column-date marker `(^)` is Rubik Light 14 bold.
- Bold is used for headings, totals and the footer.
- Line labels are regular weight.

### 1.2 Number formats

| Use | Format code | Negative | Zero |
|---|---|---|---|
| SOFP, IS amounts and totals | `_-* #,##0_-;\-* #,##0_-;_-* "-"??_-;_-@_-` | minus sign | `-` |
| SOCE movements, PPE note | `_-* #,##0\ _ج_._م_._‏_-;\-* #,##0\ …;_-* "-"??\ …` (accounting with a hidden currency suffix) | minus sign | `-` |
| SOCE balances | `#,##0_);[Red](#,##0)` and `#,##0_);(#,##0)` | parentheses (red on the balance rows) | `0` |
| Cash flow amounts | `_(* #,##0_);_(* \(#,##0\);_(* "-"_);_(@_)` | parentheses | `-` |
| Cash flow net change | `#\ ###\ ##0_-;\(#\ ###\ ##0\)` | parentheses | blank |

The reference is not consistent: the SOFP and IS show negatives with a minus sign, while the cash flow uses parentheses. Rule R1 and the brief ("negatives in parentheses, zero as -") win. Every amount cell uses one format: thousands separator, parentheses for negatives, `-` for zero. See gap G2.

### 1.3 `data` sheet (drives every heading)

| Row | A (bold) | B | C | D | E |
|---|---|---|---|---|---|
| 3 | اسم الشركة | company name | | | |
| 4 | الكيان | legal form (e.g. شركة ذات مسئولية محدودة) | `- سعودية` (red fill, text format) | | |
| 6 | السنة المالية الحالية | عن السنة المالية المنتهية في | day (31) | month | `2025 م` |
| 7 | بداية السنة المالية الحالية | | 1 | يناير | year |
| 8 | السنة المالية المقارنة | | 31 | ديسمبر | year |
| 9 | بداية السنة المالية المقارنة | | 1 | يناير | year |

- Row heights are 27.75.
- Cells have thin top and bottom borders.
- Fix: the reference D6 reads «يناير»; the year ends in «ديسمبر». The month is derived from the real period end, never typed.

### 1.4 Cover and index

- **Cover:** rows 10–13 are merged A:J, centred, 16 bold.
  - Row 10: company name.
  - Row 11: legal form + «- سعودية».
  - Row 12: «القوائــم المالية وتقرير المراجع المستقل عليها».
  - Row 13: «عن السنة المالية المنتهية في 31 ديسمبر 2025 م».
- **Index:** rows 5–7 are merged A:H.
  - Row 5: company name.
  - Row 6: legal form.
  - Row 7: «"المملكة العربية السعودية - <city>"» in 18 bold. Fix: the reference hard-codes الرياض; the city comes from the company info.
- **Index table:** «فهرس» in C17 and «الصفحة» in H17. The rows are:
  - تقرير المراجع المستقل = `-`
  - قائمة المركز المالي = 1
  - قائمة الدخل الشامل = 2
  - قائمة التغيرات في حقوق الملكية = 3
  - قائمة التدفقات النقدية = 4
  - إيضاحات حول القوائم المالية = `19-5` (text)

### 1.5 Statement sheets: common layout

- **Title rows 1–3:** merged A:H (SOCE C:M), centred, bold.
  - Row 1: company name.
  - Row 2: legal form.
  - Row 3: «<statement name> كما في / للسنة المالية المنتهية في» + the date.
- **Column header:**
  - «ايضاح» goes in the note column (B; C on the cash flow).
  - The current date is merged D:E; the comparative date is merged G:H.
  - The `(^)` markers sit under the dates.
- **Amounts:** current year in column E, comparative in column H. The note number goes in column B (C on the cash flow).
- **Footer (merged, bold):** «الإيضاحات المرفقة من إيضاح رقم (1) إلى إيضاح (..) جزء لا يتجزأ من هذه القوائم المالية.» The «(..)» is the last note number.
- **Borders:**
  - Totals have a thin (or medium) top and bottom border.
  - Grand totals (total assets, total equity and liabilities, total comprehensive income, closing balances) have a double bottom border.
- **Checks** sit outside the print area, for example SOFP row 52: assets − (equity + liabilities) = 0.

### 1.6 `المركز المالى` (rows)

| Row | Label | Type |
|---|---|---|
| 5 | الأصول | heading |
| 6 | الاصول غير المتداولة | heading |
| 7–11 | الممتلكات والآلات والمعدات (one net line, D2); استثمارات عقارية؛ استثمارات فى شركات زميلة ومشروعات مشتركة؛ FVOCI؛ amortised cost (non-current) | lines |
| 12 | إجمالي الأصول غير المتداولة | `=SUM(E7:E11)` |
| 13 | الأصول المتداولة | heading |
| 14–21 | المخزون؛ إيرادات غير مفوترة؛ الذمم التجارية المدينة؛ مصاريف مقدمة وأرصدة مدينة أخرى؛ amortised cost (current)؛ مستحق من اطراف ذات علاقة؛ ودائع مرابحة لأجل لدى البنوك؛ النقد ومعادلات النقد | lines |
| 22 | إجمالي الأصول المتداولة | SUM |
| 23 | إجمالي الأصول | `=E12+E22` (double) |
| 24–25 | حقوق الملكية والالتزامات / حقوق الملكية | headings |
| 26–28 | رأس المال؛ الاحتياطي النظامي؛ أرباح (خسائر) مبقاة | lines |
| 29 | إجمالي حقوق الملكية | SUM |
| 30 | الالتزامات غير المتداولة | heading |
| 31–33 | قروض عقود مرابحة؛ مخصص التزامات منافع الموظفين؛ مستحق لأطراف ذات علاقة طويلة الأجل | lines |
| 34 | إجمالي الالتزامات غير المتداولة | SUM |
| 35 | الالتزامات المتداولة | heading |
| 36–44 | ذمم تجارية دائنة؛ قروض…قصيرة الأجل؛ سحب على المكشوف؛ ايرادات مؤجلة؛ دائنوا توزيعات أرباح؛ (الذمم التجارية الدائنة, a duplicate); مصاريف مستحقة وأرصدة دائنة أخرى؛ مستحق لأطراف ذات علاقة؛ مخصص الزكاة | lines |
| 45 | إجمالي الالتزامات المتداولة | SUM (Fix: the reference sums from row 37 and misses row 36) |
| 46 | إجمالي الالتزامات | `=E45+E34` |
| 47 | إجمالي حقوق الملكية والالتزامات | `=E46+E29` (double) |

Fixes:

- The labels «إجماليالأصول» lack a space; we write «إجمالي الأصول».
- The trade payables duplicate (row 41) is dropped.

### 1.7 `قائمة الدخل الشامل` (rows)

| Row | Label | Formula |
|---|---|---|
| 9, 10 | إيرادات النشاط؛ تكلفة النشاط | |
| 11 | مجمل الربح (الخسائر) | `=SUM(E9:E10)` |
| 12 | يضاف (يخصم) | heading |
| 13–19 | مصاريف البيع والتسويق؛ المصروفات العمومية والادارية؛ مصروف الأهلاك؛ مصروفات أخرى؛ إيرادات أخرى؛ خسائر الانخفاض…؛ رد خسائر الانخفاض… | |
| 20 | صافى الدخل من التشغيل | `=SUM(E11:E19)` |
| 21, 22 | توزيعات أرباح من أدوات حقوق الملكية؛ الحصة فى نتائج… | |
| 23 | صافى الدخل (خسائر) قبل الزكاة والضريبة | `=SUM(E20:E22)` |
| 24 | الزكاة الشرعية | |
| 25 | صافى الدخل (الخسائر) للسنة | `=E23+E24` |
| 26–37 | OCI block | |
| 38 | اجمالي الدخل (الخسارة) الشامل للسنة | |

- The reference repeats the OCI block (rows 40–50), and its row 51 sums both. Fix: keep one OCI block.
- I11 and I23 contain `#REF!`. Fix: remove them.
- R5: «مصروف الإطفاء» becomes its own line right after «مصروف الأهلاك». The reference has depreciation only.
- Signs: income is positive, expenses are negative. Totals are SUMs.

### 1.8 `قائمة التغيرات ` (SOCE)

- **Columns:**
  - C = label.
  - E رأس المال; G رأس المال غير المدفوع (subtracted); I الأحتياطي النظامي; K أرباح مبقاة; M إجمالي حقوق الملكية.
  - Spacer columns D, F, H, J, L are narrow.
- **Comparative block, rows 11–17:**
  - Row 11: «الرصيد في 1 يناير <py>».
  - Row 12: صافى الدخل للسنة.
  - Row 13: الدخل الشامل الأخر.
  - Row 14: total comprehensive income (SUM).
  - Row 15: زيادة رأس المال.
  - Row 16: توزيعات أرباح.
  - Row 17: «الرصيد في 31 ديسمبر <py>» = `SUM(14:16)+11`, with a double border.
- **Current block, rows 19–25:** the same rows.
  - Row 19 = row 17.
  - K20 links to the IS net result.
  - Row 25 is the closing balance.
- M = E − G + I + K.
- **Checks:** Y17 and Y25 = M − SOFP equity.
- **Fixes:**
  - Remove the scratch cells O4–O23, Q3, Q15, Q23, S/V/W, N25–N29 and P29–P33, including the other company's numbers and the `#REF!` cells.
  - Remove C7–C8 «راس المال المدفوع / صافي (خسارة)الفترة», which are stray labels.
  - The checks themselves are right: Y17 compares with SOFP H29 and Y25 with E29.

### 1.9 `قائمة التدفقات النقدية`

- Labels are in column B. Notes are in C. E holds the current year and H the comparative.
- **Operating:**
  - B8: صافى دخل السنة قبل الزكاة (= IS row 23).
  - Row 9: adjustments heading.
  - Rows 10–22: adjustments, starting with استهلاك ممتلكات وآلات ومعدات. R5 adds an amortisation line after it.
  - Row 23: «التغيرات فى» heading.
  - Rows 24–32: working capital, end-of-service paid and zakat paid.
  - Row 33: net operating = SUM.
- **Investing:** rows 35–39, net in row 40.
- **Financing:** rows 42–44, net in row 45.
- **Closing rows:**
  - Row 46: net change.
  - Row 47: opening cash.
  - Row 48: closing cash (double border).
- **Checks:** row 50 onwards, outside the print area. Closing cash − SOFP cash = 0.
- **Fixes:**
  - H51 is `#REF!`.
  - C48 points at a note column.
  - E47 `=H48` is fine for the current year. The comparative opening cash is an input.

### 1.10 `ايضاح الاصول والممتلكات` (PPE note)

- **Header rows:**
  - Row 1: company name.
  - Row 2: legal form.
  - Row 3: «إيضاحات حول القوائم المالية للسنة المالية المنتهية في …».
  - Row 4: «(جميع المبالغ بالريال السعودي ما لم يُذكر خلاف ذلك)», merged B:G.
- **Row 6:** «<n>- الممتلكات و الآلات و المعدات:».
- **Row 7:** «التكلفة», then one column per category, then «الإجمالي».
  - The reference has six columns: أراضي، مباني، الآلات ومعدات، أثاث وأجهزة مكتبية، حاسب آلي، سيارات.
  - The categories are dynamic, from the prior-year PDF plus the TB (the reference client has 8).
- **Cost rows 8–11:**
  - التكلفة في 1/1.
  - إضافات خلال العام.
  - استبعادات خلال العام.
  - التكلفة في 31/12 = opening + additions − disposals.
- **Accumulated depreciation rows 13–17:**
  - Row 13: a heading. The reference mislabels it «الاطفاء المتراكم»; the fix is «مجمع الإهلاك».
  - Row 14: opening.
  - Row 15: اهلاك العام.
  - Row 16: disposals.
  - Row 17: closing.
- **Net book value rows 18–19:**
  - Row 18: at the current period end.
  - Row 19: at the comparative end.
  - Fix: the reference's row 19 formula `=C12-C18` points at an empty row. The comparative NBV = opening cost − opening accumulated depreciation.
- **Columns:** labels in B (width 41). Amounts from C, and the total column is `SUM`. Font is 16 bold for amounts.

## 2. Word notes

### 2.1 Page and sections

- **Page:** A4 (21.0 × 29.7 cm). Margins are top and bottom 2.2 cm, right and left 2.3 cm. Right-to-left (`w:bidi`, `rtlGutter`).
- **Sections:**
  1. Portrait: the notes up to the end of the estimates.
  2. Landscape: the PPE, intangibles and investment property tables.
  3. Portrait: the rest.
- **Header in every section, three lines:**
  - «(شركة ذات مسئولية محدودة - سعودية)». The company name sits above it in the original header.
  - «إيضاحات حول القوائم المالية للسنة المالية المنتهية في 31 ديسمبر 2025م».
  - «(جميع المبالغ بالريال السعودي ما لم يُذكر خلاف ذلك)».
- **Fonts:** Sakkal Majalla. Note titles are 14 bold. Body text is 13 bold, justified. Tables use the `Table Grid` style.
- **Numbering:** Word auto-numbering.
  - Main notes: `%1` (1, 2, 3 …).
  - Sub-notes: `%1-%2` (2-1, 2-2 …).
  - Lists: `%1-`, `%1)`, `arabicAbjad %1)` and bullets.
  - A deleted note renumbers the rest.

### 2.2 Note order and content

| # | Note | Content |
|---|---|---|
| 1 | التكوين والنشاط | **Text.** Company name, legal form, city, CR number and date (Hijri/Gregorian), activity, financial year (1 Jan–31 Dec), head-office address. |
| 2 | أسس إعداد القوائم المالية | **Sub-notes:** 2-1 بيان الالتزام (IFRS for SMEs as endorsed in KSA); 2-2 أسس القياس; 2-3 العملة الوظيفية; 2-4 الاستمرارية. **Going concern:** net loss, accumulated losses vs half of capital, current liabilities − current assets. |
| 3 | السياسات المحاسبية الهامة | **Policies:** current/non-current; PPE with a useful-life **table** (البند \| العمر الإنتاجي, one row per category); intangibles (lives list); investment property; SOCI/CF presentation; cash; murabaha deposits; inventory; associates; subsidiaries; FX; financial instruments; impairment; derecognition; offsetting; fair value; non-financial impairment; CWIP; provisions; leases; zakat & VAT; employee benefits; revenue variants; cost of revenue; expenses. |
| 4 | الأحكام والتقديرات والافتراضات | **Text:** useful lives, receivables impairment, non-financial impairment, contingent liabilities. |
| 5 | الممتلكات والآلات والمعدات | **Landscape.** Two tables, one per year («الممتلكات والآلات والمعدات-2025» and «-2024»), 13 rows: cost opening, additions, disposals, closing; a blank row; acc. dep. opening, charge, disposals, closing; NBV at the current end and at the prior end. One column per category plus الإجمالي. |
| 6 | الأصول غير الملموسة | Same shape. Columns: البرامج والأنظمة، التراخيص، الإجمالي. «مجمع الاستهلاك» (we write «مجمع الإطفاء»). |
| 7 | استثمارات عقارية | Same shape. Columns: أراضي، مباني، الإجمالي. |
| 8 | المخزون | **Two-year table** (see 2.3). |
| 9 | الذمم المدينة التجارية | Two-year table: ذمم مدينة تجارية، يخصم، الانخفاض في قيمة العملاء، total. |
| 10 | المدينون والأرصدة المدينة الأخرى | Two-year table. |
| 11 | النقد ومعادلات النقد | Two-year table. |
| 12 | رأس المال | **Sentence:** «يبلغ رأس مال الشركة … ريال سعودي مقسم إلى … (بالحروف) حصة، قيمة كل حصة … ريال موزعين بين الشركاء كالاتي:». **Partners table (8 columns):** الاسم \| عدد الحصص \| قيمة الحصة الواحدة \| نسبة الحصة \| الإجمالي ريال سعودي, with spacer columns. |
| 13 | مخصص التزامات منافع الموظفين | **Movement:** opening, charged, used, closing. |
| 14 | الذمم التجارية الدائنة | Two-year table, item rows. |
| 15 | دائنون وأرصدة دائنة أخرى | Two-year table. |
| 16 | أطراف ذات علاقة | **Text.** Two **7-column tables**: البيان \| طبيعة العلاقة \| طبيعة المعاملات \| حجم المعاملات \| 2025م \| 2024م. The captions are «<n>/أ مستحق من أطراف ذات علاقة» and «<n>/ب مستحق لأطراف ذات علاقة». |
| 17 | مخصص الزكاة | **Text:** rate and base. **Movement table:** الرصيد في أول السنة، المكون، زيادة، المستخدم ورد المخصص، الرصيد في نهاية السنة. |
| 18 | تكلفة النشاط | Two-year table, item rows. |
| 19 | المصروفات العمومية والإدارية | Two-year table, item rows. |
| 20 | إدارة مخاطر الأدوات المالية | **Text:** credit, liquidity, market and currency risk. |
| 21 | الأرباح المقترح توزيعها | **Removed (R4).** |
| 22 | الاحداث اللاحقة | **Text.** |
| 23 | الأحداث الهامة | **Text.** A placeholder; dropped unless entered. |
| 24 | اعتماد القوائم المالية | «اعتمدت هذه القوائم المالية من قبل <body> بتاريخ <hijri> الموافق <gregorian>.» The body defaults to «الإدارة» (R3). |

### 2.3 Two-year item tables

- **Shape:** four columns, seen right to left as label | current year | spacer | prior year. Some reference tables store the cells in the reverse order and use `bidiVisual` to flip them; they look the same on the page.
- **Header row:** «2025م» and «2024م» over the amount columns.
- **Rows:**
  - Item rows, then a total row with no label.
  - R11: the item rows are the prior-year PDF's lines, with its exact wording. D3: plus any new current-year items, with «-» for the prior year.
  - D4: rows that are zero in both years are hidden.
- **Amounts:** whole riyals, thousands separator, parentheses for negatives, `-` for zero (R1).

## 3. Names (R6)

Statement names are always written as:

- «قائمة المركز المالي»
- «قائمة الدخل الشامل»
- «قائمة التغيرات في حقوق الملكية»
- «قائمة التدفقات النقدية»

Synonyms are used when reading only, never in output:

| Read as | Written as |
|---|---|
| الموجودات | الأصول |
| المطلوبات | الالتزامات |
| حقوق الشركاء | حقوق الملكية |
| قائمة الأرباح أو الخسائر والدخل الشامل الآخر / قائمة الدخل | قائمة الدخل الشامل |
| إستهلاك ممتلكات وآلات ومعدات | اهلاك الموجودات الثابتة (line) / مصروف الأهلاك |
