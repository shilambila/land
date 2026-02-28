<?php
ini_set('display_errors', 1); 
error_reporting(E_ALL);

// reports.php - Comprehensive Reporting System
session_start();
include("head.php");
require_once 'db_connection.php';

// Check if user is logged in
// if (!isset($_SESSION['user_id'])) {
//     header("Location: login.php");
//     exit();
// }

// $user_id = $_SESSION['user_id'];
// $user_role = $_SESSION['role'] ?? 'public';
$user_id = $_SESSION['user_id'] ?? 1;
$user_role = $_SESSION['role'] ?? 'admin';

// ============================================================================
// REPORT CONFIGURATION
// ============================================================================

// Available report types
$report_types = [
    'summary' => 'Executive Summary',
    'parcels' => 'Parcel Report',
    'titles' => 'Title Report',
    'transactions' => 'Transaction Report',
    'valuations' => 'Valuation Report',
    'taxes' => 'Tax Assessment Report',
    'applications' => 'Applications Report',
    'disputes' => 'Disputes Report',
    'documents' => 'Documents Report',
    'parties' => 'Parties Report',
    'audit' => 'Audit Log Report',
    'activity' => 'User Activity Report',
    'performance' => 'System Performance'
];

// Report formats
$report_formats = [
    'html' => 'HTML (Browser)',
    'pdf' => 'PDF Document',
    'excel' => 'Excel Spreadsheet',
    'csv' => 'CSV File',
    'json' => 'JSON Data'
];

// Date range presets
$date_presets = [
    'today' => 'Today',
    'yesterday' => 'Yesterday',
    'last_7_days' => 'Last 7 Days',
    'last_30_days' => 'Last 30 Days',
    'this_month' => 'This Month',
    'last_month' => 'Last Month',
    'this_quarter' => 'This Quarter',
    'last_quarter' => 'Last Quarter',
    'this_year' => 'This Year',
    'last_year' => 'Last Year',
    'custom' => 'Custom Range'
];

// Chart colors
$chart_colors = [
    '#4e73df', '#1cc88a', '#36b9cc', '#f6c23e', '#e74a3b',
    '#5a5c69', '#6610f2', '#6f42c1', '#e83e8c', '#fd7e14'
];

// ============================================================================
// GET REPORT PARAMETERS
// ============================================================================

$report_type = isset($_GET['report']) ? $_GET['report'] : (isset($_POST['report']) ? $_POST['report'] : 'summary');
$format = isset($_GET['format']) ? $_GET['format'] : (isset($_POST['format']) ? $_POST['format'] : 'html');
$date_preset = isset($_GET['date_preset']) ? $_GET['date_preset'] : (isset($_POST['date_preset']) ? $_POST['date_preset'] : 'last_30_days');
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : (isset($_POST['from_date']) ? $_POST['from_date'] : date('Y-m-d', strtotime('-30 days')));
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : (isset($_POST['to_date']) ? $_POST['to_date'] : date('Y-m-d'));
$filters = isset($_POST['filters']) ? $_POST['filters'] : (isset($_GET['filters']) ? $_GET['filters'] : []);

// Apply date preset
if ($date_preset != 'custom' && isset($_POST['apply_preset'])) {
    switch ($date_preset) {
        case 'today':
            $from_date = date('Y-m-d');
            $to_date = date('Y-m-d');
            break;
        case 'yesterday':
            $from_date = date('Y-m-d', strtotime('-1 day'));
            $to_date = date('Y-m-d', strtotime('-1 day'));
            break;
        case 'last_7_days':
            $from_date = date('Y-m-d', strtotime('-7 days'));
            $to_date = date('Y-m-d');
            break;
        case 'last_30_days':
            $from_date = date('Y-m-d', strtotime('-30 days'));
            $to_date = date('Y-m-d');
            break;
        case 'this_month':
            $from_date = date('Y-m-01');
            $to_date = date('Y-m-t');
            break;
        case 'last_month':
            $from_date = date('Y-m-01', strtotime('first day of last month'));
            $to_date = date('Y-m-t', strtotime('last day of last month'));
            break;
        case 'this_quarter':
            $quarter = ceil(date('n') / 3);
            $from_date = date('Y-' . (($quarter - 1) * 3 + 1) . '-01');
            $to_date = date('Y-m-t', strtotime($from_date . ' +2 months'));
            break;
        case 'last_quarter':
            $quarter = ceil(date('n') / 3) - 1;
            $year = date('Y');
            if ($quarter == 0) {
                $quarter = 4;
                $year--;
            }
            $from_date = date($year . '-' . (($quarter - 1) * 3 + 1) . '-01');
            $to_date = date('Y-m-t', strtotime($from_date . ' +2 months'));
            break;
        case 'this_year':
            $from_date = date('Y-01-01');
            $to_date = date('Y-12-31');
            break;
        case 'last_year':
            $from_date = date('Y-01-01', strtotime('-1 year'));
            $to_date = date('Y-12-31', strtotime('-1 year'));
            break;
    }
}

// ============================================================================
// REPORT DATA FUNCTIONS
// ============================================================================

/**
 * Get summary report data
 */
function getSummaryReport($conn, $from_date, $to_date, $filters = []) {
    $data = [];
    
    // Parcel statistics
    $data['parcels'] = fetchOne($conn, "
        SELECT 
            COUNT(*) as total_parcels,
            SUM(area) as total_area,
            AVG(area) as avg_area,
            COUNT(DISTINCT boma_id) as bomas_covered
        FROM parcels
        WHERE created_at BETWEEN ? AND ?
    ", [$from_date . ' 00:00:00', $to_date . ' 23:59:59']);
    
    // Title statistics
    $data['titles'] = fetchOne($conn, "
        SELECT 
            COUNT(*) as total_titles,
            SUM(CASE WHEN title_type = 'freehold' THEN 1 ELSE 0 END) as freehold,
            SUM(CASE WHEN title_type = 'leasehold' THEN 1 ELSE 0 END) as leasehold,
            SUM(CASE WHEN title_type = 'customary' THEN 1 ELSE 0 END) as customary,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_titles
        FROM titles
        WHERE created_at BETWEEN ? AND ?
    ", [$from_date . ' 00:00:00', $to_date . ' 23:59:59']);
    
    // Transaction statistics
    $data['transactions'] = fetchOne($conn, "
        SELECT 
            COUNT(*) as total_transactions,
            SUM(consideration_amount) as total_value,
            AVG(consideration_amount) as avg_value,
            SUM(CASE WHEN transaction_type = 'sale' THEN 1 ELSE 0 END) as sales,
            SUM(CASE WHEN transaction_type = 'transfer' THEN 1 ELSE 0 END) as transfers,
            SUM(CASE WHEN transaction_type = 'mortgage' THEN 1 ELSE 0 END) as mortgages,
            SUM(CASE WHEN transaction_type = 'lease' THEN 1 ELSE 0 END) as leases
        FROM transactions
        WHERE transaction_date BETWEEN ? AND ?
    ", [$from_date, $to_date]);
    
    // Valuation statistics
    $data['valuations'] = fetchOne($conn, "
        SELECT 
            COUNT(*) as total_valuations,
            SUM(value) as total_value,
            AVG(value) as avg_value,
            MAX(value) as max_value,
            MIN(value) as min_value
        FROM valuations
        WHERE valuation_date BETWEEN ? AND ?
    ", [$from_date, $to_date]);
    
    // Application statistics
    $data['applications'] = fetchOne($conn, "
        SELECT 
            COUNT(*) as total_applications,
            SUM(CASE WHEN status = 'submitted' THEN 1 ELSE 0 END) as submitted,
            SUM(CASE WHEN status = 'under_review' THEN 1 ELSE 0 END) as under_review,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
        FROM applications
        WHERE submission_date BETWEEN ? AND ?
    ", [$from_date, $to_date]);
    
    // Dispute statistics
    $data['disputes'] = fetchOne($conn, "
        SELECT 
            COUNT(*) as total_disputes,
            SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open,
            SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
            SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved,
            SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed
        FROM disputes
        WHERE filing_date BETWEEN ? AND ?
    ", [$from_date, $to_date]);
    
    // Document statistics
    $data['documents'] = fetchOne($conn, "
        SELECT 
            COUNT(*) as total_documents,
            COUNT(DISTINCT uploaded_by) as unique_uploaders
        FROM documents
        WHERE uploaded_at BETWEEN ? AND ?
    ", [$from_date . ' 00:00:00', $to_date . ' 23:59:59']);
    
    // Party statistics
    $data['parties'] = fetchOne($conn, "
        SELECT 
            COUNT(*) as total_parties,
            SUM(CASE WHEN party_type = 'individual' THEN 1 ELSE 0 END) as individuals,
            SUM(CASE WHEN party_type = 'organization' THEN 1 ELSE 0 END) as organizations
        FROM parties
        WHERE created_at BETWEEN ? AND ?
    ", [$from_date . ' 00:00:00', $to_date . ' 23:59:59']);
    
    // User statistics
    $data['users'] = fetchOne($conn, "
        SELECT 
            COUNT(*) as total_users,
            SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_users
        FROM users
        WHERE created_at BETWEEN ? AND ?
    ", [$from_date . ' 00:00:00', $to_date . ' 23:59:59']);
    
    // Monthly trends
    $data['monthly_trends'] = fetchAll($conn, "
        SELECT 
            DATE_FORMAT(date, '%Y-%m') as month,
            SUM(parcels) as parcels,
            SUM(titles) as titles,
            SUM(transactions) as transactions,
            SUM(valuations) as valuations
        FROM (
            SELECT created_at as date, 1 as parcels, 0 as titles, 0 as transactions, 0 as valuations FROM parcels
            UNION ALL
            SELECT created_at, 0, 1, 0, 0 FROM titles
            UNION ALL
            SELECT transaction_date, 0, 0, 1, 0 FROM transactions
            UNION ALL
            SELECT valuation_date, 0, 0, 0, 1 FROM valuations
        ) as combined
        WHERE date BETWEEN ? AND ?
        GROUP BY DATE_FORMAT(date, '%Y-%m')
        ORDER BY month DESC
        LIMIT 12
    ", [$from_date . ' 00:00:00', $to_date . ' 23:59:59']);
    
    return $data;
}

/**
 * Get parcel report data
 */
function getParcelsReport($conn, $from_date, $to_date, $filters = []) {
    $where = ["1=1"];
    $params = [];
    
    if (!empty($filters['state_id'])) {
        $where[] = "s.id = ?";
        $params[] = $filters['state_id'];
    }
    if (!empty($filters['county_id'])) {
        $where[] = "c.id = ?";
        $params[] = $filters['county_id'];
    }
    if (!empty($filters['boma_id'])) {
        $where[] = "b.id = ?";
        $params[] = $filters['boma_id'];
    }
    if (!empty($filters['min_area'])) {
        $where[] = "p.area >= ?";
        $params[] = $filters['min_area'];
    }
    if (!empty($filters['max_area'])) {
        $where[] = "p.area <= ?";
        $params[] = $filters['max_area'];
    }
    
    $where_clause = "WHERE " . implode(" AND ", $where);
    
    // Summary statistics
    $data['summary'] = fetchOne($conn, "
        SELECT 
            COUNT(*) as total_parcels,
            SUM(area) as total_area,
            AVG(area) as avg_area,
            MIN(area) as min_area,
            MAX(area) as max_area,
            COUNT(DISTINCT boma_id) as bomas_covered,
            COUNT(DISTINCT current_zoning_id) as zones_used
        FROM parcels p
        LEFT JOIN bomas b ON p.boma_id = b.id
        LEFT JOIN payams pa ON b.payam_id = pa.id
        LEFT JOIN counties c ON pa.county_id = c.id
        LEFT JOIN states s ON c.state_id = s.id
        $where_clause
    ", $params);
    
    // Parcels by boma
    $data['by_boma'] = fetchAll($conn, "
        SELECT 
            b.name as boma,
            pa.name as payam,
            c.name as county,
            s.name as state,
            COUNT(*) as parcel_count,
            SUM(area) as total_area,
            AVG(area) as avg_area
        FROM parcels p
        JOIN bomas b ON p.boma_id = b.id
        JOIN payams pa ON b.payam_id = pa.id
        JOIN counties c ON pa.county_id = c.id
        JOIN states s ON c.state_id = s.id
        $where_clause
        GROUP BY b.id, b.name, pa.name, c.name, s.name
        ORDER BY parcel_count DESC
    ", $params);
    
    // Parcels by zoning
    $data['by_zoning'] = fetchAll($conn, "
        SELECT 
            z.zone_code,
            z.zone_name,
            COUNT(*) as parcel_count,
            SUM(area) as total_area
        FROM parcels p
        LEFT JOIN zoning z ON p.current_zoning_id = z.id
        $where_clause
        GROUP BY z.id, z.zone_code, z.zone_name
        ORDER BY parcel_count DESC
    ", $params);
    
    // Area distribution
    $data['area_distribution'] = fetchAll($conn, "
        SELECT 
            CASE 
                WHEN area < 1000 THEN '< 1,000 m²'
                WHEN area BETWEEN 1000 AND 5000 THEN '1,000 - 5,000 m²'
                WHEN area BETWEEN 5001 AND 10000 THEN '5,001 - 10,000 m²'
                WHEN area BETWEEN 10001 AND 50000 THEN '10,001 - 50,000 m²'
                ELSE '> 50,000 m²'
            END as area_range,
            COUNT(*) as count,
            SUM(area) as total_area
        FROM parcels p
        $where_clause
        GROUP BY area_range
        ORDER BY MIN(area)
    ", $params);
    
    // Parcels with/without titles
    $data['title_status'] = fetchAll($conn, "
        SELECT 
            CASE 
                WHEN t.id IS NOT NULL THEN 'With Title'
                ELSE 'Without Title'
            END as status,
            COUNT(*) as count
        FROM parcels p
        LEFT JOIN titles t ON p.id = t.parcel_id AND t.status = 'active'
        $where_clause
        GROUP BY status
    ", $params);
    
    // Recent parcels
    $data['recent'] = fetchAll($conn, "
        SELECT 
            p.parcel_number,
            p.area,
            p.created_at,
            b.name as boma,
            pa.name as payam,
            c.name as county
        FROM parcels p
        JOIN bomas b ON p.boma_id = b.id
        JOIN payams pa ON b.payam_id = pa.id
        JOIN counties c ON pa.county_id = c.id
        $where_clause
        ORDER BY p.created_at DESC
        LIMIT 100
    ", $params);
    
    return $data;
}

/**
 * Get titles report data
 */
function getTitlesReport($conn, $from_date, $to_date, $filters = []) {
    $where = ["1=1"];
    $params = [];
    
    if (!empty($filters['title_type'])) {
        $where[] = "t.title_type = ?";
        $params[] = $filters['title_type'];
    }
    if (!empty($filters['status'])) {
        $where[] = "t.status = ?";
        $params[] = $filters['status'];
    }
    
    $where_clause = "WHERE " . implode(" AND ", $where);
    
    // Summary statistics
    $data['summary'] = fetchOne($conn, "
        SELECT 
            COUNT(*) as total_titles,
            SUM(CASE WHEN title_type = 'freehold' THEN 1 ELSE 0 END) as freehold,
            SUM(CASE WHEN title_type = 'leasehold' THEN 1 ELSE 0 END) as leasehold,
            SUM(CASE WHEN title_type = 'customary' THEN 1 ELSE 0 END) as customary,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive,
            SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
            COUNT(DISTINCT parcel_id) as unique_parcels
        FROM titles t
        $where_clause
    ", $params);
    
    // Titles by type and status
    $data['by_type_status'] = fetchAll($conn, "
        SELECT 
            title_type,
            status,
            COUNT(*) as count
        FROM titles t
        $where_clause
        GROUP BY title_type, status
        ORDER BY title_type, status
    ", $params);
    
    // Expiring leases
    $data['expiring_leases'] = fetchAll($conn, "
        SELECT 
            t.title_number,
            t.issue_date,
            t.expiry_date,
            p.parcel_number,
            DATEDIFF(t.expiry_date, CURDATE()) as days_left
        FROM titles t
        JOIN parcels p ON t.parcel_id = p.id
        WHERE t.title_type = 'leasehold' 
            AND t.status = 'active'
            AND t.expiry_date IS NOT NULL
            AND t.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 1 YEAR)
        ORDER BY t.expiry_date
    ");
    
    // Titles issued over time
    $data['monthly_issuance'] = fetchAll($conn, "
        SELECT 
            DATE_FORMAT(issue_date, '%Y-%m') as month,
            COUNT(*) as issued,
            SUM(CASE WHEN title_type = 'freehold' THEN 1 ELSE 0 END) as freehold,
            SUM(CASE WHEN title_type = 'leasehold' THEN 1 ELSE 0 END) as leasehold,
            SUM(CASE WHEN title_type = 'customary' THEN 1 ELSE 0 END) as customary
        FROM titles t
        WHERE issue_date BETWEEN ? AND ?
        GROUP BY DATE_FORMAT(issue_date, '%Y-%m')
        ORDER BY month DESC
    ", [$from_date, $to_date]);
    
    return $data;
}

/**
 * Get transactions report data
 */
function getTransactionsReport($conn, $from_date, $to_date, $filters = []) {
    $where = ["tr.transaction_date BETWEEN ? AND ?"];
    $params = [$from_date, $to_date];
    
    if (!empty($filters['transaction_type'])) {
        $where[] = "tr.transaction_type = ?";
        $params[] = $filters['transaction_type'];
    }
    
    $where_clause = "WHERE " . implode(" AND ", $where);
    
    // Summary statistics
    $data['summary'] = fetchOne($conn, "
        SELECT 
            COUNT(*) as total_transactions,
            SUM(consideration_amount) as total_value,
            AVG(consideration_amount) as avg_value,
            MAX(consideration_amount) as max_value,
            MIN(consideration_amount) as min_value,
            COUNT(DISTINCT parcel_id) as unique_parcels,
            COUNT(DISTINCT title_id) as unique_titles
        FROM transactions tr
        $where_clause
    ", $params);
    
    // Transactions by type
    $data['by_type'] = fetchAll($conn, "
        SELECT 
            transaction_type,
            COUNT(*) as count,
            SUM(consideration_amount) as total_value,
            AVG(consideration_amount) as avg_value
        FROM transactions tr
        $where_clause
        GROUP BY transaction_type
        ORDER BY count DESC
    ", $params);
    
    // Monthly transaction trend
    $data['monthly_trend'] = fetchAll($conn, "
        SELECT 
            DATE_FORMAT(transaction_date, '%Y-%m') as month,
            COUNT(*) as count,
            SUM(consideration_amount) as total_value
        FROM transactions tr
        $where_clause
        GROUP BY DATE_FORMAT(transaction_date, '%Y-%m')
        ORDER BY month DESC
    ", $params);
    
    // Top transactions by value
    $data['top_transactions'] = fetchAll($conn, "
        SELECT 
            tr.id,
            tr.transaction_type,
            tr.transaction_date,
            tr.consideration_amount,
            p.parcel_number,
            t.title_number,
            from_party.name as from_party,
            to_party.name as to_party
        FROM transactions tr
        JOIN parcels p ON tr.parcel_id = p.id
        JOIN titles t ON tr.title_id = t.id
        LEFT JOIN parties from_party ON tr.from_party_id = from_party.id
        LEFT JOIN parties to_party ON tr.to_party_id = to_party.id
        $where_clause
        ORDER BY tr.consideration_amount DESC
        LIMIT 20
    ", $params);
    
    // Party activity
    $data['party_activity'] = fetchAll($conn, "
        SELECT 
            p.id,
            p.name,
            p.party_type,
            COUNT(DISTINCT tr.id) as transaction_count,
            SUM(tr.consideration_amount) as total_value
        FROM parties p
        LEFT JOIN transactions tr ON p.id = tr.from_party_id OR p.id = tr.to_party_id
        WHERE tr.transaction_date BETWEEN ? AND ?
        GROUP BY p.id, p.name, p.party_type
        HAVING transaction_count > 0
        ORDER BY transaction_count DESC
        LIMIT 20
    ", [$from_date, $to_date]);
    
    return $data;
}

/**
 * Get valuations report data
 */
function getValuationsReport($conn, $from_date, $to_date, $filters = []) {
    $where = ["v.valuation_date BETWEEN ? AND ?"];
    $params = [$from_date, $to_date];
    
    if (!empty($filters['assessed_by'])) {
        $where[] = "v.assessed_by LIKE ?";
        $params[] = '%' . $filters['assessed_by'] . '%';
    }
    
    $where_clause = "WHERE " . implode(" AND ", $where);
    
    // Summary statistics
    $data['summary'] = fetchOne($conn, "
        SELECT 
            COUNT(*) as total_valuations,
            SUM(value) as total_value,
            AVG(value) as avg_value,
            MAX(value) as max_value,
            MIN(value) as min_value,
            COUNT(DISTINCT parcel_id) as unique_parcels,
            COUNT(DISTINCT assessed_by) as unique_assessors
        FROM valuations v
        $where_clause
    ", $params);
    
    // Value trends over time
    $data['monthly_trend'] = fetchAll($conn, "
        SELECT 
            DATE_FORMAT(valuation_date, '%Y-%m') as month,
            COUNT(*) as count,
            AVG(value) as avg_value,
            SUM(value) as total_value
        FROM valuations v
        $where_clause
        GROUP BY DATE_FORMAT(valuation_date, '%Y-%m')
        ORDER BY month DESC
    ", $params);
    
    // Top assessors
    $data['top_assessors'] = fetchAll($conn, "
        SELECT 
            assessed_by,
            COUNT(*) as valuation_count,
            AVG(value) as avg_value,
            SUM(value) as total_value,
            COUNT(DISTINCT parcel_id) as parcels_assessed
        FROM valuations v
        $where_clause
        GROUP BY assessed_by
        ORDER BY valuation_count DESC
        LIMIT 20
    ", $params);
    
    // Value distribution by area
    $data['value_distribution'] = fetchAll($conn, "
        SELECT 
            CASE 
                WHEN p.area < 1000 THEN '< 1,000 m²'
                WHEN p.area BETWEEN 1000 AND 5000 THEN '1,000 - 5,000 m²'
                WHEN p.area BETWEEN 5001 AND 10000 THEN '5,001 - 10,000 m²'
                WHEN p.area BETWEEN 10001 AND 50000 THEN '10,001 - 50,000 m²'
                ELSE '> 50,000 m²'
            END as area_range,
            COUNT(*) as count,
            AVG(v.value) as avg_value,
            AVG(v.value / p.area) as avg_price_per_sqm
        FROM valuations v
        JOIN parcels p ON v.parcel_id = p.id
        $where_clause
        GROUP BY area_range
        ORDER BY MIN(p.area)
    ", $params);
    
    return $data;
}

/**
 * Get taxes report data
 */
function getTaxesReport($conn, $from_date, $to_date, $filters = []) {
    // Tax assessments
    $data['assessments'] = fetchOne($conn, "
        SELECT 
            COUNT(*) as total_assessments,
            SUM(tax_amount) as total_tax,
            AVG(tax_amount) as avg_tax,
            SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) as overdue,
            SUM(CASE WHEN status = 'paid' THEN tax_amount ELSE 0 END) as paid_amount,
            SUM(CASE WHEN status = 'overdue' THEN tax_amount ELSE 0 END) as overdue_amount
        FROM tax_assessments
        WHERE due_date BETWEEN ? AND ?
    ", [$from_date, $to_date]);
    
    // Tax payments
    $data['payments'] = fetchOne($conn, "
        SELECT 
            COUNT(*) as total_payments,
            SUM(amount_paid) as total_paid,
            AVG(amount_paid) as avg_payment
        FROM tax_payments tp
        JOIN tax_assessments ta ON tp.tax_assessment_id = ta.id
        WHERE tp.payment_date BETWEEN ? AND ?
    ", [$from_date, $to_date]);
    
    // Collection rate by month
    $data['monthly_collection'] = fetchAll($conn, "
        SELECT 
            DATE_FORMAT(ta.due_date, '%Y-%m') as month,
            COUNT(*) as assessments,
            SUM(ta.tax_amount) as total_due,
            SUM(CASE WHEN ta.status = 'paid' THEN ta.tax_amount ELSE 0 END) as collected,
            (SUM(CASE WHEN ta.status = 'paid' THEN ta.tax_amount ELSE 0 END) / SUM(ta.tax_amount) * 100) as collection_rate
        FROM tax_assessments ta
        WHERE ta.due_date BETWEEN ? AND ?
        GROUP BY DATE_FORMAT(ta.due_date, '%Y-%m')
        ORDER BY month DESC
    ", [$from_date, $to_date]);
    
    // Overdue by parcel
    $data['overdue'] = fetchAll($conn, "
        SELECT 
            ta.id,
            ta.tax_year,
            ta.tax_amount,
            ta.due_date,
            p.parcel_number,
            p.area,
            CONCAT(owner.name, ' (', o.share_percentage, '%)') as owner
        FROM tax_assessments ta
        JOIN parcels p ON ta.parcel_id = p.id
        LEFT JOIN ownerships o ON p.id = o.parcel_id AND o.is_current = 1
        LEFT JOIN parties owner ON o.party_id = owner.id
        WHERE ta.status = 'overdue'
            AND ta.due_date < CURDATE()
        ORDER BY ta.due_date
        LIMIT 100
    ");
    
    return $data;
}

/**
 * Get applications report data
 */
function getApplicationsReport($conn, $from_date, $to_date, $filters = []) {
    $where = ["submission_date BETWEEN ? AND ?"];
    $params = [$from_date, $to_date];
    
    if (!empty($filters['application_type'])) {
        $where[] = "application_type = ?";
        $params[] = $filters['application_type'];
    }
    
    $where_clause = "WHERE " . implode(" AND ", $where);
    
    // Summary statistics
    $data['summary'] = fetchOne($conn, "
        SELECT 
            COUNT(*) as total_applications,
            SUM(CASE WHEN status = 'submitted' THEN 1 ELSE 0 END) as submitted,
            SUM(CASE WHEN status = 'under_review' THEN 1 ELSE 0 END) as under_review,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
            COUNT(DISTINCT applicant_party_id) as unique_applicants
        FROM applications
        $where_clause
    ", $params);
    
    // Applications by type
    $data['by_type'] = fetchAll($conn, "
        SELECT 
            application_type,
            COUNT(*) as count,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected
        FROM applications
        $where_clause
        GROUP BY application_type
        ORDER BY count DESC
    ", $params);
    
    // Monthly trend
    $data['monthly_trend'] = fetchAll($conn, "
        SELECT 
            DATE_FORMAT(submission_date, '%Y-%m') as month,
            COUNT(*) as submitted,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved
        FROM applications
        $where_clause
        GROUP BY DATE_FORMAT(submission_date, '%Y-%m')
        ORDER BY month DESC
    ", $params);
    
    // Processing time
    $data['processing_time'] = fetchOne($conn, "
        SELECT 
            AVG(DATEDIFF(COALESCE(updated_at, NOW()), submission_date)) as avg_days,
            MIN(DATEDIFF(updated_at, submission_date)) as min_days,
            MAX(DATEDIFF(updated_at, submission_date)) as max_days
        FROM applications
        WHERE status IN ('approved', 'rejected', 'completed')
            AND submission_date BETWEEN ? AND ?
    ", [$from_date, $to_date]);
    
    return $data;
}

/**
 * Get disputes report data
 */
function getDisputesReport($conn, $from_date, $to_date, $filters = []) {
    $where = ["filing_date BETWEEN ? AND ?"];
    $params = [$from_date, $to_date];
    
    if (!empty($filters['dispute_type'])) {
        $where[] = "dispute_type = ?";
        $params[] = $filters['dispute_type'];
    }
    
    $where_clause = "WHERE " . implode(" AND ", $where);
    
    // Summary statistics
    $data['summary'] = fetchOne($conn, "
        SELECT 
            COUNT(*) as total_disputes,
            SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open,
            SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
            SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved,
            SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed,
            AVG(DATEDIFF(COALESCE(resolved_date, NOW()), filing_date)) as avg_resolution_days
        FROM disputes
        $where_clause
    ", $params);
    
    // Disputes by type
    $data['by_type'] = fetchAll($conn, "
        SELECT 
            dispute_type,
            COUNT(*) as count,
            SUM(CASE WHEN status IN ('open', 'in_progress') THEN 1 ELSE 0 END) as active
        FROM disputes
        $where_clause
        GROUP BY dispute_type
        ORDER BY count DESC
    ", $params);
    
    // Monthly trend
    $data['monthly_trend'] = fetchAll($conn, "
        SELECT 
            DATE_FORMAT(filing_date, '%Y-%m') as month,
            COUNT(*) as filed,
            SUM(CASE WHEN resolved_date IS NOT NULL THEN 1 ELSE 0 END) as resolved
        FROM disputes
        $where_clause
        GROUP BY DATE_FORMAT(filing_date, '%Y-%m')
        ORDER BY month DESC
    ", $params);
    
    return $data;
}

/**
 * Get documents report data
 */
function getDocumentsReport($conn, $from_date, $to_date, $filters = []) {
    $where = ["uploaded_at BETWEEN ? AND ?"];
    $params = [$from_date . ' 00:00:00', $to_date . ' 23:59:59'];
    
    if (!empty($filters['document_type'])) {
        $where[] = "document_type = ?";
        $params[] = $filters['document_type'];
    }
    
    $where_clause = "WHERE " . implode(" AND ", $where);
    
    // Summary statistics
    $data['summary'] = fetchOne($conn, "
        SELECT 
            COUNT(*) as total_documents,
            COUNT(DISTINCT uploaded_by) as unique_uploaders,
            COUNT(DISTINCT parcel_id) as documents_with_parcels,
            COUNT(DISTINCT title_id) as documents_with_titles,
            COUNT(DISTINCT party_id) as documents_with_parties
        FROM documents
        $where_clause
    ", $params);
    
    // Documents by type
    $data['by_type'] = fetchAll($conn, "
        SELECT 
            document_type,
            COUNT(*) as count
        FROM documents
        $where_clause
        GROUP BY document_type
        ORDER BY count DESC
    ", $params);
    
    // Upload activity by user
    $data['by_user'] = fetchAll($conn, "
        SELECT 
            u.username,
            COUNT(*) as upload_count
        FROM documents d
        JOIN users u ON d.uploaded_by = u.id
        $where_clause
        GROUP BY u.id, u.username
        ORDER BY upload_count DESC
        LIMIT 20
    ", $params);
    
    // Daily upload trend
    $data['daily_trend'] = fetchAll($conn, "
        SELECT 
            DATE(uploaded_at) as date,
            COUNT(*) as uploads
        FROM documents
        $where_clause
        GROUP BY DATE(uploaded_at)
        ORDER BY date DESC
        LIMIT 30
    ", $params);
    
    return $data;
}

/**
 * Get parties report data
 */
function getPartiesReport($conn, $from_date, $to_date, $filters = []) {
    $where = ["created_at BETWEEN ? AND ?"];
    $params = [$from_date . ' 00:00:00', $to_date . ' 23:59:59'];
    
    if (!empty($filters['party_type'])) {
        $where[] = "party_type = ?";
        $params[] = $filters['party_type'];
    }
    
    $where_clause = "WHERE " . implode(" AND ", $where);
    
    // Summary statistics
    $data['summary'] = fetchOne($conn, "
        SELECT 
            COUNT(*) as total_parties,
            SUM(CASE WHEN party_type = 'individual' THEN 1 ELSE 0 END) as individuals,
            SUM(CASE WHEN party_type = 'organization' THEN 1 ELSE 0 END) as organizations,
            COUNT(DISTINCT phone) as parties_with_phone,
            COUNT(DISTINCT email) as parties_with_email
        FROM parties
        $where_clause
    ", $params);
    
    // Parties by type
    $data['by_type'] = fetchAll($conn, "
        SELECT 
            party_type,
            COUNT(*) as count
        FROM parties
        $where_clause
        GROUP BY party_type
    ", $params);
    
    // Parties with ownership
    $data['with_ownership'] = fetchOne($conn, "
        SELECT 
            COUNT(DISTINCT p.id) as parties_with_ownership,
            AVG(o.share_percentage) as avg_share
        FROM parties p
        JOIN ownerships o ON p.id = o.party_id
        WHERE p.created_at BETWEEN ? AND ?
    ", [$from_date . ' 00:00:00', $to_date . ' 23:59:59']);
    
    return $data;
}

/**
 * Get audit log report data
 */
function getAuditReport($conn, $from_date, $to_date, $filters = []) {
    $where = ["change_time BETWEEN ? AND ?"];
    $params = [$from_date . ' 00:00:00', $to_date . ' 23:59:59'];
    
    if (!empty($filters['table_name'])) {
        $where[] = "table_name = ?";
        $params[] = $filters['table_name'];
    }
    if (!empty($filters['action'])) {
        $where[] = "action = ?";
        $params[] = $filters['action'];
    }
    if (!empty($filters['user_id'])) {
        $where[] = "user_id = ?";
        $params[] = $filters['user_id'];
    }
    
    $where_clause = "WHERE " . implode(" AND ", $where);
    
    // Summary statistics
    $data['summary'] = fetchOne($conn, "
        SELECT 
            COUNT(*) as total_actions,
            COUNT(DISTINCT user_id) as active_users,
            COUNT(DISTINCT table_name) as tables_affected
        FROM audit_logs
        $where_clause
    ", $params);
    
    // Actions by type
    $data['by_action'] = fetchAll($conn, "
        SELECT 
            action,
            COUNT(*) as count
        FROM audit_logs
        $where_clause
        GROUP BY action
    ", $params);
    
    // Actions by table
    $data['by_table'] = fetchAll($conn, "
        SELECT 
            table_name,
            COUNT(*) as count
        FROM audit_logs
        $where_clause
        GROUP BY table_name
        ORDER BY count DESC
    ", $params);
    
    // Actions by user
    $data['by_user'] = fetchAll($conn, "
        SELECT 
            u.username,
            COUNT(*) as action_count
        FROM audit_logs a
        JOIN users u ON a.user_id = u.id
        $where_clause
        GROUP BY u.id, u.username
        ORDER BY action_count DESC
        LIMIT 20
    ", $params);
    
    // Hourly activity pattern
    $data['hourly_pattern'] = fetchAll($conn, "
        SELECT 
            HOUR(change_time) as hour,
            COUNT(*) as actions
        FROM audit_logs
        $where_clause
        GROUP BY HOUR(change_time)
        ORDER BY hour
    ", $params);
    
    return $data;
}

/**
 * Get user activity report data
 */
function getUserActivityReport($conn, $from_date, $to_date, $filters = []) {
    // User login statistics
    $data['logins'] = fetchAll($conn, "
        SELECT 
            u.username,
            u.role,
            COUNT(*) as login_count,
            MAX(a.change_time) as last_login
        FROM audit_logs a
        JOIN users u ON a.user_id = u.id
        WHERE a.action = 'LOGIN'
            AND a.change_time BETWEEN ? AND ?
        GROUP BY u.id, u.username, u.role
        ORDER BY login_count DESC
    ", [$from_date . ' 00:00:00', $to_date . ' 23:59:59']);
    
    // Active users by day
    $data['daily_active'] = fetchAll($conn, "
        SELECT 
            DATE(change_time) as date,
            COUNT(DISTINCT user_id) as active_users
        FROM audit_logs
        WHERE change_time BETWEEN ? AND ?
        GROUP BY DATE(change_time)
        ORDER BY date DESC
    ", [$from_date . ' 00:00:00', $to_date . ' 23:59:59']);
    
    // User roles distribution
    $data['roles'] = fetchAll($conn, "
        SELECT 
            role,
            COUNT(*) as user_count,
            SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active
        FROM users
        GROUP BY role
    ");
    
    return $data;
}

/**
 * Get system performance data
 */
function getPerformanceReport($conn, $from_date, $to_date, $filters = []) {
    // Database size
    $data['db_size'] = fetchOne($conn, "
        SELECT 
            SUM(data_length + index_length) as total_size,
            SUM(data_length) as data_size,
            SUM(index_length) as index_size
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
    ");
    
    // Record counts by table
    $tables = [
        'parcels', 'titles', 'transactions', 'valuations', 'applications',
        'disputes', 'documents', 'parties', 'users', 'audit_logs'
    ];
    
    $data['record_counts'] = [];
    foreach ($tables as $table) {
        try {
            $count = fetchOne($conn, "SELECT COUNT(*) as count FROM $table");
            $data['record_counts'][$table] = $count['count'] ?? 0;
        } catch (Exception $e) {
            $data['record_counts'][$table] = 0;
        }
    }
    
    // Growth over time (monthly new records)
    $data['growth'] = fetchAll($conn, "
        SELECT 
            DATE_FORMAT(date, '%Y-%m') as month,
            SUM(parcels) as new_parcels,
            SUM(titles) as new_titles,
            SUM(transactions) as new_transactions,
            SUM(users) as new_users
        FROM (
            SELECT created_at as date, 1 as parcels, 0 as titles, 0 as transactions, 0 as users FROM parcels
            UNION ALL
            SELECT created_at, 0, 1, 0, 0 FROM titles
            UNION ALL
            SELECT created_at, 0, 0, 1, 0 FROM transactions
            UNION ALL
            SELECT created_at, 0, 0, 0, 1 FROM users
        ) as combined
        WHERE date BETWEEN ? AND ?
        GROUP BY DATE_FORMAT(date, '%Y-%m')
        ORDER BY month DESC
        LIMIT 12
    ", [$from_date . ' 00:00:00', $to_date . ' 23:59:59']);
    
    return $data;
}

// ============================================================================
// GET REPORT DATA BASED ON TYPE
// ============================================================================

$report_data = [];
$report_title = $report_types[$report_type] ?? 'Custom Report';

try {
    switch ($report_type) {
        case 'summary':
            $report_data = getSummaryReport($conn, $from_date, $to_date, $filters);
            break;
        case 'parcels':
            $report_data = getParcelsReport($conn, $from_date, $to_date, $filters);
            break;
        case 'titles':
            $report_data = getTitlesReport($conn, $from_date, $to_date, $filters);
            break;
        case 'transactions':
            $report_data = getTransactionsReport($conn, $from_date, $to_date, $filters);
            break;
        case 'valuations':
            $report_data = getValuationsReport($conn, $from_date, $to_date, $filters);
            break;
        case 'taxes':
            $report_data = getTaxesReport($conn, $from_date, $to_date, $filters);
            break;
        case 'applications':
            $report_data = getApplicationsReport($conn, $from_date, $to_date, $filters);
            break;
        case 'disputes':
            $report_data = getDisputesReport($conn, $from_date, $to_date, $filters);
            break;
        case 'documents':
            $report_data = getDocumentsReport($conn, $from_date, $to_date, $filters);
            break;
        case 'parties':
            $report_data = getPartiesReport($conn, $from_date, $to_date, $filters);
            break;
        case 'audit':
            $report_data = getAuditReport($conn, $from_date, $to_date, $filters);
            break;
        case 'activity':
            $report_data = getUserActivityReport($conn, $from_date, $to_date, $filters);
            break;
        case 'performance':
            $report_data = getPerformanceReport($conn, $from_date, $to_date, $filters);
            break;
    }
} catch (Exception $e) {
    $error = "Error generating report: " . $e->getMessage();
    error_log("Report error: " . $e->getMessage());
}

// ============================================================================
// EXPORT HANDLING
// ============================================================================

if ($format != 'html' && !empty($report_data)) {
    $filename = 'report_' . $report_type . '_' . date('Y-m-d') . '.' . $format;
    
    switch ($format) {
        case 'csv':
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            
            $output = fopen('php://output', 'w');
            
            // Flatten data for CSV
            $flat_data = [];
            foreach ($report_data as $section => $data) {
                if (is_array($data)) {
                    foreach ($data as $key => $value) {
                        if (is_array($value)) {
                            foreach ($value as $subkey => $subvalue) {
                                $flat_data[] = [$section, $key, $subkey, is_scalar($subvalue) ? $subvalue : json_encode($subvalue)];
                            }
                        } else {
                            $flat_data[] = [$section, $key, '', is_scalar($value) ? $value : json_encode($value)];
                        }
                    }
                }
            }
            
            // Write headers
            fputcsv($output, ['Section', 'Key', 'Subkey', 'Value']);
            
            // Write data
            foreach ($flat_data as $row) {
                fputcsv($output, $row);
            }
            
            fclose($output);
            exit();
            
        case 'json':
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            echo json_encode($report_data, JSON_PRETTY_PRINT);
            exit();
            
        case 'excel':
            // Simple CSV for Excel
            header('Content-Type: application/vnd.ms-excel');
            header('Content-Disposition: attachment; filename="' . str_replace('.excel', '.csv', $filename) . '"');
            
            $output = fopen('php://output', 'w');
            
            // Add Excel header
            fputcsv($output, ['Report: ' . $report_title]);
            fputcsv($output, ['Generated: ' . date('Y-m-d H:i:s')]);
            fputcsv($output, ['Period: ' . $from_date . ' to ' . $to_date]);
            fputcsv($output, []);
            
            // Flatten data
            foreach ($report_data as $section => $data) {
                fputcsv($output, ['--- ' . strtoupper($section) . ' ---']);
                if (is_array($data)) {
                    foreach ($data as $key => $value) {
                        if (is_array($value)) {
                            fputcsv($output, [$key . ':']);
                            foreach ($value as $subkey => $subvalue) {
                                if (is_array($subvalue)) {
                                    fputcsv($output, ['  ' . $subkey . ': ' . json_encode($subvalue)]);
                                } else {
                                    fputcsv($output, ['  ' . $subkey . ': ' . $subvalue]);
                                }
                            }
                        } else {
                            fputcsv($output, [$key . ': ' . $value]);
                        }
                    }
                }
                fputcsv($output, []);
            }
            
            fclose($output);
            exit();
            
        case 'pdf':
            // For PDF, we'll just show HTML with print styles
            // In production, use a library like DOMPDF or TCPDF
            $format = 'html';
            break;
    }
}

// ============================================================================
// HTML REPORT VIEW
// ============================================================================

?>

<body data-page="reports" class="reports-page">
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
                            <h1 class="h3 mb-0">
                                <i class="bi bi-bar-chart-line me-2"></i>
                                Reports
                            </h1>
                            <p class="text-muted mb-0">Generate and export system reports</p>
                        </div>
                        <div class="d-flex gap-2">
                            <button class="btn btn-outline-primary" onclick="window.print()">
                                <i class="bi bi-printer me-2"></i>Print
                            </button>
                            <div class="dropdown">
                                <button class="btn btn-success dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                    <i class="bi bi-download me-2"></i>Export
                                </button>
                                <ul class="dropdown-menu">
                                    <li><a class="dropdown-item" href="?<?php echo http_build_query(array_merge($_GET, ['format' => 'pdf'])); ?>">PDF Document</a></li>
                                    <li><a class="dropdown-item" href="?<?php echo http_build_query(array_merge($_GET, ['format' => 'excel'])); ?>">Excel Spreadsheet</a></li>
                                    <li><a class="dropdown-item" href="?<?php echo http_build_query(array_merge($_GET, ['format' => 'csv'])); ?>">CSV File</a></li>
                                    <li><a class="dropdown-item" href="?<?php echo http_build_query(array_merge($_GET, ['format' => 'json'])); ?>">JSON Data</a></li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <?php if (isset($error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
                        <?php echo $error; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>

                    <!-- Report Controls -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-white py-3">
                            <h5 class="card-title mb-0 fw-bold">
                                <i class="bi bi-sliders2 me-2"></i>
                                Report Controls
                            </h5>
                        </div>
                        <div class="card-body">
                            <form method="GET" class="row g-3">
                                <div class="col-md-3">
                                    <label class="form-label">Report Type</label>
                                    <select class="form-select" name="report" onchange="this.form.submit()">
                                        <?php foreach ($report_types as $key => $label): ?>
                                        <option value="<?php echo $key; ?>" <?php echo $report_type == $key ? 'selected' : ''; ?>>
                                            <?php echo $label; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-3">
                                    <label class="form-label">Date Preset</label>
                                    <select class="form-select" name="date_preset" onchange="this.form.submit()">
                                        <?php foreach ($date_presets as $key => $label): ?>
                                        <option value="<?php echo $key; ?>" <?php echo $date_preset == $key ? 'selected' : ''; ?>>
                                            <?php echo $label; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-3">
                                    <label class="form-label">From Date</label>
                                    <input type="date" class="form-control" name="from_date" value="<?php echo $from_date; ?>">
                                </div>
                                
                                <div class="col-md-3">
                                    <label class="form-label">To Date</label>
                                    <input type="date" class="form-control" name="to_date" value="<?php echo $to_date; ?>">
                                </div>
                                
                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary" name="apply_preset" value="1">
                                        <i class="bi bi-search me-2"></i>Generate Report
                                    </button>
                                    <a href="reports.php" class="btn btn-secondary">
                                        <i class="bi bi-arrow-repeat me-2"></i>Reset
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Report Header -->
                    <div class="report-header mb-4">
                        <h2><?php echo $report_title; ?></h2>
                        <p class="text-muted">
                            <i class="bi bi-calendar me-2"></i>
                            Period: <?php echo date('d M Y', strtotime($from_date)); ?> - <?php echo date('d M Y', strtotime($to_date)); ?>
                            <span class="mx-3">|</span>
                            <i class="bi bi-clock me-2"></i>
                            Generated: <?php echo date('d M Y H:i:s'); ?>
                        </p>
                    </div>

                    <!-- Report Content -->
                    <div class="report-content">
                        <?php if (empty($report_data)): ?>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle me-2"></i>
                                No data available for the selected criteria.
                            </div>
                        <?php else: ?>
                            
                            <!-- Summary Report -->
                            <?php if ($report_type == 'summary'): ?>
                                <div class="row">
                                    <!-- Parcels Card -->
                                    <div class="col-md-4 mb-4">
                                        <div class="card border-0 shadow-sm h-100">
                                            <div class="card-header bg-white py-3">
                                                <h5 class="card-title mb-0 fw-bold">
                                                    <i class="bi bi-pin-map text-success me-2"></i>
                                                    Parcels
                                                </h5>
                                            </div>
                                            <div class="card-body">
                                                <div class="d-flex justify-content-between mb-2">
                                                    <span>Total Parcels:</span>
                                                    <span class="fw-bold"><?php echo number_format($report_data['parcels']['total_parcels'] ?? 0); ?></span>
                                                </div>
                                                <div class="d-flex justify-content-between mb-2">
                                                    <span>Total Area:</span>
                                                    <span class="fw-bold"><?php echo number_format($report_data['parcels']['total_area'] ?? 0, 2); ?> m²</span>
                                                </div>
                                                <div class="d-flex justify-content-between mb-2">
                                                    <span>Average Area:</span>
                                                    <span class="fw-bold"><?php echo number_format($report_data['parcels']['avg_area'] ?? 0, 2); ?> m²</span>
                                                </div>
                                                <div class="d-flex justify-content-between">
                                                    <span>Bomas Covered:</span>
                                                    <span class="fw-bold"><?php echo number_format($report_data['parcels']['bomas_covered'] ?? 0); ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Titles Card -->
                                    <div class="col-md-4 mb-4">
                                        <div class="card border-0 shadow-sm h-100">
                                            <div class="card-header bg-white py-3">
                                                <h5 class="card-title mb-0 fw-bold">
                                                    <i class="bi bi-file-text text-primary me-2"></i>
                                                    Titles
                                                </h5>
                                            </div>
                                            <div class="card-body">
                                                <div class="d-flex justify-content-between mb-2">
                                                    <span>Total Titles:</span>
                                                    <span class="fw-bold"><?php echo number_format($report_data['titles']['total_titles'] ?? 0); ?></span>
                                                </div>
                                                <div class="d-flex justify-content-between mb-2">
                                                    <span>Freehold:</span>
                                                    <span class="fw-bold"><?php echo number_format($report_data['titles']['freehold'] ?? 0); ?></span>
                                                </div>
                                                <div class="d-flex justify-content-between mb-2">
                                                    <span>Leasehold:</span>
                                                    <span class="fw-bold"><?php echo number_format($report_data['titles']['leasehold'] ?? 0); ?></span>
                                                </div>
                                                <div class="d-flex justify-content-between">
                                                    <span>Customary:</span>
                                                    <span class="fw-bold"><?php echo number_format($report_data['titles']['customary'] ?? 0); ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Transactions Card -->
                                    <div class="col-md-4 mb-4">
                                        <div class="card border-0 shadow-sm h-100">
                                            <div class="card-header bg-white py-3">
                                                <h5 class="card-title mb-0 fw-bold">
                                                    <i class="bi bi-cash-stack text-warning me-2"></i>
                                                    Transactions
                                                </h5>
                                            </div>
                                            <div class="card-body">
                                                <div class="d-flex justify-content-between mb-2">
                                                    <span>Total:</span>
                                                    <span class="fw-bold"><?php echo number_format($report_data['transactions']['total_transactions'] ?? 0); ?></span>
                                                </div>
                                                <div class="d-flex justify-content-between mb-2">
                                                    <span>Total Value:</span>
                                                    <span class="fw-bold">$<?php echo number_format($report_data['transactions']['total_value'] ?? 0, 2); ?></span>
                                                </div>
                                                <div class="d-flex justify-content-between mb-2">
                                                    <span>Average Value:</span>
                                                    <span class="fw-bold">$<?php echo number_format($report_data['transactions']['avg_value'] ?? 0, 2); ?></span>
                                                </div>
                                                <div class="d-flex justify-content-between">
                                                    <span>Sales:</span>
                                                    <span class="fw-bold"><?php echo number_format($report_data['transactions']['sales'] ?? 0); ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Applications Card -->
                                    <div class="col-md-4 mb-4">
                                        <div class="card border-0 shadow-sm h-100">
                                            <div class="card-header bg-white py-3">
                                                <h5 class="card-title mb-0 fw-bold">
                                                    <i class="bi bi-file-earmark-text text-info me-2"></i>
                                                    Applications
                                                </h5>
                                            </div>
                                            <div class="card-body">
                                                <div class="d-flex justify-content-between mb-2">
                                                    <span>Total:</span>
                                                    <span class="fw-bold"><?php echo number_format($report_data['applications']['total_applications'] ?? 0); ?></span>
                                                </div>
                                                <div class="d-flex justify-content-between mb-2">
                                                    <span>Submitted:</span>
                                                    <span class="fw-bold"><?php echo number_format($report_data['applications']['submitted'] ?? 0); ?></span>
                                                </div>
                                                <div class="d-flex justify-content-between mb-2">
                                                    <span>Under Review:</span>
                                                    <span class="fw-bold"><?php echo number_format($report_data['applications']['under_review'] ?? 0); ?></span>
                                                </div>
                                                <div class="d-flex justify-content-between">
                                                    <span>Approved:</span>
                                                    <span class="fw-bold"><?php echo number_format($report_data['applications']['approved'] ?? 0); ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Disputes Card -->
                                    <div class="col-md-4 mb-4">
                                        <div class="card border-0 shadow-sm h-100">
                                            <div class="card-header bg-white py-3">
                                                <h5 class="card-title mb-0 fw-bold">
                                                    <i class="bi bi-exclamation-triangle text-danger me-2"></i>
                                                    Disputes
                                                </h5>
                                            </div>
                                            <div class="card-body">
                                                <div class="d-flex justify-content-between mb-2">
                                                    <span>Total:</span>
                                                    <span class="fw-bold"><?php echo number_format($report_data['disputes']['total_disputes'] ?? 0); ?></span>
                                                </div>
                                                <div class="d-flex justify-content-between mb-2">
                                                    <span>Open:</span>
                                                    <span class="fw-bold"><?php echo number_format($report_data['disputes']['open'] ?? 0); ?></span>
                                                </div>
                                                <div class="d-flex justify-content-between mb-2">
                                                    <span>In Progress:</span>
                                                    <span class="fw-bold"><?php echo number_format($report_data['disputes']['in_progress'] ?? 0); ?></span>
                                                </div>
                                                <div class="d-flex justify-content-between">
                                                    <span>Resolved:</span>
                                                    <span class="fw-bold"><?php echo number_format($report_data['disputes']['resolved'] ?? 0); ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Parties Card -->
                                    <div class="col-md-4 mb-4">
                                        <div class="card border-0 shadow-sm h-100">
                                            <div class="card-header bg-white py-3">
                                                <h5 class="card-title mb-0 fw-bold">
                                                    <i class="bi bi-people text-secondary me-2"></i>
                                                    Parties
                                                </h5>
                                            </div>
                                            <div class="card-body">
                                                <div class="d-flex justify-content-between mb-2">
                                                    <span>Total:</span>
                                                    <span class="fw-bold"><?php echo number_format($report_data['parties']['total_parties'] ?? 0); ?></span>
                                                </div>
                                                <div class="d-flex justify-content-between mb-2">
                                                    <span>Individuals:</span>
                                                    <span class="fw-bold"><?php echo number_format($report_data['parties']['individuals'] ?? 0); ?></span>
                                                </div>
                                                <div class="d-flex justify-content-between">
                                                    <span>Organizations:</span>
                                                    <span class="fw-bold"><?php echo number_format($report_data['parties']['organizations'] ?? 0); ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Monthly Trends Chart -->
                                <?php if (!empty($report_data['monthly_trends'])): ?>
                                <div class="card border-0 shadow-sm mt-4">
                                    <div class="card-header bg-white py-3">
                                        <h5 class="card-title mb-0 fw-bold">
                                            <i class="bi bi-graph-up text-success me-2"></i>
                                            Monthly Trends
                                        </h5>
                                    </div>
                                    <div class="card-body">
                                        <canvas id="trendsChart" height="300"></canvas>
                                    </div>
                                </div>
                                
                                <script>
                                    document.addEventListener('DOMContentLoaded', function() {
                                        const ctx = document.getElementById('trendsChart').getContext('2d');
                                        new Chart(ctx, {
                                            type: 'line',
                                            data: {
                                                labels: <?php echo json_encode(array_reverse(array_column($report_data['monthly_trends'], 'month'))); ?>,
                                                datasets: [
                                                    {
                                                        label: 'Parcels',
                                                        data: <?php echo json_encode(array_reverse(array_column($report_data['monthly_trends'], 'parcels'))); ?>,
                                                        borderColor: '#4e73df',
                                                        backgroundColor: 'rgba(78, 115, 223, 0.1)',
                                                        tension: 0.1
                                                    },
                                                    {
                                                        label: 'Titles',
                                                        data: <?php echo json_encode(array_reverse(array_column($report_data['monthly_trends'], 'titles'))); ?>,
                                                        borderColor: '#1cc88a',
                                                        backgroundColor: 'rgba(28, 200, 138, 0.1)',
                                                        tension: 0.1
                                                    },
                                                    {
                                                        label: 'Transactions',
                                                        data: <?php echo json_encode(array_reverse(array_column($report_data['monthly_trends'], 'transactions'))); ?>,
                                                        borderColor: '#f6c23e',
                                                        backgroundColor: 'rgba(246, 194, 62, 0.1)',
                                                        tension: 0.1
                                                    }
                                                ]
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
                                    });
                                </script>
                                <?php endif; ?>
                            
                            <!-- Parcels Report -->
                            <?php elseif ($report_type == 'parcels'): ?>
                                <div class="row">
                                    <div class="col-md-6 mb-4">
                                        <div class="card border-0 shadow-sm">
                                            <div class="card-header bg-white py-3">
                                                <h5 class="card-title mb-0 fw-bold">Parcel Summary</h5>
                                            </div>
                                            <div class="card-body">
                                                <table class="table table-sm">
                                                    <tr>
                                                        <th>Total Parcels:</th>
                                                        <td class="text-end"><?php echo number_format($report_data['summary']['total_parcels'] ?? 0); ?></td>
                                                    </tr>
                                                    <tr>
                                                        <th>Total Area:</th>
                                                        <td class="text-end"><?php echo number_format($report_data['summary']['total_area'] ?? 0, 2); ?> m²</td>
                                                    </tr>
                                                    <tr>
                                                        <th>Average Area:</th>
                                                        <td class="text-end"><?php echo number_format($report_data['summary']['avg_area'] ?? 0, 2); ?> m²</td>
                                                    </tr>
                                                    <tr>
                                                        <th>Minimum Area:</th>
                                                        <td class="text-end"><?php echo number_format($report_data['summary']['min_area'] ?? 0, 2); ?> m²</td>
                                                    </tr>
                                                    <tr>
                                                        <th>Maximum Area:</th>
                                                        <td class="text-end"><?php echo number_format($report_data['summary']['max_area'] ?? 0, 2); ?> m²</td>
                                                    </tr>
                                                    <tr>
                                                        <th>Bomas Covered:</th>
                                                        <td class="text-end"><?php echo number_format($report_data['summary']['bomas_covered'] ?? 0); ?></td>
                                                    </tr>
                                                    <tr>
                                                        <th>Zones Used:</th>
                                                        <td class="text-end"><?php echo number_format($report_data['summary']['zones_used'] ?? 0); ?></td>
                                                    </tr>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-6 mb-4">
                                        <div class="card border-0 shadow-sm">
                                            <div class="card-header bg-white py-3">
                                                <h5 class="card-title mb-0 fw-bold">Title Status</h5>
                                            </div>
                                            <div class="card-body">
                                                <canvas id="titleStatusChart" height="200"></canvas>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Parcels by Boma -->
                                <?php if (!empty($report_data['by_boma'])): ?>
                                <div class="card border-0 shadow-sm mt-4">
                                    <div class="card-header bg-white py-3">
                                        <h5 class="card-title mb-0 fw-bold">Parcels by Administrative Division</h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="table-responsive">
                                            <table class="table table-striped">
                                                <thead>
                                                    <tr>
                                                        <th>State</th>
                                                        <th>County</th>
                                                        <th>Payam</th>
                                                        <th>Boma</th>
                                                        <th class="text-end">Parcel Count</th>
                                                        <th class="text-end">Total Area (m²)</th>
                                                        <th class="text-end">Avg Area (m²)</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($report_data['by_boma'] as $row): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($row['state']); ?></td>
                                                        <td><?php echo htmlspecialchars($row['county']); ?></td>
                                                        <td><?php echo htmlspecialchars($row['payam']); ?></td>
                                                        <td><?php echo htmlspecialchars($row['boma']); ?></td>
                                                        <td class="text-end"><?php echo number_format($row['parcel_count']); ?></td>
                                                        <td class="text-end"><?php echo number_format($row['total_area'], 2); ?></td>
                                                        <td class="text-end"><?php echo number_format($row['avg_area'], 2); ?></td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <script>
                                    document.addEventListener('DOMContentLoaded', function() {
                                        // Title Status Chart
                                        const ctx = document.getElementById('titleStatusChart').getContext('2d');
                                        new Chart(ctx, {
                                            type: 'doughnut',
                                            data: {
                                                labels: <?php 
                                                    $statuses = array_column($report_data['title_status'], 'status');
                                                    echo json_encode($statuses);
                                                ?>,
                                                datasets: [{
                                                    data: <?php echo json_encode(array_column($report_data['title_status'], 'count')); ?>,
                                                    backgroundColor: ['#1cc88a', '#e74a3b'],
                                                    hoverBackgroundColor: ['#17a673', '#be3e2f']
                                                }]
                                            },
                                            options: {
                                                responsive: true,
                                                maintainAspectRatio: false
                                            }
                                        });
                                    });
                                </script>
                            
                            <!-- Titles Report -->
                            <?php elseif ($report_type == 'titles'): ?>
                                <div class="row">
                                    <div class="col-md-6 mb-4">
                                        <div class="card border-0 shadow-sm">
                                            <div class="card-header bg-white py-3">
                                                <h5 class="card-title mb-0 fw-bold">Title Summary</h5>
                                            </div>
                                            <div class="card-body">
                                                <table class="table table-sm">
                                                    <tr>
                                                        <th>Total Titles:</th>
                                                        <td class="text-end"><?php echo number_format($report_data['summary']['total_titles'] ?? 0); ?></td>
                                                    </tr>
                                                    <tr>
                                                        <th>Freehold:</th>
                                                        <td class="text-end"><?php echo number_format($report_data['summary']['freehold'] ?? 0); ?></td>
                                                    </tr>
                                                    <tr>
                                                        <th>Leasehold:</th>
                                                        <td class="text-end"><?php echo number_format($report_data['summary']['leasehold'] ?? 0); ?></td>
                                                    </tr>
                                                    <tr>
                                                        <th>Customary:</th>
                                                        <td class="text-end"><?php echo number_format($report_data['summary']['customary'] ?? 0); ?></td>
                                                    </tr>
                                                    <tr>
                                                        <th>Active:</th>
                                                        <td class="text-end"><?php echo number_format($report_data['summary']['active'] ?? 0); ?></td>
                                                    </tr>
                                                    <tr>
                                                        <th>Unique Parcels:</th>
                                                        <td class="text-end"><?php echo number_format($report_data['summary']['unique_parcels'] ?? 0); ?></td>
                                                    </tr>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-6 mb-4">
                                        <div class="card border-0 shadow-sm">
                                            <div class="card-header bg-white py-3">
                                                <h5 class="card-title mb-0 fw-bold">Titles by Type & Status</h5>
                                            </div>
                                            <div class="card-body">
                                                <canvas id="titlesChart" height="200"></canvas>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Expiring Leases -->
                                <?php if (!empty($report_data['expiring_leases'])): ?>
                                <div class="card border-0 shadow-sm mt-4">
                                    <div class="card-header bg-white py-3">
                                        <h5 class="card-title mb-0 fw-bold text-warning">
                                            <i class="bi bi-calendar-exclamation me-2"></i>
                                            Expiring Leases (Next 12 Months)
                                        </h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="table-responsive">
                                            <table class="table table-striped">
                                                <thead>
                                                    <tr>
                                                        <th>Title Number</th>
                                                        <th>Parcel</th>
                                                        <th>Issue Date</th>
                                                        <th>Expiry Date</th>
                                                        <th class="text-end">Days Left</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($report_data['expiring_leases'] as $lease): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($lease['title_number']); ?></td>
                                                        <td><?php echo htmlspecialchars($lease['parcel_number']); ?></td>
                                                        <td><?php echo date('d M Y', strtotime($lease['issue_date'])); ?></td>
                                                        <td><?php echo date('d M Y', strtotime($lease['expiry_date'])); ?></td>
                                                        <td class="text-end">
                                                            <span class="badge bg-<?php echo $lease['days_left'] < 30 ? 'danger' : ($lease['days_left'] < 90 ? 'warning' : 'info'); ?>">
                                                                <?php echo $lease['days_left']; ?> days
                                                            </span>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <script>
                                    document.addEventListener('DOMContentLoaded', function() {
                                        // Titles Chart
                                        const ctx = document.getElementById('titlesChart').getContext('2d');
                                        const data = <?php echo json_encode($report_data['by_type_status']); ?>;
                                        
                                        const labels = [];
                                        const counts = [];
                                        const colors = [];
                                        
                                        data.forEach(item => {
                                            labels.push(item.title_type + ' - ' + item.status);
                                            counts.push(item.count);
                                        });
                                        
                                        new Chart(ctx, {
                                            type: 'bar',
                                            data: {
                                                labels: labels,
                                                datasets: [{
                                                    label: 'Number of Titles',
                                                    data: counts,
                                                    backgroundColor: <?php echo json_encode($chart_colors); ?>
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
                                    });
                                </script>
                            
                            <!-- Transactions Report -->
                            <?php elseif ($report_type == 'transactions'): ?>
                                <div class="row">
                                    <div class="col-md-4 mb-4">
                                        <div class="card border-0 shadow-sm">
                                            <div class="card-header bg-white py-3">
                                                <h5 class="card-title mb-0 fw-bold">Transaction Summary</h5>
                                            </div>
                                            <div class="card-body">
                                                <table class="table table-sm">
                                                    <tr>
                                                        <th>Total Transactions:</th>
                                                        <td class="text-end"><?php echo number_format($report_data['summary']['total_transactions'] ?? 0); ?></td>
                                                    </tr>
                                                    <tr>
                                                        <th>Total Value:</th>
                                                        <td class="text-end">$<?php echo number_format($report_data['summary']['total_value'] ?? 0, 2); ?></td>
                                                    </tr>
                                                    <tr>
                                                        <th>Average Value:</th>
                                                        <td class="text-end">$<?php echo number_format($report_data['summary']['avg_value'] ?? 0, 2); ?></td>
                                                    </tr>
                                                    <tr>
                                                        <th>Max Value:</th>
                                                        <td class="text-end">$<?php echo number_format($report_data['summary']['max_value'] ?? 0, 2); ?></td>
                                                    </tr>
                                                    <tr>
                                                        <th>Min Value:</th>
                                                        <td class="text-end">$<?php echo number_format($report_data['summary']['min_value'] ?? 0, 2); ?></td>
                                                    </tr>
                                                    <tr>
                                                        <th>Unique Parcels:</th>
                                                        <td class="text-end"><?php echo number_format($report_data['summary']['unique_parcels'] ?? 0); ?></td>
                                                    </tr>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-8 mb-4">
                                        <div class="card border-0 shadow-sm">
                                            <div class="card-header bg-white py-3">
                                                <h5 class="card-title mb-0 fw-bold">Transactions by Type</h5>
                                            </div>
                                            <div class="card-body">
                                                <canvas id="transactionsChart" height="200"></canvas>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Top Transactions -->
                                <?php if (!empty($report_data['top_transactions'])): ?>
                                <div class="card border-0 shadow-sm mt-4">
                                    <div class="card-header bg-white py-3">
                                        <h5 class="card-title mb-0 fw-bold">Top 20 Transactions by Value</h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="table-responsive">
                                            <table class="table table-striped">
                                                <thead>
                                                    <tr>
                                                        <th>Type</th>
                                                        <th>Date</th>
                                                        <th>Parcel</th>
                                                        <th>Title</th>
                                                        <th>From Party</th>
                                                        <th>To Party</th>
                                                        <th class="text-end">Amount</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($report_data['top_transactions'] as $trans): ?>
                                                    <tr>
                                                        <td><span class="badge bg-info"><?php echo ucfirst($trans['transaction_type']); ?></span></td>
                                                        <td><?php echo date('d M Y', strtotime($trans['transaction_date'])); ?></td>
                                                        <td><?php echo htmlspecialchars($trans['parcel_number']); ?></td>
                                                        <td><?php echo htmlspecialchars($trans['title_number']); ?></td>
                                                        <td><?php echo htmlspecialchars($trans['from_party'] ?? 'N/A'); ?></td>
                                                        <td><?php echo htmlspecialchars($trans['to_party'] ?? 'N/A'); ?></td>
                                                        <td class="text-end fw-bold">$<?php echo number_format($trans['consideration_amount'], 2); ?></td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <script>
                                    document.addEventListener('DOMContentLoaded', function() {
                                        // Transactions Chart
                                        const ctx = document.getElementById('transactionsChart').getContext('2d');
                                        const data = <?php echo json_encode($report_data['by_type']); ?>;
                                        
                                        const labels = data.map(item => item.transaction_type);
                                        const counts = data.map(item => item.count);
                                        const values = data.map(item => item.total_value);
                                        
                                        new Chart(ctx, {
                                            type: 'bar',
                                            data: {
                                                labels: labels,
                                                datasets: [
                                                    {
                                                        label: 'Number of Transactions',
                                                        data: counts,
                                                        backgroundColor: 'rgba(78, 115, 223, 0.8)',
                                                        yAxisID: 'y'
                                                    },
                                                    {
                                                        label: 'Total Value ($)',
                                                        data: values,
                                                        backgroundColor: 'rgba(28, 200, 138, 0.8)',
                                                        yAxisID: 'y1'
                                                    }
                                                ]
                                            },
                                            options: {
                                                responsive: true,
                                                maintainAspectRatio: false,
                                                scales: {
                                                    y: {
                                                        beginAtZero: true,
                                                        position: 'left',
                                                        ticks: {
                                                            stepSize: 1
                                                        }
                                                    },
                                                    y1: {
                                                        beginAtZero: true,
                                                        position: 'right',
                                                        grid: {
                                                            drawOnChartArea: false
                                                        },
                                                        ticks: {
                                                            callback: function(value) {
                                                                return '$' + value;
                                                            }
                                                        }
                                                    }
                                                }
                                            }
                                        });
                                    });
                                </script>
                            
                            <!-- Generic Report Display for other types -->
                            <?php else: ?>
                                <pre><?php print_r($report_data); ?></pre>
                            <?php endif; ?>
                            
                        <?php endif; ?>
                    </div>

                </div>
            </main>

            <!-- Footer -->
            <?php include("footer.php"); ?>

        </div>
    </div>

    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <!-- Print Styles -->
    <style media="print">
        .admin-main {
            margin: 0 !important;
            padding: 0 !important;
        }
        .navbar, .sidebar, .sidebar-backdrop, .btn, .dropdown, footer, .report-controls {
            display: none !important;
        }
        .card {
            border: 1px solid #ddd !important;
            box-shadow: none !important;
        }
        .report-header {
            margin-top: 20px;
        }
        canvas {
            max-height: 200px;
        }
    </style>

    <style>
        .report-header {
            border-bottom: 2px solid #4e73df;
            padding-bottom: 15px;
        }
        
        .report-content .card {
            transition: transform 0.2s;
        }
        
        .report-content .card:hover {
            transform: translateY(-2px);
        }
        
        .table-sm td, .table-sm th {
            padding: 0.5rem;
        }
        
        @media print {
            body {
                background: white;
            }
            .container-fluid {
                width: 100%;
                max-width: 100%;
            }
        }
    </style>

</body>
</html>