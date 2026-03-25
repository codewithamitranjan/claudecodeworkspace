package com.northwind.tracking.entity;

import jakarta.persistence.*;
import lombok.*;

import java.time.Instant;

@Entity
@Table(name = "tracking_events")
@Getter
@Setter
@NoArgsConstructor
@AllArgsConstructor
@Builder
public class TrackingEvent {

    @Id
    @GeneratedValue(strategy = GenerationType.IDENTITY)
    private Long id;

    @Column(name = "tracking_number", nullable = false, length = 50)
    private String trackingNumber;

    @Column(name = "carrier", nullable = false, length = 10)
    private String carrier;

    @Column(name = "status", nullable = false, length = 30)
    private String status;

    @Column(name = "location", length = 200)
    private String location;

    @Column(name = "order_id", length = 30)
    private String orderId;

    @Column(name = "event_time", nullable = false)
    private Instant eventTime;

    @Column(name = "raw_response", columnDefinition = "TEXT")
    private String rawResponse;

    // "LIVE" or "CACHE"
    @Column(name = "source", length = 10)
    @Builder.Default
    private String source = "LIVE";
}
