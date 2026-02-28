<?php
ini_set('display_errors', 1); 
error_reporting(E_ALL);

// applications-new-title.php
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
    
    if (empty($_POST['applicant_party_id'])) {
        $errors[] = "Applicant is required";
    }
    
    if (empty($_POST['parcel_id'])) {
        $errors[] = "Parcel selection is required";
    }
    
    if (empty($_POST['submission_date'])) {
        $errors[] = "Submission date is required";
    }
    
    if (empty($errors)) {
        try {
            // Start transaction
            $conn->beginTransaction();
            
            $applicant_party_id = $_POST['applicant_party_id'];
            $parcel_id = $_POST['parcel_id'];
            $submission_date = $_POST['submission_date'];
            $details = $_POST['details'] ?? null;
            $status = $_POST['status'] ?? 'submitted';
            
            // Check if parcel already has an active title
            $activeTitle = fetchOne($conn, "
                SELECT id FROM titles 
                WHERE parcel_id = ? AND status = 'active'
            ", [$parcel_id]);
            
            if ($activeTitle) {
                throw new Exception("Selected parcel already has an active title. Please use transfer application instead.");
            }
            
            // Check if there's already a pending application for this parcel
            $pendingApp = fetchOne($conn, "
                SELECT id FROM applications 
                WHERE parcel_id = ? AND status IN ('submitted', 'under_review')
            ", [$parcel_id]);
            
            if ($pendingApp) {
                throw new Exception("There is already a pending application for this parcel.");
            }
            
            // Insert application
            executeQuery($conn, "
                INSERT INTO applications (
                    application_type, parcel_id, applicant_party_id, 
                    submission_date, status, details, created_at
                ) VALUES (
                    'new_title', ?, ?, ?, ?, ?, NOW()
                )
            ", [$parcel_id, $applicant_party_id, $submission_date, $status, $details]);
            
            $application_id = $conn->lastInsertId();
            
            // Create initial workflow steps
            $workflow_steps = [
                ['name' => 'Document Verification', 'assigned_role' => 'clerk', 'order' => 1],
                ['name' => 'Site Inspection', 'assigned_role' => 'surveyor', 'order' => 2],
                ['name' => 'Title Processing', 'assigned_role' => 'clerk', 'order' => 3],
                ['name' => 'Approval', 'assigned_role' => 'public_officer', 'order' => 4]
            ];
            
            foreach ($workflow_steps as $step) {
                executeQuery($conn, "
                    INSERT INTO workflow_steps (
                        application_id, step_name, assigned_to, status, 
                        due_date, created_at
                    ) VALUES (
                        ?, ?, 
                        (SELECT id FROM users WHERE role = ? LIMIT 1),
                        'pending', 
                        DATE_ADD(?, INTERVAL 7 DAY),
                        NOW()
                    )
                ", [$application_id, $step['name'], $step['assigned_role'], $submission_date]);
            }
            
            // If supporting documents were uploaded, create document records
            if (!empty($_FILES['documents']['name'][0])) {
                $upload_dir = 'uploads/applications/' . $application_id . '/';
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
                            executeQuery($conn, "
                                INSERT INTO documents (
                                    document_type, parcel_id, party_id,
                                    file_name, file_path, mime_type, 
                                    uploaded_by, uploaded_at, description
                                ) VALUES (
                                    ?, ?, ?, ?, ?, ?, ?, NOW(), ?
                                )
                            ", [
                                $document_types[$i] ?? 'application_document',
                                $parcel_id,
                                $applicant_party_id,
                                $file_name,
                                $file_path,
                                $files['type'][$i],
                                $_SESSION['user_id'] ?? 1,
                                'Supporting document for new title application #' . $application_id
                            ]);
                        }
                    }
                }
            }
            
            $conn->commit();
            
            $message = "New title application submitted successfully. Application ID: " . $application_id;
            $messageType = "success";
            
            // Redirect to view page after successful submission
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
// GET DROPDOWN DATA
// ============================================================================

// Get all parties (individuals and organizations)
$parties = fetchAll($conn, "
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
");

// Get parcels without active titles (available for new title applications)
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
        z.zone_code,
        z.zone_name,
        -- Check if any application is pending
        (SELECT COUNT(*) FROM applications a 
         WHERE a.parcel_id = p.id 
         AND a.status IN ('submitted', 'under_review')) as pending_applications
    FROM parcels p
    JOIN bomas b ON p.boma_id = b.id
    JOIN payams py ON b.payam_id = py.id
    JOIN counties c ON py.county_id = c.id
    JOIN states s ON c.state_id = s.id
    LEFT JOIN zoning z ON p.current_zoning_id = z.id
    WHERE NOT EXISTS (
        SELECT 1 FROM titles t 
        WHERE t.parcel_id = p.id AND t.status = 'active'
    )
    ORDER BY p.created_at DESC
");

// Get required documents checklist
$required_documents = [
    'application_form' => 'Completed Application Form',
    'id_proof' => 'Copy of National ID/Passport',
    'survey_plan' => 'Survey Plan / Site Plan',
    'ownership_proof' => 'Proof of Ownership/Customary Rights',
    'tax_receipt' => 'Tax Clearance Certificate',
    'consent_letter' => 'Consent Letter (if applicable)'
];

// Get recent applications for reference
$recent_applications = fetchAll($conn, "
    SELECT 
        a.id,
        a.application_type,
        a.submission_date,
        a.status,
        p.parcel_number,
        pa.name as applicant_name
    FROM applications a
    JOIN parties pa ON a.applicant_party_id = pa.id
    JOIN parcels p ON a.parcel_id = p.id
    WHERE a.application_type = 'new_title'
    ORDER BY a.created_at DESC
    LIMIT 5
");

// Get staff members for assignment
$staff_members = fetchAll($conn, "
    SELECT id, username, role 
    FROM users 
    WHERE is_active = 1 
    ORDER BY role, username
");

// Get surveyors for dropdown
$surveyors = fetchAll($conn, "
    SELECT id, username 
    FROM users 
    WHERE role = 'surveyor' AND is_active = 1
    ORDER BY username
");
?>

<body data-page="applications-new-title" class="applications-new-title-page">
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
                                    <li class="breadcrumb-item active">New Title Application</li>
                                </ol>
                            </nav>
                            <h1 class="h3 mb-0">New Title Application</h1>
                            <p class="text-muted mb-0">Apply for first registration of a land title</p>
                        </div>
                        <div class="btn-group">
                            <a href="applications.php" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-left me-2"></i>Back to Applications
                            </a>
                            <a href="applications-transfer.php" class="btn btn-outline-primary">
                                <i class="bi bi-arrow-left-right me-2"></i>Transfer Application
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
                    <form method="POST" id="applicationForm" enctype="multipart/form-data">
                        
                        <!-- Progress Steps -->
                        <div class="card border-0 shadow-sm mb-4">
                            <div class="card-body">
                                <div class="progress-steps">
                                    <div class="row">
                                        <div class="col-4 step active" id="step1-indicator">
                                            <div class="step-number">1</div>
                                            <div class="step-title">Applicant Info</div>
                                        </div>
                                        <div class="col-4 step" id="step2-indicator">
                                            <div class="step-number">2</div>
                                            <div class="step-title">Parcel Details</div>
                                        </div>
                                        <div class="col-4 step" id="step3-indicator">
                                            <div class="step-number">3</div>
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
                                            Select Applicant <span class="text-danger">*</span>
                                        </label>
                                        <select class="form-select" name="applicant_party_id" id="applicant_select" required>
                                            <option value="">-- Choose an applicant --</option>
                                            <?php foreach ($parties as $party): ?>
                                            <option value="<?php echo $party['id']; ?>" 
                                                    data-type="<?php echo $party['party_type']; ?>"
                                                    data-id="<?php echo $party['national_id'] ?? $party['registration_number']; ?>"
                                                    data-phone="<?php echo $party['phone']; ?>"
                                                    data-email="<?php echo $party['email']; ?>">
                                                <?php echo htmlspecialchars($party['name']); ?> 
                                                (<?php echo $party['party_type'] == 'individual' ? 'Individual' : 'Organization'; ?>)
                                                <?php if ($party['national_id']): ?>
                                                - ID: <?php echo htmlspecialchars($party['national_id']); ?>
                                                <?php endif; ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="text-muted">Select the person or organization applying for the title</small>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label fw-bold">Or Create New</label>
                                        <button type="button" class="btn btn-outline-success w-100" onclick="openPartyModal()">
                                            <i class="bi bi-person-plus me-2"></i>Add New Party
                                        </button>
                                    </div>
                                </div>

                                <!-- Applicant Preview -->
                                <div id="applicant_preview" class="mt-3 p-3 bg-light rounded" style="display: none;">
                                    <h6 class="fw-bold mb-3">Selected Applicant Details</h6>
                                    <div class="row">
                                        <div class="col-md-3">
                                            <small class="text-muted d-block">Name</small>
                                            <span id="preview_name" class="fw-bold">-</span>
                                        </div>
                                        <div class="col-md-3">
                                            <small class="text-muted d-block">ID/Registration</small>
                                            <span id="preview_id" class="fw-bold">-</span>
                                        </div>
                                        <div class="col-md-3">
                                            <small class="text-muted d-block">Phone</small>
                                            <span id="preview_phone" class="fw-bold">-</span>
                                        </div>
                                        <div class="col-md-3">
                                            <small class="text-muted d-block">Email</small>
                                            <span id="preview_email" class="fw-bold">-</span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Submission Date -->
                                <div class="row mt-3">
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label fw-bold">
                                            Submission Date <span class="text-danger">*</span>
                                        </label>
                                        <input type="date" class="form-control" name="submission_date" 
                                               value="<?php echo date('Y-m-d'); ?>" required>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label fw-bold">Application Status</label>
                                        <select class="form-select" name="status">
                                            <option value="submitted">Submitted</option>
                                            <option value="draft">Save as Draft</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Step 2: Parcel Selection -->
                        <div class="card border-0 shadow-sm mb-4" id="step2">
                            <div class="card-header bg-white py-3">
                                <h5 class="card-title mb-0 fw-bold">
                                    <i class="bi bi-pin-map me-2 text-success"></i>
                                    Step 2: Select Parcel
                                </h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($available_parcels)): ?>
                                <div class="alert alert-warning">
                                    <i class="bi bi-exclamation-triangle me-2"></i>
                                    No available parcels found. All parcels already have active titles or pending applications.
                                    <a href="parcels-create.php" class="alert-link">Create a new parcel</a> first.
                                </div>
                                <?php else: ?>
                                <div class="row">
                                    <div class="col-md-12 mb-3">
                                        <label class="form-label fw-bold">
                                            Select Parcel <span class="text-danger">*</span>
                                        </label>
                                        <select class="form-select" name="parcel_id" id="parcel_select" required>
                                            <option value="">-- Choose a parcel --</option>
                                            <?php foreach ($available_parcels as $parcel): ?>
                                            <option value="<?php echo $parcel['id']; ?>" 
                                                    data-area="<?php echo $parcel['area']; ?>"
                                                    data-number="<?php echo $parcel['parcel_number']; ?>"
                                                    data-survey="<?php echo $parcel['survey_number']; ?>"
                                                    data-location="<?php echo htmlspecialchars($parcel['location_description']); ?>"
                                                    data-boma="<?php echo htmlspecialchars($parcel['boma_name']); ?>"
                                                    data-payam="<?php echo htmlspecialchars($parcel['payam_name']); ?>"
                                                    data-county="<?php echo htmlspecialchars($parcel['county_name']); ?>"
                                                    data-state="<?php echo htmlspecialchars($parcel['state_name']); ?>"
                                                    data-zoning="<?php echo $parcel['zone_code'] . ' - ' . $parcel['zone_name']; ?>"
                                                    <?php echo ($parcel['pending_applications'] > 0) ? 'disabled' : ''; ?>>
                                                <?php echo htmlspecialchars($parcel['parcel_number']); ?> - 
                                                <?php echo htmlspecialchars($parcel['boma_name']); ?>, 
                                                <?php echo htmlspecialchars($parcel['payam_name']); ?>
                                                (<?php echo number_format($parcel['area'] ?? 0, 2); ?> m²)
                                                <?php if ($parcel['pending_applications'] > 0): ?>
                                                - [PENDING APPLICATION]
                                                <?php endif; ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <!-- Parcel Details Card -->
                                <div id="parcel_details_card" class="mt-3" style="display: none;">
                                    <div class="card border">
                                        <div class="card-header bg-light">
                                            <h6 class="mb-0 fw-bold">Parcel Details</h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="row">
                                                <div class="col-md-4 mb-2">
                                                    <small class="text-muted d-block">Parcel Number</small>
                                                    <span id="parcel_number" class="fw-bold">-</span>
                                                </div>
                                                <div class="col-md-4 mb-2">
                                                    <small class="text-muted d-block">Survey Number</small>
                                                    <span id="survey_number" class="fw-bold">-</span>
                                                </div>
                                                <div class="col-md-4 mb-2">
                                                    <small class="text-muted d-block">Area</small>
                                                    <span id="parcel_area" class="fw-bold">-</span>
                                                </div>
                                                <div class="col-md-4 mb-2">
                                                    <small class="text-muted d-block">Boma</small>
                                                    <span id="parcel_boma" class="fw-bold">-</span>
                                                </div>
                                                <div class="col-md-4 mb-2">
                                                    <small class="text-muted d-block">Payam</small>
                                                    <span id="parcel_payam" class="fw-bold">-</span>
                                                </div>
                                                <div class="col-md-4 mb-2">
                                                    <small class="text-muted d-block">County/State</small>
                                                    <span id="parcel_county_state" class="fw-bold">-</span>
                                                </div>
                                                <div class="col-md-4 mb-2">
                                                    <small class="text-muted d-block">Zoning</small>
                                                    <span id="parcel_zoning" class="fw-bold">-</span>
                                                </div>
                                                <div class="col-12 mt-2">
                                                    <small class="text-muted d-block">Location Description</small>
                                                    <span id="parcel_location" class="fw-bold">-</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Parcel Map Preview -->
                                <div class="mt-3" id="parcel_map_container" style="display: none;">
                                    <label class="form-label fw-bold">Parcel Location</label>
                                    <div id="parcel_map" style="height: 300px; width: 100%;" class="border rounded"></div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Step 3: Supporting Documents -->
                        <div class="card border-0 shadow-sm mb-4" id="step3">
                            <div class="card-header bg-white py-3">
                                <h5 class="card-title mb-0 fw-bold">
                                    <i class="bi bi-file-earmark-text me-2 text-info"></i>
                                    Step 3: Supporting Documents
                                </h5>
                            </div>
                            <div class="card-body">
                                <!-- Document Checklist -->
                                <div class="alert alert-info">
                                    <i class="bi bi-info-circle me-2"></i>
                                    Please upload all required documents. Maximum file size: 10MB per file.
                                    Accepted formats: PDF, JPG, PNG, JPEG
                                </div>

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label fw-bold">Document Type</label>
                                        <select class="form-select document-type" name="document_types[]">
                                            <option value="">Select Type</option>
                                            <?php foreach ($required_documents as $key => $label): ?>
                                            <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label fw-bold">Upload File</label>
                                        <input type="file" class="form-control document-file" name="documents[]" 
                                               accept=".pdf,.jpg,.jpeg,.png">
                                    </div>
                                </div>

                                <!-- Document List Template -->
                                <div id="document_list">
                                    <!-- Documents will be added here dynamically -->
                                </div>

                                <button type="button" class="btn btn-outline-primary mt-2" onclick="addDocumentRow()">
                                    <i class="bi bi-plus-circle me-2"></i>Add Another Document
                                </button>

                                <!-- Additional Information -->
                                <div class="mt-4">
                                    <label class="form-label fw-bold">Additional Information / Notes</label>
                                    <textarea class="form-control" name="details" rows="4" 
                                              placeholder="Provide any additional information about this application..."></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- Declaration -->
                        <div class="card border-0 shadow-sm mb-4">
                            <div class="card-body">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="declaration" required>
                                    <label class="form-check-label" for="declaration">
                                        I declare that the information provided is true and correct to the best of my knowledge. 
                                        I understand that providing false information may result in rejection of this application 
                                        and legal consequences.
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
                                <i class="bi bi-clock-history me-2"></i>Recent New Title Applications
                            </h6>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>App. ID</th>
                                            <th>Date</th>
                                            <th>Applicant</th>
                                            <th>Parcel</th>
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
                                            <td><?php echo htmlspecialchars($app['parcel_number']); ?></td>
                                            <td>
                                                <?php
                                                $statusClass = match($app['status']) {
                                                    'submitted' => 'primary',
                                                    'under_review' => 'info',
                                                    'approved' => 'success',
                                                    'rejected' => 'danger',
                                                    'completed' => 'success',
                                                    default => 'secondary'
                                                };
                                                ?>
                                                <span class="badge bg-<?php echo $statusClass; ?>">
                                                    <?php echo ucfirst(str_replace('_', ' ', $app['status'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <a href="applications-view.php?id=<?php echo $app['id']; ?>" 
                                                   class="btn btn-sm btn-outline-primary">
                                                    <i class="bi bi-eye"></i> View
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
                    <h5 class="modal-title">Application Preview</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6 class="fw-bold">Applicant Information</h6>
                            <table class="table table-sm">
                                <tr>
                                    <th>Name:</th>
                                    <td id="preview_applicant_name"></td>
                                </tr>
                                <tr>
                                    <th>Type:</th>
                                    <td id="preview_applicant_type"></td>
                                </tr>
                                <tr>
                                    <th>ID/Reg:</th>
                                    <td id="preview_applicant_id"></td>
                                </tr>
                                <tr>
                                    <th>Contact:</th>
                                    <td id="preview_applicant_contact"></td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <h6 class="fw-bold">Application Details</h6>
                            <table class="table table-sm">
                                <tr>
                                    <th>Date:</th>
                                    <td id="preview_date"></td>
                                </tr>
                                <tr>
                                    <th>Status:</th>
                                    <td id="preview_status"></td>
                                </tr>
                                <tr>
                                    <th>Documents:</th>
                                    <td id="preview_docs">0 files</td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    
                    <h6 class="fw-bold mt-3">Parcel Information</h6>
                    <table class="table table-sm">
                        <tr>
                            <th>Parcel Number:</th>
                            <td id="preview_parcel_number"></td>
                            <th>Area:</th>
                            <td id="preview_parcel_area"></td>
                        </tr>
                        <tr>
                            <th>Location:</th>
                            <td colspan="3" id="preview_parcel_location"></td>
                        </tr>
                        <tr>
                            <th>Administrative Area:</th>
                            <td colspan="3" id="preview_parcel_admin"></td>
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
    <script src="https://maps.googleapis.com/maps/api/js?key=YOUR_API_KEY&libraries=geometry"></script>
    
    <script>
        // Global variables
        let documentCount = 0;
        let map;
        let parcelPolygon = null;
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            // Add first document row
            addDocumentRow();
            
            // Setup applicant select listener
            document.getElementById('applicant_select').addEventListener('change', updateApplicantPreview);
            
            // Setup parcel select listener
            document.getElementById('parcel_select').addEventListener('change', updateParcelDetails);
            
            // Initialize tooltips
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[title]'));
            tooltipTriggerList.map(function(tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
            
            // Load any saved form data
            <?php if (!empty($_POST)): ?>
            // Restore form data if needed
            <?php endif; ?>
        });

        // Add document row
        function addDocumentRow() {
            const container = document.getElementById('document_list');
            const rowId = 'doc_' + Date.now() + '_' + documentCount;
            
            const row = document.createElement('div');
            row.className = 'row mb-2 document-row';
            row.id = rowId;
            
            row.innerHTML = `
                <div class="col-md-6">
                    <select class="form-select form-select-sm" name="document_types[]">
                        <option value="">Select Type</option>
                        <?php foreach ($required_documents as $key => $label): ?>
                        <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-5">
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

        // Remove document row
        function removeDocumentRow(rowId) {
            document.getElementById(rowId).remove();
        }

        // Update applicant preview
        function updateApplicantPreview() {
            const select = document.getElementById('applicant_select');
            const selected = select.options[select.selectedIndex];
            const preview = document.getElementById('applicant_preview');
            
            if (selected.value) {
                preview.style.display = 'block';
                document.getElementById('preview_name').textContent = selected.text.split(' (')[0];
                document.getElementById('preview_id').textContent = selected.dataset.id || 'N/A';
                document.getElementById('preview_phone').textContent = selected.dataset.phone || 'N/A';
                document.getElementById('preview_email').textContent = selected.dataset.email || 'N/A';
            } else {
                preview.style.display = 'none';
            }
        }

        // Update parcel details
        function updateParcelDetails() {
            const select = document.getElementById('parcel_select');
            const selected = select.options[select.selectedIndex];
            const detailsCard = document.getElementById('parcel_details_card');
            const mapContainer = document.getElementById('parcel_map_container');
            
            if (selected.value) {
                detailsCard.style.display = 'block';
                
                document.getElementById('parcel_number').textContent = selected.dataset.number || '-';
                document.getElementById('survey_number').textContent = selected.dataset.survey || '-';
                document.getElementById('parcel_area').textContent = selected.dataset.area ? 
                    parseFloat(selected.dataset.area).toFixed(2) + ' m²' : '-';
                document.getElementById('parcel_boma').textContent = selected.dataset.boma || '-';
                document.getElementById('parcel_payam').textContent = selected.dataset.payam || '-';
                document.getElementById('parcel_county_state').textContent = 
                    (selected.dataset.county || '-') + ' / ' + (selected.dataset.state || '-');
                document.getElementById('parcel_zoning').textContent = selected.dataset.zoning || 'Not specified';
                document.getElementById('parcel_location').textContent = selected.dataset.location || '-';
                
                // Show map if we have coordinates (would need to fetch from server)
                // For now, hide map container
                mapContainer.style.display = 'none';
                
                // In production, you would fetch the parcel geometry and display it
                // loadParcelGeometry(selected.value);
            } else {
                detailsCard.style.display = 'none';
                mapContainer.style.display = 'none';
            }
        }

        // Load parcel geometry (requires additional API endpoint)
        function loadParcelGeometry(parcelId) {
            // This would fetch the parcel geometry from the server
            // and display it on the map
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
            
            // In production, this would save via AJAX
            alert('Party creation would be handled via AJAX. Redirecting to parties page...');
            window.open('parties-create.php', '_blank');
            
            // Close modal
            bootstrap.Modal.getInstance(document.getElementById('partyModal')).hide();
        }

        // Preview application
        function previewApplication() {
            // Get applicant info
            const applicantSelect = document.getElementById('applicant_select');
            const applicantOption = applicantSelect.options[applicantSelect.selectedIndex];
            
            if (applicantOption.value) {
                document.getElementById('preview_applicant_name').textContent = 
                    applicantOption.text.split(' (')[0];
                document.getElementById('preview_applicant_type').textContent = 
                    applicantOption.dataset.type === 'individual' ? 'Individual' : 'Organization';
                document.getElementById('preview_applicant_id').textContent = 
                    applicantOption.dataset.id || 'N/A';
                document.getElementById('preview_applicant_contact').textContent = 
                    (applicantOption.dataset.phone || '') + ' ' + (applicantOption.dataset.email || '');
            }
            
            // Get application details
            document.getElementById('preview_date').textContent = 
                document.querySelector('input[name="submission_date"]').value;
            document.getElementById('preview_status').textContent = 
                document.querySelector('select[name="status"]').options[
                    document.querySelector('select[name="status"]').selectedIndex
                ].text;
            
            // Count documents
            const docFiles = document.querySelectorAll('.document-file');
            let fileCount = 0;
            docFiles.forEach(input => {
                if (input.files.length > 0) fileCount++;
            });
            document.getElementById('preview_docs').textContent = fileCount + ' files';
            
            // Get parcel info
            const parcelSelect = document.getElementById('parcel_select');
            const parcelOption = parcelSelect.options[parcelSelect.selectedIndex];
            
            if (parcelOption.value) {
                document.getElementById('preview_parcel_number').textContent = 
                    parcelOption.dataset.number || '-';
                document.getElementById('preview_parcel_area').textContent = 
                    parcelOption.dataset.area ? parseFloat(parcelOption.dataset.area).toFixed(2) + ' m²' : '-';
                document.getElementById('preview_parcel_location').textContent = 
                    parcelOption.dataset.location || '-';
                document.getElementById('preview_parcel_admin').textContent = 
                    (parcelOption.dataset.boma || '-') + ', ' + 
                    (parcelOption.dataset.payam || '-') + ', ' + 
                    (parcelOption.dataset.county || '-') + ' / ' + 
                    (parcelOption.dataset.state || '-');
            }
            
            // Notes
            const notes = document.querySelector('textarea[name="details"]').value;
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
        document.getElementById('applicationForm').addEventListener('submit', function(e) {
            const declaration = document.getElementById('declaration');
            
            if (!declaration.checked) {
                e.preventDefault();
                alert('Please confirm the declaration before submitting.');
                return false;
            }
            
            // Check if at least one document is uploaded
            const docFiles = document.querySelectorAll('.document-file');
            let hasDocs = false;
            docFiles.forEach(input => {
                if (input.files.length > 0) hasDocs = true;
            });
            
            if (!hasDocs) {
                e.preventDefault();
                alert('Please upload at least one supporting document.');
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
        
        .table-sm th {
            width: 30%;
            color: #6c757d;
            font-weight: 500;
        }
        
        .document-row {
            padding: 0.5rem;
            background-color: #f8f9fa;
            border-radius: 0.375rem;
            margin-bottom: 0.5rem !important;
        }
        
        @media (max-width: 768px) {
            .step:after {
                display: none;
            }
            
            .document-row .col-md-1 {
                margin-top: 0.5rem;
            }
            
            .document-row button {
                width: 100%;
            }
        }
    </style>

</body>
</html>