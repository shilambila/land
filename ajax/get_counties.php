<?php
require_once '../db_connection.php';
$state_id = $_GET['state_id'] ?? 0;
$counties = fetchAll($conn, "SELECT id, name FROM counties WHERE state_id = ? ORDER BY name", [$state_id]);
header('Content-Type: application/json');
echo json_encode($counties);