<?php
ini_set('display_errors', 1); 
error_reporting(E_ALL);

// parcels-map.php
include("head.php");
require_once 'db_connection.php';

// ============================================================================
// FILTERS AND DATA FETCHING
// ============================================================================

$message = '';
$messageType = '';

// Get filter parameters
$state_filter = $_GET['state_id'] ?? '';
$county_filter = $_GET['county_id'] ?? '';
$payam_filter = $_GET['payam_id'] ?? '';
$boma_filter = $_GET['boma_id'] ?? '';
$status_filter = $_GET['status'] ?? '';
$zoning_filter = $_GET['zoning_id'] ?? '';
$owner_filter = $_GET['owner_id'] ?? '';
$search_term = $_GET['search'] ?? '';

// Get all parcels with their related data
$sql = "
    SELECT 
        p.*,
        b.name as boma_name,
        b.code as boma_code,
        py.name as payam_name,
        c.name as county_name,
        s.name as state_name,
        s.code as state_code,
        z.zone_code,
        z.zone_name,
        (SELECT COUNT(*) FROM titles WHERE parcel_id = p.id) as title_count,
        (SELECT COUNT(*) FROM ownerships o 
         JOIN titles t ON o.title_id = t.id 
         WHERE t.parcel_id = p.id) as ownership_count,
        (SELECT GROUP_CONCAT(CONCAT(part.name, ' (', o.share_percentage, '%)') SEPARATOR ', ')
         FROM ownerships o 
         JOIN titles t ON o.title_id = t.id 
         JOIN parties part ON o.party_id = part.id
         WHERE t.parcel_id = p.id AND o.ownership_end_date IS NULL
         LIMIT 3) as current_owners,
        ST_AsGeoJSON(p.geometry) as geojson
    FROM parcels p
    LEFT JOIN bomas b ON p.boma_id = b.id
    LEFT JOIN payams py ON b.payam_id = py.id
    LEFT JOIN counties c ON py.county_id = c.id
    LEFT JOIN states s ON c.state_id = s.id
    LEFT JOIN zoning z ON p.current_zoning_id = z.id
    WHERE 1=1
";

$params = [];

// Apply filters
if (!empty($state_filter)) {
    $sql .= " AND s.id = ?";
    $params[] = $state_filter;
}

if (!empty($county_filter)) {
    $sql .= " AND c.id = ?";
    $params[] = $county_filter;
}

if (!empty($payam_filter)) {
    $sql .= " AND py.id = ?";
    $params[] = $payam_filter;
}

if (!empty($boma_filter)) {
    $sql .= " AND b.id = ?";
    $params[] = $boma_filter;
}

if (!empty($zoning_filter)) {
    $sql .= " AND p.current_zoning_id = ?";
    $params[] = $zoning_filter;
}

if (!empty($search_term)) {
    $sql .= " AND (p.parcel_number LIKE ? OR p.survey_number LIKE ? OR p.location_description LIKE ?)";
    $search = '%' . $search_term . '%';
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
}

$sql .= " ORDER BY p.created_at DESC";

$parcels = fetchAll($conn, $sql, $params);

// Get all states for dropdown
$states = fetchAll($conn, "SELECT id, name, code FROM states ORDER BY name");

// Get counties for dynamic dropdown
$counties = fetchAll($conn, "
    SELECT c.id, c.name, c.state_id, s.name as state_name 
    FROM counties c
    LEFT JOIN states s ON c.state_id = s.id
    ORDER BY s.name, c.name
");

// Get payams for dynamic dropdown
$payams = fetchAll($conn, "
    SELECT p.id, p.name, p.county_id, c.name as county_name 
    FROM payams p
    LEFT JOIN counties c ON p.county_id = c.id
    ORDER BY c.name, p.name
");

// Get bomas for dynamic dropdown
$bomas = fetchAll($conn, "
    SELECT b.id, b.name, b.payam_id, p.name as payam_name 
    FROM bomas b
    LEFT JOIN payams p ON b.payam_id = p.id
    ORDER BY p.name, b.name
");

// Get zoning types for filter
$zoning_types = fetchAll($conn, "SELECT id, zone_code, zone_name FROM zoning ORDER BY zone_code");

// Get summary statistics
$summary = fetchOne($conn, "
    SELECT 
        COUNT(*) as total_parcels,
        SUM(area) as total_area,
        AVG(area) as avg_area,
        COUNT(DISTINCT boma_id) as total_bomas_with_parcels,
        COUNT(DISTINCT current_zoning_id) as total_zoning_types,
        MAX(created_at) as last_parcel_date
    FROM parcels
");

// Get parcels without coordinates for warning
$parcels_without_geo = fetchOne($conn, "
    SELECT COUNT(*) as count 
    FROM parcels 
    WHERE geometry IS NULL
");

// Encode parcels data for JavaScript
$parcels_json = json_encode(array_map(function($parcel) {
    return [
        'id' => $parcel['id'],
        'parcel_number' => $parcel['parcel_number'],
        'survey_number' => $parcel['survey_number'],
        'area' => $parcel['area'],
        'location_description' => $parcel['location_description'],
        'boma_name' => $parcel['boma_name'],
        'payam_name' => $parcel['payam_name'],
        'county_name' => $parcel['county_name'],
        'state_name' => $parcel['state_name'],
        'zone_code' => $parcel['zone_code'],
        'zone_name' => $parcel['zone_name'],
        'title_count' => $parcel['title_count'],
        'ownership_count' => $parcel['ownership_count'],
        'current_owners' => $parcel['current_owners'],
        'geojson' => $parcel['geojson'],
        'created_at' => $parcel['created_at']
    ];
}, $parcels));
?>

<!-- Google Maps API with Geometry library -->
<script src="https://maps.googleapis.com/maps/api/js?key=YOUR_API_KEY&libraries=geometry,places&callback=initMap" async defer></script>

<body data-page="parcels-map" class="parcels-map-page">
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
                                    <li class="breadcrumb-item"><a href="parcels.php">Parcels</a></li>
                                    <li class="breadcrumb-item active">Map View</li>
                                </ol>
                            </nav>
                            <h1 class="h3 mb-0">Parcel Map Viewer</h1>
                            <p class="text-muted mb-0">Visualize and explore all land parcels in South Sudan</p>
                        </div>
                        <div class="btn-group">
                            <a href="parcels.php" class="btn btn-outline-secondary">
                                <i class="bi bi-table me-2"></i>Table View
                            </a>
                            <a href="parcels-create.php" class="btn btn-primary">
                                <i class="bi bi-plus-circle me-2"></i>Add New Parcel
                            </a>
                        </div>
                    </div>

                    <!-- Alert Messages -->
                    <?php if ($message): ?>
                    <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show mb-4" role="alert">
                        <?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    <?php endif; ?>

                    <?php if ($parcels_without_geo['count'] > 0): ?>
                    <div class="alert alert-warning alert-dismissible fade show mb-4" role="alert">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <strong><?php echo $parcels_without_geo['count']; ?> parcel(s)</strong> do not have geometry data and will not appear on the map.
                        <a href="parcels.php?filter=no_geometry" class="alert-link">View them here</a>.
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
                                                <i class="bi bi-pin-map-fill text-primary fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Total Parcels</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['total_parcels']); ?></h3>
                                            <small class="text-muted"><?php echo count($parcels); ?> displayed</small>
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
                                                <i class="bi bi-rulers text-success fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Total Area</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo $summary['total_area'] ? number_format($summary['total_area'], 2) : '0'; ?> m²</h3>
                                            <small class="text-muted">Avg: <?php echo $summary['avg_area'] ? number_format($summary['avg_area'], 2) : '0'; ?> m²</small>
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
                                            <h6 class="text-muted mb-1">Bomas with Parcels</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['total_bomas_with_parcels']); ?></h3>
                                            <small class="text-muted">Administrative units</small>
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
                                                <i class="bi bi-calendar-check text-warning fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Last Added</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo $summary['last_parcel_date'] ? date('d/m/Y', strtotime($summary['last_parcel_date'])) : 'N/A'; ?></h3>
                                            <small class="text-muted">Most recent parcel</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Filter Bar -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-white py-3">
                            <h5 class="card-title mb-0 fw-bold">
                                <i class="bi bi-funnel me-2"></i>Filter Parcels
                            </h5>
                        </div>
                        <div class="card-body">
                            <form method="GET" class="row g-3" id="filterForm">
                                <!-- Hierarchical Location Filters -->
                                <div class="col-md-2">
                                    <label class="form-label">State</label>
                                    <select class="form-select" name="state_id" id="filter_state">
                                        <option value="">All States</option>
                                        <?php foreach ($states as $state): ?>
                                        <option value="<?php echo $state['id']; ?>" <?php echo $state_filter == $state['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($state['name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-2">
                                    <label class="form-label">County</label>
                                    <select class="form-select" name="county_id" id="filter_county">
                                        <option value="">All Counties</option>
                                    </select>
                                </div>
                                
                                <div class="col-md-2">
                                    <label class="form-label">Payam</label>
                                    <select class="form-select" name="payam_id" id="filter_payam">
                                        <option value="">All Payams</option>
                                    </select>
                                </div>
                                
                                <div class="col-md-2">
                                    <label class="form-label">Boma</label>
                                    <select class="form-select" name="boma_id" id="filter_boma">
                                        <option value="">All Bomas</option>
                                    </select>
                                </div>
                                
                                <div class="col-md-2">
                                    <label class="form-label">Zoning</label>
                                    <select class="form-select" name="zoning_id">
                                        <option value="">All Zones</option>
                                        <?php foreach ($zoning_types as $zone): ?>
                                        <option value="<?php echo $zone['id']; ?>" <?php echo $zoning_filter == $zone['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($zone['zone_code']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-2">
                                    <label class="form-label">Search</label>
                                    <input type="text" class="form-control" name="search" 
                                           value="<?php echo htmlspecialchars($search_term); ?>"
                                           placeholder="Parcel #, Survey #">
                                </div>
                                
                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary me-2">
                                        <i class="bi bi-search me-2"></i>Apply Filters
                                    </button>
                                    <a href="parcels-map.php" class="btn btn-secondary">
                                        <i class="bi bi-x-circle me-2"></i>Clear Filters
                                    </a>
                                    <button type="button" class="btn btn-outline-success float-end" onclick="fitMapToAllParcels()">
                                        <i class="bi bi-arrows-fullscreen me-2"></i>Fit Map to All Parcels
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Main Map Container -->
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                            <div>
                                <h5 class="card-title mb-0 fw-bold">
                                    <i class="bi bi-map me-2 text-primary"></i>
                                    Parcel Map - South Sudan
                                </h5>
                                <small class="text-muted">
                                    Showing <?php echo count($parcels); ?> parcels 
                                    <?php if ($state_filter || $county_filter || $payam_filter || $boma_filter): ?>
                                        with applied filters
                                    <?php endif; ?>
                                </small>
                            </div>
                            <div class="btn-group btn-group-sm">
                                <button class="btn btn-outline-primary" onclick="toggleLayer('satellite')" id="satelliteBtn">
                                    <i class="bi bi-satellite"></i> Satellite
                                </button>
                                <button class="btn btn-outline-primary" onclick="toggleLayer('roadmap')" id="roadmapBtn">
                                    <i class="bi bi-map"></i> Roadmap
                                </button>
                                <button class="btn btn-outline-primary" onclick="toggleLayer('terrain')" id="terrainBtn">
                                    <i class="bi bi-terrain"></i> Terrain
                                </button>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <div id="parcels-map" style="height: 600px; width: 100%;"></div>
                        </div>
                        <div class="card-footer bg-white py-2">
                            <div class="row align-items-center">
                                <div class="col-md-6">
                                    <small class="text-muted">
                                        <i class="bi bi-info-circle me-1"></i>
                                        Click on any parcel to view details. Use the drawing tools to measure distances.
                                    </small>
                                </div>
                                <div class="col-md-6 text-end">
                                    <span class="badge bg-primary me-2" id="parcelCount"><?php echo count($parcels); ?> parcels</span>
                                    <span class="badge bg-success" id="visibleCount">0 visible</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Legend and Stats Panel -->
                    <div class="row mt-4">
                        <div class="col-md-4">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h6 class="card-title mb-0 fw-bold">
                                        <i class="bi bi-palette me-2"></i>Map Legend
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div class="legend-item d-flex align-items-center mb-2">
                                        <div style="width: 30px; height: 20px; background-color: rgba(33, 150, 243, 0.3); border: 2px solid #0D47A1; margin-right: 10px;"></div>
                                        <span>Active Parcel</span>
                                    </div>
                                    <div class="legend-item d-flex align-items-center mb-2">
                                        <div style="width: 30px; height: 20px; background-color: rgba(76, 175, 80, 0.3); border: 2px solid #1B5E20; margin-right: 10px;"></div>
                                        <span>With Title</span>
                                    </div>
                                    <div class="legend-item d-flex align-items-center mb-2">
                                        <div style="width: 30px; height: 20px; background-color: rgba(255, 152, 0, 0.3); border: 2px solid #E65100; margin-right: 10px;"></div>
                                        <span>Multiple Owners</span>
                                    </div>
                                    <div class="legend-item d-flex align-items-center">
                                        <div style="width: 30px; height: 20px; background-color: rgba(244, 67, 54, 0.3); border: 2px solid #B71C1C; margin-right: 10px;"></div>
                                        <span>Disputed</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-8">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h6 class="card-title mb-0 fw-bold">
                                        <i class="bi bi-pie-chart me-2"></i>Statistics by Zoning
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <canvas id="zoningChart" style="height: 200px;"></canvas>
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

    <!-- Parcel Details Modal -->
    <div class="modal fade" id="parcelDetailModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Parcel Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6 class="fw-bold mb-3">Parcel Information</h6>
                            <table class="table table-sm">
                                <tr>
                                    <th>Parcel Number:</th>
                                    <td id="detail_parcel_number"></td>
                                </tr>
                                <tr>
                                    <th>Survey Number:</th>
                                    <td id="detail_survey_number"></td>
                                </tr>
                                <tr>
                                    <th>Area:</th>
                                    <td id="detail_area"></td>
                                </tr>
                                <tr>
                                    <th>Zoning:</th>
                                    <td id="detail_zoning"></td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <h6 class="fw-bold mb-3">Location</h6>
                            <table class="table table-sm">
                                <tr>
                                    <th>State:</th>
                                    <td id="detail_state"></td>
                                </tr>
                                <tr>
                                    <th>County:</th>
                                    <td id="detail_county"></td>
                                </tr>
                                <tr>
                                    <th>Payam:</th>
                                    <td id="detail_payam"></td>
                                </tr>
                                <tr>
                                    <th>Boma:</th>
                                    <td id="detail_boma"></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    
                    <h6 class="fw-bold mb-2">Current Owners</h6>
                    <p id="detail_owners" class="mb-3"></p>
                    
                    <h6 class="fw-bold mb-2">Description</h6>
                    <p id="detail_description" class="mb-3"></p>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <h6 class="fw-bold mb-2">Titles</h6>
                            <p id="detail_titles"></p>
                        </div>
                        <div class="col-md-6">
                            <h6 class="fw-bold mb-2">Created</h6>
                            <p id="detail_created"></p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <a href="#" id="detail_view_link" class="btn btn-primary">View Full Details</a>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Measurement Tool Modal -->
    <div class="modal fade" id="measurementModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Measurement Tool</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Distance</label>
                        <p id="measurement_distance" class="form-control-static">0 meters</p>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Area</label>
                        <p id="measurement_area" class="form-control-static">0 square meters</p>
                    </div>
                    <div id="measurement_instructions" class="alert alert-info mb-0">
                        Click on the map to start measuring. Double-click to finish.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="clearMeasurement()">Clear</button>
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Global variables
        let map;
        let markers = [];
        let parcelPolygons = [];
        let infoWindow;
        let drawingManager;
        let measurementLine = null;
        let measurementMarkers = [];
        let measurementPath = [];
        
        // Data from PHP
        const parcels = <?php echo $parcels_json; ?>;
        const counties = <?php echo json_encode($counties); ?>;
        const payams = <?php echo json_encode($payams); ?>;
        const bomas = <?php echo json_encode($bomas); ?>;
        
        // Filter values
        const currentFilters = {
            state: '<?php echo $state_filter; ?>',
            county: '<?php echo $county_filter; ?>',
            payam: '<?php echo $payam_filter; ?>',
            boma: '<?php echo $boma_filter; ?>'
        };

        // South Sudan bounds
        const SOUTH_SUDAN_BOUNDS = {
            north: 12.0,
            south: 3.5,
            west: 23.5,
            east: 36.0
        };
        
        const SOUTH_SUDAN_CENTER = { lat: 7.5, lng: 30.0 };

        // Initialize map
        function initMap() {
            // Create map
            map = new google.maps.Map(document.getElementById('parcels-map'), {
                center: SOUTH_SUDAN_CENTER,
                zoom: 6,
                restriction: {
                    latLngBounds: SOUTH_SUDAN_BOUNDS,
                    strictBounds: false
                },
                mapTypeId: google.maps.MapTypeId.SATELLITE,
                mapTypeControl: false,
                streetViewControl: false,
                fullscreenControl: true,
                zoomControl: true,
                zoomControlOptions: {
                    position: google.maps.ControlPosition.RIGHT_CENTER
                },
                scaleControl: true
            });

            // Initialize info window
            infoWindow = new google.maps.InfoWindow();

            // Load parcels
            loadParcels();

            // Initialize drawing tools for measurement
            initMeasurementTools();

            // Setup layer toggle buttons
            document.getElementById('satelliteBtn').classList.add('active');
            
            // Initialize hierarchical filters
            initHierarchicalFilters();
            
            // Create statistics chart
            createZoningChart();
            
            // Fit map to show all parcels
            setTimeout(() => {
                fitMapToAllParcels();
            }, 1000);
        }

        // Load parcels onto map
        function loadParcels() {
            // Clear existing polygons
            parcelPolygons.forEach(polygon => polygon.setMap(null));
            parcelPolygons = [];
            
            let visibleCount = 0;
            
            parcels.forEach(parcel => {
                if (parcel.geojson) {
                    try {
                        const geojson = JSON.parse(parcel.geojson);
                        const color = getParcelColor(parcel);
                        
                        if (geojson.type === 'Polygon') {
                            const paths = geojson.coordinates[0].map(coord => {
                                return { lat: coord[1], lng: coord[0] };
                            });
                            
                            const polygon = new google.maps.Polygon({
                                paths: paths,
                                fillColor: color.fill,
                                fillOpacity: 0.3,
                                strokeColor: color.stroke,
                                strokeWeight: 2,
                                map: map,
                                parcel: parcel,
                                zIndex: 1
                            });
                            
                            // Add click listener
                            polygon.addListener('click', () => {
                                showParcelDetails(parcel);
                            });
                            
                            // Add hover effect
                            polygon.addListener('mouseover', () => {
                                polygon.setOptions({
                                    fillOpacity: 0.5,
                                    strokeWeight: 3
                                });
                            });
                            
                            polygon.addListener('mouseout', () => {
                                polygon.setOptions({
                                    fillOpacity: 0.3,
                                    strokeWeight: 2
                                });
                            });
                            
                            parcelPolygons.push(polygon);
                            visibleCount++;
                        }
                    } catch (e) {
                        console.error('Error parsing GeoJSON for parcel', parcel.parcel_number, e);
                    }
                }
            });
            
            document.getElementById('visibleCount').textContent = visibleCount + ' visible';
        }

        // Get color for parcel based on properties
        function getParcelColor(parcel) {
            if (parcel.title_count > 0) {
                if (parcel.ownership_count > 1) {
                    return { fill: '#FF9800', stroke: '#E65100' }; // Orange for multiple owners
                }
                return { fill: '#4CAF50', stroke: '#1B5E20' }; // Green for titled
            }
            return { fill: '#2196F3', stroke: '#0D47A1' }; // Blue for active
        }

        // Show parcel details in modal
        function showParcelDetails(parcel) {
            document.getElementById('detail_parcel_number').textContent = parcel.parcel_number || 'N/A';
            document.getElementById('detail_survey_number').textContent = parcel.survey_number || 'N/A';
            document.getElementById('detail_area').textContent = parcel.area ? parcel.area + ' m²' : 'N/A';
            document.getElementById('detail_zoning').textContent = parcel.zone_name || 'Not specified';
            
            document.getElementById('detail_state').textContent = parcel.state_name || 'N/A';
            document.getElementById('detail_county').textContent = parcel.county_name || 'N/A';
            document.getElementById('detail_payam').textContent = parcel.payam_name || 'N/A';
            document.getElementById('detail_boma').textContent = parcel.boma_name || 'N/A';
            
            document.getElementById('detail_owners').textContent = parcel.current_owners || 'No current owners';
            document.getElementById('detail_description').textContent = parcel.location_description || 'No description';
            
            document.getElementById('detail_titles').textContent = parcel.title_count + ' title(s)';
            document.getElementById('detail_created').textContent = parcel.created_at ? new Date(parcel.created_at).toLocaleDateString() : 'N/A';
            
            document.getElementById('detail_view_link').href = 'parcels-view.php?id=' + parcel.id;
            
            new bootstrap.Modal(document.getElementById('parcelDetailModal')).show();
        }

        // Initialize measurement tools
        function initMeasurementTools() {
            // Add measurement control to map
            const measurementDiv = document.createElement('div');
            measurementDiv.className = 'map-control';
            measurementDiv.innerHTML = `
                <button class="btn btn-sm btn-light shadow-sm" onclick="startMeasurement()">
                    <i class="bi bi-rulers"></i> Measure
                </button>
            `;
            
            map.controls[google.maps.ControlPosition.TOP_RIGHT].push(measurementDiv);
        }

        // Start measurement mode
        function startMeasurement() {
            // Show measurement modal
            const modal = new bootstrap.Modal(document.getElementById('measurementModal'));
            modal.show();
            
            // Clear previous measurement
            clearMeasurement();
            
            // Set map click listener
            map.addListener('click', addMeasurementPoint);
        }

        // Add point to measurement
        function addMeasurementPoint(event) {
            const latLng = event.latLng;
            
            // Add marker
            const marker = new google.maps.Marker({
                position: latLng,
                map: map,
                icon: {
                    path: google.maps.SymbolPath.CIRCLE,
                    scale: 5,
                    fillColor: '#2196F3',
                    fillOpacity: 1,
                    strokeColor: '#FFFFFF',
                    strokeWeight: 2
                }
            });
            
            measurementMarkers.push(marker);
            measurementPath.push(latLng);
            
            // Draw line
            if (measurementLine) {
                measurementLine.setMap(null);
            }
            
            if (measurementPath.length > 1) {
                measurementLine = new google.maps.Polyline({
                    path: measurementPath,
                    geodesic: true,
                    strokeColor: '#2196F3',
                    strokeOpacity: 1.0,
                    strokeWeight: 3,
                    map: map
                });
                
                // Calculate distance
                let distance = 0;
                for (let i = 0; i < measurementPath.length - 1; i++) {
                    distance += google.maps.geometry.spherical.computeDistanceBetween(
                        measurementPath[i], measurementPath[i + 1]
                    );
                }
                
                document.getElementById('measurement_distance').textContent = 
                    distance.toFixed(2) + ' meters (' + (distance/1000).toFixed(2) + ' km)';
                
                // Calculate area if polygon is closed (3+ points and last point near first)
                if (measurementPath.length >= 3) {
                    const first = measurementPath[0];
                    const last = measurementPath[measurementPath.length - 1];
                    const distToFirst = google.maps.geometry.spherical.computeDistanceBetween(last, first);
                    
                    if (distToFirst < 10) { // If last point is close to first (within 10 meters)
                        const area = google.maps.geometry.spherical.computeArea(measurementPath);
                        document.getElementById('measurement_area').textContent = 
                            area.toFixed(2) + ' m² (' + (area/10000).toFixed(2) + ' hectares)';
                        
                        // Draw closed polygon
                        if (measurementLine) {
                            measurementLine.setMap(null);
                        }
                        
                        const polygon = new google.maps.Polygon({
                            paths: measurementPath,
                            strokeColor: '#2196F3',
                            strokeOpacity: 0.8,
                            strokeWeight: 3,
                            fillColor: '#2196F3',
                            fillOpacity: 0.2,
                            map: map
                        });
                        
                        measurementLine = polygon;
                    }
                }
            }
        }

        // Clear measurement
        function clearMeasurement() {
            if (measurementLine) {
                measurementLine.setMap(null);
                measurementLine = null;
            }
            
            measurementMarkers.forEach(marker => marker.setMap(null));
            measurementMarkers = [];
            measurementPath = [];
            
            document.getElementById('measurement_distance').textContent = '0 meters';
            document.getElementById('measurement_area').textContent = '0 square meters';
        }

        // Toggle map layer
        function toggleLayer(layer) {
            map.setMapTypeId(layer);
            
            // Update button states
            document.getElementById('satelliteBtn').classList.remove('active');
            document.getElementById('roadmapBtn').classList.remove('active');
            document.getElementById('terrainBtn').classList.remove('active');
            
            if (layer === 'satellite') {
                document.getElementById('satelliteBtn').classList.add('active');
            } else if (layer === 'roadmap') {
                document.getElementById('roadmapBtn').classList.add('active');
            } else if (layer === 'terrain') {
                document.getElementById('terrainBtn').classList.add('active');
            }
        }

        // Fit map to show all parcels
        function fitMapToAllParcels() {
            if (parcelPolygons.length === 0) {
                map.setCenter(SOUTH_SUDAN_CENTER);
                map.setZoom(6);
                return;
            }
            
            const bounds = new google.maps.LatLngBounds();
            
            parcelPolygons.forEach(polygon => {
                polygon.getPath().forEach(path => {
                    bounds.extend(path);
                });
            });
            
            map.fitBounds(bounds);
        }

        // Initialize hierarchical filters
        function initHierarchicalFilters() {
            const stateSelect = document.getElementById('filter_state');
            const countySelect = document.getElementById('filter_county');
            const payamSelect = document.getElementById('filter_payam');
            const bomaSelect = document.getElementById('filter_boma');
            
            // State change
            stateSelect.addEventListener('change', function() {
                const stateId = this.value;
                
                // Reset and populate counties
                countySelect.innerHTML = '<option value="">All Counties</option>';
                payamSelect.innerHTML = '<option value="">All Payams</option>';
                bomaSelect.innerHTML = '<option value="">All Bomas</option>';
                
                if (stateId) {
                    const filteredCounties = counties.filter(c => c.state_id == stateId);
                    filteredCounties.forEach(county => {
                        const option = document.createElement('option');
                        option.value = county.id;
                        option.textContent = county.name;
                        if (currentFilters.county == county.id) option.selected = true;
                        countySelect.appendChild(option);
                    });
                    countySelect.disabled = false;
                } else {
                    countySelect.disabled = true;
                    payamSelect.disabled = true;
                    bomaSelect.disabled = true;
                }
            });
            
            // County change
            countySelect.addEventListener('change', function() {
                const countyId = this.value;
                
                payamSelect.innerHTML = '<option value="">All Payams</option>';
                bomaSelect.innerHTML = '<option value="">All Bomas</option>';
                
                if (countyId) {
                    const filteredPayams = payams.filter(p => p.county_id == countyId);
                    filteredPayams.forEach(payam => {
                        const option = document.createElement('option');
                        option.value = payam.id;
                        option.textContent = payam.name;
                        if (currentFilters.payam == payam.id) option.selected = true;
                        payamSelect.appendChild(option);
                    });
                    payamSelect.disabled = false;
                } else {
                    payamSelect.disabled = true;
                    bomaSelect.disabled = true;
                }
            });
            
            // Payam change
            payamSelect.addEventListener('change', function() {
                const payamId = this.value;
                
                bomaSelect.innerHTML = '<option value="">All Bomas</option>';
                
                if (payamId) {
                    const filteredBomas = bomas.filter(b => b.payam_id == payamId);
                    filteredBomas.forEach(boma => {
                        const option = document.createElement('option');
                        option.value = boma.id;
                        option.textContent = boma.name;
                        if (currentFilters.boma == boma.id) option.selected = true;
                        bomaSelect.appendChild(option);
                    });
                    bomaSelect.disabled = false;
                } else {
                    bomaSelect.disabled = true;
                }
            });
            
            // Trigger initial state
            if (currentFilters.state) {
                stateSelect.value = currentFilters.state;
                stateSelect.dispatchEvent(new Event('change'));
            }
        }

        // Create zoning statistics chart
        function createZoningChart() {
            const zoningCounts = {};
            
            parcels.forEach(parcel => {
                const zone = parcel.zone_name || 'Unzoned';
                zoningCounts[zone] = (zoningCounts[zone] || 0) + 1;
            });
            
            const ctx = document.getElementById('zoningChart').getContext('2d');
            new Chart(ctx, {
                type: 'pie',
                data: {
                    labels: Object.keys(zoningCounts),
                    datasets: [{
                        data: Object.values(zoningCounts),
                        backgroundColor: [
                            '#2196F3',
                            '#4CAF50',
                            '#FF9800',
                            '#9C27B0',
                            '#F44336',
                            '#009688',
                            '#795548'
                        ]
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right',
                            labels: {
                                boxWidth: 12,
                                font: {
                                    size: 11
                                }
                            }
                        }
                    }
                }
            });
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
        
        #parcels-map {
            border-radius: 0 0 0.375rem 0.375rem;
        }
        
        .map-control {
            margin: 10px;
        }
        
        .map-control button {
            border-radius: 4px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
            border: none;
            background: white;
            padding: 8px 12px;
            font-size: 14px;
            cursor: pointer;
        }
        
        .map-control button:hover {
            background: #f8f9fa;
        }
        
        .map-control button i {
            margin-right: 5px;
        }
        
        .btn-group .btn.active {
            background-color: var(--bs-primary);
            color: white;
            border-color: var(--bs-primary);
        }
        
        .legend-item {
            font-size: 14px;
        }
        
        .table-sm th {
            width: 40%;
            color: #6c757d;
            font-weight: 500;
        }
        
        .gm-style-iw-c {
            padding: 12px !important;
        }
        
        .gm-style-iw-d {
            overflow: hidden !important;
        }
        
        #visibleCount {
            transition: all 0.3s;
        }
        
        @media (max-width: 768px) {
            #parcels-map {
                height: 400px !important;
            }
        }
        
        /* Loading animation */
        .parcels-map-page .card {
            transition: all 0.3s;
        }
        
        .parcels-map-page .card:hover {
            box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.15) !important;
        }
    </style>

</body>
</html>