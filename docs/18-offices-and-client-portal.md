# 18 · Offices and the client portal

An accounting office works on the site with its team and its clients. Each office's data is its own; a client sees only their company.

## Offices and sign-in

- An **office** (`offices`) has a **code** (2–20 lowercase letters, digits, dashes). Sign-in takes the **office code, username and password**. A username is unique *inside* its office (`users (office_id, username)`), so two offices can both have `sara`.
- A wrong code, username or password gets the same answer; a disabled office or account is told why. The sign-in link `…/login?office=<code>` fills the code in (it is what a client is sent).
- The migration made the first office, **`main`** (the *platform* office), and moved every existing user, company, report and classification run into it. Existing users sign in with code `main`.
- Sign-up (`/register`) asks for the office's code; the account waits for **that office's** admin. A client has no sign-up: the accountant makes their login.
- Make more offices from the app (platform office: **المكاتب**, `offices.manage`: name, code, first admin) or the command line:

```bash
php artisan office:create delta "مكتب دلتا"
php artisan users:create-admin dana --office=delta     # asks for the password
```

- Only the platform office may hold `roles.manage` and `offices.manage` (roles are shared by all offices): `User::PLATFORM_ONLY`. The classification memory, taxonomy and synonyms are shared knowledge too.

## What an office's data is

`companies`, `financial_reports` and `classification_runs` carry `office_id`; with `BelongsToOffice` a signed-in user sees and creates only their office's rows (a global scope). Another office's company, report or run is a **404**, on pages and on the API. Without a signed-in user (queue jobs, commands) nothing is filtered. The users page lists the office's own staff only (clients' logins are managed from the client's page).

## The main portal (البوابة الرئيسية) — `/clients`

For `clients.view`: the office's clients as cards (progress of the checklist for the client's latest year, documents waiting for review, new messages, whether the client has a login); the office code to give to the team and the clients. `clients.manage`:

- **New client** (`/clients/new`): the company (Arabic name, English name, CR number), the client's **username and password** (a generator suggests one; shown once after saving), and optionally the audit checklist for a year.
- **A client's page** (`/clients/{company}`): the checklist by section for a year; the client's login (new password, switch off/on, or make one for a company that has none); add a request of your own; apply the checklist again (lines already requested stay).
- On each request: files (client's and the office's), **accept**, **send back with what to change**, **not applicable**, **reopen**, **delete**, and its chat.

## The checklist

`config/audit_requirements.php` — the 80 lines of the audit engagement letter (متطلبات أعمال المراجعة) in 18 sections, each `[title, format, optional]`; `{year}` becomes the chosen year. Applying it to a client makes one `document_requests` row per line, numbered as in the letter. Edit the config to change what every new client is asked.

Request status: `pending` → (client uploads) `uploaded` → `accepted` or `returned` (with a note, which is also posted in the request's chat) → the client uploads again → `uploaded`. `not_applicable` can be set by either side (a client's «لا ينطبق»; an accepted document can't be declared).

## The client's portal — `/portal`

A client's user holds only `portal.use` (the role «عميل», `roles.is_client`; `users.company_id` ties the login to its company). They see their company's requests (what's asked of them first), upload files (several at once; `xlsx xls csv pdf doc docx jpg png zip txt`, up to `PORTAL_MAX_FILE_KB`, default 20 MB), say «not applicable», read the accountant's note on a returned document, and chat. Files are kept on the private disk and served only through `/portal/files/{id}` or `/clients/{company}/files/{id}`, after checking the company: a client can't reach another client's file, nor another office's.

## Chat

`client_messages`: the general thread of a client, or one request's. `public/js/portal/chat.js` polls `…/messages?request=&after=` every 4 s while the page is open and shows text with `textContent` only. What the other side wrote is marked read when the thread is shown; unread counts show on the menu, the client cards and each request's chat button. Uploads and reviews appear in the thread as grey event lines.

## Code

| What | Where |
|---|---|
| Models | `Office`, `DocumentRequest`, `ClientFile`, `ClientMessage`; trait `Concerns/BelongsToOffice` |
| Rules (status changes, uploads, unread, checklist) | `App\Services\Portal\Workspace` |
| Controllers | `Portal/ClientController` (office), `Portal/PortalController` (client), `Portal/WorkspaceController` (files + chat for both), `Admin/OfficeController` |
| Pages | `resources/views/portal/*`, `admin/offices`; `public/css/portal.css`, `public/js/portal/chat.js`; words in `lang/{ar,en}/portal.php` |
| Tests | `tests/Feature/ClientPortalTest.php` |

## Working papers and recalculations (2026-11-10)

Applying the checklist to a client also adds the **working papers** the client fills and sends back, so the accountant can review them against the contracts and documents behind the figures.

- **The papers** (`resources/working-papers`, listed in `config/working_papers.php`): the firm's 16 blank forms (sales cut-off, bank / customer / supplier confirmations, inventory, PPE additions and movement, depreciation and payroll analytics, investment property, equity method, prepaid expenses, equity, loans, unrecorded liabilities). Each becomes a request in the section «أوراق العمل»: the form is attached by the office, and what to fill is said in plain words, **read from the form itself** (`WorkingPaperReader::read`: title, purpose, entity and period fields, questions, test steps, the sample table's columns and how many samples). Any paper laid out like these is understood the same way.
- **The recalculations** (`config/recalculations.php`, `Recalculations`): end-of-service benefit (Labour Law arts. 84–85, resignation shares by length of service, the firm's 5-year boundary of 1826 days), depreciation (cost × rate × days since purchase, less the opening accumulated depreciation, capped at cost), prepaid expenses (contract value × days left after the period end ÷ contract days), the receivables allowance (balance by age band × the band's rate, set in the paper's header; plus a specific allowance) and payroll (net pay in the payroll against the payment file and the bank statement). The blank form is made from the definition (its formulas show the result in Excel too).
- **When the client sends a copy back** it is read and said so in the request's chat, and kept on the request (`document_requests.analysis`) for the accountant: for a paper, how far it is filled (entity fields, unanswered questions, samples used and incomplete, the paper's own differences and coverage, the conclusion); for a recalculation, the recalculation row by row against the books, the total against the trial balance figure typed in the paper (rows missing), and what is missing. No AI: the paper's formulas and the methods above do the arithmetic.
- **A request of the accountant's own**, outside the checklist and the papers, is added from the client's page (`requests.store`: section, title, format, optional) and works like any other.
- Not yet: the other three firm sheets (service benefit, fixed assets, rents) carry client data and were used only to check the methods; the equity, equity-method and payroll-analytics papers have no sample table to read (only their fields and questions); the receivables allowance rates are defaults to the accountant's judgement.

## The AI reviews what the client uploads (2026-11-10)

The accountant no longer reviews every file: when a client uploads, the AI does (`DocumentReviewer`, job `ReviewClientFile`, queued like the other AI jobs).

- **It recognises the document by the words printed on it** (a commercial registration says «سجل تجاري», a statement «كشف حساب»), and says which of the client's requests for that year it answers. It is given the list of the client's requests (`[id] title (section)`), the file (PDF and images go as they are, Excel/CSV/Word as text) and never the company's name. It copies what the document shows: its own title, the company, the registration number, the year, the dates (issue, expiry), the issuer, whether a signature or stamp is visible, the key totals, one summary sentence. It never decides.
- **PHP decides from that copy** (`judge`, pure): *ok* — the right document for this request; *other_request* — it answers one other request (the file is **moved** there and judged again); *mismatch* — it answers none or several; *unreadable*; or problems: another company (name matches neither of the client's, registration number isn't theirs), the wrong year (when the request names one), expired, unsigned where the request asks for an approved or signed copy.
- **What follows** (`portal.ai_review`, env `PORTAL_AI_REVIEW`): `auto` (default) accepts a request when all its files are fine and sends it back with the problems in its note (the client sees them) otherwise; `suggest` keeps the verdict and says it in the chat, changing nothing; `off` never asks the AI. The AI's lines in the chat carry its own name (kind `ai`) and count as unread for the other side. The accountant sees every verdict on each file (type, what it read, issues) and can reopen or accept anything.
- **Working papers and recalculations** are judged from their own analysis in the same way: entity fields missing, an empty paper, incomplete rows, unanswered questions, differences left unexplained, a total that doesn't tie to the trial balance → back to the client with exactly that; a complete paper without differences → accepted.
- **When the AI can't be asked** (a provider failure), the file stays in the accountant's tray as before and the client is told the accountant will review it.
- Files of a kind it can't read (zip) are left to the accountant. Cost: one request per file; an answer is kept by the file's content, so the same file is never asked twice.

## Picking what the client uploaded (2026-11-10)

The accountant no longer downloads a client's file to upload it again. Wherever a file is uploaded for a client there is a second way in: «اختار من الملفات اللي رفعها العميل».

- **Where:** the classifier page (the trial balance: pick the company, and its files appear under the drop area; the run is made with that company, so a report from it starts with the company chosen), the report wizard's prior-year step (last year's issued statements) and step 1 (the articles of association).
- **The list** (`GET /clients/{company}/files?purpose=trial_balance|prior_statements|articles`, `clients.view`, `ClientFileSource::forPurpose`): the client's uploads whose kind can serve the purpose (xlsx/xls/csv for a trial balance, xlsx/xls/pdf for last year's statements, pdf for the articles), the likeliest first: the request that names the purpose (e.g. «ميزان مراجعة»), then the AI reviewer's verdict (fine before remarks), the request accepted, the newest. The likeliest named one is `suggested` and outlined; the accountant confirms by clicking. Each row says its name, request and year, what the AI read it as, the date and the AI's verdict. A file the AI sent back stays listed, marked.
- **How it is used:** `client_file_id` in place of `file` on `POST /api/v1/classify/jobs` (and `/trial-balance`), `POST …/prior-year/statements-file` and `POST /companies/{id}/articles`. `ClientFileSource::attach` copies the file into the request as the uploaded `file`, so each endpoint's own validation and pipeline run unchanged (a pdf is still not a trial balance). The portal's file is untouched. A file that isn't there, isn't a client's, or belongs to another office is a 422.
- **The checklist** gained a last section, «مستندات إعداد القوائم المالية», with line 81: last year's issued statements (PDF or Excel), so there is a request to pick from. Added last so no existing number moves; «apply the checklist» adds it to clients that already have the 80.

## A client, its company and its registration (2026-11-11)

A client is the person or firm the office works for; its company is identified by its **commercial registration number**.

- **New client** (`/clients/new`): the client's name and email; the company's name in Arabic and in English; the fiscal year as a period (from day-and-month to day-and-month, the end follows the start by itself and must be exactly a year: `Company::isAYear`); the **commercial registration** (PDF or picture) and the **articles of association** (PDF); then the login and the checklist as before. Nothing is typed for the registration number.
- **The AI reads the registration** (`CommercialRegistrationReader`): it says whether the file is a commercial registration (by its words) and copies the number, the name, the activity, the city, the dates as printed; PHP checks it is a registration and the number is ten digits (an unreadable file, another kind of document or no number is refused with what to fix). The number becomes the company's `cr_number`; the activity, city, unified number and the issue dates (Gregorian and Hijri) fill the profile where empty; the file and what was read are kept on the company (`profile.registration`). A registration whose company name differs from the typed names is *said*, not refused.
- **The contract is read** with the same service as before (`ArticlesOfAssociation::store`): the partners, their nationality and shares. A failed reading keeps the client and says to upload the contract again.
- **One company, one client, per office:** the number is unique inside an office (`unique (office_id, cr_number)`; another office may have the same company). A number already held is refused, naming the client it belongs to; the same holds on the API (`POST` / `PATCH /companies`). The email is not unique (one person can own several companies); it is kept on the company and on the login where no other login has it.
- **The list** shows the client's name first and under it the company and its registration number; the client's page shows the client, the company, the registration, the email and the fiscal period, with links to the two files and «تعديل البيانات».
- **Editing** (`/clients/{company}/edit`, `clients.manage`): every detail, the registration number (to correct a misreading: ten digits, not another client's), and a new registration or contract (read again; the registration file's number wins over a typed one). The login's name and email follow. The login's password and switch stay on the client's page.
- Companies made before: their client's name is the login's name (else the company's), the fiscal start is the day after the end. Two companies of one office already sharing a number (listed by `php artisan clients:duplicates`) keep working; the unique index waits until one is fixed.
## Requests follow the fiscal period (2026-11-11)

Every request is for a period, the company's fiscal year: `document_requests.period_start` / `period_end` (`year` is the year the period ends in). The checklist is applied for the year a period *ends* in, and the period is worked out from the company's fiscal year (`Company::periodEndingIn`): for a 1 July – 30 June company, 2025 is 1 July 2024 to 30 June 2025. The titles say the period's end (`{end}` in `config/audit_requirements.php`: «30 يونيو 2025», no longer a fixed «31 ديسمبر»), the client's page and the client's portal show the period, the new-client form shows it as the year is chosen, and the AI reviewer accepts a document whose year the period spans (`DocumentReviewer::judge`). The pickers of the accountant's side prefer the files of the period the report is for. Earlier requests got the period their year and their company's fiscal year give.

## The third way in: one compressed file (2026-11-11)

Besides uploading each file to its request, the client (or the office for the client) can upload **one compressed file**: «ارفع كل ملفاتك في ملف واحد» on the client's portal and on the client's page (`portal.archive`, `clients.archive`; zip, rar, 7z, tar, gz up to `PORTAL_MAX_ARCHIVE_KB`, for a year the company has requests for).

- **Unpacking** (`ArchiveExtractor`): a zip by PHP, the rest by 7-Zip (`ARCHIVE_TOOL` or the first of `portal.archive_tools` found; without one a rar/7z fails with a plain message, a zip always works). Harmless by construction: sizes and counts are checked before anything is written (`portal.archive_max_unpacked_mb`, `archive_max_files`), the extraction is bounded while writing, a name with «..», an absolute path or a link is dropped, so are hidden and system files (`__MACOSX`, `.x`, `Thumbs.db`, `~$`), an archive inside, empty files and any kind the portal doesn't take; a password-protected archive is refused. A zip name PHP can't read as UTF-8 is read as Windows Arabic (CP1256).
- **Sorting** (`ArchiveSorter`, job `SortClientArchive`): the AI sees only the files' names and folders and the client's requests for the year, and names for each the request it answers (`confident` only when the name clearly says; ids it invents are ignored). PHP then puts each file in that request as the client's upload (the request is back in the accountant's tray) and says so in the request's chat, and a summary line in the general chat. A file the names don't place goes to a request of its own, «ملفات من ملف مضغوط (لسه من غير تصنيف)» (`paper = unsorted`, optional, never asked of the client, open only while it holds files), where the **content review** (the AI reviewer, reading each file) moves it to the request it answers when it can. The unsorted request is never accepted nor sent back; what stays in it waits for the accountant.
- **What the client sees** on the portal: each archive with its status (waiting, unpacking and sorting, sorted, failed), how many files went to their requests, how many are unsorted, what was skipped and why; a failure says what to do (a password, a rar the server can't open, too many files). The page refreshes while an archive is being worked on. Working papers inside an archive are read like ones uploaded to their request; the content reviews of all the files start when the sorting ends (the accountant's own upload is not reviewed).
- Verified with 7-Zip on a 7z and a tar of Arabic-named files, and the real AI placed the trial balance, the registration, a bank statement and a payroll by their names (a lease and a medical-insurance policy had no request, so they waited for the content review).

### The period is a date to a date, with their years (2026-11-11)

The client's fiscal period is typed as **two real dates** (`companies.period_start` / `period_end`, e.g. 1/7/2025 to 1/7/2026), not a day-and-month. The accountant types both dates by hand (nothing is proposed or filled in); the end must be after the start and the period no longer than 400 days. No separate year is asked: the checklist is for the period typed, its year being the year the period ends in. The requests of that year are for exactly those dates; the next year's are the same period a year on (`Company::periodEndingIn`). The company's recurring start and end (day and month) follow the dates, and a new report starts from the company's period. The API's Company shows `period_start` and `period_end`. (This replaces the earlier rule that the end had to be the day before the start.)

## A client's file: seven steps in a side menu (2026-11-11)

Opening a client shows a side menu with the steps of their file, in this order, each marked done or not (`ClientSteps`): **1** the client's details and period (the edit page), **2** the commercial registration (`/clients/{id}/registration`: what the AI read, its expiry, a new one to replace it), **3** the articles of association (`/articles`: the partners, nationality group, ownership, the Saudi/Gulf share that zakat uses, a new contract to replace it), **4** the trial balance, current and previous if any (`/trial-balance`: what the client uploaded under each period with the AI's verdict, «classify» on a picked file, the client's classifications and «prepare the financial statements» on a classified one), **5** last year's financial statements (`/prior-statements`: the client's files, and for each set of statements the links to its «previous year» and «opening balances» steps), **6** the financial statements (`/statements`: the client's statements, and new ones from a classified trial balance), **7** zakat (`/zakat`: the ownership shares and each statement's zakat step). Below the steps, «الطلبات والمحادثة» is the client's own page (requests, chat, compressed files). Replacing the registration or the articles needs `clients.manage`; every page needs `clients.view`; another office's client is a 404 and a client's own login cannot open them. The program's financial-statements document is called «القوائم المالية» throughout the interface, never «تقرير».

## Deleting a client (2026-11-11)

`DELETE /clients/{id}` (`clients.delete`; the client's name is typed to confirm; the button is at the bottom of the client's page) removes the client for good with everything of theirs (`ClientEraser`): the company and its profile, the login, every request, file, message and compressed file, the registration and the articles, the financial statements with their versions and the files they were made from, the trial balances uploaded for the company (unless another company's statements stand on one), and the files on the private disk with what the AI kept of them (by content). The classification memory, taxonomy and synonyms are shared knowledge and stay.

### The opening balances and zakat live in the client's file (2026-11-11)

When a set of financial statements has a company, the wizard no longer holds the steps «مطابقة الأرصدة الافتتاحية» and «الزكاة وضريبة الدخل»: they are steps of the client's file. Step 5 (`/clients/{id}/prior-statements`) shows the client's uploaded statements and, below them, the **opening-balances comparison** itself; step 7 (`/clients/{id}/zakat`) shows the owners' shares and the **zakat step** itself. Both are the wizard's own step modules running alone (`FinancialReportPagesController::embeddedConfig`: `steps: [step]`, `embedded: true`, no header or step bar) on the chosen statements (`?report=`, else the latest; a picker when the company has several). Every link to a step that is not in the page — from the side panel's checks, the «next»/«back» buttons — goes through `step_urls` (`ctx.go` in `wizard.js`): to the client's page for these two steps, back to the wizard for the others. Statements with no company keep all nine steps in the wizard.

### The main side menu (2026-11-11)

Sections and links are in `Navigation`, their names and the plain-words line under each in `lang/*/nav.php`: **العمل** (العملاء، بوابتي، ميزان المراجعة، القوائم المالية), **ضبط التصنيف** (ذاكرة التصنيف، الحسابات المحفوظة، قواعد التبويب، دليل التصنيفات، مرادفات بنود القوائم), **للمطورين** (الواجهة البرمجية), **الإدارة**. The pages carry the same names.

### Where the steps are (2026-11-11, the accountant's arrangement)

- **The client's side menu** (inside a client) holds only **1 بيانات العميل والفترة، 2 السجل التجاري، 3 عقد التأسيس**, and under them «الطلبات والمحادثة».
- **The main side menu** holds, under **العمل**: العملاء، بوابتي (a client's login), then **4 ميزان المراجعة، 5 القوائم المالية السابقة، 6 القوائم المالية، 7 الزكاة** (`/work/trial-balance`, `/work/prior-statements`, `/work/statements`, `/work/zakat`; `clients.view`). A work step is about one client: the one in `?client=`, else **the client opened last** (remembered in the session by `RememberClient`, named under the work section of the menu as «العميل الحالي»). With none, the step asks which client (a searchable list) and goes on to the same step for them. «تغيير العميل» (`?change=1`) forgets the choice. Another office's client is never taken.
- On a work step the page has a **bar naming the client** with the four steps as numbered chips (4 to 7, with ✓ when done), a link to the client's file and «تغيير العميل»; the same pages are also at `/clients/{id}/trial-balance` etc.
- The most specific link is the one lit in the menu (`Navigation::activeHref`). Whoever may not see clients keeps «ميزان المراجعة» (the classifier) and «القوائم المالية» (the list) as before. The classifier is reached from step 4 («رفع ميزان من جهازك») and the list of all statements from step 6.

### Requirements, working papers and recalculation: prepared by the AI (2026-11-12)

- The main side menu continues after zakat (**الزكاة الشرعية وضريبة الدخل**, step 7) with **8 المتطلبات** (what is asked of the client: the client's page of requests and chat, moved out of the client's own menu), **9 أوراق العمل** and **10 إعادة الاحتساب**. All are about the client opened last, like steps 4–7 (`/work/requirements`, `/work/working-papers`, `/work/recalculation`; the same pages are at `/clients/{id}/working-papers` and `/recalculation`).
- **Neither the client nor the accountant makes a paper.** The checklist no longer asks the client for the 16 working papers and 5 recalculations. The client only sends the documents (the requirements). `PaperMaker` (job `PreparePaper`, table `prepared_papers`) does the rest: for each paper it reads the client's uploads of the sections named in `config/paper_sources.php` (one AI call per document, kept by the file's hash), COPIES the rows the paper asks for and the books' figure from the trial balance (never calculates, never invents; what a document does not show is null and goes to the notes), writes them into the firm's form, and `Recalculations::analyse` / `WorkingPaperReader::analyse` recalculate and compare with the books in PHP.
- A paper with no document yet is «بانتظار مستندات العميل» (the AI is not asked) and links to the requirements. «إعداد جميع الأوراق» or one paper's button starts the AI on a queue worker (`php artisan queue:work`); the page shows the documents read so far and reloads when done. The prepared paper downloads as xlsx. Only `clients.manage` starts it; `clients.view` looks and downloads.
