<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../db_connection.php';

function ensureRoleAccess(array $allowedRoles): void {
    if (!isset($_SESSION['user_id'])) {
        header('Location: /login.php');
        exit();
    }

    $currentRole = $_SESSION['user_role'] ?? '';
    if (!in_array($currentRole, $allowedRoles, true)) {
        header('HTTP/1.1 403 Forbidden');
        echo 'Access denied.';
        exit();
    }
}

function getPortalStats(PDO $conn): array {
    return [
        'users' => (int) fetchOne($conn, "SELECT COUNT(*) AS total FROM users")['total'],
        'parcels' => (int) fetchOne($conn, "SELECT COUNT(*) AS total FROM parcels")['total'],
        'applications' => (int) fetchOne($conn, "SELECT COUNT(*) AS total FROM applications")['total'],
        'payments' => (float) fetchOne($conn, "SELECT COALESCE(SUM(amount),0) AS total FROM payments WHERE status = 'completed'")['total'],
    ];
}

function getRoleModulePermissions(PDO $conn, string $role): array {
    $sql = "SELECT p.module, p.permission_name, p.permission_key
            FROM permissions p
            INNER JOIN role_permissions rp ON rp.permission_id = p.id
            WHERE rp.role = ?
            ORDER BY p.module, p.permission_name";
    return fetchAll($conn, $sql, [$role]);
}
