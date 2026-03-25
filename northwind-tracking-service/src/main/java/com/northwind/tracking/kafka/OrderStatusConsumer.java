package com.northwind.tracking.kafka;

import com.fasterxml.jackson.databind.ObjectMapper;
import com.northwind.tracking.domain.TrackingService;
import lombok.RequiredArgsConstructor;
import lombok.extern.slf4j.Slf4j;
import org.springframework.kafka.annotation.KafkaListener;
import org.springframework.stereotype.Component;
import org.springframework.transaction.annotation.Transactional;

/**
 * Consumes order status change events from topic {@code northwind.orders.status}.
 *
 * Behaviour:
 * <ul>
 *   <li>SHIPPED      → startTracking (registers shipment for polling)</li>
 *   <li>DELIVERED    → stopTracking  (removes from active polling)</li>
 *   <li>CANCELLED    → stopTracking  (removes from active polling)</li>
 *   <li>Any other status → ignored with debug log</li>
 *   <li>Malformed JSON → logged as error and skipped (no exception propagated
 *       so the Kafka consumer does not seek-to-beginning on every bad message)</li>
 * </ul>
 *
 * The listener is @Transactional so that startTracking / stopTracking DB writes
 * and any outbox rows they create are committed atomically.  If the TX rolls
 * back, Kafka will re-deliver the message (at-least-once semantics).
 */
@Component
@RequiredArgsConstructor
@Slf4j
public class OrderStatusConsumer {

    private final TrackingService trackingService;
    private final ObjectMapper    objectMapper;

    @KafkaListener(
            topics = "northwind.orders.status",
            groupId = "${spring.kafka.consumer.group-id:tracking-service}",
            containerFactory = "kafkaListenerContainerFactory"
    )
    @Transactional
    public void onOrderStatusChanged(String rawMessage) {
        log.debug("Received order status message: {}", rawMessage);

        OrderStatusChangedEvent event;
        try {
            event = objectMapper.readValue(rawMessage, OrderStatusChangedEvent.class);
        } catch (Exception e) {
            // Malformed messages must not crash the consumer or trigger retries
            // that could amplify a broken producer bug across the entire partition.
            log.error("OrderStatusConsumer — malformed message, skipping. raw={} error={}",
                    rawMessage, e.getMessage());
            return;
        }

        if (event.getOrderId() == null || event.getNewStatus() == null) {
            log.warn("OrderStatusConsumer — event missing orderId or newStatus, skipping: {}", rawMessage);
            return;
        }

        log.info("OrderStatusConsumer — orderId={} newStatus={} trackingNumber={}",
                event.getOrderId(), event.getNewStatus(), event.getTrackingNumber());

        switch (event.getNewStatus().toUpperCase()) {
            case "SHIPPED" -> {
                if (event.getTrackingNumber() == null || event.getTrackingNumber().isBlank()) {
                    log.warn("OrderStatusConsumer — SHIPPED event for orderId={} has no trackingNumber, skipping",
                            event.getOrderId());
                    return;
                }
                trackingService.startTracking(
                        event.getOrderId(),
                        event.getTrackingNumber(),
                        event.getCarrier()
                );
            }
            case "DELIVERED", "CANCELLED" -> trackingService.stopTracking(event.getOrderId());
            default -> log.debug("OrderStatusConsumer — ignoring status={} for orderId={}",
                    event.getNewStatus(), event.getOrderId());
        }
    }
}
