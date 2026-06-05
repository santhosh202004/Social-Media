<?php
require_once __DIR__ . '/../includes/db_config.php';

try {
    echo "--- Table description for 'leads' ---\n";
    $stmt = $pdo->query("DESCRIBE leads");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        printf("%-15s | %-15s | %-5s | %-3s | %-10s\n", 
            $row['Field'], 
            $row['Type'], 
            $row['Null'], 
            $row['Key'], 
            $row['Default'] ?? 'NULL'
        );
    }

    echo "\n--- Table indices for 'leads' ---\n";
    $stmt = $pdo->query("SHOW INDEX FROM leads");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        printf("Index Name: %-15s | Column Name: %-15s | Unique: %d\n",
            $row['Key_name'],
            $row['Column_name'],
            !$row['Non_unique']
        );
    }
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
