<?php
/**
 * Database connection (PDO) + global app settings.
 * Adjust DB_* constants to match your XAMPP / MySQL setup.
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'lost_and_found');
define('DB_USER', 'root');
define('DB_PASS', '');

define('SITE_NAME', 'HCCD Lost and Found');
define('SCHOOL_EMAIL_DOMAIN', '@holychild.edu.ph');
define('BASE_URL', 'http://localhost/lost-and-found'); // no trailing slash

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );

// Keep MySQL's clock in sync with PHP's, so "today" (e.g. the Messages Today
// counter) means the same day everywhere — even on hosts where MySQL runs in UTC.
$pdo->exec("SET time_zone = '" . (new DateTime('now'))->format('P') . "'");
} catch (PDOException $e) {
    die('Database connection failed: ' . $e->getMessage());
}
