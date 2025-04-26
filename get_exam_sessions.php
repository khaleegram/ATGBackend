php
<?php
// db_connection.php should contain your database connection details
include 'db_connection.php';

$sql = "
    SELECT
        e.exam_id AS examId,
        COALESCE(c.course_name, cc.name) AS course,
        v.name AS venue,
        e.start_time AS startTime,
        e.end_time AS endTime
    FROM
        exam_sessions e
    LEFT JOIN
        courses c ON e.course_id = c.course_id
    LEFT JOIN
        combined_courses cc ON e.combined_course_id = cc.combined_course_id
    JOIN
        venues v ON e.venue_id = v.venue_id
";

$result = $conn->query($sql);

$examSessions = array();
if ($result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $examSessions[] = $row;
    }
}

header('Content-Type: application/json');
echo json_encode($examSessions);

$conn->close();
?>