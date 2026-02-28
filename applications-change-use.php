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

// Delete application
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $applicationId = $_GET['delete'];
    
    try {
        // Check if application has related records
        $related = fetchOne($conn, "
            SELECT 
                (SELECT COUNT(*) FROM documents WHERE application_id = ?) as documents,
                (SELECT COUNT(*) FROM workflow_steps WHERE application_id = ?) as workflow_steps
        ", [$applicationId, $applicationId]);
        
        if ($related['documents'] > 0 || $related['workflow_steps'] > 0) {
            $message = "Cannot delete application with existing documents or workflow steps.";
            $messageType = "warning";
        } else {
            executeQuery($conn, "DELETE FROM applications WHERE id = ?", [$applicationId]);
            $message = "Application deleted successfully";
            $messageType = "success";
        }
    } catch (Exception $e) {
        $message = "Error deleting application: " . $e->getMessage();
        $messageType = "danger";
    }
}

// Update application status
if (isset($_POST['action']) && $_POST['action'] === 'update_status') {
    $application_id = $_POST['application_id'];
    $new_status = $_POST['status'];
    $comments = $_POST['comments'] ?? null;
    
    try {
        executeQuery($conn, "
            UPDATE applications SET status = ?, updated_at = NOW() WHERE id = ?
        ", [$new_status, $application_id]);
        
        // Add comment to workflow if exists
        if ($comments) {
            $workflowExists = fetchOne($conn, "
                SELECT COUNT(*) as count FROM workflow_steps WHERE application_id = ?
            ", [$application_id]);
            
            if ($workflowExists && $workflowExists['count'] > 0) {
                executeQuery($conn, "
                    UPDATE workflow_steps SET comments = CONCAT(IFNULL(comments, ''), '\n', ?) 
                    WHERE application_id = ? AND status = 'in_progress'
                ", [$comments, $application_id]);
            }
        }
        
        $message = "Application status updated successfully";
        $messageType = "success";
    } catch (Exception $e) {
        $message = "Error updating status: " . $e->getMessage();
        $messageType = "danger";
    }
}

// ============================================================================
// SAFE QUERY FUNCTIONS
// ============================================================================

function safeFetchAll($conn, $sql, $params = [], $default = []) {
    try {
        return fetchAll($conn, $sql, $params);
    } catch (Exception $e) {
        error_log("Database error in safeFetchAll: " . $e->getMessage() . " SQL: " . $sql);
        return $default;
    }
}

function safeFetchOne($conn, $sql, $params = [], $default = null) {
    try {
        return fetchOne($conn, $sql, $params);
    } catch (Exception $e) {
        error_log("Database error in safeFetchOne: " . $e->getMessage() . " SQL: " . $sql);
        return $default;
    }
}

// ============================================================================
// GET FILTERS
// ============================================================================

$application_type = $_GET['type'] ?? '';
$status_filter = $_GET['status'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$search = $_GET['search'] ?? '';

// ============================================================================
// GET ALL APPLICATIONS WITH DETAILS
// ============================================================================

$sql = "
    SELECT 
        a.*,
        p.parcel_number,
        p.area as parcel_area,
        b.name as boma_name,
        py.name as payam_name,
        app.name as applicant_name,
        app.party_type as applicant_type,
        -- Count related records
        (SELECT COUNT(*) FROM documents d WHERE d.application_id = a.id) as document_count,
        (SELECT COUNT(*) FROM workflow_steps w WHERE w.application_id = a.id) as workflow_count,
        -- Additional details based on application type
        CASE 
            WHEN a.application_type = 'change_of_use' THEN 
                (SELECT CONCAT_WS(' → ', cz.zone_code, rz.zone_code) 
                 FROM change_of_use cou 
                 LEFT JOIN zoning cz ON cou.current_zoning_id = cz.id
                 LEFT JOIN zoning rz ON cou.requested_zoning_id = rz.id
                 WHERE cou.application_id = a.id)
            WHEN a.application_type = 'transfer' THEN
                (SELECT CONCAT('To: ', pa.name) 
                 FROM transfers t 
                 LEFT JOIN parties pa ON t.to_party_id = pa.id
                 WHERE t.application_id = a.id)
            ELSE NULL
        END as type_details
    FROM applications a
    LEFT JOIN parcels p ON a.parcel_id = p.id
    LEFT JOIN bomas b ON p.boma_id = b.id
    LEFT JOIN payams py ON b.payam_id = py.id
    LEFT JOIN parties app ON a.applicant_party_id = app.id
    WHERE 1=1
";

$params = [];

if (!empty($application_type)) {
    $sql .= " AND a.application_type = ?";
    $params[] = $application_type;
}

if (!empty($status_filter)) {
    $sql .= " AND a.status = ?";
    $params[] = $status_filter;
}

if (!empty($date_from)) {
    $sql .= " AND a.submission_date >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $sql .= " AND a.submission_date <= ?";
    $params[] = $date_to;
}

if (!empty($search)) {
    $sql .= " AND (a.id LIKE ? OR p.parcel_number LIKE ? OR app.name LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

$sql .= " ORDER BY a.created_at DESC";

$applications = safeFetchAll($conn, $sql, $params, []);

// ============================================================================
// GET SUMMARY STATISTICS
// ============================================================================

$summary = safeFetchOne($conn, "
    SELECT 
        COUNT(*) as total_applications,
        SUM(CASE WHEN status = 'submitted' THEN 1 ELSE 0 END) as submitted,
        SUM(CASE WHEN status = 'under_review' THEN 1 ELSE 0 END) as under_review,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft,
        COUNT(DISTINCT application_type) as application_types,
        COUNT(DISTINCT applicant_party_id) as unique_applicants
    FROM applications
");

// Get counts by application type
$type_stats = safeFetchAll($conn, "
    SELECT 
        application_type,
        COUNT(*) as count,
        SUM(CASE WHEN status = 'submitted' THEN 1 ELSE 0 END) as pending
    FROM applications
    GROUP BY application_type
    ORDER BY count DESC
", [], []);

// Get monthly trends
$monthly_trends = safeFetchAll($conn, "
    SELECT 
        DATE_FORMAT(created_at, '%Y-%m') as month,
        COUNT(*) as total,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved
    FROM applications
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
    ORDER BY month DESC
", [], []);

// Get application types for filter
$application_types = [
    'new_title' => 'New Title',
    'transfer' => 'Transfer',
    'subdivision' => 'Subdivision',
    'consolidation' => 'Consolidation',
    'lease' => 'Lease',
    'change_of_use' => 'Change of Use'
];

// Get statuses for filter
$statuses = [
    'draft' => 'Draft',
    'submitted' => 'Submitted',
    'under_review' => 'Under Review',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
    'completed' => 'Completed'
];
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
                    <div class="d-flex justify-content-between align-items-center mb-4 mb-lg-5">
                        <div>
                            <h1 class="h3 mb-0">Applications Management</h1>
                            <p class="text-muted mb-0">Manage all land-related applications and requests</p>
                        </div>
                        <div class="btn-group">
                            <button type="button" class="btn btn-primary dropdown-toggle" data-bs-toggle="dropdown">
                                <i class="bi bi-plus-circle me-2"></i>New Application
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="applications-new-title.php">
                                    <i class="bi bi-file-text me-2"></i>New Title
                                </a></li>
                                <li><a class="dropdown-item" href="applications-transfer.php">
                                    <i class="bi bi-arrow-left-right me-2"></i>Transfer
                                </a></li>
                                <li><a class="dropdown-item" href="applications-subdivision.php">
                                    <i class="bi bi-grid-3x3-gap-fill me-2"></i>Subdivision
                                </a></li>
                                <li><a class="dropdown-item" href="applications-consolidation.php">
                                    <i class="bi bi-bounding-box-circles me-2"></i>Consolidation
                                </a></li>
                                <li><a class="dropdown-item" href="applications-lease.php">
                                    <i class="bi bi-file-earmark-text me-2"></i>Lease
                                </a></li>
                                <li><a class="dropdown-item" href="applications-change-use.php">
                                    <i class="bi bi-arrow-repeat me-2"></i>Change of Use
                                </a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="applications-bulk.php">
                                    <i class="bi bi-files me-2"></i>Bulk Upload
                                </a></li>
                            </ul>
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
                    <div class="row g-4 mb-4">
                        <div class="col-xl-3 col-md-6">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0 me-3">
                                            <div class="bg-primary bg-opacity-10 rounded-circle p-3">
                                                <i class="bi bi-files text-primary fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Total Applications</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['total_applications'] ?? 0); ?></h3>
                                            <small class="text-muted"><?php echo $summary['application_types'] ?? 0; ?> types</small>
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
                                            <h6 class="text-muted mb-1">Pending Review</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format(($summary['submitted'] ?? 0) + ($summary['under_review'] ?? 0)); ?></h3>
                                            <small class="text-muted">Submitted: <?php echo $summary['submitted'] ?? 0; ?> | In Review: <?php echo $summary['under_review'] ?? 0; ?></small>
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
                                            <h3 class="mb-0 fw-bold"><?php echo number_format(($summary['approved'] ?? 0) + ($summary['completed'] ?? 0)); ?></h3>
                                            <small class="text-muted">Approved: <?php echo $summary['approved'] ?? 0; ?> | Completed: <?php echo $summary['completed'] ?? 0; ?></small>
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
                                                <i class="bi bi-people text-info fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Unique Applicants</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['unique_applicants'] ?? 0); ?></h3>
                                            <small class="text-muted">Individual/Org applicants</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Charts Row -->
                    <div class="row g-4 mb-4">
                        <div class="col-xl-6">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h6 class="card-title mb-0 fw-bold">
                                        <i class="bi bi-pie-chart me-2 text-primary"></i>Applications by Type
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <canvas id="typeChart" style="height: 250px;"></canvas>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-xl-6">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h6 class="card-title mb-0 fw-bold">
                                        <i class="bi bi-bar-chart me-2 text-success"></i>Monthly Trends
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <canvas id="trendsChart" style="height: 250px;"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Filters -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-body">
                            <form method="GET" class="row g-3">
                                <div class="col-md-3">
                                    <label class="form-label">Application Type</label>
                                    <select class="form-select" name="type">
                                        <option value="">All Types</option>
                                        <?php foreach ($application_types as $value => $label): ?>
                                        <option value="<?php echo $value; ?>" <?php echo $application_type == $value ? 'selected' : ''; ?>>
                                            <?php echo $label; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-3">
                                    <label class="form-label">Status</label>
                                    <select class="form-select" name="status">
                                        <option value="">All Statuses</option>
                                        <?php foreach ($statuses as $value => $label): ?>
                                        <option value="<?php echo $value; ?>" <?php echo $status_filter == $value ? 'selected' : ''; ?>>
                                            <?php echo $label; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-2">
                                    <label class="form-label">Date From</label>
                                    <input type="date" class="form-control" name="date_from" value="<?php echo $date_from; ?>">
                                </div>
                                
                                <div class="col-md-2">
                                    <label class="form-label">Date To</label>
                                    <input type="date" class="form-control" name="date_to" value="<?php echo $date_to; ?>">
                                </div>
                                
                                <div class="col-md-2">
                                    <label class="form-label">Search</label>
                                    <input type="text" class="form-control" name="search" placeholder="ID, Parcel, Applicant" 
                                           value="<?php echo htmlspecialchars($search); ?>">
                                </div>
                                
                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-search me-2"></i>Apply Filters
                                    </button>
                                    <a href="applications.php" class="btn btn-secondary">
                                        <i class="bi bi-x-circle me-2"></i>Clear Filters
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Applications Table -->
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0 fw-bold">
                                <i class="bi bi-table me-2"></i>Applications List
                                <span class="badge bg-secondary ms-2"><?php echo count($applications); ?> records</span>
                            </h5>
                            <div>
                                <button class="btn btn-sm btn-outline-secondary" onclick="exportTableToCSV()">
                                    <i class="bi bi-download"></i> Export
                                </button>
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
                                            <th>Details</th>
                                            <th>Submitted</th>
                                            <th>Status</th>
                                            <th>Docs</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($applications)): ?>
                                        <tr>
                                            <td colspan="10" class="text-center py-4 text-muted">
                                                <i class="bi bi-inbox fs-1 d-block mb-3"></i>
                                                No applications found
                                            </td>
                                        </tr>
                                        <?php else: ?>
                                            <?php foreach ($applications as $app): ?>
                                            <tr>
                                                <td>
                                                    <span class="fw-bold">#<?php echo $app['id']; ?></span>
                                                </td>
                                                <td>
                                                    <?php
                                                    $typeColors = [
                                                        'new_title' => 'primary',
                                                        'transfer' => 'info',
                                                        'subdivision' => 'warning',
                                                        'consolidation' => 'success',
                                                        'lease' => 'secondary',
                                                        'change_of_use' => 'danger'
                                                    ];
                                                    $typeColor = $typeColors[$app['application_type']] ?? 'dark';
                                                    ?>
                                                    <span class="badge bg-<?php echo $typeColor; ?>">
                                                        <?php echo $application_types[$app['application_type']] ?? ucfirst(str_replace('_', ' ', $app['application_type'])); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="avatar-circle bg-primary bg-opacity-10 me-2">
                                                            <span class="text-primary fw-bold">
                                                                <?php echo strtoupper(substr($app['applicant_name'] ?? 'U', 0, 2)); ?>
                                                            </span>
                                                        </div>
                                                        <div>
                                                            <span class="fw-medium"><?php echo htmlspecialchars($app['applicant_name'] ?? 'Unknown'); ?></span>
                                                            <br><small class="text-muted"><?php echo ucfirst($app['applicant_type'] ?? 'Individual'); ?></small>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars($app['parcel_number'] ?? 'N/A'); ?>
                                                    <?php if ($app['parcel_area']): ?>
                                                    <br><small class="text-muted"><?php echo number_format($app['parcel_area'], 2); ?> m²</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <small><?php echo htmlspecialchars($app['boma_name'] ?? ''); ?>, <?php echo htmlspecialchars($app['payam_name'] ?? ''); ?></small>
                                                </td>
                                                <td>
                                                    <?php if ($app['type_details']): ?>
                                                    <small class="text-muted"><?php echo htmlspecialchars($app['type_details']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <small><?php echo date('d/m/Y', strtotime($app['submission_date'])); ?></small>
                                                </td>
                                                <td>
                                                    <?php
                                                    $statusColors = [
                                                        'draft' => 'secondary',
                                                        'submitted' => 'primary',
                                                        'under_review' => 'info',
                                                        'approved' => 'success',
                                                        'rejected' => 'danger',
                                                        'completed' => 'success'
                                                    ];
                                                    $statusColor = $statusColors[$app['status']] ?? 'dark';
                                                    ?>
                                                    <span class="badge bg-<?php echo $statusColor; ?>">
                                                        <?php echo $statuses[$app['status']] ?? ucfirst($app['status']); ?>
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    <?php if ($app['document_count'] > 0): ?>
                                                    <span class="badge bg-info" title="<?php echo $app['document_count']; ?> documents">
                                                        <i class="bi bi-file-text"></i> <?php echo $app['document_count']; ?>
                                                    </span>
                                                    <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <a href="applications-view.php?id=<?php echo $app['id']; ?>" 
                                                           class="btn btn-outline-primary" title="View">
                                                            <i class="bi bi-eye"></i>
                                                        </a>
                                                        <?php if ($app['status'] == 'draft' || $app['status'] == 'submitted'): ?>
                                                        <button class="btn btn-outline-success" 
                                                                onclick="updateStatus(<?php echo $app['id']; ?>, '<?php echo $app['status']; ?>')"
                                                                data-bs-toggle="modal" data-bs-target="#statusModal"
                                                                title="Update Status">
                                                            <i class="bi bi-arrow-repeat"></i>
                                                        </button>
                                                        <?php endif; ?>
                                                        <a href="applications-edit.php?id=<?php echo $app['id']; ?>" 
                                                           class="btn btn-outline-warning" title="Edit">
                                                            <i class="bi bi-pencil"></i>
                                                        </a>
                                                        <a href="?delete=<?php echo $app['id']; ?>" 
                                                           class="btn btn-outline-danger" 
                                                           onclick="return confirm('Delete this application? This action cannot be undone.')"
                                                           title="Delete">
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
                </div>
            </main>

            <!-- Footer -->
            <?php include("footer.php"); ?>

        </div>
    </div>

    <!-- Status Update Modal -->
    <div class="modal fade" id="statusModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="application_id" id="status_app_id">
                    <div class="modal-header">
                        <h5 class="modal-title">Update Application Status</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">New Status</label>
                            <select class="form-select" name="status" id="status_select" required>
                                <option value="submitted">Submitted</option>
                                <option value="under_review">Under Review</option>
                                <option value="approved">Approved</option>
                                <option value="rejected">Rejected</option>
                                <option value="completed">Completed</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Comments (Optional)</label>
                            <textarea class="form-control" name="comments" rows="3" 
                                      placeholder="Add any comments about this status change..."></textarea>
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

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <script>
        // Initialize charts
        document.addEventListener('DOMContentLoaded', function() {
            // Type Chart
            const typeCtx = document.getElementById('typeChart')?.getContext('2d');
            if (typeCtx) {
                new Chart(typeCtx, {
                    type: 'doughnut',
                    data: {
                        labels: [
                            <?php foreach ($type_stats as $stat): ?>
                            '<?php echo $application_types[$stat['application_type']] ?? ucfirst(str_replace('_', ' ', $stat['application_type'])); ?>',
                            <?php endforeach; ?>
                        ],
                        datasets: [{
                            data: [
                                <?php foreach ($type_stats as $stat): ?>
                                <?php echo $stat['count']; ?>,
                                <?php endforeach; ?>
                            ],
                            backgroundColor: [
                                '#0d6efd', '#0dcaf0', '#ffc107', '#198754', '#6c757d', '#dc3545'
                            ]
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'right'
                            }
                        }
                    }
                });
            }
            
            // Trends Chart
            const trendsCtx = document.getElementById('trendsChart')?.getContext('2d');
            if (trendsCtx) {
                new Chart(trendsCtx, {
                    type: 'line',
                    data: {
                        labels: [
                            <?php foreach ($monthly_trends as $trend): ?>
                            '<?php echo $trend['month']; ?>',
                            <?php endforeach; ?>
                        ],
                        datasets: [{
                            label: 'Total Applications',
                            data: [
                                <?php foreach ($monthly_trends as $trend): ?>
                                <?php echo $trend['total']; ?>,
                                <?php endforeach; ?>
                            ],
                            borderColor: '#0d6efd',
                            backgroundColor: 'rgba(13, 110, 253, 0.1)',
                            tension: 0.4
                        }, {
                            label: 'Approved',
                            data: [
                                <?php foreach ($monthly_trends as $trend): ?>
                                <?php echo $trend['approved']; ?>,
                                <?php endforeach; ?>
                            ],
                            borderColor: '#198754',
                            backgroundColor: 'rgba(25, 135, 84, 0.1)',
                            tension: 0.4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false
                    }
                });
            }
        });

        function updateStatus(id, currentStatus) {
            document.getElementById('status_app_id').value = id;
            const select = document.getElementById('status_select');
            
            // Set default next status based on current
            const nextStatus = {
                'draft': 'submitted',
                'submitted': 'under_review',
                'under_review': 'approved',
                'approved': 'completed'
            };
            
            select.value = nextStatus[currentStatus] || 'under_review';
        }

        function exportTableToCSV() {
            const table = document.getElementById('applicationsTable');
            const rows = table.querySelectorAll('tr');
            const csv = [];
            
            rows.forEach(row => {
                const cells = row.querySelectorAll('td, th');
                const rowData = [];
                cells.forEach((cell, index) => {
                    // Skip actions column (last column)
                    if (index < cells.length - 1) {
                        rowData.push('"' + cell.textContent.trim().replace(/"/g, '""') + '"');
                    }
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
            a.download = 'applications_export_' + new Date().toISOString().slice(0,10) + '.csv';
            a.click();
        }
    </script>

    <style>
        .avatar-circle {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
        }
        
        .table td {
            vertical-align: middle;
        }
        
        .btn-group .btn {
            padding: 0.25rem 0.5rem;
        }
        
        .badge {
            font-size: 0.85rem;
        }
        
        .bg-opacity-10 {
            --bs-bg-opacity: 0.1;
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