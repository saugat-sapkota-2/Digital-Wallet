<?php
session_start();

// Set logout success flag
$_SESSION['logout_success'] = true;

// Clear session data
session_unset();
session_destroy();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logging Out - Digital Wallet</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js"></script>
    <style>
        body {
            transition: background-color 0.3s, color 0.3s;
        }
        .dark body {
            background-color: #1a202c;
            color: #e2e8f0;
        }
        .logout-modal {
            display: flex;
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
        .animate-bounce-in {
            animation: bounceIn 1s ease-out;
        }
        .animate-fade-out {
            animation: fadeOut 1s ease-in forwards;
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
        .spinner {
            width: 24px;
            height: 24px;
            border: 3px solid #ffffff;
            border-top: 3px solid transparent;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body class="bg-gray-100 dark:bg-gray-900 min-h-screen">
    <!-- Logout Success Modal -->
    <div id="logoutModal" class="logout-modal">
        <div class="bg-white dark:bg-gray-800 p-8 rounded-lg shadow-lg text-center animate-bounce-in">
            <h2 class="text-3xl font-bold text-gray-800 dark:text-gray-200 animate-pulse">See You Soon!</h2>
            <p class="text-gray-600 dark:text-gray-400 mt-2">You’ve successfully logged out.</p>
            <div class="mt-4 flex justify-center">
                <span class="spinner"></span>
            </div>
            <p class="text-gray-500 dark:text-gray-400 mt-2 text-sm">Redirecting to login...</p>
        </div>
    </div>

    <script>
        // Apply saved theme
        if (localStorage.getItem('theme') === 'dark') {
            document.documentElement.classList.add('dark');
        }

        // Logout Animation
        confetti({
            particleCount: 100,
            spread: 70,
            origin: { y: 0.6 },
            colors: ['#f87171', '#fb923c', '#facc15']
        });

        // Redirect after animation
        setTimeout(() => {
            document.getElementById('logoutModal').classList.add('animate-fade-out');
            setTimeout(() => {
                window.location.href = 'index.php';
            }, 1000);
        }, 3000);
    </script>
</body>
</html>