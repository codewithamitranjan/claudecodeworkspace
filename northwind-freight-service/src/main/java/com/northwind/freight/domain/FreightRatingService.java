package com.northwind.freight.domain;

import com.northwind.freight.api.dto.FreightRateRequest;
import com.northwind.freight.api.dto.FreightRateResponse;
import org.springframework.stereotype.Service;

import java.math.BigDecimal;
import java.math.RoundingMode;
import java.time.Instant;
import java.util.Map;

/**
 * Core business logic for freight rate calculation.
 *
 * <p>This service is a faithful Java port of the PHP monolith's {@code FreightCalc.php}.
 * All algorithmic decisions — including the Florida (320-349) zone-4 classification
 * that is technically a bug — are preserved intentionally so that the new service
 * produces byte-for-byte identical rates to the legacy system during the Strangler Fig
 * transition period.
 *
 * <h2>Rate formula (matches PHP implementation)</h2>
 * <pre>
 *   zone        = calculateZone(originZip, destZip)
 *   baseRate    = carrierBaseRate[carrier][zone] + (weightLbs * zone * 0.05)
 *   surcharge   = baseRate * 0.085
 *   totalRate   = baseRate + surcharge
 * </pre>
 *
 * <p>All monetary arithmetic uses {@link BigDecimal} with {@link RoundingMode#HALF_UP}
 * and scale 2 to avoid floating-point drift across large shipment volumes.
 */
@Service
public class FreightRatingService {

    /** Fuel surcharge percentage, expressed as a multiplier fraction. */
    private static final BigDecimal FUEL_SURCHARGE_RATE = new BigDecimal("0.085");

    /** Weight multiplier constant from {@code FreightCalc.php} — {@code 0.05} per lb per zone. */
    private static final BigDecimal WEIGHT_ZONE_MULTIPLIER = new BigDecimal("0.05");

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Calculate a freight rate for the supplied request.
     *
     * @param request validated inbound DTO
     * @return fully-populated response record
     * @throws IllegalArgumentException if the carrier code is not supported
     *         (belt-and-suspenders guard; Bean Validation should catch it first)
     */
    public FreightRateResponse calculateRate(FreightRateRequest request) {
        String carrier = request.getCarrier().toUpperCase();

        Map<Integer, BigDecimal> zoneRates = CarrierRates.ALL_RATES.get(carrier);
        if (zoneRates == null) {
            throw new IllegalArgumentException(
                    "Unsupported carrier: " + carrier +
                    ". Supported carriers: " + CarrierRates.SUPPORTED_CARRIERS);
        }

        int zone = calculateZone(request.getOriginZip(), request.getDestZip());

        BigDecimal weightBd       = BigDecimal.valueOf(request.getWeightLbs());
        BigDecimal zoneBd         = BigDecimal.valueOf(zone);
        BigDecimal carrierBase    = zoneRates.get(zone);

        // baseRate = carrierBase + (weight * zone * 0.05)
        BigDecimal weightComponent = weightBd
                .multiply(zoneBd)
                .multiply(WEIGHT_ZONE_MULTIPLIER)
                .setScale(2, RoundingMode.HALF_UP);

        BigDecimal baseRate = carrierBase.add(weightComponent)
                .setScale(2, RoundingMode.HALF_UP);

        BigDecimal fuelSurcharge = applyFuelSurcharge(baseRate)
                .subtract(baseRate)
                .setScale(2, RoundingMode.HALF_UP);

        BigDecimal totalRate = baseRate.add(fuelSurcharge)
                .setScale(2, RoundingMode.HALF_UP);

        int estimatedDays = getEstimatedDays(carrier, zone);

        return new FreightRateResponse(
                carrier,
                request.getOriginZip(),
                request.getDestZip(),
                request.getWeightLbs(),
                zone,
                baseRate,
                fuelSurcharge,
                totalRate,
                estimatedDays,
                Instant.now()
        );
    }

    // -------------------------------------------------------------------------
    // Zone calculation — ported verbatim from FreightCalc.php
    // -------------------------------------------------------------------------

    /**
     * Determine the shipping zone based on the numeric difference between the
     * first three digits of origin and destination ZIP codes.
     *
     * <p><strong>Bug note (intentional):</strong> The PHP source uses a simple
     * absolute numeric difference on the 3-digit prefix. This means Florida ZIPs
     * (320–349) sometimes land in zone 4 when they should logically be zone 5 or
     * higher for distant origins.  This behaviour is preserved so that in-flight
     * shipments quoted by the monolith are not repriced by this service.
     * Tracked as {@code NW-FREIGHT-42} for future remediation.
     *
     * @param originZip 5-digit origin ZIP
     * @param destZip   5-digit destination ZIP
     * @return zone 1–8
     */
    public int calculateZone(String originZip, String destZip) {
        int originPrefix = Integer.parseInt(originZip.substring(0, 3));
        int destPrefix   = Integer.parseInt(destZip.substring(0, 3));
        int diff         = Math.abs(originPrefix - destPrefix);

        if (diff == 0)          return 1;
        if (diff <= 50)         return 2;
        if (diff <= 100)        return 3;
        if (diff <= 200)        return 4;
        if (diff <= 300)        return 5;
        if (diff <= 400)        return 6;
        if (diff <= 500)        return 7;
        return                         8;
    }

    // -------------------------------------------------------------------------
    // Surcharge helper
    // -------------------------------------------------------------------------

    /**
     * Apply the 8.5 % fuel surcharge to a base rate and return the gross amount
     * (i.e. {@code baseRate * 1.085}, not just the surcharge delta).
     *
     * @param baseRate the pre-surcharge rate
     * @return {@code baseRate * 1.085} rounded to 2 decimal places
     */
    public BigDecimal applyFuelSurcharge(BigDecimal baseRate) {
        return baseRate
                .multiply(BigDecimal.ONE.add(FUEL_SURCHARGE_RATE))
                .setScale(2, RoundingMode.HALF_UP);
    }

    // -------------------------------------------------------------------------
    // Transit-day lookup
    // -------------------------------------------------------------------------

    /**
     * Return the carrier-specific estimated transit days for a zone.
     *
     * @param carrier carrier code (FEDEX / UPS / USPS / DHL)
     * @param zone    shipping zone 1–8
     * @return estimated transit days
     * @throws IllegalArgumentException if the carrier or zone is unknown
     */
    public int getEstimatedDays(String carrier, int zone) {
        Map<Integer, Integer> carrierDays = CarrierRates.TRANSIT_DAYS.get(carrier);
        if (carrierDays == null) {
            throw new IllegalArgumentException("No transit data for carrier: " + carrier);
        }
        Integer days = carrierDays.get(zone);
        if (days == null) {
            throw new IllegalArgumentException(
                    "No transit data for carrier " + carrier + " zone " + zone);
        }
        return days;
    }
}
