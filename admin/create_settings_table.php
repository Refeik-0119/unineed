<?php
require_once '../config/database.php';

$query = 'CREATE TABLE IF NOT EXISTS settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    setting_key VARCHAR(50) UNIQUE,
    setting_value TEXT
)';
if (mysqli_query($conn, $query)) {
    echo 'Settings table created successfully.';
} else {
    echo 'Error creating table: ' . mysqli_error($conn);
}
?>