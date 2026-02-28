<?php
ini_set('display_errors', 1); 
error_reporting(E_ALL);

// applications-transfer.php
include("head.php");
require_once 'db_connection.php';

// ============================================================================
// HANDLE ACTIONS
// ============================================================================

$message = '';
$messageType = '';

// Add transfer application
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    if ($_POST['action'] === 'add') {
        $parcel_id = $_POST['parcel_id'];
        $applicant_party_id = $_POST['applicant_party_id'];
        $from_party_id = $_POST['from_party_id'];
        $to_party_id = $_POST['to_party_id'];
        $submission_date = $_POST['submission_date'];
        $consideration_amount = !empty($_POST['consideration_amount']) ? $_POST['consideration_amount'] : null;
        $transfer_reason = $_POST['transfer_reason'] ?? null;
        $details = $_POST['details'] ?? null;
        $status = $_POST['status'] ?? 'submitted';
        
        try {
            $conn->beginTransaction();
            
            // Insert application
            executeQuery($conn, "
                INSERT INTO applications (
                    application_type, parcel_id, applicant_party_id, 
                    submission_date, status, details, created_at, updated_at
                ) VALUES ('transfer', ?, ?, ?, ?, ?, NOW(), NOW())
            ", [$parcel_id, $applicant_party_id, $submission_date, $status, $details]);
            
            $applicationId = $conn->lastInsertId();
            
            // Insert transfer-specific details into a transfer_details table
            // You may need to create this table if it doesn't exist
            executeQuery($conn, "
                INSERT INTO transfer_applications (
                    application_id, from_party_id, to_party_id, 
                    consideration_amount, transfer_reason, created_at
                ) VALUES (?, ?, ?, ?, ?, NOW())
            ", [$applicationId, $from_party_id, $to_party_id, $consideration_amount, $transfer_reason]);
            
            // Create initial workflow step
            executeQuery($conn, "
                INSERT INTO workflow_steps (
                    application_id, step_name, status, created_at
                ) VALUES (?, 'Document Verification', 'pending', NOW())
            ", [$applicationId]);
            
            $conn->commit();
            
            $message = "Transfer application submitted successfully";
            $messageType = "success";
        } catch (Exception $e) {
            $conn->rollBack();
            $message = "Error submitting transfer application: " . $e->getMessage();
            $messageType = "danger";
        }
    }
    
    // Approve transfer
    if ($_POST['action'] === 'approve') {
        $application_id = $_POST['application_id'];
        $approval_date = $_POST['approval_date'];
        $approval_notes = $_POST['approval_notes'] ?? null;
        $approved_by = $_SESSION['user_id'] ?? 1;
        
        try {
            $conn->beginTransaction();
            
            // Get application details
            $app = fetchOne($conn, "
                SELECT a.*, t.from_party_id, t.to_party_id, t.consideration_amount 
                FROM applications a
                JOIN transfer_applications t ON a.id = t.application_id
                WHERE a.id = ?
            ", [$application_id]);
            
            if (!$app) {
                throw new Exception("Application not found");
            }
            
            // Get title information for the parcel
            $title = fetchOne($conn, "
                SELECT t.id, t.title_number 
                FROM titles t
                WHERE t.parcel_id = ? AND t.status = 'active'
            ", [$app['parcel_id']]);
            
            if (!$title) {
                throw new Exception("No active title found for this parcel");
            }
            
            // End current ownership
            executeQuery($conn, "
                UPDATE ownerships 
                SET ownership_end_date = ? 
                WHERE title_id = ? AND is_current = 1
            ", [$approval_date, $title['id']]);
            
            // Add new ownership
            executeQuery($conn, "
                INSERT INTO ownerships (
                    title_id, party_id, share_percentage, ownership_start_date, created_at
                ) VALUES (?, ?, 100.00, ?, NOW())
            ", [$title['id'], $app['to_party_id'], $approval_date]);
            
            // Record transaction
            executeQuery($conn, "
                INSERT INTO transactions (
                    transaction_type, parcel_id, title_id, from_party_id, to_party_id,
                    transaction_date, consideration_amount, details, created_at, created_by
                ) VALUES ('transfer', ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
            ", [
                $app['parcel_id'], 
                $title['id'], 
                $app['from_party_id'], 
                $app['to_party_id'], 
                $approval_date, 
                $app['consideration_amount'],
                "Transfer approved from application #$application_id. $approval_notes",
                $approved_by
            ]);
            
            // Update application status
            executeQuery($conn, "
                UPDATE applications SET status = 'approved', updated_at = NOW()
                WHERE id = ?
            ", [$application_id]);
            
            // Complete workflow
            executeQuery($conn, "
                UPDATE workflow_steps SET status = 'completed', completed_date = NOW()
                WHERE application_id = ? AND status = 'in_progress'
            ", [$application_id]);
            
            $conn->commit();
            
            $message = "Transfer approved and ownership updated successfully";
            $messageType = "success";
        } catch (Exception $e) {
            $conn->rollBack();
            $message = "Error approving transfer: " . $e->getMessage();
            $messageType = "danger";
        }
    }
    
    // Reject transfer
    if ($_POST['action'] === 'reject') {
        $application_id = $_POST['application_id'];
        $rejection_reason = $_POST['rejection_reason'] ?? null;
        
        try {
            executeQuery($conn, "
                UPDATE applications SET status = 'rejected', updated_at = NOW()
                WHERE id = ?
            ", [$application_id]);
            
            executeQuery($conn, "
                INSERT INTO workflow_steps (
                    application_id, step_name, status, comments, completed_date, created_at
                ) VALUES (?, 'Final Review', 'completed', ?, NOW(), NOW())
            ", [$application_id, "Application rejected. Reason: $rejection_reason"]);
            
            $message = "Transfer application rejected";
            $messageType = "warning";
        } catch (Exception $e) {
            $message = "Error rejecting application: " . $e->getMessage();
            $messageType = "danger";
        }
    }
}

// ============================================================================
// GET FILTERS AND DATA
// ============================================================================

// Create transfer_applications table if it doesn't exist
try {
    executeQuery($conn, "
        CREATE TABLE IF NOT EXISTS `transfer_applications` (
            `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
            `application_id` int UNSIGNED NOT NULL,
            `from_party_id` int UNSIGNED NOT NULL,
            `to_party_id` int UNSIGNED NOT NULL,
            `consideration_amount` decimal(15,2) DEFAULT NULL,
            `transfer_reason` varchar(100) DEFAULT NULL,
            `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `application_id` (`application_id`),
            KEY `from_party_id` (`from_party_id`),
            KEY `to_party_id` (`to_party_id`),
            CONSTRAINT `transfer_applications_ibfk_1` FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`) ON DELETE CASCADE,
            CONSTRAINT `transfer_applications_ibfk_2` FOREIGN KEY (`from_party_id`) REFERENCES `parties` (`id`),
            CONSTRAINT `transfer_applications_ibfk_3` FOREIGN KEY (`to_party_id`) REFERENCES `parties` (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
    ");
} catch (Exception $e) {
    // Table might already exist
}

// Get all parties for dropdowns
$parties = fetchAll($conn, "
    SELECT id, name, party_type, national_id, registration_number, phone, email
    FROM parties 
    ORDER BY name
");

// Get parcels with active titles for transfer
$parcels = fetchAll($conn, "
    SELECT 
        p.id,
        p.parcel_number,
        p.area,
        b.name as boma_name,
        pa.name as payam_name,
        c.name as county_name,
        s.name as state_name,
        t.id as title_id,
        t.title_number,
        -- Current owner
        o.party_id as owner_id,
        o2.name as owner_name,
        o2.party_type as owner_type
    FROM parcels p
    JOIN bomas b ON p.boma_id = b.id
    JOIN payams pa ON b.payam_id = pa.id
    JOIN counties c ON pa.county_id = c.id
    JOIN states s ON c.state_id = s.id
    JOIN titles t ON p.id = t.parcel_id AND t.status = 'active'
    JOIN ownerships o ON t.id = o.title_id AND o.is_current = 1
    JOIN parties o2 ON o.party_id = o2.id
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

// Build filter query for transfer applications
$whereClause = " WHERE a.application_type = 'transfer'";
$params = [];

if (isset($_GET['status']) && !empty($_GET['status'])) {
    $whereClause .= " AND a.status = ?";
    $params[] = $_GET['status'];
}

if (isset($_GET['from_party_id']) && !empty($_GET['from_party_id'])) {
    $whereClause .= " AND t.from_party_id = ?";
    $params[] = $_GET['from_party_id'];
}

if (isset($_GET['to_party_id']) && !empty($_GET['to_party_id'])) {
    $whereClause .= " AND t.to_party_id = ?";
    $params[] = $_GET['to_party_id'];
}

if (isset($_GET['party_id']) && !empty($_GET['party_id'])) {
    $whereClause .= " AND (t.from_party_id = ? OR t.to_party_id = ?)";
    $params[] = $_GET['party_id'];
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
    $whereClause .= " AND (a.id LIKE ? OR p.parcel_number LIKE ? OR from_party.name LIKE ? OR to_party.name LIKE ? OR a.details LIKE ?)";
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
}

// Get transfer applications with full details
$applications = fetchAll($conn, "
    SELECT 
        a.*,
        t.id as transfer_id,
        t.from_party_id,
        t.to_party_id,
        t.consideration_amount,
        t.transfer_reason,
        -- From party details
        from_party.name as from_party_name,
        from_party.party_type as from_party_type,
        from_party.national_id as from_national_id,
        from_party.registration_number as from_reg_number,
        from_party.phone as from_phone,
        from_party.email as from_email,
        -- To party details
        to_party.name as to_party_name,
        to_party.party_type as to_party_type,
        to_party.national_id as to_national_id,
        to_party.registration_number as to_reg_number,
        to_party.phone as to_phone,
        to_party.email as to_email,
        -- Parcel details
        p.id as parcel_id,
        p.parcel_number,
        p.area as parcel_area,
        p.location_description,
        b.id as boma_id,
        b.name as boma_name,
        pa.id as payam_id,
        pa.name as payam_name,
        c.id as county_id,
        c.name as county_name,
        s.id as state_id,
        s.name as state_name,
        -- Title details
        titles.id as title_id,
        titles.title_number,
        titles.title_type,
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
    JOIN transfer_applications t ON a.id = t.application_id
    JOIN parties from_party ON t.from_party_id = from_party.id
    JOIN parties to_party ON t.to_party_id = to_party.id
    JOIN parcels p ON a.parcel_id = p.id
    JOIN bomas b ON p.boma_id = b.id
    JOIN payams pa ON b.payam_id = pa.id
    JOIN counties c ON pa.county_id = c.id
    JOIN states s ON c.state_id = s.id
    LEFT JOIN titles ON p.id = titles.parcel_id AND titles.status = 'active'
    $whereClause
    ORDER BY 
        CASE a.status
            WHEN 'submitted' THEN 1
            WHEN 'under_review' THEN 2
            WHEN 'approved' THEN 3
            WHEN 'rejected' THEN 4
            WHEN 'completed' THEN 5
            ELSE 6
        END,
        a.submission_date DESC
", $params);

// Get summary statistics
$summary = fetchOne($conn, "
    SELECT 
        COUNT(*) as total_applications,
        SUM(CASE WHEN a.status = 'submitted' THEN 1 ELSE 0 END) as submitted,
        SUM(CASE WHEN a.status = 'under_review' THEN 1 ELSE 0 END) as under_review,
        SUM(CASE WHEN a.status = 'approved' THEN 1 ELSE 0 END) as approved,
        SUM(CASE WHEN a.status = 'rejected' THEN 1 ELSE 0 END) as rejected,
        SUM(CASE WHEN a.status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN t.consideration_amount IS NOT NULL THEN 1 ELSE 0 END) as with_consideration,
        SUM(t.consideration_amount) as total_consideration,
        AVG(t.consideration_amount) as avg_consideration,
        MIN(a.submission_date) as earliest,
        MAX(a.submission_date) as latest,
        AVG(DATEDIFF(NOW(), a.submission_date)) as avg_processing_days
    FROM applications a
    JOIN transfer_applications t ON a.id = t.application_id
    WHERE a.application_type = 'transfer'
");

// Get monthly trend
$monthly_stats = fetchAll($conn, "
    SELECT 
        YEAR(a.submission_date) as year,
        MONTH(a.submission_date) as month,
        COUNT(*) as count,
        SUM(CASE WHEN a.status = 'approved' THEN 1 ELSE 0 END) as approved_count,
        SUM(CASE WHEN a.status = 'rejected' THEN 1 ELSE 0 END) as rejected_count,
        SUM(t.consideration_amount) as total_value
    FROM applications a
    JOIN transfer_applications t ON a.id = t.application_id
    WHERE a.application_type = 'transfer'
      AND a.submission_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY YEAR(a.submission_date), MONTH(a.submission_date)
    ORDER BY year DESC, month DESC
");

$monthly_labels = [];
$monthly_counts = [];
$monthly_values = [];

foreach ($monthly_stats as $row) {
    $monthly_labels[] = date('M Y', mktime(0, 0, 0, $row['month'], 1, $row['year']));
    $monthly_counts[] = $row['count'];
    $monthly_values[] = $row['total_value'] ?? 0;
}

// Get top transfer reasons
$transfer_reasons = fetchAll($conn, "
    SELECT 
        transfer_reason,
        COUNT(*) as count
    FROM transfer_applications
    WHERE transfer_reason IS NOT NULL AND transfer_reason != ''
    GROUP BY transfer_reason
    ORDER BY count DESC
    LIMIT 5
");

// Get pending assignments
$pending_assignments = fetchAll($conn, "
    SELECT 
        ws.*,
        a.id as application_id,
        a.application_type,
        a.submission_date,
        p.parcel_number,
        CONCAT(from_party.name, ' → ', to_party.name) as parties,
        ws.assigned_to as assigned_to_id
    FROM workflow_steps ws
    JOIN applications a ON ws.application_id = a.id
    JOIN transfer_applications t ON a.id = t.application_id
    JOIN parcels p ON a.parcel_id = p.id
    JOIN parties from_party ON t.from_party_id = from_party.id
    JOIN parties to_party ON t.to_party_id = to_party.id
    WHERE a.application_type = 'transfer'
      AND ws.status IN ('pending', 'in_progress')
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
            ws.*,
            u.username as assigned_to_name
        FROM workflow_steps ws
        LEFT JOIN users u ON ws.assigned_to = u.id
        WHERE ws.application_id = ?
        ORDER BY ws.created_at
    ", [$_GET['view_workflow']]);
}
?>

<body data-page="applications-transfer" class="applications-transfer-page">
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
                                <i class="bi bi-arrow-left-right text-primary me-2"></i>
                                Transfer Applications
                            </h1>
                            <p class="text-muted mb-0">Manage ownership transfer applications and approvals</p>
                        </div>
                        <div>
                            <button class="btn btn-outline-secondary me-2" type="button" data-bs-toggle="collapse" data-bs-target="#filterCollapse">
                                <i class="bi bi-funnel me-2"></i>Filters
                            </button>
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTransferModal">
                                <i class="bi bi-plus-circle me-2"></i>New Transfer Application
                            </button>
                            <a href="applications.php" class="btn btn-outline-primary ms-2">
                                <i class="bi bi-list"></i> All Applications
                            </a>
                        </div>
                    </div>

                    <!-- Filter Collapse -->
                    <div class="collapse mb-4" id="filterCollapse">
                        <div class="card border-0 shadow-sm">
                            <div class="card-body">
                                <form method="GET" class="row g-3">
                                    <div class="col-md-3">
                                        <label class="form-label">Status</label>
                                        <select class="form-select" name="status">
                                            <option value="">All Statuses</option>
                                            <option value="submitted" <?php echo (isset($_GET['status']) && $_GET['status'] == 'submitted') ? 'selected' : ''; ?>>Submitted</option>
                                            <option value="under_review" <?php echo (isset($_GET['status']) && $_GET['status'] == 'under_review') ? 'selected' : ''; ?>>Under Review</option>
                                            <option value="approved" <?php echo (isset($_GET['status']) && $_GET['status'] == 'approved') ? 'selected' : ''; ?>>Approved</option>
                                            <option value="rejected" <?php echo (isset($_GET['status']) && $_GET['status'] == 'rejected') ? 'selected' : ''; ?>>Rejected</option>
                                            <option value="completed" <?php echo (isset($_GET['status']) && $_GET['status'] == 'completed') ? 'selected' : ''; ?>>Completed</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">From Owner</label>
                                        <select class="form-select" name="from_party_id">
                                            <option value="">All</option>
                                            <?php foreach ($parties as $party): ?>
                                            <option value="<?php echo $party['id']; ?>" <?php echo (isset($_GET['from_party_id']) && $_GET['from_party_id'] == $party['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($party['name']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">To Owner</label>
                                        <select class="form-select" name="to_party_id">
                                            <option value="">All</option>
                                            <?php foreach ($parties as $party): ?>
                                            <option value="<?php echo $party['id']; ?>" <?php echo (isset($_GET['to_party_id']) && $_GET['to_party_id'] == $party['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($party['name']); ?>
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
                                                <?php echo htmlspecialchars($parcel['parcel_number']); ?>
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
                                            <input type="text" class="form-control" name="search" placeholder="Application ID, Parcel #, Owner name..." 
                                                   value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
                                            <button class="btn btn-primary" type="submit">
                                                <i class="bi bi-search"></i>
                                            </button>
                                            <?php if (!empty($_GET)): ?>
                                            <a href="applications-transfer.php" class="btn btn-outline-secondary">
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
                        <div class="col-xl-3 col-md-6">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0 me-3">
                                            <div class="bg-primary bg-opacity-10 rounded-circle p-3">
                                                <i class="bi bi-file-text text-primary fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Total Transfers</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['total_applications'] ?? 0); ?></h3>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-xl-3 col-md-6">
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
                                            <small class="text-muted">Review: <?php echo $summary['under_review'] ?? 0; ?></small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-xl-3 col-md-6">
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
                        
                        <div class="col-xl-3 col-md-6">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0 me-3">
                                            <div class="bg-info bg-opacity-10 rounded-circle p-3">
                                                <i class="bi bi-cash-stack text-info fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Total Value</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['total_consideration'] ?? 0, 2); ?> SSP</h3>
                                            <small class="text-muted">Avg: <?php echo number_format($summary['avg_consideration'] ?? 0, 2); ?> SSP</small>
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
                                        <i class="bi bi-bar-chart text-primary me-2"></i>Monthly Transfer Applications
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
                                        <i class="bi bi-pie-chart text-success me-2"></i>Transfer Reasons
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <canvas id="reasonsChart" style="height: 300px;"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Pending Assignments Section -->
                    <?php if (!empty($pending_assignments)): ?>
                    <div class="card border-0 shadow-sm mb-5">
                        <div class="card-header bg-white py-3">
                            <h5 class="card-title mb-0 fw-bold text-warning">
                                <i class="bi bi-exclamation-triangle me-2"></i>Pending Transfer Tasks
                                <span class="badge bg-warning ms-2"><?php echo count($pending_assignments); ?></span>
                            </h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>App #</th>
                                            <th>Parcel</th>
                                            <th>Transfer</th>
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
                                            <td><?php echo htmlspecialchars($task['parcel_number']); ?></td>
                                            <td><?php echo htmlspecialchars($task['parties']); ?></td>
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

                    <!-- Main Transfer Applications Table -->
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0 fw-bold">
                                <i class="bi bi-table me-2"></i>Transfer Applications
                                <span class="badge bg-secondary ms-2"><?php echo count($applications); ?> records</span>
                            </h5>
                            <div>
                                <input type="text" class="form-control form-control-sm" style="width: 250px;" id="tableSearch" placeholder="Search in table...">
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0" id="transferTable">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>ID</th>
                                            <th>Parcel</th>
                                            <th>From Owner</th>
                                            <th>To Owner</th>
                                            <th>Amount</th>
                                            <th>Submitted</th>
                                            <th>Status</th>
                                            <th>Current Step</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($applications)): ?>
                                        <tr>
                                            <td colspan="9" class="text-center py-4 text-muted">
                                                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                                No transfer applications found
                                            </td>
                                        </tr>
                                        <?php else: ?>
                                            <?php foreach ($applications as $app): ?>
                                            <tr class="<?php echo ($app['days_pending'] ?? 0) > 30 ? 'table-warning' : ''; ?>">
                                                <td>
                                                    <strong>#<?php echo $app['id']; ?></strong>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars($app['parcel_number']); ?>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars($app['boma_name']); ?></small>
                                                </td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($app['from_party_name']); ?></strong>
                                                    <br><small class="text-muted"><?php echo ucfirst($app['from_party_type']); ?></small>
                                                </td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($app['to_party_name']); ?></strong>
                                                    <br><small class="text-muted"><?php echo ucfirst($app['to_party_type']); ?></small>
                                                </td>
                                                <td>
                                                    <?php if ($app['consideration_amount']): ?>
                                                        <strong><?php echo number_format($app['consideration_amount'], 2); ?> SSP</strong>
                                                    <?php else: ?>
                                                        <span class="text-muted">Gift/Inheritance</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php echo date('d M Y', strtotime($app['submission_date'])); ?>
                                                </td>
                                                <td>
                                                    <?php
                                                    $statusClass = match($app['status']) {
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
                                                                onclick="viewTransfer(<?php echo htmlspecialchars(json_encode($app)); ?>)"
                                                                data-bs-toggle="modal" data-bs-target="#viewTransferModal">
                                                            <i class="bi bi-eye"></i>
                                                        </button>
                                                        
                                                        <?php if ($app['status'] == 'submitted' || $app['status'] == 'under_review'): ?>
                                                            <?php if ($app['status'] == 'under_review'): ?>
                                                            <button class="btn btn-sm btn-outline-success" 
                                                                    onclick="approveTransfer(<?php echo htmlspecialchars(json_encode($app)); ?>)"
                                                                    data-bs-toggle="modal" data-bs-target="#approveTransferModal">
                                                                <i class="bi bi-check-circle"></i>
                                                            </button>
                                                            <?php endif; ?>
                                                            
                                                            <button class="btn btn-sm btn-outline-danger" 
                                                                    onclick="rejectTransfer(<?php echo htmlspecialchars(json_encode($app)); ?>)"
                                                                    data-bs-toggle="modal" data-bs-target="#rejectTransferModal">
                                                                <i class="bi bi-x-circle"></i>
                                                            </button>
                                                            
                                                            <button class="btn btn-sm btn-outline-info" 
                                                                    onclick="assignTransfer(<?php echo htmlspecialchars(json_encode($app)); ?>)"
                                                                    data-bs-toggle="modal" data-bs-target="#assignModal">
                                                                <i class="bi bi-person-plus"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                        
                                                        <a href="?view_workflow=<?php echo $app['id']; ?>#workflow" class="btn btn-sm btn-outline-secondary">
                                                            <i class="bi bi-diagram-3"></i>
                                                        </a>
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

                    <!-- Workflow Steps Section -->
                    <?php if (isset($_GET['view_workflow']) && !empty($workflow_steps)): ?>
                    <div class="card border-0 shadow-sm mt-4" id="workflow">
                        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0 fw-bold">
                                <i class="bi bi-diagram-3 text-info me-2"></i>
                                Workflow Steps - Transfer #<?php echo htmlspecialchars($_GET['view_workflow']); ?>
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
                                                    <?php if ($step['assigned_to_name']): ?>
                                                    <br><small class="text-muted">Assigned to: <?php echo htmlspecialchars($step['assigned_to_name']); ?></small>
                                                    <?php endif; ?>
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
                            <a href="applications-transfer.php" class="btn btn-sm btn-secondary">Back to Transfers</a>
                        </div>
                    </div>
                    <?php endif; ?>

                </div>
            </main>

            <!-- Footer -->
            <?php include("footer.php"); ?>

        </div>
    </div>

    <!-- Add Transfer Application Modal -->
    <div class="modal fade" id="addTransferModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="add">
                    <div class="modal-header">
                        <h5 class="modal-title">New Transfer Application</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Parcel <span class="text-danger">*</span></label>
                                <select class="form-select" name="parcel_id" required id="transferParcelSelect">
                                    <option value="">Select Parcel</option>
                                    <?php foreach ($parcels as $parcel): ?>
                                    <option value="<?php echo $parcel['id']; ?>" 
                                            data-owner-id="<?php echo $parcel['owner_id']; ?>"
                                            data-owner-name="<?php echo htmlspecialchars($parcel['owner_name']); ?>">
                                        <?php echo htmlspecialchars($parcel['parcel_number'] . ' - ' . $parcel['boma_name'] . ' (Current: ' . $parcel['owner_name'] . ')'); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Current Owner (From)</label>
                                <input type="text" class="form-control" id="currentOwnerDisplay" readonly>
                                <input type="hidden" name="from_party_id" id="fromPartyId">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">New Owner (To) <span class="text-danger">*</span></label>
                                <select class="form-select" name="to_party_id" required>
                                    <option value="">Select New Owner</option>
                                    <?php foreach ($parties as $party): ?>
                                    <option value="<?php echo $party['id']; ?>">
                                        <?php echo htmlspecialchars($party['name'] . ' (' . ucfirst($party['party_type']) . ')'); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Applicant (Person submitting)</label>
                                <select class="form-select" name="applicant_party_id" required>
                                    <option value="">Select Applicant</option>
                                    <?php foreach ($parties as $party): ?>
                                    <option value="<?php echo $party['id']; ?>">
                                        <?php echo htmlspecialchars($party['name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">Usually the buyer or their representative</small>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Submission Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="submission_date" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Consideration Amount (SSP)</label>
                                <input type="number" class="form-control" name="consideration_amount" step="0.01" placeholder="0.00">
                                <small class="text-muted">Leave empty for gift/inheritance</small>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Transfer Reason</label>
                                <select class="form-select" name="transfer_reason">
                                    <option value="">Select Reason</option>
                                    <option value="sale">Sale/Purchase</option>
                                    <option value="gift">Gift</option>
                                    <option value="inheritance">Inheritance</option>
                                    <option value="family_transfer">Family Transfer</option>
                                    <option value="company_transfer">Company Transfer</option>
                                    <option value="court_order">Court Order</option>
                                </select>
                            </div>
                            
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Additional Details</label>
                                <textarea class="form-control" name="details" rows="3" 
                                          placeholder="Any additional information about the transfer..."></textarea>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Initial Status</label>
                                <select class="form-select" name="status">
                                    <option value="submitted">Submitted</option>
                                    <option value="draft">Draft</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="alert alert-info mt-3">
                            <i class="bi bi-info-circle me-2"></i>
                            <strong>Note:</strong> Upon approval, ownership will be automatically transferred and a transaction record will be created.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Submit Transfer Application</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Transfer Modal -->
    <div class="modal fade" id="viewTransferModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Transfer Application Details</h5>
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
                            <h6 class="border-bottom pb-2">Transfer Information</h6>
                            <table class="table table-borderless">
                                <tr>
                                    <th width="40%">Amount:</th>
                                    <td id="viewAmount"></td>
                                </tr>
                                <tr>
                                    <th>Reason:</th>
                                    <td id="viewReason"></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    
                    <div class="row mt-3">
                        <div class="col-md-6">
                            <h6 class="border-bottom pb-2">From Owner (Current)</h6>
                            <table class="table table-borderless">
                                <tr>
                                    <th width="40%">Name:</th>
                                    <td id="viewFromName"></td>
                                </tr>
                                <tr>
                                    <th>Type:</th>
                                    <td id="viewFromType"></td>
                                </tr>
                                <tr>
                                    <th>ID/Reg:</th>
                                    <td id="viewFromId"></td>
                                </tr>
                                <tr>
                                    <th>Contact:</th>
                                    <td id="viewFromContact"></td>
                                </tr>
                            </table>
                        </div>
                        
                        <div class="col-md-6">
                            <h6 class="border-bottom pb-2">To Owner (New)</h6>
                            <table class="table table-borderless">
                                <tr>
                                    <th width="40%">Name:</th>
                                    <td id="viewToName"></td>
                                </tr>
                                <tr>
                                    <th>Type:</th>
                                    <td id="viewToType"></td>
                                </tr>
                                <tr>
                                    <th>ID/Reg:</th>
                                    <td id="viewToId"></td>
                                </tr>
                                <tr>
                                    <th>Contact:</th>
                                    <td id="viewToContact"></td>
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
                                <tr>
                                    <th>Title #:</th>
                                    <td colspan="3" id="viewTitleNumber"></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    
                    <div class="row mt-3">
                        <div class="col-12">
                            <h6 class="border-bottom pb-2">Additional Details</h6>
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

    <!-- Approve Transfer Modal -->
    <div class="modal fade" id="approveTransferModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="approve">
                    <input type="hidden" name="application_id" id="approveApplicationId">
                    <div class="modal-header">
                        <h5 class="modal-title">Approve Transfer</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p>You are about to approve transfer application <strong id="approveApplicationInfo"></strong></p>
                        <p class="text-success"><i class="bi bi-info-circle"></i> This will:</p>
                        <ul class="text-success">
                            <li>End current ownership</li>
                            <li>Create new ownership for the buyer</li>
                            <li>Record the transaction</li>
                            <li>Mark the application as approved</li>
                        </ul>
                        
                        <div class="mb-3">
                            <label class="form-label">Transfer/Approval Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="approval_date" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Approval Notes</label>
                            <textarea class="form-control" name="approval_notes" rows="3" placeholder="Any additional notes..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Approve Transfer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Reject Transfer Modal -->
    <div class="modal fade" id="rejectTransferModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="reject">
                    <input type="hidden" name="application_id" id="rejectApplicationId">
                    <div class="modal-header">
                        <h5 class="modal-title">Reject Transfer</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p>Are you sure you want to reject transfer application <strong id="rejectApplicationInfo"></strong>?</p>
                        
                        <div class="mb-3">
                            <label class="form-label">Reason for Rejection <span class="text-danger">*</span></label>
                            <textarea class="form-control" name="rejection_reason" rows="3" required placeholder="Explain why this transfer is being rejected..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Reject Application</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Assign Modal -->
    <div class="modal fade" id="assignModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="assign">
                    <input type="hidden" name="application_id" id="assignApplicationId">
                    <div class="modal-header">
                        <h5 class="modal-title">Assign Transfer Application</h5>
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
                                   placeholder="e.g., Document Verification, Title Search, Final Approval">
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
                const monthlyValues = <?php echo json_encode($monthly_values); ?>;
                
                new Chart(monthlyCtx, {
                    type: 'bar',
                    data: {
                        labels: monthlyLabels,
                        datasets: [
                            {
                                label: 'Number of Transfers',
                                data: monthlyData,
                                backgroundColor: '#0d6efd',
                                yAxisID: 'y',
                            },
                            {
                                label: 'Total Value (1,000 SSP)',
                                data: monthlyValues.map(v => v / 1000),
                                type: 'line',
                                borderColor: '#dc3545',
                                backgroundColor: 'transparent',
                                yAxisID: 'y1',
                                tension: 0.4
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: 'Number of Transfers'
                                },
                                ticks: {
                                    stepSize: 1,
                                    precision: 0
                                }
                            },
                            y1: {
                                beginAtZero: true,
                                position: 'right',
                                title: {
                                    display: true,
                                    text: 'Value (1,000 SSP)'
                                },
                                grid: {
                                    drawOnChartArea: false
                                }
                            }
                        }
                    }
                });
            }
            
            // Reasons Chart
            const reasonsCtx = document.getElementById('reasonsChart')?.getContext('2d');
            if (reasonsCtx && <?php echo !empty($transfer_reasons) ? 'true' : 'false'; ?>) {
                const reasonsLabels = [
                    <?php foreach ($transfer_reasons as $reason): ?>
                    '<?php echo ucfirst($reason['transfer_reason']); ?>',
                    <?php endforeach; ?>
                ];
                const reasonsData = [
                    <?php foreach ($transfer_reasons as $reason): ?>
                    <?php echo $reason['count']; ?>,
                    <?php endforeach; ?>
                ];
                
                new Chart(reasonsCtx, {
                    type: 'doughnut',
                    data: {
                        labels: reasonsLabels,
                        datasets: [{
                            data: reasonsData,
                            backgroundColor: ['#0d6efd', '#6610f2', '#6f42c1', '#d63384', '#dc3545'],
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
        });
        
        // Handle parcel selection
        document.getElementById('transferParcelSelect')?.addEventListener('change', function() {
            const selected = this.options[this.selectedIndex];
            const ownerId = selected.dataset.ownerId;
            const ownerName = selected.dataset.ownerName;
            
            document.getElementById('currentOwnerDisplay').value = ownerName || '';
            document.getElementById('fromPartyId').value = ownerId || '';
        });
        
        // View transfer details
        function viewTransfer(app) {
            document.getElementById('viewId').textContent = '#' + app.id;
            
            let statusClass = 'secondary';
            if (app.status === 'submitted') statusClass = 'info';
            else if (app.status === 'under_review') statusClass = 'primary';
            else if (app.status === 'approved') statusClass = 'success';
            else if (app.status === 'rejected') statusClass = 'danger';
            else if (app.status === 'completed') statusClass = 'success';
            
            document.getElementById('viewStatus').innerHTML = `<span class="badge bg-${statusClass}">${app.status.replace('_', ' ')}</span>`;
            document.getElementById('viewSubmissionDate').textContent = formatDate(app.submission_date);
            document.getElementById('viewDaysPending').textContent = (app.days_pending || 0) + ' days';
            
            document.getElementById('viewAmount').textContent = app.consideration_amount ? 
                number_format(app.consideration_amount, 2) + ' SSP' : 'Gift/Inheritance';
            document.getElementById('viewReason').textContent = app.transfer_reason ? 
                ucfirst(app.transfer_reason) : 'Not specified';
            
            // From owner
            document.getElementById('viewFromName').textContent = app.from_party_name || 'N/A';
            document.getElementById('viewFromType').textContent = app.from_party_type ? ucfirst(app.from_party_type) : 'N/A';
            document.getElementById('viewFromId').textContent = app.from_national_id || app.from_reg_number || 'N/A';
            document.getElementById('viewFromContact').textContent = (app.from_phone || app.from_email) || 'N/A';
            
            // To owner
            document.getElementById('viewToName').textContent = app.to_party_name || 'N/A';
            document.getElementById('viewToType').textContent = app.to_party_type ? ucfirst(app.to_party_type) : 'N/A';
            document.getElementById('viewToId').textContent = app.to_national_id || app.to_reg_number || 'N/A';
            document.getElementById('viewToContact').textContent = (app.to_phone || app.to_email) || 'N/A';
            
            // Parcel
            document.getElementById('viewParcelNumber').textContent = app.parcel_number || 'N/A';
            document.getElementById('viewParcelArea').textContent = app.parcel_area ? parseFloat(app.parcel_area).toFixed(2) + ' m²' : 'N/A';
            
            let location = 'N/A';
            if (app.boma_name) {
                location = app.boma_name;
                if (app.payam_name) location += ', ' + app.payam_name;
                if (app.county_name) location += ', ' + app.county_name;
            }
            document.getElementById('viewLocation').textContent = location;
            document.getElementById('viewTitleNumber').textContent = app.title_number || 'No active title';
            
            document.getElementById('viewDetails').textContent = app.details || 'No additional details';
            
            const totalSteps = app.total_steps || 0;
            const completedSteps = app.completed_steps || 0;
            const progress = totalSteps > 0 ? (completedSteps / totalSteps) * 100 : 0;
            
            document.getElementById('viewProgress').style.width = progress + '%';
            document.getElementById('viewProgress').textContent = Math.round(progress) + '%';
            document.getElementById('viewCompletedSteps').textContent = completedSteps;
            document.getElementById('viewTotalSteps').textContent = totalSteps;
            document.getElementById('viewCurrentStepText').textContent = app.current_step || 'No active step';
        }
        
        // Approve transfer
        function approveTransfer(app) {
            document.getElementById('approveApplicationId').value = app.id;
            document.getElementById('approveApplicationInfo').textContent = '#' + app.id + ' - ' + app.parcel_number + ' (' + app.from_party_name + ' → ' + app.to_party_name + ')';
        }
        
        // Reject transfer
        function rejectTransfer(app) {
            document.getElementById('rejectApplicationId').value = app.id;
            document.getElementById('rejectApplicationInfo').textContent = '#' + app.id + ' - ' + app.parcel_number;
        }
        
        // Assign application
        function assignTransfer(app) {
            document.getElementById('assignApplicationId').value = app.id;
            document.getElementById('assignApplicationInfo').value = '#' + app.id + ' - ' + app.parcel_number + ' (' + app.from_party_name + ' → ' + app.to_party_name + ')';
            document.getElementById('assignCurrentStep').value = app.current_step || 'No current step';
        }
        
        // Complete step
        function completeStep(task) {
            document.getElementById('completeStepId').value = task.id;
            document.getElementById('completeStepName').value = task.step_name;
            document.getElementById('completeApplicationInfo').value = 'Transfer #' + task.application_id;
        }
        
        // Format date
        function formatDate(dateString) {
            if (!dateString) return 'N/A';
            const options = { year: 'numeric', month: 'short', day: 'numeric' };
            return new Date(dateString).toLocaleDateString(undefined, options);
        }
        
        // Number format
        function number_format(num, decimals) {
            return num.toFixed(decimals).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        }
        
        // Capitalize first letter
        function ucfirst(str) {
            return str.charAt(0).toUpperCase() + str.slice(1);
        }
        
        // Search functionality
        document.getElementById('tableSearch')?.addEventListener('keyup', function() {
            const searchText = this.value.toLowerCase();
            const table = document.getElementById('transferTable');
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