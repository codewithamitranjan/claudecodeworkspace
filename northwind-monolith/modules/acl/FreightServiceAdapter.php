<?php
/**
 * modules/acl/FreightServiceAdapter.php
 *
 * Anti-Corruption Layer: PHP Monolith  <-->  Java Spring Boot Freight Service
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * The monolith's internal order arrays use field names from 2008 (wt_lbs, origin_zip,
 * carrier_code, etc.). The new Java service uses clean domain language that will never
 * change to match legacy names. This adapter is the ONLY place that knows both shapes.
 *
 * The new service must never receive monolith-flavoured field names. The monolith
 * must never receive Java-flavoured field names. All translation happens here.
 *
 * ARCHITECTURE RULE: The ACL lives in the MONOLITH, never in the Java service.
 *
 * Feature flag: USE_NEW_FREIGHT_SERVICE env var (set in docker-compose.yml)
 *   - 'true'  => route through this adapter to new Spring Boot service
 *   - anything else => fall back to FreightCalc::calculateRate() directly
 *
 * Usage (in OrderManager.php):
 *   if (FreightServiceAdapter::shouldUseNewService()) {
 *       $adapter = new FreightServiceAdapter();
 *       $result  = $adapter->getRate($orderId);
 *   } else {
 *       $fc     = new FreightCalc();
 *       $result = $fc->calculateRate($orderId, $carrierId);
 *   }
 *
 * @see acl_config.php for flag documentation
 * @since 2026-03
 */

// Pull in the legacy classes we need for translation and fallback.
// FreightCalc and OrderManager have a circular dependency triangle among themselves;
// require_once means re-including them here is a no-op if already loaded.
if (!defined('APP_ROOT')) {
    require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
}
require_once(APP_ROOT . '/modules/orders/OrderManager.php');
require_once(APP_ROOT . '/modules/freight/FreightCalc.php');

class FreightServiceAdapter
{
    /**
     * Base URL of the Spring Boot freight service.
     * Populated from NEW_FREIGHT_SERVICE_URL env var; defaults to localhost:8080.
     *
     * @var string
     */
    private $baseUrl;

    /**
     * Constructor.
     *
     * Reads NEW_FREIGHT_SERVICE_URL from the environment so the service URL can
     * be changed via docker-compose.yml or Kubernetes config without code changes.
     * Default of http://localhost:8080 matches the Spring Boot dev default.
     */
    public function __construct()
    {
        $envUrl = getenv('NEW_FREIGHT_SERVICE_URL');
        // Fall back to localhost:8080 if not set; matches Spring Boot default server.port
        $this->baseUrl = ($envUrl !== false && $envUrl !== '') ? rtrim($envUrl, '/') : 'http://localhost:8080';
    }

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    /**
     * Get a freight rate for the given order.
     *
     * Translates the legacy order array to the new service's JSON contract,
     * calls POST /api/freight/rate, then translates the JSON response back to
     * the legacy rate array format that OrderManager and InvoiceGen expect.
     *
     * On any HTTP or curl error the method logs to error_log() and falls back
     * to FreightCalc::calculateRate() so the monolith keeps working even if
     * the new service is down.
     *
     * @param  int  $orderId  Legacy integer order ID.
     * @return array|false    Legacy format: ['rate_cents' => int, 'zone' => int, 'est_days' => int]
     *                        false on total failure (same as FreightCalc failure semantics).
     */
    public function getRate($orderId)
    {
        $orderId = (int)$orderId;

        // ------------------------------------------------------------------
        // STEP 1: Fetch the order using the existing OrderManager.
        // We do NOT reach into the DB directly — OrderManager is the source
        // of truth for order data and must remain so during the transition.
        // ------------------------------------------------------------------
        $om    = new OrderManager();
        $order = $om->getOrder($orderId);

        if (!$order) {
            error_log("FreightServiceAdapter: order not found for orderId=$orderId");
            return false;
        }

        // ------------------------------------------------------------------
        // STEP 2: Translate legacy order fields to new service contract.
        //
        // Field mapping rationale:
        //   wt_lbs    -> weightLbs
        //     The monolith stores weight in pounds (imperial) because the DB
        //     schema was designed in 2008 before we considered metric.
        //     The new service also accepts pounds; the field is renamed to
        //     camelCase to match Java/JSON naming conventions.
        //
        //   origin_zip -> originZip
        //     Monolith uses snake_case (PHP/MySQL convention, 2008).
        //     New service uses camelCase (Java/JSON convention).
        //     The VALUE is unchanged — both store the 5-digit US ZIP string.
        //
        //   dest_zip -> destZip
        //     Same renaming reason as origin_zip above.
        //
        //   carrier_code -> carrier
        //     The monolith stores 3-letter shortcodes (FDX, UPS, USP, DHL)
        //     from the legacy carrier_rates.php lookup table dating to 2008.
        //     The new service uses standard carrier identifiers that match
        //     the carrier API names (FEDEX, UPS, USPS, DHL). The codes differ
        //     because FedEx changed their preferred abbreviation after 2008,
        //     and USPS was entered as USP (typo, then frozen to avoid DB migration).
        // ------------------------------------------------------------------
        $carrierMap = array(
            'FDX' => 'FEDEX', // FedEx: legacy 3-char code vs. FedEx's own brand abbreviation
            'UPS' => 'UPS',   // UPS: same both sides, included for explicitness
            'USP' => 'USPS',  // USPS: legacy typo (missing S) frozen since 2008
            'DHL' => 'DHL',   // DHL: same both sides
        );

        $legacyCarrierCode = isset($order['carrier_code']) ? strtoupper(trim($order['carrier_code'])) : '';
        if (!array_key_exists($legacyCarrierCode, $carrierMap)) {
            error_log("FreightServiceAdapter: unknown carrier_code '$legacyCarrierCode' for orderId=$orderId; falling back");
            return $this->_fallback($orderId, $legacyCarrierCode);
        }

        // Build the request payload in the new service's clean contract shape.
        $requestPayload = array(
            // weightLbs: renamed from wt_lbs; value unchanged (still imperial pounds)
            'weightLbs'  => (float)$order['wt_lbs'],

            // originZip: renamed from origin_zip; 5-digit ZIP string, value unchanged
            'originZip'  => (string)$order['origin_zip'],

            // destZip: renamed from dest_zip; 5-digit ZIP string, value unchanged
            'destZip'    => (string)$order['dest_zip'],

            // carrier: mapped from 3-char legacy code to standard carrier identifier
            'carrier'    => $carrierMap[$legacyCarrierCode],
        );

        // ------------------------------------------------------------------
        // STEP 3: POST to the new service.
        // ------------------------------------------------------------------
        $endpoint = $this->baseUrl . '/api/freight/rate';
        $jsonBody  = json_encode($requestPayload);

        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Accept: application/json',
            'Content-Length: ' . strlen($jsonBody),
        ));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5); // 5-second timeout — must not block order flow
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);

        $rawResponse = curl_exec($ch);
        $httpStatus  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError   = curl_error($ch);
        curl_close($ch);

        // ------------------------------------------------------------------
        // STEP 4: Error handling with fallback to legacy FreightCalc.
        //
        // We treat any non-2xx status OR a curl transport error as "new service
        // unavailable". Rather than surfacing an error to the user we fall back
        // silently to the old FreightCalc path. This implements the Strangler Fig
        // safety net: the monolith is always the last line of defence.
        // ------------------------------------------------------------------
        if ($curlError !== '') {
            error_log("FreightServiceAdapter: curl error calling $endpoint for orderId=$orderId: $curlError");
            return $this->_fallback($orderId, $legacyCarrierCode);
        }

        if ($httpStatus < 200 || $httpStatus >= 300) {
            error_log("FreightServiceAdapter: HTTP $httpStatus from $endpoint for orderId=$orderId; body=" . substr($rawResponse, 0, 200));
            return $this->_fallback($orderId, $legacyCarrierCode);
        }

        $decoded = json_decode($rawResponse, true);
        if (!is_array($decoded)) {
            error_log("FreightServiceAdapter: invalid JSON response from $endpoint for orderId=$orderId");
            return $this->_fallback($orderId, $legacyCarrierCode);
        }

        // ------------------------------------------------------------------
        // STEP 5: Translate the JSON response back to the legacy array format.
        //
        // Response field mapping rationale:
        //   totalRate -> rate_cents
        //     The new service returns totalRate as a decimal dollar amount
        //     (e.g. 47.82) because that is the natural unit in Java BigDecimal.
        //     The monolith stores and passes rates in integer cents (e.g. 4782)
        //     because the original PHP code used integer arithmetic to avoid
        //     float rounding issues (see InvoiceGen.php line ~220).
        //     Multiply by 100 and cast to int to get cents.
        //
        //   zone -> zone
        //     Integer zone number (2–8). Same semantics both sides; no conversion
        //     needed beyond ensuring PHP receives an int.
        //
        //   estimatedDays -> est_days
        //     Renamed for legacy snake_case convention and abbreviated to match
        //     the key that OrderManager and order_detail.php already read.
        // ------------------------------------------------------------------
        $totalRateDollars = isset($decoded['totalRate']) ? (float)$decoded['totalRate'] : 0.0;

        $legacyResult = array(
            // rate_cents: totalRate (dollars, float) * 100 → integer cents
            'rate_cents' => (int)round($totalRateDollars * 100),

            // zone: unchanged integer zone number (2–8 scale, same in both systems)
            'zone'       => isset($decoded['zone'])          ? (int)$decoded['zone']          : 0,

            // est_days: estimatedDays renamed to snake_case abbreviation used throughout monolith
            'est_days'   => isset($decoded['estimatedDays']) ? (int)$decoded['estimatedDays'] : 0,
        );

        return $legacyResult;
    }

    /**
     * Check whether the new freight service is reachable.
     *
     * Pings the Spring Boot Actuator health endpoint.  Returns true only if
     * the HTTP response is 200; any other status or curl failure returns false.
     *
     * Spring Boot Actuator exposes /actuator/health by default and returns
     * {"status":"UP"} when healthy.  We don't parse the body — a 200 is enough
     * to confirm the service is up.
     *
     * @return bool
     */
    public function isNewServiceAvailable()
    {
        $url = $this->baseUrl . '/actuator/health';

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json'));

        curl_exec($ch);
        $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError  = curl_error($ch);
        curl_close($ch);

        if ($curlError !== '') {
            error_log("FreightServiceAdapter::isNewServiceAvailable: curl error: $curlError");
            return false;
        }

        return ($httpStatus === 200);
    }

    /**
     * Whether the application is configured to route through the new service.
     *
     * Reads USE_NEW_FREIGHT_SERVICE env var.  Only the exact string 'true'
     * enables the new path — anything else (missing, 'false', '1', etc.) keeps
     * the legacy FreightCalc path active.  This matches the docker-compose.yml
     * convention documented in acl_config.php.
     *
     * Declared static so call sites can gate the constructor without
     * instantiating the adapter unnecessarily:
     *
     *   if (FreightServiceAdapter::shouldUseNewService()) {
     *       $adapter = new FreightServiceAdapter();
     *       // ...
     *   }
     *
     * @return bool
     */
    public static function shouldUseNewService()
    {
        return (getenv('USE_NEW_FREIGHT_SERVICE') === 'true');
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Fallback: call the legacy FreightCalc directly.
     *
     * Invoked when the new service is unreachable or returns an error.  Maps the
     * legacy carrier code back to a carrierId integer so FreightCalc can look up
     * its internal rate table.
     *
     * The return value is shaped to match the legacy rate array format so callers
     * see a consistent interface regardless of which path was taken.
     *
     * NOTE: FreightCalc::calculateRate() returns a dollar float, not cents.
     * We convert here to keep the adapter's return contract uniform.
     *
     * @param  int    $orderId
     * @param  string $legacyCarrierCode   e.g. 'FDX', 'UPS', 'USP', 'DHL'
     * @return array|false
     */
    private function _fallback($orderId, $legacyCarrierCode)
    {
        // Carrier code to legacy integer ID mapping (matches FreightCalc::$baseRates keys
        // and the carriers table in northwind_schema.sql).
        $codeToId = array(
            'FDX' => 1, // FedEx Ground
            'UPS' => 3, // UPS Ground
            'USP' => 6, // USPS Priority (legacy typo, frozen)
            'DHL' => 8, // DHL Express
        );

        $carrierId = isset($codeToId[$legacyCarrierCode]) ? $codeToId[$legacyCarrierCode] : 3; // default UPS Ground

        $fc        = new FreightCalc();
        $rateDollars = $fc->calculateRate($orderId, $carrierId);

        if ($rateDollars === false) {
            return false;
        }

        // FreightCalc returns a dollar float; convert to the same cents-based
        // array shape the primary path returns so callers need no branching.
        $om    = new OrderManager();
        $order = $om->getOrder($orderId);

        $originZip = isset($order['origin_zip']) ? $order['origin_zip'] : '19103';
        $destZip   = isset($order['dest_zip'])   ? $order['dest_zip']   : '00000';
        $zone      = $fc->calculateZoneRate($originZip, $destZip);
        $estDays   = $fc->estimateDeliveryDays($carrierId, $originZip, $destZip);

        return array(
            'rate_cents' => (int)round($rateDollars * 100),
            'zone'       => (int)$zone,
            'est_days'   => (int)$estDays,
        );
    }

} // end class FreightServiceAdapter
