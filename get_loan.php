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

// Handle loan request submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_loan'])) {
    $name = filter_input(INPUT_POST, 'name', FILTER_SANITIZE_STRING);
    $address = filter_input(INPUT_POST, 'address', FILTER_SANITIZE_STRING);
    $permanent_address = filter_input(INPUT_POST, 'permanent_address', FILTER_SANITIZE_STRING);
    $id_type = filter_input(INPUT_POST, 'id_type', FILTER_SANITIZE_STRING);
    $id_number = filter_input(INPUT_POST, 'id_number', FILTER_SANITIZE_STRING);
    $loan_amount = filter_input(INPUT_POST, 'loan_amount', FILTER_VALIDATE_FLOAT);
    $duration_days = filter_input(INPUT_POST, 'duration_days', FILTER_VALIDATE_INT);

    // Validate inputs
    if (!$name || !$address || !$permanent_address || !$id_type || !$id_number || !$loan_amount || !$duration_days) {
        $_SESSION['error'] = "All fields are required.";
        header('Location: get_loan.php');
        exit;
    }

    if (!in_array($id_type, ['citizenship', 'nic', 'driving_license'])) {
        $_SESSION['error'] = "Invalid ID type.";
        header('Location: get_loan.php');
        exit;
    }

    if ($loan_amount <= 0) {
        $_SESSION['error'] = "Loan amount must be greater than zero.";
        header('Location: get_loan.php');
        exit;
    }

    if ($duration_days <= 0) {
        $_SESSION['error'] = "Duration must be greater than zero.";
        header('Location: get_loan.php');
        exit;
    }

    // Insert loan request
    try {
        $stmt = $pdo->prepare("INSERT INTO loan_requests (user_id, name, address, permanent_address, id_type, id_number, loan_amount, duration_days, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
        $stmt->execute([$user_id, $name, $address, $permanent_address, $id_type, $id_number, $loan_amount, $duration_days]);
        $_SESSION['request_success'] = true;
        header('Location: dashboard.php');
        exit;
    } catch (Exception $e) {
        $_SESSION['error'] = "Error submitting loan request: " . $e->getMessage();
        header('Location: get_loan.php');
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
    <title>Get Loan - Digital Wallet</title>
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
        .action-card input,
        .action-card select,
        .action-card textarea {
            width: 100%;
            padding: 8px;
            margin-bottom: 10px;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
        }
        .dark .action-card input,
        .dark .action-card select,
        .dark .action-card textarea {
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
            <h2 class="text-3xl font-bold text-gray-800 dark:text-gray-200">Get a Loan</h2>
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

        <!-- Loan Request Form -->
        <div class="action-card animate-slide-in">
            <h3 class="text-xl font-semibold text-gray-800 dark:text-gray-200 mb-4">Submit Loan Request</h3>
            <form method="POST" class="space-y-4">
                <div>
                    <label for="name" class="block text-gray-700 dark:text-gray-300">Full Name</label>
                    <input type="text" name="name" id="name" placeholder="Your Full Name" class="w-full p-2 border rounded dark:bg-gray-700 dark:text-gray-200" required>
                </div>
                <div>
                    <label for="address" class="block text-gray-700 dark:text-gray-300">Current Address</label>
                    <textarea name="address" id="address" placeholder="Your Current Address" class="w-full p-2 border rounded dark:bg-gray-700 dark:text-gray-200" required></textarea>
                </div>
                <div>
                    <label for="permanent_address" class="block text-gray-700 dark:text-gray-300">Permanent Address</label>
                    <textarea name="permanent_address" id="permanent_address" placeholder="Your Permanent Address" class="w-full p-2 border rounded dark:bg-gray-700 dark:text-gray-200" required></textarea>
                </div>
                <div>
                    <label for="id_type" class="block text-gray-700 dark:text-gray-300">ID Type</label>
                    <select name="id_type" id="id_type" class="w-full p-2 border rounded dark:bg-gray-700 dark:text-gray-200" required>
                        <option value="">Select ID Type</option>
                        <option value="citizenship">Citizenship</option>
                        <option value="nic">National ID Card</option>
                        <option value="driving_license">Driving License</option>
                    </select>
                </div>
                <div>
                    <label for="id_number" class="block text-gray-700 dark:text-gray-300">ID Number</label>
                    <input type="text" name="id_number" id="id_number" placeholder="ID Number" class="w-full p-2 border rounded dark:bg-gray-700 dark:text-gray-200" required>
                </div>
                <div>
                    <label for="loan_amount" class="block text-gray-700 dark:text-gray-300">Loan Amount (₹)</label>
                    <input type="number" name="loan_amount" id="loan_amount" step="0.01" placeholder="Loan Amount" class="w-full p-2 border rounded dark:bg-gray-700 dark:text-gray-200" required>
                </div>
                <div>
                    <label for="duration_days" class="block text-gray-700 dark:text-gray-300">Duration (Days)</label>
                    <input type="number" name="duration_days" id="duration_days" placeholder="Duration in Days" class="w-full p-2 border rounded dark:bg-gray-700 dark:text-gray-200" required>
                </div>
                <button type="submit" name="submit_loan" class="px-4 py-2 bg-purple-600 text-white rounded hover:bg-purple-700 hover-scale">Submit Loan Request</button>
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