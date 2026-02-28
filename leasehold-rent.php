<?php
ini_set('display_errors', 1); 
error_reporting(E_ALL);

// leasehold-rent.php
include("head.php");
require_once 'db_connection.php';

// ============================================================================
// SAFE QUERY FUNCTIONS
// ============================================================================

function safeFetchAll($conn, $sql, $params = [], $default = []) {
    try {
        return fetchAll($conn, $sql, $params);
    } catch (Exception $e) {
        error_log("Database error in safeFetchAll: " . $e->getMessage() . " SQL: " . $sql);
        return $default;
    }
}

function safeFetchOne($conn, $sql, $params = [], $default = null) {
    try {
        return fetchOne($conn, $sql, $params);
    } catch (Exception $e) {
        error_log("Database error in safeFetchOne: " . $e->getMessage() . " SQL: " . $sql);
        return $default;
    }
}

// ============================================================================
// HANDLE ACTIONS
// ============================================================================

$message = '';
$messageType = '';

// Create new leasehold rent assessment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    
    $title_id = $_POST['title_id'];
    $rent_year = $_POST['rent_year'];
    $rent_amount = $_POST['rent_amount'];
    $due_date = $_POST['due_date'];
    $notes = $_POST['notes'] ?? null;
    
    try {
        // Check if rent assessment already exists for this title and year
        $existing = safeFetchOne($conn, "
            SELECT id FROM leasehold_rent 
            WHERE title_id = ? AND rent_year = ?
        ", [$title_id, $rent_year]);
        
        if ($existing) {
            throw new Exception("Rent assessment for this title and year already exists");
        }
        
        executeQuery($conn, "
            INSERT INTO leasehold_rent (
                title_id, rent_year, rent_amount, due_date, status, notes, created_at
            ) VALUES (
                ?, ?, ?, ?, 'pending', ?, NOW()
            )
        ", [$title_id, $rent_year, $rent_amount, $due_date, $notes]);
        
        $message = "Leasehold rent assessment created successfully";
        $messageType = "success";
        
    } catch (Exception $e) {
        $message = "Error creating rent assessment: " . $e->getMessage();
        $messageType = "danger";
    }
}

// Record rent payment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'pay') {
    
    $rent_id = $_POST['rent_id'];
    $amount_paid = $_POST['amount_paid'];
    $payment_date = $_POST['payment_date'];
    $payment_method = $_POST['payment_method'];
    $reference = $_POST['reference'] ?? null;
    
    try {
        // Start transaction
        $conn->beginTransaction();
        
        // Get rent assessment details
        $rent = safeFetchOne($conn, "
            SELECT * FROM leasehold_rent WHERE id = ?
        ", [$rent_id]);
        
        if (!$rent) {
            throw new Exception("Rent assessment not found");
        }
        
        // Generate receipt number
        $receipt_number = 'LR/' . date('Y') . '/' . str_pad($rent_id, 5, '0', STR_PAD_LEFT);
        
        // Update rent payment
        executeQuery($conn, "
            UPDATE leasehold_rent 
            SET status = 'paid', 
                payment_date = ?,
                amount_paid = ?,
                payment_method = ?,
                reference = ?,
                receipt_number = ?,
                updated_at = NOW()
            WHERE id = ?
        ", [$payment_date, $amount_paid, $payment_method, $reference, $receipt_number, $rent_id]);
        
        $conn->commit();
        
        $message = "Rent payment recorded successfully. Receipt: $receipt_number";
        $messageType = "success";
        
    } catch (Exception $e) {
        $conn->rollBack();
        $message = "Error recording payment: " . $e->getMessage();
        $messageType = "danger";
    }
}

// Update rent assessment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update') {
    
    $rent_id = $_POST['rent_id'];
    $rent_amount = $_POST['rent_amount'];
    $due_date = $_POST['due_date'];
    $notes = $_POST['notes'] ?? null;
    
    try {
        executeQuery($conn, "
            UPDATE leasehold_rent 
            SET rent_amount = ?, 
                due_date = ?, 
                notes = ?,
                updated_at = NOW()
            WHERE id = ? AND status = 'pending'
        ", [$rent_amount, $due_date, $notes, $rent_id]);
        
        $message = "Rent assessment updated successfully";
        $messageType = "success";
        
    } catch (Exception $e) {
        $message = "Error updating rent assessment: " . $e->getMessage();
        $messageType = "danger";
    }
}

// Waive rent
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'waive') {
    
    $rent_id = $_POST['rent_id'];
    $reason = $_POST['reason'] ?? null;
    
    try {
        executeQuery($conn, "
            UPDATE leasehold_rent 
            SET status = 'waived', 
                notes = CONCAT(IFNULL(notes, ''), '\nWAIVED: ', ?),
                updated_at = NOW()
            WHERE id = ? AND status = 'pending'
        ", [$reason, $rent_id]);
        
        $message = "Rent waived successfully";
        $messageType = "success";
        
    } catch (Exception $e) {
        $message = "Error waiving rent: " . $e->getMessage();
        $messageType = "danger";
    }
}

// ============================================================================
// GET FILTERS
// ============================================================================

$title_filter = isset($_GET['title_id']) ? (int)$_GET['title_id'] : 0;
$year_filter = $_GET['year'] ?? date('Y');
$status_filter = $_GET['status'] ?? '';

// ============================================================================
// GET LEASEHOLD RENT DATA
// ============================================================================

$where_conditions = [];
$params = [];

if ($title_filter > 0) {
    $where_conditions[] = "lr.title_id = ?";
    $params[] = $title_filter;
}

if (!empty($year_filter)) {
    $where_conditions[] = "lr.rent_year = ?";
    $params[] = $year_filter;
}

if (!empty($status_filter)) {
    $where_conditions[] = "lr.status = ?";
    $params[] = $status_filter;
}

$where_clause = empty($where_conditions) ? "" : "WHERE " . implode(" AND ", $where_conditions);

// Get leasehold rent records with title and parcel details
$rents = safeFetchAll($conn, "
    SELECT 
        lr.*,
        t.title_number,
        t.title_type,
        t.issue_date,
        t.expiry_date,
        t.status as title_status,
        p.id as parcel_id,
        p.parcel_number,
        p.area,
        p.location_description,
        b.name as boma_name,
        py.name as payam_name,
        c.name as county_name,
        s.name as state_name,
        -- Owner info
        o.party_id as owner_id,
        pt.name as owner_name,
        pt.phone as owner_phone,
        pt.email as owner_email,
        -- Calculate days overdue
        DATEDIFF(CURDATE(), lr.due_date) as days_overdue
    FROM leasehold_rent lr
    JOIN titles t ON lr.title_id = t.id
    JOIN parcels p ON t.parcel_id = p.id
    LEFT JOIN bomas b ON p.boma_id = b.id
    LEFT JOIN payams py ON b.payam_id = py.id
    LEFT JOIN counties c ON py.county_id = c.id
    LEFT JOIN states s ON c.state_id = s.id
    LEFT JOIN ownerships o ON t.id = o.title_id AND o.is_current = 1
    LEFT JOIN parties pt ON o.party_id = pt.id
    $where_clause
    ORDER BY lr.rent_year DESC, lr.due_date ASC
", $params, []);

// ============================================================================
// GET TITLE INFORMATION (if title_id is provided)
// ============================================================================

$title_info = null;
if ($title_filter > 0) {
    $title_info = safeFetchOne($conn, "
        SELECT 
            t.*,
            p.parcel_number,
            p.area,
            p.location_description,
            b.name as boma_name,
            py.name as payam_name,
            c.name as county_name,
            s.name as state_name,
            pt.name as owner_name,
            pt.phone as owner_phone,
            pt.email as owner_email,
            -- Calculate remaining lease years
            TIMESTAMPDIFF(YEAR, CURDATE(), t.expiry_date) as years_remaining
        FROM titles t
        JOIN parcels p ON t.parcel_id = p.id
        LEFT JOIN bomas b ON p.boma_id = b.id
        LEFT JOIN payams py ON b.payam_id = py.id
        LEFT JOIN counties c ON py.county_id = c.id
        LEFT JOIN states s ON c.state_id = s.id
        LEFT JOIN ownerships o ON t.id = o.title_id AND o.is_current = 1
        LEFT JOIN parties pt ON o.party_id = pt.id
        WHERE t.id = ?
    ", [$title_filter]);
}

// ============================================================================
// GET SUMMARY STATISTICS
// ============================================================================

$summary = safeFetchOne($conn, "
    SELECT 
        COUNT(*) as total_assessments,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
        SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid_count,
        SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) as overdue_count,
        SUM(CASE WHEN status = 'waived' THEN 1 ELSE 0 END) as waived_count,
        SUM(CASE WHEN status = 'paid' THEN amount_paid ELSE 0 END) as total_collected,
        SUM(CASE WHEN status = 'pending' AND due_date < CURDATE() THEN rent_amount ELSE 0 END) as overdue_amount,
        SUM(rent_amount) as total_expected,
        MIN(due_date) as next_due,
        MAX(payment_date) as last_payment
    FROM leasehold_rent
    " . ($title_filter > 0 ? "WHERE title_id = $title_filter" : "")
);

// Get available years for filter
$years = safeFetchAll($conn, "
    SELECT DISTINCT rent_year 
    FROM leasehold_rent 
    ORDER BY rent_year DESC
", [], []);

// Get all active leasehold titles for dropdown
$titles = safeFetchAll($conn, "
    SELECT 
        t.id,
        t.title_number,
        t.expiry_date,
        p.parcel_number,
        pt.name as owner_name
    FROM titles t
    JOIN parcels p ON t.parcel_id = p.id
    LEFT JOIN ownerships o ON t.id = o.title_id AND o.is_current = 1
    LEFT JOIN parties pt ON o.party_id = pt.id
    WHERE t.title_type = 'leasehold' AND t.status = 'active'
    ORDER BY t.title_number
", [], []);

// Status colors and labels
$status_info = [
    'pending' => ['label' => 'Pending', 'class' => 'warning', 'icon' => 'hourglass-split'],
    'paid' => ['label' => 'Paid', 'class' => 'success', 'icon' => 'check-circle'],
    'overdue' => ['label' => 'Overdue', 'class' => 'danger', 'icon' => 'exclamation-triangle'],
    'waived' => ['label' => 'Waived', 'class' => 'secondary', 'icon' => 'shield-check']
];

// Payment methods
$payment_methods = [
    'cash' => 'Cash',
    'bank_transfer' => 'Bank Transfer',
    'check' => 'Check',
    'credit_card' => 'Credit Card',
    'mobile_money' => 'Mobile Money'
];
?>

<body data-page="leasehold-rent" class="leasehold-rent-page">
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
                                    <li class="breadcrumb-item"><a href="titles.php">Titles</a></li>
                                    <li class="breadcrumb-item"><a href="titles.php?type=leasehold">Leasehold Titles</a></li>
                                    <li class="breadcrumb-item active">Rent Management</li>
                                </ol>
                            </nav>
                            <h1 class="h3 mb-0">Leasehold Rent Management</h1>
                            <p class="text-muted mb-0">
                                <?php if ($title_info): ?>
                                    Title: <?php echo htmlspecialchars($title_info['title_number']); ?> - 
                                    Parcel: <?php echo htmlspecialchars($title_info['parcel_number']); ?>
                                <?php else: ?>
                                    Manage annual rent payments for leasehold titles
                                <?php endif; ?>
                            </p>
                        </div>
                        <div>
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createRentModal">
                                <i class="bi bi-plus-circle me-2"></i>Create Rent Assessment
                            </button>
                        </div>
                    </div>

                    <!-- Alert Message -->
                    <?php if ($message): ?>
                    <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show mb-4" role="alert">
                        <?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    <?php endif; ?>

                    <!-- Title Information Card (if specific title selected) -->
                    <?php if ($title_info): ?>
                    <div class="card border-0 shadow-sm mb-4 bg-light">
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-3">
                                    <small class="text-muted d-block">Title Number</small>
                                    <strong><?php echo htmlspecialchars($title_info['title_number']); ?></strong>
                                </div>
                                <div class="col-md-3">
                                    <small class="text-muted d-block">Parcel</small>
                                    <strong><?php echo htmlspecialchars($title_info['parcel_number']); ?></strong>
                                </div>
                                <div class="col-md-3">
                                    <small class="text-muted d-block">Owner</small>
                                    <strong><?php echo htmlspecialchars($title_info['owner_name'] ?? 'Unknown'); ?></strong>
                                </div>
                                <div class="col-md-3">
                                    <small class="text-muted d-block">Lease Expiry</small>
                                    <strong class="<?php echo ($title_info['years_remaining'] ?? 0) < 5 ? 'text-danger' : ''; ?>">
                                        <?php echo date('d/m/Y', strtotime($title_info['expiry_date'])); ?>
                                        (<?php echo $title_info['years_remaining'] ?? 0; ?> years left)
                                    </strong>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Summary Cards -->
                    <div class="row g-4 mb-4">
                        <div class="col-xl-2 col-md-4">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0 me-3">
                                            <div class="bg-primary bg-opacity-10 rounded-circle p-3">
                                                <i class="bi bi-calculator text-primary fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Total Assessments</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['total_assessments'] ?? 0); ?></h3>
                                            <small class="text-muted">Expected: <?php echo number_format($summary['total_expected'] ?? 0, 2); ?> SSP</small>
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
                                            <h6 class="text-muted mb-1">Paid</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['paid_count'] ?? 0); ?></h3>
                                            <small class="text-muted">Collected: <?php echo number_format($summary['total_collected'] ?? 0, 2); ?> SSP</small>
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
                                            <h6 class="text-muted mb-1">Pending</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['pending_count'] ?? 0); ?></h3>
                                            <small class="text-muted">Due: <?php echo $summary['next_due'] ? date('d/m/Y', strtotime($summary['next_due'])) : 'N/A'; ?></small>
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
                                            <h6 class="text-muted mb-1">Overdue</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['overdue_count'] ?? 0); ?></h3>
                                            <small class="text-muted">Amount: <?php echo number_format($summary['overdue_amount'] ?? 0, 2); ?> SSP</small>
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
                                                <i class="bi bi-shield-check text-secondary fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Waived</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['waived_count'] ?? 0); ?></h3>
                                            <small class="text-muted">Exempted payments</small>
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
                                                <i class="bi bi-calendar-check text-info fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Collection Rate</h6>
                                            <h3 class="mb-0 fw-bold">
                                                <?php 
                                                $rate = ($summary['total_expected'] ?? 0) > 0 
                                                    ? (($summary['total_collected'] ?? 0) / ($summary['total_expected'] ?? 1)) * 100 
                                                    : 0;
                                                echo number_format($rate, 1); ?>%
                                            </h3>
                                            <small class="text-muted">Overall</small>
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
                                    <label class="form-label">Leasehold Title</label>
                                    <select class="form-select" name="title_id">
                                        <option value="">All Titles</option>
                                        <?php foreach ($titles as $t): ?>
                                        <option value="<?php echo $t['id']; ?>" <?php echo $title_filter == $t['id'] ? 'selected' : ''; ?>>
                                            <?php echo $t['title_number']; ?> - <?php echo $t['parcel_number']; ?> 
                                            (<?php echo htmlspecialchars($t['owner_name'] ?? 'Unknown'); ?>)
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-3">
                                    <label class="form-label">Year</label>
                                    <select class="form-select" name="year">
                                        <option value="">All Years</option>
                                        <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                                        <option value="<?php echo $y; ?>" <?php echo $year_filter == $y ? 'selected' : ''; ?>>
                                            <?php echo $y; ?>
                                        </option>
                                        <?php endfor; ?>
                                        <?php foreach ($years as $y): ?>
                                        <?php if ($y['rent_year'] < date('Y') - 5): ?>
                                        <option value="<?php echo $y['rent_year']; ?>" <?php echo $year_filter == $y['rent_year'] ? 'selected' : ''; ?>>
                                            <?php echo $y['rent_year']; ?>
                                        </option>
                                        <?php endif; ?>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-3">
                                    <label class="form-label">Status</label>
                                    <select class="form-select" name="status">
                                        <option value="">All Statuses</option>
                                        <?php foreach ($status_info as $key => $info): ?>
                                        <option value="<?php echo $key; ?>" <?php echo $status_filter == $key ? 'selected' : ''; ?>>
                                            <?php echo $info['label']; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-3 d-flex align-items-end">
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="bi bi-search me-2"></i>Apply Filters
                                    </button>
                                </div>
                                
                                <div class="col-12">
                                    <a href="leasehold-rent.php<?php echo $title_filter > 0 ? '?title_id=' . $title_filter : ''; ?>" class="btn btn-secondary">
                                        <i class="bi bi-x-circle me-2"></i>Clear Filters
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Rent Assessments Table -->
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0 fw-bold">
                                <i class="bi bi-table me-2"></i>Rent Assessments
                                <span class="badge bg-secondary ms-2"><?php echo count($rents); ?> records</span>
                            </h5>
                            <div>
                                <span class="text-muted me-3">
                                    Total Expected: <strong><?php echo number_format(array_sum(array_column($rents, 'rent_amount')), 2); ?> SSP</strong>
                                </span>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>Year</th>
                                            <th>Title/Parcel</th>
                                            <th>Owner</th>
                                            <th>Location</th>
                                            <th>Rent Amount</th>
                                            <th>Due Date</th>
                                            <th>Status</th>
                                            <th>Payment Info</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($rents)): ?>
                                        <tr>
                                            <td colspan="9" class="text-center py-4 text-muted">
                                                <i class="bi bi-inbox fs-1 d-block mb-3"></i>
                                                No rent assessments found
                                            </td>
                                        </tr>
                                        <?php else: ?>
                                            <?php foreach ($rents as $rent): ?>
                                            <?php 
                                            $is_overdue = $rent['due_date'] < date('Y-m-d') && $rent['status'] == 'pending';
                                            $status_key = $is_overdue ? 'overdue' : $rent['status'];
                                            $status = $status_info[$status_key] ?? ['label' => ucfirst($rent['status']), 'class' => 'secondary', 'icon' => 'question'];
                                            ?>
                                            <tr class="<?php echo $is_overdue ? 'table-danger' : ''; ?>">
                                                <td>
                                                    <strong><?php echo $rent['rent_year']; ?></strong>
                                                </td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($rent['title_number']); ?></strong>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars($rent['parcel_number']); ?></small>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars($rent['owner_name'] ?? 'Unknown'); ?>
                                                    <?php if ($rent['owner_phone']): ?>
                                                    <br><small class="text-muted"><?php echo $rent['owner_phone']; ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <small>
                                                        <?php echo $rent['boma_name'] ? htmlspecialchars($rent['boma_name']) : '-'; ?>,
                                                        <?php echo $rent['payam_name'] ? htmlspecialchars($rent['payam_name']) : '-'; ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <span class="fw-bold"><?php echo number_format($rent['rent_amount'], 2); ?> SSP</span>
                                                </td>
                                                <td>
                                                    <?php echo date('d/m/Y', strtotime($rent['due_date'])); ?>
                                                    <?php if ($is_overdue): ?>
                                                    <br><small class="text-danger"><?php echo $rent['days_overdue']; ?> days overdue</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?php echo $status['class']; ?>">
                                                        <i class="bi bi-<?php echo $status['icon']; ?> me-1"></i>
                                                        <?php echo $status['label']; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($rent['status'] == 'paid'): ?>
                                                        <small class="text-success">
                                                            <i class="bi bi-check-circle"></i> 
                                                            <?php echo date('d/m/Y', strtotime($rent['payment_date'])); ?>
                                                        </small>
                                                        <br>
                                                        <code><?php echo $rent['receipt_number']; ?></code>
                                                        <br>
                                                        <small class="text-muted"><?php echo $payment_methods[$rent['payment_method']] ?? $rent['payment_method']; ?></small>
                                                    <?php elseif ($rent['status'] == 'waived'): ?>
                                                        <span class="badge bg-secondary">Waived</span>
                                                    <?php else: ?>
                                                        <span class="text-muted">Not paid</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <button class="btn btn-outline-primary" 
                                                                onclick="viewRent(<?php echo htmlspecialchars(json_encode($rent)); ?>)"
                                                                data-bs-toggle="modal" data-bs-target="#viewRentModal">
                                                            <i class="bi bi-eye"></i>
                                                        </button>
                                                        
                                                        <?php if ($rent['status'] == 'pending'): ?>
                                                            <button class="btn btn-outline-success" 
                                                                    onclick="payRent(<?php echo $rent['id']; ?>, '<?php echo $rent['title_number']; ?>', <?php echo $rent['rent_amount']; ?>, <?php echo $rent['rent_year']; ?>)"
                                                                    data-bs-toggle="modal" data-bs-target="#payRentModal">
                                                                <i class="bi bi-cash"></i>
                                                            </button>
                                                            
                                                            <button class="btn btn-outline-warning" 
                                                                    onclick="editRent(<?php echo htmlspecialchars(json_encode($rent)); ?>)"
                                                                    data-bs-toggle="modal" data-bs-target="#editRentModal">
                                                                <i class="bi bi-pencil"></i>
                                                            </button>
                                                            
                                                            <button class="btn btn-outline-secondary" 
                                                                    onclick="waiveRent(<?php echo $rent['id']; ?>, '<?php echo $rent['title_number']; ?>', <?php echo $rent['rent_year']; ?>)"
                                                                    data-bs-toggle="modal" data-bs-target="#waiveRentModal">
                                                                <i class="bi bi-shield-check"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                        
                                                        <?php if ($rent['status'] == 'paid' && $rent['receipt_number']): ?>
                                                        <a href="receipt-leasehold.php?id=<?php echo $rent['id']; ?>" 
                                                           class="btn btn-outline-info" target="_blank">
                                                            <i class="bi bi-receipt"></i>
                                                        </a>
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

                    <!-- Payment Summary by Year -->
                    <?php if (!empty($rents)): ?>
                    <div class="card border-0 shadow-sm mt-4">
                        <div class="card-header bg-white py-3">
                            <h6 class="card-title mb-0 fw-bold">
                                <i class="bi bi-pie-chart me-2 text-info"></i>Payment Summary by Year
                            </h6>
                        </div>
                        <div class="card-body">
                            <?php
                            $yearly_summary = [];
                            foreach ($rents as $rent) {
                                $year = $rent['rent_year'];
                                if (!isset($yearly_summary[$year])) {
                                    $yearly_summary[$year] = [
                                        'total' => 0,
                                        'paid' => 0,
                                        'pending' => 0,
                                        'overdue' => 0,
                                        'waived' => 0,
                                        'collected' => 0
                                    ];
                                }
                                $yearly_summary[$year]['total'] += $rent['rent_amount'];
                                if ($rent['status'] == 'paid') {
                                    $yearly_summary[$year]['paid']++;
                                    $yearly_summary[$year]['collected'] += $rent['amount_paid'];
                                } elseif ($rent['status'] == 'pending') {
                                    if ($rent['due_date'] < date('Y-m-d')) {
                                        $yearly_summary[$year]['overdue']++;
                                    } else {
                                        $yearly_summary[$year]['pending']++;
                                    }
                                } elseif ($rent['status'] == 'waived') {
                                    $yearly_summary[$year]['waived']++;
                                }
                            }
                            krsort($yearly_summary);
                            ?>
                            
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>Year</th>
                                            <th>Total Amount</th>
                                            <th>Collected</th>
                                            <th>Collection Rate</th>
                                            <th>Paid</th>
                                            <th>Pending</th>
                                            <th>Overdue</th>
                                            <th>Waived</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($yearly_summary as $year => $data): ?>
                                        <tr>
                                            <td><strong><?php echo $year; ?></strong></td>
                                            <td><?php echo number_format($data['total'], 2); ?> SSP</td>
                                            <td><?php echo number_format($data['collected'], 2); ?> SSP</td>
                                            <td>
                                                <?php 
                                                $rate = $data['total'] > 0 ? ($data['collected'] / $data['total']) * 100 : 0;
                                                ?>
                                                <div class="progress" style="height: 20px; width: 150px;">
                                                    <div class="progress-bar bg-success" style="width: <?php echo $rate; ?>%">
                                                        <?php echo number_format($rate, 1); ?>%
                                                    </div>
                                                </div>
                                            </td>
                                            <td><span class="badge bg-success"><?php echo $data['paid']; ?></span></td>
                                            <td><span class="badge bg-warning"><?php echo $data['pending']; ?></span></td>
                                            <td><span class="badge bg-danger"><?php echo $data['overdue']; ?></span></td>
                                            <td><span class="badge bg-secondary"><?php echo $data['waived']; ?></span></td>
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

    <!-- Create Rent Assessment Modal -->
    <div class="modal fade" id="createRentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="create">
                    <div class="modal-header">
                        <h5 class="modal-title">Create Rent Assessment</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Leasehold Title <span class="text-danger">*</span></label>
                            <select class="form-select" name="title_id" required>
                                <option value="">Select Title</option>
                                <?php foreach ($titles as $t): ?>
                                <option value="<?php echo $t['id']; ?>" <?php echo $title_filter == $t['id'] ? 'selected' : ''; ?>>
                                    <?php echo $t['title_number']; ?> - <?php echo $t['parcel_number']; ?> 
                                    (<?php echo htmlspecialchars($t['owner_name'] ?? 'Unknown'); ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Rent Year <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="rent_year" value="<?php echo date('Y'); ?>" min="2000" max="<?php echo date('Y') + 10; ?>" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Rent Amount (SSP) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" class="form-control" name="rent_amount" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Due Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="due_date" value="<?php echo date('Y-m-d', strtotime('+1 month')); ?>" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Notes</label>
                            <textarea class="form-control" name="notes" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create Assessment</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Pay Rent Modal -->
    <div class="modal fade" id="payRentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="pay">
                    <input type="hidden" name="rent_id" id="pay_rent_id">
                    <div class="modal-header">
                        <h5 class="modal-title">Record Rent Payment</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Title</label>
                            <input type="text" class="form-control" id="pay_title" readonly>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Year</label>
                            <input type="text" class="form-control" id="pay_year" readonly>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Amount Due</label>
                            <input type="text" class="form-control" id="pay_amount_due" readonly>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Amount Paid <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" class="form-control" name="amount_paid" id="pay_amount" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Payment Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="payment_date" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Payment Method <span class="text-danger">*</span></label>
                            <select class="form-select" name="payment_method" required>
                                <?php foreach ($payment_methods as $value => $label): ?>
                                <option value="<?php echo $value; ?>"><?php echo $label; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Reference (Cheque/Transfer/M-Pesa)</label>
                            <input type="text" class="form-control" name="reference">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Record Payment</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Rent Modal -->
    <div class="modal fade" id="editRentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="rent_id" id="edit_rent_id">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Rent Assessment</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Title</label>
                            <input type="text" class="form-control" id="edit_title" readonly>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Year</label>
                            <input type="text" class="form-control" id="edit_year" readonly>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Rent Amount (SSP) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" class="form-control" name="rent_amount" id="edit_amount" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Due Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="due_date" id="edit_due_date" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Notes</label>
                            <textarea class="form-control" name="notes" id="edit_notes" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Assessment</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Waive Rent Modal -->
    <div class="modal fade" id="waiveRentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="waive">
                    <input type="hidden" name="rent_id" id="waive_rent_id">
                    <div class="modal-header">
                        <h5 class="modal-title">Waive Rent</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p>Are you sure you want to waive rent for <strong id="waive_title"></strong> - Year <strong id="waive_year"></strong>?</p>
                        
                        <div class="mb-3">
                            <label class="form-label">Reason for Waiver</label>
                            <textarea class="form-control" name="reason" rows="3" placeholder="Explain why this rent is being waived..."></textarea>
                        </div>
                        
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            This action cannot be undone. The rent will be marked as waived.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning">Waive Rent</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Rent Modal -->
    <div class="modal fade" id="viewRentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Rent Assessment Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <table class="table table-borderless">
                        <tr>
                            <th width="40%">Title Number:</th>
                            <td id="view_title"></td>
                        </tr>
                        <tr>
                            <th>Parcel Number:</th>
                            <td id="view_parcel"></td>
                        </tr>
                        <tr>
                            <th>Owner:</th>
                            <td id="view_owner"></td>
                        </tr>
                        <tr>
                            <th>Location:</th>
                            <td id="view_location"></td>
                        </tr>
                        <tr>
                            <th>Year:</th>
                            <td id="view_year"></td>
                        </tr>
                        <tr>
                            <th>Rent Amount:</th>
                            <td id="view_amount"></td>
                        </tr>
                        <tr>
                            <th>Due Date:</th>
                            <td id="view_due"></td>
                        </tr>
                        <tr>
                            <th>Status:</th>
                            <td id="view_status"></td>
                        </tr>
                        <tr id="payment_info_row" style="display: none;">
                            <th>Payment Info:</th>
                            <td id="view_payment"></td>
                        </tr>
                        <tr>
                            <th>Notes:</th>
                            <td id="view_notes"></td>
                        </tr>
                    </table>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // View rent details
        function viewRent(rent) {
            document.getElementById('view_title').textContent = rent.title_number;
            document.getElementById('view_parcel').textContent = rent.parcel_number;
            document.getElementById('view_owner').textContent = rent.owner_name || 'Unknown';
            document.getElementById('view_location').textContent = 
                (rent.boma_name || '') + ', ' + (rent.payam_name || '') + ', ' + (rent.county_name || '');
            document.getElementById('view_year').textContent = rent.rent_year;
            document.getElementById('view_amount').textContent = Number(rent.rent_amount).toFixed(2) + ' SSP';
            document.getElementById('view_due').textContent = new Date(rent.due_date).toLocaleDateString();
            
            const isOverdue = new Date(rent.due_date) < new Date() && rent.status == 'pending';
            const statusKey = isOverdue ? 'overdue' : rent.status;
            const statuses = <?php echo json_encode($status_info); ?>;
            const status = statuses[statusKey] || {label: rent.status, class: 'secondary'};
            
            document.getElementById('view_status').innerHTML = 
                '<span class="badge bg-' + status.class + '">' + status.label + '</span>';
            
            if (rent.status == 'paid') {
                document.getElementById('payment_info_row').style.display = 'table-row';
                document.getElementById('view_payment').innerHTML = 
                    'Amount: ' + Number(rent.amount_paid).toFixed(2) + ' SSP<br>' +
                    'Date: ' + new Date(rent.payment_date).toLocaleDateString() + '<br>' +
                    'Method: ' + (rent.payment_method || 'N/A') + '<br>' +
                    'Receipt: ' + (rent.receipt_number || 'N/A');
            } else {
                document.getElementById('payment_info_row').style.display = 'none';
            }
            
            document.getElementById('view_notes').textContent = rent.notes || 'No notes';
        }

        // Pay rent
        function payRent(rentId, title, amount, year) {
            document.getElementById('pay_rent_id').value = rentId;
            document.getElementById('pay_title').value = title;
            document.getElementById('pay_year').value = year;
            document.getElementById('pay_amount_due').value = Number(amount).toFixed(2) + ' SSP';
            document.getElementById('pay_amount').value = amount;
        }

        // Edit rent
        function editRent(rent) {
            document.getElementById('edit_rent_id').value = rent.id;
            document.getElementById('edit_title').value = rent.title_number;
            document.getElementById('edit_year').value = rent.rent_year;
            document.getElementById('edit_amount').value = rent.rent_amount;
            document.getElementById('edit_due_date').value = rent.due_date;
            document.getElementById('edit_notes').value = rent.notes || '';
        }

        // Waive rent
        function waiveRent(rentId, title, year) {
            document.getElementById('waive_rent_id').value = rentId;
            document.getElementById('waive_title').textContent = title;
            document.getElementById('waive_year').textContent = year;
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
        
        .table td {
            vertical-align: middle;
        }
        
        .btn-group .btn {
            padding: 0.25rem 0.5rem;
        }
        
        .bg-opacity-10 {
            --bs-bg-opacity: 0.1;
        }
        
        .progress {
            background-color: #e9ecef;
        }
        
        .table-danger td {
            background-color: rgba(220, 53, 69, 0.05);
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