<?php
session_start();
require 'db_connect.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$user_id = $_SESSION['user_id'];

// Fetch loan requests for the logged-in user
$stmt = $pdo->prepare("SELECT * FROM loan_requests WHERE user_id = ? ORDER BY created_at DESC");
$stmt->execute([$user_id]);
$loan_requests = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loan Request History</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 dark:bg-gray-900 min-h-screen">
    <div class="container mx-auto p-6">
        <h2 class="text-3xl font-bold text-gray-800 dark:text-gray-200 mb-6">Loan Request History</h2>
        <div class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md">
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="bg-gray-200 dark:bg-gray-700">
                            <th class="p-3">Request ID</th>
                            <th class="p-3">Loan Amount</th>
                            <th class="p-3">Duration (Days)</th>
                            <th class="p-3">Status</th>
                            <th class="p-3">Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($loan_requests as $request): ?>
                            <tr class="border-b dark:border-gray-700">
                                <td class="p-3"><?php echo $request['id']; ?></td>
                                <td class="p-3">₹<?php echo number_format($request['loan_amount'], 2); ?></td>
                                <td class="p-3"><?php echo $request['duration_days']; ?></td>
                                <td class="p-3"><?php echo htmlspecialchars($request['status']); ?></td>
                                <td class="p-3"><?php echo $request['created_at']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>