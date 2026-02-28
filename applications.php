<?php
ini_set('display_errors', 1); 
error_reporting(E_ALL);

// applications.php
include("head.php");
require_once 'db_connection.php';

// ============================================================================
// HANDLE ACTIONS
// ============================================================================

$message = '';
$messageType = '';

// Add application
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    if ($_POST['action'] === 'add') {
        $application_type = $_POST['application_type'];
        $parcel_id = !empty($_POST['parcel_id']) ? $_POST['parcel_id'] : null;
        $applicant_party_id = $_POST['applicant_party_id'];
        $submission_date = $_POST['submission_date'];
        $status = $_POST['status'] ?? 'submitted';
        $details = $_POST['details'] ?? null;
        
        try {
            $conn->beginTransaction();
            
            // Insert application
            executeQuery($conn, "
                INSERT INTO applications (
                    application_type, parcel_id, applicant_party_id, 
                    submission_date, status, details, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
            ", [$application_type, $parcel_id, $applicant_party_id, $submission_date, $status, $details]);
            
            $applicationId = $conn->lastInsertId();
            
            // Create initial workflow step
            executeQuery($conn, "
                INSERT INTO workflow_steps (
                    application_id, step_name, status, created_at
                ) VALUES (?, 'Initial Review', 'pending', NOW())
            ", [$applicationId]);
            
            $conn->commit();
            
            $message = "Application submitted successfully";
            $messageType = "success";
        } catch (Exception $e) {
            $conn->rollBack();
            $message = "Error submitting application: " . $e->getMessage();
            $messageType = "danger";
        }
    }
    
    // Update application
    if ($_POST['action'] === 'edit') {
        $application_id = $_POST['application_id'];
        $application_type = $_POST['application_type'];
        $parcel_id = !empty($_POST['parcel_id']) ? $_POST['parcel_id'] : null;
        $details = $_POST['details'] ?? null;
        
        try {
            executeQuery($conn, "
                UPDATE applications SET 
                    application_type = ?, 
                    parcel_id = ?, 
                    details = ?,
                    updated_at = NOW()
                WHERE id = ?
            ", [$application_type, $parcel_id, $details, $application_id]);
            
            $message = "Application updated successfully";
            $messageType = "success";
        } catch (Exception $e) {
            $message = "Error updating application: " . $e->getMessage();
            $messageType = "danger";
        }
    }
    
    // Update status
    if ($_POST['action'] === 'update_status') {
        $application_id = $_POST['application_id'];
        $status = $_POST['status'];
        $comments = $_POST['comments'] ?? null;
        
        try {
            executeQuery($conn, "
                UPDATE applications SET 
                    status = ?,
                    updated_at = NOW()
                WHERE id = ?
            ", [$status, $application_id]);
            
            // Log status change in workflow
            executeQuery($conn, "
                INSERT INTO workflow_steps (
                    application_id, step_name, status, comments, completed_date, created_at
                ) VALUES (?, 'Status Update', 'completed', ?, NOW(), NOW())
            ", [$application_id, "Status changed to: $status. $comments"]);
            
            $message = "Application status updated to: " . ucfirst($status);
            $messageType = "success";
        } catch (Exception $e) {
            $message = "Error updating status: " . $e->getMessage();
            $messageType = "danger";
        }
    }
    
    // Assign to officer
    if ($_POST['action'] === 'assign') {
        $application_id = $_POST['application_id'];
        $assigned_to = $_POST['assigned_to'];
        $step_name = $_POST['step_name'] ?? 'Review';
        
        try {
            executeQuery($conn, "
                UPDATE workflow_steps 
                SET assigned_to = ?, status = 'in_progress', updated_at = NOW()
                WHERE application_id = ? AND status = 'pending'
                ORDER BY id LIMIT 1
            ", [$assigned_to, $application_id]);
            
            $message = "Application assigned successfully";
            $messageType = "success";
        } catch (Exception $e) {
            $message = "Error assigning application: " . $e->getMessage();
            $messageType = "danger";
        }
    }
    
    // Complete workflow step
    if ($_POST['action'] === 'complete_step') {
        $step_id = $_POST['step_id'];
        $comments = $_POST['comments'] ?? null;
        $status = $_POST['step_status'] ?? 'completed';
        
        try {
            executeQuery($conn, "
                UPDATE workflow_steps 
                SET status = ?, comments = ?, completed_date = NOW(), updated_at = NOW()
                WHERE id = ?
            ", [$status, $comments, $step_id]);
            
            // Check if this is the last step
            $step = fetchOne($conn, "
                SELECT application_id FROM workflow_steps WHERE id = ?
            ", [$step_id]);
            
            // Check if there are more pending steps
            $pending = fetchOne($conn, "
                SELECT COUNT(*) as count FROM workflow_steps 
                WHERE application_id = ? AND status = 'pending'
            ", [$step['application_id']]);
            
            if ($pending['count'] == 0) {
                // All steps completed, update application status
                executeQuery($conn, "
                    UPDATE applications SET status = 'completed', updated_at = NOW()
                    WHERE id = ?
                ", [$step['application_id']]);
            }
            
            $message = "Step completed successfully";
            $messageType = "success";
        } catch (Exception $e) {
            $message = "Error completing step: " . $e->getMessage();
            $messageType = "danger";
        }
    }
    
    // Add workflow step
    if ($_POST['action'] === 'add_step') {
        $application_id = $_POST['application_id'];
        $step_name = $_POST['step_name'];
        $assigned_to = !empty($_POST['assigned_to']) ? $_POST['assigned_to'] : null;
        $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
        
        try {
            executeQuery($conn, "
                INSERT INTO workflow_steps (
                    application_id, step_name, assigned_to, due_date, status, created_at
                ) VALUES (?, ?, ?, ?, 'pending', NOW())
            ", [$application_id, $step_name, $assigned_to, $due_date]);
            
            $message = "Workflow step added successfully";
            $messageType = "success";
        } catch (Exception $e) {
            $message = "Error adding workflow step: " . $e->getMessage();
            $messageType = "danger";
        }
    }
    
    // Delete application
    if ($_POST['action'] === 'delete') {
        $application_id = $_POST['application_id'];
        
        try {
            // Check if application can be deleted (only draft or rejected)
            $app = fetchOne($conn, "SELECT status FROM applications WHERE id = ?", [$application_id]);
            
            if (!in_array($app['status'], ['draft', 'rejected'])) {
                throw new Exception("Only draft or rejected applications can be deleted");
            }
            
            // Delete workflow steps first (foreign key)
            executeQuery($conn, "DELETE FROM workflow_steps WHERE application_id = ?", [$application_id]);
            
            // Delete application
            executeQuery($conn, "DELETE FROM applications WHERE id = ?", [$application_id]);
            
            $message = "Application deleted successfully";
            $messageType = "success";
        } catch (Exception $e) {
            $message = "Error deleting application: " . $e->getMessage();
            $messageType = "danger";
        }
    }
}

// ============================================================================
// GET FILTERS AND DATA
// ============================================================================

// Get all parties for filter
$parties = fetchAll($conn, "
    SELECT id, name, party_type, national_id, registration_number 
    FROM parties 
    ORDER BY name
");

// Get all parcels for filter
$parcels = fetchAll($conn, "
    SELECT p.id, p.parcel_number, b.name as boma_name
    FROM parcels p
    JOIN bomas b ON p.boma_id = b.id
    ORDER BY p.parcel_number
");

// Get users for assignment
$users = fetchAll($conn, "
    SELECT id, username, role 
    FROM users 
    WHERE is_active = 1
    ORDER BY username
");

// Get states for filter
$states = fetchAll($conn, "SELECT id, name FROM states ORDER BY name");

// Build filter query
$whereClause = " WHERE 1=1";
$params = [];

if (isset($_GET['application_type']) && !empty($_GET['application_type'])) {
    $whereClause .= " AND a.application_type = ?";
    $params[] = $_GET['application_type'];
}

if (isset($_GET['status']) && !empty($_GET['status'])) {
    $whereClause .= " AND a.status = ?";
    $params[] = $_GET['status'];
}

if (isset($_GET['party_id']) && !empty($_GET['party_id'])) {
    $whereClause .= " AND a.applicant_party_id = ?";
    $params[] = $_GET['party_id'];
}

if (isset($_GET['parcel_id']) && !empty($_GET['parcel_id'])) {
    $whereClause .= " AND a.parcel_id = ?";
    $params[] = $_GET['parcel_id'];
}

if (isset($_GET['state_id']) && !empty($_GET['state_id'])) {
    $whereClause .= " AND s.id = ?";
    $params[] = $_GET['state_id'];
}

if (isset($_GET['date_from']) && !empty($_GET['date_from'])) {
    $whereClause .= " AND a.submission_date >= ?";
    $params[] = $_GET['date_from'];
}

if (isset($_GET['date_to']) && !empty($_GET['date_to'])) {
    $whereClause .= " AND a.submission_date <= ?";
    $params[] = $_GET['date_to'];
}

if (isset($_GET['assigned_to']) && !empty($_GET['assigned_to'])) {
    $whereClause .= " AND EXISTS (SELECT 1 FROM workflow_steps ws WHERE ws.application_id = a.id AND ws.assigned_to = ? AND ws.status = 'in_progress')";
    $params[] = $_GET['assigned_to'];
}

if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search = '%' . $_GET['search'] . '%';
    $whereClause .= " AND (a.id LIKE ? OR IFNULL(p.parcel_number, '') LIKE ? OR pa2.name LIKE ? OR IFNULL(a.details, '') LIKE ?)";
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
}

// Get applications with full details
$applications = fetchAll($conn, "
    SELECT 
        a.*,
        p.id as parcel_id,
        p.parcel_number,
        p.area as parcel_area,
        b.id as boma_id,
        b.name as boma_name,
        pa.id as payam_id,
        pa.name as payam_name,
        c.id as county_id,
        c.name as county_name,
        s.id as state_id,
        s.name as state_name,
        -- Applicant details
        pa2.id as applicant_id,
        pa2.name as applicant_name,
        pa2.party_type as applicant_type,
        pa2.national_id,
        pa2.registration_number as org_reg_number,
        pa2.phone as applicant_phone,
        pa2.email as applicant_email,
        -- Workflow steps
        (SELECT COUNT(*) FROM workflow_steps WHERE application_id = a.id) as total_steps,
        (SELECT COUNT(*) FROM workflow_steps WHERE application_id = a.id AND status = 'completed') as completed_steps,
        (SELECT COUNT(*) FROM workflow_steps WHERE application_id = a.id AND status = 'in_progress') as in_progress_steps,
        (SELECT COUNT(*) FROM workflow_steps WHERE application_id = a.id AND status = 'pending') as pending_steps,
        -- Current step
        (SELECT step_name FROM workflow_steps 
         WHERE application_id = a.id AND status = 'in_progress' 
         ORDER BY id LIMIT 1) as current_step,
        (SELECT assigned_to FROM workflow_steps 
         WHERE application_id = a.id AND status = 'in_progress' 
         ORDER BY id LIMIT 1) as current_assignee_id,
        -- Processing time
        DATEDIFF(NOW(), a.submission_date) as days_pending
    FROM applications a
    LEFT JOIN parcels p ON a.parcel_id = p.id
    LEFT JOIN bomas b ON p.boma_id = b.id
    LEFT JOIN payams pa ON b.payam_id = pa.id
    LEFT JOIN counties c ON pa.county_id = c.id
    LEFT JOIN states s ON c.state_id = s.id
    JOIN parties pa2 ON a.applicant_party_id = pa2.id
    $whereClause
    ORDER BY 
        CASE a.status
            WHEN 'submitted' THEN 1
            WHEN 'under_review' THEN 2
            WHEN 'approved' THEN 3
            WHEN 'rejected' THEN 4
            WHEN 'completed' THEN 5
            WHEN 'draft' THEN 6
            ELSE 7
        END,
        a.submission_date DESC
", $params);

// Get summary statistics
$summary = fetchOne($conn, "
    SELECT 
        COUNT(*) as total_applications,
        SUM(CASE WHEN status = 'submitted' THEN 1 ELSE 0 END) as submitted,
        SUM(CASE WHEN status = 'under_review' THEN 1 ELSE 0 END) as under_review,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft,
        SUM(CASE WHEN application_type = 'new_title' THEN 1 ELSE 0 END) as new_title,
        SUM(CASE WHEN application_type = 'transfer' THEN 1 ELSE 0 END) as transfer,
        SUM(CASE WHEN application_type = 'subdivision' THEN 1 ELSE 0 END) as subdivision,
        SUM(CASE WHEN application_type = 'consolidation' THEN 1 ELSE 0 END) as consolidation,
        SUM(CASE WHEN application_type = 'lease' THEN 1 ELSE 0 END) as lease,
        SUM(CASE WHEN application_type = 'change_of_use' THEN 1 ELSE 0 END) as change_of_use,
        MIN(submission_date) as earliest,
        MAX(submission_date) as latest,
        AVG(DATEDIFF(NOW(), submission_date)) as avg_processing_days
    FROM applications
");

// FIXED: Get applications by month for chart - using simpler query
$by_month = [];
$monthly_result = fetchAll($conn, "
    SELECT 
        YEAR(submission_date) as year,
        MONTH(submission_date) as month,
        COUNT(*) as count
    FROM applications
    WHERE submission_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY YEAR(submission_date), MONTH(submission_date)
    ORDER BY year ASC, month ASC
");

$monthly_labels = [];
$monthly_counts = [];

foreach ($monthly_result as $row) {
    $month_name = date('M Y', mktime(0, 0, 0, $row['month'], 1, $row['year']));
    $monthly_labels[] = $month_name;
    $monthly_counts[] = $row['count'];
}

// Get applications by type for chart
$by_type = fetchAll($conn, "
    SELECT 
        application_type,
        COUNT(*) as count
    FROM applications
    GROUP BY application_type
    ORDER BY count DESC
");

$type_labels = [];
$type_counts = [];
$type_colors = [];

foreach ($by_type as $row) {
    $type_labels[] = ucfirst(str_replace('_', ' ', $row['application_type']));
    $type_counts[] = $row['count'];
}

// Color mapping for chart
$color_map = [
    'new_title' => '#0d6efd',
    'transfer' => '#6610f2',
    'subdivision' => '#6f42c1',
    'consolidation' => '#d63384',
    'lease' => '#dc3545',
    'change_of_use' => '#fd7e14'
];

// Get pending assignments
$pending_assignments = fetchAll($conn, "
    SELECT 
        ws.*,
        a.id as application_id,
        a.application_type,
        a.submission_date,
        p.parcel_number,
        pa.name as applicant_name,
        ws.assigned_to as assigned_to_id
    FROM workflow_steps ws
    JOIN applications a ON ws.application_id = a.id
    LEFT JOIN parcels p ON a.parcel_id = p.id
    JOIN parties pa ON a.applicant_party_id = pa.id
    WHERE ws.status IN ('pending', 'in_progress')
    ORDER BY 
        CASE ws.status WHEN 'in_progress' THEN 1 ELSE 2 END,
        ws.due_date ASC,
        ws.created_at ASC
");

// Get workflow steps for a specific application if ID is provided
$workflow_steps = [];
if (isset($_GET['view_workflow']) && !empty($_GET['view_workflow'])) {
    $workflow_steps = fetchAll($conn, "
        SELECT 
            ws.*
        FROM workflow_steps ws
        WHERE ws.application_id = ?
        ORDER BY ws.created_at
    ", [$_GET['view_workflow']]);
}

// Get recent activity
$recent_activity = fetchAll($conn, "
    SELECT 
        a.id,
        a.application_type,
        a.status,
        a.submission_date,
        a.updated_at,
        pa.name as applicant_name,
        CONCAT('#', a.id, ' - ', REPLACE(a.application_type, '_', ' ')) as description
    FROM applications a
    JOIN parties pa ON a.applicant_party_id = pa.id
    ORDER BY a.updated_at DESC
    LIMIT 10
");
?>

<body data-page="applications" class="applications-page">
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
                                <i class="bi bi-file-earmark-text text-primary me-2"></i>
                                Applications Management
                            </h1>
                            <p class="text-muted mb-0">Process land applications, track workflow, and manage approvals</p>
                        </div>
                        <div>
                            <button class="btn btn-outline-secondary me-2" type="button" data-bs-toggle="collapse" data-bs-target="#filterCollapse">
                                <i class="bi bi-funnel me-2"></i>Filters
                            </button>
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addApplicationModal">
                                <i class="bi bi-plus-circle me-2"></i>New Application
                            </button>
                        </div>
                    </div>

                    <!-- Filter Collapse -->
                    <div class="collapse mb-4" id="filterCollapse">
                        <div class="card border-0 shadow-sm">
                            <div class="card-body">
                                <form method="GET" class="row g-3">
                                    <div class="col-md-3">
                                        <label class="form-label">Application Type</label>
                                        <select class="form-select" name="application_type">
                                            <option value="">All Types</option>
                                            <option value="new_title" <?php echo (isset($_GET['application_type']) && $_GET['application_type'] == 'new_title') ? 'selected' : ''; ?>>New Title</option>
                                            <option value="transfer" <?php echo (isset($_GET['application_type']) && $_GET['application_type'] == 'transfer') ? 'selected' : ''; ?>>Transfer</option>
                                            <option value="subdivision" <?php echo (isset($_GET['application_type']) && $_GET['application_type'] == 'subdivision') ? 'selected' : ''; ?>>Subdivision</option>
                                            <option value="consolidation" <?php echo (isset($_GET['application_type']) && $_GET['application_type'] == 'consolidation') ? 'selected' : ''; ?>>Consolidation</option>
                                            <option value="lease" <?php echo (isset($_GET['application_type']) && $_GET['application_type'] == 'lease') ? 'selected' : ''; ?>>Lease</option>
                                            <option value="change_of_use" <?php echo (isset($_GET['application_type']) && $_GET['application_type'] == 'change_of_use') ? 'selected' : ''; ?>>Change of Use</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Status</label>
                                        <select class="form-select" name="status">
                                            <option value="">All Statuses</option>
                                            <option value="draft" <?php echo (isset($_GET['status']) && $_GET['status'] == 'draft') ? 'selected' : ''; ?>>Draft</option>
                                            <option value="submitted" <?php echo (isset($_GET['status']) && $_GET['status'] == 'submitted') ? 'selected' : ''; ?>>Submitted</option>
                                            <option value="under_review" <?php echo (isset($_GET['status']) && $_GET['status'] == 'under_review') ? 'selected' : ''; ?>>Under Review</option>
                                            <option value="approved" <?php echo (isset($_GET['status']) && $_GET['status'] == 'approved') ? 'selected' : ''; ?>>Approved</option>
                                            <option value="rejected" <?php echo (isset($_GET['status']) && $_GET['status'] == 'rejected') ? 'selected' : ''; ?>>Rejected</option>
                                            <option value="completed" <?php echo (isset($_GET['status']) && $_GET['status'] == 'completed') ? 'selected' : ''; ?>>Completed</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Applicant</label>
                                        <select class="form-select" name="party_id">
                                            <option value="">All Applicants</option>
                                            <?php foreach ($parties as $party): ?>
                                            <option value="<?php echo $party['id']; ?>" <?php echo (isset($_GET['party_id']) && $_GET['party_id'] == $party['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($party['name']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Assigned To</label>
                                        <select class="form-select" name="assigned_to">
                                            <option value="">All</option>
                                            <?php foreach ($users as $user): ?>
                                            <option value="<?php echo $user['id']; ?>" <?php echo (isset($_GET['assigned_to']) && $_GET['assigned_to'] == $user['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($user['username'] . ' (' . $user['role'] . ')'); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">State</label>
                                        <select class="form-select" name="state_id">
                                            <option value="">All States</option>
                                            <?php foreach ($states as $state): ?>
                                            <option value="<?php echo $state['id']; ?>" <?php echo (isset($_GET['state_id']) && $_GET['state_id'] == $state['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($state['name']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Parcel</label>
                                        <select class="form-select" name="parcel_id">
                                            <option value="">All Parcels</option>
                                            <?php foreach ($parcels as $parcel): ?>
                                            <option value="<?php echo $parcel['id']; ?>" <?php echo (isset($_GET['parcel_id']) && $_GET['parcel_id'] == $parcel['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($parcel['parcel_number'] . ' - ' . $parcel['boma_name']); ?>
                                            </option>
                                            <?php endforeach; ?>
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
                                            <input type="text" class="form-control" name="search" placeholder="Application ID, Parcel #, Applicant name..." 
                                                   value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
                                            <button class="btn btn-primary" type="submit">
                                                <i class="bi bi-search"></i>
                                            </button>
                                            <?php if (!empty($_GET)): ?>
                                            <a href="applications.php" class="btn btn-outline-secondary">
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
                                                <i class="bi bi-file-text text-primary fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Total</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['total_applications'] ?? 0); ?></h3>
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
                                            <h3 class="mb-0 fw-bold"><?php echo number_format(($summary['submitted'] ?? 0) + ($summary['under_review'] ?? 0)); ?></h3>
                                            <small class="text-muted">Submitted: <?php echo $summary['submitted'] ?? 0; ?> | Review: <?php echo $summary['under_review'] ?? 0; ?></small>
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
                                            <h6 class="text-muted mb-1">Approved</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['approved'] ?? 0); ?></h3>
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
                                                <i class="bi bi-check2-all text-info fs-4"></i>
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
                                                <i class="bi bi-x-circle text-danger fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Rejected</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['rejected'] ?? 0); ?></h3>
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
                                                <i class="bi bi-pencil text-secondary fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Drafts</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['draft'] ?? 0); ?></h3>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Charts Row -->
                    <div class="row g-4 mb-5">
                        <div class="col-xl-6">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h5 class="card-title mb-0 fw-bold">
                                        <i class="bi bi-bar-chart text-primary me-2"></i>Applications by Month
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <canvas id="monthlyChart" style="height: 300px;"></canvas>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-xl-6">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h5 class="card-title mb-0 fw-bold">
                                        <i class="bi bi-pie-chart text-success me-2"></i>Applications by Type
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <canvas id="typeChart" style="height: 300px;"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Pending Assignments Section -->
                    <?php if (!empty($pending_assignments)): ?>
                    <div class="card border-0 shadow-sm mb-5">
                        <div class="card-header bg-white py-3">
                            <h5 class="card-title mb-0 fw-bold text-warning">
                                <i class="bi bi-exclamation-triangle me-2"></i>Pending Workflow Tasks
                                <span class="badge bg-warning ms-2"><?php echo count($pending_assignments); ?></span>
                            </h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>Application #</th>
                                            <th>Type</th>
                                            <th>Applicant</th>
                                            <th>Step</th>
                                            <th>Status</th>
                                            <th>Due Date</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pending_assignments as $task): ?>
                                        <tr class="<?php echo $task['due_date'] && $task['due_date'] < date('Y-m-d') ? 'table-danger' : ($task['status'] == 'in_progress' ? 'table-info' : ''); ?>">
                                            <td>
                                                <a href="?view_workflow=<?php echo $task['application_id']; ?>#workflow">
                                                    #<?php echo $task['application_id']; ?>
                                                </a>
                                            </td>
                                            <td>
                                                <span class="badge bg-secondary"><?php echo str_replace('_', ' ', $task['application_type']); ?></span>
                                            </td>
                                            <td><?php echo htmlspecialchars($task['applicant_name']); ?></td>
                                            <td><?php echo htmlspecialchars($task['step_name']); ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo $task['status'] == 'in_progress' ? 'primary' : 'warning'; ?>">
                                                    <?php echo ucfirst($task['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($task['due_date']): ?>
                                                    <?php echo date('d M Y', strtotime($task['due_date'])); ?>
                                                    <?php if ($task['due_date'] < date('Y-m-d')): ?>
                                                        <br><small class="text-danger">Overdue</small>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    N/A
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <button class="btn btn-sm btn-success" onclick="completeStep(<?php echo htmlspecialchars(json_encode($task)); ?>)" 
                                                        data-bs-toggle="modal" data-bs-target="#completeStepModal">
                                                    <i class="bi bi-check-circle"></i> Complete
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Main Applications Table -->
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0 fw-bold">
                                <i class="bi bi-table me-2"></i>Applications List
                                <span class="badge bg-secondary ms-2"><?php echo count($applications); ?> records</span>
                            </h5>
                            <div>
                                <input type="text" class="form-control form-control-sm" style="width: 250px;" id="tableSearch" placeholder="Search in table...">
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0" id="applicationsTable">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>ID</th>
                                            <th>Type</th>
                                            <th>Applicant</th>
                                            <th>Parcel</th>
                                            <th>Location</th>
                                            <th>Submitted</th>
                                            <th>Days</th>
                                            <th>Status</th>
                                            <th>Current Step</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($applications)): ?>
                                        <tr>
                                            <td colspan="10" class="text-center py-4 text-muted">
                                                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                                No applications found
                                            </td>
                                        </tr>
                                        <?php else: ?>
                                            <?php foreach ($applications as $app): ?>
                                            <tr class="<?php 
                                                echo ($app['days_pending'] ?? 0) > 30 ? 'table-warning' : '';
                                                echo $app['status'] == 'submitted' ? 'table-light' : '';
                                            ?>">
                                                <td>
                                                    <strong>#<?php echo $app['id']; ?></strong>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?php 
                                                        echo $app['application_type'] == 'new_title' ? 'success' : 
                                                            ($app['application_type'] == 'transfer' ? 'info' : 
                                                            ($app['application_type'] == 'subdivision' ? 'warning' : 
                                                            ($app['application_type'] == 'consolidation' ? 'primary' : 
                                                            ($app['application_type'] == 'lease' ? 'secondary' : 'dark')))); 
                                                    ?>">
                                                        <?php echo str_replace('_', ' ', $app['application_type']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($app['applicant_name']); ?></strong>
                                                    <br><small class="text-muted"><?php echo ucfirst($app['applicant_type']); ?></small>
                                                </td>
                                                <td>
                                                    <?php if ($app['parcel_number']): ?>
                                                        <?php echo htmlspecialchars($app['parcel_number']); ?>
                                                        <br><small class="text-muted"><?php echo number_format($app['parcel_area'] ?? 0, 2); ?> m²</small>
                                                    <?php else: ?>
                                                        <span class="text-muted">Not specified</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($app['boma_name']): ?>
                                                        <?php echo htmlspecialchars($app['boma_name']); ?>, <?php echo htmlspecialchars($app['payam_name']); ?>
                                                        <br><small class="text-muted"><?php echo htmlspecialchars($app['county_name'] ?? ''); ?></small>
                                                    <?php else: ?>
                                                        <span class="text-muted">N/A</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php echo date('d M Y', strtotime($app['submission_date'])); ?>
                                                </td>
                                                <td>
                                                    <?php if ($app['status'] != 'completed' && $app['status'] != 'rejected'): ?>
                                                        <span class="badge bg-<?php echo ($app['days_pending'] ?? 0) > 30 ? 'danger' : (($app['days_pending'] ?? 0) > 14 ? 'warning' : 'info'); ?>">
                                                            <?php echo $app['days_pending'] ?? 0; ?> days
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php
                                                    $statusClass = match($app['status']) {
                                                        'draft' => 'secondary',
                                                        'submitted' => 'info',
                                                        'under_review' => 'primary',
                                                        'approved' => 'success',
                                                        'rejected' => 'danger',
                                                        'completed' => 'success',
                                                        default => 'secondary'
                                                    };
                                                    ?>
                                                    <span class="badge bg-<?php echo $statusClass; ?>">
                                                        <?php echo ucfirst(str_replace('_', ' ', $app['status'])); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($app['current_step']): ?>
                                                        <small><?php echo htmlspecialchars($app['current_step']); ?></small>
                                                        <div class="progress mt-1" style="height: 5px; width: 100px;">
                                                            <?php 
                                                            $total = ($app['total_steps'] ?? 0);
                                                            $completed = ($app['completed_steps'] ?? 0);
                                                            $progress = $total > 0 ? ($completed / $total) * 100 : 0;
                                                            ?>
                                                            <div class="progress-bar bg-success" style="width: <?php echo $progress; ?>%"></div>
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="text-muted">No active step</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="btn-group">
                                                        <button class="btn btn-sm btn-outline-primary" 
                                                                onclick="viewApplication(<?php echo htmlspecialchars(json_encode($app)); ?>)"
                                                                data-bs-toggle="modal" data-bs-target="#viewApplicationModal">
                                                            <i class="bi bi-eye"></i>
                                                        </button>
                                                        
                                                        <?php if ($app['status'] == 'draft' || $app['status'] == 'submitted'): ?>
                                                        <button class="btn btn-sm btn-outline-success" 
                                                                onclick="editApplication(<?php echo htmlspecialchars(json_encode($app)); ?>)"
                                                                data-bs-toggle="modal" data-bs-target="#editApplicationModal">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                        <?php endif; ?>
                                                        
                                                        <?php if ($app['status'] == 'submitted' || $app['status'] == 'under_review'): ?>
                                                        <button class="btn btn-sm btn-outline-warning" 
                                                                onclick="updateStatus(<?php echo htmlspecialchars(json_encode($app)); ?>)"
                                                                data-bs-toggle="modal" data-bs-target="#updateStatusModal">
                                                            <i class="bi bi-arrow-repeat"></i>
                                                        </button>
                                                        
                                                        <button class="btn btn-sm btn-outline-info" 
                                                                onclick="assignApplication(<?php echo htmlspecialchars(json_encode($app)); ?>)"
                                                                data-bs-toggle="modal" data-bs-target="#assignModal">
                                                            <i class="bi bi-person-plus"></i>
                                                        </button>
                                                        <?php endif; ?>
                                                        
                                                        <a href="?view_workflow=<?php echo $app['id']; ?>#workflow" class="btn btn-sm btn-outline-secondary">
                                                            <i class="bi bi-diagram-3"></i>
                                                        </a>
                                                        
                                                        <?php if ($app['status'] == 'draft' || $app['status'] == 'rejected'): ?>
                                                        <button class="btn btn-sm btn-outline-danger" 
                                                                onclick="deleteApplication(<?php echo $app['id']; ?>, '#<?php echo $app['id']; ?>')"
                                                                data-bs-toggle="modal" data-bs-target="#deleteApplicationModal">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
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
                    </div>

                    <!-- Workflow Steps Section (if viewing a specific application) -->
                    <?php if (isset($_GET['view_workflow']) && !empty($workflow_steps)): ?>
                    <div class="card border-0 shadow-sm mt-4" id="workflow">
                        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0 fw-bold">
                                <i class="bi bi-diagram-3 text-info me-2"></i>
                                Workflow Steps - Application #<?php echo htmlspecialchars($_GET['view_workflow']); ?>
                            </h5>
                            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addStepModal">
                                <i class="bi bi-plus-circle"></i> Add Step
                            </button>
                        </div>
                        <div class="card-body">
                            <div class="timeline">
                                <?php foreach ($workflow_steps as $index => $step): ?>
                                <div class="timeline-item <?php echo $index == 0 ? 'active' : ''; ?>">
                                    <div class="row">
                                        <div class="col-md-2 text-muted small">
                                            <?php echo date('d M Y H:i', strtotime($step['created_at'])); ?>
                                        </div>
                                        <div class="col-md-8">
                                            <div class="d-flex align-items-center">
                                                <div class="timeline-badge bg-<?php 
                                                    echo $step['status'] == 'completed' ? 'success' : 
                                                        ($step['status'] == 'in_progress' ? 'primary' : 'warning'); 
                                                ?> me-3"></div>
                                                <div>
                                                    <strong><?php echo htmlspecialchars($step['step_name']); ?></strong>
                                                    <?php if ($step['comments']): ?>
                                                    <p class="mt-2 mb-0"><?php echo nl2br(htmlspecialchars($step['comments'])); ?></p>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-2 text-end">
                                            <span class="badge bg-<?php 
                                                echo $step['status'] == 'completed' ? 'success' : 
                                                    ($step['status'] == 'in_progress' ? 'primary' : 'warning'); 
                                            ?>">
                                                <?php echo ucfirst($step['status']); ?>
                                            </span>
                                            <?php if ($step['completed_date']): ?>
                                            <br><small class="text-muted">Completed: <?php echo date('d M Y', strtotime($step['completed_date'])); ?></small>
                                            <?php endif; ?>
                                            <?php if ($step['due_date']): ?>
                                            <br><small class="text-muted">Due: <?php echo date('d M Y', strtotime($step['due_date'])); ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="card-footer bg-white">
                            <a href="applications.php" class="btn btn-sm btn-secondary">Back to Applications</a>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Recent Activity -->
                    <div class="card border-0 shadow-sm mt-4">
                        <div class="card-header bg-white py-3">
                            <h5 class="card-title mb-0 fw-bold">
                                <i class="bi bi-clock-history me-2"></i>Recent Activity
                            </h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="list-group list-group-flush">
                                <?php foreach ($recent_activity as $activity): ?>
                                <div class="list-group-item">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <span class="badge bg-<?php 
                                                echo $activity['status'] == 'approved' ? 'success' : 
                                                    ($activity['status'] == 'rejected' ? 'danger' : 
                                                    ($activity['status'] == 'completed' ? 'info' : 'warning')); 
                                            ?> me-2">
                                                <?php echo ucfirst($activity['status']); ?>
                                            </span>
                                            <strong><?php echo htmlspecialchars($activity['description']); ?></strong>
                                            <br><small class="text-muted"><?php echo htmlspecialchars($activity['applicant_name']); ?></small>
                                        </div>
                                        <small class="text-muted">
                                            <?php echo date('d M Y H:i', strtotime($activity['updated_at'])); ?>
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

    <!-- Add Application Modal -->
    <div class="modal fade" id="addApplicationModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="add">
                    <div class="modal-header">
                        <h5 class="modal-title">New Application</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Application Type <span class="text-danger">*</span></label>
                                <select class="form-select" name="application_type" required>
                                    <option value="">Select Type</option>
                                    <option value="new_title">New Title</option>
                                    <option value="transfer">Transfer of Ownership</option>
                                    <option value="subdivision">Subdivision</option>
                                    <option value="consolidation">Consolidation</option>
                                    <option value="lease">Lease</option>
                                    <option value="change_of_use">Change of Use</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Applicant <span class="text-danger">*</span></label>
                                <select class="form-select" name="applicant_party_id" required>
                                    <option value="">Select Applicant</option>
                                    <?php foreach ($parties as $party): ?>
                                    <option value="<?php echo $party['id']; ?>">
                                        <?php echo htmlspecialchars($party['name'] . ' (' . ucfirst($party['party_type']) . ')'); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Parcel (if applicable)</label>
                                <select class="form-select" name="parcel_id">
                                    <option value="">Select Parcel</option>
                                    <?php foreach ($parcels as $parcel): ?>
                                    <option value="<?php echo $parcel['id']; ?>">
                                        <?php echo htmlspecialchars($parcel['parcel_number'] . ' - ' . $parcel['boma_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Submission Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="submission_date" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Initial Status</label>
                                <select class="form-select" name="status">
                                    <option value="draft">Draft</option>
                                    <option value="submitted" selected>Submitted</option>
                                </select>
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Details</label>
                                <textarea class="form-control" name="details" rows="4" 
                                          placeholder="Provide details about the application..."></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Submit Application</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Application Modal -->
    <div class="modal fade" id="editApplicationModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="application_id" id="editApplicationId">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Application</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Application Type</label>
                            <select class="form-select" name="application_type" id="editApplicationType">
                                <option value="new_title">New Title</option>
                                <option value="transfer">Transfer of Ownership</option>
                                <option value="subdivision">Subdivision</option>
                                <option value="consolidation">Consolidation</option>
                                <option value="lease">Lease</option>
                                <option value="change_of_use">Change of Use</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Parcel</label>
                            <select class="form-select" name="parcel_id" id="editParcelId">
                                <option value="">Select Parcel</option>
                                <?php foreach ($parcels as $parcel): ?>
                                <option value="<?php echo $parcel['id']; ?>">
                                    <?php echo htmlspecialchars($parcel['parcel_number']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Details</label>
                            <textarea class="form-control" name="details" rows="4" id="editDetails"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Application Modal -->
    <div class="modal fade" id="viewApplicationModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Application Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6 class="border-bottom pb-2">Application Information</h6>
                            <table class="table table-borderless">
                                <tr>
                                    <th width="40%">ID:</th>
                                    <td><strong id="viewId"></strong></td>
                                </tr>
                                <tr>
                                    <th>Type:</th>
                                    <td id="viewType"></td>
                                </tr>
                                <tr>
                                    <th>Status:</th>
                                    <td id="viewStatus"></td>
                                </tr>
                                <tr>
                                    <th>Submitted:</th>
                                    <td id="viewSubmissionDate"></td>
                                </tr>
                                <tr>
                                    <th>Days Pending:</th>
                                    <td id="viewDaysPending"></td>
                                </tr>
                            </table>
                        </div>
                        
                        <div class="col-md-6">
                            <h6 class="border-bottom pb-2">Applicant Information</h6>
                            <table class="table table-borderless">
                                <tr>
                                    <th width="40%">Name:</th>
                                    <td id="viewApplicantName"></td>
                                </tr>
                                <tr>
                                    <th>Type:</th>
                                    <td id="viewApplicantType"></td>
                                </tr>
                                <tr>
                                    <th>ID/Reg:</th>
                                    <td id="viewApplicantId"></td>
                                </tr>
                                <tr>
                                    <th>Contact:</th>
                                    <td id="viewApplicantContact"></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    
                    <div class="row mt-3">
                        <div class="col-12">
                            <h6 class="border-bottom pb-2">Parcel Information</h6>
                            <table class="table table-borderless">
                                <tr>
                                    <th width="15%">Parcel #:</th>
                                    <td width="35%" id="viewParcelNumber"></td>
                                    <th width="15%">Area:</th>
                                    <td width="35%" id="viewParcelArea"></td>
                                </tr>
                                <tr>
                                    <th>Location:</th>
                                    <td colspan="3" id="viewLocation"></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    
                    <div class="row mt-3">
                        <div class="col-12">
                            <h6 class="border-bottom pb-2">Details</h6>
                            <p id="viewDetails" class="text-muted"></p>
                        </div>
                    </div>
                    
                    <div class="row mt-3">
                        <div class="col-12">
                            <h6 class="border-bottom pb-2">Workflow Progress</h6>
                            <div class="progress mb-2" style="height: 20px;">
                                <div class="progress-bar bg-success" id="viewProgress" style="width: 0%;">0%</div>
                            </div>
                            <div class="d-flex justify-content-between small text-muted">
                                <span>Steps: <span id="viewCompletedSteps">0</span>/<span id="viewTotalSteps">0</span></span>
                                <span id="viewCurrentStepText">No active step</span>
                            </div>
                        </div>
                    </div>
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
                    <input type="hidden" name="application_id" id="statusApplicationId">
                    <div class="modal-header">
                        <h5 class="modal-title">Update Application Status</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Application</label>
                            <input type="text" class="form-control" id="statusApplicationInfo" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">New Status <span class="text-danger">*</span></label>
                            <select class="form-select" name="status" required>
                                <option value="submitted">Submitted</option>
                                <option value="under_review">Under Review</option>
                                <option value="approved">Approved</option>
                                <option value="rejected">Rejected</option>
                                <option value="completed">Completed</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Comments</label>
                            <textarea class="form-control" name="comments" rows="3" placeholder="Reason for status change..."></textarea>
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

    <!-- Assign Application Modal -->
    <div class="modal fade" id="assignModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="assign">
                    <input type="hidden" name="application_id" id="assignApplicationId">
                    <div class="modal-header">
                        <h5 class="modal-title">Assign Application</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Application</label>
                            <input type="text" class="form-control" id="assignApplicationInfo" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Current Step</label>
                            <input type="text" class="form-control" id="assignCurrentStep" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Assign To <span class="text-danger">*</span></label>
                            <select class="form-select" name="assigned_to" required>
                                <option value="">Select Officer</option>
                                <?php foreach ($users as $user): ?>
                                <option value="<?php echo $user['id']; ?>">
                                    <?php echo htmlspecialchars($user['username'] . ' (' . $user['role'] . ')'); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Step Name (optional)</label>
                            <input type="text" class="form-control" name="step_name" placeholder="e.g., Document Verification">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Assign</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Complete Step Modal -->
    <div class="modal fade" id="completeStepModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="complete_step">
                    <input type="hidden" name="step_id" id="completeStepId">
                    <div class="modal-header">
                        <h5 class="modal-title">Complete Workflow Step</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Step</label>
                            <input type="text" class="form-control" id="completeStepName" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Application</label>
                            <input type="text" class="form-control" id="completeApplicationInfo" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="step_status">
                                <option value="completed">Completed</option>
                                <option value="skipped">Skipped</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Comments</label>
                            <textarea class="form-control" name="comments" rows="3" placeholder="Completion notes..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Complete Step</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Step Modal -->
    <div class="modal fade" id="addStepModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="add_step">
                    <input type="hidden" name="application_id" value="<?php echo isset($_GET['view_workflow']) ? htmlspecialchars($_GET['view_workflow']) : ''; ?>">
                    <div class="modal-header">
                        <h5 class="modal-title">Add Workflow Step</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
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
                                    <?php echo htmlspecialchars($user['username']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Due Date</label>
                            <input type="date" class="form-control" name="due_date">
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

    <!-- Delete Application Modal -->
    <div class="modal fade" id="deleteApplicationModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="application_id" id="deleteApplicationId">
                    <div class="modal-header">
                        <h5 class="modal-title">Delete Application</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p>Are you sure you want to delete application <strong id="deleteApplicationInfo"></strong>?</p>
                        <p class="text-danger"><i class="bi bi-exclamation-triangle"></i> This action cannot be undone. Only draft or rejected applications can be deleted.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Delete</button>
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
            // Monthly Chart
            const monthlyCtx = document.getElementById('monthlyChart')?.getContext('2d');
            if (monthlyCtx) {
                const monthlyLabels = <?php echo json_encode($monthly_labels); ?>;
                const monthlyData = <?php echo json_encode($monthly_counts); ?>;
                
                new Chart(monthlyCtx, {
                    type: 'line',
                    data: {
                        labels: monthlyLabels,
                        datasets: [{
                            label: 'Number of Applications',
                            data: monthlyData,
                            borderColor: '#0d6efd',
                            backgroundColor: 'rgba(13, 110, 253, 0.1)',
                            tension: 0.4,
                            fill: true
                        }]
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
                        },
                        plugins: {
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return context.parsed.y + ' applications';
                                    }
                                }
                            }
                        }
                    }
                });
            }
            
            // Type Chart
            const typeCtx = document.getElementById('typeChart')?.getContext('2d');
            if (typeCtx) {
                const typeLabels = <?php echo json_encode($type_labels); ?>;
                const typeData = <?php echo json_encode($type_counts); ?>;
                
                // Generate colors dynamically
                const colors = [
                    '#0d6efd', '#6610f2', '#6f42c1', '#d63384', '#dc3545',
                    '#fd7e14', '#ffc107', '#198754', '#20c997', '#0dcaf0'
                ];
                
                new Chart(typeCtx, {
                    type: 'doughnut',
                    data: {
                        labels: typeLabels,
                        datasets: [{
                            data: typeData,
                            backgroundColor: colors.slice(0, typeData.length),
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    boxWidth: 12,
                                    padding: 15
                                }
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        const label = context.label || '';
                                        const value = context.parsed || 0;
                                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                        const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                        return label + ': ' + value + ' (' + percentage + '%)';
                                    }
                                }
                            }
                        }
                    }
                });
            }
        });
        
        // View application
        function viewApplication(app) {
            document.getElementById('viewId').textContent = '#' + app.id;
            document.getElementById('viewType').innerHTML = `<span class="badge bg-secondary">${app.application_type.replace('_', ' ')}</span>`;
            
            let statusClass = 'secondary';
            if (app.status === 'submitted') statusClass = 'info';
            else if (app.status === 'under_review') statusClass = 'primary';
            else if (app.status === 'approved') statusClass = 'success';
            else if (app.status === 'rejected') statusClass = 'danger';
            else if (app.status === 'completed') statusClass = 'success';
            
            document.getElementById('viewStatus').innerHTML = `<span class="badge bg-${statusClass}">${app.status.replace('_', ' ')}</span>`;
            document.getElementById('viewSubmissionDate').textContent = formatDate(app.submission_date);
            document.getElementById('viewDaysPending').textContent = (app.days_pending || 0) + ' days';
            
            document.getElementById('viewApplicantName').textContent = app.applicant_name || 'N/A';
            document.getElementById('viewApplicantType').textContent = app.applicant_type ? ucfirst(app.applicant_type) : 'N/A';
            document.getElementById('viewApplicantId').textContent = app.national_id || app.org_reg_number || 'N/A';
            document.getElementById('viewApplicantContact').textContent = (app.applicant_phone || app.applicant_email) || 'N/A';
            
            document.getElementById('viewParcelNumber').textContent = app.parcel_number || 'Not specified';
            document.getElementById('viewParcelArea').textContent = app.parcel_area ? parseFloat(app.parcel_area).toFixed(2) + ' m²' : 'N/A';
            
            let location = 'N/A';
            if (app.boma_name) {
                location = app.boma_name;
                if (app.payam_name) location += ', ' + app.payam_name;
                if (app.county_name) location += ', ' + app.county_name;
            }
            document.getElementById('viewLocation').textContent = location;
            
            document.getElementById('viewDetails').textContent = app.details || 'No details provided';
            
            const totalSteps = app.total_steps || 0;
            const completedSteps = app.completed_steps || 0;
            const progress = totalSteps > 0 ? (completedSteps / totalSteps) * 100 : 0;
            
            document.getElementById('viewProgress').style.width = progress + '%';
            document.getElementById('viewProgress').textContent = Math.round(progress) + '%';
            document.getElementById('viewCompletedSteps').textContent = completedSteps;
            document.getElementById('viewTotalSteps').textContent = totalSteps;
            document.getElementById('viewCurrentStepText').textContent = app.current_step || 'No active step';
        }
        
        // Edit application
        function editApplication(app) {
            document.getElementById('editApplicationId').value = app.id;
            document.getElementById('editApplicationType').value = app.application_type;
            document.getElementById('editParcelId').value = app.parcel_id || '';
            document.getElementById('editDetails').value = app.details || '';
        }
        
        // Update status
        function updateStatus(app) {
            document.getElementById('statusApplicationId').value = app.id;
            document.getElementById('statusApplicationInfo').value = '#' + app.id + ' - ' + app.application_type.replace('_', ' ') + ' - ' + app.applicant_name;
        }
        
        // Assign application
        function assignApplication(app) {
            document.getElementById('assignApplicationId').value = app.id;
            document.getElementById('assignApplicationInfo').value = '#' + app.id + ' - ' + app.application_type.replace('_', ' ');
            document.getElementById('assignCurrentStep').value = app.current_step || 'No current step';
        }
        
        // Complete step
        function completeStep(task) {
            document.getElementById('completeStepId').value = task.id;
            document.getElementById('completeStepName').value = task.step_name;
            document.getElementById('completeApplicationInfo').value = 'App #' + task.application_id + ' - ' + task.application_type;
        }
        
        // Delete application
        function deleteApplication(id, info) {
            document.getElementById('deleteApplicationId').value = id;
            document.getElementById('deleteApplicationInfo').textContent = info;
        }
        
        // Format date
        function formatDate(dateString) {
            if (!dateString) return 'N/A';
            const options = { year: 'numeric', month: 'short', day: 'numeric' };
            return new Date(dateString).toLocaleDateString(undefined, options);
        }
        
        // Capitalize first letter
        function ucfirst(str) {
            return str.charAt(0).toUpperCase() + str.slice(1);
        }
        
        // Search functionality
        document.getElementById('tableSearch')?.addEventListener('keyup', function() {
            const searchText = this.value.toLowerCase();
            const table = document.getElementById('applicationsTable');
            const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
            
            Array.from(rows).forEach(row => {
                const text = row.textContent.toLowerCase();
                if (text.includes(searchText)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
    </script>

    <style>
        .timeline-item {
            position: relative;
            padding-left: 30px;
            margin-bottom: 20px;
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
            bottom: -20px;
            width: 2px;
            background-color: #dee2e6;
        }
        .table-warning {
            background-color: #fff3cd !important;
        }
        .table-info {
            background-color: #d1ecf1 !important;
        }
        .table-danger {
            background-color: #f8d7da !important;
        }
        .table-light {
            background-color: #f8f9fa !important;
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