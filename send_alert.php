<?php
session_start();
require 'db_connect.php';

if (!isset($_SESSION['user_id']) || !$_SESSION['is_admin']) {
    header('Location: dashboard.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$error = '';
$success = '';

// Fetch admin details
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$admin_details = $stmt->fetch(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message_to_all'])) {
    $message = filter_input(INPUT_POST, 'message', FILTER_SANITIZE_STRING);
    if ($message) {
        $pdo->beginTransaction();
        try {
            // Fetch all non-admin users
            $stmt = $pdo->prepare("SELECT id FROM users WHERE is_admin = 0");
            $stmt->execute();
            $users = $stmt->fetchAll(PDO::FETCH_COLUMN);

            // Prepare the notification insertion query
            $stmt = $pdo->prepare("INSERT INTO notifications (sender_id, user_id, message, created_at) VALUES (?, ?, ?, NOW())");
            foreach ($users as $user_id) {
                $stmt->execute([$_SESSION['user_id'], $user_id, $message]); // Use the logged-in admin's ID as sender_id
            }

            $pdo->commit();
            $success = "Message sent to all users successfully!";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error: " . $e->getMessage();
        }
    } else {
        $error = "Message cannot be empty.";
    }
}

// Show the structure of the notifications table
$stmt = $pdo->query("SHOW CREATE TABLE notifications");
$createTable = $stmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Send Message to All - Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body {
            background-color: #f7fafc;
            transition: background-color 0.3s;
        }
        .dark body {
            background-color: #1a202c;
            color: #e2e8f0;
        }
    </style>
</head>
<body class="min-h-screen">
    <div class="container mx-auto p-6">
        <h2 class="text-2xl font-bold mb-4">Send Message to All Users</h2>

        <?php if ($error): ?>
            <div class="bg-red-100 text-red-700 p-4 rounded mb-4"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="bg-green-100 text-green-700 p-4 rounded mb-4"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <form method="POST" class="bg-white dark:bg-gray-800 p-6 rounded shadow-md">
            <div class="mb-4">
                <label for="message" class="block text-gray-700 dark:text-gray-300">Message</label>
                <textarea name="message" id="message" class="w-full p-2 border rounded dark:bg-gray-700 dark:text-gray-200" required></textarea>
            </div>
            <button type="submit" name="send_message_to_all" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">Send to All</button>
            <a href="dashboard.php" class="ml-4 px-4 py-2 bg-gray-600 text-white rounded hover:bg-gray-700">Back to Dashboard</a>
        </form>

        <!-- Replace the existing "Send Alert to All" button -->
        <div class="mt-4">
            <a href="send_alert.php" class="px-3 py-1 bg-purple-600 text-white rounded hover:bg-purple-700 hover-scale">Send Alert to All</a>
        </div>

        <!-- Display the structure of the notifications table -->
        <div class="mt-6 bg-gray-100 dark:bg-gray-700 p-4 rounded shadow-md">
            <h3 class="text-lg font-semibold mb-2">Notifications Table Structure</h3>
            <pre class="text-sm bg-gray-200 dark:bg-gray-800 p-2 rounded"><?php echo htmlspecialchars($createTable['Create Table']); ?></pre>
        </div>
    </div>

    <script>
        const themeToggle = document.getElementById('themeToggle');
        if (themeToggle) {
            themeToggle.addEventListener('click', () => {
                document.documentElement.classList.toggle('dark');
                localStorage.setItem('theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light');
            });
        }

        if (localStorage.getItem('theme') === 'dark') {
            document.documentElement.classList.add('dark');
        }
    </script>
</body>
</html>