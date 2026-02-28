<?php
ini_set('display_errors', 1); 
error_reporting(E_ALL);

// parties-organizations.php
include("head.php");
require_once 'db_connection.php';

// ============================================================================
// HANDLE ACTIONS
// ============================================================================

$message = '';
$messageType = '';

// Delete organization
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $orgId = $_GET['delete'];
    
    try {
        // Check if organization has related records
        $related = fetchOne($conn, "
            SELECT 
                (SELECT COUNT(*) FROM ownerships WHERE party_id = ?) as ownerships,
                (SELECT COUNT(*) FROM applications WHERE applicant_party_id = ?) as applications,
                (SELECT COUNT(*) FROM documents WHERE party_id = ?) as documents,
                (SELECT COUNT(*) FROM payments WHERE party_id = ?) as payments,
                (SELECT COUNT(*) FROM users WHERE party_id = ?) as users,
                (SELECT COUNT(*) FROM encumbrances WHERE involved_party_id = ?) as encumbrances
        ", [$orgId, $orgId, $orgId, $orgId, $orgId, $orgId]);
        
        if ($related['ownerships'] > 0 || $related['applications'] > 0 || 
            $related['documents'] > 0 || $related['payments'] > 0 || 
            $related['users'] > 0 || $related['encumbrances'] > 0) {
            
            $message = "Cannot delete organization with existing relationships. Consider deactivating instead.";
            $messageType = "warning";
        } else {
            executeQuery($conn, "DELETE FROM parties WHERE id = ? AND party_type = 'organization'", [$orgId]);
            $message = "Organization deleted successfully";
            $messageType = "success";
        }
    } catch (Exception $e) {
        $message = "Error deleting organization: " . $e->getMessage();
        $messageType = "danger";
    }
}

// Add/Edit organization
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        
        // Add new organization
        if ($_POST['action'] === 'add') {
            $name = $_POST['name'];
            $registration_number = $_POST['registration_number'] ?? null;
            $registration_date = !empty($_POST['registration_date']) ? $_POST['registration_date'] : null;
            $registration_authority = $_POST['registration_authority'] ?? null;
            $business_type = $_POST['business_type'] ?? null;
            $phone = $_POST['phone'] ?? null;
            $email = $_POST['email'] ?? null;
            $website = $_POST['website'] ?? null;
            $postal_address = $_POST['postal_address'] ?? null;
            $physical_address = $_POST['physical_address'] ?? null;
            $city = $_POST['city'] ?? null;
            $state = $_POST['state'] ?? null;
            $country = $_POST['country'] ?? 'South Sudan';
            $tax_id = $_POST['tax_id'] ?? null;
            $contact_person = $_POST['contact_person'] ?? null;
            $contact_person_phone = $_POST['contact_person_phone'] ?? null;
            $contact_person_email = $_POST['contact_person_email'] ?? null;
            $employee_count = !empty($_POST['employee_count']) ? $_POST['employee_count'] : null;
            $annual_revenue = !empty($_POST['annual_revenue']) ? $_POST['annual_revenue'] : null;
            $description = $_POST['description'] ?? null;
            
            try {
                // Check for duplicate registration number
                if ($registration_number) {
                    $exists = fetchOne($conn, "SELECT id FROM parties WHERE registration_number = ?", [$registration_number]);
                    if ($exists) {
                        throw new Exception("Registration number already exists in the system");
                    }
                }
                
                executeQuery($conn, "
                    INSERT INTO parties (
                        party_type, name, registration_number, phone, email, 
                        postal_address, physical_address, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ", ['organization', $name, $registration_number, $phone, $email, 
                    $postal_address, $physical_address]);
                
                $newId = $conn->lastInsertId();
                
                // Insert into organizations_extended table (you may want to create this)
                // For now, we'll store additional fields in a JSON column or separate table
                
                $message = "Organization added successfully";
                $messageType = "success";
            } catch (Exception $e) {
                $message = "Error adding organization: " . $e->getMessage();
                $messageType = "danger";
            }
        }
        
        // Edit organization
        if ($_POST['action'] === 'edit') {
            $org_id = $_POST['org_id'];
            $name = $_POST['name'];
            $registration_number = $_POST['registration_number'] ?? null;
            $phone = $_POST['phone'] ?? null;
            $email = $_POST['email'] ?? null;
            $postal_address = $_POST['postal_address'] ?? null;
            $physical_address = $_POST['physical_address'] ?? null;
            
            try {
                // Check for duplicate registration number (excluding current)
                if ($registration_number) {
                    $exists = fetchOne($conn, "SELECT id FROM parties WHERE registration_number = ? AND id != ?", [$registration_number, $org_id]);
                    if ($exists) {
                        throw new Exception("Registration number already exists in the system");
                    }
                }
                
                executeQuery($conn, "
                    UPDATE parties SET 
                        name = ?, registration_number = ?, phone = ?, email = ?,
                        postal_address = ?, physical_address = ?
                    WHERE id = ? AND party_type = 'organization'
                ", [$name, $registration_number, $phone, $email, $postal_address, $physical_address, $org_id]);
                
                $message = "Organization updated successfully";
                $messageType = "success";
            } catch (Exception $e) {
                $message = "Error updating organization: " . $e->getMessage();
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
$where_conditions = ["party_type = 'organization'"];
$params = [];

// Search
if (!empty($_GET['search'])) {
    $search = '%' . $_GET['search'] . '%';
    $where_conditions[] = "(name LIKE ? OR registration_number LIKE ? OR phone LIKE ? OR email LIKE ?)";
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
}

// Registration number filter
if (!empty($_GET['reg_no'])) {
    $where_conditions[] = "registration_number LIKE ?";
    $params[] = '%' . $_GET['reg_no'] . '%';
}

// Date range filter
if (!empty($_GET['from_date'])) {
    $where_conditions[] = "DATE(created_at) >= ?";
    $params[] = $_GET['from_date'];
}
if (!empty($_GET['to_date'])) {
    $where_conditions[] = "DATE(created_at) <= ?";
    $params[] = $_GET['to_date'];
}

$where_clause = "WHERE " . implode(" AND ", $where_conditions);

// Get total count for pagination
$count_sql = "SELECT COUNT(*) as total FROM parties $where_clause";
$total_result = executeQuery($conn, $count_sql, $params);
$total_rows = $total_result->fetch()['total'];
$total_pages = ceil($total_rows / $limit);

// Get organizations with statistics
$sql = "
    SELECT 
        p.*,
        (SELECT COUNT(*) FROM ownerships o WHERE o.party_id = p.id) as total_ownerships,
        (SELECT COUNT(*) FROM applications a WHERE a.applicant_party_id = p.id) as total_applications,
        (SELECT COUNT(*) FROM documents d WHERE d.party_id = p.id) as total_documents,
        (SELECT COUNT(*) FROM payments pm WHERE pm.party_id = p.id) as total_payments,
        (SELECT COUNT(*) FROM users u WHERE u.party_id = p.id) as has_user_account,
        (SELECT COUNT(*) FROM encumbrances e WHERE e.involved_party_id = p.id) as total_encumbrances,
        (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE party_id = p.id AND status = 'completed') as total_paid,
        (SELECT MAX(created_at) FROM applications WHERE applicant_party_id = p.id) as last_activity
    FROM parties p
    $where_clause
    ORDER BY p.created_at DESC
    LIMIT ? OFFSET ?
";

$params[] = $limit;
$params[] = $offset;

$organizations = fetchAll($conn, $sql, $params);

// Get summary statistics
$summary = fetchOne($conn, "
    SELECT 
        COUNT(*) as total_orgs,
        COUNT(DISTINCT DATE(created_at)) as active_days,
        (SELECT COUNT(*) FROM ownerships o JOIN parties p ON o.party_id = p.id WHERE p.party_type = 'organization') as total_land_holdings,
        (SELECT COALESCE(SUM(amount), 0) FROM payments pm JOIN parties p ON pm.party_id = p.id WHERE p.party_type = 'organization' AND pm.status = 'completed') as total_revenue
    FROM parties
    WHERE party_type = 'organization'
");

// Get recent organizations
$recent_orgs = fetchAll($conn, "
    SELECT name, registration_number, created_at
    FROM parties
    WHERE party_type = 'organization'
    ORDER BY created_at DESC
    LIMIT 5
");

// Get top organizations by land holdings
$top_landholders = fetchAll($conn, "
    SELECT 
        p.name,
        p.registration_number,
        COUNT(o.id) as land_count,
        COALESCE(SUM(pa.area), 0) as total_area
    FROM parties p
    JOIN ownerships o ON p.id = o.party_id
    JOIN titles t ON o.title_id = t.id
    JOIN parcels pa ON t.parcel_id = pa.id
    WHERE p.party_type = 'organization'
    GROUP BY p.id
    ORDER BY land_count DESC
    LIMIT 5
");

// Get monthly registrations
$monthly_stats = fetchAll($conn, "
    SELECT 
        DATE_FORMAT(created_at, '%Y-%m') as month,
        COUNT(*) as count
    FROM parties
    WHERE party_type = 'organization' 
        AND created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
    ORDER BY month DESC
");
?>

<body data-page="organizations" class="organizations-page">
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
                            <h1 class="h3 mb-0">Organizations Management</h1>
                            <p class="text-muted mb-0">Manage companies, businesses, and institutions</p>
                        </div>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addOrganizationModal">
                            <i class="bi bi-building me-2"></i>Add New Organization
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
                                                <i class="bi bi-building text-primary fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Total Organizations</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['total_orgs']); ?></h3>
                                            <small class="text-muted">Registered organizations</small>
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
                                                <i class="bi bi-file-text text-success fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Land Holdings</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['total_land_holdings']); ?></h3>
                                            <small class="text-muted">Total properties owned</small>
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
                                                <i class="bi bi-currency-dollar text-warning fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Total Revenue</h6>
                                            <h3 class="mb-0 fw-bold">$<?php echo number_format($summary['total_revenue'], 2); ?></h3>
                                            <small class="text-muted">From organizations</small>
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
                                                <i class="bi bi-calendar text-info fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Active Days</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo $summary['active_days']; ?></h3>
                                            <small class="text-muted">Days with registrations</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Charts Row -->
                    <div class="row g-4 mb-5">
                        <div class="col-lg-6">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h5 class="card-title mb-0 fw-bold">Monthly Registrations</h5>
                                </div>
                                <div class="card-body">
                                    <canvas id="monthlyChart" style="height: 250px;"></canvas>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-lg-6">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h5 class="card-title mb-0 fw-bold">Top Landholding Organizations</h5>
                                </div>
                                <div class="card-body">
                                    <?php foreach ($top_landholders as $index => $org): ?>
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <div class="d-flex align-items-center">
                                            <span class="badge bg-<?php 
                                                echo $index == 0 ? 'warning' : 
                                                    ($index == 1 ? 'secondary' : 
                                                    ($index == 2 ? 'bronze' : 'light')); 
                                            ?> me-3">#<?php echo $index + 1; ?></span>
                                            <div>
                                                <span class="fw-medium"><?php echo htmlspecialchars($org['name']); ?></span>
                                                <br>
                                                <small class="text-muted">Reg: <?php echo $org['registration_number'] ?? 'N/A'; ?></small>
                                            </div>
                                        </div>
                                        <div class="text-end">
                                            <span class="fw-bold"><?php echo $org['land_count']; ?></span>
                                            <br>
                                            <small class="text-muted"><?php echo number_format($org['total_area'], 2); ?> sq m</small>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Filters and Search -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-body">
                            <form method="GET" class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label">Search</label>
                                    <input type="text" class="form-control" name="search" 
                                           placeholder="Name, Reg No, Phone, Email..."
                                           value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Registration No.</label>
                                    <input type="text" class="form-control" name="reg_no" 
                                           placeholder="e.g., REG/2024/001"
                                           value="<?php echo htmlspecialchars($_GET['reg_no'] ?? ''); ?>">
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
                                <div class="col-md-1 d-flex align-items-end">
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="bi bi-search"></i>
                                    </button>
                                </div>
                                <div class="col-12">
                                    <a href="parties-organizations.php" class="btn btn-secondary">
                                        <i class="bi bi-x-circle me-2"></i>Clear Filters
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Organizations Table -->
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0 fw-bold">Organizations List</h5>
                            <span class="badge bg-primary"><?php echo number_format($total_rows); ?> Records</span>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>ID</th>
                                            <th>Organization Name</th>
                                            <th>Registration No.</th>
                                            <th>Contact</th>
                                            <th>Address</th>
                                            <th>Statistics</th>
                                            <th>Last Activity</th>
                                            <th>Created</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($organizations)): ?>
                                        <tr>
                                            <td colspan="9" class="text-center py-4 text-muted">
                                                <i class="bi bi-building fs-1 d-block mb-3"></i>
                                                No organizations found
                                            </td>
                                        </tr>
                                        <?php else: ?>
                                            <?php foreach ($organizations as $org): ?>
                                            <tr>
                                                <td>
                                                    <span class="fw-medium">#<?php echo $org['id']; ?></span>
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="org-avatar bg-warning bg-opacity-10 me-2">
                                                            <i class="bi bi-building text-warning"></i>
                                                        </div>
                                                        <div>
                                                            <span class="fw-medium"><?php echo htmlspecialchars($org['name']); ?></span>
                                                            <?php if ($org['has_user_account'] > 0): ?>
                                                                <br><small class="text-success">Has user account</small>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="fw-medium"><?php echo htmlspecialchars($org['registration_number'] ?? 'N/A'); ?></span>
                                                </td>
                                                <td>
                                                    <?php if ($org['phone']): ?>
                                                        <i class="bi bi-telephone text-muted me-1"></i><?php echo htmlspecialchars($org['phone']); ?><br>
                                                    <?php endif; ?>
                                                    <?php if ($org['email']): ?>
                                                        <small><i class="bi bi-envelope text-muted me-1"></i><?php echo htmlspecialchars($org['email']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($org['physical_address']): ?>
                                                        <small><?php echo htmlspecialchars($org['physical_address']); ?></small>
                                                    <?php endif; ?>
                                                    <?php if ($org['postal_address']): ?>
                                                        <br><small class="text-muted">P.O. Box <?php echo htmlspecialchars($org['postal_address']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="d-flex flex-wrap gap-1">
                                                        <?php if ($org['total_ownerships'] > 0): ?>
                                                            <span class="badge bg-primary" title="Land Holdings">
                                                                <i class="bi bi-file-text"></i> <?php echo $org['total_ownerships']; ?>
                                                            </span>
                                                        <?php endif; ?>
                                                        <?php if ($org['total_applications'] > 0): ?>
                                                            <span class="badge bg-info" title="Applications">
                                                                <i class="bi bi-file-earmark"></i> <?php echo $org['total_applications']; ?>
                                                            </span>
                                                        <?php endif; ?>
                                                        <?php if ($org['total_documents'] > 0): ?>
                                                            <span class="badge bg-secondary" title="Documents">
                                                                <i class="bi bi-files"></i> <?php echo $org['total_documents']; ?>
                                                            </span>
                                                        <?php endif; ?>
                                                        <?php if ($org['total_payments'] > 0): ?>
                                                            <span class="badge bg-success" title="Payments">
                                                                <i class="bi bi-currency-dollar"></i> <?php echo $org['total_payments']; ?>
                                                            </span>
                                                        <?php endif; ?>
                                                        <?php if ($org['total_encumbrances'] > 0): ?>
                                                            <span class="badge bg-danger" title="Encumbrances">
                                                                <i class="bi bi-shield"></i> <?php echo $org['total_encumbrances']; ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php if ($org['total_paid'] > 0): ?>
                                                        <small class="text-success d-block mt-1">
                                                            Paid: $<?php echo number_format($org['total_paid'], 2); ?>
                                                        </small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($org['last_activity']): ?>
                                                        <small class="text-muted">
                                                            <?php echo date('d/m/Y', strtotime($org['last_activity'])); ?>
                                                        </small>
                                                    <?php else: ?>
                                                        <small class="text-muted">No activity</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <small class="text-muted">
                                                        <?php echo date('d/m/Y', strtotime($org['created_at'])); ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <button class="btn btn-outline-primary" 
                                                                onclick="editOrganization(<?php echo htmlspecialchars(json_encode($org)); ?>)"
                                                                data-bs-toggle="modal" data-bs-target="#editOrganizationModal">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                        <a href="?delete=<?php echo $org['id']; ?>" 
                                                           class="btn btn-outline-danger"
                                                           onclick="return confirm('Delete this organization? This action cannot be undone.')">
                                                            <i class="bi bi-trash"></i>
                                                        </a>
                                                        <button class="btn btn-outline-info" 
                                                                onclick="viewOrganization(<?php echo htmlspecialchars(json_encode($org)); ?>)"
                                                                data-bs-toggle="modal" data-bs-target="#viewOrganizationModal">
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

                    <!-- Recent Organizations -->
                    <div class="row mt-4">
                        <div class="col-12">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h5 class="card-title mb-0 fw-bold">Recently Added Organizations</h5>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <?php foreach ($recent_orgs as $recent): ?>
                                        <div class="col-md-2 mb-2">
                                            <div class="border rounded p-2 text-center">
                                                <i class="bi bi-building text-warning fs-4"></i>
                                                <div class="small text-truncate"><?php echo htmlspecialchars($recent['name']); ?></div>
                                                <small class="text-muted"><?php echo date('d/m', strtotime($recent['created_at'])); ?></small>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
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

    <!-- Add Organization Modal -->
    <div class="modal fade" id="addOrganizationModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="add">
                    <div class="modal-header">
                        <h5 class="modal-title">Add New Organization</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <ul class="nav nav-tabs mb-3" id="addOrgTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="basic-info-tab" data-bs-toggle="tab" type="button" role="tab">Basic Information</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="contact-tab" data-bs-toggle="tab" type="button" role="tab">Contact Details</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="additional-tab" data-bs-toggle="tab" type="button" role="tab">Additional Info</button>
                            </li>
                        </ul>
                        
                        <div class="tab-content">
                            <!-- Basic Information Tab -->
                            <div class="tab-pane active" id="basic-info" role="tabpanel">
                                <div class="row">
                                    <div class="col-md-12 mb-3">
                                        <label class="form-label">Organization Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="name" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Registration Number</label>
                                        <input type="text" class="form-control" name="registration_number" 
                                               placeholder="e.g., REG/2024/001">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Registration Date</label>
                                        <input type="date" class="form-control" name="registration_date">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Registration Authority</label>
                                        <input type="text" class="form-control" name="registration_authority" 
                                               placeholder="e.g., Ministry of Justice">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Business Type</label>
                                        <select class="form-select" name="business_type">
                                            <option value="">Select Type</option>
                                            <option value="Private Limited">Private Limited Company</option>
                                            <option value="Public Limited">Public Limited Company</option>
                                            <option value="Partnership">Partnership</option>
                                            <option value="Sole Proprietorship">Sole Proprietorship</option>
                                            <option value="Non-Profit">Non-Profit Organization</option>
                                            <option value="Government">Government Agency</option>
                                            <option value="NGO">NGO</option>
                                            <option value="Cooperative">Cooperative Society</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Contact Details Tab -->
                            <div class="tab-pane" id="contact-info" role="tabpanel">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Phone Number</label>
                                        <input type="tel" class="form-control" name="phone" 
                                               placeholder="+211 123 456 789">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Email Address</label>
                                        <input type="email" class="form-control" name="email" 
                                               placeholder="info@company.com">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Website</label>
                                        <input type="url" class="form-control" name="website" 
                                               placeholder="https://www.company.com">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Tax ID / VAT Number</label>
                                        <input type="text" class="form-control" name="tax_id">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Postal Address</label>
                                        <input type="text" class="form-control" name="postal_address" 
                                               placeholder="P.O. Box 123">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Physical Address</label>
                                        <input type="text" class="form-control" name="physical_address" 
                                               placeholder="Street, Building, Area">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">City</label>
                                        <input type="text" class="form-control" name="city" value="Juba">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">State/Province</label>
                                        <input type="text" class="form-control" name="state" value="Central Equatoria">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Country</label>
                                        <input type="text" class="form-control" name="country" value="South Sudan">
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Additional Info Tab -->
                            <div class="tab-pane" id="additional-info" role="tabpanel">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Contact Person Name</label>
                                        <input type="text" class="form-control" name="contact_person">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Contact Person Phone</label>
                                        <input type="tel" class="form-control" name="contact_person_phone">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Contact Person Email</label>
                                        <input type="email" class="form-control" name="contact_person_email">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Number of Employees</label>
                                        <input type="number" class="form-control" name="employee_count" min="0">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Annual Revenue (USD)</label>
                                        <input type="number" class="form-control" name="annual_revenue" min="0" step="0.01">
                                    </div>
                                    <div class="col-md-12 mb-3">
                                        <label class="form-label">Description / Notes</label>
                                        <textarea class="form-control" name="description" rows="3"></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Organization</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Organization Modal -->
    <div class="modal fade" id="editOrganizationModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="org_id" id="editOrgId">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Organization</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Organization Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="name" id="editName" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Registration Number</label>
                                <input type="text" class="form-control" name="registration_number" id="editRegNumber">
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
                                <input type="text" class="form-control" name="postal_address" id="editPostal">
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Physical Address</label>
                                <input type="text" class="form-control" name="physical_address" id="editPhysical">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Organization</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Organization Modal -->
    <div class="modal fade" id="viewOrganizationModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Organization Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Organization Name:</label>
                            <p id="viewName" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3">
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
                            <p id="viewPostal" class="mb-0"></p>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="fw-bold">Physical Address:</label>
                            <p id="viewPhysical" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Created:</label>
                            <p id="viewCreated" class="mb-0"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Last Updated:</label>
                            <p id="viewUpdated" class="mb-0"></p>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <h6 class="fw-bold">Statistics</h6>
                    <div class="row">
                        <div class="col-md-3 col-6 mb-2">
                            <div class="border rounded p-2 text-center">
                                <span class="badge bg-primary" id="viewOwnerships">0</span>
                                <small class="d-block">Land Holdings</small>
                            </div>
                        </div>
                        <div class="col-md-3 col-6 mb-2">
                            <div class="border rounded p-2 text-center">
                                <span class="badge bg-info" id="viewApplications">0</span>
                                <small class="d-block">Applications</small>
                            </div>
                        </div>
                        <div class="col-md-3 col-6 mb-2">
                            <div class="border rounded p-2 text-center">
                                <span class="badge bg-secondary" id="viewDocuments">0</span>
                                <small class="d-block">Documents</small>
                            </div>
                        </div>
                        <div class="col-md-3 col-6 mb-2">
                            <div class="border rounded p-2 text-center">
                                <span class="badge bg-success" id="viewPayments">0</span>
                                <small class="d-block">Payments</small>
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
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Monthly Registrations Chart
        const monthlyCtx = document.getElementById('monthlyChart')?.getContext('2d');
        if (monthlyCtx) {
            new Chart(monthlyCtx, {
                type: 'line',
                data: {
                    labels: <?php 
                        $months = array_column($monthly_stats, 'month');
                        echo json_encode(array_map(function($m) { 
                            return date('M Y', strtotime($m . '-01')); 
                        }, $months));
                    ?>,
                    datasets: [{
                        label: 'New Organizations',
                        data: <?php echo json_encode(array_column($monthly_stats, 'count')); ?>,
                        borderColor: '#ffc107',
                        backgroundColor: 'rgba(255, 193, 7, 0.1)',
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

        // Edit organization function
        function editOrganization(org) {
            document.getElementById('editOrgId').value = org.id;
            document.getElementById('editName').value = org.name || '';
            document.getElementById('editRegNumber').value = org.registration_number || '';
            document.getElementById('editPhone').value = org.phone || '';
            document.getElementById('editEmail').value = org.email || '';
            document.getElementById('editPostal').value = org.postal_address || '';
            document.getElementById('editPhysical').value = org.physical_address || '';
        }

        // View organization function
        function viewOrganization(org) {
            document.getElementById('viewName').textContent = org.name || 'N/A';
            document.getElementById('viewRegNumber').textContent = org.registration_number || 'N/A';
            document.getElementById('viewPhone').textContent = org.phone || 'N/A';
            document.getElementById('viewEmail').textContent = org.email || 'N/A';
            document.getElementById('viewPostal').textContent = org.postal_address || 'N/A';
            document.getElementById('viewPhysical').textContent = org.physical_address || 'N/A';
            document.getElementById('viewCreated').textContent = new Date(org.created_at).toLocaleString();
            document.getElementById('viewUpdated').textContent = org.updated_at ? new Date(org.updated_at).toLocaleString() : 'N/A';
            
            document.getElementById('viewOwnerships').textContent = org.total_ownerships || 0;
            document.getElementById('viewApplications').textContent = org.total_applications || 0;
            document.getElementById('viewDocuments').textContent = org.total_documents || 0;
            document.getElementById('viewPayments').textContent = org.total_payments || 0;
        }
    </script>

    <style>
        .org-avatar {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
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
        
        .badge.bg-bronze {
            background-color: #cd7f32;
            color: white;
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
        
        .nav-tabs .nav-link {
            color: #6c757d;
            font-weight: 500;
        }
        
        .nav-tabs .nav-link.active {
            color: #ffc107;
            font-weight: 600;
        }
    </style>

</body>
</html>