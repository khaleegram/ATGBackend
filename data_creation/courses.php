<?php
include('../db_connection.php');

// Set headers
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header('Content-Type: application/json');

// Handle pre-flight
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

// ------------------
// GET Requests
// ------------------
if ($method === 'GET') {
    // 1. Fetch Programs
    if (isset($_GET['fetch_programs']) && $_GET['fetch_programs'] == 1) {
        $query = $conn->prepare("SELECT program_id, name FROM programs");
        $query->execute();
        $programs = $query->get_result()->fetch_all(MYSQLI_ASSOC);
        echo json_encode(['status'=>'success','data'=>$programs]);
        exit;
    }

    // 2. Fetch Levels for a given Program
    if (isset($_GET['program_id']) && !isset($_GET['fetch_students'])) {
        $prog_id = filter_var($_GET['program_id'], FILTER_SANITIZE_NUMBER_INT);
        $query = $conn->prepare("SELECT id, level, program_id, students_count FROM levels WHERE program_id = ?");
        $query->bind_param("i",$prog_id);
        $query->execute();
        $levels = $query->get_result()->fetch_all(MYSQLI_ASSOC);
        echo json_encode(['status'=>'success','data'=>$levels]);
        exit;
    }

    // 3. Fetch Courses for a given Level
    if (isset($_GET['level_id']) && !isset($_GET['fetch_students'])) {
        $level_id = filter_var($_GET['level_id'], FILTER_SANITIZE_NUMBER_INT);
        $query = $conn->prepare(
            "SELECT id, course_code, course_name, level_id, credit_unit,
                    exam_type, created_at, updated_at
             FROM courses WHERE level_id = ?"
        );
        $query->bind_param("i",$level_id);
        $query->execute();
        $courses = $query->get_result()->fetch_all(MYSQLI_ASSOC);
        echo json_encode(['status'=>'success','data'=>$courses]);
        exit;
    }

    // 4. Default: Fetch all Courses with level, program & exam_type
    $sql = "
        SELECT 
            c.id, c.course_code, c.course_name, c.level_id, c.credit_unit,
            c.exam_type, c.created_at, c.updated_at,
            l.level, p.program_id, p.name AS program_name
        FROM courses c
        LEFT JOIN levels l ON c.level_id = l.id
        LEFT JOIN programs p ON l.program_id = p.program_id
        ORDER BY c.id DESC
    ";
    $result = $conn->query($sql);
    $courses = [];
    while ($row = $result->fetch_assoc()) {
        $courses[] = $row;
    }
    echo json_encode(['status'=>'success','data'=>$courses]);
    exit;
}

// ------------------
// POST Request - Add a Course
// ------------------
elseif ($method === 'POST') {
    $data = json_decode(file_get_contents("php://input"), true);
    $course_code = strtoupper(trim($data['course_code'] ?? ''));
    $course_name = ucwords(strtolower(trim($data['course_name'] ?? '')));
    $level_id    = intval($data['level_id'] ?? 0);
    $credit_unit = intval($data['credit_unit'] ?? 0);

    if (!$course_code || !$course_name || !$level_id || !$credit_unit) {
        echo json_encode(['status'=>'error','message'=>'All fields are required.']);
        exit;
    }

    // Determine default exam_type from level
    $lvlQ = $conn->prepare("SELECT level FROM levels WHERE id = ?");
    $lvlQ->bind_param("i",$level_id);
    $lvlQ->execute();
    $lvlR = $lvlQ->get_result()->fetch_assoc();
    $exam_type = (isset($lvlR['level']) && intval($lvlR['level']) === 100)
                 ? 'CBT' : 'Written';

    // Admin override?
    if (isset($data['exam_type']) && in_array($data['exam_type'], ['CBT','Written'])) {
        $exam_type = $data['exam_type'];
    }

    // Prevent duplicate course_code
    $check = $conn->prepare("SELECT id FROM courses WHERE course_code = ?");
    $check->bind_param("s",$course_code);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        echo json_encode(['status'=>'error','message'=>'Duplicate record: Course already exists.']);
        exit;
    }

    // Insert
    $stmt = $conn->prepare(
        "INSERT INTO courses
            (course_code, course_name, level_id, credit_unit, exam_type, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, NOW(), NOW())"
    );
    $stmt->bind_param("ssiss",
        $course_code, $course_name, $level_id, $credit_unit, $exam_type
    );
    if ($stmt->execute()) {
        echo json_encode(['status'=>'success','message'=>'Course added successfully.']);
    } else {
        echo json_encode(['status'=>'error','message'=>'Failed to add course: '.$stmt->error]);
    }
    exit;
}

// ------------------
// PUT Request - Update a Course
// ------------------
elseif ($method === 'PUT') {
    $data = json_decode(file_get_contents("php://input"), true);
    $id          = intval($data['id'] ?? 0);
    $course_code = strtoupper(trim($data['course_code'] ?? ''));
    $course_name = ucwords(strtolower(trim($data['course_name'] ?? '')));
    $level_id    = intval($data['level_id'] ?? 0);
    $credit_unit = intval($data['credit_unit'] ?? 0);

    if (!$id || !$course_code || !$course_name || !$level_id || !$credit_unit) {
        echo json_encode(['status'=>'error','message'=>'All fields are required.']);
        exit;
    }

    // Default exam_type from level
    $lvlQ = $conn->prepare("SELECT level FROM levels WHERE id = ?");
    $lvlQ->bind_param("i",$level_id);
    $lvlQ->execute();
    $lvlR = $lvlQ->get_result()->fetch_assoc();
    $exam_type = (isset($lvlR['level']) && intval($lvlR['level']) === 100)
                 ? 'CBT' : 'Written';

    // Admin override?
    if (isset($data['exam_type']) && in_array($data['exam_type'], ['CBT','Written'])) {
        $exam_type = $data['exam_type'];
    }

    // Update
    $stmt = $conn->prepare(
        "UPDATE courses
         SET course_code = ?, course_name = ?, level_id = ?, credit_unit = ?, exam_type = ?, updated_at = NOW()
         WHERE id = ?"
    );
    $stmt->bind_param("ssiisi",
        $course_code, $course_name, $level_id, $credit_unit, $exam_type, $id
    );
    if ($stmt->execute()) {
        echo json_encode(['status'=>'success','message'=>'Course updated successfully.']);
    } else {
        echo json_encode(['status'=>'error','message'=>'Failed to update course: '.$stmt->error]);
    }
    exit;
}

// ------------------
// DELETE Request - Delete a Course
// ------------------
elseif ($method === 'DELETE') {
    $data = json_decode(file_get_contents("php://input"), true);
    $id = intval($data['id'] ?? 0);
    if (!$id) {
        echo json_encode(['status'=>'error','message'=>'Course ID is required.']);
        exit;
    }
    $stmt = $conn->prepare("DELETE FROM courses WHERE id = ?");
    $stmt->bind_param("i",$id);
    if ($stmt->execute()) {
        echo json_encode(['status'=>'success','message'=>'Course deleted successfully.']);
    } else {
        echo json_encode(['status'=>'error','message'=>'Failed to delete course: '.$stmt->error]);
    }
    exit;
}

// Unsupported method
echo json_encode(['status'=>'error','message'=>'Request method not supported.']);
exit;
?>
