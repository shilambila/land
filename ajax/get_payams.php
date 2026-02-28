<?php
require_once '../db_connection.php';
$county_id = $_GET['county_id'] ?? 0;
$payams = fetchAll($conn, "SELECT id, name FROM payams WHERE county_id = ? ORDER BY name", [$county_id]);
header('Content-Type: application/json');
echo json_encode($payams);