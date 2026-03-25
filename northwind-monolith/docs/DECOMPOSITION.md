# Northwind Logistics — Monolith Decomposition Plan

> Migration path: PHP 5 monolith → Spring Boot microservices via the Strangler Fig pattern.
> Every decision below is grounded in the actual source code under `northwind-monolith/`.

---

## 1. Seam Inventory

| Service Name | Extracted From (PHP file) | Inputs | Outputs | Shared DB Tables | Circular Dep Risk | Extraction Order |
|---|---|---|---|---|---|---|
| **Freight Rating Service** | `modules/freight/FreightCalc.php` | `order_id`, `carrier_id`, origin ZIP (hardcoded `19103`), destination ZIP from order | Rate in USD, zone (2–8), fuel surcharge amount, estimated transit days | `orders` (read-only: `ship_to_zip`), `order_items` (read-only: `quantity`, `product.weight_lbs`), `products` (read-only: `weight_lbs`), `freight_rates` (override table) | **Low** | **1** |
| **Customer Service** | `modules/customers/CustomerDB.php` | Customer ID, company name, contact details, ZIP, credit limit, payment terms | Customer record, customer order list, outstanding balance, credit-limit flag | `customers`, `orders` (read-only count/sum), `invoices` (read-only sum for balance) | **Low** | **2** |
| **Tracking Service** | `modules/tracking/TrackingService.php` | Tracking number, carrier code (`ups`/`fedex`/`usps`/`dhl`), order ID | Tracking events array, last event, estimated delivery, status string | `orders` (read/write: `tracking_number`, `status`), `tracking_events` (write), `carriers` (read) | **Medium** | **3** |
| **Invoice Service** | `modules/invoicing/InvoiceGen.php` | `order_id`, carrier ID, order items, freight amount, discount amount, payment terms | Invoice record, invoice HTML/PDF, paid/voided status | `invoices`, `orders` (read/write: `freight_amount`, `total_amount`), `order_items` (read), `customers` (read), `carriers` (read) | **Medium** | **4** |
| **Order Service** | `modules/orders/OrderManager.php` | Customer ID, ship-to address, line items (product ID + quantity), carrier ID, discount code, PO number | Order record, order number (`NW-YYYY-NNNNNN`), status transitions, pick-list, confirmation email | `orders`, `order_items`, `customers` (read), `products` (read), `carriers` (read), `invoices` (read via `InvoiceGen`), `freight_rates` (read via `FreightCalc`) | **High** | **5** |

### Circular Dependency Triangle (as found in source)

```
OrderManager.php  ──requires──▶  InvoiceGen.php
       ▲                               │
       │                           requires
       └────────── FreightCalc.php ◀──┘
```

`FreightCalc.php` line 27: `require_once('.../OrderManager.php');`
`InvoiceGen.php` line 22: `require_once('.../FreightCalc.php');`
`OrderManager.php` line 24: `require_once('.../InvoiceGen.php');`

PHP's `require_once` prevents infinite re-inclusion but the load order is fragile; adding
any new cross-require breaks with "Class not found". The extraction order below deliberately
cuts the outside edges of this triangle first, leaving the God class (`OrderManager`) for
last when the triangle no longer exists.

---

## 2. Extraction Order (Ranked 1–5)

### Rank 1 — Freight Rating Service (`FreightCalc.php`)

**Rationale:** `FreightCalc` is the only class in the dependency triangle whose primary
operation is a pure function: given a weight, two ZIP codes, and a carrier, it returns a
dollar amount. It carries no mutable state between requests (the flat-file cache is an
implementation detail, not a domain invariant). It has no _inbound_ circular dependencies
— nothing calls `FreightCalc` directly except `InvoiceGen` and `OrderManager`, and both of
those can be retargeted to call the new HTTP endpoint via the Anti-Corruption Layer (ACL).

Extracting Freight Rating first also delivers immediate business value: every invoice in the
system depends on an accurate freight charge, and the stale flat-file cache bug (no TTL,
known since 2013) is fixed as a side-effect of the extraction.

The new service is stateless: it reads rate tables from its own Postgres DB (seeded from the
hardcoded `$baseRates` array and the `freight_rates` MySQL table) and accepts clean inputs.
No Kafka consumer is required at this stage — the monolith calls it synchronously via HTTP.

### Rank 2 — Customer Service (`CustomerDB.php`)

**Rationale:** `CustomerDB.php` has no inbound circular dependencies. It reads/writes only
the `customers` table and issues read-only aggregate queries against `orders` and `invoices`.
Once extracted, the ACL in the monolith replaces direct MySQL queries with HTTP calls to the
new service. This unblocks the Order Service extraction (rank 5) by removing one of
`OrderManager`'s data-access responsibilities. Low-risk because the customer domain has a
narrow, well-defined interface: CRUD + search + credit-limit check.

### Rank 3 — Tracking Service (`TrackingService.php`)

**Rationale:** `TrackingService` has a clear external boundary (carrier APIs) that is already
logically separate from the rest of the monolith. It is ranked Medium risk for two reasons:
(1) it writes back to the `orders` table when it auto-detects a `delivered` status, creating
a coupling to Order state that must be replaced with a Kafka event (`shipment.tracked` →
Order Service consumes and transitions status); (2) the flat-file tracking cache and the
background cron poll (`pollAllActiveShipments()`) must both be re-implemented. Neither of
these is architecturally novel, but both require careful dual-write handling during
transition to avoid losing tracking events.

### Rank 4 — Invoice Service (`InvoiceGen.php`)

**Rationale:** `InvoiceGen` sits at the bottom of the circular dependency triangle. By rank 4,
the Freight Rating Service (rank 1) and Order Service (rank 5, not yet extracted) are the
only remaining callers. The Invoice Service is ranked Medium risk because it crosses two
domain boundaries in a single operation (`generateInvoice` calls both `OrderManager` and
`FreightCalc`). After rank 1 and 2 are extracted, those calls become clean HTTP or Kafka
calls. The concurrent invoice number race condition (duplicate `MAX(id)+1` under load,
incident: Black Friday 2012) is resolved at this stage by moving to a Postgres sequence.

### Rank 5 — Order Service (`OrderManager.php`)

**Rationale:** `OrderManager` is a God Class of 1,100+ lines that owns: order CRUD, an
8-state FSM (`new → processing → shipped → delivered → cancelled → on_hold → disputed →
archived`), order items, discount validation (including the `TESTCODE` 100%-off code),
pick-list HTML generation, confirmation email dispatch, freight recalculation
(`hackfix_recalculate_totals()`), and archival cron logic. It is the root of the circular
dependency triangle and the source of every other service's data.

It goes last because:
1. Every other extraction reduces its surface area first.
2. The 8-state FSM requires a complete Saga design (choreography pattern) before the service
   can stand alone.
3. `hackfix_recalculate_totals()` must be analysed and its June 2011 billing correction
   logic ported faithfully before the monolith's `orders` table can be retired.
4. The `TESTCODE` discount must be explicitly documented and either ported or removed before
   extraction — it cannot be quietly dropped.

---

## 3. Strangler Fig Strategy

### Overview

The Strangler Fig pattern lets the PHP monolith continue to run unmodified for all routes
except those explicitly handed off to an extracted service. Nginx is the proxy router.
A feature flag in the monolith controls whether each extracted path is live or dark.

### Nginx as Proxy Router

```nginx
# /etc/nginx/conf.d/northwind.conf

upstream monolith {
    server php-app:80;
}

upstream freight_service {
    server freight-service:8080;
}

upstream tracking_service {
    server tracking-service:8080;
}

upstream customer_service {
    server customer-service:8080;
}

server {
    listen 443 ssl;
    server_name northwind.internal;

    # --- Extracted services ---

    # Freight Rating Service (extracted first — rank 1)
    location /api/freight/ {
        proxy_pass         http://freight_service;
        proxy_set_header   Host              $host;
        proxy_set_header   X-Real-IP         $remote_addr;
        proxy_set_header   X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header   X-NW-Trace-ID     $request_id;
    }

    # Customer Service (rank 2)
    location /api/customers/ {
        proxy_pass         http://customer_service;
        proxy_set_header   Host              $host;
        proxy_set_header   X-Real-IP         $remote_addr;
    }

    # Tracking Service (rank 3)
    location /api/tracking/ {
        proxy_pass         http://tracking_service;
        proxy_set_header   Host              $host;
        proxy_set_header   X-Real-IP         $remote_addr;
    }

    # --- Everything else stays on the monolith ---
    location / {
        proxy_pass         http://monolith;
        proxy_set_header   Host              $host;
        proxy_set_header   X-Real-IP         $remote_addr;
        proxy_set_header   X-Forwarded-For   $proxy_add_x_forwarded_for;
    }
}
```

**Key rules:**
- `/api/freight/*` routes to the Java Freight Rating Service. The monolith never sees these
  requests once the flag is flipped.
- All other paths — including `/?page=freight_estimate` (the legacy URL) — continue to
  route to the monolith unchanged during transition.
- The Nginx config is the _only_ place in the infrastructure that knows two services exist
  simultaneously. Neither service knows about the other.

### Feature Flag Approach

Feature flags live in `config.php` as environment-variable-backed constants:

```php
// config.php — added during extraction, never in legacy code

// Freight Rating Service flag (Phase 1)
define('USE_NEW_FREIGHT_SERVICE',
    getenv('USE_NEW_FREIGHT_SERVICE') === 'true'
);

// Customer Service flag (Phase 2)
define('USE_NEW_CUSTOMER_SERVICE',
    getenv('USE_NEW_CUSTOMER_SERVICE') === 'true'
);

// Tracking Service flag (Phase 3)
define('USE_NEW_TRACKING_SERVICE',
    getenv('USE_NEW_TRACKING_SERVICE') === 'true'
);
```

The ACL adapter checks the flag and dispatches accordingly:

```php
// modules/acl/FreightServiceAdapter.php
class FreightServiceAdapter {
    public function getRate($orderId, $carrierId) {
        if (USE_NEW_FREIGHT_SERVICE) {
            return $this->callNewService($orderId, $carrierId);  // HTTP to Java
        }
        $fc = new FreightCalc();
        return $fc->calculateRate($orderId, $carrierId);         // legacy path
    }
}
```

Flag rollout sequence per service:
1. `false` (default) — all traffic goes to monolith. New service is deployed but receives
   no traffic.
2. Shadow mode — new service receives a copy of each request but its response is discarded.
   Responses are compared in the ACL and mismatches are logged to `audit_log`.
3. `true` for internal users only (by role check in ACL).
4. `true` for 10% of requests (canary, using consistent hashing on `order_id`).
5. `true` for 100% of requests.
6. ACL retained for 30-day rollback window, then removed.

---

## 4. Database Migration Strategy Per Service

### Principles

1. Every extracted service gets its own dedicated Postgres 15 instance. No service ever
   shares a database with another service or with the monolith's MySQL instance.
2. Data is seeded from a mysqldump of the relevant MySQL tables during the extraction sprint.
3. During the transition window, the monolith writes to MySQL and the ACL propagates writes
   to the new Postgres DB (dual-write). The new service's Postgres DB is the read source for
   new traffic; MySQL remains the read source for monolith traffic.
4. Dual-write stops when the monolith module is decommissioned (the corresponding PHP file
   is removed).
5. All schema changes in the new service use Flyway versioned migrations. No manual DDL.

### Per-Service Database Plan

**Freight Rating Service — `freight_db` (Postgres)**

Source tables from MySQL: `freight_rates`, relevant columns from `products` (weight),
relevant columns from `orders` (destination ZIP, carrier ID).

The hardcoded `$baseRates` array in `FreightCalc.php` and the `getCarrierRates()` method
are converted to seed data in a Flyway `V1__seed_carrier_rates.sql` migration. The `freight_rates`
table (currently almost unused in MySQL) becomes the authoritative rate store in Postgres.

During transition: `freight_db` is read-only from the monolith's perspective. Order weight
is looked up by the new service via a read-only query against the shared MySQL `order_items`
table _until_ the Order Service is extracted (rank 5), at which point the Freight Service
consumes an `orders.created` Kafka event carrying the pre-computed total weight.

**Customer Service — `customer_db` (Postgres)**

Source tables from MySQL: `customers`.

The `active` flag, `credit_limit`, and `payment_terms` columns require no data transformation.
The `legacy_id` column is preserved in Postgres during the transition period and dropped
after all legacy import scripts are retired.

Dual-write: the ACL adapter's `createCustomer()` and `updateCustomer()` calls write to both
MySQL (for the monolith) and to the new Postgres DB (via REST POST/PUT) within the same
request. A nightly reconciliation job compares row counts and `updated_at` timestamps for
the first 30 days and alerts on divergence exceeding 0 rows.

**Tracking Service — `tracking_db` (Postgres)**

Source tables from MySQL: `tracking_events`, `orders.tracking_number` (read), `carriers`.

The flat-file tracking cache (`/var/cache/nw/track_*.cache`) is replaced by a Postgres
table with a TTL column and a scheduled cleanup job. The `pollAllActiveShipments()` cron
is replaced by a Spring `@Scheduled` background task that uses proper `SELECT ... FOR UPDATE
SKIP LOCKED` to prevent duplicate polling — fixing the race condition documented in the 2012
source code comments.

Dual-write: when the monolith's `TrackingService::updateOrderTracking()` writes a tracking
number, the ACL also POSTs the tracking number to the new service's
`PUT /api/tracking/{trackingNumber}` endpoint.

**Invoice Service — `invoice_db` (Postgres)**

Source tables from MySQL: `invoices`.

The `invoice_number` uniqueness constraint is enforced via a Postgres `UNIQUE` index.
The concurrent race condition (duplicate `MAX(id)+1` under load) is eliminated by replacing
the sequence logic with a Postgres `SEQUENCE` object in the Flyway migration.

The `void_reason` column (added for audit but never displayed) is retained and surfaced in
the Invoice Service API as a first-class field.

**Order Service — `order_db` (Postgres)**

Source tables from MySQL: `orders`, `order_items`.

This is the most complex migration. The 8-value status `ENUM` maps to a Java `enum
OrderStatus` with explicit allowed transitions enforced in the domain layer (not the DB).
The `hackfix_recalculate_totals()` logic is ported as a named domain service
`OrderTotalRecalculationService` with a comment referencing the June 2011 billing incident
and a characterisation test that proves the ported logic produces identical output to the PHP
original.

The `TESTCODE` discount (100% off, `expires = 2099-12-31`) is ported as a named test-only
discount code, gated behind a `DISCOUNT_TESTCODE_ENABLED` environment variable that is
`false` in production and `true` in test environments.

---

## 5. Risk Register

| # | Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|---|
| 1 | **Stale freight cache served to new service.** The flat-file cache in `FreightCalc.php` has no TTL and no invalidation when order items change (known since 2013 Q2). If the new Freight Service is seeded with or compared against cached data from the old system, rates will silently diverge. | High | High | Run characterisation tests against the live PHP container (not cached values) before extraction. Seed the new service's `freight_db` from raw `products.weight_lbs` and `freight_rates`, not from any cache file. Invalidate all `freight_*.cache` files on deployment day. Add a nightly rate-reconciliation job for the first 90 days. |
| 2 | **Circular dependency load order breaks during extraction.** `OrderManager` → `InvoiceGen` → `FreightCalc` → `OrderManager`. If the ACL is introduced in the wrong order or `require_once` guards are disturbed, the monolith will throw "Class not found" in production. | Medium | High | Extract services in strict rank order (Freight → Customer → Tracking → Invoice → Order). Never modify a `require_once` chain in the monolith without running the full characterisation test suite. Keep the ACL in a new file (`modules/acl/`) that is included _after_ the existing triangle, not within it. |
| 3 | **Dual-write divergence between MySQL and Postgres.** During the transition window, the ACL writes to both databases. A failed Postgres write (network timeout, schema mismatch) will leave the two databases out of sync without the dispatcher being aware, potentially causing order totals or customer credit limits to differ between old and new UI paths. | Medium | High | Implement the outbox pattern: write to MySQL and an `outbox_events` table in the same MySQL transaction; a separate processor reads the outbox and writes to Postgres. If the Postgres write fails, the outbox entry remains and retries. Nightly reconciliation alert on any count or checksum divergence. |
| 4 | **Zone calculation gives wrong results for Florida and West Coast ZIPs.** `FreightCalc::calculateZoneRate()` uses a ZIP prefix difference heuristic documented as incorrect for Florida (320–349) and cross-country routes since 2011. If the new service faithfully ports this logic, it perpetuates the systematic undercharge. If it fixes the logic, rates diverge from the characterisation tests. | High | Medium | Pin the broken behaviour in characterisation tests _as-is_ (the tests document what the system does, not what it should do). Port the identical broken logic to the new service for the initial extraction so rates match. Open a separate backlog story to replace the heuristic with a real USPS zone lookup table after extraction is complete and a rate-change notice has been sent to customers. |
| 5 | **Carrier API credentials are hardcoded in source and are expired or incorrect.** `TrackingService.php` embeds UPS, FedEx, and USPS credentials directly in source (UPS `access_key`, FedEx key expired June 2013, USPS user ID). Extracting the Tracking Service without rotating these credentials would embed dead secrets into the new codebase, and any working credential would move from source control to a new system without revocation of the old value. | High | Medium | Before the Tracking Service extraction sprint begins: (1) rotate all carrier API credentials with each carrier; (2) store new credentials in HashiCorp Vault or Kubernetes Secrets; (3) inject them into the Spring Boot service via environment variables, never in source. Remove all credential literals from `TrackingService.php` and `carrier_rates.php` in the same commit that introduces the ACL, so the window where both old and new credentials exist in source is zero days. |
