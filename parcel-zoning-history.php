<?php
ini_set('display_errors', 1); 
error_reporting(E_ALL);

// parcel-zoning-history.php
include("head.php");
require_once 'db_connection.php';

// ============================================================================
// SAFE QUERY FUNCTIONS
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
// HANDLE ACTIONS
// ============================================================================

$message = '';
$messageType = '';

// Add new zoning history entry
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    
    $parcel_id = $_POST['parcel_id'];
    $zoning_id = $_POST['zoning_id'];
    $start_date = $_POST['start_date'];
    $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
    $reason = $_POST['reason'] ?? null;
    $application_id = !empty($_POST['application_id']) ? $_POST['application_id'] : null;
    
    try {
        // Start transaction
        $conn->beginTransaction();
        
        // Check if there's an active zoning for this parcel
        if (empty($end_date)) {
            $active = safeFetchOne($conn, "
                SELECT id FROM parcel_zoning_history 
                WHERE parcel_id = ? AND end_date IS NULL
            ", [$parcel_id]);
            
            if ($active) {
                // End the current active zoning
                executeQuery($conn, "
                    UPDATE parcel_zoning_history 
                    SET end_date = DATE_SUB(?, INTERVAL 1 DAY)
                    WHERE id = ?
                ", [$start_date, $active['id']]);
            }
        }
        
        // Insert new zoning history
        executeQuery($conn, "
            INSERT INTO parcel_zoning_history (
                parcel_id, zoning_id, start_date, end_date, reason, application_id, created_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?, NOW()
            )
        ", [$parcel_id, $zoning_id, $start_date, $end_date, $reason, $application_id]);
        
        // Update the parcel's current zoning if this is active
        if (empty($end_date)) {
            executeQuery($conn, "
                UPDATE parcels SET current_zoning_id = ? WHERE id = ?
            ", [$zoning_id, $parcel_id]);
        }
        
        $conn->commit();
        
        $message = "Zoning history added successfully";
        $messageType = "success";
        
    } catch (Exception $e) {
        $conn->rollBack();
        $message = "Error adding zoning history: " . $e->getMessage();
        $messageType = "danger";
    }
}

// Update zoning history entry
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit') {
    
    $history_id = $_POST['history_id'];
    $zoning_id = $_POST['zoning_id'];
    $start_date = $_POST['start_date'];
    $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
    $reason = $_POST['reason'] ?? null;
    
    try {
        // Get the parcel_id for this history entry
        $history = safeFetchOne($conn, "
            SELECT parcel_id FROM parcel_zoning_history WHERE id = ?
        ", [$history_id]);
        
        if (!$history) {
            throw new Exception("History entry not found");
        }
        
        // Start transaction
        $conn->beginTransaction();
        
        // Update the history entry
        executeQuery($conn, "
            UPDATE parcel_zoning_history 
            SET zoning_id = ?, start_date = ?, end_date = ?, reason = ?
            WHERE id = ?
        ", [$zoning_id, $start_date, $end_date, $reason, $history_id]);
        
        // If this is the current zoning (no end date), update the parcel
        if (empty($end_date)) {
            // First, check if there's any other active zoning for this parcel
            $otherActive = safeFetchOne($conn, "
                SELECT id FROM parcel_zoning_history 
                WHERE parcel_id = ? AND id != ? AND end_date IS NULL
            ", [$history['parcel_id'], $history_id]);
            
            if ($otherActive) {
                // End the other active zoning
                executeQuery($conn, "
                    UPDATE parcel_zoning_history 
                    SET end_date = DATE_SUB(?, INTERVAL 1 DAY)
                    WHERE id = ?
                ", [$start_date, $otherActive['id']]);
            }
            
            // Update parcel current zoning
            executeQuery($conn, "
                UPDATE parcels SET current_zoning_id = ? WHERE id = ?
            ", [$zoning_id, $history['parcel_id']]);
        } else {
            // If this entry is being ended, check if it was the current zoning
            $isCurrent = safeFetchOne($conn, "
                SELECT id FROM parcel_zoning_history 
                WHERE parcel_id = ? AND end_date IS NULL
            ", [$history['parcel_id']]);
            
            if (!$isCurrent) {
                // No active zoning, set the most recent as current
                $latest = safeFetchOne($conn, "
                    SELECT zoning_id FROM parcel_zoning_history 
                    WHERE parcel_id = ? AND start_date <= CURDATE()
                    ORDER BY start_date DESC LIMIT 1
                ", [$history['parcel_id']]);
                
                if ($latest) {
                    executeQuery($conn, "
                        UPDATE parcels SET current_zoning_id = ? WHERE id = ?
                    ", [$latest['zoning_id'], $history['parcel_id']]);
                }
            }
        }
        
        $conn->commit();
        
        $message = "Zoning history updated successfully";
        $messageType = "success";
        
    } catch (Exception $e) {
        $conn->rollBack();
        $message = "Error updating zoning history: " . $e->getMessage();
        $messageType = "danger";
    }
}

// Delete zoning history entry
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $history_id = $_GET['delete'];
    
    try {
        // Get the history entry details
        $history = safeFetchOne($conn, "
            SELECT * FROM parcel_zoning_history WHERE id = ?
        ", [$history_id]);
        
        if (!$history) {
            throw new Exception("History entry not found");
        }
        
        // Start transaction
        $conn->beginTransaction();
        
        // Delete the history entry
        executeQuery($conn, "DELETE FROM parcel_zoning_history WHERE id = ?", [$history_id]);
        
        // If this was the current zoning (no end date), update parcel's current zoning
        if (empty($history['end_date'])) {
            // Find the most recent active zoning
            $latest = safeFetchOne($conn, "
                SELECT zoning_id FROM parcel_zoning_history 
                WHERE parcel_id = ? AND id != ? AND start_date <= CURDATE()
                ORDER BY start_date DESC LIMIT 1
            ", [$history['parcel_id'], $history_id]);
            
            if ($latest) {
                executeQuery($conn, "
                    UPDATE parcels SET current_zoning_id = ? WHERE id = ?
                ", [$latest['zoning_id'], $history['parcel_id']]);
            } else {
                // No zoning history, set to null
                executeQuery($conn, "
                    UPDATE parcels SET current_zoning_id = NULL WHERE id = ?
                ", [$history['parcel_id']]);
            }
        }
        
        $conn->commit();
        
        $message = "Zoning history deleted successfully";
        $messageType = "success";
        
    } catch (Exception $e) {
        $conn->rollBack();
        $message = "Error deleting zoning history: " . $e->getMessage();
        $messageType = "danger";
    }
}

// ============================================================================
// GET FILTERS
// ============================================================================

$parcel_filter = $_GET['parcel_id'] ?? '';
$zoning_filter = $_GET['zoning_id'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// ============================================================================
// GET ZONING HISTORY WITH DETAILS
// ============================================================================

$where_conditions = [];
$params = [];

if (!empty($parcel_filter)) {
    $where_conditions[] = "p.id = ?";
    $params[] = $parcel_filter;
}

if (!empty($zoning_filter)) {
    $where_conditions[] = "z.id = ?";
    $params[] = $zoning_filter;
}

if (!empty($date_from)) {
    $where_conditions[] = "pzh.start_date >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $where_conditions[] = "pzh.start_date <= ?";
    $params[] = $date_to;
}

$where_clause = empty($where_conditions) ? "" : "WHERE " . implode(" AND ", $where_conditions);

$history = safeFetchAll($conn, "
    SELECT 
        pzh.*,
        p.parcel_number,
        p.area,
        p.location_description,
        b.name as boma_name,
        py.name as payam_name,
        c.name as county_name,
        s.name as state_name,
        z.zone_code,
        z.zone_name,
        z.description as zone_description,
        z.allowed_uses,
        -- Previous zoning
        prev_z.zone_code as prev_zone_code,
        prev_z.zone_name as prev_zone_name,
        -- Application reference
        a.id as application_id_ref,
        a.application_type,
        a.status as application_status
    FROM parcel_zoning_history pzh
    JOIN parcels p ON pzh.parcel_id = p.id
    JOIN zoning z ON pzh.zoning_id = z.id
    LEFT JOIN bomas b ON p.boma_id = b.id
    LEFT JOIN payams py ON b.payam_id = py.id
    LEFT JOIN counties c ON py.county_id = c.id
    LEFT JOIN states s ON c.state_id = s.id
    LEFT JOIN parcel_zoning_history prev ON pzh.parcel_id = prev.parcel_id 
        AND prev.start_date < pzh.start_date
        AND (prev.end_date IS NULL OR prev.end_date >= pzh.start_date)
    LEFT JOIN zoning prev_z ON prev.zoning_id = prev_z.id
    LEFT JOIN applications a ON pzh.application_id = a.id
    $where_clause
    ORDER BY pzh.start_date DESC, pzh.created_at DESC
", $params, []);

// ============================================================================
// GET SUMMARY STATISTICS
// ============================================================================

$summary = safeFetchOne($conn, "
    SELECT 
        COUNT(*) as total_changes,
        COUNT(DISTINCT parcel_id) as parcels_changed,
        COUNT(DISTINCT zoning_id) as zoning_types_used,
        COUNT(CASE WHEN end_date IS NULL THEN 1 END) as current_assignments,
        MIN(start_date) as first_change,
        MAX(start_date) as last_change
    FROM parcel_zoning_history
");

// Get most active parcels
$active_parcels = safeFetchAll($conn, "
    SELECT 
        p.parcel_number,
        COUNT(*) as change_count,
        MAX(pzh.start_date) as last_change
    FROM parcel_zoning_history pzh
    JOIN parcels p ON pzh.parcel_id = p.id
    GROUP BY p.id, p.parcel_number
    HAVING change_count > 1
    ORDER BY change_count DESC
    LIMIT 10
", [], []);

// Get zoning type popularity
$zoning_popularity = safeFetchAll($conn, "
    SELECT 
        z.zone_code,
        z.zone_name,
        COUNT(*) as assignment_count,
        COUNT(CASE WHEN pzh.end_date IS NULL THEN 1 END) as current_count
    FROM parcel_zoning_history pzh
    JOIN zoning z ON pzh.zoning_id = z.id
    GROUP BY z.id, z.zone_code, z.zone_name
    ORDER BY assignment_count DESC
", [], []);

// Get monthly trend
$monthly_trend = safeFetchAll($conn, "
    SELECT 
        DATE_FORMAT(start_date, '%Y-%m') as month,
        COUNT(*) as changes
    FROM parcel_zoning_history
    WHERE start_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(start_date, '%Y-%m')
    ORDER BY month DESC
", [], []);

// ============================================================================
// GET DROPDOWN DATA
// ============================================================================

// Get all parcels for dropdown
$parcels = safeFetchAll($conn, "
    SELECT 
        p.id, 
        p.parcel_number, 
        b.name as boma_name,
        z.zone_code as current_zone
    FROM parcels p
    LEFT JOIN bomas b ON p.boma_id = b.id
    LEFT JOIN zoning z ON p.current_zoning_id = z.id
    ORDER BY p.parcel_number
", [], []);

// Get all zoning types for dropdown
$zoning_types = safeFetchAll($conn, "
    SELECT id, zone_code, zone_name 
    FROM zoning 
    ORDER BY zone_code
", [], []);

// Get applications related to zoning changes
$applications = safeFetchAll($conn, "
    SELECT 
        a.id, 
        a.application_type, 
        a.status,
        p.parcel_number
    FROM applications a
    JOIN parcels p ON a.parcel_id = p.id
    WHERE a.application_type IN ('change_of_use', 'subdivision', 'consolidation')
    ORDER BY a.created_at DESC
    LIMIT 50
", [], []);
?>

<body data-page="parcel-zoning-history" class="parcel-zoning-history-page">
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
                                    <li class="breadcrumb-item"><a href="zoning.php">Zoning</a></li>
                                    <li class="breadcrumb-item active">Zoning History</li>
                                </ol>
                            </nav>
                            <h1 class="h3 mb-0">Parcel Zoning History</h1>
                            <p class="text-muted mb-0">Track zoning changes and land use classifications over time</p>
                        </div>
                        <div>
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addHistoryModal">
                                <i class="bi bi-plus-circle me-2"></i>Record Zoning Change
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

                    <!-- Summary Cards -->
                    <div class="row g-4 mb-4">
                        <div class="col-xl-3 col-md-6">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0 me-3">
                                            <div class="bg-primary bg-opacity-10 rounded-circle p-3">
                                                <i class="bi bi-arrow-repeat text-primary fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Total Changes</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['total_changes'] ?? 0); ?></h3>
                                            <small class="text-muted"><?php echo $summary['parcels_changed'] ?? 0; ?> parcels affected</small>
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
                                                <i class="bi bi-check-circle text-success fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Current Assignments</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['current_assignments'] ?? 0); ?></h3>
                                            <small class="text-muted">Active zoning</small>
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
                                                <i class="bi bi-grid text-info fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Zoning Types</h6>
                                            <h3 class="mb-0 fw-bold"><?php echo number_format($summary['zoning_types_used'] ?? 0); ?></h3>
                                            <small class="text-muted">Different classifications</small>
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
                                                <i class="bi bi-calendar-range text-warning fs-4"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Date Range</h6>
                                            <h3 class="mb-0 fw-bold">
                                                <?php 
                                                if ($summary['first_change']) {
                                                    echo date('d/m/Y', strtotime($summary['first_change']));
                                                } else {
                                                    echo 'N/A';
                                                }
                                                ?>
                                            </h3>
                                            <small class="text-muted">to <?php echo $summary['last_change'] ? date('d/m/Y', strtotime($summary['last_change'])) : 'present'; ?></small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Charts Row -->
                    <div class="row g-4 mb-4">
                        <div class="col-xl-6">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h6 class="card-title mb-0 fw-bold">
                                        <i class="bi bi-bar-chart me-2 text-primary"></i>Monthly Zoning Changes
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <canvas id="monthlyChart" style="height: 250px;"></canvas>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-xl-6">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h6 class="card-title mb-0 fw-bold">
                                        <i class="bi bi-pie-chart me-2 text-success"></i>Zoning Type Distribution
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <canvas id="zoningChart" style="height: 250px;"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Most Active Parcels -->
                    <?php if (!empty($active_parcels)): ?>
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white py-3">
                                    <h6 class="card-title mb-0 fw-bold">
                                        <i class="bi bi-activity me-2 text-warning"></i>Most Active Parcels
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <?php foreach ($active_parcels as $parcel): ?>
                                        <div class="col-md-3 mb-2">
                                            <div class="border rounded p-2 text-center">
                                                <strong><?php echo htmlspecialchars($parcel['parcel_number']); ?></strong>
                                                <br>
                                                <span class="badge bg-primary"><?php echo $parcel['change_count']; ?> changes</span>
                                                <br>
                                                <small class="text-muted">Last: <?php echo date('d/m/Y', strtotime($parcel['last_change'])); ?></small>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Filters -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-body">
                            <form method="GET" class="row g-3">
                                <div class="col-md-3">
                                    <label class="form-label">Parcel</label>
                                    <select class="form-select" name="parcel_id">
                                        <option value="">All Parcels</option>
                                        <?php foreach ($parcels as $p): ?>
                                        <option value="<?php echo $p['id']; ?>" <?php echo $parcel_filter == $p['id'] ? 'selected' : ''; ?>>
                                            <?php echo $p['parcel_number']; ?> - <?php echo htmlspecialchars($p['boma_name']); ?>
                                            <?php if ($p['current_zone']): ?>(<?php echo $p['current_zone']; ?>)<?php endif; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-3">
                                    <label class="form-label">Zoning Type</label>
                                    <select class="form-select" name="zoning_id">
                                        <option value="">All Zoning</option>
                                        <?php foreach ($zoning_types as $z): ?>
                                        <option value="<?php echo $z['id']; ?>" <?php echo $zoning_filter == $z['id'] ? 'selected' : ''; ?>>
                                            <?php echo $z['zone_code']; ?> - <?php echo $z['zone_name']; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-2">
                                    <label class="form-label">Date From</label>
                                    <input type="date" class="form-control" name="date_from" value="<?php echo $date_from; ?>">
                                </div>
                                
                                <div class="col-md-2">
                                    <label class="form-label">Date To</label>
                                    <input type="date" class="form-control" name="date_to" value="<?php echo $date_to; ?>">
                                </div>
                                
                                <div class="col-md-2 d-flex align-items-end">
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="bi bi-search me-2"></i>Filter
                                    </button>
                                </div>
                                
                                <div class="col-12">
                                    <a href="parcel-zoning-history.php" class="btn btn-secondary">
                                        <i class="bi bi-x-circle me-2"></i>Clear Filters
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Timeline View -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-white py-3">
                            <h5 class="card-title mb-0 fw-bold">
                                <i class="bi bi-clock-history me-2 text-primary"></i>Zoning Change Timeline
                                <span class="badge bg-secondary ms-2"><?php echo count($history); ?> records</span>
                            </h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>Date</th>
                                            <th>Parcel</th>
                                            <th>Location</th>
                                            <th>From</th>
                                            <th>To</th>
                                            <th>Duration</th>
                                            <th>Status</th>
                                            <th>Reason/Application</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($history)): ?>
                                        <tr>
                                            <td colspan="9" class="text-center py-4 text-muted">
                                                <i class="bi bi-inbox fs-1 d-block mb-3"></i>
                                                No zoning history found
                                            </td>
                                        </tr>
                                        <?php else: ?>
                                            <?php foreach ($history as $h): ?>
                                            <?php 
                                            $is_current = empty($h['end_date']);
                                            $start = new DateTime($h['start_date']);
                                            $end = $h['end_date'] ? new DateTime($h['end_date']) : new DateTime();
                                            $duration = $start->diff($end);
                                            $duration_text = '';
                                            if ($duration->y > 0) $duration_text .= $duration->y . 'y ';
                                            if ($duration->m > 0) $duration_text .= $duration->m . 'm ';
                                            if ($duration->d > 0) $duration_text .= $duration->d . 'd';
                                            if (empty($duration_text)) $duration_text = '<1 day';
                                            ?>
                                            <tr class="<?php echo $is_current ? 'table-success' : ''; ?>">
                                                <td>
                                                    <strong><?php echo date('d/m/Y', strtotime($h['start_date'])); ?></strong>
                                                    <?php if ($h['end_date']): ?>
                                                    <br><small class="text-muted">to <?php echo date('d/m/Y', strtotime($h['end_date'])); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <a href="parcels-view.php?id=<?php echo $h['parcel_id']; ?>" class="text-decoration-none">
                                                        <strong><?php echo $h['parcel_number']; ?></strong>
                                                    </a>
                                                    <br><small class="text-muted"><?php echo number_format($h['area'], 2); ?> m²</small>
                                                </td>
                                                <td>
                                                    <small>
                                                        <?php echo $h['boma_name'] ? htmlspecialchars($h['boma_name']) : '-'; ?><br>
                                                        <?php echo $h['payam_name'] ? htmlspecialchars($h['payam_name']) : '-'; ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <?php if ($h['prev_zone_code']): ?>
                                                    <span class="badge bg-secondary">
                                                        <?php echo $h['prev_zone_code']; ?>
                                                    </span>
                                                    <br><small><?php echo $h['prev_zone_name']; ?></small>
                                                    <?php else: ?>
                                                    <span class="text-muted">Initial</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?php 
                                                        echo $h['zone_code'] == 'R1' ? 'success' : 
                                                            ($h['zone_code'] == 'C1' ? 'warning' : 
                                                            ($h['zone_code'] == 'A1' ? 'info' : 'primary')); 
                                                    ?>">
                                                        <?php echo $h['zone_code']; ?>
                                                    </span>
                                                    <br><small><?php echo $h['zone_name']; ?></small>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?php echo $is_current ? 'success' : 'secondary'; ?>">
                                                        <?php echo $duration_text; ?>
                                                    </span>
                                                    <?php if ($is_current): ?>
                                                    <br><small class="text-success">Current</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($is_current): ?>
                                                    <span class="badge bg-success">Active</span>
                                                    <?php else: ?>
                                                    <span class="badge bg-secondary">Historical</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($h['reason']): ?>
                                                    <small><?php echo htmlspecialchars(substr($h['reason'], 0, 50)) . (strlen($h['reason']) > 50 ? '...' : ''); ?></small>
                                                    <?php endif; ?>
                                                    <?php if ($h['application_id_ref']): ?>
                                                    <br>
                                                    <a href="applications-view.php?id=<?php echo $h['application_id_ref']; ?>" class="small">
                                                        <i class="bi bi-box-arrow-up-right"></i> App #<?php echo $h['application_id_ref']; ?>
                                                    </a>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <button class="btn btn-outline-primary" 
                                                                onclick="viewHistory(<?php echo htmlspecialchars(json_encode($h)); ?>)"
                                                                data-bs-toggle="modal" data-bs-target="#viewHistoryModal">
                                                            <i class="bi bi-eye"></i>
                                                        </button>
                                                        <button class="btn btn-outline-warning" 
                                                                onclick="editHistory(<?php echo htmlspecialchars(json_encode($h)); ?>)"
                                                                data-bs-toggle="modal" data-bs-target="#editHistoryModal">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                        <a href="?delete=<?php echo $h['id']; ?>" 
                                                           class="btn btn-outline-danger"
                                                           onclick="return confirm('Delete this zoning history entry? This action cannot be undone.')">
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
                    </div>

                    <!-- Zoning Popularity Table -->
                    <?php if (!empty($zoning_popularity)): ?>
                    <div class="card border-0 shadow-sm mt-4">
                        <div class="card-header bg-white py-3">
                            <h6 class="card-title mb-0 fw-bold">
                                <i class="bi bi-grid me-2 text-info"></i>Zoning Type Popularity
                            </h6>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-sm mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>Zone Code</th>
                                            <th>Zone Name</th>
                                            <th>Total Assignments</th>
                                            <th>Currently Active</th>
                                            <th>% Current</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($zoning_popularity as $zp): ?>
                                        <tr>
                                            <td><span class="badge bg-primary"><?php echo $zp['zone_code']; ?></span></td>
                                            <td><?php echo $zp['zone_name']; ?></td>
                                            <td><?php echo $zp['assignment_count']; ?></td>
                                            <td><?php echo $zp['current_count']; ?></td>
                                            <td>
                                                <?php 
                                                $percent = $zp['assignment_count'] > 0 
                                                    ? round(($zp['current_count'] / $zp['assignment_count']) * 100, 1) 
                                                    : 0;
                                                ?>
                                                <div class="progress" style="height: 20px;">
                                                    <div class="progress-bar bg-success" style="width: <?php echo $percent; ?>%">
                                                        <?php echo $percent; ?>%
                                                    </div>
                                                </div>
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

    <!-- Add History Modal -->
    <div class="modal fade" id="addHistoryModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="add">
                    <div class="modal-header">
                        <h5 class="modal-title">Record Zoning Change</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Parcel <span class="text-danger">*</span></label>
                                <select class="form-select" name="parcel_id" required>
                                    <option value="">Select Parcel</option>
                                    <?php foreach ($parcels as $p): ?>
                                    <option value="<?php echo $p['id']; ?>">
                                        <?php echo $p['parcel_number']; ?> - <?php echo htmlspecialchars($p['boma_name']); ?>
                                        <?php if ($p['current_zone']): ?>(Current: <?php echo $p['current_zone']; ?>)<?php endif; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">New Zoning <span class="text-danger">*</span></label>
                                <select class="form-select" name="zoning_id" required>
                                    <option value="">Select Zoning</option>
                                    <?php foreach ($zoning_types as $z): ?>
                                    <option value="<?php echo $z['id']; ?>">
                                        <?php echo $z['zone_code']; ?> - <?php echo $z['zone_name']; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Start Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="start_date" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">End Date</label>
                                <input type="date" class="form-control" name="end_date">
                                <small class="text-muted">Leave blank for current zoning</small>
                            </div>
                            
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Reason for Change</label>
                                <textarea class="form-control" name="reason" rows="3" 
                                          placeholder="Explain reason for zoning change..."></textarea>
                            </div>
                            
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Related Application</label>
                                <select class="form-select" name="application_id">
                                    <option value="">None</option>
                                    <?php foreach ($applications as $a): ?>
                                    <option value="<?php echo $a['id']; ?>">
                                        #<?php echo $a['id']; ?> - <?php echo ucfirst(str_replace('_', ' ', $a['application_type'])); ?> 
                                        (<?php echo $a['parcel_number']; ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Record Change</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit History Modal -->
    <div class="modal fade" id="editHistoryModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="history_id" id="edit_history_id">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Zoning History</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Parcel</label>
                                <input type="text" class="form-control" id="edit_parcel" readonly>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Zoning <span class="text-danger">*</span></label>
                                <select class="form-select" name="zoning_id" id="edit_zoning_id" required>
                                    <?php foreach ($zoning_types as $z): ?>
                                    <option value="<?php echo $z['id']; ?>">
                                        <?php echo $z['zone_code']; ?> - <?php echo $z['zone_name']; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Start Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="start_date" id="edit_start_date" required>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">End Date</label>
                                <input type="date" class="form-control" name="end_date" id="edit_end_date">
                            </div>
                            
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Reason</label>
                                <textarea class="form-control" name="reason" id="edit_reason" rows="3"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update History</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View History Modal -->
    <div class="modal fade" id="viewHistoryModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Zoning History Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <table class="table table-borderless">
                        <tr>
                            <th width="40%">Parcel:</th>
                            <td id="view_parcel"></td>
                        </tr>
                        <tr>
                            <th>Location:</th>
                            <td id="view_location"></td>
                        </tr>
                        <tr>
                            <th>Zoning:</th>
                            <td id="view_zoning"></td>
                        </tr>
                        <tr>
                            <th>Start Date:</th>
                            <td id="view_start"></td>
                        </tr>
                        <tr>
                            <th>End Date:</th>
                            <td id="view_end"></td>
                        </tr>
                        <tr>
                            <th>Duration:</th>
                            <td id="view_duration"></td>
                        </tr>
                        <tr>
                            <th>Status:</th>
                            <td id="view_status"></td>
                        </tr>
                        <tr>
                            <th>Reason:</th>
                            <td id="view_reason"></td>
                        </tr>
                        <tr>
                            <th>Application:</th>
                            <td id="view_application"></td>
                        </tr>
                    </table>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <script>
        // Initialize charts
        document.addEventListener('DOMContentLoaded', function() {
            // Monthly Chart
            const monthlyCtx = document.getElementById('monthlyChart')?.getContext('2d');
            if (monthlyCtx && <?php echo !empty($monthly_trend) ? 'true' : 'false'; ?>) {
                new Chart(monthlyCtx, {
                    type: 'line',
                    data: {
                        labels: [
                            <?php foreach (array_reverse($monthly_trend) as $m): ?>
                            '<?php echo $m['month']; ?>',
                            <?php endforeach; ?>
                        ],
                        datasets: [{
                            label: 'Zoning Changes',
                            data: [
                                <?php foreach (array_reverse($monthly_trend) as $m): ?>
                                <?php echo $m['changes']; ?>,
                                <?php endforeach; ?>
                            ],
                            borderColor: '#0d6efd',
                            backgroundColor: 'rgba(13, 110, 253, 0.1)',
                            tension: 0.4,
                            fill: true
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false
                    }
                });
            }
            
            // Zoning Distribution Chart
            const zoningCtx = document.getElementById('zoningChart')?.getContext('2d');
            if (zoningCtx && <?php echo !empty($zoning_popularity) ? 'true' : 'false'; ?>) {
                new Chart(zoningCtx, {
                    type: 'doughnut',
                    data: {
                        labels: [
                            <?php foreach ($zoning_popularity as $zp): ?>
                            '<?php echo $zp['zone_code']; ?>',
                            <?php endforeach; ?>
                        ],
                        datasets: [{
                            data: [
                                <?php foreach ($zoning_popularity as $zp): ?>
                                <?php echo $zp['assignment_count']; ?>,
                                <?php endforeach; ?>
                            ],
                            backgroundColor: [
                                '#0d6efd', '#198754', '#ffc107', '#dc3545', '#6c757d', '#0dcaf0', '#6610f2'
                            ]
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'right'
                            }
                        }
                    }
                });
            }
        });

        // View history details
        function viewHistory(history) {
            document.getElementById('view_parcel').textContent = history.parcel_number;
            document.getElementById('view_location').textContent = 
                (history.boma_name || '') + ', ' + (history.payam_name || '') + ', ' + (history.county_name || '');
            document.getElementById('view_zoning').innerHTML = 
                '<span class="badge bg-primary">' + history.zone_code + '</span> ' + history.zone_name;
            document.getElementById('view_start').textContent = new Date(history.start_date).toLocaleDateString();
            document.getElementById('view_end').textContent = history.end_date ? new Date(history.end_date).toLocaleDateString() : 'Present';
            
            // Calculate duration
            const start = new Date(history.start_date);
            const end = history.end_date ? new Date(history.end_date) : new Date();
            const diffTime = Math.abs(end - start);
            const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
            const years = Math.floor(diffDays / 365);
            const months = Math.floor((diffDays % 365) / 30);
            const days = diffDays % 30;
            
            let duration = '';
            if (years > 0) duration += years + ' years ';
            if (months > 0) duration += months + ' months ';
            if (days > 0) duration += days + ' days';
            if (duration === '') duration = '< 1 day';
            
            document.getElementById('view_duration').textContent = duration;
            
            const isCurrent = !history.end_date;
            document.getElementById('view_status').innerHTML = isCurrent ? 
                '<span class="badge bg-success">Current</span>' : 
                '<span class="badge bg-secondary">Historical</span>';
            
            document.getElementById('view_reason').textContent = history.reason || 'No reason provided';
            document.getElementById('view_application').innerHTML = history.application_id_ref ? 
                '<a href="applications-view.php?id=' + history.application_id_ref + '">Application #' + history.application_id_ref + '</a>' : 
                'None';
        }

        // Edit history
        function editHistory(history) {
            document.getElementById('edit_history_id').value = history.id;
            document.getElementById('edit_parcel').value = history.parcel_number;
            document.getElementById('edit_zoning_id').value = history.zoning_id;
            document.getElementById('edit_start_date').value = history.start_date;
            document.getElementById('edit_end_date').value = history.end_date || '';
            document.getElementById('edit_reason').value = history.reason || '';
        }
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
        
        .table td {
            vertical-align: middle;
        }
        
        .btn-group .btn {
            padding: 0.25rem 0.5rem;
        }
        
        .bg-opacity-10 {
            --bs-bg-opacity: 0.1;
        }
        
        .progress {
            background-color: #e9ecef;
        }
        
        .table-success td {
            background-color: rgba(25, 135, 84, 0.05);
        }
        
        @media (max-width: 768px) {
            .btn-group {
                display: flex;
                flex-direction: column;
            }
            
            .btn-group .btn {
                border-radius: 0.25rem !important;
                margin-bottom: 2px;
            }
        }
    </style>

</body>
</html>