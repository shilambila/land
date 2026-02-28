<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

// login.php
require_once 'db_connection.php';

// ============================================================================
// CHECK IF ALREADY LOGGED IN
// ============================================================================

if (isset($_SESSION['user_id'])) {
    // Redirect to dashboard based on role
    switch ($_SESSION['user_role']) {
        case 'admin':
            header('Location: dashboard.php');
            break;
        case 'surveyor':
            header('Location: surveyor-dashboard.php');
            break;
        case 'clerk':
            header('Location: clerk-dashboard.php');
            break;
        case 'public_officer':
            header('Location: officer-dashboard.php');
            break;
        case 'public':
            header('Location: public-dashboard.php');
            break;
        default:
            header('Location: dashboard.php');
    }
    exit();
}

// ============================================================================
// LOGIN PROCESSING
// ============================================================================

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    if (isset($_POST['action']) && $_POST['action'] === 'login') {
        $username = trim($_POST['username']);
        $password = $_POST['password'];
        $remember = isset($_POST['remember']) ? true : false;
        
        // Validate input
        if (empty($username) || empty($password)) {
            $error = 'Please enter both username and password';
        } else {
            try {
                // Get user from database
                $user = fetchOne($conn, "
                    SELECT u.*, p.name as party_name, p.phone, p.email as party_email
                    FROM users u
                    LEFT JOIN parties p ON u.party_id = p.id
                    WHERE u.username = ? AND u.is_active = 1
                ", [$username]);
                
                if ($user) {
                    // For demo purposes - in production, use password_verify()
                    // Since your DB has placeholder hashes, we'll use a simple check for demo
                    // In production, you should have real password hashes
                    
                    // DEMO MODE: Simple password check (REMOVE IN PRODUCTION)
                    $valid_password = false;
                    
                    // For admin users, password is 'admin123'
                    if ($user['role'] === 'admin' && $password === 'admin123') {
                        $valid_password = true;
                    }
                    // For surveyor, password is 'surveyor123'
                    else if ($user['role'] === 'surveyor' && $password === 'surveyor123') {
                        $valid_password = true;
                    }
                    // For clerk, password is 'clerk123'
                    else if ($user['role'] === 'clerk' && $password === 'clerk123') {
                        $valid_password = true;
                    }
                    // For public officer, password is 'officer123'
                    else if ($user['role'] === 'public_officer' && $password === 'officer123') {
                        $valid_password = true;
                    }
                    // For public users, password is 'public123'
                    else if ($user['role'] === 'public' && $password === 'public123') {
                        $valid_password = true;
                    }
                    
                    if ($valid_password) {
                        // Update last login
                        executeQuery($conn, "UPDATE users SET last_login = NOW() WHERE id = ?", [$user['id']]);
                        
                        // Set session variables
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['username'] = $user['username'];
                        $_SESSION['user_role'] = $user['role'];
                        $_SESSION['user_email'] = $user['email'] ?? $user['party_email'];
                        $_SESSION['user_name'] = $user['party_name'] ?? $user['username'];
                        $_SESSION['login_time'] = time();
                        
                        // Set remember me cookie if requested
                        if ($remember) {
                            $token = bin2hex(random_bytes(32));
                            $expiry = time() + (30 * 24 * 60 * 60); // 30 days
                            
                            // Store token in database (you'd need a remember_tokens table)
                            // For now, we'll set a cookie
                            setcookie('remember_token', $token, $expiry, '/', '', true, true);
                        }
                        
                        // Redirect based on role
                        switch ($user['role']) {
                            case 'admin':
                                header('Location: dashboard.php');
                                break;
                            case 'surveyor':
                                header('Location: surveyor-dashboard.php');
                                break;
                            case 'clerk':
                                header('Location: clerk-dashboard.php');
                                break;
                            case 'public_officer':
                                header('Location: officer-dashboard.php');
                                break;
                            case 'public':
                                header('Location: public-dashboard.php');
                                break;
                            default:
                                header('Location: dashboard.php');
                        }
                        exit();
                    } else {
                        $error = 'Invalid username or password';
                        // Log failed attempt
                        error_log("Failed login attempt for username: $username");
                    }
                } else {
                    $error = 'Invalid username or password';
                }
            } catch (Exception $e) {
                $error = 'Database error occurred. Please try again.';
                error_log("Login error: " . $e->getMessage());
            }
        }
    }
    
    // Handle registration (for public users)
    if (isset($_POST['action']) && $_POST['action'] === 'register') {
        $name = trim($_POST['name']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone']);
        $username = trim($_POST['username']);
        $password = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];
        $national_id = trim($_POST['national_id'] ?? '');
        
        // Validate input
        $errors = [];
        
        if (empty($name)) {
            $errors[] = 'Full name is required';
        }
        
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Valid email is required';
        }
        
        if (empty($phone)) {
            $errors[] = 'Phone number is required';
        }
        
        if (empty($username) || strlen($username) < 4) {
            $errors[] = 'Username must be at least 4 characters';
        }
        
        if (empty($password) || strlen($password) < 6) {
            $errors[] = 'Password must be at least 6 characters';
        }
        
        if ($password !== $confirm_password) {
            $errors[] = 'Passwords do not match';
        }
        
        if (empty($errors)) {
            try {
                // Check if username already exists
                $existing = fetchOne($conn, "SELECT id FROM users WHERE username = ?", [$username]);
                if ($existing) {
                    $errors[] = 'Username already taken';
                } else {
                    // Check if email already exists
                    $existing = fetchOne($conn, "SELECT id FROM parties WHERE email = ?", [$email]);
                    if ($existing) {
                        $errors[] = 'Email already registered';
                    } else {
                        // Start transaction
                        $conn->beginTransaction();
                        
                        // Create party record
                        executeQuery($conn, "
                            INSERT INTO parties (party_type, name, national_id, phone, email, created_at)
                            VALUES ('individual', ?, ?, ?, ?, NOW())
                        ", [$name, $national_id ?: null, $phone, $email]);
                        
                        $party_id = $conn->lastInsertId();
                        
                        // Create user account
                        $password_hash = password_hash($password, PASSWORD_DEFAULT);
                        executeQuery($conn, "
                            INSERT INTO users (username, password_hash, party_id, email, role, is_active, created_at)
                            VALUES (?, ?, ?, ?, 'public', 1, NOW())
                        ", [$username, $password_hash, $party_id, $email]);
                        
                        $conn->commit();
                        
                        $success = 'Registration successful! You can now login.';
                        
                        // Auto-fill login form
                        echo "<script>
                            setTimeout(function() {
                                document.getElementById('loginUsername').value = '$username';
                                document.getElementById('loginPassword').value = '';
                            }, 1000);
                        </script>";
                    }
                }
            } catch (Exception $e) {
                $conn->rollBack();
                $errors[] = 'Registration failed. Please try again.';
                error_log("Registration error: " . $e->getMessage());
            }
        }
        
        if (!empty($errors)) {
            $error = implode('<br>', $errors);
        }
    }
    
    // Handle password reset request
    if (isset($_POST['action']) && $_POST['action'] === 'reset_request') {
        $email = trim($_POST['reset_email']);
        
        if (empty($email)) {
            $error = 'Please enter your email address';
        } else {
            try {
                // Check if email exists
                $user = fetchOne($conn, "
                    SELECT u.*, p.name 
                    FROM users u
                    LEFT JOIN parties p ON u.party_id = p.id
                    WHERE u.email = ? OR p.email = ?
                ", [$email, $email]);
                
                if ($user) {
                    // Generate reset token
                    $token = bin2hex(random_bytes(32));
                    $expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));
                    
                    // Store token (you'd need a password_resets table)
                    // For now, we'll just show a message
                    
                    // In production, send email with reset link
                    $reset_link = "http://{$_SERVER['HTTP_HOST']}/aland/portal/reset-password.php?token=$token";
                    
                    // For demo, show the link
                    $success = "Password reset link (demo): <br><a href='$reset_link'>$reset_link</a>";
                    
                    // Log the reset request
                    error_log("Password reset requested for: $email");
                } else {
                    // Don't reveal if email exists or not
                    $success = 'If your email is registered, you will receive a password reset link.';
                }
            } catch (Exception $e) {
                $error = 'Error processing request. Please try again.';
                error_log("Password reset error: " . $e->getMessage());
            }
        }
    }
}

// ============================================================================
// GET SYSTEM SETTINGS FOR LOGIN PAGE
// ============================================================================

$site_name = 'Land Management System';
$company_name = 'Ministry of Land';
$login_background = '';
$primary_color = '#0d6efd';

try {
    $settings = fetchAll($conn, "SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('site_name', 'company_name', 'login_background', 'primary_color')");
    foreach ($settings as $setting) {
        switch ($setting['setting_key']) {
            case 'site_name': $site_name = $setting['setting_value']; break;
            case 'company_name': $company_name = $setting['company_name'] ?? $setting['setting_value']; break;
            case 'login_background': $login_background = $setting['setting_value']; break;
            case 'primary_color': $primary_color = $setting['setting_value']; break;
        }
    }
} catch (Exception $e) {
    // Use defaults
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?php echo htmlspecialchars($site_name); ?></title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --primary-color: <?php echo $primary_color; ?>;
            --primary-dark: <?php echo adjustBrightness($primary_color, -20); ?>;
            --primary-light: <?php echo adjustBrightness($primary_color, 20); ?>;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow-x: hidden;
        }
        
        <?php if ($login_background && file_exists('../' . $login_background)): ?>
        body {
            background: url('../<?php echo htmlspecialchars($login_background); ?>') no-repeat center center fixed;
            background-size: cover;
        }
        body::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 0;
        }
        <?php endif; ?>
        
        .login-container {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 1200px;
            padding: 20px;
        }
        
        .login-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
            animation: slideUp 0.5s ease-out;
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .login-left {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
            padding: 60px 40px;
            color: white;
            position: relative;
            overflow: hidden;
        }
        
        .login-left::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: rotate 20s linear infinite;
        }
        
        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        .login-left h2 {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 20px;
            position: relative;
        }
        
        .login-left p {
            font-size: 1.1rem;
            opacity: 0.9;
            margin-bottom: 30px;
            position: relative;
        }
        
        .feature-list {
            list-style: none;
            padding: 0;
            position: relative;
        }
        
        .feature-list li {
            margin-bottom: 15px;
            display: flex;
            align-items: center;
        }
        
        .feature-list i {
            margin-right: 10px;
            font-size: 1.2rem;
            opacity: 0.9;
        }
        
        .login-right {
            padding: 60px 40px;
            background: white;
        }
        
        .login-right h3 {
            font-size: 2rem;
            font-weight: 700;
            color: #333;
            margin-bottom: 10px;
        }
        
        .login-right .subtitle {
            color: #666;
            margin-bottom: 30px;
        }
        
        .nav-tabs {
            border: none;
            margin-bottom: 30px;
        }
        
        .nav-tabs .nav-link {
            border: none;
            color: #666;
            font-weight: 500;
            padding: 10px 20px;
            border-radius: 10px;
            transition: all 0.3s;
        }
        
        .nav-tabs .nav-link:hover {
            background: rgba(102, 126, 234, 0.1);
            color: var(--primary-color);
        }
        
        .nav-tabs .nav-link.active {
            background: var(--primary-color);
            color: white;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            font-weight: 500;
            color: #555;
            margin-bottom: 8px;
        }
        
        .input-group {
            border-radius: 10px;
            overflow: hidden;
            border: 1px solid #e0e0e0;
            transition: all 0.3s;
        }
        
        .input-group:focus-within {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .input-group-text {
            background: #f8f9fa;
            border: none;
            color: #666;
        }
        
        .form-control {
            border: none;
            padding: 12px;
            font-size: 1rem;
        }
        
        .form-control:focus {
            box-shadow: none;
        }
        
        .btn-login {
            background: var(--primary-color);
            color: white;
            border: none;
            padding: 12px;
            font-size: 1.1rem;
            font-weight: 600;
            border-radius: 10px;
            width: 100%;
            transition: all 0.3s;
            margin-top: 10px;
        }
        
        .btn-login:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        
        .btn-login:active {
            transform: translateY(0);
        }
        
        .forgot-password {
            text-align: right;
            margin-top: 10px;
        }
        
        .forgot-password a {
            color: #666;
            text-decoration: none;
            font-size: 0.9rem;
            transition: color 0.3s;
        }
        
        .forgot-password a:hover {
            color: var(--primary-color);
        }
        
        .alert {
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
            animation: slideDown 0.3s ease-out;
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .password-strength {
            height: 5px;
            background: #e0e0e0;
            border-radius: 5px;
            margin-top: 10px;
            overflow: hidden;
        }
        
        .password-strength-bar {
            height: 100%;
            width: 0;
            transition: all 0.3s;
        }
        
        .password-strength-text {
            font-size: 0.85rem;
            margin-top: 5px;
            text-align: right;
        }
        
        .role-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 5px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-left: 10px;
        }
        
        .role-badge.admin { background: #dc3545; color: white; }
        .role-badge.surveyor { background: #28a745; color: white; }
        .role-badge.clerk { background: #17a2b8; color: white; }
        .role-badge.officer { background: #ffc107; color: #333; }
        .role-badge.public { background: #6c757d; color: white; }
        
        .demo-credentials {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-top: 30px;
            font-size: 0.9rem;
        }
        
        .demo-credentials h6 {
            margin-bottom: 10px;
            color: #555;
        }
        
        .demo-credentials .credential-item {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
            border-bottom: 1px dashed #dee2e6;
        }
        
        .demo-credentials .credential-item:last-child {
            border-bottom: none;
        }
        
        .demo-credentials .role {
            font-weight: 600;
            color: var(--primary-color);
        }
        
        .demo-credentials .password {
            font-family: monospace;
            color: #28a745;
        }
        
        @media (max-width: 768px) {
            .login-left {
                display: none;
            }
            
            .login-right {
                padding: 40px 20px;
            }
            
            .login-right h3 {
                font-size: 1.8rem;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="row g-0 login-card">
            <!-- Left Column - Branding -->
            <div class="col-lg-6 login-left">
                <h2><?php echo htmlspecialchars($company_name); ?></h2>
                <p><?php echo htmlspecialchars($site_name); ?></p>
                
                <ul class="feature-list">
                    <li>
                        <i class="bi bi-check-circle-fill"></i>
                        Comprehensive Land Management
                    </li>
                    <li>
                        <i class="bi bi-check-circle-fill"></i>
                        Parcel & Title Tracking
                    </li>
                    <li>
                        <i class="bi bi-check-circle-fill"></i>
                        Ownership Management
                    </li>
                    <li>
                        <i class="bi bi-check-circle-fill"></i>
                        Application Processing
                    </li>
                    <li>
                        <i class="bi bi-check-circle-fill"></i>
                        GIS Integration
                    </li>
                </ul>
                
                <div style="margin-top: 50px;">
                    <p class="mb-1"><i class="bi bi-geo-alt-fill"></i> Ministry of Land, Juba, South Sudan</p>
                    <p class="mb-1"><i class="bi bi-envelope-fill"></i> support@landsystem.gov.ss</p>
                    <p><i class="bi bi-telephone-fill"></i> +211 123 456 789</p>
                </div>
            </div>
            
            <!-- Right Column - Login Form -->
            <div class="col-lg-6 login-right">
                <h3>Welcome Back!</h3>
                <p class="subtitle">Please login to your account</p>
                
                <!-- Alert Messages -->
                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i>
                    <?php echo $success; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <!-- Tab Navigation -->
                <ul class="nav nav-tabs" id="loginTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="login-tab" data-bs-toggle="tab" data-bs-target="#login" type="button" role="tab">
                            <i class="bi bi-box-arrow-in-right me-2"></i>Login
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="register-tab" data-bs-toggle="tab" data-bs-target="#register" type="button" role="tab">
                            <i class="bi bi-person-plus me-2"></i>Register
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="forgot-tab" data-bs-toggle="tab" data-bs-target="#forgot" type="button" role="tab">
                            <i class="bi bi-key me-2"></i>Forgot Password
                        </button>
                    </li>
                </ul>
                
                <!-- Tab Content -->
                <div class="tab-content" id="loginTabsContent">
                    <!-- Login Tab -->
                    <div class="tab-pane fade show active" id="login" role="tabpanel">
                        <form method="POST" action="" class="mt-4">
                            <input type="hidden" name="action" value="login">
                            
                            <div class="form-group">
                                <label class="form-label">Username</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-person"></i></span>
                                    <input type="text" class="form-control" name="username" 
                                           id="loginUsername" placeholder="Enter your username" required
                                           value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Password</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-lock"></i></span>
                                    <input type="password" class="form-control" name="password" 
                                           id="loginPassword" placeholder="Enter your password" required>
                                    <span class="input-group-text" onclick="togglePassword()" style="cursor: pointer;">
                                        <i class="bi bi-eye" id="togglePasswordIcon"></i>
                                    </span>
                                </div>
                            </div>
                            
                            <div class="row mt-3">
                                <div class="col-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="remember" id="remember">
                                        <label class="form-check-label" for="remember">
                                            Remember me
                                        </label>
                                    </div>
                                </div>
                                <div class="col-6 forgot-password">
                                    <a href="#" onclick="showForgotTab()">Forgot password?</a>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn-login">
                                <i class="bi bi-box-arrow-in-right me-2"></i>Login
                            </button>
                        </form>
                    </div>
                    
                    <!-- Register Tab -->
                    <div class="tab-pane fade" id="register" role="tabpanel">
                        <form method="POST" action="" class="mt-4" onsubmit="return validateRegistration()">
                            <input type="hidden" name="action" value="register">
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="form-label">Full Name *</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="bi bi-person"></i></span>
                                            <input type="text" class="form-control" name="name" required 
                                                   value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>">
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="form-label">Email *</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                            <input type="email" class="form-control" name="email" required
                                                   value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="form-label">Phone Number *</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="bi bi-phone"></i></span>
                                            <input type="tel" class="form-control" name="phone" required
                                                   value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="form-label">National ID (Optional)</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="bi bi-card-text"></i></span>
                                            <input type="text" class="form-control" name="national_id"
                                                   value="<?php echo htmlspecialchars($_POST['national_id'] ?? ''); ?>">
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="form-label">Username *</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="bi bi-person-badge"></i></span>
                                            <input type="text" class="form-control" name="username" required
                                                   value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="form-label">Password *</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="bi bi-lock"></i></span>
                                            <input type="password" class="form-control" name="password" 
                                                   id="regPassword" required onkeyup="checkPasswordStrength()">
                                        </div>
                                        <div class="password-strength">
                                            <div class="password-strength-bar" id="passwordStrengthBar"></div>
                                        </div>
                                        <div class="password-strength-text" id="passwordStrengthText"></div>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="form-label">Confirm Password *</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                                            <input type="password" class="form-control" name="confirm_password" required>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-check mt-3">
                                <input class="form-check-input" type="checkbox" id="terms" required>
                                <label class="form-check-label" for="terms">
                                    I agree to the <a href="#" data-bs-toggle="modal" data-bs-target="#termsModal">Terms and Conditions</a>
                                </label>
                            </div>
                            
                            <button type="submit" class="btn-login mt-3">
                                <i class="bi bi-person-plus me-2"></i>Register
                            </button>
                            
                            <p class="text-center mt-3 mb-0">
                                Already have an account? <a href="#" onclick="showLoginTab()">Login here</a>
                            </p>
                        </form>
                    </div>
                    
                    <!-- Forgot Password Tab -->
                    <div class="tab-pane fade" id="forgot" role="tabpanel">
                        <form method="POST" action="" class="mt-4">
                            <input type="hidden" name="action" value="reset_request">
                            
                            <div class="form-group">
                                <label class="form-label">Email Address</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                    <input type="email" class="form-control" name="reset_email" 
                                           placeholder="Enter your email address" required>
                                </div>
                                <small class="text-muted">We'll send a password reset link to this email</small>
                            </div>
                            
                            <button type="submit" class="btn-login">
                                <i class="bi bi-send me-2"></i>Send Reset Link
                            </button>
                            
                            <p class="text-center mt-3 mb-0">
                                <a href="#" onclick="showLoginTab()">Back to Login</a>
                            </p>
                        </form>
                    </div>
                </div>
                
                <!-- Demo Credentials -->
                <div class="demo-credentials">
                    <h6><i class="bi bi-info-circle me-2"></i>Demo Credentials</h6>
                    <div class="credential-item">
                        <span><span class="role-badge admin">Admin</span> admin</span>
                        <span class="password">admin123</span>
                    </div>
                    <div class="credential-item">
                        <span><span class="role-badge surveyor">Surveyor</span> surveyor1</span>
                        <span class="password">surveyor123</span>
                    </div>
                    <div class="credential-item">
                        <span><span class="role-badge clerk">Clerk</span> clerk1</span>
                        <span class="password">clerk123</span>
                    </div>
                    <div class="credential-item">
                        <span><span class="role-badge officer">Officer</span> officer1</span>
                        <span class="password">officer123</span>
                    </div>
                    <div class="credential-item">
                        <span><span class="role-badge public">Public</span> johndoe</span>
                        <span class="password">public123</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Terms and Conditions Modal -->
    <div class="modal fade" id="termsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Terms and Conditions</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <h6>1. Acceptance of Terms</h6>
                    <p>By accessing and using this land management system, you agree to be bound by these Terms and Conditions.</p>
                    
                    <h6 class="mt-3">2. User Accounts</h6>
                    <p>You are responsible for maintaining the confidentiality of your account credentials and for all activities under your account.</p>
                    
                    <h6 class="mt-3">3. Data Accuracy</h6>
                    <p>You agree to provide accurate and complete information when using the system and to update such information as necessary.</p>
                    
                    <h6 class="mt-3">4. Privacy Policy</h6>
                    <p>Your use of the system is also governed by our Privacy Policy, which is incorporated into these terms by reference.</p>
                    
                    <h6 class="mt-3">5. Prohibited Activities</h6>
                    <p>You may not use the system for any unlawful purpose or in any way that could damage, disable, or impair the system.</p>
                    
                    <h6 class="mt-3">6. Termination</h6>
                    <p>We reserve the right to terminate or suspend access to the system immediately, without prior notice, for any breach of these Terms.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Helper function to adjust color brightness
        function adjustBrightness(hex, percent) {
            // This is handled in PHP, but we'll keep the function for completeness
            return hex;
        }
        
        // Toggle password visibility
        function togglePassword() {
            const passwordInput = document.getElementById('loginPassword');
            const icon = document.getElementById('togglePasswordIcon');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('bi-eye');
                icon.classList.add('bi-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('bi-eye-slash');
                icon.classList.add('bi-eye');
            }
        }
        
        // Check password strength
        function checkPasswordStrength() {
            const password = document.getElementById('regPassword').value;
            const bar = document.getElementById('passwordStrengthBar');
            const text = document.getElementById('passwordStrengthText');
            
            let strength = 0;
            
            if (password.length >= 8) strength += 25;
            if (password.match(/[a-z]+/)) strength += 25;
            if (password.match(/[A-Z]+/)) strength += 25;
            if (password.match(/[0-9]+/)) strength += 25;
            if (password.match(/[$@#&!]+/)) strength += 25;
            
            // Cap at 100
            strength = Math.min(strength, 100);
            
            bar.style.width = strength + '%';
            
            if (strength < 50) {
                bar.style.backgroundColor = '#dc3545';
                text.textContent = 'Weak password';
                text.style.color = '#dc3545';
            } else if (strength < 75) {
                bar.style.backgroundColor = '#ffc107';
                text.textContent = 'Medium password';
                text.style.color = '#ffc107';
            } else {
                bar.style.backgroundColor = '#28a745';
                text.textContent = 'Strong password';
                text.style.color = '#28a745';
            }
        }
        
        // Validate registration form
        function validateRegistration() {
            const password = document.getElementById('regPassword').value;
            const confirm = document.querySelector('input[name="confirm_password"]').value;
            
            if (password !== confirm) {
                alert('Passwords do not match!');
                return false;
            }
            
            if (password.length < 6) {
                alert('Password must be at least 6 characters long!');
                return false;
            }
            
            return true;
        }
        
        // Show login tab
        function showLoginTab() {
            const loginTab = document.getElementById('login-tab');
            const tab = new bootstrap.Tab(loginTab);
            tab.show();
        }
        
        // Show forgot password tab
        function showForgotTab() {
            const forgotTab = document.getElementById('forgot-tab');
            const tab = new bootstrap.Tab(forgotTab);
            tab.show();
        }
        
        // Auto-fill demo credentials on click
        document.querySelectorAll('.credential-item').forEach(item => {
            item.addEventListener('click', function() {
                const roleSpan = this.querySelector('.role');
                const passwordSpan = this.querySelector('.password');
                
                if (roleSpan && passwordSpan) {
                    const role = roleSpan.textContent.trim().toLowerCase();
                    const password = passwordSpan.textContent.trim();
                    
                    // Map display roles to usernames
                    let username = '';
                    if (role === 'admin') username = 'admin';
                    else if (role === 'surveyor') username = 'surveyor1';
                    else if (role === 'clerk') username = 'clerk1';
                    else if (role === 'officer') username = 'officer1';
                    else if (role === 'public') username = 'johndoe';
                    
                    document.getElementById('loginUsername').value = username;
                    document.getElementById('loginPassword').value = password;
                    
                    // Show login tab
                    showLoginTab();
                    
                    // Highlight the fields
                    document.getElementById('loginUsername').style.borderColor = '#28a745';
                    document.getElementById('loginPassword').style.borderColor = '#28a745';
                    
                    setTimeout(() => {
                        document.getElementById('loginUsername').style.borderColor = '';
                        document.getElementById('loginPassword').style.borderColor = '';
                    }, 2000);
                }
            });
        });
        
        // Handle hash navigation
        if (window.location.hash === '#register') {
            const registerTab = document.getElementById('register-tab');
            const tab = new bootstrap.Tab(registerTab);
            tab.show();
        }
        
        // Prevent form resubmission on page refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href.split('#')[0]);
        }
    </script>
</body>
</html>

<?php
// Helper function to adjust color brightness (for CSS)
function adjustBrightness($hex, $percent) {
    // Convert hex to rgb
    $hex = ltrim($hex, '#');
    if (strlen($hex) == 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    
    // Adjust brightness
    $r = max(0, min(255, $r + ($r * $percent / 100)));
    $g = max(0, min(255, $g + ($g * $percent / 100)));
    $b = max(0, min(255, $b + ($b * $percent / 100)));
    
    // Convert back to hex
    return sprintf("#%02x%02x%02x", $r, $g, $b);
}
?>