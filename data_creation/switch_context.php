<?php
// File: switch_context.php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER['REQUEST_METHOD']=='OPTIONS') { http_response_code(200); exit; }

include('../db_connection.php');
header('Content-Type: application/json');

// Expect JSON payload { session_id, semester_number }
$data = json_decode(file_get_contents("php://input"), true);
$sid = isset($data['session_id']) ? intval($data['session_id']) : 0;
$sem = isset($data['semester_number']) ? intval($data['semester_number']) : 0;

if (!$sid || !in_array($sem, [1,2], true)) {
    echo json_encode(['status'=>'error','message'=>'Valid session_id & semester_number required.']);
    exit;
}

// Simply return the active context for the frontend to store
echo json_encode([
    'status' => 'success',
    'data'   => [
        'active_session'  => $sid,
        'active_semester' => $sem
    ]
]);

$conn->close();
