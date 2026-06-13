<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$user = getCurrentUser();
$error = '';
$success = '';

// Update shift schedule
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_schedule'])) {
    $shift_ids = $_POST['shift_id'];
    $shift_names = $_POST['shift_name'];
    $start_times = $_POST['start_time'];
    $end_times = $_POST['end_time'];
    $is_active = isset($_POST['is_active']) ? $_POST['is_active'] : [];
    
    try {
        for($i = 0; $i < count($shift_ids); $i++) {
            $active = in_array($shift_ids[$i], $is_active) ? 1 : 0;
            $stmt = $pdo->prepare("UPDATE shift_schedule SET shift_name = ?, start_time = ?, end_time = ?, is_active = ? WHERE id = ?");
            $stmt->execute([$shift_names[$i], $start_times[$i], $end_times[$i], $active, $shift_ids[$i]]);
        }
        $success = "Shift schedule updated successfully!";
    } catch(Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Add new shift
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_shift'])) {
    $shift_name = $_POST['shift_name'];
    $start_time = $_POST['start_time'];
    $end_time = $_POST['end_time'];
    
    $stmt = $pdo->prepare("INSERT INTO shift_schedule (shift_name, start_time, end_time, is_active) VALUES (?, ?, ?, 1)");
    if($stmt->execute([$shift_name, $start_time, $end_time])) {
        $success = "Shift added successfully!";
    } else {
        $error = "Failed to add shift";
    }
}

// Delete shift
if(isset($_GET['delete_id'])) {
    $stmt = $pdo->prepare("DELETE FROM shift_schedule WHERE id = ?");
    if($stmt->execute([$_GET['delete_id']])) {
        $success = "Shift deleted successfully!";
    }
}

// Get all shifts
$shifts = $pdo->query("SELECT * FROM shift_schedule ORDER BY start_time")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shift Schedule Settings</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .stats-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .time-display {
            font-family: monospace;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-clock"></i> Shift Schedule Settings</h2>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addShiftModal">
                    <i class="fas fa-plus"></i> Add New Shift
                </button>
            </div>
            
            <?php if($error): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>
            <?php if($success): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
            <?php endif; ?>
            
            <!-- Current Time Display -->
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="stats-card">
                        <i class="fas fa-clock fa-2x"></i>
                        <h3 id="currentTime">--:--:--</h3>
                        <p>Current Time</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stats-card" id="currentShiftCard" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                        <i class="fas fa-calendar-alt fa-2x"></i>
                        <h3 id="currentShift">--</h3>
                        <p>Current Shift</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stats-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                        <i class="fas fa-chart-line fa-2x"></i>
                        <h3><?php echo count($shifts); ?></h3>
                        <p>Total Shifts</p>
                    </div>
                </div>
            </div>
            
            <!-- Shift Schedule Table -->
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5><i class="fas fa-list"></i> Shift Schedule Configuration</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Shift Name</th>
                                        <th>Start Time</th>
                                        <th>End Time</th>
                                        <th>Active</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($shifts as $shift): ?>
                                    <tr>
                                        <td>
                                            <input type="hidden" name="shift_id[]" value="<?php echo $shift['id']; ?>">
                                            <input type="text" name="shift_name[]" class="form-control" value="<?php echo $shift['shift_name']; ?>" required>
                                        </td>
                                        <td>
                                            <input type="time" name="start_time[]" class="form-control" value="<?php echo $shift['start_time']; ?>" required>
                                        </td>
                                        <td>
                                            <input type="time" name="end_time[]" class="form-control" value="<?php echo $shift['end_time']; ?>" required>
                                        </td>
                                        <td>
                                            <input type="checkbox" name="is_active[]" value="<?php echo $shift['id']; ?>" <?php echo $shift['is_active'] ? 'checked' : ''; ?>>
                                        </td>
                                        <td>
                                            <a href="?delete_id=<?php echo $shift['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this shift?')">
                                                <i class="fas fa-trash"></i> Delete
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                             </div>
                        </div>
                        <button type="submit" name="update_schedule" class="btn btn-primary mt-3">
                            <i class="fas fa-save"></i> Save Schedule
                        </button>
                    </form>
                </div>
            </div>
            
            <!-- Shift Information -->
            <div class="card mt-3">
                <div class="card-header bg-info text-white">
                    <h5><i class="fas fa-info-circle"></i> Shift Information</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="alert alert-success">
                                <strong><i class="fas fa-sun"></i> Morning Shift</strong><br>
                                8:01 AM - 4:00 PM
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="alert alert-warning">
                                <strong><i class="fas fa-moon"></i> Evening Shift</strong><br>
                                4:01 PM - 12:00 AM
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="alert alert-secondary">
                                <strong><i class="fas fa-star-of-life"></i> Night Shift</strong><br>
                                12:01 AM - 8:00 AM
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Add Shift Modal -->
    <div class="modal fade" id="addShiftModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5><i class="fas fa-plus"></i> Add New Shift</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label>Shift Name</label>
                            <input type="text" name="shift_name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label>Start Time</label>
                            <input type="time" name="start_time" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label>End Time</label>
                            <input type="time" name="end_time" class="form-control" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_shift" class="btn btn-primary">Add Shift</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        // Update current time and shift every second
        function updateCurrentTime() {
            const now = new Date();
            const hours = now.getHours().toString().padStart(2, '0');
            const minutes = now.getMinutes().toString().padStart(2, '0');
            const seconds = now.getSeconds().toString().padStart(2, '0');
            const currentTime = `${hours}:${minutes}:${seconds}`;
            document.getElementById('currentTime').innerText = currentTime;
            
            // Determine current shift based on time
            const currentHour = now.getHours();
            const currentMinute = now.getMinutes();
            const currentTimeValue = currentHour * 60 + currentMinute;
            
            // Get shifts from PHP
            const shifts = <?php echo json_encode($shifts); ?>;
            
            let currentShiftName = 'No Shift';
            let shiftColor = '#6c757d';
            
            for(let shift of shifts) {
                if(!shift.is_active) continue;
                
                let startParts = shift.start_time.split(':');
                let endParts = shift.end_time.split(':');
                
                let startMinutes = parseInt(startParts[0]) * 60 + parseInt(startParts[1]);
                let endMinutes = parseInt(endParts[0]) * 60 + parseInt(endParts[1]);
                
                // Handle overnight shifts (end time < start time)
                if(endMinutes < startMinutes) {
                    if(currentTimeValue >= startMinutes || currentTimeValue <= endMinutes) {
                        currentShiftName = shift.shift_name;
                        shiftColor = shift.shift_name == 'Morning' ? '#28a745' : (shift.shift_name == 'Evening' ? '#ffc107' : '#17a2b8');
                        break;
                    }
                } else {
                    if(currentTimeValue >= startMinutes && currentTimeValue <= endMinutes) {
                        currentShiftName = shift.shift_name;
                        shiftColor = shift.shift_name == 'Morning' ? '#28a745' : (shift.shift_name == 'Evening' ? '#ffc107' : '#17a2b8');
                        break;
                    }
                }
            }
            
            document.getElementById('currentShift').innerText = currentShiftName;
            document.getElementById('currentShiftCard').style.background = `linear-gradient(135deg, ${shiftColor} 0%, ${shiftColor}cc 100%)`;
        }
        
        setInterval(updateCurrentTime, 1000);
        updateCurrentTime();
    </script>
</body>
</html>