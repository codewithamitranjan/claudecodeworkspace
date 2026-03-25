package com.northwind.tracking.kafka;

import lombok.*;

/**
 * Event consumed from topic {@code northwind.orders.status}.
 *
 * Schema:
 * <pre>
 * {
 *   "eventType":     "ORDER_STATUS_CHANGED",
 *   "orderId":       "NW-2026-000042",
 *   "newStatus":     "SHIPPED",
 *   "trackingNumber":"1Z999AA10123456784",
 *   "carrier":       "UPS"
 * }
 * </pre>
 */
@Getter
@Setter
@NoArgsConstructor
@AllArgsConstructor
@Builder
public class OrderStatusChangedEvent {

    private String eventType;
    private String orderId;
    private String newStatus;
    private String trackingNumber;
    private String carrier;
}
