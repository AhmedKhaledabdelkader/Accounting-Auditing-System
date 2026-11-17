# Word lists

Known words for checking the wording read from a PDF of last year's statements (`App\Services\Statements\PriorPdf\Vocabulary::general`).
A word found here is never flagged as unknown; it is never a correction's target either (only the trial balance, the templates,
the taxonomy and the known words are).

- `ar-common.txt`: Arabic words seen at least 20 times in the OpenSubtitles 2018 Arabic frequency list of
  [FrequencyWords](https://github.com/hermitdave/FrequencyWords) by Hermit Dave (content licensed
  [CC BY-SA 4.0](https://creativecommons.org/licenses/by-sa/4.0/)), Arabic letters only, normalised as `Wording::normalised`
  does, one per line, sorted. This file is an adaptation of that list and is shared under the same licence.
  Its licence and attribution: [LICENSE-ar-common.txt](LICENSE-ar-common.txt). It stays a separate data file, read at
  runtime (`Vocabulary::general`), never merged into code or another list. No comparable list under a permissive licence
  (public domain, CC0, MIT) was found (2026-10-24): the MIT-licensed lists are either stop words only or harvested from
  classical books with merged and misspelled words, which would hide real reading slips here.
- `ar-accounting.txt`: general accounting and zakat words the common list lacks, as printed (normalised when loaded).
