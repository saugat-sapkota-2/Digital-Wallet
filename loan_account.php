<?php
session_start();
require 'db_connect.php';

if (!isset($_SESSION['user_id']) || !$_SESSION['is_admin']) {
    header('Location: index.php');
    exit;
}

$admin_id = $_SESSION['user_id'];

// Handle Approve Loan Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_loan'])) {
    $loan_request_id = $_POST['loan_request_id'];

    try {
        // Fetch loan request details
        $stmt = $pdo->prepare("SELECT * FROM loan_requests WHERE id = ? AND status = 'pending'");
        $stmt->execute([$loan_request_id]);
        $loan_request = $stmt->fetch();

        if ($loan_request) {
            $loan_user_id = $loan_request['user_id'];
            $loan_amount = $loan_request['loan_amount'];

            $in_transaction = false;
            try {
                $pdo->beginTransaction();
                $in_transaction = true;

                // Update loan request status to 'approved'
                $stmt = $pdo->prepare("UPDATE loan_requests SET status = 'approved', updated_at = NOW() WHERE id = ?");
                $stmt->execute([$loan_request_id]);

                // Update user's balance
                $stmt = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
                $stmt->execute([$loan_amount, $loan_user_id]);

                // Record transaction
                $stmt = $pdo->prepare("INSERT INTO transactions (user_id, admin_id, amount, type, description) VALUES (?, ?, ?, 'loan_credit', 'Loan credited by admin')");
                $stmt->execute([$loan_user_id, $admin_id, $loan_amount]);

                // Record admin action
                $stmt = $pdo->prepare("INSERT INTO admin_actions (admin_id, user_id, action, amount, description) VALUES (?, ?, 'loan_approve', ?, 'Admin approved loan')");
                $stmt->execute([$admin_id, $loan_user_id, $loan_amount]);

                // Notify user of approval
                $stmt = $pdo->prepare("INSERT INTO notifications (user_id, sender_id, message, created_at) VALUES (?, ?, ?, NOW())");
                $stmt->execute([$loan_user_id, $admin_id, "Your loan request of रु" . number_format($loan_amount, 2) . " has been approved by admin (Request ID: $loan_request_id)."]);

                $pdo->commit();
                $_SESSION['success_message'] = "Loan request #$loan_request_id has been approved.";
                header('Location: loan_account.php');
                exit;
            } catch (Exception $e) {
                if ($in_transaction) {
                    $pdo->rollBack();
                }
                $_SESSION['error_message'] = "Error approving loan: " . $e->getMessage();
                header('Location: loan_account.php');
                exit;
            }
        } else {
            $_SESSION['error_message'] = "Loan request not found or already processed.";
            header('Location: loan_account.php');
            exit;
        }
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error: " . $e->getMessage();
        header('Location: loan_account.php');
        exit;
    }
}

// Handle Reject Loan Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reject_loan'])) {
    $loan_request_id = $_POST['loan_request_id'];

    try {
        // Fetch loan request details
        $stmt = $pdo->prepare("SELECT * FROM loan_requests WHERE id = ? AND status = 'pending'");
        $stmt->execute([$loan_request_id]);
        $loan_request = $stmt->fetch();

        if ($loan_request) {
            $loan_user_id = $loan_request['user_id'];
            $loan_amount = $loan_request['loan_amount'];

            $in_transaction = false;
            try {
                $pdo->beginTransaction();
                $in_transaction = true;

                // Update loan request status to 'rejected'
                $stmt = $pdo->prepare("UPDATE loan_requests SET status = 'rejected', updated_at = NOW() WHERE id = ?");
                $stmt->execute([$loan_request_id]);

                // Record admin action
                $stmt = $pdo->prepare("INSERT INTO admin_actions (admin_id, user_id, action, amount, description) VALUES (?, ?, 'loan_reject', ?, 'Admin rejected loan')");
                $stmt->execute([$admin_id, $loan_user_id, $loan_amount]);

                // Notify user of rejection
                $stmt = $pdo->prepare("INSERT INTO notifications (user_id, sender_id, message, created_at) VALUES (?, ?, ?, NOW())");
                $stmt->execute([$loan_user_id, $admin_id, "Your loan request of रु" . number_format($loan_amount, 2) . " has been rejected by admin (Request ID: $loan_request_id)."]);

                $pdo->commit();
                $_SESSION['success_message'] = "Loan request #$loan_request_id has been rejected.";
                header('Location: loan_account.php');
                exit;
            } catch (Exception $e) {
                if ($in_transaction) {
                    $pdo->rollBack();
                }
                $_SESSION['error_message'] = "Error rejecting loan: " . $e->getMessage();
                header('Location: loan_account.php');
                exit;
            }
        } else {
            $_SESSION['error_message'] = "Loan request not found or already processed.";
            header('Location: loan_account.php');
            exit;
        }
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error: " . $e->getMessage();
        header('Location: loan_account.php');
        exit;
    }
}

// Fetch Pending Loan Requests
$pending_loan_requests = $pdo->query("SELECT lr.*, u.username 
                                      FROM loan_requests lr 
                                      JOIN users u ON lr.user_id = u.id 
                                      WHERE lr.status = 'pending' 
                                      ORDER BY lr.created_at DESC")->fetchAll();

// Fetch Pending Loans
$pending_loans = $pdo->query("SELECT lr.*, u.username, 
                                     DATEDIFF(DATE_ADD(lr.created_at, INTERVAL lr.duration_days DAY), NOW()) AS remaining_days
                              FROM loan_requests lr
                              JOIN users u ON lr.user_id = u.id
                              WHERE lr.status = 'approved'
                              ORDER BY lr.created_at DESC")->fetchAll();

// Fetch All Loan Requests
$all_loan_requests = $pdo->query("SELECT lr.*, u.username 
                                  FROM loan_requests lr 
                                  JOIN users u ON lr.user_id = u.id 
                                  ORDER BY lr.created_at DESC")->fetchAll();

// Fetch Successfully Returned Loans
$returned_loans = $pdo->query("SELECT lr.*, u.username 
                               FROM loan_requests lr
                               JOIN users u ON lr.user_id = u.id
                               WHERE lr.status = 'paid'
                               ORDER BY lr.updated_at DESC")->fetchAll();

// Fetch All Transactions for Admin
$all_transactions = $pdo->query("SELECT t.*, u1.username AS sender, u2.username AS recipient, u3.username AS admin_username 
                                FROM transactions t 
                                LEFT JOIN users u1 ON t.user_id = u1.id 
                                LEFT JOIN users u2 ON t.recipient_id = u2.id 
                                LEFT JOIN users u3 ON t.admin_id = u3.id 
                                ORDER BY t.created_at DESC")->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loan Account - Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">
    <script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
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
    <?php if (isset($_SESSION['success_message'])): ?>
        <div id="successToast" class="hidden bg-green-500 text-white px-4 py-2 rounded fixed top-4 right-4 shadow-lg">
            <?php echo htmlspecialchars($_SESSION['success_message']); ?>
        </div>
    <?php endif; ?>
    <?php if (isset($_SESSION['error_message'])): ?>
        <div id="errorToast" class="hidden bg-red-500 text-white px-4 py-2 rounded fixed top-4 right-4 shadow-lg">
            <?php echo htmlspecialchars($_SESSION['error_message']); ?>
        </div>
        <?php unset($_SESSION['error_message']); ?>
    <?php endif; ?>

    <div class="container mx-auto p-6">
        <!-- Header -->
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-3xl font-bold text-gray-800 dark:text-gray-200">Loan Account</h2>
            <div class="flex items-center space-x-4">
                <!-- Dark Mode Toggle -->
                <button id="themeToggle" class="p-2 bg-gray-200 dark:bg-gray-700 rounded-full">
                    <svg class="w-6 h-6 text-gray-800 dark:text-gray-200" fill="currentColor" viewBox="0 0 20 20">
                        <path d="M17.293 13.293A8 8 0 016.707 2.707a8.001 8.001 0 1010.586 10.586z" />
                    </svg>
                </button>
                <!-- Back to Dashboard Button -->
                <a href="dashboard.php" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 hover-scale">Back to Dashboard</a>
            </div>
        </div>

        <!-- Pending Loan Requests -->
        <div class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md mb-6">
            <h4 class="text-xl font-semibold text-gray-800 dark:text-gray-200 mb-4">Pending Loan Requests</h4>
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="bg-gray-200 dark:bg-gray-700">
                            <th class="p-3">Request ID</th>
                            <th class="p-3">User</th>
                            <th class="p-3">Loan Amount</th>
                            <th class="p-3">Duration (Days)</th>
                            <th class="p-3">Date</th>
                            <th class="p-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pending_loan_requests as $request): ?>
                            <tr class="border-b dark:border-gray-700">
                                <td class="p-3"><?php echo $request['id']; ?></td>
                                <td class="p-3"><?php echo htmlspecialchars($request['username']); ?></td>
                                <td class="p-3">रु <?php echo number_format($request['loan_amount'], 2); ?></td>
                                <td class="p-3"><?php echo $request['duration_days']; ?></td>
                                <td class="p-3"><?php echo $request['created_at']; ?></td>
                                <td class="p-3">
                                    <form method="POST" class="flex gap-2">
                                        <input type="hidden" name="loan_request_id" value="<?php echo $request['id']; ?>">
                                        <button type="submit" name="approve_loan" class="px-3 py-1 bg-green-600 text-white rounded hover:bg-green-700 hover-scale">Approve</button>
                                        <button type="submit" name="reject_loan" class="px-3 py-1 bg-red-600 text-white rounded hover:bg-red-700 hover-scale">Reject</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Pending Loans -->
        <div class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md mb-6">
            <h4 class="text-xl font-semibold text-gray-800 dark:text-gray-200 mb-4">Pending Loans</h4>
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="bg-gray-200 dark:bg-gray-700">
                            <th class="p-3">User</th>
                            <th class="p-3">Loan Amount</th>
                            <th class="p-3">Duration (Days)</th>
                            <th class="p-3">Remaining Days</th>
                            <th class="p-3">Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pending_loans as $loan): ?>
                            <tr class="border-b dark:border-gray-700">
                                <td class="p-3"><?php echo htmlspecialchars($loan['username']); ?></td>
                                <td class="p-3">रु <?php echo number_format($loan['loan_amount'], 2); ?></td>
                                <td class="p-3"><?php echo $loan['duration_days']; ?></td>
                                <td class="p-3">
                                    <?php if ($loan['remaining_days'] > 0): ?>
                                        <?php echo $loan['remaining_days']; ?> days remaining
                                    <?php else: ?>
                                        Overdue by <?php echo abs($loan['remaining_days']); ?> days
                                    <?php endif; ?>
                                </td>
                                <td class="p-3"><?php echo $loan['created_at']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- All Loan Requests -->
        <div class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md mb-6">
            <h4 class="text-xl font-semibold text-gray-800 dark:text-gray-200 mb-4">All Loan Requests</h4>
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="bg-gray-200 dark:bg-gray-700">
                            <th class="p-3">Request ID</th>
                            <th class="p-3">User</th>
                            <th class="p-3">Loan Amount</th>
                            <th class="p-3">Duration (Days)</th>
                            <th class="p-3">Status</th>
                            <th class="p-3">Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($all_loan_requests as $request): ?>
                            <tr class="border-b dark:border-gray-700">
                                <td class="p-3"><?php echo $request['id']; ?></td>
                                <td class="p-3"><?php echo htmlspecialchars($request['username']); ?></td>
                                <td class="p-3">रु <?php echo number_format($request['loan_amount'], 2); ?></td>
                                <td class="p-3"><?php echo $request['duration_days']; ?></td>
                                <td class="p-3"><?php echo htmlspecialchars($request['status']); ?></td>
                                <td class="p-3"><?php echo $request['created_at']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Loans Successfully Returned -->
        <div class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md mb-6">
            <h4 class="text-xl font-semibold text-gray-800 dark:text-gray-200 mb-4">Loans Successfully Returned</h4>
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="bg-gray-200 dark:bg-gray-700">
                            <th class="p-3">User</th>
                            <th class="p-3">Loan Amount</th>
                            <th class="p-3">Duration (Days)</th>
                            <th class="p-3">Date Approved</th>
                            <th class="p-3">Date Paid</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($returned_loans)): ?>
                            <?php foreach ($returned_loans as $loan): ?>
                                <tr class="border-b dark:border-gray-700">
                                    <td class="p-3"><?php echo htmlspecialchars($loan['username']); ?></td>
                                    <td class="p-3">रु <?php echo number_format($loan['loan_amount'], 2); ?></td>
                                    <td class="p-3"><?php echo $loan['duration_days']; ?></td>
                                    <td class="p-3"><?php echo $loan['created_at']; ?></td>
                                    <td class="p-3"><?php echo $loan['updated_at']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="p-3 text-center text-gray-500 dark:text-gray-400">No loans have been successfully returned yet.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Transaction History -->
        <div class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md mb-6">
            <h4 class="text-xl font-semibold text-gray-800 dark:text-gray-200 mb-4">Transaction History</h4>
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
                                <td class="p-3"><?php echo htmlspecialchars($t['sender'] ?: 'N/A'); ?></td>
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
    </div>

    <script>
        // Dark Mode Toggle
        const themeToggle = document.getElementById('themeToggle');
        themeToggle.addEventListener('click', () => {
            document.documentElement.classList.toggle('dark');
            localStorage.setItem('theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light');
        });

        // Apply saved theme
        if (localStorage.getItem('theme') === 'dark') {
            document.documentElement.classList.add('dark');
        }

        // Show success toast if it exists
        const successToast = document.getElementById('successToast');
        if (successToast) {
            successToast.classList.remove('hidden');
            successToast.classList.add('animate-fade-in');

            // Hide the toast after 3 seconds
            setTimeout(() => {
                successToast.classList.add('animate-fade-out');
                setTimeout(() => successToast.remove(), 1000); // Remove the element after fade-out
            }, 3000);
        }

        // Show error toast if it exists
        const errorToast = document.getElementById('errorToast');
        if (errorToast) {
            errorToast.classList.remove('hidden');
            errorToast.classList.add('animate-fade-in');

            // Hide the toast after 3 seconds
            setTimeout(() => {
                errorToast.classList.add('animate-fade-out');
                setTimeout(() => errorToast.remove(), 1000); // Remove the element after fade-out
            }, 3000);
        }

        // Add fade-in and fade-out animations
        const style = document.createElement('style');
        style.innerHTML = `
            .animate-fade-in {
                animation: fadeIn 0.5s ease-in-out forwards;
            }
            .animate-fade-out {
                animation: fadeOut 0.5s ease-in-out forwards;
            }
            @keyframes fadeIn {
                from { opacity: 0; transform: translateY(-10px); }
                to { opacity: 1; transform: translateY(0); }
            }
            @keyframes fadeOut {
                from { opacity: 1; transform: translateY(0); }
                to { opacity: 0; transform: translateY(-10px); }
            }
        `;
        document.head.appendChild(style);
    </script>

    <script>
        <?php if (isset($_SESSION['success_message'])): ?>
            Toastify({
                text: "<?php echo addslashes($_SESSION['success_message']); ?>",
                duration: 3000,
                gravity: "top",
                position: "right",
                backgroundColor: "linear-gradient(to right, #38a169, #48bb78)",
            }).showToast();
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>
    </script>
</body>
</html>