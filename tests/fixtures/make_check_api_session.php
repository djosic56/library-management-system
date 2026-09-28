<?php
/**
 * Fixture for CheckApiTest: writes a real PHP session file (default "php" serialize handler) that
 * config.php's session_start() will accept under session.use_strict_mode, so HTTP tests can drive
 * check_api.php / pdf_api.php as a logged-in admin or non-admin user without any auth bypass in the
 * application itself. Runs as a separate CLI process (same PHP_BINARY, no special -d flags) so the
 * generated session id uses the exact same charset/length the built-in test server will accept.
 *
 * Usage: php make_check_api_session.php <sessionSavePath> <userId> <level> <csrfToken>
 * Prints the session id (PHPSESSID) on stdout.
 */

[$script, $savePath, $userId, $level, $csrf] = $argv;

session_save_path($savePath);
session_start();
$_SESSION['user_id'] = (int) $userId;
$_SESSION['level'] = (int) $level;
$_SESSION['csrf_token'] = $csrf;
$sid = session_id();
session_write_close();

echo $sid;
