<?php
/**
 * DateRange Helper Class
 * 
 * Provides SQL date conditions and date calculations for dashboard widgets
 * based on user-selected date ranges.
 * 
 * Usage:
 *   $dateRange = new DateRange('this_month');
 *   $condition = $dateRange->getSqlCondition('created_at');
 *   $sql = "SELECT * FROM sales WHERE $condition";
 * 
 * @author Dashboard Enhancement Team
 * @version 1.0.0
 */

class DateRange {
    
    private $range;
    private $startDate;
    private $endDate;
    
    /**
     * Supported date ranges
     */
    const RANGES = [
        'today',
        'yesterday',
        'this_week',
        'last_week',
        'this_month',
        'last_month',
        'this_quarter',
        'last_quarter',
        'this_year',
        'last_year',
        'last_7_days',
        'last_30_days',
        'last_90_days',
        'last_365_days'
    ];
    
    /**
     * Constructor
     * 
     * @param string $range The date range identifier
     * @throws Exception if range is invalid
     */
    public function __construct($range = 'today') {
        if (!in_array($range, self::RANGES)) {
            throw new Exception("Invalid date range: {$range}");
        }
        
        $this->range = $range;
        $this->calculateDates();
    }
    
    /**
     * Calculate start and end dates based on range
     */
    private function calculateDates() {
        $now = new DateTime();
        
        switch ($this->range) {
            case 'today':
                $this->startDate = $now->format('Y-m-d 00:00:00');
                $this->endDate = $now->format('Y-m-d 23:59:59');
                break;
                
            case 'yesterday':
                $yesterday = clone $now;
                $yesterday->modify('-1 day');
                $this->startDate = $yesterday->format('Y-m-d 00:00:00');
                $this->endDate = $yesterday->format('Y-m-d 23:59:59');
                break;
                
            case 'this_week':
                // Start from Monday
                $week_start = clone $now;
                $week_start->modify('this week monday');
                $week_end = clone $week_start;
                $week_end->modify('+6 days');
                
                $this->startDate = $week_start->format('Y-m-d 00:00:00');
                $this->endDate = $week_end->format('Y-m-d 23:59:59');
                break;
                
            case 'last_week':
                $last_week = clone $now;
                $last_week->modify('last week monday');
                $week_end = clone $last_week;
                $week_end->modify('+6 days');
                
                $this->startDate = $last_week->format('Y-m-d 00:00:00');
                $this->endDate = $week_end->format('Y-m-d 23:59:59');
                break;
                
            case 'this_month':
                $this->startDate = $now->format('Y-m-01 00:00:00');
                $this->endDate = $now->format('Y-m-t 23:59:59');
                break;
                
            case 'last_month':
                $last_month = clone $now;
                $last_month->modify('first day of last month');
                $this->startDate = $last_month->format('Y-m-01 00:00:00');
                $this->endDate = $last_month->format('Y-m-t 23:59:59');
                break;
                
            case 'this_quarter':
                $quarter = ceil($now->format('n') / 3);
                $first_month = ($quarter - 1) * 3 + 1;
                $start = new DateTime($now->format('Y') . "-{$first_month}-01");
                $end = clone $start;
                $end->modify('+2 months');
                
                $this->startDate = $start->format('Y-m-01 00:00:00');
                $this->endDate = $end->format('Y-m-t 23:59:59');
                break;
                
            case 'last_quarter':
                $quarter = ceil($now->format('n') / 3);
                $last_quarter = $quarter - 1;
                if ($last_quarter < 1) {
                    $last_quarter = 4;
                    $year = $now->format('Y') - 1;
                } else {
                    $year = $now->format('Y');
                }
                
                $first_month = ($last_quarter - 1) * 3 + 1;
                $start = new DateTime("{$year}-{$first_month}-01");
                $end = clone $start;
                $end->modify('+2 months');
                
                $this->startDate = $start->format('Y-m-01 00:00:00');
                $this->endDate = $end->format('Y-m-t 23:59:59');
                break;
                
            case 'this_year':
                $this->startDate = $now->format('Y-01-01 00:00:00');
                $this->endDate = $now->format('Y-12-31 23:59:59');
                break;
                
            case 'last_year':
                $last_year = $now->format('Y') - 1;
                $this->startDate = "{$last_year}-01-01 00:00:00";
                $this->endDate = "{$last_year}-12-31 23:59:59";
                break;
                
            case 'last_7_days':
                $start = clone $now;
                $start->modify('-6 days');
                $this->startDate = $start->format('Y-m-d 00:00:00');
                $this->endDate = $now->format('Y-m-d 23:59:59');
                break;
                
            case 'last_30_days':
                $start = clone $now;
                $start->modify('-29 days');
                $this->startDate = $start->format('Y-m-d 00:00:00');
                $this->endDate = $now->format('Y-m-d 23:59:59');
                break;
                
            case 'last_90_days':
                $start = clone $now;
                $start->modify('-89 days');
                $this->startDate = $start->format('Y-m-d 00:00:00');
                $this->endDate = $now->format('Y-m-d 23:59:59');
                break;
                
            case 'last_365_days':
                $start = clone $now;
                $start->modify('-364 days');
                $this->startDate = $start->format('Y-m-d 00:00:00');
                $this->endDate = $now->format('Y-m-d 23:59:59');
                break;
        }
    }
    
    /**
     * Get SQL WHERE condition for a date column
     * 
     * @param string $column The date column name
     * @return string SQL condition
     */
    public function getSqlCondition($column = 'created_at') {
        return "{$column} BETWEEN '{$this->startDate}' AND '{$this->endDate}'";
    }
    
    /**
     * Get SQL WHERE condition using DATE() function
     * More efficient for indexed date columns
     * 
     * @param string $column The date column name
     * @return string SQL condition
     */
    public function getSqlDateCondition($column = 'created_at') {
        $start_date = date('Y-m-d', strtotime($this->startDate));
        $end_date = date('Y-m-d', strtotime($this->endDate));
        return "DATE({$column}) BETWEEN '{$start_date}' AND '{$end_date}'";
    }
    
    /**
     * Get start date
     * 
     * @param string $format PHP date format (default: Y-m-d H:i:s)
     * @return string Formatted start date
     */
    public function getStartDate($format = 'Y-m-d H:i:s') {
        return date($format, strtotime($this->startDate));
    }
    
    /**
     * Get end date
     * 
     * @param string $format PHP date format (default: Y-m-d H:i:s)
     * @return string Formatted end date
     */
    public function getEndDate($format = 'Y-m-d H:i:s') {
        return date($format, strtotime($this->endDate));
    }
    
    /**
     * Get human-readable label for the date range
     * 
     * @return string Label
     */
    public function getLabel() {
        $labels = [
            'today' => 'Today',
            'yesterday' => 'Yesterday',
            'this_week' => 'This Week',
            'last_week' => 'Last Week',
            'this_month' => 'This Month',
            'last_month' => 'Last Month',
            'this_quarter' => 'This Quarter',
            'last_quarter' => 'Last Quarter',
            'this_year' => 'This Year',
            'last_year' => 'Last Year',
            'last_7_days' => 'Last 7 Days',
            'last_30_days' => 'Last 30 Days',
            'last_90_days' => 'Last 90 Days',
            'last_365_days' => 'Last 365 Days'
        ];
        
        return $labels[$this->range] ?? $this->range;
    }
    
    /**
     * Get detailed date range description
     * 
     * @return string Description with actual dates
     */
    public function getDescription() {
        $start = date('M j, Y', strtotime($this->startDate));
        $end = date('M j, Y', strtotime($this->endDate));
        
        if ($start === $end) {
            return $start;
        }
        
        return "{$start} - {$end}";
    }
    
    /**
     * Get the range identifier
     * 
     * @return string
     */
    public function getRange() {
        return $this->range;
    }
    
    /**
     * Check if date range includes today
     * 
     * @return bool
     */
    public function includesToday() {
        $today = date('Y-m-d');
        $start = date('Y-m-d', strtotime($this->startDate));
        $end = date('Y-m-d', strtotime($this->endDate));
        
        return $today >= $start && $today <= $end;
    }
    
    /**
     * Get number of days in the range
     * 
     * @return int
     */
    public function getDayCount() {
        $start = new DateTime($this->startDate);
        $end = new DateTime($this->endDate);
        $diff = $start->diff($end);
        
        return $diff->days + 1; // +1 to include both start and end date
    }
    
    /**
     * Static method to get all available ranges with labels
     * 
     * @return array Associative array of range => label
     */
    public static function getAllRanges() {
        $ranges = [];
        foreach (self::RANGES as $range) {
            $dr = new self($range);
            $ranges[$range] = $dr->getLabel();
        }
        return $ranges;
    }
}

/**
 * USAGE EXAMPLES:
 * 
 * Example 1: Basic usage in dashboard widget
 * ==========================================
 * 
 * // Get user's preferred date range for a widget
 * $date_range = $widget_pref->date_range ?? 'today';
 * 
 * // Create DateRange object
 * $dateRange = new DateRange($date_range);
 * 
 * // Use in SQL query
 * $sql = "SELECT SUM(total_amount) as total 
 *         FROM sales 
 *         WHERE " . $dateRange->getSqlCondition('sale_date');
 * 
 * $total_sales = $db->query($sql)->first()->total ?? 0;
 * 
 * 
 * Example 2: Display date range in widget header
 * ===============================================
 * 
 * echo "<h3>Sales: " . $dateRange->getLabel() . "</h3>";
 * echo "<p class='text-sm'>" . $dateRange->getDescription() . "</p>";
 * // Output: 
 * //   Sales: This Month
 * //   Nov 1, 2025 - Nov 30, 2025
 * 
 * 
 * Example 3: Chart with date range
 * =================================
 * 
 * $dateRange = new DateRange('last_30_days');
 * 
 * $daily_sales = $db->query(
 *     "SELECT DATE(sale_date) as date, SUM(total_amount) as total
 *      FROM sales
 *      WHERE " . $dateRange->getSqlCondition('sale_date') . "
 *      GROUP BY DATE(sale_date)
 *      ORDER BY date"
 * )->results();
 * 
 * // Use $daily_sales for your chart
 * 
 * 
 * Example 4: Comparison widgets
 * ==============================
 * 
 * // Current period
 * $current = new DateRange('this_month');
 * $current_sales = $db->query(
 *     "SELECT SUM(total_amount) as total FROM sales 
 *      WHERE " . $current->getSqlCondition()
 * )->first()->total ?? 0;
 * 
 * // Previous period
 * $previous = new DateRange('last_month');
 * $previous_sales = $db->query(
 *     "SELECT SUM(total_amount) as total FROM sales 
 *      WHERE " . $previous->getSqlCondition()
 * )->first()->total ?? 0;
 * 
 * // Calculate percentage change
 * $change = $previous_sales > 0 
 *     ? (($current_sales - $previous_sales) / $previous_sales) * 100 
 *     : 0;
 * 
 * echo "Sales increased by " . round($change, 1) . "% compared to last month";
 * 
 * 
 * Example 5: Using in widget rendering
 * =====================================
 * 
 * // In your dashboard index.php or widget rendering:
 * function renderSalesWidget($widget_id, $date_range = 'today') {
 *     global $db;
 *     
 *     $dateRange = new DateRange($date_range);
 *     
 *     $data = $db->query(
 *         "SELECT 
 *             SUM(total_amount) as total_sales,
 *             COUNT(*) as order_count,
 *             AVG(total_amount) as avg_order
 *          FROM sales
 *          WHERE " . $dateRange->getSqlCondition('sale_date')
 *     )->first();
 *     
 *     echo '<div class="widget">';
 *     echo '<h3>Sales - ' . $dateRange->getLabel() . '</h3>';
 *     echo '<p class="date-range">' . $dateRange->getDescription() . '</p>';
 *     echo '<div class="stat-value">$' . number_format($data->total_sales, 2) . '</div>';
 *     echo '<div class="stat-details">';
 *     echo '  <span>' . $data->order_count . ' orders</span>';
 *     echo '  <span>$' . number_format($data->avg_order, 2) . ' avg</span>';
 *     echo '</div>';
 *     echo '</div>';
 * }
 * 
 * 
 * Example 6: Get all date ranges for dropdown
 * ============================================
 * 
 * $all_ranges = DateRange::getAllRanges();
 * 
 * echo '<select name="date_range">';
 * foreach ($all_ranges as $value => $label) {
 *     $selected = ($value === $current_range) ? 'selected' : '';
 *     echo "<option value='{$value}' {$selected}>{$label}</option>";
 * }
 * echo '</select>';
 * 
 * 
 * Example 7: Performance optimization with DATE() function
 * =========================================================
 * 
 * // If you have an index on DATE(sale_date), use:
 * $condition = $dateRange->getSqlDateCondition('sale_date');
 * 
 * // This is faster than:
 * $condition = $dateRange->getSqlCondition('sale_date');
 * 
 * // Because it can use the date index
 */