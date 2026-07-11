<?php
/**
 * Wheat Shipment Manager Class
 * For saasurfm Platform Integration
 * * Handles all wheat shipment operations including:
 * - Shipment CRUD operations
 * - Position tracking
 * - Market data integration
 * - API caching
 * - Alert management
 * * Updated to use the Application's Database Wrapper
 * * FIX: LIMIT clauses are now interpolated to prevent quoting errors
 * * FIX: Added API Key support for UN Comtrade (fixes 401 error)
 */

class WheatShipmentManager {
    
    private $db;
    private $branch_id;
    private $user_id;
    
    // API Configuration
    private $api_config = [
        // Use the public endpoint by default. 
        // If you have a premium subscription, change to 'https://comtradeapi.un.org/data/v1/get'
        'comtrade_base_url' => 'https://comtradeapi.un.org/public/v1/get', 
        
        // INSERT YOUR KEY HERE: Register at https://comtradedeveloper.un.org/
        'comtrade_api_key' => '', 
        
        'marinetraffic_base_url' => 'https://services.marinetraffic.com/api',
        'cache_duration' => 3600, // 1 hour
    ];
    
    /**
     * Constructor
     * @param Database $db - Custom Database wrapper object
     */
    public function __construct($db, $branch_id = null, $user_id = null) {
        $this->db = $db;
        $this->branch_id = $branch_id;
        $this->user_id = $user_id;
    }
    
    /**
     * Get next shipment number
     */
    public function getNextShipmentNumber() {
        try {
            $year = date('Y');
            // Wrapper automatically quotes parameters, which is fine for LIKE
            $count = $this->db->query("SELECT COUNT(*) as count FROM wheat_shipments WHERE shipment_number LIKE ?", ["WS-{$year}-%"])->first();
            $next = ($count->count ?? 0) + 1;
            return 'WS-' . $year . '-' . str_pad($next, 3, '0', STR_PAD_LEFT);
            
        } catch (Exception $e) {
            return 'WS-' . date('Y') . '-' . uniqid();
        }
    }
    
    /**
     * Create new shipment
     */
    public function createShipment($data) {
        try {
            if (empty($data['shipment_number'])) {
                $data['shipment_number'] = $this->getNextShipmentNumber();
            }
            
            $fields = [
                'shipment_number' => $data['shipment_number'],
                'vessel_name' => $data['vessel_name'],
                'vessel_mmsi' => $data['vessel_mmsi'] ?? null,
                'vessel_imo' => $data['vessel_imo'] ?? null,
                'origin_port' => $data['origin_port'],
                'origin_country' => $data['origin_country'] ?? null,
                'destination_port' => $data['destination_port'],
                'destination_country' => $data['destination_country'] ?? 'Bangladesh',
                'quantity_tons' => $data['quantity_tons'],
                'wheat_type' => $data['wheat_type'] ?? null,
                'supplier_name' => $data['supplier_name'] ?? null,
                'departure_date' => $data['departure_date'] ?? null,
                'expected_arrival' => $data['expected_arrival'],
                'total_cost' => $data['total_cost'] ?? 0,
                'currency' => $data['currency'] ?? 'USD',
                'payment_status' => $data['payment_status'] ?? 'Pending',
                'branch_id' => $this->branch_id,
                'assigned_to' => $data['assigned_to'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => $data['status'] ?? 'Scheduled',
                'created_by' => $this->user_id,
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            $shipment_id = $this->db->insert('wheat_shipments', $fields);
            
            if ($shipment_id) {
                $this->createAlert($shipment_id, 'Arrival', 'Info', 
                    "Shipment {$data['shipment_number']} scheduled for arrival on {$data['expected_arrival']}");
                
                return [
                    'success' => true,
                    'shipment_id' => $shipment_id,
                    'shipment_number' => $data['shipment_number'],
                    'message' => 'Shipment created successfully'
                ];
            }
            
            return ['success' => false, 'message' => 'Failed to create shipment record'];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Update shipment
     */
    public function updateShipment($shipment_id, $data) {
        try {
            $fields = [
                'vessel_name' => $data['vessel_name'],
                'vessel_mmsi' => $data['vessel_mmsi'] ?? null,
                'vessel_imo' => $data['vessel_imo'] ?? null,
                'origin_port' => $data['origin_port'],
                'origin_country' => $data['origin_country'] ?? null,
                'destination_port' => $data['destination_port'],
                'destination_country' => $data['destination_country'] ?? null,
                'quantity_tons' => $data['quantity_tons'],
                'wheat_type' => $data['wheat_type'] ?? null,
                'supplier_name' => $data['supplier_name'] ?? null,
                'departure_date' => $data['departure_date'] ?? null,
                'expected_arrival' => $data['expected_arrival'],
                'actual_arrival' => $data['actual_arrival'] ?? null,
                'total_cost' => $data['total_cost'] ?? 0,
                'currency' => $data['currency'] ?? 'USD',
                'payment_status' => $data['payment_status'] ?? 'Pending',
                'assigned_to' => $data['assigned_to'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => $data['status'],
                'updated_by' => $this->user_id
            ];
            
            $this->db->update('wheat_shipments', $shipment_id, $fields);
            
            return ['success' => true, 'message' => 'Shipment updated successfully'];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Get all shipments with filters
     */
    public function getShipments($filters = []) {
        try {
            $sql = "SELECT * FROM wheat_shipments WHERE 1=1";
            $params = [];
            
            if (!empty($filters['status'])) {
                $sql .= " AND status = ?";
                $params[] = $filters['status'];
            }
            
            if (!empty($filters['date_from'])) {
                $sql .= " AND departure_date >= ?";
                $params[] = $filters['date_from'];
            }
            
            if (!empty($filters['date_to'])) {
                $sql .= " AND departure_date <= ?";
                $params[] = $filters['date_to'];
            }
            
            if (!empty($filters['search'])) {
                $sql .= " AND (shipment_number LIKE ? OR vessel_name LIKE ? OR supplier_name LIKE ?)";
                $term = "%{$filters['search']}%";
                $params[] = $term;
                $params[] = $term;
                $params[] = $term;
            }
            
            $sql .= " ORDER BY expected_arrival DESC, created_at DESC";
            
            // FIX: Interpolate LIMIT directly to avoid wrapper adding quotes
            if (!empty($filters['limit'])) {
                $limit = (int)$filters['limit'];
                $sql .= " LIMIT $limit";
            }

            $shipments = $this->db->query($sql, $params)->results();
            
            return ['success' => true, 'data' => $shipments];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Get single shipment details
     */
    public function getShipment($shipment_id) {
        try {
            $shipment = $this->db->query("SELECT * FROM wheat_shipments WHERE id = ?", [$shipment_id])->first();
            
            if ($shipment) {
                $shipment->position_history = $this->getPositionHistory($shipment_id);
                return ['success' => true, 'data' => $shipment];
            }
            
            return ['success' => false, 'message' => 'Shipment not found'];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Delete shipment
     */
    public function deleteShipment($shipment_id) {
        try {
            $this->db->query("DELETE FROM wheat_shipments WHERE id = ?", [$shipment_id]);
            return ['success' => true, 'message' => 'Shipment deleted successfully'];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Add position update
     */
    public function addPosition($shipment_id, $data) {
        try {
            $fields = [
                'shipment_id' => $shipment_id,
                'latitude' => $data['latitude'],
                'longitude' => $data['longitude'],
                'speed_knots' => $data['speed_knots'] ?? null,
                'course' => $data['course'] ?? null,
                'position_source' => $data['position_source'] ?? 'Manual',
                'recorded_at' => $data['recorded_at'] ?? date('Y-m-d H:i:s'),
                'notes' => $data['notes'] ?? null
            ];
            
            $this->db->insert('wheat_shipment_positions', $fields);
            
            $this->db->update('wheat_shipments', $shipment_id, [
                'current_position_lat' => $data['latitude'],
                'current_position_lon' => $data['longitude'],
                'last_position_update' => $data['recorded_at'] ?? date('Y-m-d H:i:s')
            ]);
            
            return ['success' => true, 'message' => 'Position added successfully'];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Get position history
     */
    public function getPositionHistory($shipment_id, $limit = 50) {
        try {
            // FIX: Interpolate LIMIT directly
            $limitInt = (int)$limit;
            return $this->db->query(
                "SELECT * FROM wheat_shipment_positions WHERE shipment_id = ? ORDER BY recorded_at DESC LIMIT $limitInt", 
                [$shipment_id]
            )->results();
        } catch (Exception $e) {
            return [];
        }
    }
    
    /**
     * Get dashboard summary
     */
    public function getDashboardSummary() {
        try {
            $summary_data = $this->db->query("SELECT status, COUNT(*) as count, SUM(quantity_tons) as total_tons FROM wheat_shipments GROUP BY status")->results();
            
            $summary = [];
            foreach ($summary_data as $row) {
                $summary[$row->status] = $row;
            }
            
            $stats = $this->db->query("SELECT 
                COUNT(*) as total_shipments,
                SUM(quantity_tons) as total_quantity,
                SUM(total_cost) as total_cost,
                COUNT(CASE WHEN status = 'In Transit' THEN 1 END) as in_transit,
                COUNT(CASE WHEN status = 'Scheduled' THEN 1 END) as scheduled,
                COUNT(CASE WHEN status = 'Arrived' THEN 1 END) as arrived
                FROM wheat_shipments
                WHERE status NOT IN ('Completed', 'Cancelled')")->first();
            
            return [
                'success' => true,
                'summary' => $summary,
                'stats' => $stats
            ];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Create alert
     */
    public function createAlert($shipment_id, $alert_type, $severity, $message) {
        try {
            $this->db->insert('wheat_alerts', [
                'shipment_id' => $shipment_id,
                'alert_type' => $alert_type,
                'severity' => $severity,
                'message' => $message,
                'is_read' => 0,
                'created_at' => date('Y-m-d H:i:s')
            ]);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Get unread alerts
     */
    public function getUnreadAlerts($limit = 10) {
        try {
            // FIX: Interpolate LIMIT directly. 
            // This was causing "Syntax error... near ''10''" because the wrapper quotes parameters.
            $limitInt = (int)$limit;
            
            $sql = "SELECT a.*, s.shipment_number, s.vessel_name 
                    FROM wheat_alerts a
                    LEFT JOIN wheat_shipments s ON a.shipment_id = s.id
                    WHERE a.is_read = 0
                    ORDER BY a.created_at DESC
                    LIMIT $limitInt";
            
            return ['success' => true, 'data' => $this->db->query($sql)->results()];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Mark alert as read
     */
    public function markAlertRead($alert_id) {
        try {
            $this->db->update('wheat_alerts', $alert_id, [
                'is_read' => 1, 
                'read_at' => date('Y-m-d H:i:s')
            ]);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Fetch UN Comtrade data with caching
     */
    public function fetchComtradeData($params = []) {
        try {
            $cache_key = 'comtrade_' . md5(json_encode($params));
            
            $cached = $this->getCache($cache_key);
            if ($cached) {
                return ['success' => true, 'data' => json_decode($cached, true), 'cached' => true];
            }
            
            $default_params = [
                'typeCode' => 'C',
                'freqCode' => 'A',
                'clCode' => 'HS',
                'period' => date('Y') - 1,
                'reporterCode' => '0', // All countries
                'cmdCode' => '1001', // Wheat
                'flowCode' => 'X', // Exports
                'partnerCode' => '0',
                'partner2Code' => '0',
                'customsCode' => 'C00',
                'motCode' => '0',
                'maxRecords' => 50,
                'format' => 'json',
                'includeDesc' => 'true'
            ];
            
            $query_params = array_merge($default_params, $params);
            
            $url = $this->api_config['comtrade_base_url'] . '/' . 
                   $query_params['typeCode'] . '/' .
                   $query_params['freqCode'] . '/' .
                   $query_params['clCode'];
                   
            unset($query_params['typeCode'], $query_params['freqCode'], $query_params['clCode']);
            
            $url .= '?' . http_build_query($query_params);
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
            
            // FIX: Add API Key Header if present
            if (!empty($this->api_config['comtrade_api_key'])) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Ocp-Apim-Subscription-Key: ' . $this->api_config['comtrade_api_key']
                ]);
            }
            
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($http_code == 200 && $response) {
                $this->setCache($cache_key, $response);
                
                $data = json_decode($response, true);
                $records = $data['data'] ?? $data['dataset'] ?? [];
                
                if (!empty($records) && is_array($records)) {
                    $this->storeMarketData($records, 'UN Comtrade');
                }
                
                return ['success' => true, 'data' => $data, 'cached' => false];
            }
            
            return ['success' => false, 'message' => 'Failed to fetch Comtrade data (Code: ' . $http_code . ') - API Key may be missing or invalid'];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Store market data
     * * FIX: Removed direct PDO prepare() to avoid "Call to undefined method Database::prepare()"
     * * Using raw query execution loop instead
     */
    private function storeMarketData($data, $source) {
        try {
            $sql = "INSERT INTO wheat_market_data (
                    data_source, country_code, country_name, year, month,
                    export_quantity_tons, export_value_usd,
                    data_period, raw_data, fetched_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    export_quantity_tons = VALUES(export_quantity_tons),
                    export_value_usd = VALUES(export_value_usd),
                    raw_data = VALUES(raw_data),
                    fetched_at = NOW()";
            
            foreach ($data as $record) {
                $country_code = $record['reporterCode'] ?? null;
                $country_name = $record['reporterDesc'] ?? null;
                $year = $record['period'] ?? date('Y');
                $month = null;
                $quantity = $record['netWgt'] ?? ($record['primaryValue'] / 300); // Fallback
                $value = $record['primaryValue'] ?? 0;
                $period = $record['period'] ?? null;
                $raw = json_encode($record);
                
                // Use the wrapper's query method
                $this->db->query($sql, [
                    $source, $country_code, $country_name, $year, $month,
                    $quantity, $value, $period, $raw
                ]);
            }
            return true;
        } catch (Exception $e) {
            error_log("Store Market Data Error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get market data
     */
    public function getMarketData($filters = []) {
        try {
            $sql = "SELECT * FROM wheat_market_data WHERE 1=1";
            $params = [];
            
            if (!empty($filters['year'])) {
                $sql .= " AND year = ?";
                $params[] = $filters['year'];
            }
            
            $sql .= " ORDER BY year DESC, export_quantity_tons DESC";
            
            // FIX: Interpolate LIMIT directly
            if (!empty($filters['limit'])) {
                $limit = (int)$filters['limit'];
                $sql .= " LIMIT $limit";
            }
            
            return ['success' => true, 'data' => $this->db->query($sql, $params)->results()];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Get cache
     */
    private function getCache($key) {
        try {
            $result = $this->db->query("SELECT cache_data FROM wheat_api_cache WHERE cache_key = ? AND expires_at > NOW()", [$key])->first();
            return $result ? $result->cache_data : null;
        } catch (Exception $e) {
            return null;
        }
    }
    
    /**
     * Set cache
     */
    private function setCache($key, $data, $duration = null) {
        try {
            $duration = $duration ?? $this->api_config['cache_duration'];
            $expires_at = date('Y-m-d H:i:s', time() + $duration);
            
            $exists = $this->db->query("SELECT id FROM wheat_api_cache WHERE cache_key = ?", [$key])->count();
            
            if ($exists) {
                $this->db->query("UPDATE wheat_api_cache SET cache_data = ?, expires_at = ? WHERE cache_key = ?", [$data, $expires_at, $key]);
            } else {
                $this->db->insert('wheat_api_cache', [
                    'cache_key' => $key,
                    'cache_data' => $data,
                    'expires_at' => $expires_at
                ]);
            }
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}