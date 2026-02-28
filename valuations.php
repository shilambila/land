<?php
ini_set('display_errors', 1); 
error_reporting(E_ALL);

// valuations.php - Property Valuations Management
include("head.php");
require_once 'db_connection.php';

// ============================================================================
// HANDLE ACTIONS
// ============================================================================

$message = '';
$messageType = '';

// Delete valuation
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $valuationId = $_GET['delete'];
    
    try {
        executeQuery($conn, "DELETE FROM valuations WHERE id = ?", [$valuationId]);
        $message = "Valuation record deleted successfully";
        $messageType = "success";
    } catch (Exception $e) {
        $message = "Error deleting valuation: " . $e->getMessage();
        $messageType = "danger";
        error_log("Delete error: " . $e->getMessage());
    }
}

// Add new valuation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $parcel_id = $_POST['parcel_id'];
    $valuation_date = $_POST['valuation_date'];
    $value = $_POST['value'];
    $assessed_by = $_POST['assessed_by'];
    $notes = $_POST['notes'] ?? null;
    
    try {
        // Start transaction
        beginTransaction($conn);
        
        // Check if parcel exists
        $parcel = fetchOne($conn, "SELECT id, parcel_number, area FROM parcels WHERE id = ?", [$parcel_id]);
        if (!$parcel) {
            throw new Exception("Parcel not found");
        }
        
        // Insert valuation
        executeQuery($conn, "
            INSERT INTO valuations (
                parcel_id, valuation_date, value, assessed_by, notes, created_at
            ) VALUES (?, ?, ?, ?, ?, NOW())
        ", [$parcel_id, $valuation_date, $value, $assessed_by, $notes]);
        
        $valuation_id = $conn->lastInsertId();
        
        // Commit transaction
        commitTransaction($conn);
        
        $message = "Valuation record added successfully";
        $messageType = "success";
        
    } catch (Exception $e) {
        rollbackTransaction($conn);
        $message = "Error adding valuation: " . $e->getMessage();
        $messageType = "danger";
        error_log("Add error: " . $e->getMessage());
    }
}

// Update valuation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit') {
    $valuation_id = $_POST['valuation_id'];
    $valuation_date = $_POST['valuation_date'];
    $value = $_POST['value'];
    $assessed_by = $_POST['assessed_by'];
    $notes = $_POST['notes'] ?? null;
    
    try {
        executeQuery($conn, "
            UPDATE valuations SET 
                valuation_date = ?, 
                value = ?, 
                assessed_by = ?, 
                notes = ?
            WHERE id = ?
        ", [$valuation_date, $value, $assessed_by, $notes, $valuation_id]);
        
        $message = "Valuation record updated successfully";
        $messageType = "success";
        
    } catch (Exception $e) {
        $message = "Error updating valuation: " . $e->getMessage();
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

// Search
if (!empty($_GET['search'])) {
    $search = '%' . $_GET['search'] . '%';
    $where_conditions[] = "(p.parcel_number LIKE ? OR v.assessed_by LIKE ? OR v.notes LIKE ?)";
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
}

// Assessor filter
if (!empty($_GET['assessed_by'])) {
    $where_conditions[] = "v.assessed_by LIKE ?";
    $params[] = '%' . $_GET['assessed_by'] . '%';
}

// Date range filter
if (!empty($_GET['from_date'])) {
    $where_conditions[] = "v.valuation_date >= ?";
    $params[] = $_GET['from_date'];
}
if (!empty($_GET['to_date'])) {
    $where_conditions[] = "v.valuation_date <= ?";
    $params[] = $_GET['to_date'];
}

// Value range filter
if (!empty($_GET['min_value'])) {
    $where_conditions[] = "v.value >= ?";
    $params[] = $_GET['min_value'];
}
if (!empty($_GET['max_value'])) {
    $where_conditions[] = "v.value <= ?";
    $params[] = $_GET['max_value'];
}

// Value per area ratio filter
if (!empty($_GET['min_value_per_area'])) {
    $where_conditions[] = "(v.value / p.area) >= ?";
    $params[] = $_GET['min_value_per_area'];
}
if (!empty($_GET['max_value_per_area'])) {
    $where_conditions[] = "(v.value / p.area) <= ?";
    $params[] = $_GET['max_value_per_area'];
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

$where_clause = "WHERE " . implode(" AND ", $where_conditions);

// Get total count for pagination
$count_sql = "
    SELECT COUNT(*) as total
    FROM valuations v
    JOIN parcels p ON v.parcel_id = p.id
    LEFT JOIN bomas b ON p.boma_id = b.id
    LEFT JOIN payams pa ON b.payam_id = pa.id
    LEFT JOIN counties c ON pa.county_id = c.id
    LEFT JOIN states s ON c.state_id = s.id
    $where_clause
";

$total_result = executeQuery($conn, $count_sql, $params);
$total_rows = $total_result->fetch()['total'];
$total_pages = ceil($total_rows / $limit);

// Get valuations with all related information
$sql = "
    SELECT 
        v.*,
        p.id as parcel_id,
        p.parcel_number,
        p.area as parcel_area,
        p.location_description,
        p.survey_number,
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
        (v.value / NULLIF(p.area, 0)) as value_per_sqm,
        (v.value / NULLIF(p.area, 0) * 10000) as value_per_hectare,
        ta.id as tax_assessment_id,
        ta.tax_year,
        ta.tax_amount,
        ta.status as tax_status
    FROM valuations v
    JOIN parcels p ON v.parcel_id = p.id
    LEFT JOIN bomas b ON p.boma_id = b.id
    LEFT JOIN payams pa ON b.payam_id = pa.id
    LEFT JOIN counties c ON pa.county_id = c.id
    LEFT JOIN states s ON c.state_id = s.id
    LEFT JOIN titles t ON p.id = t.parcel_id AND t.status = 'active'
    LEFT JOIN tax_assessments ta ON p.id = ta.parcel_id AND ta.tax_year = YEAR(v.valuation_date)
    $where_clause
    ORDER BY v.valuation_date DESC, v.created_at DESC
    LIMIT ? OFFSET ?
";

$params[] = $limit;
$params[] = $offset;

$result = executeQuery($conn, $sql, $params);
$valuations = [];
if ($result) {
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        $valuations[] = $row;
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
$selected_payam = $_GET['payam_id'] ?? null;
$selected_boma = $_GET['boma_id'] ?? null;

$counties = [];
if ($selected_state) {
    try {
        $counties = fetchAll($conn, "SELECT id, name FROM counties WHERE state_id = ? ORDER BY name", [$selected_state]);
    } catch (Exception $e) {
        error_log("Error loading counties: " . $e->getMessage());
        $counties = [];
    }
}

$payams = [];
if ($selected_county) {
    try {
        $payams = fetchAll($conn, "SELECT id, name FROM payams WHERE county_id = ? ORDER BY name", [$selected_county]);
    } catch (Exception $e) {
        error_log("Error loading payams: " . $e->getMessage());
        $payams = [];
    }
}

$bomas = [];
if ($selected_payam) {
    try {
        $bomas = fetchAll($conn, "SELECT id, name FROM bomas WHERE payam_id = ? ORDER BY name", [$selected_payam]);
    } catch (Exception $e) {
        error_log("Error loading bomas: " . $e->getMessage());
        $bomas = [];
    }
}

// ============================================================================
// GET DATA FOR DROPDOWNS
// ============================================================================

try {
    // Get all parcels for dropdown
    $parcels = fetchAll($conn, "
        SELECT p.id, p.parcel_number, p.area,
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

try {
    // Get unique assessors for filter
    $assessors = fetchAll($conn, "
        SELECT DISTINCT assessed_by FROM valuations WHERE assessed_by IS NOT NULL ORDER BY assessed_by
    ");
} catch (Exception $e) {
    error_log("Error loading assessors: " . $e->getMessage());
    $assessors = [];
}

// ============================================================================
// SUMMARY STATISTICS
// ============================================================================

try {
    $summary = fetchOne($conn, "
        SELECT 
            COUNT(*) as total_valuations,
            COUNT(DISTINCT parcel_id) as unique_parcels,
            COUNT(DISTINCT assessed_by) as unique_assessors,
            MIN(value) as min_value,
            MAX(value) as max_value,
            AVG(value) as avg_value,
            SUM(value) as total_value,
            AVG(value / NULLIF(area, 0)) as avg_value_per_sqm,
            MIN(valuation_date) as earliest_valuation,
            MAX(valuation_date) as latest_valuation
        FROM valuations v
        JOIN parcels p ON v.parcel_id = p.id
    ");
} catch (Exception $e) {
    error_log("Error loading summary: " . $e->getMessage());
    $summary = [
        'total_valuations' => 0,
        'unique_parcels' => 0,
        'unique_assessors' => 0,
        'min_value' => 0,
        'max_value' => 0,
        'avg_value' => 0,
        'total_value' => 0,
        'avg_value_per_sqm' => 0,
        'earliest_valuation' => null,
        'latest_valuation' => null
    ];
}

try {
    // Monthly trend
    $monthly_trend = fetchAll($conn, "
        SELECT 
            DATE_FORMAT(valuation_date, '%Y-%m') as month,
            COUNT(*) as valuation_count,
            AVG(value) as avg_value,
            SUM(value) as total_value
        FROM valuations
        WHERE valuation_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(valuation_date, '%Y-%m')
        ORDER BY month DESC
    ");
} catch (Exception $e) {
    error_log("Error loading monthly trend: " . $e->getMessage());
    $monthly_trend = [];
}

try {
    // Top assessors
    $top_assessors = fetchAll($conn, "
        SELECT 
            assessed_by,
            COUNT(*) as valuation_count,
            AVG(value) as avg_value,
            SUM(value) as total_value,
            COUNT(DISTINCT parcel_id) as parcels_assessed
        FROM valuations
        WHERE assessed_by IS NOT NULL
        GROUP BY assessed_by
        ORDER BY valuation_count DESC
        LIMIT 5
    ");
} catch (Exception $e) {
    error_log("Error loading top assessors: " . $e->getMessage());
    $top_assessors = [];
}

try {
    // Value distribution by area range
    $value_distribution = fetchAll($conn, "
        SELECT 
            CASE 
                WHEN p.area < 1000 THEN '< 1,000 m²'
                WHEN p.area BETWEEN 1000 AND 5000 THEN '1,000 - 5,000 m²'
                WHEN p.area BETWEEN 5001 AND 10000 THEN '5,001 - 10,000 m²'
                WHEN p.area BETWEEN 10001 AND 50000 THEN '10,001 - 50,000 m²'
                ELSE '> 50,000 m²'
            END as area_range,
            COUNT(*) as count,
            AVG(v.value) as avg_value,
            AVG(v.value / p.area) as avg_price_per_sqm
        FROM valuations v
        JOIN parcels p ON v.parcel_id = p.id
        GROUP BY area_range
        ORDER BY MIN(p.area)
    ");
} catch (Exception $e) {
    error_log("Error loading value distribution: " . $e->getMessage());
    $value_distribution = [];
}

try {
    // Top valued parcels
    $top_parcels = fetchAll($conn, "
        SELECT 
            p.parcel_number,
            p.area,
            v.value,
            (v.value / p.area) as price_per_sqm,
            v.valuation_date,
            v.assessed_by,
            CONCAT(b.name, ', ', pa.name) as location
        FROM valuations v
        JOIN parcels p ON v.parcel_id = p.id
        JOIN bomas b ON p.boma_id = b.id
        JOIN payams pa ON b.payam_id = pa.id
        ORDER BY v.value DESC
        LIMIT 5
    ");
} catch (Exception $e) {
    error_log("Error loading top parcels: " . $e->getMessage());
    $top_parcels = [];
}

// Format currency function
function formatCurrency($amount) {
    return '$' . number_format($amount, 2);
}

// Format value per area
function formatValuePerArea($value, $area) {
    if ($area <= 0) return 'N/A';
    return '$' . number_format($value / $area, 2) . '/m²';
}
?>

<body data-page="valuations" class="valuations-page">
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
                            <h1 class="h3 mb-0">Property Valuations</h1>
                            <p class="text-muted mb-0">Manage land and property valuation records</p>
                        </div>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addValuationModal">
                            <i class="bi bi-cash-stack me-2"></i>Add Valuation
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
                                                <i class="bi bi-calculator text-primary fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Total Valuations</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['total_valuations'] ?? 0); ?></h3>
                                            <small class="text-muted"><?php echo number_format($summary['unique_parcels'] ?? 0); ?> parcels</small>
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
                                                <i class="bi bi-currency-dollar text-success fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Total Value</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo formatCurrency($summary['total_value'] ?? 0); ?></h3>
                                            <small class="text-muted">Avg: <?php echo formatCurrency($summary['avg_value'] ?? 0); ?></small>
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
                                                <i class="bi bi-rulers text-info fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Price/m²</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo formatCurrency($summary['avg_value_per_sqm'] ?? 0); ?></h3>
                                            <small class="text-muted">Range: <?php echo formatCurrency($summary['min_value'] ?? 0); ?> - <?php echo formatCurrency($summary['max_value'] ?? 0); ?></small>
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
                                                <i class="bi bi-person-badge text-warning fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Assessors</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['unique_assessors'] ?? 0); ?></h3>
                                            <small class="text-muted">Latest: <?php echo $summary['latest_valuation'] ? date('d M Y', strtotime($summary['latest_valuation'])) : 'N/A'; ?></small>
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
                                           placeholder="Parcel #, Assessor, Notes..."
                                           value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                                </div>
                                
                                <div class="col-md-2">
                                    <label class="form-label">Assessor</label>
                                    <select class="form-select" name="assessed_by">
                                        <option value="">All Assessors</option>
                                        <?php foreach ($assessors as $a): ?>
                                        <option value="<?php echo htmlspecialchars($a['assessed_by']); ?>" <?php echo ($_GET['assessed_by'] ?? '') == $a['assessed_by'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($a['assessed_by']); ?>
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
                                    <label class="form-label">Date Range</label>
                                    <div class="input-group">
                                        <input type="date" class="form-control" name="from_date" 
                                               value="<?php echo htmlspecialchars($_GET['from_date'] ?? ''); ?>" placeholder="From">
                                        <input type="date" class="form-control" name="to_date" 
                                               value="<?php echo htmlspecialchars($_GET['to_date'] ?? ''); ?>" placeholder="To">
                                    </div>
                                </div>
                                
                                <div class="col-md-3">
                                    <label class="form-label">Value Range ($)</label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" name="min_value" 
                                               value="<?php echo htmlspecialchars($_GET['min_value'] ?? ''); ?>" placeholder="Min" step="0.01">
                                        <input type="number" class="form-control" name="max_value" 
                                               value="<?php echo htmlspecialchars($_GET['max_value'] ?? ''); ?>" placeholder="Max" step="0.01">
                                    </div>
                                </div>
                                
                                <div class="col-md-3">
                                    <label class="form-label">Price/m² Range ($)</label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" name="min_value_per_area" 
                                               value="<?php echo htmlspecialchars($_GET['min_value_per_area'] ?? ''); ?>" placeholder="Min" step="0.01">
                                        <input type="number" class="form-control" name="max_value_per_area" 
                                               value="<?php echo htmlspecialchars($_GET['max_value_per_area'] ?? ''); ?>" placeholder="Max" step="0.01">
                                    </div>
                                </div>
                                
                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-search me-2"></i>Apply Filters
                                    </button>
                                    <a href="valuations.php" class="btn btn-secondary">
                                        <i class="bi bi-x-circle me-2"></i>Clear Filters
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Valuations Table -->
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0 fw-bold">
                                <i class="bi bi-cash-stack text-primary me-2"></i>
                                Valuation Records
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
                                            <th>Valuation Date</th>
                                            <th>Assessed By</th>
                                            <th>Value</th>
                                            <th>Price/m²</th>
                                            <th>Tax Impact</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($valuations)): ?>
                                        <tr>
                                            <td colspan="9" class="text-center py-4 text-muted">
                                                <i class="bi bi-cash-stack fs-1 d-block mb-3"></i>
                                                No valuation records found. 
                                                <a href="#" data-bs-toggle="modal" data-bs-target="#addValuationModal">Click here</a> to add one.
                                            </td>
                                        </tr>
                                        <?php else: ?>
                                            <?php foreach ($valuations as $val): ?>
                                            <tr>
                                                <td>
                                                    <span class="fw-medium">#<?php echo $val['id']; ?></span>
                                                </td>
                                                <td>
                                                    <span class="fw-medium"><?php echo htmlspecialchars($val['parcel_number']); ?></span>
                                                    <br><small class="text-muted">Area: <?php echo number_format($val['parcel_area'], 2); ?> m²</small>
                                                    <?php if (!empty($val['title_number'])): ?>
                                                        <br><small class="text-muted">Title: <?php echo $val['title_number']; ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <small>
                                                        <?php echo htmlspecialchars($val['boma_name'] ?? ''); ?>
                                                        <?php if (!empty($val['payam_name'])): ?><br><?php echo $val['payam_name']; ?><?php endif; ?>
                                                        <?php if (!empty($val['county_name'])): ?><br><?php echo $val['county_name']; ?><?php endif; ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <?php echo date('d M Y', strtotime($val['valuation_date'])); ?>
                                                </td>
                                                <td>
                                                    <span class="fw-medium"><?php echo htmlspecialchars($val['assessed_by'] ?? 'Unknown'); ?></span>
                                                </td>
                                                <td>
                                                    <span class="fw-bold text-primary"><?php echo formatCurrency($val['value']); ?></span>
                                                </td>
                                                <td>
                                                    <?php if ($val['parcel_area'] > 0): ?>
                                                        <span class="badge bg-info"><?php echo formatCurrency($val['value_per_sqm']); ?>/m²</span>
                                                        <br><small class="text-muted"><?php echo formatCurrency($val['value_per_hectare'] ?? 0); ?>/ha</small>
                                                    <?php else: ?>
                                                        <span class="text-muted">N/A</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($val['tax_assessment_id']): ?>
                                                        <span class="badge bg-<?php echo $val['tax_status'] == 'paid' ? 'success' : ($val['tax_status'] == 'overdue' ? 'danger' : 'warning'); ?>">
                                                            Tax: <?php echo formatCurrency($val['tax_amount']); ?>
                                                        </span>
                                                        <br><small class="text-muted">Year: <?php echo $val['tax_year']; ?></small>
                                                    <?php else: ?>
                                                        <span class="text-muted">No tax record</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <button class="btn btn-outline-primary" 
                                                                onclick="editValuation(<?php echo htmlspecialchars(json_encode($val)); ?>)"
                                                                data-bs-toggle="modal" data-bs-target="#editValuationModal">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                        <button class="btn btn-outline-info" 
                                                                onclick="viewValuation(<?php echo htmlspecialchars(json_encode($val)); ?>)"
                                                                data-bs-toggle="modal" data-bs-target="#viewValuationModal">
                                                            <i class="bi bi-eye"></i>
                                                        </button>
                                                        <a href="tax-assessments.php?parcel_id=<?php echo $val['parcel_id']; ?>" 
                                                           class="btn btn-outline-warning">
                                                            <i class="bi bi-file-text"></i>
                                                        </a>
                                                        <a href="?delete=<?php echo $val['id']; ?>" 
                                                           class="btn btn-outline-danger"
                                                           onclick="return confirm('Delete this valuation record? This action cannot be undone.')">
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
                                        <i class="bi bi-graph-up text-success me-2"></i>
                                        Monthly Valuation Trend
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <canvas id="monthlyChart" height="200"></canvas>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h5 class="card-title mb-0 fw-bold">
                                        <i class="bi bi-trophy text-warning me-2"></i>
                                        Top Assessors
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($top_assessors)): ?>
                                        <p class="text-muted text-center">No assessor data available</p>
                                    <?php else: ?>
                                        <div class="list-group">
                                            <?php foreach ($top_assessors as $assessor): ?>
                                            <div class="list-group-item">
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <div>
                                                        <h6 class="mb-0"><?php echo htmlspecialchars($assessor['assessed_by']); ?></h6>
                                                        <small class="text-muted">
                                                            <?php echo $assessor['parcels_assessed']; ?> parcels • 
                                                            Avg: <?php echo formatCurrency($assessor['avg_value']); ?>
                                                        </small>
                                                    </div>
                                                    <div class="text-end">
                                                        <span class="badge bg-primary rounded-pill"><?php echo $assessor['valuation_count']; ?> vals</span>
                                                        <br><small class="text-muted"><?php echo formatCurrency($assessor['total_value']); ?></small>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Value Distribution and Top Parcels -->
                    <div class="row mt-4">
                        <div class="col-md-6">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h5 class="card-title mb-0 fw-bold">
                                        <i class="bi bi-pie-chart text-info me-2"></i>
                                        Value by Area Range
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($value_distribution)): ?>
                                        <p class="text-muted text-center">No distribution data available</p>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-sm">
                                                <thead>
                                                    <tr>
                                                        <th>Area Range</th>
                                                        <th>Count</th>
                                                        <th>Avg Value</th>
                                                        <th>Avg Price/m²</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($value_distribution as $dist): ?>
                                                    <tr>
                                                        <td><?php echo $dist['area_range']; ?></td>
                                                        <td><span class="badge bg-primary"><?php echo $dist['count']; ?></span></td>
                                                        <td><?php echo formatCurrency($dist['avg_value']); ?></td>
                                                        <td><?php echo formatCurrency($dist['avg_price_per_sqm']); ?></td>
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
                                        <i class="bi bi-star-fill text-warning me-2"></i>
                                        Top Valued Parcels
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($top_parcels)): ?>
                                        <p class="text-muted text-center">No parcel data available</p>
                                    <?php else: ?>
                                        <div class="list-group">
                                            <?php foreach ($top_parcels as $parcel): ?>
                                            <div class="list-group-item">
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <div>
                                                        <h6 class="mb-0"><?php echo htmlspecialchars($parcel['parcel_number']); ?></h6>
                                                        <small class="text-muted">
                                                            <?php echo $parcel['location']; ?> • 
                                                            <?php echo number_format($parcel['area'], 2); ?> m²
                                                        </small>
                                                    </div>
                                                    <div class="text-end">
                                                        <span class="fw-bold text-primary"><?php echo formatCurrency($parcel['value']); ?></span>
                                                        <br><small class="text-muted"><?php echo formatCurrency($parcel['price_per_sqm']); ?>/m²</small>
                                                    </div>
                                                </div>
                                                <small class="text-muted d-block mt-1">
                                                    Assessed by: <?php echo htmlspecialchars($parcel['assessed_by']); ?> on <?php echo date('d M Y', strtotime($parcel['valuation_date'])); ?>
                                                </small>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Valuation Information Card -->
                    <div class="row mt-4">
                        <div class="col-md-12">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h5 class="card-title mb-0 fw-bold">
                                        <i class="bi bi-info-circle text-info me-2"></i>
                                        About Property Valuations
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-4">
                                            <h6>Valuation Purposes:</h6>
                                            <ul>
                                                <li>Property tax assessment</li>
                                                <li>Sale/purchase transactions</li>
                                                <li>Mortgage and financing</li>
                                                <li>Estate planning</li>
                                                <li>Insurance purposes</li>
                                            </ul>
                                        </div>
                                        <div class="col-md-4">
                                            <h6>Valuation Methods:</h6>
                                            <ul>
                                                <li>Market comparison approach</li>
                                                <li>Income capitalization approach</li>
                                                <li>Cost approach</li>
                                                <li>Residual method</li>
                                            </ul>
                                        </div>
                                        <div class="col-md-4">
                                            <h6>Factors Affecting Value:</h6>
                                            <ul>
                                                <li>Location and accessibility</li>
                                                <li>Size and shape</li>
                                                <li>Zoning and land use</li>
                                                <li>Infrastructure availability</li>
                                                <li>Market conditions</li>
                                            </ul>
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

    <!-- Add Valuation Modal -->
    <div class="modal fade" id="addValuationModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="add">
                    <div class="modal-header">
                        <h5 class="modal-title">Add Valuation Record</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Select Parcel <span class="text-danger">*</span></label>
                                <select class="form-select" name="parcel_id" id="addParcelSelect" required>
                                    <option value="">-- Select a parcel --</option>
                                    <?php foreach ($parcels as $parcel): ?>
                                    <option value="<?php echo $parcel['id']; ?>" 
                                            data-area="<?php echo $parcel['area']; ?>">
                                        <?php echo $parcel['parcel_number']; ?> - 
                                        <?php echo htmlspecialchars($parcel['boma_name']); ?>, 
                                        <?php echo htmlspecialchars($parcel['payam_name']); ?>
                                        (<?php echo number_format($parcel['area'], 2); ?> m²)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Valuation Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="valuation_date" 
                                       value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Assessed By <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="assessed_by" 
                                       placeholder="e.g., Valuer One" required>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Valuation Amount ($) <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text">$</span>
                                    <input type="number" class="form-control" name="value" 
                                           id="addValue" step="0.01" min="0" required>
                                </div>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Price per m²</label>
                                <div class="input-group">
                                    <span class="input-group-text">$</span>
                                    <input type="text" class="form-control" id="addPricePerSqm" readonly disabled>
                                </div>
                                <small class="text-muted">Auto-calculated</small>
                            </div>
                            
                            <div class="col-12 mb-3">
                                <label class="form-label">Notes</label>
                                <textarea class="form-control" name="notes" rows="3" 
                                          placeholder="Valuation methodology, assumptions, comparable properties..."></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Valuation</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Valuation Modal -->
    <div class="modal fade" id="editValuationModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="valuation_id" id="editValuationId">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Valuation Record</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Parcel</label>
                                <input type="text" class="form-control" id="editParcel" readonly disabled>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Valuation Date</label>
                                <input type="date" class="form-control" name="valuation_date" id="editValuationDate" required>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Assessed By</label>
                                <input type="text" class="form-control" name="assessed_by" id="editAssessedBy" required>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Valuation Amount ($)</label>
                                <div class="input-group">
                                    <span class="input-group-text">$</span>
                                    <input type="number" class="form-control" name="value" 
                                           id="editValue" step="0.01" min="0" required>
                                </div>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Price per m²</label>
                                <div class="input-group">
                                    <span class="input-group-text">$</span>
                                    <input type="text" class="form-control" id="editPricePerSqm" readonly disabled>
                                </div>
                            </div>
                            
                            <div class="col-12 mb-3">
                                <label class="form-label">Notes</label>
                                <textarea class="form-control" name="notes" id="editNotes" rows="3"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Valuation</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Valuation Modal -->
    <div class="modal fade" id="viewValuationModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Valuation Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Valuation ID:</label>
                            <p id="viewValuationId" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Valuation Date:</label>
                            <p id="viewValuationDate" class="mb-0"></p>
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
                            <label class="fw-bold">Title:</label>
                            <p id="viewTitle" class="mb-0"></p>
                        </div>
                        
                        <div class="col-12">
                            <hr>
                            <h6 class="fw-bold">Valuation Details</h6>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Assessed By:</label>
                            <p id="viewAssessedBy" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Value:</label>
                            <p id="viewValue" class="mb-0 fw-bold text-primary"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Price per m²:</label>
                            <p id="viewPricePerSqm" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Price per Hectare:</label>
                            <p id="viewPricePerHa" class="mb-0"></p>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="fw-bold">Notes:</label>
                            <p id="viewNotes" class="mb-0"></p>
                        </div>
                        
                        <div class="col-12">
                            <hr>
                            <h6 class="fw-bold">Tax Information</h6>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Tax Year:</label>
                            <p id="viewTaxYear" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Tax Amount:</label>
                            <p id="viewTaxAmount" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Tax Status:</label>
                            <p id="viewTaxStatus" class="mb-0"></p>
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
        // Calculate price per m² for add form
        document.getElementById('addParcelSelect')?.addEventListener('change', function() {
            calculatePricePerSqm('add');
        });
        
        document.getElementById('addValue')?.addEventListener('input', function() {
            calculatePricePerSqm('add');
        });
        
        // Calculate price per m² for edit form
        document.getElementById('editValue')?.addEventListener('input', function() {
            calculatePricePerSqm('edit');
        });
        
        function calculatePricePerSqm(form) {
            const parcelSelect = document.getElementById(form === 'add' ? 'addParcelSelect' : 'editParcelSelect');
            const valueField = document.getElementById(form === 'add' ? 'addValue' : 'editValue');
            const priceField = document.getElementById(form === 'add' ? 'addPricePerSqm' : 'editPricePerSqm');
            
            if (parcelSelect && parcelSelect.selectedOptions[0] && valueField.value) {
                const area = parcelSelect.selectedOptions[0].dataset.area;
                if (area && area > 0) {
                    const pricePerSqm = parseFloat(valueField.value) / parseFloat(area);
                    priceField.value = pricePerSqm.toFixed(2);
                } else {
                    priceField.value = '';
                }
            } else {
                priceField.value = '';
            }
        }
        
        // Edit valuation function
        function editValuation(val) {
            document.getElementById('editValuationId').value = val.id;
            document.getElementById('editParcel').value = val.parcel_number + ' (' + val.parcel_area + ' m²)';
            document.getElementById('editValuationDate').value = val.valuation_date || '';
            document.getElementById('editAssessedBy').value = val.assessed_by || '';
            document.getElementById('editValue').value = val.value || '';
            document.getElementById('editNotes').value = val.notes || '';
            
            // Create hidden parcel select for area calculation
            let parcelSelect = document.getElementById('editParcelSelect');
            if (!parcelSelect) {
                parcelSelect = document.createElement('select');
                parcelSelect.id = 'editParcelSelect';
                parcelSelect.style.display = 'none';
                document.body.appendChild(parcelSelect);
                
                const option = document.createElement('option');
                option.value = val.parcel_id;
                option.dataset.area = val.parcel_area;
                option.selected = true;
                parcelSelect.appendChild(option);
            } else {
                const option = document.createElement('option');
                option.value = val.parcel_id;
                option.dataset.area = val.parcel_area;
                option.selected = true;
                parcelSelect.innerHTML = '';
                parcelSelect.appendChild(option);
            }
            
            calculatePricePerSqm('edit');
        }
        
        // View valuation function
        function viewValuation(val) {
            document.getElementById('viewValuationId').textContent = '#' + (val.id || 'N/A');
            document.getElementById('viewValuationDate').textContent = val.valuation_date || 'N/A';
            document.getElementById('viewParcelNumber').textContent = val.parcel_number || 'N/A';
            document.getElementById('viewArea').textContent = val.parcel_area ? val.parcel_area + ' m²' : 'N/A';
            document.getElementById('viewLocation').textContent = 
                (val.boma_name || '') + ', ' + (val.payam_name || '') + ', ' + (val.county_name || '');
            document.getElementById('viewTitle').textContent = val.title_number || 'No active title';
            document.getElementById('viewAssessedBy').textContent = val.assessed_by || 'N/A';
            document.getElementById('viewValue').textContent = val.value ? '$' + Number(val.value).toLocaleString() : 'N/A';
            
            if (val.parcel_area > 0) {
                const pricePerSqm = val.value / val.parcel_area;
                const pricePerHa = pricePerSqm * 10000;
                document.getElementById('viewPricePerSqm').textContent = '$' + pricePerSqm.toFixed(2) + '/m²';
                document.getElementById('viewPricePerHa').textContent = '$' + pricePerHa.toFixed(2) + '/ha';
            } else {
                document.getElementById('viewPricePerSqm').textContent = 'N/A';
                document.getElementById('viewPricePerHa').textContent = 'N/A';
            }
            
            document.getElementById('viewNotes').textContent = val.notes || 'No notes';
            
            // Tax info
            if (val.tax_year) {
                document.getElementById('viewTaxYear').textContent = val.tax_year;
                document.getElementById('viewTaxAmount').textContent = '$' + Number(val.tax_amount).toLocaleString();
                
                let taxStatusBadge = '';
                if (val.tax_status === 'paid') taxStatusBadge = '<span class="badge bg-success">Paid</span>';
                else if (val.tax_status === 'overdue') taxStatusBadge = '<span class="badge bg-danger">Overdue</span>';
                else if (val.tax_status === 'pending') taxStatusBadge = '<span class="badge bg-warning">Pending</span>';
                else taxStatusBadge = '<span class="badge bg-secondary">' + (val.tax_status || 'N/A') + '</span>';
                
                document.getElementById('viewTaxStatus').innerHTML = taxStatusBadge;
            } else {
                document.getElementById('viewTaxYear').textContent = 'N/A';
                document.getElementById('viewTaxAmount').textContent = 'N/A';
                document.getElementById('viewTaxStatus').innerHTML = '<span class="badge bg-secondary">No tax record</span>';
            }
        }
        
        // Monthly trend chart
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('monthlyChart')?.getContext('2d');
            if (ctx) {
                const months = <?php echo json_encode(array_column($monthly_trend, 'month')); ?>;
                const counts = <?php echo json_encode(array_column($monthly_trend, 'valuation_count')); ?>;
                const avgValues = <?php echo json_encode(array_column($monthly_trend, 'avg_value')); ?>;
                
                if (months.length > 0) {
                    new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: months,
                            datasets: [
                                {
                                    label: 'Number of Valuations',
                                    data: counts,
                                    backgroundColor: 'rgba(54, 162, 235, 0.5)',
                                    borderColor: 'rgba(54, 162, 235, 1)',
                                    borderWidth: 1,
                                    yAxisID: 'y'
                                },
                                {
                                    label: 'Average Value ($)',
                                    data: avgValues,
                                    backgroundColor: 'rgba(255, 159, 64, 0.5)',
                                    borderColor: 'rgba(255, 159, 64, 1)',
                                    borderWidth: 1,
                                    yAxisID: 'y1',
                                    type: 'line'
                                }
                            ]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    position: 'left',
                                    title: {
                                        display: true,
                                        text: 'Number of Valuations'
                                    },
                                    ticks: {
                                        stepSize: 1
                                    }
                                },
                                y1: {
                                    beginAtZero: true,
                                    position: 'right',
                                    grid: {
                                        drawOnChartArea: false
                                    },
                                    title: {
                                        display: true,
                                        text: 'Average Value ($)'
                                    },
                                    ticks: {
                                        callback: function(value) {
                                            return '$' + value.toLocaleString();
                                        }
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
        
        .rounded-circle {
            width: 60px;
            height: 60px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        
        .list-group-item {
            padding: 0.75rem 1rem;
        }
        
        .list-group-item:hover {
            background-color: #f8f9fa;
        }
        
        .border-start {
            border-left-width: 4px !important;
        }
        
        .border-primary { border-left-color: #0d6efd !important; }
        .border-success { border-left-color: #198754 !important; }
        .border-info { border-left-color: #0dcaf0 !important; }
        .border-warning { border-left-color: #ffc107 !important; }
    </style>

</body>
</html>