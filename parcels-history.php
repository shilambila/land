<?php
ini_set('display_errors', 1); 
error_reporting(E_ALL);

// parcels-history.php
include("head.php");
require_once 'db_connection.php';

// ============================================================================
// GET FILTERS
// ============================================================================

$parcel_id = isset($_GET['parcel_id']) ? (int)$_GET['parcel_id'] : null;
$parcel_number = isset($_GET['parcel_number']) ? $_GET['parcel_number'] : null;
$history_type = isset($_GET['type']) ? $_GET['type'] : 'all';
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : null;
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : null;

// ============================================================================
// GET PARCEL DATA
// ============================================================================

// Get all parcels for dropdown
$all_parcels = fetchAll($conn, "
    SELECT p.id, p.parcel_number, p.area, 
           b.name as boma_name, pa.name as payam_name, c.name as county_name, s.name as state_name
    FROM parcels p
    JOIN bomas b ON p.boma_id = b.id
    JOIN payams pa ON b.payam_id = pa.id
    JOIN counties c ON pa.county_id = c.id
    JOIN states s ON c.state_id = s.id
    ORDER BY p.parcel_number
");

// Get selected parcel details
$selected_parcel = null;
if ($parcel_id) {
    $selected_parcel = fetchOne($conn, "
        SELECT p.*, 
               b.name as boma_name, b.id as boma_id,
               pa.name as payam_name, pa.id as payam_id,
               c.name as county_name, c.id as county_id,
               s.name as state_name, s.id as state_id,
               z.zone_code, z.zone_name
        FROM parcels p
        JOIN bomas b ON p.boma_id = b.id
        JOIN payams pa ON b.payam_id = pa.id
        JOIN counties c ON pa.county_id = c.id
        JOIN states s ON c.state_id = s.id
        LEFT JOIN zoning z ON p.current_zoning_id = z.id
        WHERE p.id = ?
    ", [$parcel_id]);
} elseif ($parcel_number) {
    $selected_parcel = fetchOne($conn, "
        SELECT p.*, 
               b.name as boma_name, b.id as boma_id,
               pa.name as payam_name, pa.id as payam_id,
               c.name as county_name, c.id as county_id,
               s.name as state_name, s.id as state_id,
               z.zone_code, z.zone_name
        FROM parcels p
        JOIN bomas b ON p.boma_id = b.id
        JOIN payams pa ON b.payam_id = pa.id
        JOIN counties c ON pa.county_id = c.id
        JOIN states s ON c.state_id = s.id
        LEFT JOIN zoning z ON p.current_zoning_id = z.id
        WHERE p.parcel_number = ?
    ", [$parcel_number]);
}

// ============================================================================
// GET HISTORY DATA BASED ON SELECTED PARCEL
// ============================================================================

$ownership_history = [];
$title_history = [];
$zoning_history = [];
$valuation_history = [];
$transaction_history = [];
$document_history = [];
$application_history = [];
$dispute_history = [];
$encumbrance_history = [];
$survey_history = [];

if ($selected_parcel) {
    $parcel_id = $selected_parcel['id'];
    
    // Get ownership history (current and past owners)
    $ownership_history = fetchAll($conn, "
        SELECT 
            o.id,
            o.share_percentage,
            o.ownership_start_date,
            o.ownership_end_date,
            o.is_current,
            o.created_at,
            p.name as party_name,
            p.party_type,
            p.national_id,
            p.registration_number,
            t.title_number,
            t.title_type
        FROM ownerships o
        JOIN parties p ON o.party_id = p.id
        JOIN titles t ON o.title_id = t.id
        JOIN parcels pa ON t.parcel_id = pa.id
        WHERE pa.id = ?
        ORDER BY o.ownership_start_date DESC, o.created_at DESC
    ", [$parcel_id]);
    
    // Get title history
    $title_history = fetchAll($conn, "
        SELECT 
            t.*,
            pt.title_number as previous_title_number
        FROM titles t
        LEFT JOIN titles pt ON t.previous_title_id = pt.id
        WHERE t.parcel_id = ?
        ORDER BY t.issue_date DESC, t.created_at DESC
    ", [$parcel_id]);
    
    // Get zoning history
    $zoning_history = fetchAll($conn, "
        SELECT 
            pzh.*,
            z.zone_code,
            z.zone_name,
            z.description as zone_description
        FROM parcel_zoning_history pzh
        JOIN zoning z ON pzh.zoning_id = z.id
        WHERE pzh.parcel_id = ?
        ORDER BY pzh.start_date DESC, pzh.created_at DESC
    ", [$parcel_id]);
    
    // Get valuation history
    $valuation_history = fetchAll($conn, "
        SELECT *
        FROM valuations
        WHERE parcel_id = ?
        ORDER BY valuation_date DESC, created_at DESC
    ", [$parcel_id]);
    
    // Get transaction history
    $transaction_history = fetchAll($conn, "
        SELECT 
            t.*,
            fp.name as from_party_name,
            tp.name as to_party_name,
            u.username as created_by_username
        FROM transactions t
        LEFT JOIN parties fp ON t.from_party_id = fp.id
        LEFT JOIN parties tp ON t.to_party_id = tp.id
        LEFT JOIN users u ON t.created_by = u.id
        WHERE t.parcel_id = ?
        ORDER BY t.transaction_date DESC, t.created_at DESC
    ", [$parcel_id]);
    
    // Get document history
    $document_history = fetchAll($conn, "
        SELECT 
            d.*,
            u.username as uploaded_by_username,
            p.name as party_name
        FROM documents d
        LEFT JOIN users u ON d.uploaded_by = u.id
        LEFT JOIN parties p ON d.party_id = p.id
        WHERE d.parcel_id = ?
        ORDER BY d.uploaded_at DESC
    ", [$parcel_id]);
    
    // Get application history
    $application_history = fetchAll($conn, "
        SELECT 
            a.*,
            p.name as applicant_name
        FROM applications a
        LEFT JOIN parties p ON a.applicant_party_id = p.id
        WHERE a.parcel_id = ?
        ORDER BY a.submission_date DESC, a.created_at DESC
    ", [$parcel_id]);
    
    // Get dispute history
    $dispute_history = fetchAll($conn, "
        SELECT *
        FROM disputes
        WHERE parcel_id = ?
        ORDER BY filing_date DESC, created_at DESC
    ", [$parcel_id]);
    
    // Get encumbrance history
    $encumbrance_history = fetchAll($conn, "
        SELECT 
            e.*,
            p.name as involved_party_name
        FROM encumbrances e
        LEFT JOIN parties p ON e.involved_party_id = p.id
        WHERE e.parcel_id = ?
        ORDER BY e.start_date DESC, e.created_at DESC
    ", [$parcel_id]);
    
    // Get survey history
    $survey_history = fetchAll($conn, "
        SELECT 
            sr.*,
            d.file_name as document_name
        FROM survey_records sr
        LEFT JOIN documents d ON sr.document_id = d.id
        WHERE sr.parcel_id = ?
        ORDER BY sr.survey_date DESC, sr.created_at DESC
    ", [$parcel_id]);
}

// ============================================================================
// SUMMARY STATISTICS
// ============================================================================

$summary = [
    'total_ownerships' => count($ownership_history),
    'current_owners' => count(array_filter($ownership_history, function($o) { return $o['is_current']; })),
    'total_titles' => count($title_history),
    'total_valuations' => count($valuation_history),
    'latest_valuation' => !empty($valuation_history) ? $valuation_history[0]['value'] : null,
    'total_transactions' => count($transaction_history),
    'total_documents' => count($document_history),
    'total_applications' => count($application_history),
    'total_disputes' => count($dispute_history),
    'open_disputes' => count(array_filter($dispute_history, function($d) { return $d['status'] != 'resolved' && $d['status'] != 'closed'; })),
    'total_encumbrances' => count($encumbrance_history),
    'total_surveys' => count($survey_history)
];
?>

<body data-page="parcels-history" class="parcels-history-page">
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
                            <h1 class="h3 mb-0">Parcel History Timeline</h1>
                            <p class="text-muted mb-0">Complete historical record of land parcels</p>
                        </div>
                        <div>
                            <a href="parcels.php" class="btn btn-outline-primary me-2">
                                <i class="bi bi-grid"></i> Back to Parcels
                            </a>
                        </div>
                    </div>

                    <!-- Parcel Selection -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-body">
                            <form method="GET" class="row g-3">
                                <div class="col-md-8">
                                    <label class="form-label">Select Parcel</label>
                                    <select class="form-select" name="parcel_id" id="parcelSelect" onchange="this.form.submit()">
                                        <option value="">-- Select a parcel to view history --</option>
                                        <?php foreach ($all_parcels as $p): ?>
                                        <option value="<?php echo $p['id']; ?>" 
                                            <?php echo ($selected_parcel && $selected_parcel['id'] == $p['id']) ? 'selected' : ''; ?>>
                                            <?php echo $p['parcel_number']; ?> - 
                                            <?php echo htmlspecialchars($p['boma_name']); ?>, 
                                            <?php echo htmlspecialchars($p['payam_name']); ?>, 
                                            <?php echo htmlspecialchars($p['county_name']); ?>
                                            (<?php echo number_format($p['area'], 2); ?> m²)
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Or Enter Parcel Number</label>
                                    <div class="input-group">
                                        <input type="text" class="form-control" name="parcel_number" 
                                               placeholder="e.g., PARCEL/2024/001"
                                               value="<?php echo htmlspecialchars($parcel_number ?? ''); ?>">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-search"></i>
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <?php if ($selected_parcel): ?>
                    
                    <!-- Parcel Information Card -->
                    <div class="card border-0 shadow-sm mb-4 bg-light">
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-8">
                                    <h4 class="mb-3">
                                        <i class="bi bi-grid text-primary me-2"></i>
                                        <?php echo htmlspecialchars($selected_parcel['parcel_number']); ?>
                                        <?php if ($selected_parcel['survey_number']): ?>
                                            <small class="text-muted">(Survey: <?php echo $selected_parcel['survey_number']; ?>)</small>
                                        <?php endif; ?>
                                    </h4>
                                    
                                    <div class="row">
                                        <div class="col-md-6 mb-2">
                                            <i class="bi bi-geo-alt text-danger"></i>
                                            <strong>Location:</strong> 
                                            <?php echo htmlspecialchars($selected_parcel['boma_name']); ?>, 
                                            <?php echo htmlspecialchars($selected_parcel['payam_name']); ?>, 
                                            <?php echo htmlspecialchars($selected_parcel['county_name']); ?>, 
                                            <?php echo htmlspecialchars($selected_parcel['state_name']); ?>
                                        </div>
                                        <div class="col-md-6 mb-2">
                                            <i class="bi bi-arrows-angle-expand text-success"></i>
                                            <strong>Area:</strong> 
                                            <?php echo number_format($selected_parcel['area'], 4); ?> m²
                                        </div>
                                        <div class="col-md-6 mb-2">
                                            <i class="bi bi-tag text-warning"></i>
                                            <strong>Zoning:</strong> 
                                            <?php echo $selected_parcel['zone_code'] ? $selected_parcel['zone_code'] . ' - ' . $selected_parcel['zone_name'] : 'Not assigned'; ?>
                                        </div>
                                        <div class="col-md-6 mb-2">
                                            <i class="bi bi-calendar text-info"></i>
                                            <strong>Created:</strong> 
                                            <?php echo date('d M Y, H:i', strtotime($selected_parcel['created_at'])); ?>
                                        </div>
                                        <?php if ($selected_parcel['location_description']): ?>
                                        <div class="col-12 mt-2">
                                            <i class="bi bi-chat-text text-secondary"></i>
                                            <strong>Description:</strong> 
                                            <?php echo htmlspecialchars($selected_parcel['location_description']); ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="col-md-4">
                                    <div class="bg-white p-3 rounded">
                                        <h6 class="fw-bold mb-3">Summary Statistics</h6>
                                        <div class="row g-2">
                                            <div class="col-6">
                                                <div class="text-center p-2 border rounded">
                                                    <span class="badge bg-primary d-block mb-1"><?php echo $summary['current_owners']; ?></span>
                                                    <small>Current Owners</small>
                                                </div>
                                            </div>
                                            <div class="col-6">
                                                <div class="text-center p-2 border rounded">
                                                    <span class="badge bg-success d-block mb-1"><?php echo $summary['total_titles']; ?></span>
                                                    <small>Total Titles</small>
                                                </div>
                                            </div>
                                            <div class="col-6">
                                                <div class="text-center p-2 border rounded">
                                                    <span class="badge bg-warning d-block mb-1"><?php echo $summary['total_transactions']; ?></span>
                                                    <small>Transactions</small>
                                                </div>
                                            </div>
                                            <div class="col-6">
                                                <div class="text-center p-2 border rounded">
                                                    <span class="badge bg-info d-block mb-1"><?php echo $summary['total_documents']; ?></span>
                                                    <small>Documents</small>
                                                </div>
                                            </div>
                                            <div class="col-6">
                                                <div class="text-center p-2 border rounded">
                                                    <span class="badge bg-danger d-block mb-1"><?php echo $summary['open_disputes']; ?></span>
                                                    <small>Open Disputes</small>
                                                </div>
                                            </div>
                                            <div class="col-6">
                                                <div class="text-center p-2 border rounded">
                                                    <span class="badge bg-secondary d-block mb-1"><?php echo $summary['total_valuations']; ?></span>
                                                    <small>Valuations</small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- History Tabs -->
                    <ul class="nav nav-tabs mb-4" id="historyTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="ownership-tab" data-bs-toggle="tab" type="button" role="tab">
                                <i class="bi bi-people"></i> Ownership History
                                <span class="badge bg-primary ms-2"><?php echo $summary['total_ownerships']; ?></span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="titles-tab" data-bs-toggle="tab" type="button" role="tab">
                                <i class="bi bi-file-text"></i> Title History
                                <span class="badge bg-primary ms-2"><?php echo $summary['total_titles']; ?></span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="zoning-tab" data-bs-toggle="tab" type="button" role="tab">
                                <i class="bi bi-tag"></i> Zoning Changes
                                <span class="badge bg-primary ms-2"><?php echo count($zoning_history); ?></span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="transactions-tab" data-bs-toggle="tab" type="button" role="tab">
                                <i class="bi bi-arrow-left-right"></i> Transactions
                                <span class="badge bg-primary ms-2"><?php echo $summary['total_transactions']; ?></span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="valuations-tab" data-bs-toggle="tab" type="button" role="tab">
                                <i class="bi bi-cash-stack"></i> Valuations
                                <span class="badge bg-primary ms-2"><?php echo $summary['total_valuations']; ?></span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="documents-tab" data-bs-toggle="tab" type="button" role="tab">
                                <i class="bi bi-files"></i> Documents
                                <span class="badge bg-primary ms-2"><?php echo $summary['total_documents']; ?></span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="applications-tab" data-bs-toggle="tab" type="button" role="tab">
                                <i class="bi bi-file-earmark"></i> Applications
                                <span class="badge bg-primary ms-2"><?php echo $summary['total_applications']; ?></span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="disputes-tab" data-bs-toggle="tab" type="button" role="tab">
                                <i class="bi bi-exclamation-triangle"></i> Disputes
                                <span class="badge bg-primary ms-2"><?php echo $summary['total_disputes']; ?></span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="encumbrances-tab" data-bs-toggle="tab" type="button" role="tab">
                                <i class="bi bi-shield"></i> Encumbrances
                                <span class="badge bg-primary ms-2"><?php echo $summary['total_encumbrances']; ?></span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="surveys-tab" data-bs-toggle="tab" type="button" role="tab">
                                <i class="bi bi-rulers"></i> Surveys
                                <span class="badge bg-primary ms-2"><?php echo $summary['total_surveys']; ?></span>
                            </button>
                        </li>
                    </ul>

                    <!-- Tab Content -->
                    <div class="tab-content" id="historyTabContent">
                        
                        <!-- 1. OWNERSHIP HISTORY TAB -->
                        <div class="tab-pane fade show active" id="ownership" role="tabpanel">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h5 class="card-title mb-0 fw-bold">
                                        <i class="bi bi-people text-primary me-2"></i>
                                        Complete Ownership History
                                    </h5>
                                </div>
                                <div class="card-body p-0">
                                    <?php if (empty($ownership_history)): ?>
                                        <div class="text-center py-5">
                                            <i class="bi bi-people fs-1 text-muted d-block mb-3"></i>
                                            <p class="text-muted">No ownership records found for this parcel</p>
                                        </div>
                                    <?php else: ?>
                                        <div class="timeline-ownership p-4">
                                            <?php foreach ($ownership_history as $index => $owner): ?>
                                                <div class="timeline-item <?php echo $index === 0 ? 'current' : ''; ?> mb-4">
                                                    <div class="row">
                                                        <div class="col-md-3">
                                                            <div class="timeline-date">
                                                                <span class="badge <?php echo $owner['is_current'] ? 'bg-success' : 'bg-secondary'; ?> p-2">
                                                                    <?php echo date('d M Y', strtotime($owner['ownership_start_date'])); ?>
                                                                    <?php if ($owner['ownership_end_date']): ?>
                                                                        - <?php echo date('d M Y', strtotime($owner['ownership_end_date'])); ?>
                                                                    <?php else: ?>
                                                                        - Present
                                                                    <?php endif; ?>
                                                                </span>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-9">
                                                            <div class="card">
                                                                <div class="card-body">
                                                                    <div class="d-flex justify-content-between">
                                                                        <h6 class="fw-bold">
                                                                            <?php echo htmlspecialchars($owner['party_name']); ?>
                                                                            <?php if ($owner['party_type'] == 'individual'): ?>
                                                                                <small class="text-muted">(Individual)</small>
                                                                            <?php else: ?>
                                                                                <small class="text-muted">(Organization)</small>
                                                                            <?php endif; ?>
                                                                        </h6>
                                                                        <?php if ($owner['is_current']): ?>
                                                                            <span class="badge bg-success">Current Owner</span>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                    
                                                                    <div class="row mt-2">
                                                                        <div class="col-md-4">
                                                                            <small class="text-muted d-block">Title Number</small>
                                                                            <span class="fw-medium"><?php echo $owner['title_number']; ?></span>
                                                                        </div>
                                                                        <div class="col-md-4">
                                                                            <small class="text-muted d-block">Share Percentage</small>
                                                                            <span class="fw-medium"><?php echo $owner['share_percentage']; ?>%</span>
                                                                        </div>
                                                                        <div class="col-md-4">
                                                                            <small class="text-muted d-block">ID/Registration</small>
                                                                            <span class="fw-medium">
                                                                                <?php echo $owner['national_id'] ?? $owner['registration_number'] ?? 'N/A'; ?>
                                                                            </span>
                                                                        </div>
                                                                    </div>
                                                                    
                                                                    <div class="mt-2">
                                                                        <small class="text-muted">
                                                                            Recorded: <?php echo date('d M Y H:i', strtotime($owner['created_at'])); ?>
                                                                        </small>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- 2. TITLE HISTORY TAB -->
                        <div class="tab-pane fade" id="titles" role="tabpanel">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h5 class="card-title mb-0 fw-bold">
                                        <i class="bi bi-file-text text-success me-2"></i>
                                        Title History
                                    </h5>
                                </div>
                                <div class="card-body p-0">
                                    <?php if (empty($title_history)): ?>
                                        <div class="text-center py-5">
                                            <i class="bi bi-file-text fs-1 text-muted d-block mb-3"></i>
                                            <p class="text-muted">No title records found for this parcel</p>
                                        </div>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-hover mb-0">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>Title Number</th>
                                                        <th>Type</th>
                                                        <th>Issue Date</th>
                                                        <th>Expiry Date</th>
                                                        <th>Status</th>
                                                        <th>Previous Title</th>
                                                        <th>Created</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($title_history as $title): ?>
                                                    <tr>
                                                        <td>
                                                            <span class="fw-medium"><?php echo $title['title_number']; ?></span>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-info"><?php echo ucfirst($title['title_type']); ?></span>
                                                        </td>
                                                        <td><?php echo date('d M Y', strtotime($title['issue_date'])); ?></td>
                                                        <td>
                                                            <?php echo $title['expiry_date'] ? date('d M Y', strtotime($title['expiry_date'])) : 'N/A'; ?>
                                                        </td>
                                                        <td>
                                                            <?php
                                                            $status_class = [
                                                                'active' => 'success',
                                                                'inactive' => 'secondary',
                                                                'surrendered' => 'warning',
                                                                'cancelled' => 'danger'
                                                            ][$title['status']] ?? 'secondary';
                                                            ?>
                                                            <span class="badge bg-<?php echo $status_class; ?>">
                                                                <?php echo ucfirst($title['status']); ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <?php echo $title['previous_title_number'] ?? 'N/A'; ?>
                                                        </td>
                                                        <td>
                                                            <small class="text-muted">
                                                                <?php echo date('d M Y', strtotime($title['created_at'])); ?>
                                                            </small>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- 3. ZONING CHANGES TAB -->
                        <div class="tab-pane fade" id="zoning" role="tabpanel">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h5 class="card-title mb-0 fw-bold">
                                        <i class="bi bi-tag text-warning me-2"></i>
                                        Zoning Change History
                                    </h5>
                                </div>
                                <div class="card-body p-0">
                                    <?php if (empty($zoning_history)): ?>
                                        <div class="text-center py-5">
                                            <i class="bi bi-tag fs-1 text-muted d-block mb-3"></i>
                                            <p class="text-muted">No zoning changes recorded for this parcel</p>
                                        </div>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-hover mb-0">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>Period</th>
                                                        <th>Zone Code</th>
                                                        <th>Zone Name</th>
                                                        <th>Description</th>
                                                        <th>Recorded</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($zoning_history as $zone): ?>
                                                    <tr>
                                                        <td>
                                                            <span class="badge bg-secondary">
                                                                <?php echo date('d M Y', strtotime($zone['start_date'])); ?>
                                                                <?php if ($zone['end_date']): ?>
                                                                    - <?php echo date('d M Y', strtotime($zone['end_date'])); ?>
                                                                <?php else: ?>
                                                                    - Present
                                                                <?php endif; ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <span class="fw-medium"><?php echo $zone['zone_code']; ?></span>
                                                        </td>
                                                        <td><?php echo $zone['zone_name']; ?></td>
                                                        <td>
                                                            <small><?php echo $zone['zone_description'] ?? ''; ?></small>
                                                        </td>
                                                        <td>
                                                            <small class="text-muted">
                                                                <?php echo date('d M Y', strtotime($zone['created_at'])); ?>
                                                            </small>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- 4. TRANSACTIONS TAB -->
                        <div class="tab-pane fade" id="transactions" role="tabpanel">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h5 class="card-title mb-0 fw-bold">
                                        <i class="bi bi-arrow-left-right text-primary me-2"></i>
                                        Transaction History
                                    </h5>
                                </div>
                                <div class="card-body p-0">
                                    <?php if (empty($transaction_history)): ?>
                                        <div class="text-center py-5">
                                            <i class="bi bi-arrow-left-right fs-1 text-muted d-block mb-3"></i>
                                            <p class="text-muted">No transactions recorded for this parcel</p>
                                        </div>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-hover mb-0">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>Date</th>
                                                        <th>Type</th>
                                                        <th>From Party</th>
                                                        <th>To Party</th>
                                                        <th>Consideration</th>
                                                        <th>Details</th>
                                                        <th>Recorded By</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($transaction_history as $trans): ?>
                                                    <tr>
                                                        <td>
                                                            <span class="fw-medium"><?php echo date('d M Y', strtotime($trans['transaction_date'])); ?></span>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-info"><?php echo ucfirst($trans['transaction_type']); ?></span>
                                                        </td>
                                                        <td>
                                                            <?php echo htmlspecialchars($trans['from_party_name'] ?? 'N/A'); ?>
                                                        </td>
                                                        <td>
                                                            <?php echo htmlspecialchars($trans['to_party_name'] ?? 'N/A'); ?>
                                                        </td>
                                                        <td>
                                                            <?php if ($trans['consideration_amount']): ?>
                                                                $<?php echo number_format($trans['consideration_amount'], 2); ?>
                                                            <?php else: ?>
                                                                N/A
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <small><?php echo $trans['details'] ?? ''; ?></small>
                                                        </td>
                                                        <td>
                                                            <small class="text-muted">
                                                                <?php echo $trans['created_by_username'] ?? 'System'; ?>
                                                            </small>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- 5. VALUATIONS TAB -->
                        <div class="tab-pane fade" id="valuations" role="tabpanel">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h5 class="card-title mb-0 fw-bold">
                                        <i class="bi bi-cash-stack text-success me-2"></i>
                                        Valuation History
                                    </h5>
                                </div>
                                <div class="card-body p-0">
                                    <?php if (empty($valuation_history)): ?>
                                        <div class="text-center py-5">
                                            <i class="bi bi-cash-stack fs-1 text-muted d-block mb-3"></i>
                                            <p class="text-muted">No valuations recorded for this parcel</p>
                                        </div>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-hover mb-0">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>Valuation Date</th>
                                                        <th>Value (USD)</th>
                                                        <th>Assessed By</th>
                                                        <th>Notes</th>
                                                        <th>Recorded</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($valuation_history as $val): ?>
                                                    <tr>
                                                        <td>
                                                            <span class="fw-medium"><?php echo date('d M Y', strtotime($val['valuation_date'])); ?></span>
                                                        </td>
                                                        <td>
                                                            <span class="fw-bold text-success">
                                                                $<?php echo number_format($val['value'], 2); ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <?php echo htmlspecialchars($val['assessed_by'] ?? 'N/A'); ?>
                                                        </td>
                                                        <td>
                                                            <small><?php echo $val['notes'] ?? ''; ?></small>
                                                        </td>
                                                        <td>
                                                            <small class="text-muted">
                                                                <?php echo date('d M Y', strtotime($val['created_at'])); ?>
                                                            </small>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- 6. DOCUMENTS TAB -->
                        <div class="tab-pane fade" id="documents" role="tabpanel">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h5 class="card-title mb-0 fw-bold">
                                        <i class="bi bi-files text-info me-2"></i>
                                        Document History
                                    </h5>
                                </div>
                                <div class="card-body p-0">
                                    <?php if (empty($document_history)): ?>
                                        <div class="text-center py-5">
                                            <i class="bi bi-files fs-1 text-muted d-block mb-3"></i>
                                            <p class="text-muted">No documents attached to this parcel</p>
                                        </div>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-hover mb-0">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>Document</th>
                                                        <th>Type</th>
                                                        <th>Version</th>
                                                        <th>Related To</th>
                                                        <th>Uploaded By</th>
                                                        <th>Uploaded At</th>
                                                        <th>Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($document_history as $doc): ?>
                                                    <tr>
                                                        <td>
                                                            <i class="bi bi-file-earmark-text me-2"></i>
                                                            <span class="fw-medium"><?php echo $doc['file_name']; ?></span>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-secondary"><?php echo $doc['document_type']; ?></span>
                                                        </td>
                                                        <td>
                                                            v<?php echo $doc['version'] ?? 1; ?>
                                                        </td>
                                                        <td>
                                                            <?php if ($doc['party_name']): ?>
                                                                <small>Party: <?php echo $doc['party_name']; ?></small>
                                                            <?php elseif ($doc['title_id']): ?>
                                                                <small>Title ID: <?php echo $doc['title_id']; ?></small>
                                                            <?php else: ?>
                                                                <small>Parcel only</small>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php echo $doc['uploaded_by_username'] ?? 'System'; ?>
                                                        </td>
                                                        <td>
                                                            <small><?php echo date('d M Y H:i', strtotime($doc['uploaded_at'])); ?></small>
                                                        </td>
                                                        <td>
                                                            <a href="<?php echo $doc['file_path']; ?>" class="btn btn-sm btn-outline-primary" target="_blank">
                                                                <i class="bi bi-download"></i>
                                                            </a>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- 7. APPLICATIONS TAB -->
                        <div class="tab-pane fade" id="applications" role="tabpanel">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h5 class="card-title mb-0 fw-bold">
                                        <i class="bi bi-file-earmark text-warning me-2"></i>
                                        Application History
                                    </h5>
                                </div>
                                <div class="card-body p-0">
                                    <?php if (empty($application_history)): ?>
                                        <div class="text-center py-5">
                                            <i class="bi bi-file-earmark fs-1 text-muted d-block mb-3"></i>
                                            <p class="text-muted">No applications for this parcel</p>
                                        </div>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-hover mb-0">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>Application ID</th>
                                                        <th>Type</th>
                                                        <th>Applicant</th>
                                                        <th>Submission Date</th>
                                                        <th>Status</th>
                                                        <th>Details</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($application_history as $app): ?>
                                                    <tr>
                                                        <td>
                                                            <span class="fw-medium">#<?php echo $app['id']; ?></span>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-info">
                                                                <?php echo ucfirst(str_replace('_', ' ', $app['application_type'])); ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <?php echo htmlspecialchars($app['applicant_name'] ?? 'N/A'); ?>
                                                        </td>
                                                        <td>
                                                            <?php echo date('d M Y', strtotime($app['submission_date'])); ?>
                                                        </td>
                                                        <td>
                                                            <?php
                                                            $status_class = [
                                                                'draft' => 'secondary',
                                                                'submitted' => 'info',
                                                                'under_review' => 'warning',
                                                                'approved' => 'success',
                                                                'rejected' => 'danger',
                                                                'completed' => 'primary'
                                                            ][$app['status']] ?? 'secondary';
                                                            ?>
                                                            <span class="badge bg-<?php echo $status_class; ?>">
                                                                <?php echo ucfirst(str_replace('_', ' ', $app['status'])); ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <small><?php echo $app['details'] ?? ''; ?></small>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- 8. DISPUTES TAB -->
                        <div class="tab-pane fade" id="disputes" role="tabpanel">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h5 class="card-title mb-0 fw-bold">
                                        <i class="bi bi-exclamation-triangle text-danger me-2"></i>
                                        Dispute History
                                    </h5>
                                </div>
                                <div class="card-body p-0">
                                    <?php if (empty($dispute_history)): ?>
                                        <div class="text-center py-5">
                                            <i class="bi bi-exclamation-triangle fs-1 text-muted d-block mb-3"></i>
                                            <p class="text-muted">No disputes recorded for this parcel</p>
                                        </div>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-hover mb-0">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>Filing Date</th>
                                                        <th>Dispute Type</th>
                                                        <th>Description</th>
                                                        <th>Status</th>
                                                        <th>Resolution</th>
                                                        <th>Resolved Date</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($dispute_history as $dispute): ?>
                                                    <tr>
                                                        <td>
                                                            <span class="fw-medium"><?php echo date('d M Y', strtotime($dispute['filing_date'])); ?></span>
                                                        </td>
                                                        <td>
                                                            <?php echo ucfirst($dispute['dispute_type'] ?? 'Unknown'); ?>
                                                        </td>
                                                        <td>
                                                            <small><?php echo $dispute['description'] ?? ''; ?></small>
                                                        </td>
                                                        <td>
                                                            <?php
                                                            $status_class = [
                                                                'open' => 'danger',
                                                                'in_progress' => 'warning',
                                                                'resolved' => 'success',
                                                                'closed' => 'secondary'
                                                            ][$dispute['status']] ?? 'secondary';
                                                            ?>
                                                            <span class="badge bg-<?php echo $status_class; ?>">
                                                                <?php echo ucfirst(str_replace('_', ' ', $dispute['status'])); ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <small><?php echo $dispute['resolution'] ?? ''; ?></small>
                                                        </td>
                                                        <td>
                                                            <?php echo $dispute['resolved_date'] ? date('d M Y', strtotime($dispute['resolved_date'])) : 'N/A'; ?>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- 9. ENCUMBRANCES TAB -->
                        <div class="tab-pane fade" id="encumbrances" role="tabpanel">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h5 class="card-title mb-0 fw-bold">
                                        <i class="bi bi-shield text-primary me-2"></i>
                                        Encumbrance History
                                    </h5>
                                </div>
                                <div class="card-body p-0">
                                    <?php if (empty($encumbrance_history)): ?>
                                        <div class="text-center py-5">
                                            <i class="bi bi-shield fs-1 text-muted d-block mb-3"></i>
                                            <p class="text-muted">No encumbrances recorded for this parcel</p>
                                        </div>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-hover mb-0">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>Type</th>
                                                        <th>Description</th>
                                                        <th>Start Date</th>
                                                        <th>End Date</th>
                                                        <th>Involved Party</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($encumbrance_history as $enc): ?>
                                                    <tr>
                                                        <td>
                                                            <span class="badge bg-warning">
                                                                <?php echo ucfirst($enc['encumbrance_type']); ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <small><?php echo $enc['description'] ?? ''; ?></small>
                                                        </td>
                                                        <td>
                                                            <?php echo date('d M Y', strtotime($enc['start_date'])); ?>
                                                        </td>
                                                        <td>
                                                            <?php echo $enc['end_date'] ? date('d M Y', strtotime($enc['end_date'])) : 'N/A'; ?>
                                                        </td>
                                                        <td>
                                                            <?php echo htmlspecialchars($enc['involved_party_name'] ?? 'N/A'); ?>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- 10. SURVEYS TAB -->
                        <div class="tab-pane fade" id="surveys" role="tabpanel">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h5 class="card-title mb-0 fw-bold">
                                        <i class="bi bi-rulers text-secondary me-2"></i>
                                        Survey History
                                    </h5>
                                </div>
                                <div class="card-body p-0">
                                    <?php if (empty($survey_history)): ?>
                                        <div class="text-center py-5">
                                            <i class="bi bi-rulers fs-1 text-muted d-block mb-3"></i>
                                            <p class="text-muted">No survey records for this parcel</p>
                                        </div>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-hover mb-0">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>Survey Date</th>
                                                        <th>Surveyor</th>
                                                        <th>Description</th>
                                                        <th>Document</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($survey_history as $survey): ?>
                                                    <tr>
                                                        <td>
                                                            <span class="fw-medium"><?php echo date('d M Y', strtotime($survey['survey_date'])); ?></span>
                                                        </td>
                                                        <td>
                                                            <?php echo htmlspecialchars($survey['surveyor'] ?? 'N/A'); ?>
                                                        </td>
                                                        <td>
                                                            <small><?php echo $survey['description'] ?? ''; ?></small>
                                                        </td>
                                                        <td>
                                                            <?php if ($survey['document_name']): ?>
                                                                <a href="#" class="btn btn-sm btn-outline-primary">
                                                                    <i class="bi bi-file-earmark"></i> View
                                                                </a>
                                                            <?php else: ?>
                                                                N/A
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                    </div>
                    
                    <?php else: ?>
                    
                    <!-- No Parcel Selected -->
                    <div class="card border-0 shadow-sm">
                        <div class="card-body text-center py-5">
                            <i class="bi bi-grid fs-1 text-muted d-block mb-3"></i>
                            <h4 class="text-muted">Select a parcel to view its complete history</h4>
                            <p class="text-muted mb-4">Choose a parcel from the dropdown above to see ownership changes, title history, transactions, and more.</p>
                            <div class="row justify-content-center">
                                <div class="col-md-8">
                                    <div class="alert alert-info">
                                        <i class="bi bi-info-circle me-2"></i>
                                        The parcel history includes:
                                        <ul class="text-start mt-2">
                                            <li>Complete ownership timeline with current and past owners</li>
                                            <li>All title documents issued for this parcel</li>
                                            <li>Zoning changes over time</li>
                                            <li>Transaction history (sales, transfers, mortgages)</li>
                                            <li>Valuation history and trends</li>
                                            <li>All related documents and applications</li>
                                            <li>Disputes and encumbrances</li>
                                            <li>Survey records</li>
                                        </ul>
                                    </div>
                                </div>
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

    <style>
        .timeline-ownership {
            position: relative;
            padding-left: 20px;
        }
        
        .timeline-ownership::before {
            content: '';
            position: absolute;
            left: 20px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #e9ecef;
        }
        
        .timeline-item {
            position: relative;
            padding-left: 30px;
        }
        
        .timeline-item::before {
            content: '';
            position: absolute;
            left: 0;
            top: 10px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #6c757d;
            border: 2px solid white;
            z-index: 1;
        }
        
        .timeline-item.current::before {
            background: #198754;
            box-shadow: 0 0 0 3px rgba(25, 135, 84, 0.2);
        }
        
        .timeline-date .badge {
            font-size: 0.9rem;
            padding: 0.5rem 1rem;
        }
        
        .nav-tabs .nav-link {
            color: #6c757d;
            font-weight: 500;
            padding: 0.75rem 1rem;
        }
        
        .nav-tabs .nav-link.active {
            color: #0d6efd;
            font-weight: 600;
            border-bottom: 3px solid #0d6efd;
        }
        
        .nav-tabs .nav-link .badge {
            font-size: 0.7rem;
        }
        
        .table td {
            vertical-align: middle;
        }
        
        @media (max-width: 768px) {
            .nav-tabs .nav-link {
                padding: 0.5rem;
                font-size: 0.8rem;
            }
            
            .timeline-item .row {
                flex-direction: column;
            }
            
            .timeline-item .col-md-3 {
                margin-bottom: 10px;
            }
        }
    </style>

    <script>
        // Preserve selected tab after page reload
        document.addEventListener('DOMContentLoaded', function() {
            const hash = window.location.hash;
            if (hash) {
                const tab = document.querySelector(`[data-bs-target="${hash}"]`);
                if (tab) {
                    const tabInstance = new bootstrap.Tab(tab);
                    tabInstance.show();
                }
            }
            
            // Update URL hash when tab changes
            const tabs = document.querySelectorAll('[data-bs-toggle="tab"]');
            tabs.forEach(tab => {
                tab.addEventListener('shown.bs.tab', function(event) {
                    const target = event.target.getAttribute('data-bs-target');
                    window.location.hash = target;
                });
            });
        });
    </script>

</body>
</html>