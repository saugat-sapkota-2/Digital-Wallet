<?php
session_start();
require 'db_connect.php';

if (!isset($_SESSION['user_id']) || !$_SESSION['is_admin']) {
    header('Location: index.php');
    exit;
}

// Fetch all transactions
$stmt = $pdo->query("SELECT t.*, u1.username AS sender, u2.username AS recipient, u3.username AS admin_username 
                     FROM transactions t 
                     LEFT JOIN users u1 ON t.user_id = u1.id 
                     LEFT JOIN users u2 ON t.recipient_id = u2.id 
                     LEFT JOIN users u3 ON t.admin_id = u3.id 
                     ORDER BY t.created_at DESC");
$all_transactions = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Transactions</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body {
            transition: background-color 0.3s, color 0.3s;
        }
        .dark body {
            background-color: #1a202c;
            color: #e2e8f0;
        }
        .hover-scale {
            transition: transform 0.2s;
        }
        .hover-scale:hover {
            transform: scale(1.05);
        }
    </style>
</head>
<body class="bg-gray-100 dark:bg-gray-900 min-h-screen">
    <div class="container mx-auto p-6">
        <!-- Dark Mode Toggle -->
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-3xl font-bold text-gray-800 dark:text-gray-200">All Transactions</h2>
            <button id="themeToggle" class="p-2 bg-gray-200 dark:bg-gray-700 rounded-full">
                <svg class="w-6 h-6 text-gray-800 dark:text-gray-200" fill="currentColor" viewBox="0 0 20 20">
                    <path d="M17.293 13.293A8 8 0 016.707 2.707a8.001 8.001 0 1010.586 10.586z" />
                </svg>
            </button>
        </div>

        <!-- Transactions Table -->
        <div class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md">
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="bg-gray-200 dark:bg-gray-700">
                            <th class="p-3">ID</th>
                            <th class="p-3">Sender</th>
                            <th class="p-3">Recipient</th>
                            <th class="p-3">Admin</th>
                            <th class="p-3">Amount</th>
                            <th class="p-3">Type</th>
                            <th class="p-3">Description</th>
                            <th class="p-3">Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($all_transactions as $t): ?>
                            <tr class="border-b dark:border-gray-700">
                                <td class="p-3"><?php echo $t['id']; ?></td>
                                <td class="p-3"><?php echo htmlspecialchars($t['sender']); ?></td>
                                <td class="p-3"><?php echo htmlspecialchars($t['recipient'] ?: 'N/A'); ?></td>
                                <td class="p-3"><?php echo htmlspecialchars($t['admin_username'] ?: 'N/A'); ?></td>
                                <td class="p-3">रु <?php echo number_format($t['amount'], 2); ?></td>
                                <td class="p-3"><?php echo $t['type']; ?></td>
                                <td class="p-3"><?php echo htmlspecialchars($t['description']); ?></td>
                                <td class="p-3"><?php echo $t['created_at']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Back to Dashboard Button -->
        <div class="mt-6 text-center">
            <a href="dashboard.php" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">Back to Dashboard</a>
        </div>
    </div>

    <!-- Dark Mode Toggle Script -->
    <script>
        const themeToggle = document.getElementById('themeToggle');
        themeToggle.addEventListener('click', () => {
            document.documentElement.classList.toggle('dark');
            localStorage.setItem('theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light');
        });

        // Apply saved theme
        if (localStorage.getItem('theme') === 'dark') {
            document.documentElement.classList.add('dark');
        }
    </script>
</body>
</html>