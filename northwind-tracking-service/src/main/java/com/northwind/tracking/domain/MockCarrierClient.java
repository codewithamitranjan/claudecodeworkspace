package com.northwind.tracking.domain;

import lombok.extern.slf4j.Slf4j;
import org.springframework.context.annotation.Primary;
import org.springframework.stereotype.Component;

import java.time.LocalDate;
import java.time.ZoneOffset;
import java.time.format.DateTimeFormatter;
import java.util.List;

/**
 * Mock implementation of {@link CarrierClient}.
 *
 * Real carrier API integration is intentionally deferred — the PHP monolith's
 * carrier APIs (UPS legacy XML, FedEx SOAP, USPS Web Tools, DHL XML) are all
 * deprecated or require new API keys and contract renegotiation.  This mock
 * lets the rest of the microservice be fully operational during the migration
 * window so that the Outbox pattern, dual-write adapter, and Kafka plumbing
 * can all be validated end-to-end before live carrier calls are needed.
 *
 * Status progression is simulated deterministically from the tracking number's
 * hash so that repeated calls return consistent results in tests and demos.
 *
 * Replacement path:
 *   1. Obtain new API credentials for each carrier.
 *   2. Implement {@link CarrierClient} in e.g. UpsCarrierClient, FedExCarrierClient…
 *   3. Remove {@code @Primary} from this class (or delete it entirely).
 *   4. Route by carrier name in {@link TrackingService#pollCarrier}.
 */
@Primary
@Component
@Slf4j
public class MockCarrierClient implements CarrierClient {

    private static final List<String> STATUS_PROGRESSION = List.of(
            "LABEL_CREATED",
            "PICKED_UP",
            "IN_TRANSIT",
            "IN_TRANSIT",      // weighted — most polls should see IN_TRANSIT
            "IN_TRANSIT",
            "OUT_FOR_DELIVERY",
            "DELIVERED"
    );

    private static final List<String> LOCATIONS = List.of(
            "Louisville, KY — Sort Facility",
            "Chicago, IL — Distribution Center",
            "Memphis, TN — Hub",
            "Dallas, TX — Delivery Station",
            "Atlanta, GA — Carrier Facility",
            "On vehicle for delivery",
            "Delivered — Front Door"
    );

    @Override
    public CarrierResponse poll(String trackingNumber) {
        log.debug("[MOCK-CARRIER] Polling for trackingNumber={}", trackingNumber);

        // Derive a deterministic but pseudo-random index from the tracking number.
        // This means the same tracking number always returns the same status in
        // tests, but different tracking numbers return different statuses — useful
        // for demos and integration testing without real carrier credentials.
        int hash = Math.abs(trackingNumber.hashCode());
        int statusIndex = hash % STATUS_PROGRESSION.size();
        int locationIndex = hash % LOCATIONS.size();

        String status = STATUS_PROGRESSION.get(statusIndex);
        String location = LOCATIONS.get(locationIndex);
        String estimatedDelivery = LocalDate.now(ZoneOffset.UTC)
                .plusDays(2)
                .format(DateTimeFormatter.ISO_LOCAL_DATE);

        // Build a minimal JSON-like raw response for audit logging.
        String rawResponse = String.format(
                "{\"mock\":true,\"trackingNumber\":\"%s\",\"status\":\"%s\",\"location\":\"%s\"}",
                trackingNumber, status, location);

        // Detect carrier from tracking number pattern (same logic as TrackingService.detectCarrier).
        String carrier = detectCarrierFromNumber(trackingNumber);

        log.info("[MOCK-CARRIER] trackingNumber={} carrier={} status={} location={}",
                trackingNumber, carrier, status, location);

        return new CarrierResponse(
                trackingNumber,
                carrier,
                status,
                location,
                estimatedDelivery,
                rawResponse
        );
    }

    private String detectCarrierFromNumber(String trackingNumber) {
        if (trackingNumber == null) return "UNKNOWN";
        if (trackingNumber.matches("1Z[A-Z0-9]{16}")) return "UPS";
        if (trackingNumber.matches("[0-9]{12}") || trackingNumber.matches("[0-9]{15}")) return "FEDEX";
        if (trackingNumber.matches("94[0-9]{20}")) return "USPS";
        if (trackingNumber.matches("[0-9]{10}")) return "DHL";
        return "UNKNOWN";
    }
}
