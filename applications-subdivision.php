<?php
ini_set('display_errors', 1); 
error_reporting(E_ALL);

// applications-subdivision.php - Subdivision Application Management
include("head.php");
require_once 'db_connection.php';

// Enable error logging
error_log("=== Starting applications-subdivision.php ===");

// ============================================================================
// HANDLE ACTIONS
// ============================================================================

$message = '';
$messageType = '';

// Delete subdivision application
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $applicationId = $_GET['delete'];
    
    try {
        // Check if application has workflow steps
        $steps = fetchOne($conn, "SELECT COUNT(*) as count FROM workflow_steps WHERE application_id = ?", [$applicationId]);
        
        if ($steps && $steps['count'] > 0) {
            // Soft delete - mark as rejected
            executeQuery($conn, "UPDATE applications SET status = 'rejected' WHERE id = ? AND application_type = 'subdivision'", [$applicationId]);
            $message = "Subdivision application rejected (has workflow steps)";
            $messageType = "warning";
        } else {
            executeQuery($conn, "DELETE FROM applications WHERE id = ? AND application_type = 'subdivision'", [$applicationId]);
            $message = "Subdivision application deleted successfully";
            $messageType = "success";
        }
    } catch (Exception $e) {
        $message = "Error deleting application: " . $e->getMessage();
        $messageType = "danger";
        error_log("Delete error: " . $e->getMessage());
    }
}

// Add new subdivision application
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $parcel_id = $_POST['parcel_id'];
    $applicant_party_id = $_POST['applicant_party_id'];
    $submission_date = $_POST['submission_date'];
    $status = $_POST['status'] ?? 'submitted';
    $number_of_plots = $_POST['number_of_plots'] ?? 0;
    $proposed_parcel_numbers = $_POST['proposed_parcel_numbers'] ?? null;
    $survey_plan_reference = $_POST['survey_plan_reference'] ?? null;
    $surveyor_name = $_POST['surveyor_name'] ?? null;
    $survey_date = $_POST['survey_date'] ?? null;
    $details = $_POST['details'] ?? null;
    
    // Validate number of plots
    if ($number_of_plots < 2 || $number_of_plots > 50) {
        $message = "Number of plots must be between 2 and 50";
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
            
            // Check for existing pending subdivision applications
            $existing = fetchOne($conn, "
                SELECT id FROM applications 
                WHERE parcel_id = ? AND application_type = 'subdivision' 
                AND status IN ('submitted', 'under_review')
            ", [$parcel_id]);
            
            if ($existing) {
                throw new Exception("There is already a pending subdivision application for this parcel");
            }
            
            // Prepare details with subdivision info
            $subdivision_info = [
                'number_of_plots' => $number_of_plots,
                'proposed_parcel_numbers' => $proposed_parcel_numbers,
                'survey_plan_reference' => $survey_plan_reference,
                'surveyor_name' => $surveyor_name,
                'survey_date' => $survey_date,
                'original_parcel' => $parcel['parcel_number'],
                'original_title' => $parcel['title_number'] ?? 'No title'
            ];
            
            $details_json = json_encode($subdivision_info);
            if ($details) {
                $details_json = $details . "\n\n" . $details_json;
            }
            
            // Insert the application
            executeQuery($conn, "
                INSERT INTO applications (
                    application_type, parcel_id, applicant_party_id, submission_date, 
                    status, details, created_at
                ) VALUES ('subdivision', ?, ?, ?, ?, ?, NOW())
            ", [$parcel_id, $applicant_party_id, $submission_date, $status, $details_json]);
            
            $application_id = $conn->lastInsertId();
            
            // Create initial workflow steps
            $workflow_steps = [
                ['step_name' => 'Document Verification', 'due_date' => date('Y-m-d', strtotime('+7 days'))],
                ['step_name' => 'Survey Verification', 'due_date' => date('Y-m-d', strtotime('+14 days'))],
                ['step_name' => 'Technical Assessment', 'due_date' => date('Y-m-d', strtotime('+21 days'))],
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
            if (isset($_FILES['survey_plan']) && $_FILES['survey_plan']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = 'uploads/applications/subdivision/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $file_extension = pathinfo($_FILES['survey_plan']['name'], PATHINFO_EXTENSION);
                $file_name = 'subdivision_' . $application_id . '_' . time() . '.' . $file_extension;
                $file_path = $upload_dir . $file_name;
                
                if (move_uploaded_file($_FILES['survey_plan']['tmp_name'], $file_path)) {
                    executeQuery($conn, "
                        INSERT INTO documents (
                            document_type, parcel_id, application_id, file_name, file_path, 
                            mime_type, uploaded_by, uploaded_at, description
                        ) VALUES ('survey_plan', ?, ?, ?, ?, ?, ?, NOW(), ?)
                    ", [$parcel_id, $application_id, $_FILES['survey_plan']['name'], $file_path, 
                        $_FILES['survey_plan']['type'], $_SESSION['user_id'] ?? 1, 
                        "Survey plan for subdivision application #" . $application_id]);
                }
            }
            
            // Commit transaction
            commitTransaction($conn);
            
            $message = "Subdivision application submitted successfully. Application ID: " . $application_id;
            $messageType = "success";
            
        } catch (Exception $e) {
            rollbackTransaction($conn);
            $message = "Error submitting application: " . $e->getMessage();
            $messageType = "danger";
            error_log("Add error: " . $e->getMessage());
        }
    }
}

// ============================================================================
// SIMPLE QUERY TO LIST APPLICATIONS - FIXED VERSION
// ============================================================================

try {
    // Simple query to get all subdivision applications
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
                parties.name as applicant_name,
                parties.party_type as applicant_type
            FROM applications a
            LEFT JOIN parcels p ON a.parcel_id = p.id
            LEFT JOIN parties ON a.applicant_party_id = parties.id
            WHERE a.application_type = 'subdivision'
            ORDER BY a.submission_date DESC, a.id DESC";
    
    error_log("Executing SQL: " . $sql);
    
    $result = executeQuery($conn, $sql);
    
    if ($result) {
        $applications = [];
        while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
            $applications[] = $row;
        }
        error_log("Found " . count($applications) . " subdivision applications");
    } else {
        $applications = [];
        error_log("Query returned no result");
    }
    
} catch (Exception $e) {
    error_log("Error in main query: " . $e->getMessage());
    $applications = [];
    $message = "Error loading applications: " . $e->getMessage();
    $messageType = "danger";
}

// ============================================================================
// GET HIERARCHICAL DATA FOR FILTERS
// ============================================================================

try {
    $states = fetchAll($conn, "SELECT id, name FROM states ORDER BY name");
} catch (Exception $e) {
    error_log("Error loading states: " . $e->getMessage());
    $states = [];
}

$selected_state = $_GET['state_id'] ?? null;
$selected_county = $_GET['county_id'] ?? null;

$counties = [];
if ($selected_state) {
    try {
        $counties = fetchAll($conn, "SELECT id, name FROM counties WHERE state_id = ? ORDER BY name", [$selected_state]);
    } catch (Exception $e) {
        error_log("Error loading counties: " . $e->getMessage());
        $counties = [];
    }
}

// ============================================================================
// GET DATA FOR DROPDOWNS
// ============================================================================

try {
    // Get parcels eligible for subdivision (with area > 1000 sq m)
    $eligible_parcels = fetchAll($conn, "
        SELECT p.id, p.parcel_number, p.area, p.location_description,
               b.name as boma_name, pa.name as payam_name, c.name as county_name,
               t.id as title_id, t.title_number, t.title_type
        FROM parcels p
        JOIN bomas b ON p.boma_id = b.id
        JOIN payams pa ON b.payam_id = pa.id
        JOIN counties c ON pa.county_id = c.id
        LEFT JOIN titles t ON p.id = t.parcel_id AND t.status = 'active'
        WHERE p.area >= 1000
        ORDER BY p.parcel_number
    ");
} catch (Exception $e) {
    error_log("Error loading eligible parcels: " . $e->getMessage());
    $eligible_parcels = [];
}

try {
    // Get parties for applicant selection
    $parties = fetchAll($conn, "
        SELECT id, name, party_type, national_id, registration_number, phone, email
        FROM parties 
        ORDER BY name
    ");
} catch (Exception $e) {
    error_log("Error loading parties: " . $e->getMessage());
    $parties = [];
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
        WHERE application_type = 'subdivision'
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
        WHERE application_type = 'subdivision'
            AND submission_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(submission_date, '%Y-%m')
        ORDER BY month DESC
    ");
} catch (Exception $e) {
    error_log("Error loading monthly trend: " . $e->getMessage());
    $monthly_trend = [];
}
?>

<body data-page="applications-subdivision" class="applications-subdivision-page">
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
                            <h1 class="h3 mb-0">Subdivision Applications</h1>
                            <p class="text-muted mb-0">Manage land subdivision requests and approvals</p>
                        </div>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addSubdivisionModal">
                            <i class="bi bi-pencil-square me-2"></i>New Subdivision Application
                        </button>
                    </div>

                    <!-- Alert Message -->
                    <?php if ($message): ?>
                    <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show mb-4" role="alert">
                        <?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    <?php endif; ?>

                    <!-- Debug Info (remove in production) -->
                    <?php if (isset($_GET['debug'])): ?>
                    <div class="alert alert-secondary mb-4">
                        <h6>Debug Info:</h6>
                        <p>Applications found: <?php echo count($applications); ?></p>
                        <pre><?php print_r($applications); ?></pre>
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
                                                <i class="bi bi-files text-primary fs-4"></i>
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

                    <!-- Subdivision Applications Table -->
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0 fw-bold">
                                <i class="bi bi-files text-primary me-2"></i>
                                Subdivision Applications
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
                                            <th>Applicant</th>
                                            <th>Submission Date</th>
                                            <th>Status</th>
                                            <th>Details</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($applications)): ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-4 text-muted">
                                                <i class="bi bi-files fs-1 d-block mb-3"></i>
                                                No subdivision applications found. 
                                                <a href="#" data-bs-toggle="modal" data-bs-target="#addSubdivisionModal">Click here</a> to create one.
                                            </td>
                                        </tr>
                                        <?php else: ?>
                                            <?php foreach ($applications as $app): ?>
                                            <tr>
                                                <td>
                                                    <span class="fw-medium">#<?php echo $app['id']; ?></span>
                                                </td>
                                                <td>
                                                    <span class="fw-medium"><?php echo htmlspecialchars($app['parcel_number'] ?? 'N/A'); ?></span>
                                                    <?php if (!empty($app['parcel_area'])): ?>
                                                        <br><small class="text-muted">Area: <?php echo number_format($app['parcel_area'], 2); ?> m²</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="fw-medium"><?php echo htmlspecialchars($app['applicant_name'] ?? 'N/A'); ?></span>
                                                    <?php if (!empty($app['applicant_type'])): ?>
                                                        <br><small class="text-muted"><?php echo ucfirst($app['applicant_type']); ?></small>
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
                                                </td>
                                                <td>
                                                    <?php 
                                                    $preview = strip_tags($app['details'] ?? '');
                                                    echo htmlspecialchars(substr($preview, 0, 50)) . (strlen($preview) > 50 ? '...' : '');
                                                    ?>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <button class="btn btn-outline-info" 
                                                                onclick="viewApplication(<?php echo htmlspecialchars(json_encode($app)); ?>)"
                                                                data-bs-toggle="modal" data-bs-target="#viewApplicationModal">
                                                            <i class="bi bi-eye"></i>
                                                        </button>
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

                    <!-- Simple Stats Card -->
                    <div class="row mt-4">
                        <div class="col-md-12">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h5 class="card-title mb-0 fw-bold">
                                        <i class="bi bi-info-circle text-info me-2"></i>
                                        Subdivision Guidelines
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <h6>Minimum Requirements:</h6>
                                            <ul>
                                                <li>Minimum parcel size: 1000 m²</li>
                                                <li>Minimum plot size: 200 m²</li>
                                                <li>Approved survey plan required</li>
                                                <li>Clear title or ownership proof</li>
                                            </ul>
                                        </div>
                                        <div class="col-md-6">
                                            <h6>Required Documents:</h6>
                                            <ul>
                                                <li>Survey plan (signed by licensed surveyor)</li>
                                                <li>Original title deed</li>
                                                <li>Tax clearance certificate</li>
                                                <li>Application fee receipt</li>
                                            </ul>
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

    <!-- Add Subdivision Application Modal -->
    <div class="modal fade" id="addSubdivisionModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="add">
                    <div class="modal-header">
                        <h5 class="modal-title">New Subdivision Application</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Select Parcel <span class="text-danger">*</span></label>
                                <select class="form-select" name="parcel_id" id="parcelSelect" required>
                                    <option value="">-- Select a parcel --</option>
                                    <?php foreach ($eligible_parcels as $parcel): ?>
                                    <option value="<?php echo $parcel['id']; ?>" 
                                            data-area="<?php echo $parcel['area']; ?>">
                                        <?php echo $parcel['parcel_number']; ?> - 
                                        <?php echo htmlspecialchars($parcel['boma_name']); ?> 
                                        (<?php echo number_format($parcel['area'], 2); ?> m²)
                                        <?php if ($parcel['title_number']): ?>
                                            - Title: <?php echo $parcel['title_number']; ?>
                                        <?php endif; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Number of Plots <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" name="number_of_plots" 
                                       id="numberOfPlots" min="2" max="50" required>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Avg Plot Size</label>
                                <input type="text" class="form-control" id="avgPlotSize" readonly disabled>
                            </div>
                            
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Select Applicant <span class="text-danger">*</span></label>
                                <select class="form-select" name="applicant_party_id" required>
                                    <option value="">-- Select an applicant --</option>
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
                                <label class="form-label">Survey Plan (PDF) <span class="text-danger">*</span></label>
                                <input type="file" class="form-control" name="survey_plan" 
                                       accept=".pdf,.jpg,.png" required>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Survey Reference</label>
                                <input type="text" class="form-control" name="survey_plan_reference">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Surveyor Name</label>
                                <input type="text" class="form-control" name="surveyor_name">
                            </div>
                            
                            <div class="col-12 mb-3">
                                <label class="form-label">Additional Details</label>
                                <textarea class="form-control" name="details" rows="3"></textarea>
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
                    <h5 class="modal-title">Application Details</h5>
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
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Parcel:</label>
                            <p id="viewParcel" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Applicant:</label>
                            <p id="viewApplicant" class="mb-0"></p>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="fw-bold">Details:</label>
                            <p id="viewDetails" class="mb-0"></p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Calculate average plot size
        document.getElementById('parcelSelect')?.addEventListener('change', function() {
            const selected = this.options[this.selectedIndex];
            const area = selected.dataset.area || '';
            document.getElementById('parcelArea')?.value = area ? Number(area).toLocaleString() + ' m²' : '';
        });
        
        document.getElementById('numberOfPlots')?.addEventListener('input', function() {
            const parcelSelect = document.getElementById('parcelSelect');
            if (parcelSelect && parcelSelect.selectedOptions[0]) {
                const parcelArea = parcelSelect.selectedOptions[0].dataset.area;
                if (parcelArea && this.value) {
                    const avgSize = Number(parcelArea) / Number(this.value);
                    document.getElementById('avgPlotSize').value = avgSize.toFixed(2) + ' m²';
                }
            }
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
            document.getElementById('viewParcel').textContent = app.parcel_number || 'N/A';
            document.getElementById('viewApplicant').textContent = app.applicant_name || 'N/A';
            document.getElementById('viewDetails').textContent = app.details || 'No details';
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
    </style>

</body>
</html>