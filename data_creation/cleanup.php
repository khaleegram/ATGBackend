<?php
// File: cleanup.php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER['REQUEST_METHOD']=='OPTIONS') { http_response_code(200); exit; }

include('../db_connection.php');
header('Content-Type: application/json');

try {
    if (!$conn->query("CALL ToggleSemester()")) {
        throw new Exception($conn->error);
    }
    echo json_encode([
      'status'  => 'success',
      'message' => 'Semester toggled successfully.'
    ]);
} catch (Exception $e) {
    echo json_encode([
      'status'  => 'error',
      'message' => $e->getMessage()
    ]);
}

$conn->close();
