<?php
/**
 * Wheat Shipment Web Scraper Class
 * For saasurfm Platform Integration
 * 
 * NO API KEYS REQUIRED - Pure Web Scraping
 * Fetches wheat shipment data from public websites
 * 
 * @version 3.0
 * @date 2025-11-17
 */

class WheatShipmentScraper {
    
    private $db;
    private $branch_id;
    private $user_id;
    
    // Scraping Configuration
    private $config = [
        'cache_duration' => 3600, // 1 hour
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'timeout' => 30,
        'max_retries' => 3
    ];
    
    // Data Sources (Public URLs - No authentication required)
    private $sources = [
        'vesselfinder' => 'https://www.vesselfinder.com',
        'marinetraffic' => 'https://www.marinetraffic.com',
        'fleetmon' => 'https://www.fleetmon.com',
        'graincentral' => 'https://www.graincentral.com',
        'fao' => 'https://www.fao.org/faostat',
        'usda' => 'https://www.fas.usda.gov',
        'igc' => 'https://www.igc.int'
    ];
    
    public function __construct($db, $branch_id = null, $user_id = null) {
        $this->db = $db;
        $this->branch_id = $branch_id;
        $this->user_id = $user_id;
    }
    
    /**
     * Main function to scrape vessel data
     * Searches for bulk carriers carrying wheat
     */
    public function scrapeGlobalWheatShipments() {
        $results = [
            'success' => false,
            'vessels' => [],
            'market_data' => [],
            'news' => [],
            'errors' => []
        ];
        
        try {
            // 1. Scrape VesselFinder for bulk carriers
            $vessels = $this->scrapeVesselFinder();
            if (!empty($vessels)) {
                $results['vessels'] = array_merge($results['vessels'], $vessels);
            }
            
            // 2. Scrape FleetMon for grain carriers
            $fleetmon_vessels = $this->scrapeFleetMon();
            if (!empty($fleetmon_vessels)) {
                $results['vessels'] = array_merge($results['vessels'], $fleetmon_vessels);
            }
            
            // 3. Scrape wheat market news
            $news = $this->scrapeGrainNews();
            if (!empty($news)) {
                $results['news'] = $news;
            }
            
            // 4. Scrape market data from multiple sources
            $market_data = $this->scrapeMarketData();
            if (!empty($market_data)) {
                $results['market_data'] = $market_data;
            }
            
            // Save to database
            if (!empty($results['vessels'])) {
                $this->saveScrapedVessels($results['vessels']);
            }
            
            if (!empty($results['market_data'])) {
                $this->saveMarketData($results['market_data']);
            }
            
            $results['success'] = true;
            $results['scraped_at'] = date('Y-m-d H:i:s');
            
        } catch (Exception $e) {
            $results['errors'][] = $e->getMessage();
            error_log("Scraping Error: " . $e->getMessage());
        }
        
        return $results;
    }
    
    /**
     * Scrape VesselFinder for bulk carriers
     * Searches for vessels carrying grain/wheat to Bangladesh
     */
    private function scrapeVesselFinder() {
        $vessels = [];
        $cache_key = 'vesselfinder_bulk_carriers';
        
        // Check cache first
        $cached = $this->getCache($cache_key);
        if ($cached) {
            return json_decode($cached, true);
        }
        
        try {
            // Search for vessels heading to Bangladesh ports
            $ports = ['Chittagong', 'Mongla', 'Dhaka'];
            
            foreach ($ports as $port) {
                $url = "https://www.vesselfinder.com/vessels?name=&imo=&flag=&type=5&port_dest=" . urlencode($port);
                
                $html = $this->fetchURL($url);
                if (!$html) continue;
                
                // Parse HTML to extract vessel data
                $parsed = $this->parseVesselFinderHTML($html, $port);
                if (!empty($parsed)) {
                    $vessels = array_merge($vessels, $parsed);
                }
                
                sleep(2); // Respectful scraping - wait between requests
            }
            
            // Cache results
            if (!empty($vessels)) {
                $this->setCache($cache_key, json_encode($vessels));
            }
            
        } catch (Exception $e) {
            error_log("VesselFinder scraping error: " . $e->getMessage());
        }
        
        return $vessels;
    }
    
    /**
     * Parse VesselFinder HTML
     */
    private function parseVesselFinderHTML($html, $destination_port) {
        $vessels = [];
        
        try {
            // Use DOMDocument to parse HTML
            libxml_use_internal_errors(true);
            $dom = new DOMDocument();
            $dom->loadHTML($html);
            libxml_clear_errors();
            
            $xpath = new DOMXPath($dom);
            
            // Find vessel rows in the table
            // Adjust selectors based on actual VesselFinder HTML structure
            $rows = $xpath->query("//table[@class='vessels']//tr");
            
            foreach ($rows as $row) {
                $vessel = [];
                
                // Extract vessel name
                $name_node = $xpath->query(".//td[@class='v3']//a", $row);
                if ($name_node->length > 0) {
                    $vessel['vessel_name'] = trim($name_node->item(0)->textContent);
                }
                
                // Extract IMO
                $imo_node = $xpath->query(".//td[@class='v2']", $row);
                if ($imo_node->length > 0) {
                    $vessel['vessel_imo'] = trim($imo_node->item(0)->textContent);
                }
                
                // Extract MMSI
                $mmsi_node = $xpath->query(".//td[@class='v1']", $row);
                if ($mmsi_node->length > 0) {
                    $vessel['vessel_mmsi'] = trim($mmsi_node->item(0)->textContent);
                }
                
                // Extract current position
                $lat_node = $xpath->query(".//td[@class='lat']", $row);
                $lon_node = $xpath->query(".//td[@class='lon']", $row);
                if ($lat_node->length > 0 && $lon_node->length > 0) {
                    $vessel['current_position_lat'] = trim($lat_node->item(0)->textContent);
                    $vessel['current_position_lon'] = trim($lon_node->item(0)->textContent);
                }
                
                // Extract flag/country
                $flag_node = $xpath->query(".//td[@class='flag']//img/@title", $row);
                if ($flag_node->length > 0) {
                    $vessel['origin_country'] = trim($flag_node->item(0)->nodeValue);
                }
                
                // Set destination
                $vessel['destination_port'] = $destination_port;
                $vessel['destination_country'] = 'Bangladesh';
                $vessel['status'] = 'in_transit';
                $vessel['data_source'] = 'VesselFinder';
                $vessel['scraped_at'] = date('Y-m-d H:i:s');
                
                if (!empty($vessel['vessel_name'])) {
                    $vessels[] = $vessel;
                }
            }
            
        } catch (Exception $e) {
            error_log("HTML parsing error: " . $e->getMessage());
        }
        
        return $vessels;
    }
    
    /**
     * Scrape FleetMon for grain carriers
     */
    private function scrapeFleetMon() {
        $vessels = [];
        $cache_key = 'fleetmon_grain_carriers';
        
        $cached = $this->getCache($cache_key);
        if ($cached) {
            return json_decode($cached, true);
        }
        
        try {
            // FleetMon live map - filter for bulk carriers
            $url = "https://www.fleetmon.com/vessels/bulkcarrier_latest/";
            
            $html = $this->fetchURL($url);
            if (!$html) return $vessels;
            
            // Parse HTML
            $vessels = $this->parseFleetMonHTML($html);
            
            if (!empty($vessels)) {
                $this->setCache($cache_key, json_encode($vessels));
            }
            
        } catch (Exception $e) {
            error_log("FleetMon scraping error: " . $e->getMessage());
        }
        
        return $vessels;
    }
    
    /**
     * Parse FleetMon HTML
     */
    private function parseFleetMonHTML($html) {
        $vessels = [];
        
        try {
            libxml_use_internal_errors(true);
            $dom = new DOMDocument();
            $dom->loadHTML($html);
            libxml_clear_errors();
            
            $xpath = new DOMXPath($dom);
            
            // Find vessel information
            // Adjust based on FleetMon's actual HTML structure
            $vessel_divs = $xpath->query("//div[@class='vessel-item']");
            
            foreach ($vessel_divs as $div) {
                $vessel = [];
                
                // Extract vessel details
                $name = $xpath->query(".//span[@class='vessel-name']", $div);
                if ($name->length > 0) {
                    $vessel['vessel_name'] = trim($name->item(0)->textContent);
                }
                
                $imo = $xpath->query(".//span[@class='imo']", $div);
                if ($imo->length > 0) {
                    $vessel['vessel_imo'] = trim($imo->item(0)->textContent);
                }
                
                $vessel['vessel_type'] = 'Bulk Carrier';
                $vessel['data_source'] = 'FleetMon';
                $vessel['status'] = 'in_transit';
                $vessel['scraped_at'] = date('Y-m-d H:i:s');
                
                if (!empty($vessel['vessel_name'])) {
                    $vessels[] = $vessel;
                }
            }
            
        } catch (Exception $e) {
            error_log("FleetMon HTML parsing error: " . $e->getMessage());
        }
        
        return $vessels;
    }
    
    /**
     * Scrape wheat market news from GrainCentral
     */
    private function scrapeGrainNews() {
        $news = [];
        $cache_key = 'grain_news';
        
        $cached = $this->getCache($cache_key);
        if ($cached) {
            return json_decode($cached, true);
        }
        
        try {
            $url = "https://www.graincentral.com/markets/wheat/";
            
            $html = $this->fetchURL($url);
            if (!$html) return $news;
            
            libxml_use_internal_errors(true);
            $dom = new DOMDocument();
            $dom->loadHTML($html);
            libxml_clear_errors();
            
            $xpath = new DOMXPath($dom);
            
            // Find news articles
            $articles = $xpath->query("//article");
            
            foreach ($articles as $article) {
                $item = [];
                
                $title_node = $xpath->query(".//h2[@class='entry-title']//a", $article);
                if ($title_node->length > 0) {
                    $item['title'] = trim($title_node->item(0)->textContent);
                    $item['url'] = $title_node->item(0)->getAttribute('href');
                }
                
                $excerpt_node = $xpath->query(".//div[@class='entry-content']", $article);
                if ($excerpt_node->length > 0) {
                    $item['excerpt'] = trim($excerpt_node->item(0)->textContent);
                }
                
                $date_node = $xpath->query(".//time", $article);
                if ($date_node->length > 0) {
                    $item['published_date'] = $date_node->item(0)->getAttribute('datetime');
                }
                
                $item['source'] = 'GrainCentral';
                $item['category'] = 'wheat_market';
                
                if (!empty($item['title'])) {
                    $news[] = $item;
                }
            }
            
            // Limit to latest 10
            $news = array_slice($news, 0, 10);
            
            if (!empty($news)) {
                $this->setCache($cache_key, json_encode($news));
            }
            
        } catch (Exception $e) {
            error_log("News scraping error: " . $e->getMessage());
        }
        
        return $news;
    }
    
    /**
     * Scrape market data from multiple public sources
     */
    private function scrapeMarketData() {
        $market_data = [];
        
        try {
            // 1. Scrape USDA FAS reports
            $usda_data = $this->scrapeUSDAData();
            if (!empty($usda_data)) {
                $market_data = array_merge($market_data, $usda_data);
            }
            
            // 2. Scrape FAO statistics
            $fao_data = $this->scrapeFAOData();
            if (!empty($fao_data)) {
                $market_data = array_merge($market_data, $fao_data);
            }
            
            // 3. Scrape IGC data
            $igc_data = $this->scrapeIGCData();
            if (!empty($igc_data)) {
                $market_data = array_merge($market_data, $igc_data);
            }
            
        } catch (Exception $e) {
            error_log("Market data scraping error: " . $e->getMessage());
        }
        
        return $market_data;
    }
    
    /**
     * Scrape USDA FAS public reports
     */
    private function scrapeUSDAData() {
        $data = [];
        $cache_key = 'usda_wheat_data';
        
        $cached = $this->getCache($cache_key);
        if ($cached) {
            return json_decode($cached, true);
        }
        
        try {
            // USDA Production, Supply and Distribution (PS&D) tables
            $url = "https://apps.fas.usda.gov/psdonline/reporthandler.ashx?reportId=2101&templateId=8&format=html&commodityCode=0410000&countryCode=";
            
            $html = $this->fetchURL($url);
            if (!$html) return $data;
            
            // Parse USDA table data
            $data = $this->parseUSDATable($html);
            
            if (!empty($data)) {
                $this->setCache($cache_key, json_encode($data));
            }
            
        } catch (Exception $e) {
            error_log("USDA scraping error: " . $e->getMessage());
        }
        
        return $data;
    }
    
    /**
     * Parse USDA HTML tables
     */
    private function parseUSDATable($html) {
        $data = [];
        
        try {
            libxml_use_internal_errors(true);
            $dom = new DOMDocument();
            $dom->loadHTML($html);
            libxml_clear_errors();
            
            $xpath = new DOMXPath($dom);
            
            // Find data tables
            $tables = $xpath->query("//table");
            
            foreach ($tables as $table) {
                $rows = $xpath->query(".//tr", $table);
                
                foreach ($rows as $row) {
                    $cells = $xpath->query(".//td", $row);
                    
                    if ($cells->length >= 3) {
                        $item = [
                            'data_source' => 'USDA-FAS',
                            'data_type' => 'production',
                            'country' => trim($cells->item(0)->textContent),
                            'year' => trim($cells->item(1)->textContent),
                            'volume_tons' => $this->parseNumber($cells->item(2)->textContent),
                            'data_date' => date('Y-m-d'),
                            'scraped_at' => date('Y-m-d H:i:s')
                        ];
                        
                        if (!empty($item['country']) && $item['country'] != 'Country') {
                            $data[] = $item;
                        }
                    }
                }
            }
            
        } catch (Exception $e) {
            error_log("USDA table parsing error: " . $e->getMessage());
        }
        
        return $data;
    }
    
    /**
     * Scrape FAO statistics
     */
    private function scrapeFAOData() {
        $data = [];
        $cache_key = 'fao_wheat_data';
        
        $cached = $this->getCache($cache_key);
        if ($cached) {
            return json_decode($cached, true);
        }
        
        try {
            // FAO production statistics
            $url = "https://www.fao.org/faostat/en/#data/QCL";
            
            $html = $this->fetchURL($url);
            if (!$html) return $data;
            
            // Parse FAO data - structure depends on their actual site
            // This is a placeholder - adjust based on real HTML structure
            $data = $this->parseFAOHTML($html);
            
            if (!empty($data)) {
                $this->setCache($cache_key, json_encode($data));
            }
            
        } catch (Exception $e) {
            error_log("FAO scraping error: " . $e->getMessage());
        }
        
        return $data;
    }
    
    /**
     * Parse FAO HTML
     */
    private function parseFAOHTML($html) {
        // Implement based on actual FAO HTML structure
        // This is a template
        return [];
    }
    
    /**
     * Scrape IGC (International Grains Council) data
     */
    private function scrapeIGCData() {
        $data = [];
        $cache_key = 'igc_wheat_data';
        
        $cached = $this->getCache($cache_key);
        if ($cached) {
            return json_decode($cached, true);
        }
        
        try {
            $url = "https://www.igc.int/en/markets/marketinfo-wheat.aspx";
            
            $html = $this->fetchURL($url);
            if (!$html) return $data;
            
            // Parse IGC data
            $data = $this->parseIGCHTML($html);
            
            if (!empty($data)) {
                $this->setCache($cache_key, json_encode($data));
            }
            
        } catch (Exception $e) {
            error_log("IGC scraping error: " . $e->getMessage());
        }
        
        return $data;
    }
    
    /**
     * Parse IGC HTML
     */
    private function parseIGCHTML($html) {
        // Implement based on actual IGC HTML structure
        return [];
    }
    
    /**
     * Fetch URL with proper headers and error handling
     */
    private function fetchURL($url, $retry = 0) {
        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $this->config['timeout']);
            curl_setopt($ch, CURLOPT_USERAGENT, $this->config['user_agent']);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_ENCODING, 'gzip, deflate');
            
            // Additional headers to appear more like a real browser
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.5',
                'Accept-Encoding: gzip, deflate',
                'Connection: keep-alive',
                'Upgrade-Insecure-Requests: 1'
            ]);
            
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            // Handle response
            if ($http_code == 200 && $response) {
                return $response;
            } elseif ($retry < $this->config['max_retries']) {
                // Retry with exponential backoff
                sleep(pow(2, $retry));
                return $this->fetchURL($url, $retry + 1);
            } else {
                error_log("Failed to fetch $url - HTTP Code: $http_code, Error: $error");
                return false;
            }
            
        } catch (Exception $e) {
            error_log("Fetch error for $url: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Save scraped vessels to database
     */
    private function saveScrapedVessels($vessels) {
        try {
            foreach ($vessels as $vessel) {
                // Check if vessel already exists
                $exists = $this->db->query(
                    "SELECT id FROM wheat_shipments WHERE vessel_imo = ? OR vessel_mmsi = ?",
                    [$vessel['vessel_imo'] ?? '', $vessel['vessel_mmsi'] ?? '']
                )->first();
                
                if (!$exists) {
                    // Generate shipment number
                    $vessel['shipment_number'] = $this->generateShipmentNumber();
                    $vessel['created_by'] = $this->user_id;
                    $vessel['branch_id'] = $this->branch_id;
                    
                    // Insert new shipment
                    $this->db->insert('wheat_shipments', $vessel);
                } else {
                    // Update existing shipment
                    unset($vessel['shipment_number']);
                    $this->db->update('wheat_shipments', $vessel, ['id' => $exists->id]);
                }
            }
            
            return true;
            
        } catch (Exception $e) {
            error_log("Error saving vessels: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Save market data to database
     */
    private function saveMarketData($market_data) {
        try {
            foreach ($market_data as $data) {
                // Check if data already exists
                $exists = $this->db->query(
                    "SELECT id FROM wheat_market_data WHERE data_source = ? AND country = ? AND data_date = ?",
                    [$data['data_source'], $data['country'], $data['data_date']]
                )->first();
                
                if (!$exists) {
                    $this->db->insert('wheat_market_data', $data);
                } else {
                    $this->db->update('wheat_market_data', $data, ['id' => $exists->id]);
                }
            }
            
            return true;
            
        } catch (Exception $e) {
            error_log("Error saving market data: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Generate shipment number
     */
    private function generateShipmentNumber() {
        $year = date('Y');
        $count = $this->db->query(
            "SELECT COUNT(*) as count FROM wheat_shipments WHERE shipment_number LIKE ?",
            ["WS-{$year}-%"]
        )->first();
        
        $next = ($count->count ?? 0) + 1;
        return 'WS-' . $year . '-' . str_pad($next, 4, '0', STR_PAD_LEFT);
    }
    
    /**
     * Parse number from string (handles commas, spaces, etc.)
     */
    private function parseNumber($str) {
        $str = preg_replace('/[^\d.-]/', '', $str);
        return floatval($str);
    }
    
    /**
     * Get cached data
     */
    private function getCache($key) {
        try {
            $result = $this->db->query(
                "SELECT response_data FROM wheat_api_cache WHERE cache_key = ? AND expires_at > NOW()",
                [$key]
            )->first();
            
            return $result ? $result->response_data : null;
            
        } catch (Exception $e) {
            return null;
        }
    }
    
    /**
     * Set cache data
     */
    private function setCache($key, $data, $duration = null) {
        try {
            $duration = $duration ?? $this->config['cache_duration'];
            $expires_at = date('Y-m-d H:i:s', time() + $duration);
            
            $exists = $this->db->query("SELECT id FROM wheat_api_cache WHERE cache_key = ?", [$key])->first();
            
            if ($exists) {
                $this->db->update('wheat_api_cache', [
                    'response_data' => $data,
                    'expires_at' => $expires_at
                ], ['cache_key' => $key]);
            } else {
                $this->db->insert('wheat_api_cache', [
                    'cache_key' => $key,
                    'api_endpoint' => 'web_scraping',
                    'response_data' => $data,
                    'expires_at' => $expires_at
                ]);
            }
            
            return true;
            
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Get all shipments with pagination
     */
    public function getShipments($filters = [], $page = 1, $per_page = 20) {
        try {
            $offset = ($page - 1) * $per_page;
            
            $where = "1=1";
            $params = [];
            
            if (!empty($filters['status'])) {
                $where .= " AND status = ?";
                $params[] = $filters['status'];
            }
            
            if (!empty($filters['branch_id'])) {
                $where .= " AND branch_id = ?";
                $params[] = $filters['branch_id'];
            }
            
            if (!empty($filters['search'])) {
                $where .= " AND (shipment_number LIKE ? OR vessel_name LIKE ? OR origin_country LIKE ?)";
                $search_term = '%' . $filters['search'] . '%';
                $params[] = $search_term;
                $params[] = $search_term;
                $params[] = $search_term;
            }
            
            $sql = "SELECT * FROM wheat_shipments WHERE $where ORDER BY created_at DESC LIMIT $per_page OFFSET $offset";
            
            $results = $this->db->query($sql, $params)->results();
            
            // Get total count
            $count_sql = "SELECT COUNT(*) as total FROM wheat_shipments WHERE $where";
            $total = $this->db->query($count_sql, $params)->first();
            
            return [
                'success' => true,
                'data' => $results,
                'total' => $total->total ?? 0,
                'page' => $page,
                'per_page' => $per_page
            ];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Get dashboard statistics
     */
    public function getDashboardStats() {
        try {
            $stats = [];
            
            // Total shipments
            $total = $this->db->query("SELECT COUNT(*) as count FROM wheat_shipments")->first();
            $stats['total_shipments'] = $total->count ?? 0;
            
            // In transit
            $in_transit = $this->db->query("SELECT COUNT(*) as count FROM wheat_shipments WHERE status = 'in_transit'")->first();
            $stats['in_transit'] = $in_transit->count ?? 0;
            
            // Arrived
            $arrived = $this->db->query("SELECT COUNT(*) as count FROM wheat_shipments WHERE status IN ('port_arrival', 'customs', 'delivered')")->first();
            $stats['arrived'] = $arrived->count ?? 0;
            
            // Total quantity
            $quantity = $this->db->query("SELECT SUM(quantity_tons) as total FROM wheat_shipments")->first();
            $stats['total_quantity_tons'] = $quantity->total ?? 0;
            
            // Latest news count
            $news_count = $this->db->query("SELECT COUNT(*) as count FROM wheat_market_data WHERE data_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->first();
            $stats['recent_news'] = $news_count->count ?? 0;
            
            return ['success' => true, 'data' => $stats];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}