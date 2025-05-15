<?php
session_start();
require 'db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['is_admin']) {
    header('Location: index.php');
    exit;
}

$user_id = $_SESSION['user_id'];

// Fetch user details
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    $_SESSION['error'] = "User not found.";
    header('Location: dashboard.php');
    exit;
}

// Check if account is frozen
if ($user['is_frozen']) {
    $_SESSION['error'] = "Your account is frozen. Contact admin.";
    header('Location: dashboard.php');
    exit;
}

// Fetch approved loans
$stmt = $pdo->prepare("SELECT * FROM loan_requests WHERE user_id = ? AND status = 'approved'");
$stmt->execute([$user_id]);
$approved_loans = $stmt->fetchAll();

if (empty($approved_loans)) {
    $_SESSION['error'] = "You have no approved loans to pay.";
    header('Location: dashboard.php');
    exit;
}

// Handle loan payment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pay_loan'])) {
    $loan_id = filter_input(INPUT_POST, 'loan_id', FILTER_VALIDATE_INT);
    if (!$loan_id) {
        $_SESSION['error'] = "Invalid loan selected.";
        header('Location: pay_loan.php');
        exit;
    }

    // Fetch loan details
    $stmt = $pdo->prepare("SELECT loan_amount FROM loan_requests WHERE id = ? AND user_id = ? AND status = 'approved'");
    $stmt->execute([$loan_id, $user_id]);
    $loan = $stmt->fetch();

    if ($loan) {
        $loan_amount = $loan['loan_amount'];
        if ($user['balance'] >= $loan_amount) {
            $in_transaction = false;
            try {
                $pdo->beginTransaction();
                $in_transaction = true;

                // Deduct balance
                $stmt = $pdo->prepare("UPDATE users SET balance = balance - ? WHERE id = ?");
                $stmt->execute([$loan_amount, $user_id]);

                // Update loan status
                $stmt = $pdo->prepare("UPDATE loan_requests SET status = 'paid', paid_at = NOW(), updated_at = NOW() WHERE id = ?");
                $stmt->execute([$loan_id]);

                // Record transaction
                $stmt = $pdo->prepare("INSERT INTO transactions (user_id, amount, type, description) VALUES (?, ?, 'loan_payment', ?)");
                $description = "Loan payment for Loan ID: " . $loan_id;
                $stmt->execute([$user_id, $loan_amount, $description]);

                $pdo->commit();
                $_SESSION['payment_success'] = true;
                header('Location: dashboard.php');
                exit;
            } catch (Exception $e) {
                if ($in_transaction) {
                    $pdo->rollBack();
                }
                $_SESSION['error'] = "Error processing payment: " . $e->getMessage();
                header('Location: pay_loan.php');
                exit;
            }
        } else {
            $_SESSION['error'] = "Insufficient balance to pay the loan.";
            header('Location: pay_loan.php');
            exit;
        }
    } else {
        $_SESSION['error'] = "Invalid loan request or loan already paid.";
        header('Location: pay_loan.php');
        exit;
    }
}

$error_message = isset($_SESSION['error']) ? $_SESSION['error'] : null;
unset($_SESSION['error']);
?>

<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pay Loan - Digital Wallet</title>
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
        .hover-scale {
            transition: transform 0.2s;
        }
        .hover-scale:hover {
            transform: scale(1.05);
        }
        .action-card {
            background-color: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        .dark .action-card {
            background-color: #2d3748;
        }
        .action-card label {
            display: block;
            margin-bottom: 5px;
            color: #4a5568;
        }
        .dark .action-card label {
            color: #a0aec0;
        }
        .action-card select,
        .action-card input {
            width: 100%;
            padding: 8px;
            margin-bottom: 10px;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
        }
        .dark .action-card select,
        .dark .action-card input {
            background-color: #4a5568;
            border-color: #718096;
            color: #e2e8f0;
        }
    </style>
</head>
<body class="bg-gray-100 dark:bg-gray-900 min-h-screen">
    <div class="container mx-auto p-6">
        <!-- Header -->
        <div class="flex justify-between items-center mb-6 animate-slide-in">
            <h2 class="text-3xl font-bold text-gray-800 dark:text-gray-200">Pay Loan</h2>
            <div class="flex items-center space-x-4">
                <button id="themeToggle" class="p-2 bg-gray-200 dark:bg-gray-700 rounded-full">
                    <svg class="w-6 h-6 text-gray-800 dark:text-gray-200" fill="currentColor" viewBox="0 0 20 20">
                        <path d="M17.293 13.293A8 8 0 016.707 2.707a8.001 8.001 0 1010.586 10.586z" />
                    </svg>
                </button>
                <a href="dashboard.php" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 hover-scale">Back to Dashboard</a>
            </div>
        </div>

        <!-- Error Message -->
        <?php if ($error_message): ?>
            <div class="bg-red-100 text-red-700 p-4 rounded-lg mb-6 animate-slide-in">
                <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <!-- Approved Loans -->
        <div class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md mb-6 animate-slide-in">
            <h3 class="text-xl font-semibold text-gray-800 dark:text-gray-200 mb-4">Your Approved Loans</h3>
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="bg-gray-200 dark:bg-gray-700">
                            <th class="p-3">Loan ID</th>
                            <th class="p-3">Name</th>
                            <th class="p-3">Loan Amount</th>
                            <th class="p-3">Duration (Days)</th>
                            <th class="p-3">Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($approved_loans as $loan): ?>
                            <tr class="border-b dark:border-gray-700">
                                <td class="p-3"><?php echo $loan['id']; ?></td>
                                <td class="p-3"><?php echo htmlspecialchars($loan['name']); ?></td>
                                <td class="p-3">₹<?php echo number_format($loan['loan_amount'], 2); ?></td>
                                <td class="p-3"><?php echo $loan['duration_days']; ?></td>
                                <td class="p-3"><?php echo $loan['created_at']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Pay Loan Form -->
        <div class="action-card animate-slide-in">
            <h3 class="text-xl font-semibold text-gray-800 dark:text-gray-200 mb-4">Pay a Loan</h3>
            <form method="POST" class="space-y-4">
                <div>
                    <label for="loan_id" class="block text-gray-700 dark:text-gray-300">Select Loan</label>
                    <select name="loan_id" id="loan_id" class="w-full p-2 border rounded dark:bg-gray-700 dark:text-gray-200" required>
                        <option value="">-- Select a Loan --</option>
                        <?php foreach ($approved_loans as $loan): ?>
                            <option value="<?php echo $loan['id']; ?>">
                                Loan ID: <?php echo $loan['id']; ?> - ₹<?php echo number_format($loan['loan_amount'], 2); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" name="pay_loan" class="px-4 py-2 bg-red-600 text-white rounded hover:bg-red-700 hover-scale">Pay Loan</button>
            </form>
        </div>
    </div>

    <script>
        // Theme Toggle
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