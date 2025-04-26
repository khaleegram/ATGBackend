<?php
// File: academic_sessions.php

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

include('../db_connection.php');

try {
    $query = "SELECT session_id, start_year, end_year FROM academic_sessions ORDER BY start_year DESC";
    $result = $conn->query($query);

    $sessions = [];
    while ($row = $result->fetch_assoc()) {
        $sessions[] = [
            'session_id' => $row['session_id'],
            'start_year' => $row['start_year'],
            'end_year' => $row['end_year'],
            'session_name' => "{$row['start_year']}/{$row['end_year']}"
        ];
    }

    echo json_encode(['status' => 'success', 'data' => $sessions]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();
?>
