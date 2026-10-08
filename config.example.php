<?php
// Template: copy to config.php and replace the placeholder.
// config.php does NOT belong in the Git repository (see .gitignore).
//
// BOOTSTRAP_TOKEN protects /bootstrap: it can only be called with ?token=<this value>.
// Any random value, at least 16 characters, e.g. generated with:  openssl rand -hex 24
define('BOOTSTRAP_TOKEN', 'CHANGE_ME_TO_A_LONG_RANDOM_STRING');

// Optional: size limit per file in the media library, in MB (default 20). If the hoster's PHP settings
// (upload_max_filesize, post_max_size) are lower, their value applies - the media library shows the effective limit.
// define('MEDIA_MAX_MB', 20);

// Optional: System -> Schema backs up the complete database (+ .puml) to data/schema-backups/ before every "Apply".
// The last SCHEMA_BACKUP_KEEP backups are kept (default 5); from a database size of SCHEMA_BACKUP_WARN_MB (default 50)
// the page shows a note about the storage required.
// define('SCHEMA_BACKUP_KEEP', 5);
// define('SCHEMA_BACKUP_WARN_MB', 50);

// Optional: upper limit for the raw data of a list page (GET /api/{entity}) in bytes. The API answers larger pages
// with 413 instead of exhausting PHP memory. Default: one tenth of the free memory_limit, at most 16 MB.
// define('LIST_MAX_BYTES', 8 * 1024 * 1024);
