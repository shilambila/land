<?php
ini_set('display_errors', 1); 
error_reporting(E_ALL);

// ownerships.php
include("head.php");
require_once 'db_connection.php';

// ============================================================================
// HANDLE ACTIONS
// ============================================================================

$message = '';
$messageType = '';

// Add ownership
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    if ($_POST['action'] === 'add') {
        $title_id = $_POST['title_id'];
        $party_id = $_POST['party_id'];
        $share_percentage = $_POST['share_percentage'] ?? 100;
        $ownership_start_date = $_POST['ownership_start_date'];
        $consideration_amount = !empty($_POST['consideration_amount']) ? $_POST['consideration_amount'] : null;
        $transaction_type = $_POST['transaction_type'] ?? 'transfer';
        $notes = $_POST['notes'] ?? null;
        
        try {
            $conn->beginTransaction();
            
            // End current ownership
            executeQuery($conn, "
                UPDATE ownerships 
                SET ownership_end_date = ? 
                WHERE title_id = ? AND is_current = 1
            ", [$ownership_start_date, $title_id]);
            
            // Add new ownership
            executeQuery($conn, "
                INSERT INTO ownerships (
                    title_id, party_id, share_percentage, 
                    ownership_start_date, created_at
                ) VALUES (?, ?, ?, ?, NOW())
            ", [$title_id, $party_id, $share_percentage, $ownership_start_date]);
            
            $newOwnershipId = $conn->lastInsertId();
            
            // Record transaction if consideration exists
            if ($consideration_amount || $transaction_type) {
                // Get parcel_id from title
                $title = fetchOne($conn, "SELECT parcel_id FROM titles WHERE id = ?", [$title_id]);
                
                executeQuery($conn, "
                    INSERT INTO transactions (
                        transaction_type, parcel_id, title_id, 
                        from_party_id, to_party_id, transaction_date,
                        consideration_amount, details, created_at, created_by
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
                ", [
                    $transaction_type, 
                    $title['parcel_id'], 
                    $title_id,
                    $_POST['from_party_id'] ?? null, 
                    $party_id,
                    $ownership_start_date,
                    $consideration_amount,
                    $notes,
                    $_SESSION['user_id'] ?? 1
                ]);
            }
            
            $conn->commit();
            
            $message = "Ownership added successfully";
            $messageType = "success";
        } catch (Exception $e) {
            $conn->rollBack();
            $message = "Error adding ownership: " . $e->getMessage();
            $messageType = "danger";
        }
    }
    
    // Edit ownership
    if ($_POST['action'] === 'edit') {
        $ownership_id = $_POST['ownership_id'];
        $share_percentage = $_POST['share_percentage'];
        $notes = $_POST['notes'] ?? null;
        
        try {
            executeQuery($conn, "
                UPDATE ownerships 
                SET share_percentage = ?
                WHERE id = ?
            ", [$share_percentage, $ownership_id]);
            
            $message = "Ownership updated successfully";
            $messageType = "success";
        } catch (Exception $e) {
            $message = "Error updating ownership: " . $e->getMessage();
            $messageType = "danger";
        }
    }
    
    // End ownership
    if ($_POST['action'] === 'end') {
        $ownership_id = $_POST['ownership_id'];
        $end_date = $_POST['end_date'];
        $reason = $_POST['reason'] ?? null;
        
        try {
            executeQuery($conn, "
                UPDATE ownerships 
                SET ownership_end_date = ? 
                WHERE id = ?
            ", [$end_date, $ownership_id]);
            
            $message = "Ownership ended successfully";
            $messageType = "success";
        } catch (Exception $e) {
            $message = "Error ending ownership: " . $e->getMessage();
            $messageType = "danger";
        }
    }
    
    // Transfer ownership (complete transfer)
    if ($_POST['action'] === 'transfer') {
        $title_id = $_POST['title_id'];
        $from_ownership_id = $_POST['from_ownership_id'];
        $to_party_id = $_POST['to_party_id'];
        $transfer_date = $_POST['transfer_date'];
        $consideration_amount = !empty($_POST['consideration_amount']) ? $_POST['consideration_amount'] : null;
        $transaction_type = $_POST['transaction_type'] ?? 'transfer';
        $notes = $_POST['notes'] ?? null;
        $share_percentage = $_POST['share_percentage'] ?? 100;
        
        try {
            $conn->beginTransaction();
            
            // Get title and parcel info
            $title = fetchOne($conn, "SELECT * FROM titles WHERE id = ?", [$title_id]);
            if (!$title) {
                throw new Exception("Title not found");
            }
            
            // Get from party
            $from_ownership = fetchOne($conn, "
                SELECT party_id FROM ownerships WHERE id = ?
            ", [$from_ownership_id]);
            
            // End current ownership
            executeQuery($conn, "
                UPDATE ownerships 
                SET ownership_end_date = ? 
                WHERE id = ?
            ", [$transfer_date, $from_ownership_id]);
            
            // Add new ownership
            executeQuery($conn, "
                INSERT INTO ownerships (
                    title_id, party_id, share_percentage, 
                    ownership_start_date, created_at
                ) VALUES (?, ?, ?, ?, NOW())
            ", [$title_id, $to_party_id, $share_percentage, $transfer_date]);
            
            // Record transaction
            executeQuery($conn, "
                INSERT INTO transactions (
                    transaction_type, parcel_id, title_id, 
                    from_party_id, to_party_id, transaction_date,
                    consideration_amount, details, created_at, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
            ", [
                $transaction_type,
                $title['parcel_id'],
                $title_id,
                $from_ownership['party_id'],
                $to_party_id,
                $transfer_date,
                $consideration_amount,
                $notes,
                $_SESSION['user_id'] ?? 1
            ]);
            
            $conn->commit();
            
            $message = "Ownership transferred successfully";
            $messageType = "success";
        } catch (Exception $e) {
            $conn->rollBack();
            $message = "Error transferring ownership: " . $e->getMessage();
            $messageType = "danger";
        }
    }
    
    // Delete ownership
    if ($_POST['action'] === 'delete') {
        $ownership_id = $_POST['ownership_id'];
        
        try {
            // Check if this is the only ownership
            $title_id = fetchOne($conn, "SELECT title_id FROM ownerships WHERE id = ?", [$ownership_id]);
            $count = fetchOne($conn, "
                SELECT COUNT(*) as count FROM ownerships 
                WHERE title_id = ? AND id != ?
            ", [$title_id['title_id'], $ownership_id]);
            
            if ($count['count'] == 0) {
                throw new Exception("Cannot delete the only ownership record. End it instead.");
            }
            
            executeQuery($conn, "DELETE FROM ownerships WHERE id = ?", [$ownership_id]);
            
            $message = "Ownership deleted successfully";
            $messageType = "success";
        } catch (Exception $e) {
            $message = "Error deleting ownership: " . $e->getMessage();
            $messageType = "danger";
        }
    }
}

// ============================================================================
// GET FILTERS AND DATA
// ============================================================================

// Get all titles for filter
$titles = fetchAll($conn, "
    SELECT t.id, t.title_number, p.parcel_number 
    FROM titles t
    JOIN parcels p ON t.parcel_id = p.id
    ORDER BY t.title_number
");

// Get all parties for filter
$parties = fetchAll($conn, "
    SELECT id, name, party_type, national_id, registration_number 
    FROM parties 
    ORDER BY name
");

// Get states for filter
$states = fetchAll($conn, "SELECT id, name FROM states ORDER BY name");

// Build filter query
$whereClause = " WHERE 1=1";
$params = [];

if (isset($_GET['title_id']) && !empty($_GET['title_id'])) {
    $whereClause .= " AND o.title_id = ?";
    $params[] = $_GET['title_id'];
}

if (isset($_GET['party_id']) && !empty($_GET['party_id'])) {
    $whereClause .= " AND o.party_id = ?";
    $params[] = $_GET['party_id'];
}

if (isset($_GET['state_id']) && !empty($_GET['state_id'])) {
    $whereClause .= " AND s.id = ?";
    $params[] = $_GET['state_id'];
}

if (isset($_GET['status']) && !empty($_GET['status'])) {
    if ($_GET['status'] === 'current') {
        $whereClause .= " AND o.is_current = 1";
    } elseif ($_GET['status'] === 'historical') {
        $whereClause .= " AND o.is_current = 0";
    }
}

if (isset($_GET['date_from']) && !empty($_GET['date_from'])) {
    $whereClause .= " AND o.ownership_start_date >= ?";
    $params[] = $_GET['date_from'];
}

if (isset($_GET['date_to']) && !empty($_GET['date_to'])) {
    $whereClause .= " AND o.ownership_start_date <= ?";
    $params[] = $_GET['date_to'];
}

if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search = '%' . $_GET['search'] . '%';
    $whereClause .= " AND (t.title_number LIKE ? OR p.parcel_number LIKE ? OR pa.name LIKE ? OR pa.national_id LIKE ?)";
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
}

// Get ownership records with full details
$ownerships = fetchAll($conn, "
    SELECT 
        o.*,
        t.id as title_id,
        t.title_number,
        t.title_type,
        t.status as title_status,
        p.id as parcel_id,
        p.parcel_number,
        p.area as parcel_area,
        b.id as boma_id,
        b.name as boma_name,
        pa.id as payam_id,
        pa.name as payam_name,
        c.id as county_id,
        c.name as county_name,
        s.id as state_id,
        s.name as state_name,
        -- Party/Owner details
        pa2.id as party_id,
        pa2.name as owner_name,
        pa2.party_type as owner_type,
        pa2.national_id,
        pa2.registration_number as org_reg_number,
        pa2.phone as owner_phone,
        pa2.email as owner_email,
        pa2.physical_address as owner_address,
        -- Previous/Next ownership
        prev_o.id as prev_ownership_id,
        prev_o.party_id as prev_party_id,
        prev_pa.name as prev_owner_name,
        prev_o.ownership_end_date as prev_end_date,
        next_o.id as next_ownership_id,
        next_o.party_id as next_party_id,
        next_pa.name as next_owner_name,
        next_o.ownership_start_date as next_start_date,
        -- Duration
        DATEDIFF(COALESCE(o.ownership_end_date, CURDATE()), o.ownership_start_date) as ownership_days
    FROM ownerships o
    JOIN titles t ON o.title_id = t.id
    JOIN parcels p ON t.parcel_id = p.id
    JOIN bomas b ON p.boma_id = b.id
    JOIN payams pa ON b.payam_id = pa.id
    JOIN counties c ON pa.county_id = c.id
    JOIN states s ON c.state_id = s.id
    JOIN parties pa2 ON o.party_id = pa2.id
    LEFT JOIN ownerships prev_o ON o.title_id = prev_o.title_id 
        AND prev_o.ownership_end_date = o.ownership_start_date
    LEFT JOIN parties prev_pa ON prev_o.party_id = prev_pa.id
    LEFT JOIN ownerships next_o ON o.title_id = next_o.title_id 
        AND next_o.ownership_start_date = o.ownership_end_date
    LEFT JOIN parties next_pa ON next_o.party_id = next_pa.id
    $whereClause
    ORDER BY o.ownership_start_date DESC, o.created_at DESC
", $params);

// Get summary statistics
$summary = fetchOne($conn, "
    SELECT 
        COUNT(*) as total_ownerships,
        COUNT(DISTINCT title_id) as titles_with_ownership,
        COUNT(DISTINCT party_id) as unique_owners,
        SUM(CASE WHEN is_current = 1 THEN 1 ELSE 0 END) as current_ownerships,
        SUM(CASE WHEN is_current = 0 THEN 1 ELSE 0 END) as historical_ownerships,
        AVG(share_percentage) as avg_share,
        MIN(ownership_start_date) as earliest_ownership,
        MAX(ownership_start_date) as latest_ownership,
        (SELECT COUNT(*) FROM parties) as total_parties,
        (SELECT COUNT(*) FROM titles) as total_titles
    FROM ownerships
");

// Get ownership statistics by type
$by_title_type = fetchAll($conn, "
    SELECT 
        t.title_type,
        COUNT(*) as count,
        COUNT(DISTINCT o.party_id) as unique_owners
    FROM ownerships o
    JOIN titles t ON o.title_id = t.id
    GROUP BY t.title_type
");

// Get ownership statistics by state
$by_state = fetchAll($conn, "
    SELECT 
        s.name as state_name,
        COUNT(*) as count,
        COUNT(DISTINCT o.party_id) as unique_owners
    FROM ownerships o
    JOIN titles t ON o.title_id = t.id
    JOIN parcels p ON t.parcel_id = p.id
    JOIN bomas b ON p.boma_id = b.id
    JOIN payams pa ON b.payam_id = pa.id
    JOIN counties c ON pa.county_id = c.id
    JOIN states s ON c.state_id = s.id
    GROUP BY s.id, s.name
    ORDER BY count DESC
");

// Get top owners by number of titles
$top_owners = fetchAll($conn, "
    SELECT 
        pa.id,
        pa.name,
        pa.party_type,
        COUNT(DISTINCT o.title_id) as titles_owned,
        SUM(CASE WHEN o.is_current = 1 THEN 1 ELSE 0 END) as current_titles,
        SUM(o.share_percentage) as total_share_percentage,
        AVG(o.share_percentage) as avg_share_percentage
    FROM ownerships o
    JOIN parties pa ON o.party_id = pa.id
    GROUP BY pa.id, pa.name, pa.party_type
    ORDER BY titles_owned DESC
    LIMIT 10
");

// Get recent transfers
$recent_transfers = fetchAll($conn, "
    SELECT 
        t.id as transaction_id,
        t.transaction_date,
        t.transaction_type,
        t.consideration_amount,
        ti.title_number,
        p.parcel_number,
        from_party.name as from_owner,
        to_party.name as to_owner
    FROM transactions t
    JOIN titles ti ON t.title_id = ti.id
    JOIN parcels p ON t.parcel_id = p.id
    LEFT JOIN parties from_party ON t.from_party_id = from_party.id
    LEFT JOIN parties to_party ON t.to_party_id = to_party.id
    ORDER BY t.transaction_date DESC
    LIMIT 15
");

// Get current ownerships for dropdown
$current_ownerships = fetchAll($conn, "
    SELECT 
        o.id,
        o.share_percentage,
        t.title_number,
        p.parcel_number,
        pa.name as owner_name,
        pa.party_type
    FROM ownerships o
    JOIN titles t ON o.title_id = t.id
    JOIN parcels p ON t.parcel_id = p.id
    JOIN parties pa ON o.party_id = pa.id
    WHERE o.is_current = 1
    ORDER BY t.title_number
");
?>

<body data-page="ownerships" class="ownerships-page">
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
                                <i class="bi bi-people text-primary me-2"></i>
                                Ownership Management
                            </h1>
                            <p class="text-muted mb-0">Track ownership history, transfers, and current owners</p>
                        </div>
                        <div>
                            <button class="btn btn-outline-secondary me-2" type="button" data-bs-toggle="collapse" data-bs-target="#filterCollapse">
                                <i class="bi bi-funnel me-2"></i>Filters
                            </button>
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addOwnershipModal">
                                <i class="bi bi-plus-circle me-2"></i>Add Ownership
                            </button>
                        </div>
                    </div>

                    <!-- Filter Collapse -->
                    <div class="collapse mb-4" id="filterCollapse">
                        <div class="card border-0 shadow-sm">
                            <div class="card-body">
                                <form method="GET" class="row g-3">
                                    <div class="col-md-3">
                                        <label class="form-label">Title</label>
                                        <select class="form-select" name="title_id">
                                            <option value="">All Titles</option>
                                            <?php foreach ($titles as $title): ?>
                                            <option value="<?php echo $title['id']; ?>" <?php echo (isset($_GET['title_id']) && $_GET['title_id'] == $title['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($title['title_number'] . ' - ' . $title['parcel_number']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Owner</label>
                                        <select class="form-select" name="party_id">
                                            <option value="">All Owners</option>
                                            <?php foreach ($parties as $party): ?>
                                            <option value="<?php echo $party['id']; ?>" <?php echo (isset($_GET['party_id']) && $_GET['party_id'] == $party['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($party['name'] . ' (' . ucfirst($party['party_type']) . ')'); ?>
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
                                        <label class="form-label">Status</label>
                                        <select class="form-select" name="status">
                                            <option value="">All</option>
                                            <option value="current" <?php echo (isset($_GET['status']) && $_GET['status'] == 'current') ? 'selected' : ''; ?>>Current Owners</option>
                                            <option value="historical" <?php echo (isset($_GET['status']) && $_GET['status'] == 'historical') ? 'selected' : ''; ?>>Historical</option>
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
                                    <div class="col-md-6">
                                        <label class="form-label">Search</label>
                                        <div class="input-group">
                                            <input type="text" class="form-control" name="search" placeholder="Title #, Parcel #, Owner name, ID..." 
                                                   value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
                                            <button class="btn btn-primary" type="submit">
                                                <i class="bi bi-search"></i>
                                            </button>
                                            <?php if (!empty($_GET)): ?>
                                            <a href="ownerships.php" class="btn btn-outline-secondary">
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
                        <div class="col-xl-3 col-md-6">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0 me-3">
                                            <div class="bg-primary bg-opacity-10 rounded-circle p-3">
                                                <i class="bi bi-file-text text-primary fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Total Records</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['total_ownerships'] ?? 0); ?></h3>
                                            <small class="text-muted">Titles: <?php echo number_format($summary['titles_with_ownership'] ?? 0); ?></small>
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
                                                <i class="bi bi-person-check text-success fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Current Owners</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['current_ownerships'] ?? 0); ?></h3>
                                            <small class="text-muted">Unique: <?php echo number_format($summary['unique_owners'] ?? 0); ?></small>
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
                                                <i class="bi bi-clock-history text-info fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Historical</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['historical_ownerships'] ?? 0); ?></h3>
                                            <small class="text-muted">Past owners</small>
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
                                            <h6 class="text-muted mb-1">Avg Share</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['avg_share'] ?? 0, 1); ?>%</h3>
                                            <small class="text-muted">Per ownership</small>
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
                                        <i class="bi bi-pie-chart text-primary me-2"></i>Ownership by Title Type
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
                                        <i class="bi bi-bar-chart text-success me-2"></i>Ownership by State
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <canvas id="stateChart" style="height: 300px;"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Top Owners -->
                    <div class="row g-4 mb-5">
                        <div class="col-xl-6">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h5 class="card-title mb-0 fw-bold">
                                        <i class="bi bi-trophy text-warning me-2"></i>Top Owners by Number of Titles
                                    </h5>
                                </div>
                                <div class="card-body p-0">
                                    <div class="table-responsive">
                                        <table class="table table-hover mb-0">
                                            <thead class="bg-light">
                                                <tr>
                                                    <th>Owner</th>
                                                    <th>Type</th>
                                                    <th>Total Titles</th>
                                                    <th>Current</th>
                                                    <th>Avg Share</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($top_owners as $owner): ?>
                                                <tr>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($owner['name']); ?></strong>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-<?php echo $owner['party_type'] == 'individual' ? 'info' : 'secondary'; ?>">
                                                            <?php echo ucfirst($owner['party_type']); ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo $owner['titles_owned']; ?></td>
                                                    <td><?php echo $owner['current_titles']; ?></td>
                                                    <td><?php echo number_format($owner['avg_share_percentage'], 1); ?>%</td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-xl-6">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h5 class="card-title mb-0 fw-bold">
                                        <i class="bi bi-arrow-left-right text-info me-2"></i>Recent Transfers
                                    </h5>
                                </div>
                                <div class="card-body p-0">
                                    <div class="table-responsive">
                                        <table class="table table-hover mb-0">
                                            <thead class="bg-light">
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Title</th>
                                                    <th>From</th>
                                                    <th>To</th>
                                                    <th>Amount</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($recent_transfers as $transfer): ?>
                                                <tr>
                                                    <td><?php echo date('d M Y', strtotime($transfer['transaction_date'])); ?></td>
                                                    <td>
                                                        <small><?php echo htmlspecialchars($transfer['title_number']); ?></small>
                                                        <br><small class="text-muted"><?php echo htmlspecialchars($transfer['parcel_number']); ?></small>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($transfer['from_owner'] ?? 'N/A'); ?></td>
                                                    <td><?php echo htmlspecialchars($transfer['to_owner']); ?></td>
                                                    <td>
                                                        <?php if ($transfer['consideration_amount']): ?>
                                                        <?php echo number_format($transfer['consideration_amount'], 2); ?> SSP
                                                        <?php else: ?>
                                                        <span class="text-muted">N/A</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Ownership Timeline View -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0 fw-bold">
                                <i class="bi bi-clock-history me-2"></i>Ownership Records
                                <span class="badge bg-secondary ms-2"><?php echo count($ownerships); ?> records</span>
                            </h5>
                            <div>
                                <input type="text" class="form-control form-control-sm" style="width: 250px;" id="tableSearch" placeholder="Search in table...">
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0" id="ownershipTable">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>Title #</th>
                                            <th>Parcel #</th>
                                            <th>Location</th>
                                            <th>Owner</th>
                                            <th>Share</th>
                                            <th>Start Date</th>
                                            <th>End Date</th>
                                            <th>Duration</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($ownerships)): ?>
                                        <tr>
                                            <td colspan="10" class="text-center py-4 text-muted">
                                                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                                No ownership records found
                                            </td>
                                        </tr>
                                        <?php else: ?>
                                            <?php foreach ($ownerships as $ownership): ?>
                                            <tr class="<?php echo $ownership['is_current'] ? 'table-success' : 'table-light'; ?>">
                                                <td>
                                                    <strong><?php echo htmlspecialchars($ownership['title_number']); ?></strong>
                                                    <br><small class="text-muted"><?php echo ucfirst($ownership['title_type']); ?></small>
                                                </td>
                                                <td><?php echo htmlspecialchars($ownership['parcel_number']); ?></td>
                                                <td>
                                                    <?php echo htmlspecialchars($ownership['boma_name']); ?>, <?php echo htmlspecialchars($ownership['payam_name']); ?>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars($ownership['county_name']); ?></small>
                                                </td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($ownership['owner_name']); ?></strong>
                                                    <br>
                                                    <small class="text-muted">
                                                        <?php echo ucfirst($ownership['owner_type']); ?>
                                                        <?php if ($ownership['national_id']): ?>
                                                        <br>ID: <?php echo htmlspecialchars($ownership['national_id']); ?>
                                                        <?php endif; ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <span class="badge bg-info"><?php echo $ownership['share_percentage']; ?>%</span>
                                                </td>
                                                <td><?php echo date('d M Y', strtotime($ownership['ownership_start_date'])); ?></td>
                                                <td>
                                                    <?php if ($ownership['ownership_end_date']): ?>
                                                        <?php echo date('d M Y', strtotime($ownership['ownership_end_date'])); ?>
                                                    <?php else: ?>
                                                        <span class="badge bg-success">Current</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($ownership['ownership_days']): ?>
                                                        <?php 
                                                        $days = $ownership['ownership_days'];
                                                        $years = floor($days / 365);
                                                        $months = floor(($days % 365) / 30);
                                                        $days_remain = $days % 30;
                                                        
                                                        $duration = [];
                                                        if ($years > 0) $duration[] = $years . 'y';
                                                        if ($months > 0) $duration[] = $months . 'm';
                                                        if ($days_remain > 0 && $years == 0) $duration[] = $days_remain . 'd';
                                                        
                                                        echo implode(' ', $duration);
                                                        ?>
                                                    <?php else: ?>
                                                        N/A
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($ownership['is_current']): ?>
                                                        <span class="badge bg-success">Current</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">Historical</span>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($ownership['prev_owner_name']): ?>
                                                    <br><small class="text-muted" title="Previous: <?php echo htmlspecialchars($ownership['prev_owner_name']); ?>">
                                                        ⬅ <?php echo htmlspecialchars(substr($ownership['prev_owner_name'], 0, 15)); ?>...
                                                    </small>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($ownership['next_owner_name']): ?>
                                                    <br><small class="text-muted" title="Next: <?php echo htmlspecialchars($ownership['next_owner_name']); ?>">
                                                        ➡ <?php echo htmlspecialchars(substr($ownership['next_owner_name'], 0, 15)); ?>...
                                                    </small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="btn-group">
                                                        <button class="btn btn-sm btn-outline-primary" 
                                                                onclick="viewOwnership(<?php echo htmlspecialchars(json_encode($ownership)); ?>)"
                                                                data-bs-toggle="modal" data-bs-target="#viewOwnershipModal">
                                                            <i class="bi bi-eye"></i>
                                                        </button>
                                                        
                                                        <?php if ($ownership['is_current']): ?>
                                                        <button class="btn btn-sm btn-outline-success" 
                                                                onclick="editOwnership(<?php echo htmlspecialchars(json_encode($ownership)); ?>)"
                                                                data-bs-toggle="modal" data-bs-target="#editOwnershipModal">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                        
                                                        <button class="btn btn-sm btn-outline-warning" 
                                                                onclick="endOwnership(<?php echo htmlspecialchars(json_encode($ownership)); ?>)"
                                                                data-bs-toggle="modal" data-bs-target="#endOwnershipModal">
                                                            <i class="bi bi-x-circle"></i>
                                                        </button>
                                                        
                                                        <button class="btn btn-sm btn-outline-info" 
                                                                onclick="transferOwnership(<?php echo htmlspecialchars(json_encode($ownership)); ?>)"
                                                                data-bs-toggle="modal" data-bs-target="#transferOwnershipModal">
                                                            <i class="bi bi-arrow-left-right"></i>
                                                        </button>
                                                        <?php endif; ?>
                                                        
                                                        <?php if (!$ownership['is_current'] && !$ownership['next_owner_name']): ?>
                                                        <button class="btn btn-sm btn-outline-danger" 
                                                                onclick="deleteOwnership(<?php echo $ownership['id']; ?>, '<?php echo htmlspecialchars(addslashes($ownership['owner_name'])); ?>')"
                                                                data-bs-toggle="modal" data-bs-target="#deleteOwnershipModal">
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

                    <!-- Timeline Visualization (if only one title selected) -->
                    <?php if (isset($_GET['title_id']) && !empty($_GET['title_id']) && count($ownerships) > 1): ?>
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white py-3">
                            <h5 class="card-title mb-0 fw-bold">
                                <i class="bi bi-bar-chart-steps me-2"></i>Ownership Timeline
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="timeline">
                                <?php 
                                $first_date = strtotime($ownerships[array_key_last($ownerships)]['ownership_start_date']);
                                $last_date = time();
                                $total_days = ($last_date - $first_date) / (60 * 60 * 24);
                                
                                foreach (array_reverse($ownerships) as $index => $ownership): 
                                    $start = strtotime($ownership['ownership_start_date']);
                                    $end = $ownership['ownership_end_date'] ? strtotime($ownership['ownership_end_date']) : $last_date;
                                    $duration = ($end - $start) / (60 * 60 * 24);
                                    $width = ($duration / $total_days) * 100;
                                ?>
                                <div class="timeline-item mb-3">
                                    <div class="d-flex justify-content-between mb-1">
                                        <span class="fw-bold"><?php echo htmlspecialchars($ownership['owner_name']); ?></span>
                                        <span class="text-muted small">
                                            <?php echo date('d M Y', $start); ?> - 
                                            <?php echo $ownership['ownership_end_date'] ? date('d M Y', $end) : 'Present'; ?>
                                        </span>
                                    </div>
                                    <div class="progress" style="height: 30px;">
                                        <div class="progress-bar bg-<?php 
                                            echo $index == 0 ? 'success' : ($index % 2 == 0 ? 'info' : 'warning'); 
                                        ?>" style="width: <?php echo $width; ?>%;">
                                            <?php echo round($duration); ?> days
                                        </div>
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

    <!-- Add Ownership Modal -->
    <div class="modal fade" id="addOwnershipModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="add">
                    <div class="modal-header">
                        <h5 class="modal-title">Add New Ownership</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Title <span class="text-danger">*</span></label>
                                <select class="form-select" name="title_id" required id="add_title_id">
                                    <option value="">Select Title</option>
                                    <?php foreach ($titles as $title): ?>
                                    <option value="<?php echo $title['id']; ?>">
                                        <?php echo htmlspecialchars($title['title_number'] . ' - ' . $title['parcel_number']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Owner <span class="text-danger">*</span></label>
                                <select class="form-select" name="party_id" required>
                                    <option value="">Select Owner</option>
                                    <?php foreach ($parties as $party): ?>
                                    <option value="<?php echo $party['id']; ?>">
                                        <?php echo htmlspecialchars($party['name'] . ' (' . ucfirst($party['party_type']) . ')'); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Share Percentage</label>
                                <input type="number" class="form-control" name="share_percentage" value="100" min="1" max="100" step="0.01">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Start Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="ownership_start_date" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Transaction Type</label>
                                <select class="form-select" name="transaction_type">
                                    <option value="">None</option>
                                    <option value="sale">Sale</option>
                                    <option value="transfer">Transfer</option>
                                    <option value="gift">Gift</option>
                                    <option value="inheritance">Inheritance</option>
                                    <option value="lease">Lease</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Consideration Amount</label>
                                <input type="number" class="form-control" name="consideration_amount" step="0.01" placeholder="0.00">
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Previous Owner (if transfer)</label>
                                <select class="form-select" name="from_party_id">
                                    <option value="">None</option>
                                    <?php foreach ($parties as $party): ?>
                                    <option value="<?php echo $party['id']; ?>">
                                        <?php echo htmlspecialchars($party['name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Notes</label>
                                <textarea class="form-control" name="notes" rows="2" placeholder="Additional notes about this ownership..."></textarea>
                            </div>
                        </div>
                        
                        <div class="alert alert-info" id="currentOwnerAlert" style="display: none;">
                            <i class="bi bi-info-circle me-2"></i>
                            <span id="currentOwnerText"></span>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Ownership</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Ownership Modal -->
    <div class="modal fade" id="editOwnershipModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="ownership_id" id="editOwnershipId">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Ownership</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Title</label>
                            <input type="text" class="form-control" id="editTitleNumber" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Owner</label>
                            <input type="text" class="form-control" id="editOwnerName" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Share Percentage</label>
                            <input type="number" class="form-control" name="share_percentage" id="editSharePercentage" min="1" max="100" step="0.01" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Notes</label>
                            <textarea class="form-control" name="notes" rows="2" id="editNotes"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- End Ownership Modal -->
    <div class="modal fade" id="endOwnershipModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="end">
                    <input type="hidden" name="ownership_id" id="endOwnershipId">
                    <div class="modal-header">
                        <h5 class="modal-title">End Ownership</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p>Are you sure you want to end ownership for <strong id="endOwnerName"></strong>?</p>
                        <p class="text-warning"><i class="bi bi-exclamation-triangle"></i> This will mark the ownership as ended. Make sure to add a new owner if needed.</p>
                        
                        <div class="mb-3">
                            <label class="form-label">End Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="end_date" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Reason (Optional)</label>
                            <textarea class="form-control" name="reason" rows="2" placeholder="Reason for ending ownership..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning">End Ownership</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Transfer Ownership Modal -->
    <div class="modal fade" id="transferOwnershipModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="transfer">
                    <input type="hidden" name="title_id" id="transferTitleId">
                    <input type="hidden" name="from_ownership_id" id="transferFromOwnershipId">
                    <div class="modal-header">
                        <h5 class="modal-title">Transfer Ownership</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Title</label>
                                <input type="text" class="form-control" id="transferTitleNumber" readonly>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Current Owner</label>
                                <input type="text" class="form-control" id="transferCurrentOwner" readonly>
                            </div>
                            <div class="col-md-6 mb-3">
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
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Transfer Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="transfer_date" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Transaction Type</label>
                                <select class="form-select" name="transaction_type">
                                    <option value="transfer">Transfer</option>
                                    <option value="sale">Sale</option>
                                    <option value="gift">Gift</option>
                                    <option value="inheritance">Inheritance</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Consideration Amount</label>
                                <input type="number" class="form-control" name="consideration_amount" step="0.01" placeholder="0.00">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Share Percentage</label>
                                <input type="number" class="form-control" name="share_percentage" value="100" min="1" max="100" step="0.01">
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Notes</label>
                                <textarea class="form-control" name="notes" rows="2" placeholder="Additional notes..."></textarea>
                            </div>
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

    <!-- View Ownership Modal -->
    <div class="modal fade" id="viewOwnershipModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Ownership Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6 class="border-bottom pb-2">Title Information</h6>
                            <table class="table table-borderless">
                                <tr>
                                    <th width="40%">Title Number:</th>
                                    <td id="viewTitleNumber"></td>
                                </tr>
                                <tr>
                                    <th>Title Type:</th>
                                    <td id="viewTitleType"></td>
                                </tr>
                                <tr>
                                    <th>Parcel Number:</th>
                                    <td id="viewParcelNumber"></td>
                                </tr>
                                <tr>
                                    <th>Location:</th>
                                    <td id="viewLocation"></td>
                                </tr>
                            </table>
                        </div>
                        
                        <div class="col-md-6">
                            <h6 class="border-bottom pb-2">Owner Information</h6>
                            <table class="table table-borderless">
                                <tr>
                                    <th width="40%">Name:</th>
                                    <td id="viewOwnerName"></td>
                                </tr>
                                <tr>
                                    <th>Type:</th>
                                    <td id="viewOwnerType"></td>
                                </tr>
                                <tr>
                                    <th>ID/Reg:</th>
                                    <td id="viewOwnerId"></td>
                                </tr>
                                <tr>
                                    <th>Contact:</th>
                                    <td id="viewOwnerContact"></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    
                    <div class="row mt-3">
                        <div class="col-12">
                            <h6 class="border-bottom pb-2">Ownership Period</h6>
                            <table class="table table-borderless">
                                <tr>
                                    <th width="15%">Start Date:</th>
                                    <td id="viewStartDate" width="35%"></td>
                                    <th width="15%">End Date:</th>
                                    <td id="viewEndDate" width="35%"></td>
                                </tr>
                                <tr>
                                    <th>Duration:</th>
                                    <td id="viewDuration"></td>
                                    <th>Share:</th>
                                    <td id="viewShare"></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    
                    <div class="row mt-3">
                        <div class="col-12">
                            <h6 class="border-bottom pb-2">Chain of Ownership</h6>
                            <div class="row">
                                <div class="col-md-4 text-center">
                                    <div class="border rounded p-2">
                                        <small class="text-muted d-block">Previous Owner</small>
                                        <span class="fw-bold" id="viewPrevOwner">None</span>
                                    </div>
                                </div>
                                <div class="col-md-4 text-center">
                                    <div class="border rounded p-2 bg-primary text-white">
                                        <small class="text-white-50 d-block">Current</small>
                                        <span class="fw-bold" id="viewCurrentOwner"></span>
                                    </div>
                                </div>
                                <div class="col-md-4 text-center">
                                    <div class="border rounded p-2">
                                        <small class="text-muted d-block">Next Owner</small>
                                        <span class="fw-bold" id="viewNextOwner">None</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Ownership Modal -->
    <div class="modal fade" id="deleteOwnershipModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="ownership_id" id="deleteOwnershipId">
                    <div class="modal-header">
                        <h5 class="modal-title">Delete Ownership</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p>Are you sure you want to delete ownership record for <strong id="deleteOwnerName"></strong>?</p>
                        <p class="text-danger"><i class="bi bi-exclamation-triangle"></i> This action cannot be undone. This will permanently remove this historical ownership record.</p>
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
            if (typeCtx && <?php echo !empty($by_title_type) ? 'true' : 'false'; ?>) {
                new Chart(typeCtx, {
                    type: 'bar',
                    data: {
                        labels: [
                            <?php foreach ($by_title_type as $item): ?>
                            '<?php echo ucfirst($item['title_type']); ?>',
                            <?php endforeach; ?>
                        ],
                        datasets: [{
                            label: 'Number of Ownerships',
                            data: [
                                <?php foreach ($by_title_type as $item): ?>
                                <?php echo $item['count']; ?>,
                                <?php endforeach; ?>
                            ],
                            backgroundColor: ['#0d6efd', '#ffc107', '#6f42c1']
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
            }
            
            // State Chart
            const stateCtx = document.getElementById('stateChart')?.getContext('2d');
            if (stateCtx && <?php echo !empty($by_state) ? 'true' : 'false'; ?>) {
                new Chart(stateCtx, {
                    type: 'pie',
                    data: {
                        labels: [
                            <?php foreach ($by_state as $item): ?>
                            '<?php echo htmlspecialchars($item['state_name']); ?>',
                            <?php endforeach; ?>
                        ],
                        datasets: [{
                            data: [
                                <?php foreach ($by_state as $item): ?>
                                <?php echo $item['count']; ?>,
                                <?php endforeach; ?>
                            ],
                            backgroundColor: [
                                '#0d6efd', '#6610f2', '#6f42c1', '#d63384', '#dc3545',
                                '#fd7e14', '#ffc107', '#198754', '#20c997', '#0dcaf0'
                            ]
                        }]
                    },
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
            
            // Check for current owner when title selected
            document.getElementById('add_title_id')?.addEventListener('change', function() {
                const titleId = this.value;
                if (!titleId) {
                    document.getElementById('currentOwnerAlert').style.display = 'none';
                    return;
                }
                
                // Fetch current owner via AJAX
                fetch('ajax/get_current_owner.php?title_id=' + titleId)
                    .then(response => response.json())
                    .then(data => {
                        if (data.owner_name) {
                            document.getElementById('currentOwnerText').innerHTML = 
                                'This title currently has an active owner: <strong>' + data.owner_name + '</strong>. ' +
                                'Adding a new owner will automatically end the current ownership.';
                            document.getElementById('currentOwnerAlert').style.display = 'block';
                        } else {
                            document.getElementById('currentOwnerAlert').style.display = 'none';
                        }
                    });
            });
        });
        
        // View ownership
        function viewOwnership(ownership) {
            document.getElementById('viewTitleNumber').textContent = ownership.title_number || 'N/A';
            document.getElementById('viewTitleType').textContent = ownership.title_type ? ucfirst(ownership.title_type) : 'N/A';
            document.getElementById('viewParcelNumber').textContent = ownership.parcel_number || 'N/A';
            document.getElementById('viewLocation').textContent = ownership.boma_name + ', ' + ownership.payam_name + ', ' + ownership.county_name;
            
            document.getElementById('viewOwnerName').textContent = ownership.owner_name || 'N/A';
            document.getElementById('viewOwnerType').textContent = ownership.owner_type ? ucfirst(ownership.owner_type) : 'N/A';
            document.getElementById('viewOwnerId').textContent = ownership.national_id || ownership.org_reg_number || 'N/A';
            document.getElementById('viewOwnerContact').textContent = (ownership.owner_phone || ownership.owner_email) || 'N/A';
            
            document.getElementById('viewStartDate').textContent = formatDate(ownership.ownership_start_date);
            document.getElementById('viewEndDate').textContent = ownership.ownership_end_date ? formatDate(ownership.ownership_end_date) : 'Present';
            
            let duration = 'N/A';
            if (ownership.ownership_days) {
                const days = ownership.ownership_days;
                const years = Math.floor(days / 365);
                const months = Math.floor((days % 365) / 30);
                const remainingDays = days % 30;
                
                const parts = [];
                if (years > 0) parts.push(years + ' year' + (years > 1 ? 's' : ''));
                if (months > 0) parts.push(months + ' month' + (months > 1 ? 's' : ''));
                if (remainingDays > 0 && years === 0) parts.push(remainingDays + ' day' + (remainingDays > 1 ? 's' : ''));
                
                duration = parts.join(', ');
            }
            document.getElementById('viewDuration').textContent = duration;
            document.getElementById('viewShare').textContent = ownership.share_percentage + '%';
            
            document.getElementById('viewPrevOwner').textContent = ownership.prev_owner_name || 'None';
            document.getElementById('viewCurrentOwner').textContent = ownership.owner_name || 'None';
            document.getElementById('viewNextOwner').textContent = ownership.next_owner_name || 'None';
        }
        
        // Edit ownership
        function editOwnership(ownership) {
            document.getElementById('editOwnershipId').value = ownership.id;
            document.getElementById('editTitleNumber').value = ownership.title_number || '';
            document.getElementById('editOwnerName').value = ownership.owner_name || '';
            document.getElementById('editSharePercentage').value = ownership.share_percentage || 100;
            document.getElementById('editNotes').value = ''; // Add notes field if needed
        }
        
        // End ownership
        function endOwnership(ownership) {
            document.getElementById('endOwnershipId').value = ownership.id;
            document.getElementById('endOwnerName').textContent = ownership.owner_name || '';
        }
        
        // Transfer ownership
        function transferOwnership(ownership) {
            document.getElementById('transferTitleId').value = ownership.title_id;
            document.getElementById('transferFromOwnershipId').value = ownership.id;
            document.getElementById('transferTitleNumber').value = ownership.title_number || '';
            document.getElementById('transferCurrentOwner').value = ownership.owner_name || '';
        }
        
        // Delete ownership
        function deleteOwnership(id, ownerName) {
            document.getElementById('deleteOwnershipId').value = id;
            document.getElementById('deleteOwnerName').textContent = ownerName;
        }
        
        // Format date
        function formatDate(dateString) {
            if (!dateString) return 'N/A';
            const options = { year: 'numeric', month: 'short', day: 'numeric' };
            return new Date(dateString).toLocaleDateString(undefined, options);
        }
        
        // Capitalize first letter
        function ucfirst(str) {
            return str.charAt(0).toUpperCase() + str.slice(1);
        }
        
        // Search functionality
        document.getElementById('tableSearch')?.addEventListener('keyup', function() {
            const searchText = this.value.toLowerCase();
            const table = document.getElementById('ownershipTable');
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
        .table-success {
            background-color: #d1e7dd !important;
        }
        .table-light {
            background-color: #f8f9fa !important;
        }
        .timeline .progress-bar {
            line-height: 30px;
            font-size: 12px;
            font-weight: bold;
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