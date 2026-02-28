<?php
ini_set('display_errors', 1); 
error_reporting(E_ALL);

// applications-consolidation.php
include("head.php");
require_once 'db_connection.php';

// ============================================================================
// AUTO-FIX DATABASE ISSUES - FIXED FOR PDO
// ============================================================================

function autoFixDatabaseIssues($conn) {
    $fixes = [];
    $errors = [];
    
    try {
        // Check if application_parcels table exists using PDO
        $stmt = $conn->query("
            SELECT COUNT(*) as count 
            FROM information_schema.tables 
            WHERE table_schema = DATABASE() 
            AND table_name = 'application_parcels'
        ");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($row['count'] == 0) {
            // Create application_parcels table
            $conn->exec("
                CREATE TABLE IF NOT EXISTS application_parcels (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    application_id INT UNSIGNED NOT NULL,
                    parcel_id INT UNSIGNED NOT NULL,
                    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_application (application_id),
                    KEY idx_parcel (parcel_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
            ");
            $fixes[] = "Created application_parcels table";
        }
        
        // Check if consolidations table exists
        $stmt = $conn->query("
            SELECT COUNT(*) as count 
            FROM information_schema.tables 
            WHERE table_schema = DATABASE() 
            AND table_name = 'consolidations'
        ");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($row['count'] == 0) {
            // Create consolidations table
            $conn->exec("
                CREATE TABLE IF NOT EXISTS consolidations (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    application_id INT UNSIGNED NOT NULL,
                    new_parcel_id INT UNSIGNED DEFAULT NULL,
                    new_parcel_number VARCHAR(50) NOT NULL,
                    total_area DECIMAL(15,4) DEFAULT NULL,
                    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_application (application_id),
                    KEY idx_new_parcel (new_parcel_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
            ");
            $fixes[] = "Created consolidations table";
        }
        
    } catch (Exception $e) {
        $errors[] = "Auto-fix error: " . $e->getMessage();
    }
    
    return ['fixes' => $fixes, 'errors' => $errors];
}

// Run auto-fix
$fixResult = autoFixDatabaseIssues($conn);
$fixMessages = $fixResult['fixes'];
$fixErrors = $fixResult['errors'];

// ============================================================================
// SAFE QUERY FUNCTIONS FOR PDO
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
// HANDLE FORM SUBMISSION
// ============================================================================

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Validate required fields
    $errors = [];
    
    if (empty($_POST['applicant_party_id'])) {
        $errors[] = "Applicant is required";
    }
    
    if (empty($_POST['source_parcels']) || !is_array($_POST['source_parcels']) || count($_POST['source_parcels']) < 2) {
        $errors[] = "At least two parcels must be selected for consolidation";
    }
    
    if (empty($_POST['new_parcel_number'])) {
        $errors[] = "New parcel number is required";
    }
    
    if (empty($_POST['submission_date'])) {
        $errors[] = "Submission date is required";
    }
    
    if (empty($errors)) {
        try {
            // Start transaction
            $conn->beginTransaction();
            
            $applicant_party_id = $_POST['applicant_party_id'];
            $source_parcels = $_POST['source_parcels'];
            $new_parcel_number = $_POST['new_parcel_number'];
            $new_area = !empty($_POST['new_area']) ? $_POST['new_area'] : null;
            $new_location_description = $_POST['new_location_description'] ?? null;
            $new_boma_id = !empty($_POST['new_boma_id']) ? $_POST['new_boma_id'] : null;
            $new_zoning_id = !empty($_POST['new_zoning_id']) ? $_POST['new_zoning_id'] : null;
            $submission_date = $_POST['submission_date'];
            $details = $_POST['details'] ?? null;
            $status = $_POST['status'] ?? 'submitted';
            $create_new_parcel = isset($_POST['create_new_parcel']) ? true : false;
            
            // Verify that all source parcels exist
            $placeholders = implode(',', array_fill(0, count($source_parcels), '?'));
            $parcels_check = safeFetchAll($conn, "
                SELECT 
                    p.id,
                    p.parcel_number,
                    p.area,
                    p.boma_id,
                    p.current_zoning_id,
                    (SELECT COUNT(*) FROM titles t WHERE t.parcel_id = p.id AND t.status = 'active') as has_active_title
                FROM parcels p
                WHERE p.id IN ($placeholders)
            ", $source_parcels, []);
            
            $invalid_parcels = [];
            $total_area = 0;
            $boma_ids = [];
            $zoning_ids = [];
            
            foreach ($parcels_check as $parcel) {
                if (!empty($parcel['has_active_title']) && $parcel['has_active_title'] > 0) {
                    $invalid_parcels[] = $parcel['parcel_number'] . " (has active title)";
                }
                $total_area += floatval($parcel['area'] ?? 0);
                if (!empty($parcel['boma_id'])) $boma_ids[] = $parcel['boma_id'];
                if (!empty($parcel['current_zoning_id'])) $zoning_ids[] = $parcel['current_zoning_id'];
            }
            
            if (!empty($invalid_parcels)) {
                throw new Exception("Some parcels cannot be consolidated: " . implode(", ", $invalid_parcels));
            }
            
            // Check if all parcels are in the same boma
            $unique_bomas = array_unique($boma_ids);
            if (count($unique_bomas) > 1 && empty($new_boma_id)) {
                throw new Exception("Parcels are from different bomas. Please select a boma for the new consolidated parcel.");
            }
            
            // If area not provided, calculate from sum of source parcels
            if (empty($new_area)) {
                $new_area = $total_area;
            }
            
            // Insert consolidation application
            executeQuery($conn, "
                INSERT INTO applications (
                    application_type, applicant_party_id, 
                    submission_date, status, details, created_at
                ) VALUES (
                    'consolidation', ?, ?, ?, ?, NOW()
                )
            ", [$applicant_party_id, $submission_date, $status, $details]);
            
            $application_id = $conn->lastInsertId();
            
            // Create new parcel if requested
            $new_parcel_id = null;
            if ($create_new_parcel) {
                // Check if parcel number already exists
                $existing = safeFetchOne($conn, "SELECT id FROM parcels WHERE parcel_number = ?", [$new_parcel_number]);
                if ($existing) {
                    throw new Exception("Parcel number already exists. Please choose a different number.");
                }
                
                // Use the most common boma if not specified
                if (empty($new_boma_id) && !empty($boma_ids)) {
                    $boma_counts = array_count_values($boma_ids);
                    $new_boma_id = array_search(max($boma_counts), $boma_counts);
                }
                
                // Use the most common zoning if not specified
                if (empty($new_zoning_id) && !empty($zoning_ids)) {
                    $zoning_counts = array_count_values($zoning_ids);
                    $new_zoning_id = array_search(max($zoning_counts), $zoning_counts);
                }
                
                // Insert new parcel without geometry for now
                executeQuery($conn, "
                    INSERT INTO parcels (
                        parcel_number, area, location_description,
                        boma_id, current_zoning_id, created_at
                    ) VALUES (
                        ?, ?, ?, ?, ?, NOW()
                    )
                ", [$new_parcel_number, $new_area, $new_location_description, $new_boma_id, $new_zoning_id]);
                
                $new_parcel_id = $conn->lastInsertId();
                
                // Update the application with the new parcel_id
                executeQuery($conn, "
                    UPDATE applications SET parcel_id = ? WHERE id = ?
                ", [$new_parcel_id, $application_id]);
            }
            
            // Store source parcels in application details as JSON
            $current_details = safeFetchOne($conn, "SELECT details FROM applications WHERE id = ?", [$application_id]);
            $details_array = [];
            if ($current_details && !empty($current_details['details'])) {
                $details_array = json_decode($current_details['details'], true) ?: [];
            }
            $details_array['source_parcels'] = $source_parcels;
            $details_array['consolidation'] = [
                'new_parcel_id' => $new_parcel_id,
                'new_parcel_number' => $new_parcel_number,
                'total_area' => $new_area,
                'source_parcels_count' => count($source_parcels)
            ];
            
            executeQuery($conn, "
                UPDATE applications SET details = ? WHERE id = ?
            ", [json_encode($details_array), $application_id]);
            
            // Upload documents if any
            if (!empty($_FILES['documents']['name'][0])) {
                $upload_dir = 'uploads/consolidations/' . $application_id . '/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $document_types = $_POST['document_types'] ?? [];
                $files = $_FILES['documents'];
                
                for ($i = 0; $i < count($files['name']); $i++) {
                    if ($files['error'][$i] === UPLOAD_ERR_OK) {
                        $file_name = time() . '_' . $files['name'][$i];
                        $file_path = $upload_dir . $file_name;
                        
                        if (move_uploaded_file($files['tmp_name'][$i], $file_path)) {
                            // Check if documents table exists
                            $docTableCheck = safeFetchOne($conn, "
                                SELECT COUNT(*) as count 
                                FROM information_schema.tables 
                                WHERE table_schema = DATABASE() 
                                AND table_name = 'documents'
                            ");
                            
                            if ($docTableCheck && $docTableCheck['count'] > 0) {
                                executeQuery($conn, "
                                    INSERT INTO documents (
                                        document_type, application_id, party_id,
                                        file_name, file_path, mime_type, 
                                        uploaded_by, uploaded_at, description
                                    ) VALUES (
                                        ?, ?, ?, ?, ?, ?, ?, NOW(), ?
                                    )
                                ", [
                                    $document_types[$i] ?? 'consolidation_document',
                                    $application_id,
                                    $applicant_party_id,
                                    $file_name,
                                    $file_path,
                                    $files['type'][$i],
                                    $_SESSION['user_id'] ?? 1,
                                    'Supporting document for consolidation application #' . $application_id
                                ]);
                            }
                        }
                    }
                }
            }
            
            $conn->commit();
            
            $message = "Consolidation application submitted successfully. Application ID: " . $application_id;
            $messageType = "success";
            
            // Redirect to view page
            header("Location: applications-view.php?id=" . $application_id . "&success=1");
            exit;
            
        } catch (Exception $e) {
            $conn->rollBack();
            $message = "Error submitting application: " . $e->getMessage();
            $messageType = "danger";
        }
    } else {
        $message = implode("<br>", $errors);
        $messageType = "warning";
    }
}

// ============================================================================
// GET DROPDOWN DATA WITH SAFE QUERIES
// ============================================================================

// Get all parties
$parties = safeFetchAll($conn, "
    SELECT 
        p.id, 
        p.party_type, 
        p.name, 
        p.national_id, 
        p.registration_number,
        p.phone,
        p.email
    FROM parties p
    ORDER BY 
        CASE WHEN p.party_type = 'individual' THEN 0 ELSE 1 END,
        p.name
", [], []);

// Get parcels available for consolidation
$available_parcels = safeFetchAll($conn, "
    SELECT 
        p.id,
        p.parcel_number,
        p.survey_number,
        p.area,
        p.location_description,
        b.id as boma_id,
        b.name as boma_name,
        py.id as payam_id,
        py.name as payam_name,
        c.id as county_id,
        c.name as county_name,
        s.id as state_id,
        s.name as state_name,
        z.zone_code,
        z.zone_name,
        -- Owner info
        o.party_id as owner_id,
        pa.name as owner_name,
        pa.party_type as owner_type,
        -- Check status
        (SELECT COUNT(*) FROM titles t WHERE t.parcel_id = p.id AND t.status = 'active') as has_title
    FROM parcels p
    LEFT JOIN bomas b ON p.boma_id = b.id
    LEFT JOIN payams py ON b.payam_id = py.id
    LEFT JOIN counties c ON py.county_id = c.id
    LEFT JOIN states s ON c.state_id = s.id
    LEFT JOIN zoning z ON p.current_zoning_id = z.id
    LEFT JOIN ownerships o ON p.id = o.parcel_id AND (o.is_current = 1 OR o.is_current IS NULL)
    LEFT JOIN parties pa ON o.party_id = pa.id
    WHERE (SELECT COUNT(*) FROM titles t WHERE t.parcel_id = p.id AND t.status = 'active') = 0
       OR (SELECT COUNT(*) FROM titles t WHERE t.parcel_id = p.id AND t.status = 'active') IS NULL
    ORDER BY p.parcel_number
", [], []);

// Get all bomas
$bomas = safeFetchAll($conn, "
    SELECT 
        b.id, b.name, b.code,
        py.name as payam_name,
        c.name as county_name,
        s.name as state_name
    FROM bomas b
    LEFT JOIN payams py ON b.payam_id = py.id
    LEFT JOIN counties c ON py.county_id = c.id
    LEFT JOIN states s ON c.state_id = s.id
    ORDER BY s.name, c.name, py.name, b.name
", [], []);

// Get zoning types
$zoning_types = safeFetchAll($conn, "
    SELECT id, zone_code, zone_name, description 
    FROM zoning 
    ORDER BY zone_code
", [], []);

// Get recent consolidation applications
$recent_applications = safeFetchAll($conn, "
    SELECT 
        a.id,
        a.submission_date,
        a.status,
        app.name as applicant_name
    FROM applications a
    LEFT JOIN parties app ON a.applicant_party_id = app.id
    WHERE a.application_type = 'consolidation' OR a.application_type IS NULL
    ORDER BY a.created_at DESC
    LIMIT 10
", [], []);

// Get required documents checklist
$required_documents = [
    'application_form' => 'Consolidation Application Form',
    'title_deeds' => 'Title Deeds / Ownership Documents for all parcels',
    'consent_forms' => 'Consent Forms from all owners',
    'survey_plan' => 'Consolidation Survey Plan',
    'id_proof' => 'ID Proof of Applicant',
    'tax_clearance' => 'Tax Clearance Certificate',
    'valuation_report' => 'Valuation Report (optional)'
];
?>

<!-- Display auto-fix messages -->
<?php if (!empty($fixMessages)): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <strong>Auto-fix completed:</strong>
    <ul class="mb-0">
        <?php foreach ($fixMessages as $fix): ?>
        <li><?php echo $fix; ?></li>
        <?php endforeach; ?>
    </ul>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<?php if (!empty($fixErrors)): ?>
<div class="alert alert-warning alert-dismissible fade show" role="alert">
    <strong>Auto-fix warnings:</strong>
    <ul class="mb-0">
        <?php foreach ($fixErrors as $error): ?>
        <li><?php echo $error; ?></li>
        <?php endforeach; ?>
    </ul>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<body data-page="applications-consolidation" class="applications-consolidation-page">
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
                            <nav aria-label="breadcrumb">
                                <ol class="breadcrumb mb-2">
                                    <li class="breadcrumb-item"><a href="applications.php">Applications</a></li>
                                    <li class="breadcrumb-item active">Land Consolidation</li>
                                </ol>
                            </nav>
                            <h1 class="h3 mb-0">Land Consolidation Application</h1>
                            <p class="text-muted mb-0">Combine multiple adjacent parcels into a single parcel</p>
                        </div>
                        <div>
                            <a href="applications.php" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-left me-2"></i>Back to Applications
                            </a>
                        </div>
                    </div>

                    <!-- Alert Message -->
                    <?php if ($message): ?>
                    <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show mb-4" role="alert">
                        <?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    <?php endif; ?>

                    <!-- Main Form -->
                    <form method="POST" id="consolidationForm" enctype="multipart/form-data">
                        
                        <!-- Progress Steps -->
                        <div class="card border-0 shadow-sm mb-4">
                            <div class="card-body">
                                <div class="progress-steps">
                                    <div class="row">
                                        <div class="col-3 step active" id="step1-indicator">
                                            <div class="step-number">1</div>
                                            <div class="step-title">Applicant</div>
                                        </div>
                                        <div class="col-3 step" id="step2-indicator">
                                            <div class="step-number">2</div>
                                            <div class="step-title">Select Parcels</div>
                                        </div>
                                        <div class="col-3 step" id="step3-indicator">
                                            <div class="step-number">3</div>
                                            <div class="step-title">New Parcel</div>
                                        </div>
                                        <div class="col-3 step" id="step4-indicator">
                                            <div class="step-number">4</div>
                                            <div class="step-title">Documents</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Step 1: Applicant Information -->
                        <div class="card border-0 shadow-sm mb-4" id="step1">
                            <div class="card-header bg-white py-3">
                                <h5 class="card-title mb-0 fw-bold">
                                    <i class="bi bi-person me-2 text-primary"></i>
                                    Step 1: Applicant Information
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-8 mb-3">
                                        <label class="form-label fw-bold">
                                            Applicant <span class="text-danger">*</span>
                                        </label>
                                        <select class="form-select" name="applicant_party_id" required>
                                            <option value="">-- Select Applicant --</option>
                                            <?php if (!empty($parties)): ?>
                                                <?php foreach ($parties as $party): ?>
                                                <option value="<?php echo $party['id']; ?>">
                                                    <?php echo htmlspecialchars($party['name']); ?> 
                                                    (<?php echo $party['party_type'] == 'individual' ? 'Individual' : 'Organization'; ?>)
                                                </option>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label fw-bold">Submission Date</label>
                                        <input type="date" class="form-control" name="submission_date" 
                                               value="<?php echo date('Y-m-d'); ?>" required>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Step 2: Source Parcels Selection -->
                        <div class="card border-0 shadow-sm mb-4" id="step2">
                            <div class="card-header bg-white py-3">
                                <h5 class="card-title mb-0 fw-bold">
                                    <i class="bi bi-grid-3x3-gap-fill me-2 text-success"></i>
                                    Step 2: Select Parcels to Consolidate
                                </h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($available_parcels)): ?>
                                <div class="alert alert-warning">
                                    <i class="bi bi-exclamation-triangle me-2"></i>
                                    No parcels available for consolidation. All parcels may have active titles.
                                </div>
                                <?php else: ?>
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">Search</label>
                                        <input type="text" class="form-control" id="parcel_search" 
                                               placeholder="Search by parcel number...">
                                    </div>
                                </div>

                                <div class="selected-parcels-summary mb-3 p-3 bg-light rounded">
                                    <h6 class="fw-bold">Selected Parcels: <span id="selected_count">0</span></h6>
                                    <div id="selected_list" class="small"></div>
                                    <div class="mt-2">
                                        <strong>Total Area: <span id="total_area">0.00</span> m²</strong>
                                    </div>
                                </div>

                                <div class="parcels-grid" style="max-height: 400px; overflow-y: auto;">
                                    <table class="table table-hover table-sm">
                                        <thead class="sticky-top bg-white">
                                            <tr>
                                                <th style="width: 50px;">Select</th>
                                                <th>Parcel #</th>
                                                <th>Area (m²)</th>
                                                <th>Location</th>
                                                <th>Owner</th>
                                            </tr>
                                        </thead>
                                        <tbody id="parcels_table_body">
                                            <?php foreach ($available_parcels as $parcel): ?>
                                            <tr class="parcel-row" 
                                                data-id="<?php echo $parcel['id']; ?>"
                                                data-number="<?php echo htmlspecialchars($parcel['parcel_number']); ?>"
                                                data-area="<?php echo $parcel['area']; ?>"
                                                data-boma="<?php echo htmlspecialchars($parcel['boma_name']); ?>"
                                                data-payam="<?php echo htmlspecialchars($parcel['payam_name']); ?>"
                                                data-county="<?php echo htmlspecialchars($parcel['county_name']); ?>"
                                                data-state="<?php echo htmlspecialchars($parcel['state_name']); ?>"
                                                data-owner="<?php echo htmlspecialchars($parcel['owner_name'] ?? 'Unknown'); ?>">
                                                <td>
                                                    <input type="checkbox" class="parcel-checkbox" 
                                                           name="source_parcels[]" value="<?php echo $parcel['id']; ?>">
                                                </td>
                                                <td><?php echo htmlspecialchars($parcel['parcel_number']); ?></td>
                                                <td><?php echo number_format($parcel['area'], 2); ?></td>
                                                <td>
                                                    <?php 
                                                    echo htmlspecialchars($parcel['boma_name'] . ', ' . $parcel['payam_name']);
                                                    ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($parcel['owner_name'] ?? 'No owner'); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <div class="mt-3">
                                    <small class="text-muted">
                                        <i class="bi bi-info-circle"></i>
                                        Select at least 2 parcels.
                                    </small>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Step 3: New Parcel Details -->
                        <div class="card border-0 shadow-sm mb-4" id="step3">
                            <div class="card-header bg-white py-3">
                                <h5 class="card-title mb-0 fw-bold">
                                    <i class="bi bi-pin-map me-2 text-warning"></i>
                                    Step 3: New Consolidated Parcel
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="form-check mb-4">
                                    <input class="form-check-input" type="checkbox" id="create_new_parcel" name="create_new_parcel" checked>
                                    <label class="form-check-label fw-bold" for="create_new_parcel">
                                        Create new parcel record for consolidated land
                                    </label>
                                </div>

                                <div id="new_parcel_fields">
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label fw-bold">
                                                New Parcel Number <span class="text-danger">*</span>
                                            </label>
                                            <div class="input-group">
                                                <input type="text" class="form-control" name="new_parcel_number" 
                                                       id="new_parcel_number" required
                                                       placeholder="e.g., CONSOLIDATED/2024/001">
                                                <button class="btn btn-outline-secondary" type="button" onclick="generateParcelNumber()">
                                                    <i class="bi bi-arrow-repeat"></i>
                                                </button>
                                            </div>
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label class="form-label fw-bold">Total Area (m²)</label>
                                            <div class="input-group">
                                                <input type="number" step="0.0001" class="form-control" 
                                                       name="new_area" id="new_area" readonly>
                                                <span class="input-group-text">m²</span>
                                            </div>
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label class="form-label fw-bold">Boma</label>
                                            <select class="form-select" name="new_boma_id" id="new_boma_id">
                                                <option value="">Select Boma (auto-detect)</option>
                                                <?php foreach ($bomas as $boma): ?>
                                                <option value="<?php echo $boma['id']; ?>">
                                                    <?php echo htmlspecialchars($boma['name'] . ' - ' . $boma['payam_name'] . ', ' . $boma['county_name']); ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label class="form-label fw-bold">Zoning Classification</label>
                                            <select class="form-select" name="new_zoning_id" id="new_zoning_id">
                                                <option value="">Select Zoning (auto-detect)</option>
                                                <?php foreach ($zoning_types as $zone): ?>
                                                <option value="<?php echo $zone['id']; ?>">
                                                    <?php echo htmlspecialchars($zone['zone_code'] . ' - ' . $zone['zone_name']); ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="col-12 mb-3">
                                            <label class="form-label fw-bold">Location Description</label>
                                            <textarea class="form-control" name="new_location_description" rows="3"
                                                      placeholder="Describe the location of the consolidated parcel..."></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Step 4: Documents -->
                        <div class="card border-0 shadow-sm mb-4" id="step4">
                            <div class="card-header bg-white py-3">
                                <h5 class="card-title mb-0 fw-bold">
                                    <i class="bi bi-file-earmark-text me-2 text-info"></i>
                                    Step 4: Supporting Documents
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="alert alert-info">
                                    <i class="bi bi-info-circle me-2"></i>
                                    Please upload all required documents. Max file size: 10MB. Accepted: PDF, JPG, PNG
                                </div>

                                <div id="document_list"></div>

                                <button type="button" class="btn btn-outline-primary mt-2" onclick="addDocumentRow()">
                                    <i class="bi bi-plus-circle me-2"></i>Add Document
                                </button>

                                <div class="mt-4">
                                    <label class="form-label fw-bold">Additional Notes</label>
                                    <textarea class="form-control" name="details" rows="4" 
                                              placeholder="Any additional information about this consolidation application..."></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- Declaration -->
                        <div class="card border-0 shadow-sm mb-4">
                            <div class="card-body">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="declaration" required>
                                    <label class="form-check-label" for="declaration">
                                        I declare that I am the owner or authorized representative of all selected parcels,
                                        and that the information provided is true and correct.
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Form Actions -->
                        <div class="d-flex justify-content-between">
                            <button type="button" class="btn btn-secondary" onclick="window.history.back()">
                                <i class="bi bi-x-circle me-2"></i>Cancel
                            </button>
                            <div>
                                <button type="button" class="btn btn-outline-primary me-2" onclick="previewApplication()">
                                    <i class="bi bi-eye me-2"></i>Preview
                                </button>
                                <button type="submit" class="btn btn-success" id="submitBtn">
                                    <i class="bi bi-check-circle me-2"></i>Submit Application
                                </button>
                            </div>
                        </div>
                    </form>

                    <!-- Recent Applications -->
                    <?php if (!empty($recent_applications)): ?>
                    <div class="card border-0 shadow-sm mt-4">
                        <div class="card-header bg-white py-3">
                            <h6 class="card-title mb-0 fw-bold">
                                <i class="bi bi-clock-history me-2"></i>Recent Consolidation Applications
                            </h6>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead>
                                        <tr>
                                            <th>App. ID</th>
                                            <th>Date</th>
                                            <th>Applicant</th>
                                            <th>Status</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recent_applications as $app): ?>
                                        <tr>
                                            <td>#<?php echo $app['id']; ?></td>
                                            <td><?php echo date('d M Y', strtotime($app['submission_date'])); ?></td>
                                            <td><?php echo htmlspecialchars($app['applicant_name']); ?></td>
                                            <td>
                                                <?php
                                                $statusClass = match($app['status']) {
                                                    'submitted' => 'primary',
                                                    'under_review' => 'info',
                                                    'approved' => 'success',
                                                    'rejected' => 'danger',
                                                    default => 'secondary'
                                                };
                                                ?>
                                                <span class="badge bg-<?php echo $statusClass; ?>">
                                                    <?php echo ucfirst(str_replace('_', ' ', $app['status'] ?? 'draft')); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <a href="applications-view.php?id=<?php echo $app['id']; ?>" 
                                                   class="btn btn-sm btn-outline-primary">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                </div>
            </main>

            <!-- Footer -->
            <?php include("footer.php"); ?>

        </div>
    </div>

    <!-- Preview Modal -->
    <div class="modal fade" id="previewModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Consolidation Application Preview</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6 class="fw-bold">Applicant</h6>
                            <p id="preview_applicant"></p>
                        </div>
                        <div class="col-md-6">
                            <h6 class="fw-bold">Submission Date</h6>
                            <p id="preview_date"></p>
                        </div>
                    </div>

                    <h6 class="fw-bold mt-3">Source Parcels</h6>
                    <div id="preview_parcels" class="mb-3"></div>

                    <h6 class="fw-bold">New Parcel</h6>
                    <table class="table table-sm">
                        <tr>
                            <th>Parcel Number:</th>
                            <td id="preview_new_number"></td>
                        </tr>
                        <tr>
                            <th>Total Area:</th>
                            <td id="preview_new_area"></td>
                        </tr>
                    </table>

                    <div id="preview_notes" style="display: none;">
                        <h6 class="fw-bold">Additional Notes</h6>
                        <p id="preview_notes_text" class="p-2 bg-light rounded"></p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script>
        let documentCount = 0;
        let selectedParcels = [];

        document.addEventListener('DOMContentLoaded', function() {
            addDocumentRow();
            
            // Parcel selection handlers
            document.querySelectorAll('.parcel-checkbox').forEach(checkbox => {
                checkbox.addEventListener('change', updateSelectedParcels);
            });

            // Search
            document.getElementById('parcel_search')?.addEventListener('keyup', filterParcels);
        });

        function filterParcels() {
            const searchTerm = document.getElementById('parcel_search')?.value.toLowerCase() || '';
            
            document.querySelectorAll('.parcel-row').forEach(row => {
                const parcelNumber = row.dataset.number.toLowerCase();
                row.style.display = parcelNumber.includes(searchTerm) ? '' : 'none';
            });
        }

        function updateSelectedParcels() {
            selectedParcels = [];
            let totalArea = 0;
            
            document.querySelectorAll('.parcel-checkbox:checked').forEach(checkbox => {
                const row = checkbox.closest('.parcel-row');
                selectedParcels.push({
                    id: row.dataset.id,
                    number: row.dataset.number,
                    area: parseFloat(row.dataset.area) || 0
                });
                totalArea += parseFloat(row.dataset.area) || 0;
            });
            
            document.getElementById('selected_count').textContent = selectedParcels.length;
            document.getElementById('total_area').textContent = totalArea.toFixed(2);
            document.getElementById('new_area').value = totalArea.toFixed(4);
            
            // Update selected list
            const list = document.getElementById('selected_list');
            if (selectedParcels.length > 0) {
                list.innerHTML = selectedParcels.map(p => p.number).join(', ');
            } else {
                list.innerHTML = 'None';
            }
            
            // Enable/disable submit based on selection
            document.getElementById('submitBtn').disabled = selectedParcels.length < 2;
        }

        function generateParcelNumber() {
            const year = new Date().getFullYear();
            const random = Math.floor(Math.random() * 9000) + 1000;
            document.getElementById('new_parcel_number').value = `CONSOLIDATED/${year}/${random}`;
        }

        function addDocumentRow() {
            const container = document.getElementById('document_list');
            const rowId = 'doc_' + Date.now() + '_' + documentCount;
            
            const row = document.createElement('div');
            row.className = 'row mb-2 document-row';
            row.id = rowId;
            
            row.innerHTML = `
                <div class="col-md-5">
                    <select class="form-select form-select-sm" name="document_types[]">
                        <option value="">Select Type</option>
                        <?php foreach ($required_documents as $key => $label): ?>
                        <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <input type="file" class="form-control form-control-sm" name="documents[]" 
                           accept=".pdf,.jpg,.jpeg,.png">
                </div>
                <div class="col-md-1">
                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeDocumentRow('${rowId}')">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            `;
            
            container.appendChild(row);
            documentCount++;
        }

        function removeDocumentRow(rowId) {
            document.getElementById(rowId).remove();
        }

        function previewApplication() {
            // Get applicant
            const applicantSelect = document.querySelector('select[name="applicant_party_id"]');
            const applicantText = applicantSelect.options[applicantSelect.selectedIndex]?.text || 'Not selected';
            document.getElementById('preview_applicant').textContent = applicantText;
            
            // Get date
            document.getElementById('preview_date').textContent = 
                document.querySelector('input[name="submission_date"]').value;
            
            // Get parcels
            const parcelsList = document.getElementById('preview_parcels');
            if (selectedParcels.length > 0) {
                parcelsList.innerHTML = '<ul>' + selectedParcels.map(p => 
                    `<li>${p.number} (${p.area.toFixed(2)} m²)</li>`
                ).join('') + '</ul>';
            } else {
                parcelsList.innerHTML = '<p class="text-muted">No parcels selected</p>';
            }
            
            // Get new parcel
            document.getElementById('preview_new_number').textContent = 
                document.getElementById('new_parcel_number').value || 'Not specified';
            document.getElementById('preview_new_area').textContent = 
                document.getElementById('new_area').value + ' m²';
            
            // Notes
            const notes = document.querySelector('textarea[name="details"]').value;
            if (notes) {
                document.getElementById('preview_notes').style.display = 'block';
                document.getElementById('preview_notes_text').textContent = notes;
            } else {
                document.getElementById('preview_notes').style.display = 'none';
            }
            
            new bootstrap.Modal(document.getElementById('previewModal')).show();
        }

        // Form validation
        document.getElementById('consolidationForm')?.addEventListener('submit', function(e) {
            if (!document.getElementById('declaration').checked) {
                e.preventDefault();
                alert('Please confirm the declaration before submitting.');
                return false;
            }
            
            if (selectedParcels.length < 2) {
                e.preventDefault();
                alert('Please select at least 2 parcels for consolidation.');
                return false;
            }
            
            return true;
        });
    </script>

    <style>
        .progress-steps {
            padding: 10px 0;
        }
        
        .step {
            text-align: center;
            position: relative;
        }
        
        .step.active .step-number {
            background-color: var(--bs-primary);
            color: white;
            border-color: var(--bs-primary);
        }
        
        .step.active .step-title {
            color: var(--bs-primary);
            font-weight: 600;
        }
        
        .step-number {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: #f8f9fa;
            border: 2px solid #dee2e6;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 10px;
            font-weight: bold;
            transition: all 0.3s;
        }
        
        .step-title {
            color: #6c757d;
            font-size: 0.9rem;
            transition: all 0.3s;
        }
        
        .step:not(:last-child):after {
            content: '';
            position: absolute;
            top: 20px;
            right: -50%;
            width: 100%;
            height: 2px;
            background-color: #dee2e6;
            z-index: -1;
        }
        
        .step.active:after {
            background-color: var(--bs-primary);
        }
        
        .parcels-grid {
            border: 1px solid #dee2e6;
            border-radius: 0.375rem;
        }
        
        .selected-parcels-summary {
            background-color: #e7f3ff;
            border-left: 4px solid var(--bs-primary);
        }
        
        .document-row {
            padding: 0.5rem;
            background-color: #f8f9fa;
            border-radius: 0.375rem;
            margin-bottom: 0.5rem !important;
        }
        
        .sticky-top {
            top: 0;
            z-index: 10;
        }
        
        @media (max-width: 768px) {
            .step:after {
                display: none;
            }
            
            .parcels-grid {
                max-height: 300px !important;
            }
        }
    </style>

</body>
</html>