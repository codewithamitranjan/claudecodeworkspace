# CLAUDE.md — Northwind Logistics Modernization Hackathon

This is the source of truth for what "done" means on every challenge.
The monolith must remain bootable and its characterization tests green at every commit.

---

## What This Codebase Is

PHP 5 legacy monolith for **Northwind Logistics**, a freight and shipping company.
Written in the style of real enterprise PHP circa 2008–2013. The ugliness is intentional.
**Do not clean up code that is not part of your current challenge.**

Boot it:
```bash
docker compose up --build
# App: http://localhost:8080
# Login: admin / admin123
```

---

## Codebase Map (read this before touching anything)

```
northwind-monolith/
├── index.php                     ← God entry point — router, auth, dashboard, XSS
├── config.php                    ← Hardcoded DB creds, $GLOBALS setup, magic constants
├── db.php                        ← mysql_connect(), global $conn, password in die()
├── lib/helpers.php               ← Grab-bag: eval(), duplicate freight calc, dd()
├── modules/
│   ├── auth/login.php            ← SQL injection login, MD5 passwords
│   ├── auth/session.php          ← Triple session_start(), serialized user in $_SESSION
│   ├── orders/OrderManager.php   ← GOD CLASS (1,100+ lines) — owns everything
│   ├── orders/order_list.php     ← Mixed PHP/HTML, $_GET['page'] conflict
│   ├── orders/order_detail.php   ← 4 OrderManager calls + inline SQL
│   ├── freight/FreightCalc.php   ← Circular dep → OrderManager, flat-file cache
│   ├── freight/carrier_rates.php ← $GLOBALS['carrier_rates'] with plaintext API keys
│   ├── customers/CustomerDB.php  ← searchCustomers() LIKE injection
│   ├── invoicing/InvoiceGen.php  ← Circular dep → FreightCalc + OrderManager
│   └── tracking/TrackingService.php ← file_get_contents() to dead carrier APIs
├── sql/northwind_schema.sql      ← Full schema + 5 customers, 10 products, 5 orders
└── docker-compose.yml
```

### Circular Dependency Triangle

```
OrderManager.php  ──requires──▶  InvoiceGen.php
       ▲                               │
       │                           requires
       └────────── FreightCalc.php ◀──┘
```

PHP's `require_once` prevents infinite recursion but the load order is fragile.
Any new file added to this chain will break with "Class not found".

### The God Class

`modules/orders/OrderManager.php` — 1,100+ lines. Owns:
- Order CRUD + 8-state status machine
- Order items, discounts (TESTCODE = 100% off, never removed)
- Freight calculation (calls FreightCalc)
- Pick list HTML (echoes directly — no return value)
- Confirmation emails (calls mail() inline)
- Invoice existence checks (calls InvoiceGen — circular)
- Cron archival with sleep(1) in a loop
- `hackfix_recalculate_totals()` — fixes June 2011 billing bug, do not remove

---

## The Three Business Capabilities

These are the seams everything else cuts along.

| Capability | Primary File | Extraction Risk |
|---|---|---|
| **Order Management** | `OrderManager.php` | HIGH — God class, circular deps |
| **Freight Rating** | `FreightCalc.php` | LOW — stateless, narrow inputs/outputs |
| **Shipment Tracking** | `TrackingService.php` | MEDIUM — external APIs, caching side-effects |

---

## Target Architecture — Microservices on Spring Boot + Java

This section defines the **destination architecture** the modernization is working toward.
Every extraction decision, API contract, and infrastructure choice should be evaluated against this blueprint.

---

### Architecture Overview

```
                        ┌─────────────────────────────────────────────────────┐
                        │                  CLIENTS                            │
                        │   Web App   │   Mobile   │   3rd-party / EDI        │
                        └──────────────────┬──────────────────────────────────┘
                                           │ HTTPS
                                           ▼
                        ┌─────────────────────────────────────────────────────┐
                        │              API GATEWAY (Spring Cloud Gateway)     │
                        │  • Auth token validation (JWT / OAuth2)             │
                        │  • Rate limiting                                    │
                        │  • Request routing to downstream services           │
                        │  • SSL termination                                  │
                        └────┬──────────┬──────────┬──────────┬──────────────┘
                             │          │          │          │
              ┌──────────────▼──┐  ┌────▼─────┐  ┌▼──────────┐  ┌▼──────────────┐
              │  Order Service  │  │ Freight  │  │ Customer  │  │  Tracking     │
              │  (Spring Boot)  │  │ Rating   │  │ Service   │  │  Service      │
              │                 │  │ Service  │  │ (Spring   │  │  (Spring Boot)│
              │  - Create order │  │(Spring   │  │  Boot)    │  │               │
              │  - Status FSM   │  │  Boot)   │  │           │  │  - Carrier    │
              │  - Order items  │  │          │  │  - CRUD   │  │    polling    │
              │  - Discounts    │  │  - Rate  │  │  - Search │  │  - Status     │
              │  - Pick lists   │  │    calc  │  │  - Credit │  │    cache      │
              └────────┬────────┘  │  - Zones │  └─────┬─────┘  └──────┬────────┘
                       │           │  - Surcharge│      │               │
                       │           └─────┬────────┘      │               │
                       │                 │                │               │
                       ▼                 ▼                ▼               ▼
              ┌──────────────────────────────────────────────────────────────────┐
              │                  MESSAGE BROKER (Apache Kafka)                   │
              │                                                                  │
              │  Topics:                                                         │
              │  • northwind.orders.created      • northwind.orders.status       │
              │  • northwind.freight.rated        • northwind.shipment.tracked    │
              │  • northwind.invoices.generated   • northwind.payments.received  │
              └──────────────────────────────────────────────────────────────────┘
                       │                 │                │               │
                       ▼                 ▼                ▼               ▼
              ┌──────────┐       ┌──────────┐    ┌──────────┐    ┌──────────────┐
              │ Order DB │       │Freight DB│    │Customer  │    │ Tracking DB  │
              │(Postgres)│       │(Postgres)│    │   DB     │    │  (Postgres)  │
              │          │       │          │    │(Postgres)│    │              │
              └──────────┘       └──────────┘    └──────────┘    └──────────────┘

              ┌──────────────────────────────────────────────────────────────────┐
              │                   CROSS-CUTTING SERVICES                         │
              │                                                                  │
              │  ┌─────────────────┐  ┌──────────────────┐  ┌─────────────────┐ │
              │  │ Invoice Service │  │Notification Svc  │  │  Audit Service  │ │
              │  │  (Spring Boot)  │  │  (Spring Boot)   │  │  (Spring Boot)  │ │
              │  │  - Generate PDF │  │  - Email / SMS   │  │  - Event log    │ │
              │  │  - Payment track│  │  - Webhooks      │  │  - Compliance   │ │
              │  └─────────────────┘  └──────────────────┘  └─────────────────┘ │
              └──────────────────────────────────────────────────────────────────┘

              ┌──────────────────────────────────────────────────────────────────┐
              │                   OBSERVABILITY STACK                            │
              │  Prometheus + Grafana   │   ELK / OpenTelemetry   │   Zipkin     │
              └──────────────────────────────────────────────────────────────────┘
```

---

### Service Breakdown

| Service | Extracted From | Spring Boot Module | Own DB | Publishes Events | Consumes Events |
|---|---|---|---|---|---|
| **Order Service** | `OrderManager.php` | `order-service` | `orders` (Postgres) | `orders.created`, `orders.status` | `freight.rated`, `invoices.generated` |
| **Freight Rating Service** | `FreightCalc.php` | `freight-service` | `freight_rates` (Postgres) | `freight.rated` | `orders.created` |
| **Customer Service** | `CustomerDB.php` | `customer-service` | `customers` (Postgres) | `customers.updated` | — |
| **Tracking Service** | `TrackingService.php` | `tracking-service` | `tracking_events` (Postgres) | `shipment.tracked` | `orders.status` |
| **Invoice Service** | `InvoiceGen.php` | `invoice-service` | `invoices` (Postgres) | `invoices.generated` | `orders.status`, `freight.rated` |
| **Notification Service** | `helpers.php::sendEmail()` | `notification-service` | — (stateless) | — | `invoices.generated`, `orders.status` |
| **API Gateway** | `index.php` routing | Spring Cloud Gateway | — | — | — |

---

### Tech Stack Decisions

```
Layer                   Technology                  Why
─────────────────────────────────────────────────────────────────────
Runtime                 Java 21 (LTS)               Virtual threads (Project Loom), long-term support
Framework               Spring Boot 3.3             Spring Cloud ecosystem, Actuator, native image ready
API style               REST + OpenAPI 3.1          Machine-readable contracts, matches PHP-era REST calls
Async messaging         Apache Kafka                Durable, replayable, supports exactly-once semantics
Service discovery       Spring Cloud Eureka         Self-registration, client-side load balancing
Config management       Spring Cloud Config         Centralised, environment-aware, Git-backed
API Gateway             Spring Cloud Gateway        Filter chains, JWT auth, rate limiting
Database                PostgreSQL 15               Per-service, ACID, JSON columns for flexibility
Migrations              Flyway                      Version-controlled schema, CI-safe
ORM                     Spring Data JPA + Hibernate Transaction management, repository pattern
Distributed tracing     Micrometer + Zipkin         Trace IDs across service hops, latency visibility
Metrics                 Micrometer + Prometheus     Standardised /actuator/prometheus endpoint
Log aggregation         ELK (Elasticsearch, Logstash, Kibana)  Structured JSON logs from all services
Containerisation        Docker + Kubernetes (K8s)   Independent deploy, horizontal scaling
CI/CD                   GitHub Actions              Per-service pipelines, independent deploys
Secrets                 HashiCorp Vault / K8s Secrets  No plaintext creds (replaces config.php)
```

---

### Scalability Design

#### Horizontal Scaling Per Service

Each service scales independently based on its own load profile:

```
Freight Rating  ──▶  CPU-bound (rate calc)  ──▶  Scale on CPU > 60%
Order Service   ──▶  I/O-bound (DB writes)  ──▶  Scale on request queue depth
Tracking        ──▶  Polling-bound           ──▶  Scale on active shipment count
```

Spring Boot Actuator exposes `/actuator/health` and `/actuator/metrics` —
Kubernetes HPA (Horizontal Pod Autoscaler) reads these to trigger scaling.

#### Database Scaling

```
Read-heavy service (Customer, Tracking)  ──▶  Read replica + connection pool (HikariCP)
Write-heavy service (Order, Invoice)      ──▶  Primary only, optimistic locking
High-throughput (Freight Rating)          ──▶  Redis cache layer in front of Postgres
```

#### Kafka Partitioning Strategy

```
Topic                     Partition Key         Why
northwind.orders.*        order_id              All events for one order go to same partition (ordering)
northwind.freight.*       origin_zip prefix     Geographic locality, even distribution
northwind.shipment.*      carrier_code          Carrier-specific consumers can filter cheaply
```

---

### Transactional Integrity

This is the hardest part of the migration. The PHP monolith uses implicit MySQL transactions
(autocommit on). The new architecture is distributed — no single transaction spans services.

#### Pattern 1 — Saga (Choreography) for Order Creation

```
1. Order Service creates order (status = PENDING)     ──▶  publishes orders.created
2. Freight Service rates the shipment                 ──▶  publishes freight.rated
3. Order Service updates total                        ──▶  publishes orders.confirmed
4. Invoice Service generates invoice                  ──▶  publishes invoices.generated
5. Notification Service sends confirmation email

COMPENSATING TRANSACTIONS (rollback path):
If step 3 fails  ──▶  Order Service publishes orders.cancelled
                 ──▶  Invoice Service listens and voids any draft invoice
                 ──▶  Notification Service sends cancellation email
```

#### Pattern 2 — Outbox Pattern (prevents dual-write problem)

The monolith currently writes to DB AND sometimes to flat files simultaneously —
a classic dual-write bug. In the new architecture:

```java
// WRONG — dual write, not atomic:
orderRepository.save(order);           // DB write
kafkaTemplate.send("orders.created");  // event publish — can fail independently

// RIGHT — Outbox pattern:
// 1. Write order + outbox event in ONE local DB transaction
orderRepository.save(order);
outboxRepository.save(new OutboxEvent("orders.created", payload));  // same TX

// 2. Separate Outbox Processor polls and publishes (uses Debezium CDC or scheduled job)
//    If publish fails → retry from outbox. Order DB is the source of truth.
```

Every service that both writes to its DB and publishes to Kafka must use the outbox pattern.

#### Pattern 3 — Idempotency Keys

All POST endpoints accept an `Idempotency-Key` header.
Services store processed keys in a short-TTL Redis cache.
Duplicate requests within TTL return the cached response — no double processing.

```
POST /orders
Idempotency-Key: 550e8400-e29b-41d4-a716-446655440000
```

#### Optimistic Locking for Concurrent Order Updates

Replace the monolith's implicit last-write-wins with JPA optimistic locking:

```java
@Entity
public class Order {
    @Version
    private Long version;   // Incremented on every update
    // ...
}
// Concurrent update → OptimisticLockException → caller retries
```

---

### Anti-Corruption Layer (ACL) Strategy

During the Strangler Fig migration, old and new code coexist.
The ACL prevents legacy data shapes from leaking into new services.

```
PHP Monolith                     ACL (in PHP)                  New Java Service
─────────────────────────────────────────────────────────────────────────────────
$order['cust_id']           ──▶  FreightServiceAdapter  ──▶  customerId: UUID
$order['wt_lbs']            ──▶  converts units         ──▶  weightKg: Double
$result['rate_cents']       ◀──  converts response      ◀──  rate: BigDecimal (dollars)
status int (0-7 enum)       ──▶  maps to string         ──▶  status: OrderStatus enum
```

ACL rules:
- ACL lives in the **monolith**, never in the Java service
- Java service API uses clean domain language (no `cust_id`, no `wt_lbs`)
- ACL is toggled by feature flag `USE_NEW_FREIGHT_SERVICE` env var
- ACL logs every translation mismatch to `audit_log` table during transition

---

### Security — What Changes from PHP Monolith

| PHP Monolith (now) | Java Microservices (target) |
|---|---|
| MD5 passwords in `users` table | BCrypt via Spring Security |
| Session in `$_SESSION` global | Stateless JWT (RS256), issued by Auth Service |
| SQL via string concat | JPA / PreparedStatement only |
| Hardcoded DB creds in `config.php` | Injected via K8s Secrets / Vault |
| No CSRF tokens | CSRF token per form (Spring Security CSRF filter) |
| Plaintext API keys in `carrier_rates.php` | Vault-backed secret rotation |
| No inter-service auth | mTLS between services (cert managed by cert-manager) |
| Single admin role | RBAC via Spring Security + JWT claims |

---

### Observability Requirements

Every Spring Boot service must expose:

```
/actuator/health        ← Kubernetes liveness + readiness probes
/actuator/metrics       ← Prometheus scrape endpoint
/actuator/info          ← Build version, git SHA (for deploy verification)
```

Structured log format (JSON, every service):
```json
{
  "timestamp": "2026-03-22T10:15:30Z",
  "level": "INFO",
  "service": "freight-service",
  "traceId": "abc123",
  "spanId": "def456",
  "orderId": "ORD-00042",
  "message": "Rate calculated",
  "rateUsd": 47.82,
  "zone": 5,
  "carrier": "FEDEX"
}
```

Trace IDs must propagate across service calls via `X-B3-TraceId` / W3C `traceparent` headers.

---

### Migration Phases

```
Phase 1 — Strangle (now → extraction complete)
  Nginx routes /api/freight/* to Java Freight Service
  ACL in PHP monolith translates requests/responses
  Both old and new paths live simultaneously
  Feature flag controls which path is active

Phase 2 — Isolate (after each service extraction)
  Service has its own Postgres DB (seeded from MySQL dump)
  No direct DB access from monolith to service's DB
  Event backbone (Kafka) carries state changes between services

Phase 3 — Cut Over (per service)
  Feature flag flipped to 100% new service
  ACL remains for 30 days (rollback window)
  Characterization tests run against both paths — must match

Phase 4 — Decommission (per module)
  Old PHP module removed after 30-day clean run
  ACL removed
  MySQL table ownership transferred to service's Postgres DB
  Old table dropped (with backup)
```

---

### Project Structure (Target Java Monorepo)

```
northwind-services/
├── api-gateway/                  Spring Cloud Gateway
├── order-service/
│   ├── src/main/java/com/northwind/order/
│   │   ├── domain/               Order, OrderItem, OrderStatus (pure domain, no Spring)
│   │   ├── application/          OrderService, CreateOrderCommand, OrderSaga
│   │   ├── infrastructure/       OrderJpaRepository, KafkaOrderPublisher, OutboxProcessor
│   │   └── api/                  OrderController, OrderRequest/Response DTOs
│   └── src/test/java/
│       ├── unit/                 Domain logic tests (no Spring context)
│       ├── integration/          @SpringBootTest with Testcontainers
│       └── contract/             Spring Cloud Contract — provider side
├── freight-service/              Same structure
├── customer-service/             Same structure
├── tracking-service/             Same structure
├── invoice-service/              Same structure
├── notification-service/         Same structure
├── common/
│   ├── events/                   Shared Kafka event schemas (Avro / JSON Schema)
│   └── security/                 JWT validation filter, shared RBAC annotations
└── infra/
    ├── docker-compose.yml        Local: all services + Kafka + Postgres + Zipkin
    ├── k8s/                      Kubernetes manifests per service
    └── .github/workflows/        One workflow file per service
```

---

## Challenge 1 — The Stories (PM)

**Done when** three user stories exist in `docs/stories.md` covering the three capabilities above.

Format required:
```
As a [role], I want [capability], so that [business value].

Acceptance Criteria:
- Given [context], when [action], then [outcome]
```

Minimum 3 acceptance criteria per story. Criteria must be executable by a tester
(no "system should feel fast", no "users should be happy").

Stories to write:
1. **Order Creation** — dispatcher creates a new freight order with items and customer
2. **Freight Rating** — dispatcher gets a rate quote before confirming shipment carrier
3. **Shipment Tracking** — customer service looks up live status of an active shipment

---

## Challenge 2 — The Patient (Architect)

Already done. This IS the monolith.

Verify it boots and the schema loads:
```bash
docker compose up --build
docker compose exec db mysql -uroot -proot northwind -e "SHOW TABLES;"
```

Expected tables: `users`, `customers`, `orders`, `order_items`, `products`,
`carriers`, `invoices`, `freight_rates`, `tracking_events`, `audit_log`.

**Done when:** `http://localhost:8080` renders an order list without a blank white page.

---

## Challenge 3 — The Map (Architect)

**Done when** `docs/DECOMPOSITION.md` exists and contains:

1. **Seam inventory** — list every identified seam with:
   - Name
   - Inputs (what goes in)
   - Outputs (what comes out)
   - Circular dep risk: Low / Medium / High
   - Shared state dependencies (DB tables touched)

2. **Extraction order** with written rationale for why each service ranks where it does

3. **Strategy decision** — Strangler Fig via Nginx proxy recommended:
   - Nginx routes `/api/freight/*` → new service
   - Everything else → monolith unchanged
   - ACL in the monolith translates data shapes

4. **First target identified** as Freight Rating (`FreightCalc.php`) because:
   - Stateless pure function: weight + origin + destination + carrier → price
   - No inbound circular dependencies
   - Clean input/output boundary
   - High business value (every invoice depends on it)

---

## Challenge 4 — The Pin (Tester)

Characterization tests capture what the monolith **does**, not what it **should** do.
Run them before any extraction. They are the safety net.

**Done when** `tests/characterization/` exists and:
- At least 10 input/output pairs captured for `FreightCalc::calculateZoneRate()`
  and `FreightCalc::calculateRate()`
- Tests call the code directly (PHP CLI or via HTTP to the running container)
- All tests are green and committed
- Running them again produces identical output

Key behaviors to pin (do not fix these bugs — pin them as-is):
- Florida ZIPs (320–349) → zone 4 (wrong, but that's what it does)
- Fuel surcharge = 8.5% (hardcoded, last changed 2013)
- Flat-file cache: no TTL, never expires
- `helpers.php::calcFreightEstimate()` gives different results than `FreightCalc` for same input

Run characterization tests:
```bash
docker compose exec app php tests/characterization/run.php
```

---

## Challenge 5 — The Cut (Dev)

Extract **Freight Rating** as the first service.

**Stack:** Python 3.12 + FastAPI (new service lives in `../northwind-freight-service/`)

**Done when:**
- `POST /freight/rate` accepts `{"weight_kg": N, "origin_zip": "XXXXX", "dest_zip": "XXXXX", "carrier": "FEDEX"}` and returns `{"rate": N, "zone": N, "carrier": "FEDEX", "fuel_surcharge": N}`
- New service returns identical rates to characterization test fixtures (same business logic, ported)
- Monolith's `FreightCalc.php` is still in place and still callable
- Both services run simultaneously: `docker compose up` in repo root starts both
- `http://localhost:8000/docs` shows FastAPI auto-generated API docs

Prove both work:
```bash
# Monolith (old path still works)
curl http://localhost:8080/?page=freight_estimate&weight=500&origin=60601&dest=10001

# New service
curl -X POST http://localhost:8000/freight/rate \
  -H "Content-Type: application/json" \
  -d '{"weight_kg": 227, "origin_zip": "60601", "dest_zip": "10001", "carrier": "FEDEX"}'
```

---

## Challenge 6 — The Fence (Dev)

Build the Anti-Corruption Layer (ACL) in the monolith.
The new service must not know the legacy data shape exists.

**Done when** `modules/acl/FreightServiceAdapter.php` exists and:
- `OrderManager.php` calls `FreightServiceAdapter::getRate($orderId)` instead of `FreightCalc::calculateRate()` directly
- `FreightServiceAdapter` translates the monolith's internal array format to the new service's JSON contract
- `FreightServiceAdapter` translates the JSON response back to the format `OrderManager` already expects
- Feature flag: `define('USE_NEW_FREIGHT_SERVICE', getenv('USE_NEW_FREIGHT_SERVICE') === 'true')` in `config.php`
  - `false` → ACL falls back to `FreightCalc` (old path)
  - `true` → ACL calls new service via HTTP
- Characterization tests still pass with `USE_NEW_FREIGHT_SERVICE=false`
- Characterization tests still pass with `USE_NEW_FREIGHT_SERVICE=true`

The ACL is in the **monolith**, never in the new service.
The new service's API shape is clean and permanent.

---

## Challenge 7 — The Contract (Tester)

Consumer-driven contract tests using Pact.

**Done when:**
- `../northwind-freight-service/tests/contract/test_consumer.py` — monolith's expectations of the new service (consumer side)
- `../northwind-freight-service/tests/contract/test_provider.py` — new service verifies it satisfies the consumer contract (provider side)
- Pact file committed to `../northwind-freight-service/pacts/`
- Both consumer and provider tests pass in CI
- If `FreightServiceAdapter.php` changes the request shape → consumer test fails
- If `freight.py` router changes the response shape → provider test fails

Run contract tests:
```bash
cd ../northwind-freight-service && pytest tests/contract/
```

---

## Challenge 8 — The Pipeline (Infra)

Two independent CI/CD pipelines. One failing does NOT block the other.

**Done when:**
- `.github/workflows/monolith-ci.yml` triggers on push to `northwind-monolith/**`
  - Boots PHP 5.6 + MySQL via Docker
  - Loads schema
  - Runs characterization tests
  - Passes or fails independently
- `.github/workflows/freight-service-ci.yml` triggers on push to `northwind-freight-service/**`
  - Runs pytest (unit + contract)
  - Builds Docker image
  - Pushes to registry (or GHCR) on merge to main
- Pushing a broken monolith does not block a freight service deployment
- Pushing a broken freight service does not block a monolith deployment

Verify independence: break one pipeline intentionally, confirm the other still shows green.

---

## Challenge 9 — The Second Cut (Stretch)

Extract **Shipment Tracking** as the second service.
This service must talk to the Freight Rating service via **events**, not HTTP.

**Done when:**
- `../northwind-tracking-service/` exists (Python/FastAPI or Node)
- Tracking service **publishes** a `shipment.status_updated` event to a message broker (Redis Streams or RabbitMQ) when it polls a carrier
- Freight Rating service **subscribes** and updates zone cache when a shipment moves regions
- **Dual-write handled:** during transition, tracking updates write to both old `tracking_events` table AND publish the event. New service consumes events. When old table is no longer read, dual-write stops.
- Monolith's `TrackingService.php` still works (ACL pattern again)
- Characterization tests for tracking still pass

---

## Challenge 10 — The Weekend (Stretch)

Write the production cutover runbook at `docs/CUTOVER_RUNBOOK.md`.

**Done when** the runbook contains:

1. **Pre-cutover checklist** (what must be true before you start)
2. **Step-by-step cutover** — each step is a single command or action with expected output
3. **Verification steps** after each stage — how you know it worked
4. **Rollback triggers** — explicit conditions that mean "abort and roll back NOW":
   - Error rate > X%
   - P95 latency > X ms
   - Invoice total mismatch > $0
5. **The 3am decision tree** — a flowchart (ASCII is fine) for the on-call engineer:
   - "New service returning 5xx" → go here
   - "Rates don't match" → go here
   - "Can't reach new service at all" → go here
6. **Rollback procedure** — exact commands to flip the feature flag back, in order
7. **Post-cutover** — when to delete the old FreightCalc.php (not before N days clean run)

---

## Challenge 11 — API Gateway Integration & Microservices Standards

This challenge wires all extracted services behind a single API Gateway and enforces the cross-cutting standards required for production-grade microservices.

**Stack:** Spring Cloud Gateway (replaces the Nginx Strangler Fig proxy once all services are extracted)

---

### 11.1 API Gateway (Spring Cloud Gateway)

**Done when** `northwind-api-gateway/` exists as a Spring Boot module and:

- Single entry point for all clients: `http://gateway:8080`
- Route definitions in `application.yml`:

```yaml
spring:
  cloud:
    gateway:
      routes:
        - id: freight-service
          uri: lb://freight-service        # lb:// = Eureka load-balanced
          predicates:
            - Path=/api/freight/**
          filters:
            - StripPrefix=0
            - name: RequestRateLimiter
              args:
                redis-rate-limiter.replenishRate: 100
                redis-rate-limiter.burstCapacity: 200

        - id: tracking-service
          uri: lb://tracking-service
          predicates:
            - Path=/api/tracking/**

        - id: order-service
          uri: lb://order-service
          predicates:
            - Path=/api/orders/**

        - id: customer-service
          uri: lb://customer-service
          predicates:
            - Path=/api/customers/**

        - id: monolith-fallback
          uri: http://monolith:8080        # PHP monolith for all unextracted routes
          predicates:
            - Path=/**
```

- **JWT validation filter** on all routes except `/actuator/**` and `/auth/**`
- **Rate limiting** via Redis token bucket per client IP
- **Request correlation ID** injected as `X-Correlation-ID` header on every proxied request
- `GET /actuator/gateway/routes` lists all active routes (admin-only)

---

### 11.2 Service Discovery (Spring Cloud Eureka)

**Done when** `northwind-service-registry/` exists and:

- Eureka Server running at `http://registry:8761`
- All Java services register with `@EnableEurekaClient`:
  ```yaml
  eureka:
    client:
      service-url:
        defaultZone: ${EUREKA_URL:http://registry:8761/eureka}
    instance:
      prefer-ip-address: true
      health-check-url-path: /actuator/health
  ```
- API Gateway resolves `lb://freight-service` via Eureka (no hardcoded IPs)
- `http://registry:8761` dashboard shows all registered instances
- Services deregister automatically on shutdown (graceful shutdown enabled)

---

### 11.3 Centralised Configuration (Spring Cloud Config)

**Done when** `northwind-config-server/` exists and:

- Config Server backed by a Git repository (or local `config/` folder for hackathon)
- All services fetch config from Config Server at startup:
  ```yaml
  spring:
    config:
      import: configserver:${CONFIG_SERVER_URL:http://config:8888}
  ```
- Environment-specific overrides: `freight-service-dev.yml`, `freight-service-prod.yml`
- **No hardcoded credentials in any service** — all secrets injected via Config Server or K8s Secrets
- Config refresh via `POST /actuator/refresh` without restart (`@RefreshScope` on beans that use config)
- Replaces `config.php` hardcoded credentials in the monolith

---

### 11.4 Authentication & Authorisation Standard

**Done when** a central Auth Service issues JWTs and all services validate them:

**Auth Service** (`northwind-auth-service/`):
- `POST /auth/login` → `{ "token": "eyJ...", "expiresIn": 3600 }`
- `POST /auth/refresh` → new token
- Issues RS256 JWTs with claims:
  ```json
  {
    "sub": "user-uuid",
    "roles": ["ROLE_DISPATCHER", "ROLE_CUSTOMER_SERVICE"],
    "tenantId": "northwind",
    "exp": 1234567890
  }
  ```

**All Java services** validate tokens via Spring Security:
```java
@Configuration
@EnableMethodSecurity
public class SecurityConfig {
    // Validates JWT signature using public key from Auth Service JWKS endpoint
    // No per-service user database — stateless validation only
}
```

**Role mapping** (what replaces the PHP `$_SESSION['user']['role']`):

| PHP role | JWT claim | Permitted endpoints |
|---|---|---|
| `admin` | `ROLE_ADMIN` | All |
| `dispatcher` | `ROLE_DISPATCHER` | `/api/orders/**`, `/api/freight/**` |
| `customer_service` | `ROLE_CUSTOMER_SERVICE` | `/api/tracking/**`, `/api/orders` (read-only) |
| `warehouse` | `ROLE_WAREHOUSE` | `/api/orders/*/picklist` only |

**Done criteria:**
- Unauthenticated request to any service → 401 from Gateway (not from service)
- Dispatcher cannot access `/api/customers` (forbidden → 403)
- Token expiry → 401, refresh endpoint issues new token
- PHP monolith session cookies do NOT work on Java services (clean break)

---

### 11.5 Observability Standards (Required for All Services)

Every Java service must implement the following before it can be considered production-ready:

#### Structured Logging
```java
// Every log line must include:
log.info("Rate calculated",
    kv("traceId", MDC.get("traceId")),
    kv("orderId", request.getOrderId()),
    kv("carrier", request.getCarrier()),
    kv("totalRateUsd", response.getTotalRate())
);
```
Log format: JSON. Field `service` always present. No `System.out.println`.

#### Distributed Tracing
- Micrometer Tracing + Zipkin: `spring-boot-starter-actuator` + `micrometer-tracing-bridge-otel`
- `X-B3-TraceId` propagated across all service calls
- Gateway injects `X-Correlation-ID` = traceId on entry
- Trace visible at `http://zipkin:9411`

#### Metrics (Prometheus)
Every service exposes `/actuator/prometheus`. Required custom metrics:

| Service | Metric name | Type |
|---|---|---|
| Freight | `freight_rate_calculations_total` | Counter |
| Freight | `freight_calculation_duration_seconds` | Histogram |
| Tracking | `tracking_polls_total{carrier,status}` | Counter |
| Tracking | `outbox_pending_events` | Gauge |
| Order | `orders_created_total` | Counter |
| Order | `order_status_transitions_total{from,to}` | Counter |

#### Health Checks
```yaml
management:
  endpoint:
    health:
      show-details: always
      probes:
        enabled: true   # /actuator/health/liveness + /actuator/health/readiness for K8s
```

**Done when** Grafana dashboard at `http://grafana:3000` shows:
- Request rate per service
- Error rate per service
- P50/P95/P99 latency per endpoint
- Outbox pending events (tracking service)
- Kafka consumer lag

---

### 11.6 Inter-Service Communication Standards

#### Synchronous (REST)
- Use Spring Cloud OpenFeign for service-to-service HTTP calls (not raw `RestTemplate`)
- Always set timeouts: connect 2s, read 5s
- Wrap with Resilience4j Circuit Breaker:
  ```java
  @FeignClient(name = "freight-service",
               configuration = FreightClientConfig.class)
  public interface FreightServiceClient {
      @PostMapping("/api/freight/rate")
      @CircuitBreaker(name = "freight-service", fallbackMethod = "fallbackRate")
      FreightRateResponse getRate(FreightRateRequest request);
  }
  ```
- Circuit breaker states: CLOSED (normal) → OPEN (failing) → HALF_OPEN (testing recovery)
- Fallback: return cached last-known rate or reject with 503

#### Asynchronous (Kafka Events)
Standard event envelope for all topics:

```json
{
  "eventId": "uuid-v4",
  "eventType": "ORDER_STATUS_CHANGED",
  "aggregateId": "NW-2026-000042",
  "aggregateType": "ORDER",
  "occurredAt": "2026-03-22T10:15:30Z",
  "traceId": "abc123def456",
  "version": 1,
  "payload": { }
}
```

Rules:
- `eventId` is idempotency key — consumers must deduplicate on it
- `traceId` links event to the HTTP request that caused it
- `version` increments when payload schema changes (consumers must handle version 1 AND 2 during transition)
- Dead letter topic: `northwind.dlq` — malformed or repeatedly-failed events land here
- **No direct DB reads between services** — if Service A needs data owned by Service B, it either calls B's API or consumes B's events

---

### 11.7 API Design Standards

All REST endpoints across all Java services must follow these conventions:

**URL patterns:**
```
GET    /api/{resource}            ← list (paginated)
GET    /api/{resource}/{id}       ← single resource
POST   /api/{resource}            ← create
PUT    /api/{resource}/{id}       ← full replace
PATCH  /api/{resource}/{id}       ← partial update
DELETE /api/{resource}/{id}       ← soft delete
```

**Pagination (list endpoints):**
```json
{
  "data": [...],
  "page": 0,
  "size": 20,
  "totalElements": 145,
  "totalPages": 8
}
```

**Error response standard** (all services, all error codes):
```json
{
  "error": "VALIDATION_FAILED",
  "message": "Weight must be greater than 0",
  "field": "weightLbs",
  "traceId": "abc123",
  "timestamp": "2026-03-22T10:15:30Z",
  "path": "/api/freight/rate"
}
```

**Versioning:** All APIs are `v1` by default. When a breaking change is needed:
- New path: `/api/v2/freight/rate`
- Old path: `/api/v1/freight/rate` kept for 90 days (deprecation header added)
- Never break existing consumers without a migration window

**OpenAPI / Swagger:**
- Every service exposes `GET /v3/api-docs` (SpringDoc)
- Swagger UI: `GET /swagger-ui.html`
- API Gateway aggregates all service docs at `http://gateway:8080/swagger-ui.html`

---

### 11.8 Docker Compose — Full Stack

**Done when** a root-level `docker-compose.yml` boots the entire platform:

```yaml
# /northwind-services/docker-compose.yml
services:
  # Infrastructure
  registry:       # Eureka — port 8761
  config:         # Config Server — port 8888
  gateway:        # API Gateway — port 80 (public entry point)
  kafka:          # Apache Kafka — port 9092
  zookeeper:      # Zookeeper — port 2181
  redis:          # Rate limiting cache — port 6379
  zipkin:         # Distributed tracing — port 9411
  prometheus:     # Metrics scraper — port 9090
  grafana:        # Dashboards — port 3000

  # Databases (one per service)
  order-db:       # Postgres — port 5432
  freight-db:     # Postgres — port 5433
  tracking-db:    # Postgres — port 5434
  customer-db:    # Postgres — port 5435
  monolith-db:    # MySQL 5.7 (legacy) — port 3307

  # Services
  monolith:       # PHP 5.6 — internal only (gateway proxies to it)
  auth-service:   # port 8090
  order-service:  # port 8091
  freight-service: # port 8092
  tracking-service: # port 8093
  customer-service: # port 8094
```

Start order: infrastructure → databases → auth → services → gateway.
All services depend on `registry` being healthy before starting.

**Done when:**
- `docker compose up` from repo root starts the full stack
- `http://localhost/api/freight/rate` routes through gateway → freight-service → returns rate
- `http://localhost:8761` shows all services registered in Eureka
- `http://localhost:9411` shows traces
- `http://localhost:3000` shows Grafana dashboards

---

## Invariants (Never Break These)

1. Monolith characterization tests must be **green before AND after every commit**
2. The new service's API contract is **never shaped by the legacy data model**
3. The ACL is always **in the monolith**, never in the new service
4. The Nginx router is **the only place** that knows both services exist simultaneously
5. No shared database between monolith and new service — ever
6. `hackfix_recalculate_totals()` in `OrderManager.php` — do not remove without reading it first
7. `TESTCODE` discount (100% off) — do not remove; document it instead

---

## Known Bugs (Pin These, Do Not Fix)

| Bug | Location | Notes |
|---|---|---|
| Stale freight cache → wrong invoice amount | `FreightCalc::saveRateToCache()` | No TTL, no invalidation on item change |
| Duplicate invoice numbers under concurrency | `helpers.php::generateInvoiceNumber()` | MAX()+1 race condition, occurred 2012-11-23 |
| Inactive customers appear in search | `CustomerDB::searchCustomers()` | AND precedence bug in WHERE clause |
| Wrong zones for Florida + West Coast | `FreightCalc::calculateZoneRate()` | Systematic undercharge, known since 2011 |
| `$_GET['page']` router/pagination conflict | `index.php` + `order_list.php` | Workaround in place using `$_GET['opage']` |

---

## Quick Reference

| What | Where |
|---|---|
| God class | `modules/orders/OrderManager.php` |
| First extraction target | `modules/freight/FreightCalc.php` |
| Circular dep root | `modules/orders/OrderManager.php` line ~30 |
| SQL injection (login) | `modules/auth/login.php` |
| SQL injection (search) | `modules/customers/CustomerDB.php::searchCustomers()` |
| `eval()` | `lib/helpers.php::renderTemplate()` |
| `extract($_POST)` | `index.php` new order handler |
| Plaintext API keys | `modules/freight/carrier_rates.php` |
| 100% discount code | `OrderManager.php::$discountCodes` — key `TESTCODE` |
