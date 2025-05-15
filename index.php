<?php
session_start();
require 'db_connect.php';

if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['register'])) {
        $username = filter_input(INPUT_POST, 'username', FILTER_SANITIZE_STRING);
        $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
        $password = password_hash($_POST['password'], PASSWORD_BCRYPT);
        $mpin = filter_input(INPUT_POST, 'mpin', FILTER_SANITIZE_STRING);

        if (strlen($mpin) !== 4 || !ctype_digit($mpin)) {
            echo "<p class='error'>MPIN must be a 4-digit number.</p>";
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO users (username, email, password, mpin) VALUES (?, ?, ?, ?)");
                $stmt->execute([$username, $email, $password, $mpin]);
                echo "<p class='success'>Registration successful! Please login.</p>";
            } catch (PDOException $e) {
                echo "<p class='error'>Registration failed: " . $e->getMessage() . "</p>";
            }
        }
    } elseif (isset($_POST['login'])) {
        $username = filter_input(INPUT_POST, 'username', FILTER_SANITIZE_STRING);
        $password = $_POST['password'];

        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        // Inside your login handling logic in index.php
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['is_admin'] = $user['is_admin'];
            $_SESSION['login_success'] = true; // Set the flag
            header('Location: dashboard.php');
            exit;
        } else {
            echo "<p class='error'>Invalid username or password.</p>";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Digital Wallet</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; }
        input { width: 100%; padding: 8px; box-sizing: border-box; }
        button { padding: 10px 20px; background: #007bff; color: white; border: none; cursor: pointer; }
        button:hover { background: #0056b3; }
        .success { color: green; }
        .error { color: red; }
        .tab { display: none; }
        .tab.active { display: block; }
        .tab-button { padding: 10px; cursor: pointer; background: #f0f0f0; margin-right: 5px; }
        .tab-button.active { background: #007bff; color: white; }
    </style>
</head>
<body>
    <h2>Digital Wallet</h2>
    <div>
        <span class="tab-button active" onclick="showTab('login')">Login</span>
        <span class="tab-button" onclick="showTab('register')">Register</span>
    </div>

    <div id="login" class="tab active">
        <h3>Login</h3>
        <form method="POST">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" name="username" required>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" name="password" required>
            </div>
            <button type="submit" name="login">Login</button>
        </form>
    </div>

    <div id="register" class="tab">
        <h3>Register</h3>
        <form method="POST">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" name="username" required>
            </div>
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" name="email" required>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" name="password" required>
            </div>
            <div class="form-group">
                <label for="mpin">Set MPIN (4-digit number)</label>
                <input type="text" name="mpin" maxlength="4" pattern="\d{4}" required>
            </div>
            <button type="submit" name="register">Register</button>
        </form>
    </div>

    <script>
        function showTab(tabId) {
            document.querySelectorAll('.tab').forEach(tab => tab.classList.remove('active'));
            document.querySelectorAll('.tab-button').forEach(btn => btn.classList.remove('active'));
            document.getElementById(tabId).classList.add('active');
            document.querySelector(`.tab-button[onclick="showTab('${tabId}')"]`).classList.add('active');
        }
    </script>
</body>
</html>
