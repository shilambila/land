<?php
ini_set('display_errors', 1); 
error_reporting(E_ALL);

// users-roles-permission.php
include("head.php");
require_once 'db_connection.php';

// ============================================================================
// DATABASE SETUP - Create permissions tables if they don't exist
// ============================================================================

// Create permissions table
$conn->exec("
    CREATE TABLE IF NOT EXISTS permissions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        permission_name VARCHAR(100) NOT NULL UNIQUE,
        permission_key VARCHAR(100) NOT NULL UNIQUE,
        module VARCHAR(50) NOT NULL,
        description TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
");

// Create role_permissions table
$conn->exec("
    CREATE TABLE IF NOT EXISTS role_permissions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        role VARCHAR(50) NOT NULL,
        permission_id INT UNSIGNED NOT NULL,
        granted BOOLEAN DEFAULT TRUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE,
        UNIQUE KEY unique_role_permission (role, permission_id)
    )
");

// Create user_roles table (for users with multiple roles if needed)
$conn->exec("
    CREATE TABLE IF NOT EXISTS user_roles (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        role VARCHAR(50) NOT NULL,
        assigned_by INT UNSIGNED,
        assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE KEY unique_user_role (user_id, role)
    )
");

// ============================================================================
// INITIAL DATA - Insert default permissions if not exist
// ============================================================================

$default_permissions = [
    // Dashboard permissions
    ['View Dashboard', 'dashboard.view', 'dashboard', 'Access and view main dashboard'],
    ['View Analytics', 'analytics.view', 'dashboard', 'Access analytics and reports'],
    
    // Parcel permissions
    ['View Parcels', 'parcels.view', 'parcels', 'View parcel records'],
    ['Create Parcels', 'parcels.create', 'parcels', 'Create new parcels'],
    ['Edit Parcels', 'parcels.edit', 'parcels', 'Edit parcel information'],
    ['Delete Parcels', 'parcels.delete', 'parcels', 'Delete parcel records'],
    ['Transfer Parcels', 'parcels.transfer', 'parcels', 'Transfer parcel ownership'],
    
    // Title permissions
    ['View Titles', 'titles.view', 'titles', 'View title records'],
    ['Create Titles', 'titles.create', 'titles', 'Create new titles'],
    ['Edit Titles', 'titles.edit', 'titles', 'Edit title information'],
    ['Delete Titles', 'titles.delete', 'titles', 'Delete title records'],
    
    // Application permissions
    ['View Applications', 'applications.view', 'applications', 'View applications'],
    ['Create Applications', 'applications.create', 'applications', 'Submit new applications'],
    ['Process Applications', 'applications.process', 'applications', 'Process and review applications'],
    ['Approve Applications', 'applications.approve', 'applications', 'Approve or reject applications'],
    
    // User management permissions
    ['View Users', 'users.view', 'users', 'View user list'],
    ['Create Users', 'users.create', 'users', 'Create new users'],
    ['Edit Users', 'users.edit', 'users', 'Edit user information'],
    ['Delete Users', 'users.delete', 'users', 'Delete users'],
    ['Manage Roles', 'users.roles', 'users', 'Manage user roles and permissions'],
    
    // Party permissions
    ['View Parties', 'parties.view', 'parties', 'View party records'],
    ['Create Parties', 'parties.create', 'parties', 'Create new parties'],
    ['Edit Parties', 'parties.edit', 'parties', 'Edit party information'],
    ['Delete Parties', 'parties.delete', 'parties', 'Delete party records'],
    
    // Document permissions
    ['View Documents', 'documents.view', 'documents', 'View documents'],
    ['Upload Documents', 'documents.upload', 'documents', 'Upload new documents'],
    ['Download Documents', 'documents.download', 'documents', 'Download documents'],
    ['Delete Documents', 'documents.delete', 'documents', 'Delete documents'],
    
    // Payment permissions
    ['View Payments', 'payments.view', 'payments', 'View payment records'],
    ['Process Payments', 'payments.process', 'payments', 'Process payments'],
    ['Refund Payments', 'payments.refund', 'payments', 'Issue refunds'],
    
    // Survey permissions
    ['View Surveys', 'surveys.view', 'surveys', 'View survey records'],
    ['Create Surveys', 'surveys.create', 'surveys', 'Create new surveys'],
    ['Edit Surveys', 'surveys.edit', 'surveys', 'Edit survey information'],
    
    // Dispute permissions
    ['View Disputes', 'disputes.view', 'disputes', 'View dispute records'],
    ['Manage Disputes', 'disputes.manage', 'disputes', 'Manage and resolve disputes'],
    
    // Report permissions
    ['View Reports', 'reports.view', 'reports', 'View reports'],
    ['Generate Reports', 'reports.generate', 'reports', 'Generate new reports'],
    ['Export Reports', 'reports.export', 'reports', 'Export reports to various formats'],
    
    // System permissions
    ['View Audit Logs', 'system.audit', 'system', 'View audit logs'],
    ['System Settings', 'system.settings', 'system', 'Modify system settings'],
    ['Backup Database', 'system.backup', 'system', 'Perform database backups'],
    ['View System Logs', 'system.logs', 'system', 'View system logs']
];

// Insert permissions
foreach ($default_permissions as $perm) {
    try {
        $exists = fetchOne($conn, "SELECT id FROM permissions WHERE permission_key = ?", [$perm[1]]);
        if (!$exists) {
            executeQuery($conn, 
                "INSERT INTO permissions (permission_name, permission_key, module, description) VALUES (?, ?, ?, ?)",
                $perm
            );
        }
    } catch (Exception $e) {
        // Permission might already exist
    }
}

// ============================================================================
// DEFAULT ROLE PERMISSIONS
// ============================================================================

$role_permissions = [
    'admin' => '*', // All permissions
    
    'surveyor' => [
        'dashboard.view',
        'parcels.view', 'parcels.create', 'parcels.edit',
        'titles.view',
        'surveys.view', 'surveys.create', 'surveys.edit',
        'documents.view', 'documents.upload', 'documents.download',
        'applications.view',
        'parties.view'
    ],
    
    'clerk' => [
        'dashboard.view',
        'parcels.view', 'parcels.create', 'parcels.edit',
        'titles.view', 'titles.create', 'titles.edit',
        'applications.view', 'applications.create', 'applications.process',
        'documents.view', 'documents.upload', 'documents.download',
        'parties.view', 'parties.create', 'parties.edit',
        'payments.view', 'payments.process'
    ],
    
    'public_officer' => [
        'dashboard.view',
        'parcels.view',
        'titles.view',
        'applications.view', 'applications.process', 'applications.approve',
        'disputes.view', 'disputes.manage',
        'reports.view', 'reports.generate',
        'documents.view', 'documents.download'
    ],
    
    'public' => [
        'dashboard.view',
        'parcels.view',
        'titles.view',
        'applications.view', 'applications.create',
        'documents.view', 'documents.download',
        'payments.view'
    ]
];

// ============================================================================
// HANDLE ACTIONS
// ============================================================================

$message = '';
$messageType = '';

// Save role permissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_role_permissions'])) {
    $role = $_POST['role'];
    $selected_permissions = $_POST['permissions'] ?? [];
    
    try {
        // Start transaction
        beginTransaction($conn);
        
        // Clear existing permissions for this role
        executeQuery($conn, "DELETE FROM role_permissions WHERE role = ?", [$role]);
        
        // Insert new permissions
        foreach ($selected_permissions as $perm_id) {
            executeQuery($conn, 
                "INSERT INTO role_permissions (role, permission_id) VALUES (?, ?)",
                [$role, $perm_id]
            );
        }
        
        commitTransaction($conn);
        $message = "Permissions for role '{$role}' updated successfully";
        $messageType = "success";
    } catch (Exception $e) {
        rollbackTransaction($conn);
        $message = "Error updating permissions: " . $e->getMessage();
        $messageType = "danger";
    }
}

// Assign role to user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_user_role'])) {
    $user_id = $_POST['user_id'];
    $role = $_POST['role'];
    $assigned_by = 1; // Current logged in user ID - you should get from session
    
    try {
        // Check if already assigned
        $exists = fetchOne($conn, "SELECT id FROM user_roles WHERE user_id = ? AND role = ?", [$user_id, $role]);
        
        if ($exists) {
            $message = "User already has this role";
            $messageType = "warning";
        } else {
            executeQuery($conn, 
                "INSERT INTO user_roles (user_id, role, assigned_by) VALUES (?, ?, ?)",
                [$user_id, $role, $assigned_by]
            );
            
            // Also update the user's main role in users table
            executeQuery($conn, "UPDATE users SET role = ? WHERE id = ?", [$role, $user_id]);
            
            $message = "Role assigned to user successfully";
            $messageType = "success";
        }
    } catch (Exception $e) {
        $message = "Error assigning role: " . $e->getMessage();
        $messageType = "danger";
    }
}

// Remove role from user
if (isset($_GET['remove_role']) && is_numeric($_GET['remove_role'])) {
    $user_role_id = $_GET['remove_role'];
    try {
        executeQuery($conn, "DELETE FROM user_roles WHERE id = ?", [$user_role_id]);
        $message = "Role removed from user successfully";
        $messageType = "success";
    } catch (Exception $e) {
        $message = "Error removing role: " . $e->getMessage();
        $messageType = "danger";
    }
}

// Create custom permission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_permission'])) {
    $perm_name = $_POST['permission_name'];
    $perm_key = $_POST['permission_key'];
    $module = $_POST['module'];
    $description = $_POST['description'];
    
    try {
        executeQuery($conn,
            "INSERT INTO permissions (permission_name, permission_key, module, description) VALUES (?, ?, ?, ?)",
            [$perm_name, $perm_key, $module, $description]
        );
        $message = "Permission created successfully";
        $messageType = "success";
    } catch (Exception $e) {
        $message = "Error creating permission: " . $e->getMessage();
        $messageType = "danger";
    }
}

// ============================================================================
// FETCH DATA
// ============================================================================

// Get all permissions grouped by module
$all_permissions = fetchAll($conn, "
    SELECT * FROM permissions 
    ORDER BY module, permission_name
");

$permissions_by_module = [];
foreach ($all_permissions as $perm) {
    $permissions_by_module[$perm['module']][] = $perm;
}

// Get permissions for each role
$role_permissions_data = [];
$roles = ['admin', 'surveyor', 'clerk', 'public_officer', 'public'];

foreach ($roles as $role) {
    $perms = fetchAll($conn, "
        SELECT p.* FROM permissions p
        JOIN role_permissions rp ON p.id = rp.permission_id
        WHERE rp.role = ? AND rp.granted = 1
    ", [$role]);
    
    $role_permissions_data[$role] = array_column($perms, 'id');
}

// Get all users
$users = fetchAll($conn, "
    SELECT u.*, 
           GROUP_CONCAT(ur.role SEPARATOR ', ') as additional_roles
    FROM users u
    LEFT JOIN user_roles ur ON u.id = ur.user_id
    GROUP BY u.id
    ORDER BY u.created_at DESC
");

// Get user role assignments
$user_roles = fetchAll($conn, "
    SELECT ur.*, u.username, u.role as primary_role,
           assigned.username as assigned_by_name
    FROM user_roles ur
    JOIN users u ON ur.user_id = u.id
    LEFT JOIN users assigned ON ur.assigned_by = assigned.id
    ORDER BY ur.assigned_at DESC
");

// Get active tab
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'roles';
?>

<body data-page="roles-permissions" class="roles-permissions-page">
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
                            <h1 class="h3 mb-0">Roles & Permissions</h1>
                            <p class="text-muted mb-0">Manage user roles and access permissions</p>
                        </div>
                        <div>
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createPermissionModal">
                                <i class="bi bi-plus-circle me-2"></i>Create Permission
                            </button>
                        </div>
                    </div>

                    <!-- Alert Message -->
                    <?php if ($message): ?>
                    <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show mb-4" role="alert">
                        <?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    <?php endif; ?>

                    <!-- Tabs Navigation -->
                    <ul class="nav nav-tabs mb-4" id="permissionsTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <a class="nav-link <?php echo $active_tab == 'roles' ? 'active' : ''; ?>" 
                               href="?tab=roles" role="tab">
                                <i class="bi bi-shield me-2"></i>Role Permissions
                            </a>
                        </li>
                        <li class="nav-item" role="presentation">
                            <a class="nav-link <?php echo $active_tab == 'users' ? 'active' : ''; ?>" 
                               href="?tab=users" role="tab">
                                <i class="bi bi-people me-2"></i>User Roles
                            </a>
                        </li>
                        <li class="nav-item" role="presentation">
                            <a class="nav-link <?php echo $active_tab == 'permissions' ? 'active' : ''; ?>" 
                               href="?tab=permissions" role="tab">
                                <i class="bi bi-key me-2"></i>All Permissions
                            </a>
                        </li>
                    </ul>

                    <!-- Tab Content -->
                    <div class="tab-content">
                        
                        <!-- ================================================= -->
                        <!-- TAB 1: ROLE PERMISSIONS -->
                        <!-- ================================================= -->
                        <div class="tab-pane <?php echo $active_tab == 'roles' ? 'active' : ''; ?>" id="roles-tab">
                            <div class="row">
                                <?php foreach ($roles as $role): ?>
                                <div class="col-12 mb-4">
                                    <div class="card border-0 shadow-sm">
                                        <div class="card-header bg-white py-3">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <h5 class="card-title mb-0 fw-bold text-<?php 
                                                        echo $role == 'admin' ? 'danger' : 
                                                            ($role == 'surveyor' ? 'primary' : 
                                                            ($role == 'clerk' ? 'success' : 
                                                            ($role == 'public_officer' ? 'warning' : 'info'))); 
                                                    ?>">
                                                        <i class="bi bi-shield me-2"></i>
                                                        <?php echo ucfirst(str_replace('_', ' ', $role)); ?>
                                                    </h5>
                                                </div>
                                                <button class="btn btn-sm btn-outline-primary" 
                                                        onclick="toggleRoleEdit('<?php echo $role; ?>')">
                                                    <i class="bi bi-pencil"></i> Edit Permissions
                                                </button>
                                            </div>
                                        </div>
                                        
                                        <!-- View Mode -->
                                        <div id="view-<?php echo $role; ?>" class="card-body">
                                            <?php 
                                            $role_perms = $role_permissions_data[$role] ?? [];
                                            if (empty($role_perms) && $role == 'admin'): ?>
                                                <div class="alert alert-info">
                                                    <i class="bi bi-info-circle me-2"></i>
                                                    Admin has access to ALL permissions
                                                </div>
                                            <?php elseif (empty($role_perms)): ?>
                                                <p class="text-muted">No specific permissions assigned</p>
                                            <?php else: 
                                                // Get permission details for display
                                                $placeholders = implode(',', array_fill(0, count($role_perms), '?'));
                                                $perms_display = fetchAll($conn, 
                                                    "SELECT * FROM permissions WHERE id IN ($placeholders) ORDER BY module", 
                                                    $role_perms
                                                );
                                                $current_module = '';
                                                foreach ($perms_display as $p): 
                                                    if ($current_module != $p['module']):
                                                        $current_module = $p['module'];
                                            ?>
                                                <h6 class="text-muted text-uppercase small fw-bold mt-3 mb-2">
                                                    <?php echo ucfirst($current_module); ?>
                                                </h6>
                                            <?php endif; ?>
                                                <span class="badge bg-light text-dark me-2 mb-2">
                                                    <?php echo $p['permission_name']; ?>
                                                </span>
                                            <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <!-- Edit Mode (Hidden by default) -->
                                        <div id="edit-<?php echo $role; ?>" class="card-body" style="display: none;">
                                            <form method="POST">
                                                <input type="hidden" name="role" value="<?php echo $role; ?>">
                                                <input type="hidden" name="save_role_permissions" value="1">
                                                
                                                <div class="row">
                                                    <?php foreach ($permissions_by_module as $module => $perms): ?>
                                                    <div class="col-md-6 col-lg-4 mb-3">
                                                        <div class="card bg-light">
                                                            <div class="card-header py-2">
                                                                <h6 class="mb-0 fw-bold text-primary">
                                                                    <?php echo ucfirst($module); ?>
                                                                </h6>
                                                            </div>
                                                            <div class="card-body" style="max-height: 200px; overflow-y: auto;">
                                                                <?php foreach ($perms as $perm): ?>
                                                                <div class="form-check mb-2">
                                                                    <input class="form-check-input" type="checkbox" 
                                                                           name="permissions[]" 
                                                                           value="<?php echo $perm['id']; ?>"
                                                                           id="perm_<?php echo $role . '_' . $perm['id']; ?>"
                                                                           <?php echo in_array($perm['id'], $role_permissions_data[$role] ?? []) ? 'checked' : ''; ?>
                                                                           <?php echo $role == 'admin' ? 'disabled' : ''; ?>>
                                                                    <label class="form-check-label small" 
                                                                           for="perm_<?php echo $role . '_' . $perm['id']; ?>">
                                                                        <?php echo $perm['permission_name']; ?>
                                                                        <br>
                                                                        <small class="text-muted">
                                                                            <?php echo $perm['permission_key']; ?>
                                                                        </small>
                                                                    </label>
                                                                </div>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <?php endforeach; ?>
                                                </div>
                                                
                                                <div class="mt-3">
                                                    <?php if ($role != 'admin'): ?>
                                                    <button type="submit" class="btn btn-primary">
                                                        <i class="bi bi-save me-2"></i>Save Permissions
                                                    </button>
                                                    <?php endif; ?>
                                                    <button type="button" class="btn btn-secondary" 
                                                            onclick="toggleRoleEdit('<?php echo $role; ?>')">
                                                        Cancel
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- ================================================= -->
                        <!-- TAB 2: USER ROLES -->
                        <!-- ================================================= -->
                        <div class="tab-pane <?php echo $active_tab == 'users' ? 'active' : ''; ?>" id="users-tab">
                            <div class="row">
                                <!-- Assign Role Form -->
                                <div class="col-lg-4 mb-4">
                                    <div class="card border-0 shadow-sm">
                                        <div class="card-header bg-white py-3">
                                            <h5 class="card-title mb-0 fw-bold">Assign Role to User</h5>
                                        </div>
                                        <div class="card-body">
                                            <form method="POST">
                                                <input type="hidden" name="assign_user_role" value="1">
                                                
                                                <div class="mb-3">
                                                    <label class="form-label">Select User</label>
                                                    <select class="form-select" name="user_id" required>
                                                        <option value="">Choose user...</option>
                                                        <?php foreach ($users as $user): ?>
                                                        <option value="<?php echo $user['id']; ?>">
                                                            <?php echo htmlspecialchars($user['username']); ?> 
                                                            (<?php echo $user['email'] ?? 'No email'; ?>)
                                                        </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                
                                                <div class="mb-3">
                                                    <label class="form-label">Assign Role</label>
                                                    <select class="form-select" name="role" required>
                                                        <option value="">Choose role...</option>
                                                        <option value="admin">Admin</option>
                                                        <option value="surveyor">Surveyor</option>
                                                        <option value="clerk">Clerk</option>
                                                        <option value="public_officer">Public Officer</option>
                                                        <option value="public">Public</option>
                                                    </select>
                                                </div>
                                                
                                                <button type="submit" class="btn btn-primary w-100">
                                                    <i class="bi bi-person-plus me-2"></i>Assign Role
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- User Roles List -->
                                <div class="col-lg-8 mb-4">
                                    <div class="card border-0 shadow-sm">
                                        <div class="card-header bg-white py-3">
                                            <h5 class="card-title mb-0 fw-bold">User Role Assignments</h5>
                                        </div>
                                        <div class="card-body p-0">
                                            <div class="table-responsive">
                                                <table class="table table-hover mb-0">
                                                    <thead class="table-light">
                                                        <tr>
                                                            <th>User</th>
                                                            <th>Primary Role</th>
                                                            <th>Assigned Role</th>
                                                            <th>Assigned By</th>
                                                            <th>Assigned At</th>
                                                            <th>Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($user_roles as $ur): ?>
                                                        <tr>
                                                            <td>
                                                                <span class="fw-medium">
                                                                    <?php echo htmlspecialchars($ur['username']); ?>
                                                                </span>
                                                            </td>
                                                            <td>
                                                                <span class="badge bg-<?php 
                                                                    echo $ur['primary_role'] == 'admin' ? 'danger' : 
                                                                        ($ur['primary_role'] == 'surveyor' ? 'primary' : 
                                                                        ($ur['primary_role'] == 'clerk' ? 'success' : 
                                                                        ($ur['primary_role'] == 'public_officer' ? 'warning' : 'info'))); 
                                                                ?>">
                                                                    <?php echo ucfirst($ur['primary_role']); ?>
                                                                </span>
                                                            </td>
                                                            <td>
                                                                <span class="badge bg-secondary">
                                                                    <?php echo ucfirst($ur['role']); ?>
                                                                </span>
                                                            </td>
                                                            <td><?php echo $ur['assigned_by_name'] ?? 'System'; ?></td>
                                                            <td>
                                                                <small>
                                                                    <?php echo date('M d, Y H:i', strtotime($ur['assigned_at'])); ?>
                                                                </small>
                                                            </td>
                                                            <td>
                                                                <a href="?remove_role=<?php echo $ur['id']; ?>&tab=users" 
                                                                   class="btn btn-sm btn-outline-danger"
                                                                   onclick="return confirm('Remove this role from user?')">
                                                                    <i class="bi bi-x-circle"></i>
                                                                </a>
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
                        </div>

                        <!-- ================================================= -->
                        <!-- TAB 3: ALL PERMISSIONS -->
                        <!-- ================================================= -->
                        <div class="tab-pane <?php echo $active_tab == 'permissions' ? 'active' : ''; ?>" id="permissions-tab">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                                    <h5 class="card-title mb-0 fw-bold">All System Permissions</h5>
                                    <div>
                                        <input type="text" class="form-control form-control-sm" 
                                               id="permissionSearch" placeholder="Search permissions...">
                                    </div>
                                </div>
                                <div class="card-body">
                                    <?php foreach ($permissions_by_module as $module => $perms): ?>
                                    <div class="mb-4 permission-module">
                                        <h6 class="text-primary fw-bold border-bottom pb-2">
                                            <?php echo ucfirst($module); ?> 
                                            <span class="badge bg-secondary"><?php echo count($perms); ?></span>
                                        </h6>
                                        <div class="row">
                                            <?php foreach ($perms as $perm): ?>
                                            <div class="col-md-6 col-lg-4 mb-2 permission-item">
                                                <div class="p-2 border rounded">
                                                    <div class="d-flex justify-content-between">
                                                        <div>
                                                            <span class="fw-medium"><?php echo $perm['permission_name']; ?></span>
                                                            <br>
                                                            <small class="text-muted">
                                                                <code><?php echo $perm['permission_key']; ?></code>
                                                            </small>
                                                        </div>
                                                        <div>
                                                            <?php
                                                            // Show which roles have this permission
                                                            $roles_with_perm = [];
                                                            foreach ($roles as $r) {
                                                                if (in_array($perm['id'], $role_permissions_data[$r] ?? [])) {
                                                                    $roles_with_perm[] = $r;
                                                                }
                                                            }
                                                            ?>
                                                            <?php if (!empty($roles_with_perm)): ?>
                                                            <small class="text-muted">
                                                                <?php foreach ($roles_with_perm as $r): ?>
                                                                <span class="badge bg-<?php 
                                                                    echo $r == 'admin' ? 'danger' : 
                                                                        ($r == 'surveyor' ? 'primary' : 
                                                                        ($r == 'clerk' ? 'success' : 
                                                                        ($r == 'public_officer' ? 'warning' : 'info'))); 
                                                                ?> bg-opacity-10">
                                                                    <?php echo substr($r, 0, 3); ?>
                                                                </span>
                                                                <?php endforeach; ?>
                                                            </small>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                    <?php if ($perm['description']): ?>
                                                    <small class="text-muted d-block mt-1">
                                                        <?php echo $perm['description']; ?>
                                                    </small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
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

    <!-- Create Permission Modal -->
    <div class="modal fade" id="createPermissionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="create_permission" value="1">
                    <div class="modal-header">
                        <h5 class="modal-title">Create New Permission</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Permission Name</label>
                            <input type="text" class="form-control" name="permission_name" required>
                            <small class="text-muted">e.g., View Reports</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Permission Key</label>
                            <input type="text" class="form-control" name="permission_key" required>
                            <small class="text-muted">e.g., reports.view (lowercase, dot notation)</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Module</label>
                            <select class="form-select" name="module" required>
                                <option value="">Select Module</option>
                                <option value="dashboard">Dashboard</option>
                                <option value="parcels">Parcels</option>
                                <option value="titles">Titles</option>
                                <option value="applications">Applications</option>
                                <option value="users">Users</option>
                                <option value="parties">Parties</option>
                                <option value="documents">Documents</option>
                                <option value="payments">Payments</option>
                                <option value="surveys">Surveys</option>
                                <option value="disputes">Disputes</option>
                                <option value="reports">Reports</option>
                                <option value="system">System</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create Permission</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script>
    function toggleRoleEdit(role) {
        document.getElementById('view-' + role).style.display = 
            document.getElementById('view-' + role).style.display === 'none' ? 'block' : 'none';
        document.getElementById('edit-' + role).style.display = 
            document.getElementById('edit-' + role).style.display === 'none' ? 'block' : 'none';
    }
    
    // Search permissions
    document.getElementById('permissionSearch')?.addEventListener('keyup', function() {
        let searchText = this.value.toLowerCase();
        let modules = document.querySelectorAll('.permission-module');
        
        modules.forEach(module => {
            let items = module.querySelectorAll('.permission-item');
            let hasVisible = false;
            
            items.forEach(item => {
                let text = item.textContent.toLowerCase();
                if (text.includes(searchText)) {
                    item.style.display = '';
                    hasVisible = true;
                } else {
                    item.style.display = 'none';
                }
            });
            
            module.style.display = hasVisible ? '' : 'none';
        });
    });
    </script>

    <style>
    .nav-tabs .nav-link {
        color: #6c757d;
        font-weight: 500;
    }
    
    .nav-tabs .nav-link.active {
        color: #0d6efd;
        font-weight: 600;
    }
    
    .form-check {
        padding-left: 1.8rem;
    }
    
    .form-check .form-check-input {
        margin-left: -1.8rem;
    }
    
    .badge.bg-opacity-10 {
        opacity: 0.8;
    }
    
    code {
        background: #f8f9fa;
        padding: 2px 4px;
        border-radius: 4px;
        font-size: 11px;
    }
    </style>

</body>
</html>