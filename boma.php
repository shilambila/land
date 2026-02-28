<?php
ini_set('display_errors', 1); 
error_reporting(E_ALL);

// bomas.php
include("head.php");
require_once 'db_connection.php';

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
// CHECK AND ADD MISSING COLUMNS
// ============================================================================

function checkAndAddColumns($conn) {
    $fixes = [];
    
    // Check if columns exist in bomas table
    $columns = $conn->query("SHOW COLUMNS FROM bomas")->fetchAll(PDO::FETCH_COLUMN);
    
    if (!in_array('area_sqkm', $columns)) {
        $conn->exec("ALTER TABLE bomas ADD COLUMN area_sqkm DECIMAL(15,4) DEFAULT NULL AFTER code");
        $fixes[] = "Added area_sqkm column";
    }
    
    if (!in_array('population', $columns)) {
        $conn->exec("ALTER TABLE bomas ADD COLUMN population BIGINT DEFAULT NULL AFTER area_sqkm");
        $fixes[] = "Added population column";
    }
    
    if (!in_array('established_date', $columns)) {
        $conn->exec("ALTER TABLE bomas ADD COLUMN established_date DATE DEFAULT NULL AFTER population");
        $fixes[] = "Added established_date column";
    }
    
    if (!in_array('description', $columns)) {
        $conn->exec("ALTER TABLE bomas ADD COLUMN description TEXT DEFAULT NULL AFTER name");
        $fixes[] = "Added description column";
    }
    
    if (!in_array('center_lat', $columns)) {
        $conn->exec("ALTER TABLE bomas ADD COLUMN center_lat DECIMAL(10,8) DEFAULT NULL");
        $fixes[] = "Added center_lat column";
    }
    
    if (!in_array('center_lng', $columns)) {
        $conn->exec("ALTER TABLE bomas ADD COLUMN center_lng DECIMAL(11,8) DEFAULT NULL");
        $fixes[] = "Added center_lng column";
    }
    
    return $fixes;
}

// Run column check
$column_fixes = checkAndAddColumns($conn);

// ============================================================================
// HANDLE ACTIONS
// ============================================================================

$message = '';
$messageType = '';

// Display column fix messages
if (!empty($column_fixes)) {
    $message = "Database updated: " . implode(", ", $column_fixes);
    $messageType = "info";
}

// Delete boma
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $bomaId = $_GET['delete'];
    
    try {
        // Check if boma has related records (parcels)
        $related = fetchOne($conn, "
            SELECT 
                (SELECT COUNT(*) FROM parcels WHERE boma_id = ?) as parcels
        ", [$bomaId]);
        
        if ($related['parcels'] > 0) {
            $message = "Cannot delete boma with existing parcels. Remove or reassign parcels first.";
            $messageType = "warning";
        } else {
            executeQuery($conn, "DELETE FROM bomas WHERE id = ?", [$bomaId]);
            $message = "Boma deleted successfully";
            $messageType = "success";
        }
    } catch (Exception $e) {
        $message = "Error deleting boma: " . $e->getMessage();
        $messageType = "danger";
    }
}

// Add/Edit boma
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        
        // Add new boma
        if ($_POST['action'] === 'add') {
            $name = $_POST['name'];
            $payam_id = $_POST['payam_id'];
            $code = !empty($_POST['code']) ? strtoupper($_POST['code']) : null;
            $description = $_POST['description'] ?? null;
            $center_lat = !empty($_POST['center_lat']) ? $_POST['center_lat'] : null;
            $center_lng = !empty($_POST['center_lng']) ? $_POST['center_lng'] : null;
            $area_sqkm = !empty($_POST['area_sqkm']) ? $_POST['area_sqkm'] : null;
            $population = !empty($_POST['population']) ? $_POST['population'] : null;
            $established_date = !empty($_POST['established_date']) ? $_POST['established_date'] : null;
            
            try {
                // Check for duplicate code within the same payam
                if ($code) {
                    $exists = fetchOne($conn, "SELECT id FROM bomas WHERE code = ? AND payam_id = ?", [$code, $payam_id]);
                    if ($exists) {
                        throw new Exception("Boma code already exists in this payam");
                    }
                }
                
                executeQuery($conn, "
                    INSERT INTO bomas (
                        name, payam_id, code, description, center_lat, center_lng, 
                        area_sqkm, population, established_date, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ", [$name, $payam_id, $code, $description, $center_lat, $center_lng, 
                    $area_sqkm, $population, $established_date]);
                
                $message = "Boma added successfully";
                $messageType = "success";
            } catch (Exception $e) {
                $message = "Error adding boma: " . $e->getMessage();
                $messageType = "danger";
            }
        }
        
        // Edit boma
        if ($_POST['action'] === 'edit') {
            $boma_id = $_POST['boma_id'];
            $name = $_POST['name'];
            $payam_id = $_POST['payam_id'];
            $code = !empty($_POST['code']) ? strtoupper($_POST['code']) : null;
            $description = $_POST['description'] ?? null;
            $center_lat = !empty($_POST['center_lat']) ? $_POST['center_lat'] : null;
            $center_lng = !empty($_POST['center_lng']) ? $_POST['center_lng'] : null;
            $area_sqkm = !empty($_POST['area_sqkm']) ? $_POST['area_sqkm'] : null;
            $population = !empty($_POST['population']) ? $_POST['population'] : null;
            $established_date = !empty($_POST['established_date']) ? $_POST['established_date'] : null;
            
            try {
                // Check for duplicate code within the same payam (excluding current)
                if ($code) {
                    $exists = fetchOne($conn, "SELECT id FROM bomas WHERE code = ? AND payam_id = ? AND id != ?", [$code, $payam_id, $boma_id]);
                    if ($exists) {
                        throw new Exception("Boma code already exists in this payam");
                    }
                }
                
                executeQuery($conn, "
                    UPDATE bomas SET 
                        name = ?, payam_id = ?, code = ?, description = ?,
                        center_lat = ?, center_lng = ?, area_sqkm = ?, 
                        population = ?, established_date = ?
                    WHERE id = ?
                ", [$name, $payam_id, $code, $description, $center_lat, $center_lng, 
                    $area_sqkm, $population, $established_date, $boma_id]);
                
                $message = "Boma updated successfully";
                $messageType = "success";
            } catch (Exception $e) {
                $message = "Error updating boma: " . $e->getMessage();
                $messageType = "danger";
            }
        }
    }
}

// ============================================================================
// GET HIERARCHICAL DATA
// ============================================================================

// Get all states for filter
$states = safeFetchAll($conn, "SELECT id, name FROM states ORDER BY name", [], []);

// Get counties based on selected state
$selected_state = $_GET['state_id'] ?? null;
$selected_county = $_GET['county_id'] ?? null;
$selected_payam = $_GET['payam_id'] ?? null;

$counties = [];
if ($selected_state) {
    $counties = safeFetchAll($conn, "SELECT id, name FROM counties WHERE state_id = ? ORDER BY name", [$selected_state], []);
}

$payams = [];
if ($selected_county) {
    $payams = safeFetchAll($conn, "SELECT id, name FROM payams WHERE county_id = ? ORDER BY name", [$selected_county], []);
}

// ============================================================================
// FILTERS AND PAGINATION
// ============================================================================

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Build filter conditions
$where_conditions = [];
$params = [];

// State filter
if (!empty($_GET['state_id'])) {
    $where_conditions[] = "c.state_id = ?";
    $params[] = $_GET['state_id'];
}

// County filter
if (!empty($_GET['county_id'])) {
    $where_conditions[] = "pa.county_id = ?";
    $params[] = $_GET['county_id'];
}

// Payam filter
if (!empty($_GET['payam_id'])) {
    $where_conditions[] = "b.payam_id = ?";
    $params[] = $_GET['payam_id'];
}

// Search
if (!empty($_GET['search'])) {
    $search = '%' . $_GET['search'] . '%';
    $where_conditions[] = "(b.name LIKE ? OR b.code LIKE ? OR b.description LIKE ?)";
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
}

$where_clause = empty($where_conditions) ? "" : "WHERE " . implode(" AND ", $where_conditions);

// Get total count for pagination
$count_sql = "
    SELECT COUNT(*) as total 
    FROM bomas b
    JOIN payams pa ON b.payam_id = pa.id
    JOIN counties c ON pa.county_id = c.id
    $where_clause
";
$total_result = executeQuery($conn, $count_sql, $params);
$total_rows = $total_result->fetch()['total'];
$total_pages = ceil($total_rows / $limit);

// Get bomas with hierarchical information and statistics
$sql = "
    SELECT 
        b.*,
        pa.name as payam_name,
        pa.id as payam_id,
        c.name as county_name,
        c.id as county_id,
        s.name as state_name,
        s.id as state_id,
        (SELECT COUNT(*) FROM parcels WHERE boma_id = b.id) as total_parcels,
        (SELECT COUNT(*) FROM titles t
         JOIN parcels p ON t.parcel_id = p.id
         WHERE p.boma_id = b.id) as total_titles,
        (SELECT COALESCE(SUM(area), 0) FROM parcels WHERE boma_id = b.id) as total_area,
        (SELECT COUNT(*) FROM applications a
         JOIN parcels p ON a.parcel_id = p.id
         WHERE p.boma_id = b.id) as total_applications
    FROM bomas b
    JOIN payams pa ON b.payam_id = pa.id
    JOIN counties c ON pa.county_id = c.id
    JOIN states s ON c.state_id = s.id
    $where_clause
    ORDER BY s.name, c.name, pa.name, b.name
    LIMIT ? OFFSET ?
";

$params[] = $limit;
$params[] = $offset;

$bomas = safeFetchAll($conn, $sql, $params, []);

// Get summary statistics
$summary = safeFetchOne($conn, "
    SELECT 
        COUNT(*) as total_bomas,
        (SELECT COUNT(*) FROM parcels) as total_parcels,
        (SELECT COUNT(*) FROM payams) as total_payams,
        (SELECT COUNT(*) FROM counties) as total_counties,
        (SELECT COUNT(*) FROM states) as total_states,
        (SELECT COALESCE(SUM(area), 0) FROM parcels) as total_land_area,
        (SELECT COUNT(*) FROM bomas WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) as new_bomas_30d
    FROM bomas
", [], ['total_bomas' => 0, 'total_parcels' => 0, 'total_payams' => 0, 'total_counties' => 0, 'total_states' => 0, 'total_land_area' => 0, 'new_bomas_30d' => 0]);

// Get recent bomas
$recent_bomas = safeFetchAll($conn, "
    SELECT 
        b.name, b.code, b.created_at,
        pa.name as payam_name,
        c.name as county_name
    FROM bomas b
    JOIN payams pa ON b.payam_id = pa.id
    JOIN counties c ON pa.county_id = c.id
    ORDER BY b.created_at DESC
    LIMIT 5
", [], []);

// Get bomas with most parcels
$top_bomas = safeFetchAll($conn, "
    SELECT 
        b.name,
        b.code,
        pa.name as payam_name,
        COUNT(p.id) as parcel_count,
        COALESCE(SUM(p.area), 0) as total_area
    FROM bomas b
    LEFT JOIN parcels p ON b.id = p.boma_id
    JOIN payams pa ON b.payam_id = pa.id
    GROUP BY b.id
    ORDER BY parcel_count DESC
    LIMIT 5
", [], []);

// Get all bomas with coordinates for map
$bomas_for_map = safeFetchAll($conn, "
    SELECT 
        b.id,
        b.name,
        b.code,
        b.center_lat,
        b.center_lng,
        b.description,
        pa.name as payam_name,
        c.name as county_name,
        s.name as state_name,
        (SELECT COUNT(*) FROM parcels WHERE boma_id = b.id) as parcel_count
    FROM bomas b
    JOIN payams pa ON b.payam_id = pa.id
    JOIN counties c ON pa.county_id = c.id
    JOIN states s ON c.state_id = s.id
    WHERE b.center_lat IS NOT NULL AND b.center_lng IS NOT NULL
    ORDER BY b.name
", [], []);
?>

<!-- Leaflet CSS and JS (Free OpenStreetMap alternative) -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<body data-page="bomas" class="bomas-page">
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
                            <h1 class="h3 mb-0">Bomas Management</h1>
                            <p class="text-muted mb-0">Manage the smallest administrative units (Bomas) in South Sudan</p>
                        </div>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addBomaModal">
                            <i class="bi bi-plus-circle me-2"></i>Add New Boma
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
                                                <i class="bi bi-pin-map text-primary fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Total Bomas</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['total_bomas'] ?? 0); ?></h3>
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
                                                <i class="bi bi-building text-success fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Payams</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['total_payams'] ?? 0); ?></h3>
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
                                                <i class="bi bi-grid text-info fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Counties</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['total_counties'] ?? 0); ?></h3>
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
                                                <i class="bi bi-map text-warning fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">States</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['total_states'] ?? 0); ?></h3>
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
                                                <i class="bi bi-grid-3x3 text-danger fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Parcels</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['total_parcels'] ?? 0); ?></h3>
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
                                                <i class="bi bi-arrows-angle-expand text-secondary fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">New (30 days)</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['new_bomas_30d'] ?? 0); ?></h3>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Map View (Using OpenStreetMap) -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0 fw-bold">
                                <i class="bi bi-map me-2 text-primary"></i>
                                Bomas Map - South Sudan (OpenStreetMap)
                            </h5>
                            <div class="btn-group btn-group-sm">
                                <button class="btn btn-outline-primary" onclick="centerMapOnSouthSudan()">
                                    <i class="bi bi-arrows-fullscreen"></i> Reset View
                                </button>
                                <button class="btn btn-outline-success" onclick="refreshMapMarkers()">
                                    <i class="bi bi-arrow-repeat"></i> Refresh
                                </button>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <div id="bomas-map" style="height: 400px; width: 100%;"></div>
                        </div>
                        <div class="card-footer bg-white py-2">
                            <small class="text-muted">
                                <i class="bi bi-info-circle me-1"></i>
                                Showing <?php echo count($bomas_for_map); ?> bomas with coordinates. 
                                Map data © OpenStreetMap contributors.
                            </small>
                        </div>
                    </div>

                    <!-- Filters -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-body">
                            <form method="GET" class="row g-3">
                                <div class="col-md-3">
                                    <label class="form-label">State</label>
                                    <select class="form-select" name="state_id" id="stateFilter" onchange="this.form.submit()">
                                        <option value="">All States</option>
                                        <?php foreach ($states as $state): ?>
                                        <option value="<?php echo $state['id']; ?>" 
                                            <?php echo ($selected_state == $state['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($state['name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-3">
                                    <label class="form-label">County</label>
                                    <select class="form-select" name="county_id" id="countyFilter" onchange="this.form.submit()" 
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
                                    <label class="form-label">Payam</label>
                                    <select class="form-select" name="payam_id" id="payamFilter" onchange="this.form.submit()"
                                            <?php echo empty($payams) ? 'disabled' : ''; ?>>
                                        <option value="">All Payams</option>
                                        <?php foreach ($payams as $payam): ?>
                                        <option value="<?php echo $payam['id']; ?>" 
                                            <?php echo ($selected_payam == $payam['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($payam['name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-3">
                                    <label class="form-label">Search</label>
                                    <input type="text" class="form-control" name="search" 
                                           placeholder="Boma name, code..."
                                           value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                                </div>
                                
                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-search me-2"></i>Apply Filters
                                    </button>
                                    <a href="bomas.php" class="btn btn-secondary">
                                        <i class="bi bi-x-circle me-2"></i>Clear Filters
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Bomas Table -->
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0 fw-bold">Bomas List</h5>
                            <span class="badge bg-primary"><?php echo number_format($total_rows); ?> Records</span>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>ID</th>
                                            <th>Boma Name</th>
                                            <th>Code</th>
                                            <th>Location</th>
                                            <th>Statistics</th>
                                            <th>Area/Population</th>
                                            <th>Established</th>
                                            <th>Map</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($bomas)): ?>
                                        <tr>
                                            <td colspan="9" class="text-center py-4 text-muted">
                                                <i class="bi bi-pin-map fs-1 d-block mb-3"></i>
                                                No bomas found. Try adjusting your filters or add a new boma.
                                            </td>
                                        </tr>
                                        <?php else: ?>
                                            <?php foreach ($bomas as $boma): ?>
                                            <tr>
                                                <td>
                                                    <span class="fw-medium">#<?php echo $boma['id']; ?></span>
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="boma-avatar bg-primary bg-opacity-10 me-2">
                                                            <i class="bi bi-pin-map-fill text-primary"></i>
                                                        </div>
                                                        <div>
                                                            <span class="fw-medium"><?php echo htmlspecialchars($boma['name'] ?? ''); ?></span>
                                                            <?php if (!empty($boma['description'])): ?>
                                                                <br><small class="text-muted"><?php echo substr(htmlspecialchars($boma['description']), 0, 50); ?>...</small>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php if (!empty($boma['code'])): ?>
                                                        <span class="badge bg-secondary"><?php echo $boma['code']; ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <small>
                                                        <i class="bi bi-building text-success"></i> <?php echo htmlspecialchars($boma['state_name'] ?? ''); ?><br>
                                                        <i class="bi bi-house text-info ms-3"></i> <?php echo htmlspecialchars($boma['county_name'] ?? ''); ?><br>
                                                        <i class="bi bi-pin text-warning ms-3"></i> <?php echo htmlspecialchars($boma['payam_name'] ?? ''); ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <div class="d-flex flex-wrap gap-1">
                                                        <span class="badge bg-primary" title="Parcels">
                                                            <i class="bi bi-grid"></i> <?php echo $boma['total_parcels'] ?? 0; ?>
                                                        </span>
                                                        <span class="badge bg-success" title="Titles">
                                                            <i class="bi bi-file-text"></i> <?php echo $boma['total_titles'] ?? 0; ?>
                                                        </span>
                                                        <span class="badge bg-info" title="Applications">
                                                            <i class="bi bi-file-earmark"></i> <?php echo $boma['total_applications'] ?? 0; ?>
                                                        </span>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php if (!empty($boma['area_sqkm'])): ?>
                                                        <small><i class="bi bi-arrows-angle-expand"></i> <?php echo number_format($boma['area_sqkm'], 2); ?> km²</small><br>
                                                    <?php endif; ?>
                                                    <?php if (!empty($boma['population'])): ?>
                                                        <small><i class="bi bi-people"></i> <?php echo number_format($boma['population']); ?></small>
                                                    <?php endif; ?>
                                                    <?php if (empty($boma['area_sqkm']) && empty($boma['population'])): ?>
                                                        <span class="text-muted">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (!empty($boma['established_date'])): ?>
                                                        <small><?php echo date('d/m/Y', strtotime($boma['established_date'])); ?></small>
                                                    <?php elseif (!empty($boma['created_at'])): ?>
                                                        <small class="text-muted"><?php echo date('d/m/Y', strtotime($boma['created_at'])); ?></small>
                                                    <?php else: ?>
                                                        <span class="text-muted">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (!empty($boma['center_lat']) && !empty($boma['center_lng'])): ?>
                                                        <button class="btn btn-sm btn-outline-info" 
                                                                onclick="focusMapOnBoma(<?php echo $boma['center_lat']; ?>, <?php echo $boma['center_lng']; ?>, '<?php echo htmlspecialchars(addslashes($boma['name'])); ?>')">
                                                            <i class="bi bi-geo-alt"></i>
                                                        </button>
                                                    <?php else: ?>
                                                        <span class="text-muted">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <button class="btn btn-outline-primary" 
                                                                onclick="editBoma(<?php echo htmlspecialchars(json_encode($boma)); ?>)"
                                                                data-bs-toggle="modal" data-bs-target="#editBomaModal">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                        <a href="?delete=<?php echo $boma['id']; ?>" 
                                                           class="btn btn-outline-danger"
                                                           onclick="return confirm('Delete this boma? This will affect all related parcels.')">
                                                            <i class="bi bi-trash"></i>
                                                        </a>
                                                        <button class="btn btn-outline-info" 
                                                                onclick="viewBoma(<?php echo htmlspecialchars(json_encode($boma)); ?>)"
                                                                data-bs-toggle="modal" data-bs-target="#viewBomaModal">
                                                            <i class="bi bi-eye"></i>
                                                        </button>
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

                    <!-- Top Bomas and Recent Bomas -->
                    <div class="row g-4 mt-4">
                        <!-- Top Bomas by Parcels -->
                        <div class="col-lg-6">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h5 class="card-title mb-0 fw-bold">
                                        <i class="bi bi-trophy text-warning me-2"></i>
                                        Top Bomas by Parcel Count
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($top_bomas)): ?>
                                        <p class="text-muted text-center">No data available</p>
                                    <?php else: ?>
                                        <?php foreach ($top_bomas as $index => $boma): ?>
                                        <div class="d-flex justify-content-between align-items-center mb-3">
                                            <div class="d-flex align-items-center">
                                                <span class="badge bg-<?php 
                                                    echo $index == 0 ? 'warning' : ($index == 1 ? 'secondary' : ($index == 2 ? 'danger' : 'light')); 
                                                ?> me-3">#<?php echo $index + 1; ?></span>
                                                <div>
                                                    <span class="fw-medium"><?php echo htmlspecialchars($boma['name'] ?? ''); ?></span>
                                                    <br>
                                                    <small class="text-muted">
                                                        <?php echo htmlspecialchars($boma['payam_name'] ?? ''); ?> • 
                                                        Code: <?php echo $boma['code'] ?? 'N/A'; ?>
                                                    </small>
                                                </div>
                                            </div>
                                            <div class="text-end">
                                                <span class="fw-bold"><?php echo $boma['parcel_count'] ?? 0; ?></span>
                                                <br>
                                                <small class="text-muted"><?php echo number_format($boma['total_area'] ?? 0, 2); ?> m²</small>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Recent Bomas -->
                        <div class="col-lg-6">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h5 class="card-title mb-0 fw-bold">
                                        <i class="bi bi-clock-history text-info me-2"></i>
                                        Recently Added Bomas
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($recent_bomas)): ?>
                                        <p class="text-muted text-center">No recent bomas</p>
                                    <?php else: ?>
                                        <?php foreach ($recent_bomas as $recent): ?>
                                        <div class="d-flex justify-content-between align-items-center mb-3">
                                            <div>
                                                <span class="fw-medium"><?php echo htmlspecialchars($recent['name'] ?? ''); ?></span>
                                                <br>
                                                <small class="text-muted">
                                                    <i class="bi bi-building"></i> <?php echo htmlspecialchars($recent['county_name'] ?? ''); ?> • 
                                                    <i class="bi bi-pin"></i> <?php echo htmlspecialchars($recent['payam_name'] ?? ''); ?>
                                                </small>
                                            </div>
                                            <div class="text-end">
                                                <small class="text-muted">
                                                    <?php echo !empty($recent['created_at']) ? date('d M Y', strtotime($recent['created_at'])) : 'N/A'; ?>
                                                </small>
                                                <?php if (!empty($recent['code'])): ?>
                                                    <br><span class="badge bg-light text-dark"><?php echo $recent['code']; ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
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

    <!-- Add Boma Modal -->
    <div class="modal fade" id="addBomaModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="add">
                    <div class="modal-header">
                        <h5 class="modal-title">Add New Boma</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">State</label>
                                <select class="form-select" id="addState" required onchange="loadCounties(this.value, 'addCounty')">
                                    <option value="">Select State</option>
                                    <?php foreach ($states as $state): ?>
                                    <option value="<?php echo $state['id']; ?>"><?php echo htmlspecialchars($state['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">County</label>
                                <select class="form-select" id="addCounty" name="county_id" required onchange="loadPayams(this.value, 'addPayam')">
                                    <option value="">Select County</option>
                                </select>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Payam</label>
                                <select class="form-select" id="addPayam" name="payam_id" required>
                                    <option value="">Select Payam</option>
                                </select>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Boma Name</label>
                                <input type="text" class="form-control" name="name" required 
                                       placeholder="e.g., Hai Malakal">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Boma Code</label>
                                <input type="text" class="form-control" name="code" 
                                       placeholder="e.g., CE-JU-KT-HM">
                                <small class="text-muted">Unique code (optional)</small>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Established Date</label>
                                <input type="date" class="form-control" name="established_date">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Area (km²)</label>
                                <input type="number" class="form-control" name="area_sqkm" step="0.01" 
                                       placeholder="e.g., 15.5">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Population</label>
                                <input type="number" class="form-control" name="population" 
                                       placeholder="e.g., 5000">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Center Latitude</label>
                                <input type="number" class="form-control" name="center_lat" id="add_lat" step="0.0001" 
                                       placeholder="e.g., 4.8500">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Center Longitude</label>
                                <input type="number" class="form-control" name="center_lng" id="add_lng" step="0.0001" 
                                       placeholder="e.g., 31.6000">
                            </div>
                            
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Pick from Map</label>
                                <button type="button" class="btn btn-outline-primary w-100" onclick="openMapPicker('add')">
                                    <i class="bi bi-pin-map me-2"></i>Select Location on Map
                                </button>
                            </div>
                            
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Description</label>
                                <textarea class="form-control" name="description" rows="2" 
                                          placeholder="Brief description of the boma..."></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Boma</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Boma Modal -->
    <div class="modal fade" id="editBomaModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="boma_id" id="editBomaId">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Boma</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">State</label>
                                <input type="text" class="form-control" id="editStateName" readonly disabled>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">County</label>
                                <input type="text" class="form-control" id="editCountyName" readonly disabled>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Payam</label>
                                <select class="form-select" name="payam_id" id="editPayam" required>
                                    <option value="">Select Payam</option>
                                </select>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Boma Name</label>
                                <input type="text" class="form-control" name="name" id="editName" required>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Boma Code</label>
                                <input type="text" class="form-control" name="code" id="editCode">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Established Date</label>
                                <input type="date" class="form-control" name="established_date" id="editEstablished">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Area (km²)</label>
                                <input type="number" class="form-control" name="area_sqkm" id="editArea" step="0.01">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Population</label>
                                <input type="number" class="form-control" name="population" id="editPopulation">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Center Latitude</label>
                                <input type="number" class="form-control" name="center_lat" id="edit_lat" step="0.0001">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Center Longitude</label>
                                <input type="number" class="form-control" name="center_lng" id="edit_lng" step="0.0001">
                            </div>
                            
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Update Location</label>
                                <button type="button" class="btn btn-outline-primary w-100" onclick="openMapPicker('edit')">
                                    <i class="bi bi-pin-map me-2"></i>Pick New Location
                                </button>
                            </div>
                            
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Description</label>
                                <textarea class="form-control" name="description" id="editDescription" rows="2"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Boma</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Boma Modal -->
    <div class="modal fade" id="viewBomaModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Boma Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Boma Name:</label>
                            <p id="viewName" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Boma Code:</label>
                            <p id="viewCode" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">State:</label>
                            <p id="viewState" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">County:</label>
                            <p id="viewCounty" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Payam:</label>
                            <p id="viewPayam" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Established:</label>
                            <p id="viewEstablished" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Area:</label>
                            <p id="viewArea" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Population:</label>
                            <p id="viewPopulation" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Coordinates:</label>
                            <p id="viewCoordinates" class="mb-0"></p>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="fw-bold">Description:</label>
                            <p id="viewDescription" class="mb-0"></p>
                        </div>
                        
                        <hr>
                        
                        <div class="col-md-3 col-6 mb-2">
                            <div class="border rounded p-2 text-center">
                                <span class="badge bg-primary" id="viewParcels">0</span>
                                <small class="d-block">Parcels</small>
                            </div>
                        </div>
                        <div class="col-md-3 col-6 mb-2">
                            <div class="border rounded p-2 text-center">
                                <span class="badge bg-success" id="viewTitles">0</span>
                                <small class="d-block">Titles</small>
                            </div>
                        </div>
                        <div class="col-md-3 col-6 mb-2">
                            <div class="border rounded p-2 text-center">
                                <span class="badge bg-info" id="viewApplications">0</span>
                                <small class="d-block">Applications</small>
                            </div>
                        </div>
                        <div class="col-md-3 col-6 mb-2">
                            <div class="border rounded p-2 text-center">
                                <span class="badge bg-warning" id="viewTotalArea">0</span>
                                <small class="d-block">Total Area</small>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Mini Map Preview -->
                    <div class="mt-3" id="view_map_container" style="display: none;">
                        <label class="fw-bold">Location Map:</label>
                        <div id="view-map" style="height: 200px; width: 100%;" class="border rounded"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Map Picker Modal (OpenStreetMap) -->
    <div class="modal fade" id="mapPickerModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Select Boma Location on Map</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="picker-map" style="height: 500px; width: 100%;"></div>
                    <div class="row mt-3">
                        <div class="col-md-6">
                            <label class="fw-bold">Latitude:</label>
                            <input type="text" class="form-control" id="picker_latitude" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="fw-bold">Longitude:</label>
                            <input type="text" class="form-control" id="picker_longitude" readonly>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="savePickedLocation()">Use This Location</button>
                </div>
            </div>
        </div>
    </div>

    <!-- AJAX loading functions and Map Scripts -->
    <script>
        // Global variables
        let map;
        let pickerMap;
        let markers = [];
        let currentPickerMode = 'add';
        let pickerMarker;
        
        // South Sudan bounds (approximate)
        const SOUTH_SUDAN_BOUNDS = {
            north: 12.0,
            south: 3.5,
            west: 23.5,
            east: 36.0
        };
        
        // Center of South Sudan (approximate)
        const SOUTH_SUDAN_CENTER = [7.5, 30.0];

        // Initialize main map with OpenStreetMap
        function initMap() {
            // Create main map
            map = L.map('bomas-map').setView(SOUTH_SUDAN_CENTER, 6);
            
            // Add OpenStreetMap tiles
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
                maxZoom: 19
            }).addTo(map);

            // Load boma markers
            loadBomaMarkers();
        }

        // Load boma markers from database
        function loadBomaMarkers() {
            <?php foreach ($bomas_for_map as $boma): ?>
                <?php if (!empty($boma['center_lat']) && !empty($boma['center_lng'])): ?>
                    addBomaMarker(
                        <?php echo $boma['center_lat']; ?>,
                        <?php echo $boma['center_lng']; ?>,
                        '<?php echo htmlspecialchars(addslashes($boma['name'])); ?>',
                        '<?php echo htmlspecialchars(addslashes($boma['payam_name'])); ?>',
                        '<?php echo htmlspecialchars(addslashes($boma['county_name'])); ?>',
                        '<?php echo htmlspecialchars(addslashes($boma['state_name'])); ?>',
                        <?php echo $boma['parcel_count']; ?>,
                        <?php echo $boma['id']; ?>
                    );
                <?php endif; ?>
            <?php endforeach; ?>
        }

        // Add a marker for a boma
        function addBomaMarker(lat, lng, name, payam, county, state, parcels, id) {
            // Create custom icon
            const customIcon = L.divIcon({
                className: 'custom-marker',
                html: '<div class="marker-pin"></div><i class="bi bi-pin-map-fill"></i>',
                iconSize: [30, 30],
                popupAnchor: [0, -15]
            });

            const marker = L.marker([parseFloat(lat), parseFloat(lng)], { icon: customIcon }).addTo(map);
            
            // Create popup content
            const popupContent = `
                <div style="padding: 8px; max-width: 250px;">
                    <h6 style="margin: 0 0 5px 0; font-weight: bold;">${name}</h6>
                    <p style="margin: 0 0 3px 0; color: #666;">${payam}, ${county}</p>
                    <p style="margin: 0 0 3px 0; color: #666;">${state}</p>
                    <hr style="margin: 5px 0;">
                    <div>
                        <span><strong>Parcels:</strong> ${parcels}</span>
                    </div>
                    <div style="margin-top: 8px;">
                        <a href="bomas.php?payam_id=${id}" class="btn btn-sm btn-primary">View Details</a>
                    </div>
                </div>
            `;
            
            marker.bindPopup(popupContent);
            markers.push(marker);
        }

        // Refresh map markers
        function refreshMapMarkers() {
            // Clear existing markers
            markers.forEach(marker => map.removeLayer(marker));
            markers = [];
            
            // Reload markers
            loadBomaMarkers();
        }

        // Center map on South Sudan
        function centerMapOnSouthSudan() {
            map.setView(SOUTH_SUDAN_CENTER, 6);
        }

        // Focus map on a specific boma
        function focusMapOnBoma(lat, lng, name) {
            map.setView([parseFloat(lat), parseFloat(lng)], 14);
            
            // Find and open popup for this boma
            markers.forEach(marker => {
                const pos = marker.getLatLng();
                if (pos.lat === parseFloat(lat) && pos.lng === parseFloat(lng)) {
                    marker.openPopup();
                }
            });
        }

        // Open map picker modal
        function openMapPicker(mode) {
            currentPickerMode = mode;
            
            // Show the modal
            const modal = new bootstrap.Modal(document.getElementById('mapPickerModal'));
            modal.show();
            
            // Initialize picker map after modal is shown
            setTimeout(() => {
                if (pickerMap) {
                    pickerMap.remove();
                }
                
                pickerMap = L.map('picker-map').setView(SOUTH_SUDAN_CENTER, 6);
                
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
                    maxZoom: 19
                }).addTo(pickerMap);

                // Add click listener to pick location
                pickerMap.on('click', (e) => {
                    const lat = e.latlng.lat;
                    const lng = e.latlng.lng;
                    
                    // Remove existing marker
                    if (pickerMarker) {
                        pickerMap.removeLayer(pickerMarker);
                    }

                    // Add new marker
                    pickerMarker = L.marker([lat, lng], {
                        draggable: true
                    }).addTo(pickerMap);

                    // Update input fields
                    document.getElementById('picker_latitude').value = lat.toFixed(6);
                    document.getElementById('picker_longitude').value = lng.toFixed(6);

                    // Make marker draggable
                    pickerMarker.on('dragend', (event) => {
                        const position = event.target.getLatLng();
                        document.getElementById('picker_latitude').value = position.lat.toFixed(6);
                        document.getElementById('picker_longitude').value = position.lng.toFixed(6);
                    });
                });

                // If editing, show existing location
                if (mode === 'edit') {
                    const lat = document.getElementById('edit_lat').value;
                    const lng = document.getElementById('edit_lng').value;
                    
                    if (lat && lng) {
                        const position = [parseFloat(lat), parseFloat(lng)];
                        pickerMap.setView(position, 14);
                        
                        pickerMarker = L.marker(position, {
                            draggable: true
                        }).addTo(pickerMap);
                        
                        document.getElementById('picker_latitude').value = lat;
                        document.getElementById('picker_longitude').value = lng;

                        pickerMarker.on('dragend', (event) => {
                            const pos = event.target.getLatLng();
                            document.getElementById('picker_latitude').value = pos.lat.toFixed(6);
                            document.getElementById('picker_longitude').value = pos.lng.toFixed(6);
                        });
                    }
                }
            }, 500);
        }

        // Save picked location
        function savePickedLocation() {
            const lat = document.getElementById('picker_latitude').value;
            const lng = document.getElementById('picker_longitude').value;
            
            if (!lat || !lng) {
                alert('Please select a location on the map first.');
                return;
            }

            if (currentPickerMode === 'add') {
                document.getElementById('add_lat').value = lat;
                document.getElementById('add_lng').value = lng;
            } else {
                document.getElementById('edit_lat').value = lat;
                document.getElementById('edit_lng').value = lng;
            }

            // Close modal
            bootstrap.Modal.getInstance(document.getElementById('mapPickerModal')).hide();
        }

        // Load counties based on selected state
        function loadCounties(stateId, targetElement) {
            if (!stateId) return;
            
            fetch(`ajax/get_counties.php?state_id=${stateId}`)
                .then(response => response.json())
                .then(data => {
                    const select = document.getElementById(targetElement);
                    select.innerHTML = '<option value="">Select County</option>';
                    
                    data.forEach(county => {
                        select.innerHTML += `<option value="${county.id}">${county.name}</option>`;
                    });
                    select.disabled = false;
                })
                .catch(error => console.error('Error:', error));
        }
        
        // Load payams based on selected county
        function loadPayams(countyId, targetElement) {
            if (!countyId) return;
            
            fetch(`ajax/get_payams.php?county_id=${countyId}`)
                .then(response => response.json())
                .then(data => {
                    const select = document.getElementById(targetElement);
                    select.innerHTML = '<option value="">Select Payam</option>';
                    
                    data.forEach(payam => {
                        select.innerHTML += `<option value="${payam.id}">${payam.name}</option>`;
                    });
                    select.disabled = false;
                })
                .catch(error => console.error('Error:', error));
        }
        
        // Edit boma function
        function editBoma(boma) {
            document.getElementById('editBomaId').value = boma.id || '';
            document.getElementById('editName').value = boma.name || '';
            document.getElementById('editCode').value = boma.code || '';
            document.getElementById('editEstablished').value = boma.established_date || '';
            document.getElementById('editArea').value = boma.area_sqkm || '';
            document.getElementById('editPopulation').value = boma.population || '';
            document.getElementById('edit_lat').value = boma.center_lat || '';
            document.getElementById('edit_lng').value = boma.center_lng || '';
            document.getElementById('editDescription').value = boma.description || '';
            document.getElementById('editStateName').value = boma.state_name || '';
            document.getElementById('editCountyName').value = boma.county_name || '';
            
            // Load payams for the county
            if (boma.county_id) {
                loadPayams(boma.county_id, 'editPayam');
                
                // Set the selected payam after loading
                setTimeout(() => {
                    document.getElementById('editPayam').value = boma.payam_id;
                }, 500);
            }
        }
        
        // View boma function
        function viewBoma(boma) {
            document.getElementById('viewName').textContent = boma.name || 'N/A';
            document.getElementById('viewCode').textContent = boma.code || 'N/A';
            document.getElementById('viewState').textContent = boma.state_name || 'N/A';
            document.getElementById('viewCounty').textContent = boma.county_name || 'N/A';
            document.getElementById('viewPayam').textContent = boma.payam_name || 'N/A';
            document.getElementById('viewEstablished').textContent = boma.established_date ? new Date(boma.established_date).toLocaleDateString() : 'N/A';
            document.getElementById('viewArea').textContent = boma.area_sqkm ? boma.area_sqkm + ' km²' : 'N/A';
            document.getElementById('viewPopulation').textContent = boma.population ? Number(boma.population).toLocaleString() : 'N/A';
            
            if (boma.center_lat && boma.center_lng) {
                document.getElementById('viewCoordinates').textContent = `${boma.center_lat}, ${boma.center_lng}`;
                
                // Show mini map
                document.getElementById('view_map_container').style.display = 'block';
                setTimeout(() => {
                    const viewMap = L.map('view-map').setView([parseFloat(boma.center_lat), parseFloat(boma.center_lng)], 12);
                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        attribution: '&copy; OpenStreetMap contributors'
                    }).addTo(viewMap);
                    
                    L.marker([parseFloat(boma.center_lat), parseFloat(boma.center_lng)]).addTo(viewMap);
                }, 300);
            } else {
                document.getElementById('viewCoordinates').textContent = 'N/A';
                document.getElementById('view_map_container').style.display = 'none';
            }
            
            document.getElementById('viewDescription').textContent = boma.description || 'No description';
            document.getElementById('viewParcels').textContent = boma.total_parcels || 0;
            document.getElementById('viewTitles').textContent = boma.total_titles || 0;
            document.getElementById('viewApplications').textContent = boma.total_applications || 0;
            document.getElementById('viewTotalArea').textContent = boma.total_area ? Number(boma.total_area).toFixed(2) + ' m²' : '0 m²';
        }
        
        // Auto-submit filters when dropdowns change
        document.getElementById('stateFilter')?.addEventListener('change', function() {
            this.form.submit();
        });
        
        document.getElementById('countyFilter')?.addEventListener('change', function() {
            this.form.submit();
        });
        
        document.getElementById('payamFilter')?.addEventListener('change', function() {
            this.form.submit();
        });

        // Initialize map when page loads
        document.addEventListener('DOMContentLoaded', function() {
            initMap();
        });
    </script>

    <style>
        .boma-avatar {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }
        
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
        
        .pagination {
            margin-bottom: 0;
        }
        
        .page-link {
            padding: 0.375rem 0.75rem;
        }
        
        .bg-opacity-10 {
            --bs-bg-opacity: 0.1;
        }
        
        select:disabled {
            background-color: #e9ecef;
            cursor: not-allowed;
        }
        
        #bomas-map, #picker-map, #view-map {
            border-radius: 0.375rem;
            z-index: 1;
        }
        
        .custom-marker {
            display: flex;
            justify-content: center;
            align-items: center;
        }
        
        .marker-pin {
            width: 30px;
            height: 30px;
            border-radius: 50% 50% 50% 0;
            background: #c30b82;
            position: absolute;
            transform: rotate(-45deg);
            left: 50%;
            top: 50%;
            margin: -15px 0 0 -15px;
        }
        
        .custom-marker i {
            position: relative;
            z-index: 1;
            color: white;
            font-size: 16px;
            margin-top: -5px;
        }
        
        .leaflet-container {
            font-family: inherit;
        }
        
        .leaflet-popup-content {
            margin: 8px;
        }
        
        @media (max-width: 768px) {
            #bomas-map {
                height: 300px !important;
            }
        }
    </style>

</body>
</html>