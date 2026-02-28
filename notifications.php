<?php
ini_set('display_errors', 1); 
error_reporting(E_ALL);

// notifications.php - System Notifications Management
session_start();
include("head.php");
require_once 'db_connection.php';

// Check if user is logged in
// if (!isset($_SESSION['user_id'])) {
//     header("Location: login.php");
//     exit();
// }

// $user_id = $_SESSION['user_id'];
// $user_role = $_SESSION['role'] ?? 'public';
$user_id = $_SESSION['user_id'] ?? 1;
$user_role = $_SESSION['role'] ?? 'admin';

// ============================================================================
// CREATE NOTIFICATIONS TABLE IF NOT EXISTS
// ============================================================================

try {
    // Check if notifications table exists, create if not
    $conn->exec("
        CREATE TABLE IF NOT EXISTS notifications (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED DEFAULT NULL,
            type VARCHAR(50) NOT NULL,
            title VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            link VARCHAR(500) DEFAULT NULL,
            is_read TINYINT(1) DEFAULT 0,
            is_global TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            expires_at DATE DEFAULT NULL,
            INDEX idx_user (user_id),
            INDEX idx_read (is_read),
            INDEX idx_global (is_global),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (Exception $e) {
    error_log("Error creating notifications table: " . $e->getMessage());
}

// ============================================================================
// HANDLE ACTIONS
// ============================================================================

$message = '';
$messageType = '';

// Mark notification as read
if (isset($_GET['read']) && is_numeric($_GET['read'])) {
    $notification_id = $_GET['read'];
    
    try {
        executeQuery($conn, "
            UPDATE notifications SET is_read = 1 
            WHERE id = ? AND (user_id = ? OR is_global = 1)
        ", [$notification_id, $user_id]);
        
        // Redirect back to notifications page or referring page
        $redirect = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'notifications.php';
        header("Location: $redirect");
        exit();
    } catch (Exception $e) {
        $message = "Error marking notification as read: " . $e->getMessage();
        $messageType = "danger";
        error_log("Mark read error: " . $e->getMessage());
    }
}

// Mark all as read
if (isset($_GET['read_all'])) {
    try {
        executeQuery($conn, "
            UPDATE notifications SET is_read = 1 
            WHERE (user_id = ? OR is_global = 1) AND is_read = 0
        ", [$user_id]);
        
        $message = "All notifications marked as read";
        $messageType = "success";
    } catch (Exception $e) {
        $message = "Error marking all as read: " . $e->getMessage();
        $messageType = "danger";
        error_log("Mark all read error: " . $e->getMessage());
    }
}

// Delete notification
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $notification_id = $_GET['delete'];
    
    try {
        executeQuery($conn, "
            DELETE FROM notifications 
            WHERE id = ? AND (user_id = ? OR is_global = 1)
        ", [$notification_id, $user_id]);
        
        $message = "Notification deleted successfully";
        $messageType = "success";
    } catch (Exception $e) {
        $message = "Error deleting notification: " . $e->getMessage();
        $messageType = "danger";
        error_log("Delete error: " . $e->getMessage());
    }
}

// Delete all read notifications
if (isset($_GET['delete_read'])) {
    try {
        executeQuery($conn, "
            DELETE FROM notifications 
            WHERE (user_id = ? OR is_global = 1) AND is_read = 1
        ", [$user_id]);
        
        $message = "All read notifications deleted";
        $messageType = "success";
    } catch (Exception $e) {
        $message = "Error deleting read notifications: " . $e->getMessage();
        $messageType = "danger";
        error_log("Delete read error: " . $e->getMessage());
    }
}

// Create new notification (admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    // Check if user is admin
    if ($user_role !== 'admin') {
        $message = "Unauthorized: Only admins can create notifications";
        $messageType = "danger";
    } else {
        $type = $_POST['type'];
        $title = $_POST['title'];
        $message_text = $_POST['message'];
        $link = $_POST['link'] ?? null;
        $is_global = isset($_POST['is_global']) ? 1 : 0;
        $target_user_id = !$is_global && !empty($_POST['user_id']) ? $_POST['user_id'] : null;
        $expires_at = !empty($_POST['expires_at']) ? $_POST['expires_at'] : null;
        
        try {
            if ($is_global) {
                // Create global notification (for all users)
                executeQuery($conn, "
                    INSERT INTO notifications (
                        type, title, message, link, is_global, expires_at, created_at
                    ) VALUES (?, ?, ?, ?, 1, ?, NOW())
                ", [$type, $title, $message_text, $link, $expires_at]);
            } else {
                // Create notification for specific user
                executeQuery($conn, "
                    INSERT INTO notifications (
                        user_id, type, title, message, link, expires_at, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, NOW())
                ", [$target_user_id, $type, $title, $message_text, $link, $expires_at]);
            }
            
            $message = "Notification created successfully";
            $messageType = "success";
        } catch (Exception $e) {
            $message = "Error creating notification: " . $e->getMessage();
            $messageType = "danger";
            error_log("Create error: " . $e->getMessage());
        }
    }
}

// ============================================================================
// GENERATE SYSTEM NOTIFICATIONS
// ============================================================================

// Function to check and create system notifications
function generateSystemNotifications($conn) {
    $notifications_created = 0;
    
    try {
        // Check for expiring leases (30 days before expiry)
        $expiring_leases = fetchAll($conn, "
            SELECT t.id, t.title_number, t.expiry_date, 
                   p.parcel_number, u.id as user_id
            FROM titles t
            JOIN parcels p ON t.parcel_id = p.id
            LEFT JOIN users u ON u.role = 'admin'
            WHERE t.title_type = 'leasehold' 
                AND t.status = 'active'
                AND t.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                AND NOT EXISTS (
                    SELECT 1 FROM notifications n 
                    WHERE n.type = 'lease_expiring' 
                    AND n.created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)
                    AND n.message LIKE CONCAT('%', t.title_number, '%')
                )
        ");
        
        foreach ($expiring_leases as $lease) {
            $days_left = (strtotime($lease['expiry_date']) - time()) / (60 * 60 * 24);
            $days_left = round($days_left);
            
            // Create notification for admins
            executeQuery($conn, "
                INSERT INTO notifications (
                    type, title, message, link, is_global, created_at
                ) VALUES (
                    'lease_expiring',
                    'Lease Expiring Soon',
                    ?,
                    ?,
                    1,
                    NOW()
                )
            ", [
                "Title {$lease['title_number']} on parcel {$lease['parcel_number']} will expire in {$days_left} days.",
                "titles.php?id={$lease['id']}"
            ]);
            $notifications_created++;
        }
        
        // Check for overdue taxes
        $overdue_taxes = fetchAll($conn, "
            SELECT ta.id, ta.parcel_id, ta.tax_year, ta.tax_amount, ta.due_date,
                   p.parcel_number, o.party_id, u.id as user_id
            FROM tax_assessments ta
            JOIN parcels p ON ta.parcel_id = p.id
            LEFT JOIN ownerships o ON p.id = o.parcel_id AND o.is_current = 1
            LEFT JOIN users u ON o.party_id = u.party_id
            WHERE ta.status = 'overdue' 
                AND ta.due_date < CURDATE()
                AND NOT EXISTS (
                    SELECT 1 FROM notifications n 
                    WHERE n.type = 'tax_overdue' 
                    AND n.created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
                    AND n.message LIKE CONCAT('%', p.parcel_number, '%')
                )
        ");
        
        foreach ($overdue_taxes as $tax) {
            // Notify the owner if they have a user account
            if (!empty($tax['user_id'])) {
                executeQuery($conn, "
                    INSERT INTO notifications (
                        user_id, type, title, message, link, created_at
                    ) VALUES (
                        ?,
                        'tax_overdue',
                        'Property Tax Overdue',
                        ?,
                        ?,
                        NOW()
                    )
                ", [
                    $tax['user_id'],
                    "Tax assessment for parcel {$tax['parcel_number']} (Year {$tax['tax_year']}) of $" . number_format($tax['tax_amount'], 2) . " is overdue.",
                    "tax-assessments.php?parcel_id={$tax['parcel_id']}"
                ]);
                $notifications_created++;
            }
            
            // Also notify admins
            executeQuery($conn, "
                INSERT INTO notifications (
                    type, title, message, link, is_global, created_at
                ) VALUES (
                    'tax_overdue',
                    'Overdue Tax Alert',
                    ?,
                    ?,
                    1,
                    NOW()
                )
            ", [
                "Parcel {$tax['parcel_number']} has overdue tax of $" . number_format($tax['tax_amount'], 2) . " for year {$tax['tax_year']}.",
                "tax-assessments.php?parcel_id={$tax['parcel_id']}"
            ]);
            $notifications_created++;
        }
        
        // Check for pending applications
        $pending_apps = fetchAll($conn, "
            SELECT a.id, a.application_type, a.created_at, 
                   p.parcel_number, a.applicant_party_id
            FROM applications a
            JOIN parcels p ON a.parcel_id = p.id
            WHERE a.status = 'submitted'
                AND a.created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
                AND NOT EXISTS (
                    SELECT 1 FROM notifications n 
                    WHERE n.type = 'pending_application' 
                    AND n.created_at > DATE_SUB(NOW(), INTERVAL 3 DAY)
                    AND n.message LIKE CONCAT('%', a.id, '%')
                )
        ");
        
        foreach ($pending_apps as $app) {
            executeQuery($conn, "
                INSERT INTO notifications (
                    type, title, message, link, is_global, created_at
                ) VALUES (
                    'pending_application',
                    'Pending Application Alert',
                    ?,
                    ?,
                    1,
                    NOW()
                )
            ", [
                "Application #{$app['id']} for parcel {$app['parcel_number']} has been pending for over 7 days.",
                "applications.php?id={$app['id']}"
            ]);
            $notifications_created++;
        }
        
        // Check for open disputes
        $open_disputes = fetchAll($conn, "
            SELECT d.id, d.dispute_type, d.filing_date, 
                   p.parcel_number
            FROM disputes d
            JOIN parcels p ON d.parcel_id = p.id
            WHERE d.status = 'open'
                AND d.filing_date < DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                AND NOT EXISTS (
                    SELECT 1 FROM notifications n 
                    WHERE n.type = 'open_dispute' 
                    AND n.created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
                    AND n.message LIKE CONCAT('%', d.id, '%')
                )
        ");
        
        foreach ($open_disputes as $dispute) {
            executeQuery($conn, "
                INSERT INTO notifications (
                    type, title, message, link, is_global, created_at
                ) VALUES (
                    'open_dispute',
                    'Long-standing Dispute',
                    ?,
                    ?,
                    1,
                    NOW()
                )
            ", [
                "Dispute #{$dispute['id']} ({$dispute['dispute_type']}) on parcel {$dispute['parcel_number']} has been open for over 30 days.",
                "disputes.php?id={$dispute['id']}"
            ]);
            $notifications_created++;
        }
        
        // Check for recent document uploads (notify admins)
        $recent_docs = fetchAll($conn, "
            SELECT d.id, d.document_type, d.uploaded_at, d.uploaded_by,
                   u.username, p.parcel_number
            FROM documents d
            LEFT JOIN parcels p ON d.parcel_id = p.id
            JOIN users u ON d.uploaded_by = u.id
            WHERE d.uploaded_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                AND NOT EXISTS (
                    SELECT 1 FROM notifications n 
                    WHERE n.type = 'new_document' 
                    AND n.created_at > DATE_SUB(NOW(), INTERVAL 12 HOUR)
                    AND n.message LIKE CONCAT('%', d.id, '%')
                )
        ");
        
        foreach ($recent_docs as $doc) {
            executeQuery($conn, "
                INSERT INTO notifications (
                    type, title, message, link, is_global, created_at
                ) VALUES (
                    'new_document',
                    'New Document Uploaded',
                    ?,
                    ?,
                    1,
                    NOW()
                )
            ", [
                "User {$doc['username']} uploaded a new {$doc['document_type']} document" . 
                ($doc['parcel_number'] ? " for parcel {$doc['parcel_number']}" : ""),
                "documents.php?id={$doc['id']}"
            ]);
            $notifications_created++;
        }
        
        // Check for new user registrations (notify admins)
        $new_users = fetchAll($conn, "
            SELECT id, username, role, created_at
            FROM users
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                AND NOT EXISTS (
                    SELECT 1 FROM notifications n 
                    WHERE n.type = 'new_user' 
                    AND n.created_at > DATE_SUB(NOW(), INTERVAL 12 HOUR)
                    AND n.message LIKE CONCAT('%', id, '%')
                )
        ");
        
        foreach ($new_users as $user) {
            executeQuery($conn, "
                INSERT INTO notifications (
                    type, title, message, link, is_global, created_at
                ) VALUES (
                    'new_user',
                    'New User Registration',
                    ?,
                    ?,
                    1,
                    NOW()
                )
            ", [
                "New user '{$user['username']}' registered as {$user['role']}.",
                "users.php?id={$user['id']}"
            ]);
            $notifications_created++;
        }
        
        // Clean up expired notifications
        executeQuery($conn, "
            DELETE FROM notifications 
            WHERE expires_at IS NOT NULL AND expires_at < CURDATE()
        ");
        
    } catch (Exception $e) {
        error_log("Error generating notifications: " . $e->getMessage());
    }
    
    return $notifications_created;
}

// Generate notifications on page load (once per session)
if (!isset($_SESSION['notifications_generated']) || $_SESSION['notifications_generated'] < time() - 3600) {
    $new_count = generateSystemNotifications($conn);
    $_SESSION['notifications_generated'] = time();
    if ($new_count > 0) {
        error_log("Generated $new_count new notifications");
    }
}

// ============================================================================
// GET NOTIFICATIONS
// ============================================================================

$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';

// Build filter conditions
$where_conditions = ["(n.user_id = ? OR n.is_global = 1)"];
$params = [$user_id];

if ($filter == 'unread') {
    $where_conditions[] = "n.is_read = 0";
} elseif ($filter == 'read') {
    $where_conditions[] = "n.is_read = 1";
}

// Type filter
if (!empty($_GET['type'])) {
    $where_conditions[] = "n.type = ?";
    $params[] = $_GET['type'];
}

$where_clause = "WHERE " . implode(" AND ", $where_conditions);

// Get notifications
$sql = "
    SELECT 
        n.*,
        CASE 
            WHEN n.user_id IS NULL THEN 'All Users'
            ELSE 'Personal'
        END as scope
    FROM notifications n
    $where_clause
    ORDER BY n.created_at DESC
";

$result = executeQuery($conn, $sql, $params);
$notifications = [];
if ($result) {
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        $notifications[] = $row;
    }
}

// Get unread count for badge
$unread_count = fetchOne($conn, "
    SELECT COUNT(*) as count FROM notifications 
    WHERE (user_id = ? OR is_global = 1) AND is_read = 0
", [$user_id]);
$unread_count = $unread_count['count'] ?? 0;

// Get notification types for filter
$notification_types = fetchAll($conn, "
    SELECT DISTINCT type FROM notifications ORDER BY type
");

// ============================================================================
// NOTIFICATION ICON MAPPING
// ============================================================================

$type_icons = [
    'lease_expiring' => 'bi-calendar-exclamation text-warning',
    'tax_overdue' => 'bi-exclamation-triangle text-danger',
    'pending_application' => 'bi-clock-history text-info',
    'open_dispute' => 'bi-gavel text-danger',
    'new_document' => 'bi-file-text text-success',
    'new_user' => 'bi-person-plus text-primary',
    'system' => 'bi-gear text-secondary',
    'reminder' => 'bi-bell text-info',
    'alert' => 'bi-exclamation-circle text-danger',
    'info' => 'bi-info-circle text-primary',
    'success' => 'bi-check-circle text-success',
    'warning' => 'bi-exclamation-diamond text-warning'
];

$type_labels = [
    'lease_expiring' => 'Lease Expiring',
    'tax_overdue' => 'Tax Overdue',
    'pending_application' => 'Pending Application',
    'open_dispute' => 'Open Dispute',
    'new_document' => 'New Document',
    'new_user' => 'New User',
    'system' => 'System',
    'reminder' => 'Reminder',
    'alert' => 'Alert',
    'info' => 'Information',
    'success' => 'Success',
    'warning' => 'Warning'
];

// Format time function
function timeAgo($timestamp) {
    $time_ago = strtotime($timestamp);
    $current_time = time();
    $time_difference = $current_time - $time_ago;
    $seconds = $time_difference;
    
    $minutes = round($seconds / 60);
    $hours = round($seconds / 3600);
    $days = round($seconds / 86400);
    $weeks = round($seconds / 604800);
    $months = round($seconds / 2629440);
    $years = round($seconds / 31553280);
    
    if ($seconds <= 60) {
        return "Just now";
    } else if ($minutes <= 60) {
        return ($minutes == 1) ? "1 minute ago" : "$minutes minutes ago";
    } else if ($hours <= 24) {
        return ($hours == 1) ? "1 hour ago" : "$hours hours ago";
    } else if ($days <= 7) {
        return ($days == 1) ? "yesterday" : "$days days ago";
    } else if ($weeks <= 4.3) {
        return ($weeks == 1) ? "1 week ago" : "$weeks weeks ago";
    } else if ($months <= 12) {
        return ($months == 1) ? "1 month ago" : "$months months ago";
    } else {
        return ($years == 1) ? "1 year ago" : "$years years ago";
    }
}
?>

<body data-page="notifications" class="notifications-page">
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
                            <h1 class="h3 mb-0">
                                <i class="bi bi-bell me-2"></i>
                                Notifications
                            </h1>
                            <p class="text-muted mb-0">Stay updated with system alerts and reminders</p>
                        </div>
                        <div class="d-flex gap-2">
                            <?php if ($unread_count > 0): ?>
                            <a href="?read_all=1" class="btn btn-outline-primary" onclick="return confirm('Mark all as read?')">
                                <i class="bi bi-check-all me-2"></i>Mark All Read
                            </a>
                            <?php endif; ?>
                            <a href="?delete_read=1" class="btn btn-outline-danger" onclick="return confirm('Delete all read notifications?')">
                                <i class="bi bi-trash me-2"></i>Clear Read
                            </a>
                            <?php if ($user_role === 'admin'): ?>
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createNotificationModal">
                                <i class="bi bi-plus-circle me-2"></i>New Notification
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Alert Message -->
                    <?php if ($message): ?>
                    <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show mb-4" role="alert">
                        <?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    <?php endif; ?>

                    <!-- Notification Stats -->
                    <div class="row g-4 mb-4">
                        <div class="col-md-3">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0 me-3">
                                            <div class="bg-primary bg-opacity-10 rounded-circle p-3">
                                                <i class="bi bi-bell text-primary fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Total</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo count($notifications); ?></h3>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-3">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0 me-3">
                                            <div class="bg-warning bg-opacity-10 rounded-circle p-3">
                                                <i class="bi bi-envelope text-warning fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Unread</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo $unread_count; ?></h3>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-3">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0 me-3">
                                            <div class="bg-success bg-opacity-10 rounded-circle p-3">
                                                <i class="bi bi-check-circle text-success fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Read</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo count($notifications) - $unread_count; ?></h3>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-3">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0 me-3">
                                            <div class="bg-info bg-opacity-10 rounded-circle p-3">
                                                <i class="bi bi-globe text-info fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Global</h6>
                                            <h3 class="mb-0 fw-bold">
                                                <?php 
                                                $global_count = array_filter($notifications, function($n) {
                                                    return $n['is_global'] == 1;
                                                });
                                                echo count($global_count);
                                                ?>
                                            </h3>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Filter Tabs -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-body">
                            <ul class="nav nav-pills">
                                <li class="nav-item">
                                    <a class="nav-link <?php echo $filter == 'all' ? 'active' : ''; ?>" 
                                       href="?filter=all">
                                        All
                                        <span class="badge bg-secondary ms-2"><?php echo count($notifications); ?></span>
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link <?php echo $filter == 'unread' ? 'active' : ''; ?>" 
                                       href="?filter=unread">
                                        Unread
                                        <span class="badge bg-warning ms-2"><?php echo $unread_count; ?></span>
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link <?php echo $filter == 'read' ? 'active' : ''; ?>" 
                                       href="?filter=read">
                                        Read
                                        <span class="badge bg-secondary ms-2"><?php echo count($notifications) - $unread_count; ?></span>
                                    </a>
                                </li>
                            </ul>
                            
                            <?php if (!empty($notification_types)): ?>
                            <hr>
                            <div class="d-flex flex-wrap gap-2 mt-3">
                                <span class="text-muted me-2">Filter by type:</span>
                                <a href="?filter=<?php echo $filter; ?>" class="badge bg-secondary text-decoration-none p-2">All</a>
                                <?php foreach ($notification_types as $nt): ?>
                                <a href="?filter=<?php echo $filter; ?>&type=<?php echo $nt['type']; ?>" 
                                   class="badge bg-info text-decoration-none p-2 <?php echo ($_GET['type'] ?? '') == $nt['type'] ? 'active' : ''; ?>">
                                    <?php echo $type_labels[$nt['type']] ?? ucfirst($nt['type']); ?>
                                </a>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Notifications List -->
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0 fw-bold">
                                <i class="bi bi-list-ul me-2"></i>
                                Notification History
                            </h5>
                        </div>
                        
                        <div class="card-body p-0">
                            <?php if (empty($notifications)): ?>
                                <div class="text-center py-5">
                                    <i class="bi bi-bell-slash fs-1 text-muted d-block mb-3"></i>
                                    <h5 class="text-muted">No notifications</h5>
                                    <p class="text-muted">You're all caught up!</p>
                                </div>
                            <?php else: ?>
                                <div class="list-group list-group-flush">
                                    <?php foreach ($notifications as $notification): ?>
                                    <div class="list-group-item <?php echo $notification['is_read'] ? '' : 'list-group-item-light'; ?>">
                                        <div class="d-flex">
                                            <div class="flex-shrink-0 me-3">
                                                <div class="notification-icon">
                                                    <i class="bi <?php echo $type_icons[$notification['type']] ?? 'bi-bell text-secondary'; ?> fs-3"></i>
                                                </div>
                                            </div>
                                            <div class="flex-grow-1">
                                                <div class="d-flex justify-content-between align-items-start">
                                                    <div>
                                                        <h6 class="mb-1">
                                                            <?php echo htmlspecialchars($notification['title']); ?>
                                                            <?php if (!$notification['is_read']): ?>
                                                                <span class="badge bg-warning ms-2">New</span>
                                                            <?php endif; ?>
                                                            <?php if ($notification['is_global']): ?>
                                                                <span class="badge bg-info ms-2">Global</span>
                                                            <?php endif; ?>
                                                        </h6>
                                                        <p class="mb-1"><?php echo nl2br(htmlspecialchars($notification['message'])); ?></p>
                                                        <small class="text-muted">
                                                            <i class="bi bi-clock me-1"></i>
                                                            <?php echo timeAgo($notification['created_at']); ?>
                                                            <?php if ($notification['expires_at']): ?>
                                                                • <i class="bi bi-calendar-x me-1"></i>
                                                                Expires: <?php echo date('d M Y', strtotime($notification['expires_at'])); ?>
                                                            <?php endif; ?>
                                                        </small>
                                                    </div>
                                                    <div class="btn-group btn-group-sm">
                                                        <?php if (!$notification['is_read']): ?>
                                                        <a href="?read=<?php echo $notification['id']; ?>" 
                                                           class="btn btn-outline-success" title="Mark as read">
                                                            <i class="bi bi-check"></i>
                                                        </a>
                                                        <?php endif; ?>
                                                        <?php if ($notification['link']): ?>
                                                        <a href="<?php echo htmlspecialchars($notification['link']); ?>" 
                                                           class="btn btn-outline-primary" title="View details">
                                                            <i class="bi bi-box-arrow-up-right"></i>
                                                        </a>
                                                        <?php endif; ?>
                                                        <a href="?delete=<?php echo $notification['id']; ?>&filter=<?php echo $filter; ?>" 
                                                           class="btn btn-outline-danger" title="Delete"
                                                           onclick="return confirm('Delete this notification?')">
                                                            <i class="bi bi-trash"></i>
                                                        </a>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <?php if (count($notifications) > 20): ?>
                        <div class="card-footer bg-white py-3 text-center">
                            <button class="btn btn-outline-primary btn-sm" id="loadMore">
                                <i class="bi bi-arrow-down me-2"></i>Load More
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Notification Settings Info -->
                    <div class="row mt-4">
                        <div class="col-md-12">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h5 class="card-title mb-0 fw-bold">
                                        <i class="bi bi-gear me-2"></i>
                                        About Notifications
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <h6>Notification Types:</h6>
                                            <ul class="list-unstyled">
                                                <li><i class="bi bi-calendar-exclamation text-warning me-2"></i> <strong>Lease Expiring:</strong> Alerts when leases are about to expire</li>
                                                <li><i class="bi bi-exclamation-triangle text-danger me-2"></i> <strong>Tax Overdue:</strong> Notifications for overdue property taxes</li>
                                                <li><i class="bi bi-clock-history text-info me-2"></i> <strong>Pending Applications:</strong> Applications waiting for review</li>
                                                <li><i class="bi bi-gavel text-danger me-2"></i> <strong>Open Disputes:</strong> Long-standing disputes requiring attention</li>
                                                <li><i class="bi bi-file-text text-success me-2"></i> <strong>New Documents:</strong> Recently uploaded documents</li>
                                                <li><i class="bi bi-person-plus text-primary me-2"></i> <strong>New Users:</strong> New user registrations</li>
                                            </ul>
                                        </div>
                                        <div class="col-md-6">
                                            <h6>Notification Scope:</h6>
                                            <ul class="list-unstyled">
                                                <li><i class="bi bi-person text-primary me-2"></i> <strong>Personal:</strong> Notifications specific to you</li>
                                                <li><i class="bi bi-globe text-info me-2"></i> <strong>Global:</strong> System-wide announcements</li>
                                            </ul>
                                            <h6 class="mt-3">Actions:</h6>
                                            <ul class="list-unstyled">
                                                <li><i class="bi bi-check text-success me-2"></i> Mark as read</li>
                                                <li><i class="bi bi-trash text-danger me-2"></i> Delete notification</li>
                                                <li><i class="bi bi-check-all text-primary me-2"></i> Mark all as read</li>
                                                <li><i class="bi bi-trash2 text-danger me-2"></i> Clear read notifications</li>
                                            </ul>
                                        </div>
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

    <?php if ($user_role === 'admin'): ?>
    <!-- Create Notification Modal -->
    <div class="modal fade" id="createNotificationModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="create">
                    <div class="modal-header">
                        <h5 class="modal-title">Create New Notification</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Notification Type</label>
                                <select class="form-select" name="type" required>
                                    <option value="info">Information</option>
                                    <option value="success">Success</option>
                                    <option value="warning">Warning</option>
                                    <option value="alert">Alert</option>
                                    <option value="reminder">Reminder</option>
                                    <option value="system">System</option>
                                </select>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Scope</label>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="is_global" id="isGlobal" value="1">
                                    <label class="form-check-label" for="isGlobal">
                                        Global (all users)
                                    </label>
                                </div>
                            </div>
                            
                            <div class="col-md-12 mb-3" id="userSelectField" style="display: none;">
                                <label class="form-label">Select User</label>
                                <select class="form-select" name="user_id">
                                    <option value="">-- Select user --</option>
                                    <?php
                                    $users = fetchAll($conn, "SELECT id, username, role FROM users ORDER BY username");
                                    foreach ($users as $u): ?>
                                    <option value="<?php echo $u['id']; ?>">
                                        <?php echo htmlspecialchars($u['username']); ?> (<?php echo ucfirst($u['role']); ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Title</label>
                                <input type="text" class="form-control" name="title" maxlength="255" required>
                            </div>
                            
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Message</label>
                                <textarea class="form-control" name="message" rows="4" required></textarea>
                            </div>
                            
                            <div class="col-md-8 mb-3">
                                <label class="form-label">Link (optional)</label>
                                <input type="text" class="form-control" name="link" placeholder="e.g., parcels.php?id=123">
                            </div>
                            
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Expires At (optional)</label>
                                <input type="date" class="form-control" name="expires_at">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create Notification</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        // Toggle user selection based on global checkbox
        document.getElementById('isGlobal')?.addEventListener('change', function() {
            const userField = document.getElementById('userSelectField');
            if (this.checked) {
                userField.style.display = 'none';
            } else {
                userField.style.display = 'block';
            }
        });
    </script>
    <?php endif; ?>

    <script>
        // Load more notifications (simple implementation)
        document.getElementById('loadMore')?.addEventListener('click', function() {
            alert('Load more functionality would be implemented with AJAX');
        });
        
        // Auto-refresh notifications every 60 seconds
        setTimeout(function() {
            location.reload();
        }, 60000); // 60 seconds
    </script>

    <style>
        .notification-icon {
            width: 50px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f8f9fa;
            border-radius: 50%;
        }
        
        .list-group-item {
            transition: background-color 0.2s;
            padding: 1.25rem;
        }
        
        .list-group-item:hover {
            background-color: #f8f9fa;
        }
        
        .list-group-item-light {
            background-color: #f0f7ff;
            border-left: 4px solid #0d6efd;
        }
        
        .badge.active {
            background-color: #0d6efd !important;
            color: white;
        }
        
        .nav-pills .nav-link.active {
            background-color: #0d6efd;
        }
        
        .nav-pills .nav-link .badge {
            background-color: rgba(255,255,255,0.2);
        }
        
        .btn-group .btn {
            padding: 0.25rem 0.5rem;
        }
        
        @media (max-width: 768px) {
            .notification-icon {
                width: 40px;
                height: 40px;
            }
            
            .notification-icon i {
                font-size: 1.5rem;
            }
            
            .list-group-item {
                padding: 1rem;
            }
        }
    </style>

</body>
</html>