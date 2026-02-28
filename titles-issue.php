<?php
ini_set('display_errors', 1); 
error_reporting(E_ALL);

// titles-issue.php
include("head.php");
require_once 'db_connection.php';

// ============================================================================
// HANDLE FORM SUBMISSION
// ============================================================================

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Validate required fields
    $errors = [];
    
    if (empty($_POST['parcel_id'])) {
        $errors[] = "Parcel selection is required";
    }
    
    if (empty($_POST['title_number'])) {
        $errors[] = "Title number is required";
    }
    
    if (empty($_POST['title_type'])) {
        $errors[] = "Title type is required";
    }
    
    if (empty($_POST['issue_date'])) {
        $errors[] = "Issue date is required";
    }
    
    if (empty($_POST['owners']) || !is_array($_POST['owners']) || count($_POST['owners']) === 0) {
        $errors[] = "At least one owner is required";
    }
    
    if (empty($errors)) {
        try {
            // Start transaction
            $conn->beginTransaction();
            
            $parcel_id = $_POST['parcel_id'];
            $title_number = $_POST['title_number'];
            $title_type = $_POST['title_type'];
            $issue_date = $_POST['issue_date'];
            $expiry_date = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
            $previous_title_id = !empty($_POST['previous_title_id']) ? $_POST['previous_title_id'] : null;
            $notes = $_POST['notes'] ?? null;
            
            // Check for duplicate title number
            $exists = fetchOne($conn, "SELECT id FROM titles WHERE title_number = ?", [$title_number]);
            if ($exists) {
                throw new Exception("Title number already exists in the system");
            }
            
            // Check if parcel already has an active title
            $active_title = fetchOne($conn, "
                SELECT id FROM titles 
                WHERE parcel_id = ? AND status = 'active'
            ", [$parcel_id]);
            
            if ($active_title && !$previous_title_id) {
                throw new Exception("Parcel already has an active title. Please use the transfer process instead.");
            }
            
            // Create new title
            executeQuery($conn, "
                INSERT INTO titles (
                    parcel_id, title_number, title_type, issue_date, expiry_date, 
                    previous_title_id, status, notes, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, 'active', ?, NOW())
            ", [$parcel_id, $title_number, $title_type, $issue_date, $expiry_date, 
                $previous_title_id, $notes]);
            
            $title_id = $conn->lastInsertId();
            
            // If this is a new title (not a transfer), deactivate any existing titles
            if (!$previous_title_id) {
                executeQuery($conn, "
                    UPDATE titles 
                    SET status = 'inactive' 
                    WHERE parcel_id = ? AND id != ?
                ", [$parcel_id, $title_id]);
            }
            
            // Add owners
            $total_share = 0;
            foreach ($_POST['owners'] as $owner) {
                if (!empty($owner['party_id']) && !empty($owner['share_percentage'])) {
                    $share = floatval($owner['share_percentage']);
                    $total_share += $share;
                    
                    // Check if total share exceeds 100%
                    if ($total_share > 100.01) { // Allow small floating point error
                        throw new Exception("Total ownership share cannot exceed 100%");
                    }
                    
                    executeQuery($conn, "
                        INSERT INTO ownerships (
                            title_id, party_id, share_percentage, ownership_start_date
                        ) VALUES (?, ?, ?, ?)
                    ", [$title_id, $owner['party_id'], $share, $issue_date]);
                }
            }
            
            // Check if total share is at least 99.99% (allow small rounding error)
            if ($total_share < 99.99) {
                throw new Exception("Total ownership share must be 100% (currently " . number_format($total_share, 2) . "%)");
            }
            
            // Log the transaction
            executeQuery($conn, "
                INSERT INTO transactions (
                    transaction_type, parcel_id, title_id, to_party_id, 
                    transaction_date, consideration_amount, details, created_by
                ) VALUES (
                    'first_registration', ?, ?, 
                    (SELECT party_id FROM ownerships WHERE title_id = ? AND share_percentage > 0 LIMIT 1),
                    ?, ?, ?, ?
                )
            ", [$parcel_id, $title_id, $title_id, $issue_date, 
                $_POST['consideration_amount'] ?? null, 
                "First registration of title " . $title_number,
                $_SESSION['user_id'] ?? 1]);
            
            // Commit transaction
            $conn->commit();
            
            $message = "Title issued successfully";
            $messageType = "success";
            
            // Redirect to view page after successful creation
            header("Location: titles-view.php?id=" . $title_id . "&success=1");
            exit;
            
        } catch (Exception $e) {
            // Rollback transaction on error
            $conn->rollBack();
            $message = "Error issuing title: " . $e->getMessage();
            $messageType = "danger";
        }
    } else {
        $message = implode("<br>", $errors);
        $messageType = "warning";
    }
}

// ============================================================================
// GET DROPDOWN DATA
// ============================================================================

// Get parcels without active titles (for new issuances)
$available_parcels = fetchAll($conn, "
    SELECT 
        p.id,
        p.parcel_number,
        p.survey_number,
        p.area,
        p.location_description,
        b.name as boma_name,
        py.name as payam_name,
        c.name as county_name,
        s.name as state_name,
        (SELECT COUNT(*) FROM titles WHERE parcel_id = p.id) as title_count,
        (SELECT id FROM titles WHERE parcel_id = p.id AND status = 'active') as active_title_id
    FROM parcels p
    LEFT JOIN bomas b ON p.boma_id = b.id
    LEFT JOIN payams py ON b.payam_id = py.id
    LEFT JOIN counties c ON py.county_id = c.id
    LEFT JOIN states s ON c.state_id = s.id
    WHERE (SELECT COUNT(*) FROM titles WHERE parcel_id = p.id AND status = 'active') = 0
    ORDER BY p.created_at DESC
");

// Get parcels with active titles (for transfers/re-issuance)
$active_titles = fetchAll($conn, "
    SELECT 
        t.id as title_id,
        t.title_number,
        t.title_type,
        t.issue_date,
        t.expiry_date,
        p.id as parcel_id,
        p.parcel_number,
        p.area,
        b.name as boma_name,
        (SELECT GROUP_CONCAT(CONCAT(part.name, ' (', o.share_percentage, '%)') SEPARATOR ', ')
         FROM ownerships o 
         JOIN parties part ON o.party_id = part.id
         WHERE o.title_id = t.id AND o.ownership_end_date IS NULL) as current_owners
    FROM titles t
    JOIN parcels p ON t.parcel_id = p.id
    LEFT JOIN bomas b ON p.boma_id = b.id
    WHERE t.status = 'active'
    ORDER BY t.issue_date DESC
");

// Get parties for owner selection
$parties = fetchAll($conn, "
    SELECT 
        id, 
        party_type, 
        name, 
        national_id, 
        registration_number,
        phone,
        email
    FROM parties 
    ORDER BY name
");

// Get recent title numbers for reference
$recent_titles = fetchAll($conn, "
    SELECT title_number, issue_date, parcel_id 
    FROM titles 
    ORDER BY created_at DESC 
    LIMIT 5
");

// Generate next title number suggestion
$last_title = fetchOne($conn, "
    SELECT title_number FROM titles ORDER BY id DESC LIMIT 1
");

if ($last_title) {
    // Extract the number part and increment
    preg_match('/(\d+)$/', $last_title['title_number'], $matches);
    $last_num = isset($matches[1]) ? intval($matches[1]) : 0;
    $next_title_number = "TITLE/" . date('Y') . "/" . str_pad($last_num + 1, 4, '0', STR_PAD_LEFT);
} else {
    $next_title_number = "TITLE/" . date('Y') . "/0001";
}

// Get title types for statistics
$title_stats = fetchAll($conn, "
    SELECT 
        title_type,
        COUNT(*) as count
    FROM titles
    GROUP BY title_type
");
?>

<!-- Google Maps API for parcel preview -->
<script src="https://maps.googleapis.com/maps/api/js?key=YOUR_API_KEY&libraries=geometry" async defer></script>

<body data-page="titles-issue" class="titles-issue-page">
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
                                    <li class="breadcrumb-item"><a href="titles.php">Titles</a></li>
                                    <li class="breadcrumb-item active">Issue New Title</li>
                                </ol>
                            </nav>
                            <h1 class="h3 mb-0">Issue New Land Title</h1>
                            <p class="text-muted mb-0">Register a new title for a land parcel</p>
                        </div>
                        <div class="btn-group">
                            <a href="titles.php" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-left me-2"></i>Back to Titles
                            </a>
                            <a href="titles-transfer.php" class="btn btn-outline-primary">
                                <i class="bi bi-arrow-left-right me-2"></i>Transfer Title
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

                    <!-- Quick Stats -->
                    <div class="row g-4 mb-4">
                        <div class="col-md-3">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0 me-3">
                                            <div class="bg-primary bg-opacity-10 rounded-circle p-3">
                                                <i class="bi bi-file-text text-primary fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Available Parcels</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo count($available_parcels); ?></h3>
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
                                                <i class="bi bi-tags text-success fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Active Titles</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo count($active_titles); ?></h3>
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
                                                <i class="bi bi-person text-info fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Registered Parties</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo count($parties); ?></h3>
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
                                                <i class="bi bi-calendar text-warning fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Next Title #</h6>
                                            <h6 class="mb-0 fw-bold text-truncate"><?php echo $next_title_number; ?></h6>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Title Type Distribution -->
                    <?php if (!empty($title_stats)): ?>
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h6 class="card-title mb-0 fw-bold">
                                        <i class="bi bi-pie-chart me-2"></i>Title Type Distribution
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <?php foreach ($title_stats as $stat): ?>
                                        <div class="col-md-4 mb-2">
                                            <div class="border rounded p-2">
                                                <small class="text-muted"><?php echo ucfirst($stat['title_type']); ?></small>
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <span class="fw-bold"><?php echo $stat['count']; ?> titles</span>
                                                    <div class="progress flex-grow-1 ms-3" style="height: 8px;">
                                                        <?php 
                                                        $total_titles = array_sum(array_column($title_stats, 'count'));
                                                        $percentage = ($stat['count'] / $total_titles) * 100;
                                                        ?>
                                                        <div class="progress-bar bg-<?php 
                                                            echo $stat['title_type'] == 'freehold' ? 'success' : 
                                                                ($stat['title_type'] == 'leasehold' ? 'info' : 'warning'); 
                                                        ?>" style="width: <?php echo $percentage; ?>%"></div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Main Form -->
                    <form method="POST" id="titleForm" class="needs-validation" novalidate>
                        
                        <!-- Parcel Selection -->
                        <div class="card border-0 shadow-sm mb-4">
                            <div class="card-header bg-white py-3">
                                <h5 class="card-title mb-0 fw-bold">
                                    <i class="bi bi-pin-map me-2 text-primary"></i>
                                    Step 1: Select Parcel
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-8 mb-3">
                                        <label class="form-label fw-bold">
                                            Select Parcel <span class="text-danger">*</span>
                                        </label>
                                        <select class="form-select" name="parcel_id" id="parcel_select" required>
                                            <option value="">-- Choose a parcel --</option>
                                            <optgroup label="Available Parcels (No Active Title)">
                                                <?php foreach ($available_parcels as $parcel): ?>
                                                <option value="<?php echo $parcel['id']; ?>" 
                                                        data-area="<?php echo $parcel['area']; ?>"
                                                        data-location="<?php echo htmlspecialchars($parcel['location_description']); ?>"
                                                        data-boma="<?php echo htmlspecialchars($parcel['boma_name']); ?>"
                                                        data-payam="<?php echo htmlspecialchars($parcel['payam_name']); ?>"
                                                        data-county="<?php echo htmlspecialchars($parcel['county_name']); ?>"
                                                        data-state="<?php echo htmlspecialchars($parcel['state_name']); ?>"
                                                        <?php echo ($_POST['parcel_id'] ?? '') == $parcel['id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($parcel['parcel_number']); ?> - 
                                                    <?php echo htmlspecialchars($parcel['boma_name'] ?? 'Unknown'); ?> 
                                                    (<?php echo number_format($parcel['area'] ?? 0, 2); ?> m²)
                                                </option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                            <optgroup label="Transfer from Existing Title">
                                                <?php foreach ($active_titles as $title): ?>
                                                <option value="<?php echo $title['parcel_id']; ?>" 
                                                        data-title-id="<?php echo $title['title_id']; ?>"
                                                        data-title-number="<?php echo $title['title_number']; ?>"
                                                        data-title-type="<?php echo $title['title_type']; ?>"
                                                        data-owners="<?php echo htmlspecialchars($title['current_owners']); ?>">
                                                    <?php echo htmlspecialchars($title['parcel_number']); ?> - 
                                                    Current: <?php echo htmlspecialchars($title['title_number']); ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                        </select>
                                        <small class="text-muted">Select a parcel that doesn't have an active title, or choose an existing title for transfer/re-issuance</small>
                                    </div>
                                    
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label fw-bold">Previous Title (for transfers)</label>
                                        <input type="text" class="form-control" name="previous_title_id" id="previous_title_id" readonly>
                                        <small class="text-muted">Automatically filled when selecting a parcel with existing title</small>
                                    </div>
                                </div>
                                
                                <!-- Parcel Preview -->
                                <div id="parcel_preview" style="display: none;" class="mt-3 p-3 bg-light rounded">
                                    <h6 class="fw-bold mb-3">Selected Parcel Details</h6>
                                    <div class="row">
                                        <div class="col-md-3">
                                            <small class="text-muted d-block">Area</small>
                                            <span id="preview_area" class="fw-bold">0 m²</span>
                                        </div>
                                        <div class="col-md-3">
                                            <small class="text-muted d-block">Location</small>
                                            <span id="preview_location" class="fw-bold">-</span>
                                        </div>
                                        <div class="col-md-3">
                                            <small class="text-muted d-block">Administrative Area</small>
                                            <span id="preview_admin" class="fw-bold">-</span>
                                        </div>
                                        <div class="col-md-3">
                                            <small class="text-muted d-block">Current Title</small>
                                            <span id="preview_current_title" class="fw-bold">None</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Title Details -->
                        <div class="card border-0 shadow-sm mb-4">
                            <div class="card-header bg-white py-3">
                                <h5 class="card-title mb-0 fw-bold">
                                    <i class="bi bi-file-text me-2 text-success"></i>
                                    Step 2: Title Details
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <!-- Title Number -->
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label fw-bold">
                                            Title Number <span class="text-danger">*</span>
                                        </label>
                                        <div class="input-group">
                                            <input type="text" class="form-control" name="title_number" 
                                                   id="title_number"
                                                   value="<?php echo htmlspecialchars($_POST['title_number'] ?? $next_title_number); ?>" 
                                                   required placeholder="e.g., TITLE/2024/0001">
                                            <button class="btn btn-outline-secondary" type="button" onclick="generateTitleNumber()">
                                                <i class="bi bi-arrow-repeat"></i>
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <!-- Title Type -->
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label fw-bold">
                                            Title Type <span class="text-danger">*</span>
                                        </label>
                                        <select class="form-select" name="title_type" id="title_type" required>
                                            <option value="">Select Type</option>
                                            <option value="freehold" <?php echo ($_POST['title_type'] ?? '') == 'freehold' ? 'selected' : ''; ?>>
                                                Freehold - Perpetual ownership
                                            </option>
                                            <option value="leasehold" <?php echo ($_POST['title_type'] ?? '') == 'leasehold' ? 'selected' : ''; ?>>
                                                Leasehold - Fixed term
                                            </option>
                                            <option value="customary" <?php echo ($_POST['title_type'] ?? '') == 'customary' ? 'selected' : ''; ?>>
                                                Customary - Traditional ownership
                                            </option>
                                        </select>
                                    </div>
                                    
                                    <!-- Issue Date -->
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label fw-bold">
                                            Issue Date <span class="text-danger">*</span>
                                        </label>
                                        <input type="date" class="form-control" name="issue_date" 
                                               value="<?php echo htmlspecialchars($_POST['issue_date'] ?? date('Y-m-d')); ?>" 
                                               required>
                                    </div>
                                    
                                    <!-- Expiry Date (for leasehold) -->
                                    <div class="col-md-4 mb-3" id="expiry_date_container" style="display: none;">
                                        <label class="form-label fw-bold">Expiry Date</label>
                                        <input type="date" class="form-control" name="expiry_date" 
                                               id="expiry_date"
                                               value="<?php echo htmlspecialchars($_POST['expiry_date'] ?? ''); ?>">
                                        <small class="text-muted">Required for leasehold titles</small>
                                    </div>
                                    
                                    <!-- Consideration Amount (Optional) -->
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label fw-bold">Consideration Amount (Optional)</label>
                                        <div class="input-group">
                                            <span class="input-group-text">SSP</span>
                                            <input type="number" step="0.01" class="form-control" 
                                                   name="consideration_amount"
                                                   value="<?php echo htmlspecialchars($_POST['consideration_amount'] ?? ''); ?>"
                                                   placeholder="0.00">
                                        </div>
                                        <small class="text-muted">Value paid for the title (if any)</small>
                                    </div>
                                    
                                    <!-- Notes -->
                                    <div class="col-12 mb-3">
                                        <label class="form-label fw-bold">Additional Notes</label>
                                        <textarea class="form-control" name="notes" rows="2" 
                                                  placeholder="Any additional information about this title issuance..."><?php echo htmlspecialchars($_POST['notes'] ?? ''); ?></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Owners Information -->
                        <div class="card border-0 shadow-sm mb-4">
                            <div class="card-header bg-white py-3">
                                <h5 class="card-title mb-0 fw-bold">
                                    <i class="bi bi-people me-2 text-info"></i>
                                    Step 3: Owners <span class="text-danger">*</span>
                                </h5>
                            </div>
                            <div class="card-body">
                                <div id="owners_container">
                                    <!-- Owner rows will be added here -->
                                </div>
                                
                                <div class="row mt-3">
                                    <div class="col-12">
                                        <button type="button" class="btn btn-outline-primary" onclick="addOwnerRow()">
                                            <i class="bi bi-plus-circle me-2"></i>Add Another Owner
                                        </button>
                                        <button type="button" class="btn btn-outline-success ms-2" onclick="openPartyModal()">
                                            <i class="bi bi-person-plus me-2"></i>Create New Party
                                        </button>
                                    </div>
                                </div>
                                
                                <!-- Share Summary -->
                                <div class="row mt-4">
                                    <div class="col-md-6">
                                        <div class="border rounded p-3">
                                            <h6 class="fw-bold mb-3">Ownership Summary</h6>
                                            <div class="d-flex justify-content-between mb-2">
                                                <span>Total Allocated Share:</span>
                                                <span id="total_share" class="fw-bold">0%</span>
                                            </div>
                                            <div class="progress mb-2" style="height: 20px;">
                                                <div id="share_progress" class="progress-bar bg-success" 
                                                     role="progressbar" style="width: 0%;" 
                                                     aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">
                                                    0%
                                                </div>
                                            </div>
                                            <div id="share_warning" class="text-danger small" style="display: none;">
                                                <i class="bi bi-exclamation-triangle"></i> Total share must equal 100%
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="border rounded p-3">
                                            <h6 class="fw-bold mb-3">Quick Owner Stats</h6>
                                            <div class="row">
                                                <div class="col-6">
                                                    <small class="text-muted d-block">Individual Owners</small>
                                                    <span id="individual_count" class="h5">0</span>
                                                </div>
                                                <div class="col-6">
                                                    <small class="text-muted d-block">Organizations</small>
                                                    <span id="organization_count" class="h5">0</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Form Actions -->
                        <div class="d-flex justify-content-between">
                            <button type="button" class="btn btn-secondary" onclick="window.history.back()">
                                <i class="bi bi-x-circle me-2"></i>Cancel
                            </button>
                            <div>
                                <button type="button" class="btn btn-outline-primary me-2" onclick="previewTitle()">
                                    <i class="bi bi-eye me-2"></i>Preview
                                </button>
                                <button type="submit" class="btn btn-success" id="submitBtn">
                                    <i class="bi bi-check-circle me-2"></i>Issue Title
                                </button>
                            </div>
                        </div>
                    </form>

                    <!-- Recent Titles Reference -->
                    <?php if (!empty($recent_titles)): ?>
                    <div class="card border-0 shadow-sm mt-4">
                        <div class="card-header bg-white py-3">
                            <h6 class="card-title mb-0 fw-bold">Recently Issued Titles</h6>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <?php foreach ($recent_titles as $title): ?>
                                <div class="col-md-2 mb-2">
                                    <div class="border rounded p-2 text-center">
                                        <code><?php echo htmlspecialchars($title['title_number']); ?></code>
                                        <br><small class="text-muted"><?php echo date('d/m/Y', strtotime($title['issue_date'])); ?></small>
                                    </div>
                                </div>
                                <?php endforeach; ?>
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
                    <h5 class="modal-title">Title Issuance Preview</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6 class="fw-bold">Title Information</h6>
                            <table class="table table-sm">
                                <tr>
                                    <th>Title Number:</th>
                                    <td id="preview_title_number"></td>
                                </tr>
                                <tr>
                                    <th>Title Type:</th>
                                    <td id="preview_title_type"></td>
                                </tr>
                                <tr>
                                    <th>Issue Date:</th>
                                    <td id="preview_issue_date"></td>
                                </tr>
                                <tr>
                                    <th>Expiry Date:</th>
                                    <td id="preview_expiry_date"></td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <h6 class="fw-bold">Parcel Information</h6>
                            <table class="table table-sm">
                                <tr>
                                    <th>Parcel Number:</th>
                                    <td id="preview_parcel_number"></td>
                                </tr>
                                <tr>
                                    <th>Area:</th>
                                    <td id="preview_area"></td>
                                </tr>
                                <tr>
                                    <th>Location:</th>
                                    <td id="preview_location"></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    
                    <h6 class="fw-bold mt-3">Owners</h6>
                    <table class="table table-sm" id="preview_owners_table">
                        <thead>
                            <tr>
                                <th>Owner Name</th>
                                <th>Type</th>
                                <th>Share</th>
                            </tr>
                        </thead>
                        <tbody id="preview_owners_body">
                        </tbody>
                    </table>
                    
                    <div id="preview_notes" class="mt-3 p-2 bg-light rounded" style="display: none;">
                        <small class="text-muted">Notes:</small>
                        <p id="preview_notes_text" class="mb-0"></p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Create Party Modal -->
    <div class="modal fade" id="partyModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Create New Party</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="quickPartyForm">
                        <div class="mb-3">
                            <label class="form-label">Party Type</label>
                            <select class="form-select" id="quick_party_type">
                                <option value="individual">Individual</option>
                                <option value="organization">Organization</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Full Name</label>
                            <input type="text" class="form-control" id="quick_party_name" required>
                        </div>
                        <div class="mb-3" id="quick_national_id_container">
                            <label class="form-label">National ID</label>
                            <input type="text" class="form-control" id="quick_national_id">
                        </div>
                        <div class="mb-3" id="quick_reg_number_container" style="display: none;">
                            <label class="form-label">Registration Number</label>
                            <input type="text" class="form-control" id="quick_reg_number">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Phone</label>
                            <input type="text" class="form-control" id="quick_phone">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" id="quick_email">
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="saveQuickParty()">Save Party</button>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script>
        // Global variables
        let ownerCount = 0;
        const parties = <?php echo json_encode($parties); ?>;
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            // Add first owner row
            addOwnerRow();
            
            // Setup parcel select listener
            document.getElementById('parcel_select').addEventListener('change', updateParcelPreview);
            
            // Setup title type listener for expiry date
            document.getElementById('title_type').addEventListener('change', function() {
                const container = document.getElementById('expiry_date_container');
                const expiryInput = document.getElementById('expiry_date');
                
                if (this.value === 'leasehold') {
                    container.style.display = 'block';
                    expiryInput.required = true;
                    
                    // Set default expiry date (e.g., 99 years from now)
                    if (!expiryInput.value) {
                        const date = new Date();
                        date.setFullYear(date.getFullYear() + 99);
                        expiryInput.value = date.toISOString().split('T')[0];
                    }
                } else {
                    container.style.display = 'none';
                    expiryInput.required = false;
                }
            });
            
            // Load saved form data if any
            <?php if (!empty($_POST)): ?>
            // Restore owner rows from POST data
            <?php if (isset($_POST['owners']) && is_array($_POST['owners'])): ?>
                <?php foreach ($_POST['owners'] as $index => $owner): ?>
                    <?php if ($index > 0): ?>
                        addOwnerRow();
                    <?php endif; ?>
                    setTimeout(() => {
                        const row = document.querySelectorAll('.owner-row')[<?php echo $index; ?>];
                        if (row) {
                            const select = row.querySelector('.owner-select');
                            const share = row.querySelector('.owner-share');
                            if (select) select.value = '<?php echo $owner['party_id']; ?>';
                            if (share) share.value = '<?php echo $owner['share_percentage']; ?>';
                        }
                        updateShareTotal();
                    }, 100);
                <?php endforeach; ?>
            <?php endif; ?>
            <?php endif; ?>
            
            // Initialize tooltips
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[title]'));
            tooltipTriggerList.map(function(tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
        });

        // Add owner row
        function addOwnerRow() {
            const container = document.getElementById('owners_container');
            const rowId = 'owner_' + Date.now() + '_' + ownerCount;
            
            const row = document.createElement('div');
            row.className = 'row owner-row mb-3 align-items-end';
            row.id = rowId;
            
            row.innerHTML = `
                <div class="col-md-6">
                    <label class="form-label">Select Owner</label>
                    <select class="form-select owner-select" name="owners[${ownerCount}][party_id]" onchange="updateOwnerStats()">
                        <option value="">-- Choose an owner --</option>
                        ${generateOwnerOptions()}
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Share (%)</label>
                    <input type="number" step="0.01" class="form-control owner-share" 
                           name="owners[${ownerCount}][share_percentage]" 
                           onchange="updateShareTotal()" onkeyup="updateShareTotal()"
                           min="0.01" max="100" value="">
                </div>
                <div class="col-md-3">
                    <button type="button" class="btn btn-outline-danger" onclick="removeOwnerRow('${rowId}')">
                        <i class="bi bi-trash"></i> Remove
                    </button>
                </div>
            `;
            
            container.appendChild(row);
            ownerCount++;
        }

        // Generate owner options HTML
        function generateOwnerOptions() {
            let html = '';
            parties.forEach(party => {
                const type = party.party_type === 'individual' ? 'Individual' : 'Organization';
                const id = party.national_id || party.registration_number || '';
                html += `<option value="${party.id}">${party.name} (${type}) - ${id}</option>`;
            });
            return html;
        }

        // Remove owner row
        function removeOwnerRow(rowId) {
            document.getElementById(rowId).remove();
            updateShareTotal();
            updateOwnerStats();
        }

        // Update share total and progress bar
        function updateShareTotal() {
            const shares = document.querySelectorAll('.owner-share');
            let total = 0;
            
            shares.forEach(input => {
                const value = parseFloat(input.value);
                if (!isNaN(value)) {
                    total += value;
                }
            });
            
            document.getElementById('total_share').textContent = total.toFixed(2) + '%';
            
            const progressBar = document.getElementById('share_progress');
            const warning = document.getElementById('share_warning');
            
            progressBar.style.width = Math.min(total, 100) + '%';
            progressBar.textContent = total.toFixed(1) + '%';
            
            if (Math.abs(total - 100) < 0.01) {
                progressBar.classList.remove('bg-warning', 'bg-danger');
                progressBar.classList.add('bg-success');
                warning.style.display = 'none';
                document.getElementById('submitBtn').disabled = false;
            } else if (total > 100) {
                progressBar.classList.remove('bg-success', 'bg-warning');
                progressBar.classList.add('bg-danger');
                warning.style.display = 'block';
                warning.textContent = '❌ Total share exceeds 100% by ' + (total - 100).toFixed(2) + '%';
                document.getElementById('submitBtn').disabled = true;
            } else {
                progressBar.classList.remove('bg-success', 'bg-danger');
                progressBar.classList.add('bg-warning');
                warning.style.display = 'block';
                warning.textContent = '⚠️ Total share must equal 100% (currently ' + total.toFixed(2) + '%)';
                document.getElementById('submitBtn').disabled = true;
            }
        }

        // Update owner statistics
        function updateOwnerStats() {
            const selects = document.querySelectorAll('.owner-select');
            let individual = 0;
            let organization = 0;
            
            selects.forEach(select => {
                const partyId = select.value;
                if (partyId) {
                    const party = parties.find(p => p.id == partyId);
                    if (party) {
                        if (party.party_type === 'individual') {
                            individual++;
                        } else {
                            organization++;
                        }
                    }
                }
            });
            
            document.getElementById('individual_count').textContent = individual;
            document.getElementById('organization_count').textContent = organization;
        }

        // Update parcel preview
        function updateParcelPreview() {
            const select = document.getElementById('parcel_select');
            const selected = select.options[select.selectedIndex];
            const preview = document.getElementById('parcel_preview');
            
            if (selected.value) {
                preview.style.display = 'block';
                
                // Update preview fields
                document.getElementById('preview_area').textContent = 
                    (selected.dataset.area ? parseFloat(selected.dataset.area).toFixed(2) : '0') + ' m²';
                document.getElementById('preview_location').textContent = 
                    selected.dataset.location || '-';
                document.getElementById('preview_admin').textContent = 
                    selected.dataset.boma + ', ' + selected.dataset.payam + ', ' + selected.dataset.county;
                
                // Check if this is a transfer
                if (selected.dataset.titleId) {
                    document.getElementById('previous_title_id').value = selected.dataset.titleId;
                    document.getElementById('preview_current_title').innerHTML = 
                        `<span class="text-warning">${selected.dataset.titleNumber} (${selected.dataset.titleType})</span>`;
                    
                    // Auto-fill title number suggestion for transfer
                    const titleNumber = document.getElementById('title_number');
                    if (!titleNumber.value || titleNumber.value === '<?php echo $next_title_number; ?>') {
                        const base = selected.dataset.titleNumber.replace(/\d+$/, '');
                        const num = parseInt(selected.dataset.titleNumber.match(/\d+$/)[0]) + 1;
                        titleNumber.value = base + num.toString().padStart(4, '0');
                    }
                } else {
                    document.getElementById('previous_title_id').value = '';
                    document.getElementById('preview_current_title').innerHTML = 
                        '<span class="text-success">None (New Issuance)</span>';
                }
            } else {
                preview.style.display = 'none';
            }
        }

        // Generate title number
        function generateTitleNumber() {
            const year = new Date().getFullYear();
            const random = Math.floor(Math.random() * 9000) + 1000;
            document.getElementById('title_number').value = `TITLE/${year}/${random}`;
        }

        // Open party modal
        function openPartyModal() {
            new bootstrap.Modal(document.getElementById('partyModal')).show();
        }

        // Save quick party
        function saveQuickParty() {
            const type = document.getElementById('quick_party_type').value;
            const name = document.getElementById('quick_party_name').value;
            
            if (!name) {
                alert('Please enter a name');
                return;
            }
            
            // In a real implementation, this would save via AJAX
            // For now, we'll just show a message
            alert('Party creation would be handled via AJAX in production. Redirecting to parties page...');
            window.open('parties-create.php', '_blank');
            
            // Close modal
            bootstrap.Modal.getInstance(document.getElementById('partyModal')).hide();
        }

        // Preview title
        function previewTitle() {
            // Get form values
            document.getElementById('preview_title_number').textContent = 
                document.getElementById('title_number').value || 'Not set';
            
            const titleType = document.getElementById('title_type');
            document.getElementById('preview_title_type').textContent = 
                titleType.options[titleType.selectedIndex]?.text || 'Not set';
            
            document.getElementById('preview_issue_date').textContent = 
                document.querySelector('input[name="issue_date"]').value || 'Not set';
            
            const expiryDate = document.getElementById('expiry_date');
            document.getElementById('preview_expiry_date').textContent = 
                expiryDate.value || 'N/A';
            
            // Get parcel info
            const parcelSelect = document.getElementById('parcel_select');
            const selectedParcel = parcelSelect.options[parcelSelect.selectedIndex];
            
            if (selectedParcel && selectedParcel.value) {
                document.getElementById('preview_parcel_number').textContent = 
                    selectedParcel.text.split(' - ')[0] || 'Not set';
                document.getElementById('preview_area').textContent = 
                    (selectedParcel.dataset.area ? parseFloat(selectedParcel.dataset.area).toFixed(2) : '0') + ' m²';
                document.getElementById('preview_location').textContent = 
                    selectedParcel.dataset.location || 'Not set';
            }
            
            // Get owners
            const ownersBody = document.getElementById('preview_owners_body');
            ownersBody.innerHTML = '';
            
            const rows = document.querySelectorAll('.owner-row');
            rows.forEach(row => {
                const select = row.querySelector('.owner-select');
                const share = row.querySelector('.owner-share');
                
                if (select.value && share.value) {
                    const party = parties.find(p => p.id == select.value);
                    if (party) {
                        const tr = document.createElement('tr');
                        tr.innerHTML = `
                            <td>${party.name}</td>
                            <td>${party.party_type === 'individual' ? 'Individual' : 'Organization'}</td>
                            <td>${parseFloat(share.value).toFixed(2)}%</td>
                        `;
                        ownersBody.appendChild(tr);
                    }
                }
            });
            
            // Notes
            const notes = document.querySelector('textarea[name="notes"]').value;
            if (notes) {
                document.getElementById('preview_notes').style.display = 'block';
                document.getElementById('preview_notes_text').textContent = notes;
            } else {
                document.getElementById('preview_notes').style.display = 'none';
            }
            
            // Show modal
            new bootstrap.Modal(document.getElementById('previewModal')).show();
        }

        // Form validation
        document.getElementById('titleForm').addEventListener('submit', function(e) {
            const totalShare = parseFloat(document.getElementById('total_share').textContent);
            
            if (Math.abs(totalShare - 100) > 0.01) {
                e.preventDefault();
                alert('Total ownership share must equal 100%');
                return false;
            }
            
            return true;
        });

        // Toggle party type fields
        document.getElementById('quick_party_type').addEventListener('change', function() {
            const type = this.value;
            const individualContainer = document.getElementById('quick_national_id_container');
            const orgContainer = document.getElementById('quick_reg_number_container');
            
            if (type === 'individual') {
                individualContainer.style.display = 'block';
                orgContainer.style.display = 'none';
            } else {
                individualContainer.style.display = 'none';
                orgContainer.style.display = 'block';
            }
        });
    </script>

    <style>
        .breadcrumb {
            background: transparent;
            padding: 0;
        }
        
        .breadcrumb-item a {
            color: var(--bs-primary);
            text-decoration: none;
        }
        
        .breadcrumb-item a:hover {
            text-decoration: underline;
        }
        
        .owner-row {
            padding: 1rem;
            background-color: #f8f9fa;
            border-radius: 0.5rem;
            margin-bottom: 1rem !important;
        }
        
        .table-sm th {
            width: 40%;
            color: #6c757d;
            font-weight: 500;
        }
        
        #share_progress {
            transition: width 0.3s ease;
        }
        
        #share_warning {
            font-size: 0.9rem;
            margin-top: 0.5rem;
        }
        
        .progress {
            background-color: #e9ecef;
        }
        
        .card {
            transition: all 0.3s;
        }
        
        .card:hover {
            box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.15) !important;
        }
        
        @media (max-width: 768px) {
            .owner-row .col-md-3,
            .owner-row .col-md-6 {
                margin-bottom: 0.5rem;
            }
        }
    </style>

</body>
</html>