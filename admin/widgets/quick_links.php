<!-- admin/widgets/widget_quick_links.php -->
<div class="bg-white rounded-lg shadow-md border border-gray-200 p-6 h-full">
    <h3 class="text-xl font-bold text-gray-800 mb-4">Quick Links</h3>
    <div class="grid grid-cols-2 gap-3">
        <a href="<?php echo url('pos/index.php'); ?>" class="block p-3 bg-primary-50 text-primary-700 rounded-lg text-center font-medium hover:bg-primary-100">
            <i class="fas fa-cash-register text-2xl mb-1"></i>
            <span class="block text-sm">POS Terminal</span>
        </a>
        <a href="<?php echo url('sales/create_order.php'); ?>" class="block p-3 bg-blue-50 text-blue-700 rounded-lg text-center font-medium hover:bg-blue-100">
            <i class="fas fa-file-invoice text-2xl mb-1"></i>
            <span class="block text-sm">New Credit Order</span>
        </a>
         <a href="<?php echo url('accounts/new_transaction.php'); ?>" class="block p-3 bg-green-50 text-green-700 rounded-lg text-center font-medium hover:bg-green-100">
            <i class="fas fa-plus-circle text-2xl mb-1"></i>
            <span class="block text-sm">New Transaction</span>
        </a>
         <a href="<?php echo url('admin/employees.php'); ?>" class="block p-3 bg-indigo-50 text-indigo-700 rounded-lg text-center font-medium hover:bg-indigo-100">
            <i class="fas fa-users text-2xl mb-1"></i>
            <span class="block text-sm">Employees</span>
        </a>
    </div>
</div>
