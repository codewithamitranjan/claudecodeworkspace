package com.northwind.tracking.api;

import com.northwind.tracking.api.dto.PollRequest;
import com.northwind.tracking.api.dto.TrackingStatusResponse;
import com.northwind.tracking.domain.TrackingService;
import com.northwind.tracking.entity.TrackingEvent;
import jakarta.validation.Valid;
import lombok.RequiredArgsConstructor;
import lombok.extern.slf4j.Slf4j;
import org.springframework.http.ResponseEntity;
import org.springframework.web.bind.annotation.*;

import java.time.LocalDate;
import java.util.List;
import java.util.stream.Collectors;

@RestController
@RequestMapping("/api/tracking")
@RequiredArgsConstructor
@Slf4j
public class TrackingController {

    private final TrackingService trackingService;

    /**
     * GET /api/tracking/{trackingNumber}
     *
     * Returns the current status for a tracking number. The response includes
     * whether data came from a live poll or the cached event store.
     */
    @GetMapping("/{trackingNumber}")
    public ResponseEntity<TrackingStatusResponse> getStatus(
            @PathVariable String trackingNumber) {
        log.info("GET /api/tracking/{}", trackingNumber);
        TrackingStatusResponse response = trackingService.getStatus(trackingNumber);
        return ResponseEntity.ok(response);
    }

    /**
     * POST /api/tracking/poll
     *
     * Manually triggers a carrier poll for the given tracking number.
     * Useful for on-demand refresh outside the scheduled polling cycle.
     */
    @PostMapping("/poll")
    public ResponseEntity<TrackingStatusResponse> poll(
            @Valid @RequestBody PollRequest request) {
        log.info("POST /api/tracking/poll trackingNumber={} carrier={}",
                request.getTrackingNumber(), request.getCarrier());

        String carrier = (request.getCarrier() != null && !request.getCarrier().isBlank())
                ? request.getCarrier()
                : trackingService.detectCarrier(request.getTrackingNumber());

        TrackingEvent event = trackingService.pollCarrier(request.getTrackingNumber(), carrier);

        // Build a response from the freshly-polled event.
        TrackingStatusResponse response = TrackingStatusResponse.builder()
                .carrier(event.getCarrier())
                .trackingNumber(event.getTrackingNumber())
                .status(event.getStatus())
                .location(event.getLocation())
                .orderId(event.getOrderId())
                .source("LIVE")
                .lastUpdated(event.getEventTime())
                .estimatedDelivery(LocalDate.now().plusDays(2))
                .events(List.of(
                        TrackingStatusResponse.TrackingEventDto.builder()
                                .status(event.getStatus())
                                .location(event.getLocation())
                                .eventTime(event.getEventTime())
                                .source(event.getSource())
                                .build()
                ))
                .build();

        return ResponseEntity.ok(response);
    }

    /**
     * GET /api/tracking/order/{orderId}
     *
     * Returns all tracking events recorded for a given order, newest first.
     */
    @GetMapping("/order/{orderId}")
    public ResponseEntity<List<TrackingStatusResponse.TrackingEventDto>> getByOrder(
            @PathVariable String orderId) {
        log.info("GET /api/tracking/order/{}", orderId);

        List<TrackingEvent> events = trackingService.getEventsByOrder(orderId);

        List<TrackingStatusResponse.TrackingEventDto> dtos = events.stream()
                .map(e -> TrackingStatusResponse.TrackingEventDto.builder()
                        .status(e.getStatus())
                        .location(e.getLocation())
                        .eventTime(e.getEventTime())
                        .source(e.getSource())
                        .build())
                .collect(Collectors.toList());

        return ResponseEntity.ok(dtos);
    }
}
