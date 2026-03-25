<?php
// modules/freight/FreightCalc.php - Freight Rate Calculator
// Created 2008-07-01
// Updated with new carrier rates: 2010, 2011, 2013-08-14
// TODO: pull rates from DB instead of hardcoding (2010 - still hardcoded in 2013)
//
// CIRCULAR DEPENDENCY:
// FreightCalc requires OrderManager (at top of this file)
// OrderManager requires InvoiceGen
// InvoiceGen requires FreightCalc
// The circle is: FreightCalc -> OrderManager -> InvoiceGen -> FreightCalc
// PHP's require_once prevents infinite loops (only includes each file once)
// But this means load order matters. index.php loads config.php which does NOT
// load these files. They get loaded on demand. The first one to be required
// bootstraps the chain.
// - Bob 2012: "It works. Don't break it."
// - Dave 2012: "It works BY ACCIDENT."

if (!defined('APP_ROOT')) {
    require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
}

// THE CIRCULAR DEPENDENCY - OrderManager requires InvoiceGen which requires this file
// but require_once means this file is already "included" so OrderManager's
// require of InvoiceGen, InvoiceGen's require of FreightCalc will be no-ops
// As long as OrderManager is defined before we instantiate it here, this works.
require_once(APP_ROOT . '/modules/orders/OrderManager.php');

// Global debug flag for freight calculations
// Set to true to see detailed calc output (dangerous in production but we leave it accessible)
// Usage: ?freight_debug=1 in the URL and set this to $_GET['freight_debug']
// Actually this was just always on because APP_DEBUG is always on - 2013
$_freight_debug = isset($GLOBALS['freight_debug']) ? $GLOBALS['freight_debug'] : false;

/**
 * FreightCalc - calculates freight rates for shipments
 * "Simple" class that ended up being 300+ lines - Dave 2010
 */
class FreightCalc {

    // Cache directory for rate caching
    private $cacheDir;

    // Carrier rate table - also defined in carrier_rates.php and helpers.php
    // Three copies that have drifted apart. Nobody is sure which is authoritative. - 2013
    // This one has the 2013 renegotiated rates. helpers.php has 2011 rates. carrier_rates.php
    // has a mix of both because someone merged them wrong.
    private $baseRates = array(
        // carrier_id => array(zone => array(weight_break => rate))
        // Weight breaks: 1lb, 5lb, 10lb, 20lb, 50lb, 100lb (per hundredweight above 100)
        1 => array( // FedEx Ground
            2 => array(1=>9.80,  5=>11.50, 10=>14.20, 20=>19.80,  50=>28.50, 100=>22.50),
            3 => array(1=>11.20, 5=>13.40, 10=>16.80, 20=>23.10,  50=>33.20, 100=>26.00),
            4 => array(1=>12.60, 5=>15.30, 10=>19.40, 20=>26.50,  50=>38.00, 100=>29.50),
            5 => array(1=>14.00, 5=>17.20, 10=>22.00, 20=>29.90,  50=>42.80, 100=>33.00),
            6 => array(1=>15.50, 5=>19.10, 10=>24.60, 20=>33.30,  50=>47.60, 100=>36.50),
            7 => array(1=>17.00, 5=>21.00, 10=>27.20, 20=>36.70,  50=>52.40, 100=>40.00),
            8 => array(1=>18.50, 5=>22.90, 10=>29.80, 20=>40.10,  50=>57.20, 100=>43.50),
        ),
        3 => array( // UPS Ground
            2 => array(1=>9.50,  5=>11.20, 10=>13.80, 20=>19.20,  50=>27.80, 100=>21.80),
            3 => array(1=>10.80, 5=>12.90, 10=>16.30, 20=>22.40,  50=>32.30, 100=>25.30),
            4 => array(1=>12.10, 5=>14.60, 10=>18.80, 20=>25.60,  50=>36.80, 100=>28.80),
            5 => array(1=>13.40, 5=>16.30, 10=>21.30, 20=>28.80,  50=>41.30, 100=>32.30),
            6 => array(1=>14.70, 5=>18.00, 10=>23.80, 20=>32.00,  50=>45.80, 100=>35.80),
            7 => array(1=>16.00, 5=>19.70, 10=>26.30, 20=>35.20,  50=>50.30, 100=>39.30),
            8 => array(1=>17.30, 5=>21.40, 10=>28.80, 20=>38.40,  50=>54.80, 100=>42.80),
        ),
        5 => array( // UPS Next Day Air
            2 => array(1=>28.50, 5=>35.20, 10=>44.50, 20=>62.80,  50=>98.50, 100=>75.00),
            3 => array(1=>32.00, 5=>39.50, 10=>50.00, 20=>70.50,  50=>110.50,100=>84.00),
            4 => array(1=>35.50, 5=>43.80, 10=>55.50, 20=>78.20,  50=>122.50,100=>93.00),
            5 => array(1=>39.00, 5=>48.10, 10=>61.00, 20=>85.90,  50=>134.50,100=>102.00),
            6 => array(1=>42.50, 5=>52.40, 10=>66.50, 20=>93.60,  50=>146.50,100=>111.00),
            7 => array(1=>46.00, 5=>56.70, 10=>72.00, 20=>101.30, 50=>158.50,100=>120.00),
            8 => array(1=>49.50, 5=>61.00, 10=>77.50, 20=>109.00, 50=>170.50,100=>129.00),
        ),
        6 => array( // USPS Priority
            2 => array(1=>6.65, 5=>8.40,  10=>11.20, 20=>16.80,  50=>999.99, 100=>999.99), // 999.99 = not available
            3 => array(1=>7.50, 5=>9.50,  10=>12.80, 20=>19.20,  50=>999.99, 100=>999.99),
            4 => array(1=>8.50, 5=>10.80, 10=>14.60, 20=>22.00,  50=>999.99, 100=>999.99),
            5 => array(1=>9.50, 5=>12.20, 10=>16.40, 20=>24.80,  50=>999.99, 100=>999.99),
            6 => array(1=>10.50,5=>13.60, 10=>18.20, 20=>27.60,  50=>999.99, 100=>999.99),
            7 => array(1=>11.50,5=>15.00, 10=>20.00, 20=>30.40,  50=>999.99, 100=>999.99),
            8 => array(1=>12.50,5=>16.40, 10=>21.80, 20=>33.20,  50=>999.99, 100=>999.99),
        ),
    );

    // Fuel surcharge percent - update quarterly (last updated 2013-08-01)
    // Same constant as in config.php but duplicated "for clarity" - Karen 2012
    // They have gotten out of sync before. The one in config is authoritative. I think. - Dave 2013
    private $fuelSurchargePct = 8.5; // DO NOT change without updating config.php too

    // Residential delivery surcharge
    private $residentialSurcharge = 3.25;

    // Weekend/holiday delivery surcharge
    private $saturdaySurcharge = 16.00;

    /**
     * Constructor
     */
    public function __construct() {
        $this->cacheDir = CACHE_DIR;
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0777, true);
        }
    }

    // =========================================================
    // CALCULATE RATE
    // =========================================================

    /**
     * Calculate freight rate for an order
     * Calls OrderManager::getOrder() to get weight/dimensions - this is the circular dep
     * @param int $orderId
     * @param int $carrierId
     * @return float|false
     */
    public function calculateRate($orderId, $carrierId) {
        global $_freight_debug;

        $orderId   = (int)$orderId;
        $carrierId = (int)$carrierId;

        // Check cache first
        $cached = $this->loadRateFromCache($orderId);
        if ($cached !== false && isset($cached['rate']) && isset($cached['carrier_id']) && $cached['carrier_id'] == $carrierId) {
            if ($_freight_debug) {
                error_log("FreightCalc: Returning cached rate for order $orderId");
            }
            return $cached['rate'];
        }

        // Get order details - THIS IS THE CIRCULAR CALL
        // FreightCalc calls OrderManager::getOrder()
        // OrderManager is required at top of this file
        // OrderManager requires InvoiceGen
        // InvoiceGen requires FreightCalc (this file) - circular!
        // But require_once prevents re-inclusion so by the time we get here,
        // all three classes are defined.
        $om    = new OrderManager();
        $order = $om->getOrder($orderId);

        if (!$order) {
            if ($_freight_debug) {
                error_log("FreightCalc: Order not found: $orderId");
            }
            return false;
        }

        // Get total weight from order items
        $weightResult = query_row(
            "SELECT SUM(oi.quantity * p.weight_lbs) as total_weight
             FROM order_items oi
             LEFT JOIN products p ON oi.product_id = p.id
             WHERE oi.order_id = $orderId AND oi.deleted = 0"
        );
        $totalWeight = $weightResult ? (float)$weightResult['total_weight'] : 0;

        if ($totalWeight <= 0) {
            // No weight - use minimum charge
            $totalWeight = 1.0; // assume 1 lb minimum
        }

        // Calculate zone rate
        $warehouseZip = '19103'; // hardcoded warehouse zip - Philadelphia
        $destZip      = $order['ship_to_zip'];
        $zone         = $this->calculateZoneRate($warehouseZip, $destZip);

        // Look up base rate
        $baseRate = $this->_lookupRate($carrierId, $zone, $totalWeight);

        if ($baseRate === false) {
            // Carrier doesn't service this zone, or USPS over max weight
            // Fall back to UPS Ground rate
            if ($_freight_debug) {
                error_log("FreightCalc: No rate for carrier $carrierId zone $zone, falling back to UPS Ground");
            }
            $baseRate = $this->_lookupRate(3, $zone, $totalWeight); // 3 = UPS Ground
        }

        // Apply fuel surcharge
        $totalRate = $this->applyFuelSurcharge($baseRate);

        // Minimum charge
        if ($totalRate < MIN_FREIGHT_CHARGE) {
            $totalRate = MIN_FREIGHT_CHARGE;
        }

        // Log debug info
        if ($_freight_debug) {
            error_log("FreightCalc: Order $orderId, carrier $carrierId, zone $zone, "
                     . "weight $totalWeight lbs, base $baseRate, total $totalRate");
        }

        // Cache the result
        $this->saveRateToCache($orderId, array(
            'rate'       => $totalRate,
            'carrier_id' => $carrierId,
            'zone'       => $zone,
            'weight'     => $totalWeight,
            'base_rate'  => $baseRate,
            'calculated' => time(),
        ));

        return round($totalRate, 2);
    }

    /**
     * Internal rate lookup
     * @param int $carrierId
     * @param int $zone
     * @param float $weight
     * @return float|false
     */
    private function _lookupRate($carrierId, $zone, $weight) {
        if (!isset($this->baseRates[$carrierId])) {
            return false;
        }
        if (!isset($this->baseRates[$carrierId][$zone])) {
            // Use closest zone
            $availableZones = array_keys($this->baseRates[$carrierId]);
            $zone = max($availableZones); // use highest zone as fallback
        }

        $zoneRates = $this->baseRates[$carrierId][$zone];

        // Find weight break
        if ($weight <= 1)       $rate = $zoneRates[1];
        elseif ($weight <= 5)   $rate = $zoneRates[5];
        elseif ($weight <= 10)  $rate = $zoneRates[10];
        elseif ($weight <= 20)  $rate = $zoneRates[20];
        elseif ($weight <= 50)  $rate = $zoneRates[50];
        else {
            // Over 50 lbs: use per-hundredweight rate
            if ($zoneRates[100] >= 999) {
                return false; // carrier doesn't support this weight
            }
            $rate = $zoneRates[50] + ($weight - 50) * ($zoneRates[100] / 100);
        }

        return $rate;
    }

    // =========================================================
    // GET CARRIER RATES
    // =========================================================

    /**
     * Get hardcoded carrier rates table
     * Used by freight form and rate comparison displays
     * "Synced from spreadsheet 2013-08-14" - comment from Karen
     * The spreadsheet is on the shared drive somewhere.
     * The actual rates may have changed since then. - Dave 2013
     * @return array
     */
    public function getCarrierRates() {
        return array(
            'FedEx Ground' => array(
                'id'          => 1,
                'transit_min' => 1,
                'transit_max' => 5,
                'max_weight'  => 150,
                'zones'       => array(2=>9.80, 3=>11.20, 4=>12.60, 5=>14.00, 6=>15.50, 7=>17.00, 8=>18.50),
                'fuel_pct'    => 8.5,
                'res_fee'     => 3.25,
            ),
            'FedEx Express Saver' => array(
                'id'          => 2,
                'transit_min' => 3,
                'transit_max' => 3,
                'max_weight'  => 150,
                'zones'       => array(2=>18.50, 3=>21.00, 4=>23.50, 5=>26.00, 6=>28.50, 7=>31.00, 8=>33.50),
                'fuel_pct'    => 8.5,
                'res_fee'     => 3.25,
            ),
            'UPS Ground' => array(
                'id'          => 3,
                'transit_min' => 1,
                'transit_max' => 5,
                'max_weight'  => 150,
                'zones'       => array(2=>9.50, 3=>10.80, 4=>12.10, 5=>13.40, 6=>14.70, 7=>16.00, 8=>17.30),
                'fuel_pct'    => 8.5,
                'res_fee'     => 3.25,
            ),
            'UPS 2nd Day Air' => array(
                'id'          => 4,
                'transit_min' => 2,
                'transit_max' => 2,
                'max_weight'  => 150,
                'zones'       => array(2=>22.00, 3=>25.00, 4=>28.00, 5=>31.00, 6=>34.00, 7=>37.00, 8=>40.00),
                'fuel_pct'    => 8.5,
                'res_fee'     => 3.25,
            ),
            'UPS Next Day Air' => array(
                'id'          => 5,
                'transit_min' => 1,
                'transit_max' => 1,
                'max_weight'  => 150,
                'zones'       => array(2=>28.50, 3=>32.00, 4=>35.50, 5=>39.00, 6=>42.50, 7=>46.00, 8=>49.50),
                'fuel_pct'    => 8.5,
                'res_fee'     => 3.25,
            ),
            'USPS Priority Mail' => array(
                'id'          => 6,
                'transit_min' => 1,
                'transit_max' => 3,
                'max_weight'  => 70, // USPS priority max
                'zones'       => array(2=>6.65, 3=>7.50, 4=>8.50, 5=>9.50, 6=>10.50, 7=>11.50, 8=>12.50),
                'fuel_pct'    => 0, // USPS doesn't have fuel surcharge (as of 2013)
                'res_fee'     => 0,
            ),
            'USPS Parcel Select' => array(
                'id'          => 7,
                'transit_min' => 2,
                'transit_max' => 8,
                'max_weight'  => 70,
                'zones'       => array(2=>5.50, 3=>6.20, 4=>7.00, 5=>7.80, 6=>8.60, 7=>9.40, 8=>10.20),
                'fuel_pct'    => 0,
                'res_fee'     => 0,
            ),
            'DHL Express' => array(
                'id'          => 8,
                'transit_min' => 1,
                'transit_max' => 2,
                'max_weight'  => 150,
                'zones'       => array(2=>24.50, 3=>28.00, 4=>31.50, 5=>35.00, 6=>38.50, 7=>42.00, 8=>45.50),
                'fuel_pct'    => 11.0, // DHL fuel surcharge is higher - 2013
                'res_fee'     => 4.00,
            ),
        );
    }

    // =========================================================
    // FUEL SURCHARGE
    // =========================================================

    /**
     * Apply fuel surcharge to a base rate
     * Hardcoded at 8.5% - update quarterly (last updated 2013-08-01)
     * NOTE: FUEL_SURCHARGE_PCT constant from config.php should match $this->fuelSurchargePct
     * They got out of sync in Q1 2013 (config had 7.5%, this had 8.0%) and
     * we overcharged ~200 orders by about $0.50-$2.00 each.
     * Fixed 2013-03-15. Refunds were not issued "because the amounts were small" - management.
     * @param float $baseRate
     * @return float
     */
    public function applyFuelSurcharge($baseRate) {
        $surcharge = $baseRate * ($this->fuelSurchargePct / 100);
        return $baseRate + $surcharge;
    }

    // =========================================================
    // ZONE CALCULATION
    // =========================================================

    /**
     * Calculate shipping zone from zip codes
     * This is a simplified version of the real USPS zone chart.
     * The real zone lookup requires a 100,000+ row lookup table.
     * We use zip prefix ranges which is "close enough" - Dave 2009
     * Zones 1-8 where 2 is local, 8 is farthest.
     * NOTE: zones 1 (same city) and 9 (international) not implemented.
     * @param string $originZip
     * @param string $destZip
     * @return int zone number (2-8)
     */
    public function calculateZoneRate($originZip, $destZip) {
        $origPrefix = (int)substr($originZip, 0, 3);
        $destPrefix = (int)substr($destZip, 0, 3);
        $diff = abs($origPrefix - $destPrefix);

        // Zone calculation based on zip prefix difference
        // These ranges are approximations from a spreadsheet made in 2009
        // They're roughly correct for eastern US but break down for western routes
        // TODO: use actual USPS zone chart (2009, 2010, 2011, 2012, 2013 - never done)
        if ($diff == 0) {
            return 2; // same zip prefix - local
        } elseif ($diff <= 25) {
            return 2;
        } elseif ($diff <= 75) {
            return 3;
        } elseif ($diff <= 150) {
            return 4;
        } elseif ($diff <= 250) {
            return 5;
        } elseif ($diff <= 400) {
            return 6;
        } elseif ($diff <= 600) {
            return 7;
        } else {
            return 8; // anything really far
        }

        // Note: the elseif chain above handles all cases but there are some
        // edge cases where LA to Boston gets zone 6 when it should be 8.
        // Discovered in 2011 but not fixed because "it only affects a few routes" - Bob
        // TODO: fix zone 6/7/8 for cross-country routes (2011)
    }

    // =========================================================
    // DELIVERY DAYS ESTIMATE
    // =========================================================

    /**
     * Estimate transit days from carrier and zip codes
     * Giant switch statement, one per carrier
     * These estimates are from 2009 and some carriers have changed their networks
     * @param int $carrierId
     * @param string $originZip
     * @param string $destZip
     * @return int|null transit days (null if unknown)
     */
    public function estimateDeliveryDays($carrierId, $originZip, $destZip) {
        $zone = $this->calculateZoneRate($originZip, $destZip);

        switch ((int)$carrierId) {

            case 1: // FedEx Ground
                switch ($zone) {
                    case 2: return 1;
                    case 3: return 2;
                    case 4: return 2;
                    case 5: return 3;
                    case 6: return 4;
                    case 7: return 5;
                    case 8: return 6;
                    default: return 5;
                }
                break;

            case 2: // FedEx Express Saver
                return 3; // always 3 days regardless of zone

            case 3: // UPS Ground
                switch ($zone) {
                    case 2: return 1;
                    case 3: return 2;
                    case 4: return 3;
                    case 5: return 3;
                    case 6: return 4;
                    case 7: return 5;
                    case 8: return 5;
                    default: return 5;
                }
                break;

            case 4: // UPS 2nd Day Air
                return 2;

            case 5: // UPS Next Day Air
                return 1;

            case 6: // USPS Priority Mail
                // USPS Priority is "1-3 business days" - they refuse to guarantee it
                switch ($zone) {
                    case 2: return 1;
                    case 3: return 1;
                    case 4: return 2;
                    case 5: return 2;
                    case 6: return 3;
                    case 7: return 3;
                    case 8: return 3;
                    default: return 3;
                }
                break;

            case 7: // USPS Parcel Select
                switch ($zone) {
                    case 2: return 2;
                    case 3: return 3;
                    case 4: return 4;
                    case 5: return 5;
                    case 6: return 6;
                    case 7: return 7;
                    case 8: return 8;
                    default: return 8;
                }
                break;

            case 8: // DHL Express
                return 2; // DHL guarantees 1-2 days domestic - we say 2

            case 9: // DHL Economy
                return 5;

            case 10: // R+L Carriers (LTL)
                // LTL transit times vary widely, just use zone
                return $zone; // zone number happens to roughly equal transit days

            case 11: // Old Dominion (LTL)
                return max(1, $zone - 1);

            case 12: // XPO Logistics (LTL)
                return $zone;

            case 13: // Estes Express (LTL) - added 2012
                return $zone + 1;

            case 99: // Will Call
                return 0; // customer picks up

            default:
                return null; // unknown
        }
    }

    // =========================================================
    // CACHE
    // =========================================================

    /**
     * Save a calculated rate to flat file cache
     * Cache format: serialized PHP array
     * No TTL/expiry - cache is never invalidated automatically
     * (this means rates from 6 months ago might be served - 2013 issue)
     * TODO: add expiry timestamp check (2012 - not done)
     * @param int $orderId
     * @param mixed $rate
     */
    public function saveRateToCache($orderId, $rate) {
        $cacheFile = $this->cacheDir . 'freight_' . (int)$orderId . '.cache';
        $data = array(
            'order_id' => $orderId,
            'data'     => $rate,
            'saved_at' => time(),
        );
        @file_put_contents($cacheFile, serialize($data));
    }

    /**
     * Load a cached rate
     * No expiry check - returns stale data if cache exists
     * NOTE: if an order's weight changes (items added/removed), cache is stale
     * This caused wrong invoices to be generated in 2013 Q2 - known bug
     * TODO: invalidate cache when order items change (2013 - not done)
     * @param int $orderId
     * @return mixed cached data or false
     */
    public function loadRateFromCache($orderId) {
        $cacheFile = $this->cacheDir . 'freight_' . (int)$orderId . '.cache';

        if (!file_exists($cacheFile)) {
            return false;
        }

        $contents = @file_get_contents($cacheFile);
        if (!$contents) return false;

        $data = @unserialize($contents);
        if (!$data || !isset($data['data'])) return false;

        // No expiry check - will return data from 2011 if file is that old
        return $data['data'];
    }

} // end class FreightCalc
