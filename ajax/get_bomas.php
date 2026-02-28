<?php
require_once '../db_connection.php';

if (isset($_GET['payam_id'])) {
    $payamId = $_GET['payam_id'];
    $bomas = fetchAll($conn, "SELECT id, name FROM bomas WHERE payam_id = ? ORDER BY name", [$payamId]);
    header('Content-Type: application/json');
    echo json_encode($bomas);
}