package com.northwind.tracking.kafka;

import com.northwind.tracking.domain.TrackingService;
import org.apache.kafka.clients.producer.ProducerConfig;
import org.apache.kafka.common.serialization.StringSerializer;
import org.junit.jupiter.api.DisplayName;
import org.junit.jupiter.api.Test;
import org.springframework.beans.factory.annotation.Autowired;
import org.springframework.boot.test.context.SpringBootTest;
import org.springframework.boot.test.mock.mockito.MockBean;
import org.springframework.kafka.core.DefaultKafkaProducerFactory;
import org.springframework.kafka.core.KafkaTemplate;
import org.springframework.kafka.core.ProducerFactory;
import org.springframework.kafka.test.EmbeddedKafkaBroker;
import org.springframework.kafka.test.context.EmbeddedKafka;
import org.springframework.kafka.test.utils.KafkaTestUtils;
import org.springframework.test.context.ActiveProfiles;
import org.springframework.test.context.TestPropertySource;

import java.util.Map;
import java.util.concurrent.TimeUnit;

import static org.mockito.ArgumentMatchers.anyString;
import static org.mockito.ArgumentMatchers.eq;
import static org.mockito.Mockito.*;

/**
 * Tests for {@link OrderStatusConsumer}.
 *
 * Uses {@link EmbeddedKafka} so a real Kafka consumer lifecycle runs end-to-end
 * without requiring an external broker.  TrackingService is mocked so these
 * tests focus purely on message routing, not business logic.
 *
 * Note: @DirtiesContext is intentionally absent.  Re-creating the embedded broker
 * between tests causes a TopicExistsException because the in-process ZooKeeper
 * retains state.  Instead we share one broker for the whole test class — this is
 * safe because each test uses distinct orderId values.
 */
@SpringBootTest
@ActiveProfiles("test")
@EmbeddedKafka(
        partitions = 1,
        topics = {"northwind.orders.status", "northwind.shipment.tracked"}
)
@TestPropertySource(properties = {
        "spring.kafka.bootstrap-servers=${spring.embedded.kafka.brokers}"
})
class OrderStatusConsumerTest {

    @Autowired
    private EmbeddedKafkaBroker embeddedKafkaBroker;

    @MockBean
    private TrackingService trackingService;

    private KafkaTemplate<String, String> testProducer() {
        Map<String, Object> producerProps =
                KafkaTestUtils.producerProps(embeddedKafkaBroker);
        producerProps.put(ProducerConfig.KEY_SERIALIZER_CLASS_CONFIG,   StringSerializer.class);
        producerProps.put(ProducerConfig.VALUE_SERIALIZER_CLASS_CONFIG, StringSerializer.class);
        ProducerFactory<String, String> pf = new DefaultKafkaProducerFactory<>(producerProps);
        return new KafkaTemplate<>(pf);
    }

    private static final String TOPIC = "northwind.orders.status";

    // -------------------------------------------------------------------------
    // SHIPPED → startTracking
    // -------------------------------------------------------------------------

    @Test
    @DisplayName("SHIPPED event calls startTracking with correct arguments")
    void shippedEvent_callsStartTracking() throws Exception {
        String message = """
                {
                  "eventType": "ORDER_STATUS_CHANGED",
                  "orderId": "NW-2026-000042",
                  "newStatus": "SHIPPED",
                  "trackingNumber": "1Z999AA10123456784",
                  "carrier": "UPS"
                }
                """;

        testProducer().send(TOPIC, "NW-2026-000042", message);

        verify(trackingService, timeout(10_000).times(1))
                .startTracking(
                        eq("NW-2026-000042"),
                        eq("1Z999AA10123456784"),
                        eq("UPS")
                );
        verify(trackingService, never()).stopTracking(eq("NW-2026-000042"));
    }

    // -------------------------------------------------------------------------
    // DELIVERED → stopTracking
    // -------------------------------------------------------------------------

    @Test
    @DisplayName("DELIVERED event calls stopTracking")
    void deliveredEvent_callsStopTracking() throws Exception {
        String message = """
                {
                  "eventType": "ORDER_STATUS_CHANGED",
                  "orderId": "NW-DELIVERED-001",
                  "newStatus": "DELIVERED",
                  "trackingNumber": "1Z999AA10123456784",
                  "carrier": "UPS"
                }
                """;

        testProducer().send(TOPIC, "NW-DELIVERED-001", message);

        verify(trackingService, timeout(10_000).times(1))
                .stopTracking(eq("NW-DELIVERED-001"));
        verify(trackingService, never()).startTracking(
                eq("NW-DELIVERED-001"), anyString(), anyString());
    }

    // -------------------------------------------------------------------------
    // CANCELLED → stopTracking
    // -------------------------------------------------------------------------

    @Test
    @DisplayName("CANCELLED event calls stopTracking")
    void cancelledEvent_callsStopTracking() throws Exception {
        String message = """
                {
                  "eventType": "ORDER_STATUS_CHANGED",
                  "orderId": "NW-CANCELLED-001",
                  "newStatus": "CANCELLED",
                  "trackingNumber": "1Z999AA10123456784",
                  "carrier": "UPS"
                }
                """;

        testProducer().send(TOPIC, "NW-CANCELLED-001", message);

        verify(trackingService, timeout(10_000).times(1))
                .stopTracking(eq("NW-CANCELLED-001"));
    }

    // -------------------------------------------------------------------------
    // Malformed JSON → skipped, no exception propagated
    // -------------------------------------------------------------------------

    @Test
    @DisplayName("Malformed JSON is logged and skipped — no service call made")
    void malformedJson_isSkippedGracefully() throws Exception {
        String badMessage = "THIS IS NOT JSON {{{";

        testProducer().send(TOPIC, "bad-key-001", badMessage);

        // Wait for the consumer to have processed the message.
        TimeUnit.SECONDS.sleep(3);

        verify(trackingService, never()).startTracking(
                eq("bad-key-001"), anyString(), anyString());
        verify(trackingService, never()).stopTracking(eq("bad-key-001"));
    }

    // -------------------------------------------------------------------------
    // Unknown status → silently ignored
    // -------------------------------------------------------------------------

    @Test
    @DisplayName("Unknown status (e.g. PROCESSING) is silently ignored")
    void unknownStatus_isIgnored() throws Exception {
        String message = """
                {
                  "eventType": "ORDER_STATUS_CHANGED",
                  "orderId": "NW-PROCESSING-001",
                  "newStatus": "PROCESSING",
                  "trackingNumber": "1Z999AA10123456784",
                  "carrier": "UPS"
                }
                """;

        testProducer().send(TOPIC, "NW-PROCESSING-001", message);

        // Wait a bit to confirm no service calls are made.
        TimeUnit.SECONDS.sleep(3);

        verify(trackingService, never()).startTracking(
                eq("NW-PROCESSING-001"), anyString(), anyString());
        verify(trackingService, never()).stopTracking(eq("NW-PROCESSING-001"));
    }
}
