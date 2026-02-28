<?php
ini_set('display_errors', 1); 
error_reporting(E_ALL);

// counties.php
include("head.php");
require_once 'db_connection.php';

// ============================================================================
// HANDLE ACTIONS
// ============================================================================

$message = '';
$messageType = '';

// Delete county
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $countyId = $_GET['delete'];
    
    try {
        // Check if county has related records
        $related = fetchOne($conn, "
            SELECT 
                (SELECT COUNT(*) FROM payams WHERE county_id = ?) as payams,
                (SELECT COUNT(*) FROM bomas b WHERE b.payam_id IN (SELECT id FROM payams WHERE county_id = ?)) as bomas,
                (SELECT COUNT(*) FROM parcels p WHERE p.boma_id IN (SELECT id FROM bomas WHERE payam_id IN (SELECT id FROM payams WHERE county_id = ?))) as parcels
        ", [$countyId, $countyId, $countyId]);
        
        if ($related['payams'] > 0 || $related['bomas'] > 0 || $related['parcels'] > 0) {
            $message = "Cannot delete county with existing payams, bomas, or parcels.";
            $messageType = "warning";
        } else {
            executeQuery($conn, "DELETE FROM counties WHERE id = ?", [$countyId]);
            $message = "County deleted successfully";
            $messageType = "success";
        }
    } catch (Exception $e) {
        $message = "Error deleting county: " . $e->getMessage();
        $messageType = "danger";
    }
}

// Add/Edit county
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        
        // Add new county
        if ($_POST['action'] === 'add') {
            $state_id = $_POST['state_id'];
            $name = $_POST['name'];
            $code = $_POST['code'] ?? null;
            $latitude = !empty($_POST['latitude']) ? $_POST['latitude'] : null;
            $longitude = !empty($_POST['longitude']) ? $_POST['longitude'] : null;
            $boundary_coordinates = !empty($_POST['boundary_coordinates']) ? $_POST['boundary_coordinates'] : null;
            
            try {
                // Check for duplicate county code
                if ($code) {
                    $exists = fetchOne($conn, "SELECT id FROM counties WHERE code = ?", [$code]);
                    if ($exists) {
                        throw new Exception("County code already exists in the system");
                    }
                }
                
                executeQuery($conn, "
                    INSERT INTO counties (
                        state_id, name, code, latitude, longitude, boundary_coordinates
                    ) VALUES (?, ?, ?, ?, ?, ?)
                ", [$state_id, $name, $code, $latitude, $longitude, $boundary_coordinates]);
                
                $message = "County added successfully";
                $messageType = "success";
            } catch (Exception $e) {
                $message = "Error adding county: " . $e->getMessage();
                $messageType = "danger";
            }
        }
        
        // Edit county
        if ($_POST['action'] === 'edit') {
            $county_id = $_POST['county_id'];
            $state_id = $_POST['state_id'];
            $name = $_POST['name'];
            $code = $_POST['code'] ?? null;
            $latitude = !empty($_POST['latitude']) ? $_POST['latitude'] : null;
            $longitude = !empty($_POST['longitude']) ? $_POST['longitude'] : null;
            $boundary_coordinates = !empty($_POST['boundary_coordinates']) ? $_POST['boundary_coordinates'] : null;
            
            try {
                // Check for duplicate county code (excluding current county)
                if ($code) {
                    $exists = fetchOne($conn, "SELECT id FROM counties WHERE code = ? AND id != ?", [$code, $county_id]);
                    if ($exists) {
                        throw new Exception("County code already exists in the system");
                    }
                }
                
                executeQuery($conn, "
                    UPDATE counties SET 
                        state_id = ?, name = ?, code = ?,
                        latitude = ?, longitude = ?, boundary_coordinates = ?
                    WHERE id = ?
                ", [$state_id, $name, $code, $latitude, $longitude, $boundary_coordinates, $county_id]);
                
                $message = "County updated successfully";
                $messageType = "success";
            } catch (Exception $e) {
                $message = "Error updating county: " . $e->getMessage();
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
    $where_conditions[] = "c.state_id = ?";
    $params[] = $_GET['state_id'];
}

// Search
if (!empty($_GET['search'])) {
    $search = '%' . $_GET['search'] . '%';
    $where_conditions[] = "(c.name LIKE ? OR c.code LIKE ? OR s.name LIKE ?)";
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
}

// Has coordinates filter
if (!empty($_GET['has_coordinates'])) {
    if ($_GET['has_coordinates'] === 'yes') {
        $where_conditions[] = "(c.latitude IS NOT NULL AND c.longitude IS NOT NULL)";
    } elseif ($_GET['has_coordinates'] === 'no') {
        $where_conditions[] = "(c.latitude IS NULL OR c.longitude IS NULL)";
    }
}

$where_clause = empty($where_conditions) ? "" : "WHERE " . implode(" AND ", $where_conditions);

// Get total count for pagination
$count_sql = "
    SELECT COUNT(*) as total 
    FROM counties c
    LEFT JOIN states s ON c.state_id = s.id
    $where_clause
";
$total_result = executeQuery($conn, $count_sql, $params);
$total_rows = $total_result->fetch()['total'];
$total_pages = ceil($total_rows / $limit);

// Get counties with statistics
$sql = "
    SELECT 
        c.*,
        s.name as state_name,
        s.code as state_code,
        (SELECT COUNT(*) FROM payams WHERE county_id = c.id) as total_payams,
        (SELECT COUNT(*) FROM bomas b WHERE b.payam_id IN (SELECT id FROM payams WHERE county_id = c.id)) as total_bomas,
        (SELECT COUNT(*) FROM parcels p WHERE p.boma_id IN (SELECT id FROM bomas WHERE payam_id IN (SELECT id FROM payams WHERE county_id = c.id))) as total_parcels,
        (SELECT COUNT(*) FROM payams WHERE county_id = c.id AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) as recent_payams
    FROM counties c
    LEFT JOIN states s ON c.state_id = s.id
    $where_clause
    ORDER BY s.name, c.name
    LIMIT ? OFFSET ?
";

$params[] = $limit;
$params[] = $offset;

$counties = fetchAll($conn, $sql, $params);

// Get all states for dropdown
$states = fetchAll($conn, "SELECT id, name, code FROM states ORDER BY name");

// Get summary statistics
$summary = fetchOne($conn, "
    SELECT 
        COUNT(*) as total_counties,
        COUNT(DISTINCT state_id) as total_states_with_counties,
        SUM(CASE WHEN latitude IS NOT NULL AND longitude IS NOT NULL THEN 1 ELSE 0 END) as counties_with_coords,
        SUM(CASE WHEN boundary_coordinates IS NOT NULL THEN 1 ELSE 0 END) as counties_with_boundaries,
        COUNT(DISTINCT DATE(created_at)) as active_days
    FROM counties
");

// Get counties with most parcels
$top_counties = fetchAll($conn, "
    SELECT 
        c.name,
        c.id,
        COUNT(DISTINCT p.id) as parcel_count
    FROM counties c
    LEFT JOIN payams py ON c.id = py.county_id
    LEFT JOIN bomas b ON py.id = b.payam_id
    LEFT JOIN parcels p ON b.id = p.boma_id
    GROUP BY c.id, c.name
    HAVING parcel_count > 0
    ORDER BY parcel_count DESC
    LIMIT 5
");

// Get recent activity
$recent_activity = fetchAll($conn, "
    SELECT 
        DATE(c.created_at) as date,
        COUNT(*) as count
    FROM counties c
    WHERE c.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY DATE(c.created_at)
    ORDER BY date DESC
    LIMIT 10
");
?>

<!-- Google Maps API -->
<script src="https://maps.googleapis.com/maps/api/js?key=YOUR_API_KEY&callback=initMap" async defer></script>

<body data-page="counties" class="counties-page">
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
                            <h1 class="h3 mb-0">Counties Management</h1>
                            <p class="text-muted mb-0">Manage South Sudan counties and their geographical data</p>
                        </div>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCountyModal">
                            <i class="bi bi-plus-circle me-2"></i>Add New County
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
                                                <i class="bi bi-map text-primary fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Total Counties</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['total_counties']); ?></h3>
                                            <small class="text-muted">Across <?php echo $summary['total_states_with_counties']; ?> states</small>
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
                                                <i class="bi bi-geo-alt text-success fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">With Coordinates</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['counties_with_coords']); ?></h3>
                                            <small class="text-muted"><?php echo $summary['total_counties'] > 0 ? round(($summary['counties_with_coords'] / $summary['total_counties']) * 100) : 0; ?>% mapped</small>
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
                                                <i class="bi bi-bounding-box text-info fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">With Boundaries</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['counties_with_boundaries']); ?></h3>
                                            <small class="text-muted">Defined areas</small>
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
                                                <i class="bi bi-building text-warning fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Active Days</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo $summary['active_days']; ?></h3>
                                            <small class="text-muted">Days with registrations</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Map View -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0 fw-bold">South Sudan Counties Map</h5>
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
                            <div id="counties-map" style="height: 500px; width: 100%;"></div>
                        </div>
                    </div>

                    <!-- Top Counties by Parcels -->
                    <?php if (!empty($top_counties)): ?>
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h5 class="card-title mb-0 fw-bold">Top Counties by Parcel Count</h5>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <?php foreach ($top_counties as $county): ?>
                                        <div class="col-md-<?php echo 12 / count($top_counties); ?> mb-2">
                                            <div class="border rounded p-3 text-center">
                                                <h6 class="text-muted mb-1"><?php echo htmlspecialchars($county['name']); ?></h6>
                                                <span class="h4 mb-0 fw-bold"><?php echo number_format($county['parcel_count']); ?></span>
                                                <br><small class="text-muted">Parcels</small>
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
                                    <select class="form-select" name="state_id">
                                        <option value="">All States</option>
                                        <?php foreach ($states as $state): ?>
                                        <option value="<?php echo $state['id']; ?>" <?php echo ($_GET['state_id'] ?? '') == $state['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($state['name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
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
                                           placeholder="Search by county name, code, or state..."
                                           value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                                </div>
                                <div class="col-md-2 d-flex align-items-end">
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="bi bi-search me-2"></i>Filter
                                    </button>
                                </div>
                                <div class="col-12">
                                    <a href="counties.php" class="btn btn-secondary">
                                        <i class="bi bi-x-circle me-2"></i>Clear Filters
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Counties Table -->
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0 fw-bold">Counties List</h5>
                            <span class="badge bg-primary"><?php echo number_format($total_rows); ?> Records</span>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>ID</th>
                                            <th>State</th>
                                            <th>County Name</th>
                                            <th>Code</th>
                                            <th>Coordinates</th>
                                            <th>Statistics</th>
                                            <th>Map Status</th>
                                            <th>Created</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($counties)): ?>
                                        <tr>
                                            <td colspan="9" class="text-center py-4 text-muted">
                                                <i class="bi bi-inbox fs-1 d-block mb-3"></i>
                                                No counties found
                                            </td>
                                        </tr>
                                        <?php else: ?>
                                            <?php foreach ($counties as $county): ?>
                                            <tr>
                                                <td>
                                                    <span class="fw-medium">#<?php echo $county['id']; ?></span>
                                                </td>
                                                <td>
                                                    <span class="fw-medium"><?php echo htmlspecialchars($county['state_name']); ?></span>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars($county['state_code']); ?></small>
                                                </td>
                                                <td>
                                                    <span class="fw-medium"><?php echo htmlspecialchars($county['name']); ?></span>
                                                </td>
                                                <td>
                                                    <code><?php echo htmlspecialchars($county['code'] ?? 'N/A'); ?></code>
                                                </td>
                                                <td>
                                                    <?php if ($county['latitude'] && $county['longitude']): ?>
                                                        <small>
                                                            <i class="bi bi-geo-alt-fill text-success"></i>
                                                            <?php echo number_format($county['latitude'], 4); ?>, 
                                                            <?php echo number_format($county['longitude'], 4); ?>
                                                        </small>
                                                        <br>
                                                        <button class="btn btn-sm btn-link p-0" onclick="focusMapOnCounty(<?php echo $county['latitude']; ?>, <?php echo $county['longitude']; ?>, '<?php echo htmlspecialchars($county['name']); ?>')">
                                                            <i class="bi bi-eye"></i> View on map
                                                        </button>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">No coordinates</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="d-flex flex-wrap gap-1">
                                                        <span class="badge bg-primary" title="Payams">
                                                            <i class="bi bi-diagram-2"></i> <?php echo $county['total_payams']; ?>
                                                        </span>
                                                        <span class="badge bg-info" title="Bomas">
                                                            <i class="bi bi-diagram-3"></i> <?php echo $county['total_bomas']; ?>
                                                        </span>
                                                        <span class="badge bg-success" title="Parcels">
                                                            <i class="bi bi-pin-map"></i> <?php echo $county['total_parcels']; ?>
                                                        </span>
                                                        <?php if ($county['recent_payams'] > 0): ?>
                                                            <span class="badge bg-warning text-dark" title="New payams (30 days)">
                                                                <i class="bi bi-plus-circle"></i> +<?php echo $county['recent_payams']; ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php if ($county['latitude'] && $county['longitude']): ?>
                                                        <span class="badge bg-success">
                                                            <i class="bi bi-check-circle"></i> Mapped
                                                        </span>
                                                        <?php if ($county['boundary_coordinates']): ?>
                                                            <br><small class="text-success">With boundaries</small>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="badge bg-warning text-dark">
                                                            <i class="bi bi-exclamation-triangle"></i> Not mapped
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <small class="text-muted">
                                                        <?php echo date('d/m/Y', strtotime($county['created_at'])); ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <button class="btn btn-outline-primary" 
                                                                onclick="editCounty(<?php echo htmlspecialchars(json_encode($county)); ?>)"
                                                                data-bs-toggle="modal" data-bs-target="#editCountyModal">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                        <a href="?delete=<?php echo $county['id']; ?>" 
                                                           class="btn btn-outline-danger"
                                                           onclick="return confirm('Delete this county? This will also delete all associated payams, bomas, and parcels.')">
                                                            <i class="bi bi-trash"></i>
                                                        </a>
                                                        <button class="btn btn-outline-info" 
                                                                onclick="viewCountyDetails(<?php echo htmlspecialchars(json_encode($county)); ?>)"
                                                                data-bs-toggle="modal" data-bs-target="#viewCountyModal">
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
                            <h5 class="card-title mb-0 fw-bold">Recent County Additions</h5>
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

    <!-- Add County Modal -->
    <div class="modal fade" id="addCountyModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" id="addCountyForm">
                    <input type="hidden" name="action" value="add">
                    <div class="modal-header">
                        <h5 class="modal-title">Add New County</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">State <span class="text-danger">*</span></label>
                                <select class="form-select" name="state_id" required>
                                    <option value="">Select State</option>
                                    <?php foreach ($states as $state): ?>
                                    <option value="<?php echo $state['id']; ?>">
                                        <?php echo htmlspecialchars($state['name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">County Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="name" required>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">County Code</label>
                                <input type="text" class="form-control" name="code" placeholder="e.g., CE-JU">
                                <small class="text-muted">Unique identifier (e.g., CE-JU for Juba)</small>
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
                            
                            <div class="col-12 mb-3">
                                <label class="form-label">Boundary Coordinates (GeoJSON)</label>
                                <textarea class="form-control" name="boundary_coordinates" rows="3" placeholder='{"type":"Polygon","coordinates":[...]}'></textarea>
                                <small class="text-muted">Optional: Define county boundaries in GeoJSON format</small>
                            </div>
                            
                            <div class="col-12">
                                <div id="add-map-preview" style="height: 300px; width: 100%; display: none;"></div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add County</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit County Modal -->
    <div class="modal fade" id="editCountyModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" id="editCountyForm">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="county_id" id="edit_county_id">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit County</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">State <span class="text-danger">*</span></label>
                                <select class="form-select" name="state_id" id="edit_state_id" required>
                                    <option value="">Select State</option>
                                    <?php foreach ($states as $state): ?>
                                    <option value="<?php echo $state['id']; ?>">
                                        <?php echo htmlspecialchars($state['name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">County Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="name" id="edit_name" required>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">County Code</label>
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
                        <button type="submit" class="btn btn-primary">Update County</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View County Modal -->
    <div class="modal fade" id="viewCountyModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">County Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">State:</label>
                            <p id="view_state" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">County Name:</label>
                            <p id="view_name" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Code:</label>
                            <p id="view_code" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Coordinates:</label>
                            <p id="view_coordinates" class="mb-0"></p>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="fw-bold">Created:</label>
                            <p id="view_created" class="mb-0"></p>
                        </div>
                    </div>
                    
                    <!-- Statistics -->
                    <div class="row mt-3">
                        <div class="col-md-4 mb-2">
                            <div class="border rounded p-3 text-center">
                                <small class="text-muted d-block">Payams</small>
                                <span id="view_payams" class="h4 mb-0">0</span>
                            </div>
                        </div>
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

    <!-- JavaScript for Google Maps -->
    <script>
        // Global variables
        let map;
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
        const SOUTH_SUDAN_CENTER = { lat: 7.5, lng: 30.0 };

        // Initialize map
        function initMap() {
            // Create main map
            map = new google.maps.Map(document.getElementById('counties-map'), {
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

            // Load county markers
            loadCountyMarkers();

            // Add South Sudan boundary overlay (if available)
            // You can add a GeoJSON layer here if you have South Sudan boundary data
        }

        // Load county markers from database
        function loadCountyMarkers() {
            <?php foreach ($counties as $county): ?>
                <?php if ($county['latitude'] && $county['longitude']): ?>
                    addCountyMarker(
                        <?php echo $county['latitude']; ?>,
                        <?php echo $county['longitude']; ?>,
                        '<?php echo htmlspecialchars(addslashes($county['name'])); ?>',
                        <?php echo $county['total_parcels']; ?>
                    );
                <?php endif; ?>
            <?php endforeach; ?>
        }

        // Add a marker for a county
        function addCountyMarker(lat, lng, name, parcelCount) {
            const marker = new google.maps.Marker({
                position: { lat: parseFloat(lat), lng: parseFloat(lng) },
                map: map,
                title: name,
                animation: google.maps.Animation.DROP,
                icon: {
                    url: 'https://maps.google.com/mapfiles/ms/icons/red-dot.png',
                    scaledSize: new google.maps.Size(32, 32)
                }
            });

            const infowindow = new google.maps.InfoWindow({
                content: `
                    <div style="padding: 8px;">
                        <h6 style="margin: 0 0 5px 0; font-weight: bold;">${name}</h6>
                        <p style="margin: 0;">Parcels: ${parcelCount}</p>
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
            loadCountyMarkers();
        }

        // Center map on South Sudan
        function centerMapOnSouthSudan() {
            map.setCenter(SOUTH_SUDAN_CENTER);
            map.setZoom(6);
        }

        // Focus map on a specific county
        function focusMapOnCounty(lat, lng, name) {
            map.setCenter({ lat: parseFloat(lat), lng: parseFloat(lng) });
            map.setZoom(10);
            
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
                    document.getElementById('picker_latitude').value = event.latLng.lat();
                    document.getElementById('picker_longitude').value = event.latLng.lng();

                    // Make marker draggable
                    pickerMarker.addListener('dragend', () => {
                        const position = pickerMarker.getPosition();
                        document.getElementById('picker_latitude').value = position.lat();
                        document.getElementById('picker_longitude').value = position.lng();
                    });
                });

                // If editing, show existing location
                if (mode === 'edit') {
                    const lat = document.getElementById('edit_latitude').value;
                    const lng = document.getElementById('edit_longitude').value;
                    
                    if (lat && lng) {
                        const position = { lat: parseFloat(lat), lng: parseFloat(lng) };
                        pickerMap.setCenter(position);
                        pickerMap.setZoom(10);
                        
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
                    zoom: 10,
                    disableDefaultUI: true
                });
            }, 300);
        }

        // Edit county function
        function editCounty(county) {
            document.getElementById('edit_county_id').value = county.id;
            document.getElementById('edit_state_id').value = county.state_id;
            document.getElementById('edit_name').value = county.name || '';
            document.getElementById('edit_code').value = county.code || '';
            document.getElementById('edit_latitude').value = county.latitude || '';
            document.getElementById('edit_longitude').value = county.longitude || '';
            document.getElementById('edit_boundary_coordinates').value = county.boundary_coordinates || '';
            
            // Show map preview if coordinates exist
            if (county.latitude && county.longitude) {
                initPreviewMap('edit-map-preview', county.latitude, county.longitude);
            }
        }

        // View county details
        function viewCountyDetails(county) {
            document.getElementById('view_state').textContent = county.state_name || 'N/A';
            document.getElementById('view_name').textContent = county.name || 'N/A';
            document.getElementById('view_code').textContent = county.code || 'N/A';
            
            if (county.latitude && county.longitude) {
                document.getElementById('view_coordinates').textContent = 
                    `${county.latitude}, ${county.longitude}`;
                
                // Show map
                setTimeout(() => {
                    new google.maps.Map(document.getElementById('view-map'), {
                        center: { lat: parseFloat(county.latitude), lng: parseFloat(county.longitude) },
                        zoom: 10,
                        disableDefaultUI: true
                    });
                }, 300);
            } else {
                document.getElementById('view_coordinates').textContent = 'No coordinates available';
                document.getElementById('view-map').innerHTML = '<div class="alert alert-info">No location data available</div>';
            }
            
            document.getElementById('view_created').textContent = new Date(county.created_at).toLocaleString();
            
            // Statistics
            document.getElementById('view_payams').textContent = county.total_payams || '0';
            document.getElementById('view_bomas').textContent = county.total_bomas || '0';
            document.getElementById('view_parcels').textContent = county.total_parcels || '0';
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
        
        #counties-map {
            border-radius: 0 0 0.375rem 0.375rem;
        }
        
        .gm-style-iw-c {
            padding: 12px !important;
        }
        
        .gm-style-iw-d {
            overflow: hidden !important;
        }
        
        .county-marker-label {
            color: white;
            font-size: 12px;
            font-weight: bold;
            text-shadow: 1px 1px 1px #000;
        }
        
        @media (max-width: 768px) {
            #counties-map {
                height: 300px !important;
            }
        }
    </style>

</body>
</html>