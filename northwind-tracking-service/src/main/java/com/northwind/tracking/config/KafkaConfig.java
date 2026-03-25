package com.northwind.tracking.config;

import com.fasterxml.jackson.databind.ObjectMapper;
import com.fasterxml.jackson.databind.SerializationFeature;
import com.fasterxml.jackson.datatype.jsr310.JavaTimeModule;
import org.apache.kafka.clients.consumer.ConsumerConfig;
import org.apache.kafka.clients.producer.ProducerConfig;
import org.apache.kafka.common.serialization.StringDeserializer;
import org.apache.kafka.common.serialization.StringSerializer;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.context.annotation.Bean;
import org.springframework.context.annotation.Configuration;
import org.springframework.kafka.annotation.EnableKafka;
import org.springframework.kafka.config.ConcurrentKafkaListenerContainerFactory;
import org.springframework.kafka.core.*;

import java.util.HashMap;
import java.util.Map;

/**
 * Kafka producer and consumer configuration.
 *
 * Both producer and consumer use plain String serialization/deserialization.
 * The service is responsible for its own JSON serialization via Jackson so that
 * schema evolution is explicit and not hidden inside Spring's magic converters.
 *
 * Key ordering guarantee:
 *   The producer uses {@code trackingNumber} as the message key, ensuring all
 *   events for the same shipment land on the same partition and are consumed
 *   in strict order.
 */
@Configuration
@EnableKafka
public class KafkaConfig {

    @Value("${spring.kafka.bootstrap-servers:localhost:9092}")
    private String bootstrapServers;

    @Value("${spring.kafka.consumer.group-id:tracking-service}")
    private String consumerGroupId;

    @Value("${spring.kafka.consumer.auto-offset-reset:earliest}")
    private String autoOffsetReset;

    // -------------------------------------------------------------------------
    // Producer
    // -------------------------------------------------------------------------

    @Bean
    public ProducerFactory<String, String> producerFactory() {
        Map<String, Object> props = new HashMap<>();
        props.put(ProducerConfig.BOOTSTRAP_SERVERS_CONFIG,  bootstrapServers);
        props.put(ProducerConfig.KEY_SERIALIZER_CLASS_CONFIG,   StringSerializer.class);
        props.put(ProducerConfig.VALUE_SERIALIZER_CLASS_CONFIG, StringSerializer.class);
        // Idempotent producer — avoids duplicate messages on retry.
        props.put(ProducerConfig.ENABLE_IDEMPOTENCE_CONFIG, true);
        // Wait for all in-sync replicas to ack before considering send successful.
        props.put(ProducerConfig.ACKS_CONFIG, "all");
        props.put(ProducerConfig.RETRIES_CONFIG, 3);
        return new DefaultKafkaProducerFactory<>(props);
    }

    @Bean
    public KafkaTemplate<String, String> kafkaTemplate() {
        return new KafkaTemplate<>(producerFactory());
    }

    // -------------------------------------------------------------------------
    // Consumer
    // -------------------------------------------------------------------------

    @Bean
    public ConsumerFactory<String, String> consumerFactory() {
        Map<String, Object> props = new HashMap<>();
        props.put(ConsumerConfig.BOOTSTRAP_SERVERS_CONFIG,  bootstrapServers);
        props.put(ConsumerConfig.GROUP_ID_CONFIG,            consumerGroupId);
        props.put(ConsumerConfig.AUTO_OFFSET_RESET_CONFIG,   autoOffsetReset);
        props.put(ConsumerConfig.KEY_DESERIALIZER_CLASS_CONFIG,   StringDeserializer.class);
        props.put(ConsumerConfig.VALUE_DESERIALIZER_CLASS_CONFIG, StringDeserializer.class);
        // Do not auto-commit — offsets are committed after successful processing.
        props.put(ConsumerConfig.ENABLE_AUTO_COMMIT_CONFIG, false);
        return new DefaultKafkaConsumerFactory<>(props);
    }

    @Bean
    public ConcurrentKafkaListenerContainerFactory<String, String> kafkaListenerContainerFactory() {
        ConcurrentKafkaListenerContainerFactory<String, String> factory =
                new ConcurrentKafkaListenerContainerFactory<>();
        factory.setConsumerFactory(consumerFactory());
        // Manual offset acknowledgement (AckMode.RECORD) via the default batch listener.
        factory.getContainerProperties()
               .setAckMode(org.springframework.kafka.listener.ContainerProperties.AckMode.RECORD);
        return factory;
    }

    // -------------------------------------------------------------------------
    // Shared Jackson ObjectMapper (used by TrackingService & Kafka consumers)
    // -------------------------------------------------------------------------

    @Bean
    public ObjectMapper objectMapper() {
        ObjectMapper mapper = new ObjectMapper();
        // Handle java.time types (Instant, LocalDate) without needing @JsonSerialize annotations.
        mapper.registerModule(new JavaTimeModule());
        mapper.disable(SerializationFeature.WRITE_DATES_AS_TIMESTAMPS);
        return mapper;
    }
}
