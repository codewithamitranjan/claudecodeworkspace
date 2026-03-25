# Northwind Tracking Service

Spring Boot 3.3 / Java 21 microservice for shipment tracking.
This is the **second service** extracted from the PHP 5 monolith as part of Northwind Logistics' incremental migration.

---

## What this service does

- Accepts shipments to track via Kafka (`northwind.orders.status` topic).
- Polls carrier APIs (UPS, FedEx, USPS, DHL) on a schedule and on demand.
- Publishes status updates to Kafka (`northwind.shipment.tracked` topic).
- Exposes a REST API for current status and event history.
- Writes to the legacy MySQL `tracking_events` table during the migration window (dual-write).

---

## Architecture

```
┌────────────────────────────────────────────────────────────┐
│                   northwind-tracking-service               │
│                                                            │
│  REST API (8080)                                           │
│    GET  /api/tracking/{trackingNumber}                     │
│    POST /api/tracking/poll                                 │
│    GET  /api/tracking/order/{orderId}                      │
│                                                            │
│  Kafka Consumer ──────────────────────────────────────┐    │
│    northwind.orders.status                            │    │
│    ORDER_STATUS_CHANGED → SHIPPED   → startTracking  │    │
│    ORDER_STATUS_CHANGED → DELIVERED → stopTracking   │    │
│    ORDER_STATUS_CHANGED → CANCELLED → stopTracking   │    │
│                                                       ▼    │
│  TrackingService ──────────────────────────────────────┐   │
│    detectCarrier(trackingNumber) [regex, PHP port]     │   │
│    pollCarrier() → saves TrackingEvent + OutboxEvent   │   │
│    startTracking() → saves ActiveShipment              │   │
│    stopTracking()  → marks shipment COMPLETED          │   │
│                          │                             │   │
│              ┌───────────┘                             │   │
│              ▼                                         │   │
│  Outbox Pattern                                        │   │
│    TrackingEvent ──┐                                   │   │
│    OutboxEvent   ──┘ same Postgres TX                  │   │
│                                                        │   │
│  OutboxProcessor (every 5s)                            │   │
│    reads outbox_events WHERE published=false           │   │
│    publishes to northwind.shipment.tracked             │   │
│    marks published=true                                │   │
│                                                        │   │
│  DualWriteAdapter (during migration)                   │   │
│    writes TrackingEvent to legacy MySQL                │   │
│    controlled by DUAL_WRITE_ENABLED env var            │   │
└────────────────────────────────────────────────────────────┘

Databases:
  Postgres (own) — tracking_events, active_shipments, outbox_events
  MySQL (legacy) — tracking_events (monolith still reads this)

Kafka topics consumed:  northwind.orders.status
Kafka topics published: northwind.shipment.tracked
```

---

## The Dual-Write Problem and Solution

### What was wrong in the PHP monolith

`TrackingService.php` wrote to two places independently:

```php
// PHP — not atomic, no transaction, broken lock file
$this->db->insert('tracking_events', $data);         // MySQL write
file_put_contents($cacheFile, json_encode($data));   // flat-file write
```

If the process died between those two writes the stores became inconsistent. The flat-file cache was the "source of truth" for the UI while MySQL was used by reporting jobs — they regularly drifted.

### What this service does instead

**Outbox Pattern** — the tracking event and the Kafka message intent are written to Postgres in a single transaction:

```
TrackingService.pollCarrier()
  ├── INSERT tracking_events  ┐
  └── INSERT outbox_events    ┘  same @Transactional boundary

OutboxProcessor (every 5 s)
  ├── SELECT * FROM outbox_events WHERE published = false
  ├── kafkaTemplate.send(topic, key, payload).get()   ← blocks for ack
  └── UPDATE outbox_events SET published = true, published_at = now()
```

This gives **at-least-once delivery** to Kafka. Consumers must de-duplicate on `(trackingNumber, timestamp)` if strict exactly-once is required.

**DualWriteAdapter** — during the migration window, every `pollCarrier()` call also writes to the legacy MySQL `tracking_events` table so the PHP monolith continues to see fresh data. This is deliberately NOT part of the Postgres transaction — a MySQL failure logs an error and is swallowed so it never blocks the primary flow.

---

## Turning off dual-write after migration is complete

When all consumers of the MySQL `tracking_events` table have been migrated to read from Kafka or the Postgres REST API:

**Step 1** — set the environment variable:

```bash
DUAL_WRITE_ENABLED=false
```

Or in `docker-compose.yml`:

```yaml
environment:
  DUAL_WRITE_ENABLED: "false"
```

**Step 2** — restart the service. No code changes are required.

When disabled:
- `DualWriteAdapter.writeToLegacyMySQL()` returns immediately with a DEBUG log.
- The `legacyMysqlDataSource` bean is never created, so the MySQL driver and connection pool are never initialized.
- The MySQL JDBC dependency can be removed from `pom.xml` at your leisure.

**Verification** — to confirm dual-write is off, look for this line in the logs at startup:

```
[DUAL-WRITE] DISABLED — skipping MySQL datasource creation
```

And confirm no `[DUAL-WRITE] SUCCESS` lines appear during normal operation:

```bash
kubectl logs -l app=tracking-service | grep '\[DUAL-WRITE\]'
```

---

## Carrier detection

The following regex patterns are ported directly from `TrackingService.php`:

| Carrier | Pattern             | Example                    |
|---------|---------------------|----------------------------|
| UPS     | `1Z[A-Z0-9]{16}`    | `1Z999AA10123456784`        |
| FedEx   | `[0-9]{12}`         | `123456789012`              |
| FedEx   | `[0-9]{15}`         | `123456789012345`           |
| USPS    | `94[0-9]{20}`       | `9400111899223397990051`    |
| DHL     | `[0-9]{10}`         | `1234567890`                |

---

## Running locally

### Prerequisites

- Java 21
- Docker + Docker Compose
- Maven 3.9+

### Start infrastructure

```bash
docker-compose up -d db zookeeper kafka
```

### Run the service

```bash
./mvnw spring-boot:run
```

The service starts on port **8080** (mapped to 8081 in docker-compose).

### Or run everything with Docker Compose

```bash
./mvnw package -DskipTests
docker-compose up --build
```

### Kafka UI

Open http://localhost:8090 to inspect topics and messages.

---

## REST API

### Get current tracking status

```
GET /api/tracking/{trackingNumber}
```

Response:

```json
{
  "carrier": "UPS",
  "trackingNumber": "1Z999AA10123456784",
  "status": "IN_TRANSIT",
  "location": "Chicago, IL — Distribution Center",
  "estimatedDelivery": "2026-03-24",
  "orderId": "NW-2026-000042",
  "source": "CACHE",
  "lastUpdated": "2026-03-22T10:15:30Z",
  "events": [...]
}
```

`source` is `"LIVE"` for a fresh carrier poll, `"CACHE"` for a stored event.

### Manually trigger a poll

```
POST /api/tracking/poll
Content-Type: application/json

{
  "trackingNumber": "1Z999AA10123456784",
  "carrier": "UPS"
}
```

`carrier` is optional — omit it to let the service auto-detect.

### Get all events for an order

```
GET /api/tracking/order/{orderId}
```

---

## Running tests

```bash
./mvnw test
```

Tests use H2 in-memory DB and embedded Kafka — no external infrastructure required.

---

## Key design decisions

| Decision | Rationale |
|---|---|
| Outbox pattern over direct Kafka publish | Eliminates the dual-write race condition from the PHP monolith. DB + outbox row are atomic; Kafka send is decoupled. |
| Mock carrier client | All PHP carrier API endpoints are deprecated. Defers new API contract negotiation until after the migration is stable. |
| Tracking number as Kafka message key | Guarantees all events for a shipment land on the same partition and are consumed in order. |
| DualWriteAdapter outside Postgres TX | A degraded legacy MySQL must never block the primary tracking flow. Errors are logged, not propagated. |
| `DUAL_WRITE_ENABLED` flag | Zero-code-change cutover — flip the env var and restart. |
