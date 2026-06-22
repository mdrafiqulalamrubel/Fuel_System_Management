<?php
$backup_dir = __DIR__ . '/backups/';
$test_file = $backup_dir . 'test_' . time() . '.txt';

if(file_put_contents($test_file, 'Test write')) {
    echo "✅ File created: " . $test_file;
} else {
    echo "❌ Failed to create file";
}