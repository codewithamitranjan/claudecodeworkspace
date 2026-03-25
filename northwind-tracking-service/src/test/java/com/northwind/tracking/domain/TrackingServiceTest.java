package com.northwind.tracking.domain;

import com.fasterxml.jackson.databind.ObjectMapper;
import com.fasterxml.jackson.databind.SerializationFeature;
import com.fasterxml.jackson.datatype.jsr310.JavaTimeModule;
import com.northwind.tracking.api.dto.TrackingStatusResponse;
import com.northwind.tracking.entity.ActiveShipment;
import com.northwind.tracking.entity.TrackingEvent;
import com.northwind.tracking.repository.ActiveShipmentRepository;
import com.northwind.tracking.repository.OutboxEventRepository;
import com.northwind.tracking.repository.TrackingEventRepository;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.DisplayName;
import org.junit.jupiter.api.Nested;
import org.junit.jupiter.api.Test;
import org.springframework.beans.factory.annotation.Autowired;
import org.springframework.boot.test.context.SpringBootTest;
import org.springframework.boot.test.mock.mockito.MockBean;
import org.springframework.kafka.core.KafkaTemplate;
import org.springframework.test.context.ActiveProfiles;
import org.springframework.transaction.annotation.Transactional;

import java.time.Instant;
import java.util.List;
import java.util.Optional;
import java.util.concurrent.CompletableFuture;

import static org.assertj.core.api.Assertions.assertThat;
import static org.mockito.ArgumentMatchers.*;
import static org.mockito.Mockito.*;

/**
 * Integration-style tests for {@link TrackingService}.
 *
 * Uses H2 in-memory DB (via the test profile) so no Postgres or MySQL is needed.
 * KafkaTemplate is mocked so no Kafka broker is needed for these tests.
 */
@SpringBootTest
@ActiveProfiles("test")
@Transactional
class TrackingServiceTest {

    @Autowired
    private TrackingService trackingService;

    @Autowired
    private TrackingEventRepository trackingEventRepository;

    @Autowired
    private ActiveShipmentRepository activeShipmentRepository;

    @Autowired
    private OutboxEventRepository outboxEventRepository;

    @MockBean
    @SuppressWarnings("unchecked")
    private KafkaTemplate<String, String> kafkaTemplate;

    @MockBean
    private DualWriteAdapter dualWriteAdapter;

    @BeforeEach
    void setUp() {
        // Make the mock KafkaTemplate's send() return a completed future so
        // OutboxProcessor.processOutbox() does not block.
        when(kafkaTemplate.send(anyString(), anyString(), anyString()))
                .thenReturn(CompletableFuture.completedFuture(null));
    }

    // =========================================================================
    // Carrier detection — porting the PHP regex logic
    // =========================================================================

    @Nested
    @DisplayName("detectCarrier — regex-based carrier detection (ported from PHP)")
    class DetectCarrierTests {

        @Test
        @DisplayName("UPS — 1Z prefix + 16 alphanumeric chars")
        void detectUps_standardFormat() {
            assertThat(trackingService.detectCarrier("1Z999AA10123456784")).isEqualTo("UPS");
        }

        @Test
        @DisplayName("UPS — lowercase letters in tracking number")
        void detectUps_lowercaseLetters() {
            // Pattern is case-sensitive; lowercase should NOT match UPS
            assertThat(trackingService.detectCarrier("1z999aa10123456784")).isNotEqualTo("UPS");
        }

        @Test
        @DisplayName("FedEx — 12-digit all-numeric")
        void detectFedEx_12digit() {
            assertThat(trackingService.detectCarrier("123456789012")).isEqualTo("FEDEX");
        }

        @Test
        @DisplayName("FedEx — 15-digit all-numeric")
        void detectFedEx_15digit() {
            assertThat(trackingService.detectCarrier("123456789012345")).isEqualTo("FEDEX");
        }

        @Test
        @DisplayName("USPS — 94 prefix + 20 digits")
        void detectUsps_standard() {
            assertThat(trackingService.detectCarrier("9400111899223397990051")).isEqualTo("USPS");
        }

        @Test
        @DisplayName("DHL — 10-digit all-numeric")
        void detectDhl_10digit() {
            assertThat(trackingService.detectCarrier("1234567890")).isEqualTo("DHL");
        }

        @Test
        @DisplayName("Unknown — random alphanumeric string")
        void detectUnknown_randomString() {
            assertThat(trackingService.detectCarrier("UNKNOWNXYZ")).isEqualTo("UNKNOWN");
        }

        @Test
        @DisplayName("Unknown — empty string")
        void detectUnknown_empty() {
            assertThat(trackingService.detectCarrier("")).isEqualTo("UNKNOWN");
        }

        @Test
        @DisplayName("Unknown — null")
        void detectUnknown_null() {
            assertThat(trackingService.detectCarrier(null)).isEqualTo("UNKNOWN");
        }

        @Test
        @DisplayName("UPS — exactly 16 alphanumeric chars after 1Z")
        void detectUps_exactLength() {
            // 1Z + 16 chars = 18 total
            assertThat(trackingService.detectCarrier("1ZAAAA00AA00000000")).isEqualTo("UPS");
        }

        @Test
        @DisplayName("UPS — 17 chars after 1Z should NOT match")
        void detectUps_tooLong_shouldNotMatch() {
            assertThat(trackingService.detectCarrier("1ZAAAA00AA000000000")).isNotEqualTo("UPS");
        }
    }

    // =========================================================================
    // getStatus — cache vs live
    // =========================================================================

    @Nested
    @DisplayName("getStatus — returns CACHE when event exists in DB")
    class GetStatusTests {

        @Test
        @DisplayName("returns source=CACHE when tracking event already exists")
        void getStatus_returnsCacheWhenEventExists() {
            // Arrange — pre-populate a tracking event
            TrackingEvent existingEvent = TrackingEvent.builder()
                    .trackingNumber("1Z999AA10123456784")
                    .carrier("UPS")
                    .status("IN_TRANSIT")
                    .location("Chicago, IL")
                    .orderId("NW-2026-000042")
                    .eventTime(Instant.now().minusSeconds(60))
                    .source("LIVE")
                    .build();
            trackingEventRepository.save(existingEvent);

            // Act
            TrackingStatusResponse response = trackingService.getStatus("1Z999AA10123456784");

            // Assert
            assertThat(response.getSource()).isEqualTo("CACHE");
            assertThat(response.getTrackingNumber()).isEqualTo("1Z999AA10123456784");
            assertThat(response.getCarrier()).isEqualTo("UPS");
            assertThat(response.getStatus()).isEqualTo("IN_TRANSIT");
        }

        @Test
        @DisplayName("returns source=LIVE when no event exists yet (falls back to poll)")
        void getStatus_returnsLiveWhenNoEventExists() {
            // No pre-existing event for this tracking number
            TrackingStatusResponse response = trackingService.getStatus("1ZNEWTRACKING12345");

            assertThat(response.getSource()).isEqualTo("LIVE");
            assertThat(response.getTrackingNumber()).isEqualTo("1ZNEWTRACKING12345");
        }
    }

    // =========================================================================
    // startTracking / stopTracking
    // =========================================================================

    @Nested
    @DisplayName("startTracking — creates ActiveShipment")
    class StartTrackingTests {

        @Test
        @DisplayName("startTracking creates an ActiveShipment record")
        void startTracking_createsActiveShipment() {
            trackingService.startTracking("NW-2026-000042", "1Z999AA10123456784", "UPS");

            Optional<ActiveShipment> shipment =
                    activeShipmentRepository.findByOrderId("NW-2026-000042");

            assertThat(shipment).isPresent();
            assertThat(shipment.get().getTrackingNumber()).isEqualTo("1Z999AA10123456784");
            assertThat(shipment.get().getCarrier()).isEqualTo("UPS");
            // Status is updated by the immediate pollCarrier() call inside startTracking().
            // MockCarrierClient returns a deterministic status derived from the tracking number
            // hash — we verify it is a recognised carrier status rather than a hardcoded value.
            assertThat(shipment.get().getStatus()).isNotNull().isNotBlank();
            assertThat(shipment.get().getStatus()).isIn(
                    "LABEL_CREATED", "PICKED_UP", "IN_TRANSIT",
                    "OUT_FOR_DELIVERY", "DELIVERED", "COMPLETED");
        }

        @Test
        @DisplayName("startTracking is idempotent — calling twice updates existing record")
        void startTracking_isIdempotent() {
            trackingService.startTracking("NW-2026-000099", "1Z999AA10123456784", "UPS");
            trackingService.startTracking("NW-2026-000099", "1Z888BB20234567895", "UPS");

            List<ActiveShipment> shipments = activeShipmentRepository.findAll()
                    .stream()
                    .filter(s -> "NW-2026-000099".equals(s.getOrderId()))
                    .toList();

            assertThat(shipments).hasSize(1);
            assertThat(shipments.get(0).getTrackingNumber()).isEqualTo("1Z888BB20234567895");
        }

        @Test
        @DisplayName("startTracking auto-detects carrier when not provided")
        void startTracking_autoDetectsCarrier() {
            trackingService.startTracking("NW-2026-000043", "9400111899223397990051", null);

            Optional<ActiveShipment> shipment =
                    activeShipmentRepository.findByOrderId("NW-2026-000043");

            assertThat(shipment).isPresent();
            assertThat(shipment.get().getCarrier()).isEqualTo("USPS");
        }

        @Test
        @DisplayName("stopTracking marks shipment as COMPLETED")
        void stopTracking_marksCompleted() {
            trackingService.startTracking("NW-2026-000044", "1Z999AA10123456784", "UPS");
            trackingService.stopTracking("NW-2026-000044");

            Optional<ActiveShipment> shipment =
                    activeShipmentRepository.findByOrderId("NW-2026-000044");

            assertThat(shipment).isPresent();
            assertThat(shipment.get().getStatus()).isEqualTo("COMPLETED");
        }

        @Test
        @DisplayName("stopTracking for unknown orderId logs warning and does not throw")
        void stopTracking_unknownOrderId_doesNotThrow() {
            // Should log a warning but not throw any exception
            org.junit.jupiter.api.Assertions.assertDoesNotThrow(
                    () -> trackingService.stopTracking("NW-NONEXISTENT"));
        }
    }

    // =========================================================================
    // Dual-write adapter interaction
    // =========================================================================

    @Nested
    @DisplayName("DualWriteAdapter — called based on DUAL_WRITE_ENABLED flag")
    class DualWriteTests {

        @Test
        @DisplayName("dual-write adapter is called when a carrier poll succeeds")
        void pollCarrier_callsDualWriteAdapter() {
            trackingService.pollCarrier("1Z999AA10123456784", "UPS");

            // DualWriteAdapter.writeToLegacyMySQL must be invoked exactly once
            verify(dualWriteAdapter, times(1))
                    .writeToLegacyMySQL(any(TrackingEvent.class));
        }

        @Test
        @DisplayName("dual-write adapter is called with correct tracking number")
        void pollCarrier_callsDualWriteAdapterWithCorrectData() {
            trackingService.pollCarrier("123456789012", "FEDEX");

            verify(dualWriteAdapter).writeToLegacyMySQL(
                    argThat(event -> "123456789012".equals(event.getTrackingNumber())
                            && "FEDEX".equals(event.getCarrier()))
            );
        }
    }
}
