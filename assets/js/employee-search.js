// Universal Employee Search Implementation
document.addEventListener('DOMContentLoaded', function() {
    initializeEmployeeSearch();
});

function initializeEmployeeSearch() {
    // Find all employee select dropdowns
    const employeeSelects = document.querySelectorAll('select[name="employee_id"], .employee-search');
    
    employeeSelects.forEach(function(select) {
        if (!select.classList.contains('search-initialized')) {
            convertToSearchableDropdown(select);
            select.classList.add('search-initialized');
        }
    });
}

function convertToSearchableDropdown(selectElement) {
    // Create wrapper div
    const wrapper = document.createElement('div');
    wrapper.className = 'employee-search-wrapper position-relative';
    
    // Create search input
    const searchInput = document.createElement('input');
    searchInput.type = 'text';
    searchInput.className = 'form-control employee-search-input';
    searchInput.placeholder = 'Search employee by name or position...';
    searchInput.autocomplete = 'off';
    
    // Create dropdown list
    const dropdownList = document.createElement('div');
    dropdownList.className = 'employee-search-dropdown';
    dropdownList.style.display = 'none';
    
    // Store original options
    const originalOptions = Array.from(selectElement.options).filter(option => option.value !== '');
    
    // Insert wrapper and hide original select
    selectElement.parentNode.insertBefore(wrapper, selectElement);
    wrapper.appendChild(searchInput);
    wrapper.appendChild(dropdownList);
    wrapper.appendChild(selectElement);
    selectElement.style.display = 'none';
    
    // Search functionality
    searchInput.addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase().trim();
        
        if (searchTerm.length === 0) {
            dropdownList.style.display = 'none';
            return;
        }
        
        // Filter options
        const filteredOptions = originalOptions.filter(option => {
            const text = option.textContent.toLowerCase();
            return text.includes(searchTerm);
        });
        
        // Clear and populate dropdown
        dropdownList.innerHTML = '';
        
        if (filteredOptions.length > 0) {
            filteredOptions.forEach(option => {
                const item = document.createElement('div');
                item.className = 'employee-search-item';
                item.textContent = option.textContent;
                item.dataset.value = option.value;
                
                item.addEventListener('click', function() {
                    selectEmployee(option.value, option.textContent);
                });
                
                dropdownList.appendChild(item);
            });
            
            dropdownList.style.display = 'block';
        } else {
            const noResults = document.createElement('div');
            noResults.className = 'employee-search-item text-muted';
            noResults.textContent = 'No employees found';
            dropdownList.appendChild(noResults);
            dropdownList.style.display = 'block';
        }
    });
    
    // Select employee function
    function selectEmployee(value, text) {
        selectElement.value = value;
        searchInput.value = text;
        dropdownList.style.display = 'none';
        
        // Trigger change event
        const event = new Event('change', { bubbles: true });
        selectElement.dispatchEvent(event);
    }
    
    // Handle keyboard navigation
    searchInput.addEventListener('keydown', function(e) {
        const items = dropdownList.querySelectorAll('.employee-search-item:not(.text-muted)');
        let currentIndex = -1;
        
        // Find currently highlighted item
        items.forEach((item, index) => {
            if (item.classList.contains('highlighted')) {
                currentIndex = index;
            }
        });
        
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            currentIndex = Math.min(currentIndex + 1, items.length - 1);
            highlightItem(items, currentIndex);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            currentIndex = Math.max(currentIndex - 1, 0);
            highlightItem(items, currentIndex);
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (currentIndex >= 0 && items[currentIndex]) {
                items[currentIndex].click();
            }
        } else if (e.key === 'Escape') {
            dropdownList.style.display = 'none';
        }
    });
    
    function highlightItem(items, index) {
        items.forEach(item => item.classList.remove('highlighted'));
        if (items[index]) {
            items[index].classList.add('highlighted');
        }
    }
    
    // Hide dropdown when clicking outside
    document.addEventListener('click', function(e) {
        if (!wrapper.contains(e.target)) {
            dropdownList.style.display = 'none';
        }
    });
    
    // Show dropdown when input is focused and has value
    searchInput.addEventListener('focus', function() {
        if (this.value.trim().length > 0) {
            dropdownList.style.display = 'block';
        }
    });
    
    // Clear search when input is cleared
    searchInput.addEventListener('keyup', function() {
        if (this.value.trim() === '') {
            selectElement.value = '';
            dropdownList.style.display = 'none';
        }
    });
}

// Function to refresh search dropdowns (useful for dynamically added content)
function refreshEmployeeSearch() {
    initializeEmployeeSearch();
}
