<?php
// school_timetable_api.php
ini_set('display_errors',1);
error_reporting(E_ALL);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER['REQUEST_METHOD']==='OPTIONS') exit;

include(__DIR__.'/db_connection.php');
if (!$conn) {
  http_response_code(500);
  exit(json_encode(['status'=>'error','message'=>'DB connection failed']));
}

$action = $_REQUEST['action'] ?? '';

try {
  switch ($action) {
    // ───── Sessions ─────
    case 'list_sessions':
      $res = $conn->query("SELECT session_id,session_name FROM academic_sessions ORDER BY start_year DESC");
      $data = $res->fetch_all(MYSQLI_ASSOC);
      echo json_encode(['status'=>'success','data'=>$data]);
      break;

    case 'create_session':
      // Exactly mirror the SQL logic from the CREATE PROCEDURE, in PHP:
      $conn->begin_transaction();
      // ...determine next_start/next_end, INSERT IGNORE, promote levels, open semester...
      // For brevity, reuse stored proc if you exported one:
      $conn->query("CALL CreateNextSession()");
      echo json_encode(['status'=>'success','message'=>'Session created']);
      $conn->commit();
      break;

    // ───── Semesters ─────
    case 'list_semesters':
      $sid = intval($_GET['session_id'] ?? 0);
      if (!$sid) throw new Exception('session_id required');
      $stmt = $conn->prepare("
        SELECT semester_id,semester_number,start_date,end_date,status,archived
        FROM semesters WHERE session_id=? ORDER BY semester_number
      ");
      $stmt->bind_param('i',$sid);
      $stmt->execute();
      $out = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
      echo json_encode(['status'=>'success','data'=>$out]);
      break;

    case 'switch_context':
      $body = json_decode(file_get_contents('php://input'), true);
      $sid = intval($body['session_id'] ?? 0);
      $sem = intval($body['semester_number'] ?? 0);
      if (!$sid || !$sem) throw new Exception('session_id & semester_number required');
      echo json_encode(['status'=>'success','data'=>['active_session'=>$sid,'active_semester'=>$sem]]);
      break;

    // ───── Cleanup & Versioning ─────
    case 'run_cleanup':
      $body = json_decode(file_get_contents('php://input'), true);
      $desc = $conn->real_escape_string($body['description'] ?? 'Automated');
      $conn->query("CALL EndOfYearCleanup('$desc')");
      echo json_encode(['status'=>'success','message'=>'Cleanup run']);
      break;

    case 'restore_version':
      $body = json_decode(file_get_contents('php://input'), true);
      $vid = intval($body['version_id'] ?? 0);
      if (!$vid) throw new Exception('version_id required');
      $conn->query("CALL RestoreVersion($vid)");
      echo json_encode(['status'=>'success','message'=>'Restored']);
      break;

    case 'list_cleanup_versions':
      $res = $conn->query("
        SELECT v.version_id,v.run_timestamp,v.description
        FROM cleanup_versions v
        ORDER BY v.run_timestamp DESC
      ");
      echo json_encode(['status'=>'success','data'=>$res->fetch_all(MYSQLI_ASSOC)]);
      break;

    // ───── Stats ─────
    case 'stats':
      // Example: count total students, active lecturers, courses running
      $tot = $conn->query("SELECT COUNT(*) FROM students")->fetch_row()[0];
      $lec = $conn->query("SELECT COUNT(*) FROM lecturers WHERE active=1")->fetch_row()[0];
      $crs = $conn->query("SELECT COUNT(*) FROM courses WHERE status='running'")->fetch_row()[0];
      echo json_encode(['status'=>'success','data'=>[
        'totalStudents'=>intval($tot),
        'activeLecturers'=>intval($lec),
        'coursesRunning'=>intval($crs)
      ]]);
      break;

    // ───── Programs & Levels ─────
    case 'list_programs':
      $res = $conn->query("
        SELECT p.id,p.name,p.department_id,d.name AS department_name
        FROM programs p
        LEFT JOIN departments d ON d.id=p.department_id
        ORDER BY p.name
      ");
      echo json_encode(['status'=>'success','data'=>$res->fetch_all(MYSQLI_ASSOC)]);
      break;

    case 'list_levels':
      $res = $conn->query("
        SELECT l.id,l.level,l.students_count,l.program_id,p.name AS program_name
        FROM levels l
        JOIN programs p ON p.id=l.program_id
        ORDER BY p.name,l.level
      ");
      echo json_encode(['status'=>'success','data'=>$res->fetch_all(MYSQLI_ASSOC)]);
      break;

    case 'add_level':
      $b = json_decode(file_get_contents('php://input'),true);
      $stmt = $conn->prepare("
        INSERT INTO levels(program_id,level,students_count) VALUES(?,?,?)
      ");
      $stmt->bind_param('iii',$b['program_id'],$b['level'],$b['students_count']);
      $stmt->execute();
      echo json_encode(['status'=>'success','insert_id'=>$stmt->insert_id]);
      break;

    case 'update_level':
      $b = json_decode(file_get_contents('php://input'),true);
      $stmt = $conn->prepare("
        UPDATE levels SET program_id=?,level=?,students_count=? WHERE id=?
      ");
      $stmt->bind_param('iiii',$b['program_id'],$b['level'],$b['students_count'],$b['id']);
      $stmt->execute();
      echo json_encode(['status'=>'success','affected'=>$stmt->affected_rows]);
      break;

    case 'delete_level':
      $b = json_decode(file_get_contents('php://input'),true);
      $stmt = $conn->prepare("DELETE FROM levels WHERE id=?");
      $stmt->bind_param('i',$b['id']);
      $stmt->execute();
      echo json_encode(['status'=>'success','affected'=>$stmt->affected_rows]);
      break;

    default:
      throw new Exception('Unsupported action');
  }
} catch(Exception $e) {
  if($conn->in_transaction) $conn->rollback();
  http_response_code(400);
  echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}
$conn->close();
