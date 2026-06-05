<?php
$host = 'localhost';
$user = 'root';
$pass = ''; // Default XAMPP password is empty

try {
    $pdo = new PDO("mysql:host=$host", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Create Database
    $pdo->exec("CREATE DATABASE IF NOT EXISTS campaign_db");
    $pdo->exec("USE campaign_db");

    // Create/Update facebook_config Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS facebook_config (
        id INT AUTO_INCREMENT PRIMARY KEY,
        access_token TEXT NOT NULL,
        ad_account_id VARCHAR(255) NOT NULL,
        account_name VARCHAR(255) DEFAULT '',
        page_id VARCHAR(255) DEFAULT '',
        is_active TINYINT DEFAULT 1,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");

    // Create instagram_config Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS instagram_config (
        id INT AUTO_INCREMENT PRIMARY KEY,
        facebook_config_id INT NOT NULL,
        ig_user_id VARCHAR(255) NOT NULL,
        ig_username VARCHAR(255) DEFAULT '',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (facebook_config_id) REFERENCES facebook_config(id) ON DELETE CASCADE
    )");

    // Create metrics_history Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS metrics_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        account_id INT NOT NULL,
        metric_date DATE NOT NULL,
        platform ENUM('facebook', 'instagram') NOT NULL,
        followers INT DEFAULT 0,
        reach INT DEFAULT 0,
        impressions INT DEFAULT 0,
        profile_views INT DEFAULT 0,
        content_count INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_metric (account_id, metric_date, platform),
        FOREIGN KEY (account_id) REFERENCES facebook_config(id) ON DELETE CASCADE
    )");

    // Create leads Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS leads (
        id VARCHAR(255) PRIMARY KEY,
        campaign_id VARCHAR(255) NOT NULL,
        ad_id VARCHAR(255) DEFAULT '',
        ad_name VARCHAR(255) DEFAULT '',
        form_id VARCHAR(255) DEFAULT '',
        form_name VARCHAR(255) DEFAULT '',
        created_time DATETIME NOT NULL,
        field_data TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_campaign_id (campaign_id),
        INDEX idx_created_time (created_time),
        INDEX idx_campaign_created (campaign_id, created_time)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Add form_name column if it doesn't exist (for existing installations)
    try {
        $pdo->exec("ALTER TABLE leads ADD COLUMN form_name VARCHAR(255) DEFAULT '' AFTER form_id");
    } catch (PDOException $e) {
        // Column already exists, ignore
    }

    // Add synced_at column if it doesn't exist (for existing installations)
    try {
        $pdo->exec("ALTER TABLE leads ADD COLUMN synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
    } catch (PDOException $e) {
        // Column already exists, ignore
    }

    // Add idx_campaign_created index if it doesn't exist (for existing installations)
    try {
        $pdo->exec("CREATE INDEX idx_campaign_created ON leads (campaign_id, created_time)");
    } catch (PDOException $e) {
        // Index already exists, ignore
    }

    // Add page_id column if it doesn't exist (for existing installations)
    try {
        $pdo->exec("ALTER TABLE facebook_config ADD COLUMN page_id VARCHAR(255) DEFAULT '' AFTER account_name");
    } catch (PDOException $e) {
        // Column already exists, ignore
    }

    // Add account_name column if it doesn't exist (for existing installations)
    try {
        $pdo->exec("ALTER TABLE facebook_config ADD COLUMN account_name VARCHAR(255) DEFAULT '' AFTER ad_account_id");
    } catch (PDOException $e) {
        // Column already exists, ignore
    }

    // Add is_active column if it doesn't exist (for existing installations)
    try {
        $pdo->exec("ALTER TABLE facebook_config ADD COLUMN is_active TINYINT DEFAULT 1 AFTER page_id");
    } catch (PDOException $e) {
        // Column already exists, ignore
    }

    echo "Database and table created/updated successfully!";
} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}
?>
