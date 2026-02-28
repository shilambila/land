<?php
ini_set('display_errors', 1); 
error_reporting(E_ALL);

// documents.php - Document Management System
include("head.php");
require_once 'db_connection.php';

// ============================================================================
// GET DOCUMENT TYPE FILTER FROM URL
// ============================================================================

$type_filter = isset($_GET['id']) ? $_GET['id'] : (isset($_GET['type']) ? $_GET['type'] : 'all');

// Map URL parameters to actual document types
$type_map = [
    'parcels' => ['survey_plan', 'parcel_map', 'survey_document', 'site_plan'],
    'title_deeds' => ['title_deed', 'certificate_of_title', 'title_document'],
    'party' => ['id_document', 'passport', 'certificate_of_incorporation', 'business_registration']
];

// For display purposes
$type_labels = [
    'all' => 'All Documents',
    'parcels' => 'Parcel Documents',
    'title_deeds' => 'Title Deeds',
    'party' => 'Party Documents'
];

// Build document type condition based on filter
$type_conditions = [];
if ($type_filter !== 'all' && isset($type_map[$type_filter])) {
    $placeholders = implode(',', array_fill(0, count($type_map[$type_filter]), '?'));
    $type_conditions = [
        'sql' => "doc.document_type IN ($placeholders)",
        'params' => $type_map[$type_filter]
    ];
}

// ============================================================================
// HANDLE ACTIONS
// ============================================================================

$message = '';
$messageType = '';

// Delete document
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $documentId = $_GET['delete'];
    
    try {
        // Get file path before deleting
        $doc = fetchOne($conn, "SELECT file_path FROM documents WHERE id = ?", [$documentId]);
        
        if ($doc && !empty($doc['file_path']) && file_exists($doc['file_path'])) {
            unlink($doc['file_path']); // Delete physical file
        }
        
        executeQuery($conn, "DELETE FROM documents WHERE id = ?", [$documentId]);
        $message = "Document deleted successfully";
        $messageType = "success";
    } catch (Exception $e) {
        $message = "Error deleting document: " . $e->getMessage();
        $messageType = "danger";
        error_log("Delete error: " . $e->getMessage());
    }
}

// Upload new document
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload') {
    $document_type = $_POST['document_type'];
    $parcel_id = !empty($_POST['parcel_id']) ? $_POST['parcel_id'] : null;
    $title_id = !empty($_POST['title_id']) ? $_POST['title_id'] : null;
    $party_id = !empty($_POST['party_id']) ? $_POST['party_id'] : null;
    $description = $_POST['description'] ?? '';
    $uploaded_by = $_SESSION['user_id'] ?? 1;
    
    // Determine target folder based on document type
    if ($parcel_id) {
        $target_folder = 'uploads/parcels/';
        $type_category = 'parcel';
    } elseif ($title_id) {
        $target_folder = 'uploads/titles/';
        $type_category = 'title';
    } elseif ($party_id) {
        $target_folder = 'uploads/parties/';
        $type_category = 'party';
    } else {
        $target_folder = 'uploads/documents/';
        $type_category = 'general';
    }
    
    try {
        // Start transaction
        beginTransaction($conn);
        
        // Handle file upload
        if (isset($_FILES['document_file']) && $_FILES['document_file']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = $target_folder;
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $file_name = $_FILES['document_file']['name'];
            $file_extension = pathinfo($file_name, PATHINFO_EXTENSION);
            $safe_filename = $type_category . '_' . time() . '_' . uniqid() . '.' . $file_extension;
            $file_path = $upload_dir . $safe_filename;
            
            if (move_uploaded_file($_FILES['document_file']['tmp_name'], $file_path)) {
                // Get version number
                $version = 1;
                if ($parcel_id) {
                    $last = fetchOne($conn, "SELECT MAX(version) as max_version FROM documents WHERE parcel_id = ? AND document_type = ?", [$parcel_id, $document_type]);
                    $version = ($last['max_version'] ?? 0) + 1;
                } elseif ($title_id) {
                    $last = fetchOne($conn, "SELECT MAX(version) as max_version FROM documents WHERE title_id = ? AND document_type = ?", [$title_id, $document_type]);
                    $version = ($last['max_version'] ?? 0) + 1;
                } elseif ($party_id) {
                    $last = fetchOne($conn, "SELECT MAX(version) as max_version FROM documents WHERE party_id = ? AND document_type = ?", [$party_id, $document_type]);
                    $version = ($last['max_version'] ?? 0) + 1;
                }
                
                executeQuery($conn, "
                    INSERT INTO documents (
                        document_type, parcel_id, title_id, party_id, 
                        file_name, file_path, mime_type, version, 
                        uploaded_by, uploaded_at, description
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
                ", [
                    $document_type, $parcel_id, $title_id, $party_id,
                    $file_name, $file_path, $_FILES['document_file']['type'],
                    $version, $uploaded_by, $description
                ]);
                
                $document_id = $conn->lastInsertId();
                
                // Commit transaction
                commitTransaction($conn);
                
                $message = "Document uploaded successfully";
                $messageType = "success";
            } else {
                throw new Exception("Failed to move uploaded file");
            }
        } else {
            throw new Exception("No file uploaded or upload error");
        }
        
    } catch (Exception $e) {
        rollbackTransaction($conn);
        $message = "Error uploading document: " . $e->getMessage();
        $messageType = "danger";
        error_log("Upload error: " . $e->getMessage());
    }
}

// Update document description
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update') {
    $document_id = $_POST['document_id'];
    $description = $_POST['description'] ?? '';
    
    try {
        executeQuery($conn, "
            UPDATE documents SET description = ? WHERE id = ?
        ", [$description, $document_id]);
        
        $message = "Document description updated successfully";
        $messageType = "success";
        
    } catch (Exception $e) {
        $message = "Error updating document: " . $e->getMessage();
        $messageType = "danger";
        error_log("Update error: " . $e->getMessage());
    }
}

// ============================================================================
// FILTERS AND PAGINATION
// ============================================================================

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Build filter conditions
$where_conditions = ["1=1"];
$params = [];

// Document type filter from URL
if (!empty($type_conditions)) {
    $where_conditions[] = $type_conditions['sql'];
    $params = array_merge($params, $type_conditions['params']);
}

// Search
if (!empty($_GET['search'])) {
    $search = '%' . $_GET['search'] . '%';
    $where_conditions[] = "(doc.file_name LIKE ? OR doc.description LIKE ? OR doc.document_type LIKE ?)";
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
}

// Specific document type filter (additional)
if (!empty($_GET['doc_type'])) {
    $where_conditions[] = "doc.document_type = ?";
    $params[] = $_GET['doc_type'];
}

// Date range filter
if (!empty($_GET['from_date'])) {
    $where_conditions[] = "DATE(doc.uploaded_at) >= ?";
    $params[] = $_GET['from_date'];
}
if (!empty($_GET['to_date'])) {
    $where_conditions[] = "DATE(doc.uploaded_at) <= ?";
    $params[] = $_GET['to_date'];
}

// Uploaded by filter
if (!empty($_GET['uploaded_by'])) {
    $where_conditions[] = "doc.uploaded_by = ?";
    $params[] = $_GET['uploaded_by'];
}

// Associated with filter
if (!empty($_GET['associated_id'])) {
    $associated_type = $_GET['associated_type'] ?? 'parcel';
    if ($associated_type == 'parcel') {
        $where_conditions[] = "doc.parcel_id = ?";
        $params[] = $_GET['associated_id'];
    } elseif ($associated_type == 'title') {
        $where_conditions[] = "doc.title_id = ?";
        $params[] = $_GET['associated_id'];
    } elseif ($associated_type == 'party') {
        $where_conditions[] = "doc.party_id = ?";
        $params[] = $_GET['associated_id'];
    }
}

$where_clause = "WHERE " . implode(" AND ", $where_conditions);

// Get total count for pagination
$count_sql = "
    SELECT COUNT(*) as total
    FROM documents doc
    LEFT JOIN parcels p ON doc.parcel_id = p.id
    LEFT JOIN titles t ON doc.title_id = t.id
    LEFT JOIN parties party ON doc.party_id = party.id
    $where_clause
";

$total_result = executeQuery($conn, $count_sql, $params);
$total_rows = $total_result->fetch()['total'];
$total_pages = ceil($total_rows / $limit);

// Get documents with all related information
$sql = "
    SELECT 
        doc.*,
        p.id as parcel_id,
        p.parcel_number,
        p.area as parcel_area,
        b.name as boma_name,
        pa.name as payam_name,
        t.id as title_id,
        t.title_number,
        t.title_type,
        party.id as party_id,
        party.name as party_name,
        party.party_type,
        party.national_id,
        u.username as uploaded_by_username
    FROM documents doc
    LEFT JOIN parcels p ON doc.parcel_id = p.id
    LEFT JOIN bomas b ON p.boma_id = b.id
    LEFT JOIN payams pa ON b.payam_id = pa.id
    LEFT JOIN titles t ON doc.title_id = t.id
    LEFT JOIN parties party ON doc.party_id = party.id
    LEFT JOIN users u ON doc.uploaded_by = u.id
    $where_clause
    ORDER BY doc.uploaded_at DESC
    LIMIT ? OFFSET ?
";

$params[] = $limit;
$params[] = $offset;

$result = executeQuery($conn, $sql, $params);
$documents = [];
if ($result) {
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        // Get file size
        if (!empty($row['file_path']) && file_exists($row['file_path'])) {
            $row['file_size'] = filesize($row['file_path']);
        } else {
            $row['file_size'] = 0;
        }
        $documents[] = $row;
    }
}

// ============================================================================
// GET DATA FOR DROPDOWNS
// ============================================================================

try {
    // Get all parcels for dropdown
    $parcels = fetchAll($conn, "
        SELECT p.id, p.parcel_number, 
               b.name as boma_name, pa.name as payam_name
        FROM parcels p
        JOIN bomas b ON p.boma_id = b.id
        JOIN payams pa ON b.payam_id = pa.id
        ORDER BY p.parcel_number
    ");
} catch (Exception $e) {
    error_log("Error loading parcels: " . $e->getMessage());
    $parcels = [];
}

try {
    // Get all titles for dropdown
    $titles = fetchAll($conn, "
        SELECT t.id, t.title_number, t.title_type,
               p.parcel_number
        FROM titles t
        JOIN parcels p ON t.parcel_id = p.id
        ORDER BY t.title_number
    ");
} catch (Exception $e) {
    error_log("Error loading titles: " . $e->getMessage());
    $titles = [];
}

try {
    // Get all parties for dropdown
    $parties = fetchAll($conn, "
        SELECT id, name, party_type, national_id
        FROM parties 
        ORDER BY name
    ");
} catch (Exception $e) {
    error_log("Error loading parties: " . $e->getMessage());
    $parties = [];
}

try {
    // Get users for filter
    $users = fetchAll($conn, "
        SELECT id, username FROM users WHERE is_active = 1 ORDER BY username
    ");
} catch (Exception $e) {
    error_log("Error loading users: " . $e->getMessage());
    $users = [];
}

// Common document types
$document_types = [
    'survey_plan' => 'Survey Plan',
    'parcel_map' => 'Parcel Map',
    'title_deed' => 'Title Deed',
    'certificate_of_title' => 'Certificate of Title',
    'id_document' => 'ID Document',
    'passport' => 'Passport',
    'certificate_of_incorporation' => 'Certificate of Incorporation',
    'business_registration' => 'Business Registration',
    'contract' => 'Contract',
    'agreement' => 'Agreement',
    'tax_receipt' => 'Tax Receipt',
    'payment_receipt' => 'Payment Receipt',
    'court_order' => 'Court Order',
    'other' => 'Other'
];

// ============================================================================
// SUMMARY STATISTICS
// ============================================================================

try {
    $summary = fetchOne($conn, "
        SELECT 
            COUNT(*) as total_documents,
            SUM(CASE WHEN parcel_id IS NOT NULL THEN 1 ELSE 0 END) as parcel_documents,
            SUM(CASE WHEN title_id IS NOT NULL THEN 1 ELSE 0 END) as title_documents,
            SUM(CASE WHEN party_id IS NOT NULL THEN 1 ELSE 0 END) as party_documents,
            COUNT(DISTINCT uploaded_by) as unique_uploaders,
            SUM(CASE WHEN DATE(uploaded_at) = CURDATE() THEN 1 ELSE 0 END) as uploaded_today,
            SUM(CASE WHEN DATE(uploaded_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as uploaded_this_week
        FROM documents
    ");
} catch (Exception $e) {
    error_log("Error loading summary: " . $e->getMessage());
    $summary = [
        'total_documents' => 0,
        'parcel_documents' => 0,
        'title_documents' => 0,
        'party_documents' => 0,
        'unique_uploaders' => 0,
        'uploaded_today' => 0,
        'uploaded_this_week' => 0
    ];
}

try {
    // Document types breakdown
    $type_stats = fetchAll($conn, "
        SELECT 
            document_type,
            COUNT(*) as count,
            SUM(CASE WHEN parcel_id IS NOT NULL THEN 1 ELSE 0 END) as for_parcels,
            SUM(CASE WHEN title_id IS NOT NULL THEN 1 ELSE 0 END) as for_titles,
            SUM(CASE WHEN party_id IS NOT NULL THEN 1 ELSE 0 END) as for_parties
        FROM documents
        GROUP BY document_type
        ORDER BY count DESC
        LIMIT 10
    ");
} catch (Exception $e) {
    error_log("Error loading type stats: " . $e->getMessage());
    $type_stats = [];
}

try {
    // Monthly upload trend
    $monthly_trend = fetchAll($conn, "
        SELECT 
            DATE_FORMAT(uploaded_at, '%Y-%m') as month,
            COUNT(*) as uploads
        FROM documents
        WHERE uploaded_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(uploaded_at, '%Y-%m')
        ORDER BY month DESC
    ");
} catch (Exception $e) {
    error_log("Error loading monthly trend: " . $e->getMessage());
    $monthly_trend = [];
}

// File size formatting function
function formatFileSize($bytes) {
    if ($bytes === 0) return '0 Bytes';
    $k = 1024;
    $sizes = ['Bytes', 'KB', 'MB', 'GB'];
    $i = floor(log($bytes) / log($k));
    return round($bytes / pow($k, $i), 2) . ' ' . $sizes[$i];
}

// Get file icon based on extension
function getFileIcon($filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $icons = [
        'pdf' => 'bi-file-pdf text-danger',
        'doc' => 'bi-file-word text-primary',
        'docx' => 'bi-file-word text-primary',
        'xls' => 'bi-file-excel text-success',
        'xlsx' => 'bi-file-excel text-success',
        'jpg' => 'bi-file-image text-info',
        'jpeg' => 'bi-file-image text-info',
        'png' => 'bi-file-image text-info',
        'gif' => 'bi-file-image text-info',
        'txt' => 'bi-file-text text-secondary',
        'zip' => 'bi-file-zip text-warning',
        'dwg' => 'bi-file-earmark text-danger'
    ];
    return $icons[$ext] ?? 'bi-file-earmark text-secondary';
}
?>

<body data-page="documents" class="documents-page">
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
                            <h1 class="h3 mb-0">Document Management</h1>
                            <p class="text-muted mb-0">Upload, view, and manage all land system documents</p>
                        </div>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#uploadDocumentModal">
                            <i class="bi bi-cloud-upload me-2"></i>Upload Document
                        </button>
                    </div>

                    <!-- Alert Message -->
                    <?php if ($message): ?>
                    <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show mb-4" role="alert">
                        <?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    <?php endif; ?>

                    <!-- Document Type Tabs -->
                    <ul class="nav nav-tabs mb-4">
                        <li class="nav-item">
                            <a class="nav-link <?php echo $type_filter == 'all' ? 'active' : ''; ?>" 
                               href="documents.php?type=all">
                                <i class="bi bi-files"></i> All Documents
                                <span class="badge bg-secondary ms-2"><?php echo $summary['total_documents'] ?? 0; ?></span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $type_filter == 'parcels' ? 'active' : ''; ?>" 
                               href="documents.php?id=parcels">
                                <i class="bi bi-pin-map text-success"></i> Parcel Documents
                                <span class="badge bg-success ms-2"><?php echo $summary['parcel_documents'] ?? 0; ?></span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $type_filter == 'title_deeds' ? 'active' : ''; ?>" 
                               href="documents.php?id=title_deeds">
                                <i class="bi bi-file-text text-primary"></i> Title Deeds
                                <span class="badge bg-primary ms-2"><?php echo $summary['title_documents'] ?? 0; ?></span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $type_filter == 'party' ? 'active' : ''; ?>" 
                               href="documents.php?id=party">
                                <i class="bi bi-people text-warning"></i> Party Documents
                                <span class="badge bg-warning ms-2"><?php echo $summary['party_documents'] ?? 0; ?></span>
                            </a>
                        </li>
                    </ul>

                    <!-- Summary Cards -->
                    <div class="row g-4 mb-5">
                        <div class="col-xl-3 col-md-6">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0 me-3">
                                            <div class="bg-primary bg-opacity-10 rounded-circle p-3">
                                                <i class="bi bi-files text-primary fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Total Documents</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['total_documents'] ?? 0); ?></h3>
                                            <small class="text-muted"><?php echo $summary['uploaded_today'] ?? 0; ?> today</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-xl-3 col-md-6">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0 me-3">
                                            <div class="bg-success bg-opacity-10 rounded-circle p-3">
                                                <i class="bi bi-pin-map text-success fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Parcel Documents</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['parcel_documents'] ?? 0); ?></h3>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-xl-3 col-md-6">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0 me-3">
                                            <div class="bg-info bg-opacity-10 rounded-circle p-3">
                                                <i class="bi bi-file-text text-info fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Title Documents</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['title_documents'] ?? 0); ?></h3>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-xl-3 col-md-6">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0 me-3">
                                            <div class="bg-warning bg-opacity-10 rounded-circle p-3">
                                                <i class="bi bi-people text-warning fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Party Documents</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['party_documents'] ?? 0); ?></h3>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Filters -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-body">
                            <form method="GET" class="row g-3">
                                <input type="hidden" name="type" value="<?php echo htmlspecialchars($type_filter); ?>">
                                <input type="hidden" name="id" value="<?php echo htmlspecialchars($type_filter != 'all' ? $type_filter : ''); ?>">
                                
                                <div class="col-md-3">
                                    <label class="form-label">Search</label>
                                    <input type="text" class="form-control" name="search" 
                                           placeholder="File name, description..."
                                           value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                                </div>
                                
                                <div class="col-md-2">
                                    <label class="form-label">Document Type</label>
                                    <select class="form-select" name="doc_type">
                                        <option value="">All Types</option>
                                        <?php foreach ($document_types as $value => $label): ?>
                                        <option value="<?php echo $value; ?>" <?php echo ($_GET['doc_type'] ?? '') == $value ? 'selected' : ''; ?>>
                                            <?php echo $label; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-2">
                                    <label class="form-label">From Date</label>
                                    <input type="date" class="form-control" name="from_date" 
                                           value="<?php echo htmlspecialchars($_GET['from_date'] ?? ''); ?>">
                                </div>
                                
                                <div class="col-md-2">
                                    <label class="form-label">To Date</label>
                                    <input type="date" class="form-control" name="to_date" 
                                           value="<?php echo htmlspecialchars($_GET['to_date'] ?? ''); ?>">
                                </div>
                                
                                <div class="col-md-3">
                                    <label class="form-label">Uploaded By</label>
                                    <select class="form-select" name="uploaded_by">
                                        <option value="">All Users</option>
                                        <?php foreach ($users as $user): ?>
                                        <option value="<?php echo $user['id']; ?>" <?php echo ($_GET['uploaded_by'] ?? '') == $user['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($user['username']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-search me-2"></i>Apply Filters
                                    </button>
                                    <a href="documents.php?type=<?php echo $type_filter; ?>" class="btn btn-secondary">
                                        <i class="bi bi-x-circle me-2"></i>Clear Filters
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Documents Grid/Table -->
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0 fw-bold">
                                <i class="bi bi-files me-2"></i>
                                <?php echo $type_labels[$type_filter] ?? 'Documents'; ?>
                            </h5>
                            <span class="badge bg-primary"><?php echo number_format($total_rows); ?> Files</span>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>File</th>
                                            <th>Type</th>
                                            <th>Associated With</th>
                                            <th>Description</th>
                                            <th>Version</th>
                                            <th>Uploaded</th>
                                            <th>Size</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($documents)): ?>
                                        <tr>
                                            <td colspan="8" class="text-center py-4 text-muted">
                                                <i class="bi bi-files fs-1 d-block mb-3"></i>
                                                No documents found. 
                                                <a href="#" data-bs-toggle="modal" data-bs-target="#uploadDocumentModal">Click here</a> to upload one.
                                            </td>
                                        </tr>
                                        <?php else: ?>
                                            <?php foreach ($documents as $doc): ?>
                                            <tr>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <i class="bi <?php echo getFileIcon($doc['file_name']); ?> fs-4 me-2"></i>
                                                        <div>
                                                            <span class="fw-medium"><?php echo htmlspecialchars($doc['file_name']); ?></span>
                                                            <br><small class="text-muted">ID: #<?php echo $doc['id']; ?></small>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge bg-info">
                                                        <?php echo $document_types[$doc['document_type']] ?? ucfirst(str_replace('_', ' ', $doc['document_type'])); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($doc['parcel_id']): ?>
                                                        <span class="badge bg-success">Parcel</span>
                                                        <br><small><?php echo htmlspecialchars($doc['parcel_number']); ?></small>
                                                        <?php if (!empty($doc['boma_name'])): ?>
                                                            <br><small class="text-muted"><?php echo $doc['boma_name']; ?></small>
                                                        <?php endif; ?>
                                                    <?php elseif ($doc['title_id']): ?>
                                                        <span class="badge bg-primary">Title</span>
                                                        <br><small><?php echo htmlspecialchars($doc['title_number']); ?></small>
                                                        <br><small class="text-muted"><?php echo ucfirst($doc['title_type']); ?></small>
                                                    <?php elseif ($doc['party_id']): ?>
                                                        <span class="badge bg-warning">Party</span>
                                                        <br><small><?php echo htmlspecialchars($doc['party_name']); ?></small>
                                                        <br><small class="text-muted"><?php echo ucfirst($doc['party_type']); ?></small>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">General</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars(substr($doc['description'] ?? '', 0, 50)); ?>
                                                    <?php if (strlen($doc['description'] ?? '') > 50): ?>...<?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge bg-secondary">v<?php echo $doc['version']; ?></span>
                                                </td>
                                                <td>
                                                    <?php echo date('d M Y', strtotime($doc['uploaded_at'])); ?>
                                                    <br><small class="text-muted">by <?php echo htmlspecialchars($doc['uploaded_by_username'] ?? 'System'); ?></small>
                                                </td>
                                                <td>
                                                    <?php echo formatFileSize($doc['file_size']); ?>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <a href="<?php echo htmlspecialchars($doc['file_path']); ?>" 
                                                           class="btn btn-outline-success" target="_blank">
                                                            <i class="bi bi-eye"></i>
                                                        </a>
                                                        <a href="<?php echo htmlspecialchars($doc['file_path']); ?>" 
                                                           class="btn btn-outline-primary" download>
                                                            <i class="bi bi-download"></i>
                                                        </a>
                                                        <button class="btn btn-outline-info" 
                                                                onclick="editDocument(<?php echo htmlspecialchars(json_encode($doc)); ?>)"
                                                                data-bs-toggle="modal" data-bs-target="#editDocumentModal">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                        <a href="?delete=<?php echo $doc['id']; ?>&type=<?php echo $type_filter; ?>" 
                                                           class="btn btn-outline-danger"
                                                           onclick="return confirm('Delete this document? This action cannot be undone.')">
                                                            <i class="bi bi-trash"></i>
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        
                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                        <div class="card-footer bg-white py-3">
                            <nav>
                                <ul class="pagination justify-content-center mb-0">
                                    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                                            Previous
                                        </a>
                                    </li>
                                    
                                    <?php for ($i = max(1, $page - 2); $i <= min($page + 2, $total_pages); $i++): ?>
                                    <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    </li>
                                    <?php endfor; ?>
                                    
                                    <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                                            Next
                                        </a>
                                    </li>
                                </ul>
                            </nav>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Statistics Row -->
                    <div class="row mt-4">
                        <div class="col-md-7">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h5 class="card-title mb-0 fw-bold">
                                        <i class="bi bi-pie-chart text-info me-2"></i>
                                        Document Types Breakdown
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($type_stats)): ?>
                                        <p class="text-muted text-center">No document type data available</p>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-sm">
                                                <thead>
                                                    <tr>
                                                        <th>Document Type</th>
                                                        <th>Total</th>
                                                        <th>Parcels</th>
                                                        <th>Titles</th>
                                                        <th>Parties</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($type_stats as $stat): ?>
                                                    <tr>
                                                        <td><?php echo $document_types[$stat['document_type']] ?? ucfirst(str_replace('_', ' ', $stat['document_type'])); ?></td>
                                                        <td><span class="badge bg-primary"><?php echo $stat['count']; ?></span></td>
                                                        <td><span class="badge bg-success"><?php echo $stat['for_parcels']; ?></span></td>
                                                        <td><span class="badge bg-info"><?php echo $stat['for_titles']; ?></span></td>
                                                        <td><span class="badge bg-warning"><?php echo $stat['for_parties']; ?></span></td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-5">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h5 class="card-title mb-0 fw-bold">
                                        <i class="bi bi-graph-up text-success me-2"></i>
                                        Upload Trend (12 Months)
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <canvas id="monthlyChart" height="200"></canvas>
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

    <!-- Upload Document Modal -->
    <div class="modal fade" id="uploadDocumentModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="upload">
                    <div class="modal-header">
                        <h5 class="modal-title">Upload New Document</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <ul class="nav nav-tabs mb-3" id="uploadTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="file-tab" data-bs-toggle="tab" data-bs-target="#file" type="button" role="tab">File & Type</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="association-tab" data-bs-toggle="tab" data-bs-target="#association" type="button" role="tab">Association</button>
                            </li>
                        </ul>
                        
                        <div class="tab-content" id="uploadTabsContent">
                            <!-- File & Type Tab -->
                            <div class="tab-pane fade show active" id="file" role="tabpanel">
                                <div class="row">
                                    <div class="col-md-12 mb-3">
                                        <label class="form-label">Select File <span class="text-danger">*</span></label>
                                        <input type="file" class="form-control" name="document_file" required>
                                        <small class="text-muted">Max file size: 10MB. Allowed: PDF, DOC, DOCX, JPG, PNG, DWG</small>
                                    </div>
                                    
                                    <div class="col-md-12 mb-3">
                                        <label class="form-label">Document Type <span class="text-danger">*</span></label>
                                        <select class="form-select" name="document_type" required>
                                            <option value="">-- Select type --</option>
                                            <?php foreach ($document_types as $value => $label): ?>
                                            <option value="<?php echo $value; ?>"><?php echo $label; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="col-12 mb-3">
                                        <label class="form-label">Description</label>
                                        <textarea class="form-control" name="description" rows="3" 
                                                  placeholder="Brief description of the document..."></textarea>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Association Tab -->
                            <div class="tab-pane fade" id="association" role="tabpanel">
                                <div class="row">
                                    <div class="col-md-12 mb-3">
                                        <label class="form-label">Associate with Parcel (optional)</label>
                                        <select class="form-select" name="parcel_id">
                                            <option value="">-- None --</option>
                                            <?php foreach ($parcels as $parcel): ?>
                                            <option value="<?php echo $parcel['id']; ?>">
                                                <?php echo $parcel['parcel_number']; ?> - 
                                                <?php echo htmlspecialchars($parcel['boma_name']); ?>, 
                                                <?php echo htmlspecialchars($parcel['payam_name']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="col-md-12 mb-3">
                                        <label class="form-label">Associate with Title (optional)</label>
                                        <select class="form-select" name="title_id">
                                            <option value="">-- None --</option>
                                            <?php foreach ($titles as $title): ?>
                                            <option value="<?php echo $title['id']; ?>">
                                                <?php echo $title['title_number']; ?> (<?php echo ucfirst($title['title_type']); ?>) - 
                                                Parcel: <?php echo $title['parcel_number']; ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="col-md-12 mb-3">
                                        <label class="form-label">Associate with Party (optional)</label>
                                        <select class="form-select" name="party_id">
                                            <option value="">-- None --</option>
                                            <?php foreach ($parties as $party): ?>
                                            <option value="<?php echo $party['id']; ?>">
                                                <?php echo htmlspecialchars($party['name']); ?> 
                                                (<?php echo ucfirst($party['party_type']); ?>)
                                                <?php if ($party['national_id']): ?> - ID: <?php echo $party['national_id']; ?><?php endif; ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Upload Document</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Document Modal -->
    <div class="modal fade" id="editDocumentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="document_id" id="editDocumentId">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Document Description</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">File Name</label>
                            <input type="text" class="form-control" id="editFileName" readonly disabled>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Document Type</label>
                            <input type="text" class="form-control" id="editDocType" readonly disabled>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" id="editDescription" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Description</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Edit document function
        function editDocument(doc) {
            document.getElementById('editDocumentId').value = doc.id;
            document.getElementById('editFileName').value = doc.file_name || '';
            
            let docType = doc.document_type || '';
            <?php foreach ($document_types as $value => $label): ?>
            if (docType === '<?php echo $value; ?>') {
                document.getElementById('editDocType').value = '<?php echo $label; ?>';
            }
            <?php endforeach; ?>
            
            document.getElementById('editDescription').value = doc.description || '';
        }
        
        // Monthly trend chart
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('monthlyChart')?.getContext('2d');
            if (ctx) {
                const months = <?php echo json_encode(array_column($monthly_trend, 'month')); ?>;
                const uploads = <?php echo json_encode(array_column($monthly_trend, 'uploads')); ?>;
                
                if (months.length > 0) {
                    new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: months,
                            datasets: [{
                                label: 'Documents Uploaded',
                                data: uploads,
                                borderColor: 'rgb(75, 192, 192)',
                                backgroundColor: 'rgba(75, 192, 192, 0.1)',
                                tension: 0.1,
                                fill: true
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        stepSize: 1
                                    }
                                }
                            }
                        }
                    });
                } else {
                    ctx.canvas.parentNode.innerHTML = '<p class="text-muted text-center">No monthly data available</p>';
                }
            }
        });
    </script>

    <style>
        .table td {
            vertical-align: middle;
        }
        
        .btn-group .btn {
            padding: 0.25rem 0.5rem;
        }
        
        .modal-body {
            max-height: 70vh;
            overflow-y: auto;
        }
        
        .badge {
            font-size: 0.85rem;
        }
        
        .nav-tabs .nav-link {
            color: #495057;
        }
        
        .nav-tabs .nav-link.active {
            font-weight: 600;
            color: #0d6efd;
        }
        
        .nav-tabs .nav-link .badge {
            font-size: 0.7rem;
        }
        
        .rounded-circle {
            width: 60px;
            height: 60px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        
        .bi-file-pdf { color: #dc3545; }
        .bi-file-word { color: #0d6efd; }
        .bi-file-excel { color: #198754; }
        .bi-file-image { color: #0dcaf0; }
        .bi-file-zip { color: #ffc107; }
    </style>

</body>
</html>