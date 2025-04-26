<?php
include('../db_connection.php');

// Allow CORS and JSON responses
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header('Content-Type: application/json');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

/**
 * Fetch both the course's original program-level and any additional offerings
 */
function fetchOfferings($conn, $combinedCourseId) {
    $offerings = [];

    // 1. Original association via courses -> levels -> programs
    $stmtBase = $conn->prepare(
        "SELECT l.id AS level_id, l.level AS level_number, l.program_id, p.name AS program_name
         FROM combined_courses cc
         JOIN courses c ON cc.course_code = c.course_code
         JOIN levels l ON c.level_id = l.id
         JOIN programs p ON l.program_id = p.program_id
         WHERE cc.id = ?"
    );
    $stmtBase->bind_param("i", $combinedCourseId);
    $stmtBase->execute();
    $base = $stmtBase->get_result()->fetch_assoc();
    if ($base) {
        $offerings[] = [
            'program_id'   => (int)$base['program_id'],  
            'program_name' => $base['program_name'],     
            'level_id'     => (int)$base['level_id'],     
            'level_number' => (int)$base['level_number']  
        ];
    }

    // 2. Additional offerings table
    $stmt = $conn->prepare(
        "SELECT o.program_id, p.name AS program_name, o.level_id, l.level AS level_number
         FROM combined_courses_offerings o
         JOIN programs p ON o.program_id = p.program_id
         JOIN levels l ON o.level_id = l.id
         WHERE o.combined_course_id = ?"
    );
    $stmt->bind_param("i", $combinedCourseId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($rows as $r) {
        // skip duplicate of original
        if ($base && $r['program_id'] == $base['program_id'] && $r['level_id'] == $base['level_id']) {
            continue;
        }
        $offerings[] = [
            'program_id'   => (int)$r['program_id'],  
            'program_name' => $r['program_name'],     
            'level_id'     => (int)$r['level_id'],     
            'level_number' => (int)$r['level_number']  
        ];
    }

    return $offerings;
}

// -------- GET handlers --------
if ($method === 'GET') {
    // Single record by ID
    if (isset($_GET['id'])) {
        $ccid = intval($_GET['id']);
        // include exam_type from courses table
        $stmt = $conn->prepare(
            "SELECT cc.id, cc.course_code, cc.course_name, c.exam_type
             FROM combined_courses cc
             JOIN courses c ON cc.course_code = c.course_code
             WHERE cc.id = ? LIMIT 1"
        );
        $stmt->bind_param("i", $ccid);
        $stmt->execute();
        $course = $stmt->get_result()->fetch_assoc();
        if ($course) {
            $course['offerings'] = fetchOfferings($conn, $ccid);
            echo json_encode(['status'=>'success','data'=>$course]);
        } else {
            echo json_encode(['status'=>'error','message'=>'Not found']);
        }
        exit;
    }

    // Filtered list: search, program_id, level_id
    $conds = [];
    if (!empty($_GET['search'])) {
        $s = '%' . $conn->real_escape_string($_GET['search']) . '%';
        $conds[] = "(cc.course_code LIKE '$s' OR cc.course_name LIKE '$s')";
    }
    if (!empty($_GET['program_id'])) {
        $pid = intval($_GET['program_id']);
        $conds[] = "(
            EXISTS(SELECT 1 FROM combined_courses_offerings o WHERE o.combined_course_id=cc.id AND o.program_id=$pid)
            OR EXISTS(SELECT 1 FROM courses c JOIN levels l ON c.level_id=l.id WHERE c.course_code=cc.course_code AND l.program_id=$pid)
        )";
    }
    if (!empty($_GET['level_id'])) {
        $lid = intval($_GET['level_id']);
        $conds[] = "(
            EXISTS(SELECT 1 FROM combined_courses_offerings o WHERE o.combined_course_id=cc.id AND o.level_id=$lid)
            OR EXISTS(SELECT 1 FROM courses c WHERE c.course_code=cc.course_code AND c.level_id=$lid)
        )";
    }

    // include exam_type from courses
    $sql = "
        SELECT cc.id, cc.course_code, cc.course_name, c.exam_type
        FROM combined_courses cc
        JOIN courses c ON cc.course_code = c.course_code
    ";
    if ($conds) {
        $sql .= ' WHERE ' . implode(' AND ', $conds);
    }

    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $out = [];
    foreach ($rows as $r) {
        $r['id']        = (int)$r['id'];
        // offerings unchanged
        $r['offerings'] = fetchOfferings($conn, $r['id']);
        $out[]          = $r;
    }
    echo json_encode(['status'=>'success','data'=>$out]);
    exit;
}

// -------- POST handler --------
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'),true);
    $code = strtoupper(trim($data['course_code'] ?? ''));
    $name = ucwords(strtolower(trim($data['course_name'] ?? '')));
    $ofs  = is_array($data['offerings']) ? $data['offerings'] : [];

    if (!$code || !$name || empty($ofs)) {
        echo json_encode(['status'=>'error','message'=>'code, name & offerings required']);
        exit;
    }

    // verify course exists
    $chk = $conn->prepare("SELECT course_code FROM courses WHERE course_code=? LIMIT 1");
    $chk->bind_param("s",$code); $chk->execute();
    if (!$chk->get_result()->num_rows) {
        echo json_encode(['status'=>'error','message'=>'Course not in courses table']); exit;
    }

    // no duplicate combined course
    $dup = $conn->prepare("SELECT id FROM combined_courses WHERE course_code=?");
    $dup->bind_param("s",$code); $dup->execute();
    if ($dup->get_result()->num_rows) {
        echo json_encode(['status'=>'error','message'=>'Combined course already exists']); exit;
    }

    // insert master
    $ins = $conn->prepare("INSERT INTO combined_courses(course_code,course_name)VALUES(?,?)");
    $ins->bind_param("ss",$code,$name);
    if (!$ins->execute()) { echo json_encode(['status'=>'error','message'=>$ins->error]); exit; }
    $cid = $ins->insert_id;

    // insert offerings
    $oins = $conn->prepare(
        "INSERT INTO combined_courses_offerings(combined_course_id,program_id,level_id)VALUES(?,?,?)"
    );
    foreach($ofs as $o) {
        $pid=intval($o['program_id']);
        $lid=intval($o['level_id']);
        $oins->bind_param("iii", $cid, $pid, $lid);
        $oins->execute();
    }

    // return new record (including exam_type via fetchOfferings wrapper)
    $course = [
      'id'          => $cid,
      'course_code' => $code,
      'course_name' => $name,
      'exam_type'   => $conn->query("SELECT exam_type FROM courses WHERE course_code='$code'")->fetch_assoc()['exam_type'],
      'offerings'   => fetchOfferings($conn,$cid)
    ];
    echo json_encode(['status'=>'success','data'=>$course]);
    exit;
}

// -------- PUT handler --------
if ($method==='PUT') {
    $data = json_decode(file_get_contents('php://input'),true);
    $cid  = intval($data['id'] ?? 0);
    $code = strtoupper(trim($data['course_code'] ?? ''));
    $name = ucwords(strtolower(trim($data['course_name'] ?? '')));
    $ofs  = is_array($data['offerings']) ? $data['offerings'] : [];

    if (!$cid||!$code||!$name||empty($ofs)) {
        echo json_encode(['status'=>'error','message'=>'id, code, name & offerings required']); exit;
    }

    // verify exists
    $chk=$conn->prepare("SELECT id FROM combined_courses WHERE id=? LIMIT 1");
    $chk->bind_param("i",$cid); $chk->execute();
    if (!$chk->get_result()->num_rows) {
        echo json_encode(['status'=>'error','message'=>'Not found']); exit;
    }

    // update master
    $up=$conn->prepare("UPDATE combined_courses SET course_code=?,course_name=? WHERE id=?");
    $up->bind_param("ssi",$code,$name,$cid);
    if (!$up->execute()) { echo json_encode(['status'=>'error','message'=>$up->error]); exit; }

    // reset offerings
    $del=$conn->prepare("DELETE FROM combined_courses_offerings WHERE combined_course_id=?");
    $del->bind_param("i",$cid); $del->execute();

    // reinsert offerings
    $oins=$conn->prepare(
        "INSERT INTO combined_courses_offerings(combined_course_id,program_id,level_id)VALUES(?,?,?)"
    );
    foreach($ofs as $o) {
        $pid=intval($o['program_id']); $lid=intval($o['level_id']);
        $oins->bind_param("iii",$cid,$pid,$lid); $oins->execute();
    }

    $course = [
      'id'          => $cid,
      'course_code' => $code,
      'course_name' => $name,
      'exam_type'   => $conn->query("SELECT exam_type FROM courses WHERE course_code='$code'")->fetch_assoc()['exam_type'],
      'offerings'   => fetchOfferings($conn,$cid)
    ];
    echo json_encode(['status'=>'success','data'=>$course]);
    exit;
}

// -------- DELETE handler --------
if ($method==='DELETE') {
    $data=json_decode(file_get_contents('php://input'),true);
    $cid=intval($data['id'] ?? 0);
    if (!$cid) { echo json_encode(['status'=>'error','message'=>'id required']); exit; }

    // delete offerings
    $delO=$conn->prepare("DELETE FROM combined_courses_offerings WHERE combined_course_id=?");
    $delO->bind_param("i",$cid); $delO->execute();

    // delete master
    $delC=$conn->prepare("DELETE FROM combined_courses WHERE id=?");
    $delC->bind_param("i",$cid);
    if ($delC->execute()) {
        echo json_encode(['status'=>'success','message'=>'Deleted']);
    } else {
        echo json_encode(['status'=>'error','message'=>$delC->error]);
    }
    exit;
}

// Method not allowed
http_response_code(405);
echo json_encode(['status'=>'error','message'=>'Method not allowed']);
exit;
?>
