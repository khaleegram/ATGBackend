php
<?php
// Include your database connection file
include 'db_connection.php';

// Basic authorization check (replace with your actual authentication)
// In a real application, you'd have a more robust authentication system
if (!isset($_SERVER['PHP_AUTH_USER']) || $_SERVER['PHP_AUTH_USER'] != 'admin' || $_SERVER['PHP_AUTH_PW'] != 'your_admin_password') {
    header('WWW-Authenticate: Basic realm="Admin Area"');
    header('HTTP/1.0 401 Unauthorized');
    echo 'Unauthorized';
    exit;
}

try {
    // Call the stored procedure
    $stmt = $conn->prepare("CALL EndOfYearCleanup()");
    $stmt->execute();

    // Check for errors (a more robust check might be needed)
    if ($stmt->errorCode() != '00000') {
        throw new Exception("Error executing stored procedure: " . print_r($stmt->errorInfo(), true));
    }

    echo json_encode(['success' => true, 'message' => 'End-of-year cleanup completed successfully.']);
} catch (Exception $e) {
    // Handle errors (log them, return an error response, etc.)
    http_response_code(500); // Internal Server Error
    echo json_encode(['success' => false, 'message' => 'End-of-year cleanup failed: ' . $e->getMessage()]);
    // You might want to log the error to a file or database here
} finally {
    // Close the database connection
    if ($conn) {
        $conn->close();
    }
}
?>