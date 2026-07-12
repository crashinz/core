<?php
// Copy to includes/config.php or let setup.php generate it.

const CHATSPACE_DB_DRIVER = 'sqlite';
// setup.php generates a unique filename, for example:
const CHATSPACE_SQLITE_PATH = __DIR__ . '/../db/chatspace-00000000-0000-4000-8000-000000000000.sqlite';

// MySQL / MariaDB example:
// const CHATSPACE_DB_DRIVER = 'mysql';
// const CHATSPACE_DB_HOST = '127.0.0.1';
// const CHATSPACE_DB_PORT = 3306;
// const CHATSPACE_DB_NAME = 'chatspace_ce';
// const CHATSPACE_DB_USER = 'chatspace';
// const CHATSPACE_DB_PASS = '';

// Optional private Inner-Tranquillity imported-room player capability.
// Shared/public installations should leave this disabled.
const CHATSPACE_INNER_TRANQUILLITY_PLAYER_ENABLED = false;
const CHATSPACE_INNER_TRANQUILLITY_PLAYER_ASSET_BASE = '/player';
const CHATSPACE_INNER_TRANQUILLITY_PLAYER_RUNTIME_HOSTS = [];

// Optional bounded runtime diagnostics. Shared/public installations should
// leave diagnostics and verification controls disabled.
const CHATSPACE_RUNTIME_DIAGNOSTICS_ENABLED = false;
const CHATSPACE_RUNTIME_DIAGNOSTICS_MODE = 'standard';
const CHATSPACE_RUNTIME_VERIFICATION_CONTROLS_ENABLED = false;
