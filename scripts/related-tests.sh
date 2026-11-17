#!/usr/bin/env bash
# The tests run in every phase (the brief: related tests only, the full suite at the end), always with the cross-cutting
# guards: the pages' words (FinancialReportPagesTest), one name per statement (StatementNamesTest), a title for every
# check (CheckTitlesTest), no array literal repeating a key (ArrayKeysTest), the API contract
# (ApiContractTest, OpenApiSpecTest). Extra arguments add test files.
set -euo pipefail
cd "$(dirname "$0")/.."
php artisan test tests/Feature/FinancialReportPagesTest.php tests/Unit/StatementNamesTest.php tests/Unit/CheckTitlesTest.php tests/Unit/ArrayKeysTest.php tests/Feature/ApiContractTest.php tests/Feature/OpenApiSpecTest.php "$@"
