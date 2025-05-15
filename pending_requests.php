<?php
session_start();
require 'db_connect.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$is_admin = $_SESSION['is_admin'];

// Fetch user details
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

$error = '';
$success = '';

// Fetch pending requests
$pending_requests = [];
if (!$is_admin) {
    $stmt = $pdo->prepare("SELECT mr.*, u.username AS sender_username FROM money_requests mr JOIN users u ON mr.sender_id = u.id WHERE mr.recipient_id = ? AND mr.status = 'pending'");
    $stmt->execute([$user_id]);
    $pending_requests = $stmt->fetchAll();
}

if (isset($_GET['accept']) && !$is_admin) {
    $request_id = filter_input(INPUT_GET, 'accept', FILTER_SANITIZE_NUMBER_INT);
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_mpin_accept'])) {
        $entered_mpin = filter_input(INPUT_POST, 'mpin', FILTER_SANITIZE_STRING);
        if ($entered_mpin === $user['mpin']) {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("SELECT sender_id, recipient_id, amount FROM money_requests WHERE id = ? AND recipient_id = ? AND status = 'pending'");
                $stmt->execute([$request_id, $user_id]);
                $request = $stmt->fetch();

                if ($request && $user['balance'] >= $request['amount']) {
                    $stmt = $pdo->prepare("UPDATE users SET balance = balance - ? WHERE id = ?");
                    $stmt->execute([$request['amount'], $user_id]);
                    $stmt = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
                    $stmt->execute([$request['amount'], $request['sender_id']]);
                    $stmt = $pdo->prepare("INSERT INTO transactions (user_id, amount, type, recipient_id, description) VALUES (?, ?, 'transfer_sent', ?, 'Accepted money request')");
                    $stmt->execute([$user_id, $request['amount'], $request['sender_id']]);
                    $stmt = $pdo->prepare("INSERT INTO transactions (user_id, amount, type, recipient_id, description) VALUES (?, ?, 'transfer_received', ?, 'Received from money request')");
                    $stmt->execute([$request['sender_id'], $request['amount'], $user_id]);
                    $stmt = $pdo->prepare("UPDATE money_requests SET status = 'accepted', updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$request_id]);
                    $pdo->commit();
                    $success = "Money request accepted and transferred successfully!";
                    $stmt = $pdo->prepare("SELECT mr.*, u.username AS sender_username FROM money_requests mr JOIN users u ON mr.sender_id = u.id WHERE mr.recipient_id = ? AND mr.status = 'pending'");
                    $stmt->execute([$user_id]);
                    $pending_requests = $stmt->fetchAll();
                } else {
                    $error = "Invalid request or insufficient balance.";
                }
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = "Error: " . $e->getMessage();
            }
        } else {
            $error = "Incorrect MPIN.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pending Requests - Digital Wallet</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body {
            transition: background-color 0.3s, color 0.3s;
        }
        .dark body {
            background-color: #1a202c;
            color: #e2e8f0;
        }
        .animate-slide-in {
            animation: slideIn 0.5s ease-out;
        }
        @keyframes slideIn {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        .requests-table {
            background-color: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        .dark .requests-table {
            background-color: #2d3748;
        }
        .requests-table table {
            width: 100%;
            border-collapse: collapse;
        }
        .requests-table th,
        .requests-table td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }
        .dark .requests-table th,
        .dark .requests-table td {
            border-bottom-color: #4a5568;
        }
        .requests-table th {
            background-color: #edf2f7;
            color: #2d3748;
        }
        .dark .requests-table th {
            background-color: #4a5568;
            color: #a0aec0;
        }
        .requests-table td {
            color: #2d3748;
        }
        .dark .requests-table td {
            color: #e2e8f0;
        }
        .mpin-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }
        .mpin-modal.active {
            display: flex;
        }
        .modal-content {
            background-color: white;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
        }
        .dark .modal-content {
            background-color: #2d3748;
        }
        .modal-content input {
            width: 100%;
            padding: 8px;
            margin-bottom: 10px;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
        }
        .dark .modal-content input {
            background-color: #4a5568;
            border-color: #718096;
            color: #e2e8f0;
        }
        .modal-content button {
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            background-color: #4299e1;
            color: white;
        }
        .modal-content button.cancel {
            background-color: #718096;
            margin-left: 10px;
        }
    </style>
</head>
<body class="bg-gray-100 dark:bg-gray-900 min-h-screen">
    <div class="container mx-auto p-6">
        <div class="flex justify-between items-center mb-6 animate-slide-in">
            <h2 class="text-3xl font-bold text-gray-800 dark:text-gray-200">Pending Money Requests</h2>
            <a href="dashboard.php" class="text-blue-600 dark:text-blue-400 hover:underline">Back to Dashboard</a>
        </div>

        <!-- Error Message -->
        <?php if ($error): ?>
            <div class="bg-red-100 text-red-700 p-4 rounded-lg mb-6 animate-slide-in">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <!-- Success Message -->
        <?php if ($success): ?>
            <div class="bg-green-100 text-green-700 p-4 rounded-lg mb-6 animate-slide-in">
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <div class="requests-table animate-slide-in">
            <?php if (empty($pending_requests)): ?>
                <p class="text-gray-800 dark:text-gray-200">No pending requests at the moment.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr class="bg-gray-200 dark:bg-gray-700">
                            <th class="p-3">ID</th>
                            <th class="p-3">Sender</th>
                            <th class="p-3">Amount</th>
                            <th class="p-3">Date</th>
                            <th class="p-3">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pending_requests as $request): ?>
                            <tr class="border-b dark:border-gray-700">
                                <td class="p-3"><?php echo $request['id']; ?></td>
                                <td class="p-3"><?php echo htmlspecialchars($request['sender_username']); ?></td>
                                <td class="p-3">रु <?php echo number_format($request['amount'], 2); ?></td>
                                <td class="p-3"><?php echo $request['created_at']; ?></td>
                                <td class="p-3"><a href="?accept=<?php echo $request['id']; ?>" class="text-blue-600 dark:text-blue-400 hover:underline">Accept</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- MPIN Modal for Accepting Requests -->
        <div id="mpinAcceptModal" class="mpin-modal <?php echo isset($_GET['accept']) ? 'active' : ''; ?>">
            <div class="modal-content">
                <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-200 mb-4">Enter Your MPIN to Accept</h2>
                <form method="POST">
                    <input type="hidden" name="request_id" value="<?php echo isset($_GET['accept']) ? htmlspecialchars($_GET['accept']) : ''; ?>">
                    <div class="mb-4">
                        <input type="text" name="mpin" maxlength="4" pattern="\d{4}" placeholder="Enter 4-digit MPIN" class="p-2 border rounded dark:bg-gray-700 dark:text-gray-200" required>
                    </div>
                    <button type="submit" name="verify_mpin_accept" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">Confirm</button>
                    <button type="button" class="cancel px-4 py-2 bg-gray-600 text-white rounded hover:bg-gray-700 ml-2" onclick="closeMpinAcceptModal()">Cancel</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Theme Toggle (inherited from dashboard)
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

        function closeMpinAcceptModal() {
            document.getElementById('mpinAcceptModal').classList.remove('active');
            window.location.href = 'pending_requests.php';
        }
    </script>
</body>
</html>