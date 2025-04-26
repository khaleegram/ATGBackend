<?php
// File: level_counts_history.php

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

include('../db_connection.php');

$session_id = isset($_GET['session_id']) ? filter_var($_GET['session_id'], FILTER_SANITIZE_NUMBER_INT) : null;
$semester_id = isset($_GET['semester_id']) ? filter_var($_GET['semester_id'], FILTER_SANITIZE_NUMBER_INT) : null;

if (!$session_id || !$semester_id) {
    echo json_encode(['status' => 'error', 'message' => 'session_id and semester_id are required']);
    exit;
}

try {
    // Fetch session year range
    $sessionQuery = "SELECT start_year, end_year FROM academic_sessions WHERE session_id = ?";
    $stmt1 = $conn->prepare($sessionQuery);
    $stmt1->bind_param("i", $session_id);
    $stmt1->execute();
    $result1 = $stmt1->get_result();
    if ($result1->num_rows === 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid session ID']);
        exit;
    }
    $sessionData = $result1->fetch_assoc();
    $session_name = "{$sessionData['start_year']}/{$sessionData['end_year']}";
    $stmt1->close();

    // Fetch semester number
    $semesterQuery = "SELECT semester_number FROM semesters WHERE semester_id = ?";
    $stmt2 = $conn->prepare($semesterQuery);
    $stmt2->bind_param("i", $semester_id);
    $stmt2->execute();
    $result2 = $stmt2->get_result();
    if ($result2->num_rows === 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid semester ID']);
        exit;
    }
    $semester_number = $result2->fetch_assoc()['semester_number'];
    $stmt2->close();

    // Fetch historical snapshot
    $query = "SELECT lch.program_id, lch.level, lch.count, p.name AS program_name
              FROM level_counts_history lch
              JOIN programs p ON lch.program_id = p.program_id
              WHERE lch.session = ? AND lch.semester = ?
              ORDER BY p.name, lch.level";
    $stmt3 = $conn->prepare($query);
    $stmt3->bind_param("si", $session_name, $semester_number);
    $stmt3->execute();
    $result3 = $stmt3->get_result();

    $snapshot = [];
    while ($row = $result3->fetch_assoc()) {
        $snapshot[] = [
            'id' => ($row['program_id'] * 10 + $row['level']),
            'program_id' => $row['program_id'],
            'program_name' => $row['program_name'],
            'level' => $row['level'],
            'students_count' => $row['count']
        ];
    }

    echo json_encode(['status' => 'success', 'data' => $snapshot]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();
?>
