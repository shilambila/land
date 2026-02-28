<?php
ini_set('display_errors', 1); 
error_reporting(E_ALL);

// payments.php
include("head.php");
require_once 'db_connection.php';

// ============================================================================
// HANDLE ACTIONS
// ============================================================================

$message = '';
$messageType = '';

// Process payment (mark as completed)
if (isset($_GET['process']) && is_numeric($_GET['process'])) {
    $paymentId = $_GET['process'];
    
    try {
        // Start transaction
        $conn->beginTransaction();
        
        // Get payment details
        $payment = fetchOne($conn, "
            SELECT p.*, pt.name as party_name 
            FROM payments p
            JOIN parties pt ON p.party_id = pt.id
            WHERE p.id = ?
        ", [$paymentId]);
        
        if (!$payment) {
            throw new Exception("Payment not found");
        }
        
        // Generate receipt number
        $receipt_number = 'RCPT/' . date('Y') . '/' . str_pad($paymentId, 5, '0', STR_PAD_LEFT);
        
        // Update payment status
        executeQuery($conn, "
            UPDATE payments 
            SET status = 'completed', 
                receipt_number = ?,
                payment_date = NOW()
            WHERE id = ?
        ", [$receipt_number, $paymentId]);
        
        // If this payment is linked to a transaction, update transaction status
        if ($payment['transaction_id']) {
            executeQuery($conn, "
                UPDATE transactions 
                SET status = 'completed' 
                WHERE id = ?
            ", [$payment['transaction_id']]);
        }
        
        // Log the action
        executeQuery($conn, "
            INSERT INTO audit_logs (table_name, record_id, action, user_id, new_values, created_at)
            VALUES ('payments', ?, 'UPDATE', ?, ?, NOW())
        ", [$paymentId, $_SESSION['user_id'] ?? 1, json_encode(['status' => 'completed', 'receipt' => $receipt_number])]);
        
        $conn->commit();
        
        $message = "Payment #$paymentId processed successfully. Receipt: $receipt_number";
        $messageType = "success";
        
    } catch (Exception $e) {
        $conn->rollBack();
        $message = "Error processing payment: " . $e->getMessage();
        $messageType = "danger";
    }
}

// Mark payment as failed
if (isset($_GET['fail']) && is_numeric($_GET['fail'])) {
    $paymentId = $_GET['fail'];
    
    try {
        executeQuery($conn, "
            UPDATE payments 
            SET status = 'failed' 
            WHERE id = ?
        ", [$paymentId]);
        
        $message = "Payment #$paymentId marked as failed";
        $messageType = "warning";
        
    } catch (Exception $e) {
        $message = "Error updating payment: " . $e->getMessage();
        $messageType = "danger";
    }
}

// Void/delete payment
if (isset($_GET['void']) && is_numeric($_GET['void'])) {
    $paymentId = $_GET['void'];
    
    try {
        // Check if payment can be voided
        $payment = fetchOne($conn, "SELECT status FROM payments WHERE id = ?", [$paymentId]);
        
        if ($payment['status'] == 'completed') {
            throw new Exception("Completed payments cannot be voided. Please process a refund instead.");
        }
        
        executeQuery($conn, "DELETE FROM payments WHERE id = ?", [$paymentId]);
        $message = "Payment #$paymentId voided successfully";
        $messageType = "success";
        
    } catch (Exception $e) {
        $message = "Error voiding payment: " . $e->getMessage();
        $messageType = "danger";
    }
}

// Add new payment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    
    $party_id = $_POST['party_id'];
    $amount = $_POST['amount'];
    $payment_method = $_POST['payment_method'];
    $reference = $_POST['reference'] ?? null;
    $description = $_POST['description'] ?? null;
    $transaction_id = !empty($_POST['transaction_id']) ? $_POST['transaction_id'] : null;
    $status = $_POST['status'] ?? 'pending';
    $payment_date = !empty($_POST['payment_date']) ? $_POST['payment_date'] : date('Y-m-d H:i:s');
    
    try {
        // Begin transaction
        $conn->beginTransaction();
        
        // Insert payment
        executeQuery($conn, "
            INSERT INTO payments (
                party_id, transaction_id, amount, payment_date, 
                payment_method, reference, status, description, created_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, NOW()
            )
        ", [$party_id, $transaction_id, $amount, $payment_date, 
            $payment_method, $reference, $status, $description]);
        
        $paymentId = $conn->lastInsertId();
        
        // If status is completed, generate receipt
        if ($status === 'completed') {
            $receipt_number = 'RCPT/' . date('Y') . '/' . str_pad($paymentId, 5, '0', STR_PAD_LEFT);
            executeQuery($conn, "
                UPDATE payments SET receipt_number = ? WHERE id = ?
            ", [$receipt_number, $paymentId]);
        }
        
        $conn->commit();
        
        $message = "Payment added successfully. ID: " . $paymentId;
        $messageType = "success";
        
    } catch (Exception $e) {
        $conn->rollBack();
        $message = "Error adding payment: " . $e->getMessage();
        $messageType = "danger";
    }
}

// Edit payment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit') {
    
    $payment_id = $_POST['payment_id'];
    $party_id = $_POST['party_id'];
    $amount = $_POST['amount'];
    $payment_method = $_POST['payment_method'];
    $reference = $_POST['reference'] ?? null;
    $description = $_POST['description'] ?? null;
    $transaction_id = !empty($_POST['transaction_id']) ? $_POST['transaction_id'] : null;
    $status = $_POST['status'];
    $payment_date = $_POST['payment_date'];
    
    try {
        // Get current payment data
        $current = fetchOne($conn, "SELECT * FROM payments WHERE id = ?", [$payment_id]);
        
        // Update payment
        executeQuery($conn, "
            UPDATE payments SET 
                party_id = ?, amount = ?, payment_date = ?,
                payment_method = ?, reference = ?, status = ?, 
                transaction_id = ?, description = ?
            WHERE id = ?
        ", [$party_id, $amount, $payment_date, $payment_method, 
            $reference, $status, $transaction_id, $description, $payment_id]);
        
        // If status changed to completed and no receipt, generate one
        if ($status === 'completed' && empty($current['receipt_number'])) {
            $receipt_number = 'RCPT/' . date('Y') . '/' . str_pad($payment_id, 5, '0', STR_PAD_LEFT);
            executeQuery($conn, "
                UPDATE payments SET receipt_number = ? WHERE id = ?
            ", [$receipt_number, $payment_id]);
        }
        
        $message = "Payment updated successfully";
        $messageType = "success";
        
    } catch (Exception $e) {
        $message = "Error updating payment: " . $e->getMessage();
        $messageType = "danger";
    }
}

// ============================================================================
// DETERMINE ACTIVE FILTER
// ============================================================================

$active_filter = 'all';
$status_filter = '';

// Check URL parameter for status filtering
if (isset($_GET['status'])) {
    $status_filter = $_GET['status'];
    $active_filter = $status_filter;
} elseif (isset($_GET['id'])) {
    // For backward compatibility with ?id=pending, ?id=completed, ?id=failed
    $id_param = $_GET['id'];
    if (in_array($id_param, ['pending', 'completed', 'failed'])) {
        $status_filter = $id_param;
        $active_filter = $id_param;
    }
}

// ============================================================================
// GET FILTERS
// ============================================================================

$party_filter = $_GET['party_id'] ?? '';
$method_filter = $_GET['method'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$search = $_GET['search'] ?? '';

// ============================================================================
// BUILD QUERY WITH FILTERS
// ============================================================================

$where_conditions = [];
$params = [];

// Apply status filter
if (!empty($status_filter)) {
    $where_conditions[] = "p.status = ?";
    $params[] = $status_filter;
}

// Apply party filter
if (!empty($party_filter)) {
    $where_conditions[] = "p.party_id = ?";
    $params[] = $party_filter;
}

// Apply payment method filter
if (!empty($method_filter)) {
    $where_conditions[] = "p.payment_method = ?";
    $params[] = $method_filter;
}

// Apply date range filters
if (!empty($date_from)) {
    $where_conditions[] = "DATE(p.payment_date) >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $where_conditions[] = "DATE(p.payment_date) <= ?";
    $params[] = $date_to;
}

// Apply search
if (!empty($search)) {
    $where_conditions[] = "(p.reference LIKE ? OR p.receipt_number LIKE ? OR p.description LIKE ? OR pt.name LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

$where_clause = empty($where_conditions) ? "" : "WHERE " . implode(" AND ", $where_conditions);

// ============================================================================
// GET PAYMENTS WITH DETAILS
// ============================================================================

$sql = "
    SELECT 
        p.*,
        pt.name as party_name,
        pt.party_type,
        pt.phone as party_phone,
        pt.email as party_email,
        t.id as transaction_id_ref,
        t.transaction_type,
        t.parcel_id,
        t.title_id,
        t.consideration_amount,
        parc.parcel_number,
        ti.title_number
    FROM payments p
    LEFT JOIN parties pt ON p.party_id = pt.id
    LEFT JOIN transactions t ON p.transaction_id = t.id
    LEFT JOIN parcels parc ON t.parcel_id = parc.id
    LEFT JOIN titles ti ON t.title_id = ti.id
    $where_clause
    ORDER BY p.payment_date DESC, p.created_at DESC
";

$payments = fetchAll($conn, $sql, $params);

// ============================================================================
// GET SUMMARY STATISTICS
// ============================================================================

$summary = fetchOne($conn, "
    SELECT 
        COUNT(*) as total_payments,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count,
        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count,
        SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as total_collected,
        SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END) as pending_amount,
        AVG(CASE WHEN status = 'completed' THEN amount ELSE NULL END) as avg_payment,
        COUNT(DISTINCT party_id) as unique_payers,
        MAX(payment_date) as last_payment_date
    FROM payments
");

// Get payments by method
$method_stats = fetchAll($conn, "
    SELECT 
        payment_method,
        COUNT(*) as count,
        SUM(amount) as total,
        SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as collected
    FROM payments
    GROUP BY payment_method
    ORDER BY count DESC
");

// Get monthly totals
$monthly_stats = fetchAll($conn, "
    SELECT 
        DATE_FORMAT(payment_date, '%Y-%m') as month,
        COUNT(*) as count,
        SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as collected,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending
    FROM payments
    WHERE payment_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(payment_date, '%Y-%m')
    ORDER BY month DESC
");

// Get all parties for dropdown
$parties = fetchAll($conn, "
    SELECT id, name, party_type 
    FROM parties 
    ORDER BY name
");

// Get all transactions for dropdown
$transactions = fetchAll($conn, "
    SELECT t.id, t.transaction_type, t.consideration_amount, 
           p.parcel_number, ti.title_number
    FROM transactions t
    LEFT JOIN parcels p ON t.parcel_id = p.id
    LEFT JOIN titles ti ON t.title_id = ti.id
    ORDER BY t.created_at DESC
    LIMIT 50
");

// Payment methods
$payment_methods = [
    'cash' => 'Cash',
    'bank_transfer' => 'Bank Transfer',
    'check' => 'Check',
    'credit_card' => 'Credit Card',
    'mobile_money' => 'Mobile Money',
    'bank_draft' => 'Bank Draft'
];

// Status colors and labels
$status_info = [
    'pending' => ['label' => 'Pending', 'class' => 'warning', 'icon' => 'hourglass-split'],
    'completed' => ['label' => 'Completed', 'class' => 'success', 'icon' => 'check-circle'],
    'failed' => ['label' => 'Failed', 'class' => 'danger', 'icon' => 'exclamation-triangle']
];
?>

<body data-page="payments" class="payments-page">
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
                            <h1 class="h3 mb-0">Payments Management</h1>
                            <p class="text-muted mb-0">Track and manage all financial transactions</p>
                        </div>
                        <div class="btn-group">
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addPaymentModal">
                                <i class="bi bi-plus-circle me-2"></i>Record Payment
                            </button>
                            <button class="btn btn-outline-secondary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown">
                                <span class="visually-hidden">Toggle Dropdown</span>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="#" onclick="exportReport()">
                                    <i class="bi bi-download me-2"></i>Export Report
                                </a></li>
                                <li><a class="dropdown-item" href="payments-reconciliation.php">
                                    <i class="bi bi-arrow-repeat me-2"></i>Reconciliation
                                </a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="payments-bulk.php">
                                    <i class="bi bi-files me-2"></i>Bulk Upload
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

                    <!-- Status Tabs -->
                    <ul class="nav nav-tabs mb-4">
                        <li class="nav-item">
                            <a class="nav-link <?php echo $active_filter == 'all' ? 'active' : ''; ?>" 
                               href="payments.php">
                                <i class="bi bi-grid me-2"></i>All Payments
                                <span class="badge bg-secondary ms-2"><?php echo $summary['total_payments'] ?? 0; ?></span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $active_filter == 'pending' ? 'active' : ''; ?>" 
                               href="payments.php?status=pending">
                                <i class="bi bi-hourglass-split me-2 text-warning"></i>Pending
                                <span class="badge bg-warning ms-2"><?php echo $summary['pending_count'] ?? 0; ?></span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $active_filter == 'completed' ? 'active' : ''; ?>" 
                               href="payments.php?status=completed">
                                <i class="bi bi-check-circle me-2 text-success"></i>Completed
                                <span class="badge bg-success ms-2"><?php echo $summary['completed_count'] ?? 0; ?></span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $active_filter == 'failed' ? 'active' : ''; ?>" 
                               href="payments.php?status=failed">
                                <i class="bi bi-exclamation-triangle me-2 text-danger"></i>Failed
                                <span class="badge bg-danger ms-2"><?php echo $summary['failed_count'] ?? 0; ?></span>
                            </a>
                        </li>
                    </ul>

                    <!-- Summary Cards -->
                    <div class="row g-4 mb-4">
                        <div class="col-xl-3 col-md-6">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0 me-3">
                                            <div class="bg-primary bg-opacity-10 rounded-circle p-3">
                                                <i class="bi bi-cash-stack text-primary fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Total Collected</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['total_collected'] ?? 0, 2); ?> SSP</h3>
                                            <small class="text-muted">From <?php echo $summary['completed_count'] ?? 0; ?> payments</small>
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
                                                <i class="bi bi-hourglass-split text-warning fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Pending Amount</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['pending_amount'] ?? 0, 2); ?> SSP</h3>
                                            <small class="text-muted"><?php echo $summary['pending_count'] ?? 0; ?> pending payments</small>
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
                                                <i class="bi bi-people text-info fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Unique Payers</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo $summary['unique_payers'] ?? 0; ?></h3>
                                            <small class="text-muted">Average: <?php echo number_format($summary['avg_payment'] ?? 0, 2); ?> SSP</small>
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
                                                <i class="bi bi-calendar-check text-success fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Last Payment</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo $summary['last_payment_date'] ? date('d/m/Y', strtotime($summary['last_payment_date'])) : 'N/A'; ?></h3>
                                            <small class="text-muted">Most recent transaction</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Charts Row -->
                    <div class="row g-4 mb-4">
                        <div class="col-xl-6">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h6 class="card-title mb-0 fw-bold">
                                        <i class="bi bi-pie-chart me-2 text-primary"></i>Payments by Method
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <canvas id="methodChart" style="height: 250px;"></canvas>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-xl-6">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h6 class="card-title mb-0 fw-bold">
                                        <i class="bi bi-bar-chart me-2 text-success"></i>Monthly Collection
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <canvas id="monthlyChart" style="height: 250px;"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Filters -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-body">
                            <form method="GET" class="row g-3">
                                <?php if (!empty($status_filter)): ?>
                                <input type="hidden" name="status" value="<?php echo $status_filter; ?>">
                                <?php endif; ?>
                                
                                <div class="col-md-3">
                                    <label class="form-label">Payer</label>
                                    <select class="form-select" name="party_id">
                                        <option value="">All Payers</option>
                                        <?php foreach ($parties as $party): ?>
                                        <option value="<?php echo $party['id']; ?>" <?php echo $party_filter == $party['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($party['name']); ?> (<?php echo ucfirst($party['party_type']); ?>)
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-3">
                                    <label class="form-label">Payment Method</label>
                                    <select class="form-select" name="method">
                                        <option value="">All Methods</option>
                                        <?php foreach ($payment_methods as $value => $label): ?>
                                        <option value="<?php echo $value; ?>" <?php echo $method_filter == $value ? 'selected' : ''; ?>>
                                            <?php echo $label; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-2">
                                    <label class="form-label">Date From</label>
                                    <input type="date" class="form-control" name="date_from" value="<?php echo $date_from; ?>">
                                </div>
                                
                                <div class="col-md-2">
                                    <label class="form-label">Date To</label>
                                    <input type="date" class="form-control" name="date_to" value="<?php echo $date_to; ?>">
                                </div>
                                
                                <div class="col-md-2">
                                    <label class="form-label">Search</label>
                                    <input type="text" class="form-control" name="search" placeholder="Ref, Receipt, Payer" 
                                           value="<?php echo htmlspecialchars($search); ?>">
                                </div>
                                
                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-search me-2"></i>Apply Filters
                                    </button>
                                    <a href="payments.php<?php echo !empty($status_filter) ? '?status=' . $status_filter : ''; ?>" class="btn btn-secondary">
                                        <i class="bi bi-x-circle me-2"></i>Clear Filters
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Payments Table -->
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0 fw-bold">
                                <i class="bi bi-table me-2"></i>
                                <?php 
                                if ($active_filter == 'pending') echo 'Pending Payments';
                                elseif ($active_filter == 'completed') echo 'Completed Payments';
                                elseif ($active_filter == 'failed') echo 'Failed Payments';
                                else echo 'All Payments';
                                ?>
                                <span class="badge bg-secondary ms-2"><?php echo count($payments); ?> records</span>
                            </h5>
                            <div>
                                <span class="text-muted me-3">
                                    Total: <strong><?php echo number_format(array_sum(array_column($payments, 'amount')), 2); ?> SSP</strong>
                                </span>
                                <button class="btn btn-sm btn-outline-secondary" onclick="exportTableToCSV()">
                                    <i class="bi bi-download"></i> Export
                                </button>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0" id="paymentsTable">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>ID</th>
                                            <th>Date</th>
                                            <th>Payer</th>
                                            <th>Description</th>
                                            <th>Method</th>
                                            <th>Reference</th>
                                            <th>Receipt</th>
                                            <th>Amount</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($payments)): ?>
                                        <tr>
                                            <td colspan="10" class="text-center py-4 text-muted">
                                                <i class="bi bi-inbox fs-1 d-block mb-3"></i>
                                                No payments found
                                            </td>
                                        </tr>
                                        <?php else: ?>
                                            <?php foreach ($payments as $payment): ?>
                                            <tr class="<?php echo $payment['status'] == 'pending' ? 'table-warning' : ($payment['status'] == 'failed' ? 'table-danger' : ''); ?>">
                                                <td>
                                                    <span class="fw-bold">#<?php echo $payment['id']; ?></span>
                                                </td>
                                                <td>
                                                    <?php echo date('d/m/Y H:i', strtotime($payment['payment_date'])); ?>
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="avatar-circle bg-primary bg-opacity-10 me-2">
                                                            <span class="text-primary fw-bold">
                                                                <?php echo strtoupper(substr($payment['party_name'] ?? 'U', 0, 2)); ?>
                                                            </span>
                                                        </div>
                                                        <div>
                                                            <span class="fw-medium"><?php echo htmlspecialchars($payment['party_name'] ?? 'Unknown'); ?></span>
                                                            <br><small class="text-muted"><?php echo ucfirst($payment['party_type'] ?? ''); ?></small>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <small><?php echo htmlspecialchars($payment['description'] ?? '-'); ?></small>
                                                    <?php if ($payment['parcel_number']): ?>
                                                    <br><small class="text-muted">Parcel: <?php echo $payment['parcel_number']; ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge bg-secondary">
                                                        <?php echo $payment_methods[$payment['payment_method']] ?? ucfirst($payment['payment_method']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <code><?php echo htmlspecialchars($payment['reference'] ?? '-'); ?></code>
                                                </td>
                                                <td>
                                                    <?php if ($payment['receipt_number']): ?>
                                                    <a href="receipt.php?id=<?php echo $payment['id']; ?>" target="_blank">
                                                        <code><?php echo $payment['receipt_number']; ?></code>
                                                    </a>
                                                    <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="fw-bold"><?php echo number_format($payment['amount'], 2); ?> SSP</span>
                                                </td>
                                                <td>
                                                    <?php
                                                    $status = $status_info[$payment['status']] ?? ['label' => 'Unknown', 'class' => 'secondary', 'icon' => 'question'];
                                                    ?>
                                                    <span class="badge bg-<?php echo $status['class']; ?>">
                                                        <i class="bi bi-<?php echo $status['icon']; ?> me-1"></i>
                                                        <?php echo $status['label']; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <button class="btn btn-outline-primary" 
                                                                onclick="viewPayment(<?php echo htmlspecialchars(json_encode($payment)); ?>)"
                                                                data-bs-toggle="modal" data-bs-target="#viewPaymentModal">
                                                            <i class="bi bi-eye"></i>
                                                        </button>
                                                        
                                                        <?php if ($payment['status'] == 'pending'): ?>
                                                        <a href="?process=<?php echo $payment['id']; ?><?php echo !empty($status_filter) ? '&status=' . $status_filter : ''; ?>" 
                                                           class="btn btn-outline-success" 
                                                           onclick="return confirm('Process this payment? A receipt will be generated.')">
                                                            <i class="bi bi-check-circle"></i>
                                                        </a>
                                                        <a href="?fail=<?php echo $payment['id']; ?><?php echo !empty($status_filter) ? '&status=' . $status_filter : ''; ?>" 
                                                           class="btn btn-outline-warning"
                                                           onclick="return confirm('Mark this payment as failed?')">
                                                            <i class="bi bi-exclamation-triangle"></i>
                                                        </a>
                                                        <?php endif; ?>
                                                        
                                                        <button class="btn btn-outline-info" 
                                                                onclick="editPayment(<?php echo htmlspecialchars(json_encode($payment)); ?>)"
                                                                data-bs-toggle="modal" data-bs-target="#editPaymentModal">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                        
                                                        <?php if ($payment['status'] != 'completed'): ?>
                                                        <a href="?void=<?php echo $payment['id']; ?><?php echo !empty($status_filter) ? '&status=' . $status_filter : ''; ?>" 
                                                           class="btn btn-outline-danger"
                                                           onclick="return confirm('Void this payment? This action cannot be undone.')">
                                                            <i class="bi bi-trash"></i>
                                                        </a>
                                                        <?php endif; ?>
                                                        
                                                        <?php if ($payment['receipt_number']): ?>
                                                        <a href="receipt-print.php?id=<?php echo $payment['id']; ?>" 
                                                           class="btn btn-outline-secondary" target="_blank">
                                                            <i class="bi bi-printer"></i>
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

                    <!-- Payment Methods Summary -->
                    <?php if (!empty($method_stats)): ?>
                    <div class="card border-0 shadow-sm mt-4">
                        <div class="card-header bg-white py-3">
                            <h6 class="card-title mb-0 fw-bold">
                                <i class="bi bi-grid me-2"></i>Payment Methods Summary
                            </h6>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-sm mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>Method</th>
                                            <th>Count</th>
                                            <th>Total Amount</th>
                                            <th>Collected</th>
                                            <th>Collection Rate</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($method_stats as $stat): ?>
                                        <tr>
                                            <td>
                                                <span class="badge bg-secondary">
                                                    <?php echo $payment_methods[$stat['payment_method']] ?? ucfirst($stat['payment_method']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo $stat['count']; ?></td>
                                            <td><?php echo number_format($stat['total'], 2); ?> SSP</td>
                                            <td><?php echo number_format($stat['collected'], 2); ?> SSP</td>
                                            <td>
                                                <?php 
                                                $rate = $stat['total'] > 0 ? ($stat['collected'] / $stat['total']) * 100 : 0;
                                                ?>
                                                <div class="progress" style="height: 20px;">
                                                    <div class="progress-bar bg-success" style="width: <?php echo $rate; ?>%">
                                                        <?php echo number_format($rate, 1); ?>%
                                                    </div>
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

                </div>
            </main>

            <!-- Footer -->
            <?php include("footer.php"); ?>

        </div>
    </div>

    <!-- Add Payment Modal -->
    <div class="modal fade" id="addPaymentModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="add">
                    <div class="modal-header">
                        <h5 class="modal-title">Record New Payment</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-8 mb-3">
                                <label class="form-label">Payer <span class="text-danger">*</span></label>
                                <select class="form-select" name="party_id" required>
                                    <option value="">Select Payer</option>
                                    <?php foreach ($parties as $party): ?>
                                    <option value="<?php echo $party['id']; ?>">
                                        <?php echo htmlspecialchars($party['name']); ?> (<?php echo ucfirst($party['party_type']); ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Amount <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text">SSP</span>
                                    <input type="number" step="0.01" class="form-control" name="amount" required>
                                </div>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Payment Method <span class="text-danger">*</span></label>
                                <select class="form-select" name="payment_method" required>
                                    <option value="">Select Method</option>
                                    <?php foreach ($payment_methods as $value => $label): ?>
                                    <option value="<?php echo $value; ?>"><?php echo $label; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Payment Date</label>
                                <input type="datetime-local" class="form-control" name="payment_date" 
                                       value="<?php echo date('Y-m-d\TH:i'); ?>">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Reference Number</label>
                                <input type="text" class="form-control" name="reference" 
                                       placeholder="Cheque/Transfer/M-Pesa ref">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status">
                                    <option value="pending">Pending</option>
                                    <option value="completed">Completed</option>
                                    <option value="failed">Failed</option>
                                </select>
                            </div>
                            
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Related Transaction</label>
                                <select class="form-select" name="transaction_id">
                                    <option value="">None (Direct Payment)</option>
                                    <?php foreach ($transactions as $trans): ?>
                                    <option value="<?php echo $trans['id']; ?>">
                                        #<?php echo $trans['id']; ?> - <?php echo ucfirst($trans['transaction_type']); ?> 
                                        (<?php echo $trans['parcel_number'] ?? $trans['title_number'] ?? 'N/A'; ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-12 mb-3">
                                <label class="form-label">Description</label>
                                <textarea class="form-control" name="description" rows="2" 
                                          placeholder="Purpose of payment..."></textarea>
                            </div>
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

    <!-- Edit Payment Modal -->
    <div class="modal fade" id="editPaymentModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="payment_id" id="edit_payment_id">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Payment</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-8 mb-3">
                                <label class="form-label">Payer</label>
                                <select class="form-select" name="party_id" id="edit_party_id" required>
                                    <?php foreach ($parties as $party): ?>
                                    <option value="<?php echo $party['id']; ?>">
                                        <?php echo htmlspecialchars($party['name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Amount</label>
                                <div class="input-group">
                                    <span class="input-group-text">SSP</span>
                                    <input type="number" step="0.01" class="form-control" name="amount" id="edit_amount" required>
                                </div>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Payment Method</label>
                                <select class="form-select" name="payment_method" id="edit_method" required>
                                    <?php foreach ($payment_methods as $value => $label): ?>
                                    <option value="<?php echo $value; ?>"><?php echo $label; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Payment Date</label>
                                <input type="datetime-local" class="form-control" name="payment_date" id="edit_date">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Reference</label>
                                <input type="text" class="form-control" name="reference" id="edit_reference">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status" id="edit_status">
                                    <option value="pending">Pending</option>
                                    <option value="completed">Completed</option>
                                    <option value="failed">Failed</option>
                                </select>
                            </div>
                            
                            <div class="col-12 mb-3">
                                <label class="form-label">Description</label>
                                <textarea class="form-control" name="description" id="edit_description" rows="2"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Payment</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Payment Modal -->
    <div class="modal fade" id="viewPaymentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Payment Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <table class="table table-borderless">
                        <tr>
                            <th width="40%">Payment ID:</th>
                            <td id="view_id"></td>
                        </tr>
                        <tr>
                            <th>Date:</th>
                            <td id="view_date"></td>
                        </tr>
                        <tr>
                            <th>Payer:</th>
                            <td id="view_payer"></td>
                        </tr>
                        <tr>
                            <th>Amount:</th>
                            <td id="view_amount"></td>
                        </tr>
                        <tr>
                            <th>Method:</th>
                            <td id="view_method"></td>
                        </tr>
                        <tr>
                            <th>Reference:</th>
                            <td id="view_reference"></td>
                        </tr>
                        <tr>
                            <th>Receipt:</th>
                            <td id="view_receipt"></td>
                        </tr>
                        <tr>
                            <th>Status:</th>
                            <td id="view_status"></td>
                        </tr>
                        <tr>
                            <th>Description:</th>
                            <td id="view_description"></td>
                        </tr>
                    </table>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <script>
        // Initialize charts
        document.addEventListener('DOMContentLoaded', function() {
            // Method Chart
            const methodCtx = document.getElementById('methodChart')?.getContext('2d');
            if (methodCtx) {
                new Chart(methodCtx, {
                    type: 'doughnut',
                    data: {
                        labels: [
                            <?php foreach ($method_stats as $stat): ?>
                            '<?php echo $payment_methods[$stat['payment_method']] ?? ucfirst($stat['payment_method']); ?>',
                            <?php endforeach; ?>
                        ],
                        datasets: [{
                            data: [
                                <?php foreach ($method_stats as $stat): ?>
                                <?php echo $stat['collected']; ?>,
                                <?php endforeach; ?>
                            ],
                            backgroundColor: ['#0d6efd', '#198754', '#ffc107', '#dc3545', '#6c757d', '#0dcaf0']
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'right'
                            }
                        }
                    }
                });
            }
            
            // Monthly Chart
            const monthlyCtx = document.getElementById('monthlyChart')?.getContext('2d');
            if (monthlyCtx) {
                new Chart(monthlyCtx, {
                    type: 'bar',
                    data: {
                        labels: [
                            <?php foreach ($monthly_stats as $stat): ?>
                            '<?php echo $stat['month']; ?>',
                            <?php endforeach; ?>
                        ],
                        datasets: [{
                            label: 'Collected',
                            data: [
                                <?php foreach ($monthly_stats as $stat): ?>
                                <?php echo $stat['collected']; ?>,
                                <?php endforeach; ?>
                            ],
                            backgroundColor: '#198754'
                        }, {
                            label: 'Pending',
                            data: [
                                <?php foreach ($monthly_stats as $stat): ?>
                                <?php echo $stat['pending']; ?>,
                                <?php endforeach; ?>
                            ],
                            backgroundColor: '#ffc107'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true
                            }
                        }
                    }
                });
            }
        });

        // View payment details
        function viewPayment(payment) {
            document.getElementById('view_id').textContent = '#' + payment.id;
            document.getElementById('view_date').textContent = new Date(payment.payment_date).toLocaleString();
            document.getElementById('view_payer').textContent = payment.party_name || 'Unknown';
            document.getElementById('view_amount').textContent = payment.amount.toFixed(2) + ' SSP';
            
            const methods = <?php echo json_encode($payment_methods); ?>;
            document.getElementById('view_method').textContent = methods[payment.payment_method] || payment.payment_method;
            
            document.getElementById('view_reference').textContent = payment.reference || '-';
            document.getElementById('view_receipt').innerHTML = payment.receipt_number ? 
                '<a href="receipt.php?id=' + payment.id + '" target="_blank">' + payment.receipt_number + '</a>' : '-';
            
            const statusClasses = <?php echo json_encode($status_info); ?>;
            const status = statusClasses[payment.status] || {label: payment.status, class: 'secondary'};
            document.getElementById('view_status').innerHTML = 
                '<span class="badge bg-' + status.class + '">' + status.label + '</span>';
            
            document.getElementById('view_description').textContent = payment.description || '-';
        }

        // Edit payment
        function editPayment(payment) {
            document.getElementById('edit_payment_id').value = payment.id;
            document.getElementById('edit_party_id').value = payment.party_id;
            document.getElementById('edit_amount').value = payment.amount;
            document.getElementById('edit_method').value = payment.payment_method;
            
            // Format datetime for input
            if (payment.payment_date) {
                const date = new Date(payment.payment_date);
                const year = date.getFullYear();
                const month = String(date.getMonth() + 1).padStart(2, '0');
                const day = String(date.getDate()).padStart(2, '0');
                const hours = String(date.getHours()).padStart(2, '0');
                const minutes = String(date.getMinutes()).padStart(2, '0');
                document.getElementById('edit_date').value = `${year}-${month}-${day}T${hours}:${minutes}`;
            }
            
            document.getElementById('edit_reference').value = payment.reference || '';
            document.getElementById('edit_status').value = payment.status;
            document.getElementById('edit_description').value = payment.description || '';
        }

        // Export table to CSV
        function exportTableToCSV() {
            const table = document.getElementById('paymentsTable');
            const rows = table.querySelectorAll('tr');
            const csv = [];
            
            rows.forEach(row => {
                const cells = row.querySelectorAll('td, th');
                const rowData = [];
                cells.forEach((cell, index) => {
                    // Skip actions column (last column)
                    if (index < cells.length - 1) {
                        rowData.push('"' + cell.textContent.trim().replace(/"/g, '""') + '"');
                    }
                });
                if (rowData.length > 0) {
                    csv.push(rowData.join(','));
                }
            });
            
            const csvContent = csv.join('\n');
            const blob = new Blob([csvContent], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'payments_export_' + new Date().toISOString().slice(0,10) + '.csv';
            a.click();
        }

        // Export report
        function exportReport() {
            const status = '<?php echo $status_filter; ?>';
            const url = 'payments-report.php?format=pdf' + (status ? '&status=' + status : '');
            window.open(url, '_blank');
        }
    </script>

    <style>
        .avatar-circle {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
        }
        
        .table td {
            vertical-align: middle;
        }
        
        .btn-group .btn {
            padding: 0.25rem 0.5rem;
        }
        
        .badge {
            font-size: 0.85rem;
        }
        
        .nav-tabs .nav-link {
            color: #495057;
            font-weight: 500;
        }
        
        .nav-tabs .nav-link.active {
            font-weight: 600;
        }
        
        .nav-tabs .nav-link .badge {
            font-size: 0.75rem;
        }
        
        .progress {
            background-color: #e9ecef;
        }
        
        .bg-opacity-10 {
            --bs-bg-opacity: 0.1;
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