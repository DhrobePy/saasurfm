<?php
/**
 * Bangladesh Wheat Shipment Intelligence Dashboard
 * Market Intelligence: Track ALL wheat shipments coming to Bangladesh
 * 
 * Purpose: Monitor worldwide wheat imports to Bangladesh from free public sources
 * Data Sources: AIS, MarineTraffic, UN Comtrade
 * Use: Market supply analysis, price intelligence, competitive insights
 * 
 * NOT just for Ujjal Flour Mills - tracks ALL importers to Bangladesh
 * Integrated with saasurfm Platform
 */

// Include your existing config and connection
require_once '../core/config/config.php'; // Your saasurfm config
require_once '../templates/WheatShipmentManager.php';
require_once '../core/init.php';

// Initialize manager
$wheatManager = new WheatShipmentManager($db, $branch_id ?? null, $user_id ?? null);

// Handle AJAX requests
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    switch ($_GET['ajax']) {
        case 'get_dashboard':
            echo json_encode($wheatManager->getDashboardSummary());
            break;
            
        case 'get_shipments':
            $filters = [
                'status' => $_GET['status'] ?? '',
                'search' => $_GET['search'] ?? '',
                'date_from' => $_GET['date_from'] ?? '',
                'date_to' => $_GET['date_to'] ?? '',
                'limit' => $_GET['limit'] ?? 100
            ];
            echo json_encode($wheatManager->getShipments($filters));
            break;
            
        case 'get_shipment':
            $id = $_GET['id'] ?? 0;
            echo json_encode($wheatManager->getShipment($id));
            break;
            
        case 'create_shipment':
            $data = json_decode(file_get_contents('php://input'), true);
            echo json_encode($wheatManager->createShipment($data));
            break;
            
        case 'update_shipment':
            $data = json_decode(file_get_contents('php://input'), true);
            $id = $data['id'] ?? 0;
            unset($data['id']);
            echo json_encode($wheatManager->updateShipment($id, $data));
            break;
            
        case 'delete_shipment':
            $id = $_GET['id'] ?? 0;
            echo json_encode($wheatManager->deleteShipment($id));
            break;
            
        case 'add_position':
            $data = json_decode(file_get_contents('php://input'), true);
            $shipment_id = $data['shipment_id'] ?? 0;
            unset($data['shipment_id']);
            echo json_encode($wheatManager->addPosition($shipment_id, $data));
            break;
            
        case 'get_alerts':
            echo json_encode($wheatManager->getUnreadAlerts());
            break;
            
        case 'mark_alert_read':
            $id = $_GET['id'] ?? 0;
            $result = $wheatManager->markAlertRead($id);
            echo json_encode(['success' => $result]);
            break;
            
        case 'get_market_data':
            $filters = [
                'year' => $_GET['year'] ?? date('Y'),
                'limit' => $_GET['limit'] ?? 50
            ];
            echo json_encode($wheatManager->getMarketData($filters));
            break;
            
        case 'fetch_comtrade':
            echo json_encode($wheatManager->fetchComtradeData());
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
    exit;
}
require_once '../templates/header.php';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bangladesh Wheat Import Intelligence - saasurfm</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <!-- DataTables CSS -->
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    
    <style>
        :root {
            --wheat-primary: #D4A574;
            --wheat-secondary: #8B6F47;
            --wheat-accent: #F5DEB3;
            --wheat-dark: #654321;
        }
        
        .wheat-card {
            border-left: 4px solid var(--wheat-primary);
            transition: all 0.3s ease;
        }
        
        .wheat-card:hover {
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        
        .stat-icon {
            width: 60px;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            font-size: 24px;
        }
        
        .status-badge {
            padding: 0.35em 0.65em;
            font-size: 0.875em;
            font-weight: 600;
            border-radius: 0.25rem;
        }
        
        .status-scheduled { background-color: #cfe2ff; color: #084298; }
        .status-in-transit { background-color: #fff3cd; color: #664d03; }
        .status-arrived { background-color: #d1e7dd; color: #0f5132; }
        .status-unloading { background-color: #e7f1ff; color: #004085; }
        .status-completed { background-color: #d3d3d3; color: #383d41; }
        .status-cancelled { background-color: #f8d7da; color: #721c24; }
        
        .nav-pills .nav-link {
            color: var(--wheat-dark);
            font-weight: 500;
        }
        
        .nav-pills .nav-link.active {
            background-color: var(--wheat-primary);
            color: white;
        }
        
        .tab-content {
            min-height: 500px;
        }
        
        .alert-item {
            border-left: 3px solid;
            transition: background-color 0.3s;
        }
        
        .alert-item:hover {
            background-color: #f8f9fa;
        }
        
        .alert-info { border-color: #0dcaf0; }
        .alert-warning { border-color: #ffc107; }
        .alert-critical { border-color: #dc3545; }
        
        .table-hover tbody tr:hover {
            background-color: var(--wheat-accent);
        }
        
        .modal-header {
            background-color: var(--wheat-primary);
            color: white;
        }
        
        .btn-wheat {
            background-color: var(--wheat-primary);
            color: white;
            border: none;
        }
        
        .btn-wheat:hover {
            background-color: var(--wheat-secondary);
            color: white;
        }
        
        .market-data-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px;
            padding: 20px;
        }
        
        .position-marker {
            width: 12px;
            height: 12px;
            background-color: #dc3545;
            border: 2px solid white;
            border-radius: 50%;
            box-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        .loading {
            animation: pulse 1.5s ease-in-out infinite;
        }
    </style>
</head>
<body>

<div class="container-fluid py-4">
    <!-- Market Intelligence Info Banner -->
    <div class="row mb-3">
        <div class="col-md-12">
            <div class="alert alert-info border-start border-primary border-4" role="alert">
                <h6 class="alert-heading mb-2">
                    <i class="bi bi-info-circle-fill me-2"></i>Market Intelligence Tool
                </h6>
                <p class="mb-0 small">
                    <strong>Purpose:</strong> Track ALL wheat shipments coming to Bangladesh from worldwide sources • 
                    <strong>Data:</strong> AIS, MarineTraffic, UN Comtrade (Free Public Sources) • 
                    <strong>Use:</strong> Market supply analysis, price intelligence, competitive insights
                </p>
            </div>
        </div>
    </div>

    <!-- Header -->
    <div class="row mb-4">
        <div class="col-md-8">
            <h2 class="mb-0">
                <i class="bi bi-globe2 text-warning"></i>
                Bangladesh Wheat Import Tracker
            </h2>
            <p class="text-muted">Monitor ALL wheat shipments to Bangladesh • Global market intelligence</p>
        </div>
        <div class="col-md-4 text-end">
            <button class="btn btn-wheat btn-sm" id="refreshDashboard">
                <i class="bi bi-arrow-clockwise"></i> Refresh
            </button>
            <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#addShipmentModal">
                <i class="bi bi-plus-circle"></i> Add Shipment Data
            </button>
        </div>
    </div>

    <!-- Alert Notifications -->
    <div id="alertsContainer" class="mb-3"></div>

    <!-- Navigation Tabs -->
    <ul class="nav nav-pills mb-4" id="mainTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="dashboard-tab" data-bs-toggle="pill" data-bs-target="#dashboard" type="button" role="tab">
                <i class="bi bi-speedometer2"></i> Dashboard
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="shipments-tab" data-bs-toggle="pill" data-bs-target="#shipments" type="button" role="tab">
                <i class="bi bi-box-seam"></i> Shipments
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tracking-tab" data-bs-toggle="pill" data-bs-target="#tracking" type="button" role="tab">
                <i class="bi bi-geo-alt"></i> Tracking
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="market-tab" data-bs-toggle="pill" data-bs-target="#market" type="button" role="tab">
                <i class="bi bi-graph-up"></i> Market Data
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="reports-tab" data-bs-toggle="pill" data-bs-target="#reports" type="button" role="tab">
                <i class="bi bi-file-earmark-bar-graph"></i> Reports
            </button>
        </li>
    </ul>

    <!-- Tab Content -->
    <div class="tab-content" id="mainTabContent">
        
        <!-- Dashboard Tab -->
        <div class="tab-pane fade show active" id="dashboard" role="tabpanel">
            <div class="row g-3 mb-4">
                <!-- Summary Cards -->
                <div class="col-md-3">
                    <div class="card wheat-card">
                        <div class="card-body">
                            <div class="d-flex align-items-center">
                                <div class="stat-icon bg-primary bg-opacity-10 text-primary me-3">
                                    <i class="bi bi-ship"></i>
                                </div>
                                <div>
                                    <h6 class="text-muted mb-1">In Transit</h6>
                                    <h3 class="mb-0" id="stat-in-transit">0</h3>
                                    <small class="text-muted" id="stat-in-transit-tons">0 tons</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card wheat-card">
                        <div class="card-body">
                            <div class="d-flex align-items-center">
                                <div class="stat-icon bg-warning bg-opacity-10 text-warning me-3">
                                    <i class="bi bi-calendar-check"></i>
                                </div>
                                <div>
                                    <h6 class="text-muted mb-1">Scheduled</h6>
                                    <h3 class="mb-0" id="stat-scheduled">0</h3>
                                    <small class="text-muted" id="stat-scheduled-tons">0 tons</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card wheat-card">
                        <div class="card-body">
                            <div class="d-flex align-items-center">
                                <div class="stat-icon bg-success bg-opacity-10 text-success me-3">
                                    <i class="bi bi-check-circle"></i>
                                </div>
                                <div>
                                    <h6 class="text-muted mb-1">Arrived</h6>
                                    <h3 class="mb-0" id="stat-arrived">0</h3>
                                    <small class="text-muted" id="stat-arrived-tons">0 tons</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card wheat-card">
                        <div class="card-body">
                            <div class="d-flex align-items-center">
                                <div class="stat-icon bg-info bg-opacity-10 text-info me-3">
                                    <i class="bi bi-box-seam"></i>
                                </div>
                                <div>
                                    <h6 class="text-muted mb-1">Total Shipments</h6>
                                    <h3 class="mb-0" id="stat-total">0</h3>
                                    <small class="text-muted" id="stat-total-tons">0 tons</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <!-- Recent Shipments -->
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header bg-light">
                            <h5 class="mb-0"><i class="bi bi-clock-history"></i> Recent Shipments</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover" id="recentShipmentsTable">
                                    <thead>
                                        <tr>
                                            <th>Shipment #</th>
                                            <th>Vessel</th>
                                            <th>Route</th>
                                            <th>Quantity</th>
                                            <th>ETA</th>
                                            <th>Status</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody id="recentShipmentsBody">
                                        <tr>
                                            <td colspan="7" class="text-center text-muted">
                                                <div class="spinner-border spinner-border-sm" role="status">
                                                    <span class="visually-hidden">Loading...</span>
                                                </div>
                                                Loading shipments...
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Alerts & Notifications -->
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header bg-light">
                            <h5 class="mb-0"><i class="bi bi-bell"></i> Alerts</h5>
                        </div>
                        <div class="card-body" id="alertsList" style="max-height: 400px; overflow-y: auto;">
                            <div class="text-center text-muted">
                                <div class="spinner-border spinner-border-sm" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                                Loading alerts...
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Shipments Tab -->
        <div class="tab-pane fade" id="shipments" role="tabpanel">
            <!-- Filters -->
            <div class="card mb-3">
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Status</label>
                            <select class="form-select" id="filterStatus">
                                <option value="">All Status</option>
                                <option value="Scheduled">Scheduled</option>
                                <option value="In Transit">In Transit</option>
                                <option value="Arrived">Arrived</option>
                                <option value="Unloading">Unloading</option>
                                <option value="Completed">Completed</option>
                                <option value="Cancelled">Cancelled</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">From Date</label>
                            <input type="date" class="form-control" id="filterDateFrom">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">To Date</label>
                            <input type="date" class="form-control" id="filterDateTo">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Search</label>
                            <input type="text" class="form-control" id="filterSearch" placeholder="Shipment #, Vessel, Supplier">
                        </div>
                    </div>
                    <div class="mt-3">
                        <button class="btn btn-wheat btn-sm" id="applyFilters">
                            <i class="bi bi-funnel"></i> Apply Filters
                        </button>
                        <button class="btn btn-secondary btn-sm" id="clearFilters">
                            <i class="bi bi-x-circle"></i> Clear
                        </button>
                    </div>
                </div>
            </div>

            <!-- Shipments Table -->
            <div class="card">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover" id="shipmentsTable">
                            <thead>
                                <tr>
                                    <th>Shipment #</th>
                                    <th>Vessel</th>
                                    <th>Origin</th>
                                    <th>Destination</th>
                                    <th>Quantity (MT)</th>
                                    <th>Wheat Type</th>
                                    <th>Supplier</th>
                                    <th>Departure</th>
                                    <th>ETA</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="shipmentsTableBody">
                                <tr>
                                    <td colspan="11" class="text-center text-muted">
                                        <div class="spinner-border" role="status">
                                            <span class="visually-hidden">Loading...</span>
                                        </div>
                                        <p class="mt-2">Loading shipments...</p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tracking Tab -->
        <div class="tab-pane fade" id="tracking" role="tabpanel">
            <div class="row">
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header bg-light">
                            <h5 class="mb-0"><i class="bi bi-list-ul"></i> Active Shipments</h5>
                        </div>
                        <div class="card-body" style="max-height: 600px; overflow-y: auto;">
                            <div id="trackingShipmentsList">
                                <div class="text-center text-muted">
                                    <div class="spinner-border spinner-border-sm" role="status"></div>
                                    <p class="mt-2">Loading...</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header bg-light">
                            <h5 class="mb-0"><i class="bi bi-map"></i> Position Tracking</h5>
                        </div>
                        <div class="card-body">
                            <div id="trackingDetails" class="text-center text-muted py-5">
                                <i class="bi bi-geo-alt display-1"></i>
                                <p class="mt-3">Select a shipment to view tracking details</p>
                            </div>
                        </div>
                    </div>

                    <!-- Position History -->
                    <div class="card mt-3">
                        <div class="card-header bg-light">
                            <h5 class="mb-0"><i class="bi bi-clock-history"></i> Position History</h5>
                        </div>
                        <div class="card-body">
                            <div id="positionHistory"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Market Data Tab -->
        <div class="tab-pane fade" id="market" role="tabpanel">
            <div class="row mb-3">
                <div class="col-md-12">
                    <div class="card market-data-card">
                        <h4><i class="bi bi-globe2"></i> Global Wheat Trade Statistics</h4>
                        <p class="mb-0">Data sourced from UN Comtrade and other public sources</p>
                        <button class="btn btn-light btn-sm mt-2" id="fetchMarketData">
                            <i class="bi bi-arrow-clockwise"></i> Fetch Latest Data
                        </button>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header bg-light">
                            <h5 class="mb-0"><i class="bi bi-bar-chart"></i> Top Wheat Exporters</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover" id="marketDataTable">
                                    <thead>
                                        <tr>
                                            <th>Country</th>
                                            <th>Year</th>
                                            <th>Export Quantity (MT)</th>
                                            <th>Export Value (USD)</th>
                                            <th>Price/MT (USD)</th>
                                            <th>Last Updated</th>
                                        </tr>
                                    </thead>
                                    <tbody id="marketDataBody">
                                        <tr>
                                            <td colspan="6" class="text-center text-muted">
                                                <div class="spinner-border" role="status"></div>
                                                <p class="mt-2">Loading market data...</p>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Reports Tab -->
        <div class="tab-pane fade" id="reports" role="tabpanel">
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="card text-center">
                        <div class="card-body">
                            <i class="bi bi-file-earmark-spreadsheet display-4 text-success"></i>
                            <h5 class="mt-3">Shipment Summary</h5>
                            <p class="text-muted">Export detailed shipment report</p>
                            <button class="btn btn-success btn-sm">
                                <i class="bi bi-download"></i> Export Excel
                            </button>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card text-center">
                        <div class="card-body">
                            <i class="bi bi-file-earmark-pdf display-4 text-danger"></i>
                            <h5 class="mt-3">Monthly Report</h5>
                            <p class="text-muted">Generate monthly analytics PDF</p>
                            <button class="btn btn-danger btn-sm">
                                <i class="bi bi-file-pdf"></i> Generate PDF
                            </button>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card text-center">
                        <div class="card-body">
                            <i class="bi bi-graph-up display-4 text-primary"></i>
                            <h5 class="mt-3">Analytics Dashboard</h5>
                            <p class="text-muted">View comprehensive analytics</p>
                            <button class="btn btn-primary btn-sm">
                                <i class="bi bi-bar-chart"></i> View Analytics
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- Add Shipment Modal -->
<div class="modal fade" id="addShipmentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-plus-circle"></i> Add Bangladesh Import Shipment Data</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="addShipmentForm">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Vessel Name *</label>
                            <input type="text" class="form-control" name="vessel_name" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Vessel MMSI</label>
                            <input type="text" class="form-control" name="vessel_mmsi">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Vessel IMO</label>
                            <input type="text" class="form-control" name="vessel_imo">
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Origin Port *</label>
                            <input type="text" class="form-control" name="origin_port" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Origin Country</label>
                            <input type="text" class="form-control" name="origin_country">
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Destination Port *</label>
                            <input type="text" class="form-control" name="destination_port" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Destination Country</label>
                            <input type="text" class="form-control" name="destination_country" value="Bangladesh">
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Quantity (MT) *</label>
                            <input type="number" step="0.01" class="form-control" name="quantity_tons" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Wheat Type</label>
                            <select class="form-select" name="wheat_type">
                                <option>Hard Red Winter</option>
                                <option>Soft Red Winter</option>
                                <option>Hard Red Spring</option>
                                <option>Soft White</option>
                                <option>Australian Prime Hard</option>
                                <option>Durum</option>
                                <option>Other</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Supplier Name</label>
                            <input type="text" class="form-control" name="supplier_name">
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Departure Date</label>
                            <input type="date" class="form-control" name="departure_date">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Expected Arrival *</label>
                            <input type="date" class="form-control" name="expected_arrival" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status">
                                <option value="Scheduled">Scheduled</option>
                                <option value="In Transit">In Transit</option>
                                <option value="Arrived">Arrived</option>
                                <option value="Unloading">Unloading</option>
                            </select>
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Total Cost</label>
                            <input type="number" step="0.01" class="form-control" name="total_cost">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Currency</label>
                            <select class="form-select" name="currency">
                                <option value="USD">USD</option>
                                <option value="EUR">EUR</option>
                                <option value="BDT">BDT</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Payment Status</label>
                            <select class="form-select" name="payment_status">
                                <option value="Pending">Pending</option>
                                <option value="Partial">Partial</option>
                                <option value="Paid">Paid</option>
                            </select>
                        </div>
                        
                        <div class="col-md-12">
                            <label class="form-label">Notes</label>
                            <textarea class="form-control" name="notes" rows="3"></textarea>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-wheat" id="saveShipmentBtn">
                    <i class="bi bi-save"></i> Save Shipment
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Shipment Modal -->
<div class="modal fade" id="editShipmentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pencil-square"></i> Edit Shipment</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="editShipmentForm">
                    <input type="hidden" name="id" id="edit_shipment_id">
                    <!-- Same form fields as add modal -->
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Vessel Name *</label>
                            <input type="text" class="form-control" name="vessel_name" id="edit_vessel_name" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Vessel MMSI</label>
                            <input type="text" class="form-control" name="vessel_mmsi" id="edit_vessel_mmsi">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Vessel IMO</label>
                            <input type="text" class="form-control" name="vessel_imo" id="edit_vessel_imo">
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Origin Port *</label>
                            <input type="text" class="form-control" name="origin_port" id="edit_origin_port" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Origin Country</label>
                            <input type="text" class="form-control" name="origin_country" id="edit_origin_country">
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Destination Port *</label>
                            <input type="text" class="form-control" name="destination_port" id="edit_destination_port" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Destination Country</label>
                            <input type="text" class="form-control" name="destination_country" id="edit_destination_country">
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Quantity (MT) *</label>
                            <input type="number" step="0.01" class="form-control" name="quantity_tons" id="edit_quantity_tons" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Wheat Type</label>
                            <input type="text" class="form-control" name="wheat_type" id="edit_wheat_type">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Supplier Name</label>
                            <input type="text" class="form-control" name="supplier_name" id="edit_supplier_name">
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Departure Date</label>
                            <input type="date" class="form-control" name="departure_date" id="edit_departure_date">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Expected Arrival *</label>
                            <input type="date" class="form-control" name="expected_arrival" id="edit_expected_arrival" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Actual Arrival</label>
                            <input type="date" class="form-control" name="actual_arrival" id="edit_actual_arrival">
                        </div>
                        
                        <div class="col-md-12">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status" id="edit_status">
                                <option value="Scheduled">Scheduled</option>
                                <option value="In Transit">In Transit</option>
                                <option value="Arrived">Arrived</option>
                                <option value="Unloading">Unloading</option>
                                <option value="Completed">Completed</option>
                                <option value="Cancelled">Cancelled</option>
                            </select>
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Total Cost</label>
                            <input type="number" step="0.01" class="form-control" name="total_cost" id="edit_total_cost">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Currency</label>
                            <select class="form-select" name="currency" id="edit_currency">
                                <option value="USD">USD</option>
                                <option value="EUR">EUR</option>
                                <option value="BDT">BDT</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Payment Status</label>
                            <select class="form-select" name="payment_status" id="edit_payment_status">
                                <option value="Pending">Pending</option>
                                <option value="Partial">Partial</option>
                                <option value="Paid">Paid</option>
                            </select>
                        </div>
                        
                        <div class="col-md-12">
                            <label class="form-label">Notes</label>
                            <textarea class="form-control" name="notes" id="edit_notes" rows="3"></textarea>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-wheat" id="updateShipmentBtn">
                    <i class="bi bi-save"></i> Update Shipment
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Add Position Modal -->
<div class="modal fade" id="addPositionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-geo-alt"></i> Add Position Update</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="addPositionForm">
                    <input type="hidden" name="shipment_id" id="position_shipment_id">
                    
                    <div class="mb-3">
                        <label class="form-label">Latitude *</label>
                        <input type="number" step="0.000001" class="form-control" name="latitude" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Longitude *</label>
                        <input type="number" step="0.000001" class="form-control" name="longitude" required>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Speed (Knots)</label>
                                <input type="number" step="0.1" class="form-control" name="speed_knots">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Course (°)</label>
                                <input type="number" step="0.1" class="form-control" name="course">
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Position Source</label>
                        <select class="form-select" name="position_source">
                            <option value="Manual">Manual Entry</option>
                            <option value="API">API</option>
                            <option value="AIS">AIS</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Recorded At *</label>
                        <input type="datetime-local" class="form-control" name="recorded_at" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" rows="2"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-wheat" id="savePositionBtn">
                    <i class="bi bi-save"></i> Save Position
                </button>
            </div>
        </div>
    </div>
</div>

<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<!-- Bootstrap 5 JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- DataTables JS -->
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<script>
// Global variables
let shipmentsDataTable;
let currentEditingShipment = null;

// Initialize on page load
$(document).ready(function() {
    // Load initial data
    loadDashboard();
    loadAlerts();
    
    // Set up auto-refresh
    setInterval(loadDashboard, 300000); // Refresh every 5 minutes
    setInterval(loadAlerts, 60000); // Refresh alerts every minute
    
    // Tab change handlers
    $('#shipments-tab').on('shown.bs.tab', function() {
        loadShipments();
    });
    
    $('#tracking-tab').on('shown.bs.tab', function() {
        loadTrackingShipments();
    });
    
    $('#market-tab').on('shown.bs.tab', function() {
        loadMarketData();
    });
    
    // Button handlers
    $('#refreshDashboard').click(function() {
        loadDashboard();
        showNotification('Dashboard refreshed', 'success');
    });
    
    $('#saveShipmentBtn').click(saveShipment);
    $('#updateShipmentBtn').click(updateShipment);
    $('#savePositionBtn').click(savePosition);
    
    $('#applyFilters').click(loadShipments);
    $('#clearFilters').click(function() {
        $('#filterStatus').val('');
        $('#filterDateFrom').val('');
        $('#filterDateTo').val('');
        $('#filterSearch').val('');
        loadShipments();
    });
    
    $('#fetchMarketData').click(fetchComtradeData);
});

// Load dashboard summary
function loadDashboard() {
    $.ajax({
        url: '?ajax=get_dashboard',
        method: 'GET',
        success: function(response) {
            if (response.success) {
                updateDashboardStats(response);
                loadRecentShipments();
            }
        },
        error: function() {
            showNotification('Failed to load dashboard', 'danger');
        }
    });
}

// Update dashboard statistics
function updateDashboardStats(data) {
    const summary = data.summary;
    const stats = data.stats;
    
    // Update stat cards
    $('#stat-in-transit').text(stats.in_transit || 0);
    $('#stat-in-transit-tons').text(formatNumber((summary['In Transit']?.total_tons || 0)) + ' tons');
    
    $('#stat-scheduled').text(stats.scheduled || 0);
    $('#stat-scheduled-tons').text(formatNumber((summary['Scheduled']?.total_tons || 0)) + ' tons');
    
    $('#stat-arrived').text(stats.arrived || 0);
    $('#stat-arrived-tons').text(formatNumber((summary['Arrived']?.total_tons || 0)) + ' tons');
    
    $('#stat-total').text(stats.total_shipments || 0);
    $('#stat-total-tons').text(formatNumber(stats.total_quantity || 0) + ' tons');
}

// Load recent shipments for dashboard
function loadRecentShipments() {
    $.ajax({
        url: '?ajax=get_shipments&limit=10',
        method: 'GET',
        success: function(response) {
            if (response.success) {
                renderRecentShipments(response.data);
            }
        }
    });
}

// Render recent shipments table
function renderRecentShipments(shipments) {
    const tbody = $('#recentShipmentsBody');
    tbody.empty();
    
    if (shipments.length === 0) {
        tbody.append('<tr><td colspan="7" class="text-center text-muted">No shipments found</td></tr>');
        return;
    }
    
    shipments.forEach(ship => {
        const row = `
            <tr>
                <td><strong>${ship.shipment_number}</strong></td>
                <td>${ship.vessel_name}</td>
                <td><small>${ship.origin_port} → ${ship.destination_port}</small></td>
                <td>${formatNumber(ship.quantity_tons)} MT</td>
                <td>${formatDate(ship.expected_arrival)}</td>
                <td><span class="status-badge status-${ship.status.toLowerCase().replace(' ', '-')}">${ship.status}</span></td>
                <td>
                    <button class="btn btn-sm btn-primary" onclick="viewShipment(${ship.id})">
                        <i class="bi bi-eye"></i>
                    </button>
                </td>
            </tr>
        `;
        tbody.append(row);
    });
}

// Load all shipments
function loadShipments() {
    const filters = {
        status: $('#filterStatus').val(),
        date_from: $('#filterDateFrom').val(),
        date_to: $('#filterDateTo').val(),
        search: $('#filterSearch').val()
    };
    
    $.ajax({
        url: '?ajax=get_shipments',
        method: 'GET',
        data: filters,
        success: function(response) {
            if (response.success) {
                renderShipmentsTable(response.data);
            }
        }
    });
}

// Render shipments table
function renderShipmentsTable(shipments) {
    const tbody = $('#shipmentsTableBody');
    tbody.empty();
    
    if (shipments.length === 0) {
        tbody.append('<tr><td colspan="11" class="text-center text-muted">No shipments found</td></tr>');
        return;
    }
    
    shipments.forEach(ship => {
        const row = `
            <tr>
                <td><strong>${ship.shipment_number}</strong></td>
                <td>${ship.vessel_name}${ship.vessel_mmsi ? '<br><small class="text-muted">MMSI: ' + ship.vessel_mmsi + '</small>' : ''}</td>
                <td>${ship.origin_port}<br><small class="text-muted">${ship.origin_country || ''}</small></td>
                <td>${ship.destination_port}<br><small class="text-muted">${ship.destination_country || ''}</small></td>
                <td>${formatNumber(ship.quantity_tons)}</td>
                <td>${ship.wheat_type || '-'}</td>
                <td>${ship.supplier_name || '-'}</td>
                <td>${formatDate(ship.departure_date)}</td>
                <td>${formatDate(ship.expected_arrival)}</td>
                <td><span class="status-badge status-${ship.status.toLowerCase().replace(' ', '-')}">${ship.status}</span></td>
                <td>
                    <div class="btn-group btn-group-sm" role="group">
                        <button class="btn btn-primary" onclick="editShipment(${ship.id})" title="Edit">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button class="btn btn-info" onclick="trackShipment(${ship.id})" title="Track">
                            <i class="bi bi-geo-alt"></i>
                        </button>
                        <button class="btn btn-danger" onclick="deleteShipment(${ship.id}, '${ship.shipment_number}')" title="Delete">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </td>
            </tr>
        `;
        tbody.append(row);
    });
}

// Save new shipment
function saveShipment() {
    const formData = {};
    $('#addShipmentForm').serializeArray().forEach(item => {
        formData[item.name] = item.value;
    });
    
    $.ajax({
        url: '?ajax=create_shipment',
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify(formData),
        success: function(response) {
            if (response.success) {
                $('#addShipmentModal').modal('hide');
                $('#addShipmentForm')[0].reset();
                showNotification('Shipment created successfully: ' + response.shipment_number, 'success');
                loadDashboard();
                if ($('#shipments-tab').hasClass('active')) {
                    loadShipments();
                }
            } else {
                showNotification('Error: ' + response.message, 'danger');
            }
        },
        error: function() {
            showNotification('Failed to create shipment', 'danger');
        }
    });
}

// Edit shipment
function editShipment(id) {
    $.ajax({
        url: '?ajax=get_shipment&id=' + id,
        method: 'GET',
        success: function(response) {
            if (response.success) {
                const ship = response.data;
                currentEditingShipment = id;
                
                // Populate edit form
                $('#edit_shipment_id').val(ship.id);
                $('#edit_vessel_name').val(ship.vessel_name);
                $('#edit_vessel_mmsi').val(ship.vessel_mmsi);
                $('#edit_vessel_imo').val(ship.vessel_imo);
                $('#edit_origin_port').val(ship.origin_port);
                $('#edit_origin_country').val(ship.origin_country);
                $('#edit_destination_port').val(ship.destination_port);
                $('#edit_destination_country').val(ship.destination_country);
                $('#edit_quantity_tons').val(ship.quantity_tons);
                $('#edit_wheat_type').val(ship.wheat_type);
                $('#edit_supplier_name').val(ship.supplier_name);
                $('#edit_departure_date').val(ship.departure_date);
                $('#edit_expected_arrival').val(ship.expected_arrival);
                $('#edit_actual_arrival').val(ship.actual_arrival);
                $('#edit_status').val(ship.status);
                $('#edit_total_cost').val(ship.total_cost);
                $('#edit_currency').val(ship.currency);
                $('#edit_payment_status').val(ship.payment_status);
                $('#edit_notes').val(ship.notes);
                
                $('#editShipmentModal').modal('show');
            }
        }
    });
}

// Update shipment
function updateShipment() {
    const formData = {};
    $('#editShipmentForm').serializeArray().forEach(item => {
        formData[item.name] = item.value;
    });
    
    $.ajax({
        url: '?ajax=update_shipment',
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify(formData),
        success: function(response) {
            if (response.success) {
                $('#editShipmentModal').modal('hide');
                showNotification('Shipment updated successfully', 'success');
                loadDashboard();
                if ($('#shipments-tab').hasClass('active')) {
                    loadShipments();
                }
            } else {
                showNotification('Error: ' + response.message, 'danger');
            }
        },
        error: function() {
            showNotification('Failed to update shipment', 'danger');
        }
    });
}

// Delete shipment
function deleteShipment(id, shipmentNumber) {
    if (confirm('Are you sure you want to delete shipment ' + shipmentNumber + '?')) {
        $.ajax({
            url: '?ajax=delete_shipment&id=' + id,
            method: 'GET',
            success: function(response) {
                if (response.success) {
                    showNotification('Shipment deleted successfully', 'success');
                    loadDashboard();
                    if ($('#shipments-tab').hasClass('active')) {
                        loadShipments();
                    }
                } else {
                    showNotification('Error: ' + response.message, 'danger');
                }
            }
        });
    }
}

// View shipment details
function viewShipment(id) {
    editShipment(id);
}

// Track shipment
function trackShipment(id) {
    $('#tracking-tab').tab('show');
    setTimeout(() => {
        loadShipmentTracking(id);
    }, 300);
}

// Load tracking shipments
function loadTrackingShipments() {
    $.ajax({
        url: '?ajax=get_shipments&status=In Transit',
        method: 'GET',
        success: function(response) {
            if (response.success) {
                renderTrackingList(response.data);
            }
        }
    });
}

// Render tracking list
function renderTrackingList(shipments) {
    const container = $('#trackingShipmentsList');
    container.empty();
    
    if (shipments.length === 0) {
        container.html('<div class="text-center text-muted py-3">No active shipments</div>');
        return;
    }
    
    shipments.forEach(ship => {
        const item = `
            <div class="card mb-2" style="cursor: pointer;" onclick="loadShipmentTracking(${ship.id})">
                <div class="card-body p-3">
                    <h6 class="mb-1">${ship.shipment_number}</h6>
                    <p class="mb-1 small">${ship.vessel_name}</p>
                    <small class="text-muted">${ship.origin_port} → ${ship.destination_port}</small>
                    ${ship.last_position_update ? '<br><small class="text-info"><i class="bi bi-geo-alt"></i> Updated: ' + formatDateTime(ship.last_position_update) + '</small>' : ''}
                </div>
            </div>
        `;
        container.append(item);
    });
}

// Load shipment tracking details
function loadShipmentTracking(id) {
    $.ajax({
        url: '?ajax=get_shipment&id=' + id,
        method: 'GET',
        success: function(response) {
            if (response.success) {
                renderTrackingDetails(response.data);
            }
        }
    });
}

// Render tracking details
function renderTrackingDetails(shipment) {
    const details = `
        <div class="row">
            <div class="col-md-6">
                <h5>${shipment.shipment_number}</h5>
                <p class="mb-2"><strong>Vessel:</strong> ${shipment.vessel_name}</p>
                <p class="mb-2"><strong>Route:</strong> ${shipment.origin_port} → ${shipment.destination_port}</p>
                <p class="mb-2"><strong>Status:</strong> <span class="status-badge status-${shipment.status.toLowerCase().replace(' ', '-')}">${shipment.status}</span></p>
            </div>
            <div class="col-md-6">
                <p class="mb-2"><strong>ETA:</strong> ${formatDate(shipment.expected_arrival)}</p>
                ${shipment.current_position_lat ? `
                    <p class="mb-2"><strong>Current Position:</strong><br>
                    Lat: ${shipment.current_position_lat}<br>
                    Lon: ${shipment.current_position_lon}<br>
                    <small class="text-muted">Updated: ${formatDateTime(shipment.last_position_update)}</small></p>
                ` : '<p class="mb-2 text-muted">No position data available</p>'}
                <button class="btn btn-sm btn-wheat" onclick="showAddPosition(${shipment.id})">
                    <i class="bi bi-plus-circle"></i> Add Position
                </button>
            </div>
        </div>
    `;
    
    $('#trackingDetails').html(details);
    
    // Render position history
    if (shipment.position_history && shipment.position_history.length > 0) {
        renderPositionHistory(shipment.position_history);
    } else {
        $('#positionHistory').html('<p class="text-muted text-center">No position history available</p>');
    }
}

// Render position history
function renderPositionHistory(positions) {
    const container = $('#positionHistory');
    container.empty();
    
    const table = `
        <table class="table table-sm table-hover">
            <thead>
                <tr>
                    <th>Date/Time</th>
                    <th>Latitude</th>
                    <th>Longitude</th>
                    <th>Speed</th>
                    <th>Course</th>
                    <th>Source</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                ${positions.map(pos => `
                    <tr>
                        <td><small>${formatDateTime(pos.recorded_at)}</small></td>
                        <td>${pos.latitude.toFixed(6)}</td>
                        <td>${pos.longitude.toFixed(6)}</td>
                        <td>${pos.speed_knots ? pos.speed_knots + ' kts' : '-'}</td>
                        <td>${pos.course ? pos.course + '°' : '-'}</td>
                        <td><span class="badge bg-secondary">${pos.position_source}</span></td>
                        <td><small>${pos.notes || '-'}</small></td>
                    </tr>
                `).join('')}
            </tbody>
        </table>
    `;
    
    container.html(table);
}

// Show add position modal
function showAddPosition(shipmentId) {
    $('#position_shipment_id').val(shipmentId);
    $('#addPositionForm')[0].reset();
    
    // Set current datetime
    const now = new Date();
    const datetime = now.toISOString().slice(0, 16);
    $('[name="recorded_at"]').val(datetime);
    
    $('#addPositionModal').modal('show');
}

// Save position
function savePosition() {
    const formData = {};
    $('#addPositionForm').serializeArray().forEach(item => {
        formData[item.name] = item.value;
    });
    
    $.ajax({
        url: '?ajax=add_position',
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify(formData),
        success: function(response) {
            if (response.success) {
                $('#addPositionModal').modal('hide');
                showNotification('Position added successfully', 'success');
                loadShipmentTracking(formData.shipment_id);
            } else {
                showNotification('Error: ' + response.message, 'danger');
            }
        },
        error: function() {
            showNotification('Failed to add position', 'danger');
        }
    });
}

// Load alerts
function loadAlerts() {
    $.ajax({
        url: '?ajax=get_alerts',
        method: 'GET',
        success: function(response) {
            if (response.success) {
                renderAlerts(response.data);
            }
        }
    });
}

// Render alerts
function renderAlerts(alerts) {
    const container = $('#alertsList');
    container.empty();
    
    if (alerts.length === 0) {
        container.html('<div class="text-center text-muted">No new alerts</div>');
        return;
    }
    
    alerts.forEach(alert => {
        const severityClass = alert.severity.toLowerCase();
        const item = `
            <div class="alert-item alert-${severityClass} p-2 mb-2 rounded" onclick="markAlertRead(${alert.id})">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <strong>${alert.alert_type}</strong>
                        ${alert.shipment_number ? '<br><small class="text-muted">' + alert.shipment_number + '</small>' : ''}
                        <p class="mb-0 mt-1 small">${alert.message}</p>
                        <small class="text-muted">${formatDateTime(alert.created_at)}</small>
                    </div>
                    <i class="bi bi-x-circle" style="cursor: pointer;"></i>
                </div>
            </div>
        `;
        container.append(item);
    });
}

// Mark alert as read
function markAlertRead(id) {
    $.ajax({
        url: '?ajax=mark_alert_read&id=' + id,
        method: 'GET',
        success: function() {
            loadAlerts();
        }
    });
}

// Load market data
function loadMarketData() {
    $.ajax({
        url: '?ajax=get_market_data&year=' + new Date().getFullYear(),
        method: 'GET',
        success: function(response) {
            if (response.success) {
                renderMarketData(response.data);
            }
        }
    });
}

// Render market data
function renderMarketData(data) {
    const tbody = $('#marketDataBody');
    tbody.empty();
    
    if (data.length === 0) {
        tbody.append('<tr><td colspan="6" class="text-center text-muted">No market data available. Click "Fetch Latest Data" to load from UN Comtrade.</td></tr>');
        return;
    }
    
    data.forEach(item => {
        const pricePerTon = item.export_value_usd && item.export_quantity_tons ? 
            (item.export_value_usd / item.export_quantity_tons).toFixed(2) : '-';
        
        const row = `
            <tr>
                <td><strong>${item.country_name || item.country_code}</strong></td>
                <td>${item.year}${item.month ? '-' + String(item.month).padStart(2, '0') : ''}</td>
                <td>${formatNumber(item.export_quantity_tons)}</td>
                <td>$${formatNumber(item.export_value_usd)}</td>
                <td>$${pricePerTon}</td>
                <td><small>${formatDateTime(item.fetched_at)}</small></td>
            </tr>
        `;
        tbody.append(row);
    });
}

// Fetch Comtrade data
function fetchComtradeData() {
    $('#fetchMarketData').prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Fetching...');
    
    $.ajax({
        url: '?ajax=fetch_comtrade',
        method: 'GET',
        success: function(response) {
            if (response.success) {
                showNotification('Market data fetched successfully!', 'success');
                loadMarketData();
            } else {
                showNotification('Error: ' + response.message, 'warning');
            }
        },
        error: function() {
            showNotification('Failed to fetch market data', 'danger');
        },
        complete: function() {
            $('#fetchMarketData').prop('disabled', false).html('<i class="bi bi-arrow-clockwise"></i> Fetch Latest Data');
        }
    });
}

// Utility functions
function formatNumber(num) {
    if (!num) return '0';
    return parseFloat(num).toLocaleString('en-US', { maximumFractionDigits: 2 });
}

function formatDate(dateStr) {
    if (!dateStr) return '-';
    const date = new Date(dateStr);
    return date.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
}

function formatDateTime(dateStr) {
    if (!dateStr) return '-';
    const date = new Date(dateStr);
    return date.toLocaleString('en-US', { 
        year: 'numeric', 
        month: 'short', 
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

function showNotification(message, type = 'info') {
    const alert = `
        <div class="alert alert-${type} alert-dismissible fade show" role="alert">
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    `;
    $('#alertsContainer').html(alert);
    
    setTimeout(() => {
        $('#alertsContainer').empty();
    }, 5000);
}
</script>

</body>
</html>