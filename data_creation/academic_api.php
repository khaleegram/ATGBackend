<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

include('../db_connection.php');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'get_sessions':
            getSessions($conn);
            break;
        case 'create_session':
            createSession($conn);
            break;
        case 'cleanup':
            cleanupSemester($conn);
            break;
        case 'get_level_history':
            getLevelCountsHistory($conn);
            break;
        case 'get_semesters':
            getSemesters($conn);
            break;
        case 'switch_context':
            switchContext($conn);
            break;
        case 'end_of_year_cleanup':
            endOfYearCleanup($conn);
            break;
        case 'restore_version':
            restoreVersion($conn);
            break;
        case 'get_active_context':
                getActiveContext($conn);
                break;
            
        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
} finally {
    $conn->close();
}

// === FUNCTIONS ===

function getSessions($conn) {
    $res = $conn->query("SELECT session_id, start_year, end_year FROM academic_sessions ORDER BY start_year DESC");
    $out = [];
    while ($r = $res->fetch_assoc()) {
        $out[] = [
            'session_id'   => $r['session_id'],
            'start_year'   => $r['start_year'],
            'end_year'     => $r['end_year'],
            'session_name' => "{$r['start_year']}/{$r['end_year']}"
        ];
    }
    echo json_encode(['status'=>'success','data'=>$out]);
}

function createSession($conn) {
    $conn->begin_transaction();

    // Determine next session years
    $res = $conn->query("SELECT start_year, end_year FROM academic_sessions ORDER BY session_id DESC LIMIT 1");
    if ($res->num_rows === 0) {
        $next_start = (int)date('Y');
        $next_end   = $next_start + 1;
    } else {
        $row = $res->fetch_assoc();
        $next_start = intval($row['start_year']) + 1;
        $next_end   = intval($row['end_year']) + 1;
    }

    // Insert or reuse session
    $stmt = $conn->prepare("
        INSERT INTO academic_sessions(start_year, end_year)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE session_id = session_id
    ");
    $stmt->bind_param("ii", $next_start, $next_end);
    $stmt->execute();
    $new_sess = $stmt->insert_id ?: $conn->query("
        SELECT session_id FROM academic_sessions WHERE start_year=$next_start AND end_year=$next_end LIMIT 1
    ")->fetch_assoc()['session_id'];
    $stmt->close();

    // Open Semester 1 on new session
    $one = 1;
    $stmt2 = $conn->prepare("
        INSERT INTO semesters(session_id, semester_number, start_date, status)
        VALUES (?, ?, CURDATE(), 'open')
        ON DUPLICATE KEY UPDATE semester_id = semester_id
    ");
    $stmt2->bind_param("ii", $new_sess, $one);
    $stmt2->execute();
    $stmt2->close();

    $conn->commit();
    echo json_encode(['status'=>'success','session_id'=>$new_sess]);
}

function cleanupSemester($conn) {
    if (!$conn->query("CALL ToggleSemester()")) {
        throw new Exception($conn->error);
    }
    echo json_encode(['status'=>'success','message'=>'Semester toggled successfully.']);
}

function getLevelCountsHistory($conn) {
    $session_id  = filter_input(INPUT_GET, 'session_id', FILTER_SANITIZE_NUMBER_INT);
    $semester_id = filter_input(INPUT_GET, 'semester_id', FILTER_SANITIZE_NUMBER_INT);
    if (!$session_id || !$semester_id) {
        echo json_encode(['status'=>'error','message'=>'session_id and semester_id are required']);
        return;
    }

    $stmt = $conn->prepare("
        SELECT lch.program_id, lch.level, lch.student_count AS count, p.name AS program_name
        FROM level_counts_history lch
        JOIN programs p ON lch.program_id = p.program_id
        WHERE lch.session_id = ? AND lch.semester_id = ?
        ORDER BY p.name, lch.level
    ");
    $stmt->bind_param("ii", $session_id, $semester_id);
    $stmt->execute();
    $r = $stmt->get_result();

    $out = [];
    while ($row = $r->fetch_assoc()) {
        $out[] = [
            'id'             => $row['program_id']*10 + $row['level'],
            'program_id'     => $row['program_id'],
            'program_name'   => $row['program_name'],
            'level'          => $row['level'],
            'students_count' => $row['count']
        ];
    }
    echo json_encode(['status'=>'success','data'=>$out]);
}

function getSemesters($conn) {
    $session_id = filter_input(INPUT_GET, 'session_id', FILTER_SANITIZE_NUMBER_INT);
    if (!$session_id) {
        echo json_encode(['status'=>'error','message'=>'session_id is required']);
        return;
    }

    $stmt = $conn->prepare("
        SELECT semester_id, session_id, semester_number, start_date, end_date
        FROM semesters
        WHERE session_id = ?
        ORDER BY semester_number
    ");
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    $r = $stmt->get_result();

    $out = [];
    while ($row = $r->fetch_assoc()) {
        $out[] = $row;
    }
    echo json_encode(['status'=>'success','data'=>$out]);
}

function switchContext($conn) {
    $data = json_decode(file_get_contents("php://input"), true);
    $sid = intval($data['session_id'] ?? 0);
    $sem = intval($data['semester_number'] ?? 0);
    if (!$sid || !in_array($sem, [1,2], true)) {
        echo json_encode(['status'=>'error','message'=>'Valid session_id & semester_number required.']);
        return;
    }
    echo json_encode([
        'status'=>'success',
        'data'=>['active_session'=>$sid,'active_semester'=>$sem]
    ]);
}

function endOfYearCleanup($conn) {
    $desc = trim($_POST['description'] ?? '');
    if ($desc === '') {
        echo json_encode(['status'=>'error','message'=>'description is required']);
        return;
    }
    $stmt = $conn->prepare("CALL EndOfYearCleanup(?)");
    $stmt->bind_param("s", $desc);
    if (!$stmt->execute()) throw new Exception($stmt->error);
    $stmt->close();
    echo json_encode(['status'=>'success','message'=>'End-of-year cleanup completed']);
}

function restoreVersion($conn) {
    $vid = intval($_POST['version_id'] ?? 0);
    if ($vid <= 0) {
        echo json_encode(['status'=>'error','message'=>'Valid version_id is required']);
        return;
    }
    $stmt = $conn->prepare("CALL RestoreVersion(?)");
    $stmt->bind_param("i", $vid);
    if (!$stmt->execute()) throw new Exception($stmt->error);
    $stmt->close();
    echo json_encode(['status'=>'success','message'=>"Restored to version {$vid}"]);
}
function getActiveContext($conn) {
    $stmt = $conn->prepare("SELECT session_id, semester_number FROM active_context LIMIT 1");
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        echo json_encode(['status'=>'error','message'=>'No active context found']);
        return;
    }
    $context = $result->fetch_assoc();
    echo json_encode(['status'=>'success','data'=>$context]);
}