# 1. Overview

## Purpose

Accountants receive trial balances (ميزان المراجعة) exported from various ERPs with inconsistent account names. Before building financial statements, each account must be mapped to a standard category. This app automates that mapping:

1. User uploads a trial balance (`.xlsx`, `.xls`, `.csv`, ≤ 10 MB) in **any layout or language**.
2. Laravel reads the raw cells of every sheet, without interpreting them.
3. The AI (Claude by default) identifies the structure (sheet, header rows, which column holds what). Laravel validates it.
4. Rows the classification cache already knows are labelled from the database; the AI labels every other data row (account / parent / total / section / other) and classifies each account into exactly one **sub class** from a fixed taxonomy. It never returns amounts.
5. Laravel reads the exact values from the original cells, derives the **main class** and **financial statement**, and checks the AI output against the file (missing/duplicate rows, parents, debit = credit, total rows).
6. Each row gets a confidence score, a `needs_review` flag and Arabic notes. The AI's reason is in Egyptian Arabic.

Uploads from the page run as queued jobs (`classification_runs`); the sync endpoint still exists. Answers worth reusing are kept in the **classification cache**, so accounts seen before skip the AI ([11](11-classification-cache.md)). Files already classified by accountants can be imported into that cache from `/classification-cache` after the accountant reviews the mappings ([12](12-accountant-imports.md)). Tables: [13](13-database-schema.md).

## Features

| Feature | Where |
|---|---|
| Excel/CSV upload with validation | `ClassifyTrialBalanceRequest` |
| Raw reading of every sheet (formulas use the value saved in the file) | `SpreadsheetReader` |
| AI structure detection for any layout, validated, one retry | `AccountClassifierRepository::detectStructure`, `StructureChecks::check` |
| Exact value extraction (debit/credit pairs, signed or Dr/Cr balances, number+name in one cell) | `AccountExtractor`, `AmountParser` |
| AI row labelling + classification with enforced allowed values, batches of 100 (`CLASSIFIER_BATCH_SIZE`) in a rolling pool (`CLASSIFIER_CONCURRENCY`), one retry of the gaps; a dedicated call matches adjustment lines and existing classification values | `AccountClassifierRepository::classifyRowsRolling`, `ClassificationService::labelRows` |
| Parent/total/section rows excluded; probable parents flagged | `ClassificationService::flagParentAccounts` |
| File checks: debit = credit, account sums = total row | `ClassificationService::fileChecks` |
| Egyptian-Arabic AI reason with fallback | `ClassificationService::egyptianReason` |
| Abnormal-balance check against each sub class's `normal_side` (contra accounts carry the opposite side in the taxonomy) | `ClassificationService::abnormalBalanceFlags` |
| Account-number check: the file's own numbering scheme, inferred per first digit (`prefix_mismatch`) | `ClassificationService::flagNumberPrefixes` |
| Confidence caps: 60 for an abnormal balance, 50 for `prefix_mismatch` (config `confidence_caps`) | `ClassificationService::capConfidence` |
| Summary metadata (total, needs review, avg confidence, skipped rows, partial, warnings, info, side flips, usage) | `ClassificationResponse::make` |
| RTL results UI with progress, column confirmation, warnings banner, search, filter, CSV export | `resources/views/classifier.blade.php` |
| Classification cache: key lookup, code + name fallback for imported entries, name layer, side pairs (stored as base class), manual corrections (API only) | `ClassificationCache`, `ClassificationKey` |
| Accountant-file import: queued read + mapping, review page, staged «تطبيق», reuse of earlier mappings | `CacheImport`, `ProcessClassificationImport`, `classification-cache.blade.php`, `classification-import-review.blade.php` |
| Cache export (Excel, JSON) and JSON import into another instance | `CacheTransfer` |

## Glossary

| Arabic | English | Meaning in the code |
|---|---|---|
| ميزان المراجعة | Trial balance | The uploaded file |
| رقم الحساب | Account number | `account_number` |
| اسم الحساب | Account name | `account_name` |
| مدين / دائن | Debit / Credit | |
| اول المدة | Opening (start of period) | `opening_debit`, `opening_credit` |
| الحركة | Period movement | `period_debit`, `period_credit` |
| اخر المدة | Closing (end of period) | `closing_debit`, `closing_credit` |
| قائمة المركز المالي | Balance sheet (statement of financial position) | `financial_statement` |
| قائمة الدخل | Income statement | `financial_statement` |
| التصنيف الرئيسي | Main class | `main_class` |
| التصنيف الفرعي | Sub class | `sub_class` |
| أصول | Assets | family |
| التزامات | Liabilities | family |
| حقوق الملكية | Equity | family |
| إيرادات | Revenues | family |
| تكلفة | Cost (of sales) | family |
| مصروفات | Expenses | family |
| مجمع إهلاك | Accumulated depreciation | contra account |
| مخصص | Provision / allowance | |

**Family** = the accounting nature of a main class (assets, liabilities, …; `TaxonomyRepository::familyOf`). It drives the full / partial trial-balance decision (`fileChecks`) and the account-number check (`flagNumberPrefixes`). The debit/credit nature check uses each sub class's `normal_side` instead.
