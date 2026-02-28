<?php
ini_set('display_errors', 1); 
error_reporting(E_ALL);

// users.php
include("head.php");
require_once 'db_connection.php';

// Handle user actions (add, edit, delete, toggle status)
$message = '';
$messageType = '';

// Delete user
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $userId = $_GET['delete'];
    try {
        // Check if user has related records
        $check = fetchOne($conn, "SELECT id FROM audit_logs WHERE user_id = ?", [$userId]);
        if ($check) {
            // Soft delete - just deactivate
            executeQuery($conn, "UPDATE users SET is_active = 0 WHERE id = ?", [$userId]);
            $message = "User deactivated successfully (has related records)";
            $messageType = "warning";
        } else {
            // Hard delete
            executeQuery($conn, "DELETE FROM users WHERE id = ?", [$userId]);
            $message = "User deleted successfully";
            $messageType = "success";
        }
    } catch (Exception $e) {
        $message = "Error deleting user: " . $e->getMessage();
        $messageType = "danger";
    }
}

// Toggle user status (activate/deactivate)
if (isset($_GET['toggle']) && is_numeric($_GET['toggle'])) {
    $userId = $_GET['toggle'];
    $current = fetchOne($conn, "SELECT is_active FROM users WHERE id = ?", [$userId]);
    if ($current) {
        $newStatus = $current['is_active'] ? 0 : 1;
        executeQuery($conn, "UPDATE users SET is_active = ? WHERE id = ?", [$newStatus, $userId]);
        $message = "User status updated successfully";
        $messageType = "success";
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        // Add new user
        if ($_POST['action'] === 'add') {
            $username = $_POST['username'];
            $email = $_POST['email'];
            $role = $_POST['role'];
            $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $party_id = !empty($_POST['party_id']) ? $_POST['party_id'] : null;
            
            try {
                // Check if username exists
                $exists = fetchOne($conn, "SELECT id FROM users WHERE username = ?", [$username]);
                if ($exists) {
                    $message = "Username already exists";
                    $messageType = "danger";
                } else {
                    executeQuery($conn, 
                        "INSERT INTO users (username, password_hash, email, role, party_id, is_active) VALUES (?, ?, ?, ?, ?, 1)",
                        [$username, $password, $email, $role, $party_id]
                    );
                    $message = "User added successfully";
                    $messageType = "success";
                }
            } catch (Exception $e) {
                $message = "Error adding user: " . $e->getMessage();
                $messageType = "danger";
            }
        }
        
        // Edit user
        if ($_POST['action'] === 'edit') {
            $userId = $_POST['user_id'];
            $username = $_POST['username'];
            $email = $_POST['email'];
            $role = $_POST['role'];
            $party_id = !empty($_POST['party_id']) ? $_POST['party_id'] : null;
            
            try {
                // Check if username exists for other users
                $exists = fetchOne($conn, "SELECT id FROM users WHERE username = ? AND id != ?", [$username, $userId]);
                if ($exists) {
                    $message = "Username already exists";
                    $messageType = "danger";
                } else {
                    if (!empty($_POST['password'])) {
                        // Update with new password
                        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
                        executeQuery($conn, 
                            "UPDATE users SET username = ?, email = ?, role = ?, party_id = ?, password_hash = ? WHERE id = ?",
                            [$username, $email, $role, $party_id, $password, $userId]
                        );
                    } else {
                        // Update without password
                        executeQuery($conn, 
                            "UPDATE users SET username = ?, email = ?, role = ?, party_id = ? WHERE id = ?",
                            [$username, $email, $role, $party_id, $userId]
                        );
                    }
                    $message = "User updated successfully";
                    $messageType = "success";
                }
            } catch (Exception $e) {
                $message = "Error updating user: " . $e->getMessage();
                $messageType = "danger";
            }
        }
    }
}

// Get all users with party information
$users = fetchAll($conn, "
    SELECT u.*, p.name as party_name, p.party_type 
    FROM users u 
    LEFT JOIN parties p ON u.party_id = p.id 
    ORDER BY u.created_at DESC
");

// Get all parties for dropdown
$parties = fetchAll($conn, "SELECT id, name, party_type FROM parties ORDER BY name");

// Get user for editing if requested
$editUser = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $editUser = fetchOne($conn, "SELECT * FROM users WHERE id = ?", [$_GET['edit']]);
}

// Role badges configuration
$role_badges = [
    'admin' => 'danger',
    'surveyor' => 'primary',
    'clerk' => 'success',
    'public_officer' => 'warning',
    'public' => 'info'
];
?>

<body data-page="users" class="users-page">
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
                            <h1 class="h3 mb-0">User Management</h1>
                            <p class="text-muted mb-0">Manage system users and their permissions</p>
                        </div>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
                            <i class="bi bi-person-plus me-2"></i>Add New User
                        </button>
                    </div>

                    <!-- Alert Message -->
                    <?php if ($message): ?>
                    <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show mb-4" role="alert">
                        <?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    <?php endif; ?>

                    <!-- Users Stats -->
                    <div class="row g-4 mb-5">
                        <div class="col-xl-3 col-md-6">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0 me-3">
                                            <div class="bg-primary bg-opacity-10 rounded-circle p-3">
                                                <i class="bi bi-people text-primary fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Total Users</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo count($users); ?></h3>
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
                                                <i class="bi bi-person-check text-success fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Active Users</h6>
                                            <h3 class="mb-0 fw-bold">
                                                <?php echo count(array_filter($users, function($u) { return $u['is_active']; })); ?>
                                            </h3>
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
                                                <i class="bi bi-shield text-warning fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Admins</h6>
                                            <h3 class="mb-0 fw-bold">
                                                <?php echo count(array_filter($users, function($u) { return $u['role'] === 'admin'; })); ?>
                                            </h3>
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
                                                <i class="bi bi-person-down text-info fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Inactive</h6>
                                            <h3 class="mb-0 fw-bold">
                                                <?php echo count(array_filter($users, function($u) { return !$u['is_active']; })); ?>
                                            </h3>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Users Table -->
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white py-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0 fw-bold">System Users</h5>
                                <div class="input-group" style="width: 300px;">
                                    <span class="input-group-text bg-white border-end-0">
                                        <i class="bi bi-search"></i>
                                    </span>
                                    <input type="text" class="form-control border-start-0" id="searchInput" placeholder="Search users...">
                                </div>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0" id="usersTable">
                                    <thead class="table-light">
                                        <tr>
                                            <th>ID</th>
                                            <th>Username</th>
                                            <th>Email</th>
                                            <th>Role</th>
                                            <th>Party</th>
                                            <th>Status</th>
                                            <th>Last Login</th>
                                            <th>Created</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($users as $user): ?>
                                        <tr>
                                            <td>
                                                <span class="fw-medium">#<?php echo $user['id']; ?></span>
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="avatar-circle bg-<?php echo $role_badges[$user['role']] ?? 'secondary'; ?> bg-opacity-10 me-2">
                                                        <span class="text-<?php echo $role_badges[$user['role']] ?? 'secondary'; ?> fw-bold">
                                                            <?php echo strtoupper(substr($user['username'], 0, 2)); ?>
                                                        </span>
                                                    </div>
                                                    <span class="fw-medium"><?php echo htmlspecialchars($user['username']); ?></span>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($user['email'] ?? 'N/A'); ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo $role_badges[$user['role']] ?? 'secondary'; ?>">
                                                    <?php echo ucfirst(str_replace('_', ' ', $user['role'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($user['party_name']): ?>
                                                    <span class="text-muted small">
                                                        <?php echo htmlspecialchars($user['party_name']); ?>
                                                        <br><small>(<?php echo $user['party_type']; ?>)</small>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($user['is_active']): ?>
                                                    <span class="badge bg-success">Active</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Inactive</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php echo $user['last_login'] ? date('M d, Y', strtotime($user['last_login'])) : 'Never'; ?>
                                            </td>
                                            <td>
                                                <small class="text-muted">
                                                    <?php echo date('M d, Y', strtotime($user['created_at'])); ?>
                                                </small>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <a href="?edit=<?php echo $user['id']; ?>" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editUserModal<?php echo $user['id']; ?>">
                                                        <i class="bi bi-pencil"></i>
                                                    </a>
                                                    <a href="?toggle=<?php echo $user['id']; ?>" class="btn btn-outline-<?php echo $user['is_active'] ? 'warning' : 'success'; ?>" onclick="return confirm('Toggle user status?')">
                                                        <i class="bi bi-<?php echo $user['is_active'] ? 'pause' : 'play'; ?>"></i>
                                                    </a>
                                                    <a href="?delete=<?php echo $user['id']; ?>" class="btn btn-outline-danger" onclick="return confirm('Are you sure you want to delete this user?')">
                                                        <i class="bi bi-trash"></i>
                                                    </a>
                                                </div>

                                                <!-- Edit User Modal -->
                                                <div class="modal fade" id="editUserModal<?php echo $user['id']; ?>" tabindex="-1">
                                                    <div class="modal-dialog">
                                                        <div class="modal-content">
                                                            <form method="POST">
                                                                <input type="hidden" name="action" value="edit">
                                                                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                                <div class="modal-header">
                                                                    <h5 class="modal-title">Edit User</h5>
                                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                                </div>
                                                                <div class="modal-body">
                                                                    <div class="mb-3">
                                                                        <label class="form-label">Username</label>
                                                                        <input type="text" class="form-control" name="username" value="<?php echo htmlspecialchars($user['username']); ?>" required>
                                                                    </div>
                                                                    <div class="mb-3">
                                                                        <label class="form-label">Email</label>
                                                                        <input type="email" class="form-control" name="email" value="<?php echo htmlspecialchars($user['email']); ?>">
                                                                    </div>
                                                                    <div class="mb-3">
                                                                        <label class="form-label">Password (leave blank to keep current)</label>
                                                                        <input type="password" class="form-control" name="password">
                                                                    </div>
                                                                    <div class="mb-3">
                                                                        <label class="form-label">Role</label>
                                                                        <select class="form-select" name="role" required>
                                                                            <option value="admin" <?php echo $user['role'] == 'admin' ? 'selected' : ''; ?>>Admin</option>
                                                                            <option value="surveyor" <?php echo $user['role'] == 'surveyor' ? 'selected' : ''; ?>>Surveyor</option>
                                                                            <option value="clerk" <?php echo $user['role'] == 'clerk' ? 'selected' : ''; ?>>Clerk</option>
                                                                            <option value="public_officer" <?php echo $user['role'] == 'public_officer' ? 'selected' : ''; ?>>Public Officer</option>
                                                                            <option value="public" <?php echo $user['role'] == 'public' ? 'selected' : ''; ?>>Public</option>
                                                                        </select>
                                                                    </div>
                                                                    <div class="mb-3">
                                                                        <label class="form-label">Link to Party (Optional)</label>
                                                                        <select class="form-select" name="party_id">
                                                                            <option value="">None</option>
                                                                            <?php foreach ($parties as $party): ?>
                                                                            <option value="<?php echo $party['id']; ?>" <?php echo $user['party_id'] == $party['id'] ? 'selected' : ''; ?>>
                                                                                <?php echo htmlspecialchars($party['name']); ?> (<?php echo $party['party_type']; ?>)
                                                                            </option>
                                                                            <?php endforeach; ?>
                                                                        </select>
                                                                    </div>
                                                                </div>
                                                                <div class="modal-footer">
                                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                                    <button type="submit" class="btn btn-primary">Update User</button>
                                                                </div>
                                                            </form>
                                                        </div>
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
            </main>

            <!-- Footer -->
            <?php include("footer.php"); ?>

        </div>
    </div>

    <!-- Add User Modal -->
    <div class="modal fade" id="addUserModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="add">
                    <div class="modal-header">
                        <h5 class="modal-title">Add New User</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Username</label>
                            <input type="text" class="form-control" name="username" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Password</label>
                            <input type="password" class="form-control" name="password" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Role</label>
                            <select class="form-select" name="role" required>
                                <option value="">Select Role</option>
                                <option value="admin">Admin</option>
                                <option value="surveyor">Surveyor</option>
                                <option value="clerk">Clerk</option>
                                <option value="public_officer">Public Officer</option>
                                <option value="public">Public</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Link to Party (Optional)</label>
                            <select class="form-select" name="party_id">
                                <option value="">None</option>
                                <?php foreach ($parties as $party): ?>
                                <option value="<?php echo $party['id']; ?>">
                                    <?php echo htmlspecialchars($party['name']); ?> (<?php echo $party['party_type']; ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Search Script -->
    <script>
    document.getElementById('searchInput').addEventListener('keyup', function() {
        let searchText = this.value.toLowerCase();
        let tableRows = document.querySelectorAll('#usersTable tbody tr');
        
        tableRows.forEach(row => {
            let text = row.textContent.toLowerCase();
            row.style.display = text.includes(searchText) ? '' : 'none';
        });
    });

    // Auto-show edit modal if edit parameter is set
    <?php if (isset($_GET['edit']) && is_numeric($_GET['edit'])): ?>
    document.addEventListener('DOMContentLoaded', function() {
        let editModal = new bootstrap.Modal(document.getElementById('editUserModal<?php echo $_GET['edit']; ?>'));
        editModal.show();
    });
    <?php endif; ?>
    </script>

    <style>
    .avatar-circle {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 12px;
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
    </style>

</body>
</html>