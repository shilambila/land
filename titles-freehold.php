<?php
ini_set('display_errors', 1); 
error_reporting(E_ALL);

// titles-freehold.php
include("head.php");
require_once 'db_connection.php';

// ============================================================================
// HANDLE ACTIONS
// ============================================================================

$message = '';
$messageType = '';

// Delete freehold title
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $titleId = $_GET['delete'];
    
    try {
        // Check if title has related records
        $related = fetchOne($conn, "
            SELECT 
                (SELECT COUNT(*) FROM ownerships WHERE title_id = ?) as ownerships,
                (SELECT COUNT(*) FROM documents WHERE title_id = ?) as documents,
                (SELECT COUNT(*) FROM transactions WHERE title_id = ?) as transactions
        ", [$titleId, $titleId, $titleId]);
        
        if ($related['ownerships'] > 0 || $related['documents'] > 0 || $related['transactions'] > 0) {
            // Soft delete - just mark as cancelled
            executeQuery($conn, "UPDATE titles SET status = 'cancelled' WHERE id = ?", [$titleId]);
            $message = "Freehold title deactivated (has related records)";
            $messageType = "warning";
        } else {
            executeQuery($conn, "DELETE FROM titles WHERE id = ? AND title_type = 'freehold'", [$titleId]);
            $message = "Freehold title deleted successfully";
            $messageType = "success";
        }
    } catch (Exception $e) {
        $message = "Error deleting freehold title: " . $e->getMessage();
        $messageType = "danger";
    }
}

// Add new freehold title
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $parcel_id = $_POST['parcel_id'];
    $title_number = $_POST['title_number'];
    $issue_date = $_POST['issue_date'];
    $previous_title_id = !empty($_POST['previous_title_id']) ? $_POST['previous_title_id'] : null;
    $owner_party_id = $_POST['owner_party_id'];
    $share_percentage = $_POST['share_percentage'] ?? 100;
    $registration_notes = $_POST['registration_notes'] ?? null;
    $document_file = null;
    
    try {
        // Start transaction
        beginTransaction($conn);
        
        // Check if title number already exists
        $exists = fetchOne($conn, "SELECT id FROM titles WHERE title_number = ?", [$title_number]);
        if ($exists) {
            throw new Exception("Title number already exists");
        }
        
        // Check if parcel already has an active freehold title
        $existing_title = fetchOne($conn, "
            SELECT id FROM titles 
            WHERE parcel_id = ? AND title_type = 'freehold' AND status = 'active'
        ", [$parcel_id]);
        
        if ($existing_title) {
            throw new Exception("This parcel already has an active freehold title");
        }
        
        // Insert the freehold title
        executeQuery($conn, "
            INSERT INTO titles (
                parcel_id, title_number, title_type, issue_date, 
                previous_title_id, status, created_at
            ) VALUES (?, ?, 'freehold', ?, ?, 'active', NOW())
        ", [$parcel_id, $title_number, $issue_date, $previous_title_id]);
        
        $title_id = $conn->lastInsertId();
        
        // Add owner to ownerships table
        executeQuery($conn, "
            INSERT INTO ownerships (
                title_id, party_id, share_percentage, ownership_start_date, created_at
            ) VALUES (?, ?, ?, ?, NOW())
        ", [$title_id, $owner_party_id, $share_percentage, $issue_date]);
        
        // Handle file upload if present
        if (isset($_FILES['title_document']) && $_FILES['title_document']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = 'uploads/titles/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $file_extension = pathinfo($_FILES['title_document']['name'], PATHINFO_EXTENSION);
            $file_name = 'title_' . $title_number . '_' . time() . '.' . $file_extension;
            $file_path = $upload_dir . $file_name;
            
            if (move_uploaded_file($_FILES['title_document']['tmp_name'], $file_path)) {
                executeQuery($conn, "
                    INSERT INTO documents (
                        document_type, parcel_id, title_id, file_name, file_path, 
                        mime_type, uploaded_by, uploaded_at, description
                    ) VALUES ('title_deed', ?, ?, ?, ?, ?, ?, NOW(), ?)
                ", [$parcel_id, $title_id, $_FILES['title_document']['name'], $file_path, 
                    $_FILES['title_document']['type'], $_SESSION['user_id'] ?? 1, 
                    "Title deed for " . $title_number]);
            }
        }
        
        // Commit transaction
        commitTransaction($conn);
        
        $message = "Freehold title created successfully";
        $messageType = "success";
        
    } catch (Exception $e) {
        rollbackTransaction($conn);
        $message = "Error creating freehold title: " . $e->getMessage();
        $messageType = "danger";
    }
}

// Update freehold title
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit') {
    $title_id = $_POST['title_id'];
    $title_number = $_POST['title_number'];
    $issue_date = $_POST['issue_date'];
    $status = $_POST['status'];
    $previous_title_id = !empty($_POST['previous_title_id']) ? $_POST['previous_title_id'] : null;
    $registration_notes = $_POST['registration_notes'] ?? null;
    
    try {
        // Check if title number already exists (excluding current)
        $exists = fetchOne($conn, "
            SELECT id FROM titles 
            WHERE title_number = ? AND id != ? AND title_type = 'freehold'
        ", [$title_number, $title_id]);
        
        if ($exists) {
            throw new Exception("Title number already exists");
        }
        
        // Update title
        executeQuery($conn, "
            UPDATE titles SET 
                title_number = ?, issue_date = ?, status = ?, 
                previous_title_id = ?, updated_at = NOW()
            WHERE id = ? AND title_type = 'freehold'
        ", [$title_number, $issue_date, $status, $previous_title_id, $title_id]);
        
        $message = "Freehold title updated successfully";
        $messageType = "success";
        
    } catch (Exception $e) {
        $message = "Error updating freehold title: " . $e->getMessage();
        $messageType = "danger";
    }
}

// ============================================================================
// FILTERS AND PAGINATION
// ============================================================================

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Build filter conditions
$where_conditions = ["t.title_type = 'freehold'"];
$params = [];

// Search
if (!empty($_GET['search'])) {
    $search = '%' . $_GET['search'] . '%';
    $where_conditions[] = "(t.title_number LIKE ? OR p.parcel_number LIKE ? OR parties.name LIKE ?)";
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
}

// Status filter
if (!empty($_GET['status'])) {
    $where_conditions[] = "t.status = ?";
    $params[] = $_GET['status'];
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

// Payam filter
if (!empty($_GET['payam_id'])) {
    $where_conditions[] = "pa.id = ?";
    $params[] = $_GET['payam_id'];
}

// Boma filter
if (!empty($_GET['boma_id'])) {
    $where_conditions[] = "b.id = ?";
    $params[] = $_GET['boma_id'];
}

// Date range filter
if (!empty($_GET['from_date'])) {
    $where_conditions[] = "t.issue_date >= ?";
    $params[] = $_GET['from_date'];
}
if (!empty($_GET['to_date'])) {
    $where_conditions[] = "t.issue_date <= ?";
    $params[] = $_GET['to_date'];
}

$where_clause = "WHERE " . implode(" AND ", $where_conditions);

// Get total count for pagination
$count_sql = "
    SELECT COUNT(DISTINCT t.id) as total
    FROM titles t
    JOIN parcels p ON t.parcel_id = p.id
    JOIN bomas b ON p.boma_id = b.id
    JOIN payams pa ON b.payam_id = pa.id
    JOIN counties c ON pa.county_id = c.id
    JOIN states s ON c.state_id = s.id
    LEFT JOIN ownerships o ON t.id = o.title_id AND o.is_current = 1
    LEFT JOIN parties ON o.party_id = parties.id
    $where_clause
";

$total_result = executeQuery($conn, $count_sql, $params);
$total_rows = $total_result->fetch()['total'];
$total_pages = ceil($total_rows / $limit);

// Get freehold titles with all related information
$sql = "
    SELECT 
        t.*,
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
        z.zone_code,
        z.zone_name,
        GROUP_CONCAT(DISTINCT CONCAT(parties.name, ' (', o.share_percentage, '%)') SEPARATOR ', ') as owners,
        COUNT(DISTINCT o.id) as owner_count,
        COUNT(DISTINCT doc.id) as document_count,
        COUNT(DISTINCT trans.id) as transaction_count,
        MAX(o.ownership_start_date) as latest_ownership_date,
        pt.title_number as previous_title_number
    FROM titles t
    JOIN parcels p ON t.parcel_id = p.id
    JOIN bomas b ON p.boma_id = b.id
    JOIN payams pa ON b.payam_id = pa.id
    JOIN counties c ON pa.county_id = c.id
    JOIN states s ON c.state_id = s.id
    LEFT JOIN zoning z ON p.current_zoning_id = z.id
    LEFT JOIN ownerships o ON t.id = o.title_id AND o.is_current = 1
    LEFT JOIN parties ON o.party_id = parties.id
    LEFT JOIN documents doc ON doc.title_id = t.id
    LEFT JOIN transactions trans ON trans.title_id = t.id
    LEFT JOIN titles pt ON t.previous_title_id = pt.id
    $where_clause
    GROUP BY t.id
    ORDER BY t.issue_date DESC, t.created_at DESC
    LIMIT ? OFFSET ?
";

$params[] = $limit;
$params[] = $offset;

$titles = fetchAll($conn, $sql, $params);

// ============================================================================
// GET HIERARCHICAL DATA FOR FILTERS
// ============================================================================

$states = fetchAll($conn, "SELECT id, name FROM states ORDER BY name");

$selected_state = $_GET['state_id'] ?? null;
$selected_county = $_GET['county_id'] ?? null;
$selected_payam = $_GET['payam_id'] ?? null;
$selected_boma = $_GET['boma_id'] ?? null;

$counties = [];
if ($selected_state) {
    $counties = fetchAll($conn, "SELECT id, name FROM counties WHERE state_id = ? ORDER BY name", [$selected_state]);
}

$payams = [];
if ($selected_county) {
    $payams = fetchAll($conn, "SELECT id, name FROM payams WHERE county_id = ? ORDER BY name", [$selected_county]);
}

$bomas = [];
if ($selected_payam) {
    $bomas = fetchAll($conn, "SELECT id, name FROM bomas WHERE payam_id = ? ORDER BY name", [$selected_payam]);
}

// ============================================================================
// GET DATA FOR DROPDOWNS
// ============================================================================

// Get parcels without active freehold titles for the add form
$available_parcels = fetchAll($conn, "
    SELECT p.id, p.parcel_number, p.area, 
           b.name as boma_name, pa.name as payam_name, c.name as county_name
    FROM parcels p
    JOIN bomas b ON p.boma_id = b.id
    JOIN payams pa ON b.payam_id = pa.id
    JOIN counties c ON pa.county_id = c.id
    WHERE NOT EXISTS (
        SELECT 1 FROM titles t 
        WHERE t.parcel_id = p.id AND t.title_type = 'freehold' AND t.status = 'active'
    )
    ORDER BY p.parcel_number
");

// Get parties for owner selection
$parties = fetchAll($conn, "
    SELECT id, name, party_type, national_id, registration_number 
    FROM parties 
    ORDER BY name
");

// Get previous titles for reference
$previous_titles = fetchAll($conn, "
    SELECT t.id, t.title_number, p.parcel_number
    FROM titles t
    JOIN parcels p ON t.parcel_id = p.id
    WHERE t.title_type = 'freehold'
    ORDER BY t.title_number
");

// ============================================================================
// SUMMARY STATISTICS
// ============================================================================

$summary = fetchOne($conn, "
    SELECT 
        COUNT(*) as total_freehold,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_titles,
        SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive_titles,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_titles,
        COUNT(DISTINCT parcel_id) as unique_parcels,
        (SELECT COUNT(DISTINCT party_id) FROM ownerships o JOIN titles tt ON o.title_id = tt.id WHERE tt.title_type = 'freehold') as total_owners
    FROM titles
    WHERE title_type = 'freehold'
");
?>

<body data-page="titles-freehold" class="titles-freehold-page">
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
                            <h1 class="h3 mb-0">Freehold Titles Management</h1>
                            <p class="text-muted mb-0">Manage perpetual land ownership titles</p>
                        </div>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addFreeholdModal">
                            <i class="bi bi-file-earmark-plus me-2"></i>Issue New Freehold Title
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
                                            <h6 class="text-muted mb-1">Total Freehold Titles</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['total_freehold'] ?? 0); ?></h3>
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
                                            <h6 class="text-muted mb-1">Active Titles</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['active_titles'] ?? 0); ?></h3>
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
                                                <i class="bi bi-grid text-info fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Unique Parcels</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['unique_parcels'] ?? 0); ?></h3>
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
                                                <i class="bi bi-people text-warning fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Total Owners</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['total_owners'] ?? 0); ?></h3>
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
                                <div class="col-md-3">
                                    <label class="form-label">Search</label>
                                    <input type="text" class="form-control" name="search" 
                                           placeholder="Title #, Parcel #, Owner..."
                                           value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                                </div>
                                
                                <div class="col-md-2">
                                    <label class="form-label">Status</label>
                                    <select class="form-select" name="status">
                                        <option value="">All Statuses</option>
                                        <option value="active" <?php echo ($_GET['status'] ?? '') == 'active' ? 'selected' : ''; ?>>Active</option>
                                        <option value="inactive" <?php echo ($_GET['status'] ?? '') == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                        <option value="cancelled" <?php echo ($_GET['status'] ?? '') == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
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
                                    <label class="form-label">Date Range</label>
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
                                    <a href="titles-freehold.php" class="btn btn-secondary">
                                        <i class="bi bi-x-circle me-2"></i>Clear Filters
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Freehold Titles Table -->
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0 fw-bold">
                                <i class="bi bi-file-text text-primary me-2"></i>
                                Freehold Titles
                            </h5>
                            <span class="badge bg-primary"><?php echo number_format($total_rows); ?> Records</span>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Title Number</th>
                                            <th>Parcel</th>
                                            <th>Location</th>
                                            <th>Owners</th>
                                            <th>Issue Date</th>
                                            <th>Status</th>
                                            <th>Documents</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($titles)): ?>
                                        <tr>
                                            <td colspan="8" class="text-center py-4 text-muted">
                                                <i class="bi bi-file-text fs-1 d-block mb-3"></i>
                                                No freehold titles found. Click "Issue New Freehold Title" to create one.
                                            </td>
                                        </tr>
                                        <?php else: ?>
                                            <?php foreach ($titles as $title): ?>
                                            <tr>
                                                <td>
                                                    <span class="fw-medium"><?php echo htmlspecialchars($title['title_number']); ?></span>
                                                    <?php if ($title['previous_title_number']): ?>
                                                        <br><small class="text-muted">(prev: <?php echo $title['previous_title_number']; ?>)</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="fw-medium"><?php echo htmlspecialchars($title['parcel_number']); ?></span>
                                                    <br><small class="text-muted">Area: <?php echo number_format($title['parcel_area'], 2); ?> m²</small>
                                                </td>
                                                <td>
                                                    <small>
                                                        <i class="bi bi-building text-success"></i> <?php echo htmlspecialchars($title['state_name']); ?><br>
                                                        <i class="bi bi-house text-info ms-2"></i> <?php echo htmlspecialchars($title['county_name']); ?><br>
                                                        <i class="bi bi-pin text-warning ms-2"></i> <?php echo htmlspecialchars($title['boma_name']); ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <?php if ($title['owner_count'] > 0): ?>
                                                        <span class="badge bg-info mb-1"><?php echo $title['owner_count']; ?> owner(s)</span>
                                                        <br><small><?php echo htmlspecialchars(substr($title['owners'] ?? '', 0, 50)); ?></small>
                                                    <?php else: ?>
                                                        <span class="text-muted">No owners</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php echo date('d M Y', strtotime($title['issue_date'])); ?>
                                                </td>
                                                <td>
                                                    <?php
                                                    $status_class = [
                                                        'active' => 'success',
                                                        'inactive' => 'secondary',
                                                        'cancelled' => 'danger'
                                                    ][$title['status']] ?? 'secondary';
                                                    ?>
                                                    <span class="badge bg-<?php echo $status_class; ?>">
                                                        <?php echo ucfirst($title['status']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge bg-secondary"><?php echo $title['document_count'] ?? 0; ?></span>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <button class="btn btn-outline-primary" 
                                                                onclick="editFreehold(<?php echo htmlspecialchars(json_encode($title)); ?>)"
                                                                data-bs-toggle="modal" data-bs-target="#editFreeholdModal">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                        <a href="?delete=<?php echo $title['id']; ?>" 
                                                           class="btn btn-outline-danger"
                                                           onclick="return confirm('Deactivate/Delete this freehold title? This may affect related records.')">
                                                            <i class="bi bi-trash"></i>
                                                        </a>
                                                        <button class="btn btn-outline-info" 
                                                                onclick="viewFreehold(<?php echo htmlspecialchars(json_encode($title)); ?>)"
                                                                data-bs-toggle="modal" data-bs-target="#viewFreeholdModal">
                                                            <i class="bi bi-eye"></i>
                                                        </button>
                                                        <a href="parcels-history.php?parcel_id=<?php echo $title['parcel_id']; ?>" 
                                                           class="btn btn-outline-secondary" target="_blank">
                                                            <i class="bi bi-clock-history"></i>
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

                    <!-- Freehold Title Information -->
                    <div class="row g-4 mt-4">
                        <div class="col-md-6">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h5 class="card-title mb-0 fw-bold">
                                        <i class="bi bi-info-circle text-info me-2"></i>
                                        About Freehold Titles
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <p><strong>Freehold</strong> is the highest form of land ownership where the owner has indefinite and absolute ownership of the land and any buildings on it.</p>
                                    
                                    <h6 class="fw-bold mt-3">Key Characteristics:</h6>
                                    <ul class="mb-0">
                                        <li>Perpetual ownership - no expiration date</li>
                                        <li>Owner has full rights to use, sell, lease, or transfer</li>
                                        <li>Can be inherited by descendants</li>
                                        <li>Subject to government regulations and taxes</li>
                                        <li>Highest value in real estate market</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h5 class="card-title mb-0 fw-bold">
                                        <i class="bi bi-graph-up text-success me-2"></i>
                                        Freehold Statistics
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-6 mb-3">
                                            <div class="border rounded p-3 text-center">
                                                <div class="text-muted small">Active Percentage</div>
                                                <h4 class="mb-0">
                                                    <?php 
                                                    $active_percent = ($summary['total_freehold'] ?? 0) > 0 
                                                        ? round(($summary['active_titles'] ?? 0) / ($summary['total_freehold'] ?? 1) * 100, 1) 
                                                        : 0;
                                                    echo $active_percent; ?>%
                                                </h4>
                                            </div>
                                        </div>
                                        <div class="col-6 mb-3">
                                            <div class="border rounded p-3 text-center">
                                                <div class="text-muted small">Avg Owners/Title</div>
                                                <h4 class="mb-0">
                                                    <?php 
                                                    $avg_owners = ($summary['total_freehold'] ?? 0) > 0 
                                                        ? round(($summary['total_owners'] ?? 0) / ($summary['total_freehold'] ?? 1), 1) 
                                                        : 0;
                                                    echo $avg_owners;
                                                    ?>
                                                </h4>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="progress mt-2" style="height: 25px;">
                                        <div class="progress-bar bg-success" role="progressbar" 
                                             style="width: <?php echo $active_percent; ?>%;" 
                                             aria-valuenow="<?php echo $summary['active_titles'] ?? 0; ?>" 
                                             aria-valuemin="0" 
                                             aria-valuemax="<?php echo $summary['total_freehold'] ?? 1; ?>">
                                            Active: <?php echo $summary['active_titles'] ?? 0; ?>
                                        </div>
                                        <div class="progress-bar bg-secondary" role="progressbar" 
                                             style="width: <?php echo 100 - $active_percent; ?>%;" 
                                             aria-valuenow="<?php echo ($summary['total_freehold'] ?? 0) - ($summary['active_titles'] ?? 0); ?>" 
                                             aria-valuemin="0" 
                                             aria-valuemax="<?php echo $summary['total_freehold'] ?? 1; ?>">
                                            Inactive: <?php echo ($summary['total_freehold'] ?? 0) - ($summary['active_titles'] ?? 0); ?>
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

    <!-- Add Freehold Title Modal -->
    <div class="modal fade" id="addFreeholdModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="add">
                    <div class="modal-header">
                        <h5 class="modal-title">Issue New Freehold Title</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Select Parcel <span class="text-danger">*</span></label>
                                <select class="form-select" name="parcel_id" required>
                                    <option value="">-- Select a parcel --</option>
                                    <?php foreach ($available_parcels as $parcel): ?>
                                    <option value="<?php echo $parcel['id']; ?>">
                                        <?php echo $parcel['parcel_number']; ?> - 
                                        <?php echo htmlspecialchars($parcel['boma_name']); ?>, 
                                        <?php echo htmlspecialchars($parcel['payam_name']); ?> 
                                        (<?php echo number_format($parcel['area'], 2); ?> m²)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">Only parcels without active freehold titles are shown</small>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Title Number <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="title_number" required 
                                       placeholder="e.g., FREEHOLD/2024/001">
                                <small class="text-muted">Must be unique</small>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Issue Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="issue_date" 
                                       value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Previous Title (if applicable)</label>
                                <select class="form-select" name="previous_title_id">
                                    <option value="">No previous title</option>
                                    <?php foreach ($previous_titles as $prev): ?>
                                    <option value="<?php echo $prev['id']; ?>">
                                        <?php echo $prev['title_number']; ?> (Parcel: <?php echo $prev['parcel_number']; ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-12">
                                <hr>
                                <h6 class="fw-bold">Owner Information</h6>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Select Owner <span class="text-danger">*</span></label>
                                <select class="form-select" name="owner_party_id" required>
                                    <option value="">-- Select an owner --</option>
                                    <?php foreach ($parties as $party): ?>
                                    <option value="<?php echo $party['id']; ?>">
                                        <?php echo htmlspecialchars($party['name']); ?> 
                                        (<?php echo $party['party_type']; ?>)
                                        <?php if ($party['national_id']): ?> - ID: <?php echo $party['national_id']; ?><?php endif; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Share Percentage</label>
                                <input type="number" class="form-control" name="share_percentage" 
                                       value="100" min="1" max="100" step="0.01">
                                <small class="text-muted">For joint ownership, add more owners after creation</small>
                            </div>
                            
                            <div class="col-12">
                                <hr>
                                <h6 class="fw-bold">Document Upload</h6>
                            </div>
                            
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Title Deed Document (PDF/Image)</label>
                                <input type="file" class="form-control" name="title_document" 
                                       accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                                <small class="text-muted">Upload the scanned title deed (optional)</small>
                            </div>
                            
                            <div class="col-12 mb-3">
                                <label class="form-label">Registration Notes</label>
                                <textarea class="form-control" name="registration_notes" rows="2"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Issue Freehold Title</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Freehold Title Modal -->
    <div class="modal fade" id="editFreeholdModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="title_id" id="editTitleId">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Freehold Title</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Title Number</label>
                                <input type="text" class="form-control" name="title_number" id="editTitleNumber" required>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Issue Date</label>
                                <input type="date" class="form-control" name="issue_date" id="editIssueDate" required>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status" id="editStatus" required>
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                    <option value="cancelled">Cancelled</option>
                                </select>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Previous Title</label>
                                <select class="form-select" name="previous_title_id" id="editPreviousTitle">
                                    <option value="">None</option>
                                    <?php foreach ($previous_titles as $prev): ?>
                                    <option value="<?php echo $prev['id']; ?>">
                                        <?php echo $prev['title_number']; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-12 mb-3">
                                <label class="form-label">Parcel Information</label>
                                <input type="text" class="form-control" id="editParcelInfo" readonly disabled>
                            </div>
                            
                            <div class="col-12 mb-3">
                                <label class="form-label">Current Owners</label>
                                <input type="text" class="form-control" id="editOwners" readonly disabled>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Title</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Freehold Title Modal -->
    <div class="modal fade" id="viewFreeholdModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Freehold Title Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Title Number:</label>
                            <p id="viewTitleNumber" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Status:</label>
                            <p id="viewStatus" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Issue Date:</label>
                            <p id="viewIssueDate" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Previous Title:</label>
                            <p id="viewPreviousTitle" class="mb-0"></p>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="fw-bold">Parcel:</label>
                            <p id="viewParcel" class="mb-0"></p>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="fw-bold">Location:</label>
                            <p id="viewLocation" class="mb-0"></p>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="fw-bold">Owners:</label>
                            <p id="viewOwners" class="mb-0"></p>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="fw-bold">Zoning:</label>
                            <p id="viewZoning" class="mb-0"></p>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="fw-bold">Area:</label>
                            <p id="viewArea" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Documents:</label>
                            <p id="viewDocuments" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Transactions:</label>
                            <p id="viewTransactions" class="mb-0"></p>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="fw-bold">Created:</label>
                            <p id="viewCreated" class="mb-0"></p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <a href="#" id="viewHistoryLink" class="btn btn-outline-primary" target="_blank">
                        <i class="bi bi-clock-history"></i> View Full History
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Edit freehold function
        function editFreehold(title) {
            document.getElementById('editTitleId').value = title.id;
            document.getElementById('editTitleNumber').value = title.title_number || '';
            document.getElementById('editIssueDate').value = title.issue_date || '';
            document.getElementById('editStatus').value = title.status || 'active';
            document.getElementById('editPreviousTitle').value = title.previous_title_id || '';
            
            document.getElementById('editParcelInfo').value = 
                title.parcel_number + ' - Area: ' + title.parcel_area + ' m²';
            
            document.getElementById('editOwners').value = title.owners || 'No owners assigned';
        }
        
        // View freehold function
        function viewFreehold(title) {
            document.getElementById('viewTitleNumber').textContent = title.title_number || 'N/A';
            
            let statusBadge = '';
            if (title.status === 'active') statusBadge = '<span class="badge bg-success">Active</span>';
            else if (title.status === 'inactive') statusBadge = '<span class="badge bg-secondary">Inactive</span>';
            else if (title.status === 'cancelled') statusBadge = '<span class="badge bg-danger">Cancelled</span>';
            else statusBadge = title.status || 'N/A';
            
            document.getElementById('viewStatus').innerHTML = statusBadge;
            document.getElementById('viewIssueDate').textContent = title.issue_date ? new Date(title.issue_date).toLocaleDateString() : 'N/A';
            document.getElementById('viewPreviousTitle').textContent = title.previous_title_number || 'None';
            document.getElementById('viewParcel').textContent = title.parcel_number || 'N/A';
            document.getElementById('viewLocation').textContent = 
                title.boma_name + ', ' + title.payam_name + ', ' + title.county_name + ', ' + title.state_name;
            document.getElementById('viewOwners').textContent = title.owners || 'No owners recorded';
            document.getElementById('viewZoning').textContent = title.zone_code ? title.zone_code + ' - ' + title.zone_name : 'Not zoned';
            document.getElementById('viewArea').textContent = title.parcel_area ? title.parcel_area + ' m²' : 'N/A';
            document.getElementById('viewDocuments').textContent = title.document_count || 0;
            document.getElementById('viewTransactions').textContent = title.transaction_count || 0;
            document.getElementById('viewCreated').textContent = title.created_at ? new Date(title.created_at).toLocaleString() : 'N/A';
            
            document.getElementById('viewHistoryLink').href = 'parcels-history.php?parcel_id=' + title.parcel_id;
        }
        
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
        
        select:disabled {
            background-color: #e9ecef;
            cursor: not-allowed;
        }
        
        .progress {
            border-radius: 4px;
        }
        
        .progress-bar {
            font-size: 0.8rem;
            line-height: 25px;
        }
    </style>

</body>
</html>