<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

include('../db_connection.php');
header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        // Retrieve all venues (including the new venue_type field)
        $sql = "SELECT id, name, code, capacity, latitude, longitude, venue_type FROM venues ORDER BY id DESC";
        $result = $conn->query($sql);
        $venues = [];
        while ($row = $result->fetch_assoc()) {
            $venues[] = $row;
        }
        echo json_encode($venues);
        break;

    case 'POST':
        $input = json_decode(file_get_contents("php://input"), true);
        if (!isset($input['name'], $input['code'], $input['capacity'], $input['venue_type'])) {
            echo json_encode(['status' => 'error', 'message' => 'Missing required fields']);
            exit;
        }

        // Validate venue_type
        $allowed = ['CBT','Written'];
        if (!in_array($input['venue_type'], $allowed)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid venue_type']);
            exit;
        }

        // Prevent duplicate venue based on name and code
        $check = $conn->prepare("SELECT id FROM venues WHERE name = ? AND code = ?");
        $check->bind_param("ss", $input['name'], $input['code']);
        $check->execute();
        $check->store_result();
        if ($check->num_rows > 0) {
            echo json_encode(['status' => 'error', 'message' => 'Venue already exists']);
            exit;
        }

        $capacity = intval($input['capacity']);
        $latitude = isset($input['latitude']) ? $input['latitude'] : null;
        $longitude = isset($input['longitude']) ? $input['longitude'] : null;
        $venue_type = $input['venue_type'];

        $stmt = $conn->prepare(
            "INSERT INTO venues (name, code, capacity, latitude, longitude, venue_type) 
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param("ssisss",
            $input['name'],
            $input['code'],
            $capacity,
            $latitude,
            $longitude,
            $venue_type
        );
        $success = $stmt->execute();
        echo json_encode([
            'status' => $success ? 'success' : 'error', 
            'message' => $success ? 'Venue added successfully' : 'Failed to add venue'
        ]);
        break;

    case 'PUT':
        $input = json_decode(file_get_contents("php://input"), true);
        if (!isset($input['id'], $input['name'], $input['code'], $input['capacity'], $input['venue_type'])) {
            echo json_encode(['status' => 'error', 'message' => 'Missing required fields']);
            exit;
        }

        // Validate venue_type
        $allowed = ['CBT','Written'];
        if (!in_array($input['venue_type'], $allowed)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid venue_type']);
            exit;
        }

        // Prevent duplicate on update (exclude the current record)
        $check = $conn->prepare("SELECT id FROM venues WHERE name = ? AND code = ? AND id != ?");
        $check->bind_param("ssi", $input['name'], $input['code'], $input['id']);
        $check->execute();
        $check->store_result();
        if ($check->num_rows > 0) {
            echo json_encode(['status' => 'error', 'message' => 'Another venue with these details exists']);
            exit;
        }

        $capacity   = intval($input['capacity']);
        $latitude   = isset($input['latitude']) ? $input['latitude'] : null;
        $longitude  = isset($input['longitude']) ? $input['longitude'] : null;
        $venue_type = $input['venue_type'];

        $stmt = $conn->prepare(
            "UPDATE venues 
                SET name = ?, code = ?, capacity = ?, latitude = ?, longitude = ?, venue_type = ? 
              WHERE id = ?"
        );
        $stmt->bind_param("ssisssi",
            $input['name'],
            $input['code'],
            $capacity,
            $latitude,
            $longitude,
            $venue_type,
            $input['id']
        );
        $success = $stmt->execute();
        echo json_encode([
            'status' => $success ? 'success' : 'error', 
            'message' => $success ? 'Venue updated successfully' : 'Failed to update venue'
        ]);
        break;

    case 'DELETE':
        $input = json_decode(file_get_contents("php://input"), true);
        if (!isset($input['id'])) {
            echo json_encode(['status' => 'error', 'message' => 'Missing venue ID']);
            exit;
        }
        $stmt = $conn->prepare("DELETE FROM venues WHERE id = ?");
        $stmt->bind_param("i", $input['id']);
        $success = $stmt->execute();
        echo json_encode([
            'status' => $success ? 'success' : 'error', 
            'message' => $success ? 'Venue deleted successfully' : 'Failed to delete venue'
        ]);
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Unsupported method']);
}

$conn->close();
