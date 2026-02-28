<?php
ini_set('display_errors', 1); 
error_reporting(E_ALL);

// transactions.php - Property Transaction Management
include("head.php");
require_once 'db_connection.php';

// ============================================================================
// HANDLE ACTIONS
// ============================================================================

$message = '';
$messageType = '';

// Delete transaction
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $transactionId = $_GET['delete'];
    
    try {
        // Check if transaction has related payments
        $payments = fetchOne($conn, "SELECT COUNT(*) as count FROM payments WHERE transaction_id = ?", [$transactionId]);
        
        if ($payments && $payments['count'] > 0) {
            // Soft delete not possible, just show error
            $message = "Cannot delete transaction with associated payments";
            $messageType = "danger";
        } else {
            executeQuery($conn, "DELETE FROM transactions WHERE id = ?", [$transactionId]);
            $message = "Transaction deleted successfully";
            $messageType = "success";
        }
    } catch (Exception $e) {
        $message = "Error deleting transaction: " . $e->getMessage();
        $messageType = "danger";
        error_log("Delete error: " . $e->getMessage());
    }
}

// Add new transaction
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $transaction_type = $_POST['transaction_type'];
    $parcel_id = $_POST['parcel_id'];
    $title_id = $_POST['title_id'];
    $from_party_id = !empty($_POST['from_party_id']) ? $_POST['from_party_id'] : null;
    $to_party_id = !empty($_POST['to_party_id']) ? $_POST['to_party_id'] : null;
    $transaction_date = $_POST['transaction_date'];
    $consideration_amount = !empty($_POST['consideration_amount']) ? $_POST['consideration_amount'] : null;
    $details = $_POST['details'] ?? null;
    $created_by = $_SESSION['user_id'] ?? 1;
    
    try {
        // Start transaction
        beginTransaction($conn);
        
        // Validate parties based on transaction type
        if ($transaction_type == 'sale' || $transaction_type == 'transfer' || $transaction_type == 'gift') {
            if (!$from_party_id || !$to_party_id) {
                throw new Exception("Both from and to parties are required for this transaction type");
            }
        } elseif ($transaction_type == 'mortgage') {
            if (!$from_party_id || !$to_party_id) {
                throw new Exception("Both mortgagor (from) and mortgagee (to) are required");
            }
        } elseif ($transaction_type == 'lease') {
            if (!$from_party_id || !$to_party_id) {
                throw new Exception("Both lessor (from) and lessee (to) are required");
            }
        }
        
        // Check if parcel and title exist
        $parcel = fetchOne($conn, "SELECT id, parcel_number FROM parcels WHERE id = ?", [$parcel_id]);
        if (!$parcel) {
            throw new Exception("Parcel not found");
        }
        
        $title = fetchOne($conn, "SELECT id, title_number, title_type FROM titles WHERE id = ?", [$title_id]);
        if (!$title) {
            throw new Exception("Title not found");
        }
        
        // Insert transaction
        executeQuery($conn, "
            INSERT INTO transactions (
                transaction_type, parcel_id, title_id, from_party_id, to_party_id,
                transaction_date, consideration_amount, details, created_at, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
        ", [$transaction_type, $parcel_id, $title_id, $from_party_id, $to_party_id, 
            $transaction_date, $consideration_amount, $details, $created_by]);
        
        $transaction_id = $conn->lastInsertId();
        
        // For sale/transfer/gift, update ownership if needed
        if (in_array($transaction_type, ['sale', 'transfer', 'gift', 'inheritance']) && $to_party_id) {
            // End current ownership
            executeQuery($conn, "
                UPDATE ownerships 
                SET ownership_end_date = ? 
                WHERE title_id = ? AND is_current = 1
            ", [$transaction_date, $title_id]);
            
            // Add new ownership
            executeQuery($conn, "
                INSERT INTO ownerships (
                    title_id, party_id, share_percentage, ownership_start_date, created_at
                ) VALUES (?, ?, 100, ?, NOW())
            ", [$title_id, $to_party_id, $transaction_date]);
        }
        
        // Handle file upload if present
        if (isset($_FILES['transaction_document']) && $_FILES['transaction_document']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = 'uploads/transactions/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $file_extension = pathinfo($_FILES['transaction_document']['name'], PATHINFO_EXTENSION);
            $file_name = 'transaction_' . $transaction_id . '_' . time() . '.' . $file_extension;
            $file_path = $upload_dir . $file_name;
            
            if (move_uploaded_file($_FILES['transaction_document']['tmp_name'], $file_path)) {
                executeQuery($conn, "
                    INSERT INTO documents (
                        document_type, parcel_id, title_id, file_name, file_path, 
                        mime_type, uploaded_by, uploaded_at, description
                    ) VALUES ('transaction_document', ?, ?, ?, ?, ?, ?, NOW(), ?)
                ", [$parcel_id, $title_id, $_FILES['transaction_document']['name'], $file_path, 
                    $_FILES['transaction_document']['type'], $_SESSION['user_id'] ?? 1, 
                    "Transaction document for " . $transaction_type]);
            }
        }
        
        // Commit transaction
        commitTransaction($conn);
        
        $message = "Transaction recorded successfully";
        $messageType = "success";
        
    } catch (Exception $e) {
        rollbackTransaction($conn);
        $message = "Error recording transaction: " . $e->getMessage();
        $messageType = "danger";
        error_log("Add error: " . $e->getMessage());
    }
}

// Update transaction
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit') {
    $transaction_id = $_POST['transaction_id'];
    $transaction_type = $_POST['transaction_type'];
    $transaction_date = $_POST['transaction_date'];
    $consideration_amount = !empty($_POST['consideration_amount']) ? $_POST['consideration_amount'] : null;
    $details = $_POST['details'] ?? null;
    
    try {
        executeQuery($conn, "
            UPDATE transactions SET 
                transaction_type = ?, 
                transaction_date = ?, 
                consideration_amount = ?, 
                details = ?
            WHERE id = ?
        ", [$transaction_type, $transaction_date, $consideration_amount, $details, $transaction_id]);
        
        $message = "Transaction updated successfully";
        $messageType = "success";
        
    } catch (Exception $e) {
        $message = "Error updating transaction: " . $e->getMessage();
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

// Transaction type filter
if (!empty($_GET['transaction_type'])) {
    $where_conditions[] = "t.transaction_type = ?";
    $params[] = $_GET['transaction_type'];
}

// Search
if (!empty($_GET['search'])) {
    $search = '%' . $_GET['search'] . '%';
    $where_conditions[] = "(p.parcel_number LIKE ? OR t.title_number LIKE ? OR from_party.name LIKE ? OR to_party.name LIKE ?)";
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
}

// Date range filter
if (!empty($_GET['from_date'])) {
    $where_conditions[] = "tr.transaction_date >= ?";
    $params[] = $_GET['from_date'];
}
if (!empty($_GET['to_date'])) {
    $where_conditions[] = "tr.transaction_date <= ?";
    $params[] = $_GET['to_date'];
}

// Party filter
if (!empty($_GET['party_id'])) {
    $where_conditions[] = "(tr.from_party_id = ? OR tr.to_party_id = ?)";
    $params[] = $_GET['party_id'];
    $params[] = $_GET['party_id'];
}

// Amount range
if (!empty($_GET['min_amount'])) {
    $where_conditions[] = "tr.consideration_amount >= ?";
    $params[] = $_GET['min_amount'];
}
if (!empty($_GET['max_amount'])) {
    $where_conditions[] = "tr.consideration_amount <= ?";
    $params[] = $_GET['max_amount'];
}

$where_clause = "WHERE " . implode(" AND ", $where_conditions);

// Get total count for pagination
$count_sql = "
    SELECT COUNT(*) as total
    FROM transactions tr
    JOIN parcels p ON tr.parcel_id = p.id
    JOIN titles t ON tr.title_id = t.id
    LEFT JOIN parties from_party ON tr.from_party_id = from_party.id
    LEFT JOIN parties to_party ON tr.to_party_id = to_party.id
    $where_clause
";

$total_result = executeQuery($conn, $count_sql, $params);
$total_rows = $total_result->fetch()['total'];
$total_pages = ceil($total_rows / $limit);

// Get transactions with all related information
$sql = "
    SELECT 
        tr.*,
        p.id as parcel_id,
        p.parcel_number,
        p.area as parcel_area,
        p.location_description,
        b.name as boma_name,
        pa.name as payam_name,
        c.name as county_name,
        s.name as state_name,
        t.id as title_id,
        t.title_number,
        t.title_type,
        t.status as title_status,
        from_party.id as from_party_id,
        from_party.name as from_party_name,
        from_party.party_type as from_party_type,
        to_party.id as to_party_id,
        to_party.name as to_party_name,
        to_party.party_type as to_party_type,
        COUNT(DISTINCT pmt.id) as payment_count,
        SUM(pmt.amount) as total_paid,
        u.username as created_by_username
    FROM transactions tr
    JOIN parcels p ON tr.parcel_id = p.id
    JOIN bomas b ON p.boma_id = b.id
    JOIN payams pa ON b.payam_id = pa.id
    JOIN counties c ON pa.county_id = c.id
    JOIN states s ON c.state_id = s.id
    JOIN titles t ON tr.title_id = t.id
    LEFT JOIN parties from_party ON tr.from_party_id = from_party.id
    LEFT JOIN parties to_party ON tr.to_party_id = to_party.id
    LEFT JOIN payments pmt ON pmt.transaction_id = tr.id
    LEFT JOIN users u ON tr.created_by = u.id
    $where_clause
    GROUP BY tr.id
    ORDER BY tr.transaction_date DESC, tr.created_at DESC
    LIMIT ? OFFSET ?
";

$params[] = $limit;
$params[] = $offset;

$result = executeQuery($conn, $sql, $params);
$transactions = [];
if ($result) {
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        $transactions[] = $row;
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
    // Get all titles for dropdown
    $titles = fetchAll($conn, "
        SELECT t.id, t.title_number, t.title_type, t.status,
               p.parcel_number
        FROM titles t
        JOIN parcels p ON t.parcel_id = p.id
        WHERE t.status = 'active'
        ORDER BY t.title_number
    ");
} catch (Exception $e) {
    error_log("Error loading titles: " . $e->getMessage());
    $titles = [];
}

try {
    // Get all parties for dropdown
    $parties = fetchAll($conn, "
        SELECT id, name, party_type, national_id, registration_number
        FROM parties 
        ORDER BY name
    ");
} catch (Exception $e) {
    error_log("Error loading parties: " . $e->getMessage());
    $parties = [];
}

// ============================================================================
// SUMMARY STATISTICS
// ============================================================================

try {
    $summary = fetchOne($conn, "
        SELECT 
            COUNT(*) as total_transactions,
            SUM(CASE WHEN transaction_type = 'sale' THEN 1 ELSE 0 END) as sales,
            SUM(CASE WHEN transaction_type = 'transfer' THEN 1 ELSE 0 END) as transfers,
            SUM(CASE WHEN transaction_type = 'mortgage' THEN 1 ELSE 0 END) as mortgages,
            SUM(CASE WHEN transaction_type = 'lease' THEN 1 ELSE 0 END) as leases,
            SUM(CASE WHEN transaction_type = 'gift' THEN 1 ELSE 0 END) as gifts,
            SUM(CASE WHEN transaction_type = 'inheritance' THEN 1 ELSE 0 END) as inheritances,
            SUM(consideration_amount) as total_value,
            AVG(consideration_amount) as avg_value,
            COUNT(DISTINCT parcel_id) as unique_parcels,
            COUNT(DISTINCT title_id) as unique_titles
        FROM transactions
    ");
} catch (Exception $e) {
    error_log("Error loading summary: " . $e->getMessage());
    $summary = [
        'total_transactions' => 0,
        'sales' => 0,
        'transfers' => 0,
        'mortgages' => 0,
        'leases' => 0,
        'gifts' => 0,
        'inheritances' => 0,
        'total_value' => 0,
        'avg_value' => 0,
        'unique_parcels' => 0,
        'unique_titles' => 0
    ];
}

try {
    // Monthly trend
    $monthly_trend = fetchAll($conn, "
        SELECT 
            DATE_FORMAT(transaction_date, '%Y-%m') as month,
            COUNT(*) as count,
            SUM(consideration_amount) as total_value
        FROM transactions
        WHERE transaction_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(transaction_date, '%Y-%m')
        ORDER BY month DESC
    ");
} catch (Exception $e) {
    error_log("Error loading monthly trend: " . $e->getMessage());
    $monthly_trend = [];
}

try {
    // Top parties by transaction count
    $top_parties = fetchAll($conn, "
        SELECT 
            p.id,
            p.name,
            p.party_type,
            COUNT(*) as transaction_count,
            SUM(CASE WHEN tr.from_party_id = p.id THEN 1 ELSE 0 END) as as_seller,
            SUM(CASE WHEN tr.to_party_id = p.id THEN 1 ELSE 0 END) as as_buyer
        FROM parties p
        JOIN (
            SELECT from_party_id as party_id FROM transactions WHERE from_party_id IS NOT NULL
            UNION ALL
            SELECT to_party_id FROM transactions WHERE to_party_id IS NOT NULL
        ) as parties_ids ON p.id = parties_ids.party_id
        LEFT JOIN transactions tr ON p.id = tr.from_party_id OR p.id = tr.to_party_id
        GROUP BY p.id, p.name, p.party_type
        ORDER BY transaction_count DESC
        LIMIT 5
    ");
} catch (Exception $e) {
    error_log("Error loading top parties: " . $e->getMessage());
    $top_parties = [];
}

// Transaction type labels for display
$transaction_type_labels = [
    'sale' => 'Sale',
    'transfer' => 'Transfer',
    'mortgage' => 'Mortgage',
    'lease' => 'Lease',
    'gift' => 'Gift',
    'inheritance' => 'Inheritance'
];
?>

<body data-page="transactions" class="transactions-page">
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
                            <h1 class="h3 mb-0">Property Transactions</h1>
                            <p class="text-muted mb-0">Manage all land and property transactions</p>
                        </div>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTransactionModal">
                            <i class="bi bi-cash-stack me-2"></i>New Transaction
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
                                                <i class="bi bi-arrow-left-right text-primary fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Total Transactions</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['total_transactions'] ?? 0); ?></h3>
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
                                                <i class="bi bi-cash text-success fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Total Value</h6>
                                            <h3 class="mb-0 fw-bold">$<?php echo number_format($summary['total_value'] ?? 0, 2); ?></h3>
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
                        
                        <div class="col-xl-3 col-md-6">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0 me-3">
                                            <div class="bg-warning bg-opacity-10 rounded-circle p-3">
                                                <i class="bi bi-file-text text-warning fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Avg. Value</h6>
                                            <h3 class="mb-0 fw-bold">$<?php echo number_format($summary['avg_value'] ?? 0, 2); ?></h3>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Transaction Type Summary -->
                    <div class="row g-4 mb-4">
                        <div class="col-md-2">
                            <div class="card border-0 shadow-sm text-center">
                                <div class="card-body">
                                    <h6 class="text-muted">Sales</h6>
                                    <h4 class="mb-0 text-primary"><?php echo $summary['sales'] ?? 0; ?></h4>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="card border-0 shadow-sm text-center">
                                <div class="card-body">
                                    <h6 class="text-muted">Transfers</h6>
                                    <h4 class="mb-0 text-info"><?php echo $summary['transfers'] ?? 0; ?></h4>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="card border-0 shadow-sm text-center">
                                <div class="card-body">
                                    <h6 class="text-muted">Mortgages</h6>
                                    <h4 class="mb-0 text-warning"><?php echo $summary['mortgages'] ?? 0; ?></h4>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="card border-0 shadow-sm text-center">
                                <div class="card-body">
                                    <h6 class="text-muted">Leases</h6>
                                    <h4 class="mb-0 text-success"><?php echo $summary['leases'] ?? 0; ?></h4>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="card border-0 shadow-sm text-center">
                                <div class="card-body">
                                    <h6 class="text-muted">Gifts</h6>
                                    <h4 class="mb-0 text-secondary"><?php echo $summary['gifts'] ?? 0; ?></h4>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="card border-0 shadow-sm text-center">
                                <div class="card-body">
                                    <h6 class="text-muted">Inheritance</h6>
                                    <h4 class="mb-0 text-danger"><?php echo $summary['inheritances'] ?? 0; ?></h4>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Filters -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-body">
                            <form method="GET" class="row g-3">
                                <div class="col-md-2">
                                    <label class="form-label">Transaction Type</label>
                                    <select class="form-select" name="transaction_type">
                                        <option value="">All Types</option>
                                        <?php foreach ($transaction_type_labels as $value => $label): ?>
                                        <option value="<?php echo $value; ?>" <?php echo ($_GET['transaction_type'] ?? '') == $value ? 'selected' : ''; ?>>
                                            <?php echo $label; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-3">
                                    <label class="form-label">Search</label>
                                    <input type="text" class="form-control" name="search" 
                                           placeholder="Parcel #, Title #, Party..."
                                           value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                                </div>
                                
                                <div class="col-md-2">
                                    <label class="form-label">From Date</label>
                                    <input type="date" class="form-control" name="from_date" 
                                           value="<?php echo htmlspecialchars($_GET['from_date'] ?? ''); ?>">
                                </div>
                                
                                <div class="col-md-2">
                                    <label class="form-label">To Date</label>
                                    <input type="date" class="form-control" name="to_date" 
                                           value="<?php echo htmlspecialchars($_GET['to_date'] ?? ''); ?>">
                                </div>
                                
                                <div class="col-md-3">
                                    <label class="form-label">Party (Buyer/Seller)</label>
                                    <select class="form-select" name="party_id">
                                        <option value="">All Parties</option>
                                        <?php foreach ($parties as $party): ?>
                                        <option value="<?php echo $party['id']; ?>" <?php echo ($_GET['party_id'] ?? '') == $party['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($party['name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-2">
                                    <label class="form-label">Min Amount ($)</label>
                                    <input type="number" class="form-control" name="min_amount" step="0.01"
                                           value="<?php echo htmlspecialchars($_GET['min_amount'] ?? ''); ?>">
                                </div>
                                
                                <div class="col-md-2">
                                    <label class="form-label">Max Amount ($)</label>
                                    <input type="number" class="form-control" name="max_amount" step="0.01"
                                           value="<?php echo htmlspecialchars($_GET['max_amount'] ?? ''); ?>">
                                </div>
                                
                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-search me-2"></i>Apply Filters
                                    </button>
                                    <a href="transactions.php" class="btn btn-secondary">
                                        <i class="bi bi-x-circle me-2"></i>Clear Filters
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Transactions Table -->
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0 fw-bold">
                                <i class="bi bi-cash-stack text-primary me-2"></i>
                                Transaction History
                            </h5>
                            <span class="badge bg-primary"><?php echo number_format($total_rows); ?> Records</span>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>ID</th>
                                            <th>Type</th>
                                            <th>Parcel/Title</th>
                                            <th>From Party</th>
                                            <th>To Party</th>
                                            <th>Date</th>
                                            <th>Amount</th>
                                            <th>Payments</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($transactions)): ?>
                                        <tr>
                                            <td colspan="9" class="text-center py-4 text-muted">
                                                <i class="bi bi-cash-stack fs-1 d-block mb-3"></i>
                                                No transactions found. 
                                                <a href="#" data-bs-toggle="modal" data-bs-target="#addTransactionModal">Click here</a> to record one.
                                            </td>
                                        </tr>
                                        <?php else: ?>
                                            <?php foreach ($transactions as $trans): ?>
                                            <tr>
                                                <td>
                                                    <span class="fw-medium">#<?php echo $trans['id']; ?></span>
                                                </td>
                                                <td>
                                                    <?php
                                                    $type_class = [
                                                        'sale' => 'success',
                                                        'transfer' => 'info',
                                                        'mortgage' => 'warning',
                                                        'lease' => 'primary',
                                                        'gift' => 'secondary',
                                                        'inheritance' => 'danger'
                                                    ][$trans['transaction_type']] ?? 'secondary';
                                                    ?>
                                                    <span class="badge bg-<?php echo $type_class; ?>">
                                                        <?php echo ucfirst($trans['transaction_type']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="fw-medium"><?php echo htmlspecialchars($trans['parcel_number']); ?></span>
                                                    <br><small class="text-muted">Title: <?php echo $trans['title_number']; ?></small>
                                                    <br><small class="text-muted"><?php echo $trans['boma_name']; ?>, <?php echo $trans['payam_name']; ?></small>
                                                </td>
                                                <td>
                                                    <?php if ($trans['from_party_name']): ?>
                                                        <span class="fw-medium"><?php echo htmlspecialchars($trans['from_party_name']); ?></span>
                                                        <br><small class="text-muted"><?php echo ucfirst($trans['from_party_type'] ?? ''); ?></small>
                                                    <?php else: ?>
                                                        <span class="text-muted">N/A</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($trans['to_party_name']): ?>
                                                        <span class="fw-medium"><?php echo htmlspecialchars($trans['to_party_name']); ?></span>
                                                        <br><small class="text-muted"><?php echo ucfirst($trans['to_party_type'] ?? ''); ?></small>
                                                    <?php else: ?>
                                                        <span class="text-muted">N/A</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php echo date('d M Y', strtotime($trans['transaction_date'])); ?>
                                                </td>
                                                <td>
                                                    <?php if ($trans['consideration_amount']): ?>
                                                        <span class="fw-bold">$<?php echo number_format($trans['consideration_amount'], 2); ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($trans['payment_count'] > 0): ?>
                                                        <span class="badge bg-info"><?php echo $trans['payment_count']; ?> payments</span>
                                                        <br><small>$<?php echo number_format($trans['total_paid'] ?? 0, 2); ?></small>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">0</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <button class="btn btn-outline-primary" 
                                                                onclick="editTransaction(<?php echo htmlspecialchars(json_encode($trans)); ?>)"
                                                                data-bs-toggle="modal" data-bs-target="#editTransactionModal">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                        <button class="btn btn-outline-info" 
                                                                onclick="viewTransaction(<?php echo htmlspecialchars(json_encode($trans)); ?>)"
                                                                data-bs-toggle="modal" data-bs-target="#viewTransactionModal">
                                                            <i class="bi bi-eye"></i>
                                                        </button>
                                                        <a href="payments.php?transaction_id=<?php echo $trans['id']; ?>" 
                                                           class="btn btn-outline-success">
                                                            <i class="bi bi-cash"></i>
                                                        </a>
                                                        <a href="?delete=<?php echo $trans['id']; ?>" 
                                                           class="btn btn-outline-danger"
                                                           onclick="return confirm('Delete this transaction? This action cannot be undone.')">
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
                        <div class="col-md-7">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h5 class="card-title mb-0 fw-bold">
                                        <i class="bi bi-graph-up text-success me-2"></i>
                                        Monthly Transaction Trend
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <canvas id="monthlyChart" height="200"></canvas>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-5">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h5 class="card-title mb-0 fw-bold">
                                        <i class="bi bi-trophy text-warning me-2"></i>
                                        Top Parties
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($top_parties)): ?>
                                        <p class="text-muted text-center">No party data available</p>
                                    <?php else: ?>
                                        <div class="list-group">
                                            <?php foreach ($top_parties as $party): ?>
                                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                                <div>
                                                    <h6 class="mb-0"><?php echo htmlspecialchars($party['name']); ?></h6>
                                                    <small class="text-muted"><?php echo ucfirst($party['party_type']); ?></small>
                                                </div>
                                                <div class="text-end">
                                                    <span class="badge bg-primary rounded-pill"><?php echo $party['transaction_count']; ?> txns</span>
                                                    <br><small class="text-muted">S:<?php echo $party['as_seller']; ?> B:<?php echo $party['as_buyer']; ?></small>
                                                </div>
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

    <!-- Add Transaction Modal -->
    <div class="modal fade" id="addTransactionModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="add">
                    <div class="modal-header">
                        <h5 class="modal-title">New Transaction</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <ul class="nav nav-tabs mb-3" id="transactionTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="basic-tab" data-bs-toggle="tab" data-bs-target="#basic" type="button" role="tab">Basic Info</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="parties-tab" data-bs-toggle="tab" data-bs-target="#parties" type="button" role="tab">Parties</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="property-tab" data-bs-toggle="tab" data-bs-target="#property" type="button" role="tab">Property</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="document-tab" data-bs-toggle="tab" data-bs-target="#document" type="button" role="tab">Document</button>
                            </li>
                        </ul>
                        
                        <div class="tab-content" id="transactionTabsContent">
                            <!-- Basic Info Tab -->
                            <div class="tab-pane fade show active" id="basic" role="tabpanel">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Transaction Type <span class="text-danger">*</span></label>
                                        <select class="form-select" name="transaction_type" id="transactionType" required>
                                            <option value="">-- Select type --</option>
                                            <option value="sale">Sale</option>
                                            <option value="transfer">Transfer</option>
                                            <option value="mortgage">Mortgage</option>
                                            <option value="lease">Lease</option>
                                            <option value="gift">Gift</option>
                                            <option value="inheritance">Inheritance</option>
                                        </select>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Transaction Date <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" name="transaction_date" 
                                               value="<?php echo date('Y-m-d'); ?>" required>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Consideration Amount ($)</label>
                                        <div class="input-group">
                                            <span class="input-group-text">$</span>
                                            <input type="number" class="form-control" name="consideration_amount" 
                                                   step="0.01" min="0">
                                        </div>
                                        <small class="text-muted">Monetary value of the transaction (if any)</small>
                                    </div>
                                    
                                    <div class="col-12 mb-3">
                                        <label class="form-label">Transaction Details</label>
                                        <textarea class="form-control" name="details" rows="3" 
                                                  placeholder="Additional information about this transaction..."></textarea>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Parties Tab -->
                            <div class="tab-pane fade" id="parties" role="tabpanel">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label" id="fromPartyLabel">From Party (Seller/Transferor)</label>
                                        <select class="form-select" name="from_party_id" id="fromParty">
                                            <option value="">-- Select party --</option>
                                            <?php foreach ($parties as $party): ?>
                                            <option value="<?php echo $party['id']; ?>">
                                                <?php echo htmlspecialchars($party['name']); ?> 
                                                (<?php echo $party['party_type']; ?>)
                                                <?php if ($party['national_id']): ?> - ID: <?php echo $party['national_id']; ?><?php endif; ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="text-muted">The party transferring ownership/rights</small>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label" id="toPartyLabel">To Party (Buyer/Transferee)</label>
                                        <select class="form-select" name="to_party_id" id="toParty">
                                            <option value="">-- Select party --</option>
                                            <?php foreach ($parties as $party): ?>
                                            <option value="<?php echo $party['id']; ?>">
                                                <?php echo htmlspecialchars($party['name']); ?> 
                                                (<?php echo $party['party_type']; ?>)
                                                <?php if ($party['national_id']): ?> - ID: <?php echo $party['national_id']; ?><?php endif; ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="text-muted">The party receiving ownership/rights</small>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Property Tab -->
                            <div class="tab-pane fade" id="property" role="tabpanel">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Select Parcel <span class="text-danger">*</span></label>
                                        <select class="form-select" name="parcel_id" id="parcelSelect" required>
                                            <option value="">-- Select a parcel --</option>
                                            <?php foreach ($parcels as $parcel): ?>
                                            <option value="<?php echo $parcel['id']; ?>" 
                                                    data-parcel="<?php echo $parcel['parcel_number']; ?>">
                                                <?php echo $parcel['parcel_number']; ?> - 
                                                <?php echo htmlspecialchars($parcel['boma_name']); ?>, 
                                                <?php echo htmlspecialchars($parcel['payam_name']); ?>
                                                (<?php echo number_format($parcel['area'], 2); ?> m²)
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Select Title <span class="text-danger">*</span></label>
                                        <select class="form-select" name="title_id" id="titleSelect" required>
                                            <option value="">-- Select a title --</option>
                                            <?php foreach ($titles as $title): ?>
                                            <option value="<?php echo $title['id']; ?>"
                                                    data-title="<?php echo $title['title_number']; ?>">
                                                <?php echo $title['title_number']; ?> 
                                                (<?php echo ucfirst($title['title_type']); ?>) - 
                                                Parcel: <?php echo $title['parcel_number']; ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Selected Parcel</label>
                                        <input type="text" class="form-control" id="selectedParcel" readonly disabled>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Selected Title</label>
                                        <input type="text" class="form-control" id="selectedTitle" readonly disabled>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Document Tab -->
                            <div class="tab-pane fade" id="document" role="tabpanel">
                                <div class="row">
                                    <div class="col-md-12 mb-3">
                                        <label class="form-label">Transaction Document</label>
                                        <input type="file" class="form-control" name="transaction_document" 
                                               accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                                        <small class="text-muted">Upload contract, agreement, or related document</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Record Transaction</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Transaction Modal -->
    <div class="modal fade" id="editTransactionModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="transaction_id" id="editTransactionId">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Transaction</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Transaction Type</label>
                                <select class="form-select" name="transaction_type" id="editTransactionType" required>
                                    <option value="sale">Sale</option>
                                    <option value="transfer">Transfer</option>
                                    <option value="mortgage">Mortgage</option>
                                    <option value="lease">Lease</option>
                                    <option value="gift">Gift</option>
                                    <option value="inheritance">Inheritance</option>
                                </select>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Transaction Date</label>
                                <input type="date" class="form-control" name="transaction_date" id="editTransactionDate" required>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Consideration Amount ($)</label>
                                <input type="number" class="form-control" name="consideration_amount" 
                                       id="editConsiderationAmount" step="0.01">
                            </div>
                            
                            <div class="col-12 mb-3">
                                <label class="form-label">Transaction Details</label>
                                <textarea class="form-control" name="details" id="editDetails" rows="3"></textarea>
                            </div>
                            
                            <div class="col-12">
                                <div class="alert alert-info">
                                    <i class="bi bi-info-circle"></i>
                                    Note: Parties and property cannot be edited. Create a new transaction if changes are needed.
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Transaction</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Transaction Modal -->
    <div class="modal fade" id="viewTransactionModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Transaction Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Transaction ID:</label>
                            <p id="viewTransactionId" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Type:</label>
                            <p id="viewType" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Date:</label>
                            <p id="viewDate" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Amount:</label>
                            <p id="viewAmount" class="mb-0"></p>
                        </div>
                        
                        <div class="col-12">
                            <hr>
                            <h6 class="fw-bold">Property Information</h6>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Parcel:</label>
                            <p id="viewParcel" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Title:</label>
                            <p id="viewTitle" class="mb-0"></p>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="fw-bold">Location:</label>
                            <p id="viewLocation" class="mb-0"></p>
                        </div>
                        
                        <div class="col-12">
                            <hr>
                            <h6 class="fw-bold">Parties</h6>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">From Party:</label>
                            <p id="viewFromParty" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">To Party:</label>
                            <p id="viewToParty" class="mb-0"></p>
                        </div>
                        
                        <div class="col-12">
                            <hr>
                            <h6 class="fw-bold">Additional Information</h6>
                        </div>
                        
                        <div class="col-12 mb-3">
                            <label class="fw-bold">Details:</label>
                            <p id="viewDetails" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Created By:</label>
                            <p id="viewCreatedBy" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Created At:</label>
                            <p id="viewCreatedAt" class="mb-0"></p>
                        </div>
                        <div class="col-12">
                            <label class="fw-bold">Payments:</label>
                            <p id="viewPayments" class="mb-0"></p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <a href="#" id="viewPaymentsLink" class="btn btn-outline-success">
                        <i class="bi bi-cash"></i> View Payments
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Update party labels based on transaction type
        document.getElementById('transactionType')?.addEventListener('change', function() {
            const type = this.value;
            const fromLabel = document.getElementById('fromPartyLabel');
            const toLabel = document.getElementById('toPartyLabel');
            
            switch(type) {
                case 'sale':
                    fromLabel.textContent = 'From Party (Seller)';
                    toLabel.textContent = 'To Party (Buyer)';
                    break;
                case 'transfer':
                    fromLabel.textContent = 'From Party (Transferor)';
                    toLabel.textContent = 'To Party (Transferee)';
                    break;
                case 'mortgage':
                    fromLabel.textContent = 'From Party (Mortgagor/Borrower)';
                    toLabel.textContent = 'To Party (Mortgagee/Lender)';
                    break;
                case 'lease':
                    fromLabel.textContent = 'From Party (Lessor/Landlord)';
                    toLabel.textContent = 'To Party (Lessee/Tenant)';
                    break;
                case 'gift':
                    fromLabel.textContent = 'From Party (Donor)';
                    toLabel.textContent = 'To Party (Donee)';
                    break;
                case 'inheritance':
                    fromLabel.textContent = 'From Party (Deceased Estate)';
                    toLabel.textContent = 'To Party (Heir/Beneficiary)';
                    break;
                default:
                    fromLabel.textContent = 'From Party';
                    toLabel.textContent = 'To Party';
            }
        });
        
        // Update selected parcel/title display
        document.getElementById('parcelSelect')?.addEventListener('change', function() {
            const selected = this.options[this.selectedIndex];
            document.getElementById('selectedParcel').value = selected.text || '';
        });
        
        document.getElementById('titleSelect')?.addEventListener('change', function() {
            const selected = this.options[this.selectedIndex];
            document.getElementById('selectedTitle').value = selected.text || '';
        });
        
        // View transaction function
        function viewTransaction(trans) {
            document.getElementById('viewTransactionId').textContent = '#' + (trans.id || 'N/A');
            
            let typeBadge = '';
            const typeClasses = {
                'sale': 'bg-success',
                'transfer': 'bg-info',
                'mortgage': 'bg-warning',
                'lease': 'bg-primary',
                'gift': 'bg-secondary',
                'inheritance': 'bg-danger'
            };
            typeBadge = '<span class="badge ' + (typeClasses[trans.transaction_type] || 'bg-secondary') + '">' + 
                       (trans.transaction_type ? trans.transaction_type.charAt(0).toUpperCase() + trans.transaction_type.slice(1) : 'N/A') + '</span>';
            document.getElementById('viewType').innerHTML = typeBadge;
            
            document.getElementById('viewDate').textContent = trans.transaction_date || 'N/A';
            document.getElementById('viewAmount').textContent = trans.consideration_amount ? '$' + Number(trans.consideration_amount).toLocaleString() : 'N/A';
            
            document.getElementById('viewParcel').textContent = trans.parcel_number || 'N/A';
            document.getElementById('viewTitle').textContent = trans.title_number || 'N/A';
            document.getElementById('viewLocation').textContent = 
                (trans.boma_name || '') + ', ' + (trans.payam_name || '') + ', ' + (trans.county_name || '');
            
            document.getElementById('viewFromParty').textContent = trans.from_party_name || 'N/A';
            document.getElementById('viewToParty').textContent = trans.to_party_name || 'N/A';
            
            document.getElementById('viewDetails').textContent = trans.details || 'No details provided';
            document.getElementById('viewCreatedBy').textContent = trans.created_by_username || 'System';
            document.getElementById('viewCreatedAt').textContent = trans.created_at ? new Date(trans.created_at).toLocaleString() : 'N/A';
            
            let paymentsHtml = '<span class="badge bg-primary">' + (trans.payment_count || 0) + ' payment(s)</span>';
            if (trans.total_paid) {
                paymentsHtml += '<br>Total paid: $' + Number(trans.total_paid).toLocaleString();
            }
            document.getElementById('viewPayments').innerHTML = paymentsHtml;
            
            document.getElementById('viewPaymentsLink').href = 'payments.php?transaction_id=' + trans.id;
        }
        
        // Edit transaction function
        function editTransaction(trans) {
            document.getElementById('editTransactionId').value = trans.id;
            document.getElementById('editTransactionType').value = trans.transaction_type || 'sale';
            document.getElementById('editTransactionDate').value = trans.transaction_date || '';
            document.getElementById('editConsiderationAmount').value = trans.consideration_amount || '';
            document.getElementById('editDetails').value = trans.details || '';
        }
        
        // Monthly trend chart
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('monthlyChart')?.getContext('2d');
            if (ctx) {
                const months = <?php echo json_encode(array_column($monthly_trend, 'month')); ?>;
                const counts = <?php echo json_encode(array_column($monthly_trend, 'count')); ?>;
                const values = <?php echo json_encode(array_column($monthly_trend, 'total_value')); ?>;
                
                if (months.length > 0) {
                    new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: months,
                            datasets: [
                                {
                                    label: 'Number of Transactions',
                                    data: counts,
                                    backgroundColor: 'rgba(54, 162, 235, 0.5)',
                                    borderColor: 'rgba(54, 162, 235, 1)',
                                    borderWidth: 1,
                                    yAxisID: 'y'
                                },
                                {
                                    label: 'Total Value ($)',
                                    data: values,
                                    backgroundColor: 'rgba(255, 99, 132, 0.5)',
                                    borderColor: 'rgba(255, 99, 132, 1)',
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
                                        text: 'Number of Transactions'
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
                                        text: 'Value ($)'
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
        
        .list-group-item {
            padding: 0.75rem 1rem;
        }
        
        .list-group-item:hover {
            background-color: #f8f9fa;
        }
    </style>

</body>
</html>