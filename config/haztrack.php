<?php

return [
    /*
    |--------------------------------------------------------------------------
    | External backup tools (optional)
    |--------------------------------------------------------------------------
    |
    | MySQL/MariaDB backups use a pure-PHP dumper by default (no mysqldump binary).
    | Set MYSQLDUMP_PATH to a mysqldump executable for faster native dumps when available.
    |
    */
    'mysqldump_path' => env('MYSQLDUMP_PATH', ''),

    /**
     * Optional extra arguments for mysqldump (after --defaults-extra-file), e.g. ["--column-statistics=0"] for MariaDB.
     * JSON array in .env: MYSQLDUMP_EXTRA_ARGS='["--column-statistics=0"]'
     */
    'mysqldump_extra_args' => json_decode((string) env('MYSQLDUMP_EXTRA_ARGS', ''), true) ?: [],

    'sqlite3_path' => env('SQLITE3_PATH', 'sqlite3'),

    'backup_timeout_seconds' => (int) env('BACKUP_TIMEOUT_SECONDS', 3600),
];
