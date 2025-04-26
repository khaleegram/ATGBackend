<?php
include('../db_connection.php');

// CORS Headers (already in db_connection.php)
header('Content-Type: application/json');

// Handle preflight OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Main Dispatcher
$method = $_SERVER['REQUEST_METHOD'];
switch ($method) {
    case 'GET':
        getColleges($conn);
        break;
    case 'POST':
        addCollege($conn);
        break;
    case 'PUT':
        updateCollege($conn);
        break;
    case 'DELETE':
        deleteCollege($conn);
        break;
    default:
        echo json_encode(['status' => 'error', 'message' => 'Unsupported request method']);
        break;
}

// 📌 Functions

function getColleges($conn) {
    $result = $conn->query("SELECT * FROM colleges");
    $colleges = [];

    while ($row = $result->fetch_assoc()) {
        $colleges[] = [
            'id' => $row['id'],
            'name' => $row['code'],        // Short code
            'full_name' => $row['name']    // Full name
        ];
    }

    echo json_encode($colleges);
}

function addCollege($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    $name = filter_var($input['name'], FILTER_SANITIZE_STRING);
    $code = filter_var($input['code'], FILTER_SANITIZE_STRING);

    if (empty($name) || empty($code)) {
        echo json_encode(['status' => 'error', 'message' => 'College name and code are required']);
        return;
    }

    // Check for duplicate name or code
    $stmt = $conn->prepare("SELECT id FROM colleges WHERE name = ? OR code = ?");
    $stmt->bind_param('ss', $name, $code);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        echo json_encode(['status' => 'error', 'message' => 'College name or code already exists']);
        $stmt->close();
        return;
    }
    $stmt->close();

    $stmt = $conn->prepare("INSERT INTO colleges (name, code) VALUES (?, ?)");
    $stmt->bind_param('ss', $name, $code);

    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'College added successfully']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to add college']);
    }

    $stmt->close();
}

function updateCollege($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = filter_var($input['id'], FILTER_SANITIZE_NUMBER_INT);
    $name = filter_var($input['name'], FILTER_SANITIZE_STRING);
    $code = filter_var($input['code'], FILTER_SANITIZE_STRING);

    // Check for duplicates excluding current record
    $stmt = $conn->prepare("SELECT id FROM colleges WHERE (name = ? OR code = ?) AND id != ?");
    $stmt->bind_param('ssi', $name, $code, $id);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        echo json_encode(['status' => 'error', 'message' => 'Another college with this name or code already exists']);
        $stmt->close();
        return;
    }
    $stmt->close();

    $stmt = $conn->prepare("UPDATE colleges SET name = ?, code = ? WHERE id = ?");
    $stmt->bind_param('ssi', $name, $code, $id);

    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'College updated successfully']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to update college']);
    }

    $stmt->close();
}

function deleteCollege($conn) {
    $input = json_decode(file_get_contents("php://input"), true);
    $id = filter_var($input['id'], FILTER_SANITIZE_NUMBER_INT);

    $stmt = $conn->prepare("DELETE FROM colleges WHERE id = ?");
    $stmt->bind_param('i', $id);

    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'College deleted successfully']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to delete college']);
    }

    $stmt->close();
}

$conn->close();
?>
