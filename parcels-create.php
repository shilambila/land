<?php
ini_set('display_errors', 1); 
error_reporting(E_ALL);

// parcels-create.php
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
    
    if (empty($_POST['parcel_number'])) {
        $errors[] = "Parcel number is required";
    }
    
    if (empty($_POST['boma_id'])) {
        $errors[] = "Boma selection is required";
    }
    
    if (empty($_POST['geometry'])) {
        $errors[] = "Please draw the parcel boundary on the map";
    }
    
    if (empty($errors)) {
        try {
            // Start transaction
            $conn->beginTransaction();
            
            $parcel_number = $_POST['parcel_number'];
            $survey_number = $_POST['survey_number'] ?? null;
            $area = !empty($_POST['area']) ? $_POST['area'] : null;
            $location_description = $_POST['location_description'] ?? null;
            $geometry = $_POST['geometry']; // GeoJSON from map drawing
            $boma_id = $_POST['boma_id'];
            $current_zoning_id = !empty($_POST['current_zoning_id']) ? $_POST['current_zoning_id'] : null;
            
            // Check for duplicate parcel number
            $exists = fetchOne($conn, "SELECT id FROM parcels WHERE parcel_number = ?", [$parcel_number]);
            if ($exists) {
                throw new Exception("Parcel number already exists in the system");
            }
            
            // Convert GeoJSON to MySQL geometry
            // Note: You'll need to parse the GeoJSON and convert to MySQL geometry format
            // This is a simplified version - you may need to adjust based on your geometry storage
            $geometry_sql = "ST_GeomFromGeoJSON(?)";
            
            // Insert parcel
            executeQuery($conn, "
                INSERT INTO parcels (
                    parcel_number, survey_number, area, location_description, 
                    geometry, boma_id, current_zoning_id
                ) VALUES (?, ?, ?, ?, $geometry_sql, ?, ?)
            ", [$parcel_number, $survey_number, $area, $location_description, 
                $geometry, $boma_id, $current_zoning_id]);
            
            $parcel_id = $conn->lastInsertId();
            
            // If title information is provided
            if (!empty($_POST['create_title']) && $_POST['create_title'] === 'yes') {
                
                $title_number = $_POST['title_number'] ?? null;
                $title_type = $_POST['title_type'] ?? null;
                $issue_date = $_POST['issue_date'] ?? date('Y-m-d');
                $expiry_date = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
                
                if ($title_number && $title_type) {
                    // Check for duplicate title number
                    $title_exists = fetchOne($conn, "SELECT id FROM titles WHERE title_number = ?", [$title_number]);
                    if ($title_exists) {
                        throw new Exception("Title number already exists in the system");
                    }
                    
                    // Create title
                    executeQuery($conn, "
                        INSERT INTO titles (
                            parcel_id, title_number, title_type, issue_date, expiry_date, status
                        ) VALUES (?, ?, ?, ?, ?, 'active')
                    ", [$parcel_id, $title_number, $title_type, $issue_date, $expiry_date]);
                    
                    $title_id = $conn->lastInsertId();
                    
                    // If owner information is provided
                    if (!empty($_POST['owner_party_id'])) {
                        $owner_party_id = $_POST['owner_party_id'];
                        $share_percentage = !empty($_POST['share_percentage']) ? $_POST['share_percentage'] : 100.00;
                        $ownership_start_date = $_POST['ownership_start_date'] ?? date('Y-m-d');
                        
                        // Create ownership record
                        executeQuery($conn, "
                            INSERT INTO ownerships (
                                title_id, party_id, share_percentage, ownership_start_date
                            ) VALUES (?, ?, ?, ?)
                        ", [$title_id, $owner_party_id, $share_percentage, $ownership_start_date]);
                    }
                }
            }
            
            // Commit transaction
            $conn->commit();
            
            $message = "Parcel created successfully";
            $messageType = "success";
            
            // Redirect to view page after successful creation
            header("Location: parcels-view.php?id=" . $parcel_id . "&success=1");
            exit;
            
        } catch (Exception $e) {
            // Rollback transaction on error
            $conn->rollBack();
            $message = "Error creating parcel: " . $e->getMessage();
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

// Get all states for hierarchical dropdowns
$states = fetchAll($conn, "SELECT id, name, code FROM states ORDER BY name");

// Get all counties with their state info
$counties = fetchAll($conn, "
    SELECT c.id, c.name, c.code, c.state_id, s.name as state_name 
    FROM counties c
    LEFT JOIN states s ON c.state_id = s.id
    ORDER BY s.name, c.name
");

// Get all payams with their hierarchy
$payams = fetchAll($conn, "
    SELECT p.id, p.name, p.code, p.county_id, c.name as county_name, c.state_id, s.name as state_name
    FROM payams p
    LEFT JOIN counties c ON p.county_id = c.id
    LEFT JOIN states s ON c.state_id = s.id
    ORDER BY s.name, c.name, p.name
");

// Get all bomas with full hierarchy
$bomas = fetchAll($conn, "
    SELECT 
        b.id, b.name, b.code, b.payam_id,
        p.name as payam_name, p.county_id,
        c.name as county_name, c.state_id,
        s.name as state_name
    FROM bomas b
    LEFT JOIN payams p ON b.payam_id = p.id
    LEFT JOIN counties c ON p.county_id = c.id
    LEFT JOIN states s ON c.state_id = s.id
    ORDER BY s.name, c.name, p.name, b.name
");

// Get zoning types
$zoning_types = fetchAll($conn, "SELECT id, zone_code, zone_name, description FROM zoning ORDER BY zone_code");

// Get parties for owner selection
$parties = fetchAll($conn, "
    SELECT id, party_type, name, national_id, registration_number 
    FROM parties 
    ORDER BY name
");

// Get recent parcel numbers for reference
$recent_parcels = fetchAll($conn, "
    SELECT parcel_number, created_at 
    FROM parcels 
    ORDER BY created_at DESC 
    LIMIT 5
");

// Generate next parcel number suggestion
$last_parcel = fetchOne($conn, "
    SELECT parcel_number FROM parcels ORDER BY id DESC LIMIT 1
");
$next_parcel_number = "PARCEL/" . date('Y') . "/" . str_pad(($last_parcel ? intval(substr($last_parcel['parcel_number'], -3)) + 1 : 1), 3, '0', STR_PAD_LEFT);
?>

<!-- Google Maps API with Drawing library -->
<script src="https://maps.googleapis.com/maps/api/js?key=YOUR_API_KEY&libraries=drawing,geometry&callback=initMap" async defer></script>

<body data-page="parcels-create" class="parcels-create-page">
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
                                    <li class="breadcrumb-item"><a href="parcels.php">Parcels</a></li>
                                    <li class="breadcrumb-item active">Create New Parcel</li>
                                </ol>
                            </nav>
                            <h1 class="h3 mb-0">Create New Land Parcel</h1>
                            <p class="text-muted mb-0">Define parcel boundaries and register ownership information</p>
                        </div>
                        <a href="parcels.php" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left me-2"></i>Back to Parcels
                        </a>
                    </div>

                    <!-- Alert Message -->
                    <?php if ($message): ?>
                    <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show mb-4" role="alert">
                        <?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    <?php endif; ?>

                    <!-- Main Form -->
                    <form method="POST" id="parcelForm" enctype="multipart/form-data">
                        
                        <!-- Progress Steps -->
                        <div class="card border-0 shadow-sm mb-4">
                            <div class="card-body">
                                <div class="progress-steps">
                                    <div class="row">
                                        <div class="col-4 step active" id="step1-indicator">
                                            <div class="step-number">1</div>
                                            <div class="step-title">Parcel Details</div>
                                        </div>
                                        <div class="col-4 step" id="step2-indicator">
                                            <div class="step-number">2</div>
                                            <div class="step-title">Draw Boundary</div>
                                        </div>
                                        <div class="col-4 step" id="step3-indicator">
                                            <div class="step-number">3</div>
                                            <div class="step-title">Title & Ownership</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Step 1: Parcel Details -->
                        <div class="card border-0 shadow-sm mb-4" id="step1">
                            <div class="card-header bg-white py-3">
                                <h5 class="card-title mb-0 fw-bold">
                                    <i class="bi bi-info-circle me-2 text-primary"></i>
                                    Step 1: Parcel Information
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <!-- Parcel Number -->
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label fw-bold">
                                            Parcel Number <span class="text-danger">*</span>
                                        </label>
                                        <div class="input-group">
                                            <input type="text" class="form-control" name="parcel_number" 
                                                   value="<?php echo htmlspecialchars($_POST['parcel_number'] ?? $next_parcel_number); ?>" 
                                                   required placeholder="e.g., PARCEL/2024/001">
                                            <button class="btn btn-outline-secondary" type="button" onclick="generateParcelNumber()">
                                                <i class="bi bi-arrow-repeat"></i> Generate
                                            </button>
                                        </div>
                                        <small class="text-muted">Unique identifier for the parcel</small>
                                    </div>
                                    
                                    <!-- Survey Number -->
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label fw-bold">Survey Number</label>
                                        <input type="text" class="form-control" name="survey_number" 
                                               value="<?php echo htmlspecialchars($_POST['survey_number'] ?? ''); ?>"
                                               placeholder="e.g., SURV/2024/001">
                                        <small class="text-muted">Reference to survey document</small>
                                    </div>
                                    
                                    <!-- Area -->
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label fw-bold">Area (sq meters)</label>
                                        <div class="input-group">
                                            <input type="number" step="0.0001" class="form-control" name="area" 
                                                   id="area_input"
                                                   value="<?php echo htmlspecialchars($_POST['area'] ?? ''); ?>"
                                                   placeholder="0.0000">
                                            <span class="input-group-text">m²</span>
                                            <button class="btn btn-outline-secondary" type="button" onclick="calculateArea()" id="calcAreaBtn">
                                                <i class="bi bi-calculator"></i> Calculate from Map
                                            </button>
                                        </div>
                                        <small class="text-muted">Can be calculated automatically from drawn boundary</small>
                                    </div>
                                    
                                    <!-- Zoning -->
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label fw-bold">Zoning Classification</label>
                                        <select class="form-select" name="current_zoning_id">
                                            <option value="">Select Zoning Type</option>
                                            <?php foreach ($zoning_types as $zoning): ?>
                                            <option value="<?php echo $zoning['id']; ?>" 
                                                <?php echo ($_POST['current_zoning_id'] ?? '') == $zoning['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($zoning['zone_code'] . ' - ' . $zoning['zone_name']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <!-- Location Description -->
                                    <div class="col-12 mb-3">
                                        <label class="form-label fw-bold">Location Description</label>
                                        <textarea class="form-control" name="location_description" rows="3" 
                                                  placeholder="Describe the location, landmarks, boundaries..."><?php echo htmlspecialchars($_POST['location_description'] ?? ''); ?></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Step 2: Administrative Location & Map Drawing -->
                        <div class="card border-0 shadow-sm mb-4" id="step2">
                            <div class="card-header bg-white py-3">
                                <h5 class="card-title mb-0 fw-bold">
                                    <i class="bi bi-pin-map me-2 text-success"></i>
                                    Step 2: Administrative Location & Boundary Drawing
                                </h5>
                            </div>
                            <div class="card-body">
                                <!-- Hierarchical Location Selection -->
                                <div class="row mb-4">
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label fw-bold">State</label>
                                        <select class="form-select" id="state_select">
                                            <option value="">Select State</option>
                                            <?php foreach ($states as $state): ?>
                                            <option value="<?php echo $state['id']; ?>">
                                                <?php echo htmlspecialchars($state['name']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label fw-bold">County</label>
                                        <select class="form-select" id="county_select" disabled>
                                            <option value="">Select County</option>
                                        </select>
                                    </div>
                                    
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label fw-bold">Payam</label>
                                        <select class="form-select" id="payam_select" disabled>
                                            <option value="">Select Payam</option>
                                        </select>
                                    </div>
                                    
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label fw-bold">
                                            Boma <span class="text-danger">*</span>
                                        </label>
                                        <select class="form-select" name="boma_id" id="boma_select" required disabled>
                                            <option value="">Select Boma</option>
                                        </select>
                                    </div>
                                </div>

                                <!-- Map Drawing Tools -->
                                <div class="row">
                                    <div class="col-12">
                                        <div class="d-flex justify-content-between align-items-center mb-3">
                                            <label class="form-label fw-bold mb-0">
                                                <i class="bi bi-bounding-box-circles me-2"></i>
                                                Draw Parcel Boundary <span class="text-danger">*</span>
                                            </label>
                                            <div class="btn-group btn-group-sm">
                                                <button type="button" class="btn btn-outline-primary" onclick="startDrawing()" id="drawBtn">
                                                    <i class="bi bi-pencil"></i> Draw
                                                </button>
                                                <button type="button" class="btn btn-outline-danger" onclick="clearDrawing()" id="clearBtn">
                                                    <i class="bi bi-trash"></i> Clear
                                                </button>
                                                <button type="button" class="btn btn-outline-success" onclick="centerMapOnSouthSudan()">
                                                    <i class="bi bi-arrows-fullscreen"></i> Reset
                                                </button>
                                            </div>
                                        </div>
                                        <div id="parcel-map" style="height: 500px; width: 100%;" class="border rounded"></div>
                                        <input type="hidden" name="geometry" id="geometry_input">
                                        <small class="text-muted mt-2 d-block">
                                            <i class="bi bi-info-circle"></i> 
                                            Click on the map to draw the parcel boundary. Double-click to complete the polygon.
                                        </small>
                                    </div>
                                </div>

                                <!-- Quick Coordinates Input -->
                                <div class="row mt-3">
                                    <div class="col-12">
                                        <button class="btn btn-link p-0" type="button" data-bs-toggle="collapse" data-bs-target="#coordinatesInput">
                                            <i class="bi bi-code-square"></i> Or enter coordinates manually
                                        </button>
                                        <div class="collapse mt-2" id="coordinatesInput">
                                            <label class="form-label">GeoJSON Coordinates</label>
                                            <textarea class="form-control font-monospace" rows="4" id="manual_geometry" 
                                                      placeholder='{"type":"Polygon","coordinates":[[[30.0,7.5],[30.1,7.5],[30.1,7.6],[30.0,7.6],[30.0,7.5]]]}'></textarea>
                                            <button type="button" class="btn btn-sm btn-secondary mt-2" onclick="loadManualGeometry()">
                                                Load to Map
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Step 3: Title & Ownership -->
                        <div class="card border-0 shadow-sm mb-4" id="step3">
                            <div class="card-header bg-white py-3">
                                <h5 class="card-title mb-0 fw-bold">
                                    <i class="bi bi-file-text me-2 text-info"></i>
                                    Step 3: Title and Ownership (Optional)
                                </h5>
                            </div>
                            <div class="card-body">
                                <!-- Create Title Toggle -->
                                <div class="form-check mb-4">
                                    <input class="form-check-input" type="checkbox" id="create_title_check" name="create_title" value="yes">
                                    <label class="form-check-label fw-bold" for="create_title_check">
                                        Create title for this parcel
                                    </label>
                                    <small class="text-muted d-block">Check this if you want to create a title immediately</small>
                                </div>

                                <div id="title_fields" style="display: none;">
                                    <div class="row">
                                        <!-- Title Number -->
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label fw-bold">Title Number</label>
                                            <input type="text" class="form-control" name="title_number" 
                                                   value="<?php echo htmlspecialchars($_POST['title_number'] ?? ''); ?>"
                                                   placeholder="e.g., TITLE/2024/001">
                                            <small class="text-muted">Leave blank to auto-generate</small>
                                        </div>
                                        
                                        <!-- Title Type -->
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label fw-bold">Title Type</label>
                                            <select class="form-select" name="title_type">
                                                <option value="">Select Type</option>
                                                <option value="freehold">Freehold</option>
                                                <option value="leasehold">Leasehold</option>
                                                <option value="customary">Customary</option>
                                            </select>
                                        </div>
                                        
                                        <!-- Issue Date -->
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label fw-bold">Issue Date</label>
                                            <input type="date" class="form-control" name="issue_date" 
                                                   value="<?php echo date('Y-m-d'); ?>">
                                        </div>
                                        
                                        <!-- Expiry Date (for leasehold) -->
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label fw-bold">Expiry Date</label>
                                            <input type="date" class="form-control" name="expiry_date">
                                            <small class="text-muted">Required only for leasehold</small>
                                        </div>
                                    </div>

                                    <!-- Owner Information -->
                                    <div class="border-top pt-3 mt-2">
                                        <h6 class="fw-bold mb-3">
                                            <i class="bi bi-person me-2"></i>
                                            Owner Information
                                        </h6>
                                        
                                        <div class="row">
                                            <div class="col-md-8 mb-3">
                                                <label class="form-label fw-bold">Select Owner</label>
                                                <select class="form-select" name="owner_party_id" id="owner_select">
                                                    <option value="">Select Owner</option>
                                                    <?php foreach ($parties as $party): ?>
                                                    <option value="<?php echo $party['id']; ?>">
                                                        <?php echo htmlspecialchars($party['name']); ?> 
                                                        (<?php echo $party['party_type'] == 'individual' ? 'Individual' : 'Organization'; ?>)
                                                        <?php if ($party['national_id'] || $party['registration_number']): ?>
                                                            - <?php echo htmlspecialchars($party['national_id'] ?? $party['registration_number']); ?>
                                                        <?php endif; ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-4 mb-3">
                                                <label class="form-label fw-bold">Share Percentage</label>
                                                <div class="input-group">
                                                    <input type="number" step="0.01" class="form-control" name="share_percentage" value="100.00" min="0.01" max="100">
                                                    <span class="input-group-text">%</span>
                                                </div>
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label fw-bold">Ownership Start Date</label>
                                                <input type="date" class="form-control" name="ownership_start_date" 
                                                       value="<?php echo date('Y-m-d'); ?>">
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label fw-bold">Or Create New Party</label>
                                                <button type="button" class="btn btn-outline-primary w-100" onclick="window.open('parties-create.php', '_blank')">
                                                    <i class="bi bi-person-plus"></i> Add New Party
                                                </button>
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
                                <button type="button" class="btn btn-outline-primary me-2" id="previewBtn" onclick="previewParcel()">
                                    <i class="bi bi-eye me-2"></i>Preview
                                </button>
                                <button type="submit" class="btn btn-success" id="submitBtn">
                                    <i class="bi bi-check-circle me-2"></i>Create Parcel
                                </button>
                            </div>
                        </div>
                    </form>

                    <!-- Recent Parcel Numbers Reference -->
                    <?php if (!empty($recent_parcels)): ?>
                    <div class="card border-0 shadow-sm mt-4">
                        <div class="card-header bg-white py-3">
                            <h5 class="card-title mb-0 fw-bold">Recent Parcel Numbers</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <?php foreach ($recent_parcels as $parcel): ?>
                                <div class="col-md-2 mb-2">
                                    <div class="border rounded p-2 text-center">
                                        <code><?php echo htmlspecialchars($parcel['parcel_number']); ?></code>
                                        <br><small class="text-muted"><?php echo date('d/m/Y', strtotime($parcel['created_at'])); ?></small>
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
                    <h5 class="modal-title">Parcel Preview</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6 class="fw-bold">Parcel Details</h6>
                            <table class="table table-sm">
                                <tr>
                                    <th>Parcel Number:</th>
                                    <td id="preview_parcel_number"></td>
                                </tr>
                                <tr>
                                    <th>Survey Number:</th>
                                    <td id="preview_survey_number"></td>
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
                        <div class="col-md-6">
                            <h6 class="fw-bold">Administrative Location</h6>
                            <table class="table table-sm">
                                <tr>
                                    <th>State:</th>
                                    <td id="preview_state"></td>
                                </tr>
                                <tr>
                                    <th>County:</th>
                                    <td id="preview_county"></td>
                                </tr>
                                <tr>
                                    <th>Payam:</th>
                                    <td id="preview_payam"></td>
                                </tr>
                                <tr>
                                    <th>Boma:</th>
                                    <td id="preview_boma"></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Preview Map -->
                    <div id="preview-map" style="height: 300px; width: 100%;" class="mt-3"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript for Google Maps and Drawing -->
    <script>
        // Global variables
        let map;
        let drawingManager;
        let selectedShape = null;
        let markers = [];
        
        // Hierarchical data
        const counties = <?php echo json_encode($counties); ?>;
        const payams = <?php echo json_encode($payams); ?>;
        const bomas = <?php echo json_encode($bomas); ?>;
        
        // South Sudan bounds
        const SOUTH_SUDAN_BOUNDS = {
            north: 12.0,
            south: 3.5,
            west: 23.5,
            east: 36.0
        };
        
        const SOUTH_SUDAN_CENTER = { lat: 7.5, lng: 30.0 };

        // Initialize map
        function initMap() {
            // Create main map
            map = new google.maps.Map(document.getElementById('parcel-map'), {
                center: SOUTH_SUDAN_CENTER,
                zoom: 6,
                restriction: {
                    latLngBounds: SOUTH_SUDAN_BOUNDS,
                    strictBounds: false
                },
                mapTypeId: google.maps.MapTypeId.SATELLITE,
                mapTypeControl: true,
                streetViewControl: false,
                fullscreenControl: true
            });

            // Initialize drawing manager
            drawingManager = new google.maps.drawing.DrawingManager({
                drawingMode: null,
                drawingControl: true,
                drawingControlOptions: {
                    position: google.maps.ControlPosition.TOP_CENTER,
                    drawingModes: ['polygon']
                },
                polygonOptions: {
                    fillColor: '#2196F3',
                    fillOpacity: 0.3,
                    strokeColor: '#0D47A1',
                    strokeWeight: 2,
                    editable: true,
                    draggable: true
                }
            });

            drawingManager.setMap(map);

            // Add drawing listeners
            google.maps.event.addListener(drawingManager, 'polygoncomplete', function(polygon) {
                // Remove previous shape if exists
                if (selectedShape) {
                    selectedShape.setMap(null);
                }
                
                selectedShape = polygon;
                
                // Add edit listeners
                google.maps.event.addListener(polygon.getPath(), 'insert_at', function() {
                    updateGeometry();
                });
                google.maps.event.addListener(polygon.getPath(), 'set_at', function() {
                    updateGeometry();
                });
                
                // Update geometry input
                updateGeometry();
                
                // Calculate area
                calculateAreaFromPolygon(polygon);
                
                // Disable drawing mode
                drawingManager.setDrawingMode(null);
            });

            // Load existing boundaries if any
            loadExistingBoundaries();
        }

        // Update geometry input from drawn polygon
        function updateGeometry() {
            if (selectedShape) {
                const paths = [];
                const vertices = selectedShape.getPath();
                
                for (let i = 0; i < vertices.getLength(); i++) {
                    const xy = vertices.getAt(i);
                    paths.push([xy.lng(), xy.lat()]);
                }
                
                // Close the polygon
                if (paths.length > 0) {
                    paths.push(paths[0]);
                }
                
                const geojson = {
                    type: "Polygon",
                    coordinates: [paths]
                };
                
                document.getElementById('geometry_input').value = JSON.stringify(geojson);
            }
        }

        // Calculate area from polygon
        function calculateAreaFromPolygon(polygon) {
            if (polygon) {
                const area = google.maps.geometry.spherical.computeArea(polygon.getPath());
                document.getElementById('area_input').value = area.toFixed(2);
            }
        }

        // Calculate area button click
        function calculateArea() {
            if (selectedShape) {
                calculateAreaFromPolygon(selectedShape);
            } else {
                alert('Please draw a polygon first.');
            }
        }

        // Start drawing
        function startDrawing() {
            drawingManager.setDrawingMode(google.maps.drawing.OverlayType.POLYGON);
        }

        // Clear drawing
        function clearDrawing() {
            if (selectedShape) {
                selectedShape.setMap(null);
                selectedShape = null;
                document.getElementById('geometry_input').value = '';
                document.getElementById('area_input').value = '';
            }
        }

        // Load manual geometry input
        function loadManualGeometry() {
            const geojsonText = document.getElementById('manual_geometry').value;
            try {
                const geojson = JSON.parse(geojsonText);
                
                if (geojson.type === 'Polygon') {
                    const paths = geojson.coordinates[0].map(coord => {
                        return { lat: coord[1], lng: coord[0] };
                    });
                    
                    // Remove last point if it's duplicate (closing the polygon)
                    if (paths.length > 0 && 
                        paths[0].lat === paths[paths.length-1].lat && 
                        paths[0].lng === paths[paths.length-1].lng) {
                        paths.pop();
                    }
                    
                    const polygon = new google.maps.Polygon({
                        paths: paths,
                        fillColor: '#2196F3',
                        fillOpacity: 0.3,
                        strokeColor: '#0D47A1',
                        strokeWeight: 2,
                        editable: true,
                        draggable: true,
                        map: map
                    });
                    
                    // Remove previous shape
                    if (selectedShape) {
                        selectedShape.setMap(null);
                    }
                    
                    selectedShape = polygon;
                    
                    // Add edit listeners
                    google.maps.event.addListener(polygon.getPath(), 'insert_at', function() {
                        updateGeometry();
                    });
                    google.maps.event.addListener(polygon.getPath(), 'set_at', function() {
                        updateGeometry();
                    });
                    
                    // Update geometry input
                    updateGeometry();
                    
                    // Calculate area
                    calculateAreaFromPolygon(polygon);
                    
                    // Fit map to polygon
                    const bounds = new google.maps.LatLngBounds();
                    paths.forEach(path => bounds.extend(path));
                    map.fitBounds(bounds);
                }
            } catch (e) {
                alert('Invalid GeoJSON format: ' + e.message);
            }
        }

        // Load existing boundaries (if editing)
        function loadExistingBoundaries() {
            <?php if (!empty($_POST['geometry'])): ?>
            setTimeout(() => {
                document.getElementById('manual_geometry').value = '<?php echo addslashes($_POST['geometry']); ?>';
                loadManualGeometry();
            }, 1000);
            <?php endif; ?>
        }

        // Generate parcel number
        function generateParcelNumber() {
            const year = new Date().getFullYear();
            const random = Math.floor(Math.random() * 900) + 100;
            document.querySelector('input[name="parcel_number"]').value = `PARCEL/${year}/${random}`;
        }

        // Center map on South Sudan
        function centerMapOnSouthSudan() {
            map.setCenter(SOUTH_SUDAN_CENTER);
            map.setZoom(6);
        }

        // Hierarchical dropdowns
        document.getElementById('state_select').addEventListener('change', function() {
            const stateId = this.value;
            const countySelect = document.getElementById('county_select');
            const payamSelect = document.getElementById('payam_select');
            const bomaSelect = document.getElementById('boma_select');
            
            // Reset and disable downstream selects
            countySelect.innerHTML = '<option value="">Select County</option>';
            payamSelect.innerHTML = '<option value="">Select Payam</option>';
            bomaSelect.innerHTML = '<option value="">Select Boma</option>';
            
            if (stateId) {
                // Filter counties by state
                const filteredCounties = counties.filter(c => c.state_id == stateId);
                filteredCounties.forEach(county => {
                    const option = document.createElement('option');
                    option.value = county.id;
                    option.textContent = county.name;
                    countySelect.appendChild(option);
                });
                countySelect.disabled = false;
                payamSelect.disabled = true;
                bomaSelect.disabled = true;
            } else {
                countySelect.disabled = true;
                payamSelect.disabled = true;
                bomaSelect.disabled = true;
            }
        });

        document.getElementById('county_select').addEventListener('change', function() {
            const countyId = this.value;
            const payamSelect = document.getElementById('payam_select');
            const bomaSelect = document.getElementById('boma_select');
            
            payamSelect.innerHTML = '<option value="">Select Payam</option>';
            bomaSelect.innerHTML = '<option value="">Select Boma</option>';
            
            if (countyId) {
                const filteredPayams = payams.filter(p => p.county_id == countyId);
                filteredPayams.forEach(payam => {
                    const option = document.createElement('option');
                    option.value = payam.id;
                    option.textContent = payam.name;
                    payamSelect.appendChild(option);
                });
                payamSelect.disabled = false;
                bomaSelect.disabled = true;
            } else {
                payamSelect.disabled = true;
                bomaSelect.disabled = true;
            }
        });

        document.getElementById('payam_select').addEventListener('change', function() {
            const payamId = this.value;
            const bomaSelect = document.getElementById('boma_select');
            
            bomaSelect.innerHTML = '<option value="">Select Boma</option>';
            
            if (payamId) {
                const filteredBomas = bomas.filter(b => b.payam_id == payamId);
                filteredBomas.forEach(boma => {
                    const option = document.createElement('option');
                    option.value = boma.id;
                    option.textContent = boma.name;
                    bomaSelect.appendChild(option);
                });
                bomaSelect.disabled = false;
            } else {
                bomaSelect.disabled = true;
            }
        });

        // Toggle title fields
        document.getElementById('create_title_check').addEventListener('change', function() {
            document.getElementById('title_fields').style.display = this.checked ? 'block' : 'none';
        });

        // Preview function
        function previewParcel() {
            // Get form values
            document.getElementById('preview_parcel_number').textContent = 
                document.querySelector('input[name="parcel_number"]').value || 'Not set';
            document.getElementById('preview_survey_number').textContent = 
                document.querySelector('input[name="survey_number"]').value || 'Not set';
            document.getElementById('preview_area').textContent = 
                document.getElementById('area_input').value ? document.getElementById('area_input').value + ' m²' : 'Not set';
            document.getElementById('preview_location').textContent = 
                document.querySelector('textarea[name="location_description"]').value || 'Not set';
            
            // Get selected location names
            const stateSelect = document.getElementById('state_select');
            const countySelect = document.getElementById('county_select');
            const payamSelect = document.getElementById('payam_select');
            const bomaSelect = document.getElementById('boma_select');
            
            document.getElementById('preview_state').textContent = 
                stateSelect.options[stateSelect.selectedIndex]?.text || 'Not selected';
            document.getElementById('preview_county').textContent = 
                countySelect.options[countySelect.selectedIndex]?.text || 'Not selected';
            document.getElementById('preview_payam').textContent = 
                payamSelect.options[payamSelect.selectedIndex]?.text || 'Not selected';
            document.getElementById('preview_boma').textContent = 
                bomaSelect.options[bomaSelect.selectedIndex]?.text || 'Not selected';
            
            // Show preview map
            if (selectedShape) {
                const bounds = new google.maps.LatLngBounds();
                selectedShape.getPath().forEach(path => bounds.extend(path));
                
                const previewMap = new google.maps.Map(document.getElementById('preview-map'), {
                    center: bounds.getCenter(),
                    zoom: 15,
                    disableDefaultUI: true
                });
                
                // Copy polygon to preview
                const paths = [];
                selectedShape.getPath().forEach(path => {
                    paths.push(path);
                });
                
                new google.maps.Polygon({
                    paths: paths,
                    fillColor: '#2196F3',
                    fillOpacity: 0.3,
                    strokeColor: '#0D47A1',
                    strokeWeight: 2,
                    map: previewMap
                });
                
                previewMap.fitBounds(bounds);
            }
            
            // Show modal
            new bootstrap.Modal(document.getElementById('previewModal')).show();
        }

        // Form validation
        document.getElementById('parcelForm').addEventListener('submit', function(e) {
            if (!document.getElementById('geometry_input').value) {
                e.preventDefault();
                alert('Please draw the parcel boundary on the map.');
                return false;
            }
            
            if (!document.getElementById('boma_select').value) {
                e.preventDefault();
                alert('Please select a boma.');
                return false;
            }
            
            return true;
        });

        // Initialize tooltips
        document.addEventListener('DOMContentLoaded', function() {
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[title]'));
            tooltipTriggerList.map(function(tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
            
            // Show title fields if checkbox was checked
            <?php if (!empty($_POST['create_title'])): ?>
            document.getElementById('create_title_check').checked = true;
            document.getElementById('title_fields').style.display = 'block';
            <?php endif; ?>
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
        
        #parcel-map {
            border-radius: 0.375rem;
        }
        
        .table-sm th {
            width: 40%;
            color: #6c757d;
            font-weight: 500;
        }
        
        .table-sm td {
            font-weight: 500;
        }
        
        .font-monospace {
            font-family: 'Courier New', monospace;
        }
        
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
        
        @media (max-width: 768px) {
            .step:after {
                display: none;
            }
            
            #parcel-map {
                height: 300px !important;
            }
        }
    </style>

</body>
</html>