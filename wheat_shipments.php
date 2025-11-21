<?php
/**
 * Wheat Shipment Dashboard
 * Integrated with saasurfm platform
 * Uses web scraping - NO API keys required
 */

// Include saasurfm core files
require_once 'config.php';
require_once 'helpers.php';
require_once 'init.php';

// Check authentication
if (!$user->isLoggedIn()) {
    redirect('login.php');
}

// Include header
$page_title = 'Global Wheat Shipments';
$page_description = 'Track wheat shipments from global sources';
require_once 'header.php';

// Include scraper class
require_once 'includes/WheatShipmentScraper.php';

// Initialize scraper
$scraper = new WheatShipmentScraper($db, $user->data()->branch_id, $user->data()->id);

// Handle AJAX requests
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'scrape_global_data':
            $result = $scraper->scrapeGlobalWheatShipments();
            echo json_encode($result);
            exit;
            
        case 'get_shipments':
            $filters = [
                'status' => $_POST['status'] ?? '',
                'search' => $_POST['search'] ?? '',
                'branch_id' => $user->data()->branch_id
            ];
            $page = intval($_POST['page'] ?? 1);
            $result = $scraper->getShipments($filters, $page);
            echo json_encode($result);
            exit;
            
        case 'get_stats':
            $result = $scraper->getDashboardStats();
            echo json_encode($result);
            exit;
    }
}

// Get dashboard stats
$stats_result = $scraper->getDashboardStats();
$stats = $stats_result['success'] ? $stats_result['data'] : [];
?>

<style>
.wheat-dashboard {
    padding: 20px;
}

.stats-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.stat-card {
    background: white;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    border-left: 4px solid #4CAF50;
}

.stat-card.blue { border-left-color: #2196F3; }
.stat-card.orange { border-left-color: #FF9800; }
.stat-card.green { border-left-color: #4CAF50; }
.stat-card.purple { border-left-color: #9C27B0; }

.stat-card h3 {
    margin: 0 0 10px 0;
    color: #666;
    font-size: 14px;
    font-weight: normal;
}

.stat-card .value {
    font-size: 32px;
    font-weight: bold;
    color: #333;
}

.stat-card .sub-value {
    font-size: 14px;
    color: #999;
    margin-top: 5px;
}

.action-buttons {
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
}

.btn-scrape {
    background: #4CAF50;
    color: white;
    padding: 12px 24px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.btn-scrape:hover {
    background: #45a049;
}

.btn-scrape:disabled {
    background: #ccc;
    cursor: not-allowed;
}

.spinner {
    display: inline-block;
    width: 16px;
    height: 16px;
    border: 3px solid rgba(255,255,255,.3);
    border-radius: 50%;
    border-top-color: #fff;
    animation: spin 1s ease-in-out infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

.tabs {
    display: flex;
    border-bottom: 2px solid #ddd;
    margin-bottom: 20px;
}

.tab {
    padding: 12px 24px;
    cursor: pointer;
    border: none;
    background: none;
    color: #666;
    font-size: 16px;
    border-bottom: 3px solid transparent;
    transition: all 0.3s;
}

.tab.active {
    color: #4CAF50;
    border-bottom-color: #4CAF50;
}

.tab:hover {
    background: #f5f5f5;
}

.tab-content {
    display: none;
}

.tab-content.active {
    display: block;
}

.shipment-table {
    width: 100%;
    background: white;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.shipment-table table {
    width: 100%;
    border-collapse: collapse;
}

.shipment-table th {
    background: #f5f5f5;
    padding: 12px;
    text-align: left;
    font-weight: 600;
    border-bottom: 2px solid #ddd;
}

.shipment-table td {
    padding: 12px;
    border-bottom: 1px solid #eee;
}

.shipment-table tr:hover {
    background: #f9f9f9;
}

.status-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
}

.status-badge.in-transit { background: #E3F2FD; color: #1976D2; }
.status-badge.port-arrival { background: #FFF3E0; color: #F57C00; }
.status-badge.delivered { background: #E8F5E9; color: #388E3C; }
.status-badge.planning { background: #F3E5F5; color: #7B1FA2; }

.alert-box {
    padding: 15px;
    border-radius: 4px;
    margin-bottom: 20px;
}

.alert-box.info {
    background: #E3F2FD;
    color: #1976D2;
    border-left: 4px solid #2196F3;
}

.alert-box.success {
    background: #E8F5E9;
    color: #388E3C;
    border-left: 4px solid #4CAF50;
}

.alert-box.warning {
    background: #FFF3E0;
    color: #F57C00;
    border-left: 4px solid #FF9800;
}

.alert-box.error {
    background: #FFEBEE;
    color: #C62828;
    border-left: 4px solid #F44336;
}

.news-item {
    background: white;
    padding: 15px;
    margin-bottom: 15px;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.news-item h4 {
    margin: 0 0 8px 0;
    color: #333;
}

.news-item .meta {
    font-size: 12px;
    color: #999;
    margin-bottom: 8px;
}

.news-item p {
    margin: 0;
    color: #666;
    font-size: 14px;
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #999;
}

.empty-state i {
    font-size: 64px;
    margin-bottom: 20px;
    opacity: 0.3;
}

.search-box {
    margin-bottom: 20px;
}

.search-box input {
    width: 100%;
    max-width: 400px;
    padding: 10px 15px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
}

.filter-select {
    padding: 10px 15px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
    margin-left: 10px;
}
</style>

<div class="wheat-dashboard">
    <!-- Header -->
    <div class="page-header">
        <h1>🌾 Global Wheat Shipment Tracking</h1>
        <p>Real-time tracking of wheat shipments from global sources using web scraping</p>
    </div>

    <!-- Alert Info -->
    <div class="alert-box info">
        <strong>ℹ️ No API Keys Required!</strong> This system scrapes public websites to gather wheat shipment data. 
        Data sources include: VesselFinder, FleetMon, GrainCentral, USDA, FAO, and IGC.
    </div>

    <!-- Stats Cards -->
    <div class="stats-cards">
        <div class="stat-card blue">
            <h3>Total Shipments</h3>
            <div class="value"><?php echo $stats['total_shipments'] ?? 0; ?></div>
            <div class="sub-value">All time</div>
        </div>
        
        <div class="stat-card orange">
            <h3>In Transit</h3>
            <div class="value"><?php echo $stats['in_transit'] ?? 0; ?></div>
            <div class="sub-value">Currently shipping</div>
        </div>
        
        <div class="stat-card green">
            <h3>Arrived</h3>
            <div class="value"><?php echo $stats['arrived'] ?? 0; ?></div>
            <div class="sub-value">At ports / delivered</div>
        </div>
        
        <div class="stat-card purple">
            <h3>Total Quantity</h3>
            <div class="value"><?php echo number_format($stats['total_quantity_tons'] ?? 0); ?></div>
            <div class="sub-value">Metric Tons</div>
        </div>
    </div>

    <!-- Action Buttons -->
    <div class="action-buttons">
        <button class="btn-scrape" onclick="scrapeGlobalData()">
            <span class="btn-text">🌐 Fetch Global Data</span>
            <span class="spinner" style="display: none;"></span>
        </button>
        
        <button class="btn-scrape" style="background: #2196F3;" onclick="refreshStats()">
            🔄 Refresh Stats
        </button>
    </div>

    <!-- Tabs -->
    <div class="tabs">
        <button class="tab active" onclick="switchTab('shipments')">
            📦 Shipments
        </button>
        <button class="tab" onclick="switchTab('tracking')">
            🗺️ Vessel Tracking
        </button>
        <button class="tab" onclick="switchTab('market')">
            📊 Market Data
        </button>
        <button class="tab" onclick="switchTab('news')">
            📰 News & Reports
        </button>
    </div>

    <!-- Tab Contents -->
    
    <!-- Shipments Tab -->
    <div id="tab-shipments" class="tab-content active">
        <div class="search-box">
            <input type="text" id="search-shipments" placeholder="Search by vessel name, IMO, or country..." onkeyup="searchShipments()">
            <select class="filter-select" id="filter-status" onchange="filterShipments()">
                <option value="">All Status</option>
                <option value="planning">Planning</option>
                <option value="in_transit">In Transit</option>
                <option value="port_arrival">Port Arrival</option>
                <option value="customs">Customs</option>
                <option value="delivered">Delivered</option>
            </select>
        </div>
        
        <div class="shipment-table">
            <table>
                <thead>
                    <tr>
                        <th>Shipment #</th>
                        <th>Vessel Name</th>
                        <th>Origin</th>
                        <th>Destination</th>
                        <th>Quantity (MT)</th>
                        <th>Status</th>
                        <th>ETA</th>
                        <th>Source</th>
                    </tr>
                </thead>
                <tbody id="shipments-tbody">
                    <tr>
                        <td colspan="8" class="empty-state">
                            <div>
                                <i class="fas fa-ship"></i>
                                <p>Click "Fetch Global Data" to load shipments from public sources</p>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Vessel Tracking Tab -->
    <div id="tab-tracking" class="tab-content">
        <div class="alert-box info">
            <strong>🗺️ Vessel Tracking:</strong> Live positions scraped from VesselFinder and FleetMon. 
            Updates automatically when you fetch global data.
        </div>
        
        <div id="tracking-content">
            <div class="empty-state">
                <i class="fas fa-map-marked-alt"></i>
                <p>No vessel tracking data available. Fetch global data to see vessel positions.</p>
            </div>
        </div>
    </div>

    <!-- Market Data Tab -->
    <div id="tab-market" class="tab-content">
        <div class="alert-box info">
            <strong>📊 Market Intelligence:</strong> Wheat production, trade volumes, and prices scraped from USDA, FAO, and IGC public reports.
        </div>
        
        <div id="market-content">
            <div class="empty-state">
                <i class="fas fa-chart-line"></i>
                <p>No market data available. Fetch global data to load latest statistics.</p>
            </div>
        </div>
    </div>

    <!-- News Tab -->
    <div id="tab-news" class="tab-content">
        <div class="alert-box info">
            <strong>📰 Latest News:</strong> Wheat market news and reports scraped from GrainCentral and industry sources.
        </div>
        
        <div id="news-content">
            <div class="empty-state">
                <i class="fas fa-newspaper"></i>
                <p>No news available. Fetch global data to load latest articles.</p>
            </div>
        </div>
    </div>
</div>

<script>
// Tab switching
function switchTab(tabName) {
    // Hide all tabs
    document.querySelectorAll('.tab-content').forEach(tab => {
        tab.classList.remove('active');
    });
    document.querySelectorAll('.tab').forEach(tab => {
        tab.classList.remove('active');
    });
    
    // Show selected tab
    document.getElementById('tab-' + tabName).classList.add('active');
    event.target.classList.add('active');
}

// Scrape global data
function scrapeGlobalData() {
    const btn = document.querySelector('.btn-scrape');
    const btnText = btn.querySelector('.btn-text');
    const spinner = btn.querySelector('.spinner');
    
    // Disable button and show spinner
    btn.disabled = true;
    btnText.textContent = 'Scraping data...';
    spinner.style.display = 'inline-block';
    
    fetch(window.location.href, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=scrape_global_data'
    })
    .then(response => response.json())
    .then(data => {
        console.log('Scrape result:', data);
        
        if (data.success) {
            showAlert('success', `Successfully scraped data! Found ${data.vessels.length} vessels, ${data.market_data.length} market records, and ${data.news.length} news articles.`);
            
            // Reload shipments and stats
            loadShipments();
            refreshStats();
            
            // Update other tabs
            updateTrackingTab(data.vessels);
            updateMarketTab(data.market_data);
            updateNewsTab(data.news);
        } else {
            showAlert('error', 'Failed to scrape data: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showAlert('error', 'Network error while scraping data');
    })
    .finally(() => {
        // Re-enable button
        btn.disabled = false;
        btnText.textContent = '🌐 Fetch Global Data';
        spinner.style.display = 'none';
    });
}

// Load shipments
function loadShipments(page = 1) {
    const search = document.getElementById('search-shipments').value;
    const status = document.getElementById('filter-status').value;
    
    fetch(window.location.href, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=get_shipments&page=${page}&search=${encodeURIComponent(search)}&status=${status}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.data.length > 0) {
            displayShipments(data.data);
        } else {
            document.getElementById('shipments-tbody').innerHTML = `
                <tr>
                    <td colspan="8" class="empty-state">
                        <div>
                            <i class="fas fa-ship"></i>
                            <p>No shipments found. Try fetching global data.</p>
                        </div>
                    </td>
                </tr>
            `;
        }
    })
    .catch(error => {
        console.error('Error loading shipments:', error);
    });
}

// Display shipments in table
function displayShipments(shipments) {
    const tbody = document.getElementById('shipments-tbody');
    tbody.innerHTML = '';
    
    shipments.forEach(shipment => {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td><strong>${shipment.shipment_number}</strong></td>
            <td>${shipment.vessel_name || 'N/A'}</td>
            <td>${shipment.origin_country || 'N/A'}</td>
            <td>${shipment.destination_port || 'N/A'}, ${shipment.destination_country}</td>
            <td>${shipment.quantity_tons ? parseFloat(shipment.quantity_tons).toLocaleString() : 'N/A'}</td>
            <td><span class="status-badge ${shipment.status}">${formatStatus(shipment.status)}</span></td>
            <td>${shipment.expected_arrival || 'N/A'}</td>
            <td><small>${shipment.data_source || 'Manual'}</small></td>
        `;
        tbody.appendChild(row);
    });
}

// Format status
function formatStatus(status) {
    return status.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
}

// Search shipments
function searchShipments() {
    clearTimeout(window.searchTimeout);
    window.searchTimeout = setTimeout(() => {
        loadShipments(1);
    }, 500);
}

// Filter shipments
function filterShipments() {
    loadShipments(1);
}

// Refresh stats
function refreshStats() {
    fetch(window.location.href, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=get_stats'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Update stat cards
            const stats = data.data;
            document.querySelectorAll('.stat-card .value').forEach((el, index) => {
                switch(index) {
                    case 0:
                        el.textContent = stats.total_shipments || 0;
                        break;
                    case 1:
                        el.textContent = stats.in_transit || 0;
                        break;
                    case 2:
                        el.textContent = stats.arrived || 0;
                        break;
                    case 3:
                        el.textContent = (stats.total_quantity_tons || 0).toLocaleString();
                        break;
                }
            });
            
            showAlert('success', 'Stats refreshed successfully');
        }
    })
    .catch(error => {
        console.error('Error refreshing stats:', error);
    });
}

// Update tracking tab
function updateTrackingTab(vessels) {
    const content = document.getElementById('tracking-content');
    
    if (vessels.length > 0) {
        let html = '<div class="shipment-table"><table><thead><tr>' +
                   '<th>Vessel</th><th>Position</th><th>Speed</th><th>Course</th><th>Destination</th></tr></thead><tbody>';
        
        vessels.forEach(v => {
            if (v.current_position_lat && v.current_position_lon) {
                html += `<tr>
                    <td><strong>${v.vessel_name}</strong></td>
                    <td>${v.current_position_lat}, ${v.current_position_lon}</td>
                    <td>${v.current_speed || 'N/A'} knots</td>
                    <td>${v.current_course || 'N/A'}°</td>
                    <td>${v.destination_port || 'N/A'}</td>
                </tr>`;
            }
        });
        
        html += '</tbody></table></div>';
        content.innerHTML = html;
    }
}

// Update market tab
function updateMarketTab(marketData) {
    const content = document.getElementById('market-content');
    
    if (marketData.length > 0) {
        let html = '<div class="shipment-table"><table><thead><tr>' +
                   '<th>Country</th><th>Type</th><th>Volume (MT)</th><th>Value (USD)</th><th>Source</th></tr></thead><tbody>';
        
        marketData.forEach(m => {
            html += `<tr>
                <td><strong>${m.country}</strong></td>
                <td>${m.data_type}</td>
                <td>${m.volume_tons ? parseFloat(m.volume_tons).toLocaleString() : 'N/A'}</td>
                <td>${m.export_value_usd ? '$' + parseFloat(m.export_value_usd).toLocaleString() : 'N/A'}</td>
                <td><small>${m.data_source}</small></td>
            </tr>`;
        });
        
        html += '</tbody></table></div>';
        content.innerHTML = html;
    }
}

// Update news tab
function updateNewsTab(news) {
    const content = document.getElementById('news-content');
    
    if (news.length > 0) {
        let html = '';
        
        news.forEach(n => {
            html += `<div class="news-item">
                <h4>${n.title}</h4>
                <div class="meta">${n.published_date || 'Recent'} • ${n.source}</div>
                <p>${n.excerpt || ''}</p>
                ${n.url ? `<a href="${n.url}" target="_blank">Read more →</a>` : ''}
            </div>`;
        });
        
        content.innerHTML = html;
    }
}

// Show alert
function showAlert(type, message) {
    const alert = document.createElement('div');
    alert.className = `alert-box ${type}`;
    alert.innerHTML = message;
    
    const dashboard = document.querySelector('.wheat-dashboard');
    dashboard.insertBefore(alert, dashboard.firstChild);
    
    setTimeout(() => {
        alert.remove();
    }, 5000);
}

// Load initial data on page load
document.addEventListener('DOMContentLoaded', function() {
    loadShipments();
});
</script>

<?php
// Include footer
require_once 'footer.php';
?>