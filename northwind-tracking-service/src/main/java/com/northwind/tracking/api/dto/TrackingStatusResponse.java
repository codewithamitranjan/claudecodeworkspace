package com.northwind.tracking.api.dto;

import lombok.*;

import java.time.Instant;
import java.time.LocalDate;
import java.util.List;

/**
 * API response for a tracking status query.
 * The `source` field tells callers whether the data came from a live carrier
 * poll ("LIVE") or from the cached event history ("CACHE").
 */
@Getter
@Setter
@NoArgsConstructor
@AllArgsConstructor
@Builder
public class TrackingStatusResponse {

    private String carrier;
    private String trackingNumber;
    private String status;
    private String location;
    private LocalDate estimatedDelivery;
    private String orderId;

    /**
     * "LIVE"  — data was just fetched from the carrier (mock) client.
     * "CACHE" — data was read from the most-recent stored TrackingEvent.
     */
    private String source;

    private Instant lastUpdated;

    /** Full event history for this tracking number, newest first. */
    private List<TrackingEventDto> events;

    @Getter
    @Setter
    @NoArgsConstructor
    @AllArgsConstructor
    @Builder
    public static class TrackingEventDto {
        private String status;
        private String location;
        private Instant eventTime;
        private String source;
    }
}
