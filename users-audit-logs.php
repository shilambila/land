<?php
ini_set('display_errors', 1); 
error_reporting(E_ALL);

// users-audit-logs.php
include("head.php");
require_once 'db_connection.php';

// ============================================================================
// HANDLE ACTIONS
// ============================================================================

$message = '';
$messageType = '';

// Clear old logs (admin only)
if (isset($_POST['clear_logs']) && $_POST['clear_logs'] === 'yes') {
    $days = (int)($_POST['older_than'] ?? 30);
    try {
        $deleted = executeQuery($conn, 
            "DELETE FROM audit_logs WHERE change_time < DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$days]
        );
        $count = $deleted->rowCount();
        $message = "Cleared $count log entries older than $days days";
        $messageType = "success";
    } catch (Exception $e) {
        $message = "Error clearing logs: " . $e->getMessage();
        $messageType = "danger";
    }
}

// Export logs
if (isset($_GET['export'])) {
    $format = $_GET['export'];
    $from_date = $_GET['from_date'] ?? date('Y-m-d', strtotime('-30 days'));
    $to_date = $_GET['to_date'] ?? date('Y-m-d');
    
    $logs = fetchAll($conn, "
        SELECT al.*, u.username 
        FROM audit_logs al
        LEFT JOIN users u ON al.user_id = u.id
        WHERE DATE(al.change_time) BETWEEN ? AND ?
        ORDER BY al.change_time DESC
    ", [$from_date, $to_date]);
    
    if ($format === 'csv') {
        // Export as CSV
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="audit_logs_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, ['ID', 'Table', 'Record ID', 'Action', 'User', 'Change Time', 'Old Values', 'New Values']);
        
        foreach ($logs as $log) {
            fputcsv($output, [
                $log['id'],
                $log['table_name'],
                $log['record_id'],
                $log['action'],
                $log['username'] ?? 'System',
                $log['change_time'],
                $log['old_values'],
                $log['new_values']
            ]);
        }
        fclose($output);
        exit;
    } elseif ($format === 'json') {
        // Export as JSON
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="audit_logs_' . date('Y-m-d') . '.json"');
        echo json_encode($logs, JSON_PRETTY_PRINT);
        exit;
    }
}

// ============================================================================
// FILTERS AND PAGINATION
// ============================================================================

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 50;
$offset = ($page - 1) * $limit;

// Build filter conditions
$where_conditions = [];
$params = [];

// Date filter
if (!empty($_GET['from_date'])) {
    $where_conditions[] = "DATE(al.change_time) >= ?";
    $params[] = $_GET['from_date'];
}
if (!empty($_GET['to_date'])) {
    $where_conditions[] = "DATE(al.change_time) <= ?";
    $params[] = $_GET['to_date'];
}

// Table filter
if (!empty($_GET['table'])) {
    $where_conditions[] = "al.table_name = ?";
    $params[] = $_GET['table'];
}

// Action filter
if (!empty($_GET['action'])) {
    $where_conditions[] = "al.action = ?";
    $params[] = $_GET['action'];
}

// User filter
if (!empty($_GET['user_id'])) {
    $where_conditions[] = "al.user_id = ?";
    $params[] = $_GET['user_id'];
}

// Search in old/new values
if (!empty($_GET['search'])) {
    $search = '%' . $_GET['search'] . '%';
    $where_conditions[] = "(al.old_values LIKE ? OR al.new_values LIKE ?)";
    $params[] = $search;
    $params[] = $search;
}

$where_clause = empty($where_conditions) ? "" : "WHERE " . implode(" AND ", $where_conditions);

// Get total count for pagination
$count_sql = "SELECT COUNT(*) as total FROM audit_logs al $where_clause";
$total_result = executeQuery($conn, $count_sql, $params);
$total_rows = $total_result->fetch()['total'];
$total_pages = ceil($total_rows / $limit);

// Get audit logs with pagination
$sql = "
    SELECT al.*, u.username 
    FROM audit_logs al
    LEFT JOIN users u ON al.user_id = u.id
    $where_clause
    ORDER BY al.change_time DESC
    LIMIT ? OFFSET ?
";

$params[] = $limit;
$params[] = $offset;

$audit_logs = fetchAll($conn, $sql, $params);

// Get unique tables for filter dropdown
$tables = fetchAll($conn, "
    SELECT DISTINCT table_name 
    FROM audit_logs 
    ORDER BY table_name
");

// Get summary statistics
$summary = fetchOne($conn, "
    SELECT 
        COUNT(*) as total_logs,
        COUNT(DISTINCT DATE(change_time)) as active_days,
        COUNT(DISTINCT user_id) as active_users,
        SUM(CASE WHEN action = 'INSERT' THEN 1 ELSE 0 END) as total_inserts,
        SUM(CASE WHEN action = 'UPDATE' THEN 1 ELSE 0 END) as total_updates,
        SUM(CASE WHEN action = 'DELETE' THEN 1 ELSE 0 END) as total_deletes
    FROM audit_logs
    WHERE change_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)
");

// Get activity by hour for today
$hourly_activity = fetchAll($conn, "
    SELECT 
        HOUR(change_time) as hour,
        COUNT(*) as count
    FROM audit_logs
    WHERE DATE(change_time) = CURDATE()
    GROUP BY HOUR(change_time)
    ORDER BY hour
");

// Get top active users
$top_users = fetchAll($conn, "
    SELECT 
        u.username,
        COUNT(*) as action_count,
        MAX(al.change_time) as last_action
    FROM audit_logs al
    JOIN users u ON al.user_id = u.id
    WHERE al.change_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY al.user_id
    ORDER BY action_count DESC
    LIMIT 5
");

// Get activity by table
$table_activity = fetchAll($conn, "
    SELECT 
        table_name,
        COUNT(*) as count,
        SUM(CASE WHEN action = 'INSERT' THEN 1 ELSE 0 END) as inserts,
        SUM(CASE WHEN action = 'UPDATE' THEN 1 ELSE 0 END) as updates,
        SUM(CASE WHEN action = 'DELETE' THEN 1 ELSE 0 END) as deletes
    FROM audit_logs
    WHERE change_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY table_name
    ORDER BY count DESC
    LIMIT 10
");
?>

<body data-page="audit-logs" class="audit-logs-page">
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
                            <h1 class="h3 mb-0">Audit Logs</h1>
                            <p class="text-muted mb-0">Track all system activities and changes</p>
                        </div>
                        <div class="btn-group">
                            <button type="button" class="btn btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown">
                                <i class="bi bi-download me-2"></i>Export
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li>
                                    <a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#exportModal">
                                        <i class="bi bi-file-earmark-spreadsheet me-2"></i>Export as CSV
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#exportModal">
                                        <i class="bi bi-file-earmark-code me-2"></i>Export as JSON
                                    </a>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <a class="dropdown-item text-danger" href="#" data-bs-toggle="modal" data-bs-target="#clearLogsModal">
                                        <i class="bi bi-trash me-2"></i>Clear Old Logs
                                    </a>
                                </li>
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

                    <!-- Summary Cards -->
                    <div class="row g-4 mb-5">
                        <div class="col-xl-3 col-md-6">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0 me-3">
                                            <div class="bg-primary bg-opacity-10 rounded-circle p-3">
                                                <i class="bi bi-journal-text text-primary fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Total Logs (30 days)</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['total_logs'] ?? 0); ?></h3>
                                            <small class="text-muted"><?php echo $summary['active_days'] ?? 0; ?> active days</small>
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
                                                <i class="bi bi-person-plus text-success fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Inserts</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['total_inserts'] ?? 0); ?></h3>
                                            <small class="text-success">New records</small>
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
                                                <i class="bi bi-pencil text-warning fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Updates</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['total_updates'] ?? 0); ?></h3>
                                            <small class="text-warning">Modified records</small>
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
                                                <i class="bi bi-trash text-danger fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Deletes</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['total_deletes'] ?? 0); ?></h3>
                                            <small class="text-danger">Deleted records</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Activity Charts Row -->
                    <div class="row g-4 mb-5">
                        <!-- Hourly Activity -->
                        <div class="col-lg-6">
                            <div class="card border-0 shadow-sm h-100">
                                <div class="card-header bg-white py-3">
                                    <h5 class="card-title mb-0 fw-bold">Today's Activity by Hour</h5>
                                </div>
                                <div class="card-body">
                                    <canvas id="hourlyChart" style="height: 200px;"></canvas>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Top Users -->
                        <div class="col-lg-6">
                            <div class="card border-0 shadow-sm h-100">
                                <div class="card-header bg-white py-3">
                                    <h5 class="card-title mb-0 fw-bold">Most Active Users (7 days)</h5>
                                </div>
                                <div class="card-body">
                                    <?php foreach ($top_users as $index => $user): ?>
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <div class="d-flex align-items-center">
                                            <span class="badge bg-primary me-3">#<?php echo $index + 1; ?></span>
                                            <div>
                                                <span class="fw-medium"><?php echo htmlspecialchars($user['username']); ?></span>
                                                <br>
                                                <small class="text-muted">
                                                    Last action: <?php echo date('M d, H:i', strtotime($user['last_action'])); ?>
                                                </small>
                                            </div>
                                        </div>
                                        <span class="badge bg-light text-dark fs-6">
                                            <?php echo number_format($user['action_count']); ?> actions
                                        </span>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Table Activity -->
                    <div class="row g-4 mb-5">
                        <div class="col-12">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h5 class="card-title mb-0 fw-bold">Activity by Table (30 days)</h5>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-sm">
                                            <thead>
                                                <tr>
                                                    <th>Table</th>
                                                    <th>Total Actions</th>
                                                    <th>Inserts</th>
                                                    <th>Updates</th>
                                                    <th>Deletes</th>
                                                    <th>Activity Breakdown</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($table_activity as $table): ?>
                                                <tr>
                                                    <td class="fw-medium"><?php echo $table['table_name']; ?></td>
                                                    <td><?php echo number_format($table['count']); ?></td>
                                                    <td class="text-success"><?php echo number_format($table['inserts']); ?></td>
                                                    <td class="text-warning"><?php echo number_format($table['updates']); ?></td>
                                                    <td class="text-danger"><?php echo number_format($table['deletes']); ?></td>
                                                    <td style="width: 200px;">
                                                        <div class="progress" style="height: 20px;">
                                                            <div class="progress-bar bg-success" 
                                                                 style="width: <?php echo ($table['inserts'] / $table['count']) * 100; ?>%"
                                                                 title="Inserts: <?php echo $table['inserts']; ?>">
                                                            </div>
                                                            <div class="progress-bar bg-warning" 
                                                                 style="width: <?php echo ($table['updates'] / $table['count']) * 100; ?>%"
                                                                 title="Updates: <?php echo $table['updates']; ?>">
                                                            </div>
                                                            <div class="progress-bar bg-danger" 
                                                                 style="width: <?php echo ($table['deletes'] / $table['count']) * 100; ?>%"
                                                                 title="Deletes: <?php echo $table['deletes']; ?>">
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
                        </div>
                    </div>

                    <!-- Filters -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-white py-3">
                            <h5 class="card-title mb-0 fw-bold">
                                <i class="bi bi-funnel me-2"></i>Filter Logs
                            </h5>
                        </div>
                        <div class="card-body">
                            <form method="GET" class="row g-3">
                                <div class="col-md-3">
                                    <label class="form-label">From Date</label>
                                    <input type="date" class="form-control" name="from_date" 
                                           value="<?php echo htmlspecialchars($_GET['from_date'] ?? ''); ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">To Date</label>
                                    <input type="date" class="form-control" name="to_date" 
                                           value="<?php echo htmlspecialchars($_GET['to_date'] ?? ''); ?>">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Table</label>
                                    <select class="form-select" name="table">
                                        <option value="">All Tables</option>
                                        <?php foreach ($tables as $t): ?>
                                        <option value="<?php echo $t['table_name']; ?>" 
                                            <?php echo ($_GET['table'] ?? '') == $t['table_name'] ? 'selected' : ''; ?>>
                                            <?php echo $t['table_name']; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Action</label>
                                    <select class="form-select" name="action">
                                        <option value="">All Actions</option>
                                        <option value="INSERT" <?php echo ($_GET['action'] ?? '') == 'INSERT' ? 'selected' : ''; ?>>INSERT</option>
                                        <option value="UPDATE" <?php echo ($_GET['action'] ?? '') == 'UPDATE' ? 'selected' : ''; ?>>UPDATE</option>
                                        <option value="DELETE" <?php echo ($_GET['action'] ?? '') == 'DELETE' ? 'selected' : ''; ?>>DELETE</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Search</label>
                                    <input type="text" class="form-control" name="search" 
                                           placeholder="Search in values..."
                                           value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                                </div>
                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-search me-2"></i>Apply Filters
                                    </button>
                                    <a href="users-audit-logs.php" class="btn btn-secondary">
                                        <i class="bi bi-x-circle me-2"></i>Clear Filters
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Audit Logs Table -->
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0 fw-bold">Audit Trail</h5>
                            <span class="badge bg-primary"><?php echo number_format($total_rows); ?> Total Records</span>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>ID</th>
                                            <th>Time</th>
                                            <th>User</th>
                                            <th>Table</th>
                                            <th>Record ID</th>
                                            <th>Action</th>
                                            <th>Changes</th>
                                            <th>Details</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($audit_logs)): ?>
                                        <tr>
                                            <td colspan="8" class="text-center py-4 text-muted">
                                                <i class="bi bi-inbox fs-1 d-block mb-3"></i>
                                                No audit logs found
                                            </td>
                                        </tr>
                                        <?php else: ?>
                                            <?php foreach ($audit_logs as $log): ?>
                                            <tr>
                                                <td>
                                                    <span class="fw-medium">#<?php echo $log['id']; ?></span>
                                                </td>
                                                <td>
                                                    <span class="fw-medium">
                                                        <?php echo date('M d, Y', strtotime($log['change_time'])); ?>
                                                    </span>
                                                    <br>
                                                    <small class="text-muted">
                                                        <?php echo date('H:i:s', strtotime($log['change_time'])); ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <?php if ($log['username']): ?>
                                                        <span class="fw-medium"><?php echo htmlspecialchars($log['username']); ?></span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">System</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge bg-light text-dark">
                                                        <?php echo $log['table_name']; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge bg-secondary">
                                                        <?php echo $log['record_id']; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php
                                                    $action_class = [
                                                        'INSERT' => 'success',
                                                        'UPDATE' => 'warning',
                                                        'DELETE' => 'danger'
                                                    ][$log['action']] ?? 'secondary';
                                                    ?>
                                                    <span class="badge bg-<?php echo $action_class; ?>">
                                                        <?php echo $log['action']; ?>
                                                    </span>
                                                </td>
                                                <td style="max-width: 300px;">
                                                    <button class="btn btn-sm btn-outline-primary" 
                                                            onclick="showChanges(<?php echo htmlspecialchars(json_encode($log)); ?>)">
                                                        <i class="bi bi-eye"></i> View Changes
                                                    </button>
                                                </td>
                                                <td>
                                                    <a href="#" class="text-primary" onclick="showLogDetails(<?php echo htmlspecialchars(json_encode($log)); ?>)">
                                                        <i class="bi bi-info-circle"></i>
                                                    </a>
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
                </div>
            </main>

            <!-- Footer -->
            <?php include("footer.php"); ?>

        </div>
    </div>

    <!-- Export Modal -->
    <div class="modal fade" id="exportModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="GET">
                    <input type="hidden" name="export" id="exportFormat" value="">
                    <div class="modal-header">
                        <h5 class="modal-title">Export Audit Logs</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">From Date</label>
                            <input type="date" class="form-control" name="from_date" 
                                   value="<?php echo date('Y-m-d', strtotime('-30 days')); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">To Date</label>
                            <input type="date" class="form-control" name="to_date" 
                                   value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>
                            This will export all logs within the selected date range.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" onclick="document.getElementById('exportFormat').value='csv'">
                            <i class="bi bi-file-earmark-spreadsheet me-2"></i>Export as CSV
                        </button>
                        <button type="submit" class="btn btn-primary" onclick="document.getElementById('exportFormat').value='json'">
                            <i class="bi bi-file-earmark-code me-2"></i>Export as JSON
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Clear Logs Modal -->
    <div class="modal fade" id="clearLogsModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="clear_logs" value="yes">
                    <div class="modal-header">
                        <h5 class="modal-title text-danger">Clear Old Logs</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Delete logs older than (days)</label>
                            <input type="number" class="form-control" name="older_than" value="30" min="1" max="365" required>
                        </div>
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            <strong>Warning!</strong> This action cannot be undone. Make sure you have exported important logs first.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger" onclick="return confirm('Are you absolutely sure?')">
                            <i class="bi bi-trash me-2"></i>Clear Logs
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Changes Modal -->
    <div class="modal fade" id="changesModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Record Changes</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6 class="text-danger">Old Values</h6>
                            <pre id="oldValues" class="bg-light p-3 rounded" style="max-height: 300px; overflow: auto;"></pre>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-success">New Values</h6>
                            <pre id="newValues" class="bg-light p-3 rounded" style="max-height: 300px; overflow: auto;"></pre>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Details Modal -->
    <div class="modal fade" id="detailsModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Log Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <table class="table table-bordered">
                        <tr>
                            <th style="width: 120px;">Log ID:</th>
                            <td id="detailId"></td>
                        </tr>
                        <tr>
                            <th>Time:</th>
                            <td id="detailTime"></td>
                        </tr>
                        <tr>
                            <th>User:</th>
                            <td id="detailUser"></td>
                        </tr>
                        <tr>
                            <th>Table:</th>
                            <td id="detailTable"></td>
                        </tr>
                        <tr>
                            <th>Record ID:</th>
                            <td id="detailRecordId"></td>
                        </tr>
                        <tr>
                            <th>Action:</th>
                            <td id="detailAction"></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Hourly Activity Chart
        const hourlyCtx = document.getElementById('hourlyChart').getContext('2d');
        new Chart(hourlyCtx, {
            type: 'bar',
            data: {
                labels: <?php 
                    $hours = array_fill(0, 24, 0);
                    foreach ($hourly_activity as $h) {
                        $hours[$h['hour']] = $h['count'];
                    }
                    echo json_encode(array_map(function($h) { return $h . ':00'; }, range(0, 23)));
                ?>,
                datasets: [{
                    label: 'Activities',
                    data: <?php echo json_encode(array_values($hours)); ?>,
                    backgroundColor: '#0d6efd',
                    borderColor: '#0a58ca',
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
                            stepSize: 1
                        }
                    }
                }
            }
        });

        // Show changes in modal
        function showChanges(log) {
            document.getElementById('oldValues').textContent = 
                log.old_values ? JSON.stringify(JSON.parse(log.old_values), null, 2) : 'No old values';
            document.getElementById('newValues').textContent = 
                log.new_values ? JSON.stringify(JSON.parse(log.new_values), null, 2) : 'No new values';
            
            new bootstrap.Modal(document.getElementById('changesModal')).show();
        }

        // Show log details
        function showLogDetails(log) {
            document.getElementById('detailId').textContent = log.id;
            document.getElementById('detailTime').textContent = new Date(log.change_time).toLocaleString();
            document.getElementById('detailUser').textContent = log.username || 'System';
            document.getElementById('detailTable').textContent = log.table_name;
            document.getElementById('detailRecordId').textContent = log.record_id;
            document.getElementById('detailAction').textContent = log.action;
            
            new bootstrap.Modal(document.getElementById('detailsModal')).show();
        }

        // Auto-refresh every 60 seconds (optional)
        // setInterval(() => location.reload(), 60000);
    </script>

    <style>
        .progress {
            border-radius: 4px;
            overflow: hidden;
        }
        
        .progress-bar {
            transition: width 0.3s ease;
        }
        
        pre {
            font-size: 11px;
            line-height: 1.4;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        
        .table td {
            vertical-align: middle;
        }
        
        .bg-opacity-10 {
            --bs-bg-opacity: 0.1;
        }
        
        .pagination {
            margin-bottom: 0;
        }
        
        .page-link {
            padding: 0.375rem 0.75rem;
        }
    </style>

</body>
</html>