<?php
session_start();
require 'db_connect.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$is_admin = $_SESSION['is_admin'];

// Log file for debugging (commented out to disable logging)
// $log_file = 'notification_log.txt';

// Send notification (called via POST from admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_admin && isset($_POST['send_message'])) {
    $recipient_id = $_POST['user_id'];
    $message = filter_input(INPUT_POST, 'message', FILTER_SANITIZE_STRING);
    if ($message) {
        try {
            $stmt = $pdo->prepare("INSERT INTO notifications (user_id, sender_id, message, created_at, seen) VALUES (?, ?, ?, NOW(), 0) ON DUPLICATE KEY UPDATE message = VALUES(message), created_at = VALUES(created_at), seen = 0");
            $stmt->execute([$recipient_id, $user_id, $message]);
            // file_put_contents($log_file, date('Y-m-d H:i:s') . " - Notification sent to user $recipient_id: $message\n", FILE_APPEND);
            header('Location: dashboard.php');
            exit;
        } catch (Exception $e) {
            // file_put_contents($log_file, date('Y-m-d H:i:s') . " - Error sending notification: " . $e->getMessage() . "\n", FILE_APPEND);
            http_response_code(500);
            echo json_encode(['error' => 'Failed to send notification']);
            exit;
        }
    } else {
        // file_put_contents($log_file, date('Y-m-d H:i:s') . " - Failed to send notification: Empty message\n", FILE_APPEND);
        http_response_code(400);
        echo json_encode(['error' => 'Message cannot be empty']);
        exit;
    }
}

// Fetch all notifications for the current user
try {
    $stmt = $pdo->prepare("SELECT message, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->execute([$user_id]);
    $all_notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // file_put_contents($log_file, date('Y-m-d H:i:s') . " - Fetching all notifications for user $user_id, found " . count($all_notifications) . "\n", FILE_APPEND);

    // Return notification data as JSON for AJAX
    header('Content-Type: application/json');
    echo json_encode(['notifications' => $all_notifications]);
} catch (Exception $e) {
    // file_put_contents($log_file, date('Y-m-d H:i:s') . " - Error fetching notifications for user $user_id: " . $e->getMessage() . "\n", FILE_APPEND);
    http_response_code(500);
    echo json_encode(['error' => 'Database error while fetching notifications']);
    exit;
}
?>