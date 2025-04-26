<?php
// File: semesters.php

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

include('../db_connection.php');

if (!isset($_GET['session_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'session_id is required']);
    exit;
}

$session_id = filter_var($_GET['session_id'], FILTER_SANITIZE_NUMBER_INT);

try {
    $query = "SELECT semester_id, session_id, semester_number, start_date, end_date FROM semesters WHERE session_id = ? ORDER BY semester_number";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $semesters = [];
    while ($row = $result->fetch_assoc()) {
        $semesters[] = $row;
    }

    echo json_encode(['status' => 'success', 'data' => $semesters]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$stmt->close();
$conn->close();
?>
