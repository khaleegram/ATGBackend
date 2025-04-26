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
        // Fetch staffs along with college and department names
        $sql = "SELECT s.*, c.name AS college_name, d.name AS department_name 
                FROM staffs s 
                JOIN colleges c ON s.college_id = c.id 
                JOIN departments d ON s.department_id = d.id
                ORDER BY s.id DESC";
        $result = $conn->query($sql);
        $staffs = [];
        while ($row = $result->fetch_assoc()) {
            $staffs[] = $row;
        }
        echo json_encode($staffs);
        break;

    case 'POST':
        $input = json_decode(file_get_contents("php://input"), true);
        if (!isset($input['name'], $input['email'], $input['phone'], $input['college_id'], $input['department_id'], $input['position'])) {
            echo json_encode(['status' => 'error', 'message' => 'Missing required fields']);
            exit;
        }

        // Prevent duplicate entry based on key fields
        $check = $conn->prepare("SELECT id FROM staffs WHERE name = ? AND email = ? AND phone = ? AND college_id = ? AND department_id = ? AND position = ?");
        $check->bind_param("sssiii", $input['name'], $input['email'], $input['phone'], $input['college_id'], $input['department_id'], $input['position']);
        $check->execute();
        $check->store_result();
        if ($check->num_rows > 0) {
            echo json_encode(['status' => 'error', 'message' => 'Staff already exists with these details']);
            exit;
        }
        $stmt = $conn->prepare("INSERT INTO staffs (name, email, phone, college_id, department_id, position) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssiis", $input['name'], $input['email'], $input['phone'], $input['college_id'], $input['department_id'], $input['position']);
        $success = $stmt->execute();
        echo json_encode(['status' => $success ? 'success' : 'error', 'message' => $success ? 'Staff added successfully' : 'Failed to add staff']);
        break;

    case 'PUT':
        $input = json_decode(file_get_contents("php://input"), true);
        if (!isset($input['id'], $input['name'], $input['email'], $input['phone'], $input['college_id'], $input['department_id'], $input['position'])) {
            echo json_encode(['status' => 'error', 'message' => 'Missing required fields']);
            exit;
        }

        // Prevent duplicate on update (exclude the current record)
        $check = $conn->prepare("SELECT id FROM staffs WHERE name = ? AND email = ? AND phone = ? AND college_id = ? AND department_id = ? AND position = ? AND id != ?");
        $check->bind_param("sssiiii", $input['name'], $input['email'], $input['phone'], $input['college_id'], $input['department_id'], $input['position'], $input['id']);
        $check->execute();
        $check->store_result();
        if ($check->num_rows > 0) {
            echo json_encode(['status' => 'error', 'message' => 'Another staff with these details already exists']);
            exit;
        }
        $stmt = $conn->prepare("UPDATE staffs SET name = ?, email = ?, phone = ?, college_id = ?, department_id = ?, position = ? WHERE id = ?");
        $stmt->bind_param("sssissi", $input['name'], $input['email'], $input['phone'], $input['college_id'], $input['department_id'], $input['position'], $input['id']);
        $success = $stmt->execute();
        echo json_encode(['status' => $success ? 'success' : 'error', 'message' => $success ? 'Staff updated successfully' : 'Failed to update staff']);
        break;

    case 'DELETE':
        $input = json_decode(file_get_contents("php://input"), true);
        if (!isset($input['id'])) {
            echo json_encode(['status' => 'error', 'message' => 'Missing staff ID']);
            exit;
        }
        $stmt = $conn->prepare("DELETE FROM staffs WHERE id = ?");
        $stmt->bind_param("i", $input['id']);
        $success = $stmt->execute();
        echo json_encode(['status' => $success ? 'success' : 'error', 'message' => $success ? 'Staff deleted successfully' : 'Failed to delete staff']);
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Unsupported method']);
}

$conn->close();
