package com.northwind.tracking.entity;

import jakarta.persistence.*;
import lombok.*;

import java.time.Instant;

@Entity
@Table(name = "active_shipments")
@Getter
@Setter
@NoArgsConstructor
@AllArgsConstructor
@Builder
public class ActiveShipment {

    @Id
    @GeneratedValue(strategy = GenerationType.IDENTITY)
    private Long id;

    @Column(name = "order_id", nullable = false, unique = true, length = 30)
    private String orderId;

    @Column(name = "tracking_number", nullable = false, length = 50)
    private String trackingNumber;

    @Column(name = "carrier", nullable = false, length = 10)
    private String carrier;

    @Column(name = "started_at")
    @Builder.Default
    private Instant startedAt = Instant.now();

    @Column(name = "last_polled_at")
    private Instant lastPolledAt;

    @Column(name = "status", length = 30)
    @Builder.Default
    private String status = "IN_TRANSIT";
}
