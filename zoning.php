<?php
ini_set('display_errors', 1); 
error_reporting(E_ALL);

// zoning.php
include("head.php");
require_once 'db_connection.php';

// ============================================================================
// HANDLE ACTIONS
// ============================================================================

$message = '';
$messageType = '';

// Add zoning
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    if ($_POST['action'] === 'add') {
        $zone_code = strtoupper($_POST['zone_code']);
        $zone_name = $_POST['zone_name'];
        $description = $_POST['description'] ?? null;
        $allowed_uses = $_POST['allowed_uses'] ?? null;
        
        try {
            // Check for duplicate zone code
            $exists = fetchOne($conn, "SELECT id FROM zoning WHERE zone_code = ?", [$zone_code]);
            if ($exists) {
                throw new Exception("Zone code already exists");
            }
            
            executeQuery($conn, "
                INSERT INTO zoning (
                    zone_code, zone_name, description, allowed_uses, created_at
                ) VALUES (?, ?, ?, ?, NOW())
            ", [$zone_code, $zone_name, $description, $allowed_uses]);
            
            $message = "Zoning classification added successfully";
            $messageType = "success";
        } catch (Exception $e) {
            $message = "Error adding zoning: " . $e->getMessage();
            $messageType = "danger";
        }
    }
    
    // Edit zoning
    if ($_POST['action'] === 'edit') {
        $zoning_id = $_POST['zoning_id'];
        $zone_code = strtoupper($_POST['zone_code']);
        $zone_name = $_POST['zone_name'];
        $description = $_POST['description'] ?? null;
        $allowed_uses = $_POST['allowed_uses'] ?? null;
        
        try {
            // Check for duplicate zone code (excluding current)
            $exists = fetchOne($conn, "SELECT id FROM zoning WHERE zone_code = ? AND id != ?", [$zone_code, $zoning_id]);
            if ($exists) {
                throw new Exception("Zone code already exists");
            }
            
            executeQuery($conn, "
                UPDATE zoning SET 
                    zone_code = ?, zone_name = ?, description = ?, allowed_uses = ?
                WHERE id = ?
            ", [$zone_code, $zone_name, $description, $allowed_uses, $zoning_id]);
            
            $message = "Zoning classification updated successfully";
            $messageType = "success";
        } catch (Exception $e) {
            $message = "Error updating zoning: " . $e->getMessage();
            $messageType = "danger";
        }
    }
    
    // Delete zoning
    if ($_POST['action'] === 'delete') {
        $zoning_id = $_POST['zoning_id'];
        
        try {
            // Check if zoning is in use
            $in_use = fetchOne($conn, "
                SELECT 
                    (SELECT COUNT(*) FROM parcels WHERE current_zoning_id = ?) as parcels,
                    (SELECT COUNT(*) FROM parcel_zoning_history WHERE zoning_id = ?) as history
            ", [$zoning_id, $zoning_id]);
            
            if ($in_use['parcels'] > 0 || $in_use['history'] > 0) {
                throw new Exception("Cannot delete zoning that is in use by parcels.");
            }
            
            executeQuery($conn, "DELETE FROM zoning WHERE id = ?", [$zoning_id]);
            
            $message = "Zoning classification deleted successfully";
            $messageType = "success";
        } catch (Exception $e) {
            $message = "Error deleting zoning: " . $e->getMessage();
            $messageType = "danger";
        }
    }
}

// ============================================================================
// GET FILTERS AND DATA
// ============================================================================

// Build filter query
$whereClause = " WHERE 1=1";
$params = [];

if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search = '%' . $_GET['search'] . '%';
    $whereClause .= " AND (z.zone_code LIKE ? OR z.zone_name LIKE ? OR z.description LIKE ?)";
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
}

// Get zoning classifications with statistics - FIXED: Only use existing columns
$zoning_list = fetchAll($conn, "
    SELECT 
        z.*,
        (SELECT COUNT(*) FROM parcels WHERE current_zoning_id = z.id) as current_parcels,
        (SELECT COUNT(*) FROM parcel_zoning_history WHERE zoning_id = z.id) as historical_parcels,
        (SELECT SUM(area) FROM parcels WHERE current_zoning_id = z.id) as total_area,
        (SELECT AVG(area) FROM parcels WHERE current_zoning_id = z.id) as avg_parcel_size
    FROM zoning z
    $whereClause
    ORDER BY z.zone_code
", $params);

// Get summary statistics
$summary = fetchOne($conn, "
    SELECT 
        COUNT(*) as total_zones,
        (SELECT COUNT(DISTINCT current_zoning_id) FROM parcels WHERE current_zoning_id IS NOT NULL) as zones_in_use,
        (SELECT COUNT(*) FROM parcels) as total_parcels,
        (SELECT COUNT(*) FROM parcels WHERE current_zoning_id IS NOT NULL) as zoned_parcels,
        (SELECT SUM(area) FROM parcels WHERE current_zoning_id IS NOT NULL) as zoned_area
    FROM zoning
");

// Get parcel distribution by zone
$parcel_distribution = fetchAll($conn, "
    SELECT 
        z.zone_code,
        z.zone_name,
        COUNT(p.id) as parcel_count,
        SUM(p.area) as total_area,
        AVG(p.area) as avg_area
    FROM zoning z
    LEFT JOIN parcels p ON z.id = p.current_zoning_id
    GROUP BY z.id, z.zone_code, z.zone_name
    ORDER BY parcel_count DESC
");

// Get zoning change history
$zoning_history = fetchAll($conn, "
    SELECT 
        pzh.*,
        p.parcel_number,
        z.zone_code as new_zone_code,
        z.zone_name as new_zone_name
    FROM parcel_zoning_history pzh
    JOIN parcels p ON pzh.parcel_id = p.id
    JOIN zoning z ON pzh.zoning_id = z.id
    ORDER BY pzh.created_at DESC
    LIMIT 20
");

// FIXED: Simplified zone by state query - removed complex joins that might be causing issues
$zone_by_state = fetchAll($conn, "
    SELECT 
        s.name as state_name,
        z.zone_code,
        COUNT(p.id) as parcel_count
    FROM states s
    LEFT JOIN counties c ON s.id = c.state_id
    LEFT JOIN payams pa ON c.id = pa.county_id
    LEFT JOIN bomas b ON pa.id = b.payam_id
    LEFT JOIN parcels p ON b.id = p.boma_id
    LEFT JOIN zoning z ON p.current_zoning_id = z.id
    GROUP BY s.id, s.name, z.id, z.zone_code
    HAVING parcel_count > 0
    ORDER BY s.name, parcel_count DESC
");

// Get unzoned parcels count
$unzoned_parcels = fetchOne($conn, "
    SELECT COUNT(*) as count, SUM(area) as total_area
    FROM parcels
    WHERE current_zoning_id IS NULL
");
?>

<body data-page="zoning" class="zoning-page">
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
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div>
                            <h1 class="h3 mb-0">
                                <i class="bi bi-grid-3x3-gap-fill text-success me-2"></i>
                                Zoning Management
                            </h1>
                            <p class="text-muted mb-0">Manage zoning classifications and land use policies</p>
                        </div>
                        <div>
                            <button class="btn btn-outline-secondary me-2" type="button" data-bs-toggle="collapse" data-bs-target="#filterCollapse">
                                <i class="bi bi-funnel me-2"></i>Filters
                            </button>
                            <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addZoningModal">
                                <i class="bi bi-plus-circle me-2"></i>Add Zoning Classification
                            </button>
                        </div>
                    </div>

                    <!-- Filter Collapse -->
                    <div class="collapse mb-4" id="filterCollapse">
                        <div class="card border-0 shadow-sm">
                            <div class="card-body">
                                <form method="GET" class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Search</label>
                                        <div class="input-group">
                                            <input type="text" class="form-control" name="search" placeholder="Zone code, name, description..." 
                                                   value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
                                            <button class="btn btn-primary" type="submit">
                                                <i class="bi bi-search"></i>
                                            </button>
                                            <?php if (!empty($_GET)): ?>
                                            <a href="zoning.php" class="btn btn-outline-secondary">
                                                <i class="bi bi-x-circle"></i> Clear
                                            </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Alert Message -->
                    <?php if ($message): ?>
                    <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show mb-4" role="alert">
                        <?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    <?php endif; ?>

                    <!-- Unzoned Parcels Alert -->
                    <?php if (($unzoned_parcels['count'] ?? 0) > 0): ?>
                    <div class="alert alert-warning mb-4">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-exclamation-triangle-fill fs-3 me-3"></i>
                            <div>
                                <strong><?php echo number_format($unzoned_parcels['count']); ?> parcels (<?php echo number_format($unzoned_parcels['total_area'] ?? 0, 2); ?> m²) have no zoning assigned</strong>
                                <a href="parcels.php?filter=no_zoning" class="btn btn-sm btn-warning ms-3">View Unzoned Parcels</a>
                            </div>
                        </div>
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
                                            <h6 class="text-muted mb-1">Total Zones</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['total_zones'] ?? 0); ?></h3>
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
                                                <i class="bi bi-pin-map text-info fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Zones in Use</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['zones_in_use'] ?? 0); ?></h3>
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
                                                <i class="bi bi-pie-chart text-warning fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Zoned Parcels</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['zoned_parcels'] ?? 0); ?></h3>
                                            <small class="text-muted">of <?php echo number_format($summary['total_parcels'] ?? 0); ?> total</small>
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
                                                <i class="bi bi-arrows-angle-expand text-success fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Zoned Area</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['zoned_area'] ?? 0, 2); ?> m²</h3>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Charts Row -->
                    <div class="row g-4 mb-5">
                        <div class="col-xl-6">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h5 class="card-title mb-0 fw-bold">
                                        <i class="bi bi-pie-chart text-primary me-2"></i>Parcel Distribution by Zone
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <canvas id="distributionChart" style="height: 300px;"></canvas>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-xl-6">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h5 class="card-title mb-0 fw-bold">
                                        <i class="bi bi-bar-chart text-success me-2"></i>Zoning by State
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <canvas id="stateChart" style="height: 300px;"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Zoning Classifications Table -->
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0 fw-bold">
                                <i class="bi bi-table me-2"></i>Zoning Classifications
                                <span class="badge bg-secondary ms-2"><?php echo count($zoning_list); ?> records</span>
                            </h5>
                            <div>
                                <input type="text" class="form-control form-control-sm" style="width: 250px;" id="tableSearch" placeholder="Search in table...">
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0" id="zoningTable">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>Zone Code</th>
                                            <th>Zone Name</th>
                                            <th>Description</th>
                                            <th>Allowed Uses</th>
                                            <th>Current Parcels</th>
                                            <th>Total Area (m²)</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($zoning_list)): ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-4 text-muted">
                                                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                                No zoning classifications found
                                            </td>
                                        </tr>
                                        <?php else: ?>
                                            <?php foreach ($zoning_list as $zone): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($zone['zone_code']); ?></strong>
                                                </td>
                                                <td><?php echo htmlspecialchars($zone['zone_name']); ?></td>
                                                <td>
                                                    <?php echo htmlspecialchars(substr($zone['description'] ?? '', 0, 50)) . (strlen($zone['description'] ?? '') > 50 ? '...' : ''); ?>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars(substr($zone['allowed_uses'] ?? '', 0, 50)) . (strlen($zone['allowed_uses'] ?? '') > 50 ? '...' : ''); ?>
                                                </td>
                                                <td>
                                                    <span class="badge bg-info"><?php echo $zone['current_parcels']; ?></span>
                                                    <?php if ($zone['historical_parcels'] > 0): ?>
                                                    <br><small class="text-muted">(<?php echo $zone['historical_parcels']; ?> historic)</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php echo $zone['total_area'] ? number_format($zone['total_area'], 2) : '0.00'; ?>
                                                </td>
                                                <td>
                                                    <div class="btn-group">
                                                        <button class="btn btn-sm btn-outline-primary" 
                                                                onclick="viewZone(<?php echo htmlspecialchars(json_encode($zone)); ?>)"
                                                                data-bs-toggle="modal" data-bs-target="#viewZoneModal">
                                                            <i class="bi bi-eye"></i>
                                                        </button>
                                                        
                                                        <button class="btn btn-sm btn-outline-success" 
                                                                onclick="editZone(<?php echo htmlspecialchars(json_encode($zone)); ?>)"
                                                                data-bs-toggle="modal" data-bs-target="#editZoneModal">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                        
                                                        <?php if ($zone['current_parcels'] == 0 && $zone['historical_parcels'] == 0): ?>
                                                        <button class="btn btn-sm btn-outline-danger" 
                                                                onclick="deleteZone(<?php echo $zone['id']; ?>, '<?php echo htmlspecialchars(addslashes($zone['zone_code'])); ?>')"
                                                                data-bs-toggle="modal" data-bs-target="#deleteZoneModal">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                        <?php endif; ?>
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

                    <!-- Zoning History -->
                    <?php if (!empty($zoning_history)): ?>
                    <div class="card border-0 shadow-sm mt-4">
                        <div class="card-header bg-white py-3">
                            <h5 class="card-title mb-0 fw-bold">
                                <i class="bi bi-clock-history text-info me-2"></i>Recent Zoning Changes
                            </h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>Date</th>
                                            <th>Parcel</th>
                                            <th>New Zone</th>
                                            <th>Start Date</th>
                                            <th>End Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($zoning_history as $history): ?>
                                        <tr>
                                            <td><?php echo date('d M Y H:i', strtotime($history['created_at'])); ?></td>
                                            <td>
                                                <a href="parcels.php?view=<?php echo $history['parcel_id']; ?>">
                                                    <?php echo htmlspecialchars($history['parcel_number']); ?>
                                                </a>
                                            </td>
                                            <td>
                                                <span class="badge bg-success">
                                                    <?php echo htmlspecialchars($history['new_zone_code']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo date('d M Y', strtotime($history['start_date'])); ?></td>
                                            <td><?php echo $history['end_date'] ? date('d M Y', strtotime($history['end_date'])) : 'Current'; ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
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

    <!-- Add Zoning Modal -->
    <div class="modal fade" id="addZoningModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="add">
                    <div class="modal-header">
                        <h5 class="modal-title">Add Zoning Classification</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Zone Code <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="zone_code" required 
                                       placeholder="e.g., R1, C2, A1" maxlength="20">
                                <small class="text-muted">Short identifier (will be uppercase)</small>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Zone Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="zone_name" required 
                                       placeholder="e.g., Residential Low Density, Commercial Core">
                            </div>
                            
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Description</label>
                                <textarea class="form-control" name="description" rows="3" 
                                          placeholder="Detailed description of this zoning classification..."></textarea>
                            </div>
                            
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Allowed Uses</label>
                                <textarea class="form-control" name="allowed_uses" rows="4" 
                                          placeholder="List permitted uses, one per line&#10;- Single family dwellings&#10;- Duplexes&#10;- Home occupations&#10;- etc."></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Add Zoning</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Zoning Modal -->
    <div class="modal fade" id="editZoneModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="zoning_id" id="editZoneId">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Zoning Classification</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Zone Code <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="zone_code" id="editZoneCode" required>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Zone Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="zone_name" id="editZoneName" required>
                            </div>
                            
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Description</label>
                                <textarea class="form-control" name="description" rows="3" id="editDescription"></textarea>
                            </div>
                            
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Allowed Uses</label>
                                <textarea class="form-control" name="allowed_uses" rows="4" id="editAllowedUses"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Zoning</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Zone Modal -->
    <div class="modal fade" id="viewZoneModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Zoning Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6 class="border-bottom pb-2">Basic Information</h6>
                            <table class="table table-borderless">
                                <tr>
                                    <th width="40%">Zone Code:</th>
                                    <td><strong id="viewZoneCode"></strong></td>
                                </tr>
                                <tr>
                                    <th>Zone Name:</th>
                                    <td id="viewZoneName"></td>
                                </tr>
                                <tr>
                                    <th>Created:</th>
                                    <td id="viewCreated"></td>
                                </tr>
                            </table>
                        </div>
                        
                        <div class="col-md-6">
                            <h6 class="border-bottom pb-2">Usage Statistics</h6>
                            <table class="table table-borderless">
                                <tr>
                                    <th width="40%">Current Parcels:</th>
                                    <td id="viewCurrentParcels"></td>
                                </tr>
                                <tr>
                                    <th>Historical Parcels:</th>
                                    <td id="viewHistoricalParcels"></td>
                                </tr>
                                <tr>
                                    <th>Total Area:</th>
                                    <td id="viewTotalArea"></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    
                    <div class="row mt-3">
                        <div class="col-12">
                            <h6 class="border-bottom pb-2">Description</h6>
                            <p id="viewDescription" class="text-muted"></p>
                        </div>
                    </div>
                    
                    <div class="row mt-3">
                        <div class="col-12">
                            <h6 class="border-bottom pb-2">Allowed Uses</h6>
                            <div id="viewAllowedUses" class="text-muted"></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Zone Modal -->
    <div class="modal fade" id="deleteZoneModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="zoning_id" id="deleteZoneId">
                    <div class="modal-header">
                        <h5 class="modal-title">Delete Zoning Classification</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p>Are you sure you want to delete zoning <strong id="deleteZoneCode"></strong>?</p>
                        <p class="text-danger"><i class="bi bi-exclamation-triangle"></i> This action cannot be undone. Only zones with no parcels can be deleted.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Delete</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Chart Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <script>
        // Initialize charts
        document.addEventListener('DOMContentLoaded', function() {
            // Distribution Chart
            const distCtx = document.getElementById('distributionChart')?.getContext('2d');
            if (distCtx) {
                const distLabels = [
                    <?php foreach ($parcel_distribution as $dist): ?>
                    '<?php echo htmlspecialchars($dist['zone_code'] . ' - ' . $dist['zone_name']); ?>',
                    <?php endforeach; ?>
                ];
                const distData = [
                    <?php foreach ($parcel_distribution as $dist): ?>
                    <?php echo $dist['parcel_count']; ?>,
                    <?php endforeach; ?>
                ];
                
                // Generate colors
                const colors = ['#0d6efd', '#6610f2', '#6f42c1', '#d63384', '#dc3545', '#fd7e14', '#ffc107', '#198754', '#20c997', '#0dcaf0'];
                
                new Chart(distCtx, {
                    type: 'doughnut',
                    data: {
                        labels: distLabels,
                        datasets: [{
                            data: distData,
                            backgroundColor: colors.slice(0, distData.length),
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    boxWidth: 12
                                }
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        const label = context.label || '';
                                        const value = context.parsed || 0;
                                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                        const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                        return label + ': ' + value + ' parcels (' + percentage + '%)';
                                    }
                                }
                            }
                        }
                    }
                });
            }
            
            // State Chart
            const stateCtx = document.getElementById('stateChart')?.getContext('2d');
            if (stateCtx && <?php echo !empty($zone_by_state) ? 'true' : 'false'; ?>) {
                // Group data by state
                const stateData = {};
                <?php foreach ($zone_by_state as $item): ?>
                if (!stateData['<?php echo $item['state_name']; ?>']) {
                    stateData['<?php echo $item['state_name']; ?>'] = {};
                }
                stateData['<?php echo $item['state_name']; ?>']['<?php echo $item['zone_code']; ?>'] = <?php echo $item['parcel_count']; ?>;
                <?php endforeach; ?>
                
                const states = Object.keys(stateData);
                const zones = [...new Set(<?php echo json_encode(array_column($zone_by_state, 'zone_code')); ?>)];
                
                const datasets = zones.map((zone, index) => {
                    const data = states.map(state => stateData[state][zone] || 0);
                    const colors = ['#0d6efd', '#6610f2', '#6f42c1', '#d63384', '#dc3545', '#fd7e14', '#ffc107', '#198754'];
                    
                    return {
                        label: zone,
                        data: data,
                        backgroundColor: colors[index % colors.length],
                        barPercentage: 0.8
                    };
                });
                
                new Chart(stateCtx, {
                    type: 'bar',
                    data: {
                        labels: states,
                        datasets: datasets
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            x: {
                                stacked: true
                            },
                            y: {
                                stacked: true,
                                beginAtZero: true,
                                ticks: {
                                    stepSize: 1,
                                    precision: 0
                                }
                            }
                        },
                        plugins: {
                            legend: {
                                position: 'bottom'
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return context.dataset.label + ': ' + context.parsed.y + ' parcels';
                                    }
                                }
                            }
                        }
                    }
                });
            }
        });
        
        // View zone
        function viewZone(zone) {
            document.getElementById('viewZoneCode').textContent = zone.zone_code || 'N/A';
            document.getElementById('viewZoneName').textContent = zone.zone_name || 'N/A';
            document.getElementById('viewCreated').textContent = zone.created_at ? formatDate(zone.created_at) : 'N/A';
            document.getElementById('viewCurrentParcels').textContent = zone.current_parcels || 0;
            document.getElementById('viewHistoricalParcels').textContent = zone.historical_parcels || 0;
            document.getElementById('viewTotalArea').textContent = zone.total_area ? number_format(zone.total_area, 2) + ' m²' : '0 m²';
            
            document.getElementById('viewDescription').textContent = zone.description || 'No description provided';
            
            const allowedUses = zone.allowed_uses ? zone.allowed_uses.split('\n') : ['No uses specified'];
            let usesHtml = '<ul class="list-unstyled">';
            allowedUses.forEach(use => {
                if (use.trim()) {
                    usesHtml += '<li><i class="bi bi-check-circle text-success me-2"></i>' + use.trim() + '</li>';
                }
            });
            usesHtml += '</ul>';
            document.getElementById('viewAllowedUses').innerHTML = usesHtml;
        }
        
        // Edit zone
        function editZone(zone) {
            document.getElementById('editZoneId').value = zone.id;
            document.getElementById('editZoneCode').value = zone.zone_code || '';
            document.getElementById('editZoneName').value = zone.zone_name || '';
            document.getElementById('editDescription').value = zone.description || '';
            document.getElementById('editAllowedUses').value = zone.allowed_uses || '';
        }
        
        // Delete zone
        function deleteZone(id, code) {
            document.getElementById('deleteZoneId').value = id;
            document.getElementById('deleteZoneCode').textContent = code;
        }
        
        // Format date
        function formatDate(dateString) {
            if (!dateString) return 'N/A';
            const options = { year: 'numeric', month: 'short', day: 'numeric' };
            return new Date(dateString).toLocaleDateString(undefined, options);
        }
        
        // Number format
        function number_format(num, decimals) {
            return parseFloat(num).toFixed(decimals).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        }
        
        // Search functionality
        document.getElementById('tableSearch')?.addEventListener('keyup', function() {
            const searchText = this.value.toLowerCase();
            const table = document.getElementById('zoningTable');
            const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
            
            Array.from(rows).forEach(row => {
                const cells = Array.from(row.cells);
                const text = cells.map(cell => cell.textContent).join(' ').toLowerCase();
                
                if (text.includes(searchText)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
    </script>

    <style>
        .table td {
            vertical-align: middle;
        }
        .badge {
            font-size: 0.85rem;
        }
        .btn-group .btn {
            padding: 0.25rem 0.5rem;
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