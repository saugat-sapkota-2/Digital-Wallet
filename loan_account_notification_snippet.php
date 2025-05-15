<?php
// Inside the approve_loan handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_loan'])) {
    $loan_request_id = $_POST['loan_request_id'];

    try {
        // Fetch loan request details
        $stmt = $pdo->prepare("SELECT lr.*, u.balance, u.is_frozen FROM loan_requests lr JOIN users u ON lr.user_id = u.id WHERE lr.id = ? AND lr.status = 'pending'");
        $stmt->execute([$loan_request_id]);
        $loan_request = $stmt->fetch();

        if ($loan_request) {
            $loan_user_id = $loan_request['user_id'];
            $loan_amount = $loan_request['loan_amount'];

            // Begin transaction
            $pdo->beginTransaction();

            // Update loan request status to 'approved'
            $stmt = $pdo->prepare("UPDATE loan_requests SET status = 'approved', updated_at = NOW() WHERE id = ?");
            $stmt->execute([$loan_request_id]);

            // Add loan amount to user's balance
            $stmt = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
            $stmt->execute([$loan_amount, $loan_user_id]);

            // Verify balance update
            $stmt = $pdo->prepare("SELECT balance FROM users WHERE id = ?");
            $stmt->execute([$loan_user_id]);
            $new_balance = $stmt->fetchColumn();
            if ($new_balance < $loan_amount) {
                throw new Exception("Balance update failed for user ID $loan_user_id.");
            }

            // Record the transaction
            $stmt = $pdo->prepare("INSERT INTO transactions (user_id, amount, type, description, created_at) VALUES (?, ?, 'loan_credit', 'Loan credited to user account', NOW())");
            $stmt->execute([$loan_user_id, $loan_amount]);

            // Send notification to the user
            $message = "Your loan request of रु" . number_format($loan_amount, 2) . " has been approved and credited to your account.";
            $stmt = $pdo->prepare("INSERT INTO notifications (user_id, message, created_at) VALUES (?, ?, NOW())");
            $stmt->execute([$loan_user_id, $message]);

            // Commit transaction
            $pdo->commit();

            $_SESSION['success_message'] = "Loan request #$loan_request_id has been approved, and रु" . number_format($loan_amount, 2) . " has been credited to the user's account.";
        } else {
            $_SESSION['error_message'] = "Loan request not found or already processed.";
        }
    } catch (Exception $e) {
        // Rollback transaction in case of error
        $pdo->rollBack();
        error_log("Error approving loan request #$loan_request_id: " . $e->getMessage());
        $_SESSION['error_message'] = "Error: Failed to approve loan request.";
    }

    header('Location: loan_account.php');
    exit;
}
?>