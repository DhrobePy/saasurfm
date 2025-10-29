<!-- admin/widgets/widget_welcome.php -->
<div class="bg-white rounded-lg shadow-md border border-gray-200 p-6 h-full">
    <h3 class="text-xl font-bold text-gray-800 mb-2">Welcome, <?php echo htmlspecialchars($currentUser['display_name']); ?>!</h3>
    <p class="text-gray-600">
        This is your central dashboard. You can add, remove, and reorder widgets
        by visiting your <a href="settings.php" class="text-primary-600 hover:underline font-medium">Dashboard Settings</a> page.
    </p>
     <p class="text-gray-600 mt-2">
        Your role: <span class="font-semibold text-primary-700"><?php echo htmlspecialchars($currentUser['role']); ?></span>
    </p>
</div>
