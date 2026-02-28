<?php
ini_set('display_errors', 1); 
error_reporting(E_ALL);

// audit-logs.php
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
// SAFE JSON DECODE FUNCTION
// ============================================================================

function safeJsonDecode($json, $assoc = true) {
    if ($json === null || $json === '') {
        return $assoc ? [] : null;
    }
    return json_decode($json, $assoc);
}

// ============================================================================
// HANDLE ACTIONS
// ============================================================================

$message = '';
$messageType = '';

// Clear old logs (admin only)
if (isset($_POST['action']) && $_POST['action'] === 'clear_old') {
    $days = $_POST['days'] ?? 90;
    
    try {
        $cutoff_date = date('Y-m-d H:i:s', strtotime("-$days days"));
        $result = executeQuery($conn, "
            DELETE FROM audit_logs WHERE change_time < ?
        ", [$cutoff_date]);
        
        $rowCount = $result->rowCount();
        $message = "Cleared $rowCount log entries older than $days days";
        $messageType = "success";
        
    } catch (Exception $e) {
        $message = "Error clearing logs: " . $e->getMessage();
        $messageType = "danger";
    }
}

// Export logs
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    exportLogsToCSV($conn);
    exit;
}

function exportLogsToCSV($conn) {
    // Get filter parameters
    $table_filter = $_GET['table'] ?? '';
    $action_filter = $_GET['action'] ?? '';
    $user_filter = $_GET['user_id'] ?? '';
    $date_from = $_GET['date_from'] ?? '';
    $date_to = $_GET['date_to'] ?? '';
    
    // Build query
    $where_conditions = [];
    $params = [];
    
    if (!empty($table_filter)) {
        $where_conditions[] = "al.table_name = ?";
        $params[] = $table_filter;
    }
    
    if (!empty($action_filter)) {
        $where_conditions[] = "al.action = ?";
        $params[] = $action_filter;
    }
    
    if (!empty($user_filter)) {
        $where_conditions[] = "al.user_id = ?";
        $params[] = $user_filter;
    }
    
    if (!empty($date_from)) {
        $where_conditions[] = "DATE(al.change_time) >= ?";
        $params[] = $date_from;
    }
    
    if (!empty($date_to)) {
        $where_conditions[] = "DATE(al.change_time) <= ?";
        $params[] = $date_to;
    }
    
    $where_clause = empty($where_conditions) ? "" : "WHERE " . implode(" AND ", $where_conditions);
    
    $logs = fetchAll($conn, "
        SELECT 
            al.id,
            al.table_name,
            al.record_id,
            al.action,
            al.user_id,
            u.username,
            al.change_time,
            al.old_values,
            al.new_values
        FROM audit_logs al
        LEFT JOIN users u ON al.user_id = u.id
        $where_clause
        ORDER BY al.change_time DESC
    ", $params);
    
    // Set headers for CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=audit_logs_' . date('Y-m-d_His') . '.csv');
    
    // Create output stream
    $output = fopen('php://output', 'w');
    
    // Add UTF-8 BOM for Excel compatibility
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Add headers
    fputcsv($output, [
        'ID', 'Table', 'Record ID', 'Action', 'User ID', 'Username', 'Change Time', 'Old Values', 'New Values'
    ]);
    
    // Add data rows
    foreach ($logs as $log) {
        fputcsv($output, [
            $log['id'],
            $log['table_name'],
            $log['record_id'],
            $log['action'],
            $log['user_id'],
            $log['username'] ?? 'System',
            $log['change_time'],
            $log['old_values'] ?? '',
            $log['new_values'] ?? ''
        ]);
    }
    
    fclose($output);
    exit;
}

// ============================================================================
// GET FILTERS
// ============================================================================

$table_filter = $_GET['table'] ?? '';
$action_filter = $_GET['action'] ?? '';
$user_filter = $_GET['user_id'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$search = $_GET['search'] ?? '';

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 50;
$offset = ($page - 1) * $limit;

// ============================================================================
// BUILD QUERY WITH FILTERS
// ============================================================================

$where_conditions = [];
$params = [];

if (!empty($table_filter)) {
    $where_conditions[] = "al.table_name = ?";
    $params[] = $table_filter;
}

if (!empty($action_filter)) {
    $where_conditions[] = "al.action = ?";
    $params[] = $action_filter;
}

if (!empty($user_filter)) {
    $where_conditions[] = "al.user_id = ?";
    $params[] = $user_filter;
}

if (!empty($date_from)) {
    $where_conditions[] = "DATE(al.change_time) >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $where_conditions[] = "DATE(al.change_time) <= ?";
    $params[] = $date_to;
}

if (!empty($search)) {
    $where_conditions[] = "(al.table_name LIKE ? OR al.record_id LIKE ? OR u.username LIKE ? OR al.old_values LIKE ? OR al.new_values LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

$where_clause = empty($where_conditions) ? "" : "WHERE " . implode(" AND ", $where_conditions);

// Get total count for pagination
$count_sql = "
    SELECT COUNT(*) as total 
    FROM audit_logs al
    LEFT JOIN users u ON al.user_id = u.id
    $where_clause
";
$total_result = safeFetchOne($conn, $count_sql, $params);
$total_rows = $total_result['total'] ?? 0;
$total_pages = ceil($total_rows / $limit);

// Get audit logs with pagination
$logs = safeFetchAll($conn, "
    SELECT 
        al.id,
        al.table_name,
        al.record_id,
        al.action,
        al.user_id,
        u.username,
        al.change_time,
        al.old_values,
        al.new_values
    FROM audit_logs al
    LEFT JOIN users u ON al.user_id = u.id
    $where_clause
    ORDER BY al.change_time DESC
    LIMIT ? OFFSET ?
", array_merge($params, [$limit, $offset]), []);

// ============================================================================
// GET SUMMARY STATISTICS
// ============================================================================

$summary = safeFetchOne($conn, "
    SELECT 
        COUNT(*) as total_logs,
        COUNT(DISTINCT table_name) as tables_audited,
        COUNT(DISTINCT user_id) as active_users,
        MIN(change_time) as oldest_log,
        MAX(change_time) as newest_log,
        SUM(CASE WHEN action = 'INSERT' THEN 1 ELSE 0 END) as inserts,
        SUM(CASE WHEN action = 'UPDATE' THEN 1 ELSE 0 END) as updates,
        SUM(CASE WHEN action = 'DELETE' THEN 1 ELSE 0 END) as deletes,
        SUM(CASE WHEN change_time >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 ELSE 0 END) as last_24h
    FROM audit_logs
");

// Get activity by table
$table_stats = safeFetchAll($conn, "
    SELECT 
        table_name,
        COUNT(*) as count,
        SUM(CASE WHEN action = 'INSERT' THEN 1 ELSE 0 END) as inserts,
        SUM(CASE WHEN action = 'UPDATE' THEN 1 ELSE 0 END) as updates,
        SUM(CASE WHEN action = 'DELETE' THEN 1 ELSE 0 END) as deletes,
        MAX(change_time) as last_activity
    FROM audit_logs
    GROUP BY table_name
    ORDER BY count DESC
", [], []);

// Get activity by hour (last 24h)
$hourly_activity = safeFetchAll($conn, "
    SELECT 
        HOUR(change_time) as hour,
        COUNT(*) as count
    FROM audit_logs
    WHERE change_time >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    GROUP BY HOUR(change_time)
    ORDER BY hour
", [], []);

// Get top users
$top_users = safeFetchAll($conn, "
    SELECT 
        u.id,
        u.username,
        u.role,
        COUNT(*) as action_count,
        MAX(al.change_time) as last_action
    FROM audit_logs al
    JOIN users u ON al.user_id = u.id
    GROUP BY u.id, u.username, u.role
    ORDER BY action_count DESC
    LIMIT 10
", [], []);

// Get distinct tables for filter
$tables = safeFetchAll($conn, "
    SELECT DISTINCT table_name 
    FROM audit_logs 
    ORDER BY table_name
", [], []);

// Get distinct actions for filter
$actions = ['INSERT', 'UPDATE', 'DELETE'];

// Get users for filter
$users = safeFetchAll($conn, "
    SELECT id, username, role 
    FROM users 
    WHERE is_active = 1 
    ORDER BY username
", [], []);
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
                            <h1 class="h3 mb-0">Audit Trail</h1>
                            <p class="text-muted mb-0">Track all system activities and changes</p>
                        </div>
                        <div class="btn-group">
                            <button class="btn btn-outline-primary" onclick="exportLogs()">
                                <i class="bi bi-download me-2"></i>Export CSV
                            </button>
                            <button class="btn btn-outline-danger dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown">
                                <span class="visually-hidden">Toggle Dropdown</span>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#clearLogsModal">
                                    <i class="bi bi-trash me-2"></i>Clear Old Logs
                                </a></li>
                                <li><a class="dropdown-item" href="audit-settings.php">
                                    <i class="bi bi-gear me-2"></i>Audit Settings
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

                    <!-- Summary Cards -->
                    <div class="row g-4 mb-4">
                        <div class="col-xl-2 col-md-4">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0 me-3">
                                            <div class="bg-primary bg-opacity-10 rounded-circle p-3">
                                                <i class="bi bi-journal-text text-primary fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Total Logs</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['total_logs'] ?? 0); ?></h3>
                                            <small class="text-muted"><?php echo $summary['tables_audited'] ?? 0; ?> tables</small>
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
                                                <i class="bi bi-plus-circle text-success fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Inserts</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['inserts'] ?? 0); ?></h3>
                                            <small class="text-muted">New records</small>
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
                                                <i class="bi bi-pencil-square text-warning fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Updates</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['updates'] ?? 0); ?></h3>
                                            <small class="text-muted">Changes</small>
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
                                                <i class="bi bi-trash text-danger fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Deletes</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['deletes'] ?? 0); ?></h3>
                                            <small class="text-muted">Removals</small>
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
                                                <i class="bi bi-people text-info fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Active Users</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['active_users'] ?? 0); ?></h3>
                                            <small class="text-muted">Past period</small>
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
                                                <i class="bi bi-clock text-secondary fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Last 24h</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['last_24h'] ?? 0); ?></h3>
                                            <small class="text-muted">Activities</small>
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
                                        <i class="bi bi-bar-chart me-2 text-primary"></i>Activity by Table
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <canvas id="tableChart" style="height: 250px;"></canvas>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-xl-6">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h6 class="card-title mb-0 fw-bold">
                                        <i class="bi bi-graph-up me-2 text-success"></i>Hourly Activity (Last 24h)
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <canvas id="hourlyChart" style="height: 250px;"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Top Users -->
                    <?php if (!empty($top_users)): ?>
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h6 class="card-title mb-0 fw-bold">
                                        <i class="bi bi-trophy me-2 text-warning"></i>Most Active Users
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <?php foreach ($top_users as $user): ?>
                                        <div class="col-md-2 mb-2">
                                            <div class="border rounded p-2 text-center">
                                                <div class="avatar-circle bg-primary bg-opacity-10 mx-auto mb-2" style="width: 48px; height: 48px;">
                                                    <span class="text-primary fw-bold">
                                                        <?php echo strtoupper(substr($user['username'], 0, 2)); ?>
                                                    </span>
                                                </div>
                                                <strong><?php echo htmlspecialchars($user['username']); ?></strong>
                                                <br>
                                                <span class="badge bg-info"><?php echo ucfirst($user['role']); ?></span>
                                                <br>
                                                <small class="text-muted"><?php echo $user['action_count']; ?> actions</small>
                                                <br>
                                                <small class="text-muted">Last: <?php echo date('d/m H:i', strtotime($user['last_action'])); ?></small>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Filters -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-body">
                            <form method="GET" class="row g-3">
                                <div class="col-md-2">
                                    <label class="form-label">Table</label>
                                    <select class="form-select" name="table">
                                        <option value="">All Tables</option>
                                        <?php foreach ($tables as $t): ?>
                                        <option value="<?php echo $t['table_name']; ?>" <?php echo $table_filter == $t['table_name'] ? 'selected' : ''; ?>>
                                            <?php echo $t['table_name']; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-2">
                                    <label class="form-label">Action</label>
                                    <select class="form-select" name="action">
                                        <option value="">All Actions</option>
                                        <?php foreach ($actions as $a): ?>
                                        <option value="<?php echo $a; ?>" <?php echo $action_filter == $a ? 'selected' : ''; ?>>
                                            <?php echo $a; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-2">
                                    <label class="form-label">User</label>
                                    <select class="form-select" name="user_id">
                                        <option value="">All Users</option>
                                        <?php foreach ($users as $u): ?>
                                        <option value="<?php echo $u['id']; ?>" <?php echo $user_filter == $u['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($u['username']); ?> (<?php echo ucfirst($u['role']); ?>)
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
                                    <input type="text" class="form-control" name="search" placeholder="Keywords..." 
                                           value="<?php echo htmlspecialchars($search); ?>">
                                </div>
                                
                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-search me-2"></i>Apply Filters
                                    </button>
                                    <a href="audit-logs.php" class="btn btn-secondary">
                                        <i class="bi bi-x-circle me-2"></i>Clear Filters
                                    </a>
                                    <button type="button" class="btn btn-outline-success float-end" onclick="exportLogs()">
                                        <i class="bi bi-download me-2"></i>Export Current View
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Audit Logs Table -->
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0 fw-bold">
                                <i class="bi bi-list-check me-2"></i>Audit Trail
                                <span class="badge bg-secondary ms-2"><?php echo number_format($total_rows); ?> records</span>
                            </h5>
                            <div>
                                <span class="text-muted me-3">
                                    Showing <?php echo count($logs); ?> of <?php echo number_format($total_rows); ?>
                                </span>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0" id="auditTable">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>Time</th>
                                            <th>User</th>
                                            <th>Action</th>
                                            <th>Table</th>
                                            <th>Record ID</th>
                                            <th>Changes</th>
                                            <th>Details</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($logs)): ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-4 text-muted">
                                                <i class="bi bi-inbox fs-1 d-block mb-3"></i>
                                                No audit logs found
                                            </td>
                                        </tr>
                                        <?php else: ?>
                                            <?php foreach ($logs as $log): ?>
                                            <?php
                                            // Safe JSON decoding with null check
                                            $old_values = !empty($log['old_values']) ? json_decode($log['old_values'], true) : [];
                                            $new_values = !empty($log['new_values']) ? json_decode($log['new_values'], true) : [];
                                            
                                            $actionClass = match($log['action']) {
                                                'INSERT' => 'success',
                                                'UPDATE' => 'warning',
                                                'DELETE' => 'danger',
                                                default => 'secondary'
                                            };
                                            
                                            $actionIcon = match($log['action']) {
                                                'INSERT' => 'plus-circle',
                                                'UPDATE' => 'pencil-square',
                                                'DELETE' => 'trash',
                                                default => 'question-circle'
                                            };
                                            ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo date('d/m/Y', strtotime($log['change_time'])); ?></strong>
                                                    <br><small class="text-muted"><?php echo date('H:i:s', strtotime($log['change_time'])); ?></small>
                                                </td>
                                                <td>
                                                    <?php if ($log['user_id']): ?>
                                                    <div class="d-flex align-items-center">
                                                        <div class="avatar-circle bg-info bg-opacity-10 me-2" style="width: 32px; height: 32px;">
                                                            <span class="text-info fw-bold small">
                                                                <?php echo strtoupper(substr($log['username'] ?? 'U', 0, 2)); ?>
                                                            </span>
                                                        </div>
                                                        <div>
                                                            <span><?php echo htmlspecialchars($log['username'] ?? 'Unknown'); ?></span>
                                                            <br><small class="text-muted">ID: <?php echo $log['user_id']; ?></small>
                                                        </div>
                                                    </div>
                                                    <?php else: ?>
                                                    <span class="text-muted">System</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?php echo $actionClass; ?>">
                                                        <i class="bi bi-<?php echo $actionIcon; ?> me-1"></i>
                                                        <?php echo $log['action']; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge bg-secondary"><?php echo $log['table_name']; ?></span>
                                                </td>
                                                <td>
                                                    <code>#<?php echo $log['record_id']; ?></code>
                                                </td>
                                                <td style="max-width: 300px;">
                                                    <?php if ($log['action'] == 'INSERT' && !empty($new_values)): ?>
                                                        <small class="text-success">New record created</small>
                                                        <button class="btn btn-sm btn-link p-0" onclick="showChanges(<?php echo htmlspecialchars(json_encode($new_values)); ?>, 'new')">
                                                            <i class="bi bi-eye"></i> View
                                                        </button>
                                                    <?php elseif ($log['action'] == 'DELETE' && !empty($old_values)): ?>
                                                        <small class="text-danger">Record deleted</small>
                                                        <button class="btn btn-sm btn-link p-0" onclick="showChanges(<?php echo htmlspecialchars(json_encode($old_values)); ?>, 'old')">
                                                            <i class="bi bi-eye"></i> View
                                                        </button>
                                                    <?php elseif ($log['action'] == 'UPDATE' && !empty($old_values) && !empty($new_values)): ?>
                                                        <?php
                                                        $changes = [];
                                                        foreach ($new_values as $key => $value) {
                                                            $old = $old_values[$key] ?? null;
                                                            if ($old != $value) {
                                                                $changes[] = $key;
                                                            }
                                                        }
                                                        ?>
                                                        <small class="text-warning"><?php echo count($changes); ?> field(s) changed</small>
                                                        <button class="btn btn-sm btn-link p-0" onclick="showDiff(<?php echo htmlspecialchars(json_encode($old_values)); ?>, <?php echo htmlspecialchars(json_encode($new_values)); ?>)">
                                                            <i class="bi bi-file-diff"></i> Diff
                                                        </button>
                                                    <?php else: ?>
                                                        <span class="text-muted">No details</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <button class="btn btn-sm btn-outline-primary" onclick="viewFullLog(<?php echo htmlspecialchars(json_encode($log)); ?>)">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
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
                                    
                                    <?php 
                                    $start = max(1, $page - 2);
                                    $end = min($start + 4, $total_pages);
                                    for ($i = $start; $i <= $end; $i++): 
                                    ?>
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

    <!-- View Log Modal -->
    <div class="modal fade" id="viewLogModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Audit Log Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <th>Log ID:</th>
                                    <td id="log_id"></td>
                                </tr>
                                <tr>
                                    <th>Timestamp:</th>
                                    <td id="log_time"></td>
                                </tr>
                                <tr>
                                    <th>User:</th>
                                    <td id="log_user"></td>
                                </tr>
                                <tr>
                                    <th>Action:</th>
                                    <td id="log_action"></td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <th>Table:</th>
                                    <td id="log_table"></td>
                                </tr>
                                <tr>
                                    <th>Record ID:</th>
                                    <td id="log_record"></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    
                    <ul class="nav nav-tabs" id="logTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="old-tab" data-bs-toggle="tab" data-bs-target="#old" type="button">Old Values</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="new-tab" data-bs-toggle="tab" data-bs-target="#new" type="button">New Values</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="diff-tab" data-bs-toggle="tab" data-bs-target="#diff" type="button">Differences</button>
                        </li>
                    </ul>
                    
                    <div class="tab-content p-3 border border-top-0 rounded-bottom" id="logTabContent">
                        <div class="tab-pane fade show active" id="old" role="tabpanel">
                            <pre id="old_values" class="mb-0" style="max-height: 300px; overflow-y: auto;"></pre>
                        </div>
                        <div class="tab-pane fade" id="new" role="tabpanel">
                            <pre id="new_values" class="mb-0" style="max-height: 300px; overflow-y: auto;"></pre>
                        </div>
                        <div class="tab-pane fade" id="diff" role="tabpanel">
                            <div id="diff_view" class="mb-0" style="max-height: 300px; overflow-y: auto;"></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Clear Logs Modal -->
    <div class="modal fade" id="clearLogsModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="clear_old">
                    <div class="modal-header">
                        <h5 class="modal-title">Clear Old Logs</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p>This will permanently delete audit logs older than the specified number of days.</p>
                        <div class="mb-3">
                            <label class="form-label">Delete logs older than (days)</label>
                            <input type="number" class="form-control" name="days" value="90" min="30" max="3650" required>
                        </div>
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            This action cannot be undone. Consider exporting logs before clearing.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Clear Logs</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <script>
        // Initialize charts
        document.addEventListener('DOMContentLoaded', function() {
            // Table Chart
            const tableCtx = document.getElementById('tableChart')?.getContext('2d');
            if (tableCtx && <?php echo !empty($table_stats) ? 'true' : 'false'; ?>) {
                new Chart(tableCtx, {
                    type: 'bar',
                    data: {
                        labels: [
                            <?php foreach ($table_stats as $stat): ?>
                            '<?php echo addslashes($stat['table_name']); ?>',
                            <?php endforeach; ?>
                        ],
                        datasets: [{
                            label: 'Inserts',
                            data: [
                                <?php foreach ($table_stats as $stat): ?>
                                <?php echo (int)($stat['inserts'] ?? 0); ?>,
                                <?php endforeach; ?>
                            ],
                            backgroundColor: '#198754'
                        }, {
                            label: 'Updates',
                            data: [
                                <?php foreach ($table_stats as $stat): ?>
                                <?php echo (int)($stat['updates'] ?? 0); ?>,
                                <?php endforeach; ?>
                            ],
                            backgroundColor: '#ffc107'
                        }, {
                            label: 'Deletes',
                            data: [
                                <?php foreach ($table_stats as $stat): ?>
                                <?php echo (int)($stat['deletes'] ?? 0); ?>,
                                <?php endforeach; ?>
                            ],
                            backgroundColor: '#dc3545'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            x: {
                                stacked: true
                            },
                            y: {
                                stacked: true,
                                beginAtZero: true
                            }
                        }
                    }
                });
            }
            
            // Hourly Chart
            const hourlyCtx = document.getElementById('hourlyChart')?.getContext('2d');
            if (hourlyCtx) {
                const hourlyData = new Array(24).fill(0);
                <?php foreach ($hourly_activity as $h): ?>
                hourlyData[<?php echo $h['hour']; ?>] = <?php echo $h['count']; ?>;
                <?php endforeach; ?>
                
                new Chart(hourlyCtx, {
                    type: 'line',
                    data: {
                        labels: Array.from({length: 24}, (_, i) => String(i).padStart(2, '0') + ':00'),
                        datasets: [{
                            label: 'Activities',
                            data: hourlyData,
                            borderColor: '#0d6efd',
                            backgroundColor: 'rgba(13, 110, 253, 0.1)',
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

        // View full log details
        function viewFullLog(log) {
            document.getElementById('log_id').textContent = '#' + log.id;
            document.getElementById('log_time').textContent = new Date(log.change_time).toLocaleString();
            document.getElementById('log_user').textContent = log.username || 'System (ID: ' + (log.user_id || 'N/A') + ')';
            
            const actionClass = {
                'INSERT': 'success',
                'UPDATE': 'warning',
                'DELETE': 'danger'
            }[log.action] || 'secondary';
            
            document.getElementById('log_action').innerHTML = 
                '<span class="badge bg-' + actionClass + '">' + log.action + '</span>';
            
            document.getElementById('log_table').innerHTML = 
                '<span class="badge bg-secondary">' + log.table_name + '</span>';
            document.getElementById('log_record').innerHTML = '<code>#' + log.record_id + '</code>';
            
            // Safely parse JSON with null check
            let oldValues = 'null';
            let newValues = 'null';
            
            if (log.old_values) {
                try {
                    oldValues = JSON.stringify(JSON.parse(log.old_values), null, 2);
                } catch (e) {
                    oldValues = log.old_values;
                }
            }
            
            if (log.new_values) {
                try {
                    newValues = JSON.stringify(JSON.parse(log.new_values), null, 2);
                } catch (e) {
                    newValues = log.new_values;
                }
            }
            
            document.getElementById('old_values').textContent = oldValues;
            document.getElementById('new_values').textContent = newValues;
            
            // Generate diff if both exist
            if (log.old_values && log.new_values) {
                try {
                    const oldObj = JSON.parse(log.old_values);
                    const newObj = JSON.parse(log.new_values);
                    const diffHtml = generateDiff(oldObj, newObj);
                    document.getElementById('diff_view').innerHTML = diffHtml;
                } catch (e) {
                    document.getElementById('diff_view').innerHTML = '<p class="text-danger">Error parsing JSON for diff</p>';
                }
            } else {
                document.getElementById('diff_view').innerHTML = '<p class="text-muted">No differences to display</p>';
            }
            
            new bootstrap.Modal(document.getElementById('viewLogModal')).show();
        }

        // Generate diff between two objects
        function generateDiff(oldObj, newObj) {
            if (!oldObj || !newObj) return '<p class="text-muted">Insufficient data for diff</p>';
            
            let html = '<table class="table table-sm table-bordered">';
            html += '<thead><tr><th>Field</th><th>Old Value</th><th>New Value</th><th>Change</th></tr></thead><tbody>';
            
            const allKeys = new Set([...Object.keys(oldObj || {}), ...Object.keys(newObj || {})]);
            
            allKeys.forEach(key => {
                const oldVal = oldObj ? oldObj[key] : undefined;
                const newVal = newObj ? newObj[key] : undefined;
                
                // Convert to string for comparison, handling null/undefined
                const oldStr = oldVal !== undefined ? String(oldVal) : '';
                const newStr = newVal !== undefined ? String(newVal) : '';
                
                if (oldStr !== newStr) {
                    const changeType = oldVal === undefined ? 'Added' : (newVal === undefined ? 'Removed' : 'Modified');
                    const changeClass = oldVal === undefined ? 'success' : (newVal === undefined ? 'danger' : 'warning');
                    
                    html += `<tr class="table-${changeClass}">`;
                    html += `<td><strong>${key}</strong></td>`;
                    html += `<td>${oldVal !== undefined ? JSON.stringify(oldVal) : '<em>null</em>'}</td>`;
                    html += `<td>${newVal !== undefined ? JSON.stringify(newVal) : '<em>null</em>'}</td>`;
                    html += `<td><span class="badge bg-${changeClass}">${changeType}</span></td>`;
                    html += '</tr>';
                }
            });
            
            html += '</tbody></table>';
            return html;
        }

        // Show changes modal (simple alert for now)
        function showChanges(values, type) {
            const title = type === 'new' ? 'New Values' : 'Old Values';
            let content;
            try {
                content = typeof values === 'string' ? values : JSON.stringify(values, null, 2);
            } catch (e) {
                content = String(values);
            }
            alert(title + ':\n\n' + content);
        }

        // Show diff modal
        function showDiff(oldValues, newValues) {
            // Parse if strings
            let oldObj = oldValues;
            let newObj = newValues;
            
            if (typeof oldValues === 'string') {
                try { oldObj = JSON.parse(oldValues); } catch (e) { oldObj = {}; }
            }
            if (typeof newValues === 'string') {
                try { newObj = JSON.parse(newValues); } catch (e) { newObj = {}; }
            }
            
            viewFullLog({
                old_values: JSON.stringify(oldObj),
                new_values: JSON.stringify(newObj)
            });
        }

        // Export logs
        function exportLogs() {
            const currentUrl = new URL(window.location.href);
            currentUrl.searchParams.set('export', 'csv');
            window.location.href = currentUrl.toString();
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
        
        .bg-opacity-10 {
            --bs-bg-opacity: 0.1;
        }
        
        .pagination {
            margin-bottom: 0;
        }
        
        pre {
            background-color: #f8f9fa;
            padding: 1rem;
            border-radius: 0.375rem;
            font-size: 0.875rem;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        
        .nav-tabs .nav-link {
            color: #495057;
            font-weight: 500;
        }
        
        .nav-tabs .nav-link.active {
            font-weight: 600;
        }
        
        #diff_view table {
            font-size: 0.875rem;
        }
        
        #diff_view td {
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
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
            
            #diff_view td {
                max-width: 100px;
            }
        }
    </style>

</body>
</html>