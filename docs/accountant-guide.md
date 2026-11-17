# Financial statements generator: a guide for the accountant

## What the system does

You upload a company's trial balance. You add the company's details, and you tell the system where last year's figures come from. It then produces the four financial statements and the notes:

- statement of financial position;
- statement of comprehensive income;
- statement of changes in equity;
- statement of cash flows.

The statements follow IFRS for SMEs (3rd edition wording) for a Saudi limited liability company, in Arabic. You can download each saved version as:

- an **Excel** file of the statements, including your adjusting entries as journal entries;
- a **Word** file of the notes;
- a **PDF** of the statements and the notes together.

**Every number is calculated by fixed rules, with no AI.** AI is used in one place only: when the trial balance is first read, it recognises the columns and suggests a classification for each account name. It never reads or changes an amount, and any classification it isn't sure of is flagged for your review. From then on, every figure comes from the trial balance, from rules you can read below, or from what you type, and you can trace each one back to its accounts. A figure the system doesn't have is shown as «[ناقص]» (missing), never as 0 and never guessed.

## How to try it

**Test data.** If you want to test without a real client, use the anonymised sample trial balance we will send you (sample-trial-balance.xlsx, a real 2025 trial balance with the names removed, 282 accounts).

**Before the wizard: the trial balance.**
1. Open «تصنيف ميزان المراجعة» and upload the trial balance (Excel or CSV, any layout).
2. Check the accounts it marks for review.
3. Press «إعداد القوائم المالية». Choose or create the company and the period (by default, the last full financial year).

**The 9 steps.** You can move between steps freely, and every change is saved at once. A side panel always shows what still stops the report from being final; click an item to go to the step that fixes it.

1. **Company and period.** The company's details as the notes print them: name, city, commercial registration and its Hijri date, unified number, activity, head office, branches, financial year end. Also the period of the report.
2. **Prior year.** Asked only when the trial balance shows balances from before the year: its opening column; without one, closing balances less the year's movements, retained earnings from before the year, or accumulated depreciation larger than this year's. The step says which. With no sign either way, you choose. With an opening column that is all zero, the report is a first year and the step says so; press «فيه سنة سابقة؟» if there is one. With opening balances, choosing «أول سنة» is kept as your decision. Where last year's figures come from. There are four ways:
   - **Last year's report** in this system, if you prepared one. Its figures are taken exactly.
   - **Last year's trial balance**, classified like this year's. Other comprehensive income is typed by hand.
   - **Manual entry:** type last year's figures line by line from the issued statements.
   - **Upload last year's issued statements (Excel or PDF).** They must come with the independent auditor's report: a page of it in a PDF (the system names it; correct its page's kind if it took it for something else), a sheet or a title naming it in an Excel file. Without it the file isn't accepted as approved statements. A PDF can be scanned. The system first finds the statements' pages and shows them: open each page with «افتح الصفحة», correct a page number if it's wrong, then press «اقرا القوائم». The system copies each statement's table from its pages; it never decides a line or a figure. A total that doesn't add up to the rows above it is marked «راجع الرقم ده»: look at that page, since a digit may have been misread. Then, as for Excel, the system reads each statement and its year column and shows every row with its amount, its page, and the line it proposes:
     - «مطابق»: the wording is the line's own;
     - «مطابق من المرادفات»: the wording is in the synonyms list;
     - «مقترح — راجعه» or «اختار سطر»: you choose the line, a total to check against, or «تجاهل».

     Nothing is used until you save the step. You can change any row before saving. Amounts it can't read are shown and stay missing.

   With the trial balance, manual and upload options you also type the prior year's changes in equity, cash flow (unless the uploaded file has one), note tables and movement tables. If last year's figures were restated, tick «أرقام المقارنة معدّلة» and give the reason.
3. **Accounts and entries.**
   - Accounts with no line: choose one.
   - Possible parent (total) accounts: exclude them with a reason, or keep them.
   - Periodic stock accounts: move them to cost of revenue, or keep them.
   - The accounts total of every statement line, next to the line's amount in the statement (the difference is your adjusting entries or a computed figure such as the year's profit), with each statement's totals added up the same way, and the trial balance's total debit and credit balances.
   - Every account, with the option to move it to another line, split it between lines in percentages, or set its note category.
   - Your **adjusting entries**: balanced, each with a reason.
4. **Opening balances.** Each line's opening balance in the trial balance against last year's closing balance in the approved statements (the file of last year's issued statements, which must come with the independent auditor's report: a PDF without a page of it, or an Excel file without a sheet or title naming it, isn't accepted). The three parts of the balance sheet first, then every line (the differences only, or all), with how the differences relate: the accounting equation of the opening balance sheet in the trial balance and in the approved statements, the differences meeting each other, the part of the equity difference retained earnings don't explain, and the opening cash the cash flow starts from. It also shows what the differences do to the statements: each line as presented against the trial balance alone, and which statements and notes are reached (balance sheet, changes in equity, cash flow, the property note). The decision is yours: start the year from the approved balances (the differences become rows of their own on their lines) or keep the trial balance's.
5. **Movements and missing figures.**
   - The movements the system suggests for the notes (additions, disposals, depreciation, provisions charged and used) and for the changes in equity and cash flow. Each one shows where it came from; you confirm, edit or reject it.
   - The figures only you can give, such as the zakat charge and other comprehensive income.
6. **Zakat and income tax.** The year's zakat base and zakat under the Zakat Collection Regulation of 1445 H, as the accountants' zakat sheet works it: equity and what is treated as equity + non-current liabilities within the assets deducted + the difference between the adjusted and the book profit + current liabilities beyond the current assets - the assets deducted, between a minimum (the adjusted profit) and a maximum (equity), x 2.5% x the year's days / 354, on the Saudi and GCC owners' share; and income tax at 20% on the foreign owners' share of the adjusted profit, after carried-forward losses (at most a quarter of the profit). The figures come from the statements before the year's own zakat and tax; you answer what they can't give (the owners' share, the profit's adjustments, the assets deducted, the allocation, the carried-forward losses). Then charge the zakat and the tax in the statements (expense debit, provision credit), only where the trial balance, an entry or the intake doesn't charge them already.
7. **Notes and text.**
   - The questions the notes need: approval body and date, partners and shares, useful lives, related parties, accounting policy choices, subsequent and significant events.
   - The going-concern paragraph, if the system finds an indicator.
   - Every note as it will print: you can show or hide a note, and reword any paragraph.
8. **Review.**
   - Errors to fix, and warnings to acknowledge (each with an optional note).
   - Prior-year cells left empty and counted as 0.
   - Everything you changed that affects the figures: adjusting entries and moved accounts.
   - Then the statements and notes exactly as they will print.
   - Save a **draft** at any time, or issue a **final** when nothing is blocking.
9. **Versions.** Every saved version. Open one to see it as it was saved, and download its Excel, Word or PDF file.

**Synonyms page («مرادفات البنود»,** in the top menu). This is the list of other wordings that issued statements use for our lines, for example «النقدية وما في حكمها» for «النقد ومعادلات النقد». It is used only when you upload last year's issued statements. You can add, edit or delete entries; changes apply to files still waiting for review, never to figures you already saved.

## Assumptions and rules

**Placing accounts on lines**

1. Each account goes on a statement line according to its classification, using a fixed table of classification to line. Any account can be moved to another line or split between lines by you; your choice always wins.
2. A classification already in the client's file is used as a starting point only. It is never trusted where the checks disagree, and those cases ask for your confirmation.
3. Expenses are presented by function: cost of revenue, selling and marketing, general and administrative. Each type of expense has a default function. You can move an account, or split it in percentages.
4. An account with a balance on the wrong side for its line is flagged for review; it is never moved without you.
5. A bank account in credit is shown as a bank overdraft, a liability, for that year. Overdrafts are not counted in cash in the cash flow unless you say so.
6. Customer and supplier advances are presented under prepayments and other receivables, or under accruals and other payables. Customers, suppliers, employee custody and advances, prepaid and accrued expenses follow their balance: a credit balance goes with the credits, a debit balance with the debits, whatever the account's group (the closing balance decides, else the opening, else the movement).
6a. Accounts that are zero in the opening, movement and closing are left out of the classification.
6b. A trial balance that doesn't balance (opening, movement or closing off by 1 riyal or more), full or partial, is refused.
6c. A trial balance with opening balances always has a comparative year: «first year» isn't applied.
7. Opening and closing stock accounts kept on the periodic method are proposed for cost of revenue; you confirm or keep them in inventory. The cost of revenue note then shows the build-up: opening stock + purchases + purchase costs − closing stock.
8. Parent (total) accounts shown as accounts in the trial balance are left out when the file's levels show they are totals and their balance is the sum of the accounts right under them. Levels (dots, indentation) where no account adds up its children are ignored. When only the amounts suggest it, they are proposed for you to exclude with a reason. You can exclude any account by hand, with a reason; every exclusion is listed in the review.
9. Deferred finance cost on murabaha loans is deducted from the loan on the balance sheet. You mark the deferred finance cost account as such in step 3. The loans note shows the gross amount, the deferred cost and the net.
10. Partners' current accounts are a liability by default; you can present them in equity instead.

**Trial balance and profit**

11. The trial balance should be pre-closing, with the income statement accounts still open. Retained earnings then include the year's profit.
12. For a post-closing trial balance, the income statement is taken from the year's movements only if the closing entry on retained earnings equals the profit (or loss) exactly. Otherwise the income statement is missing and reported as an error.
13. **Zakat** is never calculated. If there is a profit before zakat and no zakat charge, you must enter the charge or confirm it is zero with a reason. The charge is booked as zakat expense against the zakat provision.
14. Income tax, other comprehensive income items, related-party transaction volumes and transfers to the statutory reserve are entered by you. There is no automatic 10% reserve transfer.
15. Adjusting entries are applied to the report only, never to the trial balance itself. They are exported as journal entries so you can post them in the books.

**Prior year**

16. Comparative figures always come from you: last year's report, last year's trial balance, manual entry, or last year's issued statements. Without any of these:
    - the balance sheet comparative comes from the trial balance's opening columns, if it has them;
    - the other comparatives are missing, so only a draft is possible.
17. Last year's closing balance sheet must equal this year's opening balances. Each difference is an error until you correct last year's figures, or mark the comparatives as restated with a reason. The reason then becomes a «تعديل أرقام المقارنة» note. Nothing is adjusted automatically.
18. In an uploaded issued statement, **a line that does not appear in the file is taken as 0**, because issued statements leave out zero lines. Each total in the file is checked against the lines read from it, so a row left out or put on the wrong line shows as an error.
19. In an uploaded file, expenses shown as positive numbers are turned negative only after you confirm it. An amount the system cannot read is missing, not 0.
20. **Still entered by hand for the prior year:** the changes in equity, the note tables and the movement tables. The cash flow is also entered by hand, unless the uploaded file has one.
21. In the company's first year there is one column only, with no comparatives.

**Presentation**

22. Amounts are in whole Saudi riyals. Negatives are in parentheses, zero is shown as «-», and digits are Western (1, 2, 3).
23. Totals are the sum of the rounded lines. A total's rounding difference goes on one line under it, the largest by default; you can choose another line. A difference above 5 riyals goes nowhere and blocks a final.
24. **Zero lines:** a line or total that is zero in both years is hidden. This applies even if accounts sit under it, and it is the same in the review, Excel and PDF. A heading with nothing under it is also hidden. A line whose prior year is missing is shown with «[ناقص]».
25. **Missing figures** are shown as «[ناقص]», never 0. Any report with a missing figure can only be a draft.
26. A note is shown when one of its lines is not zero in either year, or for its own reason (restatement, going concern, related parties, events). Shown notes are numbered 1, 2, 3… in order, and the «إيضاح» column of the statements uses the same numbers. You can force a note on or off.
27. Within notes, rows and category columns that are zero in both years are hidden.
28. The wording of the notes is the template's text (IFRS for SMEs, 3rd edition) filled with the company's figures and details. You can reword any paragraph; the system tells you if the template's text changes later.
29. An early-application paragraph for the 3rd edition is shown for periods starting before 1 January 2027.
30. The going-concern paragraph is proposed when there is a net loss, accumulated losses of half the capital or more, current liabilities above current assets, or total liabilities above total assets. You include it, or exclude it with a reason. It is added by itself with the figures (the Companies Law requires the disclosure once losses reach half the capital): nothing to acknowledge, and no mark on the line. You may still exclude it, with a reason.
31. Property, plant and equipment is shown per category. When the trial balance has one accumulated depreciation account, you enter the split by category, and it must add up to the total.
32. Gregorian dates are used as dates. Hijri dates are printed as you type them; there is no conversion.
33. **The notes start at page 5** in the Word file. This is a fixed number from the template and is not yet linked to how many pages the statements take.
34. The Word file uses the template's font (Sakkal Majalla). The PDF uses an open Arabic font (XB Riyaz), because Sakkal Majalla can't be embedded without a licence.
35. The auditor's report is not produced.

**What the system works out by itself (no question asked)** — on every report you review, from the trial balance, last year's statements and the articles of association. Everything it works out is shown as a suggestion, and your own answer always wins.

39. **Other comprehensive income:** none, when no reserve other than the statutory one moved in the year and last year shows none. Otherwise you are asked.
40. **Kinds of revenue** (for the revenue policy): last year's policy note, else the company's activity and the names of its revenue accounts.
41. **Useful lives** of fixed assets: from last year's statements (100 ÷ the depreciation rate); asked only for a category last year doesn't give.
42. **Partners and shares:** from last year's capital note, else from the articles of association. Ownership percentages are worked out from the shares.
43. **Articles of association (عقد التأسيس):** upload the PDF once on the company's page (step 1). The AI only copies the partners, their nationality and shares; the system decides who is Saudi or Gulf (zakat) and who is foreign (income tax), and the zakat step starts from that share. A nationality or share it can't read leaves the share to you.
44. **Zakat calculation:** its own step in the wizard and a section of its own under the statements in the review, line by line with the regulation's article beside each line, so you can review it apart from the statements and notes. The calculated zakat (and the income tax of the foreign owners' share) goes into the statements and the notes by itself and follows any change of your answers; where the trial balance, an entry or the intake already charges it, it is only compared. Take it out in the zakat step and it stays out until you charge it again there.
45. **No empty lines:** a line with no amount in its year is not printed. In the changes in equity this is decided for each year's table on its own, so a movement the other year has doesn't leave an empty line here; a column with nothing in it is left out too.

**Draft and final**

36. A **draft** can be saved at any time. Its files say «مسودة» (draft) on every page and start with a list of what is missing and which checks fail.
37. A **final** can only be issued when there are no errors, every warning is acknowledged, nothing is missing, and every suggestion is decided.
38. Saved versions never change. A version's files are always identical, and a new final replaces the previous one as the current final.

## How to request changes

Please refer to the rules above by number, for example "rule 13: we want zakat calculated" or "rule 33: start the notes at page 4".

**You can change these yourself in the app, without asking us:**
- the synonyms list (add, edit or delete wordings);
- moving an account to another line, splitting it, or excluding it with a reason;
- adjusting entries;
- the line used for each row of an uploaded issued statement;
- showing or hiding a note, and rewording any paragraph;
- the answers to the notes' questions (policies, useful lives, partners, related parties, events);
- confirming, editing or rejecting suggested movements;
- the prior-year source and figures, and marking comparatives as restated;
- presenting partners' current accounts in equity, and counting overdrafts in cash.

**These need a change from us:** the fixed table of classifications to lines and the default function of each expense type; the template's wording, line names and layout; anything about how a figure is calculated or checked; rounding and number format; the note page number (rule 33); the fonts; adding a new statement or note; and anything listed above as "entered by hand" that you would like the system to work out.
