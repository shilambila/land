<?php
ini_set('display_errors', 1); 
error_reporting(E_ALL);

// profile.php - User Profile Management
session_start();
include("head.php");
require_once 'db_connection.php';

// Check if user is logged in
// if (!isset($_SESSION['user_id'])) {
//     header("Location: login.php");
//     exit();
// }

// $user_id = $_SESSION['user_id'];
$user_id = 1;

// ============================================================================
// HANDLE ACTIONS
// ============================================================================

$message = '';
$messageType = '';

// Get user data
try {
    $user = fetchOne($conn, "
        SELECT u.*, p.name as party_name, p.party_type, p.national_id, 
               p.phone, p.email as party_email, p.physical_address, p.postal_address,
               (SELECT COUNT(*) FROM audit_logs WHERE user_id = u.id) as audit_count
        FROM users u
        LEFT JOIN parties p ON u.party_id = p.id
        WHERE u.id = ?
    ", [$user_id]);
    
    if (!$user) {
        throw new Exception("User not found");
    }
} catch (Exception $e) {
    error_log("Error loading user: " . $e->getMessage());
    $message = "Error loading profile: " . $e->getMessage();
    $messageType = "danger";
}

// Update profile information
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    $email = $_POST['email'];
    $phone = $_POST['phone'] ?? null;
    $physical_address = $_POST['physical_address'] ?? null;
    $postal_address = $_POST['postal_address'] ?? null;
    
    try {
        beginTransaction($conn);
        
        // Update user email
        executeQuery($conn, "UPDATE users SET email = ? WHERE id = ?", [$email, $user_id]);
        
        // If user has a linked party, update party info
        if (!empty($user['party_id'])) {
            executeQuery($conn, "
                UPDATE parties SET 
                    phone = ?, 
                    physical_address = ?, 
                    postal_address = ?,
                    email = ?
                WHERE id = ?
            ", [$phone, $physical_address, $postal_address, $email, $user['party_id']]);
        }
        
        commitTransaction($conn);
        
        $message = "Profile updated successfully";
        $messageType = "success";
        
        // Refresh user data
        $user = fetchOne($conn, "
            SELECT u.*, p.name as party_name, p.party_type, p.national_id, 
                   p.phone, p.email as party_email, p.physical_address, p.postal_address,
                   (SELECT COUNT(*) FROM audit_logs WHERE user_id = u.id) as audit_count
            FROM users u
            LEFT JOIN parties p ON u.party_id = p.id
            WHERE u.id = ?
        ", [$user_id]);
        
    } catch (Exception $e) {
        rollbackTransaction($conn);
        $message = "Error updating profile: " . $e->getMessage();
        $messageType = "danger";
        error_log("Update error: " . $e->getMessage());
    }
}

// Change password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    try {
        // Verify current password
        $user_data = fetchOne($conn, "SELECT password_hash FROM users WHERE id = ?", [$user_id]);
        
        if (!password_verify($current_password, $user_data['password_hash'])) {
            throw new Exception("Current password is incorrect");
        }
        
        if ($new_password !== $confirm_password) {
            throw new Exception("New passwords do not match");
        }
        
        if (strlen($new_password) < 8) {
            throw new Exception("Password must be at least 8 characters long");
        }
        
        // Update password
        $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
        executeQuery($conn, "UPDATE users SET password_hash = ? WHERE id = ?", [$password_hash, $user_id]);
        
        $message = "Password changed successfully";
        $messageType = "success";
        
    } catch (Exception $e) {
        $message = "Error changing password: " . $e->getMessage();
        $messageType = "danger";
        error_log("Password error: " . $e->getMessage());
    }
}

// Update preferences
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_preferences') {
    $language = $_POST['language'] ?? 'en';
    $notifications = isset($_POST['notifications']) ? 1 : 0;
    $items_per_page = $_POST['items_per_page'] ?? 20;
    
    try {
        // Store preferences in session or user meta table
        // For now, we'll store in session
        $_SESSION['preferences'] = [
            'language' => $language,
            'notifications' => $notifications,
            'items_per_page' => $items_per_page
        ];
        
        $message = "Preferences updated successfully";
        $messageType = "success";
        
    } catch (Exception $e) {
        $message = "Error updating preferences: " . $e->getMessage();
        $messageType = "danger";
        error_log("Preferences error: " . $e->getMessage());
    }
}

// ============================================================================
// GET USER ACTIVITY
// ============================================================================

try {
    // Recent login history (from audit logs or custom table)
    $login_history = fetchAll($conn, "
        SELECT * FROM (
            SELECT 
                'login' as action,
                created_at as timestamp,
                'User logged in' as description
            FROM audit_logs 
            WHERE user_id = ? AND action = 'LOGIN'
            UNION ALL
            SELECT 
                'logout' as action,
                created_at as timestamp,
                'User logged out' as description
            FROM audit_logs 
            WHERE user_id = ? AND action = 'LOGOUT'
            ORDER BY timestamp DESC
            LIMIT 10
        ) as history
    ", [$user_id, $user_id]);
} catch (Exception $e) {
    error_log("Error loading login history: " . $e->getMessage());
    $login_history = [];
}

try {
    // Recent activity
    $recent_activity = fetchAll($conn, "
        SELECT 
            table_name,
            action,
            record_id,
            change_time as timestamp,
            CONCAT(action, ' on ', table_name, ' #', record_id) as description
        FROM audit_logs 
        WHERE user_id = ?
        ORDER BY change_time DESC
        LIMIT 10
    ", [$user_id]);
} catch (Exception $e) {
    error_log("Error loading recent activity: " . $e->getMessage());
    $recent_activity = [];
}

try {
    // User statistics
    $stats = fetchOne($conn, "
        SELECT 
            (SELECT COUNT(*) FROM audit_logs WHERE user_id = ?) as total_actions,
            (SELECT COUNT(DISTINCT DATE(change_time)) FROM audit_logs WHERE user_id = ?) as active_days,
            (SELECT MAX(change_time) FROM audit_logs WHERE user_id = ?) as last_action,
            (SELECT COUNT(*) FROM documents WHERE uploaded_by = ?) as documents_uploaded
        ",
        [$user_id, $user_id, $user_id, $user_id]
    );
} catch (Exception $e) {
    error_log("Error loading stats: " . $e->getMessage());
    $stats = [
        'total_actions' => 0,
        'active_days' => 0,
        'last_action' => null,
        'documents_uploaded' => 0
    ];
}

// Get user preferences from session
$preferences = $_SESSION['preferences'] ?? [
    'language' => 'en',
    'notifications' => 1,
    'items_per_page' => 20
];

// Format date function
function formatDateTime($datetime) {
    if (!$datetime) return 'Never';
    return date('d M Y H:i', strtotime($datetime));
}

// Get initials for avatar
function getInitials($name) {
    $words = explode(' ', $name);
    $initials = '';
    foreach ($words as $word) {
        if (!empty($word)) {
            $initials .= strtoupper(substr($word, 0, 1));
        }
    }
    return substr($initials, 0, 2);
}
?>

<body data-page="profile" class="profile-page">
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
                            <h1 class="h3 mb-0">My Profile</h1>
                            <p class="text-muted mb-0">Manage your account settings and preferences</p>
                        </div>
                        <div>
                            <span class="badge bg-<?php echo $user['is_active'] ? 'success' : 'danger'; ?> p-2">
                                <i class="bi bi-circle-fill me-1"></i>
                                <?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?>
                            </span>
                        </div>
                    </div>

                    <!-- Alert Message -->
                    <?php if ($message): ?>
                    <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show mb-4" role="alert">
                        <?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    <?php endif; ?>

                    <div class="row">
                        <!-- Profile Sidebar -->
                        <div class="col-lg-4 mb-4">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body text-center p-4">
                                    <div class="avatar-circle mx-auto mb-3">
                                        <span class="initials"><?php echo getInitials($user['username']); ?></span>
                                    </div>
                                    <h4 class="mb-1"><?php echo htmlspecialchars($user['username']); ?></h4>
                                    <p class="text-muted mb-2"><?php echo ucfirst($user['role']); ?></p>
                                    
                                    <?php if (!empty($user['party_name'])): ?>
                                    <div class="mb-3">
                                        <span class="badge bg-info">Linked Party: <?php echo htmlspecialchars($user['party_name']); ?></span>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="d-flex justify-content-center gap-2 mb-3">
                                        <span class="badge bg-secondary">
                                            <i class="bi bi-calendar me-1"></i>
                                            Joined: <?php echo date('M Y', strtotime($user['created_at'])); ?>
                                        </span>
                                        <span class="badge bg-secondary">
                                            <i class="bi bi-clock-history me-1"></i>
                                            Last: <?php echo $user['last_login'] ? formatDateTime($user['last_login']) : 'Never'; ?>
                                        </span>
                                    </div>
                                    
                                    <hr>
                                    
                                    <div class="row text-center">
                                        <div class="col-4">
                                            <h5 class="mb-0"><?php echo number_format($stats['total_actions'] ?? 0); ?></h5>
                                            <small class="text-muted">Actions</small>
                                        </div>
                                        <div class="col-4">
                                            <h5 class="mb-0"><?php echo number_format($stats['active_days'] ?? 0); ?></h5>
                                            <small class="text-muted">Active Days</small>
                                        </div>
                                        <div class="col-4">
                                            <h5 class="mb-0"><?php echo number_format($stats['documents_uploaded'] ?? 0); ?></h5>
                                            <small class="text-muted">Documents</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Quick Links -->
                            <div class="card border-0 shadow-sm mt-4">
                                <div class="card-header bg-white py-3">
                                    <h5 class="card-title mb-0 fw-bold">
                                        <i class="bi bi-link me-2"></i>
                                        Quick Links
                                    </h5>
                                </div>
                                <div class="card-body p-0">
                                    <div class="list-group list-group-flush">
                                        <a href="dashboard.php" class="list-group-item list-group-item-action">
                                            <i class="bi bi-speedometer2 me-2 text-primary"></i>
                                            Dashboard
                                        </a>
                                        <a href="documents.php?uploaded_by=<?php echo $user_id; ?>" class="list-group-item list-group-item-action">
                                            <i class="bi bi-files me-2 text-success"></i>
                                            My Documents
                                        </a>
                                        <a href="audit-logs.php?user_id=<?php echo $user_id; ?>" class="list-group-item list-group-item-action">
                                            <i class="bi bi-clock-history me-2 text-info"></i>
                                            My Activity Log
                                        </a>
                                        <a href="logout.php" class="list-group-item list-group-item-action text-danger">
                                            <i class="bi bi-box-arrow-right me-2"></i>
                                            Logout
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Main Content -->
                        <div class="col-lg-8">
                            <!-- Profile Information -->
                            <div class="card border-0 shadow-sm mb-4">
                                <div class="card-header bg-white py-3">
                                    <h5 class="card-title mb-0 fw-bold">
                                        <i class="bi bi-person me-2"></i>
                                        Profile Information
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <form method="POST">
                                        <input type="hidden" name="action" value="update_profile">
                                        
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label">Username</label>
                                                <input type="text" class="form-control" value="<?php echo htmlspecialchars($user['username']); ?>" readonly disabled>
                                                <small class="text-muted">Username cannot be changed</small>
                                            </div>
                                            
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label">Role</label>
                                                <input type="text" class="form-control" value="<?php echo ucfirst($user['role']); ?>" readonly disabled>
                                            </div>
                                            
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label">Email Address</label>
                                                <input type="email" class="form-control" name="email" 
                                                       value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" required>
                                            </div>
                                            
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label">Phone Number</label>
                                                <input type="text" class="form-control" name="phone" 
                                                       value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
                                            </div>
                                            
                                            <div class="col-12 mb-3">
                                                <label class="form-label">Physical Address</label>
                                                <textarea class="form-control" name="physical_address" rows="2"><?php echo htmlspecialchars($user['physical_address'] ?? ''); ?></textarea>
                                            </div>
                                            
                                            <div class="col-12 mb-3">
                                                <label class="form-label">Postal Address</label>
                                                <textarea class="form-control" name="postal_address" rows="2"><?php echo htmlspecialchars($user['postal_address'] ?? ''); ?></textarea>
                                            </div>
                                            
                                            <?php if (!empty($user['party_name'])): ?>
                                            <div class="col-12">
                                                <div class="alert alert-info">
                                                    <i class="bi bi-info-circle"></i>
                                                    This profile is linked to party: <strong><?php echo htmlspecialchars($user['party_name']); ?></strong> (<?php echo ucfirst($user['party_type']); ?>)
                                                    <?php if (!empty($user['national_id'])): ?>
                                                    <br>National ID: <?php echo $user['national_id']; ?>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <div class="col-12">
                                                <button type="submit" class="btn btn-primary">
                                                    <i class="bi bi-check-circle me-2"></i>Update Profile
                                                </button>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                            
                            <!-- Change Password -->
                            <div class="card border-0 shadow-sm mb-4">
                                <div class="card-header bg-white py-3">
                                    <h5 class="card-title mb-0 fw-bold">
                                        <i class="bi bi-key me-2"></i>
                                        Change Password
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <form method="POST">
                                        <input type="hidden" name="action" value="change_password">
                                        
                                        <div class="row">
                                            <div class="col-12 mb-3">
                                                <label class="form-label">Current Password</label>
                                                <input type="password" class="form-control" name="current_password" required>
                                            </div>
                                            
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label">New Password</label>
                                                <input type="password" class="form-control" name="new_password" 
                                                       pattern=".{8,}" title="Minimum 8 characters" required>
                                                <small class="text-muted">Minimum 8 characters</small>
                                            </div>
                                            
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label">Confirm New Password</label>
                                                <input type="password" class="form-control" name="confirm_password" required>
                                            </div>
                                            
                                            <div class="col-12">
                                                <button type="submit" class="btn btn-warning">
                                                    <i class="bi bi-shield-lock me-2"></i>Change Password
                                                </button>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                            
                            <!-- Preferences -->
                            <div class="card border-0 shadow-sm mb-4">
                                <div class="card-header bg-white py-3">
                                    <h5 class="card-title mb-0 fw-bold">
                                        <i class="bi bi-gear me-2"></i>
                                        Preferences
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <form method="POST">
                                        <input type="hidden" name="action" value="update_preferences">
                                        
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label">Language</label>
                                                <select class="form-select" name="language">
                                                    <option value="en" <?php echo $preferences['language'] == 'en' ? 'selected' : ''; ?>>English</option>
                                                    <option value="ar" <?php echo $preferences['language'] == 'ar' ? 'selected' : ''; ?>>العربية</option>
                                                    <option value="fr" <?php echo $preferences['language'] == 'fr' ? 'selected' : ''; ?>>Français</option>
                                                </select>
                                            </div>
                                            
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label">Items Per Page</label>
                                                <select class="form-select" name="items_per_page">
                                                    <option value="10" <?php echo $preferences['items_per_page'] == 10 ? 'selected' : ''; ?>>10</option>
                                                    <option value="20" <?php echo $preferences['items_per_page'] == 20 ? 'selected' : ''; ?>>20</option>
                                                    <option value="50" <?php echo $preferences['items_per_page'] == 50 ? 'selected' : ''; ?>>50</option>
                                                    <option value="100" <?php echo $preferences['items_per_page'] == 100 ? 'selected' : ''; ?>>100</option>
                                                </select>
                                            </div>
                                            
                                            <div class="col-12 mb-3">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="notifications" 
                                                           id="notifications" <?php echo $preferences['notifications'] ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="notifications">
                                                        Enable email notifications
                                                    </label>
                                                </div>
                                            </div>
                                            
                                            <div class="col-12">
                                                <button type="submit" class="btn btn-primary">
                                                    <i class="bi bi-save me-2"></i>Save Preferences
                                                </button>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                            
                            <!-- Recent Activity -->
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                                    <h5 class="card-title mb-0 fw-bold">
                                        <i class="bi bi-clock-history me-2"></i>
                                        Recent Activity
                                    </h5>
                                    <a href="audit-logs.php?user_id=<?php echo $user_id; ?>" class="btn btn-sm btn-outline-primary">
                                        View All
                                    </a>
                                </div>
                                <div class="card-body p-0">
                                    <?php if (empty($recent_activity)): ?>
                                        <p class="text-muted text-center py-4">No recent activity found</p>
                                    <?php else: ?>
                                        <div class="list-group list-group-flush">
                                            <?php foreach ($recent_activity as $activity): ?>
                                            <div class="list-group-item">
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <div>
                                                        <span class="badge bg-<?php 
                                                            echo $activity['action'] == 'INSERT' ? 'success' : 
                                                                ($activity['action'] == 'UPDATE' ? 'info' : 'danger'); 
                                                        ?> me-2">
                                                            <?php echo $activity['action']; ?>
                                                        </span>
                                                        <span><?php echo htmlspecialchars($activity['description']); ?></span>
                                                    </div>
                                                    <small class="text-muted">
                                                        <?php echo formatDateTime($activity['timestamp']); ?>
                                                    </small>
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

    <style>
        .avatar-circle {
            width: 100px;
            height: 100px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .initials {
            font-size: 40px;
            color: white;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .profile-page .card {
            border-radius: 10px;
            transition: transform 0.2s;
        }
        
        .profile-page .card:hover {
            transform: translateY(-2px);
        }
        
        .list-group-item {
            border-left: none;
            border-right: none;
            padding: 1rem;
        }
        
        .list-group-item:first-child {
            border-top: none;
        }
        
        .list-group-item:last-child {
            border-bottom: none;
        }
        
        .badge {
            font-size: 0.85rem;
            padding: 0.5rem 0.75rem;
        }
        
        .form-control:read-only,
        .form-control:disabled {
            background-color: #f8f9fa;
            cursor: not-allowed;
        }
        
        @media (max-width: 768px) {
            .avatar-circle {
                width: 80px;
                height: 80px;
            }
            
            .initials {
                font-size: 32px;
            }
        }
    </style>

    <script>
        // Confirm password match
        document.querySelector('form[action*="change_password"]')?.addEventListener('submit', function(e) {
            const newPass = document.querySelector('input[name="new_password"]').value;
            const confirmPass = document.querySelector('input[name="confirm_password"]').value;
            
            if (newPass !== confirmPass) {
                e.preventDefault();
                alert('New passwords do not match!');
            }
        });
        
        // Enable/disable notifications
        document.getElementById('notifications')?.addEventListener('change', function() {
            // Could implement AJAX to save preference immediately
            console.log('Notifications:', this.checked);
        });
    </script>

</body>
</html>