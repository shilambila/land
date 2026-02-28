<?php
ini_set('display_errors', 1); 
error_reporting(E_ALL);

// tax.php
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

// Create new tax assessment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_assessment') {
    
    $parcel_id = $_POST['parcel_id'];
    $tax_year = $_POST['tax_year'];
    $assessed_value = $_POST['assessed_value'];
    $tax_amount = $_POST['tax_amount'];
    $due_date = $_POST['due_date'];
    $notes = $_POST['notes'] ?? null;
    
    try {
        // Check if assessment already exists for this parcel and year
        $existing = safeFetchOne($conn, "
            SELECT id FROM tax_assessments 
            WHERE parcel_id = ? AND tax_year = ?
        ", [$parcel_id, $tax_year]);
        
        if ($existing) {
            throw new Exception("Tax assessment for this parcel and year already exists");
        }
        
        executeQuery($conn, "
            INSERT INTO tax_assessments (
                parcel_id, tax_year, assessed_value, tax_amount, due_date, status, created_at
            ) VALUES (
                ?, ?, ?, ?, ?, 'pending', NOW()
            )
        ", [$parcel_id, $tax_year, $assessed_value, $tax_amount, $due_date]);
        
        $message = "Tax assessment created successfully";
        $messageType = "success";
        
    } catch (Exception $e) {
        $message = "Error creating assessment: " . $e->getMessage();
        $messageType = "danger";
    }
}

// Update assessment status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_assessment') {
    
    $assessment_id = $_POST['assessment_id'];
    $status = $_POST['status'];
    
    try {
        executeQuery($conn, "
            UPDATE tax_assessments 
            SET status = ?, updated_at = NOW()
            WHERE id = ?
        ", [$status, $assessment_id]);
        
        $message = "Assessment status updated successfully";
        $messageType = "success";
        
    } catch (Exception $e) {
        $message = "Error updating assessment: " . $e->getMessage();
        $messageType = "danger";
    }
}

// Record tax payment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'record_payment') {
    
    $assessment_id = $_POST['assessment_id'];
    $amount_paid = $_POST['amount_paid'];
    $payment_date = $_POST['payment_date'];
    $payment_method = $_POST['payment_method'];
    $reference = $_POST['reference'] ?? null;
    
    try {
        // Start transaction
        $conn->beginTransaction();
        
        // Get assessment details
        $assessment = safeFetchOne($conn, "
            SELECT * FROM tax_assessments WHERE id = ?
        ", [$assessment_id]);
        
        if (!$assessment) {
            throw new Exception("Assessment not found");
        }
        
        // Generate receipt number
        $receipt_number = 'TAX/' . date('Y') . '/' . str_pad($assessment_id, 5, '0', STR_PAD_LEFT);
        
        // Record payment
        executeQuery($conn, "
            INSERT INTO tax_payments (
                tax_assessment_id, payment_date, amount_paid, receipt_number,
                payment_method, reference, status, created_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?, 'completed', NOW()
            )
        ", [$assessment_id, $payment_date, $amount_paid, $receipt_number, $payment_method, $reference]);
        
        // Update assessment status
        $total_paid = safeFetchOne($conn, "
            SELECT SUM(amount_paid) as total FROM tax_payments WHERE tax_assessment_id = ?
        ", [$assessment_id]);
        
        $total_paid_amount = $total_paid ? $total_paid['total'] : $amount_paid;
        $new_status = ($total_paid_amount >= $assessment['tax_amount']) ? 'paid' : 'pending';
        
        executeQuery($conn, "
            UPDATE tax_assessments 
            SET status = ?, updated_at = NOW() 
            WHERE id = ?
        ", [$new_status, $assessment_id]);
        
        $conn->commit();
        
        $message = "Tax payment recorded successfully. Receipt: $receipt_number";
        $messageType = "success";
        
    } catch (Exception $e) {
        $conn->rollBack();
        $message = "Error recording payment: " . $e->getMessage();
        $messageType = "danger";
    }
}

// ============================================================================
// DETERMINE ACTIVE VIEW
// ============================================================================

$active_view = 'assessments';
$view_param = '';

if (isset($_GET['id'])) {
    $view_param = $_GET['id'];
    if (in_array($view_param, ['assessments', 'payments', 'overdue', 'reports'])) {
        $active_view = $view_param;
    }
}

// ============================================================================
// GET FILTERS
// ============================================================================

$year_filter = $_GET['year'] ?? date('Y');
$parcel_filter = $_GET['parcel_id'] ?? '';
$status_filter = $_GET['status'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// ============================================================================
// GET TAX ASSESSMENTS
// ============================================================================

$assessments = [];
if ($active_view == 'assessments' || $active_view == 'overdue') {
    
    $where_conditions = [];
    $params = [];
    
    // Apply year filter
    if (!empty($year_filter)) {
        $where_conditions[] = "ta.tax_year = ?";
        $params[] = $year_filter;
    }
    
    // Apply parcel filter
    if (!empty($parcel_filter)) {
        $where_conditions[] = "ta.parcel_id = ?";
        $params[] = $parcel_filter;
    }
    
    // Apply status filter
    if (!empty($status_filter)) {
        $where_conditions[] = "ta.status = ?";
        $params[] = $status_filter;
    }
    
    // For overdue view, filter by overdue status and past due date
    if ($active_view == 'overdue') {
        $where_conditions[] = "ta.status IN ('pending')";
        $where_conditions[] = "ta.due_date < CURDATE()";
    }
    
    $where_clause = empty($where_conditions) ? "" : "WHERE " . implode(" AND ", $where_conditions);
    
    // Fix: Use LEFT JOIN for ownerships and parties to avoid missing data
    $assessments = safeFetchAll($conn, "
        SELECT 
            ta.*,
            p.parcel_number,
            p.area,
            b.name as boma_name,
            py.name as payam_name,
            c.name as county_name,
            s.name as state_name,
            -- Get owner info with LEFT JOIN
            o.party_id as owner_id,
            pt.name as owner_name,
            -- Get payment info with subquery
            (SELECT SUM(amount_paid) FROM tax_payments WHERE tax_assessment_id = ta.id) as total_paid,
            (SELECT COUNT(*) FROM tax_payments WHERE tax_assessment_id = ta.id) as payment_count,
            (SELECT MAX(payment_date) FROM tax_payments WHERE tax_assessment_id = ta.id) as last_payment_date,
            -- Calculate days overdue
            DATEDIFF(CURDATE(), ta.due_date) as days_overdue
        FROM tax_assessments ta
        JOIN parcels p ON ta.parcel_id = p.id
        LEFT JOIN bomas b ON p.boma_id = b.id
        LEFT JOIN payams py ON b.payam_id = py.id
        LEFT JOIN counties c ON py.county_id = c.id
        LEFT JOIN states s ON c.state_id = s.id
        LEFT JOIN ownerships o ON p.id = o.parcel_id AND (o.is_current = 1 OR o.is_current IS NULL)
        LEFT JOIN parties pt ON o.party_id = pt.id
        $where_clause
        ORDER BY 
            CASE 
                WHEN ta.due_date < CURDATE() AND ta.status = 'pending' THEN 0
                ELSE 1
            END,
            ta.due_date ASC
    ", $params, []);
}

// ============================================================================
// GET TAX PAYMENTS
// ============================================================================

$payments = [];
if ($active_view == 'payments') {
    
    $where_conditions = [];
    $params = [];
    
    // Apply date range
    if (!empty($date_from)) {
        $where_conditions[] = "tp.payment_date >= ?";
        $params[] = $date_from;
    }
    
    if (!empty($date_to)) {
        $where_conditions[] = "tp.payment_date <= ?";
        $params[] = $date_to;
    }
    
    // Apply parcel filter
    if (!empty($parcel_filter)) {
        $where_conditions[] = "p.id = ?";
        $params[] = $parcel_filter;
    }
    
    // Apply year filter
    if (!empty($year_filter)) {
        $where_conditions[] = "ta.tax_year = ?";
        $params[] = $year_filter;
    }
    
    $where_clause = empty($where_conditions) ? "" : "WHERE " . implode(" AND ", $where_conditions);
    
    $payments = safeFetchAll($conn, "
        SELECT 
            tp.*,
            ta.tax_year,
            ta.tax_amount as assessed_amount,
            ta.due_date,
            p.parcel_number,
            p.area,
            b.name as boma_name
        FROM tax_payments tp
        JOIN tax_assessments ta ON tp.tax_assessment_id = ta.id
        JOIN parcels p ON ta.parcel_id = p.id
        LEFT JOIN bomas b ON p.boma_id = b.id
        $where_clause
        ORDER BY tp.payment_date DESC
    ", $params, []);
}

// ============================================================================
// GET REPORT DATA
// ============================================================================

$report_summary = [];

if ($active_view == 'reports') {
    
    // Summary by year
    $report_summary['by_year'] = safeFetchAll($conn, "
        SELECT 
            tax_year,
            COUNT(*) as total_assessments,
            SUM(tax_amount) as total_assessed,
            SUM(CASE WHEN status = 'paid' THEN tax_amount ELSE 0 END) as total_collected,
            SUM(CASE WHEN status = 'pending' AND due_date < CURDATE() THEN 1 ELSE 0 END) as overdue_count,
            SUM(CASE WHEN status = 'pending' AND due_date < CURDATE() THEN tax_amount ELSE 0 END) as overdue_amount,
            COUNT(DISTINCT parcel_id) as parcels_assessed
        FROM tax_assessments
        GROUP BY tax_year
        ORDER BY tax_year DESC
    ", [], []);
    
    // Collection rate by month - Fix: Use DATE_FORMAT for month grouping
    $report_summary['monthly'] = safeFetchAll($conn, "
        SELECT 
            DATE_FORMAT(payment_date, '%Y-%m') as month,
            COUNT(*) as payment_count,
            SUM(amount_paid) as total_collected,
            COUNT(DISTINCT tax_assessment_id) as assessments_paid
        FROM tax_payments
        WHERE payment_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(payment_date, '%Y-%m')
        ORDER BY month DESC
    ", [], []);
    
    // Top defaulters - Fix: Simplified query without complex subqueries
    $report_summary['defaulters'] = safeFetchAll($conn, "
        SELECT 
            p.id as parcel_id,
            p.parcel_number,
            pt.name as owner_name,
            pt.phone,
            COUNT(ta.id) as overdue_count,
            SUM(ta.tax_amount - IFNULL((SELECT SUM(amount_paid) FROM tax_payments WHERE tax_assessment_id = ta.id), 0)) as total_due
        FROM tax_assessments ta
        JOIN parcels p ON ta.parcel_id = p.id
        LEFT JOIN ownerships o ON p.id = o.parcel_id AND (o.is_current = 1 OR o.is_current IS NULL)
        LEFT JOIN parties pt ON o.party_id = pt.id
        WHERE ta.status = 'pending' AND ta.due_date < CURDATE()
        GROUP BY p.id, p.parcel_number, pt.name, pt.phone
        HAVING total_due > 0
        ORDER BY total_due DESC
        LIMIT 10
    ", [], []);
    
    // Performance by county - Fix: Simplified and using correct joins
    $report_summary['by_county'] = safeFetchAll($conn, "
        SELECT 
            s.name as state_name,
            c.name as county_name,
            COUNT(DISTINCT p.id) as total_parcels,
            COUNT(ta.id) as total_assessments,
            COALESCE(SUM(ta.tax_amount), 0) as total_assessed,
            COALESCE(SUM(tp.amount_paid), 0) as total_collected,
            CASE 
                WHEN SUM(ta.tax_amount) > 0 
                THEN ROUND((COALESCE(SUM(tp.amount_paid), 0) / SUM(ta.tax_amount)) * 100, 2)
                ELSE 0 
            END as collection_rate
        FROM counties c
        JOIN states s ON c.state_id = s.id
        LEFT JOIN payams py ON c.id = py.county_id
        LEFT JOIN bomas b ON py.id = b.payam_id
        LEFT JOIN parcels p ON b.id = p.boma_id
        LEFT JOIN tax_assessments ta ON p.id = ta.parcel_id AND ta.tax_year = ?
        LEFT JOIN tax_payments tp ON ta.id = tp.tax_assessment_id
        WHERE ta.id IS NOT NULL
        GROUP BY s.id, s.name, c.id, c.name
        ORDER BY collection_rate DESC
    ", [$year_filter], []);
}

// ============================================================================
// GET DROPDOWN DATA
// ============================================================================

// Get all parcels for dropdown
$parcels = safeFetchAll($conn, "
    SELECT 
        p.id, p.parcel_number, 
        b.name as boma_name,
        pt.name as owner_name
    FROM parcels p
    LEFT JOIN bomas b ON p.boma_id = b.id
    LEFT JOIN ownerships o ON p.id = o.parcel_id AND (o.is_current = 1 OR o.is_current IS NULL)
    LEFT JOIN parties pt ON o.party_id = pt.id
    ORDER BY p.parcel_number
", [], []);

// Get available years
$years = safeFetchAll($conn, "
    SELECT DISTINCT tax_year 
    FROM tax_assessments 
    ORDER BY tax_year DESC
", [], []);

// Get statuses
$statuses = [
    'pending' => ['label' => 'Pending', 'class' => 'warning', 'icon' => 'hourglass-split'],
    'paid' => ['label' => 'Paid', 'class' => 'success', 'icon' => 'check-circle'],
    'overdue' => ['label' => 'Overdue', 'class' => 'danger', 'icon' => 'exclamation-triangle'],
    'exempt' => ['label' => 'Exempt', 'class' => 'secondary', 'icon' => 'shield-check']
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

<body data-page="tax" class="tax-page">
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
                            <h1 class="h3 mb-0">Property Tax Management</h1>
                            <p class="text-muted mb-0">Manage tax assessments, payments, and reporting</p>
                        </div>
                        <div class="btn-group">
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createAssessmentModal">
                                <i class="bi bi-plus-circle me-2"></i>New Assessment
                            </button>
                            <button class="btn btn-outline-secondary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown">
                                <span class="visually-hidden">Toggle Dropdown</span>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="#" onclick="exportReport()">
                                    <i class="bi bi-download me-2"></i>Export Report
                                </a></li>
                            </ul>
                        </div>
                    </div>

                    <!-- Alert Message -->
                    <?php if ($message): ?>
                    <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show mb-4" role="alert">
                        <?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    <?php endif; ?>

                    <!-- Navigation Tabs -->
                    <ul class="nav nav-tabs mb-4">
                        <li class="nav-item">
                            <a class="nav-link <?php echo $active_view == 'assessments' ? 'active' : ''; ?>" 
                               href="tax.php?id=assessments">
                                <i class="bi bi-list-check me-2"></i>Assessments
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $active_view == 'payments' ? 'active' : ''; ?>" 
                               href="tax.php?id=payments">
                                <i class="bi bi-credit-card me-2"></i>Payments
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $active_view == 'overdue' ? 'active' : ''; ?>" 
                               href="tax.php?id=overdue">
                                <i class="bi bi-exclamation-triangle me-2 text-danger"></i>Overdue
                                <?php 
                                $overdue_count = safeFetchOne($conn, "
                                    SELECT COUNT(*) as count FROM tax_assessments 
                                    WHERE status = 'pending' AND due_date < CURDATE()
                                ");
                                if ($overdue_count && $overdue_count['count'] > 0): ?>
                                <span class="badge bg-danger ms-2"><?php echo $overdue_count['count']; ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $active_view == 'reports' ? 'active' : ''; ?>" 
                               href="tax.php?id=reports">
                                <i class="bi bi-bar-chart me-2"></i>Reports
                            </a>
                        </li>
                    </ul>

                    <!-- Filters Bar -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-body">
                            <form method="GET" class="row g-3">
                                <input type="hidden" name="id" value="<?php echo $active_view; ?>">
                                
                                <?php if ($active_view == 'assessments' || $active_view == 'overdue' || $active_view == 'payments'): ?>
                                <div class="col-md-3">
                                    <label class="form-label">Tax Year</label>
                                    <select class="form-select" name="year">
                                        <option value="">All Years</option>
                                        <?php foreach ($years as $y): ?>
                                        <option value="<?php echo $y['tax_year']; ?>" <?php echo $year_filter == $y['tax_year'] ? 'selected' : ''; ?>>
                                            <?php echo $y['tax_year']; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-3">
                                    <label class="form-label">Parcel</label>
                                    <select class="form-select" name="parcel_id">
                                        <option value="">All Parcels</option>
                                        <?php foreach ($parcels as $p): ?>
                                        <option value="<?php echo $p['id']; ?>" <?php echo $parcel_filter == $p['id'] ? 'selected' : ''; ?>>
                                            <?php echo $p['parcel_number']; ?> - <?php echo htmlspecialchars($p['boma_name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($active_view == 'assessments'): ?>
                                <div class="col-md-3">
                                    <label class="form-label">Status</label>
                                    <select class="form-select" name="status">
                                        <option value="">All Statuses</option>
                                        <?php foreach ($statuses as $key => $info): ?>
                                        <option value="<?php echo $key; ?>" <?php echo $status_filter == $key ? 'selected' : ''; ?>>
                                            <?php echo $info['label']; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($active_view == 'payments'): ?>
                                <div class="col-md-2">
                                    <label class="form-label">Date From</label>
                                    <input type="date" class="form-control" name="date_from" value="<?php echo $date_from; ?>">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Date To</label>
                                    <input type="date" class="form-control" name="date_to" value="<?php echo $date_to; ?>">
                                </div>
                                <?php endif; ?>
                                
                                <div class="col-md-2 d-flex align-items-end">
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="bi bi-search me-2"></i>Apply
                                    </button>
                                </div>
                                
                                <div class="col-md-2 d-flex align-items-end">
                                    <a href="tax.php?id=<?php echo $active_view; ?>" class="btn btn-secondary w-100">
                                        <i class="bi bi-x-circle me-2"></i>Clear
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Assessments View -->
                    <?php if ($active_view == 'assessments' || $active_view == 'overdue'): ?>
                    
                    <?php if (!empty($assessments)): 
                    $total_assessed = array_sum(array_column($assessments, 'tax_amount'));
                    $total_paid = array_sum(array_column($assessments, 'total_paid'));
                    $outstanding = $total_assessed - $total_paid;
                    ?>
                    <!-- Summary Cards for Assessments -->
                    <div class="row g-4 mb-4">
                        <div class="col-md-4">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0 me-3">
                                            <div class="bg-primary bg-opacity-10 rounded-circle p-3">
                                                <i class="bi bi-calculator text-primary fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Total Assessed</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($total_assessed, 2); ?> SSP</h3>
                                            <small class="text-muted"><?php echo count($assessments); ?> assessments</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-4">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0 me-3">
                                            <div class="bg-success bg-opacity-10 rounded-circle p-3">
                                                <i class="bi bi-cash-stack text-success fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Total Paid</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($total_paid, 2); ?> SSP</h3>
                                            <small class="text-muted">Collection rate: <?php echo $total_assessed > 0 ? round(($total_paid / $total_assessed) * 100, 1) : 0; ?>%</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-4">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0 me-3">
                                            <div class="bg-warning bg-opacity-10 rounded-circle p-3">
                                                <i class="bi bi-clock-history text-warning fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Outstanding</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($outstanding, 2); ?> SSP</h3>
                                            <small class="text-muted">Pending collection</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Assessments Table -->
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white py-3">
                            <h5 class="card-title mb-0 fw-bold">
                                <i class="bi bi-table me-2"></i>
                                <?php echo $active_view == 'overdue' ? 'Overdue Assessments' : 'Tax Assessments'; ?>
                            </h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>Parcel</th>
                                            <th>Owner</th>
                                            <th>Location</th>
                                            <th>Year</th>
                                            <th>Assessed</th>
                                            <th>Paid</th>
                                            <th>Balance</th>
                                            <th>Due Date</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($assessments)): ?>
                                        <tr>
                                            <td colspan="10" class="text-center py-4 text-muted">
                                                <i class="bi bi-inbox fs-1 d-block mb-3"></i>
                                                No assessments found
                                            </td>
                                        </tr>
                                        <?php else: ?>
                                            <?php foreach ($assessments as $a): ?>
                                            <?php 
                                            $balance = $a['tax_amount'] - ($a['total_paid'] ?? 0);
                                            $is_overdue = strtotime($a['due_date']) < time() && $a['status'] == 'pending';
                                            ?>
                                            <tr class="<?php echo $is_overdue ? 'table-danger' : ''; ?>">
                                                <td>
                                                    <strong><?php echo $a['parcel_number']; ?></strong>
                                                    <br><small class="text-muted">Area: <?php echo number_format($a['area'], 2); ?> m²</small>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars($a['owner_name'] ?? 'Unknown'); ?>
                                                </td>
                                                <td>
                                                    <small><?php echo $a['boma_name'] ?? ''; ?>, <?php echo $a['payam_name'] ?? ''; ?></small>
                                                </td>
                                                <td><?php echo $a['tax_year']; ?></td>
                                                <td><?php echo number_format($a['tax_amount'], 2); ?> SSP</td>
                                                <td><?php echo number_format($a['total_paid'] ?? 0, 2); ?> SSP</td>
                                                <td>
                                                    <span class="fw-bold <?php echo $balance > 0 ? 'text-danger' : 'text-success'; ?>">
                                                        <?php echo number_format($balance, 2); ?> SSP
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php echo date('d/m/Y', strtotime($a['due_date'])); ?>
                                                    <?php if ($is_overdue): ?>
                                                    <br><small class="text-danger"><?php echo $a['days_overdue']; ?> days overdue</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php
                                                    $status_key = $a['status'];
                                                    if ($is_overdue) {
                                                        $status_key = 'overdue';
                                                    }
                                                    $status = $statuses[$status_key] ?? ['label' => $a['status'], 'class' => 'secondary', 'icon' => 'question'];
                                                    ?>
                                                    <span class="badge bg-<?php echo $status['class']; ?>">
                                                        <i class="bi bi-<?php echo $status['icon']; ?> me-1"></i>
                                                        <?php echo $status['label']; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <button class="btn btn-outline-primary" 
                                                                onclick="viewAssessment(<?php echo htmlspecialchars(json_encode($a)); ?>)"
                                                                data-bs-toggle="modal" data-bs-target="#viewAssessmentModal">
                                                            <i class="bi bi-eye"></i>
                                                        </button>
                                                        
                                                        <?php if ($balance > 0): ?>
                                                        <button class="btn btn-outline-success" 
                                                                onclick="recordPayment(<?php echo $a['id']; ?>, '<?php echo $a['parcel_number']; ?>', <?php echo $balance; ?>)"
                                                                data-bs-toggle="modal" data-bs-target="#paymentModal">
                                                            <i class="bi bi-cash"></i>
                                                        </button>
                                                        <?php endif; ?>
                                                        
                                                        <button class="btn btn-outline-warning" 
                                                                onclick="updateAssessment(<?php echo $a['id']; ?>, '<?php echo $a['parcel_number']; ?>', '<?php echo $a['status']; ?>')"
                                                                data-bs-toggle="modal" data-bs-target="#updateAssessmentModal">
                                                            <i class="bi bi-pencil"></i>
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

                    <?php endif; ?>

                    <!-- Payments View -->
                    <?php if ($active_view == 'payments'): ?>
                    
                    <?php if (!empty($payments)): 
                    $total_payments = array_sum(array_column($payments, 'amount_paid'));
                    ?>
                    <!-- Payments Summary -->
                    <div class="row g-4 mb-4">
                        <div class="col-md-6">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0 me-3">
                                            <div class="bg-success bg-opacity-10 rounded-circle p-3">
                                                <i class="bi bi-cash-stack text-success fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Total Collections</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($total_payments, 2); ?> SSP</h3>
                                            <small class="text-muted"><?php echo count($payments); ?> payments</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0 me-3">
                                            <div class="bg-info bg-opacity-10 rounded-circle p-3">
                                                <i class="bi bi-calendar-check text-info fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Average Payment</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo count($payments) > 0 ? number_format($total_payments / count($payments), 2) : 0; ?> SSP</h3>
                                            <small class="text-muted">Per transaction</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Payments Table -->
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white py-3">
                            <h5 class="card-title mb-0 fw-bold">
                                <i class="bi bi-credit-card me-2"></i>Tax Payment History
                            </h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>Receipt #</th>
                                            <th>Date</th>
                                            <th>Parcel</th>
                                            <th>Year</th>
                                            <th>Amount</th>
                                            <th>Method</th>
                                            <th>Reference</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($payments)): ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-4 text-muted">
                                                <i class="bi bi-inbox fs-1 d-block mb-3"></i>
                                                No payments found
                                            </td>
                                        </tr>
                                        <?php else: ?>
                                            <?php foreach ($payments as $p): ?>
                                            <tr>
                                                <td>
                                                    <code><?php echo $p['receipt_number']; ?></code>
                                                </td>
                                                <td><?php echo date('d/m/Y', strtotime($p['payment_date'])); ?></td>
                                                <td>
                                                    <?php echo $p['parcel_number']; ?>
                                                    <br><small class="text-muted"><?php echo $p['boma_name'] ?? ''; ?></small>
                                                </td>
                                                <td><?php echo $p['tax_year']; ?></td>
                                                <td>
                                                    <span class="fw-bold"><?php echo number_format($p['amount_paid'], 2); ?> SSP</span>
                                                </td>
                                                <td>
                                                    <span class="badge bg-secondary">
                                                        <?php echo $payment_methods[$p['payment_method']] ?? $p['payment_method']; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <small><?php echo $p['reference'] ?? '-'; ?></small>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <?php endif; ?>

                    <!-- Reports View -->
                    <?php if ($active_view == 'reports'): ?>
                    
                    <?php if (!empty($report_summary['by_year'])): 
                    $current_year_data = null;
                    foreach ($report_summary['by_year'] as $y) {
                        if ($y['tax_year'] == $year_filter) {
                            $current_year_data = $y;
                            break;
                        }
                    }
                    ?>
                    <!-- Report Cards -->
                    <div class="row g-4 mb-4">
                        <div class="col-md-3">
                            <div class="card border-0 shadow-sm bg-primary text-white">
                                <div class="card-body">
                                    <h6 class="text-white-50 mb-2">Current Year Collection</h6>
                                    <h3 class="mb-0">
                                        <?php echo number_format($current_year_data['total_collected'] ?? 0, 2); ?> SSP
                                    </h3>
                                    <small class="text-white-50">
                                        of <?php echo number_format($current_year_data['total_assessed'] ?? 0, 2); ?> SSP assessed
                                    </small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-3">
                            <div class="card border-0 shadow-sm bg-success text-white">
                                <div class="card-body">
                                    <h6 class="text-white-50 mb-2">Collection Rate</h6>
                                    <h3 class="mb-0">
                                        <?php 
                                        $rate = $current_year_data && $current_year_data['total_assessed'] > 0 
                                            ? ($current_year_data['total_collected'] / $current_year_data['total_assessed']) * 100 
                                            : 0;
                                        echo number_format($rate, 1); ?>%
                                    </h3>
                                    <small class="text-white-50">Current year</small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-3">
                            <div class="card border-0 shadow-sm bg-warning text-white">
                                <div class="card-body">
                                    <h6 class="text-white-50 mb-2">Overdue Amount</h6>
                                    <h3 class="mb-0">
                                        <?php 
                                        $overdue_total = array_sum(array_column($report_summary['defaulters'] ?? [], 'total_due'));
                                        echo number_format($overdue_total, 2); 
                                        ?> SSP
                                    </h3>
                                    <small class="text-white-50"><?php echo count($report_summary['defaulters'] ?? []); ?> defaulters</small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-3">
                            <div class="card border-0 shadow-sm bg-info text-white">
                                <div class="card-body">
                                    <h6 class="text-white-50 mb-2">Total Parcels</h6>
                                    <h3 class="mb-0">
                                        <?php 
                                        $total_parcels = array_sum(array_column($report_summary['by_year'] ?? [], 'parcels_assessed'));
                                        echo number_format($total_parcels); 
                                        ?>
                                    </h3>
                                    <small class="text-white-50">Assessed properties</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Charts Row -->
                    <div class="row g-4 mb-4">
                        <div class="col-xl-6">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h6 class="card-title mb-0 fw-bold">
                                        <i class="bi bi-bar-chart me-2 text-primary"></i>Annual Tax Collection
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <canvas id="annualChart" style="height: 300px;"></canvas>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-xl-6">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h6 class="card-title mb-0 fw-bold">
                                        <i class="bi bi-graph-up me-2 text-success"></i>Monthly Collection Trend
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <canvas id="monthlyChart" style="height: 300px;"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Top Defaulters -->
                    <?php if (!empty($report_summary['defaulters'])): ?>
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-white py-3">
                            <h6 class="card-title mb-0 fw-bold">
                                <i class="bi bi-exclamation-triangle me-2 text-danger"></i>Top Defaulters
                            </h6>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>Parcel</th>
                                            <th>Owner</th>
                                            <th>Contact</th>
                                            <th>Overdue Count</th>
                                            <th>Total Due</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($report_summary['defaulters'] as $defaulter): ?>
                                        <tr>
                                            <td><?php echo $defaulter['parcel_number']; ?></td>
                                            <td><?php echo htmlspecialchars($defaulter['owner_name'] ?? 'Unknown'); ?></td>
                                            <td><?php echo $defaulter['phone'] ?? 'N/A'; ?></td>
                                            <td><?php echo $defaulter['overdue_count']; ?> years</td>
                                            <td class="text-danger fw-bold"><?php echo number_format($defaulter['total_due'], 2); ?> SSP</td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Performance by County -->
                    <?php if (!empty($report_summary['by_county'])): ?>
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white py-3">
                            <h6 class="card-title mb-0 fw-bold">
                                <i class="bi bi-geo-alt me-2 text-info"></i>Collection Performance by County
                            </h6>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>State</th>
                                            <th>County</th>
                                            <th>Parcels</th>
                                            <th>Assessments</th>
                                            <th>Assessed</th>
                                            <th>Collected</th>
                                            <th>Rate</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($report_summary['by_county'] as $county): ?>
                                        <tr>
                                            <td><?php echo $county['state_name']; ?></td>
                                            <td><?php echo $county['county_name']; ?></td>
                                            <td><?php echo $county['total_parcels']; ?></td>
                                            <td><?php echo $county['total_assessments']; ?></td>
                                            <td><?php echo number_format($county['total_assessed'], 2); ?> SSP</td>
                                            <td><?php echo number_format($county['total_collected'], 2); ?> SSP</td>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="progress flex-grow-1 me-2" style="height: 8px; width: 100px;">
                                                        <div class="progress-bar bg-success" style="width: <?php echo $county['collection_rate']; ?>%"></div>
                                                    </div>
                                                    <span><?php echo $county['collection_rate']; ?>%</span>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php endif; ?>

                </div>
            </main>

            <!-- Footer -->
            <?php include("footer.php"); ?>

        </div>
    </div>

    <!-- Create Assessment Modal -->
    <div class="modal fade" id="createAssessmentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="create_assessment">
                    <div class="modal-header">
                        <h5 class="modal-title">Create Tax Assessment</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Parcel <span class="text-danger">*</span></label>
                            <select class="form-select" name="parcel_id" required>
                                <option value="">Select Parcel</option>
                                <?php foreach ($parcels as $p): ?>
                                <option value="<?php echo $p['id']; ?>">
                                    <?php echo $p['parcel_number']; ?> - <?php echo htmlspecialchars($p['boma_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Tax Year <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="tax_year" value="<?php echo date('Y'); ?>" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Assessed Value (SSP) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" class="form-control" name="assessed_value" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Tax Amount (SSP) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" class="form-control" name="tax_amount" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Due Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="due_date" value="<?php echo date('Y-m-d', strtotime('+3 months')); ?>" required>
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

    <!-- Record Payment Modal -->
    <div class="modal fade" id="paymentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="record_payment">
                    <input type="hidden" name="assessment_id" id="payment_assessment_id">
                    <div class="modal-header">
                        <h5 class="modal-title">Record Tax Payment</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Parcel</label>
                            <input type="text" class="form-control" id="payment_parcel" readonly>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Outstanding Balance</label>
                            <input type="text" class="form-control" id="payment_balance" readonly>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Amount Paid <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" class="form-control" name="amount_paid" id="payment_amount" required>
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
                        <button type="submit" class="btn btn-primary">Record Payment</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Assessment Modal -->
    <div class="modal fade" id="viewAssessmentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Assessment Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <table class="table table-borderless">
                        <tr>
                            <th width="40%">Parcel:</th>
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
                            <th>Assessed Value:</th>
                            <td id="view_assessed"></td>
                        </tr>
                        <tr>
                            <th>Tax Amount:</th>
                            <td id="view_tax"></td>
                        </tr>
                        <tr>
                            <th>Paid:</th>
                            <td id="view_paid"></td>
                        </tr>
                        <tr>
                            <th>Balance:</th>
                            <td id="view_balance"></td>
                        </tr>
                        <tr>
                            <th>Due Date:</th>
                            <td id="view_due"></td>
                        </tr>
                        <tr>
                            <th>Status:</th>
                            <td id="view_status"></td>
                        </tr>
                    </table>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Update Assessment Modal -->
    <div class="modal fade" id="updateAssessmentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="update_assessment">
                    <input type="hidden" name="assessment_id" id="update_assessment_id">
                    <div class="modal-header">
                        <h5 class="modal-title">Update Assessment Status</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Parcel</label>
                            <input type="text" class="form-control" id="update_parcel" readonly>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status" required>
                                <option value="pending">Pending</option>
                                <option value="paid">Paid</option>
                                <option value="exempt">Exempt</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Status</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <script>
        // Initialize charts
        document.addEventListener('DOMContentLoaded', function() {
            // Annual Chart
            const annualCtx = document.getElementById('annualChart')?.getContext('2d');
            if (annualCtx) {
                new Chart(annualCtx, {
                    type: 'bar',
                    data: {
                        labels: [
                            <?php foreach (array_reverse($report_summary['by_year'] ?? []) as $year): ?>
                            '<?php echo $year['tax_year']; ?>',
                            <?php endforeach; ?>
                        ],
                        datasets: [{
                            label: 'Assessed',
                            data: [
                                <?php foreach (array_reverse($report_summary['by_year'] ?? []) as $year): ?>
                                <?php echo $year['total_assessed']; ?>,
                                <?php endforeach; ?>
                            ],
                            backgroundColor: '#0d6efd'
                        }, {
                            label: 'Collected',
                            data: [
                                <?php foreach (array_reverse($report_summary['by_year'] ?? []) as $year): ?>
                                <?php echo $year['total_collected']; ?>,
                                <?php endforeach; ?>
                            ],
                            backgroundColor: '#198754'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false
                    }
                });
            }
            
            // Monthly Chart
            const monthlyCtx = document.getElementById('monthlyChart')?.getContext('2d');
            if (monthlyCtx && <?php echo !empty($report_summary['monthly']) ? 'true' : 'false'; ?>) {
                new Chart(monthlyCtx, {
                    type: 'line',
                    data: {
                        labels: [
                            <?php foreach (array_reverse($report_summary['monthly'] ?? []) as $month): ?>
                            '<?php echo $month['month']; ?>',
                            <?php endforeach; ?>
                        ],
                        datasets: [{
                            label: 'Collections',
                            data: [
                                <?php foreach (array_reverse($report_summary['monthly'] ?? []) as $month): ?>
                                <?php echo $month['total_collected']; ?>,
                                <?php endforeach; ?>
                            ],
                            borderColor: '#198754',
                            backgroundColor: 'rgba(25, 135, 84, 0.1)',
                            tension: 0.4,
                            fill: true
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false
                    }
                });
            }
        });

        // View assessment details
        function viewAssessment(assessment) {
            document.getElementById('view_parcel').textContent = assessment.parcel_number;
            document.getElementById('view_owner').textContent = assessment.owner_name || 'Unknown';
            document.getElementById('view_location').textContent = (assessment.boma_name || '') + ', ' + (assessment.payam_name || '');
            document.getElementById('view_year').textContent = assessment.tax_year;
            document.getElementById('view_assessed').textContent = parseFloat(assessment.assessed_value).toFixed(2) + ' SSP';
            document.getElementById('view_tax').textContent = parseFloat(assessment.tax_amount).toFixed(2) + ' SSP';
            
            const paid = assessment.total_paid ? parseFloat(assessment.total_paid) : 0;
            const balance = assessment.tax_amount - paid;
            
            document.getElementById('view_paid').textContent = paid.toFixed(2) + ' SSP';
            document.getElementById('view_balance').textContent = balance.toFixed(2) + ' SSP';
            document.getElementById('view_due').textContent = new Date(assessment.due_date).toLocaleDateString();
            
            const statuses = <?php echo json_encode($statuses); ?>;
            const isOverdue = new Date(assessment.due_date) < new Date() && assessment.status == 'pending';
            const statusKey = isOverdue ? 'overdue' : assessment.status;
            const status = statuses[statusKey] || {label: assessment.status, class: 'secondary'};
            
            document.getElementById('view_status').innerHTML = 
                '<span class="badge bg-' + status.class + '">' + status.label + '</span>';
        }

        // Record payment
        function recordPayment(assessmentId, parcel, balance) {
            document.getElementById('payment_assessment_id').value = assessmentId;
            document.getElementById('payment_parcel').value = parcel;
            document.getElementById('payment_balance').value = parseFloat(balance).toFixed(2) + ' SSP';
            document.getElementById('payment_amount').value = balance;
        }

        // Update assessment
        function updateAssessment(assessmentId, parcel, currentStatus) {
            document.getElementById('update_assessment_id').value = assessmentId;
            document.getElementById('update_parcel').value = parcel;
            
            // Set current status in dropdown
            const statusSelect = document.querySelector('select[name="status"]');
            for (let i = 0; i < statusSelect.options.length; i++) {
                if (statusSelect.options[i].value === currentStatus) {
                    statusSelect.selectedIndex = i;
                    break;
                }
            }
        }

        // Export report
        function exportReport() {
            const type = '<?php echo $active_view; ?>';
            alert('Export functionality would be implemented here for: ' + type);
        }
    </script>

    <style>
        .nav-tabs .nav-link {
            color: #495057;
            font-weight: 500;
        }
        
        .nav-tabs .nav-link.active {
            font-weight: 600;
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