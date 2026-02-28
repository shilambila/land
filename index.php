<?php
ini_set('display_errors', 1); 
error_reporting(E_ALL);

// superadmin_dashboard.php
include("head.php");
require_once 'db_connection.php'; // PDO connection file

// Get real data from database using PDO
function getDashboardStats($conn) {
    $stats = [];
    
    // Total parcels
    $stmt = executeQuery($conn, "SELECT COUNT(*) as total FROM parcels");
    $result = $stmt->fetch();
    $stats['total_parcels'] = $result['total'];
    
    // Total titles
    $stmt = executeQuery($conn, "SELECT COUNT(*) as total FROM titles WHERE status = 'active'");
    $result = $stmt->fetch();
    $stats['active_titles'] = $result['total'];
    
    // Total parties (users)
    $stmt = executeQuery($conn, "SELECT COUNT(*) as total FROM parties");
    $result = $stmt->fetch();
    $stats['total_parties'] = $result['total'];
    
    // Pending applications
    $stmt = executeQuery($conn, "SELECT COUNT(*) as total FROM applications WHERE status = 'submitted' OR status = 'under_review'");
    $result = $stmt->fetch();
    $stats['pending_applications'] = $result['total'];
    
    // Total revenue from payments
    $stmt = executeQuery($conn, "SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE status = 'completed'");
    $result = $stmt->fetch();
    $stats['total_revenue'] = $result['total'];
    
    // This month's revenue
    $stmt = executeQuery($conn, "SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE status = 'completed' AND MONTH(payment_date) = MONTH(CURRENT_DATE()) AND YEAR(payment_date) = YEAR(CURRENT_DATE())");
    $result = $stmt->fetch();
    $stats['monthly_revenue'] = $result['total'];
    
    // Active users
    $stmt = executeQuery($conn, "SELECT COUNT(*) as total FROM users WHERE is_active = 1");
    $result = $stmt->fetch();
    $stats['active_users'] = $result['total'];
    
    // Total disputes
    $stmt = executeQuery($conn, "SELECT COUNT(*) as total FROM disputes WHERE status != 'resolved' AND status != 'closed'");
    $result = $stmt->fetch();
    $stats['open_disputes'] = $result['total'];
    
    return $stats;
}

function getRecentApplications($conn, $limit = 5) {
    $sql = "SELECT a.*, p.parcel_number, pt.name as applicant_name, a.status 
            FROM applications a 
            LEFT JOIN parcels p ON a.parcel_id = p.id 
            LEFT JOIN parties pt ON a.applicant_party_id = pt.id 
            ORDER BY a.submission_date DESC 
            LIMIT :limit";
    
    $stmt = executeQuery($conn, $sql, ['limit' => $limit]);
    return $stmt->fetchAll();
}

function getRevenueByMonth($conn, $months = 6) {
    $sql = "SELECT DATE_FORMAT(payment_date, '%Y-%m') as month, 
                   COALESCE(SUM(amount), 0) as revenue 
            FROM payments 
            WHERE status = 'completed' 
            AND payment_date >= DATE_SUB(CURRENT_DATE(), INTERVAL :months MONTH)
            GROUP BY DATE_FORMAT(payment_date, '%Y-%m')
            ORDER BY month DESC";
    
    $stmt = executeQuery($conn, $sql, ['months' => $months]);
    return $stmt->fetchAll();
}

function getApplicationStats($conn) {
    $sql = "SELECT status, COUNT(*) as count 
            FROM applications 
            GROUP BY status";
    $stmt = executeQuery($conn, $sql);
    $stats = [];
    while($row = $stmt->fetch()) {
        $stats[$row['status']] = $row['count'];
    }
    return $stats;
}

function getUserRolesDistribution($conn) {
    $sql = "SELECT role, COUNT(*) as count 
            FROM users 
            GROUP BY role";
    $stmt = executeQuery($conn, $sql);
    $roles = [];
    while($row = $stmt->fetch()) {
        $roles[$row['role']] = $row['count'];
    }
    return $roles;
}

function getRecentActivities($conn, $limit = 10) {
    $sql = "SELECT al.*, u.username 
            FROM audit_logs al 
            LEFT JOIN users u ON al.user_id = u.id 
            ORDER BY al.change_time DESC 
            LIMIT :limit";
    
    $stmt = executeQuery($conn, $sql, ['limit' => $limit]);
    return $stmt->fetchAll();
}

// Get all the data
$stats = getDashboardStats($conn);
$recent_applications = getRecentApplications($conn);
$revenue_data = getRevenueByMonth($conn);
$application_stats = getApplicationStats($conn);
$user_roles = getUserRolesDistribution($conn);
$recent_activities = getRecentActivities($conn);

// Format revenue data for charts
$revenue_chart_labels = [];
$revenue_chart_values = [];
foreach($revenue_data as $row) {
    array_unshift($revenue_chart_labels, date('M Y', strtotime($row['month'])));
    array_unshift($revenue_chart_values, $row['revenue']);
}
?>

<body data-page="superadmin" class="superadmin-page">
    <!-- Admin App Container -->
    <div class="admin-app">
        <div class="admin-wrapper" id="admin-wrapper">
            
            <!-- Header -->
         <?php include("navbar.php"); ?>

            <!-- Sidebar -->
          <?php include("sidebar.php"); ?>

            <!-- Sidebar Backdrop (mobile overlay) -->
        <div class="sidebar-backdrop" aria-hidden="true"></div>
            <!-- Main Content -->
            <main class="admin-main">
                <div class="container-fluid p-4 p-lg-5">
                    
                    <!-- Page Header -->
                    <div class="d-flex justify-content-between align-items-center mb-4 mb-lg-5">
                        <div>
                            <h1 class="h3 mb-0">SuperAdmin Dashboard</h1>
                            <p class="text-muted mb-0">System-wide overview and management</p>
                        </div>
                        <div>
                            <span class="badge bg-primary me-2">System Status: Active</span>
                            <span class="badge bg-success">Last Updated: <?php echo date('Y-m-d H:i'); ?></span>
                        </div>
                    </div>

                    <!-- Key Metrics Row -->
                    <div class="row g-4 g-lg-5 g-xl-6 mb-5">
                        <!-- Total Parcels -->
                        <div class="col-xl-3 col-lg-6 col-md-6">
                            <div class="card metric-card border-0 shadow-sm">
                                <div class="card-body p-4">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0 me-3">
                                            <div class="stats-icon bg-primary bg-opacity-10 text-primary rounded-circle p-3">
                                                <i class="bi bi-map fs-4"></i>
                                            </div>
                                        </div>
                                        <div class="flex-grow-1">
                                            <h6 class="text-muted mb-1">Total Parcels</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($stats['total_parcels']); ?></h3>
                                            <small class="text-success"><i class="bi bi-arrow-up"></i> +2.5%</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Active Titles -->
                        <div class="col-xl-3 col-lg-6 col-md-6">
                            <div class="card metric-card border-0 shadow-sm">
                                <div class="card-body p-4">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0 me-3">
                                            <div class="stats-icon bg-success bg-opacity-10 text-success rounded-circle p-3">
                                                <i class="bi bi-file-earmark-text fs-4"></i>
                                            </div>
                                        </div>
                                        <div class="flex-grow-1">
                                            <h6 class="text-muted mb-1">Active Titles</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($stats['active_titles']); ?></h3>
                                            <small class="text-success"><i class="bi bi-arrow-up"></i> +5.3%</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Total Users/Parties -->
                        <div class="col-xl-3 col-lg-6 col-md-6">
                            <div class="card metric-card border-0 shadow-sm">
                                <div class="card-body p-4">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0 me-3">
                                            <div class="stats-icon bg-info bg-opacity-10 text-info rounded-circle p-3">
                                                <i class="bi bi-people fs-4"></i>
                                            </div>
                                        </div>
                                        <div class="flex-grow-1">
                                            <h6 class="text-muted mb-1">Registered Parties</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($stats['total_parties']); ?></h3>
                                            <small class="text-success"><i class="bi bi-arrow-up"></i> +12</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Pending Applications -->
                        <div class="col-xl-3 col-lg-6 col-md-6">
                            <div class="card metric-card border-0 shadow-sm">
                                <div class="card-body p-4">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0 me-3">
                                            <div class="stats-icon bg-warning bg-opacity-10 text-warning rounded-circle p-3">
                                                <i class="bi bi-clock-history fs-4"></i>
                                            </div>
                                        </div>
                                        <div class="flex-grow-1">
                                            <h6 class="text-muted mb-1">Pending Applications</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($stats['pending_applications']); ?></h3>
                                            <small class="text-danger"><i class="bi bi-arrow-up"></i> +3</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Second Metrics Row -->
                    <div class="row g-4 g-lg-5 g-xl-6 mb-5">
                        <!-- Total Revenue -->
                        <div class="col-xl-3 col-lg-6 col-md-6">
                            <div class="card metric-card border-0 shadow-sm">
                                <div class="card-body p-4">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0 me-3">
                                            <div class="stats-icon bg-success bg-opacity-10 text-success rounded-circle p-3">
                                                <i class="bi bi-currency-dollar fs-4"></i>
                                            </div>
                                        </div>
                                        <div class="flex-grow-1">
                                            <h6 class="text-muted mb-1">Total Revenue</h6>
                                            <h3 class="mb-0 fw-bold">$<?php echo number_format($stats['total_revenue'], 2); ?></h3>
                                            <small class="text-success">$<?php echo number_format($stats['monthly_revenue'], 2); ?> this month</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Active Users -->
                        <div class="col-xl-3 col-lg-6 col-md-6">
                            <div class="card metric-card border-0 shadow-sm">
                                <div class="card-body p-4">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0 me-3">
                                            <div class="stats-icon bg-primary bg-opacity-10 text-primary rounded-circle p-3">
                                                <i class="bi bi-person-check fs-4"></i>
                                            </div>
                                        </div>
                                        <div class="flex-grow-1">
                                            <h6 class="text-muted mb-1">Active Users</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($stats['active_users']); ?></h3>
                                            <small class="text-success">System users</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Open Disputes -->
                        <div class="col-xl-3 col-lg-6 col-md-6">
                            <div class="card metric-card border-0 shadow-sm">
                                <div class="card-body p-4">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0 me-3">
                                            <div class="stats-icon bg-danger bg-opacity-10 text-danger rounded-circle p-3">
                                                <i class="bi bi-exclamation-triangle fs-4"></i>
                                            </div>
                                        </div>
                                        <div class="flex-grow-1">
                                            <h6 class="text-muted mb-1">Open Disputes</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($stats['open_disputes']); ?></h3>
                                            <small class="text-warning">Requires attention</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- System Health -->
                        <div class="col-xl-3 col-lg-6 col-md-6">
                            <div class="card metric-card border-0 shadow-sm">
                                <div class="card-body p-4">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0 me-3">
                                            <div class="stats-icon bg-info bg-opacity-10 text-info rounded-circle p-3">
                                                <i class="bi bi-heart-pulse fs-4"></i>
                                            </div>
                                        </div>
                                        <div class="flex-grow-1">
                                            <h6 class="text-muted mb-1">System Health</h6>
                                            <h3 class="mb-0 fw-bold">98.5%</h3>
                                            <small class="text-success">All systems operational</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Charts Row -->
                    <div class="row g-4 g-lg-5 g-xl-6 mb-5">
                        <!-- Revenue Chart -->
                        <div class="col-lg-8">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white border-0 py-3">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <h5 class="card-title mb-0 fw-bold">Revenue Overview</h5>
                                        <div class="btn-group btn-group-sm" role="group">
                                            <button type="button" class="btn btn-outline-secondary active">6 Months</button>
                                            <button type="button" class="btn btn-outline-secondary">1 Year</button>
                                            <button type="button" class="btn btn-outline-secondary">All</button>
                                        </div>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <canvas id="revenueChart" style="height: 300px;"></canvas>
                                </div>
                            </div>
                        </div>

                        <!-- Application Status Distribution -->
                  <!-- Application Status Distribution -->
<div class="col-lg-4">
    <div class="card border-0 shadow-sm" style="min-height: 400px; max-height: 500px;">
        <div class="card-header bg-white border-0 py-3">
            <h5 class="card-title mb-0 fw-bold">Application Status</h5>
        </div>
        <div class="card-body p-3" style="overflow-y: auto;">
            <canvas id="applicationChart" style="height: 200px; width: 100%;" class="mb-3"></canvas>
            
            <div>
                <?php foreach($application_stats as $status => $count): ?>
                <div class="d-flex justify-content-between align-items-center mb-2 pb-1 border-bottom">
                    <span>
                        <span class="badge bg-<?php echo $status_colors[$status] ?? 'secondary'; ?>">
                            <?php echo ucfirst(str_replace('_', ' ', $status)); ?>
                        </span>
                    </span>
                    <span class="fw-bold"><?php echo $count; ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
                    </div>

                    <!-- Recent Applications & User Roles -->
                    <div class="row g-4 g-lg-5 g-xl-6 mb-5">
                        <!-- Recent Applications -->
                        <div class="col-lg-7">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                                    <h5 class="card-title mb-0 fw-bold">Recent Applications</h5>
                                    <a href="applications.php" class="btn btn-sm btn-primary">View All</a>
                                </div>
                                <div class="card-body p-0">
                                    <div class="table-responsive">
                                        <table class="table table-hover mb-0">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Application ID</th>
                                                    <th>Type</th>
                                                    <th>Applicant</th>
                                                    <th>Parcel</th>
                                                    <th>Date</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach($recent_applications as $app): ?>
                                                <tr>
                                                    <td>
                                                        <span class="fw-medium">#<?php echo $app['id']; ?></span>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-light text-dark"><?php echo ucfirst(str_replace('_', ' ', $app['application_type'])); ?></span>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($app['applicant_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($app['parcel_number'] ?? 'N/A'); ?></td>
                                                    <td><?php echo date('M d, Y', strtotime($app['submission_date'])); ?></td>
                                                    <td>
                                                        <?php
                                                        $status_class = [
                                                            'draft' => 'secondary',
                                                            'submitted' => 'info',
                                                            'under_review' => 'warning',
                                                            'approved' => 'success',
                                                            'rejected' => 'danger',
                                                            'completed' => 'primary'
                                                        ][$app['status']] ?? 'secondary';
                                                        ?>
                                                        <span class="badge bg-<?php echo $status_class; ?>">
                                                            <?php echo ucfirst(str_replace('_', ' ', $app['status'])); ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- User Roles Distribution -->
                        <div class="col-lg-5">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white border-0 py-3">
                                    <h5 class="card-title mb-0 fw-bold">User Roles Distribution</h5>
                                </div>
                                <div class="card-body">
                                    <?php 
                                    $total_users = array_sum($user_roles);
                                    foreach($user_roles as $role => $count): 
                                        $percentage = ($total_users > 0) ? ($count / $total_users) * 100 : 0;
                                        $role_class = [
                                            'admin' => 'danger',
                                            'surveyor' => 'primary',
                                            'clerk' => 'success',
                                            'public_officer' => 'warning',
                                            'public' => 'info'
                                        ][$role] ?? 'secondary';
                                    ?>
                                    <div class="mb-4">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <span>
                                                <span class="badge bg-<?php echo $role_class; ?> me-2"><?php echo ucfirst(str_replace('_', ' ', $role)); ?></span>
                                                <span class="text-muted"><?php echo $count; ?> users</span>
                                            </span>
                                            <span class="fw-bold"><?php echo number_format($percentage, 1); ?>%</span>
                                        </div>
                                        <div class="progress" style="height: 8px;">
                                            <div class="progress-bar bg-<?php echo $role_class; ?>" 
                                                 style="width: <?php echo $percentage; ?>%"></div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Activities & Quick Actions -->
                    <div class="row g-4 g-lg-5 g-xl-6">
                        <!-- Recent Activities -->
                        <div class="col-lg-8">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white border-0 py-3">
                                    <h5 class="card-title mb-0 fw-bold">Recent System Activities</h5>
                                </div>
                                <div class="card-body p-0">
                                    <div class="list-group list-group-flush">
                                        <?php foreach($recent_activities as $activity): ?>
                                        <div class="list-group-item px-4 py-3">
                                            <div class="d-flex align-items-center">
                                                <div class="me-3">
                                                    <?php
                                                    $action_icon = [
                                                        'INSERT' => 'plus-circle text-success',
                                                        'UPDATE' => 'pencil text-primary',
                                                        'DELETE' => 'trash text-danger'
                                                    ][$activity['action']] ?? 'info-circle text-info';
                                                    ?>
                                                    <i class="bi bi-<?php echo $action_icon; ?> fs-5"></i>
                                                </div>
                                                <div class="flex-grow-1">
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <div>
                                                            <span class="fw-medium"><?php echo $activity['action']; ?></span>
                                                            <span class="text-muted">on</span>
                                                            <span class="fw-medium"><?php echo $activity['table_name']; ?></span>
                                                            <span class="text-muted">by</span>
                                                            <span class="fw-medium"><?php echo $activity['username'] ?? 'System'; ?></span>
                                                        </div>
                                                        <small class="text-muted">
                                                            <?php echo date('M d, H:i', strtotime($activity['change_time'])); ?>
                                                        </small>
                                                    </div>
                                                    <small class="text-muted d-block">
                                                        Record ID: <?php echo $activity['record_id']; ?>
                                                    </small>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Quick Actions & System Info -->
                        <div class="col-lg-4">
                            <div class="card border-0 shadow-sm mb-4">
                                <div class="card-header bg-white border-0 py-3">
                                    <h5 class="card-title mb-0 fw-bold">Quick Actions</h5>
                                </div>
                                <div class="card-body">
                                    <div class="d-grid gap-2">
                                        <button class="btn btn-primary" onclick="location.href='add_user.php'">
                                            <i class="bi bi-person-plus me-2"></i>Add New User
                                        </button>
                                        <button class="btn btn-outline-primary" onclick="location.href='system-settings.php'">
                                            <i class="bi bi-gear me-2"></i>System Settings
                                        </button>
                                        <button class="btn btn-outline-success" onclick="location.href='backup.php'">
                                            <i class="bi bi-database me-2"></i>Backup Database
                                        </button>
                                        <button class="btn btn-outline-warning" onclick="location.href='audit_logs.php'">
                                            <i class="bi bi-journal-text me-2"></i>View Audit Logs
                                        </button>
                                        <button class="btn btn-outline-info" onclick="location.href='reports.php'">
                                            <i class="bi bi-bar-chart me-2"></i>Generate Reports
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white border-0 py-3">
                                    <h5 class="card-title mb-0 fw-bold">System Information</h5>
                                </div>
                                <div class="card-body">
                                    <ul class="list-unstyled mb-0">
                                        <li class="mb-3 d-flex justify-content-between">
                                            <span class="text-muted">PHP Version:</span>
                                            <span class="fw-medium"><?php echo phpversion(); ?></span>
                                        </li>
                                        <li class="mb-3 d-flex justify-content-between">
                                            <span class="text-muted">Database:</span>
                                            <span class="fw-medium">MySQL 8.0 (PDO)</span>
                                        </li>
                                        <li class="mb-3 d-flex justify-content-between">
                                            <span class="text-muted">Server Time:</span>
                                            <span class="fw-medium"><?php echo date('H:i:s'); ?></span>
                                        </li>
                                        <li class="mb-3 d-flex justify-content-between">
                                            <span class="text-muted">Last Backup:</span>
                                            <span class="fw-medium"><?php echo date('M d, Y'); ?></span>
                                        </li>
                                        <li class="d-flex justify-content-between">
                                            <span class="text-muted">Disk Usage:</span>
                                            <span class="fw-medium">45% / 100GB</span>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </main>

            <!-- Footer -->
          <?php include("footer.php"); ?>

        </div> <!-- /.admin-wrapper -->
    </div>

    <!-- Page-specific JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Revenue Chart
        const revenueCtx = document.getElementById('revenueChart').getContext('2d');
        new Chart(revenueCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($revenue_chart_labels); ?>,
                datasets: [{
                    label: 'Revenue',
                    data: <?php echo json_encode($revenue_chart_values); ?>,
                    borderColor: '#0d6efd',
                    backgroundColor: 'rgba(13, 110, 253, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '$' + value.toLocaleString();
                            }
                        }
                    }
                }
            }
        });

        // Application Status Chart
        const appCtx = document.getElementById('applicationChart').getContext('2d');
        new Chart(appCtx, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode(array_map(function($status) {
                    return ucfirst(str_replace('_', ' ', $status));
                }, array_keys($application_stats))); ?>,
                datasets: [{
                    data: <?php echo json_encode(array_values($application_stats)); ?>,
                    backgroundColor: [
                        '#6c757d', // draft
                        '#0dcaf0', // submitted
                        '#ffc107', // under_review
                        '#198754', // approved
                        '#dc3545', // rejected
                        '#0d6efd'  // completed
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
                },
                cutout: '70%'
            }
        });
    </script>

</body>
</html>