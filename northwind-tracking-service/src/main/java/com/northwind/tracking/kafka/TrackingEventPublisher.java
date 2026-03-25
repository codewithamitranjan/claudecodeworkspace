package com.northwind.tracking.kafka;

import lombok.RequiredArgsConstructor;
import lombok.extern.slf4j.Slf4j;
import org.springframework.kafka.core.KafkaTemplate;
import org.springframework.kafka.support.SendResult;
import org.springframework.stereotype.Component;

import java.util.concurrent.CompletableFuture;

/**
 * Direct Kafka publisher for {@link ShipmentTrackedEvent}.
 *
 * This is used by the OutboxProcessor (which serializes events to JSON strings)
 * and can also be used for any cases where an immediate, non-outbox publish is
 * appropriate (e.g., admin endpoints, health checks).
 *
 * The tracking number is used as the Kafka message key so all events for the
 * same shipment land on the same partition and are consumed in order.
 */
@Component
@RequiredArgsConstructor
@Slf4j
public class TrackingEventPublisher {

    private static final String TOPIC = "northwind.shipment.tracked";

    private final KafkaTemplate<String, String> kafkaTemplate;

    /**
     * Publishes a pre-serialized JSON payload to the shipment tracking topic.
     * The key guarantees partition ordering per tracking number.
     *
     * @param trackingNumber partition key
     * @param jsonPayload    serialized {@link ShipmentTrackedEvent}
     */
    public CompletableFuture<SendResult<String, String>> publish(
            String trackingNumber,
            String jsonPayload) {

        log.info("Publishing to topic={} key={}", TOPIC, trackingNumber);

        CompletableFuture<SendResult<String, String>> future =
                kafkaTemplate.send(TOPIC, trackingNumber, jsonPayload);

        future.whenComplete((result, ex) -> {
            if (ex == null) {
                log.debug("Published to topic={} partition={} offset={}",
                        TOPIC,
                        result.getRecordMetadata().partition(),
                        result.getRecordMetadata().offset());
            } else {
                log.error("Failed to publish to topic={} key={}: {}",
                        TOPIC, trackingNumber, ex.getMessage(), ex);
            }
        });

        return future;
    }
}
