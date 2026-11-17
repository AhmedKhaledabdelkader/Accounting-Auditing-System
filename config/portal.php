<?php

/* The client portal: what a client (or the office) may upload to a document request. */
return [
    'max_file_kb' => (int) env('PORTAL_MAX_FILE_KB', 20480),
    // The AI reviews what a client uploads (DocumentReviewer): off | suggest (the verdict is kept and said, nothing changes) | auto
    // (accepts a good document, returns one with a problem and what to fix, moves one to the request it answers).
    'ai_review' => env('PORTAL_AI_REVIEW', 'auto'),
    'review_text_chars' => (int) env('PORTAL_REVIEW_TEXT_CHARS', 24000),
    // A compressed file with everything in it (ArchiveSorter): what it may hold, and the limits that keep an unpacked archive harmless.
    'archive_extensions' => ['zip', 'rar', '7z', 'tar', 'gz', 'tgz'],
    'max_archive_kb' => (int) env('PORTAL_MAX_ARCHIVE_KB', 204800),       // the server's upload_max_filesize / post_max_size must allow it too
    'archive_max_files' => (int) env('PORTAL_ARCHIVE_MAX_FILES', 150),
    'archive_max_unpacked_mb' => (int) env('PORTAL_ARCHIVE_MAX_UNPACKED_MB', 400),
    // The tool that unpacks anything but a zip (7-Zip reads rar, 7z, tar, gz): the first found; ARCHIVE_TOOL names one.
    'archive_tool' => env('ARCHIVE_TOOL'),
    'archive_tools' => ['7z', '7za', '7zz', 'C:\\Program Files\\7-Zip\\7z.exe', '/usr/bin/7z', '/usr/bin/7zz', '/usr/local/bin/7z'],
    'extensions' => ['xlsx', 'xls', 'csv', 'pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'zip', 'txt'],
];
