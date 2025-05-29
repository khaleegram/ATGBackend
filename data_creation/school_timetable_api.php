<?php
// Unified API: school_timetable_api.php
// Provides endpoints for academic session and semester management, including cleanup routines

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

include(__DIR__ . '/../db_connection.php');

// Determine action parameter (GET or POST)
$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';

try {
    switch ($action) {
        case 'list_sessions':
            listSessions($conn);
            break;
        case 'create_session':
            createSession($conn);
            break;
        case 'run_cleanup':
            runCleanup($conn);
            break;
        case 'restore_version':
            restoreVersion($conn);
            break;
        case 'list_semesters':
            listSemesters($conn);
            break;
        case 'switch_context':
            switchContext();
            break;
        default:
            throw new Exception('Invalid or missing action.');
    }
} catch (Exception $e) {
    if ($conn->in_transaction) {
        $conn->rollback();
    }
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();
exit;

/**
 * Fetch all academic sessions.
 */
function listSessions($conn) {
    $sql = "SELECT session_id, start_year, end_year FROM academic_sessions ORDER BY start_year DESC";
    $result = $conn->query($sql);
    if (!$result) throw new Exception($conn->error);
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = [
            'session_id'   => (int)$row['session_id'],
            'start_year'   => (int)$row['start_year'],
            'end_year'     => (int)$row['end_year'],
            'session_name' => "{$row['start_year']}/{$row['end_year']}"
        ];
    }
    echo json_encode(['status' => 'success', 'data' => $data]);
}

/**
 * Create or reuse next academic session and promote levels.
 */
function createSession($conn) {
    $conn->begin_transaction();

    // Determine next session years
    $res = $conn->query("SELECT start_year, end_year FROM academic_sessions ORDER BY session_id DESC LIMIT 1");
    if (!$res) throw new Exception($conn->error);
    if ($res->num_rows === 0) {
        $next_start = (int)date('Y');
        $next_end   = $next_start + 1;
    } else {
        $row = $res->fetch_assoc();
        $next_start = (int)$row['start_year'] + 1;
        $next_end   = (int)$row['end_year'] + 1;
    }

    // Insert or reuse session record
    $stmt = $conn->prepare(
        "INSERT INTO academic_sessions(start_year,end_year) VALUES (?,?) ON DUPLICATE KEY UPDATE session_id=session_id"
    );
    if (!$stmt) throw new Exception($conn->error);
    $stmt->bind_param('ii', $next_start, $next_end);
    $stmt->execute();
    $new_id = $stmt->insert_id;
    $stmt->close();

    if ($new_id === 0) {
        $row = $conn->query(
            "SELECT session_id FROM academic_sessions WHERE start_year={$next_start} AND end_year={$next_end} LIMIT 1"
        );
        if (!$row) throw new Exception($conn->error);
        $new_id = (int)$row->fetch_assoc()['session_id'];
    }

    // Archive current level counts for new session
    $sess_name = "{$next_start}/{$next_end}";
    if (!$conn->query(
        "INSERT INTO level_counts_history(session_name,semester_number,`level`,program_id,student_count)
         SELECT '{$sess_name}',1,`level`,program_id,students_count FROM levels"
    )) throw new Exception($conn->error);

    // Promote levels within a transaction
    if (!$conn->query("DROP TEMPORARY TABLE IF EXISTS tmp_counts, max_levels")) throw new Exception($conn->error);
    if (!$conn->query(
        "CREATE TEMPORARY TABLE tmp_counts AS SELECT `level`,program_id,students_count,promotion_rate FROM levels WHERE students_count>0"
    )) throw new Exception($conn->error);
    if (!$conn->query(
        "CREATE TEMPORARY TABLE max_levels AS SELECT program_id,MAX(`level`) AS max_level FROM levels GROUP BY program_id"
    )) throw new Exception($conn->error);
    if (!$conn->query(
        "INSERT INTO levels(program_id,level,students_count,promotion_rate)
         SELECT program_id,level+1,FLOOR(students_count*promotion_rate),1.00 FROM tmp_counts t
         JOIN max_levels m USING(program_id) WHERE t.level<m.max_level
         ON DUPLICATE KEY UPDATE students_count=students_count+VALUES(students_count)"
    )) throw new Exception($conn->error);
    if (!$conn->query(
        "UPDATE levels l JOIN tmp_counts t USING(program_id,level)
         SET l.students_count = t.students_count - FLOOR(t.students_count*t.promotion_rate)
         WHERE t.level < (SELECT max_level FROM max_levels WHERE program_id=t.program_id)"
    )) throw new Exception($conn->error);
    if (!$conn->query("DROP TEMPORARY TABLE tmp_counts, max_levels")) throw new Exception($conn->error);

    // Open first semester for new session
    $one = 1;
    $stmt2 = $conn->prepare(
        "INSERT INTO semesters(session_id,semester_number,start_date,status)
         VALUES(?,?,CURDATE(),'open') ON DUPLICATE KEY UPDATE semester_id=semester_id"
    );
    if (!$stmt2) throw new Exception($conn->error);
    $stmt2->bind_param('ii', $new_id, $one);
    $stmt2->execute();
    $stmt2->close();

    $conn->commit();
    echo json_encode(['status' => 'success', 'session_id' => $new_id]);
}

/**
 * Execute the end-of-year cleanup stored procedure.
 */
function runCleanup($conn) {
    $data = json_decode(file_get_contents('php://input'), true);
    $desc = isset($data['description']) && is_string($data['description'])
        ? $conn->real_escape_string($data['description'])
        : 'Automated cleanup';
    if (!$conn->query("CALL EndOfYearCleanup('{$desc}')")) {
        throw new Exception($conn->error);
    }
    echo json_encode(['status' => 'success', 'message' => 'Cleanup procedure executed.']);
}

/**
 * Restore levels from a previous cleanup version.
 */
function restoreVersion($conn) {
    $data = json_decode(file_get_contents('php://input'), true);
    $version_id = isset($data['version_id']) ? intval($data['version_id']) : 0;
    if ($version_id < 1) {
        throw new Exception('Valid version_id required.');
    }
    if (!$conn->query("CALL RestoreVersion({$version_id})")) {
        throw new Exception($conn->error);
    }
    echo json_encode(['status' => 'success', 'message' => 'Version restored successfully.']);
}

/**
 * List semesters for a given session.
 */
function listSemesters($conn) {
    if (empty($_GET['session_id'])) {
        throw new Exception('session_id is required');
    }
    $sid = filter_var($_GET['session_id'], FILTER_SANITIZE_NUMBER_INT);

    $stmt = $conn->prepare(
        "SELECT semester_id, session_id, semester_number, start_date, end_date, archived
         FROM semesters WHERE session_id=? ORDER BY semester_number"
    );
    if (!$stmt) throw new Exception($conn->error);
    $stmt->bind_param('i', $sid);
    $stmt->execute();
    $res = $stmt->get_result();
    $data = [];
    while ($row = $res->fetch_assoc()) {
        $data[] = $row;
    }
    $stmt->close();
    echo json_encode(['status' => 'success', 'data' => $data]);
}

/**
 * Switch context (session & semester) without DB changes.
 */
function switchContext() {
    $data = json_decode(file_get_contents('php://input'), true);
    $sid = isset($data['session_id']) ? intval($data['session_id']) : 0;
    $sem = isset($data['semester_number']) ? intval($data['semester_number']) : 0;
    if ($sid < 1 || !in_array($sem, [1,2], true)) {
        throw new Exception('Valid session_id & semester_number required.');
    }
    echo json_encode(['status' => 'success', 'data' => ['active_session' => $sid, 'active_semester' => $sem]]);
}
?>
