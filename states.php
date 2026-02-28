<?php
ini_set('display_errors', 1); 
error_reporting(E_ALL);

// states.php
include("head.php");
require_once 'db_connection.php';

// ============================================================================
// HANDLE ACTIONS
// ============================================================================

$message = '';
$messageType = '';

// Delete state
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $stateId = $_GET['delete'];
    
    try {
        // Check if state has related records (counties, parcels, etc.)
        $related = fetchOne($conn, "
            SELECT 
                (SELECT COUNT(*) FROM counties WHERE state_id = ?) as counties,
                (SELECT COUNT(*) FROM parcels p 
                 JOIN bomas b ON p.boma_id = b.id
                 JOIN payams pa ON b.payam_id = pa.id
                 JOIN counties c ON pa.county_id = c.id
                 WHERE c.state_id = ?) as parcels
        ", [$stateId, $stateId]);
        
        if ($related['counties'] > 0 || $related['parcels'] > 0) {
            $message = "Cannot delete state with existing counties or parcels. Remove dependencies first.";
            $messageType = "warning";
        } else {
            executeQuery($conn, "DELETE FROM states WHERE id = ?", [$stateId]);
            $message = "State deleted successfully";
            $messageType = "success";
        }
    } catch (Exception $e) {
        $message = "Error deleting state: " . $e->getMessage();
        $messageType = "danger";
    }
}

// Add/Edit state
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        
        // Add new state
        if ($_POST['action'] === 'add') {
            $name = $_POST['name'];
            $code = strtoupper($_POST['code']);
            $capital = $_POST['capital'] ?? null;
            $population = !empty($_POST['population']) ? $_POST['population'] : null;
            $area_sqkm = !empty($_POST['area_sqkm']) ? $_POST['area_sqkm'] : null;
            $description = $_POST['description'] ?? null;
            $center_lat = !empty($_POST['center_lat']) ? $_POST['center_lat'] : null;
            $center_lng = !empty($_POST['center_lng']) ? $_POST['center_lng'] : null;
            $zoom_level = !empty($_POST['zoom_level']) ? $_POST['zoom_level'] : 8;
            $boundary_coordinates = $_POST['boundary_coordinates'] ?? null;
            
            try {
                // Check for duplicate code
                $exists = fetchOne($conn, "SELECT id FROM states WHERE code = ?", [$code]);
                if ($exists) {
                    throw new Exception("State code already exists");
                }
                
                executeQuery($conn, "
                    INSERT INTO states (
                        name, code, capital, population, area_sqkm, 
                        description, center_lat, center_lng, zoom_level, 
                        boundary_coordinates, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ", [$name, $code, $capital, $population, $area_sqkm, 
                    $description, $center_lat, $center_lng, $zoom_level, 
                    $boundary_coordinates]);
                
                $message = "State added successfully";
                $messageType = "success";
            } catch (Exception $e) {
                $message = "Error adding state: " . $e->getMessage();
                $messageType = "danger";
            }
        }
        
        // Edit state
        if ($_POST['action'] === 'edit') {
            $state_id = $_POST['state_id'];
            $name = $_POST['name'];
            $code = strtoupper($_POST['code']);
            $capital = $_POST['capital'] ?? null;
            $population = !empty($_POST['population']) ? $_POST['population'] : null;
            $area_sqkm = !empty($_POST['area_sqkm']) ? $_POST['area_sqkm'] : null;
            $description = $_POST['description'] ?? null;
            $center_lat = !empty($_POST['center_lat']) ? $_POST['center_lat'] : null;
            $center_lng = !empty($_POST['center_lng']) ? $_POST['center_lng'] : null;
            $zoom_level = !empty($_POST['zoom_level']) ? $_POST['zoom_level'] : 8;
            $boundary_coordinates = $_POST['boundary_coordinates'] ?? null;
            
            try {
                // Check for duplicate code (excluding current)
                $exists = fetchOne($conn, "SELECT id FROM states WHERE code = ? AND id != ?", [$code, $state_id]);
                if ($exists) {
                    throw new Exception("State code already exists");
                }
                
                executeQuery($conn, "
                    UPDATE states SET 
                        name = ?, code = ?, capital = ?, population = ?, 
                        area_sqkm = ?, description = ?, center_lat = ?, 
                        center_lng = ?, zoom_level = ?, boundary_coordinates = ?
                    WHERE id = ?
                ", [$name, $code, $capital, $population, $area_sqkm, 
                    $description, $center_lat, $center_lng, $zoom_level, 
                    $boundary_coordinates, $state_id]);
                
                $message = "State updated successfully";
                $messageType = "success";
            } catch (Exception $e) {
                $message = "Error updating state: " . $e->getMessage();
                $messageType = "danger";
            }
        }
    }
}

// ============================================================================
// GET STATES WITH STATISTICS
// ============================================================================

$states = fetchAll($conn, "
    SELECT 
        s.*,
        (SELECT COUNT(*) FROM counties WHERE state_id = s.id) as total_counties,
        (SELECT COUNT(*) FROM payams pa 
         JOIN counties c ON pa.county_id = c.id 
         WHERE c.state_id = s.id) as total_payams,
        (SELECT COUNT(*) FROM bomas b 
         JOIN payams pa ON b.payam_id = pa.id
         JOIN counties c ON pa.county_id = c.id 
         WHERE c.state_id = s.id) as total_bomas,
        (SELECT COUNT(*) FROM parcels p 
         JOIN bomas b ON p.boma_id = b.id
         JOIN payams pa ON b.payam_id = pa.id
         JOIN counties c ON pa.county_id = c.id 
         WHERE c.state_id = s.id) as total_parcels,
        (SELECT COUNT(*) FROM titles t
         JOIN parcels p ON t.parcel_id = p.id
         JOIN bomas b ON p.boma_id = b.id
         JOIN payams pa ON b.payam_id = pa.id
         JOIN counties c ON pa.county_id = c.id 
         WHERE c.state_id = s.id) as total_titles
    FROM states s
    ORDER BY s.name
");

// Get summary statistics
$summary = fetchOne($conn, "
    SELECT 
        COUNT(*) as total_states,
        SUM(area_sqkm) as total_area,
        SUM(population) as total_population,
        (SELECT COUNT(*) FROM counties) as total_counties,
        (SELECT COUNT(*) FROM payams) as total_payams,
        (SELECT COUNT(*) FROM bomas) as total_bomas,
        (SELECT COUNT(*) FROM parcels) as total_parcels
    FROM states
");

// Get coordinates for each state (for map markers)
$state_coordinates = [];
foreach ($states as $state) {
    if ($state['center_lat'] && $state['center_lng']) {
        $state_coordinates[] = [
            'id' => $state['id'],
            'name' => $state['name'],
            'code' => $state['code'],
            'capital' => $state['capital'],
            'lat' => (float)$state['center_lat'],
            'lng' => (float)$state['center_lng'],
            'zoom' => (int)$state['zoom_level'],
            'counties' => $state['total_counties'],
            'parcels' => $state['total_parcels']
        ];
    }
}

// South Sudan center coordinates
$south_sudan_center = ['lat' => 7.5, 'lng' => 30.0, 'zoom' => 7];

// South Sudan states data (approximate coordinates)
$south_sudan_states = [
    ['name' => 'Central Equatoria', 'code' => 'CE', 'capital' => 'Juba', 'lat' => 4.85, 'lng' => 31.6],
    ['name' => 'Eastern Equatoria', 'code' => 'EE', 'capital' => 'Torit', 'lat' => 4.41, 'lng' => 32.57],
    ['name' => 'Jonglei', 'code' => 'JG', 'capital' => 'Bor', 'lat' => 6.21, 'lng' => 31.56],
    ['name' => 'Lakes', 'code' => 'LK', 'capital' => 'Rumbek', 'lat' => 6.81, 'lng' => 29.68],
    ['name' => 'Northern Bahr el Ghazal', 'code' => 'NB', 'capital' => 'Aweil', 'lat' => 8.77, 'lng' => 27.4],
    ['name' => 'Unity', 'code' => 'UN', 'capital' => 'Bentiu', 'lat' => 9.23, 'lng' => 29.83],
    ['name' => 'Upper Nile', 'code' => 'UN', 'capital' => 'Malakal', 'lat' => 9.53, 'lng' => 31.65],
    ['name' => 'Warrap', 'code' => 'WR', 'capital' => 'Kuajok', 'lat' => 8.3, 'lng' => 28.0],
    ['name' => 'Western Bahr el Ghazal', 'code' => 'WB', 'capital' => 'Wau', 'lat' => 7.7, 'lng' => 27.98],
    ['name' => 'Western Equatoria', 'code' => 'WE', 'capital' => 'Yambio', 'lat' => 4.57, 'lng' => 28.4]
];
?>

<body data-page="states" class="states-page">
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
                            <h1 class="h3 mb-0">States Management - South Sudan</h1>
                            <p class="text-muted mb-0">Manage states and administrative boundaries with map visualization</p>
                        </div>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addStateModal">
                            <i class="bi bi-plus-circle me-2"></i>Add New State
                        </button>
                    </div>

                    <!-- Alert Message -->
                    <?php if ($message): ?>
                    <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show mb-4" role="alert">
                        <?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    <?php endif; ?>

                    <!-- Summary Cards - FIXED: Added null checks for number_format -->
                    <div class="row g-4 mb-5">
                        <div class="col-xl-2 col-md-4">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0 me-3">
                                            <div class="bg-primary bg-opacity-10 rounded-circle p-3">
                                                <i class="bi bi-map text-primary fs-4"></i>
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
                                            <div class="bg-success bg-opacity-10 rounded-circle p-3">
                                                <i class="bi bi-building text-success fs-4"></i>
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
                                            <div class="bg-info bg-opacity-10 rounded-circle p-3">
                                                <i class="bi bi-house text-info fs-4"></i>
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
                                            <div class="bg-warning bg-opacity-10 rounded-circle p-3">
                                                <i class="bi bi-pin-map text-warning fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Bomas</h6>
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
                                            <div class="bg-danger bg-opacity-10 rounded-circle p-3">
                                                <i class="bi bi-grid text-danger fs-4"></i>
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
                                            <h6 class="text-muted mb-1">Total Area</h6>
                                            <h3 class="mb-0 fw-bold">
                                                <?php 
                                                $total_area = $summary['total_area'] ?? 0;
                                                echo $total_area ? number_format($total_area) . ' km²' : '0 km²'; 
                                                ?>
                                            </h3>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Google Maps Section -->
                    <div class="card border-0 shadow-sm mb-5">
                        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0 fw-bold">
                                <i class="bi bi-geo-alt text-danger me-2"></i>South Sudan States Map
                            </h5>
                            <div>
                                <span class="badge bg-primary me-2" id="map-status">Loading map...</span>
                                <button class="btn btn-sm btn-outline-primary" onclick="centerMap()">
                                    <i class="bi bi-crosshair"></i> Center Map
                                </button>
                                <button class="btn btn-sm btn-outline-success" onclick="showAllStates()">
                                    <i class="bi bi-eye"></i> Show All States
                                </button>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <div id="south-sudan-map" style="height: 550px; width: 100%;"></div>
                        </div>
                        <div class="card-footer bg-white py-2">
                            <div class="row">
                                <div class="col-md-8">
                                    <small class="text-muted">
                                        <i class="bi bi-info-circle me-1"></i>
                                        Click on markers to view state details. Double-click to zoom. 
                                        <span class="badge bg-danger ms-2">🔴 National Capital</span>
                                        <span class="badge bg-primary ms-2">🔵 State Capital</span>
                                    </small>
                                </div>
                                <div class="col-md-4 text-end">
                                    <small class="text-muted">
                                        <i class="bi bi-arrow-repeat me-1"></i>
                                        Last updated: <?php echo date('Y-m-d H:i'); ?>
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- States Grid - FIXED: Added null checks for all number_format calls -->
                    <div class="row g-4 mb-5">
                        <?php foreach ($states as $state): ?>
                        <div class="col-xl-4 col-lg-6">
                            <div class="card border-0 shadow-sm h-100 state-card" 
                                 onmouseover="highlightState(<?php echo $state['center_lat'] ?? 'null'; ?>, <?php echo $state['center_lng'] ?? 'null'; ?>)"
                                 onmouseout="unhighlightState()">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start mb-3">
                                        <div>
                                            <h5 class="card-title mb-1">
                                                <?php echo htmlspecialchars($state['name'] ?? 'Unknown'); ?>
                                                <span class="badge bg-secondary ms-2"><?php echo $state['code'] ?? 'N/A'; ?></span>
                                            </h5>
                                            <p class="text-muted mb-0">
                                                <i class="bi bi-building me-1"></i> Capital: <?php echo $state['capital'] ?? 'N/A'; ?>
                                            </p>
                                        </div>
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="dropdown">
                                                <i class="bi bi-three-dots"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end">
                                                <li>
                                                    <button class="dropdown-item" onclick="flyToState(<?php echo $state['center_lat']; ?>, <?php echo $state['center_lng']; ?>, <?php echo $state['zoom_level'] ?? 8; ?>)">
                                                        <i class="bi bi-geo-alt me-2"></i>View on Map
                                                    </button>
                                                </li>
                                                <li>
                                                    <button class="dropdown-item" onclick="editState(<?php echo htmlspecialchars(json_encode($state)); ?>)"
                                                            data-bs-toggle="modal" data-bs-target="#editStateModal">
                                                        <i class="bi bi-pencil me-2"></i>Edit
                                                    </button>
                                                </li>
                                                <li>
                                                    <a class="dropdown-item text-danger" href="?delete=<?php echo $state['id']; ?>" 
                                                       onclick="return confirm('Delete this state? This will affect all related counties, payams, and bomas.')">
                                                        <i class="bi bi-trash me-2"></i>Delete
                                                    </a>
                                                </li>
                                            </ul>
                                        </div>
                                    </div>
                                    
                                    <div class="row g-2 mb-3">
                                        <div class="col-6">
                                            <div class="border rounded p-2 text-center">
                                                <small class="text-muted d-block">Population</small>
                                                <span class="fw-bold">
                                                    <?php echo isset($state['population']) && $state['population'] ? number_format($state['population']) : 'N/A'; ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="border rounded p-2 text-center">
                                                <small class="text-muted d-block">Area (km²)</small>
                                                <span class="fw-bold">
                                                    <?php echo isset($state['area_sqkm']) && $state['area_sqkm'] ? number_format($state['area_sqkm']) : 'N/A'; ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="d-flex flex-wrap gap-2 mb-3">
                                        <span class="badge bg-success" title="Counties">
                                            <i class="bi bi-building"></i> <?php echo $state['total_counties'] ?? 0; ?> Counties
                                        </span>
                                        <span class="badge bg-info" title="Payams">
                                            <i class="bi bi-house"></i> <?php echo $state['total_payams'] ?? 0; ?> Payams
                                        </span>
                                        <span class="badge bg-warning" title="Bomas">
                                            <i class="bi bi-pin"></i> <?php echo $state['total_bomas'] ?? 0; ?> Bomas
                                        </span>
                                        <span class="badge bg-primary" title="Parcels">
                                            <i class="bi bi-grid"></i> <?php echo $state['total_parcels'] ?? 0; ?> Parcels
                                        </span>
                                        <span class="badge bg-secondary" title="Titles">
                                            <i class="bi bi-file-text"></i> <?php echo $state['total_titles'] ?? 0; ?> Titles
                                        </span>
                                    </div>
                                    
                                    <?php if (!empty($state['description'])): ?>
                                    <p class="small text-muted mb-0">
                                        <?php echo htmlspecialchars($state['description']); ?>
                                    </p>
                                    <?php endif; ?>
                                </div>
                                <div class="card-footer bg-white border-0 pt-0">
                                    <?php if (!empty($state['center_lat']) && !empty($state['center_lng'])): ?>
                                    <button class="btn btn-sm btn-outline-primary w-100" 
                                            onclick="flyToState(<?php echo $state['center_lat']; ?>, <?php echo $state['center_lng']; ?>, <?php echo $state['zoom_level'] ?? 8; ?>)">
                                        <i class="bi bi-geo-alt me-2"></i>Locate on Map
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                </div>
            </main>

            <!-- Footer -->
            <?php include("footer.php"); ?>

        </div>
    </div>

    <!-- Add State Modal -->
    <div class="modal fade" id="addStateModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="add">
                    <div class="modal-header">
                        <h5 class="modal-title">Add New State - South Sudan</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">State Name</label>
                                <input type="text" class="form-control" name="name" required 
                                       placeholder="e.g., Central Equatoria">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">State Code</label>
                                <input type="text" class="form-control" name="code" required 
                                       placeholder="e.g., CE" maxlength="10">
                                <small class="text-muted">Two-letter code (e.g., CE for Central Equatoria)</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Capital City</label>
                                <input type="text" class="form-control" name="capital" 
                                       placeholder="e.g., Juba">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Population</label>
                                <input type="number" class="form-control" name="population" 
                                       placeholder="e.g., 1500000">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Area (km²)</label>
                                <input type="number" class="form-control" name="area_sqkm" step="0.01" 
                                       placeholder="e.g., 43000">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Zoom Level</label>
                                <select class="form-select" name="zoom_level">
                                    <option value="6">6 - Far</option>
                                    <option value="7" selected>7 - Default</option>
                                    <option value="8">8 - Close</option>
                                    <option value="9">9 - Very Close</option>
                                    <option value="10">10 - Detailed</option>
                                </select>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Center Latitude</label>
                                <input type="number" class="form-control" name="center_lat" step="0.0001" 
                                       placeholder="e.g., 4.8500">
                                <small class="text-muted">Use Google Maps to find coordinates</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Center Longitude</label>
                                <input type="number" class="form-control" name="center_lng" step="0.0001" 
                                       placeholder="e.g., 31.6000">
                            </div>
                            
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Boundary Coordinates (GeoJSON)</label>
                                <textarea class="form-control" name="boundary_coordinates" rows="4" 
                                          placeholder='{"type":"Polygon","coordinates":[...]}'></textarea>
                                <small class="text-muted">Optional: GeoJSON format for state boundaries</small>
                            </div>
                            
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Description</label>
                                <textarea class="form-control" name="description" rows="3" 
                                          placeholder="Brief description of the state..."></textarea>
                            </div>
                        </div>
                        
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>
                            <strong>Quick Coordinates for South Sudan States:</strong><br>
                            Central Equatoria: 4.85, 31.60 | Eastern Equatoria: 4.41, 32.57 | Jonglei: 6.21, 31.56<br>
                            Lakes: 6.81, 29.68 | Northern Bahr el Ghazal: 8.77, 27.40 | Unity: 9.23, 29.83<br>
                            Upper Nile: 9.53, 31.65 | Warrap: 8.30, 28.00 | Western Bahr el Ghazal: 7.70, 27.98 | Western Equatoria: 4.57, 28.40
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add State</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit State Modal -->
    <div class="modal fade" id="editStateModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="state_id" id="editStateId">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit State</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">State Name</label>
                                <input type="text" class="form-control" name="name" id="editName" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">State Code</label>
                                <input type="text" class="form-control" name="code" id="editCode" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Capital City</label>
                                <input type="text" class="form-control" name="capital" id="editCapital">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Population</label>
                                <input type="number" class="form-control" name="population" id="editPopulation">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Area (km²)</label>
                                <input type="number" class="form-control" name="area_sqkm" id="editArea" step="0.01">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Zoom Level</label>
                                <select class="form-select" name="zoom_level" id="editZoom">
                                    <option value="6">6 - Far</option>
                                    <option value="7">7 - Default</option>
                                    <option value="8">8 - Close</option>
                                    <option value="9">9 - Very Close</option>
                                    <option value="10">10 - Detailed</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Center Latitude</label>
                                <input type="number" class="form-control" name="center_lat" id="editLat" step="0.0001">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Center Longitude</label>
                                <input type="number" class="form-control" name="center_lng" id="editLng" step="0.0001">
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Description</label>
                                <textarea class="form-control" name="description" id="editDescription" rows="3"></textarea>
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Boundary Coordinates (GeoJSON)</label>
                                <textarea class="form-control" name="boundary_coordinates" id="editBoundary" rows="3"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update State</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Google Maps JavaScript -->
    <script src="https://maps.googleapis.com/maps/api/js?key=YOUR_GOOGLE_MAPS_API_KEY&callback=initMap" async defer></script>
    
    <script>
        // Global variables
        let map;
        let markers = [];
        let activeInfoWindow = null;
        let bounds;
        
        // Initialize map
        function initMap() {
            // Set map center to South Sudan
            const southSudan = { lat: 7.5, lng: 30.0 };
            
            // Create map
            map = new google.maps.Map(document.getElementById('south-sudan-map'), {
                center: southSudan,
                zoom: 7,
                mapTypeId: 'hybrid',
                mapTypeControl: true,
                mapTypeControlOptions: {
                    style: google.maps.MapTypeControlStyle.DROPDOWN_MENU,
                    position: google.maps.ControlPosition.TOP_RIGHT
                },
                fullscreenControl: true,
                streetViewControl: false,
                zoomControl: true,
                zoomControlOptions: {
                    position: google.maps.ControlPosition.RIGHT_CENTER
                }
            });
            
            bounds = new google.maps.LatLngBounds();
            
            // Add markers for each state
            <?php foreach ($south_sudan_states as $state): ?>
            addStateMarker(
                <?php echo $state['lat']; ?>, 
                <?php echo $state['lng']; ?>, 
                '<?php echo $state['name']; ?>',
                '<?php echo $state['capital']; ?>',
                '<?php echo $state['code']; ?>'
            );
            <?php endforeach; ?>
            
            // Add a special marker for Juba (national capital)
            addCapitalMarker(4.85, 31.6, 'Juba', 'National Capital');
            
            // Fit map to show all markers
            map.fitBounds(bounds);
            
            // Update map status
            document.getElementById('map-status').innerHTML = 'Map loaded - South Sudan';
            
            // Add click listener to map
            map.addListener('click', function() {
                if (activeInfoWindow) {
                    activeInfoWindow.close();
                }
            });
        }
        
        // Add state marker
        function addStateMarker(lat, lng, name, capital, code) {
            const position = { lat: lat, lng: lng };
            
            // Create marker
            const marker = new google.maps.Marker({
                position: position,
                map: map,
                title: name,
                icon: {
                    url: 'http://maps.google.com/mapfiles/ms/icons/blue-dot.png',
                    scaledSize: new google.maps.Size(32, 32)
                },
                animation: google.maps.Animation.DROP
            });
            
            // Create info window content
            const contentString = `
                <div style="padding: 10px; max-width: 250px;">
                    <h5 style="margin: 0 0 5px 0; color: #0d6efd;">${name}</h5>
                    <p style="margin: 0 0 3px 0;"><strong>Capital:</strong> ${capital}</p>
                    <p style="margin: 0 0 3px 0;"><strong>Code:</strong> ${code}</p>
                    <p style="margin: 0 0 5px 0;"><strong>Coordinates:</strong> ${lat.toFixed(4)}, ${lng.toFixed(4)}</p>
                    <button onclick="flyToState(${lat}, ${lng}, 9)" style="background: #0d6efd; color: white; border: none; padding: 5px 10px; border-radius: 4px; cursor: pointer; width: 100%;">
                        Zoom to State
                    </button>
                </div>
            `;
            
            // Create info window
            const infoWindow = new google.maps.InfoWindow({
                content: contentString
            });
            
            // Add click listener to marker
            marker.addListener('click', function() {
                if (activeInfoWindow) {
                    activeInfoWindow.close();
                }
                infoWindow.open(map, marker);
                activeInfoWindow = infoWindow;
            });
            
            markers.push(marker);
            bounds.extend(position);
        }
        
        // Add capital marker (special style)
        function addCapitalMarker(lat, lng, name, description) {
            const position = { lat: lat, lng: lng };
            
            const marker = new google.maps.Marker({
                position: position,
                map: map,
                title: name,
                icon: {
                    url: 'http://maps.google.com/mapfiles/ms/icons/red-dot.png',
                    scaledSize: new google.maps.Size(40, 40)
                },
                animation: google.maps.Animation.DROP
            });
            
            const contentString = `
                <div style="padding: 10px; max-width: 250px;">
                    <h5 style="margin: 0 0 5px 0; color: #dc3545;">🏛️ ${name}</h5>
                    <p style="margin: 0 0 3px 0;"><strong>${description}</strong></p>
                    <p style="margin: 0 0 5px 0;">Coordinates: ${lat.toFixed(4)}, ${lng.toFixed(4)}</p>
                </div>
            `;
            
            const infoWindow = new google.maps.InfoWindow({
                content: contentString
            });
            
            marker.addListener('click', function() {
                if (activeInfoWindow) {
                    activeInfoWindow.close();
                }
                infoWindow.open(map, marker);
                activeInfoWindow = infoWindow;
            });
            
            markers.push(marker);
            bounds.extend(position);
        }
        
        // Fly to specific state
        function flyToState(lat, lng, zoom) {
            if (lat && lng) {
                map.setCenter({ lat: parseFloat(lat), lng: parseFloat(lng) });
                map.setZoom(parseInt(zoom) || 8);
                
                // Highlight the marker temporarily
                markers.forEach(marker => {
                    const markerPos = marker.getPosition();
                    if (markerPos.lat() === parseFloat(lat) && markerPos.lng() === parseFloat(lng)) {
                        marker.setAnimation(google.maps.Animation.BOUNCE);
                        setTimeout(() => marker.setAnimation(null), 1500);
                        
                        // Open info window
                        google.maps.event.trigger(marker, 'click');
                    }
                });
            }
        }
        
        // Center map on South Sudan
        function centerMap() {
            map.setCenter({ lat: 7.5, lng: 30.0 });
            map.setZoom(7);
            if (activeInfoWindow) {
                activeInfoWindow.close();
            }
        }
        
        // Show all states
        function showAllStates() {
            map.fitBounds(bounds);
            if (activeInfoWindow) {
                activeInfoWindow.close();
            }
        }
        
        // Highlight state on hover
        function highlightState(lat, lng) {
            if (!lat || !lng) return;
            
            markers.forEach(marker => {
                const markerPos = marker.getPosition();
                if (markerPos.lat() === parseFloat(lat) && markerPos.lng() === parseFloat(lng)) {
                    marker.setIcon({
                        url: 'http://maps.google.com/mapfiles/ms/icons/green-dot.png',
                        scaledSize: new google.maps.Size(40, 40)
                    });
                }
            });
        }
        
        // Unhighlight state
        function unhighlightState() {
            markers.forEach(marker => {
                marker.setIcon({
                    url: 'http://maps.google.com/mapfiles/ms/icons/blue-dot.png',
                    scaledSize: new google.maps.Size(32, 32)
                });
            });
        }
        
        // Edit state function
        function editState(state) {
            document.getElementById('editStateId').value = state.id;
            document.getElementById('editName').value = state.name || '';
            document.getElementById('editCode').value = state.code || '';
            document.getElementById('editCapital').value = state.capital || '';
            document.getElementById('editPopulation').value = state.population || '';
            document.getElementById('editArea').value = state.area_sqkm || '';
            document.getElementById('editZoom').value = state.zoom_level || 7;
            document.getElementById('editLat').value = state.center_lat || '';
            document.getElementById('editLng').value = state.center_lng || '';
            document.getElementById('editDescription').value = state.description || '';
            document.getElementById('editBoundary').value = state.boundary_coordinates || '';
        }
        
        // Search functionality
        document.getElementById('searchInput')?.addEventListener('keyup', function() {
            let searchText = this.value.toLowerCase();
            let cards = document.querySelectorAll('.state-card');
            
            cards.forEach(card => {
                let title = card.querySelector('.card-title').textContent.toLowerCase();
                let capital = card.querySelector('.text-muted').textContent.toLowerCase();
                
                if (title.includes(searchText) || capital.includes(searchText)) {
                    card.closest('.col-xl-4').style.display = '';
                } else {
                    card.closest('.col-xl-4').style.display = 'none';
                }
            });
        });
    </script>

    <style>
        .state-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
        }
        
        .state-card {
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .state-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1) !important;
        }
        
        .badge {
            font-size: 0.85rem;
        }
        
        .btn-group .btn {
            padding: 0.25rem 0.5rem;
        }
        
        #south-sudan-map {
            border-radius: 0 0 4px 4px;
        }
        
        .gm-style .gm-style-iw-c {
            padding: 12px;
        }
        
        .gm-style .gm-style-iw-t::after {
            background: linear-gradient(45deg, rgba(255,255,255,1) 50%, rgba(255,255,255,0) 51%, rgba(255,255,255,0) 100%);
        }
        
        @media (max-width: 768px) {
            #south-sudan-map {
                height: 350px !important;
            }
        }
    </style>

</body>
</html>