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

    // 1) Determine next session years
    $res = $conn->query("SELECT start_year,end_year FROM academic_sessions ORDER BY session_id DESC LIMIT 1");
    if ($res->num_rows === 0) {
        $next_start = (int)date('Y');
        $next_end   = $next_start + 1;
    } else {
        $row = $res->fetch_assoc();
        $next_start = intval($row['start_year']) + 1;
        $next_end   = intval($row['end_year']) + 1;
    }

    // 2) Insert or reuse session
    $stmt = $conn->prepare("
        INSERT INTO academic_sessions(start_year,end_year)
        VALUES (?,?)
        ON DUPLICATE KEY UPDATE session_id = session_id
    ");
    $stmt->bind_param("ii", $next_start, $next_end);
    $stmt->execute();
    $new_sess = $stmt->insert_id ?: $conn->query("
        SELECT session_id 
        FROM academic_sessions 
        WHERE start_year=$next_start AND end_year=$next_end 
        LIMIT 1
    ")->fetch_assoc()['session_id'];
    $stmt->close();

    $sess_name = "{$next_start}/{$next_end}";

    // *** REMOVE THIS BLOCK ***
    // $conn->query("
    //   INSERT INTO level_counts_history( … ) 
    //   SELECT … FROM levels
    // ");

    // 3) Promote students (exactly as before)…
    $conn->query("DROP TEMPORARY TABLE IF EXISTS tmp_counts, max_levels");
    $conn->query("
      CREATE TEMPORARY TABLE tmp_counts AS
      SELECT `level`,program_id,students_count
      FROM levels WHERE students_count>0
    ");
    $conn->query("
      CREATE TEMPORARY TABLE max_levels AS
      SELECT program_id,MAX(`level`) AS max_level
      FROM levels GROUP BY program_id
    ");
    $conn->query("
      UPDATE levels l
      JOIN tmp_counts t ON l.program_id=t.program_id AND l.`level`=t.`level`+1
      JOIN max_levels m ON t.program_id=m.program_id
      SET l.students_count=l.students_count+t.students_count
      WHERE t.`level`<m.max_level
    ");
    $conn->query("
      UPDATE levels l
      JOIN tmp_counts t ON l.program_id=t.program_id AND l.`level`=t.`level`
      SET l.students_count=0
    ");
    $conn->query("DROP TEMPORARY TABLE tmp_counts, max_levels");

    // 4) Open Semester 1 on new session
    $one = 1;
    $stmt2 = $conn->prepare("
      INSERT INTO semesters(session_id,semester_number,start_date,status)
      VALUES (?,?,CURDATE(),'open')
      ON DUPLICATE KEY UPDATE semester_id=semester_id
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

    // Resolve session_name
    $stmt1 = $conn->prepare("SELECT start_year,end_year FROM academic_sessions WHERE session_id=?");
    $stmt1->bind_param("i",$session_id);
    $stmt1->execute();
    $r1 = $stmt1->get_result();
    if ($r1->num_rows===0) { echo json_encode(['status'=>'error','message'=>'Invalid session ID']); return; }
    $s = $r1->fetch_assoc();
    $session_name = "{$s['start_year']}/{$s['end_year']}";
    $stmt1->close();

    // Resolve semester_number
    $stmt2 = $conn->prepare("SELECT semester_number FROM semesters WHERE semester_id=?");
    $stmt2->bind_param("i",$semester_id);
    $stmt2->execute();
    $r2 = $stmt2->get_result();
    if ($r2->num_rows===0) { echo json_encode(['status'=>'error','message'=>'Invalid semester ID']); return; }
    $sem_num = $r2->fetch_assoc()['semester_number'];
    $stmt2->close();

    // Fetch history
    $stmt3 = $conn->prepare("
        SELECT lch.program_id, lch.level, lch.student_count AS count, p.name AS program_name
        FROM level_counts_history lch
        JOIN programs p ON lch.program_id = p.program_id
        WHERE lch.session_name=? AND lch.semester_number=?
        ORDER BY p.name,lch.level
    ");
    $stmt3->bind_param("si",$session_name,$sem_num);
    $stmt3->execute();
    $r3 = $stmt3->get_result();

    $out = [];
    while ($row = $r3->fetch_assoc()) {
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
        SELECT semester_id,session_id,semester_number,start_date,end_date
        FROM semesters
        WHERE session_id=?
        ORDER BY semester_number
    ");
    $stmt->bind_param("i",$session_id);
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
    if (!$stmt) throw new Exception($conn->error);
    $stmt->bind_param("s",$desc);
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
    if (!$stmt) throw new Exception($conn->error);
    $stmt->bind_param("i",$vid);
    if (!$stmt->execute()) throw new Exception($stmt->error);
    $stmt->close();
    echo json_encode(['status'=>'success','message'=>"Restored to version {$vid}"]);
}
