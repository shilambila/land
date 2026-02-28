<?php
ini_set('display_errors', 1); 
error_reporting(E_ALL);

// titles-customary.php
include("head.php");
require_once 'db_connection.php';

// ============================================================================
// CHECK AND CREATE CUSTOMARY TITLE DETAILS TABLE IF NOT EXISTS
// ============================================================================

try {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS customary_title_details (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title_id INT UNSIGNED NOT NULL,
            community_name VARCHAR(255),
            traditional_authority VARCHAR(255),
            chief_name VARCHAR(255),
            witnesses TEXT,
            customary_law_details TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (title_id) REFERENCES titles(id) ON DELETE CASCADE,
            UNIQUE KEY unique_title (title_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
    ");
} catch (Exception $e) {
    // Table might already exist or other error - log it but continue
    error_log("Error creating customary_title_details table: " . $e->getMessage());
}

// ============================================================================
// HANDLE ACTIONS
// ============================================================================

$message = '';
$messageType = '';

// Delete customary title
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $titleId = $_GET['delete'];
    
    try {
        // Check if title has related records
        $related = fetchOne($conn, "
            SELECT 
                (SELECT COUNT(*) FROM ownerships WHERE title_id = ?) as ownerships,
                (SELECT COUNT(*) FROM documents WHERE title_id = ?) as documents
        ", [$titleId, $titleId]);
        
        if ($related['ownerships'] > 0 || $related['documents'] > 0) {
            // Soft delete - just mark as surrendered
            executeQuery($conn, "UPDATE titles SET status = 'surrendered' WHERE id = ?", [$titleId]);
            $message = "Customary title marked as surrendered (has related records)";
            $messageType = "warning";
        } else {
            executeQuery($conn, "DELETE FROM titles WHERE id = ? AND title_type = 'customary'", [$titleId]);
            $message = "Customary title deleted successfully";
            $messageType = "success";
        }
    } catch (Exception $e) {
        $message = "Error deleting customary title: " . $e->getMessage();
        $messageType = "danger";
    }
}

// Add new customary title
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $parcel_id = $_POST['parcel_id'];
    $title_number = $_POST['title_number'];
    $issue_date = $_POST['issue_date'];
    $community_name = $_POST['community_name'] ?? null;
    $traditional_authority = $_POST['traditional_authority'] ?? null;
    $chief_name = $_POST['chief_name'] ?? null;
    $witnesses = $_POST['witnesses'] ?? null;
    $customary_law_details = $_POST['customary_law_details'] ?? null;
    $owner_party_id = $_POST['owner_party_id'];
    $share_percentage = $_POST['share_percentage'] ?? 100;
    $registration_notes = $_POST['registration_notes'] ?? null;
    
    try {
        // Start transaction
        beginTransaction($conn);
        
        // Check if title number already exists
        $exists = fetchOne($conn, "SELECT id FROM titles WHERE title_number = ?", [$title_number]);
        if ($exists) {
            throw new Exception("Title number already exists");
        }
        
        // Check if parcel already has an active customary title
        $existing_title = fetchOne($conn, "
            SELECT id FROM titles 
            WHERE parcel_id = ? AND title_type = 'customary' AND status = 'active'
        ", [$parcel_id]);
        
        if ($existing_title) {
            throw new Exception("This parcel already has an active customary title");
        }
        
        // Insert the customary title
        executeQuery($conn, "
            INSERT INTO titles (
                parcel_id, title_number, title_type, issue_date, 
                status, created_at
            ) VALUES (?, ?, 'customary', ?, 'active', NOW())
        ", [$parcel_id, $title_number, $issue_date]);
        
        $title_id = $conn->lastInsertId();
        
        // Add owner to ownerships table
        executeQuery($conn, "
            INSERT INTO ownerships (
                title_id, party_id, share_percentage, ownership_start_date, created_at
            ) VALUES (?, ?, ?, ?, NOW())
        ", [$title_id, $owner_party_id, $share_percentage, $issue_date]);
        
        // Insert customary title specific data
        if ($community_name || $traditional_authority || $chief_name || $witnesses || $customary_law_details) {
            // Check if details already exist
            $details_exists = fetchOne($conn, "SELECT id FROM customary_title_details WHERE title_id = ?", [$title_id]);
            
            if ($details_exists) {
                executeQuery($conn, "
                    UPDATE customary_title_details SET 
                        community_name = ?, traditional_authority = ?, chief_name = ?,
                        witnesses = ?, customary_law_details = ?
                    WHERE title_id = ?
                ", [$community_name, $traditional_authority, $chief_name, $witnesses, $customary_law_details, $title_id]);
            } else {
                executeQuery($conn, "
                    INSERT INTO customary_title_details (
                        title_id, community_name, traditional_authority, chief_name, 
                        witnesses, customary_law_details
                    ) VALUES (?, ?, ?, ?, ?, ?)
                ", [$title_id, $community_name, $traditional_authority, $chief_name, $witnesses, $customary_law_details]);
            }
        }
        
        // Handle file upload if present
        if (isset($_FILES['title_document']) && $_FILES['title_document']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = 'uploads/customary_titles/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $file_extension = pathinfo($_FILES['title_document']['name'], PATHINFO_EXTENSION);
            $file_name = 'customary_' . $title_number . '_' . time() . '.' . $file_extension;
            $file_path = $upload_dir . $file_name;
            
            if (move_uploaded_file($_FILES['title_document']['tmp_name'], $file_path)) {
                executeQuery($conn, "
                    INSERT INTO documents (
                        document_type, parcel_id, title_id, file_name, file_path, 
                        mime_type, uploaded_by, uploaded_at, description
                    ) VALUES ('customary_title_deed', ?, ?, ?, ?, ?, ?, NOW(), ?)
                ", [$parcel_id, $title_id, $_FILES['title_document']['name'], $file_path, 
                    $_FILES['title_document']['type'], $_SESSION['user_id'] ?? 1, 
                    "Customary title deed for " . $title_number]);
            }
        }
        
        // Commit transaction
        commitTransaction($conn);
        
        $message = "Customary title created successfully";
        $messageType = "success";
        
    } catch (Exception $e) {
        rollbackTransaction($conn);
        $message = "Error creating customary title: " . $e->getMessage();
        $messageType = "danger";
    }
}

// Update customary title
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit') {
    $title_id = $_POST['title_id'];
    $title_number = $_POST['title_number'];
    $issue_date = $_POST['issue_date'];
    $status = $_POST['status'];
    $community_name = $_POST['community_name'] ?? null;
    $traditional_authority = $_POST['traditional_authority'] ?? null;
    $chief_name = $_POST['chief_name'] ?? null;
    $witnesses = $_POST['witnesses'] ?? null;
    $customary_law_details = $_POST['customary_law_details'] ?? null;
    
    try {
        // Check if title number already exists (excluding current)
        $exists = fetchOne($conn, "
            SELECT id FROM titles 
            WHERE title_number = ? AND id != ? AND title_type = 'customary'
        ", [$title_number, $title_id]);
        
        if ($exists) {
            throw new Exception("Title number already exists");
        }
        
        // Start transaction
        beginTransaction($conn);
        
        // Update title
        executeQuery($conn, "
            UPDATE titles SET 
                title_number = ?, issue_date = ?, status = ?, updated_at = NOW()
            WHERE id = ? AND title_type = 'customary'
        ", [$title_number, $issue_date, $status, $title_id]);
        
        // Update or insert customary title details
        if ($community_name || $traditional_authority || $chief_name || $witnesses || $customary_law_details) {
            $details_exists = fetchOne($conn, "SELECT id FROM customary_title_details WHERE title_id = ?", [$title_id]);
            
            if ($details_exists) {
                executeQuery($conn, "
                    UPDATE customary_title_details SET 
                        community_name = ?, traditional_authority = ?, chief_name = ?,
                        witnesses = ?, customary_law_details = ?
                    WHERE title_id = ?
                ", [$community_name, $traditional_authority, $chief_name, $witnesses, $customary_law_details, $title_id]);
            } else {
                executeQuery($conn, "
                    INSERT INTO customary_title_details (
                        title_id, community_name, traditional_authority, chief_name, 
                        witnesses, customary_law_details
                    ) VALUES (?, ?, ?, ?, ?, ?)
                ", [$title_id, $community_name, $traditional_authority, $chief_name, $witnesses, $customary_law_details]);
            }
        }
        
        commitTransaction($conn);
        
        $message = "Customary title updated successfully";
        $messageType = "success";
        
    } catch (Exception $e) {
        rollbackTransaction($conn);
        $message = "Error updating customary title: " . $e->getMessage();
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
$where_conditions = ["t.title_type = 'customary'"];
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

// Get customary titles with all related information
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
        ctd.community_name,
        ctd.traditional_authority,
        ctd.chief_name,
        ctd.witnesses,
        ctd.customary_law_details,
        GROUP_CONCAT(DISTINCT CONCAT(parties.name, ' (', o.share_percentage, '%)') SEPARATOR ', ') as owners,
        COUNT(DISTINCT o.id) as owner_count,
        COUNT(DISTINCT doc.id) as document_count
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
    LEFT JOIN customary_title_details ctd ON t.id = ctd.title_id
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

// Get parcels without active customary titles for the add form
$available_parcels = fetchAll($conn, "
    SELECT p.id, p.parcel_number, p.area, 
           b.name as boma_name, pa.name as payam_name, c.name as county_name
    FROM parcels p
    JOIN bomas b ON p.boma_id = b.id
    JOIN payams pa ON b.payam_id = pa.id
    JOIN counties c ON pa.county_id = c.id
    WHERE NOT EXISTS (
        SELECT 1 FROM titles t 
        WHERE t.parcel_id = p.id AND t.title_type = 'customary' AND t.status = 'active'
    )
    ORDER BY p.parcel_number
");

// Get parties for owner selection
$parties = fetchAll($conn, "
    SELECT id, name, party_type, national_id, registration_number 
    FROM parties 
    ORDER BY name
");

// Get unique communities for filter
try {
    $communities = fetchAll($conn, "
        SELECT DISTINCT community_name 
        FROM customary_title_details 
        WHERE community_name IS NOT NULL AND community_name != ''
        ORDER BY community_name
    ");
} catch (Exception $e) {
    $communities = []; // Table might not exist yet
}

// Get unique traditional authorities for filter
try {
    $authorities = fetchAll($conn, "
        SELECT DISTINCT traditional_authority 
        FROM customary_title_details 
        WHERE traditional_authority IS NOT NULL AND traditional_authority != ''
        ORDER BY traditional_authority
    ");
} catch (Exception $e) {
    $authorities = []; // Table might not exist yet
}

// ============================================================================
// SUMMARY STATISTICS
// ============================================================================

$summary = fetchOne($conn, "
    SELECT 
        COUNT(*) as total_customary,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_titles,
        SUM(CASE WHEN status = 'surrendered' THEN 1 ELSE 0 END) as surrendered_titles,
        COUNT(DISTINCT parcel_id) as unique_parcels,
        (SELECT COUNT(DISTINCT party_id) FROM ownerships o JOIN titles tt ON o.title_id = tt.id WHERE tt.title_type = 'customary') as total_owners
    FROM titles
    WHERE title_type = 'customary'
");

// Get communities count separately
try {
    $community_count = fetchOne($conn, "SELECT COUNT(DISTINCT community_name) as count FROM customary_title_details WHERE community_name IS NOT NULL AND community_name != ''");
    $summary['total_communities'] = $community_count['count'] ?? 0;
} catch (Exception $e) {
    $summary['total_communities'] = 0;
}
?>

<body data-page="titles-customary" class="titles-customary-page">
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
                            <h1 class="h3 mb-0">Customary Land Titles</h1>
                            <p class="text-muted mb-0">Manage community-based and traditional land ownership</p>
                        </div>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCustomaryModal">
                            <i class="bi bi-file-earmark-plus me-2"></i>Register Customary Title
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
                                                <i class="bi bi-file-text text-primary fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Total Customary</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['total_customary'] ?? 0); ?></h3>
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
                                            <div class="bg-warning bg-opacity-10 rounded-circle p-3">
                                                <i class="bi bi-people text-warning fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Communities</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['total_communities'] ?? 0); ?></h3>
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
                                                <i class="bi bi-person-badge text-danger fs-4"></i>
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
                        
                        <div class="col-xl-2 col-md-4">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0 me-3">
                                            <div class="bg-secondary bg-opacity-10 rounded-circle p-3">
                                                <i class="bi bi-arrow-return-left text-secondary fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Surrendered</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['surrendered_titles'] ?? 0); ?></h3>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Customary Information Banner -->
                    <div class="alert alert-info d-flex align-items-center mb-4" role="alert">
                        <i class="bi bi-info-circle-fill fs-4 me-3"></i>
                        <div>
                            <strong>About Customary Land Rights:</strong> Customary land is held under traditional systems and governed by customary law. 
                            These titles recognize community-based ownership and traditional authority structures.
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
                                        <option value="surrendered" <?php echo ($_GET['status'] ?? '') == 'surrendered' ? 'selected' : ''; ?>>Surrendered</option>
                                    </select>
                                </div>
                                
                                <div class="col-md-2">
                                    <label class="form-label">Location</label>
                                    <div class="row g-1">
                                        <div class="col-6">
                                            <select class="form-select" name="state_id" id="filterState" onchange="this.form.submit()">
                                                <option value="">State</option>
                                                <?php foreach ($states as $state): ?>
                                                <option value="<?php echo $state['id']; ?>" 
                                                    <?php echo ($selected_state == $state['id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($state['name']); ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-6">
                                            <select class="form-select" name="county_id" id="filterCounty" onchange="this.form.submit()" 
                                                    <?php echo empty($counties) ? 'disabled' : ''; ?>>
                                                <option value="">County</option>
                                                <?php foreach ($counties as $county): ?>
                                                <option value="<?php echo $county['id']; ?>" 
                                                    <?php echo ($selected_county == $county['id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($county['name']); ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
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
                                    <a href="titles-customary.php" class="btn btn-secondary">
                                        <i class="bi bi-x-circle me-2"></i>Clear Filters
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Customary Titles Table -->
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0 fw-bold">
                                <i class="bi bi-file-text text-warning me-2"></i>
                                Customary Land Titles
                            </h5>
                            <span class="badge bg-primary"><?php echo number_format($total_rows); ?> Records</span>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Title #</th>
                                            <th>Parcel</th>
                                            <th>Community</th>
                                            <th>Traditional Authority</th>
                                            <th>Chief</th>
                                            <th>Owners</th>
                                            <th>Issue Date</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($titles)): ?>
                                        <tr>
                                            <td colspan="9" class="text-center py-4 text-muted">
                                                <i class="bi bi-file-text fs-1 d-block mb-3"></i>
                                                No customary titles found. Click "Register Customary Title" to create one.
                                            </td>
                                        </tr>
                                        <?php else: ?>
                                            <?php foreach ($titles as $title): ?>
                                            <tr>
                                                <td>
                                                    <span class="fw-medium"><?php echo htmlspecialchars($title['title_number']); ?></span>
                                                </td>
                                                <td>
                                                    <span class="fw-medium"><?php echo htmlspecialchars($title['parcel_number']); ?></span>
                                                    <br><small class="text-muted">Area: <?php echo number_format($title['parcel_area'], 2); ?> m²</small>
                                                </td>
                                                <td>
                                                    <?php if (!empty($title['community_name'])): ?>
                                                        <span class="badge bg-info"><?php echo htmlspecialchars($title['community_name']); ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars($title['traditional_authority'] ?? '—'); ?>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars($title['chief_name'] ?? '—'); ?>
                                                </td>
                                                <td>
                                                    <?php if (($title['owner_count'] ?? 0) > 0): ?>
                                                        <span class="badge bg-success"><?php echo $title['owner_count']; ?></span>
                                                        <br><small><?php echo htmlspecialchars(substr($title['owners'] ?? '', 0, 30)); ?></small>
                                                    <?php else: ?>
                                                        <span class="text-muted">None</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php echo date('d M Y', strtotime($title['issue_date'])); ?>
                                                </td>
                                                <td>
                                                    <?php
                                                    $status_class = [
                                                        'active' => 'success',
                                                        'surrendered' => 'warning'
                                                    ][$title['status']] ?? 'secondary';
                                                    ?>
                                                    <span class="badge bg-<?php echo $status_class; ?>">
                                                        <?php echo ucfirst($title['status']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <button class="btn btn-outline-primary" 
                                                                onclick="editCustomary(<?php echo htmlspecialchars(json_encode($title)); ?>)"
                                                                data-bs-toggle="modal" data-bs-target="#editCustomaryModal">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                        <a href="?delete=<?php echo $title['id']; ?>" 
                                                           class="btn btn-outline-danger"
                                                           onclick="return confirm('Surrender/Delete this customary title? This may affect community records.')">
                                                            <i class="bi bi-trash"></i>
                                                        </a>
                                                        <button class="btn btn-outline-info" 
                                                                onclick="viewCustomary(<?php echo htmlspecialchars(json_encode($title)); ?>)"
                                                                data-bs-toggle="modal" data-bs-target="#viewCustomaryModal">
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

                    <!-- Customary Land Information -->
                    <div class="row g-4 mt-4">
                        <div class="col-md-6">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h5 class="card-title mb-0 fw-bold">
                                        <i class="bi bi-info-circle text-info me-2"></i>
                                        About Customary Land Tenure
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <p><strong>Customary land</strong> refers to land owned by communities under traditional systems and governed by customary law rather than statutory law.</p>
                                    
                                    <h6 class="fw-bold mt-3">Key Characteristics:</h6>
                                    <ul class="mb-0">
                                        <li>Community-based ownership</li>
                                        <li>Governed by traditional authorities and chiefs</li>
                                        <li>Rights defined by customary law and practice</li>
                                        <li>Often held collectively by families or communities</li>
                                        <li>May be converted to freehold through formalization</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h5 class="card-title mb-0 fw-bold">
                                        <i class="bi bi-bar-chart text-success me-2"></i>
                                        Customary Land Statistics
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="row text-center mb-3">
                                        <div class="col-4">
                                            <div class="border rounded p-2">
                                                <div class="text-muted small">Active</div>
                                                <h4 class="mb-0 text-success">
                                                    <?php echo $summary['active_titles'] ?? 0; ?>
                                                </h4>
                                            </div>
                                        </div>
                                        <div class="col-4">
                                            <div class="border rounded p-2">
                                                <div class="text-muted small">Communities</div>
                                                <h4 class="mb-0 text-info">
                                                    <?php echo $summary['total_communities'] ?? 0; ?>
                                                </h4>
                                            </div>
                                        </div>
                                        <div class="col-4">
                                            <div class="border rounded p-2">
                                                <div class="text-muted small">Surrendered</div>
                                                <h4 class="mb-0 text-warning">
                                                    <?php echo $summary['surrendered_titles'] ?? 0; ?>
                                                </h4>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <?php if (($summary['total_customary'] ?? 0) > 0): ?>
                                    <div class="progress mt-3" style="height: 20px;">
                                        <?php 
                                        $active_percent = round(($summary['active_titles'] ?? 0) / ($summary['total_customary'] ?? 1) * 100, 1);
                                        $surrendered_percent = round(($summary['surrendered_titles'] ?? 0) / ($summary['total_customary'] ?? 1) * 100, 1);
                                        ?>
                                        <div class="progress-bar bg-success" role="progressbar" 
                                             style="width: <?php echo $active_percent; ?>%;" 
                                             title="Active: <?php echo $active_percent; ?>%">
                                            <?php echo $active_percent; ?>%
                                        </div>
                                        <div class="progress-bar bg-warning" role="progressbar" 
                                             style="width: <?php echo $surrendered_percent; ?>%;" 
                                             title="Surrendered: <?php echo $surrendered_percent; ?>%">
                                            <?php echo $surrendered_percent; ?>%
                                        </div>
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

    <!-- Add Customary Title Modal -->
    <div class="modal fade" id="addCustomaryModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="add">
                    <div class="modal-header">
                        <h5 class="modal-title">Register New Customary Title</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-8 mb-3">
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
                            </div>
                            
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Title Number <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="title_number" required 
                                       placeholder="e.g., CUST/2024/001">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Issue Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="issue_date" 
                                       value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Community Name</label>
                                <input type="text" class="form-control" name="community_name" 
                                       placeholder="e.g., Bari Community">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Traditional Authority</label>
                                <input type="text" class="form-control" name="traditional_authority" 
                                       placeholder="e.g., Bari Traditional Council">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Chief's Name</label>
                                <input type="text" class="form-control" name="chief_name" 
                                       placeholder="e.g., Chief John Doe">
                            </div>
                            
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Witnesses</label>
                                <textarea class="form-control" name="witnesses" rows="2" 
                                          placeholder="Names of witnesses present during registration"></textarea>
                            </div>
                            
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Customary Law Details</label>
                                <textarea class="form-control" name="customary_law_details" rows="3" 
                                          placeholder="Describe the customary laws and practices governing this land..."></textarea>
                            </div>
                            
                            <div class="col-md-8 mb-3">
                                <label class="form-label">Select Primary Owner <span class="text-danger">*</span></label>
                                <select class="form-select" name="owner_party_id" required>
                                    <option value="">-- Select an owner --</option>
                                    <?php foreach ($parties as $party): ?>
                                    <option value="<?php echo $party['id']; ?>">
                                        <?php echo htmlspecialchars($party['name']); ?> 
                                        (<?php echo $party['party_type']; ?>)
                                        <?php if (!empty($party['national_id'])): ?> - ID: <?php echo $party['national_id']; ?><?php endif; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Share Percentage</label>
                                <input type="number" class="form-control" name="share_percentage" 
                                       value="100" min="1" max="100" step="0.01">
                            </div>
                            
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Title Document (PDF/Image)</label>
                                <input type="file" class="form-control" name="title_document" 
                                       accept=".pdf,.jpg,.jpeg,.png">
                            </div>
                            
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Registration Notes</label>
                                <textarea class="form-control" name="registration_notes" rows="2"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Register Customary Title</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Customary Title Modal -->
    <div class="modal fade" id="editCustomaryModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="title_id" id="editTitleId">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Customary Title</h5>
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
                                    <option value="surrendered">Surrendered</option>
                                </select>
                            </div>
                            
                            <div class="col-12">
                                <hr>
                                <h6 class="fw-bold">Community Information</h6>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Community Name</label>
                                <input type="text" class="form-control" name="community_name" id="editCommunity">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Traditional Authority</label>
                                <input type="text" class="form-control" name="traditional_authority" id="editAuthority">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Chief's Name</label>
                                <input type="text" class="form-control" name="chief_name" id="editChief">
                            </div>
                            
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Witnesses</label>
                                <textarea class="form-control" name="witnesses" id="editWitnesses" rows="2"></textarea>
                            </div>
                            
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Customary Law Details</label>
                                <textarea class="form-control" name="customary_law_details" id="editLawDetails" rows="2"></textarea>
                            </div>
                            
                            <div class="col-12">
                                <hr>
                                <h6 class="fw-bold">Parcel Information</h6>
                            </div>
                            
                            <div class="col-12 mb-3">
                                <label class="form-label">Parcel</label>
                                <input type="text" class="form-control" id="editParcelInfo" readonly disabled>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Customary Title</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Customary Title Modal -->
    <div class="modal fade" id="viewCustomaryModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Customary Title Details</h5>
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
                        
                        <div class="col-12">
                            <hr>
                            <h6 class="fw-bold">Community Information</h6>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Community:</label>
                            <p id="viewCommunity" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Traditional Authority:</label>
                            <p id="viewAuthority" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Chief:</label>
                            <p id="viewChief" class="mb-0"></p>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="fw-bold">Witnesses:</label>
                            <p id="viewWitnesses" class="mb-0"></p>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="fw-bold">Customary Law:</label>
                            <p id="viewLawDetails" class="mb-0"></p>
                        </div>
                        
                        <div class="col-12">
                            <hr>
                            <h6 class="fw-bold">Parcel Information</h6>
                        </div>
                        
                        <div class="col-12 mb-3">
                            <label class="fw-bold">Parcel:</label>
                            <p id="viewParcel" class="mb-0"></p>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="fw-bold">Location:</label>
                            <p id="viewLocation" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Area:</label>
                            <p id="viewArea" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Zoning:</label>
                            <p id="viewZoning" class="mb-0"></p>
                        </div>
                        
                        <div class="col-12">
                            <hr>
                            <h6 class="fw-bold">Ownership</h6>
                        </div>
                        
                        <div class="col-12 mb-3">
                            <label class="fw-bold">Owners:</label>
                            <p id="viewOwners" class="mb-0"></p>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Documents:</label>
                            <p id="viewDocuments" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Created:</label>
                            <p id="viewCreated" class="mb-0"></p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <a href="#" id="viewHistoryLink" class="btn btn-outline-primary" target="_blank">
                        <i class="bi bi-clock-history"></i> View Parcel History
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Edit customary function
        function editCustomary(title) {
            document.getElementById('editTitleId').value = title.id || '';
            document.getElementById('editTitleNumber').value = title.title_number || '';
            document.getElementById('editIssueDate').value = title.issue_date || '';
            document.getElementById('editStatus').value = title.status || 'active';
            document.getElementById('editCommunity').value = title.community_name || '';
            document.getElementById('editAuthority').value = title.traditional_authority || '';
            document.getElementById('editChief').value = title.chief_name || '';
            document.getElementById('editWitnesses').value = title.witnesses || '';
            document.getElementById('editLawDetails').value = title.customary_law_details || '';
            
            document.getElementById('editParcelInfo').value = 
                (title.parcel_number || 'N/A') + ' - Area: ' + (title.parcel_area || '0') + ' m² - ' +
                (title.boma_name || '') + ', ' + (title.payam_name || '');
        }
        
        // View customary function
        function viewCustomary(title) {
            document.getElementById('viewTitleNumber').textContent = title.title_number || 'N/A';
            
            let statusBadge = '';
            if (title.status === 'active') statusBadge = '<span class="badge bg-success">Active</span>';
            else if (title.status === 'surrendered') statusBadge = '<span class="badge bg-warning">Surrendered</span>';
            else statusBadge = title.status || 'N/A';
            
            document.getElementById('viewStatus').innerHTML = statusBadge;
            document.getElementById('viewIssueDate').textContent = title.issue_date ? new Date(title.issue_date).toLocaleDateString() : 'N/A';
            
            document.getElementById('viewCommunity').textContent = title.community_name || 'Not specified';
            document.getElementById('viewAuthority').textContent = title.traditional_authority || 'Not specified';
            document.getElementById('viewChief').textContent = title.chief_name || 'Not specified';
            document.getElementById('viewWitnesses').textContent = title.witnesses || 'None recorded';
            document.getElementById('viewLawDetails').textContent = title.customary_law_details || 'No details recorded';
            
            document.getElementById('viewParcel').textContent = title.parcel_number || 'N/A';
            document.getElementById('viewLocation').textContent = 
                (title.boma_name || '') + ', ' + (title.payam_name || '') + ', ' + 
                (title.county_name || '') + ', ' + (title.state_name || '');
            document.getElementById('viewArea').textContent = title.parcel_area ? title.parcel_area + ' m²' : 'N/A';
            document.getElementById('viewZoning').textContent = title.zone_code ? title.zone_code + ' - ' + title.zone_name : 'Not zoned';
            
            document.getElementById('viewOwners').textContent = title.owners || 'No owners recorded';
            document.getElementById('viewDocuments').textContent = title.document_count || 0;
            document.getElementById('viewCreated').textContent = title.created_at ? new Date(title.created_at).toLocaleString() : 'N/A';
            
            document.getElementById('viewHistoryLink').href = 'parcels-history.php?parcel_id=' + (title.parcel_id || '');
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
        
        .progress {
            border-radius: 4px;
            height: 20px;
        }
        
        .progress-bar {
            font-size: 0.8rem;
            line-height: 20px;
        }
        
        select:disabled {
            background-color: #e9ecef;
            cursor: not-allowed;
        }
    </style>

</body>
</html>