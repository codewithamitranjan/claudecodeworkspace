package com.northwind.tracking.repository;

import com.northwind.tracking.entity.OutboxEvent;
import org.springframework.data.jpa.repository.JpaRepository;
import org.springframework.stereotype.Repository;

import java.util.List;

@Repository
public interface OutboxEventRepository extends JpaRepository<OutboxEvent, Long> {

    /**
     * Find all outbox events that have not yet been published to Kafka.
     * The partial index idx_outbox_unpublished makes this very fast.
     */
    List<OutboxEvent> findByPublishedFalseOrderByCreatedAtAsc();
}
