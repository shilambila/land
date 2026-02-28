<?php
ini_set('display_errors', 1); 
error_reporting(E_ALL);

// titles-leasehold.php
include("head.php");
require_once 'db_connection.php';

// ============================================================================
// HANDLE ACTIONS
// ============================================================================

$message = '';
$messageType = '';

// Renew leasehold
if (isset($_POST['action']) && $_POST['action'] === 'renew') {
    $title_id = $_POST['title_id'];
    $new_expiry_date = $_POST['new_expiry_date'];
    $renewal_fee = !empty($_POST['renewal_fee']) ? $_POST['renewal_fee'] : null;
    $terms_conditions = $_POST['terms_conditions'] ?? null;
    $renewed_by = $_SESSION['user_id'] ?? 1;
    
    try {
        $conn->beginTransaction();
        
        // Get current title info
        $title = fetchOne($conn, "SELECT * FROM titles WHERE id = ?", [$title_id]);
        if (!$title) {
            throw new Exception("Title not found");
        }
        
        // Update title with new expiry date
        executeQuery($conn, "
            UPDATE titles 
            SET expiry_date = ?, updated_at = NOW() 
            WHERE id = ?
        ", [$new_expiry_date, $title_id]);
        
        // Record renewal in a leasehold_renewals table (you may need to create this)
        // For now, we'll add a note to the title description or create a transaction
        executeQuery($conn, "
            INSERT INTO transactions (
                transaction_type, parcel_id, title_id, from_party_id, to_party_id,
                transaction_date, consideration_amount, details, created_at, created_by
            ) VALUES (
                'lease_renewal', ?, ?, NULL, NULL, CURDATE(), ?, ?, NOW(), ?
            )
        ", [$title['parcel_id'], $title_id, $renewal_fee, 
            "Leasehold renewal. New expiry date: $new_expiry_date. Terms: $terms_conditions", 
            $renewed_by]);
        
        $conn->commit();
        
        $message = "Leasehold renewed successfully. New expiry date: " . date('d M Y', strtotime($new_expiry_date));
        $messageType = "success";
    } catch (Exception $e) {
        $conn->rollBack();
        $message = "Error renewing leasehold: " . $e->getMessage();
        $messageType = "danger";
    }
}

// Calculate ground rent
if (isset($_POST['action']) && $_POST['action'] === 'calculate_rent') {
    $title_id = $_POST['title_id'];
    $rent_year = $_POST['rent_year'];
    $rent_amount = $_POST['rent_amount'];
    $due_date = $_POST['due_date'];
    
    try {
        // Check if rent already calculated for this year
        $existing = fetchOne($conn, "
            SELECT id FROM leasehold_rent 
            WHERE title_id = ? AND rent_year = ?
        ", [$title_id, $rent_year]);
        
        if ($existing) {
            throw new Exception("Ground rent already calculated for this year");
        }
        
        // Insert rent record (you may need to create leasehold_rent table)
        executeQuery($conn, "
            INSERT INTO leasehold_rent (
                title_id, rent_year, rent_amount, due_date, status, created_at
            ) VALUES (?, ?, ?, ?, 'pending', NOW())
        ", [$title_id, $rent_year, $rent_amount, $due_date]);
        
        $message = "Ground rent calculated successfully";
        $messageType = "success";
    } catch (Exception $e) {
        $message = "Error calculating ground rent: " . $e->getMessage();
        $messageType = "danger";
    }
}

// Record rent payment
if (isset($_POST['action']) && $_POST['action'] === 'pay_rent') {
    $rent_id = $_POST['rent_id'];
    $payment_date = $_POST['payment_date'];
    $amount_paid = $_POST['amount_paid'];
    $payment_method = $_POST['payment_method'];
    $reference = $_POST['reference'] ?? null;
    $receipt_number = $_POST['receipt_number'] ?? null;
    
    try {
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
        
        $message = "Rent payment recorded successfully";
        $messageType = "success";
    } catch (Exception $e) {
        $message = "Error recording payment: " . $e->getMessage();
        $messageType = "danger";
    }
}

// Get leasehold titles with filters
$whereClause = " AND t.title_type = 'leasehold'";
$params = [];

if (isset($_GET['status']) && !empty($_GET['status'])) {
    $whereClause .= " AND t.status = ?";
    $params[] = $_GET['status'];
}

if (isset($_GET['expiry_filter']) && !empty($_GET['expiry_filter'])) {
    switch ($_GET['expiry_filter']) {
        case 'expired':
            $whereClause .= " AND t.expiry_date < CURDATE()";
            break;
        case 'expiring_30':
            $whereClause .= " AND t.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
            break;
        case 'expiring_90':
            $whereClause .= " AND t.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)";
            break;
        case 'expiring_year':
            $whereClause .= " AND t.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 1 YEAR)";
            break;
    }
}

if (isset($_GET['state_id']) && !empty($_GET['state_id'])) {
    $whereClause .= " AND s.id = ?";
    $params[] = $_GET['state_id'];
}

if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search = '%' . $_GET['search'] . '%';
    $whereClause .= " AND (t.title_number LIKE ? OR p.parcel_number LIKE ? OR pa2.name LIKE ?)";
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
}

// Get leasehold titles with full details
$leaseholds = fetchAll($conn, "
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
        -- Current owner
        o.party_id as owner_id,
        o.share_percentage,
        o.ownership_start_date,
        pa2.name as owner_name,
        pa2.party_type as owner_type,
        pa2.phone as owner_phone,
        pa2.email as owner_email,
        -- Leasehold specific
        DATEDIFF(t.expiry_date, CURDATE()) as days_remaining,
        CASE 
            WHEN t.expiry_date < CURDATE() THEN 'Expired'
            WHEN t.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 'Critical'
            WHEN t.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY) THEN 'Warning'
            WHEN t.expiry_date > CURDATE() THEN 'Active'
            ELSE 'Unknown'
        END as expiry_status,
        -- Rent information (if you have leasehold_rent table)
        (SELECT COUNT(*) FROM leasehold_rent WHERE title_id = t.id) as total_rent_entries,
        (SELECT COUNT(*) FROM leasehold_rent WHERE title_id = t.id AND status = 'pending') as pending_rent,
        (SELECT SUM(amount_paid) FROM leasehold_rent WHERE title_id = t.id AND status = 'paid') as total_rent_paid
    FROM titles t
    JOIN parcels p ON t.parcel_id = p.id
    JOIN bomas b ON p.boma_id = b.id
    JOIN payams pa ON b.payam_id = pa.id
    JOIN counties c ON pa.county_id = c.id
    JOIN states s ON c.state_id = s.id
    LEFT JOIN ownerships o ON t.id = o.title_id AND o.is_current = 1
    LEFT JOIN parties pa2 ON o.party_id = pa2.id
    WHERE 1=1 $whereClause
    ORDER BY 
        CASE 
            WHEN t.expiry_date IS NULL THEN 1
            WHEN t.expiry_date < CURDATE() THEN 2
            WHEN t.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 3
            WHEN t.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY) THEN 4
            ELSE 5
        END,
        t.expiry_date ASC
", $params);

// Get states for filter
$states = fetchAll($conn, "SELECT id, name FROM states ORDER BY name");

// Summary statistics for leaseholds
$summary = fetchOne($conn, "
    SELECT 
        COUNT(*) as total_leaseholds,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_leaseholds,
        SUM(CASE WHEN expiry_date < CURDATE() AND status = 'active' THEN 1 ELSE 0 END) as expired_leaseholds,
        SUM(CASE WHEN expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND status = 'active' THEN 1 ELSE 0 END) as critical_leaseholds,
        SUM(CASE WHEN expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY) AND status = 'active' THEN 1 ELSE 0 END) as warning_leaseholds,
        SUM(CASE WHEN title_type = 'leasehold' THEN 1 ELSE 0 END) as total_leaseholds,
        MIN(expiry_date) as earliest_expiry,
        MAX(expiry_date) as latest_expiry,
        AVG(DATEDIFF(expiry_date, issue_date)) as avg_lease_term_days
    FROM titles
    WHERE title_type = 'leasehold'
");

// Get rent summary (if table exists)
$rent_summary = fetchOne($conn, "
    SELECT 
        COUNT(*) as total_rent_entries,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_rent,
        SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid_rent,
        SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) as overdue_rent,
        SUM(rent_amount) as total_rent_due,
        SUM(amount_paid) as total_rent_collected
    FROM leasehold_rent
    WHERE YEAR(due_date) = YEAR(CURDATE())
");

// Get expiry timeline data for chart
$expiry_timeline = fetchAll($conn, "
    SELECT 
        DATE_FORMAT(expiry_date, '%Y-%m') as month,
        COUNT(*) as count
    FROM titles
    WHERE title_type = 'leasehold' 
      AND expiry_date IS NOT NULL
      AND expiry_date >= CURDATE()
    GROUP BY DATE_FORMAT(expiry_date, '%Y-%m')
    ORDER BY month
    LIMIT 12
");

// Get leaseholds by state
$by_state = fetchAll($conn, "
    SELECT 
        s.name as state_name,
        COUNT(*) as count,
        SUM(CASE WHEN t.expiry_date < CURDATE() THEN 1 ELSE 0 END) as expired
    FROM titles t
    JOIN parcels p ON t.parcel_id = p.id
    JOIN bomas b ON p.boma_id = b.id
    JOIN payams pa ON b.payam_id = pa.id
    JOIN counties c ON pa.county_id = c.id
    JOIN states s ON c.state_id = s.id
    WHERE t.title_type = 'leasehold'
    GROUP BY s.id, s.name
    ORDER BY count DESC
");

// Get pending rent (if table exists)
$pending_rent = fetchAll($conn, "
    SELECT 
        lr.*,
        t.title_number,
        p.parcel_number,
        pa2.name as owner_name
    FROM leasehold_rent lr
    JOIN titles t ON lr.title_id = t.id
    JOIN parcels p ON t.parcel_id = p.id
    LEFT JOIN ownerships o ON t.id = o.title_id AND o.is_current = 1
    LEFT JOIN parties pa2 ON o.party_id = pa2.id
    WHERE lr.status IN ('pending', 'overdue')
    ORDER BY lr.due_date ASC
");
?>

<body data-page="titles-leasehold" class="titles-leasehold-page">
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
                                <i class="bi bi-calendar-range text-warning me-2"></i>
                                Leasehold Titles Management
                            </h1>
                            <p class="text-muted mb-0">Manage leasehold titles, renewals, and ground rent payments</p>
                        </div>
                        <div>
                            <button class="btn btn-outline-secondary me-2" type="button" data-bs-toggle="collapse" data-bs-target="#filterCollapse">
                                <i class="bi bi-funnel me-2"></i>Filters
                            </button>
                            <a href="titles.php" class="btn btn-outline-primary me-2">
                                <i class="bi bi-file-text"></i> All Titles
                            </a>
                        </div>
                    </div>

                    <!-- Filter Collapse -->
                    <div class="collapse mb-4" id="filterCollapse">
                        <div class="card border-0 shadow-sm">
                            <div class="card-body">
                                <form method="GET" class="row g-3">
                                    <div class="col-md-3">
                                        <label class="form-label">Status</label>
                                        <select class="form-select" name="status">
                                            <option value="">All Statuses</option>
                                            <option value="active" <?php echo (isset($_GET['status']) && $_GET['status'] == 'active') ? 'selected' : ''; ?>>Active</option>
                                            <option value="inactive" <?php echo (isset($_GET['status']) && $_GET['status'] == 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                                            <option value="cancelled" <?php echo (isset($_GET['status']) && $_GET['status'] == 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                                            <option value="surrendered" <?php echo (isset($_GET['status']) && $_GET['status'] == 'surrendered') ? 'selected' : ''; ?>>Surrendered</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Expiry Filter</label>
                                        <select class="form-select" name="expiry_filter">
                                            <option value="">All</option>
                                            <option value="expired" <?php echo (isset($_GET['expiry_filter']) && $_GET['expiry_filter'] == 'expired') ? 'selected' : ''; ?>>Expired</option>
                                            <option value="expiring_30" <?php echo (isset($_GET['expiry_filter']) && $_GET['expiry_filter'] == 'expiring_30') ? 'selected' : ''; ?>>Expiring in 30 days</option>
                                            <option value="expiring_90" <?php echo (isset($_GET['expiry_filter']) && $_GET['expiry_filter'] == 'expiring_90') ? 'selected' : ''; ?>>Expiring in 90 days</option>
                                            <option value="expiring_year" <?php echo (isset($_GET['expiry_filter']) && $_GET['expiry_filter'] == 'expiring_year') ? 'selected' : ''; ?>>Expiring in 1 year</option>
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
                                        <label class="form-label">Search</label>
                                        <div class="input-group">
                                            <input type="text" class="form-control" name="search" placeholder="Title #, Parcel #, Owner..." 
                                                   value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
                                            <button class="btn btn-primary" type="submit">
                                                <i class="bi bi-search"></i>
                                            </button>
                                            <?php if (!empty($_GET)): ?>
                                            <a href="titles-leasehold.php" class="btn btn-outline-secondary">
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
                                            <h6 class="text-muted mb-1">Total Leaseholds</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['total_leaseholds'] ?? 0); ?></h3>
                                            <small class="text-muted">Active: <?php echo number_format($summary['active_leaseholds'] ?? 0); ?></small>
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
                                            <div class="bg-danger bg-opacity-10 rounded-circle p-3">
                                                <i class="bi bi-exclamation-triangle text-danger fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Expired</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['expired_leaseholds'] ?? 0); ?></h3>
                                            <small class="text-danger">Require immediate action</small>
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
                                                <i class="bi bi-clock-history text-warning fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Expiring Soon</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format(($summary['critical_leaseholds'] ?? 0) + ($summary['warning_leaseholds'] ?? 0)); ?></h3>
                                            <small class="text-warning">Critical: <?php echo $summary['critical_leaseholds'] ?? 0; ?></small>
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
                                                <i class="bi bi-cash-stack text-success fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Rent Due</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($rent_summary['pending_rent'] ?? 0); ?></h3>
                                            <small class="text-success">Collected: <?php echo number_format($rent_summary['paid_rent'] ?? 0); ?></small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Expiry Timeline Chart -->
                    <div class="row g-4 mb-5">
                        <div class="col-xl-8">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h5 class="card-title mb-0 fw-bold">
                                        <i class="bi bi-graph-up text-primary me-2"></i>Leasehold Expiry Timeline
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <canvas id="expiryChart" style="height: 300px;"></canvas>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-xl-4">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h5 class="card-title mb-0 fw-bold">
                                        <i class="bi bi-pie-chart text-info me-2"></i>Leaseholds by State
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <canvas id="stateChart" style="height: 300px;"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Pending Rent Section -->
                    <?php if (!empty($pending_rent)): ?>
                    <div class="card border-0 shadow-sm mb-5">
                        <div class="card-header bg-white py-3">
                            <h5 class="card-title mb-0 fw-bold text-warning">
                                <i class="bi bi-cash-stack me-2"></i>Pending Ground Rent Payments
                            </h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>Title #</th>
                                            <th>Parcel #</th>
                                            <th>Owner</th>
                                            <th>Year</th>
                                            <th>Due Date</th>
                                            <th>Amount</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pending_rent as $rent): ?>
                                        <tr class="<?php echo $rent['due_date'] < date('Y-m-d') ? 'table-danger' : ''; ?>">
                                            <td><?php echo htmlspecialchars($rent['title_number']); ?></td>
                                            <td><?php echo htmlspecialchars($rent['parcel_number']); ?></td>
                                            <td><?php echo htmlspecialchars($rent['owner_name'] ?? 'Unknown'); ?></td>
                                            <td><?php echo $rent['rent_year']; ?></td>
                                            <td>
                                                <?php echo date('d M Y', strtotime($rent['due_date'])); ?>
                                                <?php if ($rent['due_date'] < date('Y-m-d')): ?>
                                                <span class="badge bg-danger ms-2">Overdue</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo number_format($rent['rent_amount'], 2); ?> SSP</td>
                                            <td>
                                                <span class="badge bg-<?php echo $rent['status'] == 'overdue' ? 'danger' : 'warning'; ?>">
                                                    <?php echo ucfirst($rent['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <button class="btn btn-sm btn-success" onclick="payRent(<?php echo htmlspecialchars(json_encode($rent)); ?>)" 
                                                        data-bs-toggle="modal" data-bs-target="#payRentModal">
                                                    <i class="bi bi-cash"></i> Record Payment
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Leasehold Titles Table -->
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0 fw-bold">
                                <i class="bi bi-table me-2"></i>Leasehold Titles
                                <span class="badge bg-secondary ms-2"><?php echo count($leaseholds); ?> records</span>
                            </h5>
                            <div>
                                <input type="text" class="form-control form-control-sm" style="width: 250px;" id="tableSearch" placeholder="Search in table...">
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0" id="leaseholdTable">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>Title #</th>
                                            <th>Parcel #</th>
                                            <th>Location</th>
                                            <th>Owner</th>
                                            <th>Issue Date</th>
                                            <th>Expiry Date</th>
                                            <th>Term Remaining</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($leaseholds)): ?>
                                        <tr>
                                            <td colspan="9" class="text-center py-4 text-muted">
                                                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                                No leasehold titles found
                                            </td>
                                        </tr>
                                        <?php else: ?>
                                            <?php foreach ($leaseholds as $title): ?>
                                            <?php 
                                            $rowClass = '';
                                            if ($title['expiry_status'] == 'Expired') $rowClass = 'table-danger';
                                            elseif ($title['expiry_status'] == 'Critical') $rowClass = 'table-warning';
                                            elseif ($title['expiry_status'] == 'Warning') $rowClass = 'table-info';
                                            ?>
                                            <tr class="<?php echo $rowClass; ?>">
                                                <td>
                                                    <strong><?php echo htmlspecialchars($title['title_number']); ?></strong>
                                                </td>
                                                <td><?php echo htmlspecialchars($title['parcel_number']); ?></td>
                                                <td>
                                                    <?php echo htmlspecialchars($title['boma_name']); ?>, <?php echo htmlspecialchars($title['payam_name']); ?>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars($title['county_name']); ?></small>
                                                </td>
                                                <td>
                                                    <?php if ($title['owner_name']): ?>
                                                        <?php echo htmlspecialchars($title['owner_name']); ?>
                                                        <br><small class="text-muted"><?php echo ucfirst($title['owner_type']); ?></small>
                                                    <?php else: ?>
                                                        <span class="text-muted">Unknown</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo date('d M Y', strtotime($title['issue_date'])); ?></td>
                                                <td>
                                                    <strong><?php echo date('d M Y', strtotime($title['expiry_date'])); ?></strong>
                                                </td>
                                                <td>
                                                    <?php if ($title['days_remaining'] > 0): ?>
                                                        <div class="d-flex align-items-center">
                                                            <div class="progress flex-grow-1 me-2" style="height: 5px; width: 80px;">
                                                                <?php 
                                                                $total_term = (strtotime($title['expiry_date']) - strtotime($title['issue_date'])) / (60 * 60 * 24);
                                                                $percent = min(100, ($title['days_remaining'] / $total_term) * 100);
                                                                ?>
                                                                <div class="progress-bar bg-<?php 
                                                                    echo $percent > 50 ? 'success' : ($percent > 20 ? 'warning' : 'danger'); 
                                                                ?>" style="width: <?php echo $percent; ?>%"></div>
                                                            </div>
                                                            <span class="small"><?php echo $title['days_remaining']; ?> days</span>
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="text-danger">Expired</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php
                                                    $badgeClass = 'success';
                                                    if ($title['expiry_status'] == 'Expired') $badgeClass = 'danger';
                                                    elseif ($title['expiry_status'] == 'Critical') $badgeClass = 'warning';
                                                    elseif ($title['expiry_status'] == 'Warning') $badgeClass = 'info';
                                                    ?>
                                                    <span class="badge bg-<?php echo $badgeClass; ?>">
                                                        <?php echo $title['expiry_status']; ?>
                                                    </span>
                                                    <?php if ($title['pending_rent'] > 0): ?>
                                                    <br><span class="badge bg-warning mt-1"><?php echo $title['pending_rent']; ?> rent due</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="btn-group">
                                                        <button class="btn btn-sm btn-outline-primary" 
                                                                onclick="viewLeasehold(<?php echo htmlspecialchars(json_encode($title)); ?>)"
                                                                data-bs-toggle="modal" data-bs-target="#viewLeaseholdModal">
                                                            <i class="bi bi-eye"></i>
                                                        </button>
                                                        <?php if ($title['status'] == 'active' && $title['expiry_date'] >= date('Y-m-d')): ?>
                                                        <button class="btn btn-sm btn-outline-success" 
                                                                onclick="renewLeasehold(<?php echo htmlspecialchars(json_encode($title)); ?>)"
                                                                data-bs-toggle="modal" data-bs-target="#renewLeaseholdModal">
                                                            <i class="bi bi-arrow-repeat"></i>
                                                        </button>
                                                        <?php endif; ?>
                                                        <button class="btn btn-sm btn-outline-warning" 
                                                                onclick="calculateRent(<?php echo htmlspecialchars(json_encode($title)); ?>)"
                                                                data-bs-toggle="modal" data-bs-target="#calculateRentModal">
                                                            <i class="bi bi-calculator"></i>
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

    <!-- View Leasehold Modal -->
    <div class="modal fade" id="viewLeaseholdModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Leasehold Title Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6 class="border-bottom pb-2">Title Information</h6>
                            <table class="table table-borderless">
                                <tr>
                                    <th width="40%">Title Number:</th>
                                    <td><strong id="viewTitleNumber"></strong></td>
                                </tr>
                                <tr>
                                    <th>Issue Date:</th>
                                    <td id="viewIssueDate"></td>
                                </tr>
                                <tr>
                                    <th>Expiry Date:</th>
                                    <td id="viewExpiryDate"></td>
                                </tr>
                                <tr>
                                    <th>Term Remaining:</th>
                                    <td id="viewTermRemaining"></td>
                                </tr>
                                <tr>
                                    <th>Status:</th>
                                    <td id="viewStatus"></td>
                                </tr>
                            </table>
                        </div>
                        
                        <div class="col-md-6">
                            <h6 class="border-bottom pb-2">Parcel Information</h6>
                            <table class="table table-borderless">
                                <tr>
                                    <th width="40%">Parcel Number:</th>
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
                    </div>
                    
                    <div class="row mt-3">
                        <div class="col-12">
                            <h6 class="border-bottom pb-2">Current Owner</h6>
                            <table class="table table-borderless">
                                <tr>
                                    <th width="15%">Name:</th>
                                    <td id="viewOwnerName"></td>
                                    <th width="15%">Contact:</th>
                                    <td id="viewOwnerContact"></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    
                    <div class="row mt-3">
                        <div class="col-12">
                            <h6 class="border-bottom pb-2">Rent Information</h6>
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="border rounded p-2 text-center">
                                        <small class="text-muted d-block">Total Rent Entries</small>
                                        <span class="fw-bold" id="viewTotalRent">0</span>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="border rounded p-2 text-center">
                                        <small class="text-muted d-block">Pending Rent</small>
                                        <span class="fw-bold" id="viewPendingRent">0</span>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="border rounded p-2 text-center">
                                        <small class="text-muted d-block">Total Paid</small>
                                        <span class="fw-bold" id="viewTotalPaid">0 SSP</span>
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

    <!-- Renew Leasehold Modal -->
    <div class="modal fade" id="renewLeaseholdModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="renew">
                    <input type="hidden" name="title_id" id="renewTitleId">
                    <div class="modal-header">
                        <h5 class="modal-title">Renew Leasehold</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Title Number</label>
                            <input type="text" class="form-control" id="renewTitleNumber" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Current Expiry Date</label>
                            <input type="text" class="form-control" id="renewCurrentExpiry" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">New Expiry Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="new_expiry_date" required 
                                   min="<?php echo date('Y-m-d', strtotime('+1 year')); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Renewal Fee (SSP)</label>
                            <input type="number" class="form-control" name="renewal_fee" step="0.01" 
                                   placeholder="0.00">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Terms & Conditions</label>
                            <textarea class="form-control" name="terms_conditions" rows="3" 
                                      placeholder="Enter renewal terms and conditions..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Renew Leasehold</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Calculate Rent Modal -->
    <div class="modal fade" id="calculateRentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="calculate_rent">
                    <input type="hidden" name="title_id" id="rentTitleId">
                    <div class="modal-header">
                        <h5 class="modal-title">Calculate Ground Rent</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Title Number</label>
                            <input type="text" class="form-control" id="rentTitleNumber" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Rent Year <span class="text-danger">*</span></label>
                            <select class="form-select" name="rent_year" required>
                                <option value="">Select Year</option>
                                <?php for ($year = date('Y'); $year <= date('Y') + 5; $year++): ?>
                                <option value="<?php echo $year; ?>"><?php echo $year; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Rent Amount (SSP) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="rent_amount" step="0.01" required 
                                   placeholder="e.g., 5000.00">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Due Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="due_date" required 
                                   value="<?php echo date('Y-m-d', strtotime('first day of January next year')); ?>">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Calculate Rent</button>
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
                    <input type="hidden" name="action" value="pay_rent">
                    <input type="hidden" name="rent_id" id="payRentId">
                    <div class="modal-header">
                        <h5 class="modal-title">Record Rent Payment</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Title Number</label>
                            <input type="text" class="form-control" id="payRentTitle" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Year</label>
                            <input type="text" class="form-control" id="payRentYear" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Amount Due</label>
                            <input type="text" class="form-control" id="payRentAmount" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Payment Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="payment_date" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Amount Paid <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="amount_paid" step="0.01" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Payment Method</label>
                            <select class="form-select" name="payment_method">
                                <option value="cash">Cash</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="check">Check</option>
                                <option value="mobile_money">Mobile Money</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Reference Number</label>
                            <input type="text" class="form-control" name="reference" placeholder="Transaction reference">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Receipt Number</label>
                            <input type="text" class="form-control" name="receipt_number" placeholder="Receipt #">
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

    <!-- Charts Script -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <script>
        // Initialize charts
        document.addEventListener('DOMContentLoaded', function() {
            // Expiry Timeline Chart
            const expiryCtx = document.getElementById('expiryChart')?.getContext('2d');
            if (expiryCtx) {
                new Chart(expiryCtx, {
                    type: 'line',
                    data: {
                        labels: [
                            <?php foreach ($expiry_timeline as $item): ?>
                            '<?php echo date('M Y', strtotime($item['month'] . '-01')); ?>',
                            <?php endforeach; ?>
                        ],
                        datasets: [{
                            label: 'Titles Expiring',
                            data: [
                                <?php foreach ($expiry_timeline as $item): ?>
                                <?php echo $item['count']; ?>,
                                <?php endforeach; ?>
                            ],
                            borderColor: '#dc3545',
                            backgroundColor: 'rgba(220, 53, 69, 0.1)',
                            tension: 0.4,
                            fill: true
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
                    type: 'doughnut',
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
        });
        
        // View leasehold details
        function viewLeasehold(title) {
            document.getElementById('viewTitleNumber').textContent = title.title_number || 'N/A';
            document.getElementById('viewIssueDate').textContent = title.issue_date ? new Date(title.issue_date).toLocaleDateString() : 'N/A';
            document.getElementById('viewExpiryDate').textContent = title.expiry_date ? new Date(title.expiry_date).toLocaleDateString() : 'N/A';
            
            let termText = 'N/A';
            if (title.days_remaining) {
                if (title.days_remaining > 0) {
                    termText = title.days_remaining + ' days (' + Math.round(title.days_remaining/365.25*10)/10 + ' years)';
                } else {
                    termText = 'Expired';
                }
            }
            document.getElementById('viewTermRemaining').textContent = termText;
            
            let statusClass = 'success';
            if (title.expiry_status == 'Expired') statusClass = 'danger';
            else if (title.expiry_status == 'Critical') statusClass = 'warning';
            else if (title.expiry_status == 'Warning') statusClass = 'info';
            document.getElementById('viewStatus').innerHTML = `<span class="badge bg-${statusClass}">${title.expiry_status}</span>`;
            
            document.getElementById('viewParcelNumber').textContent = title.parcel_number || 'N/A';
            document.getElementById('viewParcelArea').textContent = title.parcel_area ? title.parcel_area.toFixed(2) + ' m²' : 'N/A';
            document.getElementById('viewLocation').textContent = title.boma_name + ', ' + title.payam_name + ', ' + title.county_name;
            
            document.getElementById('viewOwnerName').textContent = title.owner_name || 'Unknown';
            document.getElementById('viewOwnerContact').textContent = (title.owner_phone || title.owner_email) || 'N/A';
            
            document.getElementById('viewTotalRent').textContent = title.total_rent_entries || 0;
            document.getElementById('viewPendingRent').textContent = title.pending_rent || 0;
            document.getElementById('viewTotalPaid').textContent = title.total_rent_paid ? number_format(title.total_rent_paid, 2) + ' SSP' : '0 SSP';
        }
        
        // Renew leasehold
        function renewLeasehold(title) {
            document.getElementById('renewTitleId').value = title.id;
            document.getElementById('renewTitleNumber').value = title.title_number || '';
            document.getElementById('renewCurrentExpiry').value = title.expiry_date ? new Date(title.expiry_date).toLocaleDateString() : 'N/A';
        }
        
        // Calculate rent
        function calculateRent(title) {
            document.getElementById('rentTitleId').value = title.id;
            document.getElementById('rentTitleNumber').value = title.title_number || '';
        }
        
        // Pay rent
        function payRent(rent) {
            document.getElementById('payRentId').value = rent.id;
            document.getElementById('payRentTitle').value = rent.title_number || '';
            document.getElementById('payRentYear').value = rent.rent_year || '';
            document.getElementById('payRentAmount').value = number_format(rent.rent_amount, 2) + ' SSP';
        }
        
        // Number format helper
        function number_format(num, decimals) {
            return num.toFixed(decimals).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        }
        
        // Search functionality
        document.getElementById('tableSearch')?.addEventListener('keyup', function() {
            const searchText = this.value.toLowerCase();
            const table = document.getElementById('leaseholdTable');
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
        .table-danger {
            background-color: #f8d7da;
        }
        .table-warning {
            background-color: #fff3cd;
        }
        .table-info {
            background-color: #d1ecf1;
        }
        .progress {
            background-color: #e9ecef;
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