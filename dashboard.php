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

// Check for login success (set in index.php after successful login)
$login_success = isset($_SESSION['login_success']) ? $_SESSION['login_success'] : false;
unset($_SESSION['login_success']);

// Create loan_requests table if it doesn't exist (with duration field)
$pdo->exec("CREATE TABLE IF NOT EXISTS loan_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    address TEXT NOT NULL,
    permanent_address TEXT NOT NULL,
    id_type ENUM('citizenship', 'nic', 'driving_license') NOT NULL,
    id_number VARCHAR(50) NOT NULL,
    loan_amount DECIMAL(10, 2) NOT NULL,
    duration_days INT NOT NULL,
    status ENUM('pending', 'approved', 'rejected', 'paid') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
)");

// Handle loan request submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$is_admin && isset($_POST['request_loan'])) {
    if ($user['is_frozen']) {
        $_SESSION['error'] = "Your account is frozen. Contact admin.";
        header('Location: dashboard.php');
        exit;
    }

    $name = filter_input(INPUT_POST, 'name', FILTER_SANITIZE_STRING);
    $address = filter_input(INPUT_POST, 'address', FILTER_SANITIZE_STRING);
    $permanent_address = filter_input(INPUT_POST, 'permanent_address', FILTER_SANITIZE_STRING);
    $id_type = filter_input(INPUT_POST, 'id_type', FILTER_SANITIZE_STRING);
    $id_number = filter_input(INPUT_POST, 'id_number', FILTER_SANITIZE_STRING);
    $loan_amount = floatval($_POST['loan_amount']);
    $duration_days = intval($_POST['duration_days']);
    $terms_accepted = isset($_POST['terms']) ? true : false;

    if (!$terms_accepted) {
        $_SESSION['error'] = "You must accept the terms and conditions to request a loan.";
        header('Location: dashboard.php');
        exit;
    }

    if ($loan_amount <= 0) {
        $_SESSION['error'] = "Loan amount must be greater than zero.";
        header('Location: dashboard.php');
        exit;
    }

    if ($duration_days <= 0) {
        $_SESSION['error'] = "Loan duration must be greater than zero.";
        header('Location: dashboard.php');
        exit;
    }

    if (empty($name) || empty($address) || empty($permanent_address) || empty($id_type) || empty($id_number)) {
        $_SESSION['error'] = "All fields are required.";
        header('Location: dashboard.php');
        exit;
    }

    // Check active loan requests count and total loan amount
    $stmt = $pdo->prepare("SELECT COUNT(*) as active_count, SUM(loan_amount) as total_amount 
                           FROM loan_requests WHERE user_id = ? AND status IN ('pending', 'approved')");
    $stmt->execute([$user_id]);
    $loan_stats = $stmt->fetch();
    $active_loan_count = $loan_stats['active_count'];
    $total_loan_amount = $loan_stats['total_amount'] ?: 0;

    if ($active_loan_count >= 2) {
        $_SESSION['error'] = "You have reached the maximum limit of 2 active loan requests. Please pay off an existing loan before requesting a new one.";
        header('Location: dashboard.php');
        exit;
    }

    if (($total_loan_amount + $loan_amount) > 500000) {
        $_SESSION['error'] = "Total loan amount cannot exceed 500,000. Please pay off an existing loan first.";
        header('Location: dashboard.php');
        exit;
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO loan_requests (user_id, name, address, permanent_address, id_type, id_number, loan_amount, duration_days, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())");
        $stmt->execute([$user_id, $name, $address, $permanent_address, $id_type, $id_number, $loan_amount, $duration_days]);
        $_SESSION['request_success'] = true;
        header('Location: dashboard.php');
        exit;
    } catch (Exception $e) {
        $_SESSION['error'] = "Error: " . $e->getMessage();
        header('Location: dashboard.php');
        exit;
    }
}

// Handle loan payment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$is_admin && isset($_POST['pay_loan'])) {
    if ($user['is_frozen']) {
        $_SESSION['error'] = "Your account is frozen. Contact admin.";
        header('Location: dashboard.php');
        exit;
    }

    $loan_id = $_POST['loan_id'];
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
                $stmt = $pdo->prepare("UPDATE users SET balance = balance - ? WHERE id = ?");
                $stmt->execute([$loan_amount, $user_id]);
                $stmt = $pdo->prepare("UPDATE loan_requests SET status = 'paid', updated_at = NOW() WHERE id = ?");
                $stmt->execute([$loan_id]);
                $pdo->commit();
                $_SESSION['payment_success'] = true;
                header('Location: dashboard.php');
                exit;
            } catch (Exception $e) {
                if ($in_transaction) {
                    $pdo->rollBack();
                }
                $_SESSION['error'] = "Error: " . $e->getMessage();
                header('Location: dashboard.php');
                exit;
            }
        } else {
            $_SESSION['error'] = "Insufficient balance to pay the loan.";
            header('Location: dashboard.php');
            exit;
        }
    } else {
        $_SESSION['error'] = "Invalid loan request or loan already paid.";
        header('Location: dashboard.php');
        exit;
    }
}

// Handle admin loan request approval/rejection (already handled in loan_account.php, keeping for consistency if needed)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_admin && (isset($_POST['approve_loan']) || isset($_POST['reject_loan']))) {
    // This is now handled in loan_account.php, so redirect
    header('Location: loan_account.php');
    exit;
}

// Handle user actions (transfer with MPIN)
if (isset($_POST['transfer'])) {
    if ($user['is_frozen']) {
        $_SESSION['error'] = "Your account is frozen. Contact admin.";
        header('Location: dashboard.php');
        exit;
    } elseif (!$user['mpin']) {
        $_SESSION['error'] = "Please set your MPIN during registration.";
        header('Location: dashboard.php');
        exit;
    } else {
        $recipient_username = filter_input(INPUT_POST, 'recipient', FILTER_SANITIZE_STRING);
        $amount = floatval($_POST['amount']);

        // Validate recipient and amount
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $stmt->execute([$recipient_username, $user_id]);
        $recipient = $stmt->fetch();

        if (!$recipient) {
            $_SESSION['error'] = "Recipient not found or invalid.";
            header('Location: dashboard.php');
            exit;
        }
        if ($amount <= 0) {
            $_SESSION['error'] = "Amount must be greater than zero.";
            header('Location: dashboard.php');
            exit;
        }
        if ($user['balance'] < $amount) {
            $_SESSION['error'] = "Insufficient balance for transfer.";
            header('Location: dashboard.php');
            exit;
        }

        $_SESSION['pending_action'] = 'transfer';
        $_SESSION['transfer_data'] = ['recipient' => $recipient_username, 'amount' => $amount];
        $_SESSION['require_mpin'] = true;
        header('Location: dashboard.php');
        exit;
    }
} elseif (isset($_POST['verify_mpin']) && $_SESSION['pending_action'] === 'transfer') {
    $entered_mpin = filter_input(INPUT_POST, 'mpin', FILTER_SANITIZE_STRING);
    if ($entered_mpin === $user['mpin']) {
        $data = $_SESSION['transfer_data'];
        $amount = floatval($data['amount']);
        $recipient_username = $data['recipient'];

        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $stmt->execute([$recipient_username, $user_id]);
        $recipient = $stmt->fetch();

        if ($recipient && $amount > 0 && $user['balance'] >= $amount) {
            $in_transaction = false;
            try {
                $pdo->beginTransaction();
                $in_transaction = true;
                $stmt = $pdo->prepare("UPDATE users SET balance = balance - ? WHERE id = ?");
                $stmt->execute([$amount, $user_id]);
                $stmt = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
                $stmt->execute([$amount, $recipient['id']]);
                $stmt = $pdo->prepare("INSERT INTO transactions (user_id, amount, type, recipient_id, description) VALUES (?, ?, 'transfer_sent', ?, 'Transfer to $recipient_username')");
                $stmt->execute([$user_id, $amount, $recipient['id']]);
                $stmt = $pdo->prepare("INSERT INTO transactions (user_id, amount, type, recipient_id, description) VALUES (?, ?, 'transfer_received', ?, 'Transfer from {$user['username']}')");
                $stmt->execute([$recipient['id'], $amount, $user_id]);

                // Notify sender
                $stmt = $pdo->prepare("INSERT INTO notifications (user_id, sender_id, message, created_at) VALUES (?, ?, ?, NOW())");
                $stmt->execute([$user_id, $user_id, "You have transferred रु" . number_format($amount, 2) . " to $recipient_username."]);

                // Notify recipient
                $stmt->execute([$recipient['id'], $user_id, "You have received रु" . number_format($amount, 2) . " from {$user['username']}."]);

                $pdo->commit();
                $_SESSION['payment_success'] = true;
                unset($_SESSION['pending_action']);
                unset($_SESSION['transfer_data']);
                unset($_SESSION['require_mpin']);
                header('Location: dashboard.php');
                exit;
            } catch (Exception $e) {
                if ($in_transaction) {
                    $pdo->rollBack();
                }
                $_SESSION['error'] = "Transfer failed: " . $e->getMessage();
                unset($_SESSION['require_mpin']);
                header('Location: dashboard.php');
                exit;
            }
        } else {
            $_SESSION['error'] = "Invalid transfer details or insufficient balance.";
            unset($_SESSION['require_mpin']);
            header('Location: dashboard.php');
            exit;
        }
    } else {
        $_SESSION['error'] = "Incorrect MPIN.";
        unset($_SESSION['require_mpin']);
        header('Location: dashboard.php');
        exit;
    }
} elseif (isset($_POST['request_money'])) {
    if ($user['is_frozen']) {
        $_SESSION['error'] = "Your account is frozen. Contact admin.";
        header('Location: dashboard.php');
        exit;
    } elseif (!$user['mpin']) {
        $_SESSION['error'] = "Please set your MPIN during registration.";
        header('Location: dashboard.php');
        exit;
    } else {
        $_SESSION['pending_action'] = 'request_money';
        $_SESSION['request_data'] = $_POST;
        $_SESSION['require_mpin'] = true;
        header('Location: dashboard.php');
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
            $in_transaction = false;
            try {
                $pdo->beginTransaction();
                $in_transaction = true;
                $stmt = $pdo->prepare("INSERT INTO money_requests (sender_id, recipient_id, amount, status, created_at) VALUES (?, ?, ?, 'pending', NOW())");
                $stmt->execute([$user_id, $recipient['id'], $amount]);
                $request_id = $pdo->lastInsertId();
                $pdo->commit();

                // Notify recipient
                $stmt = $pdo->prepare("INSERT INTO notifications (user_id, sender_id, message, created_at) VALUES (?, ?, ?, NOW())");
                $stmt->execute([$recipient['id'], $user_id, "You have a new money request of रु" . number_format($amount, 2) . " from {$user['username']} (ID: $request_id)"]);
                $_SESSION['request_success'] = true;
                unset($_SESSION['pending_action']);
                unset($_SESSION['request_data']);
                unset($_SESSION['require_mpin']);
                header('Location: dashboard.php');
                exit;
            } catch (Exception $e) {
                if ($in_transaction) {
                    $pdo->rollBack();
                }
                $_SESSION['error'] = "Error: " . $e->getMessage();
                unset($_SESSION['require_mpin']);
                header('Location: dashboard.php');
                exit;
            }
        } else {
            $_SESSION['error'] = "Invalid recipient or amount.";
            unset($_SESSION['require_mpin']);
            header('Location: dashboard.php');
            exit;
        }
    } else {
        $_SESSION['error'] = "Incorrect MPIN.";
        unset($_SESSION['require_mpin']);
        header('Location: dashboard.php');
        exit;
    }
}

// Handle admin actions (with MPIN)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_admin) {
    if (isset($_POST['add_user_balance'])) {
        $_SESSION['pending_action'] = 'add_user_balance';
        $_SESSION['action_data'] = $_POST;
        $_SESSION['require_mpin'] = true;
        header('Location: dashboard.php');
        exit;
    } elseif (isset($_POST['remove_user_balance'])) {
        $_SESSION['pending_action'] = 'remove_user_balance';
        $_SESSION['action_data'] = $_POST;
        $_SESSION['require_mpin'] = true;
        header('Location: dashboard.php');
        exit;
    } elseif (isset($_POST['verify_mpin']) && in_array($_SESSION['pending_action'], ['add_user_balance', 'remove_user_balance'])) {
        $entered_mpin = filter_input(INPUT_POST, 'mpin', FILTER_SANITIZE_STRING);
        if ($entered_mpin === $user['mpin']) {
            if ($_SESSION['pending_action'] === 'add_user_balance') {
                $data = $_SESSION['action_data'];
                $user_id_to_update = $data['user_id'];
                $amount = floatval($data['amount']);
                if ($amount > 0) {
                    $in_transaction = false;
                    try {
                        $pdo->beginTransaction();
                        $in_transaction = true;
                        $stmt = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
                        $stmt->execute([$amount, $user_id_to_update]);
                        $stmt = $pdo->prepare("INSERT INTO transactions (user_id, admin_id, amount, type, description) VALUES (?, ?, ?, 'deposit', 'Admin added balance')");
                        $stmt->execute([$user_id_to_update, $user_id, $amount]);
                        $stmt = $pdo->prepare("INSERT INTO admin_actions (admin_id, user_id, action, amount, description) VALUES (?, ?, 'add_balance', ?, 'Admin added balance')");
                        $stmt->execute([$user_id, $user_id_to_update, $amount]);

                        // Notify user
                        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, sender_id, message, created_at) VALUES (?, ?, ?, NOW())");
                        $stmt->execute([$user_id_to_update, $user_id, "Admin has added रु" . number_format($amount, 2) . " to your balance."]);

                        $pdo->commit();
                        $_SESSION['payment_success'] = true;
                        unset($_SESSION['pending_action']);
                        unset($_SESSION['action_data']);
                        unset($_SESSION['require_mpin']);
                        header('Location: dashboard.php');
                        exit;
                    } catch (Exception $e) {
                        if ($in_transaction) {
                            $pdo->rollBack();
                        }
                        $_SESSION['error'] = "Error: " . $e->getMessage();
                        unset($_SESSION['require_mpin']);
                        header('Location: dashboard.php');
                        exit;
                    }
                } else {
                    $_SESSION['error'] = "Invalid amount.";
                    unset($_SESSION['require_mpin']);
                    header('Location: dashboard.php');
                    exit;
                }
            } elseif ($_SESSION['pending_action'] === 'remove_user_balance') {
                $data = $_SESSION['action_data'];
                $user_id_to_update = $data['user_id'];
                $amount = floatval($data['amount']);
                $stmt = $pdo->prepare("SELECT balance FROM users WHERE id = ?");
                $stmt->execute([$user_id_to_update]);
                $current_balance = $stmt->fetchColumn();
                if ($amount > 0 && $current_balance >= $amount) {
                    $in_transaction = false;
                    try {
                        $pdo->beginTransaction();
                        $in_transaction = true;
                        $stmt = $pdo->prepare("UPDATE users SET balance = balance - ? WHERE id = ?");
                        $stmt->execute([$amount, $user_id_to_update]);
                        $stmt = $pdo->prepare("INSERT INTO transactions (user_id, admin_id, amount, type, description) VALUES (?, ?, ?, 'withdrawal', 'Admin removed balance')");
                        $stmt->execute([$user_id_to_update, $user_id, $amount]);
                        $stmt = $pdo->prepare("INSERT INTO admin_actions (admin_id, user_id, action, amount, description) VALUES (?, ?, 'remove_balance', ?, 'Admin removed balance')");
                        $stmt->execute([$user_id, $user_id_to_update, $amount]);
                        $pdo->commit();
                        $_SESSION['payment_success'] = true;
                        unset($_SESSION['pending_action']);
                        unset($_SESSION['action_data']);
                        unset($_SESSION['require_mpin']);
                        header('Location: dashboard.php');
                        exit;
                    } catch (Exception $e) {
                        if ($in_transaction) {
                            $pdo->rollBack();
                        }
                        $_SESSION['error'] = "Error: " . $e->getMessage();
                        unset($_SESSION['require_mpin']);
                        header('Location: dashboard.php');
                        exit;
                    }
                } else {
                    $_SESSION['error'] = "Invalid amount or insufficient balance.";
                    unset($_SESSION['require_mpin']);
                    header('Location: dashboard.php');
                    exit;
                }
            }
        } else {
            $_SESSION['error'] = "Incorrect MPIN. Please try again.";
            unset($_SESSION['require_mpin']);
            header('Location: dashboard.php');
            exit;
        }
    } elseif (isset($_POST['delete_user'])) {
        $user_id_to_delete = $_POST['user_id'];
        $in_transaction = false;
        try {
            $pdo->beginTransaction();
            $in_transaction = true;
            $stmt = $pdo->prepare("DELETE FROM transactions WHERE user_id = ? OR recipient_id = ?");
            $stmt->execute([$user_id_to_delete, $user_id_to_delete]);
            $stmt = $pdo->prepare("DELETE FROM admin_actions WHERE user_id = ?");
            $stmt->execute([$user_id_to_delete]);
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND is_admin = 0");
            $stmt->execute([$user_id_to_delete]);
            $pdo->commit();
            header('Location: dashboard.php');
            exit;
        } catch (Exception $e) {
            if ($in_transaction) {
                $pdo->rollBack();
            }
            $_SESSION['error'] = "Error: " . $e->getMessage();
            header('Location: dashboard.php');
            exit;
        }
    } elseif (isset($_POST['freeze_user'])) {
        $user_id_to_freeze = $_POST['user_id'];
        $in_transaction = false;
        try {
            $pdo->beginTransaction();
            $in_transaction = true;
            $stmt = $pdo->prepare("UPDATE users SET is_frozen = 1 WHERE id = ? AND is_admin = 0");
            $stmt->execute([$user_id_to_freeze]);
            $stmt = $pdo->prepare("INSERT INTO admin_actions (admin_id, user_id, action, description) VALUES (?, ?, 'freeze', 'Admin froze account due to suspicious activity')");
            $stmt->execute([$user_id, $user_id_to_freeze]);
            $pdo->commit();
            header('Location: dashboard.php');
            exit;
        } catch (Exception $e) {
            if ($in_transaction) {
                $pdo->rollBack();
            }
            $_SESSION['error'] = "Error: " . $e->getMessage();
            header('Location: dashboard.php');
            exit;
        }
    } elseif (isset($_POST['unfreeze_user'])) {
        $user_id_to_unfreeze = $_POST['user_id'];
        $in_transaction = false;
        try {
            $pdo->beginTransaction();
            $in_transaction = true;
            $stmt = $pdo->prepare("UPDATE users SET is_frozen = 0 WHERE id = ? AND is_admin = 0");
            $stmt->execute([$user_id_to_unfreeze]);
            $stmt = $pdo->prepare("INSERT INTO admin_actions (admin_id, user_id, action, description) VALUES (?, ?, 'unfreeze', 'Admin unfroze account')");
            $stmt->execute([$user_id, $user_id_to_unfreeze]);
            $pdo->commit();
            header('Location: dashboard.php');
            exit;
        } catch (Exception $e) {
            if ($in_transaction) {
                $pdo->rollBack();
            }
            $_SESSION['error'] = "Error: " . $e->getMessage();
            header('Location: dashboard.php');
            exit;
        }
    }
}

// Fetch all users and transactions for admin with search
$search = isset($_GET['search']) ? $_GET['search'] : '';
$all_users_query = "SELECT * FROM users WHERE is_admin = 0";
if ($search) {
    $all_users_query .= " AND (username LIKE ? OR email LIKE ?)";
    $stmt = $pdo->prepare($all_users_query);
    $search_param = "%$search%";
    $stmt->execute([$search_param, $search_param]);
    $all_users = $stmt->fetchAll();
} else {
    $all_users = $pdo->query("SELECT * FROM users WHERE is_admin = 0")->fetchAll();
}

$all_transactions = $is_admin ? $pdo->query("SELECT t.*, u1.username AS sender, u2.username AS recipient, u3.username AS admin_username 
                                           FROM transactions t 
                                           LEFT JOIN users u1 ON t.user_id = u1.id 
                                           LEFT JOIN users u2 ON t.recipient_id = u2.id 
                                           LEFT JOIN users u3 ON t.admin_id = u3.id 
                                           ORDER BY t.created_at DESC")->fetchAll() : [];

$transactions = $pdo->prepare("SELECT t.*, u.username AS recipient_username, u2.username AS admin_username 
                              FROM transactions t 
                              LEFT JOIN users u ON t.recipient_id = u.id 
                              LEFT JOIN users u2 ON t.admin_id = u2.id 
                              WHERE t.user_id = ? 
                              ORDER BY created_at DESC");
$transactions->execute([$user_id]);
$transactions = $transactions->fetchAll();

// Fetch pending loan requests for admin
$pending_loan_requests = $is_admin ? $pdo->query("SELECT lr.*, u.username 
                                          FROM loan_requests lr 
                                          JOIN users u ON lr.user_id = u.id 
                                          WHERE lr.status = 'pending' 
                                          ORDER BY lr.created_at DESC")->fetchAll() : [];

// Fetch all loan requests for admin (including all statuses)
$all_loan_requests = $is_admin ? $pdo->query("SELECT lr.*, u.username 
                                      FROM loan_requests lr 
                                      JOIN users u ON lr.user_id = u.id 
                                      ORDER BY lr.created_at DESC")->fetchAll() : [];

// Calculate remaining days for all loan requests (for admin view)
$current_date = new DateTime();
$all_loan_requests_with_remaining = [];
foreach ($all_loan_requests as $request) {
    $created_at = new DateTime($request['created_at']);
    $interval = $current_date->diff($created_at);
    $days_passed = $interval->days;
    $remaining_days = max(0, $request['duration_days'] - $days_passed);
    $percentage = $request['duration_days'] > 0 ? ($remaining_days / $request['duration_days']) * 100 : 0;
    $request['remaining_days'] = $remaining_days;
    $request['percentage'] = $percentage;
    $all_loan_requests_with_remaining[] = $request;
}

// Fetch user's loan requests
$user_loan_requests = $pdo->prepare("SELECT * FROM loan_requests WHERE user_id = ? ORDER BY created_at DESC");
$user_loan_requests->execute([$user_id]);
$user_loan_requests = $user_loan_requests->fetchAll();

// Calculate remaining days for each loan request (for user view)
$loan_requests_with_remaining = [];
foreach ($user_loan_requests as $request) {
    $created_at = new DateTime($request['created_at']);
    $interval = $current_date->diff($created_at);
    $days_passed = $interval->days;
    $remaining_days = max(0, $request['duration_days'] - $days_passed);
    $percentage = $request['duration_days'] > 0 ? ($remaining_days / $request['duration_days']) * 100 : 0;
    $request['remaining_days'] = $remaining_days;
    $request['percentage'] = $percentage;
    $loan_requests_with_remaining[] = $request;
}

// Fetch pending loans (unpaid loans)
$pending_loans = [];
if ($is_admin) {
    $stmt = $pdo->query("SELECT lr.*, u.username, 
                                DATEDIFF(DATE_ADD(lr.created_at, INTERVAL lr.duration_days DAY), NOW()) AS remaining_days
                         FROM loan_requests lr
                         JOIN users u ON lr.user_id = u.id
                         WHERE lr.status = 'approved'
                         ORDER BY lr.created_at DESC");
    $pending_loans = $stmt->fetchAll();
}

// Fetch successfully returned loans
$returned_loans = [];
if ($is_admin) {
    $stmt = $pdo->query("SELECT lr.*, u.username 
                         FROM loan_requests lr
                         JOIN users u ON lr.user_id = u.id
                         WHERE lr.status = 'paid'
                         ORDER BY lr.updated_at DESC");
    $returned_loans = $stmt->fetchAll();
}

// Fetch pending requests count
$pending_count = 0;
if (!$is_admin) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM money_requests WHERE recipient_id = ? AND status = 'pending'");
    $stmt->execute([$user_id]);
    $pending_count = $stmt->fetchColumn();
}

// Handle money request acceptance
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$is_admin && isset($_POST['accept_request'])) {
    $request_id = $_POST['request_id'];
    $stmt = $pdo->prepare("SELECT * FROM money_requests WHERE id = ? AND recipient_id = ? AND status = 'pending'");
    $stmt->execute([$request_id, $user_id]);
    $request = $stmt->fetch();

    if ($request) {
        $sender_id = $request['sender_id'];
        $amount = $request['amount'];

        if ($user['balance'] >= $amount) {
            $in_transaction = false;
            try {
                $pdo->beginTransaction();
                $in_transaction = true;
                $stmt = $pdo->prepare("UPDATE users SET balance = balance - ? WHERE id = ?");
                $stmt->execute([$amount, $user_id]);
                $stmt = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
                $stmt->execute([$amount, $sender_id]);
                $stmt = $pdo->prepare("UPDATE money_requests SET status = 'accepted', updated_at = NOW() WHERE id = ?");
                $stmt->execute([$request_id]);
                $pdo->commit();

                // Notify sender
                $stmt = $pdo->prepare("INSERT INTO notifications (user_id, sender_id, message, created_at) VALUES (?, ?, ?, NOW())");
                $stmt->execute([$sender_id, $user_id, "Your money request of रु" . number_format($amount, 2) . " has been accepted by {$user['username']} (Request ID: $request_id)."]);

                $_SESSION['payment_success'] = true;
                header('Location: dashboard.php');
                exit;
            } catch (Exception $e) {
                if ($in_transaction) {
                    $pdo->rollBack();
                }
                $_SESSION['error'] = "Error accepting request: " . $e->getMessage();
                header('Location: dashboard.php');
                exit;
            }
        } else {
            $_SESSION['error'] = "Insufficient balance to accept the request.";
            header('Location: dashboard.php');
            exit;
        }
    } else {
        $_SESSION['error'] = "Invalid request or already processed.";
        header('Location: dashboard.php');
        exit;
    }
}

// Check for success or error
$payment_success = isset($_SESSION['payment_success']) ? $_SESSION['payment_success'] : false;
$request_success = isset($_SESSION['request_success']) ? $_SESSION['request_success'] : false;
$error_message = isset($_SESSION['error']) ? $_SESSION['error'] : null;
$require_mpin = isset($_SESSION['require_mpin']) ? $_SESSION['require_mpin'] : false;
unset($_SESSION['payment_success']);
unset($_SESSION['request_success']);
unset($_SESSION['error']);
?>

<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Digital Wallet</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
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
        .animate-bounce-in {
            animation: bounceIn 1s ease-out;
        }
        .animate-fade-out {
            animation: fadeOut 1s ease-in forwards;
        }
        @keyframes slideIn {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        @keyframes bounceIn {
            0% { transform: scale(0.3); opacity: 0; }
            50% { transform: scale(1.05); opacity: 1; }
            100% { transform: scale(1); }
        }
        @keyframes fadeOut {
            from { opacity: 1; }
            to { opacity: 0; }
        }
        .success-modal, .request-success-modal, .mpin-modal, .notification-popup, .login-modal {
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
        .success-modal.active, .request-success-modal.active, .mpin-modal.active, .notification-popup.active, .login-modal.active {
            display: flex;
        }
        .checkmark {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: #10b981;
            position: relative;
            animation: scaleIn 0.5s ease-out;
        }
        .checkmark::after {
            content: '';
            position: absolute;
            top: 20px;
            left: 30px;
            width: 20px;
            height: 40px;
            border: solid white;
            border-width: 0 6px 6px 0;
            transform: rotate(45deg);
        }
        .request-checkmark {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: linear-gradient(135deg, #34d399, #059669);
            position: relative;
            animation: scaleIn 0.5s ease-out;
            box-shadow: 0 0 20px rgba(52, 211, 153, 0.5);
        }
        .request-checkmark::after {
            content: '';
            position: absolute;
            top: 25px;
            left: 35px;
            width: 25px;
            height: 50px;
            border: solid white;
            border-width: 0 8px 8px 0;
            transform: rotate(45deg);
        }
        @keyframes scaleIn {
            from { transform: scale(0); }
            to { transform: scale(1); }
        }
        @keyframes glow {
            0% { box-shadow: 0 0 10px rgba(52, 211, 153, 0.5); }
            50% { box-shadow: 0 0 20px rgba(52, 211, 153, 0.8); }
            100% { box-shadow: 0 0 10px rgba(52, 211, 153, 0.5); }
        }
        .request-success-modal .modal-content {
            animation: glow 1.5s infinite;
        }
        .frozen { color: #ef4444; font-weight: bold; }
        .hover-scale { transition: transform 0.2s; }
        .hover-scale:hover { transform: scale(1.05); }
        .notification-dropdown {
            display: none;
            position: absolute;
            top: 50px;
            right: 0;
            width: 300px;
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            z-index: 1000;
        }
        .dark .notification-dropdown {
            background: #2d3748;
            border-color: #4a5568;
        }
        .notification-item {
            padding: 10px;
            border-bottom: 1px solid #ddd;
            cursor: pointer;
        }
        .dark .notification-item {
            border-bottom: 1px solid #4a5568;
        }
        .notification-item:last-child {
            border-bottom: none;
        }
        .notification-item:hover {
            background-color: #f1f5f9;
        }
        .dark .notification-item:hover {
            background-color: #4a5568;
        }
        .notification-count {
            position: absolute;
            top: -5px;
            right: -5px;
            background: #ef4444;
            color: white;
            border-radius: 50%;
            padding: 2px 6px;
            font-size: 12px;
        }
        .spinner {
            display: none;
            width: 16px;
            height: 16px;
            border: 2px solid #ffffff;
            border-top: 2px solid transparent;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .user-actions {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
        }
        .action-card, .loan-card {
            background-color: white;
            padding: 20px;
            border-radius: 8px;
            width: 50%;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        .dark .action-card, .dark .loan-card {
            background-color: #2d3748;
        }
        .action-card label, .loan-card label {
            display: block;
            margin-bottom: 5px;
            color: #4a5568;
        }
        .dark .action-card label, .dark .loan-card label {
            color: #a0aec0;
        }
        .action-card input, .loan-card input, .loan-card select, .loan-card textarea {
            width: 100%;
            padding: 8px;
            margin-bottom: 10px;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
        }
        .dark .action-card input, .dark .loan-card input, .dark .loan-card select, .dark .loan-card textarea {
            background-color: #4a5568;
            border-color: #718096;
            color: #e2e8f0;
        }
        .action-card button, .loan-card button {
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            background-color: #4299e1;
            color: white;
        }
        .action-card button.request, .loan-card button.request {
            background-color: #48bb78;
        }
        .action-card button:hover, .loan-card button:hover {
            opacity: 0.9;
        }
        .pending-count {
            background-color: #ef4444;
            color: white;
            border-radius: 50%;
            padding: 2px 6px;
            font-size: 12px;
            margin-left: 5px;
        }
        .logout-btn {
            transition: all 0.3s ease;
        }
        .logout-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }
        #loanFormContainer {
            display: none;
            margin-top: 20px;
        }
        .toggle-btn {
            transition: all 0.3s ease;
        }
        .toggle-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }
        .balance-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .volume-meter {
            width: 100%;
            height: 10px;
            background: #e2e8f0;
            border-radius: 5px;
            position: relative;
            overflow: hidden;
        }
        .dark .volume-meter {
            background: #4a5568;
        }
        .volume-meter-fill {
            height: 100%;
            background: linear-gradient(to right, #ff0000, #ffa500, #00ff00);
            position: absolute;
            left: 0;
            top: 0;
            transition: width 0.3s ease;
        }
        .volume-meter-text {
            margin-top: 5px;
            font-size: 12px;
            color: #4a5568;
        }
        .dark .volume-meter-text {
            color: #a0aec0;
        }
    </style>
</head>
<body class="bg-gray-100 dark:bg-gray-900 min-h-screen">
    <!-- Login Success Modal -->
    <div id="loginModal" class="login-modal <?php echo $login_success ? 'active' : ''; ?>">
        <div class="bg-white dark:bg-gray-800 p-8 rounded-lg shadow-lg text-center animate-bounce-in">
            <div class="checkmark mx-auto mb-4"></div>
            <h2 class="text-3xl font-bold text-gray-800 dark:text-gray-200 animate-pulse">Welcome Back, <?php echo htmlspecialchars($user['username']); ?>!</h2>
            <p class="text-gray-600 dark:text-gray-400 mt-2">You’ve successfully logged in to your Digital Wallet.</p>
            <div class="mt-4 flex justify-center">
                <button onclick="closeLoginModal()" class="px-6 py-2 bg-gradient-to-r from-blue-500 to-blue-700 text-white rounded-full hover:from-blue-600 hover:to-blue-800 transition-all duration-300">Let’s Get Started</button>
            </div>
        </div>
    </div>

    <!-- Payment Success Modal -->
    <div id="successModal" class="success-modal <?php echo $payment_success ? 'active' : ''; ?>">
        <div class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-lg text-center animate-slide-in">
            <div class="checkmark mx-auto mb-4"></div>
            <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-200">Payment Successful!</h2>
            <p class="text-gray-600 dark:text-gray-400 mt-2">Your transaction has been completed.</p>
            <button onclick="closeSuccessModal()" class="mt-4 px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">Close</button>
        </div>
    </div>

    <!-- Request Success Modal -->
    <div id="requestSuccessModal" class="request-success-modal <?php echo $request_success ? 'active' : ''; ?>">
        <div class="modal-content bg-white dark:bg-gray-800 p-8 rounded-lg shadow-lg text-center animate-bounce-in">
            <div class="request-checkmark mx-auto mb-4"></div>
            <h2 class="text-3xl font-bold text-gray-800 dark:text-gray-200 animate-pulse">Request Successful!</h2>
            <p class="text-gray-600 dark:text-gray-400 mt-2">Your request has been submitted successfully.</p>
            <button onclick="closeRequestSuccessModal()" class="mt-4 px-6 py-2 bg-gradient-to-r from-green-500 to-green-700 text-white rounded-full hover:from-green-600 hover:to-green-800 transition-all duration-300">Close</button>
        </div>
    </div>

    <!-- MPIN Modal -->
    <div id="mpinModal" class="mpin-modal <?php echo $require_mpin ? 'active' : ''; ?>">
        <div class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-lg text-center animate-slide-in">
            <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-200 mb-4"><?php echo $is_admin ? 'Are you sure?' : 'Enter Your MPIN'; ?></h2>
            <form method="POST">
                <?php if ($is_admin): ?>
                    <input type="hidden" name="amount" value="<?php echo isset($_SESSION['action_data']['amount']) ? htmlspecialchars($_SESSION['action_data']['amount']) : ''; ?>">
                <?php else: ?>
                    <input type="hidden" name="amount" value="<?php echo isset($_SESSION['transfer_data']['amount']) ? htmlspecialchars($_SESSION['transfer_data']['amount']) : (isset($_SESSION['request_data']['amount']) ? htmlspecialchars($_SESSION['request_data']['amount']) : ''); ?>">
                <?php endif; ?>
                <div class="mb-4">
                    <input type="text" name="mpin" maxlength="4" pattern="\d{4}" placeholder="Enter 4-digit MPIN" class="p-2 border rounded dark:bg-gray-700 dark:text-gray-200" required>
                </div>
                <button type="submit" name="verify_mpin" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">Confirm</button>
                <button type="button" onclick="closeMpinModal()" class="px-4 py-2 bg-gray-600 text-white rounded hover:bg-gray-700 ml-2">Cancel</button>
            </form>
        </div>
    </div>

    <!-- Message Modal for Admin -->
    <div id="messageModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden z-50">
        <div class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-lg w-1/3">
            <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-200 mb-4">Send Message</h2>
            <form method="POST" action="notification_handler.php" class="space-y-4">
                <input type="hidden" name="user_id" id="messageRecipientId">
                <textarea name="message" class="w-full p-2 border rounded dark:bg-gray-700 dark:text-gray-200" placeholder="Type your message here..." required></textarea>
                <button type="submit" name="send_message" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">Send Notification</button>
                <button type="button" onclick="closeMessageModal()" class="px-4 py-2 bg-gray-600 text-white rounded hover:bg-gray-700 ml-2">Cancel</button>
            </form>
        </div>
    </div>

    <!-- Notification Popup -->
    <div id="notificationPopup" class="notification-popup">
        <div id="notificationContent" class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-lg text-center animate-slide-in max-w-md">
            <p id="popupMessage" class="text-gray-800 dark:text-gray-200"></p>
            <p id="popupTimestamp" class="text-sm text-gray-500 dark:text-gray-400 mt-2"></p>
            <button onclick="closeNotificationPopup()" class="mt-4 px-4 py-2 bg-red-600 text-white rounded hover:bg-red-700">Close</button>
        </div>
    </div>

    <div class="container mx-auto p-6">
        <!-- Header -->
        <div class="flex justify-between items-center mb-6 animate-slide-in">
            <h2 class="text-3xl font-bold text-gray-800 dark:text-gray-200">Welcome, <?php echo htmlspecialchars($user['username']); ?>!</h2>
            <div class="flex items-center space-x-4">
                <div class="relative">
                    <button id="notificationToggle" class="p-2 bg-gray-200 dark:bg-gray-700 rounded-full relative">
                        <svg class="w-6 h-6 text-gray-800 dark:text-gray-200" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6zm0 16a2 2 0 01-2-2h4a2 2 0 01-2 2z"/>
                        </svg>
                        <span id="notificationCount" class="notification-count hidden">0</span>
                    </button>
                    <div id="notificationDropdown" class="notification-dropdown">
                        <div class="p-2 flex justify-between items-center">
                            <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-200">Notifications</h3>
                            <button id="refreshNotifications" class="text-blue-600 dark:text-blue-400 text-sm hover:underline flex items-center">
                                <span>Refresh</span>
                                <span id="refreshSpinner" class="spinner ml-1"></span>
                            </button>
                        </div>
                        <div id="notificationList" class="max-h-60 overflow-y-auto">
                            <!-- Notifications will be populated here -->
                        </div>
                    </div>
                </div>
                <button id="themeToggle" class="p-2 bg-gray-200 dark:bg-gray-700 rounded-full">
                    <svg class="w-6 h-6 text-gray-800 dark:text-gray-200" fill="currentColor" viewBox="0 0 20 20">
                        <path d="M17.293 13.293A8 8 0 016.707 2.707a8.001 8.001 0 1010.586 10.586z" />
                    </svg>
                </button>
                <a href="logout.php" id="logoutBtn" class="text-blue-600 dark:text-blue-400 hover:underline logout-btn px-4 py-2 bg-gradient-to-r from-red-500 to-red-700 rounded-full text-white">Logout</a>
            </div>
        </div>

        <!-- Balance -->
        <div class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md mb-6 animate-slide-in">
            <div class="balance-section flex justify-between items-center">
                <p class="text-2xl font-semibold text-gray-800 dark:text-gray-200">
                    Current Balance: रु <?php echo number_format($user['balance'], 2); ?>
                    <?php if ($user['is_frozen'] && !$is_admin): ?>
                        <span class="frozen">(Account Frozen)</span>
                    <?php endif; ?>
                </p>
                <div class="flex items-center space-x-4">
                    <?php if (!$is_admin): ?>
                        <!-- Get Loan Button -->
                        <a href="get_loan.php" class="px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700 hover-scale">Get Loan</a>
                        <!-- Pay Loan Button -->
                        <a href="pay_loan.php" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 hover-scale">Pay Loan</a>
                    <?php endif; ?>
                    <?php if ($is_admin): ?>
                        <a href="loan_account.php" class="px-4 py-2 bg-purple-600 text-white rounded hover:bg-purple-700 hover-scale">View Loan Accounts</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Error Message -->
        <?php if ($error_message): ?>
            <div class="bg-red-100 text-red-700 p-4 rounded-lg mb-6 animate-slide-in">
                <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <?php if ($is_admin): ?>
            <!-- Admin Panel -->
            <div class="animate-slide-in">
                <h3 class="text-2xl font-bold text-gray-800 dark:text-gray-200 mb-4">Admin Panel</h3>

                <!-- Search and All Users -->
                <div class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md mb-6">
                    <h4 class="text-xl font-semibold text-gray-800 dark:text-gray-200 mb-4">All Users</h4>
                    <div class="mb-4">
                        <input type="text" id="userSearch" placeholder="Search by username or email..." class="p-2 border rounded dark:bg-gray-700 dark:text-gray-200 w-full" onkeyup="searchUsers()">
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left" id="userTable">
                            <thead>
                                <tr class="bg-gray-200 dark:bg-gray-700">
                                    <th class="p-3">ID</th>
                                    <th class="p-3">Username</th>
                                    <th class="p-3">Email</th>
                                    <th class="p-3">Balance</th>
                                    <th class="p-3">Status</th>
                                    <th class="p-3">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <div class="mt-4">
                        <a href="send_alert.php" class="px-3 py-1 bg-purple-600 text-white rounded hover:bg-purple-700 hover-scale">Send Alert to All</a>
                    </div>
                                <?php foreach ($all_users as $u): ?>
                                    <tr class="border-b dark:border-gray-700">
                                        <td class="p-3"><?php echo $u['id']; ?></td>
                                        <td class="p-3"><?php echo htmlspecialchars($u['username']); ?></td>
                                        <td class="p-3"><?php echo htmlspecialchars($u['email']); ?></td>
                                        <td class="p-3">रु <?php echo number_format($u['balance'], 2); ?></td>
                                        <td class="p-3"><?php echo $u['is_frozen'] ? 'Frozen' : 'Active'; ?></td>
                                        <td class="p-3">
                                            <form method="POST" class="flex flex-wrap gap-2">
                                                <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                                <input type="number" name="amount" step="0.01" placeholder="Amount" class="p-2 border rounded dark:bg-gray-700 dark:text-gray-200" required>
                                                <button type="submit" name="add_user_balance" class="px-3 py-1 bg-blue-600 text-white rounded hover:bg-blue-700 hover-scale">Add Balance</button>
                                                <button type="submit" name="remove_user_balance" class="px-3 py-1 bg-yellow-600 text-white rounded hover:bg-yellow-700 hover-scale">Remove Balance</button>
                                                <button type="submit" name="delete_user" onclick="return confirm('Are you sure?');" class="px-3 py-1 bg-red-600 text-white rounded hover:bg-red-700 hover-scale">Delete User</button>
                                                <?php if ($u['is_frozen']): ?>
                                                    <button type="submit" name="unfreeze_user" class="px-3 py-1 bg-green-600 text-white rounded hover:bg-green-700 hover-scale">Unfreeze</button>
                                                <?php else: ?>
                                                    <button type="submit" name="freeze_user" class="px-3 py-1 bg-orange-600 text-white rounded hover:bg-orange-700 hover-scale">Freeze</button>
                                                <?php endif; ?>
                                                <button type="button" onclick="openMessageModal(<?php echo $u['id']; ?>)" class="px-3 py-1 bg-purple-600 text-white rounded hover:bg-purple-700 hover-scale">Send Message</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- All Transactions -->
                <div class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md mb-6">
                    <h4 class="text-xl font-semibold text-gray-800 dark:text-gray-200 mb-4">All Transactions</h4>
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
                                <?php foreach (array_slice($all_transactions, 0, 5) as $t): ?>
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
                    <?php if (count($all_transactions) > 5): ?>
                        <div class="mt-4 text-center">
                            <a href="see_more_transactions.php" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">See More</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <!-- User Actions -->
            <div class="animate-slide-in">
                <h3 class="text-2xl font-bold text-gray-800 dark:text-gray-200 mb-4">User Actions</h3>
                <div class="user-actions">
                    <div class="action-card">
                        <h4 class="text-lg font-semibold mb-4">Transfer Money</h4>
                        <form method="POST" class="space-y-4">
                            <div>
                                <label for="recipient" class="block text-gray-700 dark:text-gray-300">Recipient Username</label>
                                <input type="text" name="recipient" id="recipient" placeholder="Recipient Username" class="w-full p-2 border rounded dark:bg-gray-700 dark:text-gray-200" required>
                            </div>
                            <div>
                                <label for="amount" class="block text-gray-700 dark:text-gray-300">Amount</label>
                                <input type="number" name="amount" id="amount" step="0.01" placeholder="Amount" class="w-full p-2 border rounded dark:bg-gray-700 dark:text-gray-200" required>
                            </div>
                            <button type="submit" name="transfer" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 hover-scale" <?php echo $user['is_frozen'] ? 'disabled' : ''; ?>>Transfer</button>
                        </form>
                    </div>
                    <div class="action-card">
                        <h4 class="text-lg font-semibold mb-4">Request Money <a href="pending_requests.php" class="text-blue-600 dark:text-blue-400 hover:underline mt-4 inline-block">
                    Pending Requests
                    <?php if ($pending_count > 0): ?>
                        <span class="pending-count"><?php echo $pending_count; ?></span>
                    <?php endif; ?>
                </a></h4>
                        <form method="POST" class="space-y-4">
                            <div>
                                <label for="recipient_request" class="block text-gray-700 dark:text-gray-300">Recipient Username</label>
                                <input type="text" name="recipient" id="recipient_request" placeholder="Recipient Username" class="w-full p-2 border rounded dark:bg-gray-700 dark:text-gray-200" required>
                            </div>
                            <div>
                                <label for="amount_request" class="block text-gray-700 dark:text-gray-300">Amount</label>
                                <input type="number" name="amount" id="amount_request" step="0.01" placeholder="Amount" class="w-full p-2 border rounded dark:bg-gray-700 dark:text-gray-200" required>
                            </div>
                            <button type="submit" name="request_money" class="request px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700 hover-scale" <?php echo $user['is_frozen'] ? 'disabled' : ''; ?>>Request Money</button>
                        </form>
                    </div>
                </div>
                <!-- Get Loan Form (Hidden by Default) -->
                <div id="loanFormContainer" class="loan-card">
                    <h4 class="text-lg font-semibold mb-4">Get Loan</h4>
                    <form method="POST" class="space-y-4" onsubmit="return validateLoanForm()">
                        <div>
                            <label for="name" class="block text-gray-700 dark:text-gray-300">Full Name</label>
                            <input type="text" name="name" id="name" placeholder="Full Name" value="<?php echo htmlspecialchars($user['username']); ?>" class="w-full p-2 border rounded dark:bg-gray-700 dark:text-gray-200" required>
                        </div>
                        <div>
                            <label for="address" class="block text-gray-700 dark:text-gray-300">Address</label>
                            <textarea name="address" id="address" placeholder="Current Address" class="w-full p-2 border rounded dark:bg-gray-700 dark:text-gray-200" required></textarea>
                        </div>
                        <div>
                            <label for="permanent_address" class="block text-gray-700 dark:text-gray-300">Permanent Address</label>
                            <textarea name="permanent_address" id="permanent_address" placeholder="Permanent Address" class="w-full p-2 border rounded dark:bg-gray-700 dark:text-gray-200" required></textarea>
                        </div>
                        <div>
                            <label for="id_type" class="block text-gray-700 dark:text-gray-300">Select ID Type</label>
                            <select name="id_type" id="id_type" class="w-full p-2 border rounded dark:bg-gray-700 dark:text-gray-200" required>
                                <option value="">Select ID Type</option>
                                <option value="citizenship">Citizenship Number</option>
                                <option value="nic">National Identity Card</option>
                                <option value="driving_license">Driving License</option>
                            </select>
                        </div>
                        <div>
                            <label for="id_number" class="block text-gray-700 dark:text-gray-300">ID Number</label>
                            <input type="text" name="id_number" id="id_number" placeholder="ID Number" class="w-full p-2 border rounded dark:bg-gray-700 dark:text-gray-200" required>
                        </div>
                        <div>
                            <label for="loan_amount" class="block text-gray-700 dark:text-gray-300">Loan Amount</label>
                            <input type="number" name="loan_amount" id="loan_amount" step="0.01" placeholder="Loan Amount" class="w-full p-2 border rounded dark:bg-gray-700 dark:text-gray-200" required>
                        </div>
                        <div>
                            <label for="duration_days" class="block text-gray-700 dark:text-gray-300">Loan Duration (Days)</label>
                            <input type="number" name="duration_days" id="duration_days" min="1" placeholder="Duration in Days" class="w-full p-2 border rounded dark:bg-gray-700 dark:text-gray-200" required>
                        </div>
                        <div>
                            <label class="flex items-center text-gray-700 dark:text-gray-300">
                                <input type="checkbox" name="terms" id="terms" class="mr-2">
                                I accept the <a href="#terms" class="text-blue-600 dark:text-blue-400 hover:underline">terms and conditions</a>
                            </label>
                        </div>
                        <button type="submit" name="request_loan" class="request px-4 py-2 bg-purple-600 text-white rounded hover:bg-purple-700 hover-scale" <?php echo $user['is_frozen'] ? 'disabled' : ''; ?>>Request Loan</button>
                    </form>
                </div>

                <!-- Loan Request History -->
                <div class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md mt-6">
                    <h3 class="text-xl font-semibold text-gray-800 dark:text-gray-200 mb-4">Loan Request History</h3>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left">
                            <thead>
                                <tr class="bg-gray-200 dark:bg-gray-700">
                                    <th class="p-3">Request ID</th>
                                    <th class="p-3">Name</th>
                                    <th class="p-3">Loan Amount</th>
                                    <th class="p-3">Status</th>
                                    <th class="p-3">Duration (Days)</th>
                                    <th class="p-3">Remaining Days</th>
                                    <th class="p-3">Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($loan_requests_with_remaining as $request): ?>
                                    <tr class="border-b dark:border-gray-700">
                                        <td class="p-3"><?php echo $request['id']; ?></td>
                                        <td class="p-3"><?php echo htmlspecialchars($request['name']); ?></td>
                                        <td class="p-3">रु <?php echo number_format($request['loan_amount'], 2); ?></td>
                                        <td class="p-3"><?php echo htmlspecialchars($request['status']); ?></td>
                                        <td class="p-3"><?php echo $request['duration_days']; ?></td>
                                        <td class="p-3">
                                            <?php if ($request['status'] === 'approved'): ?>
                                                <div class="volume-meter">
                                                    <div class="volume-meter-fill" style="width: <?php echo $request['percentage']; ?>%;"></div>
                                                </div>
                                                <div class="volume-meter-text"><?php echo $request['remaining_days']; ?> days remaining</div>
                                            <?php else: ?>
                                                N/A
                                            <?php endif; ?>
                                        </td>
                                        <td class="p-3"><?php echo $request['created_at']; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <!-- Link to Loan Request History -->
                    <div class="mt-4">
                        <a href="loan_request_history.php" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 hover-scale">View Loan Request History</a>
                    </div>
                </div>

                <!-- Pending Money Requests -->
                <div class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md mt-6">
                    <h3 class="text-xl font-semibold text-gray-800 dark:text-gray-200 mb-4">Pending Money Requests</h3>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left">
                            <thead>
                                <tr class="bg-gray-200 dark:bg-gray-700">
                                    <th class="p-3">Request ID</th>
                                    <th class="p-3">Sender</th>
                                    <th class="p-3">Amount</th>
                                    <th class="p-3">Date</th>
                                    <th class="p-3">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $pending_requests = $pdo->prepare("SELECT mr.*, u.username AS sender_username 
                                                                  FROM money_requests mr 
                                                                  JOIN users u ON mr.sender_id = u.id 
                                                                  WHERE mr.recipient_id = ? AND mr.status = 'pending'");
                                $pending_requests->execute([$user_id]);
                                $pending_requests = $pending_requests->fetchAll();
                                foreach ($pending_requests as $request): ?>
                                    <tr class="border-b dark:border-gray-700">
                                        <td class="p-3"><?php echo $request['id']; ?></td>
                                        <td class="p-3"><?php echo htmlspecialchars($request['sender_username']); ?></td>
                                        <td class="p-3">रु <?php echo number_format($request['amount'], 2); ?></td>
                                        <td class="p-3"><?php echo $request['created_at']; ?></td>
                                        <td class="p-3">
                                            <form method="POST" class="flex gap-2">
                                                <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>">
                                                <button type="submit" name="accept_request" class="px-3 py-1 bg-green-600 text-white rounded hover:bg-green-700 hover-scale">Accept</button>
                                                <button type="submit" name="reject_request" class="px-3 py-1 bg-red-600 text-white rounded hover:bg-red-700 hover-scale">Reject</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Transaction History -->
                <div class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md mt-6">
                    <h3 class="text-xl font-semibold text-gray-800 dark:text-gray-200 mb-4">Transaction History</h3>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left">
                            <thead>
                                <tr class="bg-gray-200 dark:bg-gray-700">
                                    <th class="p-3">ID</th>
                                    <th class="p-3">Amount</th>
                                    <th class="p-3">Type</th>
                                    <th class="p-3">Recipient</th>
                                    <th class="p-3">Description</th>
                                    <th class="p-3">Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_slice($transactions, 0, 5) as $t): ?>
                                    <tr class="border-b dark:border-gray-700">
                                        <td class="p-3"><?php echo $t['id']; ?></td>
                                        <td class="p-3">रु <?php echo number_format($t['amount'], 2); ?></td>
                                        <td class="p-3"><?php echo $t['type']; ?></td>
                                        <td class="p-3"><?php echo htmlspecialchars($t['recipient_username'] ?: 'N/A'); ?></td>
                                        <td class="p-3"><?php echo htmlspecialchars($t['description']); ?></td>
                                        <td class="p-3"><?php echo $t['created_at']; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if (count($transactions) > 5): ?>
                        <div class="mt-4 text-center">
                            <a href="see_more_transactions.php" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">See More</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
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

        // Login Success Animation
        <?php if ($login_success): ?>
            confetti({
                particleCount: 150,
                spread: 100,
                origin: { y: 0.6 },
                colors: ['#4f46e5', '#10b981', '#f59e0b']
            });
            setTimeout(() => {
                const modal = document.getElementById('loginModal');
                modal.classList.remove('active');
            }, 5000);
        <?php endif; ?>

        // Payment Success Animation
        <?php if ($payment_success): ?>
            confetti({
                particleCount: 100,
                spread: 70,
                origin: { y: 0.6 }
            });
            setTimeout(() => {
                const modal = document.getElementById('successModal');
                modal.classList.remove('active');
            }, 3000);
        <?php endif; ?>

        // Request Success Animation
        <?php if ($request_success): ?>
            confetti({
                particleCount: 150,
                spread: 100,
                origin: { y: 0.6 },
                colors: ['#34d399', '#059669', '#10b981']
            });
            setTimeout(() => {
                const modal = document.getElementById('requestSuccessModal');
                modal.classList.remove('active');
            }, 3000);
        <?php endif; ?>

        function closeLoginModal() {
            const modal = document.getElementById('loginModal');
            modal.classList.remove('active');
        }

        function closeSuccessModal() {
            document.getElementById('successModal').classList.remove('active');
        }

        function closeRequestSuccessModal() {
            document.getElementById('requestSuccessModal').classList.remove('active');
        }

        function closeMpinModal() {
            document.getElementById('mpinModal').classList.remove('active');
            window.location.href = 'dashboard.php';
        }

        function openMessageModal(userId) {
            document.getElementById('messageRecipientId').value = userId;
            document.getElementById('messageModal').classList.remove('hidden');
        }

        function closeMessageModal() {
            document.getElementById('messageModal').classList.add('hidden');
        }

        function searchUsers() {
            const input = document.getElementById('userSearch').value.toLowerCase();
            const table = document.getElementById('userTable');
            const tr = table.getElementsByTagName('tr');

            for (let i = 1; i < tr.length; i++) {
                const td = tr[i].getElementsByTagName('td')[1]; // Username column
                const tdEmail = tr[i].getElementsByTagName('td')[2]; // Email column
                if (td || tdEmail) {
                    const txtValue = td.textContent.toLowerCase() || tdEmail.textContent.toLowerCase();
                    tr[i].style.display = txtValue.includes(input) ? '' : 'none';
                }
            }
        }

        // Toggle Loan Form Visibility
        function toggleLoanForm() {
            const loanFormContainer = document.getElementById('loanFormContainer');
            if (loanFormContainer.style.display === 'none' || loanFormContainer.style.display === '') {
                loanFormContainer.style.display = 'block';
            } else {
                loanFormContainer.style.display = 'none';
            }
        }

        // Validate Loan Form
        function validateLoanForm() {
            const termsCheckbox = document.getElementById('terms');
            const durationDays = document.getElementById('duration_days').value;
            if (!termsCheckbox.checked) {
                alert('You must accept the terms and conditions to request a loan.');
                return false;
            }
            if (durationDays <= 0) {
                alert('Loan duration must be greater than zero.');
                return false;
            }
            return true;
        }

       // Notification Popup and Dropdown Logic
       const notificationToggle = document.getElementById('notificationToggle');
        const notificationDropdown = document.getElementById('notificationDropdown');
        const notificationList = document.getElementById('notificationList');
        const notificationCount = document.getElementById('notificationCount');
        const refreshButton = document.getElementById('refreshNotifications');
        const refreshSpinner = document.getElementById('refreshSpinner');
        const notificationPopup = document.getElementById('notificationPopup');
        const popupMessage = document.getElementById('popupMessage');
        const popupTimestamp = document.getElementById('popupTimestamp');
        let lastNotificationTimestamp = localStorage.getItem('lastNotificationTimestamp') || '1970-01-01 00:00:00';

        notificationToggle.addEventListener('click', () => {
            notificationDropdown.style.display = notificationDropdown.style.display === 'block' ? 'none' : 'block';
            if (notificationDropdown.style.display === 'block') {
                checkNotifications();
            }
        });

        document.addEventListener('click', (e) => {
            if (!notificationToggle.contains(e.target) && !notificationDropdown.contains(e.target)) {
                notificationDropdown.style.display = 'none';
            }
        });

        refreshButton.addEventListener('click', () => {
            checkNotifications();
        });

        function checkNotifications() {
            refreshSpinner.style.display = 'inline-block';
            $.ajax({
                url: 'notification_handler.php',
                method: 'GET',
                dataType: 'json',
                timeout: 5000,
                success: function(data) {
                    refreshSpinner.style.display = 'none';
                    notificationList.innerHTML = '';
                    if (data.error) {
                        notificationList.innerHTML = `<div class="notification-item text-red-600 dark:text-red-400">Error: ${data.error}</div>`;
                        notificationCount.classList.add('hidden');
                    } else if (data.notifications && data.notifications.length > 0) {
                        data.notifications.forEach(notification => {
                            const div = document.createElement('div');
                            div.className = 'notification-item text-gray-800 dark:text-gray-200';
                            div.innerHTML = `<p>${notification.message}</p><p class="text-sm text-gray-500 dark:text-gray-400">${notification.created_at}</p>`;
                            notificationList.appendChild(div);
                        });
                        notificationCount.textContent = data.notifications.length;
                        notificationCount.classList.remove('hidden');

                        const newNotifications = data.notifications.filter(n => n.created_at > lastNotificationTimestamp);
                        if (newNotifications.length > 0) {
                            const latestNotification = newNotifications[0];
                            popupMessage.textContent = latestNotification.message;
                            popupTimestamp.textContent = latestNotification.created_at;
                            notificationPopup.classList.add('active');
                            setTimeout(() => {
                                notificationPopup.classList.remove('active');
                            }, 10000);
                            lastNotificationTimestamp = latestNotification.created_at;
                            localStorage.setItem('lastNotificationTimestamp', lastNotificationTimestamp);
                        }
                    } else {
                        notificationList.innerHTML = '<div class="notification-item text-gray-800 dark:text-gray-200">No notifications</div>';
                        notificationCount.classList.add('hidden');
                    }
                },
                error: function(xhr, status, error) {
                    refreshSpinner.style.display = 'none';
                    console.error('Error fetching notifications:', error);
                    notificationList.innerHTML = `<div class="notification-item text-red-600 dark:text-red-400">Error loading notifications: ${status === 'timeout' ? 'Request timed out' : 'Server error'}</div>`;
                    notificationCount.classList.add('hidden');
                }
            });
        }

        function closeNotificationPopup() {
            notificationPopup.classList.remove('active');
        }

        setInterval(checkNotifications, 3000);
        checkNotifications();
    </script>
</body>
</html>