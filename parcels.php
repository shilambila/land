<?php
ini_set('display_errors', 1); 
error_reporting(E_ALL);

// parcels.php
include("head.php");
require_once 'db_connection.php';

// ============================================================================
// HANDLE ACTIONS
// ============================================================================

$message = '';
$messageType = '';

// Delete parcel
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $parcelId = $_GET['delete'];
    
    try {
        // Check if parcel has related records
        $related = fetchOne($conn, "
            SELECT 
                (SELECT COUNT(*) FROM titles WHERE parcel_id = ?) as titles,
                (SELECT COUNT(*) FROM applications WHERE parcel_id = ?) as applications,
                (SELECT COUNT(*) FROM encumbrances WHERE parcel_id = ?) as encumbrances,
                (SELECT COUNT(*) FROM disputes WHERE parcel_id = ?) as disputes,
                (SELECT COUNT(*) FROM survey_records WHERE parcel_id = ?) as surveys,
                (SELECT COUNT(*) FROM valuations WHERE parcel_id = ?) as valuations,
                (SELECT COUNT(*) FROM tax_assessments WHERE parcel_id = ?) as tax_assessments
        ", [$parcelId, $parcelId, $parcelId, $parcelId, $parcelId, $parcelId, $parcelId]);
        
        if ($related['titles'] > 0 || $related['applications'] > 0 || $related['encumbrances'] > 0 || 
            $related['disputes'] > 0 || $related['surveys'] > 0) {
            $message = "Cannot delete parcel with existing titles, applications, or other related records. Deactivate instead.";
            $messageType = "warning";
        } else {
            executeQuery($conn, "DELETE FROM parcels WHERE id = ?", [$parcelId]);
            $message = "Parcel deleted successfully";
            $messageType = "success";
        }
    } catch (Exception $e) {
        $message = "Error deleting parcel: " . $e->getMessage();
        $messageType = "danger";
    }
}

// Add/Edit parcel
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        
        // Add new parcel
        if ($_POST['action'] === 'add') {
            $parcel_number = $_POST['parcel_number'];
            $survey_number = $_POST['survey_number'] ?? null;
            $area = !empty($_POST['area']) ? $_POST['area'] : null;
            $location_description = $_POST['location_description'] ?? null;
            $boma_id = $_POST['boma_id'];
            $current_zoning_id = !empty($_POST['current_zoning_id']) ? $_POST['current_zoning_id'] : null;
            
            // For geometry - using a simple point for now, but you might want to implement more complex geometry
            $latitude = !empty($_POST['latitude']) ? $_POST['latitude'] : 4.85;
            $longitude = !empty($_POST['longitude']) ? $_POST['longitude'] : 31.6;
            $geometry = "POINT($longitude $latitude)"; // Note: GeoJSON uses lng, lat order
            
            try {
                // Check for duplicate parcel number
                $exists = fetchOne($conn, "SELECT id FROM parcels WHERE parcel_number = ?", [$parcel_number]);
                if ($exists) {
                    throw new Exception("Parcel number already exists");
                }
                
                executeQuery($conn, "
                    INSERT INTO parcels (
                        parcel_number, survey_number, area, location_description, 
                        geometry, boma_id, current_zoning_id, created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ST_GeomFromText(?), ?, ?, NOW(), NOW())
                ", [$parcel_number, $survey_number, $area, $location_description, 
                    $geometry, $boma_id, $current_zoning_id]);
                
                $newParcelId = $conn->lastInsertId();
                
                // Log the action
                $message = "Parcel added successfully";
                $messageType = "success";
            } catch (Exception $e) {
                $message = "Error adding parcel: " . $e->getMessage();
                $messageType = "danger";
            }
        }
        
        // Edit parcel
        if ($_POST['action'] === 'edit') {
            $parcel_id = $_POST['parcel_id'];
            $parcel_number = $_POST['parcel_number'];
            $survey_number = $_POST['survey_number'] ?? null;
            $area = !empty($_POST['area']) ? $_POST['area'] : null;
            $location_description = $_POST['location_description'] ?? null;
            $boma_id = $_POST['boma_id'];
            $current_zoning_id = !empty($_POST['current_zoning_id']) ? $_POST['current_zoning_id'] : null;
            
            // For geometry updates
            $latitude = !empty($_POST['latitude']) ? $_POST['latitude'] : null;
            $longitude = !empty($_POST['longitude']) ? $_POST['longitude'] : null;
            
            try {
                // Check for duplicate parcel number (excluding current)
                $exists = fetchOne($conn, "SELECT id FROM parcels WHERE parcel_number = ? AND id != ?", [$parcel_number, $parcel_id]);
                if ($exists) {
                    throw new Exception("Parcel number already exists");
                }
                
                // If coordinates are provided, update geometry
                if ($latitude && $longitude) {
                    $geometry = "POINT($longitude $latitude)";
                    executeQuery($conn, "
                        UPDATE parcels SET 
                            parcel_number = ?, survey_number = ?, area = ?, 
                            location_description = ?, geometry = ST_GeomFromText(?), 
                            boma_id = ?, current_zoning_id = ?, updated_at = NOW()
                        WHERE id = ?
                    ", [$parcel_number, $survey_number, $area, $location_description, 
                        $geometry, $boma_id, $current_zoning_id, $parcel_id]);
                } else {
                    executeQuery($conn, "
                        UPDATE parcels SET 
                            parcel_number = ?, survey_number = ?, area = ?, 
                            location_description = ?, boma_id = ?, 
                            current_zoning_id = ?, updated_at = NOW()
                        WHERE id = ?
                    ", [$parcel_number, $survey_number, $area, $location_description, 
                        $boma_id, $current_zoning_id, $parcel_id]);
                }
                
                $message = "Parcel updated successfully";
                $messageType = "success";
            } catch (Exception $e) {
                $message = "Error updating parcel: " . $e->getMessage();
                $messageType = "danger";
            }
        }
        
        // Transfer ownership
        if ($_POST['action'] === 'transfer') {
            $parcel_id = $_POST['parcel_id'];
            $from_party_id = $_POST['from_party_id'];
            $to_party_id = $_POST['to_party_id'];
            $transfer_date = $_POST['transfer_date'];
            $consideration_amount = !empty($_POST['consideration_amount']) ? $_POST['consideration_amount'] : null;
            $transaction_type = $_POST['transaction_type'] ?? 'transfer';
            
            try {
                // Get current title
                $title = fetchOne($conn, "SELECT id FROM titles WHERE parcel_id = ? AND status = 'active'", [$parcel_id]);
                
                if (!$title) {
                    throw new Exception("No active title found for this parcel");
                }
                
                // Begin transaction
                $conn->beginTransaction();
                
                // Update current ownership end date
                executeQuery($conn, "
                    UPDATE ownerships SET ownership_end_date = ? 
                    WHERE title_id = ? AND is_current = 1
                ", [$transfer_date, $title['id']]);
                
                // Create new ownership
                executeQuery($conn, "
                    INSERT INTO ownerships (title_id, party_id, share_percentage, ownership_start_date, created_at)
                    VALUES (?, ?, 100.00, ?, NOW())
                ", [$title['id'], $to_party_id, $transfer_date]);
                
                // Record transaction
                executeQuery($conn, "
                    INSERT INTO transactions (transaction_type, parcel_id, title_id, from_party_id, to_party_id, 
                                             transaction_date, consideration_amount, created_at, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)
                ", [$transaction_type, $parcel_id, $title['id'], $from_party_id, $to_party_id, 
                    $transfer_date, $consideration_amount, $_SESSION['user_id'] ?? 1]);
                
                $conn->commit();
                $message = "Ownership transferred successfully";
                $messageType = "success";
            } catch (Exception $e) {
                $conn->rollBack();
                $message = "Error transferring ownership: " . $e->getMessage();
                $messageType = "danger";
            }
        }
    }
}

// ============================================================================
// GET HIERARCHY DATA FOR DROPDOWNS
// ============================================================================

// Get all states for filter
$states = fetchAll($conn, "SELECT id, name, code FROM states ORDER BY name");

// Get all counties with state info
$counties = fetchAll($conn, "
    SELECT c.*, s.name as state_name, s.id as state_id 
    FROM counties c
    JOIN states s ON c.state_id = s.id
    ORDER BY s.name, c.name
");

// Get all payams with county info
$payams = fetchAll($conn, "
    SELECT p.*, c.name as county_name, c.state_id 
    FROM payams p
    JOIN counties c ON p.county_id = c.id
    ORDER BY c.name, p.name
");

// Get all bomas with full hierarchy
$bomas = fetchAll($conn, "
    SELECT 
        b.*, 
        p.name as payam_name,
        p.id as payam_id,
        c.name as county_name,
        c.id as county_id,
        s.name as state_name,
        s.id as state_id
    FROM bomas b
    JOIN payams p ON b.payam_id = p.id
    JOIN counties c ON p.county_id = c.id
    JOIN states s ON c.state_id = s.id
    ORDER BY s.name, c.name, p.name, b.name
");

// Get zoning types
$zoning_types = fetchAll($conn, "SELECT * FROM zoning ORDER BY zone_code");

// Get all parties for ownership dropdowns
$parties = fetchAll($conn, "SELECT id, name, party_type FROM parties ORDER BY name");

// ============================================================================
// GET PARCELS WITH COMPLETE DETAILS
// ============================================================================

// Build filter query
$whereClause = "";
$params = [];

if (isset($_GET['state_id']) && !empty($_GET['state_id'])) {
    $whereClause .= " AND s.id = ?";
    $params[] = $_GET['state_id'];
}

if (isset($_GET['county_id']) && !empty($_GET['county_id'])) {
    $whereClause .= " AND c.id = ?";
    $params[] = $_GET['county_id'];
}

if (isset($_GET['payam_id']) && !empty($_GET['payam_id'])) {
    $whereClause .= " AND pa.id = ?";
    $params[] = $_GET['payam_id'];
}

if (isset($_GET['boma_id']) && !empty($_GET['boma_id'])) {
    $whereClause .= " AND b.id = ?";
    $params[] = $_GET['boma_id'];
}

if (isset($_GET['zoning_id']) && !empty($_GET['zoning_id'])) {
    $whereClause .= " AND p.current_zoning_id = ?";
    $params[] = $_GET['zoning_id'];
}

if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search = '%' . $_GET['search'] . '%';
    $whereClause .= " AND (p.parcel_number LIKE ? OR p.survey_number LIKE ? OR p.location_description LIKE ?)";
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
}

// Get parcels with full hierarchy and ownership info
$parcels = fetchAll($conn, "
    SELECT 
        p.*,
        b.id as boma_id,
        b.name as boma_name,
        b.code as boma_code,
        pa.id as payam_id,
        pa.name as payam_name,
        c.id as county_id,
        c.name as county_name,
        s.id as state_id,
        s.name as state_name,
        z.zone_code,
        z.zone_name,
        -- Get current title
        t.id as title_id,
        t.title_number,
        t.title_type,
        t.issue_date,
        t.expiry_date,
        t.status as title_status,
        -- Get current owner
        o.party_id as owner_id,
        pa2.name as owner_name,
        pa2.party_type as owner_type,
        -- Get counts
        (SELECT COUNT(*) FROM titles WHERE parcel_id = p.id) as total_titles,
        (SELECT COUNT(*) FROM applications WHERE parcel_id = p.id) as total_applications,
        (SELECT COUNT(*) FROM encumbrances WHERE parcel_id = p.id) as total_encumbrances,
        (SELECT COUNT(*) FROM disputes WHERE parcel_id = p.id) as total_disputes,
        (SELECT COUNT(*) FROM survey_records WHERE parcel_id = p.id) as total_surveys,
        (SELECT COUNT(*) FROM valuations WHERE parcel_id = p.id) as total_valuations,
        -- Extract coordinates from geometry (if point)
        ST_X(p.geometry) as longitude,
        ST_Y(p.geometry) as latitude
    FROM parcels p
    JOIN bomas b ON p.boma_id = b.id
    JOIN payams pa ON b.payam_id = pa.id
    JOIN counties c ON pa.county_id = c.id
    JOIN states s ON c.state_id = s.id
    LEFT JOIN zoning z ON p.current_zoning_id = z.id
    LEFT JOIN titles t ON p.id = t.parcel_id AND t.status = 'active'
    LEFT JOIN ownerships o ON t.id = o.title_id AND o.is_current = 1
    LEFT JOIN parties pa2 ON o.party_id = pa2.id
    WHERE 1=1 $whereClause
    ORDER BY s.name, c.name, pa.name, b.name, p.parcel_number
", $params);

// Get summary statistics
$summary = fetchOne($conn, "
    SELECT 
        COUNT(*) as total_parcels,
        SUM(area) as total_area,
        AVG(area) as avg_area,
        (SELECT COUNT(*) FROM titles WHERE status = 'active') as active_titles,
        (SELECT COUNT(*) FROM titles) as total_titles,
        (SELECT COUNT(*) FROM applications WHERE status = 'submitted' OR status = 'under_review') as pending_applications,
        (SELECT COUNT(*) FROM disputes WHERE status = 'open' OR status = 'in_progress') as active_disputes,
        (SELECT COUNT(*) FROM encumbrances WHERE end_date IS NULL OR end_date > CURDATE()) as active_encumbrances,
        (SELECT SUM(area) FROM parcels WHERE current_zoning_id = (SELECT id FROM zoning WHERE zone_code = 'R1')) as residential_area,
        (SELECT SUM(area) FROM parcels WHERE current_zoning_id = (SELECT id FROM zoning WHERE zone_code = 'C1')) as commercial_area,
        (SELECT SUM(area) FROM parcels WHERE current_zoning_id = (SELECT id FROM zoning WHERE zone_code = 'A1')) as agricultural_area
    FROM parcels
");

// Get parcels by zoning for chart
$parcels_by_zoning = fetchAll($conn, "
    SELECT 
        z.zone_name,
        z.zone_code,
        COUNT(*) as count,
        SUM(p.area) as total_area
    FROM parcels p
    JOIN zoning z ON p.current_zoning_id = z.id
    GROUP BY z.id, z.zone_name, z.zone_code
    ORDER BY count DESC
");

// Get recent activity
$recent_activity = fetchAll($conn, "
    (SELECT 'parcel' as type, id, parcel_number as identifier, created_at as date
     FROM parcels ORDER BY created_at DESC LIMIT 5)
    UNION ALL
    (SELECT 'title' as type, id, title_number as identifier, created_at as date
     FROM titles ORDER BY created_at DESC LIMIT 5)
    UNION ALL
    (SELECT 'transaction' as type, id, CONCAT('Transaction #', id) as identifier, created_at as date
     FROM transactions ORDER BY created_at DESC LIMIT 5)
    ORDER BY date DESC LIMIT 10
");

// Get parcels with issues (no title, disputes, etc.)
$parcels_with_issues = fetchAll($conn, "
    SELECT 
        p.id,
        p.parcel_number,
        CONCAT(s.name, ' / ', c.name, ' / ', pa.name, ' / ', b.name) as location,
        CASE 
            WHEN t.id IS NULL THEN 'No Title'
            WHEN d.id IS NOT NULL THEN 'Has Disputes'
            WHEN e.id IS NOT NULL THEN 'Has Encumbrances'
            WHEN ta.status = 'overdue' THEN 'Tax Overdue'
        END as issue_type
    FROM parcels p
    JOIN bomas b ON p.boma_id = b.id
    JOIN payams pa ON b.payam_id = pa.id
    JOIN counties c ON pa.county_id = c.id
    JOIN states s ON c.state_id = s.id
    LEFT JOIN titles t ON p.id = t.parcel_id AND t.status = 'active'
    LEFT JOIN disputes d ON p.id = d.parcel_id AND d.status IN ('open', 'in_progress')
    LEFT JOIN encumbrances e ON p.id = e.parcel_id AND (e.end_date IS NULL OR e.end_date > CURDATE())
    LEFT JOIN tax_assessments ta ON p.id = ta.parcel_id AND ta.status = 'overdue'
    WHERE t.id IS NULL OR d.id IS NOT NULL OR e.id IS NOT NULL OR ta.status = 'overdue'
    GROUP BY p.id
    LIMIT 10
");

// Get all parcels for map markers
$parcel_markers = [];
foreach ($parcels as $parcel) {
    if ($parcel['latitude'] && $parcel['longitude']) {
        $parcel_markers[] = [
            'id' => $parcel['id'],
            'parcel_number' => $parcel['parcel_number'],
            'location' => $parcel['location_description'],
            'boma' => $parcel['boma_name'],
            'payam' => $parcel['payam_name'],
            'county' => $parcel['county_name'],
            'state' => $parcel['state_name'],
            'area' => $parcel['area'],
            'owner' => $parcel['owner_name'],
            'title_number' => $parcel['title_number'],
            'zone' => $parcel['zone_code'],
            'lat' => (float)$parcel['latitude'],
            'lng' => (float)$parcel['longitude'],
            'status' => $parcel['title_status'] ?? 'No Title'
        ];
    }
}

// South Sudan center coordinates
$south_sudan_center = ['lat' => 7.5, 'lng' => 30.0, 'zoom' => 7];
?>

<body data-page="parcels" class="parcels-page">
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
                    
                    <!-- Page Header with Filter Toggle -->
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div>
                            <h1 class="h3 mb-0">Parcels Management</h1>
                            <p class="text-muted mb-0">Manage land parcels, titles, and ownership with map visualization</p>
                        </div>
                        <div>
                            <button class="btn btn-outline-secondary me-2" type="button" data-bs-toggle="collapse" data-bs-target="#filterCollapse">
                                <i class="bi bi-funnel me-2"></i>Filters
                            </button>
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addParcelModal">
                                <i class="bi bi-plus-circle me-2"></i>Add New Parcel
                            </button>
                        </div>
                    </div>

                    <!-- Filter Collapse -->
                    <div class="collapse mb-4" id="filterCollapse">
                        <div class="card border-0 shadow-sm">
                            <div class="card-body">
                                <form method="GET" class="row g-3">
                                    <div class="col-md-3">
                                        <label class="form-label">State</label>
                                        <select class="form-select" name="state_id" onchange="this.form.submit()">
                                            <option value="">All States</option>
                                            <?php foreach ($states as $state): ?>
                                            <option value="<?php echo $state['id']; ?>" <?php echo (isset($_GET['state_id']) && $_GET['state_id'] == $state['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($state['name']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">County</label>
                                        <select class="form-select" name="county_id" onchange="this.form.submit()">
                                            <option value="">All Counties</option>
                                            <?php foreach ($counties as $county): ?>
                                            <option value="<?php echo $county['id']; ?>" <?php echo (isset($_GET['county_id']) && $_GET['county_id'] == $county['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($county['name']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Payam</label>
                                        <select class="form-select" name="payam_id" onchange="this.form.submit()">
                                            <option value="">All Payams</option>
                                            <?php foreach ($payams as $payam): ?>
                                            <option value="<?php echo $payam['id']; ?>" <?php echo (isset($_GET['payam_id']) && $_GET['payam_id'] == $payam['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($payam['name']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Boma</label>
                                        <select class="form-select" name="boma_id" onchange="this.form.submit()">
                                            <option value="">All Bomas</option>
                                            <?php foreach ($bomas as $boma): ?>
                                            <option value="<?php echo $boma['id']; ?>" <?php echo (isset($_GET['boma_id']) && $_GET['boma_id'] == $boma['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($boma['name']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Zoning</label>
                                        <select class="form-select" name="zoning_id" onchange="this.form.submit()">
                                            <option value="">All Zones</option>
                                            <?php foreach ($zoning_types as $zone): ?>
                                            <option value="<?php echo $zone['id']; ?>" <?php echo (isset($_GET['zoning_id']) && $_GET['zoning_id'] == $zone['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($zone['zone_code'] . ' - ' . $zone['zone_name']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Search</label>
                                        <div class="input-group">
                                            <input type="text" class="form-control" name="search" placeholder="Parcel number, survey number, location..." 
                                                   value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
                                            <button class="btn btn-primary" type="submit">
                                                <i class="bi bi-search"></i>
                                            </button>
                                            <?php if (!empty($_GET)): ?>
                                            <a href="parcels.php" class="btn btn-outline-secondary">
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

                    <!-- Summary Cards -->
                    <div class="row g-4 mb-5">
                        <div class="col-xl-2 col-md-4">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0 me-3">
                                            <div class="bg-primary bg-opacity-10 rounded-circle p-3">
                                                <i class="bi bi-grid text-primary fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Total Parcels</h6>
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
                                            <div class="bg-success bg-opacity-10 rounded-circle p-3">
                                                <i class="bi bi-file-text text-success fs-4"></i>
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
                        
                        <div class="col-xl-2 col-md-4">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0 me-3">
                                            <div class="bg-warning bg-opacity-10 rounded-circle p-3">
                                                <i class="bi bi-clock-history text-warning fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Pending Apps</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['pending_applications'] ?? 0); ?></h3>
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
                                                <i class="bi bi-exclamation-triangle text-danger fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Active Disputes</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['active_disputes'] ?? 0); ?></h3>
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
                                                <i class="bi bi-arrows-angle-expand text-info fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Total Area</h6>
                                            <h3 class="mb-0 fw-bold">
                                                <?php 
                                                $total_area = $summary['total_area'] ?? 0;
                                                echo $total_area ? number_format($total_area, 2) . ' m²' : '0 m²'; 
                                                ?>
                                            </h3>
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
                                                <i class="bi bi-pie-chart text-secondary fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Avg Size</h6>
                                            <h3 class="mb-0 fw-bold">
                                                <?php 
                                                $avg_area = $summary['avg_area'] ?? 0;
                                                echo $avg_area ? number_format($avg_area, 2) . ' m²' : '0 m²'; 
                                                ?>
                                            </h3>
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
                                        <i class="bi bi-pie-chart text-primary me-2"></i>Parcels by Zoning
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <canvas id="zoningChart" style="height: 300px;"></canvas>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-xl-6">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                                    <h5 class="card-title mb-0 fw-bold">
                                        <i class="bi bi-exclamation-triangle text-warning me-2"></i>Parcels Needing Attention
                                    </h5>
                                </div>
                                <div class="card-body p-0">
                                    <div class="list-group list-group-flush">
                                        <?php if (empty($parcels_with_issues)): ?>
                                        <div class="list-group-item text-center text-muted py-4">
                                            <i class="bi bi-check-circle text-success fs-1 d-block mb-2"></i>
                                            No parcels with issues found
                                        </div>
                                        <?php else: ?>
                                            <?php foreach ($parcels_with_issues as $issue): ?>
                                            <div class="list-group-item">
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <div>
                                                        <h6 class="mb-1"><?php echo htmlspecialchars($issue['parcel_number']); ?></h6>
                                                        <small class="text-muted"><?php echo htmlspecialchars($issue['location']); ?></small>
                                                    </div>
                                                    <span class="badge bg-<?php 
                                                        echo $issue['issue_type'] == 'No Title' ? 'danger' : 
                                                            ($issue['issue_type'] == 'Has Disputes' ? 'warning' : 
                                                            ($issue['issue_type'] == 'Has Encumbrances' ? 'info' : 'secondary')); 
                                                    ?>">
                                                        <?php echo $issue['issue_type']; ?>
                                                    </span>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Google Maps Section -->
                    <div class="card border-0 shadow-sm mb-5">
                        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0 fw-bold">
                                <i class="bi bi-geo-alt text-danger me-2"></i>Parcels Map
                            </h5>
                            <div>
                                <span class="badge bg-primary me-2" id="map-status">Loading map...</span>
                                <button class="btn btn-sm btn-outline-primary" onclick="centerMap()">
                                    <i class="bi bi-crosshair"></i> Center Map
                                </button>
                                <button class="btn btn-sm btn-outline-success" onclick="showAllMarkers()">
                                    <i class="bi bi-eye"></i> Show All
                                </button>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <div id="parcels-map" style="height: 500px; width: 100%;"></div>
                        </div>
                        <div class="card-footer bg-white py-2">
                            <div class="row">
                                <div class="col-md-8">
                                    <small class="text-muted">
                                        <i class="bi bi-info-circle me-1"></i>
                                        Click on markers to view parcel details. 
                                        <span class="badge bg-success ms-2">🟢 With Title</span>
                                        <span class="badge bg-warning ms-2">🟡 No Title</span>
                                        <span class="badge bg-danger ms-2">🔴 Has Issues</span>
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

                    <!-- Parcels Table -->
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white py-3">
                            <h5 class="card-title mb-0 fw-bold">
                                <i class="bi bi-table me-2"></i>Parcels List
                                <span class="badge bg-secondary ms-2"><?php echo count($parcels); ?> records</span>
                            </h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0" id="parcelsTable">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>Parcel #</th>
                                            <th>Location</th>
                                            <th>Area (m²)</th>
                                            <th>Zoning</th>
                                            <th>Title #</th>
                                            <th>Owner</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($parcels)): ?>
                                        <tr>
                                            <td colspan="8" class="text-center py-4 text-muted">
                                                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                                No parcels found
                                            </td>
                                        </tr>
                                        <?php else: ?>
                                            <?php foreach ($parcels as $parcel): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($parcel['parcel_number']); ?></strong>
                                                    <?php if ($parcel['survey_number']): ?>
                                                    <br><small class="text-muted">Survey: <?php echo htmlspecialchars($parcel['survey_number']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div><?php echo htmlspecialchars($parcel['boma_name']); ?>, <?php echo htmlspecialchars($parcel['payam_name']); ?></div>
                                                    <small class="text-muted"><?php echo htmlspecialchars($parcel['county_name']); ?> / <?php echo htmlspecialchars($parcel['state_name']); ?></small>
                                                    <?php if ($parcel['location_description']): ?>
                                                    <br><small class="text-muted"><i class="bi bi-pin"></i> <?php echo htmlspecialchars(substr($parcel['location_description'], 0, 50)) . (strlen($parcel['location_description']) > 50 ? '...' : ''); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php echo number_format($parcel['area'], 2); ?>
                                                </td>
                                                <td>
                                                    <?php if ($parcel['zone_code']): ?>
                                                    <span class="badge bg-info">
                                                        <?php echo htmlspecialchars($parcel['zone_code']); ?>
                                                    </span>
                                                    <small class="d-block text-muted"><?php echo htmlspecialchars($parcel['zone_name']); ?></small>
                                                    <?php else: ?>
                                                    <span class="badge bg-secondary">Not zoned</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($parcel['title_number']): ?>
                                                    <strong><?php echo htmlspecialchars($parcel['title_number']); ?></strong>
                                                    <br><small class="text-muted"><?php echo ucfirst($parcel['title_type']); ?></small>
                                                    <?php else: ?>
                                                    <span class="badge bg-warning">No Title</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($parcel['owner_name']): ?>
                                                    <?php echo htmlspecialchars($parcel['owner_name']); ?>
                                                    <br><small class="text-muted"><?php echo ucfirst($parcel['owner_type']); ?></small>
                                                    <?php else: ?>
                                                    <span class="text-muted">Unknown</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php
                                                    $statusClass = 'success';
                                                    $statusText = 'Active';
                                                    
                                                    if (!$parcel['title_id']) {
                                                        $statusClass = 'warning';
                                                        $statusText = 'No Title';
                                                    } elseif ($parcel['total_disputes'] > 0) {
                                                        $statusClass = 'danger';
                                                        $statusText = 'Has Disputes';
                                                    } elseif ($parcel['total_encumbrances'] > 0) {
                                                        $statusClass = 'info';
                                                        $statusText = 'Has Encumbrances';
                                                    }
                                                    ?>
                                                    <span class="badge bg-<?php echo $statusClass; ?>">
                                                        <?php echo $statusText; ?>
                                                    </span>
                                                    <?php if ($parcel['total_applications'] > 0): ?>
                                                    <br><small class="text-muted"><?php echo $parcel['total_applications']; ?> apps</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="btn-group">
                                                        <button class="btn btn-sm btn-outline-primary" 
                                                                onclick="viewParcel(<?php echo htmlspecialchars(json_encode($parcel)); ?>)"
                                                                data-bs-toggle="modal" data-bs-target="#viewParcelModal">
                                                            <i class="bi bi-eye"></i>
                                                        </button>
                                                        <button class="btn btn-sm btn-outline-success" 
                                                                onclick="editParcel(<?php echo htmlspecialchars(json_encode($parcel)); ?>)"
                                                                data-bs-toggle="modal" data-bs-target="#editParcelModal">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                        <?php if ($parcel['title_id']): ?>
                                                        <button class="btn btn-sm btn-outline-info" 
                                                                onclick="transferOwnership(<?php echo htmlspecialchars(json_encode($parcel)); ?>)"
                                                                data-bs-toggle="modal" data-bs-target="#transferModal">
                                                            <i class="bi bi-arrow-left-right"></i>
                                                        </button>
                                                        <?php endif; ?>
                                                        <?php if ($parcel['latitude'] && $parcel['longitude']): ?>
                                                        <button class="btn btn-sm btn-outline-secondary" 
                                                                onclick="flyToParcel(<?php echo $parcel['latitude']; ?>, <?php echo $parcel['longitude']; ?>, 16)">
                                                            <i class="bi bi-geo-alt"></i>
                                                        </button>
                                                        <?php endif; ?>
                                                        <button class="btn btn-sm btn-outline-danger" 
                                                                onclick="confirmDelete(<?php echo $parcel['id']; ?>, '<?php echo htmlspecialchars($parcel['parcel_number']); ?>')">
                                                            <i class="bi bi-trash"></i>
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
                    </div>

                </div>
            </main>

            <!-- Footer -->
            <?php include("footer.php"); ?>

        </div>
    </div>

    <!-- Add Parcel Modal -->
    <div class="modal fade" id="addParcelModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="add">
                    <div class="modal-header">
                        <h5 class="modal-title">Add New Parcel</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <ul class="nav nav-tabs mb-3" id="parcelTab" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="basic-tab" data-bs-toggle="tab" data-bs-target="#basic" type="button" role="tab">Basic Info</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="location-tab" data-bs-toggle="tab" data-bs-target="#location" type="button" role="tab">Location</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="coordinates-tab" data-bs-toggle="tab" data-bs-target="#coordinates" type="button" role="tab">Coordinates</button>
                            </li>
                        </ul>
                        
                        <div class="tab-content">
                            <div class="tab-pane fade show active" id="basic" role="tabpanel">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Parcel Number <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="parcel_number" required 
                                               placeholder="e.g., PARCEL/2024/001">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Survey Number</label>
                                        <input type="text" class="form-control" name="survey_number" 
                                               placeholder="e.g., SURV/001/2024">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Area (m²)</label>
                                        <input type="number" class="form-control" name="area" step="0.01" 
                                               placeholder="e.g., 5000.00">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Zoning Type</label>
                                        <select class="form-select" name="current_zoning_id">
                                            <option value="">Select Zoning</option>
                                            <?php foreach ($zoning_types as $zone): ?>
                                            <option value="<?php echo $zone['id']; ?>">
                                                <?php echo htmlspecialchars($zone['zone_code'] . ' - ' . $zone['zone_name']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="tab-pane fade" id="location" role="tabpanel">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">State <span class="text-danger">*</span></label>
                                        <select class="form-select" id="add_state_id" required onchange="loadCounties(this.value, 'add_county_id')">
                                            <option value="">Select State</option>
                                            <?php foreach ($states as $state): ?>
                                            <option value="<?php echo $state['id']; ?>"><?php echo htmlspecialchars($state['name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">County <span class="text-danger">*</span></label>
                                        <select class="form-select" id="add_county_id" required onchange="loadPayams(this.value, 'add_payam_id')" disabled>
                                            <option value="">Select County</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Payam <span class="text-danger">*</span></label>
                                        <select class="form-select" id="add_payam_id" required onchange="loadBomas(this.value, 'add_boma_id')" disabled>
                                            <option value="">Select Payam</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Boma <span class="text-danger">*</span></label>
                                        <select class="form-select" name="boma_id" id="add_boma_id" required disabled>
                                            <option value="">Select Boma</option>
                                        </select>
                                    </div>
                                    <div class="col-md-12 mb-3">
                                        <label class="form-label">Location Description</label>
                                        <textarea class="form-control" name="location_description" rows="3" 
                                                  placeholder="Detailed description of the parcel location..."></textarea>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="tab-pane fade" id="coordinates" role="tabpanel">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Latitude</label>
                                        <input type="number" class="form-control" name="latitude" step="0.000001" 
                                               placeholder="e.g., 4.850000">
                                        <small class="text-muted">Use Google Maps to find coordinates</small>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Longitude</label>
                                        <input type="number" class="form-control" name="longitude" step="0.000001" 
                                               placeholder="e.g., 31.600000">
                                    </div>
                                    <div class="col-md-12">
                                        <div class="alert alert-info">
                                            <i class="bi bi-info-circle me-2"></i>
                                            <strong>Quick Coordinates for Juba Area:</strong><br>
                                            Juba Center: 4.851667, 31.582500 | Custom Market: 4.846667, 31.601667<br>
                                            Gudele: 4.858333, 31.568333 | Rock City: 4.863333, 31.575000
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Parcel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Parcel Modal -->
    <div class="modal fade" id="editParcelModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="parcel_id" id="editParcelId">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Parcel</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <ul class="nav nav-tabs mb-3" id="editParcelTab" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="edit-basic-tab" data-bs-toggle="tab" data-bs-target="#edit-basic" type="button" role="tab">Basic Info</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="edit-location-tab" data-bs-toggle="tab" data-bs-target="#edit-location" type="button" role="tab">Location</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="edit-coordinates-tab" data-bs-toggle="tab" data-bs-target="#edit-coordinates" type="button" role="tab">Coordinates</button>
                            </li>
                        </ul>
                        
                        <div class="tab-content">
                            <div class="tab-pane fade show active" id="edit-basic" role="tabpanel">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Parcel Number <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="parcel_number" id="editParcelNumber" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Survey Number</label>
                                        <input type="text" class="form-control" name="survey_number" id="editSurveyNumber">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Area (m²)</label>
                                        <input type="number" class="form-control" name="area" id="editArea" step="0.01">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Zoning Type</label>
                                        <select class="form-select" name="current_zoning_id" id="editZoningId">
                                            <option value="">Select Zoning</option>
                                            <?php foreach ($zoning_types as $zone): ?>
                                            <option value="<?php echo $zone['id']; ?>">
                                                <?php echo htmlspecialchars($zone['zone_code'] . ' - ' . $zone['zone_name']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="tab-pane fade" id="edit-location" role="tabpanel">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">State</label>
                                        <select class="form-select" id="edit_state_id" onchange="loadCounties(this.value, 'edit_county_id')">
                                            <option value="">Select State</option>
                                            <?php foreach ($states as $state): ?>
                                            <option value="<?php echo $state['id']; ?>"><?php echo htmlspecialchars($state['name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">County</label>
                                        <select class="form-select" id="edit_county_id" onchange="loadPayams(this.value, 'edit_payam_id')" disabled>
                                            <option value="">Select County</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Payam</label>
                                        <select class="form-select" id="edit_payam_id" onchange="loadBomas(this.value, 'edit_boma_id')" disabled>
                                            <option value="">Select Payam</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Boma</label>
                                        <select class="form-select" name="boma_id" id="edit_boma_id" disabled>
                                            <option value="">Select Boma</option>
                                        </select>
                                    </div>
                                    <div class="col-md-12 mb-3">
                                        <label class="form-label">Location Description</label>
                                        <textarea class="form-control" name="location_description" id="editLocationDescription" rows="3"></textarea>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="tab-pane fade" id="edit-coordinates" role="tabpanel">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Latitude</label>
                                        <input type="number" class="form-control" name="latitude" id="editLatitude" step="0.000001">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Longitude</label>
                                        <input type="number" class="form-control" name="longitude" id="editLongitude" step="0.000001">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Parcel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Parcel Modal -->
    <div class="modal fade" id="viewParcelModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Parcel Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <table class="table table-borderless">
                                <tr>
                                    <th width="40%">Parcel Number:</th>
                                    <td><strong id="viewParcelNumber"></strong></td>
                                </tr>
                                <tr>
                                    <th>Survey Number:</th>
                                    <td id="viewSurveyNumber"></td>
                                </tr>
                                <tr>
                                    <th>Area:</th>
                                    <td id="viewArea"></td>
                                </tr>
                                <tr>
                                    <th>Zoning:</th>
                                    <td id="viewZoning"></td>
                                </tr>
                                <tr>
                                    <th>Title Number:</th>
                                    <td id="viewTitleNumber"></td>
                                </tr>
                                <tr>
                                    <th>Title Type:</th>
                                    <td id="viewTitleType"></td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <table class="table table-borderless">
                                <tr>
                                    <th width="40%">State:</th>
                                    <td id="viewState"></td>
                                </tr>
                                <tr>
                                    <th>County:</th>
                                    <td id="viewCounty"></td>
                                </tr>
                                <tr>
                                    <th>Payam:</th>
                                    <td id="viewPayam"></td>
                                </tr>
                                <tr>
                                    <th>Boma:</th>
                                    <td id="viewBoma"></td>
                                </tr>
                                <tr>
                                    <th>Owner:</th>
                                    <td id="viewOwner"></td>
                                </tr>
                                <tr>
                                    <th>Status:</th>
                                    <td id="viewStatus"></td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-12">
                            <hr>
                            <h6>Location Description</h6>
                            <p id="viewLocationDescription" class="text-muted"></p>
                            
                            <h6>Statistics</h6>
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="border rounded p-2 text-center">
                                        <small class="text-muted d-block">Titles</small>
                                        <span class="fw-bold" id="viewTotalTitles">0</span>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="border rounded p-2 text-center">
                                        <small class="text-muted d-block">Applications</small>
                                        <span class="fw-bold" id="viewTotalApplications">0</span>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="border rounded p-2 text-center">
                                        <small class="text-muted d-block">Encumbrances</small>
                                        <span class="fw-bold" id="viewTotalEncumbrances">0</span>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="border rounded p-2 text-center">
                                        <small class="text-muted d-block">Disputes</small>
                                        <span class="fw-bold" id="viewTotalDisputes">0</span>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if (!empty($parcel['latitude']) && !empty($parcel['longitude'])): ?>
                            <div class="mt-3">
                                <h6>Location Map</h6>
                                <div id="view-map" style="height: 250px; width: 100%;"></div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Transfer Ownership Modal -->
    <div class="modal fade" id="transferModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="transfer">
                    <input type="hidden" name="parcel_id" id="transferParcelId">
                    <div class="modal-header">
                        <h5 class="modal-title">Transfer Ownership</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Parcel</label>
                            <input type="text" class="form-control" id="transferParcelNumber" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Current Owner</label>
                            <input type="text" class="form-control" id="transferCurrentOwner" readonly>
                            <input type="hidden" name="from_party_id" id="transferFromPartyId">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">New Owner <span class="text-danger">*</span></label>
                            <select class="form-select" name="to_party_id" required>
                                <option value="">Select New Owner</option>
                                <?php foreach ($parties as $party): ?>
                                <option value="<?php echo $party['id']; ?>">
                                    <?php echo htmlspecialchars($party['name'] . ' (' . ucfirst($party['party_type']) . ')'); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Transaction Type</label>
                            <select class="form-select" name="transaction_type">
                                <option value="transfer">Transfer</option>
                                <option value="sale">Sale</option>
                                <option value="gift">Gift</option>
                                <option value="inheritance">Inheritance</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Transfer Date</label>
                            <input type="date" class="form-control" name="transfer_date" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Consideration Amount</label>
                            <input type="number" class="form-control" name="consideration_amount" step="0.01" placeholder="0.00">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Transfer Ownership</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirm Delete</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete parcel <strong id="deleteParcelNumber"></strong>?</p>
                    <p class="text-danger"><i class="bi bi-exclamation-triangle"></i> This action cannot be undone. Make sure there are no related records (titles, applications, etc.) before deleting.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <a href="#" id="deleteConfirmBtn" class="btn btn-danger">Delete</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Google Maps JavaScript -->
    <script src="https://maps.googleapis.com/maps/api/js?key=YOUR_GOOGLE_MAPS_API_KEY&callback=initMap" async defer></script>
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <script>
        // Global variables
        let map;
        let markers = [];
        let activeInfoWindow = null;
        let bounds;
        let viewMap = null;
        
        // Initialize main map
        function initMap() {
            // Set map center to South Sudan
            const southSudan = { lat: 7.5, lng: 30.0 };
            
            // Create map
            map = new google.maps.Map(document.getElementById('parcels-map'), {
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
            
            // Add markers for parcels
            <?php foreach ($parcel_markers as $parcel): ?>
            addParcelMarker(
                <?php echo $parcel['lat']; ?>, 
                <?php echo $parcel['lng']; ?>,
                '<?php echo htmlspecialchars($parcel['parcel_number']); ?>',
                '<?php echo htmlspecialchars($parcel['location'] ?? ''); ?>',
                '<?php echo htmlspecialchars($parcel['boma']); ?>',
                '<?php echo htmlspecialchars($parcel['payam']); ?>',
                '<?php echo htmlspecialchars($parcel['county']); ?>',
                '<?php echo htmlspecialchars($parcel['state']); ?>',
                <?php echo $parcel['area'] ?: 0; ?>,
                '<?php echo htmlspecialchars($parcel['owner'] ?? 'Unknown'); ?>',
                '<?php echo htmlspecialchars($parcel['title_number'] ?? 'No Title'); ?>',
                '<?php echo $parcel['zone'] ?? 'N/A'; ?>',
                '<?php echo $parcel['status'] ?? 'No Title'; ?>'
            );
            <?php endforeach; ?>
            
            // Fit map to show all markers if there are any
            if (markers.length > 0) {
                map.fitBounds(bounds);
            } else {
                // Otherwise center on South Sudan
                map.setCenter(southSudan);
                map.setZoom(7);
            }
            
            // Update map status
            document.getElementById('map-status').innerHTML = 'Map loaded - ' + markers.length + ' parcels';
            
            // Add click listener to map
            map.addListener('click', function() {
                if (activeInfoWindow) {
                    activeInfoWindow.close();
                }
            });
            
            // Initialize zoning chart
            initZoningChart();
        }
        
        // Add parcel marker
        function addParcelMarker(lat, lng, parcelNumber, location, boma, payam, county, state, area, owner, titleNumber, zone, status) {
            const position = { lat: lat, lng: lng };
            
            // Determine marker color based on status
            let iconUrl = 'http://maps.google.com/mapfiles/ms/icons/green-dot.png'; // With title
            if (status === 'No Title') {
                iconUrl = 'http://maps.google.com/mapfiles/ms/icons/yellow-dot.png';
            } else if (status === 'Has Disputes' || status === 'Has Encumbrances') {
                iconUrl = 'http://maps.google.com/mapfiles/ms/icons/red-dot.png';
            }
            
            // Create marker
            const marker = new google.maps.Marker({
                position: position,
                map: map,
                title: parcelNumber,
                icon: {
                    url: iconUrl,
                    scaledSize: new google.maps.Size(32, 32)
                },
                animation: google.maps.Animation.DROP
            });
            
            // Create info window content
            const contentString = `
                <div style="padding: 10px; max-width: 280px;">
                    <h5 style="margin: 0 0 5px 0; color: #0d6efd;">${parcelNumber}</h5>
                    <p style="margin: 0 0 3px 0;"><strong>Location:</strong> ${boma}, ${payam}</p>
                    <p style="margin: 0 0 3px 0;"><strong>Hierarchy:</strong> ${county} / ${state}</p>
                    <p style="margin: 0 0 3px 0;"><strong>Area:</strong> ${area.toFixed(2)} m²</p>
                    <p style="margin: 0 0 3px 0;"><strong>Zoning:</strong> ${zone}</p>
                    <p style="margin: 0 0 3px 0;"><strong>Title:</strong> ${titleNumber}</p>
                    <p style="margin: 0 0 3px 0;"><strong>Owner:</strong> ${owner}</p>
                    <p style="margin: 0 0 5px 0;"><strong>Status:</strong> <span style="color: ${status === 'With Title' ? '#28a745' : (status === 'No Title' ? '#ffc107' : '#dc3545')};">${status}</span></p>
                    <button onclick="flyToParcel(${lat}, ${lng}, 18)" style="background: #0d6efd; color: white; border: none; padding: 5px 10px; border-radius: 4px; cursor: pointer; width: 100%;">
                        Zoom to Parcel
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
        
        // Fly to specific parcel
        function flyToParcel(lat, lng, zoom) {
            if (lat && lng) {
                map.setCenter({ lat: parseFloat(lat), lng: parseFloat(lng) });
                map.setZoom(parseInt(zoom) || 16);
                
                // Highlight the marker temporarily
                markers.forEach(marker => {
                    const markerPos = marker.getPosition();
                    if (Math.abs(markerPos.lat() - parseFloat(lat)) < 0.001 && 
                        Math.abs(markerPos.lng() - parseFloat(lng)) < 0.001) {
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
        
        // Show all markers
        function showAllMarkers() {
            map.fitBounds(bounds);
            if (activeInfoWindow) {
                activeInfoWindow.close();
            }
        }
        
        // Initialize zoning chart
        function initZoningChart() {
            const ctx = document.getElementById('zoningChart')?.getContext('2d');
            if (!ctx) return;
            
            const data = {
                labels: [
                    <?php foreach ($parcels_by_zoning as $zone): ?>
                    '<?php echo htmlspecialchars($zone['zone_name']); ?>',
                    <?php endforeach; ?>
                ],
                datasets: [{
                    label: 'Number of Parcels',
                    data: [
                        <?php foreach ($parcels_by_zoning as $zone): ?>
                        <?php echo $zone['count']; ?>,
                        <?php endforeach; ?>
                    ],
                    backgroundColor: [
                        'rgba(54, 162, 235, 0.8)',
                        'rgba(255, 99, 132, 0.8)',
                        'rgba(255, 206, 86, 0.8)',
                        'rgba(75, 192, 192, 0.8)',
                        'rgba(153, 102, 255, 0.8)',
                        'rgba(255, 159, 64, 0.8)'
                    ],
                    borderWidth: 1
                }]
            };
            
            new Chart(ctx, {
                type: 'doughnut',
                data: data,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });
        }
        
        // Load counties based on state selection
        function loadCounties(stateId, targetSelectId) {
            if (!stateId) {
                document.getElementById(targetSelectId).innerHTML = '<option value="">Select County</option>';
                document.getElementById(targetSelectId).disabled = true;
                return;
            }
            
            fetch('ajax/get_counties.php?state_id=' + stateId)
                .then(response => response.json())
                .then(data => {
                    let options = '<option value="">Select County</option>';
                    data.forEach(county => {
                        options += `<option value="${county.id}">${county.name}</option>`;
                    });
                    document.getElementById(targetSelectId).innerHTML = options;
                    document.getElementById(targetSelectId).disabled = false;
                });
        }
        
        // Load payams based on county selection
        function loadPayams(countyId, targetSelectId) {
            if (!countyId) {
                document.getElementById(targetSelectId).innerHTML = '<option value="">Select Payam</option>';
                document.getElementById(targetSelectId).disabled = true;
                return;
            }
            
            fetch('ajax/get_payams.php?county_id=' + countyId)
                .then(response => response.json())
                .then(data => {
                    let options = '<option value="">Select Payam</option>';
                    data.forEach(payam => {
                        options += `<option value="${payam.id}">${payam.name}</option>`;
                    });
                    document.getElementById(targetSelectId).innerHTML = options;
                    document.getElementById(targetSelectId).disabled = false;
                });
        }
        
        // Load bomas based on payam selection
        function loadBomas(payamId, targetSelectId) {
            if (!payamId) {
                document.getElementById(targetSelectId).innerHTML = '<option value="">Select Boma</option>';
                document.getElementById(targetSelectId).disabled = true;
                return;
            }
            
            fetch('ajax/get_bomas.php?payam_id=' + payamId)
                .then(response => response.json())
                .then(data => {
                    let options = '<option value="">Select Boma</option>';
                    data.forEach(boma => {
                        options += `<option value="${boma.id}">${boma.name}</option>`;
                    });
                    document.getElementById(targetSelectId).innerHTML = options;
                    document.getElementById(targetSelectId).disabled = false;
                });
        }
        
        // View parcel details
        function viewParcel(parcel) {
            document.getElementById('viewParcelNumber').textContent = parcel.parcel_number || 'N/A';
            document.getElementById('viewSurveyNumber').textContent = parcel.survey_number || 'N/A';
            document.getElementById('viewArea').textContent = parcel.area ? parcel.area.toFixed(2) + ' m²' : 'N/A';
            document.getElementById('viewZoning').textContent = parcel.zone_code ? parcel.zone_code + ' - ' + parcel.zone_name : 'Not zoned';
            document.getElementById('viewTitleNumber').textContent = parcel.title_number || 'No Title';
            document.getElementById('viewTitleType').textContent = parcel.title_type ? ucfirst(parcel.title_type) : 'N/A';
            
            document.getElementById('viewState').textContent = parcel.state_name || 'N/A';
            document.getElementById('viewCounty').textContent = parcel.county_name || 'N/A';
            document.getElementById('viewPayam').textContent = parcel.payam_name || 'N/A';
            document.getElementById('viewBoma').textContent = parcel.boma_name || 'N/A';
            document.getElementById('viewOwner').textContent = parcel.owner_name || 'Unknown';
            
            let statusText = 'Active';
            let statusClass = 'success';
            if (!parcel.title_id) {
                statusText = 'No Title';
                statusClass = 'warning';
            } else if (parcel.total_disputes > 0) {
                statusText = 'Has Disputes';
                statusClass = 'danger';
            } else if (parcel.total_encumbrances > 0) {
                statusText = 'Has Encumbrances';
                statusClass = 'info';
            }
            document.getElementById('viewStatus').innerHTML = `<span class="badge bg-${statusClass}">${statusText}</span>`;
            
            document.getElementById('viewLocationDescription').textContent = parcel.location_description || 'No description provided';
            
            document.getElementById('viewTotalTitles').textContent = parcel.total_titles || 0;
            document.getElementById('viewTotalApplications').textContent = parcel.total_applications || 0;
            document.getElementById('viewTotalEncumbrances').textContent = parcel.total_encumbrances || 0;
            document.getElementById('viewTotalDisputes').textContent = parcel.total_disputes || 0;
            
            // Initialize view map if coordinates exist
            if (parcel.latitude && parcel.longitude && document.getElementById('view-map')) {
                setTimeout(() => {
                    if (viewMap) {
                        viewMap.setCenter({ lat: parseFloat(parcel.latitude), lng: parseFloat(parcel.longitude) });
                    } else {
                        viewMap = new google.maps.Map(document.getElementById('view-map'), {
                            center: { lat: parseFloat(parcel.latitude), lng: parseFloat(parcel.longitude) },
                            zoom: 15,
                            mapTypeId: 'satellite'
                        });
                        
                        new google.maps.Marker({
                            position: { lat: parseFloat(parcel.latitude), lng: parseFloat(parcel.longitude) },
                            map: viewMap,
                            title: parcel.parcel_number
                        });
                    }
                }, 500);
            }
        }
        
        // Edit parcel
        function editParcel(parcel) {
            document.getElementById('editParcelId').value = parcel.id;
            document.getElementById('editParcelNumber').value = parcel.parcel_number || '';
            document.getElementById('editSurveyNumber').value = parcel.survey_number || '';
            document.getElementById('editArea').value = parcel.area || '';
            document.getElementById('editZoningId').value = parcel.current_zoning_id || '';
            document.getElementById('editLocationDescription').value = parcel.location_description || '';
            document.getElementById('editLatitude').value = parcel.latitude || '';
            document.getElementById('editLongitude').value = parcel.longitude || '';
            
            // Set hierarchy selections
            if (parcel.state_id) {
                document.getElementById('edit_state_id').value = parcel.state_id;
                loadCounties(parcel.state_id, 'edit_county_id');
                
                setTimeout(() => {
                    if (parcel.county_id) {
                        document.getElementById('edit_county_id').value = parcel.county_id;
                        loadPayams(parcel.county_id, 'edit_payam_id');
                        
                        setTimeout(() => {
                            if (parcel.payam_id) {
                                document.getElementById('edit_payam_id').value = parcel.payam_id;
                                loadBomas(parcel.payam_id, 'edit_boma_id');
                                
                                setTimeout(() => {
                                    if (parcel.boma_id) {
                                        document.getElementById('edit_boma_id').value = parcel.boma_id;
                                    }
                                }, 300);
                            }
                        }, 300);
                    }
                }, 300);
            }
        }
        
        // Transfer ownership
        function transferOwnership(parcel) {
            document.getElementById('transferParcelId').value = parcel.id;
            document.getElementById('transferParcelNumber').value = parcel.parcel_number;
            document.getElementById('transferCurrentOwner').value = parcel.owner_name || 'Unknown';
            document.getElementById('transferFromPartyId').value = parcel.owner_id || '';
        }
        
        // Confirm delete
        function confirmDelete(id, parcelNumber) {
            document.getElementById('deleteParcelNumber').textContent = parcelNumber;
            document.getElementById('deleteConfirmBtn').href = '?delete=' + id;
            new bootstrap.Modal(document.getElementById('deleteModal')).show();
        }
        
        // Helper function to capitalize first letter
        function ucfirst(str) {
            return str.charAt(0).toUpperCase() + str.slice(1);
        }
        
        // Search functionality for table
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('tableSearch');
            if (searchInput) {
                searchInput.addEventListener('keyup', function() {
                    const searchText = this.value.toLowerCase();
                    const table = document.getElementById('parcelsTable');
                    const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
                    
                    Array.from(rows).forEach(row => {
                        const text = row.textContent.toLowerCase();
                        if (text.includes(searchText)) {
                            row.style.display = '';
                        } else {
                            row.style.display = 'none';
                        }
                    });
                });
            }
        });
    </script>

    <style>
        .parcel-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
        }
        
        .parcel-card {
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .parcel-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1) !important;
        }
        
        .badge {
            font-size: 0.85rem;
        }
        
        .btn-group .btn {
            padding: 0.25rem 0.5rem;
        }
        
        #parcels-map {
            border-radius: 0 0 4px 4px;
        }
        
        .gm-style .gm-style-iw-c {
            padding: 12px;
        }
        
        .gm-style .gm-style-iw-t::after {
            background: linear-gradient(45deg, rgba(255,255,255,1) 50%, rgba(255,255,255,0) 51%, rgba(255,255,255,0) 100%);
        }
        
        .table th {
            font-weight: 600;
            color: #495057;
        }
        
        .modal-body .table-borderless th {
            width: 40%;
        }
        
        @media (max-width: 768px) {
            #parcels-map {
                height: 350px !important;
            }
            
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