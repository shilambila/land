<?php
ini_set('display_errors', 1); 
error_reporting(E_ALL);

// parties.php
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
            
            $message = "Cannot delete party with existing relationships. Consider deactivating instead.";
            $messageType = "warning";
        } else {
            executeQuery($conn, "DELETE FROM parties WHERE id = ?", [$partyId]);
            $message = "Party deleted successfully";
            $messageType = "success";
        }
    } catch (Exception $e) {
        $message = "Error deleting party: " . $e->getMessage();
        $messageType = "danger";
    }
}

// Add/Edit party
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        
        // Add new party
        if ($_POST['action'] === 'add') {
            $party_type = $_POST['party_type'];
            $name = $_POST['name'];
            $national_id = $_POST['national_id'] ?? null;
            $date_of_birth = !empty($_POST['date_of_birth']) ? $_POST['date_of_birth'] : null;
            $registration_number = $_POST['registration_number'] ?? null;
            $phone = $_POST['phone'] ?? null;
            $email = $_POST['email'] ?? null;
            $postal_address = $_POST['postal_address'] ?? null;
            $physical_address = $_POST['physical_address'] ?? null;
            
            try {
                // Check for duplicate national_id or registration_number
                if ($national_id) {
                    $exists = fetchOne($conn, "SELECT id FROM parties WHERE national_id = ?", [$national_id]);
                    if ($exists) {
                        throw new Exception("National ID already exists in the system");
                    }
                }
                
                if ($registration_number && $party_type === 'organization') {
                    $exists = fetchOne($conn, "SELECT id FROM parties WHERE registration_number = ?", [$registration_number]);
                    if ($exists) {
                        throw new Exception("Registration number already exists in the system");
                    }
                }
                
                executeQuery($conn, "
                    INSERT INTO parties (
                        party_type, name, national_id, date_of_birth, 
                        registration_number, phone, email, postal_address, physical_address
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ", [$party_type, $name, $national_id, $date_of_birth, 
                    $registration_number, $phone, $email, $postal_address, $physical_address]);
                
                $message = "Party added successfully";
                $messageType = "success";
            } catch (Exception $e) {
                $message = "Error adding party: " . $e->getMessage();
                $messageType = "danger";
            }
        }
        
        // Edit party
        if ($_POST['action'] === 'edit') {
            $party_id = $_POST['party_id'];
            $party_type = $_POST['party_type'];
            $name = $_POST['name'];
            $national_id = $_POST['national_id'] ?? null;
            $date_of_birth = !empty($_POST['date_of_birth']) ? $_POST['date_of_birth'] : null;
            $registration_number = $_POST['registration_number'] ?? null;
            $phone = $_POST['phone'] ?? null;
            $email = $_POST['email'] ?? null;
            $postal_address = $_POST['postal_address'] ?? null;
            $physical_address = $_POST['physical_address'] ?? null;
            
            try {
                // Check for duplicate national_id (excluding current party)
                if ($national_id) {
                    $exists = fetchOne($conn, "SELECT id FROM parties WHERE national_id = ? AND id != ?", [$national_id, $party_id]);
                    if ($exists) {
                        throw new Exception("National ID already exists in the system");
                    }
                }
                
                // Check for duplicate registration_number (excluding current party)
                if ($registration_number && $party_type === 'organization') {
                    $exists = fetchOne($conn, "SELECT id FROM parties WHERE registration_number = ? AND id != ?", [$registration_number, $party_id]);
                    if ($exists) {
                        throw new Exception("Registration number already exists in the system");
                    }
                }
                
                executeQuery($conn, "
                    UPDATE parties SET 
                        party_type = ?, name = ?, national_id = ?, date_of_birth = ?,
                        registration_number = ?, phone = ?, email = ?, 
                        postal_address = ?, physical_address = ?
                    WHERE id = ?
                ", [$party_type, $name, $national_id, $date_of_birth, 
                    $registration_number, $phone, $email, $postal_address, $physical_address, $party_id]);
                
                $message = "Party updated successfully";
                $messageType = "success";
            } catch (Exception $e) {
                $message = "Error updating party: " . $e->getMessage();
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

// Build filter conditions
$where_conditions = [];
$params = [];

// Party type filter
if (!empty($_GET['type'])) {
    $where_conditions[] = "party_type = ?";
    $params[] = $_GET['type'];
}

// Search
if (!empty($_GET['search'])) {
    $search = '%' . $_GET['search'] . '%';
    $where_conditions[] = "(name LIKE ? OR national_id LIKE ? OR registration_number LIKE ? OR phone LIKE ? OR email LIKE ?)";
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
}

$where_clause = empty($where_conditions) ? "" : "WHERE " . implode(" AND ", $where_conditions);

// Get total count for pagination
$count_sql = "SELECT COUNT(*) as total FROM parties $where_clause";
$total_result = executeQuery($conn, $count_sql, $params);
$total_rows = $total_result->fetch()['total'];
$total_pages = ceil($total_rows / $limit);

// Get parties with statistics
$sql = "
    SELECT 
        p.*,
        (SELECT COUNT(*) FROM ownerships o WHERE o.party_id = p.id) as total_ownerships,
        (SELECT COUNT(*) FROM applications a WHERE a.applicant_party_id = p.id) as total_applications,
        (SELECT COUNT(*) FROM documents d WHERE d.party_id = p.id) as total_documents,
        (SELECT COUNT(*) FROM users u WHERE u.party_id = p.id) as has_user_account,
        (SELECT COUNT(*) FROM payments pm WHERE pm.party_id = p.id) as total_payments
    FROM parties p
    $where_clause
    ORDER BY p.created_at DESC
    LIMIT ? OFFSET ?
";

$params[] = $limit;
$params[] = $offset;

$parties = fetchAll($conn, $sql, $params);

// Get summary statistics
$summary = fetchOne($conn, "
    SELECT 
        COUNT(*) as total_parties,
        SUM(CASE WHEN party_type = 'individual' THEN 1 ELSE 0 END) as total_individuals,
        SUM(CASE WHEN party_type = 'organization' THEN 1 ELSE 0 END) as total_organizations,
        COUNT(DISTINCT DATE(created_at)) as active_days
    FROM parties
");

// Get recent activity
$recent_activity = fetchAll($conn, "
    SELECT 
        DATE(created_at) as date,
        COUNT(*) as count
    FROM parties
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY DATE(created_at)
    ORDER BY date DESC
    LIMIT 10
");
?>

<body data-page="parties" class="parties-page">
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
                            <h1 class="h3 mb-0">Parties Management</h1>
                            <p class="text-muted mb-0">Manage individuals and organizations</p>
                        </div>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addPartyModal">
                            <i class="bi bi-person-plus me-2"></i>Add New Party
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
                        <div class="col-xl-4 col-md-4">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0 me-3">
                                            <div class="bg-primary bg-opacity-10 rounded-circle p-3">
                                                <i class="bi bi-people text-primary fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Total Parties</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['total_parties']); ?></h3>
                                            <small class="text-muted">All registered parties</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-xl-4 col-md-4">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0 me-3">
                                            <div class="bg-success bg-opacity-10 rounded-circle p-3">
                                                <i class="bi bi-person text-success fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Individuals</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['total_individuals']); ?></h3>
                                            <small class="text-muted">Personal accounts</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-xl-4 col-md-4">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0 me-3">
                                            <div class="bg-warning bg-opacity-10 rounded-circle p-3">
                                                <i class="bi bi-building text-warning fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Organizations</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['total_organizations']); ?></h3>
                                            <small class="text-muted">Companies & groups</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Filters and Search -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-body">
                            <form method="GET" class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label">Party Type</label>
                                    <select class="form-select" name="type">
                                        <option value="">All Types</option>
                                        <option value="individual" <?php echo ($_GET['type'] ?? '') == 'individual' ? 'selected' : ''; ?>>Individual</option>
                                        <option value="organization" <?php echo ($_GET['type'] ?? '') == 'organization' ? 'selected' : ''; ?>>Organization</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Search</label>
                                    <input type="text" class="form-control" name="search" 
                                           placeholder="Search by name, ID, phone, email..."
                                           value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                                </div>
                                <div class="col-md-2 d-flex align-items-end">
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="bi bi-search me-2"></i>Filter
                                    </button>
                                </div>
                                <div class="col-12">
                                    <a href="parties.php" class="btn btn-secondary">
                                        <i class="bi bi-x-circle me-2"></i>Clear Filters
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Parties Table -->
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0 fw-bold">Parties List</h5>
                            <span class="badge bg-primary"><?php echo number_format($total_rows); ?> Records</span>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>ID</th>
                                            <th>Type</th>
                                            <th>Name</th>
                                            <th>ID/Reg Number</th>
                                            <th>Contact</th>
                                            <th>Address</th>
                                            <th>Statistics</th>
                                            <th>Created</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($parties)): ?>
                                        <tr>
                                            <td colspan="9" class="text-center py-4 text-muted">
                                                <i class="bi bi-inbox fs-1 d-block mb-3"></i>
                                                No parties found
                                            </td>
                                        </tr>
                                        <?php else: ?>
                                            <?php foreach ($parties as $party): ?>
                                            <tr>
                                                <td>
                                                    <span class="fw-medium">#<?php echo $party['id']; ?></span>
                                                </td>
                                                <td>
                                                    <?php if ($party['party_type'] === 'individual'): ?>
                                                        <span class="badge bg-success">Individual</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-warning">Organization</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="avatar-circle bg-<?php echo $party['party_type'] === 'individual' ? 'success' : 'warning'; ?> bg-opacity-10 me-2">
                                                            <span class="text-<?php echo $party['party_type'] === 'individual' ? 'success' : 'warning'; ?> fw-bold">
                                                                <?php echo strtoupper(substr($party['name'], 0, 2)); ?>
                                                            </span>
                                                        </div>
                                                        <div>
                                                            <span class="fw-medium"><?php echo htmlspecialchars($party['name']); ?></span>
                                                            <?php if ($party['has_user_account'] > 0): ?>
                                                                <br><small class="text-success">Has user account</small>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php if ($party['party_type'] === 'individual'): ?>
                                                        <span class="fw-medium"><?php echo htmlspecialchars($party['national_id'] ?? 'N/A'); ?></span>
                                                        <?php if ($party['date_of_birth']): ?>
                                                            <br><small class="text-muted">DOB: <?php echo date('d/m/Y', strtotime($party['date_of_birth'])); ?></small>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="fw-medium"><?php echo htmlspecialchars($party['registration_number'] ?? 'N/A'); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($party['phone']): ?>
                                                        <i class="bi bi-telephone text-muted me-1"></i><?php echo htmlspecialchars($party['phone']); ?><br>
                                                    <?php endif; ?>
                                                    <?php if ($party['email']): ?>
                                                        <small><i class="bi bi-envelope text-muted me-1"></i><?php echo htmlspecialchars($party['email']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($party['physical_address']): ?>
                                                        <small><?php echo htmlspecialchars($party['physical_address']); ?></small>
                                                    <?php endif; ?>
                                                    <?php if ($party['postal_address']): ?>
                                                        <br><small class="text-muted">P.O. Box <?php echo htmlspecialchars($party['postal_address']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="d-flex flex-wrap gap-1">
                                                        <?php if ($party['total_ownerships'] > 0): ?>
                                                            <span class="badge bg-primary" title="Ownerships">
                                                                <i class="bi bi-file-text"></i> <?php echo $party['total_ownerships']; ?>
                                                            </span>
                                                        <?php endif; ?>
                                                        <?php if ($party['total_applications'] > 0): ?>
                                                            <span class="badge bg-info" title="Applications">
                                                                <i class="bi bi-file-earmark"></i> <?php echo $party['total_applications']; ?>
                                                            </span>
                                                        <?php endif; ?>
                                                        <?php if ($party['total_documents'] > 0): ?>
                                                            <span class="badge bg-secondary" title="Documents">
                                                                <i class="bi bi-files"></i> <?php echo $party['total_documents']; ?>
                                                            </span>
                                                        <?php endif; ?>
                                                        <?php if ($party['total_payments'] > 0): ?>
                                                            <span class="badge bg-success" title="Payments">
                                                                <i class="bi bi-currency-dollar"></i> <?php echo $party['total_payments']; ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <small class="text-muted">
                                                        <?php echo date('d/m/Y', strtotime($party['created_at'])); ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <button class="btn btn-outline-primary" 
                                                                onclick="editParty(<?php echo htmlspecialchars(json_encode($party)); ?>)"
                                                                data-bs-toggle="modal" data-bs-target="#editPartyModal">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                        <a href="?delete=<?php echo $party['id']; ?>" 
                                                           class="btn btn-outline-danger"
                                                           onclick="return confirm('Delete this party? This action cannot be undone.')">
                                                            <i class="bi bi-trash"></i>
                                                        </a>
                                                        <button class="btn btn-outline-info" 
                                                                onclick="viewPartyDetails(<?php echo htmlspecialchars(json_encode($party)); ?>)"
                                                                data-bs-toggle="modal" data-bs-target="#viewPartyModal">
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
                            <h5 class="card-title mb-0 fw-bold">Recent Registrations</h5>
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

    <!-- Add Party Modal -->
    <div class="modal fade" id="addPartyModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" id="addPartyForm">
                    <input type="hidden" name="action" value="add">
                    <div class="modal-header">
                        <h5 class="modal-title">Add New Party</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Party Type</label>
                                <select class="form-select" name="party_type" id="addPartyType" required>
                                    <option value="">Select Type</option>
                                    <option value="individual">Individual</option>
                                    <option value="organization">Organization</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Full Name</label>
                                <input type="text" class="form-control" name="name" required>
                            </div>
                            
                            <!-- Individual Fields -->
                            <div class="col-md-6 mb-3 individual-field">
                                <label class="form-label">National ID</label>
                                <input type="text" class="form-control" name="national_id">
                                <small class="text-muted">Unique identification number</small>
                            </div>
                            <div class="col-md-6 mb-3 individual-field">
                                <label class="form-label">Date of Birth</label>
                                <input type="date" class="form-control" name="date_of_birth">
                            </div>
                            
                            <!-- Organization Fields -->
                            <div class="col-md-6 mb-3 organization-field" style="display: none;">
                                <label class="form-label">Registration Number</label>
                                <input type="text" class="form-control" name="registration_number">
                                <small class="text-muted">Company/Business registration</small>
                            </div>
                            
                            <!-- Common Fields -->
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Phone Number</label>
                                <input type="tel" class="form-control" name="phone">
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
                        <button type="submit" class="btn btn-primary">Add Party</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Party Modal -->
    <div class="modal fade" id="editPartyModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" id="editPartyForm">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="party_id" id="editPartyId">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Party</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Party Type</label>
                                <select class="form-select" name="party_type" id="editPartyType" required>
                                    <option value="individual">Individual</option>
                                    <option value="organization">Organization</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Full Name</label>
                                <input type="text" class="form-control" name="name" id="editName" required>
                            </div>
                            
                            <!-- Individual Fields -->
                            <div class="col-md-6 mb-3 edit-individual-field">
                                <label class="form-label">National ID</label>
                                <input type="text" class="form-control" name="national_id" id="editNationalId">
                            </div>
                            <div class="col-md-6 mb-3 edit-individual-field">
                                <label class="form-label">Date of Birth</label>
                                <input type="date" class="form-control" name="date_of_birth" id="EditDateOfBirth">
                            </div>
                            
                            <!-- Organization Fields -->
                            <div class="col-md-6 mb-3 edit-organization-field">
                                <label class="form-label">Registration Number</label>
                                <input type="text" class="form-control" name="registration_number" id="editRegistrationNumber">
                            </div>
                            
                            <!-- Common Fields -->
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
                        <button type="submit" class="btn btn-primary">Update Party</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Party Modal -->
    <div class="modal fade" id="viewPartyModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Party Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Party Type:</label>
                            <p id="viewPartyType" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Name:</label>
                            <p id="viewName" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3" id="viewNationalIdContainer">
                            <label class="fw-bold">National ID:</label>
                            <p id="viewNationalId" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3" id="viewDobContainer">
                            <label class="fw-bold">Date of Birth:</label>
                            <p id="viewDob" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3" id="viewRegNumberContainer">
                            <label class="fw-bold">Registration Number:</label>
                            <p id="viewRegNumber" class="mb-0"></p>
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
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script>
        // Toggle fields based on party type in Add Modal
        document.getElementById('addPartyType')?.addEventListener('change', function() {
            const type = this.value;
            const individualFields = document.querySelectorAll('.individual-field');
            const orgFields = document.querySelectorAll('.organization-field');
            
            if (type === 'individual') {
                individualFields.forEach(f => f.style.display = 'block');
                orgFields.forEach(f => f.style.display = 'none');
                document.querySelector('[name="registration_number"]').required = false;
                document.querySelector('[name="national_id"]').required = true;
            } else if (type === 'organization') {
                individualFields.forEach(f => f.style.display = 'none');
                orgFields.forEach(f => f.style.display = 'block');
                document.querySelector('[name="national_id"]').required = false;
                document.querySelector('[name="registration_number"]').required = true;
            } else {
                individualFields.forEach(f => f.style.display = 'block');
                orgFields.forEach(f => f.style.display = 'block');
            }
        });

        // Edit party function
        function editParty(party) {
            document.getElementById('editPartyId').value = party.id;
            document.getElementById('editPartyType').value = party.party_type;
            document.getElementById('editName').value = party.name || '';
            document.getElementById('editNationalId').value = party.national_id || '';
            document.getElementById('EditDateOfBirth').value = party.date_of_birth || '';
            document.getElementById('editRegistrationNumber').value = party.registration_number || '';
            document.getElementById('editPhone').value = party.phone || '';
            document.getElementById('editEmail').value = party.email || '';
            document.getElementById('editPostalAddress').value = party.postal_address || '';
            document.getElementById('editPhysicalAddress').value = party.physical_address || '';
            
            // Toggle fields based on type
            const type = party.party_type;
            const individualFields = document.querySelectorAll('.edit-individual-field');
            const orgFields = document.querySelectorAll('.edit-organization-field');
            
            if (type === 'individual') {
                individualFields.forEach(f => f.style.display = 'block');
                orgFields.forEach(f => f.style.display = 'none');
            } else {
                individualFields.forEach(f => f.style.display = 'none');
                orgFields.forEach(f => f.style.display = 'block');
            }
        }

        // View party details
        function viewPartyDetails(party) {
            document.getElementById('viewPartyType').textContent = 
                party.party_type === 'individual' ? 'Individual' : 'Organization';
            document.getElementById('viewName').textContent = party.name || 'N/A';
            
            // Handle individual fields
            if (party.party_type === 'individual') {
                document.getElementById('viewNationalIdContainer').style.display = 'block';
                document.getElementById('viewNationalId').textContent = party.national_id || 'N/A';
                document.getElementById('viewDobContainer').style.display = 'block';
                document.getElementById('viewDob').textContent = party.date_of_birth ? 
                    new Date(party.date_of_birth).toLocaleDateString() : 'N/A';
                document.getElementById('viewRegNumberContainer').style.display = 'none';
            } else {
                document.getElementById('viewNationalIdContainer').style.display = 'none';
                document.getElementById('viewDobContainer').style.display = 'none';
                document.getElementById('viewRegNumberContainer').style.display = 'block';
                document.getElementById('viewRegNumber').textContent = party.registration_number || 'N/A';
            }
            
            document.getElementById('viewPhone').textContent = party.phone || 'N/A';
            document.getElementById('viewEmail').textContent = party.email || 'N/A';
            document.getElementById('viewPostalAddress').textContent = party.postal_address || 'N/A';
            document.getElementById('viewPhysicalAddress').textContent = party.physical_address || 'N/A';
            document.getElementById('viewCreated').textContent = new Date(party.created_at).toLocaleString();
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
        
        .individual-field, .organization-field,
        .edit-individual-field, .edit-organization-field {
            transition: all 0.3s ease;
        }
    </style>

</body>
</html>