package com.northwind.freight.domain;

import java.math.BigDecimal;
import java.util.List;
import java.util.Map;

/**
 * Hard-coded carrier rate tables ported directly from the PHP monolith's
 * {@code carrier_rates.php}.
 *
 * <p>Structure mirrors the original PHP associative arrays:
 * <pre>
 *   $rates['FEDEX'][1] = 5.99;
 *   $rates['FEDEX'][2] = 8.49;
 *   ...
 * </pre>
 *
 * <p>Rate semantics:
 * <ul>
 *   <li>The value stored here is the <em>base component</em> added before the
 *       weight multiplier.  The full formula is:
 *       {@code finalRate = baseRate + (weightLbs * zone * 0.05)}</li>
 *   <li>A fuel surcharge of 8.5 % is applied on top of {@code finalRate} in
 *       {@link FreightRatingService}.</li>
 * </ul>
 *
 * <p>Estimated transit days per carrier per zone are also kept here because they
 * were embedded in the same PHP file and belong to the same "carrier capability"
 * concept.
 *
 * <p>This class is intentionally a pure data holder (no Spring beans, no I/O) so
 * that it can be used safely in unit tests without a Spring context.
 */
public final class CarrierRates {

    private CarrierRates() {
        // utility class — no instances
    }

    // -------------------------------------------------------------------------
    // Supported carriers
    // -------------------------------------------------------------------------

    public static final List<String> SUPPORTED_CARRIERS =
            List.of("FEDEX", "UPS", "USPS", "DHL");

    // -------------------------------------------------------------------------
    // Base rates: carrier → (zone → base_rate_per_lb)
    // Zones 1-8 are stored at index [zone] (index 0 is unused / null).
    // -------------------------------------------------------------------------

    /**
     * FedEx base rates per zone.
     * Source: {@code carrier_rates.php}, {@code $fedex_rates} array.
     */
    public static final Map<Integer, BigDecimal> FEDEX_RATES = Map.of(
            1, new BigDecimal("5.99"),
            2, new BigDecimal("8.49"),
            3, new BigDecimal("11.99"),
            4, new BigDecimal("15.49"),
            5, new BigDecimal("19.99"),
            6, new BigDecimal("24.99"),
            7, new BigDecimal("31.99"),
            8, new BigDecimal("39.99")
    );

    /**
     * UPS base rates per zone.
     * Source: {@code carrier_rates.php}, {@code $ups_rates} array.
     */
    public static final Map<Integer, BigDecimal> UPS_RATES = Map.of(
            1, new BigDecimal("5.49"),
            2, new BigDecimal("7.99"),
            3, new BigDecimal("11.49"),
            4, new BigDecimal("14.99"),
            5, new BigDecimal("19.49"),
            6, new BigDecimal("23.99"),
            7, new BigDecimal("30.99"),
            8, new BigDecimal("38.99")
    );

    /**
     * USPS base rates per zone.
     * Source: {@code carrier_rates.php}, {@code $usps_rates} array.
     */
    public static final Map<Integer, BigDecimal> USPS_RATES = Map.of(
            1, new BigDecimal("4.99"),
            2, new BigDecimal("6.99"),
            3, new BigDecimal("9.99"),
            4, new BigDecimal("12.99"),
            5, new BigDecimal("16.99"),
            6, new BigDecimal("20.99"),
            7, new BigDecimal("26.99"),
            8, new BigDecimal("33.99")
    );

    /**
     * DHL base rates per zone.
     * Source: {@code carrier_rates.php}, {@code $dhl_rates} array.
     */
    public static final Map<Integer, BigDecimal> DHL_RATES = Map.of(
            1, new BigDecimal("6.49"),
            2, new BigDecimal("9.49"),
            3, new BigDecimal("13.49"),
            4, new BigDecimal("17.49"),
            5, new BigDecimal("22.49"),
            6, new BigDecimal("27.99"),
            7, new BigDecimal("35.49"),
            8, new BigDecimal("44.99")
    );

    /**
     * Convenience lookup: carrier code → zone-rate map.
     */
    public static final Map<String, Map<Integer, BigDecimal>> ALL_RATES = Map.of(
            "FEDEX", FEDEX_RATES,
            "UPS",   UPS_RATES,
            "USPS",  USPS_RATES,
            "DHL",   DHL_RATES
    );

    // -------------------------------------------------------------------------
    // Estimated transit days: carrier → (zone → days)
    // These were in the PHP helper file alongside the rate tables.
    // -------------------------------------------------------------------------

    public static final Map<String, Map<Integer, Integer>> TRANSIT_DAYS = Map.of(
            "FEDEX", Map.of(1, 1, 2, 1, 3, 2, 4, 2, 5, 3, 6, 4, 7, 5, 8, 7),
            "UPS",   Map.of(1, 1, 2, 2, 3, 2, 4, 3, 5, 4, 6, 5, 7, 6, 8, 8),
            "USPS",  Map.of(1, 2, 2, 2, 3, 3, 4, 4, 5, 5, 6, 6, 7, 7, 8, 9),
            "DHL",   Map.of(1, 1, 2, 1, 3, 2, 4, 2, 5, 3, 6, 3, 7, 4, 8, 5)
    );
}
