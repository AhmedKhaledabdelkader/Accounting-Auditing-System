# Classification rules

The accountant's decisions for balance-sheet accounts, kept in a table the accountant edits (`/classification-rules`, the taxonomy's permissions) instead of being left to the AI on every run. Applied to every run since 2026-10-19 (below); `php artisan classification:rules-preview <run…> [--company=] [--out=]` reports what they would do to a stored run (Markdown), and the balance-sheet accounts on the side opposite their class's nature with no counterpart (flagged, not moved). The reports for the accountant are in [`reviews/`](reviews/).

## A rule

`classification_rules` (`App\Models\ClassificationRule`), seeded by migration `2026_10_19_000002` with the decisions of 2026-10-19:

| Field | Meaning |
|---|---|
| `name` | The kind of account (مورد، موظف (حساب شخصي)، …) |
| `sort` | The first enabled rule that matches wins |
| `name_words` | Any of them in the account's name… |
| `also_words` | …and, when given, one of these too (e.g. «رواتب» with «مستحقة») |
| `exclude_words` | None of them may be in the name, whatever matched |
| `heading_words` | Or any of them in the heading above the account: the run's `heading` (the last section, parent or named zero row with no number before it until a total row, or its parent by the file's levels) |
| `debit_class` / `credit_class` | The sub class for a debit / credit balance; null: not decided yet (the AI's class stays) |
| `review_side` | `none` / `debit` / `credit` / `both`: flagged for review on that side (e.g. a debit zakat provision) |
| `ai_classes` | The AI's classes that suggest this kind, for code-group proposals |
| `enabled` | |

**Matching** (`App\Services\ClassificationRules::match`):
- Balance-sheet accounts only: the AI or the cache says which statement an account is on (`financial_statement`), so «مصروف الزكاة» or «بدل سكن المدير» never match.
- Whole words (`ClassificationCache::nameHas`): after normalization (`ImportNormalizer`: alef forms, ة/ه, punctuation split), a leading «ال» or «و» doesn't count, so «مدير» matches «المدير» and «ممتاز*المدير» but not «المديرية»; «عهد» doesn't match «معهد». Several words are a phrase in order.
- By name or by heading, in rule order; then by the company's code group (below): an account's own words beat its number («جاري التأمينات» in a group marked «مورد» stays the insurance).

**Outcome** (`outcome`): the class for the side of the balance (`ClassificationCache::rowSide`: closing, else opening, else the net movement; no minimum amount), and whether that side needs a review.

## Code groups

`company_code_groups`: a company's account-number prefix marked as one rule's kind (personal employee accounts are people's names: no word catches them). Applies to the company's runs (`classification_runs.company_id`, chosen at upload). The longest marked prefix wins.

**Proposals** (`proposals`, stored in a run's `meta.code_group_proposals`): at least 3 balance-sheet accounts no rule catches, sharing their number less its last two digits, with at least 60% of them in one rule's `ai_classes`. The classifier page lists them with a link to the rules page, the mark filled in; the user confirms it there, and comes back to the run by the link the page shows. Marking a group already marked with the same kind says so; with another kind it's refused. Nothing is marked by itself.

## The accountant's answers (2026-10-19, migration `2026_10_19_000003`)

- New classes: «عُهد وسُلف العاملين» (debit) ↔ «عُهد وسُلف دائنه» (credit), also a side pair; «أصول أخرى» and «مجمع إطفاء أصول أخرى» under their own main class, the line `sofp.other_assets` under PPE ([14](14-financial-statements.md)).
- «تسهيلات موردين» is not a kind of its own (a supplier). «جاري الشريك» keeps its own treatment: out of «مدير / شريك» (exclude words).
- Other assets and their amortisation on the wrong side are flagged, not moved (no counterpart).

## The accountant's answers (2026-10-20, migration `2026_10_20_000001`)

- A class on the wrong side goes to its counterpart, as side pairs (`config/classification.php`): مدينون ↔ دائنون متنوعون, أرصدة مدينة ↔ دائنة أخرى, إيرادات مستحقة ↔ مؤجلة, سلف الموردين (credit) → موردون, ضرائب مستحقة (debit) → حسابات مدينة لدى الجهات الحكومية, تأمينات لدى الغير (credit) → أرصدة دائنة أخرى, رواتب وأجور مستحقة (debit) → سلف العاملين (the rule «رواتب مستحقة (عام)» too, no review).
- Flagged only: a debit income-tax provision (as zakat), and every other class with no counterpart (inventory, fixed assets and their accumulated depreciation, loans, provisions, capital, reserves).
- Other assets: their own line (done 2026-10-19).
- The partners' current account: a related party. It stays its own class, on the liabilities line `sofp.partners_current_liability` (the report's default), so the result cleared to the partners still shows in the equity and cash-flow statements.
- A company's creditors are classified by their account number: mark the code group (e.g. a creditors' code group marked «مورد»): a debit balance becomes «دفعات مقدمة للموردين».

## In a run

`ClassificationService::applyRules`, after the AI, the cache and the side pairs, once each account has its heading:
- A matched rule with a class for the side of the balance replaces the class (`classification_source: rule`, confidence 100, the reason names the rule and how it matched). The side pair's flip and the old class's abnormal-balance flags are replaced by the rule's (its class's abnormal flags, recomputed: an opening on the other side still shows).
- A rule with no class for that side leaves the class; `review_side` adds `rule_review` either way.
- A manual correction (`manual_correction`) wins over the rules.
- Every matched account carries `classification_rule`, whether or not its class changed. The file's info says what each rule classified, by class and side.
- Retained earnings (`classification.either_side_classes`) are never flagged abnormal: in debit they are accumulated losses.
- A class with no counterpart on the wrong side is not moved: its `abnormal_closing` flag asks for the review.
- An account a rule moved to the other side is not flagged by the family form of `prefix_mismatch`.
- Accounts with a zero closing balance take their side from the opening balance (`rowSide`). With no balance anywhere (the movement nets to zero), the side the file's own classification column points to (one of the rule's two classes under the main class it names), else the side of the rule's kind: the base side of the side pair holding its classes (an employee's account debit, a supplier credit), else the side of the class the account has.

## Not done yet

- In the statements, each year by its own balance (the comparative year by the opening balance).
- Runs stored before `heading` was added have none: the preview of them doesn't try heading words.
