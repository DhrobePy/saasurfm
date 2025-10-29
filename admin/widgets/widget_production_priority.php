<!-- admin/widgets/widget_production_priority.php -->
<!-- This widget is interactive and requires Alpine.js -->
<div class="bg-white rounded-lg shadow-md border border-gray-200 h-full flex flex-col" x-data="productionPriorityWidget()">
    <div class="p-5 border-b border-gray-200">
        <h3 class="text-xl font-bold text-gray-800">Production Priority</h3>
        <p class="text-sm text-gray-500 mt-1">Drag <i class="fas fa-grip-vertical"></i> to set priority. Highest priority is at the top.</p>
    </div>
    
    <!-- Loading / Error State -->
    <template x-if="isLoading">
        <div class="p-6 text-center text-gray-500">
            <i class="fas fa-spinner fa-spin text-2xl"></i>
            <p class="mt-2">Loading orders...</p>
        </div>
    </template>
    <template x-if="error">
        <div class="p-6 text-red-600 bg-red-50">
            <p class="font-bold">Error loading orders:</p>
            <p x-text="error"></p>
        </div>
    </template>

    <!-- Order List -->
    <div class="flex-grow overflow-y-auto" x-show="!isLoading && !error">
        <ul x-ref="sortableList" class="divide-y divide-gray-200">
            <template x-for="order in orders" :key="order.id">
                <li class="flex items-center p-4" :data-id="order.id">
                    <i class="fas fa-grip-vertical text-gray-400 mr-4 cursor-grab" x-sortable-handle></i>
                    <div class="flex-grow">
                        <p class="font-semibold text-gray-900" x-text="order.order_number"></p>
                        <p class="text-sm text-gray-600" x-text="order.customer_name"></p>
                        <p class="text-xs text-gray-500" x-text="`Required By: ${order.required_date}`"></p>
                    </div>
                    <span class="px-2 py-0.5 rounded-full text-xs font-medium"
                          :class="{
                              'bg-red-100 text-red-800': order.priority === 'urgent',
                              'bg-orange-100 text-orange-800': order.priority === 'high',
                              'bg-blue-100 text-blue-800': order.priority === 'normal',
                              'bg-gray-100 text-gray-800': order.priority === 'low'
                          }"
                          x-text="order.priority">
                    </span>
                </li>
            </template>
        </ul>
        <template x-if="orders.length === 0">
             <p class="p-6 text-center text-gray-500">No orders currently in production or approved.</p>
        </template>
    </div>
    <div class="p-3 bg-gray-50 border-t text-xs text-gray-500" x-show="!isLoading">
        Changes are saved automatically on drag.
    </div>
</div>

<!-- This widget needs its own script, add this at the bottom of admin/index.php or in a global JS file -->
<script>
    if (typeof productionPriorityWidget === 'undefined') {
        function productionPriorityWidget() {
            return {
                orders: [],
                isLoading: true,
                error: '',
                csrfToken: '<?php echo $_SESSION['csrf_token']; ?>',
                
                init() {
                    this.fetchOrders();
                    // Initialize Alpine.js Sortable on the list
                    Alpine.plugin(Sortable).init(this.$refs.sortableList, this.orders, {
                        onEnd: (evt) => {
                            this.savePriority();
                        }
                    });
                },
                
                async fetchOrders() {
                    this.isLoading = true;
                    this.error = '';
                    try {
                        const response = await fetch('ajax_handler.php?action=get_production_orders', {
                            headers: { 'Accept': 'application/json' }
                        });
                        const result = await response.json();
                        if (result.success) {
                            this.orders = result.orders;
                        } else {
                            throw new Error(result.error);
                        }
                    } catch (e) {
                        this.error = e.message || 'Failed to fetch production orders.';
                    } finally {
                        this.isLoading = false;
                    }
                },
                
                async savePriority() {
                    // 'this.orders' is now sorted in the new order
                    // We send the new list of IDs in their new order
                    const orderedIds = this.orders.map(order => order.id);
                    
                    try {
                         const response = await fetch('ajax_handler.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-Token': this.csrfToken
                            },
                            body: JSON.stringify({
                                action: 'update_order_priority',
                                order_ids: orderedIds // Send the array of IDs in the new order
                            })
                        });
                        const result = await response.json();
                        if (!result.success) {
                            throw new Error(result.error);
                        }
                        console.log('Priority saved!');
                        // Optional: Show a success toast
                    } catch (e) {
                         console.error('Failed to save priority:', e);
                         alert('Error saving priority: ' + e.message);
                         // Re-fetch to revert to old order
                         this.fetchOrders();
                    }
                }
            }
        }
        // Register the component if it's not already
        document.addEventListener('alpine:init', () => {
             Alpine.data('productionPriorityWidget', productionPriorityWidget);
        });
    }
</script>
