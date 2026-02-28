<?php
ini_set('display_errors', 1); 
error_reporting(E_ALL);

// parties-individuals.php
include("head.php");
require_once 'db_connection.php';

// ============================================================================
// HANDLE ACTIONS
// ============================================================================

$message = '';
$messageType = '';

// Delete party
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $partyId = $_GET['delete'];
    
    try {
        // Check if party has related records
        $related = fetchOne($conn, "
            SELECT 
                (SELECT COUNT(*) FROM applications WHERE applicant_party_id = ?) as applications,
                (SELECT COUNT(*) FROM ownerships WHERE party_id = ?) as ownerships,
                (SELECT COUNT(*) FROM documents WHERE party_id = ?) as documents,
                (SELECT COUNT(*) FROM payments WHERE party_id = ?) as payments,
                (SELECT COUNT(*) FROM users WHERE party_id = ?) as users
        ", [$partyId, $partyId, $partyId, $partyId, $partyId]);
        
        if ($related['applications'] > 0 || $related['ownerships'] > 0 || 
            $related['documents'] > 0 || $related['payments'] > 0 || $related['users'] > 0) {
            
            $message = "Cannot delete individual with existing relationships. Consider deactivating instead.";
            $messageType = "warning";
        } else {
            executeQuery($conn, "DELETE FROM parties WHERE id = ? AND party_type = 'individual'", [$partyId]);
            $message = "Individual deleted successfully";
            $messageType = "success";
        }
    } catch (Exception $e) {
        $message = "Error deleting individual: " . $e->getMessage();
        $messageType = "danger";
    }
}

// Add/Edit individual
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        
        // Add new individual
        if ($_POST['action'] === 'add') {
            $name = $_POST['name'];
            $national_id = $_POST['national_id'] ?? null;
            $date_of_birth = !empty($_POST['date_of_birth']) ? $_POST['date_of_birth'] : null;
            $phone = $_POST['phone'] ?? null;
            $email = $_POST['email'] ?? null;
            $postal_address = $_POST['postal_address'] ?? null;
            $physical_address = $_POST['physical_address'] ?? null;
            
            try {
                // Check for duplicate national_id
                if ($national_id) {
                    $exists = fetchOne($conn, "SELECT id FROM parties WHERE national_id = ? AND party_type = 'individual'", [$national_id]);
                    if ($exists) {
                        throw new Exception("National ID already exists in the system");
                    }
                }
                
                executeQuery($conn, "
                    INSERT INTO parties (
                        party_type, name, national_id, date_of_birth, 
                        phone, email, postal_address, physical_address
                    ) VALUES ('individual', ?, ?, ?, ?, ?, ?, ?)
                ", [$name, $national_id, $date_of_birth, $phone, $email, $postal_address, $physical_address]);
                
                $message = "Individual added successfully";
                $messageType = "success";
            } catch (Exception $e) {
                $message = "Error adding individual: " . $e->getMessage();
                $messageType = "danger";
            }
        }
        
        // Edit individual
        if ($_POST['action'] === 'edit') {
            $party_id = $_POST['party_id'];
            $name = $_POST['name'];
            $national_id = $_POST['national_id'] ?? null;
            $date_of_birth = !empty($_POST['date_of_birth']) ? $_POST['date_of_birth'] : null;
            $phone = $_POST['phone'] ?? null;
            $email = $_POST['email'] ?? null;
            $postal_address = $_POST['postal_address'] ?? null;
            $physical_address = $_POST['physical_address'] ?? null;
            
            try {
                // Check for duplicate national_id (excluding current party)
                if ($national_id) {
                    $exists = fetchOne($conn, "SELECT id FROM parties WHERE national_id = ? AND party_type = 'individual' AND id != ?", [$national_id, $party_id]);
                    if ($exists) {
                        throw new Exception("National ID already exists in the system");
                    }
                }
                
                executeQuery($conn, "
                    UPDATE parties SET 
                        name = ?, national_id = ?, date_of_birth = ?,
                        phone = ?, email = ?, postal_address = ?, physical_address = ?
                    WHERE id = ? AND party_type = 'individual'
                ", [$name, $national_id, $date_of_birth, $phone, $email, $postal_address, $physical_address, $party_id]);
                
                $message = "Individual updated successfully";
                $messageType = "success";
            } catch (Exception $e) {
                $message = "Error updating individual: " . $e->getMessage();
                $messageType = "danger";
            }
        }
    }
}

// ============================================================================
// FILTERS AND PAGINATION
// ============================================================================

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Build filter conditions - always filter for individuals only
$where_conditions = ["party_type = 'individual'"];
$params = [];

// Search
if (!empty($_GET['search'])) {
    $search = '%' . $_GET['search'] . '%';
    $where_conditions[] = "(name LIKE ? OR national_id LIKE ? OR phone LIKE ? OR email LIKE ?)";
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
}

// Age range filter
if (!empty($_GET['age_from']) && is_numeric($_GET['age_from'])) {
    $date_from = date('Y-m-d', strtotime('-' . $_GET['age_from'] . ' years'));
    $where_conditions[] = "date_of_birth <= ?";
    $params[] = $date_from;
}

if (!empty($_GET['age_to']) && is_numeric($_GET['age_to'])) {
    $date_to = date('Y-m-d', strtotime('-' . $_GET['age_to'] . ' years'));
    $where_conditions[] = "date_of_birth >= ?";
    $params[] = $date_to;
}

// Has user account filter
if (!empty($_GET['has_user'])) {
    if ($_GET['has_user'] === 'yes') {
        $where_conditions[] = "EXISTS (SELECT 1 FROM users WHERE party_id = parties.id)";
    } elseif ($_GET['has_user'] === 'no') {
        $where_conditions[] = "NOT EXISTS (SELECT 1 FROM users WHERE party_id = parties.id)";
    }
}

$where_clause = "WHERE " . implode(" AND ", $where_conditions);

// Get total count for pagination
$count_sql = "SELECT COUNT(*) as total FROM parties $where_clause";
$total_result = executeQuery($conn, $count_sql, $params);
$total_rows = $total_result->fetch()['total'];
$total_pages = ceil($total_rows / $limit);

// Get individuals with statistics
$sql = "
    SELECT 
        p.*,
        (SELECT COUNT(*) FROM ownerships o WHERE o.party_id = p.id) as total_ownerships,
        (SELECT COUNT(*) FROM applications a WHERE a.applicant_party_id = p.id) as total_applications,
        (SELECT COUNT(*) FROM documents d WHERE d.party_id = p.id) as total_documents,
        (SELECT COUNT(*) FROM users u WHERE u.party_id = p.id) as has_user_account,
        (SELECT COUNT(*) FROM payments pm WHERE pm.party_id = p.id) as total_payments,
        (SELECT COUNT(*) FROM transactions WHERE from_party_id = p.id OR to_party_id = p.id) as total_transactions,
        TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) as age
    FROM parties p
    $where_clause
    ORDER BY p.created_at DESC
    LIMIT ? OFFSET ?
";

$params[] = $limit;
$params[] = $offset;

$individuals = fetchAll($conn, $sql, $params);

// Get summary statistics
$summary = fetchOne($conn, "
    SELECT 
        COUNT(*) as total_individuals,
        AVG(TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE())) as avg_age,
        COUNT(DISTINCT DATE(created_at)) as active_days,
        SUM(CASE WHEN EXISTS (SELECT 1 FROM users WHERE party_id = parties.id) THEN 1 ELSE 0 END) as with_user_accounts,
        COUNT(DISTINCT MONTH(created_at)) as months_active
    FROM parties
    WHERE party_type = 'individual'
");

// Get age distribution
$age_distribution = fetchAll($conn, "
    SELECT 
        CASE 
            WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) < 18 THEN 'Under 18'
            WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 18 AND 30 THEN '18-30'
            WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 31 AND 45 THEN '31-45'
            WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 46 AND 60 THEN '46-60'
            ELSE '60+'
        END as age_group,
        COUNT(*) as count
    FROM parties
    WHERE party_type = 'individual' AND date_of_birth IS NOT NULL
    GROUP BY age_group
    ORDER BY age_group
");

// Get recent activity
$recent_activity = fetchAll($conn, "
    SELECT 
        DATE(created_at) as date,
        COUNT(*) as count
    FROM parties
    WHERE party_type = 'individual' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY DATE(created_at)
    ORDER BY date DESC
    LIMIT 10
");
?>

<body data-page="parties-individuals" class="parties-individuals-page">
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
                                    <li class="breadcrumb-item"><a href="parties.php">Parties</a></li>
                                    <li class="breadcrumb-item active">Individuals</li>
                                </ol>
                            </nav>
                            <h1 class="h3 mb-0">Individuals Management</h1>
                            <p class="text-muted mb-0">Manage individual persons and their records</p>
                        </div>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addIndividualModal">
                            <i class="bi bi-person-plus me-2"></i>Add New Individual
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
                                                <i class="bi bi-person text-primary fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Total Individuals</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['total_individuals']); ?></h3>
                                            <small class="text-muted">Registered persons</small>
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
                                            <h6 class="text-muted mb-1">Average Age</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo round($summary['avg_age'] ?? 0); ?> yrs</h3>
                                            <small class="text-muted">Based on DOB records</small>
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
                                            <h6 class="text-muted mb-1">With User Accounts</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['with_user_accounts']); ?></h3>
                                            <small class="text-muted"><?php echo $summary['total_individuals'] > 0 ? round(($summary['with_user_accounts'] / $summary['total_individuals']) * 100) : 0; ?>% of total</small>
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
                                                <i class="bi bi-graph-up text-warning fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Active Months</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo $summary['months_active']; ?></h3>
                                            <small class="text-muted">Registration period</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Age Distribution -->
                    <?php if (!empty($age_distribution)): ?>
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h5 class="card-title mb-0 fw-bold">Age Distribution</h5>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <?php foreach ($age_distribution as $age_group): ?>
                                        <div class="col-md-2 mb-2">
                                            <div class="border rounded p-3 text-center">
                                                <h6 class="text-muted mb-1"><?php echo $age_group['age_group']; ?></h6>
                                                <span class="h4 mb-0 fw-bold"><?php echo $age_group['count']; ?></span>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Filters and Search -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-body">
                            <form method="GET" class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label">Age Range</label>
                                    <div class="row g-2">
                                        <div class="col-6">
                                            <input type="number" class="form-control" name="age_from" 
                                                   placeholder="Min age" min="0" max="120"
                                                   value="<?php echo htmlspecialchars($_GET['age_from'] ?? ''); ?>">
                                        </div>
                                        <div class="col-6">
                                            <input type="number" class="form-control" name="age_to" 
                                                   placeholder="Max age" min="0" max="120"
                                                   value="<?php echo htmlspecialchars($_GET['age_to'] ?? ''); ?>">
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Has User Account</label>
                                    <select class="form-select" name="has_user">
                                        <option value="">All</option>
                                        <option value="yes" <?php echo ($_GET['has_user'] ?? '') == 'yes' ? 'selected' : ''; ?>>Yes</option>
                                        <option value="no" <?php echo ($_GET['has_user'] ?? '') == 'no' ? 'selected' : ''; ?>>No</option>
                                    </select>
                                </div>
                                <div class="col-md-5">
                                    <label class="form-label">Search</label>
                                    <input type="text" class="form-control" name="search" 
                                           placeholder="Search by name, national ID, phone, email..."
                                           value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                                </div>
                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary me-2">
                                        <i class="bi bi-search me-2"></i>Apply Filters
                                    </button>
                                    <a href="parties-individuals.php" class="btn btn-secondary">
                                        <i class="bi bi-x-circle me-2"></i>Clear Filters
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Individuals Table -->
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0 fw-bold">Individuals List</h5>
                            <span class="badge bg-primary"><?php echo number_format($total_rows); ?> Records</span>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>ID</th>
                                            <th>Name</th>
                                            <th>National ID</th>
                                            <th>Age/DOB</th>
                                            <th>Contact</th>
                                            <th>Address</th>
                                            <th>Statistics</th>
                                            <th>User Account</th>
                                            <th>Created</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($individuals)): ?>
                                        <tr>
                                            <td colspan="10" class="text-center py-4 text-muted">
                                                <i class="bi bi-inbox fs-1 d-block mb-3"></i>
                                                No individuals found
                                            </td>
                                        </tr>
                                        <?php else: ?>
                                            <?php foreach ($individuals as $individual): ?>
                                            <tr>
                                                <td>
                                                    <span class="fw-medium">#<?php echo $individual['id']; ?></span>
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="avatar-circle bg-success bg-opacity-10 me-2">
                                                            <span class="text-success fw-bold">
                                                                <?php echo strtoupper(substr($individual['name'], 0, 2)); ?>
                                                            </span>
                                                        </div>
                                                        <div>
                                                            <span class="fw-medium"><?php echo htmlspecialchars($individual['name']); ?></span>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="fw-medium"><?php echo htmlspecialchars($individual['national_id'] ?? 'N/A'); ?></span>
                                                </td>
                                                <td>
                                                    <?php if ($individual['date_of_birth']): ?>
                                                        <span><?php echo date('d/m/Y', strtotime($individual['date_of_birth'])); ?></span>
                                                        <br><small class="text-muted">Age: <?php echo $individual['age']; ?> yrs</small>
                                                    <?php else: ?>
                                                        <span class="text-muted">Not provided</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($individual['phone']): ?>
                                                        <i class="bi bi-telephone text-muted me-1"></i><?php echo htmlspecialchars($individual['phone']); ?><br>
                                                    <?php endif; ?>
                                                    <?php if ($individual['email']): ?>
                                                        <small><i class="bi bi-envelope text-muted me-1"></i><?php echo htmlspecialchars($individual['email']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($individual['physical_address']): ?>
                                                        <small><?php echo htmlspecialchars($individual['physical_address']); ?></small>
                                                    <?php endif; ?>
                                                    <?php if ($individual['postal_address']): ?>
                                                        <br><small class="text-muted">P.O. Box <?php echo htmlspecialchars($individual['postal_address']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="d-flex flex-wrap gap-1">
                                                        <?php if ($individual['total_ownerships'] > 0): ?>
                                                            <span class="badge bg-primary" title="Property Ownerships">
                                                                <i class="bi bi-file-text"></i> <?php echo $individual['total_ownerships']; ?>
                                                            </span>
                                                        <?php endif; ?>
                                                        <?php if ($individual['total_applications'] > 0): ?>
                                                            <span class="badge bg-info" title="Applications">
                                                                <i class="bi bi-file-earmark"></i> <?php echo $individual['total_applications']; ?>
                                                            </span>
                                                        <?php endif; ?>
                                                        <?php if ($individual['total_transactions'] > 0): ?>
                                                            <span class="badge bg-secondary" title="Transactions">
                                                                <i class="bi bi-arrow-left-right"></i> <?php echo $individual['total_transactions']; ?>
                                                            </span>
                                                        <?php endif; ?>
                                                        <?php if ($individual['total_documents'] > 0): ?>
                                                            <span class="badge bg-warning text-dark" title="Documents">
                                                                <i class="bi bi-files"></i> <?php echo $individual['total_documents']; ?>
                                                            </span>
                                                        <?php endif; ?>
                                                        <?php if ($individual['total_payments'] > 0): ?>
                                                            <span class="badge bg-success" title="Payments">
                                                                <i class="bi bi-currency-dollar"></i> <?php echo $individual['total_payments']; ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php if ($individual['has_user_account'] > 0): ?>
                                                        <span class="badge bg-success">
                                                            <i class="bi bi-check-circle"></i> Active
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">
                                                            <i class="bi bi-x-circle"></i> No Account
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <small class="text-muted">
                                                        <?php echo date('d/m/Y', strtotime($individual['created_at'])); ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <button class="btn btn-outline-primary" 
                                                                onclick="editIndividual(<?php echo htmlspecialchars(json_encode($individual)); ?>)"
                                                                data-bs-toggle="modal" data-bs-target="#editIndividualModal">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                        <a href="?delete=<?php echo $individual['id']; ?>" 
                                                           class="btn btn-outline-danger"
                                                           onclick="return confirm('Delete this individual? This action cannot be undone.')">
                                                            <i class="bi bi-trash"></i>
                                                        </a>
                                                        <button class="btn btn-outline-info" 
                                                                onclick="viewIndividualDetails(<?php echo htmlspecialchars(json_encode($individual)); ?>)"
                                                                data-bs-toggle="modal" data-bs-target="#viewIndividualModal">
                                                            <i class="bi bi-eye"></i>
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

                    <!-- Recent Activity -->
                    <?php if (!empty($recent_activity)): ?>
                    <div class="card border-0 shadow-sm mt-4">
                        <div class="card-header bg-white py-3">
                            <h5 class="card-title mb-0 fw-bold">Recent Registrations (Last 30 Days)</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <?php foreach ($recent_activity as $activity): ?>
                                <div class="col-md-3 mb-2">
                                    <div class="d-flex justify-content-between align-items-center border rounded p-2">
                                        <span><?php echo date('M d', strtotime($activity['date'])); ?></span>
                                        <span class="badge bg-primary"><?php echo $activity['count']; ?> new</span>
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

    <!-- Add Individual Modal -->
    <div class="modal fade" id="addIndividualModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" id="addIndividualForm">
                    <input type="hidden" name="action" value="add">
                    <div class="modal-header">
                        <h5 class="modal-title">Add New Individual</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Full Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="name" required>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">National ID</label>
                                <input type="text" class="form-control" name="national_id">
                                <small class="text-muted">Unique identification number</small>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Date of Birth</label>
                                <input type="date" class="form-control" name="date_of_birth" max="<?php echo date('Y-m-d', strtotime('-18 years')); ?>">
                                <small class="text-muted">Must be at least 18 years old</small>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Phone Number</label>
                                <input type="tel" class="form-control" name="phone" placeholder="+211 912 345 678">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email Address</label>
                                <input type="email" class="form-control" name="email">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Postal Address</label>
                                <input type="text" class="form-control" name="postal_address" placeholder="P.O. Box 123">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Physical Address</label>
                                <input type="text" class="form-control" name="physical_address" placeholder="Street, Building, Area">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Individual</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Individual Modal -->
    <div class="modal fade" id="editIndividualModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" id="editIndividualForm">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="party_id" id="editIndividualId">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Individual</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Full Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="name" id="editName" required>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">National ID</label>
                                <input type="text" class="form-control" name="national_id" id="editNationalId">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Date of Birth</label>
                                <input type="date" class="form-control" name="date_of_birth" id="editDateOfBirth" max="<?php echo date('Y-m-d', strtotime('-18 years')); ?>">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Phone Number</label>
                                <input type="tel" class="form-control" name="phone" id="editPhone">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email Address</label>
                                <input type="email" class="form-control" name="email" id="editEmail">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Postal Address</label>
                                <input type="text" class="form-control" name="postal_address" id="editPostalAddress">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Physical Address</label>
                                <input type="text" class="form-control" name="physical_address" id="editPhysicalAddress">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Individual</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Individual Modal -->
    <div class="modal fade" id="viewIndividualModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Individual Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Name:</label>
                            <p id="viewName" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">National ID:</label>
                            <p id="viewNationalId" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Date of Birth:</label>
                            <p id="viewDob" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Age:</label>
                            <p id="viewAge" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Phone:</label>
                            <p id="viewPhone" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Email:</label>
                            <p id="viewEmail" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Postal Address:</label>
                            <p id="viewPostalAddress" class="mb-0"></p>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="fw-bold">Physical Address:</label>
                            <p id="viewPhysicalAddress" class="mb-0"></p>
                        </div>
                        <div class="col-12">
                            <label class="fw-bold">Created:</label>
                            <p id="viewCreated" class="mb-0"></p>
                        </div>
                    </div>
                    
                    <!-- Statistics Section -->
                    <div class="mt-4">
                        <h6 class="fw-bold mb-3">Related Records</h6>
                        <div class="row">
                            <div class="col-md-3 col-6 mb-2">
                                <div class="border rounded p-2 text-center">
                                    <small class="text-muted d-block">Ownerships</small>
                                    <span id="viewOwnerships" class="h5 mb-0">0</span>
                                </div>
                            </div>
                            <div class="col-md-3 col-6 mb-2">
                                <div class="border rounded p-2 text-center">
                                    <small class="text-muted d-block">Applications</small>
                                    <span id="viewApplications" class="h5 mb-0">0</span>
                                </div>
                            </div>
                            <div class="col-md-3 col-6 mb-2">
                                <div class="border rounded p-2 text-center">
                                    <small class="text-muted d-block">Documents</small>
                                    <span id="viewDocuments" class="h5 mb-0">0</span>
                                </div>
                            </div>
                            <div class="col-md-3 col-6 mb-2">
                                <div class="border rounded p-2 text-center">
                                    <small class="text-muted d-block">Payments</small>
                                    <span id="viewPayments" class="h5 mb-0">0</span>
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

    <!-- JavaScript -->
    <script>
        // Edit individual function
        function editIndividual(individual) {
            document.getElementById('editIndividualId').value = individual.id;
            document.getElementById('editName').value = individual.name || '';
            document.getElementById('editNationalId').value = individual.national_id || '';
            document.getElementById('editDateOfBirth').value = individual.date_of_birth || '';
            document.getElementById('editPhone').value = individual.phone || '';
            document.getElementById('editEmail').value = individual.email || '';
            document.getElementById('editPostalAddress').value = individual.postal_address || '';
            document.getElementById('editPhysicalAddress').value = individual.physical_address || '';
        }

        // View individual details
        function viewIndividualDetails(individual) {
            document.getElementById('viewName').textContent = individual.name || 'N/A';
            document.getElementById('viewNationalId').textContent = individual.national_id || 'N/A';
            
            if (individual.date_of_birth) {
                const dob = new Date(individual.date_of_birth);
                document.getElementById('viewDob').textContent = dob.toLocaleDateString();
                document.getElementById('viewAge').textContent = individual.age + ' years';
            } else {
                document.getElementById('viewDob').textContent = 'N/A';
                document.getElementById('viewAge').textContent = 'N/A';
            }
            
            document.getElementById('viewPhone').textContent = individual.phone || 'N/A';
            document.getElementById('viewEmail').textContent = individual.email || 'N/A';
            document.getElementById('viewPostalAddress').textContent = individual.postal_address || 'N/A';
            document.getElementById('viewPhysicalAddress').textContent = individual.physical_address || 'N/A';
            document.getElementById('viewCreated').textContent = new Date(individual.created_at).toLocaleString();
            
            // Statistics
            document.getElementById('viewOwnerships').textContent = individual.total_ownerships || '0';
            document.getElementById('viewApplications').textContent = individual.total_applications || '0';
            document.getElementById('viewDocuments').textContent = individual.total_documents || '0';
            document.getElementById('viewPayments').textContent = individual.total_payments || '0';
        }

        // Validate date of birth (must be at least 18 years old)
        document.querySelectorAll('input[name="date_of_birth"]').forEach(input => {
            input.addEventListener('change', function() {
                const dob = new Date(this.value);
                const today = new Date();
                const minDate = new Date(today.setFullYear(today.getFullYear() - 18));
                
                if (dob > minDate) {
                    alert('Individual must be at least 18 years old.');
                    this.value = '';
                }
            });
        });

        // Initialize tooltips
        document.addEventListener('DOMContentLoaded', function() {
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[title]'));
            tooltipTriggerList.map(function(tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
        });
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
        
        .modal-body {
            max-height: 70vh;
            overflow-y: auto;
        }
        
        .badge {
            font-size: 0.85rem;
        }
        
        .pagination {
            margin-bottom: 0;
        }
        
        .page-link {
            padding: 0.375rem 0.75rem;
        }
        
        .bg-opacity-10 {
            --bs-bg-opacity: 0.1;
        }
        
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
    </style>

</body>
</html>