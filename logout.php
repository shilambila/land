<?php
// logout.php - User Logout with Confirmation
session_start();

// ============================================================================
// CHECK IF USER IS LOGGED IN
// ============================================================================

if (!isset($_SESSION['user_id'])) {
    // If not logged in, redirect to login
    header("Location: login.php");
    exit();
}

// ============================================================================
// HANDLE LOGOUT CONFIRMATION
// ============================================================================

$message = '';
$messageType = '';

if (isset($_GET['confirm']) && $_GET['confirm'] === 'yes') {
    // User confirmed logout
    
    // Optional: Log logout action
    if (isset($_SESSION['user_id'])) {
        try {
            // You can include db_connection.php here if you want to log logout
            // require_once 'db_connection.php';
            // $user_id = $_SESSION['user_id'];
            // Log the logout action in audit_logs
            // ... logging code ...
        } catch (Exception $e) {
            // Silently ignore
        }
    }
    
    // Clear all session data
    $_SESSION = array();
    
    // Delete session cookie
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    // Destroy session
    session_destroy();
    
    // Redirect to login
    header("Location: login.php?logout=success");
    exit();
    
} elseif (isset($_GET['confirm']) && $_GET['confirm'] === 'no') {
    // User cancelled logout - redirect back to previous page or dashboard
    $redirect = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'dashboard.php';
    header("Location: $redirect");
    exit();
}

// ============================================================================
// DISPLAY LOGOUT CONFIRMATION PAGE
// ============================================================================

// Get user info for display
$username = $_SESSION['username'] ?? 'User';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logout - Land Management System</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .logout-container {
            max-width: 450px;
            width: 90%;
        }
        
        .logout-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
            overflow: hidden;
            animation: slideIn 0.5s ease-out;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .logout-header {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .logout-header i {
            font-size: 60px;
            margin-bottom: 15px;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }
        
        .logout-header h2 {
            margin: 0;
            font-size: 28px;
            font-weight: 600;
        }
        
        .logout-body {
            padding: 40px 30px;
            text-align: center;
        }
        
        .logout-body p {
            color: #666;
            font-size: 16px;
            margin-bottom: 30px;
            line-height: 1.6;
        }
        
        .user-info {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 30px;
            border-left: 4px solid #f5576c;
        }
        
        .user-info i {
            color: #f5576c;
            margin-right: 10px;
            font-size: 20px;
        }
        
        .user-info span {
            font-weight: 600;
            color: #333;
        }
        
        .btn-logout {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            border: none;
            color: white;
            padding: 12px 30px;
            font-size: 16px;
            font-weight: 600;
            border-radius: 50px;
            width: 100%;
            margin-bottom: 10px;
            transition: transform 0.3s, box-shadow 0.3s;
        }
        
        .btn-logout:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(245, 87, 108, 0.3);
            color: white;
        }
        
        .btn-cancel {
            background: transparent;
            border: 2px solid #ddd;
            color: #666;
            padding: 12px 30px;
            font-size: 16px;
            font-weight: 600;
            border-radius: 50px;
            width: 100%;
            transition: all 0.3s;
        }
        
        .btn-cancel:hover {
            background: #f8f9fa;
            border-color: #999;
            color: #333;
        }
        
        .logout-footer {
            background: #f8f9fa;
            padding: 20px;
            text-align: center;
            border-top: 1px solid #eee;
        }
        
        .logout-footer small {
            color: #999;
            font-size: 13px;
        }
        
        .session-info {
            font-size: 13px;
            color: #999;
            margin-top: 15px;
        }
        
        .session-info i {
            margin-right: 5px;
        }
    </style>
</head>
<body>
    <div class="logout-container">
        <div class="logout-card">
            <div class="logout-header">
                <i class="bi bi-box-arrow-right"></i>
                <h2>Logout</h2>
            </div>
            
            <div class="logout-body">
                <div class="user-info">
                    <i class="bi bi-person-circle"></i>
                    <span><?php echo htmlspecialchars($username); ?></span>
                </div>
                
                <p>
                    Are you sure you want to logout from the Land Management System?<br>
                    Any unsaved changes will be lost.
                </p>
                
                <a href="?confirm=yes" class="btn btn-logout">
                    <i class="bi bi-box-arrow-right me-2"></i>Yes, Logout
                </a>
                
                <a href="?confirm=no" class="btn btn-cancel">
                    <i class="bi bi-x-circle me-2"></i>Cancel, Stay Logged In
                </a>
                
                <div class="session-info">
                    <i class="bi bi-clock"></i>
                    Session started: <?php echo date('H:i:s', $_SESSION['login_time'] ?? time()); ?>
                </div>
            </div>
            
            <div class="logout-footer">
                <small>
                    <i class="bi bi-shield-lock"></i>
                    You will be redirected to the login page after logout
                </small>
            </div>
        </div>
    </div>
    
    <!-- Optional: Auto logout after inactivity -->
    <script>
        // Auto-redirect after 60 seconds of inactivity on this page
        let timeout;
        resetTimer();
        
        function resetTimer() {
            clearTimeout(timeout);
            timeout = setTimeout(() => {
                window.location.href = '?confirm=yes';
            }, 60000); // 60 seconds
        }
        
        document.addEventListener('mousemove', resetTimer);
        document.addEventListener('keypress', resetTimer);
        document.addEventListener('click', resetTimer);
        document.addEventListener('scroll', resetTimer);
    </script>
</body>
</html>
<?php
exit();
?>