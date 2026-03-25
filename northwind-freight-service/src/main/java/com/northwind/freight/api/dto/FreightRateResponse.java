package com.northwind.freight.api.dto;

import java.math.BigDecimal;
import java.time.Instant;

/**
 * Outbound payload returned by {@code POST /api/freight/rate}.
 *
 * <p>Using a Java 21 record keeps this type immutable and eliminates boilerplate.
 * Jackson serialises records out of the box (Spring Boot 3 / Jackson 2.15+).
 *
 * <p>Monetary fields use {@link BigDecimal} with scale-2 rounding applied in the service
 * layer so that JSON consumers always receive values like {@code 23.45}, never
 * {@code 23.449999999}.
 *
 * @param carrier        Carrier code as supplied in the request (FEDEX / UPS / USPS / DHL).
 * @param originZip      Origin ZIP code echoed from the request.
 * @param destZip        Destination ZIP code echoed from the request.
 * @param weightLbs      Shipment weight in pounds echoed from the request.
 * @param zone           Calculated shipping zone (1–8) per the PHP FreightCalc logic.
 * @param baseRate       Per-carrier, per-zone base rate before fuel surcharge.
 * @param fuelSurcharge  8.5 % surcharge applied on top of {@code baseRate}.
 * @param totalRate      {@code baseRate + fuelSurcharge}, rounded to 2 decimal places.
 * @param estimatedDays  Carrier-specific transit-day estimate for the computed zone.
 * @param calculatedAt   Server-side timestamp (UTC) when the rate was computed.
 */
public record FreightRateResponse(
        String carrier,
        String originZip,
        String destZip,
        Double weightLbs,
        Integer zone,
        BigDecimal baseRate,
        BigDecimal fuelSurcharge,
        BigDecimal totalRate,
        Integer estimatedDays,
        Instant calculatedAt
) {}
