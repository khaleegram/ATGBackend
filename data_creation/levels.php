<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

include('../db_connection.php');
header('Content-Type: application/json');

// Get the HTTP method and input data
$method = $_SERVER['REQUEST_METHOD'];
$data = json_decode(file_get_contents("php://input"), true);

switch ($method) {
    case 'GET':
        // If a program_id is provided, fetch only levels belonging to that program.
        if (isset($_GET['program_id'])) {
            $program_id = filter_var($_GET['program_id'], FILTER_SANITIZE_NUMBER_INT);
            if (!$program_id) {
                echo json_encode(['status' => 'error', 'message' => 'A valid Program ID is required.']);
                exit;
            }
            try {
                $query = "SELECT * FROM levels WHERE program_id = ?";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("i", $program_id);
                $stmt->execute();
                $result = $stmt->get_result();

                $levels = [];
                while ($row = $result->fetch_assoc()) {
                    $levels[] = [
                        'id' => $row['id'],
                        'level' => $row['level'],
                        'students_count' => $row['students_count'],
                        'program_id' => $row['program_id']
                    ];
                }
                echo json_encode(['status' => 'success', 'data' => $levels]);
            } catch (Exception $e) {
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
            $stmt->close();
        } else {
            // Otherwise, fetch all levels joined with program names.
            try {
                // Updated join using "p.program_id" instead of "p.id"
                $query = "SELECT l.*, p.name as program_name FROM levels l JOIN programs p ON l.program_id = p.program_id ORDER BY p.name, l.level";
                $result = $conn->query($query);

                $levels = [];
                while ($row = $result->fetch_assoc()) {
                    $levels[] = [
                        'id' => $row['id'],
                        'level' => $row['level'],
                        'students_count' => $row['students_count'],
                        'program_id' => $row['program_id'],
                        'program_name' => $row['program_name']
                    ];
                }
                echo json_encode(['status' => 'success', 'data' => $levels]);
            } catch (Exception $e) {
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
        }
        $conn->close();
        break;

    case 'POST':
        $level = filter_var($data['level'], FILTER_SANITIZE_NUMBER_INT);
        $program_id = filter_var($data['program_id'], FILTER_SANITIZE_NUMBER_INT);
        $students_count = filter_var($data['students_count'], FILTER_SANITIZE_NUMBER_INT);

        if (!$level || !$program_id || !$students_count || $students_count <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'All fields are required and must be valid.']);
            exit;
        }

        try {
            // Check for existing level in the same program
            $checkQuery = "SELECT id FROM levels WHERE program_id = ? AND level = ?";
            $checkStmt = $conn->prepare($checkQuery);
            $checkStmt->bind_param("ii", $program_id, $level);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();

            if ($checkResult->num_rows > 0) {
                echo json_encode(['status' => 'error', 'message' => 'Level already exists for this program.']);
                $checkStmt->close();
                $conn->close();
                exit;
            }
            $checkStmt->close();

            $conn->begin_transaction();
            $query = "INSERT INTO levels (program_id, level, students_count) VALUES (?, ?, ?)";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("iii", $program_id, $level, $students_count);

            if ($stmt->execute()) {
                $conn->commit();
                echo json_encode(['status' => 'success', 'message' => 'Level added successfully.']);
            } else {
                throw new Exception('Failed to add level.');
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
            echo json_encode(['status' => 'error', 'message' => 'ID is required for update.']);
            exit;
        }
        $id = filter_var($data['id'], FILTER_SANITIZE_NUMBER_INT);
        $program_id = filter_var($data['program_id'], FILTER_SANITIZE_NUMBER_INT);
        $level = filter_var($data['level'], FILTER_SANITIZE_NUMBER_INT);
        $students_count = filter_var($data['students_count'], FILTER_SANITIZE_NUMBER_INT);

        if (!$id || !$program_id || !$level || !$students_count || $students_count <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'All fields are required and must be valid.']);
            exit;
        }

        $conn->begin_transaction();
        try {
            $query = "UPDATE levels SET program_id = ?, level = ?, students_count = ? WHERE id = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("iiii", $program_id, $level, $students_count, $id);

            if ($stmt->execute()) {
                $conn->commit();
                echo json_encode(['status' => 'success', 'message' => 'Level updated successfully.']);
            } else {
                throw new Exception('Failed to update level.');
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
            echo json_encode(['status' => 'error', 'message' => 'ID is required for deletion.']);
            exit;
        }
        $id = filter_var($data['id'], FILTER_SANITIZE_NUMBER_INT);
        $conn->begin_transaction();
        try {
            $query = "DELETE FROM levels WHERE id = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("i", $id);

            if ($stmt->execute()) {
                $conn->commit();
                echo json_encode(['status' => 'success', 'message' => 'Level deleted successfully.']);
            } else {
                throw new Exception('Failed to delete level.');
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
