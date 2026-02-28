<?php
ini_set('display_errors', 1); 
error_reporting(E_ALL);

// encumbrances.php
include("head.php");
require_once 'db_connection.php';

// ============================================================================
// HANDLE ACTIONS
// ============================================================================

$message = '';
$messageType = '';

// Add encumbrance
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    if ($_POST['action'] === 'add') {
        $parcel_id = $_POST['parcel_id'];
        $encumbrance_type = $_POST['encumbrance_type'];
        $description = $_POST['description'] ?? null;
        $start_date = $_POST['start_date'];
        $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
        $involved_party_id = !empty($_POST['involved_party_id']) ? $_POST['involved_party_id'] : null;
        $reference_number = $_POST['reference_number'] ?? null;
        $amount = !empty($_POST['amount']) ? $_POST['amount'] : null;
        $interest_rate = !empty($_POST['interest_rate']) ? $_POST['interest_rate'] : null;
        $lender_name = $_POST['lender_name'] ?? null;
        $terms_conditions = $_POST['terms_conditions'] ?? null;
        $document_reference = $_POST['document_reference'] ?? null;
        
        try {
            executeQuery($conn, "
                INSERT INTO encumbrances (
                    parcel_id, encumbrance_type, description, start_date, end_date,
                    involved_party_id, reference_number, amount, interest_rate,
                    lender_name, terms_conditions, document_reference, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ", [
                $parcel_id, $encumbrance_type, $description, $start_date, $end_date,
                $involved_party_id, $reference_number, $amount, $interest_rate,
                $lender_name, $terms_conditions, $document_reference
            ]);
            
            $message = "Encumbrance added successfully";
            $messageType = "success";
        } catch (Exception $e) {
            $message = "Error adding encumbrance: " . $e->getMessage();
            $messageType = "danger";
        }
    }
    
    // Edit encumbrance
    if ($_POST['action'] === 'edit') {
        $encumbrance_id = $_POST['encumbrance_id'];
        $encumbrance_type = $_POST['encumbrance_type'];
        $description = $_POST['description'] ?? null;
        $start_date = $_POST['start_date'];
        $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
        $involved_party_id = !empty($_POST['involved_party_id']) ? $_POST['involved_party_id'] : null;
        $reference_number = $_POST['reference_number'] ?? null;
        $amount = !empty($_POST['amount']) ? $_POST['amount'] : null;
        $interest_rate = !empty($_POST['interest_rate']) ? $_POST['interest_rate'] : null;
        $lender_name = $_POST['lender_name'] ?? null;
        $terms_conditions = $_POST['terms_conditions'] ?? null;
        $document_reference = $_POST['document_reference'] ?? null;
        
        try {
            executeQuery($conn, "
                UPDATE encumbrances SET 
                    encumbrance_type = ?, description = ?, start_date = ?, end_date = ?,
                    involved_party_id = ?, reference_number = ?, amount = ?, interest_rate = ?,
                    lender_name = ?, terms_conditions = ?, document_reference = ?, updated_at = NOW()
                WHERE id = ?
            ", [
                $encumbrance_type, $description, $start_date, $end_date,
                $involved_party_id, $reference_number, $amount, $interest_rate,
                $lender_name, $terms_conditions, $document_reference, $encumbrance_id
            ]);
            
            $message = "Encumbrance updated successfully";
            $messageType = "success";
        } catch (Exception $e) {
            $message = "Error updating encumbrance: " . $e->getMessage();
            $messageType = "danger";
        }
    }
    
    // Release encumbrance (set end date to today)
    if ($_POST['action'] === 'release') {
        $encumbrance_id = $_POST['encumbrance_id'];
        $release_date = $_POST['release_date'] ?? date('Y-m-d');
        $release_notes = $_POST['release_notes'] ?? null;
        
        try {
            executeQuery($conn, "
                UPDATE encumbrances SET 
                    end_date = ?, 
                    description = CONCAT(description, '\n\nRELEASED ON ', ?, ': ', ?),
                    updated_at = NOW()
                WHERE id = ?
            ", [$release_date, $release_date, $release_notes, $encumbrance_id]);
            
            $message = "Encumbrance released successfully";
            $messageType = "success";
        } catch (Exception $e) {
            $message = "Error releasing encumbrance: " . $e->getMessage();
            $messageType = "danger";
        }
    }
    
    // Delete encumbrance
    if ($_POST['action'] === 'delete') {
        $encumbrance_id = $_POST['encumbrance_id'];
        
        try {
            executeQuery($conn, "DELETE FROM encumbrances WHERE id = ?", [$encumbrance_id]);
            
            $message = "Encumbrance deleted successfully";
            $messageType = "success";
        } catch (Exception $e) {
            $message = "Error deleting encumbrance: " . $e->getMessage();
            $messageType = "danger";
        }
    }
}

// ============================================================================
// GET FILTERS AND DATA
// ============================================================================

// Check if we need to add additional columns to encumbrances table
try {
    // Check for reference_number column
    $result = fetchOne($conn, "SHOW COLUMNS FROM encumbrances LIKE 'reference_number'");
    if (!$result) {
        executeQuery($conn, "ALTER TABLE encumbrances ADD COLUMN reference_number VARCHAR(100) AFTER involved_party_id");
    }
    
    // Check for amount column
    $result = fetchOne($conn, "SHOW COLUMNS FROM encumbrances LIKE 'amount'");
    if (!$result) {
        executeQuery($conn, "ALTER TABLE encumbrances ADD COLUMN amount DECIMAL(15,2) AFTER reference_number");
    }
    
    // Check for interest_rate column
    $result = fetchOne($conn, "SHOW COLUMNS FROM encumbrances LIKE 'interest_rate'");
    if (!$result) {
        executeQuery($conn, "ALTER TABLE encumbrances ADD COLUMN interest_rate DECIMAL(5,2) AFTER amount");
    }
    
    // Check for lender_name column
    $result = fetchOne($conn, "SHOW COLUMNS FROM encumbrances LIKE 'lender_name'");
    if (!$result) {
        executeQuery($conn, "ALTER TABLE encumbrances ADD COLUMN lender_name VARCHAR(255) AFTER interest_rate");
    }
    
    // Check for terms_conditions column
    $result = fetchOne($conn, "SHOW COLUMNS FROM encumbrances LIKE 'terms_conditions'");
    if (!$result) {
        executeQuery($conn, "ALTER TABLE encumbrances ADD COLUMN terms_conditions TEXT AFTER lender_name");
    }
    
    // Check for document_reference column
    $result = fetchOne($conn, "SHOW COLUMNS FROM encumbrances LIKE 'document_reference'");
    if (!$result) {
        executeQuery($conn, "ALTER TABLE encumbrances ADD COLUMN document_reference VARCHAR(255) AFTER terms_conditions");
    }
    
    // Check for updated_at column
    $result = fetchOne($conn, "SHOW COLUMNS FROM encumbrances LIKE 'updated_at'");
    if (!$result) {
        executeQuery($conn, "ALTER TABLE encumbrances ADD COLUMN updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
    }
} catch (Exception $e) {
    // Columns might already exist
}

// Get all parcels for filter
$parcels = fetchAll($conn, "
    SELECT 
        p.id,
        p.parcel_number,
        p.area,
        b.name as boma_name,
        pa.name as payam_name,
        c.name as county_name,
        s.name as state_name
    FROM parcels p
    JOIN bomas b ON p.boma_id = b.id
    JOIN payams pa ON b.payam_id = pa.id
    JOIN counties c ON pa.county_id = c.id
    JOIN states s ON c.state_id = s.id
    ORDER BY p.parcel_number
");

// Get all parties for involved party dropdown
$parties = fetchAll($conn, "
    SELECT id, name, party_type, national_id, registration_number, phone, email
    FROM parties 
    ORDER BY name
");

// Get states for filter
$states = fetchAll($conn, "SELECT id, name FROM states ORDER BY name");

// Build filter query
$whereClause = " WHERE 1=1";
$params = [];

if (isset($_GET['encumbrance_type']) && !empty($_GET['encumbrance_type'])) {
    $whereClause .= " AND e.encumbrance_type = ?";
    $params[] = $_GET['encumbrance_type'];
}

if (isset($_GET['status']) && !empty($_GET['status'])) {
    if ($_GET['status'] === 'active') {
        $whereClause .= " AND (e.end_date IS NULL OR e.end_date >= CURDATE())";
    } elseif ($_GET['status'] === 'expired') {
        $whereClause .= " AND e.end_date < CURDATE()";
    } elseif ($_GET['status'] === 'no_end_date') {
        $whereClause .= " AND e.end_date IS NULL";
    }
}

if (isset($_GET['parcel_id']) && !empty($_GET['parcel_id'])) {
    $whereClause .= " AND e.parcel_id = ?";
    $params[] = $_GET['parcel_id'];
}

if (isset($_GET['party_id']) && !empty($_GET['party_id'])) {
    $whereClause .= " AND e.involved_party_id = ?";
    $params[] = $_GET['party_id'];
}

if (isset($_GET['state_id']) && !empty($_GET['state_id'])) {
    $whereClause .= " AND s.id = ?";
    $params[] = $_GET['state_id'];
}

if (isset($_GET['date_from']) && !empty($_GET['date_from'])) {
    $whereClause .= " AND e.start_date >= ?";
    $params[] = $_GET['date_from'];
}

if (isset($_GET['date_to']) && !empty($_GET['date_to'])) {
    $whereClause .= " AND e.start_date <= ?";
    $params[] = $_GET['date_to'];
}

if (isset($_GET['expiring_soon']) && $_GET['expiring_soon'] == '1') {
    $whereClause .= " AND e.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
}

if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search = '%' . $_GET['search'] . '%';
    $whereClause .= " AND (e.description LIKE ? OR e.reference_number LIKE ? OR e.lender_name LIKE ? OR p.parcel_number LIKE ? OR party.name LIKE ?)";
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
}

// Get encumbrances with full details
$encumbrances = fetchAll($conn, "
    SELECT 
        e.*,
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
        -- Involved party details
        party.id as party_id,
        party.name as party_name,
        party.party_type as party_type,
        party.national_id,
        party.registration_number as reg_number,
        party.phone as party_phone,
        party.email as party_email,
        -- Calculated fields
        DATEDIFF(e.end_date, CURDATE()) as days_remaining,
        CASE 
            WHEN e.end_date IS NULL THEN 'Indefinite'
            WHEN e.end_date < CURDATE() THEN 'Expired'
            WHEN e.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 'Expiring Soon'
            ELSE 'Active'
        END as status_label
    FROM encumbrances e
    JOIN parcels p ON e.parcel_id = p.id
    JOIN bomas b ON p.boma_id = b.id
    JOIN payams pa ON b.payam_id = pa.id
    JOIN counties c ON pa.county_id = c.id
    JOIN states s ON c.state_id = s.id
    LEFT JOIN parties party ON e.involved_party_id = party.id
    $whereClause
    ORDER BY 
        CASE 
            WHEN e.end_date IS NULL THEN 1
            WHEN e.end_date < CURDATE() THEN 4
            WHEN e.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 2
            ELSE 3
        END,
        e.end_date ASC,
        e.start_date DESC
", $params);

// Get summary statistics
$summary = fetchOne($conn, "
    SELECT 
        COUNT(*) as total_encumbrances,
        SUM(CASE WHEN e.encumbrance_type = 'mortgage' THEN 1 ELSE 0 END) as mortgages,
        SUM(CASE WHEN e.encumbrance_type = 'lien' THEN 1 ELSE 0 END) as liens,
        SUM(CASE WHEN e.encumbrance_type = 'easement' THEN 1 ELSE 0 END) as easements,
        SUM(CASE WHEN e.encumbrance_type = 'caveat' THEN 1 ELSE 0 END) as caveats,
        SUM(CASE WHEN e.encumbrance_type = 'other' THEN 1 ELSE 0 END) as other,
        SUM(CASE WHEN e.end_date IS NULL OR e.end_date >= CURDATE() THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN e.end_date < CURDATE() THEN 1 ELSE 0 END) as expired,
        SUM(CASE WHEN e.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as expiring_soon,
        SUM(e.amount) as total_amount,
        AVG(e.amount) as avg_amount,
        COUNT(DISTINCT e.parcel_id) as parcels_with_encumbrances
    FROM encumbrances e
");

// Get encumbrances by type for chart
$by_type = fetchAll($conn, "
    SELECT 
        encumbrance_type,
        COUNT(*) as count,
        SUM(amount) as total_amount
    FROM encumbrances
    GROUP BY encumbrance_type
    ORDER BY count DESC
");

$type_labels = [];
$type_counts = [];
$type_amounts = [];

foreach ($by_type as $row) {
    $type_labels[] = ucfirst($row['encumbrance_type']);
    $type_counts[] = (int)$row['count'];
    $type_amounts[] = (float)($row['total_amount'] ?? 0);
}

// Get monthly trend
$monthly_stats = fetchAll($conn, "
    SELECT 
        YEAR(start_date) as year,
        MONTH(start_date) as month,
        COUNT(*) as count,
        SUM(amount) as total_amount
    FROM encumbrances
    WHERE start_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY YEAR(start_date), MONTH(start_date)
    ORDER BY year DESC, month DESC
");

$monthly_labels = [];
$monthly_counts = [];

foreach ($monthly_stats as $row) {
    $monthly_labels[] = date('M Y', mktime(0, 0, 0, $row['month'], 1, $row['year']));
    $monthly_counts[] = (int)$row['count'];
}

// Get top parcels with most encumbrances
$top_parcels = fetchAll($conn, "
    SELECT 
        p.parcel_number,
        p.area,
        b.name as boma_name,
        COUNT(e.id) as encumbrance_count,
        GROUP_CONCAT(DISTINCT e.encumbrance_type) as types
    FROM parcels p
    JOIN encumbrances e ON p.id = e.parcel_id
    JOIN bomas b ON p.boma_id = b.id
    GROUP BY p.id, p.parcel_number, p.area, b.name
    ORDER BY encumbrance_count DESC
    LIMIT 10
");

// Get expiring soon
$expiring_soon = fetchAll($conn, "
    SELECT 
        e.*,
        p.parcel_number,
        b.name as boma_name,
        pa.name as payam_name,
        party.name as party_name,
        DATEDIFF(e.end_date, CURDATE()) as days_left
    FROM encumbrances e
    JOIN parcels p ON e.parcel_id = p.id
    JOIN bomas b ON p.boma_id = b.id
    JOIN payams pa ON b.payam_id = pa.id
    LEFT JOIN parties party ON e.involved_party_id = party.id
    WHERE e.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    ORDER BY e.end_date
");

// Get recent activity
$recent_activity = fetchAll($conn, "
    SELECT 
        e.*,
        p.parcel_number,
        CONCAT(UCASE(LEFT(e.encumbrance_type, 1)), SUBSTRING(e.encumbrance_type, 2)) as type_label,
        CONCAT(e.encumbrance_type, ' on parcel ', p.parcel_number) as description
    FROM encumbrances e
    JOIN parcels p ON e.parcel_id = p.id
    ORDER BY e.created_at DESC
    LIMIT 15
");
?>

<body data-page="encumbrances" class="encumbrances-page">
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
                                <i class="bi bi-shield-lock text-warning me-2"></i>
                                Encumbrances Management
                            </h1>
                            <p class="text-muted mb-0">Track mortgages, liens, easements, caveats and other encumbrances on land parcels</p>
                        </div>
                        <div>
                            <button class="btn btn-outline-secondary me-2" type="button" data-bs-toggle="collapse" data-bs-target="#filterCollapse">
                                <i class="bi bi-funnel me-2"></i>Filters
                            </button>
                            <button class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#addEncumbranceModal">
                                <i class="bi bi-plus-circle me-2"></i>Add Encumbrance
                            </button>
                        </div>
                    </div>

                    <!-- Filter Collapse -->
                    <div class="collapse mb-4" id="filterCollapse">
                        <div class="card border-0 shadow-sm">
                            <div class="card-body">
                                <form method="GET" class="row g-3">
                                    <div class="col-md-3">
                                        <label class="form-label">Encumbrance Type</label>
                                        <select class="form-select" name="encumbrance_type">
                                            <option value="">All Types</option>
                                            <option value="mortgage" <?php echo (isset($_GET['encumbrance_type']) && $_GET['encumbrance_type'] == 'mortgage') ? 'selected' : ''; ?>>Mortgage</option>
                                            <option value="lien" <?php echo (isset($_GET['encumbrance_type']) && $_GET['encumbrance_type'] == 'lien') ? 'selected' : ''; ?>>Lien</option>
                                            <option value="easement" <?php echo (isset($_GET['encumbrance_type']) && $_GET['encumbrance_type'] == 'easement') ? 'selected' : ''; ?>>Easement</option>
                                            <option value="caveat" <?php echo (isset($_GET['encumbrance_type']) && $_GET['encumbrance_type'] == 'caveat') ? 'selected' : ''; ?>>Caveat</option>
                                            <option value="other" <?php echo (isset($_GET['encumbrance_type']) && $_GET['encumbrance_type'] == 'other') ? 'selected' : ''; ?>>Other</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Status</label>
                                        <select class="form-select" name="status">
                                            <option value="">All</option>
                                            <option value="active" <?php echo (isset($_GET['status']) && $_GET['status'] == 'active') ? 'selected' : ''; ?>>Active</option>
                                            <option value="expired" <?php echo (isset($_GET['status']) && $_GET['status'] == 'expired') ? 'selected' : ''; ?>>Expired</option>
                                            <option value="no_end_date" <?php echo (isset($_GET['status']) && $_GET['status'] == 'no_end_date') ? 'selected' : ''; ?>>No End Date</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Parcel</label>
                                        <select class="form-select" name="parcel_id">
                                            <option value="">All Parcels</option>
                                            <?php foreach ($parcels as $parcel): ?>
                                            <option value="<?php echo $parcel['id']; ?>" <?php echo (isset($_GET['parcel_id']) && $_GET['parcel_id'] == $parcel['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($parcel['parcel_number'] . ' - ' . $parcel['boma_name']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Involved Party</label>
                                        <select class="form-select" name="party_id">
                                            <option value="">All Parties</option>
                                            <?php foreach ($parties as $party): ?>
                                            <option value="<?php echo $party['id']; ?>" <?php echo (isset($_GET['party_id']) && $_GET['party_id'] == $party['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($party['name']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">State</label>
                                        <select class="form-select" name="state_id">
                                            <option value="">All States</option>
                                            <?php foreach ($states as $state): ?>
                                            <option value="<?php echo $state['id']; ?>" <?php echo (isset($_GET['state_id']) && $_GET['state_id'] == $state['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($state['name']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Date From</label>
                                        <input type="date" class="form-control" name="date_from" value="<?php echo isset($_GET['date_from']) ? htmlspecialchars($_GET['date_from']) : ''; ?>">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Date To</label>
                                        <input type="date" class="form-control" name="date_to" value="<?php echo isset($_GET['date_to']) ? htmlspecialchars($_GET['date_to']) : ''; ?>">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">
                                            <input type="checkbox" name="expiring_soon" value="1" <?php echo (isset($_GET['expiring_soon']) && $_GET['expiring_soon'] == '1') ? 'checked' : ''; ?>>
                                            Expiring Soon (30 days)
                                        </label>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Search</label>
                                        <div class="input-group">
                                            <input type="text" class="form-control" name="search" placeholder="Description, reference #, lender, parcel #, party..." 
                                                   value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
                                            <button class="btn btn-primary" type="submit">
                                                <i class="bi bi-search"></i>
                                            </button>
                                            <?php if (!empty($_GET)): ?>
                                            <a href="encumbrances.php" class="btn btn-outline-secondary">
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

                    <!-- Expiring Soon Alert -->
                    <?php if (!empty($expiring_soon)): ?>
                    <div class="alert alert-warning mb-4">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-exclamation-triangle-fill fs-3 me-3"></i>
                            <div>
                                <strong><?php echo count($expiring_soon); ?> encumbrances expiring within 30 days</strong>
                                <button class="btn btn-sm btn-warning ms-3" type="button" data-bs-toggle="collapse" data-bs-target="#expiringList">
                                    View Details
                                </button>
                            </div>
                        </div>
                        <div class="collapse mt-3" id="expiringList">
                            <div class="list-group">
                                <?php foreach ($expiring_soon as $item): ?>
                                <div class="list-group-item">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <strong><?php echo ucfirst($item['encumbrance_type']); ?></strong>
                                            <span class="text-muted mx-2">|</span>
                                            <?php echo htmlspecialchars($item['parcel_number']); ?>
                                            <span class="text-muted mx-2">|</span>
                                            <?php echo htmlspecialchars($item['boma_name']); ?>, <?php echo htmlspecialchars($item['payam_name']); ?>
                                            <?php if ($item['party_name']): ?>
                                            <br><small>Party: <?php echo htmlspecialchars($item['party_name']); ?></small>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <span class="badge bg-danger"><?php echo $item['days_left']; ?> days left</span>
                                            <small class="text-muted ms-2">Expires: <?php echo date('d M Y', strtotime($item['end_date'])); ?></small>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
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
                                                <i class="bi bi-shield-lock text-primary fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Total</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['total_encumbrances'] ?? 0); ?></h3>
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
                                            <h6 class="text-muted mb-1">Active</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['active'] ?? 0); ?></h3>
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
                                                <i class="bi bi-hourglass-split text-warning fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Expiring Soon</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['expiring_soon'] ?? 0); ?></h3>
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
                                                <i class="bi bi-x-circle text-danger fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Expired</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['expired'] ?? 0); ?></h3>
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
                                                <i class="bi bi-cash-stack text-info fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Total Value</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['total_amount'] ?? 0, 2); ?> SSP</h3>
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
                                            <h6 class="text-muted mb-1">Parcels Affected</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['parcels_with_encumbrances'] ?? 0); ?></h3>
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
                                        <i class="bi bi-pie-chart text-primary me-2"></i>Encumbrances by Type
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <canvas id="typeChart" style="height: 300px;"></canvas>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-xl-6">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h5 class="card-title mb-0 fw-bold">
                                        <i class="bi bi-bar-chart text-success me-2"></i>Monthly Trend
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <canvas id="monthlyChart" style="height: 300px;"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Top Parcels with Encumbrances -->
                    <?php if (!empty($top_parcels)): ?>
                    <div class="card border-0 shadow-sm mb-5">
                        <div class="card-header bg-white py-3">
                            <h5 class="card-title mb-0 fw-bold">
                                <i class="bi bi-trophy text-warning me-2"></i>Top Parcels with Most Encumbrances
                            </h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>Parcel #</th>
                                            <th>Location</th>
                                            <th>Area (m²)</th>
                                            <th>Encumbrance Count</th>
                                            <th>Types</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($top_parcels as $parcel): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($parcel['parcel_number']); ?></strong>
                                            </td>
                                            <td><?php echo htmlspecialchars($parcel['boma_name']); ?></td>
                                            <td><?php echo number_format($parcel['area'], 2); ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo $parcel['encumbrance_count'] > 2 ? 'danger' : 'warning'; ?>">
                                                    <?php echo $parcel['encumbrance_count']; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php 
                                                $types = explode(',', $parcel['types']);
                                                foreach ($types as $type) {
                                                    echo '<span class="badge bg-secondary me-1">' . ucfirst(trim($type)) . '</span>';
                                                }
                                                ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Encumbrances Table -->
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0 fw-bold">
                                <i class="bi bi-table me-2"></i>Encumbrances List
                                <span class="badge bg-secondary ms-2"><?php echo count($encumbrances); ?> records</span>
                            </h5>
                            <div>
                                <input type="text" class="form-control form-control-sm" style="width: 250px;" id="tableSearch" placeholder="Search in table...">
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0" id="encumbrancesTable">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>Type</th>
                                            <th>Parcel</th>
                                            <th>Location</th>
                                            <th>Involved Party</th>
                                            <th>Start Date</th>
                                            <th>End Date</th>
                                            <th>Amount</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($encumbrances)): ?>
                                        <tr>
                                            <td colspan="9" class="text-center py-4 text-muted">
                                                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                                No encumbrances found
                                            </td>
                                        </tr>
                                        <?php else: ?>
                                            <?php foreach ($encumbrances as $enc): ?>
                                            <tr class="<?php 
                                                echo $enc['status_label'] == 'Expired' ? 'table-secondary' : 
                                                    ($enc['status_label'] == 'Expiring Soon' ? 'table-warning' : '');
                                            ?>">
                                                <td>
                                                    <span class="badge bg-<?php 
                                                        echo $enc['encumbrance_type'] == 'mortgage' ? 'primary' : 
                                                            ($enc['encumbrance_type'] == 'lien' ? 'danger' : 
                                                            ($enc['encumbrance_type'] == 'easement' ? 'success' : 
                                                            ($enc['encumbrance_type'] == 'caveat' ? 'warning' : 'secondary'))); 
                                                    ?>">
                                                        <?php echo ucfirst($enc['encumbrance_type']); ?>
                                                    </span>
                                                    <?php if ($enc['reference_number']): ?>
                                                    <br><small class="text-muted">Ref: <?php echo htmlspecialchars($enc['reference_number']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($enc['parcel_number']); ?></strong>
                                                    <br><small class="text-muted"><?php echo number_format($enc['parcel_area'], 2); ?> m²</small>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars($enc['boma_name']); ?>, <?php echo htmlspecialchars($enc['payam_name']); ?>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars($enc['county_name']); ?></small>
                                                </td>
                                                <td>
                                                    <?php if ($enc['party_name']): ?>
                                                        <strong><?php echo htmlspecialchars($enc['party_name']); ?></strong>
                                                        <br><small class="text-muted"><?php echo ucfirst($enc['party_type']); ?></small>
                                                    <?php else: ?>
                                                        <span class="text-muted">None</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo date('d M Y', strtotime($enc['start_date'])); ?></td>
                                                <td>
                                                    <?php if ($enc['end_date']): ?>
                                                        <?php echo date('d M Y', strtotime($enc['end_date'])); ?>
                                                        <?php if ($enc['status_label'] == 'Expiring Soon'): ?>
                                                            <br><small class="text-warning"><?php echo $enc['days_remaining']; ?> days left</small>
                                                        <?php elseif ($enc['status_label'] == 'Expired'): ?>
                                                            <br><small class="text-danger">Expired</small>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">Indefinite</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($enc['amount']): ?>
                                                        <strong><?php echo number_format($enc['amount'], 2); ?> SSP</strong>
                                                        <?php if ($enc['interest_rate']): ?>
                                                        <br><small class="text-muted"><?php echo $enc['interest_rate']; ?>% interest</small>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">N/A</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?php 
                                                        echo $enc['status_label'] == 'Active' ? 'success' : 
                                                            ($enc['status_label'] == 'Expiring Soon' ? 'warning' : 
                                                            ($enc['status_label'] == 'Expired' ? 'secondary' : 'info')); 
                                                    ?>">
                                                        <?php echo $enc['status_label']; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="btn-group">
                                                        <button class="btn btn-sm btn-outline-primary" 
                                                                onclick="viewEncumbrance(<?php echo htmlspecialchars(json_encode($enc)); ?>)"
                                                                data-bs-toggle="modal" data-bs-target="#viewEncumbranceModal">
                                                            <i class="bi bi-eye"></i>
                                                        </button>
                                                        
                                                        <button class="btn btn-sm btn-outline-success" 
                                                                onclick="editEncumbrance(<?php echo htmlspecialchars(json_encode($enc)); ?>)"
                                                                data-bs-toggle="modal" data-bs-target="#editEncumbranceModal">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                        
                                                        <?php if ($enc['status_label'] != 'Expired' && $enc['end_date']): ?>
                                                        <button class="btn btn-sm btn-outline-warning" 
                                                                onclick="releaseEncumbrance(<?php echo htmlspecialchars(json_encode($enc)); ?>)"
                                                                data-bs-toggle="modal" data-bs-target="#releaseEncumbranceModal">
                                                            <i class="bi bi-check-circle"></i>
                                                        </button>
                                                        <?php endif; ?>
                                                        
                                                        <button class="btn btn-sm btn-outline-danger" 
                                                                onclick="deleteEncumbrance(<?php echo $enc['id']; ?>, '<?php echo htmlspecialchars(addslashes($enc['encumbrance_type'])); ?> on <?php echo htmlspecialchars(addslashes($enc['parcel_number'])); ?>')"
                                                                data-bs-toggle="modal" data-bs-target="#deleteEncumbranceModal">
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

                    <!-- Recent Activity -->
                    <div class="card border-0 shadow-sm mt-4">
                        <div class="card-header bg-white py-3">
                            <h5 class="card-title mb-0 fw-bold">
                                <i class="bi bi-clock-history me-2"></i>Recent Encumbrance Activity
                            </h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="list-group list-group-flush">
                                <?php foreach ($recent_activity as $activity): ?>
                                <div class="list-group-item">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <span class="badge bg-<?php 
                                                echo $activity['encumbrance_type'] == 'mortgage' ? 'primary' : 
                                                    ($activity['encumbrance_type'] == 'lien' ? 'danger' : 
                                                    ($activity['encumbrance_type'] == 'easement' ? 'success' : 
                                                    ($activity['encumbrance_type'] == 'caveat' ? 'warning' : 'secondary'))); 
                                            ?> me-2">
                                                <?php echo ucfirst($activity['encumbrance_type']); ?>
                                            </span>
                                            <strong><?php echo htmlspecialchars($activity['description']); ?></strong>
                                            <br><small class="text-muted">Parcel: <?php echo htmlspecialchars($activity['parcel_number']); ?></small>
                                        </div>
                                        <small class="text-muted">
                                            <?php echo date('d M Y H:i', strtotime($activity['created_at'])); ?>
                                        </small>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                </div>
            </main>

            <!-- Footer -->
            <?php include("footer.php"); ?>

        </div>
    </div>

    <!-- Add Encumbrance Modal -->
    <div class="modal fade" id="addEncumbranceModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="add">
                    <div class="modal-header">
                        <h5 class="modal-title">Add New Encumbrance</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <ul class="nav nav-tabs mb-3" id="encumbranceTab" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="basic-tab" data-bs-toggle="tab" data-bs-target="#basic" type="button" role="tab">Basic Info</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="financial-tab" data-bs-toggle="tab" data-bs-target="#financial" type="button" role="tab">Financial Details</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="documents-tab" data-bs-toggle="tab" data-bs-target="#documents" type="button" role="tab">Documents</button>
                            </li>
                        </ul>
                        
                        <div class="tab-content">
                            <div class="tab-pane fade show active" id="basic" role="tabpanel">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Parcel <span class="text-danger">*</span></label>
                                        <select class="form-select" name="parcel_id" required>
                                            <option value="">Select Parcel</option>
                                            <?php foreach ($parcels as $parcel): ?>
                                            <option value="<?php echo $parcel['id']; ?>">
                                                <?php echo htmlspecialchars($parcel['parcel_number'] . ' - ' . $parcel['boma_name'] . ', ' . $parcel['payam_name']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Encumbrance Type <span class="text-danger">*</span></label>
                                        <select class="form-select" name="encumbrance_type" required>
                                            <option value="">Select Type</option>
                                            <option value="mortgage">Mortgage</option>
                                            <option value="lien">Lien</option>
                                            <option value="easement">Easement</option>
                                            <option value="caveat">Caveat</option>
                                            <option value="other">Other</option>
                                        </select>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Start Date <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" name="start_date" value="<?php echo date('Y-m-d'); ?>" required>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">End Date (if applicable)</label>
                                        <input type="date" class="form-control" name="end_date" min="<?php echo date('Y-m-d'); ?>">
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Involved Party</label>
                                        <select class="form-select" name="involved_party_id">
                                            <option value="">None</option>
                                            <?php foreach ($parties as $party): ?>
                                            <option value="<?php echo $party['id']; ?>">
                                                <?php echo htmlspecialchars($party['name'] . ' (' . ucfirst($party['party_type']) . ')'); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Reference Number</label>
                                        <input type="text" class="form-control" name="reference_number" placeholder="e.g., MORT-2024-001">
                                    </div>
                                    
                                    <div class="col-md-12 mb-3">
                                        <label class="form-label">Description</label>
                                        <textarea class="form-control" name="description" rows="3" placeholder="Describe the encumbrance..."></textarea>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="tab-pane fade" id="financial" role="tabpanel">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Amount (SSP)</label>
                                        <input type="number" class="form-control" name="amount" step="0.01" placeholder="0.00">
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Interest Rate (%)</label>
                                        <input type="number" class="form-control" name="interest_rate" step="0.01" min="0" max="100" placeholder="e.g., 5.5">
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Lender/Institution Name</label>
                                        <input type="text" class="form-control" name="lender_name" placeholder="e.g., Nile Bank">
                                    </div>
                                    
                                    <div class="col-md-12 mb-3">
                                        <label class="form-label">Terms & Conditions</label>
                                        <textarea class="form-control" name="terms_conditions" rows="4" placeholder="Enter terms and conditions..."></textarea>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="tab-pane fade" id="documents" role="tabpanel">
                                <div class="row">
                                    <div class="col-md-12 mb-3">
                                        <label class="form-label">Document Reference</label>
                                        <input type="text" class="form-control" name="document_reference" placeholder="e.g., File #12345, Deed Book 5, Page 10">
                                    </div>
                                    
                                    <div class="col-md-12">
                                        <div class="alert alert-info">
                                            <i class="bi bi-info-circle me-2"></i>
                                            Document upload will be available in the documents section.
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning">Add Encumbrance</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Encumbrance Modal -->
    <div class="modal fade" id="editEncumbranceModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="encumbrance_id" id="editEncumbranceId">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Encumbrance</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Encumbrance Type</label>
                                <select class="form-select" name="encumbrance_type" id="editType" required>
                                    <option value="mortgage">Mortgage</option>
                                    <option value="lien">Lien</option>
                                    <option value="easement">Easement</option>
                                    <option value="caveat">Caveat</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Start Date</label>
                                <input type="date" class="form-control" name="start_date" id="editStartDate" required>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">End Date</label>
                                <input type="date" class="form-control" name="end_date" id="editEndDate">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Involved Party</label>
                                <select class="form-select" name="involved_party_id" id="editPartyId">
                                    <option value="">None</option>
                                    <?php foreach ($parties as $party): ?>
                                    <option value="<?php echo $party['id']; ?>">
                                        <?php echo htmlspecialchars($party['name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Reference Number</label>
                                <input type="text" class="form-control" name="reference_number" id="editReference">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Amount</label>
                                <input type="number" class="form-control" name="amount" id="editAmount" step="0.01">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Interest Rate (%)</label>
                                <input type="number" class="form-control" name="interest_rate" id="editInterestRate" step="0.01">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Lender Name</label>
                                <input type="text" class="form-control" name="lender_name" id="editLender">
                            </div>
                            
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Description</label>
                                <textarea class="form-control" name="description" rows="3" id="editDescription"></textarea>
                            </div>
                            
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Terms & Conditions</label>
                                <textarea class="form-control" name="terms_conditions" rows="3" id="editTerms"></textarea>
                            </div>
                            
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Document Reference</label>
                                <input type="text" class="form-control" name="document_reference" id="editDocumentRef">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Encumbrance</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Encumbrance Modal -->
    <div class="modal fade" id="viewEncumbranceModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Encumbrance Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6 class="border-bottom pb-2">Basic Information</h6>
                            <table class="table table-borderless">
                                <tr>
                                    <th width="40%">Type:</th>
                                    <td id="viewType"></td>
                                </tr>
                                <tr>
                                    <th>Reference #:</th>
                                    <td id="viewReference"></td>
                                </tr>
                                <tr>
                                    <th>Start Date:</th>
                                    <td id="viewStartDate"></td>
                                </tr>
                                <tr>
                                    <th>End Date:</th>
                                    <td id="viewEndDate"></td>
                                </tr>
                                <tr>
                                    <th>Status:</th>
                                    <td id="viewStatus"></td>
                                </tr>
                            </table>
                        </div>
                        
                        <div class="col-md-6">
                            <h6 class="border-bottom pb-2">Financial Information</h6>
                            <table class="table table-borderless">
                                <tr>
                                    <th width="40%">Amount:</th>
                                    <td id="viewAmount"></td>
                                </tr>
                                <tr>
                                    <th>Interest Rate:</th>
                                    <td id="viewInterest"></td>
                                </tr>
                                <tr>
                                    <th>Lender:</th>
                                    <td id="viewLender"></td>
                                </tr>
                                <tr>
                                    <th>Document Ref:</th>
                                    <td id="viewDocumentRef"></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    
                    <div class="row mt-3">
                        <div class="col-md-6">
                            <h6 class="border-bottom pb-2">Parcel Information</h6>
                            <table class="table table-borderless">
                                <tr>
                                    <th width="40%">Parcel #:</th>
                                    <td id="viewParcelNumber"></td>
                                </tr>
                                <tr>
                                    <th>Area:</th>
                                    <td id="viewParcelArea"></td>
                                </tr>
                                <tr>
                                    <th>Location:</th>
                                    <td id="viewLocation"></td>
                                </tr>
                            </table>
                        </div>
                        
                        <div class="col-md-6">
                            <h6 class="border-bottom pb-2">Involved Party</h6>
                            <table class="table table-borderless">
                                <tr>
                                    <th width="40%">Name:</th>
                                    <td id="viewPartyName"></td>
                                </tr>
                                <tr>
                                    <th>Type:</th>
                                    <td id="viewPartyType"></td>
                                </tr>
                                <tr>
                                    <th>ID/Reg:</th>
                                    <td id="viewPartyId"></td>
                                </tr>
                                <tr>
                                    <th>Contact:</th>
                                    <td id="viewPartyContact"></td>
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
                            <h6 class="border-bottom pb-2">Terms & Conditions</h6>
                            <p id="viewTerms" class="text-muted"></p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Release Encumbrance Modal -->
    <div class="modal fade" id="releaseEncumbranceModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="release">
                    <input type="hidden" name="encumbrance_id" id="releaseEncumbranceId">
                    <div class="modal-header">
                        <h5 class="modal-title">Release Encumbrance</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p>Are you sure you want to release this encumbrance?</p>
                        <p><strong id="releaseEncumbranceInfo"></strong></p>
                        
                        <div class="mb-3">
                            <label class="form-label">Release Date</label>
                            <input type="date" class="form-control" name="release_date" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Release Notes</label>
                            <textarea class="form-control" name="release_notes" rows="3" placeholder="Reason for release..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning">Release Encumbrance</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Encumbrance Modal -->
    <div class="modal fade" id="deleteEncumbranceModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="encumbrance_id" id="deleteEncumbranceId">
                    <div class="modal-header">
                        <h5 class="modal-title">Delete Encumbrance</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p>Are you sure you want to delete this encumbrance?</p>
                        <p><strong id="deleteEncumbranceInfo"></strong></p>
                        <p class="text-danger"><i class="bi bi-exclamation-triangle"></i> This action cannot be undone.</p>
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
            // Type Chart
            const typeCtx = document.getElementById('typeChart')?.getContext('2d');
            if (typeCtx) {
                const typeLabels = <?php echo json_encode($type_labels); ?>;
                const typeData = <?php echo json_encode($type_counts); ?>;
                
                new Chart(typeCtx, {
                    type: 'doughnut',
                    data: {
                        labels: typeLabels,
                        datasets: [{
                            data: typeData,
                            backgroundColor: ['#0d6efd', '#dc3545', '#198754', '#ffc107', '#6c757d'],
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom'
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        const label = context.label || '';
                                        const value = context.parsed || 0;
                                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                        const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                        return label + ': ' + value + ' (' + percentage + '%)';
                                    }
                                }
                            }
                        }
                    }
                });
            }
            
            // Monthly Chart
            const monthlyCtx = document.getElementById('monthlyChart')?.getContext('2d');
            if (monthlyCtx) {
                const monthlyLabels = <?php echo json_encode($monthly_labels); ?>;
                const monthlyData = <?php echo json_encode($monthly_counts); ?>;
                
                new Chart(monthlyCtx, {
                    type: 'bar',
                    data: {
                        labels: monthlyLabels,
                        datasets: [{
                            label: 'Number of Encumbrances',
                            data: monthlyData,
                            backgroundColor: '#ffc107',
                            borderColor: '#ffca2c',
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
                                    stepSize: 1,
                                    precision: 0
                                }
                            }
                        }
                    }
                });
            }
        });
        
        // View encumbrance
        function viewEncumbrance(enc) {
            document.getElementById('viewType').innerHTML = `<span class="badge bg-${enc.encumbrance_type == 'mortgage' ? 'primary' : (enc.encumbrance_type == 'lien' ? 'danger' : (enc.encumbrance_type == 'easement' ? 'success' : (enc.encumbrance_type == 'caveat' ? 'warning' : 'secondary')))}">${ucfirst(enc.encumbrance_type)}</span>`;
            document.getElementById('viewReference').textContent = enc.reference_number || 'N/A';
            document.getElementById('viewStartDate').textContent = formatDate(enc.start_date);
            document.getElementById('viewEndDate').textContent = enc.end_date ? formatDate(enc.end_date) : 'No end date';
            
            let statusClass = enc.status_label == 'Active' ? 'success' : (enc.status_label == 'Expiring Soon' ? 'warning' : (enc.status_label == 'Expired' ? 'secondary' : 'info'));
            document.getElementById('viewStatus').innerHTML = `<span class="badge bg-${statusClass}">${enc.status_label}</span>`;
            
            document.getElementById('viewAmount').textContent = enc.amount ? number_format(enc.amount, 2) + ' SSP' : 'N/A';
            document.getElementById('viewInterest').textContent = enc.interest_rate ? enc.interest_rate + '%' : 'N/A';
            document.getElementById('viewLender').textContent = enc.lender_name || 'N/A';
            document.getElementById('viewDocumentRef').textContent = enc.document_reference || 'N/A';
            
            document.getElementById('viewParcelNumber').textContent = enc.parcel_number || 'N/A';
            document.getElementById('viewParcelArea').textContent = enc.parcel_area ? number_format(enc.parcel_area, 2) + ' m²' : 'N/A';
            document.getElementById('viewLocation').textContent = enc.boma_name + ', ' + enc.payam_name + ', ' + enc.county_name;
            
            document.getElementById('viewPartyName').textContent = enc.party_name || 'None';
            document.getElementById('viewPartyType').textContent = enc.party_type ? ucfirst(enc.party_type) : 'N/A';
            document.getElementById('viewPartyId').textContent = enc.national_id || enc.reg_number || 'N/A';
            document.getElementById('viewPartyContact').textContent = (enc.party_phone || enc.party_email) || 'N/A';
            
            document.getElementById('viewDescription').textContent = enc.description || 'No description provided';
            document.getElementById('viewTerms').textContent = enc.terms_conditions || 'No terms specified';
        }
        
        // Edit encumbrance
        function editEncumbrance(enc) {
            document.getElementById('editEncumbranceId').value = enc.id;
            document.getElementById('editType').value = enc.encumbrance_type || 'other';
            document.getElementById('editStartDate').value = enc.start_date || '';
            document.getElementById('editEndDate').value = enc.end_date || '';
            document.getElementById('editPartyId').value = enc.involved_party_id || '';
            document.getElementById('editReference').value = enc.reference_number || '';
            document.getElementById('editAmount').value = enc.amount || '';
            document.getElementById('editInterestRate').value = enc.interest_rate || '';
            document.getElementById('editLender').value = enc.lender_name || '';
            document.getElementById('editDescription').value = enc.description || '';
            document.getElementById('editTerms').value = enc.terms_conditions || '';
            document.getElementById('editDocumentRef').value = enc.document_reference || '';
        }
        
        // Release encumbrance
        function releaseEncumbrance(enc) {
            document.getElementById('releaseEncumbranceId').value = enc.id;
            document.getElementById('releaseEncumbranceInfo').textContent = ucfirst(enc.encumbrance_type) + ' on ' + enc.parcel_number;
        }
        
        // Delete encumbrance
        function deleteEncumbrance(id, info) {
            document.getElementById('deleteEncumbranceId').value = id;
            document.getElementById('deleteEncumbranceInfo').textContent = info;
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
        
        // Capitalize first letter
        function ucfirst(str) {
            return str.charAt(0).toUpperCase() + str.slice(1);
        }
        
        // Search functionality
        document.getElementById('tableSearch')?.addEventListener('keyup', function() {
            const searchText = this.value.toLowerCase();
            const table = document.getElementById('encumbrancesTable');
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
    </script>

    <style>
        .table-warning {
            background-color: #fff3cd !important;
        }
        .table-secondary {
            background-color: #e9ecef !important;
        }
        .table-danger {
            background-color: #f8d7da !important;
        }
        .table-info {
            background-color: #d1ecf1 !important;
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