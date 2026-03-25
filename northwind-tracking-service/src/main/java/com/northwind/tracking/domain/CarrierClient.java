package com.northwind.tracking.domain;

/**
 * Abstraction over carrier tracking APIs.
 *
 * The PHP monolith called carrier APIs directly via file_get_contents(), with
 * no error handling and hardcoded URLs that are now all deprecated.  This
 * interface lets us swap in a mock during migration and plug in a real HTTP
 * client later once carrier API contracts are renegotiated.
 */
public interface CarrierClient {

    /**
     * Poll the carrier for the latest status of {@code trackingNumber}.
     *
     * @param trackingNumber the carrier-specific tracking number
     * @return a {@link CarrierResponse} with the latest status and location
     */
    CarrierResponse poll(String trackingNumber);

    /**
     * Carrier-agnostic response model returned by any CarrierClient implementation.
     */
    record CarrierResponse(
            String trackingNumber,
            String carrier,
            String status,
            String location,
            String estimatedDelivery,   // ISO-8601 date string, may be null
            String rawResponse          // raw payload for audit logging
    ) {}
}
