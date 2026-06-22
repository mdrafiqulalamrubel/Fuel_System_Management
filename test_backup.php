<?php
$dir = __DIR__ . '/backups/';
if(!is_dir($dir)) {
    mkdir($dir, 0777, true);
}
$test_file = $dir . 'test.txt';
if(file_put_contents($test_file, 'Test write success')) {
    echo "✅ Write successful!";
    unlink($test_file);
} else {
    echo "❌ Write failed! Check permissions.";
}
?>