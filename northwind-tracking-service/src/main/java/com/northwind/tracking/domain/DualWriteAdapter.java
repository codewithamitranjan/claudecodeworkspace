package com.northwind.tracking.domain;

import com.northwind.tracking.entity.TrackingEvent;
import lombok.RequiredArgsConstructor;
import lombok.extern.slf4j.Slf4j;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.stereotype.Component;

import javax.sql.DataSource;
import java.sql.Connection;
import java.sql.PreparedStatement;
import java.sql.Timestamp;
import java.time.Instant;

/**
 * Dual-write adapter — writes tracking events to the legacy MySQL
 * {@code tracking_events} table during the migration window.
 *
 * <h3>Why dual-write?</h3>
 * The PHP monolith still reads from MySQL {@code tracking_events}.  Until every
 * consumer of that table has been migrated to read from the new Kafka topic or
 * the Postgres API, we must keep both stores in sync.
 *
 * <h3>How to disable after migration</h3>
 * Set the environment variable {@code DUAL_WRITE_ENABLED=false} (or remove it).
 * When disabled, this adapter logs a single debug message and returns immediately
 * without touching MySQL.  No code changes are needed.
 *
 * <h3>Monitoring</h3>
 * Every write (and every skip) is prefixed with {@code [DUAL-WRITE]} in the logs
 * so you can grep for it:
 * <pre>
 *   kubectl logs -l app=tracking-service | grep '\[DUAL-WRITE\]'
 * </pre>
 *
 * <h3>Safety</h3>
 * This adapter intentionally does NOT participate in the Postgres transaction.
 * Failures are caught, logged, and swallowed so a degraded legacy MySQL instance
 * never blocks the primary tracking flow.  Operators should alert on
 * {@code [DUAL-WRITE] FAILED} log lines during the migration window.
 */
@Component
@Slf4j
public class DualWriteAdapter {

    private static final String INSERT_SQL =
            "INSERT INTO tracking_events " +
            "(tracking_number, carrier, status, location, order_id, event_time, raw_response, source) " +
            "VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

    private final boolean dualWriteEnabled;
    // Nullable — only present when DUAL_WRITE_ENABLED=true (see DataSourceConfig).
    private final DataSource legacyMysqlDataSource;

    public DualWriteAdapter(
            @Value("${dual-write.enabled:true}") boolean dualWriteEnabled,
            // Use @org.springframework.beans.factory.annotation.Autowired(required=false)
            // so Spring does not fail when the MySQL datasource bean is absent.
            @org.springframework.beans.factory.annotation.Autowired(required = false)
            @org.springframework.beans.factory.annotation.Qualifier("legacyMysqlDataSource")
            DataSource legacyMysqlDataSource) {
        this.dualWriteEnabled    = dualWriteEnabled;
        this.legacyMysqlDataSource = legacyMysqlDataSource;
    }

    /**
     * Writes a {@link TrackingEvent} to the legacy MySQL {@code tracking_events}
     * table.  No-op when {@code DUAL_WRITE_ENABLED=false} or when the MySQL
     * datasource bean was not created.
     */
    public void writeToLegacyMySQL(TrackingEvent event) {
        if (!dualWriteEnabled) {
            log.debug("[DUAL-WRITE] DISABLED — skipping legacy MySQL write for trackingNumber={}",
                    event.getTrackingNumber());
            return;
        }

        if (legacyMysqlDataSource == null) {
            log.warn("[DUAL-WRITE] ENABLED but legacyMysqlDataSource bean is null " +
                     "— check DataSourceConfig. Skipping write for trackingNumber={}",
                    event.getTrackingNumber());
            return;
        }

        try (Connection conn = legacyMysqlDataSource.getConnection();
             PreparedStatement ps = conn.prepareStatement(INSERT_SQL)) {

            ps.setString(1, event.getTrackingNumber());
            ps.setString(2, event.getCarrier());
            ps.setString(3, event.getStatus());
            ps.setString(4, event.getLocation());
            ps.setString(5, event.getOrderId());
            ps.setTimestamp(6, Timestamp.from(
                    event.getEventTime() != null ? event.getEventTime() : Instant.now()));
            ps.setString(7, event.getRawResponse());
            ps.setString(8, event.getSource() != null ? event.getSource() : "LIVE");

            int rows = ps.executeUpdate();

            log.info("[DUAL-WRITE] SUCCESS — wrote {} row(s) to legacy MySQL for trackingNumber={} status={}",
                    rows, event.getTrackingNumber(), event.getStatus());

        } catch (Exception e) {
            // Never let a legacy DB failure block the primary tracking flow.
            log.error("[DUAL-WRITE] FAILED — could not write to legacy MySQL for trackingNumber={}: {}",
                    event.getTrackingNumber(), e.getMessage(), e);
        }
    }
}
