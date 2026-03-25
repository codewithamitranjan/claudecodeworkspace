# Northwind Logistics — PHP 5 Monolith Modernization

![PHP 5.6](https://img.shields.io/badge/PHP-5.6-777BB4?style=flat-square&logo=php&logoColor=white)
![Java 21](https://img.shields.io/badge/Java-21-ED8B00?style=flat-square&logo=openjdk&logoColor=white)
![Spring Boot 3.3](https://img.shields.io/badge/Spring_Boot-3.3-6DB33F?style=flat-square&logo=springboot&logoColor=white)
![Apache Kafka](https://img.shields.io/badge/Apache_Kafka-231F20?style=flat-square&logo=apachekafka&logoColor=white)
![PostgreSQL](https://img.shields.io/badge/PostgreSQL-15-4169E1?style=flat-square&logo=postgresql&logoColor=white)
![Docker](https://img.shields.io/badge/Docker-2496ED?style=flat-square&logo=docker&logoColor=white)
![GitHub Actions](https://img.shields.io/badge/GitHub_Actions-2088FF?style=flat-square&logo=githubactions&logoColor=white)

---

## Overview

Northwind Logistics is a fictional freight and shipping company whose operations are managed by a PHP 5 monolith written in the style of real enterprise PHP circa 2008–2013. The application handles order management, freight rating, shipment tracking, customer management, and invoicing — all tightly coupled inside a single codebase with a God class, circular dependencies, SQL injection vectors, hardcoded credentials, and flat-file caches that never expire. The ugliness is intentional and representative of what modernization engineers actually encounter.

This repository demonstrates how to migrate that monolith to a Spring Boot microservices architecture using the **Strangler Fig pattern** — extracting one bounded context at a time, routing traffic selectively via an Nginx proxy, and translating data shapes through an Anti-Corruption Layer (ACL) that lives in the monolith. There is no big-bang rewrite. At every stage the monolith boots, its characterization tests are green, and production traffic can roll back to the legacy path in under a minute by flipping a feature flag.

The target audience is hackathon participants and engineers learning modernization patterns. Each of the ten challenges in this project maps to a real-world modernization role: PM writing stories, architect mapping seams, tester pinning behavior, developer extracting a service, infra engineer building independent pipelines, and ops writing a 3am-safe cutover runbook. Every challenge has a concrete "done" definition in [CLAUDE.md](CLAUDE.md).

---

## Repository Structure

```
northwind-monolith/          <- PHP 5 legacy monolith (this repo)
northwind-freight-service/   <- Extracted Freight Rating Service (Spring Boot)
northwind-tracking-service/  <- Extracted Tracking Service (Spring Boot + Kafka)
```

**`northwind-monolith/`** — The legacy application. PHP 5.6, MySQL 5.7, Apache. Contains the God class (`OrderManager.php`, 1 100+ lines), circular dependency triangle, and all five intentionally preserved known bugs. Also contains the characterization test suite and the Anti-Corruption Layer (`modules/acl/`) that bridges the monolith to the new services.

**`northwind-freight-service/`** — The first extracted microservice. Stateless Spring Boot 3.3 service that calculates freight rates. Exposes `POST /api/freight/rate` and `GET /api/freight/carriers`. Has its own PostgreSQL database, Flyway migrations, Spring Cloud Contract provider tests, and an independent GitHub Actions pipeline. Produces identical rate calculations to the legacy `FreightCalc.php`, including its known zone bugs (pinned by characterization tests).

**`northwind-tracking-service/`** — The second extracted microservice. Spring Boot 3.3 service that polls carrier APIs for shipment status and publishes events to Apache Kafka via the Outbox pattern. Consumes `orders.status` events from Kafka. During the migration window, a `DualWriteAdapter` keeps the legacy MySQL `tracking_events` table in sync so the monolith can still read from it. Has its own PostgreSQL database and Kafka topic configuration.

---

## Architecture Diagram

The Strangler Fig proxy sits in front of all traffic. New routes are progressively claimed by Java services; everything else passes through to the PHP monolith unchanged.

```
                          +------------------------------------------+
  Client (HTTP/HTTPS)     |         Nginx  (Strangler Fig Proxy)      |
  ----------------------> |                                          |
                          |  /api/freight/*  -->  Freight Service    |
                          |                       (Java :8080)       |
                          |                                          |
                          |  /api/tracking/* -->  Tracking Service   |
                          |                       (Java :8081)       |
                          |                                          |
                          |  everything else -->  PHP Monolith       |
                          |                       (Apache :80)       |
                          +------------------------------------------+
                                                        |
                                                        | ACL
                                                        | (FreightServiceAdapter.php
                                                        |  translates field names,
                                                        |  toggled by feature flag)
                                                        |
                                                   +---------+
                                                   |  Kafka  |  <-- Tracking Service
                                                   | (events)|      publishes events
                                                   +---------+      via Outbox pattern
```

The ACL lives in the monolith, not in the Java services. Java services expose clean domain APIs (e.g., `weightLbs`, `FEDEX`) and never see legacy field names (`wt_lbs`, `FDX`). The Nginx proxy is the only component that knows both old and new services exist simultaneously.

---

## Tech Stack

| Layer | Technology |
|---|---|
| PHP Monolith | PHP 5.6, Apache 2.4, MySQL 5.7 |
| API Gateway | Nginx (Strangler Fig proxy); Spring Cloud Gateway (target) |
| Freight Rating Service | Java 21, Spring Boot 3.3, Spring Data JPA, Flyway, PostgreSQL 15 |
| Tracking Service | Java 21, Spring Boot 3.3, Apache Kafka (Confluent Platform 7.6), PostgreSQL 15 |
| Message Broker | Apache Kafka with Zookeeper; Outbox pattern for reliable event publishing |
| Databases | MySQL 5.7 (monolith, legacy); PostgreSQL 15 per extracted service (no shared schema) |
| CI/CD | GitHub Actions — independent pipeline per service; Docker image push to GHCR on merge to main |
| Observability | Spring Boot Actuator (`/actuator/health`, `/actuator/metrics`), Micrometer, Prometheus, Zipkin (target) |

---

## Quick Start

### Prerequisites

- Docker 24+ and Docker Compose v2
- Java 21 (Temurin recommended)
- Maven 3.9+

### Boot the PHP monolith (legacy)

```bash
git clone <repo-url>
cd northwind-monolith

docker compose up --build
# Monolith: http://localhost:8080
# Login:    admin / admin123
# MySQL:    localhost:3307  (root / root)
```

### Boot the Freight Rating Service

```bash
cd ../northwind-freight-service

mvn package -DskipTests
docker compose up --build
# API:      http://localhost:8080/api/freight/rate
# Carriers: http://localhost:8080/api/freight/carriers
# Health:   http://localhost:8080/actuator/health
# Docs:     http://localhost:8080/swagger-ui.html
```

### Boot the Tracking Service

```bash
cd ../northwind-tracking-service

mvn package -DskipTests
docker compose up --build
# API:      http://localhost:8081/api/tracking/{trackingNumber}
# Poll:     http://localhost:8081/api/tracking/poll
# Kafka UI: http://localhost:8090
```

### Test the Freight Rating endpoint

```bash
curl -X POST http://localhost:8080/api/freight/rate \
  -H "Content-Type: application/json" \
  -d '{"weightLbs": 100, "originZip": "60601", "destZip": "10001", "carrier": "FEDEX"}'
```

Expected response shape:

```json
{
  "carrier": "FEDEX",
  "originZip": "60601",
  "destZip": "10001",
  "weightLbs": 100.0,
  "zone": 3,
  "baseRate": 42.50,
  "fuelSurcharge": 3.61,
  "totalRate": 46.11,
  "estimatedDays": 3,
  "calculatedAt": "2026-03-22T10:15:30Z"
}
```

### Run characterization tests against the monolith

```bash
docker compose exec app php tests/characterization/FreightCalcCharacterizationTest.php
# Expected: Results: 43/43 passed
```

---

## The 10 Challenges

Each challenge maps to a real modernization role and produces a concrete, reviewable artifact.

| # | Challenge | Role | Status | Primary Output |
|---|---|---|---|---|
| 1 | The Stories | PM | Done | `docs/stories.md` |
| 2 | The Patient | Architect | Done | `northwind-monolith/` (this repo) |
| 3 | The Map | Architect | Done | `docs/DECOMPOSITION.md` |
| 4 | The Pin | Tester | Done | `tests/characterization/` |
| 5 | The Cut | Developer | Done | `northwind-freight-service/` |
| 6 | The Fence | Developer | Done | `modules/acl/FreightServiceAdapter.php` |
| 7 | The Contract | Tester | Done | `src/test/resources/contracts/` (Spring Cloud Contract) |
| 8 | The Pipeline | Infra | Done | `.github/workflows/` |
| 9 | The Second Cut | Developer | Done | `northwind-tracking-service/` |
| 10 | The Weekend | Ops | Done | `docs/CUTOVER_RUNBOOK.md` |

---

## Key Design Decisions

### Strangler Fig via Nginx

Nginx is the only component aware that two generations of the system run simultaneously. It routes `/api/freight/*` to the Java Freight Service and `/api/tracking/*` to the Java Tracking Service; every other path reaches the PHP monolith unchanged. Legacy browser sessions, cookie-based auth, and all non-extracted modules continue to work without modification. The ACL in the monolith controls per-request routing via feature flags, enabling gradual rollout and instant rollback with no infrastructure change.

### Anti-Corruption Layer (ACL)

The ACL lives in `modules/acl/FreightServiceAdapter.php` inside the monolith. It translates legacy field names to the Java service's clean domain language on the way out (`wt_lbs` → `weightLbs`, `FDX` → `FEDEX`, status integers → enum strings) and translates the response back to the shape `OrderManager` already expects on the way in. The Java service API is designed in clean domain terms and will never change to accommodate legacy names. This means the Java service is immediately portable — it has no dependency on the PHP era's data model.

The feature flag `USE_NEW_FREIGHT_SERVICE` (environment variable, default `false`) controls which path the ACL takes:

- `false` — ACL delegates directly to `FreightCalc::calculateRate()` (legacy path, zero network calls)
- `true` — ACL calls the Spring Boot service over HTTP and translates the response

Both paths are covered by the characterization test suite, so the 43-test suite must pass regardless of flag state.

### Outbox Pattern (dual-write safety in the Tracking Service)

The PHP monolith uses MySQL autocommit, writing to the database and to flat-file caches without atomicity guarantees. The Tracking Service solves the equivalent problem in the new architecture: it must write a tracking event to its PostgreSQL database AND publish a `shipment.tracked` event to Kafka, but a network failure between the two would cause divergence.

The solution is the Outbox pattern: the service writes the tracking event and an outbox record in a single local database transaction. A separate `OutboxProcessor` polls the outbox table and publishes to Kafka. If Kafka is unavailable, the outbox accumulates records and retries on reconnect. The PostgreSQL row is always the source of truth. The `DualWriteAdapter` additionally mirrors events to the legacy MySQL `tracking_events` table while the monolith still reads from it; setting `DUAL_WRITE_ENABLED=false` disables the MySQL mirror once migration is complete.

### Database per Service

Each Java service has its own PostgreSQL 15 database with its own schema managed by Flyway. The monolith's MySQL database is not accessible to any Java service directly. Data needed by a new service is either provided through the API call that triggers the service (e.g., the freight rate request carries all required fields) or consumed via Kafka events. The MySQL database remains the monolith's sole concern until its modules are decommissioned.

---

## Feature Flags

```bash
# Route freight rating requests through the new Spring Boot service.
# Set in docker-compose.yml or as a Kubernetes env var.
USE_NEW_FREIGHT_SERVICE=true

# Mirror tracking events to the legacy MySQL table during migration.
# Set to false once all consumers have migrated to the new Postgres API or Kafka.
DUAL_WRITE_ENABLED=true
```

Both flags default to safe values (`false` and `true` respectively) so that a misconfigured deployment falls back to known-good behavior rather than failing silently.

---

## Running Tests

### Monolith characterization tests

```bash
# Against the running Docker container (recommended)
docker compose exec app php tests/characterization/FreightCalcCharacterizationTest.php

# Directly via PHP CLI (requires MySQL accessible at DB_HOST)
DB_HOST=127.0.0.1 DB_USER=root DB_PASS=root DB_NAME=northwind \
  php tests/characterization/FreightCalcCharacterizationTest.php

# Expected output
Results: 43/43 passed
```

### Freight Service — unit and integration tests

```bash
cd northwind-freight-service
mvn test
# Runs FreightRatingServiceTest (unit) + FreightControllerIntegrationTest (@SpringBootTest)
```

### Freight Service — Spring Cloud Contract tests

```bash
cd northwind-freight-service
mvn verify
# Runs ContractBaseTest which verifies the service satisfies all three Groovy DSL contracts:
#   shouldReturnRateForValidRequest.groovy
#   shouldListCarriers.groovy
#   shouldReturn400ForInvalidCarrier.groovy
```

### Tracking Service — unit and Kafka consumer tests

```bash
cd northwind-tracking-service
mvn test
# Runs TrackingServiceTest (unit) + OrderStatusConsumerTest (Kafka consumer)
```

### Full CI simulation (mirrors GitHub Actions)

```bash
# Monolith pipeline
DB_HOST=127.0.0.1 DB_USER=root DB_PASS=root DB_NAME=northwind \
  USE_NEW_FREIGHT_SERVICE=false \
  php northwind-monolith/tests/characterization/FreightCalcCharacterizationTest.php

# Freight service pipeline
SPRING_DATASOURCE_URL=jdbc:postgresql://localhost:5432/freight \
  mvn test -pl northwind-freight-service
```

---

## Known Bugs (Intentionally Preserved)

These bugs exist in the PHP monolith and are pinned by the characterization test suite. **Do not fix them in the monolith.** They are preserved so the extracted Java services can be verified against the monolith's actual behavior, and then corrected in the new service with an explicit decision and test coverage.

| # | Bug | Location | Notes |
|---|---|---|---|
| 1 | Wrong shipping zones for Florida and West Coast ZIPs | `FreightCalc::calculateZoneRate()` | Florida ZIPs (320–349) assigned zone 4; systematic undercharge known since 2011 |
| 2 | Duplicate invoice numbers under concurrent order creation | `helpers.php::generateInvoiceNumber()` | `MAX()+1` race condition; occurred in production on 2012-11-23 |
| 3 | Inactive customers appear in search results | `CustomerDB::searchCustomers()` | Boolean operator precedence bug in `WHERE` clause |
| 4 | Stale freight cache produces wrong invoice amounts | `FreightCalc::saveRateToCache()` | Flat-file cache has no TTL and is never invalidated on item change |
| 5 | `$_GET['page']` router/pagination conflict | `index.php` + `order_list.php` | Workaround in place using `$_GET['opage']`; do not remove the workaround |

The `TESTCODE` discount code (100% off all items) is also preserved. It was used in development and never removed. Do not remove it; document it instead.

---

## Cutover Runbook

The production cutover procedure for the Freight Rating Service is documented step by step in [`docs/CUTOVER_RUNBOOK.md`](docs/CUTOVER_RUNBOOK.md). It covers:

- Pre-cutover checklist (what must be true before flipping the flag)
- Step-by-step cutover with exact commands and expected outputs
- Verification steps after each stage
- Rollback triggers: error rate thresholds, P95 latency limits, invoice total mismatches
- The 3am decision tree for on-call engineers (ASCII flowchart)
- Exact rollback commands in order
- Post-cutover decommission schedule (not before 30 days of clean run)

---

## CI/CD Pipelines

Two independent pipelines exist in `.github/workflows/`. They trigger on path-scoped push events so a failure in one never blocks a deployment of the other.

| Workflow | Trigger path | Jobs |
|---|---|---|
| `monolith-ci.yml` | `northwind-monolith/**` | PHP 5.6 lint, schema load, 43 characterization tests |
| `freight-service-ci.yml` | `northwind-freight-service/**` | Maven test (unit + contract + integration), Docker build and push to GHCR on merge to main |

Pipeline independence is an architectural invariant: a broken monolith deployment must not gate a freight service hotfix, and vice versa.

---

## Contributing (Hackathon Context)

- **Do not clean up monolith code that is not part of your current challenge.** The ugliness is intentional and documents what was actually there. Accidental cleanup breaks characterization tests and obscures the learning value.
- **Characterization tests must pass before AND after every change.** Run them before you start and again before you commit. A red test suite means something observable changed — investigate before proceeding.
- **New services must not know the legacy data model exists.** The ACL is in the monolith. If you find yourself adding a legacy field name to a Java DTO, the translation belongs in `FreightServiceAdapter.php`, not in the service.
- **No shared database.** Java services read from their own PostgreSQL instance. They do not query MySQL. They do not share a schema with each other.
- **The `hackfix_recalculate_totals()` function in `OrderManager.php` must not be removed** without reading it first. It corrects a June 2011 billing calculation that is still wrong in the base data.
- See [CLAUDE.md](CLAUDE.md) for the full "done" definition for each of the ten challenges.
