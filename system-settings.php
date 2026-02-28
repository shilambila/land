<?php
ini_set('display_errors', 1); 
error_reporting(E_ALL);

// system-settings.php
include("head.php");
require_once 'db_connection.php';

// ============================================================================
// CREATE SETTINGS TABLE IF NOT EXISTS
// ============================================================================

try {
    // Check if table exists first
    $tableExists = fetchOne($conn, "SHOW TABLES LIKE 'system_settings'");
    
    if (!$tableExists) {
        $conn->exec("
            CREATE TABLE IF NOT EXISTS `system_settings` (
                `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
                `setting_key` varchar(100) NOT NULL,
                `setting_value` text,
                `setting_type` enum('text','number','boolean','json','file','email','phone','url','color') DEFAULT 'text',
                `group` varchar(50) DEFAULT 'general',
                `description` text,
                `is_public` tinyint(1) DEFAULT 0,
                `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `setting_key` (`setting_key`),
                KEY `idx_group` (`group`),
                KEY `idx_public` (`is_public`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        ");
    }
    
    // Try to create uploads directory with error suppression
    @mkdir('../uploads', 0755, true);
    @mkdir('../uploads/logo', 0755, true);
    @mkdir('../uploads/favicon', 0755, true);
    
} catch (Exception $e) {
    // Log error but continue - table might already exist or permissions issue
    error_log("System settings initialization error: " . $e->getMessage());
}

// ============================================================================
// DEFAULT SETTINGS
// ============================================================================

$default_settings = [
    // General Settings
    ['site_name', 'Land Management System', 'text', 'general', 'System name displayed throughout the application'],
    ['site_tagline', 'Comprehensive Land Administration Platform', 'text', 'general', 'Tagline displayed on login page and emails'],
    ['site_description', 'A complete land management system for tracking parcels, titles, ownership, and applications', 'text', 'general', 'Meta description for SEO'],
    ['site_keywords', 'land management, parcels, titles, ownership, GIS', 'text', 'general', 'Meta keywords for SEO'],
    ['site_url', 'http://localhost/aland/portal', 'url', 'general', 'Base URL of the system'],
    ['site_email', 'info@landsystem.gov.ss', 'email', 'general', 'Primary contact email'],
    ['site_phone', '+211 123 456 789', 'phone', 'general', 'Primary contact phone'],
    ['site_address', 'Ministry of Land, Juba, South Sudan', 'text', 'general', 'Physical address'],
    ['site_timezone', 'Africa/Juba', 'text', 'general', 'System timezone'],
    ['site_language', 'en', 'text', 'general', 'Default language'],
    ['date_format', 'd/m/Y', 'text', 'general', 'Date display format'],
    ['time_format', 'H:i:s', 'text', 'general', 'Time display format'],
    ['week_start', 'monday', 'text', 'general', 'First day of the week'],
    
    // Branding
    ['company_name', 'Ministry of Land, Housing & Urban Development', 'text', 'branding', 'Organization name'],
    ['company_abbreviation', 'MLHUD', 'text', 'branding', 'Organization abbreviation'],
    ['company_logo', '', 'file', 'branding', 'Main logo (recommended size: 200x60px)'],
    ['company_logo_dark', '', 'file', 'branding', 'Dark version of logo for light backgrounds'],
    ['company_logo_light', '', 'file', 'branding', 'Light version of logo for dark backgrounds'],
    ['company_favicon', '', 'file', 'branding', 'Favicon (16x16px or 32x32px)'],
    ['company_icon', '', 'file', 'branding', 'App icon (192x192px for mobile)'],
    ['login_background', '', 'file', 'branding', 'Login page background image'],
    ['primary_color', '#0d6efd', 'color', 'branding', 'Primary theme color'],
    ['secondary_color', '#6c757d', 'color', 'branding', 'Secondary theme color'],
    
    // Contact Information
    ['contact_email', 'support@landsystem.gov.ss', 'email', 'contact', 'Support email address'],
    ['contact_phone', '+211 123 456 789', 'phone', 'contact', 'Support phone number'],
    ['contact_fax', '+211 123 456 788', 'phone', 'contact', 'Fax number'],
    ['contact_address', 'Ministry of Land, Juba, South Sudan', 'text', 'contact', 'Physical address'],
    ['contact_po_box', 'P.O. Box 123, Juba', 'text', 'contact', 'Post office box'],
    ['contact_hours', 'Monday - Friday: 8:00 AM - 5:00 PM', 'text', 'contact', 'Working hours'],
    
    // Currency & Financial
    ['currency_name', 'South Sudanese Pound', 'text', 'currency', 'Currency full name'],
    ['currency_code', 'SSP', 'text', 'currency', 'Currency code (e.g., SSP, USD)'],
    ['currency_symbol', '£', 'text', 'currency', 'Currency symbol'],
    ['currency_symbol_position', 'before', 'text', 'currency', 'Symbol position (before/after)'],
    ['currency_decimal_places', '2', 'number', 'currency', 'Number of decimal places'],
    ['currency_thousand_separator', ',', 'text', 'currency', 'Thousands separator'],
    ['currency_decimal_separator', '.', 'text', 'currency', 'Decimal separator'],
    
    // Email Settings
    ['mail_driver', 'smtp', 'text', 'email', 'Mail driver (smtp, sendmail, mail)'],
    ['mail_host', 'smtp.gmail.com', 'text', 'email', 'SMTP host'],
    ['mail_port', '587', 'number', 'email', 'SMTP port'],
    ['mail_username', '', 'text', 'email', 'SMTP username'],
    ['mail_password', '', 'text', 'email', 'SMTP password'],
    ['mail_encryption', 'tls', 'text', 'email', 'Encryption (tls, ssl, null)'],
    ['mail_from_address', 'noreply@landsystem.gov.ss', 'email', 'email', 'Default from email address'],
    ['mail_from_name', 'Land Management System', 'text', 'email', 'Default from name'],
    
    // Security Settings
    ['session_timeout', '30', 'number', 'security', 'Session timeout in minutes'],
    ['password_min_length', '8', 'number', 'security', 'Minimum password length'],
    ['max_login_attempts', '5', 'number', 'security', 'Maximum login attempts before lockout'],
    ['lockout_duration', '15', 'number', 'security', 'Lockout duration in minutes'],
    ['two_factor_auth', '0', 'boolean', 'security', 'Enable two-factor authentication'],
];

// ============================================================================
// HANDLE ACTIONS
// ============================================================================

$message = '';
$messageType = '';

// Check if table exists and initialize settings
try {
    $tableExists = fetchOne($conn, "SHOW TABLES LIKE 'system_settings'");
    
    if ($tableExists) {
        // Check if settings table is empty
        $count = fetchOne($conn, "SELECT COUNT(*) as count FROM system_settings");
        
        if ($count['count'] == 0) {
            // Insert default settings
            foreach ($default_settings as $setting) {
                try {
                    executeQuery($conn, "
                        INSERT INTO system_settings (setting_key, setting_value, setting_type, `group`, description)
                        VALUES (?, ?, ?, ?, ?)
                    ", $setting);
                } catch (Exception $e) {
                    // Skip if duplicate key or other error
                    error_log("Error inserting default setting: " . $e->getMessage());
                }
            }
        }
    }
} catch (Exception $e) {
    error_log("Error initializing settings: " . $e->getMessage());
}

// Handle settings update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    if ($_POST['action'] === 'update_settings') {
        $group = $_POST['group'] ?? 'general';
        $settings = $_POST['settings'] ?? [];
        
        try {
            $conn->beginTransaction();
            
            foreach ($settings as $key => $value) {
                // Handle file uploads
                if (isset($_FILES['settings']['name'][$key]) && $_FILES['settings']['error'][$key] === UPLOAD_ERR_OK) {
                    $file = [
                        'name' => $_FILES['settings']['name'][$key],
                        'type' => $_FILES['settings']['type'][$key],
                        'tmp_name' => $_FILES['settings']['tmp_name'][$key],
                        'error' => $_FILES['settings']['error'][$key],
                        'size' => $_FILES['settings']['size'][$key]
                    ];
                    
                    $upload_result = handleFileUpload($file, $key);
                    if ($upload_result['success']) {
                        $value = $upload_result['path'];
                    } else {
                        // Just log error but continue - don't throw exception for file upload issues
                        error_log("File upload error for $key: " . $upload_result['message']);
                    }
                }
                
                // Handle boolean values (checkboxes)
                if (is_array($value)) {
                    $value = isset($value[0]) ? '1' : '0';
                }
                
                // Check if setting exists
                $exists = fetchOne($conn, "SELECT id FROM system_settings WHERE setting_key = ?", [$key]);
                
                if ($exists) {
                    executeQuery($conn, "
                        UPDATE system_settings 
                        SET setting_value = ?, updated_at = NOW()
                        WHERE setting_key = ?
                    ", [$value, $key]);
                } else {
                    // Insert if it doesn't exist (for custom settings)
                    executeQuery($conn, "
                        INSERT INTO system_settings (setting_key, setting_value, `group`, created_at)
                        VALUES (?, ?, ?, NOW())
                    ", [$key, $value, $group]);
                }
            }
            
            $conn->commit();
            $message = "Settings updated successfully";
            $messageType = "success";
        } catch (Exception $e) {
            $conn->rollBack();
            $message = "Error updating settings: " . $e->getMessage();
            $messageType = "danger";
            error_log("Settings update error: " . $e->getMessage());
        }
    }
    
    // Reset to defaults
    if ($_POST['action'] === 'reset_defaults') {
        $group = $_POST['group'] ?? null;
        
        try {
            $conn->beginTransaction();
            
            if ($group && $group != 'all') {
                // Reset specific group
                foreach ($default_settings as $setting) {
                    if ($setting[3] == $group) {
                        executeQuery($conn, "
                            UPDATE system_settings 
                            SET setting_value = ?, updated_at = NOW()
                            WHERE setting_key = ?
                        ", [$setting[1], $setting[0]]);
                    }
                }
                $message = "Group settings reset to defaults";
            } else {
                // Reset all
                foreach ($default_settings as $setting) {
                    executeQuery($conn, "
                        UPDATE system_settings 
                        SET setting_value = ?, updated_at = NOW()
                        WHERE setting_key = ?
                    ", [$setting[1], $setting[0]]);
                }
                $message = "All settings reset to defaults";
            }
            
            $conn->commit();
            $messageType = "warning";
        } catch (Exception $e) {
            $conn->rollBack();
            $message = "Error resetting settings: " . $e->getMessage();
            $messageType = "danger";
            error_log("Settings reset error: " . $e->getMessage());
        }
    }
}

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

function handleFileUpload($file, $key) {
    $allowed_types = [
        'image/jpeg', 'image/png', 'image/gif', 'image/svg+xml',
        'image/x-icon', 'image/vnd.microsoft.icon'
    ];
    
    // Use a writable directory - try multiple options
    $upload_dir = '';
    $possible_dirs = [
        __DIR__ . '/../uploads/',
        __DIR__ . '/uploads/',
        '/tmp/landsystem_uploads/'
    ];
    
    foreach ($possible_dirs as $dir) {
        if (@mkdir($dir, 0755, true) || is_dir($dir)) {
            if (is_writable($dir)) {
                $upload_dir = $dir;
                break;
            }
        }
    }
    
    if (!$upload_dir) {
        // Fallback to system temp dir
        $upload_dir = sys_get_temp_dir() . '/landsystem_uploads/';
        @mkdir($upload_dir, 0755, true);
    }
    
    // Create subfolder based on key
    if (strpos($key, 'logo') !== false) {
        $upload_dir .= 'logo/';
    } elseif (strpos($key, 'favicon') !== false || strpos($key, 'icon') !== false) {
        $upload_dir .= 'favicon/';
    } elseif (strpos($key, 'background') !== false) {
        $upload_dir .= 'backgrounds/';
    } else {
        $upload_dir .= 'misc/';
    }
    
    @mkdir($upload_dir, 0755, true);
    
    // Generate unique filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = $key . '_' . time() . '.' . $extension;
    $filepath = $upload_dir . $filename;
    
    // Validate file type
    if (!in_array($file['type'], $allowed_types)) {
        return ['success' => false, 'message' => 'Invalid file type'];
    }
    
    // Validate file size (max 2MB)
    if ($file['size'] > 2 * 1024 * 1024) {
        return ['success' => false, 'message' => 'File too large (max 2MB)'];
    }
    
    // Move uploaded file
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        // Return relative path for storage in database
        $relative_path = 'uploads/' . basename(dirname($filepath)) . '/' . $filename;
        return ['success' => true, 'path' => $relative_path];
    }
    
    return ['success' => false, 'message' => 'Failed to upload file'];
}

function getAllSettings($conn) {
    try {
        $result = fetchAll($conn, "SELECT setting_key, setting_value, setting_type FROM system_settings");
        $settings = [];
        foreach ($result as $row) {
            $settings[$row['setting_key']] = [
                'value' => $row['setting_value'],
                'type' => $row['setting_type']
            ];
        }
        return $settings;
    } catch (Exception $e) {
        error_log("Error getting settings: " . $e->getMessage());
        return [];
    }
}

// ============================================================================
// GET CURRENT SETTINGS
// ============================================================================

$current_settings = [];
$settings_by_group = [];

try {
    // Check if table exists before querying
    $tableExists = fetchOne($conn, "SHOW TABLES LIKE 'system_settings'");
    
    if ($tableExists) {
        $all_settings = fetchAll($conn, "
            SELECT * FROM system_settings 
            ORDER BY 
                CASE `group`
                    WHEN 'general' THEN 1
                    WHEN 'branding' THEN 2
                    WHEN 'contact' THEN 3
                    WHEN 'currency' THEN 4
                    WHEN 'email' THEN 5
                    WHEN 'security' THEN 6
                    ELSE 7
                END,
                id
        ");
        
        foreach ($all_settings as $setting) {
            $current_settings[$setting['setting_key']] = $setting['setting_value'];
            if (!isset($settings_by_group[$setting['group']])) {
                $settings_by_group[$setting['group']] = [];
            }
            $settings_by_group[$setting['group']][] = $setting;
        }
    } else {
        // Create default settings array for display
        foreach ($default_settings as $setting) {
            $current_settings[$setting[0]] = $setting[1];
            if (!isset($settings_by_group[$setting[3]])) {
                $settings_by_group[$setting[3]] = [];
            }
            $settings_by_group[$setting[3]][] = [
                'setting_key' => $setting[0],
                'setting_value' => $setting[1],
                'setting_type' => $setting[2],
                'group' => $setting[3],
                'description' => $setting[4]
            ];
        }
    }
} catch (Exception $e) {
    error_log("Error loading settings: " . $e->getMessage());
    // Create default settings array for display
    foreach ($default_settings as $setting) {
        $current_settings[$setting[0]] = $setting[1];
        if (!isset($settings_by_group[$setting[3]])) {
            $settings_by_group[$setting[3]] = [];
        }
        $settings_by_group[$setting[3]][] = [
            'setting_key' => $setting[0],
            'setting_value' => $setting[1],
            'setting_type' => $setting[2],
            'group' => $setting[3],
            'description' => $setting[4]
        ];
    }
}

// Get available timezones
try {
    $timezones = DateTimeZone::listIdentifiers();
} catch (Exception $e) {
    $timezones = ['Africa/Juba', 'UTC', 'Africa/Nairobi'];
}
?>

<body data-page="system-settings" class="system-settings-page">
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
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div>
                            <h1 class="h3 mb-0">
                                <i class="bi bi-gear-fill text-secondary me-2"></i>
                                System Settings
                            </h1>
                            <p class="text-muted mb-0">Configure system parameters, branding, and preferences</p>
                        </div>
                        <div>
                            <button class="btn btn-outline-danger me-2" onclick="resetToDefaults()">
                                <i class="bi bi-arrow-counterclockwise"></i> Reset to Defaults
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

                    <!-- Settings Tabs -->
                    <div class="row">
                        <div class="col-md-3 mb-4">
                            <!-- Settings Navigation -->
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h5 class="card-title mb-0 fw-bold">
                                        <i class="bi bi-list-ul me-2"></i>Settings Menu
                                    </h5>
                                </div>
                                <div class="list-group list-group-flush" id="settingsTabs" role="tablist">
                                    <a class="list-group-item list-group-item-action active" data-bs-toggle="list" href="#general" role="tab">
                                        <i class="bi bi-house-door me-2"></i>General
                                    </a>
                                    <a class="list-group-item list-group-item-action" data-bs-toggle="list" href="#branding" role="tab">
                                        <i class="bi bi-brush me-2"></i>Branding
                                    </a>
                                    <a class="list-group-item list-group-item-action" data-bs-toggle="list" href="#contact" role="tab">
                                        <i class="bi bi-telephone me-2"></i>Contact
                                    </a>
                                    <a class="list-group-item list-group-item-action" data-bs-toggle="list" href="#currency" role="tab">
                                        <i class="bi bi-cash-stack me-2"></i>Currency
                                    </a>
                                    <a class="list-group-item list-group-item-action" data-bs-toggle="list" href="#email" role="tab">
                                        <i class="bi bi-envelope me-2"></i>Email
                                    </a>
                                    <a class="list-group-item list-group-item-action" data-bs-toggle="list" href="#security" role="tab">
                                        <i class="bi bi-shield-lock me-2"></i>Security
                                    </a>
                                </div>
                            </div>
                            
                            <!-- System Info Card -->
                            <div class="card border-0 shadow-sm mt-4">
                                <div class="card-header bg-white py-3">
                                    <h5 class="card-title mb-0 fw-bold">
                                        <i class="bi bi-info-circle me-2"></i>System Info
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <table class="table table-sm">
                                        <tr>
                                            <td>PHP Version:</td>
                                            <td><strong><?php echo phpversion(); ?></strong></td>
                                        </tr>
                                        <tr>
                                            <td>Server:</td>
                                            <td><strong><?php echo $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'; ?></strong></td>
                                        </tr>
                                        <tr>
                                            <td>Max Upload:</td>
                                            <td><strong><?php echo ini_get('upload_max_filesize'); ?></strong></td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-9">
                            <!-- Settings Forms -->
                            <div class="tab-content">
                                <?php foreach ($settings_by_group as $group => $settings): ?>
                                <div class="tab-pane fade <?php echo $group == 'general' ? 'show active' : ''; ?>" id="<?php echo $group; ?>" role="tabpanel">
                                    <div class="card border-0 shadow-sm">
                                        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                                            <h5 class="card-title mb-0 fw-bold">
                                                <i class="bi bi-gear me-2"></i>
                                                <?php echo ucfirst($group); ?> Settings
                                            </h5>
                                            <button class="btn btn-sm btn-outline-warning" onclick="resetGroup('<?php echo $group; ?>')">
                                                <i class="bi bi-arrow-counterclockwise"></i> Reset Group
                                            </button>
                                        </div>
                                        <div class="card-body">
                                            <form method="POST" enctype="multipart/form-data" id="form-<?php echo $group; ?>">
                                                <input type="hidden" name="action" value="update_settings">
                                                <input type="hidden" name="group" value="<?php echo $group; ?>">
                                                
                                                <?php foreach ($settings as $setting): ?>
                                                <div class="row mb-3 align-items-center">
                                                    <label class="col-sm-4 col-form-label">
                                                        <?php 
                                                        $label = str_replace('_', ' ', $setting['setting_key']);
                                                        $label = ucwords($label);
                                                        echo $label;
                                                        ?>
                                                        <?php if (!empty($setting['description'])): ?>
                                                        <i class="bi bi-question-circle text-muted ms-1" 
                                                           data-bs-toggle="tooltip" 
                                                           title="<?php echo htmlspecialchars($setting['description']); ?>"></i>
                                                        <?php endif; ?>
                                                    </label>
                                                    <div class="col-sm-8">
                                                        <?php if ($setting['setting_type'] == 'boolean'): ?>
                                                            <div class="form-check form-switch">
                                                                <input class="form-check-input" type="checkbox" 
                                                                       name="settings[<?php echo $setting['setting_key']; ?>][]" 
                                                                       value="1" 
                                                                       id="<?php echo $setting['setting_key']; ?>"
                                                                       <?php echo ($current_settings[$setting['setting_key']] ?? '') == '1' ? 'checked' : ''; ?>>
                                                                <label class="form-check-label" for="<?php echo $setting['setting_key']; ?>">
                                                                    Enable
                                                                </label>
                                                            </div>
                                                            
                                                        <?php elseif ($setting['setting_type'] == 'color'): ?>
                                                            <input type="color" class="form-control form-control-color" 
                                                                   name="settings[<?php echo $setting['setting_key']; ?>]" 
                                                                   value="<?php echo htmlspecialchars($current_settings[$setting['setting_key']] ?? '#0d6efd'); ?>"
                                                                   style="width: 100%; height: 38px;">
                                                            
                                                        <?php elseif ($setting['setting_type'] == 'file'): ?>
                                                            <div>
                                                                <?php if (!empty($current_settings[$setting['setting_key']])): ?>
                                                                <div class="mb-2">
                                                                    <img src="../<?php echo htmlspecialchars($current_settings[$setting['setting_key']]); ?>" 
                                                                         alt="<?php echo $label; ?>" style="max-height: 40px;">
                                                                </div>
                                                                <?php endif; ?>
                                                                <input type="file" class="form-control" 
                                                                       name="settings[<?php echo $setting['setting_key']; ?>]" 
                                                                       accept="image/*">
                                                            </div>
                                                            
                                                        <?php elseif ($setting['setting_type'] == 'email'): ?>
                                                            <input type="email" class="form-control" 
                                                                   name="settings[<?php echo $setting['setting_key']; ?>]" 
                                                                   value="<?php echo htmlspecialchars($current_settings[$setting['setting_key']] ?? ''); ?>">
                                                            
                                                        <?php elseif ($setting['setting_type'] == 'phone'): ?>
                                                            <input type="tel" class="form-control" 
                                                                   name="settings[<?php echo $setting['setting_key']; ?>]" 
                                                                   value="<?php echo htmlspecialchars($current_settings[$setting['setting_key']] ?? ''); ?>">
                                                            
                                                        <?php elseif ($setting['setting_type'] == 'url'): ?>
                                                            <input type="url" class="form-control" 
                                                                   name="settings[<?php echo $setting['setting_key']; ?>]" 
                                                                   value="<?php echo htmlspecialchars($current_settings[$setting['setting_key']] ?? ''); ?>">
                                                            
                                                        <?php elseif ($setting['setting_type'] == 'number'): ?>
                                                            <input type="number" class="form-control" 
                                                                   name="settings[<?php echo $setting['setting_key']; ?>]" 
                                                                   value="<?php echo htmlspecialchars($current_settings[$setting['setting_key']] ?? ''); ?>"
                                                                   step="any">
                                                            
                                                        <?php elseif ($setting['setting_key'] == 'site_timezone'): ?>
                                                            <select class="form-select" name="settings[<?php echo $setting['setting_key']; ?>]">
                                                                <?php foreach ($timezones as $tz): ?>
                                                                <option value="<?php echo $tz; ?>" <?php echo ($current_settings[$setting['setting_key']] ?? 'Africa/Juba') == $tz ? 'selected' : ''; ?>>
                                                                    <?php echo $tz; ?>
                                                                </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                            
                                                        <?php else: ?>
                                                            <?php if (strlen($current_settings[$setting['setting_key']] ?? '') > 100): ?>
                                                                <textarea class="form-control" 
                                                                          name="settings[<?php echo $setting['setting_key']; ?>]" 
                                                                          rows="3"><?php echo htmlspecialchars($current_settings[$setting['setting_key']] ?? ''); ?></textarea>
                                                            <?php else: ?>
                                                                <input type="text" class="form-control" 
                                                                       name="settings[<?php echo $setting['setting_key']; ?>]" 
                                                                       value="<?php echo htmlspecialchars($current_settings[$setting['setting_key']] ?? ''); ?>">
                                                            <?php endif; ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <?php endforeach; ?>
                                                
                                                <hr>
                                                <div class="d-flex justify-content-end">
                                                    <button type="submit" class="btn btn-primary">
                                                        <i class="bi bi-save me-2"></i>Save <?php echo ucfirst($group); ?> Settings
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                </div>
            </main>

            <!-- Footer -->
            <?php include("footer.php"); ?>

        </div>
    </div>

    <!-- Reset Confirmation Modal -->
    <div class="modal fade" id="resetModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Reset Settings</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to reset <span id="resetScope"></span> settings to default values?</p>
                    <p class="text-warning"><i class="bi bi-exclamation-triangle"></i> This action cannot be undone.</p>
                </div>
                <div class="modal-footer">
                    <form method="POST" id="resetForm">
                        <input type="hidden" name="action" value="reset_defaults">
                        <input type="hidden" name="group" id="resetGroup" value="">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning">Reset to Defaults</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Initialize tooltips
        document.addEventListener('DOMContentLoaded', function() {
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
            var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl)
            });
        });
        
        // Reset group settings
        function resetGroup(group) {
            document.getElementById('resetScope').textContent = group;
            document.getElementById('resetGroup').value = group;
            new bootstrap.Modal(document.getElementById('resetModal')).show();
        }
        
        // Reset all settings
        function resetToDefaults() {
            document.getElementById('resetScope').textContent = 'all';
            document.getElementById('resetGroup').value = 'all';
            new bootstrap.Modal(document.getElementById('resetModal')).show();
        }
    </script>

    <style>
        .list-group-item.active {
            background-color: #0d6efd;
            border-color: #0d6efd;
        }
        
        .list-group-item i {
            width: 20px;
        }
        
        .form-switch .form-check-input {
            width: 3em;
            height: 1.5em;
        }
        
        .card {
            transition: all 0.3s ease;
        }
        
        .card:hover {
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15) !important;
        }
        
        .form-control-color {
            padding: 0.375rem;
            height: 38px;
        }
        
        @media (max-width: 768px) {
            .col-sm-4, .col-sm-8 {
                width: 100%;
            }
            
            .col-sm-4 {
                margin-bottom: 0.5rem;
            }
        }
    </style>
</body>
</html>