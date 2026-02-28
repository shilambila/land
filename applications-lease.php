<?php
ini_set('display_errors', 1); 
error_reporting(E_ALL);

// applications-lease.php - Lease Application Management
include("head.php");
require_once 'db_connection.php';

// ============================================================================
// HANDLE ACTIONS
// ============================================================================

$message = '';
$messageType = '';

// Delete lease application
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $applicationId = $_GET['delete'];
    
    try {
        // Check if application has workflow steps
        $steps = fetchOne($conn, "SELECT COUNT(*) as count FROM workflow_steps WHERE application_id = ?", [$applicationId]);
        
        if ($steps && $steps['count'] > 0) {
            // Soft delete - mark as rejected
            executeQuery($conn, "UPDATE applications SET status = 'rejected' WHERE id = ? AND application_type = 'lease'", [$applicationId]);
            $message = "Lease application rejected (has workflow steps)";
            $messageType = "warning";
        } else {
            executeQuery($conn, "DELETE FROM applications WHERE id = ? AND application_type = 'lease'", [$applicationId]);
            $message = "Lease application deleted successfully";
            $messageType = "success";
        }
    } catch (Exception $e) {
        $message = "Error deleting application: " . $e->getMessage();
        $messageType = "danger";
        error_log("Delete error: " . $e->getMessage());
    }
}

// Add new lease application
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $parcel_id = $_POST['parcel_id'];
    $applicant_party_id = $_POST['applicant_party_id'];
    $submission_date = $_POST['submission_date'];
    $status = $_POST['status'] ?? 'submitted';
    
    // Lease specific fields
    $lease_term_years = $_POST['lease_term_years'] ?? 0;
    $proposed_start_date = $_POST['proposed_start_date'] ?? null;
    $proposed_end_date = $_POST['proposed_end_date'] ?? null;
    $annual_rent_proposed = $_POST['annual_rent_proposed'] ?? 0;
    $rent_payment_frequency = $_POST['rent_payment_frequency'] ?? 'annual';
    $proposed_use = $_POST['proposed_use'] ?? null;
    $lessor_party_id = $_POST['lessor_party_id'] ?? null;
    $details = $_POST['details'] ?? null;
    
    // Validate lease term
    if ($lease_term_years < 1 || $lease_term_years > 99) {
        $message = "Lease term must be between 1 and 99 years";
        $messageType = "danger";
    } else {
        try {
            // Start transaction
            beginTransaction($conn);
            
            // Check if parcel exists
            $parcel = fetchOne($conn, "
                SELECT p.*, t.id as title_id, t.title_number, t.title_type 
                FROM parcels p
                LEFT JOIN titles t ON p.id = t.parcel_id AND t.status = 'active'
                WHERE p.id = ?
            ", [$parcel_id]);
            
            if (!$parcel) {
                throw new Exception("Parcel not found");
            }
            
            // Check for existing pending lease applications
            $existing = fetchOne($conn, "
                SELECT id FROM applications 
                WHERE parcel_id = ? AND application_type = 'lease' 
                AND status IN ('submitted', 'under_review')
            ", [$parcel_id]);
            
            if ($existing) {
                throw new Exception("There is already a pending lease application for this parcel");
            }
            
            // Prepare details with lease info as JSON
            $lease_info = [
                'lease_term_years' => $lease_term_years,
                'proposed_start_date' => $proposed_start_date,
                'proposed_end_date' => $proposed_end_date,
                'annual_rent_proposed' => $annual_rent_proposed,
                'rent_payment_frequency' => $rent_payment_frequency,
                'proposed_use' => $proposed_use,
                'lessor_party_id' => $lessor_party_id,
                'original_parcel' => $parcel['parcel_number'],
                'original_title' => $parcel['title_number'] ?? 'No title'
            ];
            
            $details_json = json_encode($lease_info);
            
            // Insert the application
            executeQuery($conn, "
                INSERT INTO applications (
                    application_type, parcel_id, applicant_party_id, submission_date, 
                    status, details, created_at
                ) VALUES ('lease', ?, ?, ?, ?, ?, NOW())
            ", [$parcel_id, $applicant_party_id, $submission_date, $status, $details_json]);
            
            $application_id = $conn->lastInsertId();
            
            // Create initial workflow steps
            $workflow_steps = [
                ['step_name' => 'Document Verification', 'due_date' => date('Y-m-d', strtotime('+7 days'))],
                ['step_name' => 'Lease Terms Review', 'due_date' => date('Y-m-d', strtotime('+14 days'))],
                ['step_name' => 'Rent Assessment', 'due_date' => date('Y-m-d', strtotime('+21 days'))],
                ['step_name' => 'Final Approval', 'due_date' => date('Y-m-d', strtotime('+28 days'))]
            ];
            
            foreach ($workflow_steps as $step) {
                executeQuery($conn, "
                    INSERT INTO workflow_steps (
                        application_id, step_name, status, due_date, created_at
                    ) VALUES (?, ?, 'pending', ?, NOW())
                ", [$application_id, $step['step_name'], $step['due_date']]);
            }
            
            // Handle file upload if present
            if (isset($_FILES['lease_document']) && $_FILES['lease_document']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = 'uploads/applications/lease/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $file_extension = pathinfo($_FILES['lease_document']['name'], PATHINFO_EXTENSION);
                $file_name = 'lease_' . $application_id . '_' . time() . '.' . $file_extension;
                $file_path = $upload_dir . $file_name;
                
                if (move_uploaded_file($_FILES['lease_document']['tmp_name'], $file_path)) {
                    executeQuery($conn, "
                        INSERT INTO documents (
                            document_type, parcel_id, application_id, file_name, file_path, 
                            mime_type, uploaded_by, uploaded_at, description
                        ) VALUES ('lease_agreement', ?, ?, ?, ?, ?, ?, NOW(), ?)
                    ", [$parcel_id, $application_id, $_FILES['lease_document']['name'], $file_path, 
                        $_FILES['lease_document']['type'], $_SESSION['user_id'] ?? 1, 
                        "Lease agreement for application #" . $application_id]);
                }
            }
            
            // Commit transaction
            commitTransaction($conn);
            
            $message = "Lease application submitted successfully. Application ID: " . $application_id;
            $messageType = "success";
            
        } catch (Exception $e) {
            rollbackTransaction($conn);
            $message = "Error submitting application: " . $e->getMessage();
            $messageType = "danger";
            error_log("Add error: " . $e->getMessage());
        }
    }
}

// Approve lease application and create leasehold title
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'approve') {
    $application_id = $_POST['application_id'];
    $title_number = $_POST['title_number'];
    $issue_date = $_POST['issue_date'];
    $expiry_date = $_POST['expiry_date'];
    $annual_rent = $_POST['annual_rent'];
    $rent_due_date = $_POST['rent_due_date'] ?? date('Y-01-01', strtotime('+1 year'));
    
    try {
        // Start transaction
        beginTransaction($conn);
        
        // Get application details
        $application = fetchOne($conn, "
            SELECT a.*, p.id as parcel_id, p.parcel_number 
            FROM applications a
            JOIN parcels p ON a.parcel_id = p.id
            WHERE a.id = ? AND a.application_type = 'lease'
        ", [$application_id]);
        
        if (!$application) {
            throw new Exception("Application not found");
        }
        
        // Check if title number already exists
        $exists = fetchOne($conn, "SELECT id FROM titles WHERE title_number = ?", [$title_number]);
        if ($exists) {
            throw new Exception("Title number already exists");
        }
        
        // Create leasehold title
        executeQuery($conn, "
            INSERT INTO titles (
                parcel_id, title_number, title_type, issue_date, expiry_date, 
                status, notes, created_at
            ) VALUES (?, ?, 'leasehold', ?, ?, 'active', ?, NOW())
        ", [$application['parcel_id'], $title_number, $issue_date, $expiry_date, 
            "Created from lease application #" . $application_id]);
        
        $title_id = $conn->lastInsertId();
        
        // Add owner to ownerships table (lessee/applicant)
        executeQuery($conn, "
            INSERT INTO ownerships (
                title_id, party_id, share_percentage, ownership_start_date, created_at
            ) VALUES (?, ?, 100, ?, NOW())
        ", [$title_id, $application['applicant_party_id'], $issue_date]);
        
        // Create rent record
        executeQuery($conn, "
            INSERT INTO leasehold_rent (
                title_id, rent_year, rent_amount, due_date, status, created_at
            ) VALUES (?, YEAR(?), ?, ?, 'pending', NOW())
        ", [$title_id, $issue_date, $annual_rent, $rent_due_date]);
        
        // Update application status
        executeQuery($conn, "
            UPDATE applications SET status = 'approved', updated_at = NOW() 
            WHERE id = ?
        ", [$application_id]);
        
        // Commit transaction
        commitTransaction($conn);
        
        $message = "Lease application approved and leasehold title created successfully";
        $messageType = "success";
        
    } catch (Exception $e) {
        rollbackTransaction($conn);
        $message = "Error approving application: " . $e->getMessage();
        $messageType = "danger";
        error_log("Approve error: " . $e->getMessage());
    }
}

// ============================================================================
// GET ALL LEASE APPLICATIONS - SIMPLIFIED VERSION
// ============================================================================

try {
    // Simplified query without JSON_EXTRACT
    $sql = "SELECT 
                a.id,
                a.application_type,
                a.parcel_id,
                a.applicant_party_id,
                a.submission_date,
                a.status,
                a.details,
                a.created_at,
                a.updated_at,
                p.parcel_number,
                p.area as parcel_area,
                p.location_description,
                b.name as boma_name,
                pa.name as payam_name,
                c.name as county_name,
                s.name as state_name,
                applicant.id as applicant_id,
                applicant.name as applicant_name,
                applicant.party_type as applicant_type,
                applicant.phone as applicant_phone,
                applicant.email as applicant_email,
                t.id as title_id,
                t.title_number,
                t.title_type
            FROM applications a
            LEFT JOIN parcels p ON a.parcel_id = p.id
            LEFT JOIN bomas b ON p.boma_id = b.id
            LEFT JOIN payams pa ON b.payam_id = pa.id
            LEFT JOIN counties c ON pa.county_id = c.id
            LEFT JOIN states s ON c.state_id = s.id
            LEFT JOIN parties applicant ON a.applicant_party_id = applicant.id
            LEFT JOIN titles t ON p.id = t.parcel_id AND t.status = 'active'
            WHERE a.application_type = 'lease'
            ORDER BY a.submission_date DESC, a.id DESC";
    
    $result = executeQuery($conn, $sql);
    
    if ($result) {
        $applications = [];
        while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
            // Parse JSON details if it's valid JSON
            if (!empty($row['details']) && $row['details'][0] == '{') {
                $details_json = json_decode($row['details'], true);
                if ($details_json) {
                    // Merge JSON details into the row
                    foreach ($details_json as $key => $value) {
                        $row[$key] = $value;
                    }
                }
            }
            $applications[] = $row;
        }
    } else {
        $applications = [];
    }
    
} catch (Exception $e) {
    error_log("Error loading lease applications: " . $e->getMessage());
    $applications = [];
    $message = "Error loading applications: " . $e->getMessage();
    $messageType = "danger";
}

// ============================================================================
// GET DOCUMENT AND WORKFLOW COUNTS SEPARATELY
// ============================================================================

// Get document counts for applications
try {
    $doc_counts = fetchAll($conn, "
        SELECT application_id, COUNT(*) as count 
        FROM documents 
        WHERE application_id IS NOT NULL 
        GROUP BY application_id
    ");
    $doc_count_map = [];
    foreach ($doc_counts as $item) {
        $doc_count_map[$item['application_id']] = $item['count'];
    }
} catch (Exception $e) {
    error_log("Error loading document counts: " . $e->getMessage());
    $doc_count_map = [];
}

// Get workflow step counts for applications
try {
    $workflow_counts = fetchAll($conn, "
        SELECT 
            application_id, 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
        FROM workflow_steps 
        WHERE application_id IS NOT NULL 
        GROUP BY application_id
    ");
    $workflow_map = [];
    foreach ($workflow_counts as $item) {
        $workflow_map[$item['application_id']] = [
            'total' => $item['total'],
            'completed' => $item['completed']
        ];
    }
} catch (Exception $e) {
    error_log("Error loading workflow counts: " . $e->getMessage());
    $workflow_map = [];
}

// Merge counts into applications array
foreach ($applications as &$app) {
    $app['document_count'] = $doc_count_map[$app['id']] ?? 0;
    $app['workflow_step_count'] = $workflow_map[$app['id']]['total'] ?? 0;
    $app['completed_steps'] = $workflow_map[$app['id']]['completed'] ?? 0;
}

// ============================================================================
// GET DATA FOR DROPDOWNS
// ============================================================================

try {
    // Get parcels eligible for lease (must have title)
    $eligible_parcels = fetchAll($conn, "
        SELECT p.id, p.parcel_number, p.area, p.location_description,
               b.name as boma_name, pa.name as payam_name, c.name as county_name,
               t.id as title_id, t.title_number, t.title_type,
               CONCAT(owner.name, ' (', o.share_percentage, '%)') as owner_name
        FROM parcels p
        JOIN bomas b ON p.boma_id = b.id
        JOIN payams pa ON b.payam_id = pa.id
        JOIN counties c ON pa.county_id = c.id
        JOIN titles t ON p.id = t.parcel_id AND t.status = 'active'
        LEFT JOIN ownerships o ON t.id = o.title_id AND o.is_current = 1
        LEFT JOIN parties owner ON o.party_id = owner.id
        WHERE t.title_type IN ('freehold', 'leasehold', 'customary')
        ORDER BY p.parcel_number
    ");
} catch (Exception $e) {
    error_log("Error loading eligible parcels: " . $e->getMessage());
    $eligible_parcels = [];
}

try {
    // Get parties for applicant selection (potential lessees)
    $parties = fetchAll($conn, "
        SELECT id, name, party_type, national_id, registration_number, phone, email
        FROM parties 
        ORDER BY name
    ");
} catch (Exception $e) {
    error_log("Error loading parties: " . $e->getMessage());
    $parties = [];
}

try {
    // Get potential lessors (current title owners)
    $lessors = fetchAll($conn, "
        SELECT DISTINCT p.id, p.name, p.party_type
        FROM parties p
        JOIN ownerships o ON p.id = o.party_id
        JOIN titles t ON o.title_id = t.id
        WHERE o.is_current = 1
        ORDER BY p.name
    ");
} catch (Exception $e) {
    error_log("Error loading lessors: " . $e->getMessage());
    $lessors = [];
}

// ============================================================================
// SUMMARY STATISTICS
// ============================================================================

try {
    $summary = fetchOne($conn, "
        SELECT 
            COUNT(*) as total_applications,
            SUM(CASE WHEN status = 'submitted' THEN 1 ELSE 0 END) as submitted,
            SUM(CASE WHEN status = 'under_review' THEN 1 ELSE 0 END) as under_review,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
            COUNT(DISTINCT applicant_party_id) as unique_applicants
        FROM applications
        WHERE application_type = 'lease'
    ");
} catch (Exception $e) {
    error_log("Error loading summary: " . $e->getMessage());
    $summary = [
        'total_applications' => 0,
        'submitted' => 0,
        'under_review' => 0,
        'approved' => 0,
        'rejected' => 0,
        'completed' => 0,
        'unique_applicants' => 0
    ];
}

try {
    // Monthly trend
    $monthly_trend = fetchAll($conn, "
        SELECT 
            DATE_FORMAT(submission_date, '%Y-%m') as month,
            COUNT(*) as count
        FROM applications
        WHERE application_type = 'lease'
            AND submission_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(submission_date, '%Y-%m')
        ORDER BY month DESC
    ");
} catch (Exception $e) {
    error_log("Error loading monthly trend: " . $e->getMessage());
    $monthly_trend = [];
}
?>

<body data-page="applications-lease" class="applications-lease-page">
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
                    <div class="d-flex justify-content-between align-items-center mb-4 mb-lg-5">
                        <div>
                            <h1 class="h3 mb-0">Lease Applications</h1>
                            <p class="text-muted mb-0">Manage land lease applications and approvals</p>
                        </div>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addLeaseModal">
                            <i class="bi bi-file-earmark-text me-2"></i>New Lease Application
                        </button>
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
                                                <i class="bi bi-clock text-warning fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Submitted</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['submitted'] ?? 0); ?></h3>
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
                                                <i class="bi bi-search text-info fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Under Review</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['under_review'] ?? 0); ?></h3>
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
                                                <i class="bi bi-people text-secondary fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Applicants</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['unique_applicants'] ?? 0); ?></h3>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Lease Applications Table -->
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0 fw-bold">
                                <i class="bi bi-file-text text-primary me-2"></i>
                                Lease Applications
                            </h5>
                            <span class="badge bg-primary"><?php echo number_format(count($applications)); ?> Records</span>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>ID</th>
                                            <th>Parcel</th>
                                            <th>Location</th>
                                            <th>Applicant (Lessee)</th>
                                            <th>Term</th>
                                            <th>Annual Rent</th>
                                            <th>Submission Date</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($applications)): ?>
                                        <tr>
                                            <td colspan="9" class="text-center py-4 text-muted">
                                                <i class="bi bi-file-text fs-1 d-block mb-3"></i>
                                                No lease applications found. 
                                                <a href="#" data-bs-toggle="modal" data-bs-target="#addLeaseModal">Click here</a> to create one.
                                            </td>
                                        </tr>
                                        <?php else: ?>
                                            <?php foreach ($applications as $app): ?>
                                            <tr>
                                                <td>
                                                    <span class="fw-medium">#<?php echo $app['id']; ?></span>
                                                    <?php if (($app['workflow_step_count'] ?? 0) > 0): ?>
                                                        <br><small class="text-muted"><?php echo ($app['completed_steps'] ?? 0); ?>/<?php echo ($app['workflow_step_count'] ?? 0); ?> steps</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="fw-medium"><?php echo htmlspecialchars($app['parcel_number'] ?? 'N/A'); ?></span>
                                                    <?php if (!empty($app['parcel_area'])): ?>
                                                        <br><small class="text-muted">Area: <?php echo number_format($app['parcel_area'], 2); ?> m²</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <small>
                                                        <?php echo htmlspecialchars($app['boma_name'] ?? ''); ?>
                                                        <?php if (!empty($app['payam_name'])): ?><br><?php echo $app['payam_name']; ?><?php endif; ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <span class="fw-medium"><?php echo htmlspecialchars($app['applicant_name'] ?? 'N/A'); ?></span>
                                                    <br><small class="text-muted"><?php echo ucfirst($app['applicant_type'] ?? ''); ?></small>
                                                </td>
                                                <td>
                                                    <?php if (!empty($app['lease_term_years'])): ?>
                                                        <span class="badge bg-info"><?php echo $app['lease_term_years']; ?> years</span>
                                                    <?php else: ?>
                                                        <span class="text-muted">N/A</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (!empty($app['annual_rent_proposed'])): ?>
                                                        <span class="fw-medium">$<?php echo number_format($app['annual_rent_proposed'], 2); ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted">N/A</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php echo $app['submission_date'] ? date('d M Y', strtotime($app['submission_date'])) : 'N/A'; ?>
                                                </td>
                                                <td>
                                                    <?php
                                                    $status_class = [
                                                        'submitted' => 'warning',
                                                        'under_review' => 'info',
                                                        'approved' => 'success',
                                                        'rejected' => 'danger',
                                                        'completed' => 'secondary',
                                                        'draft' => 'secondary'
                                                    ][$app['status']] ?? 'secondary';
                                                    ?>
                                                    <span class="badge bg-<?php echo $status_class; ?>">
                                                        <?php echo ucfirst(str_replace('_', ' ', $app['status'] ?? 'unknown')); ?>
                                                    </span>
                                                    <br><small class="text-muted">Docs: <?php echo $app['document_count'] ?? 0; ?></small>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <button class="btn btn-outline-info" 
                                                                onclick="viewApplication(<?php echo htmlspecialchars(json_encode($app)); ?>)"
                                                                data-bs-toggle="modal" data-bs-target="#viewApplicationModal">
                                                            <i class="bi bi-eye"></i>
                                                        </button>
                                                        
                                                        <?php if ($app['status'] == 'approved' && empty($app['title_id'])): ?>
                                                        <button class="btn btn-outline-success" 
                                                                onclick="approveApplication(<?php echo $app['id']; ?>, '<?php echo $app['parcel_number']; ?>')"
                                                                data-bs-toggle="modal" data-bs-target="#approveApplicationModal">
                                                            <i class="bi bi-check-circle"></i>
                                                        </button>
                                                        <?php endif; ?>
                                                        
                                                        <a href="?delete=<?php echo $app['id']; ?>" 
                                                           class="btn btn-outline-danger"
                                                           onclick="return confirm('Delete this application? This action cannot be undone.')">
                                                            <i class="bi bi-trash"></i>
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

                    <!-- Information Card -->
                    <div class="row mt-4">
                        <div class="col-md-12">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h5 class="card-title mb-0 fw-bold">
                                        <i class="bi bi-info-circle text-success me-2"></i>
                                        Lease Information
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <h6>About Lease Applications:</h6>
                                            <p>A lease application is submitted by a prospective lessee to obtain a leasehold interest in land for a specified term.</p>
                                            
                                            <h6 class="mt-3">Key Information:</h6>
                                            <ul>
                                                <li><strong>Lessee:</strong> The person/organization applying to lease the land</li>
                                                <li><strong>Lessor:</strong> The current title holder/owner of the land</li>
                                                <li><strong>Lease Term:</strong> Duration of the lease (typically 5-99 years)</li>
                                                <li><strong>Ground Rent:</strong> Annual rent payable to the lessor</li>
                                            </ul>
                                        </div>
                                        <div class="col-md-6">
                                            <h6>Required Documents:</h6>
                                            <ul>
                                                <li>Lease agreement draft</li>
                                                <li>Proof of identity (lessee and lessor)</li>
                                                <li>Current title deed</li>
                                                <li>Site plan/map</li>
                                                <li>Tax clearance certificates</li>
                                            </ul>
                                            
                                            <div class="alert alert-info mt-3 mb-0">
                                                <i class="bi bi-lightbulb"></i>
                                                <strong>Note:</strong> Approved lease applications automatically create a leasehold title and rent record.
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </main>

            <!-- Footer -->
            <?php include("footer.php"); ?>

        </div>
    </div>

    <!-- Add Lease Application Modal -->
    <div class="modal fade" id="addLeaseModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="add">
                    <div class="modal-header">
                        <h5 class="modal-title">New Lease Application</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <ul class="nav nav-tabs mb-3" id="leaseTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="parcel-tab" data-bs-toggle="tab" data-bs-target="#parcel" type="button" role="tab">Parcel & Parties</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="terms-tab" data-bs-toggle="tab" data-bs-target="#terms" type="button" role="tab">Lease Terms</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="documents-tab" data-bs-toggle="tab" data-bs-target="#documents" type="button" role="tab">Documents</button>
                            </li>
                        </ul>
                        
                        <div class="tab-content" id="leaseTabsContent">
                            <!-- Parcel & Parties Tab -->
                            <div class="tab-pane fade show active" id="parcel" role="tabpanel">
                                <div class="row">
                                    <div class="col-md-12 mb-3">
                                        <label class="form-label">Select Parcel <span class="text-danger">*</span></label>
                                        <select class="form-select" name="parcel_id" id="parcelSelect" required>
                                            <option value="">-- Select a parcel --</option>
                                            <?php foreach ($eligible_parcels as $parcel): ?>
                                            <option value="<?php echo $parcel['id']; ?>" 
                                                    data-area="<?php echo $parcel['area']; ?>"
                                                    data-title="<?php echo htmlspecialchars($parcel['title_number'] ?? ''); ?>"
                                                    data-owner="<?php echo htmlspecialchars($parcel['owner_name'] ?? ''); ?>">
                                                <?php echo $parcel['parcel_number']; ?> - 
                                                <?php echo htmlspecialchars($parcel['boma_name']); ?> 
                                                (<?php echo number_format($parcel['area'], 2); ?> m²)
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Parcel Area</label>
                                        <input type="text" class="form-control" id="parcelArea" readonly disabled>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Current Title</label>
                                        <input type="text" class="form-control" id="parcelTitle" readonly disabled>
                                    </div>
                                    
                                    <div class="col-md-12 mb-3">
                                        <label class="form-label">Current Owner (Lessor) <span class="text-danger">*</span></label>
                                        <select class="form-select" name="lessor_party_id" id="lessorSelect" required>
                                            <option value="">-- Select lessor --</option>
                                            <?php foreach ($lessors as $lessor): ?>
                                            <option value="<?php echo $lessor['id']; ?>">
                                                <?php echo htmlspecialchars($lessor['name']); ?> (<?php echo ucfirst($lessor['party_type']); ?>)
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="text-muted">The current title holder/owner of the land</small>
                                    </div>
                                    
                                    <div class="col-md-12 mb-3">
                                        <label class="form-label">Applicant (Lessee) <span class="text-danger">*</span></label>
                                        <select class="form-select" name="applicant_party_id" required>
                                            <option value="">-- Select applicant --</option>
                                            <?php foreach ($parties as $party): ?>
                                            <option value="<?php echo $party['id']; ?>">
                                                <?php echo htmlspecialchars($party['name']); ?> 
                                                (<?php echo $party['party_type']; ?>)
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Submission Date <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" name="submission_date" 
                                               value="<?php echo date('Y-m-d'); ?>" required>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Initial Status</label>
                                        <select class="form-select" name="status">
                                            <option value="submitted">Submitted</option>
                                            <option value="draft">Draft</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Lease Terms Tab -->
                            <div class="tab-pane fade" id="terms" role="tabpanel">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Lease Term (Years) <span class="text-danger">*</span></label>
                                        <input type="number" class="form-control" name="lease_term_years" 
                                               id="leaseTerm" min="1" max="99" required>
                                        <small class="text-muted">Between 1 and 99 years</small>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Proposed Start Date</label>
                                        <input type="date" class="form-control" name="proposed_start_date" 
                                               id="startDate" value="<?php echo date('Y-m-d'); ?>">
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Proposed End Date</label>
                                        <input type="date" class="form-control" name="proposed_end_date" 
                                               id="endDate" readonly disabled>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Annual Rent Proposed ($) <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <span class="input-group-text">$</span>
                                            <input type="number" class="form-control" name="annual_rent_proposed" 
                                                   step="0.01" min="0" required>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Payment Frequency</label>
                                        <select class="form-select" name="rent_payment_frequency">
                                            <option value="annual">Annual</option>
                                            <option value="semi_annual">Semi-Annual</option>
                                            <option value="quarterly">Quarterly</option>
                                            <option value="monthly">Monthly</option>
                                        </select>
                                    </div>
                                    
                                    <div class="col-md-12 mb-3">
                                        <label class="form-label">Proposed Use of Land</label>
                                        <textarea class="form-control" name="proposed_use" rows="2" 
                                                  placeholder="e.g., Commercial farming, residential development, etc."></textarea>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Documents Tab -->
                            <div class="tab-pane fade" id="documents" role="tabpanel">
                                <div class="row">
                                    <div class="col-md-12 mb-3">
                                        <label class="form-label">Lease Agreement Draft (PDF)</label>
                                        <input type="file" class="form-control" name="lease_document" 
                                               accept=".pdf,.doc,.docx">
                                        <small class="text-muted">Upload the proposed lease agreement</small>
                                    </div>
                                </div>
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

    <!-- View Application Modal -->
    <div class="modal fade" id="viewApplicationModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Lease Application Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Application ID:</label>
                            <p id="viewAppId" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Status:</label>
                            <p id="viewStatus" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Submission Date:</label>
                            <p id="viewSubmissionDate" class="mb-0"></p>
                        </div>
                        
                        <div class="col-12">
                            <hr>
                            <h6 class="fw-bold">Parcel Information</h6>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Parcel Number:</label>
                            <p id="viewParcelNumber" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Area:</label>
                            <p id="viewArea" class="mb-0"></p>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="fw-bold">Location:</label>
                            <p id="viewLocation" class="mb-0"></p>
                        </div>
                        
                        <div class="col-12">
                            <hr>
                            <h6 class="fw-bold">Parties</h6>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Lessee (Applicant):</label>
                            <p id="viewLessee" class="mb-0"></p>
                        </div>
                        
                        <div class="col-12">
                            <hr>
                            <h6 class="fw-bold">Lease Terms</h6>
                        </div>
                        
                        <div class="col-md-4 mb-3">
                            <label class="fw-bold">Term (Years):</label>
                            <p id="viewTerm" class="mb-0"></p>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="fw-bold">Start Date:</label>
                            <p id="viewStartDate" class="mb-0"></p>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="fw-bold">End Date:</label>
                            <p id="viewEndDate" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Annual Rent:</label>
                            <p id="viewRent" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Payment Frequency:</label>
                            <p id="viewFrequency" class="mb-0"></p>
                        </div>
                        
                        <div class="col-12 mb-3">
                            <label class="fw-bold">Details:</label>
                            <p id="viewDetails" class="mb-0"></p>
                        </div>
                        
                        <div class="col-12">
                            <label class="fw-bold">Documents:</label>
                            <p id="viewDocuments" class="mb-0"></p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Approve Application Modal -->
    <div class="modal fade" id="approveApplicationModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="approve">
                    <input type="hidden" name="application_id" id="approveAppId">
                    <div class="modal-header">
                        <h5 class="modal-title">Approve Lease & Create Title</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Title Number <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="title_number" 
                                   id="approveTitleNumber" required>
                            <small class="text-muted">e.g., LEASE/2024/001</small>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Issue Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="issue_date" 
                                   value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Expiry Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="expiry_date" id="approveExpiryDate" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Annual Rent <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="number" class="form-control" name="annual_rent" 
                                       id="approveAnnualRent" step="0.01" required>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Rent Due Date</label>
                            <input type="date" class="form-control" name="rent_due_date" 
                                   value="<?php echo date('Y-01-01', strtotime('+1 year')); ?>">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Approve & Create Title</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Calculate end date based on start date and lease term
        document.getElementById('startDate')?.addEventListener('change', calculateEndDate);
        document.getElementById('leaseTerm')?.addEventListener('input', calculateEndDate);
        
        function calculateEndDate() {
            const startDate = document.getElementById('startDate').value;
            const leaseTerm = document.getElementById('leaseTerm').value;
            
            if (startDate && leaseTerm) {
                const endDate = new Date(startDate);
                endDate.setFullYear(endDate.getFullYear() + parseInt(leaseTerm));
                
                const year = endDate.getFullYear();
                const month = String(endDate.getMonth() + 1).padStart(2, '0');
                const day = String(endDate.getDate()).padStart(2, '0');
                
                document.getElementById('endDate').value = year + '-' + month + '-' + day;
            }
        }
        
        // Update parcel info
        document.getElementById('parcelSelect')?.addEventListener('change', function() {
            const selected = this.options[this.selectedIndex];
            const area = selected.dataset.area || '';
            const title = selected.dataset.title || '';
            
            document.getElementById('parcelArea').value = area ? Number(area).toLocaleString() + ' m²' : '';
            document.getElementById('parcelTitle').value = title || 'No title';
        });
        
        // View application function
        function viewApplication(app) {
            document.getElementById('viewAppId').textContent = '#' + (app.id || 'N/A');
            
            let statusBadge = '';
            const status = app.status || 'unknown';
            const statusClasses = {
                'submitted': 'bg-warning',
                'under_review': 'bg-info',
                'approved': 'bg-success',
                'rejected': 'bg-danger',
                'completed': 'bg-secondary'
            };
            statusBadge = '<span class="badge ' + (statusClasses[status] || 'bg-secondary') + '">' + 
                         status.replace('_', ' ') + '</span>';
            document.getElementById('viewStatus').innerHTML = statusBadge;
            
            document.getElementById('viewSubmissionDate').textContent = app.submission_date || 'N/A';
            document.getElementById('viewParcelNumber').textContent = app.parcel_number || 'N/A';
            document.getElementById('viewArea').textContent = app.parcel_area ? app.parcel_area + ' m²' : 'N/A';
            document.getElementById('viewLocation').textContent = 
                (app.boma_name || '') + ', ' + (app.payam_name || '');
            
            document.getElementById('viewLessee').textContent = app.applicant_name || 'N/A';
            
            document.getElementById('viewTerm').textContent = app.lease_term_years ? app.lease_term_years + ' years' : 'N/A';
            document.getElementById('viewStartDate').textContent = app.proposed_start_date || 'N/A';
            document.getElementById('viewEndDate').textContent = app.proposed_end_date || 'N/A';
            document.getElementById('viewRent').textContent = app.annual_rent_proposed ? '$' + Number(app.annual_rent_proposed).toLocaleString() : 'N/A';
            document.getElementById('viewFrequency').textContent = app.rent_payment_frequency || 'annual';
            
            document.getElementById('viewDetails').textContent = app.details || 'No additional details';
            document.getElementById('viewDocuments').innerHTML = 
                '<span class="badge bg-primary">' + (app.document_count || 0) + ' document(s)</span>';
        }
        
        // Approve application function
        function approveApplication(appId, parcelNumber) {
            document.getElementById('approveAppId').value = appId;
            document.getElementById('approveTitleNumber').value = 'LEASE/' + new Date().getFullYear() + '/' + 
                String(Math.floor(Math.random() * 1000)).padStart(3, '0');
            
            // Set expiry date to 50 years from now by default
            const expiryDate = new Date();
            expiryDate.setFullYear(expiryDate.getFullYear() + 50);
            const year = expiryDate.getFullYear();
            const month = String(expiryDate.getMonth() + 1).padStart(2, '0');
            const day = String(expiryDate.getDate()).padStart(2, '0');
            document.getElementById('approveExpiryDate').value = year + '-' + month + '-' + day;
        }
    </script>

    <style>
        .table td {
            vertical-align: middle;
        }
        
        .btn-group .btn {
            padding: 0.25rem 0.5rem;
        }
        
        .modal-body {
            max-height: 70vh;
            overflow-y: auto;
        }
        
        .badge {
            font-size: 0.85rem;
        }
        
        .nav-tabs .nav-link {
            color: #495057;
        }
        
        .nav-tabs .nav-link.active {
            font-weight: 600;
            color: #0d6efd;
        }
    </style>

</body>
</html>