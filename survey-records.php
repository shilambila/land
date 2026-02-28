<?php
ini_set('display_errors', 1); 
error_reporting(E_ALL);

// survey-records.php - Survey Records Management
include("head.php");
require_once 'db_connection.php';

// ============================================================================
// HANDLE ACTIONS
// ============================================================================

$message = '';
$messageType = '';

// Delete survey record
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $surveyId = $_GET['delete'];
    
    try {
        // Check if survey has related documents
        $documents = fetchOne($conn, "SELECT COUNT(*) as count FROM documents WHERE document_type = 'survey_plan' AND parcel_id IN (SELECT parcel_id FROM survey_records WHERE id = ?)", [$surveyId]);
        
        if ($documents && $documents['count'] > 0) {
            $message = "Cannot delete survey record with associated documents";
            $messageType = "danger";
        } else {
            executeQuery($conn, "DELETE FROM survey_records WHERE id = ?", [$surveyId]);
            $message = "Survey record deleted successfully";
            $messageType = "success";
        }
    } catch (Exception $e) {
        $message = "Error deleting survey record: " . $e->getMessage();
        $messageType = "danger";
        error_log("Delete error: " . $e->getMessage());
    }
}

// Add new survey record
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $parcel_id = $_POST['parcel_id'];
    $survey_date = $_POST['survey_date'];
    $surveyor = $_POST['surveyor'];
    $description = $_POST['description'] ?? null;
    $document_id = null;
    
    // Handle geometry data (if provided)
    $geometry = null;
    if (!empty($_POST['coordinates']) && !empty($_POST['geometry_type'])) {
        // Simple point geometry
        if ($_POST['geometry_type'] === 'point' && !empty($_POST['longitude']) && !empty($_POST['latitude'])) {
            $geometry = "POINT({$_POST['longitude']} {$_POST['latitude']})";
        }
        // You could add more complex geometry handling here
    }
    
    try {
        // Start transaction
        beginTransaction($conn);
        
        // Check if parcel exists
        $parcel = fetchOne($conn, "SELECT id, parcel_number FROM parcels WHERE id = ?", [$parcel_id]);
        if (!$parcel) {
            throw new Exception("Parcel not found");
        }
        
        // Handle file upload if present
        if (isset($_FILES['survey_document']) && $_FILES['survey_document']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = 'uploads/surveys/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $file_extension = pathinfo($_FILES['survey_document']['name'], PATHINFO_EXTENSION);
            $file_name = 'survey_' . time() . '_' . uniqid() . '.' . $file_extension;
            $file_path = $upload_dir . $file_name;
            
            if (move_uploaded_file($_FILES['survey_document']['tmp_name'], $file_path)) {
                executeQuery($conn, "
                    INSERT INTO documents (
                        document_type, parcel_id, file_name, file_path, 
                        mime_type, uploaded_by, uploaded_at, description
                    ) VALUES ('survey_plan', ?, ?, ?, ?, ?, NOW(), ?)
                ", [$parcel_id, $_FILES['survey_document']['name'], $file_path, 
                    $_FILES['survey_document']['type'], $_SESSION['user_id'] ?? 1, 
                    "Survey document for parcel " . $parcel['parcel_number']]);
                
                $document_id = $conn->lastInsertId();
            }
        }
        
        // Insert survey record with geometry
        if ($geometry) {
            executeQuery($conn, "
                INSERT INTO survey_records (
                    parcel_id, survey_date, surveyor, geometry, description, document_id, created_at
                ) VALUES (?, ?, ?, ST_GeomFromText(?), ?, ?, NOW())
            ", [$parcel_id, $survey_date, $surveyor, $geometry, $description, $document_id]);
        } else {
            executeQuery($conn, "
                INSERT INTO survey_records (
                    parcel_id, survey_date, surveyor, description, document_id, created_at
                ) VALUES (?, ?, ?, ?, ?, NOW())
            ", [$parcel_id, $survey_date, $surveyor, $description, $document_id]);
        }
        
        $survey_id = $conn->lastInsertId();
        
        // Commit transaction
        commitTransaction($conn);
        
        $message = "Survey record added successfully";
        $messageType = "success";
        
    } catch (Exception $e) {
        rollbackTransaction($conn);
        $message = "Error adding survey record: " . $e->getMessage();
        $messageType = "danger";
        error_log("Add error: " . $e->getMessage());
    }
}

// Update survey record
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit') {
    $survey_id = $_POST['survey_id'];
    $survey_date = $_POST['survey_date'];
    $surveyor = $_POST['surveyor'];
    $description = $_POST['description'] ?? null;
    
    try {
        executeQuery($conn, "
            UPDATE survey_records SET 
                survey_date = ?, 
                surveyor = ?, 
                description = ?
            WHERE id = ?
        ", [$survey_date, $surveyor, $description, $survey_id]);
        
        $message = "Survey record updated successfully";
        $messageType = "success";
        
    } catch (Exception $e) {
        $message = "Error updating survey record: " . $e->getMessage();
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
    $where_conditions[] = "(p.parcel_number LIKE ? OR sr.surveyor LIKE ? OR sr.description LIKE ?)";
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
}

// Surveyor filter
if (!empty($_GET['surveyor'])) {
    $where_conditions[] = "sr.surveyor LIKE ?";
    $params[] = '%' . $_GET['surveyor'] . '%';
}

// Date range filter
if (!empty($_GET['from_date'])) {
    $where_conditions[] = "sr.survey_date >= ?";
    $params[] = $_GET['from_date'];
}
if (!empty($_GET['to_date'])) {
    $where_conditions[] = "sr.survey_date <= ?";
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

// Has geometry filter
if (!empty($_GET['has_geometry']) && $_GET['has_geometry'] === 'yes') {
    $where_conditions[] = "sr.geometry IS NOT NULL";
} elseif (!empty($_GET['has_geometry']) && $_GET['has_geometry'] === 'no') {
    $where_conditions[] = "sr.geometry IS NULL";
}

// Has document filter
if (!empty($_GET['has_document']) && $_GET['has_document'] === 'yes') {
    $where_conditions[] = "sr.document_id IS NOT NULL";
} elseif (!empty($_GET['has_document']) && $_GET['has_document'] === 'no') {
    $where_conditions[] = "sr.document_id IS NULL";
}

$where_clause = "WHERE " . implode(" AND ", $where_conditions);

// Get total count for pagination
$count_sql = "
    SELECT COUNT(*) as total
    FROM survey_records sr
    JOIN parcels p ON sr.parcel_id = p.id
    LEFT JOIN bomas b ON p.boma_id = b.id
    LEFT JOIN payams pa ON b.payam_id = pa.id
    LEFT JOIN counties c ON pa.county_id = c.id
    LEFT JOIN states s ON c.state_id = s.id
    $where_clause
";

$total_result = executeQuery($conn, $count_sql, $params);
$total_rows = $total_result->fetch()['total'];
$total_pages = ceil($total_rows / $limit);

// Get survey records with all related information
$sql = "
    SELECT 
        sr.*,
        p.id as parcel_id,
        p.parcel_number,
        p.area as parcel_area,
        p.location_description,
        p.survey_number as parcel_survey_number,
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
        d.id as doc_id,
        d.file_name,
        d.file_path,
        d.mime_type,
        ST_AsText(sr.geometry) as geometry_wkt,
        ST_AsGeoJSON(sr.geometry) as geometry_geojson
    FROM survey_records sr
    JOIN parcels p ON sr.parcel_id = p.id
    LEFT JOIN bomas b ON p.boma_id = b.id
    LEFT JOIN payams pa ON b.payam_id = pa.id
    LEFT JOIN counties c ON pa.county_id = c.id
    LEFT JOIN states s ON c.state_id = s.id
    LEFT JOIN titles t ON p.id = t.parcel_id AND t.status = 'active'
    LEFT JOIN documents d ON sr.document_id = d.id
    $where_clause
    ORDER BY sr.survey_date DESC, sr.created_at DESC
    LIMIT ? OFFSET ?
";

$params[] = $limit;
$params[] = $offset;

$result = executeQuery($conn, $sql, $params);
$surveys = [];
if ($result) {
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        $surveys[] = $row;
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
        SELECT p.id, p.parcel_number, p.area, p.survey_number,
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
    // Get unique surveyors for filter
    $surveyors = fetchAll($conn, "
        SELECT DISTINCT surveyor FROM survey_records WHERE surveyor IS NOT NULL ORDER BY surveyor
    ");
} catch (Exception $e) {
    error_log("Error loading surveyors: " . $e->getMessage());
    $surveyors = [];
}

// ============================================================================
// SUMMARY STATISTICS
// ============================================================================

try {
    $summary = fetchOne($conn, "
        SELECT 
            COUNT(*) as total_surveys,
            COUNT(DISTINCT parcel_id) as unique_parcels,
            COUNT(DISTINCT surveyor) as unique_surveyors,
            SUM(CASE WHEN geometry IS NOT NULL THEN 1 ELSE 0 END) as with_geometry,
            SUM(CASE WHEN document_id IS NOT NULL THEN 1 ELSE 0 END) as with_documents,
            MIN(survey_date) as earliest_survey,
            MAX(survey_date) as latest_survey
        FROM survey_records
    ");
} catch (Exception $e) {
    error_log("Error loading summary: " . $e->getMessage());
    $summary = [
        'total_surveys' => 0,
        'unique_parcels' => 0,
        'unique_surveyors' => 0,
        'with_geometry' => 0,
        'with_documents' => 0,
        'earliest_survey' => null,
        'latest_survey' => null
    ];
}

try {
    // Monthly trend
    $monthly_trend = fetchAll($conn, "
        SELECT 
            DATE_FORMAT(survey_date, '%Y-%m') as month,
            COUNT(*) as survey_count
        FROM survey_records
        WHERE survey_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(survey_date, '%Y-%m')
        ORDER BY month DESC
    ");
} catch (Exception $e) {
    error_log("Error loading monthly trend: " . $e->getMessage());
    $monthly_trend = [];
}

try {
    // Top surveyors
    $top_surveyors = fetchAll($conn, "
        SELECT 
            surveyor,
            COUNT(*) as survey_count,
            COUNT(DISTINCT parcel_id) as parcels_surveyed
        FROM survey_records
        WHERE surveyor IS NOT NULL
        GROUP BY surveyor
        ORDER BY survey_count DESC
        LIMIT 5
    ");
} catch (Exception $e) {
    error_log("Error loading top surveyors: " . $e->getMessage());
    $top_surveyors = [];
}

// Function to format geometry type
function getGeometryType($wkt) {
    if (empty($wkt)) return 'None';
    if (strpos($wkt, 'POINT') === 0) return 'Point';
    if (strpos($wkt, 'LINESTRING') === 0) return 'Line';
    if (strpos($wkt, 'POLYGON') === 0) return 'Polygon';
    if (strpos($wkt, 'MULTIPOINT') === 0) return 'MultiPoint';
    if (strpos($wkt, 'MULTILINESTRING') === 0) return 'MultiLine';
    if (strpos($wkt, 'MULTIPOLYGON') === 0) return 'MultiPolygon';
    return 'Complex';
}
?>

<body data-page="survey-records" class="survey-records-page">
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
                            <h1 class="h3 mb-0">Survey Records</h1>
                            <p class="text-muted mb-0">Manage land survey records and spatial data</p>
                        </div>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addSurveyModal">
                            <i class="bi bi-pin-map-fill me-2"></i>Add Survey Record
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
                                            <h6 class="text-muted mb-1">Total Surveys</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['total_surveys'] ?? 0); ?></h3>
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
                                                <i class="bi bi-grid text-success fs-4"></i>
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
                        
                        <div class="col-xl-2 col-md-4">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0 me-3">
                                            <div class="bg-info bg-opacity-10 rounded-circle p-3">
                                                <i class="bi bi-people text-info fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Surveyors</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['unique_surveyors'] ?? 0); ?></h3>
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
                                                <i class="bi bi-geo-alt text-warning fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">With Geometry</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['with_geometry'] ?? 0); ?></h3>
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
                                                <i class="bi bi-file-text text-secondary fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">With Documents</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['with_documents'] ?? 0); ?></h3>
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
                                                <i class="bi bi-calendar-range text-danger fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Latest Survey</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo $summary['latest_survey'] ? date('d M Y', strtotime($summary['latest_survey'])) : 'N/A'; ?></h3>
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
                                           placeholder="Parcel #, Surveyor, Description..."
                                           value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                                </div>
                                
                                <div class="col-md-2">
                                    <label class="form-label">Surveyor</label>
                                    <select class="form-select" name="surveyor">
                                        <option value="">All Surveyors</option>
                                        <?php foreach ($surveyors as $s): ?>
                                        <option value="<?php echo htmlspecialchars($s['surveyor']); ?>" <?php echo ($_GET['surveyor'] ?? '') == $s['surveyor'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($s['surveyor']); ?>
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
                                
                                <div class="col-md-2">
                                    <label class="form-label">Has Geometry</label>
                                    <select class="form-select" name="has_geometry">
                                        <option value="">All</option>
                                        <option value="yes" <?php echo ($_GET['has_geometry'] ?? '') == 'yes' ? 'selected' : ''; ?>>Yes</option>
                                        <option value="no" <?php echo ($_GET['has_geometry'] ?? '') == 'no' ? 'selected' : ''; ?>>No</option>
                                    </select>
                                </div>
                                
                                <div class="col-md-2">
                                    <label class="form-label">Has Document</label>
                                    <select class="form-select" name="has_document">
                                        <option value="">All</option>
                                        <option value="yes" <?php echo ($_GET['has_document'] ?? '') == 'yes' ? 'selected' : ''; ?>>Yes</option>
                                        <option value="no" <?php echo ($_GET['has_document'] ?? '') == 'no' ? 'selected' : ''; ?>>No</option>
                                    </select>
                                </div>
                                
                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-search me-2"></i>Apply Filters
                                    </button>
                                    <a href="survey-records.php" class="btn btn-secondary">
                                        <i class="bi bi-x-circle me-2"></i>Clear Filters
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Survey Records Table -->
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0 fw-bold">
                                <i class="bi bi-pin-map text-primary me-2"></i>
                                Survey Records
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
                                            <th>Survey Date</th>
                                            <th>Surveyor</th>
                                            <th>Geometry</th>
                                            <th>Document</th>
                                            <th>Description</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($surveys)): ?>
                                        <tr>
                                            <td colspan="9" class="text-center py-4 text-muted">
                                                <i class="bi bi-pin-map fs-1 d-block mb-3"></i>
                                                No survey records found. 
                                                <a href="#" data-bs-toggle="modal" data-bs-target="#addSurveyModal">Click here</a> to add one.
                                            </td>
                                        </tr>
                                        <?php else: ?>
                                            <?php foreach ($surveys as $survey): ?>
                                            <tr>
                                                <td>
                                                    <span class="fw-medium">#<?php echo $survey['id']; ?></span>
                                                </td>
                                                <td>
                                                    <span class="fw-medium"><?php echo htmlspecialchars($survey['parcel_number']); ?></span>
                                                    <?php if (!empty($survey['parcel_area'])): ?>
                                                        <br><small class="text-muted">Area: <?php echo number_format($survey['parcel_area'], 2); ?> m²</small>
                                                    <?php endif; ?>
                                                    <?php if (!empty($survey['parcel_survey_number'])): ?>
                                                        <br><small class="text-muted">Survey #: <?php echo $survey['parcel_survey_number']; ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <small>
                                                        <?php echo htmlspecialchars($survey['boma_name'] ?? ''); ?>
                                                        <?php if (!empty($survey['payam_name'])): ?><br><?php echo $survey['payam_name']; ?><?php endif; ?>
                                                        <?php if (!empty($survey['county_name'])): ?><br><?php echo $survey['county_name']; ?><?php endif; ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <?php echo date('d M Y', strtotime($survey['survey_date'])); ?>
                                                </td>
                                                <td>
                                                    <span class="fw-medium"><?php echo htmlspecialchars($survey['surveyor'] ?? 'Unknown'); ?></span>
                                                </td>
                                                <td>
                                                    <?php if (!empty($survey['geometry_wkt'])): ?>
                                                        <span class="badge bg-success" title="<?php echo htmlspecialchars($survey['geometry_wkt']); ?>">
                                                            <i class="bi bi-geo-alt"></i> <?php echo getGeometryType($survey['geometry_wkt']); ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">None</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (!empty($survey['file_name'])): ?>
                                                        <a href="<?php echo htmlspecialchars($survey['file_path']); ?>" target="_blank" class="text-decoration-none">
                                                            <span class="badge bg-info">
                                                                <i class="bi bi-file-earmark"></i> View
                                                            </span>
                                                        </a>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">None</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars(substr($survey['description'] ?? '', 0, 30)); ?>
                                                    <?php if (strlen($survey['description'] ?? '') > 30): ?>...<?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <button class="btn btn-outline-primary" 
                                                                onclick="editSurvey(<?php echo htmlspecialchars(json_encode($survey)); ?>)"
                                                                data-bs-toggle="modal" data-bs-target="#editSurveyModal">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                        <button class="btn btn-outline-info" 
                                                                onclick="viewSurvey(<?php echo htmlspecialchars(json_encode($survey)); ?>)"
                                                                data-bs-toggle="modal" data-bs-target="#viewSurveyModal">
                                                            <i class="bi bi-eye"></i>
                                                        </button>
                                                        <?php if (!empty($survey['geometry_geojson'])): ?>
                                                        <button class="btn btn-outline-success" 
                                                                onclick="viewMap(<?php echo htmlspecialchars(json_encode($survey)); ?>)"
                                                                data-bs-toggle="modal" data-bs-target="#mapModal">
                                                            <i class="bi bi-map"></i>
                                                        </button>
                                                        <?php endif; ?>
                                                        <a href="?delete=<?php echo $survey['id']; ?>" 
                                                           class="btn btn-outline-danger"
                                                           onclick="return confirm('Delete this survey record? This action cannot be undone.')">
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
                                        Monthly Survey Trend
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
                                        Top Surveyors
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($top_surveyors)): ?>
                                        <p class="text-muted text-center">No surveyor data available</p>
                                    <?php else: ?>
                                        <div class="list-group">
                                            <?php foreach ($top_surveyors as $surveyor): ?>
                                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                                <div>
                                                    <h6 class="mb-0"><?php echo htmlspecialchars($surveyor['surveyor']); ?></h6>
                                                    <small class="text-muted"><?php echo $surveyor['parcels_surveyed']; ?> parcels</small>
                                                </div>
                                                <span class="badge bg-primary rounded-pill"><?php echo $surveyor['survey_count']; ?> surveys</span>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
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

    <!-- Add Survey Modal -->
    <div class="modal fade" id="addSurveyModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="add">
                    <div class="modal-header">
                        <h5 class="modal-title">Add Survey Record</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <ul class="nav nav-tabs mb-3" id="addSurveyTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="basic-tab" data-bs-toggle="tab" data-bs-target="#basic" type="button" role="tab">Basic Info</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="geometry-tab" data-bs-toggle="tab" data-bs-target="#geometry" type="button" role="tab">Geometry (Optional)</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="document-tab" data-bs-toggle="tab" data-bs-target="#document" type="button" role="tab">Document</button>
                            </li>
                        </ul>
                        
                        <div class="tab-content" id="addSurveyTabsContent">
                            <!-- Basic Info Tab -->
                            <div class="tab-pane fade show active" id="basic" role="tabpanel">
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
                                                (<?php echo number_format($parcel['area'], 2); ?> m²)
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Survey Date <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" name="survey_date" 
                                               value="<?php echo date('Y-m-d'); ?>" required>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Surveyor Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="surveyor" 
                                               placeholder="e.g., John Surveyor" required>
                                    </div>
                                    
                                    <div class="col-12 mb-3">
                                        <label class="form-label">Description</label>
                                        <textarea class="form-control" name="description" rows="3" 
                                                  placeholder="Survey details, methodology, notes..."></textarea>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Geometry Tab -->
                            <div class="tab-pane fade" id="geometry" role="tabpanel">
                                <div class="row">
                                    <div class="col-md-12 mb-3">
                                        <label class="form-label">Geometry Type</label>
                                        <select class="form-select" name="geometry_type" id="geometryType">
                                            <option value="">None (No Geometry)</option>
                                            <option value="point">Point (Single Coordinate)</option>
                                            <option value="polygon">Polygon (Coming Soon)</option>
                                        </select>
                                    </div>
                                    
                                    <div id="pointGeometry" style="display: none;">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Longitude</label>
                                            <input type="number" class="form-control" name="longitude" step="any" 
                                                   placeholder="e.g., 31.5805">
                                        </div>
                                        
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Latitude</label>
                                            <input type="number" class="form-control" name="latitude" step="any" 
                                                   placeholder="e.g., 4.8517">
                                        </div>
                                        
                                        <div class="col-12 mb-3">
                                            <small class="text-muted">
                                                <i class="bi bi-info-circle"></i>
                                                Enter coordinates in decimal degrees (WGS84). Example: Juba: 31.5805°E, 4.8517°N
                                            </small>
                                        </div>
                                    </div>
                                    
                                    <div class="col-12 mb-3">
                                        <label class="form-label">Coordinates (WKT Format - Advanced)</label>
                                        <textarea class="form-control" name="coordinates" rows="3" 
                                                  placeholder="e.g., POINT(31.5805 4.8517) or POLYGON((31.58 4.85, 31.59 4.85, 31.59 4.86, 31.58 4.86, 31.58 4.85))"></textarea>
                                        <small class="text-muted">Well-Known Text format for spatial data</small>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Document Tab -->
                            <div class="tab-pane fade" id="document" role="tabpanel">
                                <div class="row">
                                    <div class="col-md-12 mb-3">
                                        <label class="form-label">Survey Document (PDF/DWG/DXF)</label>
                                        <input type="file" class="form-control" name="survey_document" 
                                               accept=".pdf,.dwg,.dxf,.jpg,.png">
                                        <small class="text-muted">Upload survey plan, field notes, or certificate</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Survey Record</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Survey Modal -->
    <div class="modal fade" id="editSurveyModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="survey_id" id="editSurveyId">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Survey Record</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Parcel</label>
                                <input type="text" class="form-control" id="editParcel" readonly disabled>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Survey Date</label>
                                <input type="date" class="form-control" name="survey_date" id="editSurveyDate" required>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Surveyor</label>
                                <input type="text" class="form-control" name="surveyor" id="editSurveyor" required>
                            </div>
                            
                            <div class="col-12 mb-3">
                                <label class="form-label">Description</label>
                                <textarea class="form-control" name="description" id="editDescription" rows="3"></textarea>
                            </div>
                            
                            <div class="col-12">
                                <div class="alert alert-info">
                                    <i class="bi bi-info-circle"></i>
                                    Note: Geometry and document cannot be edited. Create a new record if changes are needed.
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Survey</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Survey Modal -->
    <div class="modal fade" id="viewSurveyModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Survey Record Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Survey ID:</label>
                            <p id="viewSurveyId" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Survey Date:</label>
                            <p id="viewSurveyDate" class="mb-0"></p>
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
                            <h6 class="fw-bold">Survey Details</h6>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Surveyor:</label>
                            <p id="viewSurveyor" class="mb-0"></p>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="fw-bold">Description:</label>
                            <p id="viewDescription" class="mb-0"></p>
                        </div>
                        
                        <div class="col-12">
                            <hr>
                            <h6 class="fw-bold">Spatial Data</h6>
                        </div>
                        
                        <div class="col-12 mb-3">
                            <label class="fw-bold">Geometry (WKT):</label>
                            <p id="viewGeometry" class="mb-0 text-muted" style="font-family: monospace; font-size: 0.85rem;"></p>
                        </div>
                        
                        <div class="col-12">
                            <hr>
                            <h6 class="fw-bold">Document</h6>
                        </div>
                        
                        <div class="col-12 mb-3">
                            <label class="fw-bold">Document:</label>
                            <p id="viewDocument" class="mb-0"></p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <?php if (!empty($survey['geometry_geojson'])): ?>
                    <button type="button" class="btn btn-success" onclick="viewMapFromModal()">
                        <i class="bi bi-map"></i> View on Map
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Map Modal -->
    <div class="modal fade" id="mapModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Survey Map View</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0">
                    <div id="map" style="height: 500px; width: 100%;"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    
    <script>
        // Toggle geometry fields
        document.getElementById('geometryType')?.addEventListener('change', function() {
            const pointGeo = document.getElementById('pointGeometry');
            if (this.value === 'point') {
                pointGeo.style.display = 'flex';
            } else {
                pointGeo.style.display = 'none';
            }
        });
        
        // Edit survey function
        function editSurvey(survey) {
            document.getElementById('editSurveyId').value = survey.id;
            document.getElementById('editParcel').value = survey.parcel_number || '';
            document.getElementById('editSurveyDate').value = survey.survey_date || '';
            document.getElementById('editSurveyor').value = survey.surveyor || '';
            document.getElementById('editDescription').value = survey.description || '';
        }
        
        // View survey function
        function viewSurvey(survey) {
            document.getElementById('viewSurveyId').textContent = '#' + (survey.id || 'N/A');
            document.getElementById('viewSurveyDate').textContent = survey.survey_date || 'N/A';
            document.getElementById('viewParcelNumber').textContent = survey.parcel_number || 'N/A';
            document.getElementById('viewArea').textContent = survey.parcel_area ? survey.parcel_area + ' m²' : 'N/A';
            document.getElementById('viewLocation').textContent = 
                (survey.boma_name || '') + ', ' + (survey.payam_name || '') + ', ' + (survey.county_name || '');
            document.getElementById('viewTitle').textContent = survey.title_number || 'No active title';
            document.getElementById('viewSurveyor').textContent = survey.surveyor || 'N/A';
            document.getElementById('viewDescription').textContent = survey.description || 'No description';
            document.getElementById('viewGeometry').textContent = survey.geometry_wkt || 'No geometry data';
            
            if (survey.file_name) {
                document.getElementById('viewDocument').innerHTML = 
                    '<a href="' + survey.file_path + '" target="_blank" class="btn btn-sm btn-outline-primary">' +
                    '<i class="bi bi-file-earmark"></i> ' + survey.file_name + '</a>';
            } else {
                document.getElementById('viewDocument').innerHTML = '<span class="text-muted">No document attached</span>';
            }
            
            // Store for map view
            window.currentSurveyGeoJSON = survey.geometry_geojson;
        }
        
        // View map function
        function viewMap(survey) {
            window.currentSurveyGeoJSON = survey.geometry_geojson;
            setTimeout(initMap, 500);
        }
        
        function viewMapFromModal() {
            $('#viewSurveyModal').modal('hide');
            setTimeout(() => {
                $('#mapModal').modal('show');
                setTimeout(initMap, 500);
            }, 500);
        }
        
        // Initialize map
        function initMap() {
            if (!window.currentSurveyGeoJSON) return;
            
            // Remove existing map if any
            if (window.surveyMap) {
                window.surveyMap.remove();
            }
            
            // Create map
            window.surveyMap = L.map('map').setView([0, 0], 2);
            
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap contributors'
            }).addTo(window.surveyMap);
            
            try {
                const geojson = JSON.parse(window.currentSurveyGeoJSON);
                const layer = L.geoJSON(geojson).addTo(window.surveyMap);
                window.surveyMap.fitBounds(layer.getBounds());
            } catch (e) {
                console.error('Invalid GeoJSON', e);
            }
        }
        
        // Monthly trend chart
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('monthlyChart')?.getContext('2d');
            if (ctx) {
                const months = <?php echo json_encode(array_column($monthly_trend, 'month')); ?>;
                const counts = <?php echo json_encode(array_column($monthly_trend, 'survey_count')); ?>;
                
                if (months.length > 0) {
                    new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: months,
                            datasets: [{
                                label: 'Surveys Conducted',
                                data: counts,
                                backgroundColor: 'rgba(40, 167, 69, 0.5)',
                                borderColor: 'rgb(40, 167, 69)',
                                borderWidth: 1
                            }]
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
        
        .rounded-circle {
            width: 60px;
            height: 60px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        
        #map {
            border-radius: 0 0 4px 4px;
        }
        
        .list-group-item {
            padding: 0.75rem 1rem;
        }
        
        .list-group-item:hover {
            background-color: #f8f9fa;
        }
    </style>

</body>
</html>