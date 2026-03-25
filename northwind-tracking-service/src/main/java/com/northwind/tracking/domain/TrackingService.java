package com.northwind.tracking.domain;

import com.fasterxml.jackson.core.JsonProcessingException;
import com.fasterxml.jackson.databind.ObjectMapper;
import com.northwind.tracking.api.dto.TrackingStatusResponse;
import com.northwind.tracking.entity.ActiveShipment;
import com.northwind.tracking.entity.OutboxEvent;
import com.northwind.tracking.entity.TrackingEvent;
import com.northwind.tracking.kafka.ShipmentTrackedEvent;
import com.northwind.tracking.repository.ActiveShipmentRepository;
import com.northwind.tracking.repository.OutboxEventRepository;
import com.northwind.tracking.repository.TrackingEventRepository;
import lombok.RequiredArgsConstructor;
import lombok.extern.slf4j.Slf4j;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;

import java.time.Instant;
import java.time.LocalDate;
import java.time.ZoneOffset;
import java.time.format.DateTimeFormatter;
import java.util.List;
import java.util.Optional;
import java.util.regex.Pattern;
import java.util.stream.Collectors;

/**
 * Core domain service for shipment tracking.
 *
 * Ports the business logic from the PHP monolith's TrackingService.php,
 * replacing file_get_contents() + flat-file cache with proper JPA persistence
 * and the Outbox pattern for reliable Kafka publishing.
 *
 * Carrier auto-detection uses the same regex patterns as the PHP code:
 *   UPS   — 1Z[A-Z0-9]{16}
 *   FedEx — 12-digit or 15-digit all-numeric
 *   USPS  — 94 + 20 digits
 *   DHL   — 10-digit all-numeric
 */
@Service
@RequiredArgsConstructor
@Slf4j
public class TrackingService {

    // Carrier detection patterns — exact port from TrackingService.php
    private static final Pattern UPS_PATTERN   = Pattern.compile("1Z[A-Z0-9]{16}");
    private static final Pattern FEDEX_PATTERN = Pattern.compile("[0-9]{12}|[0-9]{15}");
    private static final Pattern USPS_PATTERN  = Pattern.compile("94[0-9]{20}");
    private static final Pattern DHL_PATTERN   = Pattern.compile("[0-9]{10}");

    private static final String TOPIC_SHIPMENT_TRACKED = "northwind.shipment.tracked";

    private final TrackingEventRepository      trackingEventRepository;
    private final OutboxEventRepository        outboxEventRepository;
    private final ActiveShipmentRepository     activeShipmentRepository;
    private final CarrierClient                carrierClient;
    private final DualWriteAdapter             dualWriteAdapter;
    private final ObjectMapper                 objectMapper;

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Returns the current status for a tracking number.
     *
     * Strategy:
     *   1. Look for an existing TrackingEvent in Postgres → return with source=CACHE.
     *   2. If nothing in DB yet, do a live poll → return with source=LIVE.
     *
     * The PHP monolith checked flat files first, then the DB — we invert this so
     * the authoritative DB is always consulted first.
     */
    @Transactional
    public TrackingStatusResponse getStatus(String trackingNumber) {
        Optional<TrackingEvent> cached =
                trackingEventRepository.findTopByTrackingNumberOrderByEventTimeDesc(trackingNumber);

        if (cached.isPresent()) {
            TrackingEvent event = cached.get();
            log.debug("getStatus({}) — returning CACHE hit", trackingNumber);
            return buildResponse(event, "CACHE",
                    trackingEventRepository.findByTrackingNumberOrderByEventTimeDesc(trackingNumber));
        }

        // Nothing cached — do a live poll so the caller gets something useful.
        log.debug("getStatus({}) — no cache, falling back to LIVE poll", trackingNumber);
        String carrier = detectCarrier(trackingNumber);
        TrackingEvent event = pollCarrier(trackingNumber, carrier);
        return buildResponse(event, "LIVE",
                List.of(event));
    }

    /**
     * Polls the carrier (mock during migration) for the latest status,
     * persists a TrackingEvent + OutboxEvent in one transaction (Outbox pattern),
     * then triggers the dual-write adapter for the legacy MySQL table.
     */
    @Transactional
    public TrackingEvent pollCarrier(String trackingNumber, String carrier) {
        log.info("pollCarrier({}, {})", trackingNumber, carrier);

        CarrierClient.CarrierResponse response = carrierClient.poll(trackingNumber);

        // Resolve orderId from active shipment if available.
        String orderId = activeShipmentRepository
                .findByTrackingNumber(trackingNumber)
                .map(ActiveShipment::getOrderId)
                .orElse(null);

        // 1. Persist to Postgres (own DB).
        TrackingEvent event = TrackingEvent.builder()
                .trackingNumber(trackingNumber)
                .carrier(carrier)
                .status(response.status())
                .location(response.location())
                .orderId(orderId)
                .eventTime(Instant.now())
                .rawResponse(response.rawResponse())
                .source("LIVE")
                .build();
        trackingEventRepository.save(event);

        // 2. Write OutboxEvent in the same transaction (Outbox pattern).
        //    The OutboxProcessor will publish to Kafka asynchronously.
        ShipmentTrackedEvent kafkaPayload = ShipmentTrackedEvent.builder()
                .eventType("SHIPMENT_STATUS_UPDATED")
                .trackingNumber(trackingNumber)
                .carrier(carrier)
                .status(response.status())
                .location(response.location())
                .timestamp(Instant.now().toString())
                .estimatedDelivery(response.estimatedDelivery())
                .orderId(orderId)
                .build();

        OutboxEvent outboxEvent = OutboxEvent.builder()
                .topic(TOPIC_SHIPMENT_TRACKED)
                .partitionKey(trackingNumber)
                .payload(toJson(kafkaPayload))
                .build();
        outboxEventRepository.save(outboxEvent);

        // Update last_polled_at on the active shipment (if any).
        activeShipmentRepository.findByTrackingNumber(trackingNumber)
                .ifPresent(shipment -> {
                    shipment.setLastPolledAt(Instant.now());
                    shipment.setStatus(response.status());
                    activeShipmentRepository.save(shipment);
                });

        // 3. Dual-write to legacy MySQL (only when DUAL_WRITE_ENABLED=true).
        //    This runs OUTSIDE the Postgres transaction because it is a separate
        //    data source.  Failures are logged and swallowed so the primary path
        //    is never blocked by legacy DB issues.
        dualWriteAdapter.writeToLegacyMySQL(event);

        return event;
    }

    /**
     * Auto-detect carrier from tracking number format.
     * Direct port of the regex logic in TrackingService.php.
     */
    public String detectCarrier(String trackingNumber) {
        if (trackingNumber == null || trackingNumber.isBlank()) {
            return "UNKNOWN";
        }
        if (UPS_PATTERN.matcher(trackingNumber).matches())   return "UPS";
        if (USPS_PATTERN.matcher(trackingNumber).matches())  return "USPS";
        if (FEDEX_PATTERN.matcher(trackingNumber).matches()) return "FEDEX";
        if (DHL_PATTERN.matcher(trackingNumber).matches())   return "DHL";
        return "UNKNOWN";
    }

    /**
     * Called when an order transitions to SHIPPED.
     * Creates an ActiveShipment record so the scheduled polling cron picks it up.
     */
    @Transactional
    public void startTracking(String orderId, String trackingNumber, String carrier) {
        log.info("startTracking orderId={} trackingNumber={} carrier={}",
                orderId, trackingNumber, carrier);

        // Idempotent — if already tracking this order, update tracking details.
        ActiveShipment shipment = activeShipmentRepository
                .findByOrderId(orderId)
                .orElse(ActiveShipment.builder()
                        .orderId(orderId)
                        .startedAt(Instant.now())
                        .build());

        shipment.setTrackingNumber(trackingNumber);
        shipment.setCarrier(carrier != null ? carrier : detectCarrier(trackingNumber));
        shipment.setStatus("IN_TRANSIT");
        activeShipmentRepository.save(shipment);

        // Do an immediate poll so the caller has fresh data right away.
        pollCarrier(trackingNumber, shipment.getCarrier());
    }

    /**
     * Called when an order transitions to DELIVERED or CANCELLED.
     * Removes the shipment from the active polling list.
     */
    @Transactional
    public void stopTracking(String orderId) {
        log.info("stopTracking orderId={}", orderId);
        activeShipmentRepository.findByOrderId(orderId)
                .ifPresentOrElse(
                        shipment -> {
                            shipment.setStatus("COMPLETED");
                            activeShipmentRepository.save(shipment);
                            log.info("stopTracking — marked orderId={} as COMPLETED", orderId);
                        },
                        () -> log.warn("stopTracking — no active shipment found for orderId={}", orderId)
                );
    }

    /**
     * Returns all tracking events for an order (used by the controller).
     */
    public List<TrackingEvent> getEventsByOrder(String orderId) {
        return trackingEventRepository.findByOrderIdOrderByEventTimeDesc(orderId);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private TrackingStatusResponse buildResponse(
            TrackingEvent latest,
            String source,
            List<TrackingEvent> allEvents) {

        List<TrackingStatusResponse.TrackingEventDto> eventDtos = allEvents.stream()
                .map(e -> TrackingStatusResponse.TrackingEventDto.builder()
                        .status(e.getStatus())
                        .location(e.getLocation())
                        .eventTime(e.getEventTime())
                        .source(e.getSource())
                        .build())
                .collect(Collectors.toList());

        return TrackingStatusResponse.builder()
                .carrier(latest.getCarrier())
                .trackingNumber(latest.getTrackingNumber())
                .status(latest.getStatus())
                .location(latest.getLocation())
                .orderId(latest.getOrderId())
                .source(source)
                .lastUpdated(latest.getEventTime())
                // Estimated delivery: 2 days from now as a default;
                // real carrier data would come from the CarrierResponse.
                .estimatedDelivery(LocalDate.now(ZoneOffset.UTC).plusDays(2))
                .events(eventDtos)
                .build();
    }

    private String toJson(Object obj) {
        try {
            return objectMapper.writeValueAsString(obj);
        } catch (JsonProcessingException e) {
            log.error("Failed to serialize outbox payload", e);
            throw new IllegalStateException("Cannot serialize outbox payload", e);
        }
    }
}
