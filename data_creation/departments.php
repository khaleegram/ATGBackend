<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200); exit;
}

include('../db_connection.php');
header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        if (isset($_GET['college_id'])) {
            $college_id = intval($_GET['college_id']);
            $stmt = $conn->prepare("SELECT d.*, c.name AS college_name FROM departments d JOIN colleges c ON d.college_id = c.id WHERE d.college_id = ?");
            $stmt->bind_param("i", $college_id);
        } else {
            $stmt = $conn->prepare("SELECT d.*, c.name AS college_name FROM departments d JOIN colleges c ON d.college_id = c.id");
        }
    
        $stmt->execute();
        $result = $stmt->get_result();
        $departments = [];
        while ($row = $result->fetch_assoc()) {
            $departments[] = $row;
        }
        echo json_encode($departments);
        break;
    

    case 'POST':
        $input = json_decode(file_get_contents("php://input"), true);
        if (!isset($input['college_id'], $input['name'])) {
            echo json_encode(['status' => 'error', 'message' => 'Missing fields']); exit;
        }

        // Prevent duplicate
        $check = $conn->prepare("SELECT id FROM departments WHERE name = ? AND college_id = ?");
        $check->bind_param("si", $input['name'], $input['college_id']);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            echo json_encode(['status' => 'error', 'message' => 'Department already exists']); exit;
        }

        $stmt = $conn->prepare("INSERT INTO departments (name, college_id) VALUES (?, ?)");
        $stmt->bind_param("si", $input['name'], $input['college_id']);
        $success = $stmt->execute();
        echo json_encode(['status' => $success ? 'success' : 'error', 'message' => $success ? 'Department added' : 'Failed to add']);
        break;

    case 'PUT':
        $input = json_decode(file_get_contents("php://input"), true);
        if (!isset($input['id'], $input['name'], $input['college_id'])) {
            echo json_encode(['status' => 'error', 'message' => 'Missing fields']); exit;
        }

        // Prevent duplicate on update
        $check = $conn->prepare("SELECT id FROM departments WHERE name = ? AND college_id = ? AND id != ?");
        $check->bind_param("sii", $input['name'], $input['college_id'], $input['id']);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            echo json_encode(['status' => 'error', 'message' => 'Another department with this name exists']); exit;
        }

        $stmt = $conn->prepare("UPDATE departments SET name = ?, college_id = ? WHERE id = ?");
        $stmt->bind_param("sii", $input['name'], $input['college_id'], $input['id']);
        $success = $stmt->execute();
        echo json_encode(['status' => $success ? 'success' : 'error', 'message' => $success ? 'Updated' : 'Failed to update']);
        break;

    case 'DELETE':
        $input = json_decode(file_get_contents("php://input"), true);
        if (!isset($input['id'])) {
            echo json_encode(['status' => 'error', 'message' => 'Missing ID']); exit;
        }
        $stmt = $conn->prepare("DELETE FROM departments WHERE id = ?");
        $stmt->bind_param("i", $input['id']);
        $success = $stmt->execute();
        echo json_encode(['status' => $success ? 'success' : 'error', 'message' => $success ? 'Deleted' : 'Failed to delete']);
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Unsupported method']);
}

$conn->close();
