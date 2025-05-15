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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$is_admin) {
    if (isset($_POST['request_money'])) {
        if ($user['is_frozen']) {
            $error = "Your account is frozen. Contact admin.";
        } elseif (!$user['mpin']) {
            $error = "Please set your MPIN during registration.";
        } else {
            $_SESSION['pending_action'] = 'request_money';
            $_SESSION['request_data'] = $_POST;
            $_SESSION['require_mpin'] = true;
            header('Location: request_money.php');
            exit;
        }
    } elseif (isset($_POST['verify_mpin']) && $_SESSION['pending_action'] === 'request_money') {
        $entered_mpin = filter_input(INPUT_POST, 'mpin', FILTER_SANITIZE_STRING);
        if ($entered_mpin === $user['mpin']) {
            $data = $_SESSION['request_data'];
            $amount = floatval($data['amount']);
            $recipient_username = $data['recipient'];

            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
            $stmt->execute([$recipient_username, $user_id]);
            $recipient = $stmt->fetch();

            if ($recipient && $amount > 0) {
                $pdo->beginTransaction();
                try {
                    $stmt = $pdo->prepare("INSERT INTO money_requests (sender_id, recipient_id, amount, status, created_at) VALUES (?, ?, ?, 'pending', NOW())");
                    $stmt->execute([$user_id, $recipient['id'], $amount]);
                    $pdo->commit();
                    $success = "Money request sent successfully!";
                    unset($_SESSION['pending_action']);
                    unset($_SESSION['request_data']);
                    unset($_SESSION['require_mpin']);
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error = "Error: " . $e->getMessage();
                    $_SESSION['require_mpin'] = true;
                }
            } else {
                $error = "Invalid recipient or amount.";
                $_SESSION['require_mpin'] = true;
            }
        } else {
            $error = "Incorrect MPIN.";
            $_SESSION['require_mpin'] = true;
        }
    }
}

// Handle request acceptance
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
    $stmt = $pdo->prepare("SELECT mr.*, u.username AS sender_username FROM money_requests mr JOIN users u ON mr.sender_id = u.id WHERE mr.recipient_id = ? AND mr.status = 'pending'");
    $stmt->execute([$user_id]);
    $pending_requests = $stmt->fetchAll();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Money - Digital Wallet</title>
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
        .container {
            display: flex;
            gap: 20px;
        }
        .half-width {
            width: 50%;
        }
        .modal {
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
        .modal.active {
            display: flex;
        }
    </style>
</head>
<body class="min-h-screen">
    <div class="container mx-auto p-6">
        <h2 class="text-2xl font-bold mb-4">User Actions</h2>

        <?php if ($error): ?>
            <div class="bg-red-100 text-red-700 p-4 rounded mb-4"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="bg-green-100 text-green-700 p-4 rounded mb-4"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <div class="container flex gap-4">
            <!-- Transfer Money Container -->
            <div class="bg-white dark:bg-gray-800 p-6 rounded shadow-md half-width">
                <h3 class="text-xl font-semibold mb-4">Transfer Money</h3>
                <form method="POST" action="dashboard.php" class="space-y-4">
                    <div>
                        <label for="recipient" class="block text-gray-700 dark:text-gray-300">Recipient Username</label>
                        <input type="text" name="recipient" id="recipient" class="w-full p-2 border rounded dark:bg-gray-700 dark:text-gray-200" required>
                    </div>
                    <div>
                        <input type="number" name="amount" step="0.01" placeholder="Amount" class="w-full p-2 border rounded dark:bg-gray-700 dark:text-gray-200" required>
                    </div>
                    <button type="submit" name="transfer" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700" <?php echo $user['is_frozen'] ? 'disabled' : ''; ?>>Transfer</button>
                </form>
            </div>

            <!-- Request Money Container -->
            <div class="bg-white dark:bg-gray-800 p-6 rounded shadow-md half-width">
                <h3 class="text-xl font-semibold mb-4">Request Money</h3>
                <form method="POST" class="space-y-4">
                    <div>
                        <label for="recipient_request" class="block text-gray-700 dark:text-gray-300">Recipient Username</label>
                        <input type="text" name="recipient" id="recipient_request" class="w-full p-2 border rounded dark:bg-gray-700 dark:text-gray-200" required>
                    </div>
                    <div>
                        <input type="number" name="amount" step="0.01" placeholder="Amount" class="w-full p-2 border rounded dark:bg-gray-700 dark:text-gray-200" required>
                    </div>
                    <button type="submit" name="request_money" class="px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700" <?php echo $user['is_frozen'] ? 'disabled' : ''; ?>>Request Money</button>
                </form>
            </div>
        </div>

        <!-- MPIN Modal for Request Money -->
        <div id="mpinModal" class="modal <?php echo isset($_SESSION['require_mpin']) ? 'active' : ''; ?>">
            <div class="bg-white dark:bg-gray-800 p-6 rounded shadow-lg text-center">
                <h2 class="text-2xl font-bold mb-4">Enter Your MPIN</h2>
                <form method="POST">
                    <div class="mb-4">
                        <input type="text" name="mpin" maxlength="4" pattern="\d{4}" placeholder="Enter 4-digit MPIN" class="p-2 border rounded dark:bg-gray-700 dark:text-gray-200" required>
                    </div>
                    <button type="submit" name="verify_mpin" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">Confirm</button>
                    <button type="button" onclick="closeMpinModal()" class="px-4 py-2 bg-gray-600 text-white rounded hover:bg-gray-700 ml-2">Cancel</button>
                </form>
            </div>
        </div>

        <!-- Pending Requests Section -->
        <?php if ($pending_requests): ?>
            <div class="bg-white dark:bg-gray-800 p-6 rounded shadow-md mt-6">
                <h3 class="text-xl font-semibold mb-4">Pending Money Requests</h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
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
                                    <td class="p-3">
                                        <a href="?accept=<?php echo $request['id']; ?>" class="px-3 py-1 bg-green-600 text-white rounded hover:bg-green-700">Accept</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- MPIN Modal for Accepting Requests -->
            <div id="mpinAcceptModal" class="modal <?php echo isset($_GET['accept']) ? 'active' : ''; ?>">
                <div class="bg-white dark:bg-gray-800 p-6 rounded shadow-lg text-center">
                    <h2 class="text-2xl font-bold mb-4">Enter Your MPIN to Accept</h2>
                    <form method="POST">
                        <input type="hidden" name="request_id" value="<?php echo isset($_GET['accept']) ? htmlspecialchars($_GET['accept']) : ''; ?>">
                        <div class="mb-4">
                            <input type="text" name="mpin" maxlength="4" pattern="\d{4}" placeholder="Enter 4-digit MPIN" class="p-2 border rounded dark:bg-gray-700 dark:text-gray-200" required>
                        </div>
                        <button type="submit" name="verify_mpin_accept" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">Confirm</button>
                        <button type="button" onclick="closeMpinAcceptModal()" class="px-4 py-2 bg-gray-600 text-white rounded hover:bg-gray-700 ml-2">Cancel</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <a href="dashboard.php" class="mt-4 inline-block px-4 py-2 bg-gray-600 text-white rounded hover:bg-gray-700">Back to Dashboard</a>
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

        function closeMpinModal() {
            document.getElementById('mpinModal').classList.remove('active');
            window.location.href = 'request_money.php';
        }

        function closeMpinAcceptModal() {
            document.getElementById('mpinAcceptModal').classList.remove('active');
            window.location.href = 'request_money.php';
        }
    </script>
</body>
</html>