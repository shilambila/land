<?php
ini_set('display_errors', 1); 
error_reporting(E_ALL);

// disputes.php - Land Dispute Management
include("head.php");
require_once 'db_connection.php';

// ============================================================================
// GET STATUS FILTER FROM URL
// ============================================================================

$status_filter = isset($_GET['id']) ? $_GET['id'] : (isset($_GET['status']) ? $_GET['status'] : 'all');
// Map URL parameters to actual status values
$status_map = [
    'open' => 'open',
    'in_progress' => 'in_progress',
    'resolved' => 'resolved',
    'closed' => 'closed'
];

$actual_status = isset($status_map[$status_filter]) ? $status_map[$status_filter] : null;

// ============================================================================
// HANDLE ACTIONS
// ============================================================================

$message = '';
$messageType = '';

// Delete dispute
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $disputeId = $_GET['delete'];
    
    try {
        executeQuery($conn, "DELETE FROM disputes WHERE id = ?", [$disputeId]);
        $message = "Dispute record deleted successfully";
        $messageType = "success";
    } catch (Exception $e) {
        $message = "Error deleting dispute: " . $e->getMessage();
        $messageType = "danger";
        error_log("Delete error: " . $e->getMessage());
    }
}

// Add new dispute
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $parcel_id = $_POST['parcel_id'];
    $dispute_type = $_POST['dispute_type'];
    $description = $_POST['description'];
    $filing_date = $_POST['filing_date'];
    $status = $_POST['status'] ?? 'open';
    
    try {
        // Start transaction
        beginTransaction($conn);
        
        // Check if parcel exists
        $parcel = fetchOne($conn, "SELECT id, parcel_number FROM parcels WHERE id = ?", [$parcel_id]);
        if (!$parcel) {
            throw new Exception("Parcel not found");
        }
        
        // Check for duplicate open disputes on same parcel
        if ($status == 'open' || $status == 'in_progress') {
            $existing = fetchOne($conn, "
                SELECT id FROM disputes 
                WHERE parcel_id = ? AND status IN ('open', 'in_progress')
            ", [$parcel_id]);
            
            if ($existing) {
                throw new Exception("There is already an active dispute for this parcel");
            }
        }
        
        // Insert dispute
        executeQuery($conn, "
            INSERT INTO disputes (
                parcel_id, dispute_type, description, filing_date, status, created_at
            ) VALUES (?, ?, ?, ?, ?, NOW())
        ", [$parcel_id, $dispute_type, $description, $filing_date, $status]);
        
        $dispute_id = $conn->lastInsertId();
        
        // Handle file upload if present
        if (isset($_FILES['dispute_document']) && $_FILES['dispute_document']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = 'uploads/disputes/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $file_extension = pathinfo($_FILES['dispute_document']['name'], PATHINFO_EXTENSION);
            $file_name = 'dispute_' . $dispute_id . '_' . time() . '.' . $file_extension;
            $file_path = $upload_dir . $file_name;
            
            if (move_uploaded_file($_FILES['dispute_document']['tmp_name'], $file_path)) {
                executeQuery($conn, "
                    INSERT INTO documents (
                        document_type, parcel_id, file_name, file_path, 
                        mime_type, uploaded_by, uploaded_at, description
                    ) VALUES ('dispute_document', ?, ?, ?, ?, ?, NOW(), ?)
                ", [$parcel_id, $_FILES['dispute_document']['name'], $file_path, 
                    $_FILES['dispute_document']['type'], $_SESSION['user_id'] ?? 1, 
                    "Dispute document for dispute #" . $dispute_id]);
            }
        }
        
        // Commit transaction
        commitTransaction($conn);
        
        $message = "Dispute filed successfully";
        $messageType = "success";
        
    } catch (Exception $e) {
        rollbackTransaction($conn);
        $message = "Error filing dispute: " . $e->getMessage();
        $messageType = "danger";
        error_log("Add error: " . $e->getMessage());
    }
}

// Update dispute
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit') {
    $dispute_id = $_POST['dispute_id'];
    $dispute_type = $_POST['dispute_type'];
    $description = $_POST['description'];
    $status = $_POST['status'];
    $resolution = $_POST['resolution'] ?? null;
    $resolved_date = ($status == 'resolved' || $status == 'closed') ? date('Y-m-d') : null;
    
    try {
        // If resolving, set resolved date
        if ($status == 'resolved' || $status == 'closed') {
            executeQuery($conn, "
                UPDATE disputes SET 
                    dispute_type = ?, 
                    description = ?, 
                    status = ?, 
                    resolution = ?,
                    resolved_date = ?
                WHERE id = ?
            ", [$dispute_type, $description, $status, $resolution, $resolved_date, $dispute_id]);
        } else {
            executeQuery($conn, "
                UPDATE disputes SET 
                    dispute_type = ?, 
                    description = ?, 
                    status = ?, 
                    resolution = ?
                WHERE id = ?
            ", [$dispute_type, $description, $status, $resolution, $dispute_id]);
        }
        
        $message = "Dispute updated successfully";
        $messageType = "success";
        
    } catch (Exception $e) {
        $message = "Error updating dispute: " . $e->getMessage();
        $messageType = "danger";
        error_log("Edit error: " . $e->getMessage());
    }
}

// ============================================================================
// FILTERS AND PAGINATION
// ============================================================================

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Build filter conditions
$where_conditions = ["1=1"];
$params = [];

// Status filter from URL
if ($actual_status) {
    $where_conditions[] = "d.status = ?";
    $params[] = $actual_status;
}

// Search
if (!empty($_GET['search'])) {
    $search = '%' . $_GET['search'] . '%';
    $where_conditions[] = "(p.parcel_number LIKE ? OR d.dispute_type LIKE ? OR d.description LIKE ?)";
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
}

// Dispute type filter
if (!empty($_GET['dispute_type'])) {
    $where_conditions[] = "d.dispute_type LIKE ?";
    $params[] = '%' . $_GET['dispute_type'] . '%';
}

// Date range filter
if (!empty($_GET['from_date'])) {
    $where_conditions[] = "d.filing_date >= ?";
    $params[] = $_GET['from_date'];
}
if (!empty($_GET['to_date'])) {
    $where_conditions[] = "d.filing_date <= ?";
    $params[] = $_GET['to_date'];
}

// State filter
if (!empty($_GET['state_id'])) {
    $where_conditions[] = "s.id = ?";
    $params[] = $_GET['state_id'];
}

// County filter
if (!empty($_GET['county_id'])) {
    $where_conditions[] = "c.id = ?";
    $params[] = $_GET['county_id'];
}

$where_clause = "WHERE " . implode(" AND ", $where_conditions);

// Get total count for pagination
$count_sql = "
    SELECT COUNT(*) as total
    FROM disputes d
    JOIN parcels p ON d.parcel_id = p.id
    LEFT JOIN bomas b ON p.boma_id = b.id
    LEFT JOIN payams pa ON b.payam_id = pa.id
    LEFT JOIN counties c ON pa.county_id = c.id
    LEFT JOIN states s ON c.state_id = s.id
    $where_clause
";

$total_result = executeQuery($conn, $count_sql, $params);
$total_rows = $total_result->fetch()['total'];
$total_pages = ceil($total_rows / $limit);

// Get disputes with all related information
$sql = "
    SELECT 
        d.*,
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
        t.id as title_id,
        t.title_number,
        t.title_type,
        CONCAT(owner.name, ' (', o.share_percentage, '%)') as owner_name,
        COUNT(DISTINCT doc.id) as document_count
    FROM disputes d
    JOIN parcels p ON d.parcel_id = p.id
    LEFT JOIN bomas b ON p.boma_id = b.id
    LEFT JOIN payams pa ON b.payam_id = pa.id
    LEFT JOIN counties c ON pa.county_id = c.id
    LEFT JOIN states s ON c.state_id = s.id
    LEFT JOIN titles t ON p.id = t.parcel_id AND t.status = 'active'
    LEFT JOIN ownerships o ON t.id = o.title_id AND o.is_current = 1
    LEFT JOIN parties owner ON o.party_id = owner.id
    LEFT JOIN documents doc ON doc.parcel_id = p.id AND doc.document_type = 'dispute_document'
    $where_clause
    GROUP BY d.id
    ORDER BY 
        CASE d.status
            WHEN 'open' THEN 1
            WHEN 'in_progress' THEN 2
            WHEN 'resolved' THEN 3
            WHEN 'closed' THEN 4
            ELSE 5
        END,
        d.filing_date DESC
    LIMIT ? OFFSET ?
";

$params[] = $limit;
$params[] = $offset;

$result = executeQuery($conn, $sql, $params);
$disputes = [];
if ($result) {
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        $disputes[] = $row;
    }
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
    // Get all parcels for dropdown
    $parcels = fetchAll($conn, "
        SELECT p.id, p.parcel_number, 
               b.name as boma_name, pa.name as payam_name
        FROM parcels p
        JOIN bomas b ON p.boma_id = b.id
        JOIN payams pa ON b.payam_id = pa.id
        ORDER BY p.parcel_number
    ");
} catch (Exception $e) {
    error_log("Error loading parcels: " . $e->getMessage());
    $parcels = [];
}

// Common dispute types
$dispute_types = [
    'Boundary Dispute',
    'Ownership Dispute',
    'Land Use Conflict',
    'Encroachment',
    'Inheritance Dispute',
    'Fraudulent Transfer',
    'Survey Error',
    'Tax Dispute',
    'Easement Dispute',
    'Environmental Violation',
    'Family/Clan Dispute',
    'Other'
];

// ============================================================================
// SUMMARY STATISTICS
// ============================================================================

try {
    $summary = fetchOne($conn, "
        SELECT 
            COUNT(*) as total_disputes,
            SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open,
            SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
            SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved,
            SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed,
            COUNT(DISTINCT parcel_id) as unique_parcels,
            AVG(DATEDIFF(COALESCE(resolved_date, CURDATE()), filing_date)) as avg_resolution_days
        FROM disputes
    ");
} catch (Exception $e) {
    error_log("Error loading summary: " . $e->getMessage());
    $summary = [
        'total_disputes' => 0,
        'open' => 0,
        'in_progress' => 0,
        'resolved' => 0,
        'closed' => 0,
        'unique_parcels' => 0,
        'avg_resolution_days' => 0
    ];
}

try {
    // Disputes by type
    $type_stats = fetchAll($conn, "
        SELECT 
            dispute_type,
            COUNT(*) as count,
            SUM(CASE WHEN status = 'open' OR status = 'in_progress' THEN 1 ELSE 0 END) as active
        FROM disputes
        GROUP BY dispute_type
        ORDER BY count DESC
        LIMIT 5
    ");
} catch (Exception $e) {
    error_log("Error loading type stats: " . $e->getMessage());
    $type_stats = [];
}

try {
    // Monthly trend
    $monthly_trend = fetchAll($conn, "
        SELECT 
            DATE_FORMAT(filing_date, '%Y-%m') as month,
            COUNT(*) as filed,
            SUM(CASE WHEN resolved_date IS NOT NULL THEN 1 ELSE 0 END) as resolved
        FROM disputes
        WHERE filing_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(filing_date, '%Y-%m')
        ORDER BY month DESC
    ");
} catch (Exception $e) {
    error_log("Error loading monthly trend: " . $e->getMessage());
    $monthly_trend = [];
}

// Status labels and classes
$status_labels = [
    'open' => 'Open',
    'in_progress' => 'In Progress',
    'resolved' => 'Resolved',
    'closed' => 'Closed'
];

$status_classes = [
    'open' => 'danger',
    'in_progress' => 'warning',
    'resolved' => 'success',
    'closed' => 'secondary'
];
?>

<body data-page="disputes" class="disputes-page">
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
                            <h1 class="h3 mb-0">Land Dispute Management</h1>
                            <p class="text-muted mb-0">Track and manage land-related disputes and resolutions</p>
                        </div>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addDisputeModal">
                            <i class="bi bi-exclamation-triangle me-2"></i>File New Dispute
                        </button>
                    </div>

                    <!-- Alert Message -->
                    <?php if ($message): ?>
                    <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show mb-4" role="alert">
                        <?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    <?php endif; ?>

                    <!-- Status Tabs -->
                    <ul class="nav nav-tabs mb-4">
                        <li class="nav-item">
                            <a class="nav-link <?php echo $status_filter == 'all' ? 'active' : ''; ?>" 
                               href="disputes.php?status=all">
                                All Disputes
                                <span class="badge bg-secondary ms-2"><?php echo $summary['total_disputes'] ?? 0; ?></span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $status_filter == 'open' ? 'active' : ''; ?>" 
                               href="disputes.php?id=open">
                                <i class="bi bi-exclamation-circle text-danger"></i> Open
                                <span class="badge bg-danger ms-2"><?php echo $summary['open'] ?? 0; ?></span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $status_filter == 'in_progress' ? 'active' : ''; ?>" 
                               href="disputes.php?status=in_progress">
                                <i class="bi bi-hourglass-split text-warning"></i> In Progress
                                <span class="badge bg-warning ms-2"><?php echo $summary['in_progress'] ?? 0; ?></span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $status_filter == 'resolved' ? 'active' : ''; ?>" 
                               href="disputes.php?id=resolved">
                                <i class="bi bi-check-circle text-success"></i> Resolved
                                <span class="badge bg-success ms-2"><?php echo $summary['resolved'] ?? 0; ?></span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $status_filter == 'closed' ? 'active' : ''; ?>" 
                               href="disputes.php?status=closed">
                                <i class="bi bi-archive text-secondary"></i> Closed
                                <span class="badge bg-secondary ms-2"><?php echo $summary['closed'] ?? 0; ?></span>
                            </a>
                        </li>
                    </ul>

                    <!-- Summary Cards -->
                    <div class="row g-4 mb-5">
                        <div class="col-xl-3 col-md-6">
                            <div class="card border-0 shadow-sm border-start border-danger border-4">
                                <div class="card-body">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0 me-3">
                                            <div class="bg-danger bg-opacity-10 rounded-circle p-3">
                                                <i class="bi bi-exclamation-triangle text-danger fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Active Disputes</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo ($summary['open'] ?? 0) + ($summary['in_progress'] ?? 0); ?></h3>
                                            <small class="text-muted">Open: <?php echo $summary['open'] ?? 0; ?> | In Progress: <?php echo $summary['in_progress'] ?? 0; ?></small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-xl-3 col-md-6">
                            <div class="card border-0 shadow-sm border-start border-success border-4">
                                <div class="card-body">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0 me-3">
                                            <div class="bg-success bg-opacity-10 rounded-circle p-3">
                                                <i class="bi bi-check-circle text-success fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Resolved/Closed</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo ($summary['resolved'] ?? 0) + ($summary['closed'] ?? 0); ?></h3>
                                            <small class="text-muted">Resolved: <?php echo $summary['resolved'] ?? 0; ?> | Closed: <?php echo $summary['closed'] ?? 0; ?></small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-xl-3 col-md-6">
                            <div class="card border-0 shadow-sm border-start border-info border-4">
                                <div class="card-body">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0 me-3">
                                            <div class="bg-info bg-opacity-10 rounded-circle p-3">
                                                <i class="bi bi-grid text-info fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Affected Parcels</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['unique_parcels'] ?? 0); ?></h3>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-xl-3 col-md-6">
                            <div class="card border-0 shadow-sm border-start border-warning border-4">
                                <div class="card-body">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0 me-3">
                                            <div class="bg-warning bg-opacity-10 rounded-circle p-3">
                                                <i class="bi bi-clock-history text-warning fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Avg Resolution Time</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo round($summary['avg_resolution_days'] ?? 0); ?> days</h3>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Filters -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-body">
                            <form method="GET" class="row g-3">
                                <input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>">
                                
                                <div class="col-md-3">
                                    <label class="form-label">Search</label>
                                    <input type="text" class="form-control" name="search" 
                                           placeholder="Parcel #, Dispute Type, Description..."
                                           value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                                </div>
                                
                                <div class="col-md-2">
                                    <label class="form-label">Dispute Type</label>
                                    <select class="form-select" name="dispute_type">
                                        <option value="">All Types</option>
                                        <?php foreach ($dispute_types as $type): ?>
                                        <option value="<?php echo $type; ?>" <?php echo ($_GET['dispute_type'] ?? '') == $type ? 'selected' : ''; ?>>
                                            <?php echo $type; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-2">
                                    <label class="form-label">State</label>
                                    <select class="form-select" name="state_id" id="filterState" onchange="this.form.submit()">
                                        <option value="">All States</option>
                                        <?php foreach ($states as $state): ?>
                                        <option value="<?php echo $state['id']; ?>" 
                                            <?php echo ($selected_state == $state['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($state['name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-2">
                                    <label class="form-label">County</label>
                                    <select class="form-select" name="county_id" id="filterCounty" onchange="this.form.submit()" 
                                            <?php echo empty($counties) ? 'disabled' : ''; ?>>
                                        <option value="">All Counties</option>
                                        <?php foreach ($counties as $county): ?>
                                        <option value="<?php echo $county['id']; ?>" 
                                            <?php echo ($selected_county == $county['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($county['name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-3">
                                    <label class="form-label">Filing Date Range</label>
                                    <div class="input-group">
                                        <input type="date" class="form-control" name="from_date" 
                                               value="<?php echo htmlspecialchars($_GET['from_date'] ?? ''); ?>" placeholder="From">
                                        <input type="date" class="form-control" name="to_date" 
                                               value="<?php echo htmlspecialchars($_GET['to_date'] ?? ''); ?>" placeholder="To">
                                    </div>
                                </div>
                                
                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-search me-2"></i>Apply Filters
                                    </button>
                                    <a href="disputes.php?status=<?php echo $status_filter; ?>" class="btn btn-secondary">
                                        <i class="bi bi-x-circle me-2"></i>Clear Filters
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Disputes Table -->
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0 fw-bold">
                                <i class="bi bi-exclamation-triangle text-danger me-2"></i>
                                <?php echo $status_filter == 'all' ? 'All Disputes' : ($status_labels[$actual_status] ?? 'Disputes'); ?>
                                <?php if ($actual_status): ?>
                                    <span class="badge bg-<?php echo $status_classes[$actual_status] ?? 'secondary'; ?> ms-2">
                                        <?php echo $status_labels[$actual_status] ?? ''; ?>
                                    </span>
                                <?php endif; ?>
                            </h5>
                            <span class="badge bg-primary"><?php echo number_format($total_rows); ?> Records</span>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>ID</th>
                                            <th>Parcel</th>
                                            <th>Location</th>
                                            <th>Owner</th>
                                            <th>Dispute Type</th>
                                            <th>Filing Date</th>
                                            <th>Status</th>
                                            <th>Resolution Date</th>
                                            <th>Docs</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($disputes)): ?>
                                        <tr>
                                            <td colspan="10" class="text-center py-4 text-muted">
                                                <i class="bi bi-exclamation-triangle fs-1 d-block mb-3"></i>
                                                No disputes found. 
                                                <a href="#" data-bs-toggle="modal" data-bs-target="#addDisputeModal">Click here</a> to file one.
                                            </td>
                                        </tr>
                                        <?php else: ?>
                                            <?php foreach ($disputes as $dispute): ?>
                                            <tr>
                                                <td>
                                                    <span class="fw-medium">#<?php echo $dispute['id']; ?></span>
                                                </td>
                                                <td>
                                                    <span class="fw-medium"><?php echo htmlspecialchars($dispute['parcel_number']); ?></span>
                                                    <?php if (!empty($dispute['parcel_area'])): ?>
                                                        <br><small class="text-muted">Area: <?php echo number_format($dispute['parcel_area'], 2); ?> m²</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <small>
                                                        <?php echo htmlspecialchars($dispute['boma_name'] ?? ''); ?>
                                                        <?php if (!empty($dispute['payam_name'])): ?><br><?php echo $dispute['payam_name']; ?><?php endif; ?>
                                                        <?php if (!empty($dispute['county_name'])): ?><br><?php echo $dispute['county_name']; ?><?php endif; ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <?php if (!empty($dispute['owner_name'])): ?>
                                                        <span class="fw-medium"><?php echo htmlspecialchars($dispute['owner_name']); ?></span>
                                                        <?php if (!empty($dispute['title_number'])): ?>
                                                            <br><small class="text-muted">Title: <?php echo $dispute['title_number']; ?></small>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">Unknown</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge bg-info"><?php echo htmlspecialchars($dispute['dispute_type']); ?></span>
                                                </td>
                                                <td>
                                                    <?php echo date('d M Y', strtotime($dispute['filing_date'])); ?>
                                                </td>
                                                <td>
                                                    <?php
                                                    $status_class = [
                                                        'open' => 'danger',
                                                        'in_progress' => 'warning',
                                                        'resolved' => 'success',
                                                        'closed' => 'secondary'
                                                    ][$dispute['status']] ?? 'secondary';
                                                    ?>
                                                    <span class="badge bg-<?php echo $status_class; ?>">
                                                        <?php echo ucfirst(str_replace('_', ' ', $dispute['status'])); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php echo $dispute['resolved_date'] ? date('d M Y', strtotime($dispute['resolved_date'])) : '-'; ?>
                                                </td>
                                                <td>
                                                    <span class="badge bg-secondary"><?php echo $dispute['document_count'] ?? 0; ?></span>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <button class="btn btn-outline-primary" 
                                                                onclick="editDispute(<?php echo htmlspecialchars(json_encode($dispute)); ?>)"
                                                                data-bs-toggle="modal" data-bs-target="#editDisputeModal">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                        <button class="btn btn-outline-info" 
                                                                onclick="viewDispute(<?php echo htmlspecialchars(json_encode($dispute)); ?>)"
                                                                data-bs-toggle="modal" data-bs-target="#viewDisputeModal">
                                                            <i class="bi bi-eye"></i>
                                                        </button>
                                                        <a href="?delete=<?php echo $dispute['id']; ?>&status=<?php echo $status_filter; ?>" 
                                                           class="btn btn-outline-danger"
                                                           onclick="return confirm('Delete this dispute record? This action cannot be undone.')">
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
                        
                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                        <div class="card-footer bg-white py-3">
                            <nav>
                                <ul class="pagination justify-content-center mb-0">
                                    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                                            Previous
                                        </a>
                                    </li>
                                    
                                    <?php for ($i = max(1, $page - 2); $i <= min($page + 2, $total_pages); $i++): ?>
                                    <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    </li>
                                    <?php endfor; ?>
                                    
                                    <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                                            Next
                                        </a>
                                    </li>
                                </ul>
                            </nav>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Charts and Stats Row -->
                    <div class="row mt-4">
                        <div class="col-md-6">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h5 class="card-title mb-0 fw-bold">
                                        <i class="bi bi-pie-chart text-info me-2"></i>
                                        Disputes by Type
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($type_stats)): ?>
                                        <p class="text-muted text-center">No dispute type data available</p>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-sm">
                                                <thead>
                                                    <tr>
                                                        <th>Dispute Type</th>
                                                        <th>Total</th>
                                                        <th>Active</th>
                                                        <th>Progress</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($type_stats as $stat): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($stat['dispute_type']); ?></td>
                                                        <td><span class="badge bg-primary"><?php echo $stat['count']; ?></span></td>
                                                        <td><span class="badge bg-danger"><?php echo $stat['active']; ?></span></td>
                                                        <td style="width: 150px;">
                                                            <div class="progress" style="height: 8px;">
                                                                <div class="progress-bar bg-success" role="progressbar" 
                                                                     style="width: <?php echo ($stat['count'] - $stat['active']) / $stat['count'] * 100; ?>%"></div>
                                                                <div class="progress-bar bg-danger" role="progressbar" 
                                                                     style="width: <?php echo $stat['active'] / $stat['count'] * 100; ?>%"></div>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h5 class="card-title mb-0 fw-bold">
                                        <i class="bi bi-graph-up text-success me-2"></i>
                                        Monthly Trend
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <canvas id="monthlyChart" height="200"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Dispute Resolution Guidelines -->
                    <div class="row mt-4">
                        <div class="col-md-12">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h5 class="card-title mb-0 fw-bold">
                                        <i class="bi bi-info-circle text-info me-2"></i>
                                        Dispute Resolution Guidelines
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-3">
                                            <div class="text-center">
                                                <span class="badge bg-danger p-3 rounded-circle mb-2">
                                                    <i class="bi bi-exclamation-circle fs-4"></i>
                                                </span>
                                                <h6>Open</h6>
                                                <small class="text-muted">New dispute filed, awaiting review</small>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="text-center">
                                                <span class="badge bg-warning p-3 rounded-circle mb-2">
                                                    <i class="bi bi-hourglass-split fs-4"></i>
                                                </span>
                                                <h6>In Progress</h6>
                                                <small class="text-muted">Investigation and mediation ongoing</small>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="text-center">
                                                <span class="badge bg-success p-3 rounded-circle mb-2">
                                                    <i class="bi bi-check-circle fs-4"></i>
                                                </span>
                                                <h6>Resolved</h6>
                                                <small class="text-muted">Resolution reached and documented</small>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="text-center">
                                                <span class="badge bg-secondary p-3 rounded-circle mb-2">
                                                    <i class="bi bi-archive fs-4"></i>
                                                </span>
                                                <h6>Closed</h6>
                                                <small class="text-muted">File archived, no further action</small>
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

    <!-- Add Dispute Modal -->
    <div class="modal fade" id="addDisputeModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="add">
                    <div class="modal-header">
                        <h5 class="modal-title">File New Dispute</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Select Parcel <span class="text-danger">*</span></label>
                                <select class="form-select" name="parcel_id" required>
                                    <option value="">-- Select a parcel --</option>
                                    <?php foreach ($parcels as $parcel): ?>
                                    <option value="<?php echo $parcel['id']; ?>">
                                        <?php echo $parcel['parcel_number']; ?> - 
                                        <?php echo htmlspecialchars($parcel['boma_name']); ?>, 
                                        <?php echo htmlspecialchars($parcel['payam_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Dispute Type <span class="text-danger">*</span></label>
                                <select class="form-select" name="dispute_type" required>
                                    <option value="">-- Select type --</option>
                                    <?php foreach ($dispute_types as $type): ?>
                                    <option value="<?php echo $type; ?>"><?php echo $type; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Filing Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="filing_date" 
                                       value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Initial Status</label>
                                <select class="form-select" name="status">
                                    <option value="open">Open</option>
                                    <option value="in_progress">In Progress</option>
                                </select>
                            </div>
                            
                            <div class="col-12 mb-3">
                                <label class="form-label">Description <span class="text-danger">*</span></label>
                                <textarea class="form-control" name="description" rows="4" required 
                                          placeholder="Provide detailed description of the dispute..."></textarea>
                            </div>
                            
                            <div class="col-12 mb-3">
                                <label class="form-label">Supporting Document (optional)</label>
                                <input type="file" class="form-control" name="dispute_document" 
                                       accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                                <small class="text-muted">Upload any relevant documents (evidence, claims, etc.)</small>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">File Dispute</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Dispute Modal -->
    <div class="modal fade" id="editDisputeModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="dispute_id" id="editDisputeId">
                    <div class="modal-header">
                        <h5 class="modal-title">Update Dispute</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Dispute Type</label>
                                <select class="form-select" name="dispute_type" id="editDisputeType" required>
                                    <?php foreach ($dispute_types as $type): ?>
                                    <option value="<?php echo $type; ?>"><?php echo $type; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status" id="editStatus" onchange="toggleResolutionField()" required>
                                    <option value="open">Open</option>
                                    <option value="in_progress">In Progress</option>
                                    <option value="resolved">Resolved</option>
                                    <option value="closed">Closed</option>
                                </select>
                            </div>
                            
                            <div class="col-12 mb-3">
                                <label class="form-label">Description</label>
                                <textarea class="form-control" name="description" id="editDescription" rows="3" required></textarea>
                            </div>
                            
                            <div class="col-12 mb-3" id="resolutionField">
                                <label class="form-label">Resolution Details</label>
                                <textarea class="form-control" name="resolution" id="editResolution" rows="3" 
                                          placeholder="Describe how the dispute was resolved..."></textarea>
                            </div>
                            
                            <div class="col-12">
                                <div class="alert alert-info">
                                    <i class="bi bi-info-circle"></i>
                                    Setting status to "Resolved" or "Closed" will automatically set the resolution date to today.
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Dispute</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Dispute Modal -->
    <div class="modal fade" id="viewDisputeModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Dispute Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Dispute ID:</label>
                            <p id="viewDisputeId" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Status:</label>
                            <p id="viewStatus" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Dispute Type:</label>
                            <p id="viewType" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Filing Date:</label>
                            <p id="viewFilingDate" class="mb-0"></p>
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
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Current Owner:</label>
                            <p id="viewOwner" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Title Number:</label>
                            <p id="viewTitle" class="mb-0"></p>
                        </div>
                        
                        <div class="col-12">
                            <hr>
                            <h6 class="fw-bold">Dispute Details</h6>
                        </div>
                        
                        <div class="col-12 mb-3">
                            <label class="fw-bold">Description:</label>
                            <p id="viewDescription" class="mb-0"></p>
                        </div>
                        
                        <div class="col-12 mb-3">
                            <label class="fw-bold">Resolution:</label>
                            <p id="viewResolution" class="mb-0"></p>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Resolution Date:</label>
                            <p id="viewResolutionDate" class="mb-0"></p>
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

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Toggle resolution field based on status
        function toggleResolutionField() {
            const status = document.getElementById('editStatus').value;
            const resolutionField = document.getElementById('resolutionField');
            
            if (status === 'resolved' || status === 'closed') {
                resolutionField.style.display = 'block';
            } else {
                resolutionField.style.display = 'none';
            }
        }
        
        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            toggleResolutionField();
        });
        
        // Edit dispute function
        function editDispute(dispute) {
            document.getElementById('editDisputeId').value = dispute.id;
            document.getElementById('editDisputeType').value = dispute.dispute_type || '';
            document.getElementById('editStatus').value = dispute.status || 'open';
            document.getElementById('editDescription').value = dispute.description || '';
            document.getElementById('editResolution').value = dispute.resolution || '';
            
            toggleResolutionField();
        }
        
        // View dispute function
        function viewDispute(dispute) {
            document.getElementById('viewDisputeId').textContent = '#' + (dispute.id || 'N/A');
            
            let statusBadge = '';
            const status = dispute.status || 'unknown';
            const statusClasses = {
                'open': 'danger',
                'in_progress': 'warning',
                'resolved': 'success',
                'closed': 'secondary'
            };
            statusBadge = '<span class="badge bg-' + (statusClasses[status] || 'secondary') + '">' + 
                         (status ? status.replace('_', ' ') : 'N/A') + '</span>';
            document.getElementById('viewStatus').innerHTML = statusBadge;
            
            document.getElementById('viewType').textContent = dispute.dispute_type || 'N/A';
            document.getElementById('viewFilingDate').textContent = dispute.filing_date || 'N/A';
            
            document.getElementById('viewParcelNumber').textContent = dispute.parcel_number || 'N/A';
            document.getElementById('viewArea').textContent = dispute.parcel_area ? dispute.parcel_area + ' m²' : 'N/A';
            document.getElementById('viewLocation').textContent = 
                (dispute.boma_name || '') + ', ' + (dispute.payam_name || '') + ', ' + (dispute.county_name || '');
            document.getElementById('viewOwner').textContent = dispute.owner_name || 'Unknown';
            document.getElementById('viewTitle').textContent = dispute.title_number || 'No active title';
            
            document.getElementById('viewDescription').textContent = dispute.description || 'No description provided';
            document.getElementById('viewResolution').textContent = dispute.resolution || 'Not resolved yet';
            document.getElementById('viewResolutionDate').textContent = dispute.resolved_date || 'N/A';
            
            document.getElementById('viewDocuments').innerHTML = 
                '<span class="badge bg-primary">' + (dispute.document_count || 0) + ' document(s)</span>';
        }
        
        // Monthly trend chart
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('monthlyChart')?.getContext('2d');
            if (ctx) {
                const months = <?php echo json_encode(array_column($monthly_trend, 'month')); ?>;
                const filed = <?php echo json_encode(array_column($monthly_trend, 'filed')); ?>;
                const resolved = <?php echo json_encode(array_column($monthly_trend, 'resolved')); ?>;
                
                if (months.length > 0) {
                    new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: months,
                            datasets: [
                                {
                                    label: 'Filed',
                                    data: filed,
                                    borderColor: 'rgb(255, 99, 132)',
                                    backgroundColor: 'rgba(255, 99, 132, 0.1)',
                                    tension: 0.1
                                },
                                {
                                    label: 'Resolved',
                                    data: resolved,
                                    borderColor: 'rgb(75, 192, 192)',
                                    backgroundColor: 'rgba(75, 192, 192, 0.1)',
                                    tension: 0.1
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
                                        stepSize: 1
                                    }
                                }
                            }
                        }
                    });
                } else {
                    ctx.canvas.parentNode.innerHTML = '<p class="text-muted text-center">No monthly data available</p>';
                }
            }
        });
        
        // Auto-submit filters
        document.getElementById('filterState')?.addEventListener('change', function() {
            this.form.submit();
        });
        
        document.getElementById('filterCounty')?.addEventListener('change', function() {
            this.form.submit();
        });
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
        
        .nav-tabs .nav-link .badge {
            font-size: 0.7rem;
        }
        
        .border-4 {
            border-width: 4px !important;
        }
        
        .rounded-circle {
            width: 60px;
            height: 60px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        
        .progress {
            margin-top: 5px;
        }
    </style>

</body>
</html>