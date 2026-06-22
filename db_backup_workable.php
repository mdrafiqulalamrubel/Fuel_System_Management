<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

// Check if user has admin access
$user = getCurrentUser();
if(!in_array($user['role'], ['super_admin', 'owner', 'admin'])) {
    die("Access denied! Only Super Admin and Owner can access this page.");
}

$error = '';
$success = '';
$backup_files = [];

// =============================================
// BACKUP DIRECTORY
// =============================================
$backup_dir = __DIR__ . '/backups/';

if(!is_dir($backup_dir)) {
    mkdir($backup_dir, 0777, true);
}
chmod($backup_dir, 0777);

// =============================================
// GET BACKUP FILES LIST - FIXED
// =============================================
if(is_dir($backup_dir)) {
    // Get all files
    $all_files = scandir($backup_dir);
    $backup_files = array_diff($all_files, array('.', '..'));
    
    // Filter only SQL, GZ, ZIP files
    $backup_files = array_values(array_filter($backup_files, function($file) {
        return preg_match('/\.(sql|gz|zip)$/i', $file);
    }));
    
    // Sort by newest first
    usort($backup_files, function($a, $b) use ($backup_dir) {
        $time_a = filemtime($backup_dir . $a);
        $time_b = filemtime($backup_dir . $b);
        return $time_b - $time_a;
    });
}

// =============================================
// CREATE BACKUP
// =============================================
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_backup'])) {
    $backup_name = isset($_POST['backup_name']) ? trim($_POST['backup_name']) : '';
    $backup_type = isset($_POST['backup_type']) ? $_POST['backup_type'] : 'full';
    $compression = isset($_POST['compression']) ? $_POST['compression'] : 'none';
    
    if(empty($backup_name)) {
        $backup_name = 'backup_' . date('Y-m-d_H-i-s');
    }
    
    $backup_name = preg_replace('/[^a-zA-Z0-9_\-]/', '', $backup_name);
    
    try {
        // Get database name
        $stmt = $pdo->query("SELECT DATABASE()");
        $db_name = $stmt->fetchColumn();
        
        if(!$db_name) {
            throw new Exception("Could not detect database name.");
        }
        
        // Get all tables
        $stmt = $pdo->query("SHOW TABLES");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if(empty($tables)) {
            throw new Exception("No tables found in database.");
        }
        
        // =============================================
        // BUILD SQL CONTENT
        // =============================================
        $sql_content = "-- Database Backup\n";
        $sql_content .= "-- Database: " . $db_name . "\n";
        $sql_content .= "-- Date: " . date('Y-m-d H:i:s') . "\n\n";
        $sql_content .= "SET FOREIGN_KEY_CHECKS=0;\n\n";
        
        // Structure
        if($backup_type == 'full' || $backup_type == 'structure') {
            foreach($tables as $table) {
                $sql_content .= "-- Table: " . $table . "\n";
                
                $stmt = $pdo->query("SHOW CREATE TABLE `$table`");
                $table_create = $stmt->fetch(PDO::FETCH_ASSOC);
                if($table_create && isset($table_create['Create Table'])) {
                    $sql_content .= $table_create['Create Table'] . ";\n\n";
                }
            }
        }
        
        // Data
        if($backup_type == 'full' || $backup_type == 'data') {
            foreach($tables as $table) {
                $sql_content .= "-- Dumping data for table `$table`\n";
                
                $stmt = $pdo->query("SELECT * FROM `$table`");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if(count($rows) > 0) {
                    $sql_content .= "INSERT INTO `$table` VALUES\n";
                    
                    $values = [];
                    foreach($rows as $row) {
                        $row_values = [];
                        foreach($row as $col => $val) {
                            if($val === null) {
                                $row_values[] = 'NULL';
                            } else {
                                $row_values[] = "'" . addslashes($val) . "'";
                            }
                        }
                        $values[] = "(" . implode(", ", $row_values) . ")";
                    }
                    $sql_content .= implode(",\n", $values) . ";\n\n";
                } else {
                    $sql_content .= "-- No data found\n\n";
                }
            }
        }
        
        $sql_content .= "SET FOREIGN_KEY_CHECKS=1;\n";
        
        // =============================================
        // SAVE FILE
        // =============================================
        $filename = $backup_name . '.sql';
        $filepath = $backup_dir . $filename;
        
        $bytes_written = file_put_contents($filepath, $sql_content);
        
        if($bytes_written === false) {
            throw new Exception("Failed to write backup file.");
        }
        
        chmod($filepath, 0666);
        
        // =============================================
        // COMPRESSION
        // =============================================
        if($compression == 'gzip') {
            $gz_filepath = $filepath . '.gz';
            $gz_data = gzencode($sql_content, 9);
            if(file_put_contents($gz_filepath, $gz_data) !== false) {
                unlink($filepath);
                $filename = $filename . '.gz';
                $filepath = $gz_filepath;
                chmod($filepath, 0666);
            }
        } elseif($compression == 'zip') {
            if(class_exists('ZipArchive')) {
                $zip = new ZipArchive();
                $zip_filepath = $filepath . '.zip';
                if($zip->open($zip_filepath, ZipArchive::CREATE) === TRUE) {
                    $zip->addFile($filepath, $filename);
                    $zip->close();
                    unlink($filepath);
                    $filename = $filename . '.zip';
                    $filepath = $zip_filepath;
                    chmod($filepath, 0666);
                }
            }
        }
        
        // =============================================
        // SUCCESS
        // =============================================
        $success = "✅ Backup created successfully!<br>";
        $success .= "📁 File: <strong>" . $filename . "</strong><br>";
        $success .= "📍 Path: <code>" . $filepath . "</code><br>";
        $success .= "📊 Size: " . formatSizeUnits(filesize($filepath)) . "<br>";
        $success .= "📋 Tables: " . count($tables);
        $success .= "<br><br>";
        $success .= '<div class="alert alert-success">';
        $success .= '<strong>⬇️ Click the button below to download and save to your PC:</strong><br><br>';
        $success .= '<a href="?download=' . urlencode($filename) . '" class="btn btn-success btn-lg">';
        $success .= '<i class="fas fa-download"></i> Download ' . $filename . ' (Save to your PC)';
        $success .= '</a>';
        $success .= '<br><br><small class="text-muted">When you click Download, your browser will ask <strong>"Where do you want to save this file?"</strong> - Choose ANY folder on your PC.</small>';
        $success .= '</div>';
        
        // Refresh backup list
        if(is_dir($backup_dir)) {
            $all_files = scandir($backup_dir);
            $backup_files = array_diff($all_files, array('.', '..'));
            $backup_files = array_values(array_filter($backup_files, function($file) {
                return preg_match('/\.(sql|gz|zip)$/i', $file);
            }));
            usort($backup_files, function($a, $b) use ($backup_dir) {
                $time_a = filemtime($backup_dir . $a);
                $time_b = filemtime($backup_dir . $b);
                return $time_b - $time_a;
            });
        }
        
    } catch(Exception $e) {
        $error = "❌ Backup failed: " . $e->getMessage();
    }
}

// =============================================
// DOWNLOAD BACKUP - User chooses folder location
// =============================================
if(isset($_GET['download'])) {
    $file = basename($_GET['download']);
    $filepath = $backup_dir . $file;
    
    if(strpos($file, '..') !== false) {
        die("Invalid file name");
    }
    
    if(file_exists($filepath)) {
        // Force download - browser will show "Save As" dialog
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Content-Length: ' . filesize($filepath));
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Expires: 0');
        readfile($filepath);
        exit();
    } else {
        $error = "File not found!";
    }
}

// =============================================
// DELETE BACKUP
// =============================================
if(isset($_GET['delete'])) {
    $file = basename($_GET['delete']);
    $filepath = $backup_dir . $file;
    
    if(strpos($file, '..') !== false) {
        $error = "Invalid file name";
    } elseif(file_exists($filepath)) {
        if(unlink($filepath)) {
            $success = "🗑️ Backup deleted: " . $file;
        } else {
            $error = "Failed to delete file.";
        }
        // Refresh list
        if(is_dir($backup_dir)) {
            $all_files = scandir($backup_dir);
            $backup_files = array_diff($all_files, array('.', '..'));
            $backup_files = array_values(array_filter($backup_files, function($file) {
                return preg_match('/\.(sql|gz|zip)$/i', $file);
            }));
            usort($backup_files, function($a, $b) use ($backup_dir) {
                $time_a = filemtime($backup_dir . $a);
                $time_b = filemtime($backup_dir . $b);
                return $time_b - $time_a;
            });
        }
    } else {
        $error = "File not found!";
    }
}

// =============================================
// FUNCTIONS
// =============================================
function formatSizeUnits($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } elseif ($bytes > 1) {
        return $bytes . ' bytes';
    } elseif ($bytes == 1) {
        return '1 byte';
    } else {
        return '0 bytes';
    }
}

// Get database size
$db_size = 0;
try {
    $stmt = $pdo->query("
        SELECT SUM(data_length + index_length) AS size 
        FROM information_schema.tables 
        WHERE table_schema = DATABASE()
    ");
    $result = $stmt->fetch();
    $db_size = $result['size'] ?? 0;
} catch(Exception $e) {
    $db_size = 0;
}

// Get table count
$table_count = 0;
try {
    $stmt = $pdo->query("SHOW TABLES");
    $table_count = $stmt->rowCount();
} catch(Exception $e) {
    $table_count = 0;
}

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings['currency_symbol'] ?? 'BDT';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Backup System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
        .stats-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            transition: transform 0.3s;
        }
        .stats-card:hover { transform: translateY(-5px); }
        .stats-card i { font-size: 40px; opacity: 0.5; float: right; }
        
        .btn-download {
            background: #28a745;
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
            display: inline-block;
        }
        .btn-download:hover {
            background: #218838;
            color: white;
        }
        .btn-delete {
            background: #dc3545;
            color: white;
            border: none;
            padding: 6px 14px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 12px;
            text-decoration: none;
            display: inline-block;
        }
        .btn-delete:hover {
            background: #c82333;
            color: white;
        }
        
        .table-hover tbody tr:hover {
            background-color: #e8f0fe !important;
        }
        
        .badge-compression {
            background: #17a2b8;
            color: white;
        }
        .badge-type {
            background: #28a745;
            color: white;
        }
        
        .download-info {
            background: #d4edda;
            border-left: 4px solid #28a745;
            padding: 12px 15px;
            border-radius: 5px;
            margin: 10px 0;
        }
        .download-info i {
            color: #28a745;
        }
        
        .success-box {
            background: #d4edda;
            border: 2px solid #28a745;
            border-radius: 10px;
            padding: 20px;
            margin: 15px 0;
        }
        .success-box .btn {
            font-size: 18px;
            padding: 12px 30px;
        }
        
        /* Folder Selector Styles */
        .folder-selector {
            background: #f8f9fa;
            border: 2px dashed #dee2e6;
            border-radius: 10px;
            padding: 20px;
            margin: 10px 0;
            transition: all 0.3s;
        }
        .folder-selector:hover {
            border-color: #667eea;
            background: #f0f4ff;
        }
        .folder-selector .folder-path {
            background: #fff;
            border: 1px solid #ced4da;
            border-radius: 5px;
            padding: 10px 15px;
            font-family: 'Courier New', monospace;
            font-size: 14px;
            word-break: break-all;
            margin: 10px 0;
        }
        
        @media print {
            .sidebar, .no-print, .btn, .stats-card, form {
                display: none !important;
            }
            .main-content { margin: 0 !important; padding: 10px !important; }
        }
    </style>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-database"></i> Database Backup System</h2>
                <div>
                    <a href="dashboard.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                </div>
            </div>
            
            <?php if($error): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <!-- Display Success with Download Button -->
            <?php if($success): ?>
                <div class="alert alert-success">
                    <?php echo $success; ?>
                </div>
            <?php endif; ?>
            
            <!-- Stats -->
            <div class="row">
                <div class="col-md-3">
                    <div class="stats-card">
                        <i class="fas fa-table"></i>
                        <h3><?php echo $table_count; ?></h3>
                        <p>Total Tables</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                        <i class="fas fa-database"></i>
                        <h3><?php echo formatSizeUnits($db_size); ?></h3>
                        <p>Database Size</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                        <i class="fas fa-file-archive"></i>
                        <h3><?php echo count($backup_files); ?></h3>
                        <p>Backup Files</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                        <i class="fas fa-clock"></i>
                        <h3><?php echo date('d-m-Y H:i'); ?></h3>
                        <p>Last Update</p>
                    </div>
                </div>
            </div>
            
            <!-- Folder Selection Info -->
            <div class="folder-selector">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <h6><i class="fas fa-folder-open text-primary"></i> Choose Where to Save Your Backup</h6>
                        <p class="text-muted small mb-2">
                            When you click <strong>"Download"</strong>, your browser will open the 
                            <strong>"Save As"</strong> dialog. You can navigate to <strong>ANY FOLDER</strong> 
                            on your PC (C:\, D:\, F:\, etc.) and save the backup file there.
                        </p>
                        <div class="alert alert-info mb-0">
                            <i class="fas fa-info-circle"></i>
                            <strong>Tip:</strong> Create a dedicated folder like 
                            <code>F:\Backups\</code>, <code>C:\Backups\FuelStation\</code>, or 
                            <code>D:\Database Backups\</code> to keep your backups organized.
                        </div>
                    </div>
                    <div class="col-md-4 text-center">
                        <i class="fas fa-folder-open fa-4x text-warning"></i>
                        <br>
                        <span class="badge bg-success mt-2">You Choose the Location!</span>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <!-- Create Backup Form -->
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header bg-primary text-white">
                            <h5><i class="fas fa-plus-circle"></i> Create Backup</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <div class="mb-3">
                                    <label><i class="fas fa-tag"></i> Backup Name</label>
                                    <input type="text" name="backup_name" class="form-control" placeholder="backup_2024-01-15_10-30" value="backup_<?php echo date('Y-m-d_H-i-s'); ?>">
                                    <small class="text-muted">Leave empty for auto-name</small>
                                </div>
                                
                                <div class="mb-3">
                                    <label><i class="fas fa-cog"></i> Backup Type</label>
                                    <select name="backup_type" class="form-control">
                                        <option value="full">Full Backup (Structure + Data)</option>
                                        <option value="structure">Structure Only</option>
                                        <option value="data">Data Only</option>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label><i class="fas fa-file-archive"></i> Compression</label>
                                    <select name="compression" class="form-control">
                                        <option value="none">SQL File DB</option>                                        
                                    </select>
                                </div>
                                
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle"></i>
                                    <strong>Save Location:</strong> <br>
                                    You will be asked to choose a folder when you download.
                                    <br><small class="text-muted">Click "Download" after backup creation to select your folder.</small>
                                </div>
                                
                                <button type="submit" name="create_backup" class="btn btn-success w-100" id="backupBtn">
                                    <i class="fas fa-database"></i> Create Backup
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Backup Files List -->
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header bg-info text-white">
                            <h5><i class="fas fa-list"></i> Backup Files</h5>
                            <small class="d-block text-light">Click <i class="fas fa-download"></i> to download and choose where to save</small>
                        </div>
                        <div class="card-body">
                            <?php if(empty($backup_files)): ?>
                                <div class="text-center text-muted py-4">
                                    <i class="fas fa-database fa-3x mb-3"></i>
                                    <p>No backup files found.</p>
                                    <p><small>Click "Create Backup" to generate your first backup.</small></p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-bordered table-hover">
                                        <thead class="table-dark">
                                            <tr>
                                                <th>#</th>
                                                <th>File Name</th>
                                                <th>Size</th>
                                                <th>Date</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $i = 1;
                                            $total_size = 0;
                                            foreach($backup_files as $file): 
                                                $filepath = $backup_dir . $file;
                                                if(!file_exists($filepath)) continue;
                                                $file_size = filesize($filepath);
                                                $total_size += $file_size;
                                                $file_date = filemtime($filepath);
                                                $ext = pathinfo($file, PATHINFO_EXTENSION);
                                            ?>
                                            <tr>
                                                <td><?php echo $i++; ?></td>
                                                <td>
                                                    <i class="fas fa-file-code text-primary"></i>
                                                    <?php echo $file; ?>
                                                    <?php if($ext == 'gz'): ?>
                                                        <span class="badge badge-compression">GZIP</span>
                                                    <?php elseif($ext == 'zip'): ?>
                                                        <span class="badge badge-compression">ZIP</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo formatSizeUnits($file_size); ?></td>
                                                <td><?php echo date('d-m-Y H:i:s', $file_date); ?></td>
                                                <td>
                                                    <a href="?download=<?php echo urlencode($file); ?>" class="btn btn-download" title="Download - Choose where to save on your PC">
                                                        <i class="fas fa-download"></i> Download
                                                    </a>
                                                    <a href="?delete=<?php echo urlencode($file); ?>" class="btn btn-delete" onclick="return confirm('Delete this backup file?')" title="Delete">
                                                        <i class="fas fa-trash"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                        <tfoot class="table-light">
                                            <tr class="fw-bold">
                                                <td colspan="2" class="text-end">TOTAL:</td>
                                                <td><?php echo formatSizeUnits($total_size); ?></td>
                                                <td colspan="2"></td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                                
                                <!-- Download Instructions -->
                                <div class="alert alert-success mt-3">
                                    <i class="fas fa-arrow-down"></i>
                                    <strong>How to save to your preferred folder:</strong>
                                    <ol class="mb-0 small">
                                        <li>Click the <strong>"Download"</strong> button next to your backup file</li>
                                        <li>Your browser will open the <strong>"Save As"</strong> dialog</li>
                                        <li>Navigate to <strong>ANY FOLDER</strong> on your PC (C:\, D:\, F:\, etc.)</li>
                                        <li>Click <strong>Save</strong> - the file is saved to your chosen location!</li>
                                    </ol>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Instructions -->
            <div class="card mt-4">
                <div class="card-header bg-dark text-white">
                    <h5><i class="fas fa-info-circle"></i> How to Backup & Choose Your Folder</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3">
                            <div class="text-center">
                                <i class="fas fa-database fa-3x text-primary"></i>
                                <h6 class="mt-2">Step 1: Create Backup</h6>
                                <p class="text-muted">Click <strong>"Create Backup"</strong> to generate the backup file.</p>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-center">
                                <i class="fas fa-download fa-3x text-success"></i>
                                <h6 class="mt-2">Step 2: Click Download</h6>
                                <p class="text-muted">Click the <strong>"Download"</strong> button next to your backup.</p>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-center">
                                <i class="fas fa-folder-open fa-3x text-warning"></i>
                                <h6 class="mt-2">Step 3: Choose Your Folder</h6>
                                <p class="text-muted">The <strong>"Save As"</strong> dialog will open. Navigate to <strong>your preferred folder</strong>.</p>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-center">
                                <i class="fas fa-check-circle fa-3x text-success"></i>
                                <h6 class="mt-2">Step 4: Save</h6>
                                <p class="text-muted">Click <strong>Save</strong> - the file is now saved to your chosen location!</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row mt-3">
                        <div class="col-md-6">
                            <div class="alert alert-secondary">
                                <strong><i class="fas fa-folder"></i> You can save to ANY folder:</strong>
                                <ul class="mb-0">
                                    <li><code>F:\MyBackups\</code></li>
                                    <li><code>C:\Backups\FuelStation\</code></li>
                                    <li><code>D:\Database Backups\</code></li>
                                    <li><code>OneDrive\Backups\</code></li>
                                    <li><code>GoogleDrive\Backups\</code></li>
                                    <li><strong>ANY FOLDER YOU WANT!</strong></li>
                                </ul>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="alert alert-info">
                                <strong><i class="fas fa-lightbulb"></i> Best Practices:</strong>
                                <ul class="mb-0">
                                    <li>Create a dedicated <strong>Backups</strong> folder</li>
                                    <li>Organize by date: <code>Backups\2024\Jan\</code></li>
                                    <li>Keep at least <strong>3 months</strong> of backups</li>
                                    <li>Test your backups by restoring them</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    
                    <div class="alert alert-warning mt-3">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>Important:</strong>
                        <ul class="mb-0">
                            <li>You have FULL CONTROL over where to save your backup files.</li>
                            <li>When you click "Download", the browser asks: <strong>"Where do you want to save?"</strong></li>
                            <li>You can choose <strong>ANY FOLDER</strong> on your PC.</li>
                            <li>We recommend creating a dedicated <strong>Backups</strong> folder for easy organization.</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#backupForm').on('submit', function() {
                var btn = $('#backupForm button[type="submit"]');
                btn.html('<i class="fas fa-spinner fa-spin"></i> Creating Backup...');
                btn.prop('disabled', true);
                
                // Re-enable after 60 seconds
                setTimeout(function() {
                    btn.html('<i class="fas fa-database"></i> Create Backup');
                    btn.prop('disabled', false);
                }, 60000);
            });
        });
    </script>
</body>
</html>