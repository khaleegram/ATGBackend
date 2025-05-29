<?php
// File: create_session.php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER['REQUEST_METHOD']=='OPTIONS') { http_response_code(200); exit; }

include('../db_connection.php');
header('Content-Type: application/json');

try {
    $conn->begin_transaction();

    // 1) Check if there's any session at all
    $res = $conn->query("SELECT start_year,end_year,session_id FROM academic_sessions ORDER BY session_id DESC LIMIT 1");
    if ($res->num_rows === 0) {
        // Seed the very first session
        $next_start = (int)date('Y');
        $next_end   = $next_start + 1;
    } else {
        $row = $res->fetch_assoc();
        $next_start = intval($row['start_year']) + 1;
        $next_end   = intval($row['end_year'])   + 1;
    }

    // 2) Insert (or reuse) the session
    //    Prevent duplicate via UNIQUE on (start_year,end_year)
    $stmt = $conn->prepare("
        INSERT INTO academic_sessions(start_year,end_year)
        VALUES (?,?)
        ON DUPLICATE KEY UPDATE session_id = session_id
    ");
    $stmt->bind_param("ii", $next_start, $next_end);
    $stmt->execute();
    // get the session_id (whether new or existing)
    $new_sess = $stmt->insert_id ?: $conn->query("
      SELECT session_id FROM academic_sessions
       WHERE start_year = $next_start AND end_year = $next_end
      LIMIT 1
    ")->fetch_assoc()['session_id'];
    $stmt->close();

    // 3) Archive & promote levels (only on session creation)
    $sess_name = "{$next_start}/{$next_end}";
    $conn->query("
      INSERT INTO level_counts_history(session_name,semester_number,`level`,program_id,student_count)
      SELECT '$sess_name', 1, l.`level`, l.program_id, l.students_count
      FROM levels l
    ");
    // Promote (same temp table logic)…
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

    // 4) Open Semester 1 on that session
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
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}
$conn->close();
