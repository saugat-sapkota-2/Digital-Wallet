<?php
// At the top of dashboard.php, fetch notifications
$stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
$stmt->execute([$user_id]);
$notifications = $stmt->fetchAll();
?>

<!-- In the HTML body, display notifications in a section -->
<div class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md mt-6 animate-slide-in">
    <h3 class="text-xl font-semibold text-gray-800 dark:text-gray-200 mb-4">Notifications</h3>
    <?php if (empty($notifications)): ?>
        <p class="text-gray-600 dark:text-gray-400">No notifications found.</p>
    <?php else: ?>
        <ul>
            <?php foreach ($notifications as $notification): ?>
                <li class="mb-2 text-gray-700 dark:text-gray-300">
                    <?php echo htmlspecialchars($notification['message']); ?>
                    <span class="text-sm text-gray-500 dark:text-gray-400"><?php echo $notification['created_at']; ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<!-- At the bottom, in the script section, show notifications as Toastify popups -->
<script>
    // Show notifications as Toastify popups
    <?php foreach ($notifications as $index => $notification): ?>
        setTimeout(() => {
            Toastify({
                text: "<?php echo addslashes($notification['message']); ?>",
                duration: 5000,
                gravity: "top",
                position: "right",
                backgroundColor: "linear-gradient(to right, #38a169, #48bb78)",
            }).showToast();
        }, <?php echo $index * 1000; ?>);
    <?php endforeach; ?>
</script>