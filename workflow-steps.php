<?php
ini_set('display_errors', 1); 
error_reporting(E_ALL);

// workflow-steps.php
include("head.php");
require_once 'db_connection.php';

// ============================================================================
// HANDLE ACTIONS
// ============================================================================

$message = '';
$messageType = '';

// Add workflow step
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    if ($_POST['action'] === 'add') {
        $application_id = $_POST['application_id'];
        $step_name = $_POST['step_name'];
        $assigned_to = !empty($_POST['assigned_to']) ? $_POST['assigned_to'] : null;
        $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
        $status = $_POST['status'] ?? 'pending';
        $description = $_POST['description'] ?? null;
        
        try {
            executeQuery($conn, "
                INSERT INTO workflow_steps (
                    application_id, step_name, assigned_to, due_date, 
                    status, comments, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
            ", [$application_id, $step_name, $assigned_to, $due_date, $status, $description]);
            
            // Update application status if this is the first step
            if ($status === 'in_progress') {
                executeQuery($conn, "
                    UPDATE applications SET status = 'under_review', updated_at = NOW()
                    WHERE id = ? AND status = 'submitted'
                ", [$application_id]);
            }
            
            $message = "Workflow step added successfully";
            $messageType = "success";
        } catch (Exception $e) {
            $message = "Error adding workflow step: " . $e->getMessage();
            $messageType = "danger";
        }
    }
    
    // Update step status
    if ($_POST['action'] === 'update_status') {
        $step_id = $_POST['step_id'];
        $status = $_POST['status'];
        $comments = $_POST['comments'] ?? null;
        $completed_date = ($status === 'completed' || $status === 'skipped') ? date('Y-m-d') : null;
        
        try {
            $conn->beginTransaction();
            
            // Get step details before update
            $step = fetchOne($conn, "
                SELECT ws.*, a.status as app_status 
                FROM workflow_steps ws
                JOIN applications a ON ws.application_id = a.id
                WHERE ws.id = ?
            ", [$step_id]);
            
            // Update the step
            executeQuery($conn, "
                UPDATE workflow_steps 
                SET status = ?, comments = ?, completed_date = ?, updated_at = NOW()
                WHERE id = ?
            ", [$status, $comments, $completed_date, $step_id]);
            
            // If step is completed or skipped, check if we should move to next step
            if ($status === 'completed' || $status === 'skipped') {
                // Check if there are more pending steps
                $pending = fetchOne($conn, "
                    SELECT COUNT(*) as count FROM workflow_steps 
                    WHERE application_id = ? AND status = 'pending'
                ", [$step['application_id']]);
                
                // Check if there are in_progress steps
                $in_progress = fetchOne($conn, "
                    SELECT COUNT(*) as count FROM workflow_steps 
                    WHERE application_id = ? AND status = 'in_progress'
                ", [$step['application_id']]);
                
                if ($pending['count'] == 0 && $in_progress['count'] == 0) {
                    // All steps completed
                    executeQuery($conn, "
                        UPDATE applications SET status = 'completed', updated_at = NOW()
                        WHERE id = ?
                    ", [$step['application_id']]);
                } elseif ($pending['count'] > 0 && $in_progress['count'] == 0) {
                    // There are pending steps but none in progress - set next pending to in_progress
                    executeQuery($conn, "
                        UPDATE workflow_steps 
                        SET status = 'in_progress', updated_at = NOW()
                        WHERE application_id = ? AND status = 'pending'
                        ORDER BY id
                        LIMIT 1
                    ", [$step['application_id']]);
                }
            }
            
            // If step is set to in_progress, update application status
            if ($status === 'in_progress' && $step['app_status'] === 'submitted') {
                executeQuery($conn, "
                    UPDATE applications SET status = 'under_review', updated_at = NOW()
                    WHERE id = ?
                ", [$step['application_id']]);
            }
            
            $conn->commit();
            
            $message = "Step status updated to: " . ucfirst($status);
            $messageType = "success";
        } catch (Exception $e) {
            $conn->rollBack();
            $message = "Error updating step status: " . $e->getMessage();
            $messageType = "danger";
        }
    }
    
    // Edit step
    if ($_POST['action'] === 'edit') {
        $step_id = $_POST['step_id'];
        $step_name = $_POST['step_name'];
        $assigned_to = !empty($_POST['assigned_to']) ? $_POST['assigned_to'] : null;
        $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
        $description = $_POST['description'] ?? null;
        
        try {
            executeQuery($conn, "
                UPDATE workflow_steps 
                SET step_name = ?, assigned_to = ?, due_date = ?, comments = ?, updated_at = NOW()
                WHERE id = ?
            ", [$step_name, $assigned_to, $due_date, $description, $step_id]);
            
            $message = "Workflow step updated successfully";
            $messageType = "success";
        } catch (Exception $e) {
            $message = "Error updating workflow step: " . $e->getMessage();
            $messageType = "danger";
        }
    }
    
    // Delete step
    if ($_POST['action'] === 'delete') {
        $step_id = $_POST['step_id'];
        
        try {
            // Get application_id before deletion
            $step = fetchOne($conn, "SELECT application_id, status FROM workflow_steps WHERE id = ?", [$step_id]);
            
            if (!$step) {
                throw new Exception("Step not found");
            }
            
            // Don't allow deletion of completed steps
            if ($step['status'] === 'completed') {
                throw new Exception("Cannot delete completed steps");
            }
            
            executeQuery($conn, "DELETE FROM workflow_steps WHERE id = ?", [$step_id]);
            
            // Check if this was the last step
            $remaining = fetchOne($conn, "
                SELECT COUNT(*) as count FROM workflow_steps WHERE application_id = ?
            ", [$step['application_id']]);
            
            if ($remaining['count'] == 0) {
                // No steps left, revert application to submitted if it was under_review
                executeQuery($conn, "
                    UPDATE applications SET status = 'submitted', updated_at = NOW()
                    WHERE id = ? AND status = 'under_review'
                ", [$step['application_id']]);
            }
            
            $message = "Workflow step deleted successfully";
            $messageType = "success";
        } catch (Exception $e) {
            $message = "Error deleting workflow step: " . $e->getMessage();
            $messageType = "danger";
        }
    }
}

// ============================================================================
// GET FILTERS AND DATA
// ============================================================================

// Get all applications for filter
$applications = fetchAll($conn, "
    SELECT 
        a.id,
        a.application_type,
        a.status as app_status,
        a.submission_date,
        CONCAT('#', a.id, ' - ', REPLACE(a.application_type, '_', ' ')) as application_name,
        p.parcel_number,
        pa.name as applicant_name
    FROM applications a
    LEFT JOIN parcels p ON a.parcel_id = p.id
    JOIN parties pa ON a.applicant_party_id = pa.id
    ORDER BY a.submission_date DESC
");

// Get users for assignment
$users = fetchAll($conn, "
    SELECT id, username, role 
    FROM users 
    WHERE is_active = 1
    ORDER BY username
");

// Build filter query
$whereClause = " WHERE 1=1";
$params = [];

if (isset($_GET['application_id']) && !empty($_GET['application_id'])) {
    $whereClause .= " AND ws.application_id = ?";
    $params[] = $_GET['application_id'];
}

if (isset($_GET['status']) && !empty($_GET['status'])) {
    $whereClause .= " AND ws.status = ?";
    $params[] = $_GET['status'];
}

if (isset($_GET['assigned_to']) && !empty($_GET['assigned_to'])) {
    $whereClause .= " AND ws.assigned_to = ?";
    $params[] = $_GET['assigned_to'];
}

if (isset($_GET['overdue']) && $_GET['overdue'] == '1') {
    $whereClause .= " AND ws.due_date < CURDATE() AND ws.status IN ('pending', 'in_progress')";
}

if (isset($_GET['date_from']) && !empty($_GET['date_from'])) {
    $whereClause .= " AND DATE(ws.created_at) >= ?";
    $params[] = $_GET['date_from'];
}

if (isset($_GET['date_to']) && !empty($_GET['date_to'])) {
    $whereClause .= " AND DATE(ws.created_at) <= ?";
    $params[] = $_GET['date_to'];
}

if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search = '%' . $_GET['search'] . '%';
    $whereClause .= " AND (ws.step_name LIKE ? OR IFNULL(ws.comments, '') LIKE ? OR a.application_type LIKE ? OR IFNULL(p.parcel_number, '') LIKE ? OR pa.name LIKE ?)";
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
}

// Get workflow steps with details
$steps = fetchAll($conn, "
    SELECT 
        ws.*,
        a.id as application_id,
        a.application_type,
        a.status as app_status,
        a.submission_date,
        p.parcel_number,
        pa.name as applicant_name,
        u.username as assigned_to_name,
        u.role as assigned_to_role,
        DATEDIFF(ws.due_date, CURDATE()) as days_remaining,
        CASE 
            WHEN ws.due_date IS NULL THEN 'no_due_date'
            WHEN ws.due_date < CURDATE() AND ws.status IN ('pending', 'in_progress') THEN 'overdue'
            WHEN ws.due_date = CURDATE() AND ws.status IN ('pending', 'in_progress') THEN 'due_today'
            WHEN ws.due_date <= DATE_ADD(CURDATE(), INTERVAL 3 DAY) AND ws.status IN ('pending', 'in_progress') THEN 'due_soon'
            ELSE 'on_track'
        END as due_status
    FROM workflow_steps ws
    JOIN applications a ON ws.application_id = a.id
    LEFT JOIN parcels p ON a.parcel_id = p.id
    JOIN parties pa ON a.applicant_party_id = pa.id
    LEFT JOIN users u ON ws.assigned_to = u.id
    $whereClause
    ORDER BY 
        CASE ws.status
            WHEN 'in_progress' THEN 1
            WHEN 'pending' THEN 2
            WHEN 'completed' THEN 3
            WHEN 'skipped' THEN 4
            ELSE 5
        END,
        ws.due_date ASC,
        ws.created_at DESC
", $params);

// Get summary statistics
$summary = fetchOne($conn, "
    SELECT 
        COUNT(*) as total_steps,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN status = 'skipped' THEN 1 ELSE 0 END) as skipped,
        COUNT(DISTINCT application_id) as applications_with_steps,
        SUM(CASE WHEN due_date < CURDATE() AND status IN ('pending', 'in_progress') THEN 1 ELSE 0 END) as overdue,
        SUM(CASE WHEN due_date = CURDATE() AND status IN ('pending', 'in_progress') THEN 1 ELSE 0 END) as due_today,
        MIN(CASE WHEN status IN ('pending', 'in_progress') THEN due_date ELSE NULL END) as next_due
    FROM workflow_steps
");

// FIXED: Get steps by status for chart - using simpler query
$steps_by_status = [];
$status_result = fetchAll($conn, "
    SELECT status, COUNT(*) as count 
    FROM workflow_steps 
    GROUP BY status
");

foreach ($status_result as $row) {
    $steps_by_status[] = [
        'status' => $row['status'],
        'count' => $row['count']
    ];
}

// Get steps by day for the last 30 days
$steps_by_day = fetchAll($conn, "
    SELECT 
        DATE(created_at) as day,
        COUNT(*) as total,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
    FROM workflow_steps
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY DATE(created_at)
    ORDER BY day
");

$day_labels = [];
$day_totals = [];
$day_completed = [];

foreach ($steps_by_day as $row) {
    $day_labels[] = date('d M', strtotime($row['day']));
    $day_totals[] = (int)$row['total'];
    $day_completed[] = (int)$row['completed'];
}

// Get assignment workload
$assignment_load = fetchAll($conn, "
    SELECT 
        u.id,
        u.username,
        u.role,
        COUNT(CASE WHEN ws.status IN ('pending', 'in_progress') THEN 1 END) as active_tasks,
        COUNT(CASE WHEN ws.status = 'completed' AND ws.completed_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN 1 END) as completed_week,
        MIN(CASE WHEN ws.status IN ('pending', 'in_progress') THEN ws.due_date END) as next_due
    FROM users u
    LEFT JOIN workflow_steps ws ON u.id = ws.assigned_to
    WHERE u.is_active = 1
    GROUP BY u.id, u.username, u.role
    ORDER BY active_tasks DESC
");

// Get workflow for a specific application if selected
$selected_application_steps = [];
$selected_application_info = null;

if (isset($_GET['view_application']) && !empty($_GET['view_application'])) {
    $selected_application_steps = fetchAll($conn, "
        SELECT 
            ws.*,
            u.username as assigned_to_name,
            DATEDIFF(ws.due_date, CURDATE()) as days_remaining
        FROM workflow_steps ws
        LEFT JOIN users u ON ws.assigned_to = u.id
        WHERE ws.application_id = ?
        ORDER BY ws.created_at
    ", [$_GET['view_application']]);
    
    $selected_application_info = fetchOne($conn, "
        SELECT 
            a.*,
            p.parcel_number,
            pa.name as applicant_name
        FROM applications a
        LEFT JOIN parcels p ON a.parcel_id = p.id
        JOIN parties pa ON a.applicant_party_id = pa.id
        WHERE a.id = ?
    ", [$_GET['view_application']]);
}

// Get recent activity
$recent_activity = fetchAll($conn, "
    SELECT 
        ws.*,
        a.application_type,
        a.id as application_id,
        p.parcel_number,
        u.username as assigned_to_name,
        CONCAT('Step \"', ws.step_name, '\" for application #', a.id) as description
    FROM workflow_steps ws
    JOIN applications a ON ws.application_id = a.id
    LEFT JOIN parcels p ON a.parcel_id = p.id
    LEFT JOIN users u ON ws.assigned_to = u.id
    ORDER BY ws.updated_at DESC
    LIMIT 20
");
?>

<!-- Rest of your HTML code remains exactly the same from here -->
<!-- ... -->

<body data-page="workflow-steps" class="workflow-steps-page">
    <!-- Admin App Container -->
    <div class="admin-app">
        <div class="admin-wrapper" id="admin-wrapper">
            
            <!-- Header -->
            <?php include("navbar.php"); ?>

            <!-- Sidebar -->
            <?php include("sidebar.php"); ?>

            <!-- Sidebar Backdrop -->
            <div class="sidebar-backdrop" aria-hidden="true"></div>
            
            <!-- Main Content -->
            <main class="admin-main">
                <div class="container-fluid p-4 p-lg-5">
                    
                    <!-- Page Header -->
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div>
                            <h1 class="h3 mb-0">
                                <i class="bi bi-diagram-3 text-primary me-2"></i>
                                Workflow Steps Management
                            </h1>
                            <p class="text-muted mb-0">Track and manage workflow steps across all applications</p>
                        </div>
                        <div>
                            <button class="btn btn-outline-secondary me-2" type="button" data-bs-toggle="collapse" data-bs-target="#filterCollapse">
                                <i class="bi bi-funnel me-2"></i>Filters
                            </button>
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addStepModal">
                                <i class="bi bi-plus-circle me-2"></i>Add Workflow Step
                            </button>
                        </div>
                    </div>

                    <!-- Filter Collapse -->
                    <div class="collapse mb-4" id="filterCollapse">
                        <div class="card border-0 shadow-sm">
                            <div class="card-body">
                                <form method="GET" class="row g-3">
                                    <div class="col-md-3">
                                        <label class="form-label">Application</label>
                                        <select class="form-select" name="application_id">
                                            <option value="">All Applications</option>
                                            <?php foreach ($applications as $app): ?>
                                            <option value="<?php echo $app['id']; ?>" <?php echo (isset($_GET['application_id']) && $_GET['application_id'] == $app['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($app['application_name'] . ' - ' . ($app['parcel_number'] ?? 'No parcel')); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Step Status</label>
                                        <select class="form-select" name="status">
                                            <option value="">All Statuses</option>
                                            <option value="pending" <?php echo (isset($_GET['status']) && $_GET['status'] == 'pending') ? 'selected' : ''; ?>>Pending</option>
                                            <option value="in_progress" <?php echo (isset($_GET['status']) && $_GET['status'] == 'in_progress') ? 'selected' : ''; ?>>In Progress</option>
                                            <option value="completed" <?php echo (isset($_GET['status']) && $_GET['status'] == 'completed') ? 'selected' : ''; ?>>Completed</option>
                                            <option value="skipped" <?php echo (isset($_GET['status']) && $_GET['status'] == 'skipped') ? 'selected' : ''; ?>>Skipped</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Assigned To</label>
                                        <select class="form-select" name="assigned_to">
                                            <option value="">All Users</option>
                                            <?php foreach ($users as $user): ?>
                                            <option value="<?php echo $user['id']; ?>" <?php echo (isset($_GET['assigned_to']) && $_GET['assigned_to'] == $user['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($user['username'] . ' (' . $user['role'] . ')'); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Due Filter</label>
                                        <select class="form-select" name="overdue">
                                            <option value="">All</option>
                                            <option value="1" <?php echo (isset($_GET['overdue']) && $_GET['overdue'] == '1') ? 'selected' : ''; ?>>Overdue Only</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Date From</label>
                                        <input type="date" class="form-control" name="date_from" value="<?php echo isset($_GET['date_from']) ? htmlspecialchars($_GET['date_from']) : ''; ?>">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Date To</label>
                                        <input type="date" class="form-control" name="date_to" value="<?php echo isset($_GET['date_to']) ? htmlspecialchars($_GET['date_to']) : ''; ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Search</label>
                                        <div class="input-group">
                                            <input type="text" class="form-control" name="search" placeholder="Step name, comments, parcel #, applicant..." 
                                                   value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
                                            <button class="btn btn-primary" type="submit">
                                                <i class="bi bi-search"></i>
                                            </button>
                                            <?php if (!empty($_GET)): ?>
                                            <a href="workflow-steps.php" class="btn btn-outline-secondary">
                                                <i class="bi bi-x-circle"></i> Clear
                                            </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Alert Message -->
                    <?php if ($message): ?>
                    <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show mb-4" role="alert">
                        <?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    <?php endif; ?>

                    <!-- Summary Cards -->
                    <div class="row g-4 mb-5">
                        <div class="col-xl-2 col-md-4">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0 me-3">
                                            <div class="bg-primary bg-opacity-10 rounded-circle p-3">
                                                <i class="bi bi-diagram-3 text-primary fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Total Steps</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['total_steps'] ?? 0); ?></h3>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-xl-2 col-md-4">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0 me-3">
                                            <div class="bg-warning bg-opacity-10 rounded-circle p-3">
                                                <i class="bi bi-hourglass-split text-warning fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Pending</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['pending'] ?? 0); ?></h3>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-xl-2 col-md-4">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0 me-3">
                                            <div class="bg-info bg-opacity-10 rounded-circle p-3">
                                                <i class="bi bi-play-circle text-info fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">In Progress</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['in_progress'] ?? 0); ?></h3>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-xl-2 col-md-4">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0 me-3">
                                            <div class="bg-success bg-opacity-10 rounded-circle p-3">
                                                <i class="bi bi-check-circle text-success fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Completed</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['completed'] ?? 0); ?></h3>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-xl-2 col-md-4">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0 me-3">
                                            <div class="bg-danger bg-opacity-10 rounded-circle p-3">
                                                <i class="bi bi-exclamation-triangle text-danger fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Overdue</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['overdue'] ?? 0); ?></h3>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-xl-2 col-md-4">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0 me-3">
                                            <div class="bg-secondary bg-opacity-10 rounded-circle p-3">
                                                <i class="bi bi-calendar text-secondary fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Due Today</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['due_today'] ?? 0); ?></h3>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Charts Row -->
                    <div class="row g-4 mb-5">
                        <div class="col-xl-4">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h5 class="card-title mb-0 fw-bold">
                                        <i class="bi bi-pie-chart text-primary me-2"></i>Steps by Status
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <canvas id="statusChart" style="height: 250px;"></canvas>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-xl-8">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h5 class="card-title mb-0 fw-bold">
                                        <i class="bi bi-bar-chart text-success me-2"></i>Daily Step Activity (Last 30 Days)
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <canvas id="dailyChart" style="height: 250px;"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Assignment Workload -->
                    <div class="card border-0 shadow-sm mb-5">
                        <div class="card-header bg-white py-3">
                            <h5 class="card-title mb-0 fw-bold">
                                <i class="bi bi-people text-info me-2"></i>Team Workload
                            </h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>User</th>
                                            <th>Role</th>
                                            <th>Active Tasks</th>
                                            <th>Completed (Week)</th>
                                            <th>Next Due</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($assignment_load as $load): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($load['username']); ?></strong>
                                            </td>
                                            <td><?php echo ucfirst($load['role']); ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo $load['active_tasks'] > 5 ? 'danger' : ($load['active_tasks'] > 2 ? 'warning' : 'success'); ?>">
                                                    <?php echo $load['active_tasks']; ?> tasks
                                                </span>
                                            </td>
                                            <td><?php echo $load['completed_week']; ?> tasks</td>
                                            <td>
                                                <?php if ($load['next_due']): ?>
                                                    <?php echo date('d M Y', strtotime($load['next_due'])); ?>
                                                <?php else: ?>
                                                    <span class="text-muted">No pending</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($load['active_tasks'] > 0): ?>
                                                    <div class="progress" style="height: 5px; width: 80px;">
                                                        <?php 
                                                        $load_percent = min(100, ($load['active_tasks'] / 10) * 100);
                                                        ?>
                                                        <div class="progress-bar bg-<?php echo $load_percent > 70 ? 'danger' : ($load_percent > 40 ? 'warning' : 'success'); ?>" 
                                                             style="width: <?php echo $load_percent; ?>%"></div>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-muted">Idle</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Workflow Steps Table -->
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0 fw-bold">
                                <i class="bi bi-list-check me-2"></i>Workflow Steps
                                <span class="badge bg-secondary ms-2"><?php echo count($steps); ?> steps</span>
                            </h5>
                            <div class="d-flex gap-2">
                                <input type="text" class="form-control form-control-sm" style="width: 250px;" id="tableSearch" placeholder="Search in table...">
                                <button class="btn btn-sm btn-outline-secondary" onclick="exportTableToCSV()">
                                    <i class="bi bi-download"></i> Export
                                </button>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0" id="stepsTable">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>
                                                <input type="checkbox" class="form-check-input" id="selectAll">
                                            </th>
                                            <th>Step Name</th>
                                            <th>Application</th>
                                            <th>Parcel</th>
                                            <th>Assigned To</th>
                                            <th>Status</th>
                                            <th>Created</th>
                                            <th>Due Date</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($steps)): ?>
                                        <tr>
                                            <td colspan="9" class="text-center py-4 text-muted">
                                                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                                No workflow steps found
                                            </td>
                                        </tr>
                                        <?php else: ?>
                                            <?php foreach ($steps as $step): ?>
                                            <tr class="<?php 
                                                echo $step['due_status'] == 'overdue' ? 'table-danger' : 
                                                    ($step['due_status'] == 'due_today' ? 'table-warning' : 
                                                    ($step['due_status'] == 'due_soon' ? 'table-info' : ''));
                                            ?>">
                                                <td>
                                                    <input type="checkbox" class="form-check-input step-select" value="<?php echo $step['id']; ?>">
                                                </td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($step['step_name']); ?></strong>
                                                    <?php if ($step['comments']): ?>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars(substr($step['comments'], 0, 50)) . (strlen($step['comments']) > 50 ? '...' : ''); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <a href="?view_application=<?php echo $step['application_id']; ?>#workflow">
                                                        #<?php echo $step['application_id']; ?> - <?php echo str_replace('_', ' ', $step['application_type']); ?>
                                                    </a>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars($step['applicant_name']); ?></small>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars($step['parcel_number'] ?? 'N/A'); ?>
                                                </td>
                                                <td>
                                                    <?php if ($step['assigned_to_name']): ?>
                                                        <span class="badge bg-info">
                                                            <?php echo htmlspecialchars($step['assigned_to_name']); ?>
                                                        </span>
                                                        <br><small class="text-muted"><?php echo $step['assigned_to_role']; ?></small>
                                                    <?php else: ?>
                                                        <span class="text-muted">Unassigned</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php
                                                    $statusClass = match($step['status']) {
                                                        'pending' => 'warning',
                                                        'in_progress' => 'primary',
                                                        'completed' => 'success',
                                                        'skipped' => 'secondary',
                                                        default => 'secondary'
                                                    };
                                                    ?>
                                                    <span class="badge bg-<?php echo $statusClass; ?>">
                                                        <?php echo ucfirst(str_replace('_', ' ', $step['status'])); ?>
                                                    </span>
                                                    <?php if ($step['completed_date']): ?>
                                                    <br><small class="text-muted"><?php echo date('d M', strtotime($step['completed_date'])); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php echo date('d M Y', strtotime($step['created_at'])); ?>
                                                </td>
                                                <td>
                                                    <?php if ($step['due_date']): ?>
                                                        <span class="<?php 
                                                            echo $step['due_status'] == 'overdue' ? 'text-danger fw-bold' : 
                                                                ($step['due_status'] == 'due_today' ? 'text-warning fw-bold' : '');
                                                        ?>">
                                                            <?php echo date('d M Y', strtotime($step['due_date'])); ?>
                                                        </span>
                                                        <?php if ($step['due_status'] == 'overdue'): ?>
                                                            <br><small class="text-danger"><?php echo abs($step['days_remaining']); ?> days overdue</small>
                                                        <?php elseif ($step['due_status'] == 'due_today'): ?>
                                                            <br><small class="text-warning">Due today</small>
                                                        <?php elseif ($step['due_status'] == 'due_soon' && $step['days_remaining'] > 0): ?>
                                                            <br><small class="text-info"><?php echo $step['days_remaining']; ?> days left</small>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">No due date</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="btn-group">
                                                        <button class="btn btn-sm btn-outline-primary" 
                                                                onclick="viewStep(<?php echo htmlspecialchars(json_encode($step)); ?>)"
                                                                data-bs-toggle="modal" data-bs-target="#viewStepModal">
                                                            <i class="bi bi-eye"></i>
                                                        </button>
                                                        
                                                        <?php if ($step['status'] != 'completed'): ?>
                                                        <button class="btn btn-sm btn-outline-success" 
                                                                onclick="editStep(<?php echo htmlspecialchars(json_encode($step)); ?>)"
                                                                data-bs-toggle="modal" data-bs-target="#editStepModal">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                        
                                                        <button class="btn btn-sm btn-outline-warning" 
                                                                onclick="updateStepStatus(<?php echo htmlspecialchars(json_encode($step)); ?>)"
                                                                data-bs-toggle="modal" data-bs-target="#updateStatusModal">
                                                            <i class="bi bi-arrow-repeat"></i>
                                                        </button>
                                                        
                                                        <?php if ($step['status'] != 'in_progress' && $step['status'] != 'completed'): ?>
                                                        <button class="btn btn-sm btn-outline-danger" 
                                                                onclick="deleteStep(<?php echo $step['id']; ?>, '<?php echo htmlspecialchars(addslashes($step['step_name'])); ?>')"
                                                                data-bs-toggle="modal" data-bs-target="#deleteStepModal">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                        <?php endif; ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <?php if (!empty($steps)): ?>
                        <div class="card-footer bg-white py-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <button class="btn btn-sm btn-primary" id="bulkUpdateBtn" disabled onclick="showBulkUpdateModal()">
                                        <i class="bi bi-pencil-square"></i> Bulk Update Selected (<span id="selectedCount">0</span>)
                                    </button>
                                </div>
                                <small class="text-muted">Showing <?php echo count($steps); ?> workflow steps</small>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Application Workflow View -->
                    <?php if ($selected_application_info && !empty($selected_application_steps)): ?>
                    <div class="card border-0 shadow-sm mt-4" id="workflow">
                        <div class="card-header bg-white py-3">
                            <h5 class="card-title mb-0 fw-bold">
                                <i class="bi bi-diagram-3 text-info me-2"></i>
                                Workflow Timeline - Application #<?php echo $selected_application_info['id']; ?>
                                <small class="text-muted ms-2"><?php echo str_replace('_', ' ', $selected_application_info['application_type']); ?></small>
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="timeline">
                                <?php foreach ($selected_application_steps as $index => $step): ?>
                                <div class="timeline-item <?php echo $step['status'] == 'in_progress' ? 'active' : ''; ?>">
                                    <div class="row">
                                        <div class="col-md-2 text-muted small">
                                            <?php echo date('d M Y H:i', strtotime($step['created_at'])); ?>
                                            <?php if ($step['completed_date']): ?>
                                            <br>Completed: <?php echo date('d M Y', strtotime($step['completed_date'])); ?>
                                            <?php endif; ?>
                                        </div>
                                        <div class="col-md-8">
                                            <div class="d-flex align-items-start">
                                                <div class="timeline-badge bg-<?php 
                                                    echo $step['status'] == 'completed' ? 'success' : 
                                                        ($step['status'] == 'in_progress' ? 'primary' : 
                                                        ($step['status'] == 'skipped' ? 'secondary' : 'warning')); 
                                                ?> me-3 mt-1"></div>
                                                <div class="flex-grow-1">
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <h6 class="mb-1"><?php echo htmlspecialchars($step['step_name']); ?></h6>
                                                        <span class="badge bg-<?php 
                                                            echo $step['status'] == 'completed' ? 'success' : 
                                                                ($step['status'] == 'in_progress' ? 'primary' : 
                                                                ($step['status'] == 'skipped' ? 'secondary' : 'warning')); 
                                                        ?>">
                                                            <?php echo ucfirst($step['status']); ?>
                                                        </span>
                                                    </div>
                                                    <?php if ($step['assigned_to_name']): ?>
                                                    <small class="text-muted d-block">Assigned to: <?php echo htmlspecialchars($step['assigned_to_name']); ?></small>
                                                    <?php endif; ?>
                                                    <?php if ($step['due_date']): ?>
                                                    <small class="text-muted d-block">Due: <?php echo date('d M Y', strtotime($step['due_date'])); ?>
                                                        <?php if ($step['days_remaining'] && $step['status'] != 'completed'): ?>
                                                            (<?php echo $step['days_remaining'] > 0 ? $step['days_remaining'] . ' days left' : abs($step['days_remaining']) . ' days overdue'; ?>)
                                                        <?php endif; ?>
                                                    </small>
                                                    <?php endif; ?>
                                                    <?php if ($step['comments']): ?>
                                                    <p class="mt-2 mb-0 p-2 bg-light rounded">
                                                        <i class="bi bi-chat-text me-1"></i>
                                                        <?php echo nl2br(htmlspecialchars($step['comments'])); ?>
                                                    </p>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-2 text-end">
                                            <button class="btn btn-sm btn-outline-primary" onclick="editStep(<?php echo htmlspecialchars(json_encode($step)); ?>)" 
                                                    data-bs-toggle="modal" data-bs-target="#editStepModal">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="card-footer bg-white">
                            <a href="workflow-steps.php" class="btn btn-sm btn-secondary">Back to All Steps</a>
                            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addStepModal">
                                <i class="bi bi-plus-circle"></i> Add Step
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Recent Activity -->
                    <div class="card border-0 shadow-sm mt-4">
                        <div class="card-header bg-white py-3">
                            <h5 class="card-title mb-0 fw-bold">
                                <i class="bi bi-clock-history me-2"></i>Recent Step Activity
                            </h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="list-group list-group-flush">
                                <?php foreach ($recent_activity as $activity): ?>
                                <div class="list-group-item">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <span class="badge bg-<?php 
                                                echo $activity['status'] == 'completed' ? 'success' : 
                                                    ($activity['status'] == 'in_progress' ? 'primary' : 
                                                    ($activity['status'] == 'skipped' ? 'secondary' : 'warning')); 
                                            ?> me-2">
                                                <?php echo ucfirst($activity['status']); ?>
                                            </span>
                                            <strong><?php echo htmlspecialchars($activity['description']); ?></strong>
                                            <br>
                                            <small class="text-muted">
                                                <?php echo htmlspecialchars($activity['parcel_number'] ?? 'No parcel'); ?> | 
                                                <?php echo $activity['assigned_to_name'] ? 'Assigned to: ' . htmlspecialchars($activity['assigned_to_name']) : 'Unassigned'; ?>
                                            </small>
                                        </div>
                                        <small class="text-muted">
                                            <?php echo date('d M H:i', strtotime($activity['updated_at'])); ?>
                                        </small>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                </div>
            </main>

            <!-- Footer -->
            <?php include("footer.php"); ?>

        </div>
    </div>

    <!-- Add Step Modal -->
    <div class="modal fade" id="addStepModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="add">
                    <div class="modal-header">
                        <h5 class="modal-title">Add Workflow Step</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Application <span class="text-danger">*</span></label>
                            <select class="form-select" name="application_id" required>
                                <option value="">Select Application</option>
                                <?php foreach ($applications as $app): ?>
                                <option value="<?php echo $app['id']; ?>" <?php echo (isset($_GET['view_application']) && $_GET['view_application'] == $app['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($app['application_name'] . ' - ' . ($app['parcel_number'] ?? 'No parcel')); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Step Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="step_name" required 
                                   placeholder="e.g., Document Verification, Site Inspection, Final Approval">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Assign To</label>
                            <select class="form-select" name="assigned_to">
                                <option value="">Unassigned</option>
                                <?php foreach ($users as $user): ?>
                                <option value="<?php echo $user['id']; ?>">
                                    <?php echo htmlspecialchars($user['username'] . ' (' . $user['role'] . ')'); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Due Date</label>
                            <input type="date" class="form-control" name="due_date" min="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Initial Status</label>
                            <select class="form-select" name="status">
                                <option value="pending">Pending</option>
                                <option value="in_progress">In Progress</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description / Notes</label>
                            <textarea class="form-control" name="description" rows="3" 
                                      placeholder="Instructions or notes for this step..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Step</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Step Modal -->
    <div class="modal fade" id="editStepModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="step_id" id="editStepId">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Workflow Step</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Step Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="step_name" id="editStepName" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Assign To</label>
                            <select class="form-select" name="assigned_to" id="editAssignedTo">
                                <option value="">Unassigned</option>
                                <?php foreach ($users as $user): ?>
                                <option value="<?php echo $user['id']; ?>">
                                    <?php echo htmlspecialchars($user['username']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Due Date</label>
                            <input type="date" class="form-control" name="due_date" id="editDueDate">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description / Notes</label>
                            <textarea class="form-control" name="description" rows="3" id="editDescription"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Step</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Step Modal -->
    <div class="modal fade" id="viewStepModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Step Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <table class="table table-borderless">
                        <tr>
                            <th width="35%">Step Name:</th>
                            <td id="viewStepName"></td>
                        </tr>
                        <tr>
                            <th>Application:</th>
                            <td id="viewApplication"></td>
                        </tr>
                        <tr>
                            <th>Parcel:</th>
                            <td id="viewParcel"></td>
                        </tr>
                        <tr>
                            <th>Applicant:</th>
                            <td id="viewApplicant"></td>
                        </tr>
                        <tr>
                            <th>Assigned To:</th>
                            <td id="viewAssignedTo"></td>
                        </tr>
                        <tr>
                            <th>Status:</th>
                            <td id="viewStatus"></td>
                        </tr>
                        <tr>
                            <th>Created:</th>
                            <td id="viewCreated"></td>
                        </tr>
                        <tr>
                            <th>Due Date:</th>
                            <td id="viewDueDate"></td>
                        </tr>
                        <tr>
                            <th>Completed:</th>
                            <td id="viewCompleted"></td>
                        </tr>
                    </table>
                    
                    <h6 class="mt-3">Comments / Notes</h6>
                    <p id="viewComments" class="text-muted p-3 bg-light rounded"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Update Status Modal -->
    <div class="modal fade" id="updateStatusModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="step_id" id="statusStepId">
                    <div class="modal-header">
                        <h5 class="modal-title">Update Step Status</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Step</label>
                            <input type="text" class="form-control" id="statusStepName" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">New Status <span class="text-danger">*</span></label>
                            <select class="form-select" name="status" required id="statusSelect">
                                <option value="pending">Pending</option>
                                <option value="in_progress">In Progress</option>
                                <option value="completed">Completed</option>
                                <option value="skipped">Skipped</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Comments / Notes</label>
                            <textarea class="form-control" name="comments" rows="3" id="statusComments" 
                                      placeholder="Add notes about this status change..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Status</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bulk Update Modal -->
    <div class="modal fade" id="bulkUpdateModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="bulk_update">
                    <input type="hidden" name="application_id" id="bulkApplicationId">
                    <input type="hidden" name="step_ids" id="bulkStepIds">
                    <div class="modal-header">
                        <h5 class="modal-title">Bulk Update Steps</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p>You have selected <span id="bulkCount">0</span> steps to update.</p>
                        
                        <div class="mb-3">
                            <label class="form-label">New Status <span class="text-danger">*</span></label>
                            <select class="form-select" name="bulk_status" required>
                                <option value="pending">Pending</option>
                                <option value="in_progress">In Progress</option>
                                <option value="completed">Completed</option>
                                <option value="skipped">Skipped</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Comments (will be appended to each step)</label>
                            <textarea class="form-control" name="bulk_comments" rows="3" 
                                      placeholder="Additional notes for all selected steps..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Selected</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Step Modal -->
    <div class="modal fade" id="deleteStepModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="step_id" id="deleteStepId">
                    <div class="modal-header">
                        <h5 class="modal-title">Delete Workflow Step</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p>Are you sure you want to delete step: <strong id="deleteStepName"></strong>?</p>
                        <p class="text-danger"><i class="bi bi-exclamation-triangle"></i> This action cannot be undone. Only pending steps can be deleted.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Delete Step</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Chart Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <script>
        // Initialize charts
        document.addEventListener('DOMContentLoaded', function() {
            // Status Chart
            const statusCtx = document.getElementById('statusChart')?.getContext('2d');
            if (statusCtx && <?php echo !empty($steps_by_status) ? 'true' : 'false'; ?>) {
                const statusLabels = [
                    <?php foreach ($steps_by_status as $item): ?>
                    '<?php echo ucfirst($item['status']); ?>',
                    <?php endforeach; ?>
                ];
                const statusData = [
                    <?php foreach ($steps_by_status as $item): ?>
                    <?php echo $item['count']; ?>,
                    <?php endforeach; ?>
                ];
                
                new Chart(statusCtx, {
                    type: 'doughnut',
                    data: {
                        labels: statusLabels,
                        datasets: [{
                            data: statusData,
                            backgroundColor: ['#ffc107', '#0d6efd', '#198754', '#6c757d'],
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom'
                            }
                        }
                    }
                });
            }
            
            // Daily Chart
            const dailyCtx = document.getElementById('dailyChart')?.getContext('2d');
            if (dailyCtx) {
                const dailyLabels = <?php echo json_encode($day_labels); ?>;
                const dailyTotals = <?php echo json_encode($day_totals); ?>;
                const dailyCompleted = <?php echo json_encode($day_completed); ?>;
                
                new Chart(dailyCtx, {
                    type: 'bar',
                    data: {
                        labels: dailyLabels,
                        datasets: [
                            {
                                label: 'Total Steps Created',
                                data: dailyTotals,
                                backgroundColor: '#0d6efd',
                                yAxisID: 'y',
                            },
                            {
                                label: 'Steps Completed',
                                data: dailyCompleted,
                                backgroundColor: '#198754',
                                yAxisID: 'y',
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    stepSize: 1,
                                    precision: 0
                                }
                            }
                        }
                    }
                });
            }
            
            // Bulk selection
            const selectAll = document.getElementById('selectAll');
            const stepCheckboxes = document.querySelectorAll('.step-select');
            const bulkUpdateBtn = document.getElementById('bulkUpdateBtn');
            const selectedCountSpan = document.getElementById('selectedCount');
            
            if (selectAll) {
                selectAll.addEventListener('change', function() {
                    stepCheckboxes.forEach(cb => {
                        cb.checked = this.checked;
                    });
                    updateBulkButton();
                });
            }
            
            stepCheckboxes.forEach(cb => {
                cb.addEventListener('change', updateBulkButton);
            });
            
            function updateBulkButton() {
                const selected = document.querySelectorAll('.step-select:checked');
                const count = selected.length;
                
                if (selectedCountSpan) {
                    selectedCountSpan.textContent = count;
                }
                
                if (bulkUpdateBtn) {
                    bulkUpdateBtn.disabled = count === 0;
                }
            }
        });
        
        // View step
        function viewStep(step) {
            document.getElementById('viewStepName').textContent = step.step_name || 'N/A';
            document.getElementById('viewApplication').innerHTML = `#${step.application_id} - ${step.application_type.replace('_', ' ')}`;
            document.getElementById('viewParcel').textContent = step.parcel_number || 'N/A';
            document.getElementById('viewApplicant').textContent = step.applicant_name || 'N/A';
            document.getElementById('viewAssignedTo').textContent = step.assigned_to_name || 'Unassigned';
            
            let statusClass = 'warning';
            if (step.status === 'in_progress') statusClass = 'primary';
            else if (step.status === 'completed') statusClass = 'success';
            else if (step.status === 'skipped') statusClass = 'secondary';
            
            document.getElementById('viewStatus').innerHTML = `<span class="badge bg-${statusClass}">${ucfirst(step.status)}</span>`;
            document.getElementById('viewCreated').textContent = formatDate(step.created_at, true);
            document.getElementById('viewDueDate').textContent = step.due_date ? formatDate(step.due_date) : 'No due date';
            document.getElementById('viewCompleted').textContent = step.completed_date ? formatDate(step.completed_date) : 'Not completed';
            document.getElementById('viewComments').textContent = step.comments || 'No comments';
        }
        
        // Edit step
        function editStep(step) {
            document.getElementById('editStepId').value = step.id;
            document.getElementById('editStepName').value = step.step_name || '';
            document.getElementById('editAssignedTo').value = step.assigned_to || '';
            document.getElementById('editDueDate').value = step.due_date || '';
            document.getElementById('editDescription').value = step.comments || '';
        }
        
        // Update step status
        function updateStepStatus(step) {
            document.getElementById('statusStepId').value = step.id;
            document.getElementById('statusStepName').value = step.step_name || '';
            document.getElementById('statusSelect').value = step.status || 'pending';
            document.getElementById('statusComments').value = '';
        }
        
        // Delete step
        function deleteStep(id, name) {
            document.getElementById('deleteStepId').value = id;
            document.getElementById('deleteStepName').textContent = name;
        }
        
        // Show bulk update modal
        function showBulkUpdateModal() {
            const selected = document.querySelectorAll('.step-select:checked');
            const stepIds = Array.from(selected).map(cb => cb.value);
            
            // Check if all selected steps belong to the same application
            const rows = Array.from(selected).map(cb => cb.closest('tr'));
            const appIds = rows.map(row => {
                const appLink = row.querySelector('td a');
                return appLink ? appLink.href.split('=')[1] : null;
            });
            
            const uniqueApps = [...new Set(appIds)];
            
            if (uniqueApps.length > 1) {
                alert('Please select steps from the same application for bulk update.');
                return;
            }
            
            document.getElementById('bulkApplicationId').value = uniqueApps[0] || '';
            document.getElementById('bulkStepIds').value = JSON.stringify(stepIds);
            document.getElementById('bulkCount').textContent = stepIds.length;
            
            new bootstrap.Modal(document.getElementById('bulkUpdateModal')).show();
        }
        
        // Format date
        function formatDate(dateString, includeTime = false) {
            if (!dateString) return 'N/A';
            const options = { 
                year: 'numeric', 
                month: 'short', 
                day: 'numeric',
                ...(includeTime && { hour: '2-digit', minute: '2-digit' })
            };
            return new Date(dateString).toLocaleDateString(undefined, options);
        }
        
        // Capitalize first letter
        function ucfirst(str) {
            return str.charAt(0).toUpperCase() + str.slice(1);
        }
        
        // Search functionality
        document.getElementById('tableSearch')?.addEventListener('keyup', function() {
            const searchText = this.value.toLowerCase();
            const table = document.getElementById('stepsTable');
            const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
            
            Array.from(rows).forEach(row => {
                // Skip the first cell (checkbox)
                const cells = Array.from(row.cells).slice(1);
                const text = cells.map(cell => cell.textContent).join(' ').toLowerCase();
                
                if (text.includes(searchText)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
        
        // Export to CSV
        function exportTableToCSV() {
            const table = document.getElementById('stepsTable');
            const rows = table.querySelectorAll('tr');
            const csv = [];
            
            rows.forEach(row => {
                const cells = row.querySelectorAll('td, th');
                // Skip checkbox column
                const rowData = Array.from(cells).slice(1).map(cell => {
                    return '"' + cell.textContent.trim().replace(/"/g, '""') + '"';
                });
                if (rowData.length > 0) {
                    csv.push(rowData.join(','));
                }
            });
            
            const csvContent = csv.join('\n');
            const blob = new Blob([csvContent], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'workflow_steps_' + new Date().toISOString().slice(0,10) + '.csv';
            a.click();
        }
    </script>

    <style>
        .timeline-item {
            position: relative;
            padding-left: 30px;
            margin-bottom: 25px;
        }
        .timeline-badge {
            position: absolute;
            left: 0;
            top: 0;
            width: 15px;
            height: 15px;
            border-radius: 50%;
        }
        .timeline-item.active .timeline-badge {
            box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.3);
        }
        .timeline-item:not(:last-child):before {
            content: '';
            position: absolute;
            left: 7px;
            top: 20px;
            bottom: -25px;
            width: 2px;
            background-color: #dee2e6;
        }
        .table-danger {
            background-color: #f8d7da !important;
        }
        .table-warning {
            background-color: #fff3cd !important;
        }
        .table-info {
            background-color: #d1ecf1 !important;
        }
        .btn-group .btn {
            padding: 0.25rem 0.5rem;
        }
        @media (max-width: 768px) {
            .btn-group {
                display: flex;
                flex-direction: column;
            }
            .btn-group .btn {
                border-radius: 0.25rem !important;
                margin-bottom: 2px;
            }
        }
    </style>
</body>
</html>