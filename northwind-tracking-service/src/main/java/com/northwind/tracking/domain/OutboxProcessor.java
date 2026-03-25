package com.northwind.tracking.domain;

import com.northwind.tracking.entity.OutboxEvent;
import com.northwind.tracking.repository.OutboxEventRepository;
import lombok.RequiredArgsConstructor;
import lombok.extern.slf4j.Slf4j;
import org.springframework.kafka.core.KafkaTemplate;
import org.springframework.scheduling.annotation.Scheduled;
import org.springframework.stereotype.Component;
import org.springframework.transaction.annotation.Transactional;

import java.time.Instant;
import java.util.List;

/**
 * Outbox pattern processor.
 *
 * Every 5 seconds this component reads all unpublished rows from the
 * {@code outbox_events} table and forwards them to the appropriate Kafka topic.
 * Once a message is confirmed sent, the row is marked published within the same
 * Postgres transaction so there is no window for silent data loss.
 *
 * Why the Outbox pattern here?
 *   The PHP monolith wrote directly to Kafka in-process, which meant a crash
 *   between the DB write and the Kafka send could silently drop events.  By
 *   writing to the outbox table atomically with the domain event (same TX in
 *   TrackingService.pollCarrier) we guarantee at-least-once delivery: if the
 *   app crashes before the outbox row is marked published, the next poll will
 *   re-send it.  Kafka consumers must be idempotent (they should de-duplicate
 *   on trackingNumber + timestamp).
 */
@Component
@RequiredArgsConstructor
@Slf4j
public class OutboxProcessor {

    private final OutboxEventRepository     outboxEventRepository;
    private final KafkaTemplate<String, String> kafkaTemplate;

    @Scheduled(fixedDelay = 5_000)
    @Transactional
    public void processOutbox() {
        List<OutboxEvent> pending =
                outboxEventRepository.findByPublishedFalseOrderByCreatedAtAsc();

        if (pending.isEmpty()) {
            return;
        }

        log.debug("OutboxProcessor — found {} unpublished event(s)", pending.size());

        for (OutboxEvent event : pending) {
            try {
                // Send to Kafka synchronously so we can be sure it was accepted
                // before marking the row published.  For higher throughput this
                // could be made async with a CompletableFuture, but during the
                // migration window synchronous is safer.
                kafkaTemplate.send(event.getTopic(), event.getPartitionKey(), event.getPayload())
                        .get(); // blocks until broker ack

                event.setPublished(true);
                event.setPublishedAt(Instant.now());
                outboxEventRepository.save(event);

                log.info("OutboxProcessor — published outboxEventId={} to topic={} partitionKey={}",
                        event.getId(), event.getTopic(), event.getPartitionKey());

            } catch (InterruptedException e) {
                Thread.currentThread().interrupt();
                log.warn("OutboxProcessor — interrupted while publishing outboxEventId={}", event.getId());
                break; // exit this cycle; will retry in 5 s
            } catch (Exception e) {
                log.error("OutboxProcessor — failed to publish outboxEventId={}: {}",
                        event.getId(), e.getMessage(), e);
                // Continue to next event rather than aborting the whole batch.
                // The failed event will be retried on the next scheduled run.
            }
        }
    }
}
