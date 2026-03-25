package com.northwind.tracking.repository;

import com.northwind.tracking.entity.TrackingEvent;
import org.springframework.data.jpa.repository.JpaRepository;
import org.springframework.stereotype.Repository;

import java.util.List;
import java.util.Optional;

@Repository
public interface TrackingEventRepository extends JpaRepository<TrackingEvent, Long> {

    /**
     * Get the most recent tracking event for a tracking number.
     */
    Optional<TrackingEvent> findTopByTrackingNumberOrderByEventTimeDesc(String trackingNumber);

    /**
     * Get all tracking events for a tracking number, ordered most-recent first.
     */
    List<TrackingEvent> findByTrackingNumberOrderByEventTimeDesc(String trackingNumber);

    /**
     * Get all tracking events for an order.
     */
    List<TrackingEvent> findByOrderIdOrderByEventTimeDesc(String orderId);
}
