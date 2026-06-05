<?php
require_once __DIR__ . '/../includes/db_config.php';

try {
    $pdo->exec("ALTER TABLE facebook_config ADD COLUMN page_access_token VARCHAR(255) NULL AFTER access_token");
    echo "Column added successfully.\n";
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "Column already exists.\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
