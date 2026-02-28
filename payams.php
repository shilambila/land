<?php
ini_set('display_errors', 1); 
error_reporting(E_ALL);

// payams.php
include("head.php");
require_once 'db_connection.php';

// ============================================================================
// HANDLE ACTIONS
// ============================================================================

$message = '';
$messageType = '';

// Delete payam
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $payamId = $_GET['delete'];
    
    try {
        // Check if payam has related records
        $related = fetchOne($conn, "
            SELECT 
                (SELECT COUNT(*) FROM bomas WHERE payam_id = ?) as bomas,
                (SELECT COUNT(*) FROM parcels p WHERE p.boma_id IN (SELECT id FROM bomas WHERE payam_id = ?)) as parcels
        ", [$payamId, $payamId]);
        
        if ($related['bomas'] > 0 || $related['parcels'] > 0) {
            $message = "Cannot delete payam with existing bomas or parcels.";
            $messageType = "warning";
        } else {
            executeQuery($conn, "DELETE FROM payams WHERE id = ?", [$payamId]);
            $message = "Payam deleted successfully";
            $messageType = "success";
        }
    } catch (Exception $e) {
        $message = "Error deleting payam: " . $e->getMessage();
        $messageType = "danger";
    }
}

// Add/Edit payam
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        
        // Add new payam
        if ($_POST['action'] === 'add') {
            $county_id = $_POST['county_id'];
            $name = $_POST['name'];
            $code = $_POST['code'] ?? null;
            $latitude = !empty($_POST['latitude']) ? $_POST['latitude'] : null;
            $longitude = !empty($_POST['longitude']) ? $_POST['longitude'] : null;
            $boundary_coordinates = !empty($_POST['boundary_coordinates']) ? $_POST['boundary_coordinates'] : null;
            $population = !empty($_POST['population']) ? $_POST['population'] : null;
            $area_sqkm = !empty($_POST['area_sqkm']) ? $_POST['area_sqkm'] : null;
            
            try {
                // Check for duplicate payam code
                if ($code) {
                    $exists = fetchOne($conn, "SELECT id FROM payams WHERE code = ?", [$code]);
                    if ($exists) {
                        throw new Exception("Payam code already exists in the system");
                    }
                }
                
                executeQuery($conn, "
                    INSERT INTO payams (
                        county_id, name, code, latitude, longitude, 
                        boundary_coordinates, population, area_sqkm
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ", [$county_id, $name, $code, $latitude, $longitude, $boundary_coordinates, $population, $area_sqkm]);
                
                $message = "Payam added successfully";
                $messageType = "success";
            } catch (Exception $e) {
                $message = "Error adding payam: " . $e->getMessage();
                $messageType = "danger";
            }
        }
        
        // Edit payam
        if ($_POST['action'] === 'edit') {
            $payam_id = $_POST['payam_id'];
            $county_id = $_POST['county_id'];
            $name = $_POST['name'];
            $code = $_POST['code'] ?? null;
            $latitude = !empty($_POST['latitude']) ? $_POST['latitude'] : null;
            $longitude = !empty($_POST['longitude']) ? $_POST['longitude'] : null;
            $boundary_coordinates = !empty($_POST['boundary_coordinates']) ? $_POST['boundary_coordinates'] : null;
            $population = !empty($_POST['population']) ? $_POST['population'] : null;
            $area_sqkm = !empty($_POST['area_sqkm']) ? $_POST['area_sqkm'] : null;
            
            try {
                // Check for duplicate payam code (excluding current payam)
                if ($code) {
                    $exists = fetchOne($conn, "SELECT id FROM payams WHERE code = ? AND id != ?", [$code, $payam_id]);
                    if ($exists) {
                        throw new Exception("Payam code already exists in the system");
                    }
                }
                
                executeQuery($conn, "
                    UPDATE payams SET 
                        county_id = ?, name = ?, code = ?,
                        latitude = ?, longitude = ?, boundary_coordinates = ?,
                        population = ?, area_sqkm = ?
                    WHERE id = ?
                ", [$county_id, $name, $code, $latitude, $longitude, $boundary_coordinates, $population, $area_sqkm, $payam_id]);
                
                $message = "Payam updated successfully";
                $messageType = "success";
            } catch (Exception $e) {
                $message = "Error updating payam: " . $e->getMessage();
                $messageType = "danger";
            }
        }
    }
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
    $where_conditions[] = "s.id = ?";
    $params[] = $_GET['state_id'];
}

// County filter
if (!empty($_GET['county_id'])) {
    $where_conditions[] = "p.county_id = ?";
    $params[] = $_GET['county_id'];
}

// Search
if (!empty($_GET['search'])) {
    $search = '%' . $_GET['search'] . '%';
    $where_conditions[] = "(p.name LIKE ? OR p.code LIKE ? OR c.name LIKE ? OR s.name LIKE ?)";
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
}

// Has coordinates filter
if (!empty($_GET['has_coordinates'])) {
    if ($_GET['has_coordinates'] === 'yes') {
        $where_conditions[] = "(p.latitude IS NOT NULL AND p.longitude IS NOT NULL)";
    } elseif ($_GET['has_coordinates'] === 'no') {
        $where_conditions[] = "(p.latitude IS NULL OR p.longitude IS NULL)";
    }
}

// Population range filter
if (!empty($_GET['population_min'])) {
    $where_conditions[] = "p.population >= ?";
    $params[] = $_GET['population_min'];
}
if (!empty($_GET['population_max'])) {
    $where_conditions[] = "p.population <= ?";
    $params[] = $_GET['population_max'];
}

// Area range filter
if (!empty($_GET['area_min'])) {
    $where_conditions[] = "p.area_sqkm >= ?";
    $params[] = $_GET['area_min'];
}
if (!empty($_GET['area_max'])) {
    $where_conditions[] = "p.area_sqkm <= ?";
    $params[] = $_GET['area_max'];
}

$where_clause = empty($where_conditions) ? "" : "WHERE " . implode(" AND ", $where_conditions);

// Get total count for pagination
$count_sql = "
    SELECT COUNT(*) as total 
    FROM payams p
    LEFT JOIN counties c ON p.county_id = c.id
    LEFT JOIN states s ON c.state_id = s.id
    $where_clause
";
$total_result = executeQuery($conn, $count_sql, $params);
$total_rows = $total_result->fetch()['total'];
$total_pages = ceil($total_rows / $limit);

// Get payams with statistics
$sql = "
    SELECT 
        p.*,
        c.name as county_name,
        c.code as county_code,
        s.name as state_name,
        s.code as state_code,
        (SELECT COUNT(*) FROM bomas WHERE payam_id = p.id) as total_bomas,
        (SELECT COUNT(*) FROM parcels WHERE boma_id IN (SELECT id FROM bomas WHERE payam_id = p.id)) as total_parcels,
        (SELECT COUNT(*) FROM bomas WHERE payam_id = p.id AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) as recent_bomas,
        (SELECT COUNT(DISTINCT parcel_id) FROM ownerships o 
         JOIN titles t ON o.title_id = t.id 
         JOIN parcels par ON t.parcel_id = par.id
         WHERE par.boma_id IN (SELECT id FROM bomas WHERE payam_id = p.id)) as total_ownerships
    FROM payams p
    LEFT JOIN counties c ON p.county_id = c.id
    LEFT JOIN states s ON c.state_id = s.id
    $where_clause
    ORDER BY s.name, c.name, p.name
    LIMIT ? OFFSET ?
";

$params[] = $limit;
$params[] = $offset;

$payams = fetchAll($conn, $sql, $params);

// Get all states for dropdown
$states = fetchAll($conn, "SELECT id, name, code FROM states ORDER BY name");

// Get counties for dropdown (with state info)
$counties = fetchAll($conn, "
    SELECT c.id, c.name, c.code, s.name as state_name 
    FROM counties c
    LEFT JOIN states s ON c.state_id = s.id
    ORDER BY s.name, c.name
");

// Get summary statistics
$summary = fetchOne($conn, "
    SELECT 
        COUNT(*) as total_payams,
        COUNT(DISTINCT county_id) as total_counties_with_payams,
        SUM(CASE WHEN latitude IS NOT NULL AND longitude IS NOT NULL THEN 1 ELSE 0 END) as payams_with_coords,
        SUM(CASE WHEN boundary_coordinates IS NOT NULL THEN 1 ELSE 0 END) as payams_with_boundaries,
        SUM(population) as total_population,
        AVG(population) as avg_population,
        SUM(area_sqkm) as total_area,
        AVG(area_sqkm) as avg_area,
        COUNT(DISTINCT DATE(created_at)) as active_days
    FROM payams
");

// Get payams with most bomas
$top_payams = fetchAll($conn, "
    SELECT 
        p.name,
        p.id,
        COUNT(DISTINCT b.id) as boma_count,
        COUNT(DISTINCT par.id) as parcel_count
    FROM payams p
    LEFT JOIN bomas b ON p.id = b.payam_id
    LEFT JOIN parcels par ON b.id = par.boma_id
    GROUP BY p.id, p.name
    HAVING boma_count > 0 OR parcel_count > 0
    ORDER BY boma_count DESC, parcel_count DESC
    LIMIT 5
");

// Get recent activity
$recent_activity = fetchAll($conn, "
    SELECT 
        DATE(p.created_at) as date,
        COUNT(*) as count
    FROM payams p
    WHERE p.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY DATE(p.created_at)
    ORDER BY date DESC
    LIMIT 10
");

// Get county list for dynamic dropdown
$counties_json = json_encode($counties);
?>

<!-- Google Maps API -->
<script src="https://maps.googleapis.com/maps/api/js?key=YOUR_API_KEY&callback=initMap" async defer></script>

<body data-page="payams" class="payams-page">
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
                            <nav aria-label="breadcrumb">
                                <ol class="breadcrumb mb-2">
                                    <li class="breadcrumb-item"><a href="counties.php">Counties</a></li>
                                    <li class="breadcrumb-item active">Payams</li>
                                </ol>
                            </nav>
                            <h1 class="h3 mb-0">Payams Management</h1>
                            <p class="text-muted mb-0">Manage administrative payams (sub-counties) in South Sudan</p>
                        </div>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addPayamModal">
                            <i class="bi bi-plus-circle me-2"></i>Add New Payam
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
                                                <i class="bi bi-grid-3x3-gap-fill text-primary fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Total Payams</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['total_payams']); ?></h3>
                                            <small class="text-muted">Across <?php echo $summary['total_counties_with_payams']; ?> counties</small>
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
                                                <i class="bi bi-people text-success fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Total Population</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo $summary['total_population'] ? number_format($summary['total_population']) : 'N/A'; ?></h3>
                                            <small class="text-muted">Avg: <?php echo $summary['avg_population'] ? number_format($summary['avg_population']) : 'N/A'; ?> per payam</small>
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
                                                <i class="bi bi-map text-info fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Total Area</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo $summary['total_area'] ? number_format($summary['total_area'], 2) : 'N/A'; ?> km²</h3>
                                            <small class="text-muted">Avg: <?php echo $summary['avg_area'] ? number_format($summary['avg_area'], 2) : 'N/A'; ?> km²</small>
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
                                                <i class="bi bi-pin-map-fill text-warning fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Mapped Payams</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['payams_with_coords']); ?></h3>
                                            <small class="text-muted"><?php echo $summary['total_payams'] > 0 ? round(($summary['payams_with_coords'] / $summary['total_payams']) * 100) : 0; ?>% with coordinates</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Map View -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0 fw-bold">South Sudan Payams Map</h5>
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
                            <div id="payams-map" style="height: 500px; width: 100%;"></div>
                        </div>
                    </div>

                    <!-- Top Payams by Bomas -->
                    <?php if (!empty($top_payams)): ?>
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h5 class="card-title mb-0 fw-bold">Top Payams by Administrative Units</h5>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <?php foreach ($top_payams as $payam): ?>
                                        <div class="col-md-<?php echo 12 / count($top_payams); ?> mb-2">
                                            <div class="border rounded p-3 text-center">
                                                <h6 class="text-muted mb-1"><?php echo htmlspecialchars($payam['name']); ?></h6>
                                                <div class="d-flex justify-content-center gap-3">
                                                    <div>
                                                        <span class="h5 mb-0 fw-bold text-primary"><?php echo number_format($payam['boma_count']); ?></span>
                                                        <br><small class="text-muted">Bomas</small>
                                                    </div>
                                                    <div>
                                                        <span class="h5 mb-0 fw-bold text-success"><?php echo number_format($payam['parcel_count']); ?></span>
                                                        <br><small class="text-muted">Parcels</small>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Filters and Search -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-body">
                            <form method="GET" class="row g-3">
                                <div class="col-md-3">
                                    <label class="form-label">State</label>
                                    <select class="form-select" name="state_id" id="filter_state">
                                        <option value="">All States</option>
                                        <?php foreach ($states as $state): ?>
                                        <option value="<?php echo $state['id']; ?>" <?php echo ($_GET['state_id'] ?? '') == $state['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($state['name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">County</label>
                                    <select class="form-select" name="county_id" id="filter_county">
                                        <option value="">All Counties</option>
                                        <?php foreach ($counties as $county): ?>
                                        <option value="<?php echo $county['id']; ?>" data-state="<?php echo $county['state_name']; ?>" <?php echo ($_GET['county_id'] ?? '') == $county['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($county['name']); ?> (<?php echo htmlspecialchars($county['state_name']); ?>)
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Has Coordinates</label>
                                    <select class="form-select" name="has_coordinates">
                                        <option value="">All</option>
                                        <option value="yes" <?php echo ($_GET['has_coordinates'] ?? '') == 'yes' ? 'selected' : ''; ?>>Yes</option>
                                        <option value="no" <?php echo ($_GET['has_coordinates'] ?? '') == 'no' ? 'selected' : ''; ?>>No</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Search</label>
                                    <input type="text" class="form-control" name="search" 
                                           placeholder="Search by payam name, code, county, state..."
                                           value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                                </div>
                                
                                <!-- Advanced Filters (collapsible) -->
                                <div class="col-12">
                                    <button class="btn btn-link p-0" type="button" data-bs-toggle="collapse" data-bs-target="#advancedFilters">
                                        <i class="bi bi-sliders2"></i> Advanced Filters
                                    </button>
                                </div>
                                
                                <div class="collapse <?php echo (isset($_GET['population_min']) || isset($_GET['population_max']) || isset($_GET['area_min']) || isset($_GET['area_max'])) ? 'show' : ''; ?>" id="advancedFilters">
                                    <div class="row mt-3">
                                        <div class="col-md-3">
                                            <label class="form-label">Population (Min)</label>
                                            <input type="number" class="form-control" name="population_min" value="<?php echo htmlspecialchars($_GET['population_min'] ?? ''); ?>">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Population (Max)</label>
                                            <input type="number" class="form-control" name="population_max" value="<?php echo htmlspecialchars($_GET['population_max'] ?? ''); ?>">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Area (Min km²)</label>
                                            <input type="number" step="0.01" class="form-control" name="area_min" value="<?php echo htmlspecialchars($_GET['area_min'] ?? ''); ?>">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Area (Max km²)</label>
                                            <input type="number" step="0.01" class="form-control" name="area_max" value="<?php echo htmlspecialchars($_GET['area_max'] ?? ''); ?>">
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary me-2">
                                        <i class="bi bi-search me-2"></i>Apply Filters
                                    </button>
                                    <a href="payams.php" class="btn btn-secondary">
                                        <i class="bi bi-x-circle me-2"></i>Clear Filters
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Payams Table -->
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0 fw-bold">Payams List</h5>
                            <span class="badge bg-primary"><?php echo number_format($total_rows); ?> Records</span>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>ID</th>
                                            <th>Location</th>
                                            <th>Payam Name</th>
                                            <th>Code</th>
                                            <th>Demographics</th>
                                            <th>Statistics</th>
                                            <th>Map Status</th>
                                            <th>Created</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($payams)): ?>
                                        <tr>
                                            <td colspan="9" class="text-center py-4 text-muted">
                                                <i class="bi bi-inbox fs-1 d-block mb-3"></i>
                                                No payams found
                                            </td>
                                        </tr>
                                        <?php else: ?>
                                            <?php foreach ($payams as $payam): ?>
                                            <tr>
                                                <td>
                                                    <span class="fw-medium">#<?php echo $payam['id']; ?></span>
                                                </td>
                                                <td>
                                                    <span class="fw-medium"><?php echo htmlspecialchars($payam['state_name']); ?></span>
                                                    <br><small class="text-muted">
                                                        <?php echo htmlspecialchars($payam['county_name']); ?> (<?php echo htmlspecialchars($payam['county_code']); ?>)
                                                    </small>
                                                </td>
                                                <td>
                                                    <span class="fw-medium"><?php echo htmlspecialchars($payam['name']); ?></span>
                                                </td>
                                                <td>
                                                    <code><?php echo htmlspecialchars($payam['code'] ?? 'N/A'); ?></code>
                                                </td>
                                                <td>
                                                    <?php if ($payam['population']): ?>
                                                        <i class="bi bi-people text-muted"></i> <?php echo number_format($payam['population']); ?><br>
                                                    <?php endif; ?>
                                                    <?php if ($payam['area_sqkm']): ?>
                                                        <small class="text-muted"><?php echo number_format($payam['area_sqkm'], 2); ?> km²</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="d-flex flex-wrap gap-1">
                                                        <span class="badge bg-primary" title="Bomas">
                                                            <i class="bi bi-diagram-2"></i> <?php echo $payam['total_bomas']; ?>
                                                        </span>
                                                        <span class="badge bg-success" title="Parcels">
                                                            <i class="bi bi-pin-map"></i> <?php echo $payam['total_parcels']; ?>
                                                        </span>
                                                        <span class="badge bg-info" title="Ownerships">
                                                            <i class="bi bi-file-text"></i> <?php echo $payam['total_ownerships']; ?>
                                                        </span>
                                                        <?php if ($payam['recent_bomas'] > 0): ?>
                                                            <span class="badge bg-warning text-dark" title="New bomas (30 days)">
                                                                <i class="bi bi-plus-circle"></i> +<?php echo $payam['recent_bomas']; ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php if ($payam['latitude'] && $payam['longitude']): ?>
                                                        <span class="badge bg-success">
                                                            <i class="bi bi-check-circle"></i> Mapped
                                                        </span>
                                                        <br>
                                                        <button class="btn btn-sm btn-link p-0" onclick="focusMapOnPayam(<?php echo $payam['latitude']; ?>, <?php echo $payam['longitude']; ?>, '<?php echo htmlspecialchars(addslashes($payam['name'])); ?>')">
                                                            <i class="bi bi-eye"></i> View
                                                        </button>
                                                    <?php else: ?>
                                                        <span class="badge bg-warning text-dark">
                                                            <i class="bi bi-exclamation-triangle"></i> Not mapped
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <small class="text-muted">
                                                        <?php echo date('d/m/Y', strtotime($payam['created_at'])); ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <button class="btn btn-outline-primary" 
                                                                onclick="editPayam(<?php echo htmlspecialchars(json_encode($payam)); ?>)"
                                                                data-bs-toggle="modal" data-bs-target="#editPayamModal">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                        <a href="?delete=<?php echo $payam['id']; ?>" 
                                                           class="btn btn-outline-danger"
                                                           onclick="return confirm('Delete this payam? This will also delete all associated bomas and parcels.')">
                                                            <i class="bi bi-trash"></i>
                                                        </a>
                                                        <button class="btn btn-outline-info" 
                                                                onclick="viewPayamDetails(<?php echo htmlspecialchars(json_encode($payam)); ?>)"
                                                                data-bs-toggle="modal" data-bs-target="#viewPayamModal">
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

                    <!-- Recent Activity -->
                    <?php if (!empty($recent_activity)): ?>
                    <div class="card border-0 shadow-sm mt-4">
                        <div class="card-header bg-white py-3">
                            <h5 class="card-title mb-0 fw-bold">Recent Payam Additions</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <?php foreach ($recent_activity as $activity): ?>
                                <div class="col-md-3 mb-2">
                                    <div class="d-flex justify-content-between align-items-center border rounded p-2">
                                        <span><?php echo date('M d', strtotime($activity['date'])); ?></span>
                                        <span class="badge bg-primary"><?php echo $activity['count']; ?> new</span>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                </div>
            </main>

            <!-- Footer -->
            <?php include("footer.php"); ?>

        </div>
    </div>

    <!-- Add Payam Modal -->
    <div class="modal fade" id="addPayamModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" id="addPayamForm">
                    <input type="hidden" name="action" value="add">
                    <div class="modal-header">
                        <h5 class="modal-title">Add New Payam</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">County <span class="text-danger">*</span></label>
                                <select class="form-select" name="county_id" id="add_county_id" required>
                                    <option value="">Select County</option>
                                    <?php foreach ($counties as $county): ?>
                                    <option value="<?php echo $county['id']; ?>">
                                        <?php echo htmlspecialchars($county['name']); ?> (<?php echo htmlspecialchars($county['state_name']); ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Payam Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="name" required>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Payam Code</label>
                                <input type="text" class="form-control" name="code" placeholder="e.g., CE-JU-KT">
                                <small class="text-muted">Unique identifier (e.g., CE-JU-KT for Kator)</small>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Find on Map</label>
                                <button type="button" class="btn btn-outline-primary w-100" onclick="openMapPicker('add')">
                                    <i class="bi bi-pin-map me-2"></i>Pick Location from Map
                                </button>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Latitude</label>
                                <input type="number" step="any" class="form-control" name="latitude" id="add_latitude" readonly>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Longitude</label>
                                <input type="number" step="any" class="form-control" name="longitude" id="add_longitude" readonly>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Population</label>
                                <input type="number" class="form-control" name="population" min="0">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Area (km²)</label>
                                <input type="number" step="0.01" class="form-control" name="area_sqkm" min="0">
                            </div>
                            
                            <div class="col-12 mb-3">
                                <label class="form-label">Boundary Coordinates (GeoJSON)</label>
                                <textarea class="form-control" name="boundary_coordinates" rows="3" placeholder='{"type":"Polygon","coordinates":[...]}'></textarea>
                                <small class="text-muted">Optional: Define payam boundaries in GeoJSON format</small>
                            </div>
                            
                            <div class="col-12">
                                <div id="add-map-preview" style="height: 300px; width: 100%; display: none;"></div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Payam</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Payam Modal -->
    <div class="modal fade" id="editPayamModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" id="editPayamForm">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="payam_id" id="edit_payam_id">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Payam</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">County <span class="text-danger">*</span></label>
                                <select class="form-select" name="county_id" id="edit_county_id" required>
                                    <option value="">Select County</option>
                                    <?php foreach ($counties as $county): ?>
                                    <option value="<?php echo $county['id']; ?>">
                                        <?php echo htmlspecialchars($county['name']); ?> (<?php echo htmlspecialchars($county['state_name']); ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Payam Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="name" id="edit_name" required>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Payam Code</label>
                                <input type="text" class="form-control" name="code" id="edit_code">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Update Location</label>
                                <button type="button" class="btn btn-outline-primary w-100" onclick="openMapPicker('edit')">
                                    <i class="bi bi-pin-map me-2"></i>Pick New Location
                                </button>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Latitude</label>
                                <input type="number" step="any" class="form-control" name="latitude" id="edit_latitude" readonly>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Longitude</label>
                                <input type="number" step="any" class="form-control" name="longitude" id="edit_longitude" readonly>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Population</label>
                                <input type="number" class="form-control" name="population" id="edit_population" min="0">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Area (km²)</label>
                                <input type="number" step="0.01" class="form-control" name="area_sqkm" id="edit_area_sqkm" min="0">
                            </div>
                            
                            <div class="col-12 mb-3">
                                <label class="form-label">Boundary Coordinates (GeoJSON)</label>
                                <textarea class="form-control" name="boundary_coordinates" id="edit_boundary_coordinates" rows="3"></textarea>
                            </div>
                            
                            <div class="col-12">
                                <div id="edit-map-preview" style="height: 300px; width: 100%;"></div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Payam</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Payam Modal -->
    <div class="modal fade" id="viewPayamModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Payam Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">State:</label>
                            <p id="view_state" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">County:</label>
                            <p id="view_county" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Payam Name:</label>
                            <p id="view_name" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Code:</label>
                            <p id="view_code" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Population:</label>
                            <p id="view_population" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Area:</label>
                            <p id="view_area" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Coordinates:</label>
                            <p id="view_coordinates" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Created:</label>
                            <p id="view_created" class="mb-0"></p>
                        </div>
                    </div>
                    
                    <!-- Statistics -->
                    <div class="row mt-3">
                        <div class="col-md-4 mb-2">
                            <div class="border rounded p-3 text-center">
                                <small class="text-muted d-block">Bomas</small>
                                <span id="view_bomas" class="h4 mb-0">0</span>
                            </div>
                        </div>
                        <div class="col-md-4 mb-2">
                            <div class="border rounded p-3 text-center">
                                <small class="text-muted d-block">Parcels</small>
                                <span id="view_parcels" class="h4 mb-0">0</span>
                            </div>
                        </div>
                        <div class="col-md-4 mb-2">
                            <div class="border rounded p-3 text-center">
                                <small class="text-muted d-block">Ownerships</small>
                                <span id="view_ownerships" class="h4 mb-0">0</span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Map Preview -->
                    <div class="mt-3">
                        <label class="fw-bold">Location Map:</label>
                        <div id="view-map" style="height: 300px; width: 100%;"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Map Picker Modal -->
    <div class="modal fade" id="mapPickerModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Select Location on Map</h5>
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

    <!-- JavaScript for Google Maps and Dynamic Filters -->
    <script>
        // Global variables
        let map;
        let markers = [];
        let currentPickerMode = 'add';
        let pickerMarker;
        let counties = <?php echo $counties_json; ?>;
        
        // South Sudan bounds (approximate)
        const SOUTH_SUDAN_BOUNDS = {
            north: 12.0,
            south: 3.5,
            west: 23.5,
            east: 36.0
        };
        
        // Center of South Sudan (approximate)
        const SOUTH_SUDAN_CENTER = { lat: 7.5, lng: 30.0 };

        // Initialize map
        function initMap() {
            // Create main map
            map = new google.maps.Map(document.getElementById('payams-map'), {
                center: SOUTH_SUDAN_CENTER,
                zoom: 6,
                restriction: {
                    latLngBounds: SOUTH_SUDAN_BOUNDS,
                    strictBounds: false
                },
                mapTypeId: google.maps.MapTypeId.ROADMAP,
                mapTypeControl: true,
                streetViewControl: false,
                fullscreenControl: true
            });

            // Load payam markers
            loadPayamMarkers();
        }

        // Load payam markers from database
        function loadPayamMarkers() {
            <?php foreach ($payams as $payam): ?>
                <?php if ($payam['latitude'] && $payam['longitude']): ?>
                    addPayamMarker(
                        <?php echo $payam['latitude']; ?>,
                        <?php echo $payam['longitude']; ?>,
                        '<?php echo htmlspecialchars(addslashes($payam['name'])); ?>',
                        '<?php echo htmlspecialchars(addslashes($payam['county_name'])); ?>',
                        <?php echo $payam['total_bomas']; ?>,
                        <?php echo $payam['total_parcels']; ?>
                    );
                <?php endif; ?>
            <?php endforeach; ?>
        }

        // Add a marker for a payam
        function addPayamMarker(lat, lng, name, county, bomas, parcels) {
            const marker = new google.maps.Marker({
                position: { lat: parseFloat(lat), lng: parseFloat(lng) },
                map: map,
                title: name,
                animation: google.maps.Animation.DROP,
                icon: {
                    url: 'https://maps.google.com/mapfiles/ms/icons/blue-dot.png',
                    scaledSize: new google.maps.Size(32, 32)
                }
            });

            const infowindow = new google.maps.InfoWindow({
                content: `
                    <div style="padding: 8px; max-width: 200px;">
                        <h6 style="margin: 0 0 5px 0; font-weight: bold;">${name}</h6>
                        <p style="margin: 0 0 3px 0; color: #666;">${county}</p>
                        <hr style="margin: 5px 0;">
                        <div style="display: flex; justify-content: space-between;">
                            <span><strong>Bomas:</strong> ${bomas}</span>
                            <span><strong>Parcels:</strong> ${parcels}</span>
                        </div>
                    </div>
                `
            });

            marker.addListener('click', () => {
                infowindow.open(map, marker);
            });

            markers.push(marker);
        }

        // Refresh map markers
        function refreshMapMarkers() {
            // Clear existing markers
            markers.forEach(marker => marker.setMap(null));
            markers = [];
            
            // Reload markers
            loadPayamMarkers();
        }

        // Center map on South Sudan
        function centerMapOnSouthSudan() {
            map.setCenter(SOUTH_SUDAN_CENTER);
            map.setZoom(6);
        }

        // Focus map on a specific payam
        function focusMapOnPayam(lat, lng, name) {
            map.setCenter({ lat: parseFloat(lat), lng: parseFloat(lng) });
            map.setZoom(12);
            
            // Show info window
            const marker = new google.maps.Marker({
                position: { lat: parseFloat(lat), lng: parseFloat(lng) },
                map: map,
                title: name,
                animation: google.maps.Animation.BOUNCE
            });
            
            setTimeout(() => {
                marker.setAnimation(null);
            }, 2100);
        }

        // Dynamic county filtering based on selected state
        document.getElementById('filter_state')?.addEventListener('change', function() {
            const stateId = this.value;
            const countySelect = document.getElementById('filter_county');
            
            // Clear current options
            countySelect.innerHTML = '<option value="">All Counties</option>';
            
            // Filter counties by state
            counties.forEach(county => {
                if (!stateId || county.state_id == stateId) {
                    const option = document.createElement('option');
                    option.value = county.id;
                    option.textContent = `${county.name} (${county.state_name})`;
                    if (county.id == '<?php echo $_GET['county_id'] ?? ''; ?>') {
                        option.selected = true;
                    }
                    countySelect.appendChild(option);
                }
            });
        });

        // Open map picker modal
        function openMapPicker(mode) {
            currentPickerMode = mode;
            
            // Show the modal
            const modal = new bootstrap.Modal(document.getElementById('mapPickerModal'));
            modal.show();
            
            // Initialize picker map after modal is shown
            setTimeout(() => {
                const pickerMap = new google.maps.Map(document.getElementById('picker-map'), {
                    center: SOUTH_SUDAN_CENTER,
                    zoom: 6,
                    restriction: {
                        latLngBounds: SOUTH_SUDAN_BOUNDS,
                        strictBounds: false
                    }
                });

                // Add click listener to pick location
                pickerMap.addListener('click', (event) => {
                    // Remove existing marker
                    if (pickerMarker) {
                        pickerMarker.setMap(null);
                    }

                    // Add new marker
                    pickerMarker = new google.maps.Marker({
                        position: event.latLng,
                        map: pickerMap,
                        animation: google.maps.Animation.DROP,
                        draggable: true
                    });

                    // Update input fields
                    document.getElementById('picker_latitude').value = event.latLng.lat().toFixed(6);
                    document.getElementById('picker_longitude').value = event.latLng.lng().toFixed(6);

                    // Make marker draggable
                    pickerMarker.addListener('dragend', () => {
                        const position = pickerMarker.getPosition();
                        document.getElementById('picker_latitude').value = position.lat().toFixed(6);
                        document.getElementById('picker_longitude').value = position.lng().toFixed(6);
                    });
                });

                // If editing, show existing location
                if (mode === 'edit') {
                    const lat = document.getElementById('edit_latitude').value;
                    const lng = document.getElementById('edit_longitude').value;
                    
                    if (lat && lng) {
                        const position = { lat: parseFloat(lat), lng: parseFloat(lng) };
                        pickerMap.setCenter(position);
                        pickerMap.setZoom(12);
                        
                        pickerMarker = new google.maps.Marker({
                            position: position,
                            map: pickerMap,
                            animation: google.maps.Animation.DROP,
                            draggable: true
                        });
                        
                        document.getElementById('picker_latitude').value = lat;
                        document.getElementById('picker_longitude').value = lng;
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
                document.getElementById('add_latitude').value = lat;
                document.getElementById('add_longitude').value = lng;
                
                // Show map preview
                document.getElementById('add-map-preview').style.display = 'block';
                initPreviewMap('add-map-preview', lat, lng);
            } else {
                document.getElementById('edit_latitude').value = lat;
                document.getElementById('edit_longitude').value = lng;
                
                // Update preview
                initPreviewMap('edit-map-preview', lat, lng);
            }

            // Close modal
            bootstrap.Modal.getInstance(document.getElementById('mapPickerModal')).hide();
        }

        // Initialize preview map
        function initPreviewMap(elementId, lat, lng) {
            setTimeout(() => {
                new google.maps.Map(document.getElementById(elementId), {
                    center: { lat: parseFloat(lat), lng: parseFloat(lng) },
                    zoom: 12,
                    disableDefaultUI: true
                });
            }, 300);
        }

        // Edit payam function
        function editPayam(payam) {
            document.getElementById('edit_payam_id').value = payam.id;
            document.getElementById('edit_county_id').value = payam.county_id;
            document.getElementById('edit_name').value = payam.name || '';
            document.getElementById('edit_code').value = payam.code || '';
            document.getElementById('edit_latitude').value = payam.latitude || '';
            document.getElementById('edit_longitude').value = payam.longitude || '';
            document.getElementById('edit_population').value = payam.population || '';
            document.getElementById('edit_area_sqkm').value = payam.area_sqkm || '';
            document.getElementById('edit_boundary_coordinates').value = payam.boundary_coordinates || '';
            
            // Show map preview if coordinates exist
            if (payam.latitude && payam.longitude) {
                initPreviewMap('edit-map-preview', payam.latitude, payam.longitude);
            }
        }

        // View payam details
        function viewPayamDetails(payam) {
            document.getElementById('view_state').textContent = payam.state_name || 'N/A';
            document.getElementById('view_county').textContent = payam.county_name || 'N/A';
            document.getElementById('view_name').textContent = payam.name || 'N/A';
            document.getElementById('view_code').textContent = payam.code || 'N/A';
            document.getElementById('view_population').textContent = payam.population ? Number(payam.population).toLocaleString() : 'N/A';
            document.getElementById('view_area').textContent = payam.area_sqkm ? `${Number(payam.area_sqkm).toLocaleString()} km²` : 'N/A';
            
            if (payam.latitude && payam.longitude) {
                document.getElementById('view_coordinates').textContent = 
                    `${payam.latitude}, ${payam.longitude}`;
                
                // Show map
                setTimeout(() => {
                    new google.maps.Map(document.getElementById('view-map'), {
                        center: { lat: parseFloat(payam.latitude), lng: parseFloat(payam.longitude) },
                        zoom: 12,
                        disableDefaultUI: true
                    });
                }, 300);
            } else {
                document.getElementById('view_coordinates').textContent = 'No coordinates available';
                document.getElementById('view-map').innerHTML = '<div class="alert alert-info">No location data available</div>';
            }
            
            document.getElementById('view_created').textContent = new Date(payam.created_at).toLocaleString();
            
            // Statistics
            document.getElementById('view_bomas').textContent = payam.total_bomas || '0';
            document.getElementById('view_parcels').textContent = payam.total_parcels || '0';
            document.getElementById('view_ownerships').textContent = payam.total_ownerships || '0';
        }

        // Initialize tooltips
        document.addEventListener('DOMContentLoaded', function() {
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[title]'));
            tooltipTriggerList.map(function(tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
        });
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
        
        #payams-map {
            border-radius: 0 0 0.375rem 0.375rem;
        }
        
        .gm-style-iw-c {
            padding: 12px !important;
        }
        
        .gm-style-iw-d {
            overflow: hidden !important;
        }
        
        .breadcrumb {
            background: transparent;
            padding: 0;
        }
        
        .breadcrumb-item a {
            color: var(--bs-primary);
            text-decoration: none;
        }
        
        .breadcrumb-item a:hover {
            text-decoration: underline;
        }
        
        @media (max-width: 768px) {
            #payams-map {
                height: 300px !important;
            }
        }
    </style>

</body>
</html>