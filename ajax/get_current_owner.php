<?php
require_once '../db_connection.php';

if (isset($_GET['title_id'])) {
    $titleId = $_GET['title_id'];
    
    $owner = fetchOne($conn, "
        SELECT pa.name as owner_name
        FROM ownerships o
        JOIN parties pa ON o.party_id = pa.id
        WHERE o.title_id = ? AND o.is_current = 1
    ", [$titleId]);
    
    header('Content-Type: application/json');
    echo json_encode($owner ?: ['owner_name' => null]);
}