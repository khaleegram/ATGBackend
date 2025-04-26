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

// Get the HTTP method and input data
$method = $_SERVER['REQUEST_METHOD'];
$data = json_decode(file_get_contents("php://input"), true);

switch($method) {
    case 'GET':
        try {
            // Join programs with departments so that each program includes department_name
            $query = "SELECT p.program_id AS id, p.name, p.department_id, d.name AS department_name 
                      FROM programs p 
                      LEFT JOIN departments d ON p.department_id = d.id 
                      ORDER BY p.name";
            $result = $conn->query($query);
    
            $programs = [];
            while ($row = $result->fetch_assoc()) {
                $programs[] = [
                    'id'              => $row['id'], // program id from the join
                    'name'            => $row['name'],
                    'department_id'   => $row['department_id'],
                    'department_name' => $row['department_name'] ? $row['department_name'] : 'Unknown'
                ];
            }
            echo json_encode(['status' => 'success', 'data' => $programs]);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        $conn->close();
        break;

    case 'POST':
        $department_id = filter_var($data['department_id'], FILTER_SANITIZE_NUMBER_INT);
        $name = filter_var($data['name'], FILTER_SANITIZE_STRING);

        if (!$department_id || !$name) {
            echo json_encode(['status' => 'error', 'message' => 'Department ID and Program Name are required.']);
            exit;
        }

        try {
            // Check for an existing program with the same name in the same department.
            $checkQuery = "SELECT program_id FROM programs WHERE department_id = ? AND name = ?";
            $checkStmt = $conn->prepare($checkQuery);
            $checkStmt->bind_param("is", $department_id, $name);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();

            if ($checkResult->num_rows > 0) {
                echo json_encode(['status' => 'error', 'message' => 'Program already exists for this department.']);
                $checkStmt->close();
                $conn->close();
                exit;
            }
            $checkStmt->close();

            // Insert new program.
            $conn->begin_transaction();
            $query = "INSERT INTO programs (department_id, name) VALUES (?, ?)";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("is", $department_id, $name);

            if ($stmt->execute()) {
                $conn->commit();
                echo json_encode(['status' => 'success', 'message' => 'Program added successfully.']);
            } else {
                throw new Exception('Failed to add program.');
            }
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        $stmt->close();
        $conn->close();
        break;

    case 'PUT':
        if (!isset($data['id'])) {
            echo json_encode(['status' => 'error', 'message' => 'Program ID is required for update.']);
            exit;
        }
        $id = filter_var($data['id'], FILTER_SANITIZE_NUMBER_INT);
        $department_id = filter_var($data['department_id'], FILTER_SANITIZE_NUMBER_INT);
        $name = filter_var($data['name'], FILTER_SANITIZE_STRING);

        if (!$id || !$department_id || !$name) {
            echo json_encode(['status' => 'error', 'message' => 'All fields are required and must be valid.']);
            exit;
        }

        $conn->begin_transaction();
        try {
            $query = "UPDATE programs SET department_id = ?, name = ? WHERE program_id = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("isi", $department_id, $name, $id);

            if ($stmt->execute()) {
                $conn->commit();
                echo json_encode(['status' => 'success', 'message' => 'Program updated successfully.']);
            } else {
                throw new Exception('Failed to update program.');
            }
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        $stmt->close();
        $conn->close();
        break;

    case 'DELETE':
        if (!isset($data['id'])) {
            echo json_encode(['status' => 'error', 'message' => 'Program ID is required for deletion.']);
            exit;
        }
        $id = filter_var($data['id'], FILTER_SANITIZE_NUMBER_INT);
        $conn->begin_transaction();
        try {
            $query = "DELETE FROM programs WHERE program_id = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("i", $id);

            if ($stmt->execute()) {
                $conn->commit();
                echo json_encode(['status' => 'success', 'message' => 'Program deleted successfully.']);
            } else {
                throw new Exception('Failed to delete program.');
            }
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        $stmt->close();
        $conn->close();
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Invalid Request Method']);
        break;
}
?>
