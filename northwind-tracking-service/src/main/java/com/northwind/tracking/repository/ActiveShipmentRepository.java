package com.northwind.tracking.repository;

import com.northwind.tracking.entity.ActiveShipment;
import org.springframework.data.jpa.repository.JpaRepository;
import org.springframework.stereotype.Repository;

import java.util.List;
import java.util.Optional;

@Repository
public interface ActiveShipmentRepository extends JpaRepository<ActiveShipment, Long> {

    Optional<ActiveShipment> findByOrderId(String orderId);

    Optional<ActiveShipment> findByTrackingNumber(String trackingNumber);

    List<ActiveShipment> findByStatus(String status);
}
