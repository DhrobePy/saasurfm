/**
 * Main JavaScript file for Fajr School Finance Management System
 */

// Global app object
window.FajrApp = {
    // Configuration
    config: {
        currency: '₹',
        dateFormat: 'dd/mm/yyyy',
        apiUrl: '/api',
        csrfToken: document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
    },
    
    // Utility functions
    utils: {
        // Format currency
        formatCurrency: function(amount) {
            return FajrApp.config.currency + parseFloat(amount).toLocaleString('en-IN', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        },
        
        // Format date
        formatDate: function(date, format = 'dd/mm/yyyy') {
            if (!date) return '';
            const d = new Date(date);
            const day = String(d.getDate()).padStart(2, '0');
            const month = String(d.getMonth() + 1).padStart(2, '0');
            const year = d.getFullYear();
            
            switch (format) {
                case 'dd/mm/yyyy':
                    return `${day}/${month}/${year}`;
                case 'yyyy-mm-dd':
                    return `${year}-${month}-${day}`;
                case 'dd MMM yyyy':
                    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
                                  'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                    return `${day} ${months[d.getMonth()]} ${year}`;
                default:
                    return d.toLocaleDateString();
            }
        },
        
        // Debounce function
        debounce: function(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        },
        
        // Show loading state
        showLoading: function(element) {
            element.classList.add('loading');
            element.disabled = true;
        },
        
        // Hide loading state
        hideLoading: function(element) {
            element.classList.remove('loading');
            element.disabled = false;
        },
        
        // Show notification
        showNotification: function(message, type = 'info', duration = 5000) {
            const notification = document.createElement('div');
            notification.className = `fixed top-4 right-4 z-50 max-w-sm w-full bg-white shadow-lg rounded-lg pointer-events-auto ring-1 ring-black ring-opacity-5 overflow-hidden transform transition-all duration-300 translate-x-full`;
            
            const iconClass = {
                success: 'fas fa-check-circle text-green-400',
                error: 'fas fa-exclamation-circle text-red-400',
                warning: 'fas fa-exclamation-triangle text-yellow-400',
                info: 'fas fa-info-circle text-blue-400'
            }[type] || 'fas fa-info-circle text-blue-400';
            
            notification.innerHTML = `
                <div class="p-4">
                    <div class="flex items-start">
                        <div class="flex-shrink-0">
                            <i class="${iconClass}"></i>
                        </div>
                        <div class="ml-3 w-0 flex-1 pt-0.5">
                            <p class="text-sm font-medium text-gray-900">${message}</p>
                        </div>
                        <div class="ml-4 flex-shrink-0 flex">
                            <button onclick="this.parentElement.parentElement.parentElement.parentElement.remove()" 
                                    class="bg-white rounded-md inline-flex text-gray-400 hover:text-gray-500 focus:outline-none">
                                <i class="fas fa-times text-sm"></i>
                            </button>
                        </div>
                    </div>
                </div>
            `;
            
            document.body.appendChild(notification);
            
            // Animate in
            setTimeout(() => {
                notification.classList.remove('translate-x-full');
            }, 100);
            
            // Auto remove
            if (duration > 0) {
                setTimeout(() => {
                    notification.classList.add('translate-x-full');
                    setTimeout(() => notification.remove(), 300);
                }, duration);
            }
        },
        
        // Confirm dialog
        confirm: function(message, callback) {
            const modal = document.createElement('div');
            modal.className = 'fixed inset-0 z-50 overflow-y-auto';
            modal.innerHTML = `
                <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                    <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity"></div>
                    <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
                        <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                            <div class="sm:flex sm:items-start">
                                <div class="mx-auto flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full bg-red-100 sm:mx-0 sm:h-10 sm:w-10">
                                    <i class="fas fa-exclamation-triangle text-red-600"></i>
                                </div>
                                <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left">
                                    <h3 class="text-lg leading-6 font-medium text-gray-900">Confirm Action</h3>
                                    <div class="mt-2">
                                        <p class="text-sm text-gray-500">${message}</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                            <button type="button" class="confirm-yes w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-red-600 text-base font-medium text-white hover:bg-red-700 focus:outline-none sm:ml-3 sm:w-auto sm:text-sm">
                                Yes, Continue
                            </button>
                            <button type="button" class="confirm-no mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm">
                                Cancel
                            </button>
                        </div>
                    </div>
                </div>
            `;
            
            document.body.appendChild(modal);
            
            modal.querySelector('.confirm-yes').addEventListener('click', () => {
                modal.remove();
                callback(true);
            });
            
            modal.querySelector('.confirm-no').addEventListener('click', () => {
                modal.remove();
                callback(false);
            });
            
            // Close on backdrop click
            modal.addEventListener('click', (e) => {
                if (e.target === modal || e.target.classList.contains('bg-gray-500')) {
                    modal.remove();
                    callback(false);
                }
            });
        }
    },
    
    // Form handling
    forms: {
        // Initialize form validation
        init: function() {
            document.querySelectorAll('form[data-validate]').forEach(form => {
                form.addEventListener('submit', FajrApp.forms.handleSubmit);
            });
            
            // Real-time validation
            document.querySelectorAll('input[data-validate], select[data-validate], textarea[data-validate]').forEach(field => {
                field.addEventListener('blur', FajrApp.forms.validateField);
                field.addEventListener('input', FajrApp.utils.debounce(() => {
                    FajrApp.forms.validateField({ target: field });
                }, 500));
            });
        },
        
        // Handle form submission
        handleSubmit: function(e) {
            e.preventDefault();
            const form = e.target;
            
            if (FajrApp.forms.validateForm(form)) {
                FajrApp.utils.showLoading(form.querySelector('button[type="submit"]'));
                
                // If form has data-ajax attribute, submit via AJAX
                if (form.hasAttribute('data-ajax')) {
                    FajrApp.forms.submitAjax(form);
                } else {
                    form.submit();
                }
            }
        },
        
        // Validate entire form
        validateForm: function(form) {
            let isValid = true;
            const fields = form.querySelectorAll('input[data-validate], select[data-validate], textarea[data-validate]');
            
            fields.forEach(field => {
                if (!FajrApp.forms.validateField({ target: field })) {
                    isValid = false;
                }
            });
            
            return isValid;
        },
        
        // Validate individual field
        validateField: function(e) {
            const field = e.target;
            const rules = field.getAttribute('data-validate').split('|');
            const value = field.value.trim();
            let isValid = true;
            let errorMessage = '';
            
            for (const rule of rules) {
                const [ruleName, ruleValue] = rule.split(':');
                
                switch (ruleName) {
                    case 'required':
                        if (!value) {
                            isValid = false;
                            errorMessage = 'This field is required';
                        }
                        break;
                        
                    case 'email':
                        if (value && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
                            isValid = false;
                            errorMessage = 'Please enter a valid email address';
                        }
                        break;
                        
                    case 'min':
                        if (value && value.length < parseInt(ruleValue)) {
                            isValid = false;
                            errorMessage = `Minimum ${ruleValue} characters required`;
                        }
                        break;
                        
                    case 'max':
                        if (value && value.length > parseInt(ruleValue)) {
                            isValid = false;
                            errorMessage = `Maximum ${ruleValue} characters allowed`;
                        }
                        break;
                        
                    case 'numeric':
                        if (value && !/^\d+(\.\d+)?$/.test(value)) {
                            isValid = false;
                            errorMessage = 'Please enter a valid number';
                        }
                        break;
                        
                    case 'phone':
                        if (value && !/^[\+]?[0-9\-\s\(\)]{10,15}$/.test(value)) {
                            isValid = false;
                            errorMessage = 'Please enter a valid phone number';
                        }
                        break;
                }
                
                if (!isValid) break;
            }
            
            FajrApp.forms.showFieldError(field, isValid ? '' : errorMessage);
            return isValid;
        },
        
        // Show field error
        showFieldError: function(field, message) {
            const errorElement = field.parentElement.querySelector('.field-error');
            
            if (message) {
                field.classList.add('input-error');
                field.classList.remove('input-success');
                
                if (errorElement) {
                    errorElement.textContent = message;
                } else {
                    const error = document.createElement('div');
                    error.className = 'field-error text-red-500 text-sm mt-1';
                    error.textContent = message;
                    field.parentElement.appendChild(error);
                }
            } else {
                field.classList.remove('input-error');
                field.classList.add('input-success');
                
                if (errorElement) {
                    errorElement.remove();
                }
            }
        },
        
        // Submit form via AJAX
        submitAjax: function(form) {
            const formData = new FormData(form);
            const submitButton = form.querySelector('button[type="submit"]');
            
            fetch(form.action, {
                method: form.method || 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': FajrApp.config.csrfToken
                }
            })
            .then(response => response.json())
            .then(data => {
                FajrApp.utils.hideLoading(submitButton);
                
                if (data.success) {
                    FajrApp.utils.showNotification(data.message || 'Operation completed successfully', 'success');
                    
                    if (data.redirect) {
                        setTimeout(() => {
                            window.location.href = data.redirect;
                        }, 1000);
                    } else if (form.hasAttribute('data-reset')) {
                        form.reset();
                    }
                } else {
                    FajrApp.utils.showNotification(data.message || 'An error occurred', 'error');
                    
                    // Show field-specific errors
                    if (data.errors) {
                        Object.keys(data.errors).forEach(fieldName => {
                            const field = form.querySelector(`[name="${fieldName}"]`);
                            if (field) {
                                FajrApp.forms.showFieldError(field, data.errors[fieldName]);
                            }
                        });
                    }
                }
            })
            .catch(error => {
                FajrApp.utils.hideLoading(submitButton);
                FajrApp.utils.showNotification('Network error occurred', 'error');
                console.error('Form submission error:', error);
            });
        }
    },
    
    // Data tables
    tables: {
        init: function() {
            // Initialize sortable tables
            document.querySelectorAll('table[data-sortable]').forEach(table => {
                FajrApp.tables.makeSortable(table);
            });
            
            // Initialize searchable tables
            document.querySelectorAll('input[data-table-search]').forEach(input => {
                const tableId = input.getAttribute('data-table-search');
                const table = document.getElementById(tableId);
                if (table) {
                    input.addEventListener('input', FajrApp.utils.debounce(() => {
                        FajrApp.tables.search(table, input.value);
                    }, 300));
                }
            });
        },
        
        makeSortable: function(table) {
            const headers = table.querySelectorAll('th[data-sort]');
            
            headers.forEach(header => {
                header.style.cursor = 'pointer';
                header.innerHTML += ' <i class="fas fa-sort text-gray-400 ml-1"></i>';
                
                header.addEventListener('click', () => {
                    const column = header.getAttribute('data-sort');
                    const currentSort = table.getAttribute('data-sort-column');
                    const currentOrder = table.getAttribute('data-sort-order') || 'asc';
                    
                    let newOrder = 'asc';
                    if (currentSort === column && currentOrder === 'asc') {
                        newOrder = 'desc';
                    }
                    
                    FajrApp.tables.sort(table, column, newOrder);
                });
            });
        },
        
        sort: function(table, column, order) {
            const tbody = table.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));
            
            rows.sort((a, b) => {
                const aValue = a.querySelector(`[data-sort-value="${column}"]`)?.textContent.trim() || 
                              a.children[parseInt(column)]?.textContent.trim() || '';
                const bValue = b.querySelector(`[data-sort-value="${column}"]`)?.textContent.trim() || 
                              b.children[parseInt(column)]?.textContent.trim() || '';
                
                // Try to parse as numbers
                const aNum = parseFloat(aValue.replace(/[^\d.-]/g, ''));
                const bNum = parseFloat(bValue.replace(/[^\d.-]/g, ''));
                
                if (!isNaN(aNum) && !isNaN(bNum)) {
                    return order === 'asc' ? aNum - bNum : bNum - aNum;
                }
                
                // String comparison
                return order === 'asc' ? 
                    aValue.localeCompare(bValue) : 
                    bValue.localeCompare(aValue);
            });
            
            // Update table
            rows.forEach(row => tbody.appendChild(row));
            
            // Update sort indicators
            table.querySelectorAll('th i').forEach(icon => {
                icon.className = 'fas fa-sort text-gray-400 ml-1';
            });
            
            const activeHeader = table.querySelector(`th[data-sort="${column}"] i`);
            if (activeHeader) {
                activeHeader.className = `fas fa-sort-${order === 'asc' ? 'up' : 'down'} text-blue-500 ml-1`;
            }
            
            table.setAttribute('data-sort-column', column);
            table.setAttribute('data-sort-order', order);
        },
        
        search: function(table, query) {
            const tbody = table.querySelector('tbody');
            const rows = tbody.querySelectorAll('tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                const matches = text.includes(query.toLowerCase());
                row.style.display = matches ? '' : 'none';
            });
        }
    },
    
    // Charts
    charts: {
        colors: {
            primary: '#0ea5e9',
            success: '#22c55e',
            warning: '#f59e0b',
            danger: '#ef4444',
            info: '#06b6d4',
            secondary: '#6b7280'
        },
        
        createPieChart: function(canvas, data, options = {}) {
            return new Chart(canvas, {
                type: 'pie',
                data: data,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    },
                    ...options
                }
            });
        },
        
        createBarChart: function(canvas, data, options = {}) {
            return new Chart(canvas, {
                type: 'bar',
                data: data,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    },
                    ...options
                }
            });
        },
        
        createLineChart: function(canvas, data, options = {}) {
            return new Chart(canvas, {
                type: 'line',
                data: data,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    },
                    ...options
                }
            });
        }
    }
};

// Initialize app when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    FajrApp.forms.init();
    FajrApp.tables.init();
    
    // Initialize tooltips
    document.querySelectorAll('[data-tooltip]').forEach(element => {
        element.addEventListener('mouseenter', function() {
            const tooltip = document.createElement('div');
            tooltip.className = 'tooltip-popup';
            tooltip.textContent = this.getAttribute('data-tooltip');
            document.body.appendChild(tooltip);
            
            const rect = this.getBoundingClientRect();
            tooltip.style.left = rect.left + (rect.width / 2) - (tooltip.offsetWidth / 2) + 'px';
            tooltip.style.top = rect.top - tooltip.offsetHeight - 5 + 'px';
        });
        
        element.addEventListener('mouseleave', function() {
            document.querySelectorAll('.tooltip-popup').forEach(tooltip => tooltip.remove());
        });
    });
    
    // Initialize file upload areas
    document.querySelectorAll('.file-upload-area').forEach(area => {
        const input = area.querySelector('input[type="file"]');
        
        area.addEventListener('click', () => input.click());
        
        area.addEventListener('dragover', (e) => {
            e.preventDefault();
            area.classList.add('dragover');
        });
        
        area.addEventListener('dragleave', () => {
            area.classList.remove('dragover');
        });
        
        area.addEventListener('drop', (e) => {
            e.preventDefault();
            area.classList.remove('dragover');
            input.files = e.dataTransfer.files;
            input.dispatchEvent(new Event('change'));
        });
    });
    
    // Auto-hide alerts
    setTimeout(() => {
        document.querySelectorAll('.alert[data-auto-hide]').forEach(alert => {
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 300);
        });
    }, 5000);
});

// Global functions for inline use
window.confirmDelete = function(message, callback) {
    FajrApp.utils.confirm(message || 'Are you sure you want to delete this item?', callback);
};

window.showNotification = FajrApp.utils.showNotification;
window.formatCurrency = FajrApp.utils.formatCurrency;
