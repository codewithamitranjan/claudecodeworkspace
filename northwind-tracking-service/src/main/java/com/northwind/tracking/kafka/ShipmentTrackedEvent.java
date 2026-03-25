package com.northwind.tracking.kafka;

import lombok.*;

/**
 * Event published to topic {@code northwind.shipment.tracked}.
 *
 * Schema:
 * <pre>
 * {
 *   "eventType":        "SHIPMENT_STATUS_UPDATED",
 *   "trackingNumber":   "1Z999AA10123456784",
 *   "carrier":          "UPS",
 *   "status":           "IN_TRANSIT",
 *   "location":         "Chicago, IL",
 *   "timestamp":        "2026-03-22T10:15:30Z",
 *   "estimatedDelivery":"2026-03-24",
 *   "orderId":          "NW-2026-000042"
 * }
 * </pre>
 */
@Getter
@Setter
@NoArgsConstructor
@AllArgsConstructor
@Builder
public class ShipmentTrackedEvent {

    private String eventType;
    private String trackingNumber;
    private String carrier;
    private String status;
    private String location;
    private String timestamp;
    private String estimatedDelivery;
    private String orderId;
}
