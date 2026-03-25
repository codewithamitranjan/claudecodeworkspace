# Freight Rating Service — Production Cutover Runbook

**Migration:** `FreightCalc.php` (PHP monolith) → Spring Boot Freight Rating Service
**Pattern:** Strangler Fig with ACL feature flag
**Owner:** On-call engineer + freight team lead
**Last updated:** 2026-03-22
**Estimated total duration:** 2–3 hours (excluding 30-min shadow monitoring window)

> This is a live operations document. Every command is exact. Every expected output is literal.
> If something does not match, stop and follow the "If this fails" instruction.

---

## Pre-Cutover Checklist

All items must be checked before Step 1. No exceptions. If any item is not green, **do not proceed**.

- [ ] Freight service health check green:
  ```bash
  curl -s http://freight-service:8080/actuator/health | python3 -m json.tool
  ```
  Expected: `{"status":"UP"}` — full body may include component detail, `status` must be `UP`

- [ ] Characterization tests pass against NEW service (all 43 assertions green):
  ```bash
  USE_NEW_FREIGHT_SERVICE=true docker compose exec app \
    php tests/characterization/FreightCalcCharacterizationTest.php
  ```
  Expected last line: `Results: 43/43 passed`

- [ ] Characterization tests pass against OLD path (all 43 assertions green):
  ```bash
  USE_NEW_FREIGHT_SERVICE=false docker compose exec app \
    php tests/characterization/FreightCalcCharacterizationTest.php
  ```
  Expected last line: `Results: 43/43 passed`

- [ ] Rate comparison test — same inputs produce same outputs from both paths (within $0.01):
  ```bash
  curl -s "http://monolith:8080/?page=freight_estimate&weight=500&origin=60601&dest=10001&carrier=fedex_ground" | grep -o '"rate":[0-9.]*'
  curl -s -X POST http://freight-service:8080/api/freight/rate \
    -H "Content-Type: application/json" \
    -d '{"weightLbs":500,"originZip":"60601","destZip":"10001","carrier":"FEDEX_GROUND"}' \
    | python3 -c "import sys,json; d=json.load(sys.stdin); print(f'rate: {d[\"rate\"]}')"
  ```
  Expected: Both values within $0.01 of each other

- [ ] Last 24h error rate on freight-service < 0.1%:
  Check Grafana: `http://grafana.internal/d/freight-service-overview`
  Panel: "HTTP Error Rate (5xx)" — must show < 0.1% for the past 24 hours

- [ ] DB migration applied and freight_rates table populated:
  ```bash
  docker compose exec db mysql -uroot -proot northwind \
    -e "SELECT count(*) AS rate_count FROM freight_rates;"
  ```
  Expected: `rate_count` > 0 (minimum 8 rows for 8 carriers × zones)

- [ ] Rollback tested in staging within the last 7 days:
  Confirm in #northwind-cutover that staging rollback was exercised after:
  ```bash
  date -d "7 days ago" +%Y-%m-%d
  ```
  Look for a message in #northwind-cutover with "staging rollback verified" dated on or after that date.

- [ ] On-call engineer paged and acknowledged:
  PagerDuty incident must show "Acknowledged" status — not just "Triggered"

- [ ] Incident channel open: #northwind-cutover
  Post: `@here CUTOVER STARTING — Freight Rating Service — runbook: docs/CUTOVER_RUNBOOK.md`

- [ ] Freight service Postgres DB seeded from MySQL freight_rates dump (no stale flat-file cache):
  ```bash
  curl -s http://freight-service:8080/api/freight/carriers | python3 -m json.tool | grep -c '"id"'
  ```
  Expected: `8` (all 8 carriers present: FedEx Ground, FedEx Express Saver, UPS Ground, UPS 2nd Day Air, UPS Next Day Air, USPS Priority Mail, USPS Parcel Select, DHL Express)

- [ ] Zone calculation smoke test — confirm the Florida ZIP bug is preserved identically:
  ```bash
  curl -s -X POST http://freight-service:8080/api/freight/rate \
    -H "Content-Type: application/json" \
    -d '{"weightLbs":10,"originZip":"32001","destZip":"10001","carrier":"FEDEX_GROUND"}' \
    | python3 -c "import sys,json; d=json.load(sys.stdin); print('zone:', d['zone'])"
  ```
  Expected: `zone: 5` (this is the pinned bug — FL→NY gives zone 5, not geographically correct 6-7)

- [ ] Fuel surcharge precision check — confirm rounding matches PHP behavior:
  ```bash
  curl -s -X POST http://freight-service:8080/api/freight/rate \
    -H "Content-Type: application/json" \
    -d '{"weightLbs":1,"originZip":"19103","destZip":"33101","carrier":"FEDEX_GROUND"}' \
    | python3 -c "import sys,json; d=json.load(sys.stdin); print('fuel_surcharge:', d['fuelSurcharge'])"
  ```
  Expected: fuel surcharge matches PHP `FreightCalc::applyFuelSurcharge()` output to within $0.01 (8.5% surcharge, round-half-even per Java BigDecimal vs PHP round() — acceptable delta is $0.01 max)

---

## Step-by-Step Cutover

---

### Step 1 — Snapshot the freight_rates table

**What:** Take a point-in-time MySQL dump of `freight_rates` before any changes — this is your restore point.

**Command:**
```bash
docker compose exec db mysqldump \
  -uroot -proot \
  --single-transaction \
  --no-tablespaces \
  northwind freight_rates \
  > /var/backups/northwind/freight_rates_cutover_$(date +%Y%m%dT%H%M%S).sql

echo "Dump size: $(ls -lh /var/backups/northwind/freight_rates_cutover_*.sql | tail -1 | awk '{print $5}')"
```

**Expected output:**
```
Dump size: [non-zero, e.g. 4.2K]
```
The dump file must exist and be non-empty. Verify:
```bash
ls -lh /var/backups/northwind/freight_rates_cutover_*.sql | tail -1
```
Expected: file size > 0 bytes, timestamp within the last 2 minutes.

**If this fails:** The mysqldump command may fail if the container name differs. Check with `docker compose ps`. Do not proceed without a verified backup file.

**Time estimate:** 1 minute

---

### Step 2 — Enable shadow mode

**What:** Tell the ACL to call the new service on every request but use only the old result — mismatches are logged to `audit_log`, no user impact.

**Command:**
```bash
docker compose exec app env \
  USE_NEW_FREIGHT_SERVICE=false \
  FREIGHT_SHADOW_MODE=true \
  php -r "echo getenv('FREIGHT_SHADOW_MODE') === 'true' ? 'SHADOW MODE: ON' : 'SHADOW MODE: OFF';"
```

Then set the environment variable on the running container:
```bash
docker compose stop app && \
  USE_NEW_FREIGHT_SERVICE=false FREIGHT_SHADOW_MODE=true docker compose up -d app && \
  docker compose exec app php -r "echo defined('FREIGHT_SHADOW_MODE') && FREIGHT_SHADOW_MODE ? 'OK: shadow on' : 'FAIL: shadow off';"
```

**Expected output:**
```
OK: shadow on
```

Verify shadow mode is logging divergence checks:
```bash
docker compose exec db mysql -uroot -proot northwind \
  -e "SELECT COUNT(*) AS shadow_log_entries FROM audit_log WHERE event_type='FREIGHT_SHADOW' AND created_at > NOW() - INTERVAL 1 MINUTE;"
```
Send a test request to generate a log entry:
```bash
curl -s "http://monolith:8080/?page=freight_estimate&weight=100&origin=60601&dest=10001&carrier=fedex_ground" > /dev/null
docker compose exec db mysql -uroot -proot northwind \
  -e "SELECT COUNT(*) FROM audit_log WHERE event_type='FREIGHT_SHADOW' ORDER BY id DESC LIMIT 1;"
```
Expected: count increases by at least 1.

**If this fails:** Shadow mode env var not wired into `config.php` or `FreightServiceAdapter.php`. Check `modules/acl/FreightServiceAdapter.php` — look for `FREIGHT_SHADOW_MODE` constant. If it does not exist, shadow mode was not implemented — escalate to freight team lead before proceeding.

**Time estimate:** 3 minutes

---

### Step 3 — Run shadow comparison for 30 minutes

**What:** Let real traffic flow through shadow mode. Monitor the divergence log for any rate mismatches before flipping live traffic.

**Command — watch divergence log in real time:**
```bash
watch -n 10 'docker compose exec db mysql -uroot -proot northwind \
  -e "SELECT event_type, old_value, new_value, created_at \
      FROM audit_log \
      WHERE event_type='"'"'FREIGHT_SHADOW_MISMATCH'"'"' \
      AND created_at > NOW() - INTERVAL 30 MINUTE \
      ORDER BY id DESC \
      LIMIT 20;"'
```

**Check divergence count after 30 minutes:**
```bash
docker compose exec db mysql -uroot -proot northwind \
  -e "SELECT \
        COUNT(*) AS total_shadow_calls, \
        SUM(CASE WHEN event_type='FREIGHT_SHADOW_MISMATCH' THEN 1 ELSE 0 END) AS mismatches, \
        ROUND(100.0 * SUM(CASE WHEN event_type='FREIGHT_SHADOW_MISMATCH' THEN 1 ELSE 0 END) / COUNT(*), 2) AS mismatch_pct \
      FROM audit_log \
      WHERE event_type IN ('FREIGHT_SHADOW','FREIGHT_SHADOW_MISMATCH') \
      AND created_at > NOW() - INTERVAL 30 MINUTE;"
```

**Expected output:**
```
+--------------------+------------+--------------+
| total_shadow_calls | mismatches | mismatch_pct |
+--------------------+------------+--------------+
| [N > 0]            | 0          |         0.00 |
+--------------------+------------+--------------+
```

Acceptable: `mismatch_pct` < 0 is ideal. Any mismatches > 0 that exceed 5% of calls means **STOP** — see rollback triggers.

**If this fails (mismatch_pct > 5%):** Do NOT flip traffic. Examine the mismatch rows:
```bash
docker compose exec db mysql -uroot -proot northwind \
  -e "SELECT old_value, new_value, context FROM audit_log \
      WHERE event_type='FREIGHT_SHADOW_MISMATCH' \
      ORDER BY id DESC LIMIT 10;"
```
Escalate to freight team lead with the mismatch data. This is a stop condition.

**If this fails (total_shadow_calls = 0):** No real traffic is hitting the freight path. Trigger test traffic:
```bash
for i in $(seq 1 10); do
  curl -s "http://monolith:8080/?page=freight_estimate&weight=$((i*100))&origin=60601&dest=10001&carrier=fedex_ground" > /dev/null
done
```
Then re-check the count.

**Time estimate:** 30 minutes (monitoring)

---

### Step 4 — Flip feature flag to 10% traffic (canary)

**What:** Route 10% of freight rate requests to the new service using consistent hashing on `order_id`. Old service still handles 90%.

**Command:**
```bash
docker compose stop app && \
  USE_NEW_FREIGHT_SERVICE=false \
  FREIGHT_SHADOW_MODE=false \
  USE_NEW_FREIGHT_SERVICE_PERCENT=10 \
  docker compose up -d app
```

Verify the flag is active:
```bash
docker compose exec app php -r "
  define('USE_NEW_FREIGHT_SERVICE_PERCENT', intval(getenv('USE_NEW_FREIGHT_SERVICE_PERCENT')));
  echo 'Canary %: ' . USE_NEW_FREIGHT_SERVICE_PERCENT . PHP_EOL;
  echo (USE_NEW_FREIGHT_SERVICE_PERCENT === 10) ? 'OK: 10% canary active' : 'FAIL: wrong value';
"
```

**Expected output:**
```
Canary %: 10
OK: 10% canary active
```

**If this fails:** `USE_NEW_FREIGHT_SERVICE_PERCENT` env var not wired. Check `config.php` for the constant definition. If missing, the percentage canary feature was not implemented — use binary flag only: set `USE_NEW_FREIGHT_SERVICE=true` and jump to Step 7 after monitoring.

**Time estimate:** 2 minutes

---

### Step 5 — Monitor error rate at 10% for 15 minutes

**What:** Watch the new service's error rate and latency while it handles 10% of production requests.

**Command — error rate (run every 2 minutes for 15 minutes):**
```bash
watch -n 30 'curl -s http://freight-service:8080/actuator/metrics/http.server.requests \
  | python3 -c "
import sys, json
d = json.load(sys.stdin)
measurements = {m[\"statistic\"]: m[\"value\"] for m in d.get(\"measurements\", [])}
print(f\"COUNT: {measurements.get(chr(67)+chr(79)+chr(85)+chr(78)+chr(84), 0):.0f}\")
print(f\"TOTAL_TIME: {measurements.get(chr(84)+chr(79)+chr(84)+chr(65)+chr(76)+'_TIME', 0):.3f}s\")
"'
```

Check 5xx error count directly:
```bash
curl -s "http://freight-service:8080/actuator/metrics/http.server.requests?tag=status:500" \
  | python3 -c "import sys,json; d=json.load(sys.stdin); print('5xx count:', next((m['value'] for m in d.get('measurements',[]) if m['statistic']=='COUNT'), 0))"

curl -s "http://freight-service:8080/actuator/metrics/http.server.requests?tag=status:503" \
  | python3 -c "import sys,json; d=json.load(sys.stdin); print('503 count:', next((m['value'] for m in d.get('measurements',[]) if m['statistic']=='COUNT'), 0))"
```

Check P95 latency via Grafana:
```
http://grafana.internal/d/freight-service-overview
Panel: "Request Latency P95" — must stay below 800ms
```

**Expected output:** 5xx count: 0 (or growing at < 2% of total request count), P95 < 800ms

**If this fails (5xx count growing):** Rollback immediately. Go to Rollback Procedure Step 1.

**Time estimate:** 15 minutes (monitoring)

---

### Step 6 — Flip to 50% traffic

**What:** Increase canary to 50%. If error rates stay clean at 10%, 50% confirms the service handles production load.

**Command:**
```bash
docker compose stop app && \
  USE_NEW_FREIGHT_SERVICE=false \
  FREIGHT_SHADOW_MODE=false \
  USE_NEW_FREIGHT_SERVICE_PERCENT=50 \
  docker compose up -d app
```

Verify:
```bash
docker compose exec app php -r "
  define('USE_NEW_FREIGHT_SERVICE_PERCENT', intval(getenv('USE_NEW_FREIGHT_SERVICE_PERCENT')));
  echo (USE_NEW_FREIGHT_SERVICE_PERCENT === 50) ? 'OK: 50% canary active' : 'FAIL: ' . USE_NEW_FREIGHT_SERVICE_PERCENT;
"
```

**Expected output:**
```
OK: 50% canary active
```

Monitor for 10 minutes — same commands as Step 5.

Check invoice totals are not drifting on the 50% path:
```bash
docker compose exec db mysql -uroot -proot northwind \
  -e "SELECT o.id, o.freight_amount, i.total_amount \
      FROM orders o JOIN invoices i ON i.order_id = o.id \
      WHERE o.updated_at > NOW() - INTERVAL 15 MINUTE \
      ORDER BY o.updated_at DESC LIMIT 5;"
```
Expected: `freight_amount` values are non-zero and consistent with historical ranges ($35.00 minimum per `MIN_FREIGHT_CHARGE`).

**If this fails:** Rollback immediately. Go to Rollback Procedure Step 1.

**Time estimate:** 12 minutes (flip + monitoring)

---

### Step 7 — Flip to 100% — full cutover

**What:** All freight rate requests now go to the new Java service. The old `FreightCalc.php` path is no longer called.

**Command:**
```bash
docker compose stop app && \
  USE_NEW_FREIGHT_SERVICE=true \
  FREIGHT_SHADOW_MODE=false \
  USE_NEW_FREIGHT_SERVICE_PERCENT=100 \
  docker compose up -d app
```

Verify the full flag is set:
```bash
docker compose exec app php -r "
  define('USE_NEW_FREIGHT_SERVICE', getenv('USE_NEW_FREIGHT_SERVICE') === 'true');
  echo USE_NEW_FREIGHT_SERVICE ? 'OK: new service is LIVE at 100%' : 'FAIL: still on old path';
"
```

Verify the ACL is routing to new service by checking the audit log for new-service calls:
```bash
docker compose exec db mysql -uroot -proot northwind \
  -e "SELECT COUNT(*) AS new_service_calls FROM audit_log \
      WHERE event_type='FREIGHT_NEW_SERVICE_CALL' \
      AND created_at > NOW() - INTERVAL 1 MINUTE;"
```
Send a test request then re-check to confirm count increases.

**Expected output:**
```
OK: new service is LIVE at 100%
new_service_calls: [N > 0, increases after test request]
```

**If this fails:** Rollback immediately. Go to Rollback Procedure Step 1.

**Time estimate:** 2 minutes

---

### Step 8 — Verify characterization tests pass on live traffic path

**What:** Confirm all 43 characterization test assertions pass against the new service now that it's handling 100% of traffic.

**Command:**
```bash
docker compose exec app \
  php tests/characterization/FreightCalcCharacterizationTest.php 2>&1 | tee /tmp/chartest_post_cutover.log

tail -3 /tmp/chartest_post_cutover.log
```

**Expected output:**
```
Results: 43/43 passed
```
No `[FAIL]` lines in the output. Exit code 0.

Verify exit code:
```bash
echo "Exit code: $?"
```
Expected: `Exit code: 0`

**If this fails:** Any `[FAIL]` line means a behavioral regression. Rollback immediately. Go to Rollback Procedure Step 1. Save the log:
```bash
cp /tmp/chartest_post_cutover.log /var/log/northwind/chartest_fail_$(date +%Y%m%dT%H%M%S).log
```

**Time estimate:** 2 minutes

---

### Step 9 — Update Nginx to route /api/freight/* directly (remove ACL hop)

**What:** Update Nginx config so `/api/freight/*` bypasses the monolith entirely and goes straight to the freight service. This removes the ACL translation hop from the request path.

**Command:**

First confirm the current Nginx config routes freight through the monolith:
```bash
docker compose exec nginx nginx -T 2>/dev/null | grep -A5 "freight"
```

Apply the direct routing config (the block should already exist in `docker/nginx/northwind.conf` — enable it):
```bash
docker compose exec nginx sh -c "
  grep -q 'proxy_pass.*freight_service' /etc/nginx/conf.d/northwind.conf \
    && echo 'DIRECT ROUTE: already configured' \
    || echo 'DIRECT ROUTE: not found — check northwind.conf'
"
```

Reload Nginx:
```bash
docker compose exec nginx nginx -s reload
echo "Nginx reload exit code: $?"
```

Verify the route is live — this request must hit the freight service directly (no monolith in the path):
```bash
curl -sv -X POST http://monolith:8080/api/freight/rate \
  -H "Content-Type: application/json" \
  -d '{"weightLbs":100,"originZip":"60601","destZip":"10001","carrier":"FEDEX_GROUND"}' \
  2>&1 | grep -E "< HTTP|\"rate\":"
```

**Expected output:**
```
< HTTP/1.1 200
"rate": [dollar amount]
```
Response comes directly from the Java service (check `X-Powered-By` or `Server` header — must NOT say `PHP`).

**If this fails:** Nginx config error — check `docker compose exec nginx nginx -t` for syntax errors. Do not proceed with a broken Nginx. Roll back Nginx to previous config: `docker compose exec nginx nginx -s reload` after reverting the config file. ACL path still works (Step 7 flag is still set).

**Time estimate:** 3 minutes

---

### Step 10 — Archive FreightCalc.php (move to _deprecated/)

**What:** Move `FreightCalc.php` to `_deprecated/` so it is preserved but not actively loaded. Do NOT delete it — you need it for the 30-day rollback window.

**Command:**
```bash
mkdir -p /Users/amit.b.ranjan/CladueCodeWorkspace/northwind-monolith/modules/freight/_deprecated

mv /Users/amit.b.ranjan/CladueCodeWorkspace/northwind-monolith/modules/freight/FreightCalc.php \
   /Users/amit.b.ranjan/CladueCodeWorkspace/northwind-monolith/modules/freight/_deprecated/FreightCalc.php

ls -lh /Users/amit.b.ranjan/CladueCodeWorkspace/northwind-monolith/modules/freight/_deprecated/FreightCalc.php
```

Verify the monolith still boots without FreightCalc.php in its original location:
```bash
curl -s -o /dev/null -w "%{http_code}" http://monolith:8080/
```

**Expected output:**
```
-rw-r--r-- 1 [user] [group] [size] _deprecated/FreightCalc.php
200
```
HTTP 200 confirms the monolith is serving requests. The ACL now calls the new service; `FreightCalc.php` is no longer `require_once`'d in production paths.

**If this fails (monolith returns non-200):** The ACL or some other module still has a hard `require_once` for `FreightCalc.php`. Restore the file:
```bash
mv /Users/amit.b.ranjan/CladueCodeWorkspace/northwind-monolith/modules/freight/_deprecated/FreightCalc.php \
   /Users/amit.b.ranjan/CladueCodeWorkspace/northwind-monolith/modules/freight/FreightCalc.php
```
Then investigate which file is pulling it in. The circular dependency chain (`OrderManager → InvoiceGen → FreightCalc`) must be severed before this step.

**Time estimate:** 2 minutes

---

## Verification Steps After Full Cutover

### Verify rate calculation is live on new service

```bash
# Direct hit to new service
curl -s -X POST http://freight-service:8080/api/freight/rate \
  -H "Content-Type: application/json" \
  -d '{"weightLbs":250,"originZip":"19103","destZip":"90001","carrier":"UPS_GROUND"}' \
  | python3 -m json.tool
```

Expected: JSON response with `rate`, `zone`, `carrier`, `fuelSurcharge` fields. Zone must be 6 for prefix diff of 706 (191→900). Fuel surcharge must be 8.5% of base rate.

```bash
# Confirm request did NOT go through FreightCalc.php by checking new service logs
docker compose logs freight-service --since=1m | grep "Rate calculated" | tail -5
```
Expected: log lines showing rate calculations in the last minute.

### Spot-check invoice totals for 3 recent orders

```bash
# Get 3 most recently updated orders
docker compose exec db mysql -uroot -proot northwind -e "
  SELECT
    o.id AS order_id,
    o.freight_amount,
    o.total_amount,
    i.total_amount AS invoice_total,
    ABS(o.total_amount - i.total_amount) AS discrepancy
  FROM orders o
  LEFT JOIN invoices i ON i.order_id = o.id
  WHERE o.updated_at > NOW() - INTERVAL 2 HOUR
  ORDER BY o.updated_at DESC
  LIMIT 3;
"
```

Expected: `discrepancy` = 0.00 for all rows. `freight_amount` >= 35.00 (MIN_FREIGHT_CHARGE).

```bash
# Spot-check: manually verify one order's freight amount against new service
ORDER_ID=$(docker compose exec db mysql -uroot -proot northwind -sN \
  -e "SELECT id FROM orders ORDER BY updated_at DESC LIMIT 1;")
SHIP_ZIP=$(docker compose exec db mysql -uroot -proot northwind -sN \
  -e "SELECT ship_to_zip FROM orders WHERE id=$ORDER_ID;")
WEIGHT=$(docker compose exec db mysql -uroot -proot northwind -sN \
  -e "SELECT SUM(oi.quantity * p.weight_lbs) FROM order_items oi \
      JOIN products p ON p.id = oi.product_id WHERE oi.order_id=$ORDER_ID;")

echo "Order $ORDER_ID: ship_zip=$SHIP_ZIP, weight=${WEIGHT}lbs"

curl -s -X POST http://freight-service:8080/api/freight/rate \
  -H "Content-Type: application/json" \
  -d "{\"weightLbs\":$WEIGHT,\"originZip\":\"19103\",\"destZip\":\"$SHIP_ZIP\",\"carrier\":\"FEDEX_GROUND\"}" \
  | python3 -c "import sys,json; d=json.load(sys.stdin); print(f'New service rate: \${d[\"rate\"]}')"

docker compose exec db mysql -uroot -proot northwind -sN \
  -e "SELECT CONCAT('\$', freight_amount) FROM orders WHERE id=$ORDER_ID;"
```

Expected: rates match within $0.01.

### Dashboard

Primary dashboard: `http://grafana.internal/d/freight-service-overview`

Panels to verify are green:
- **HTTP Error Rate (5xx)** — must be 0%
- **Request Rate** — must show active traffic (non-zero, matching pre-cutover monolith traffic)
- **Request Latency P95** — must be < 800ms
- **Freight DB Connection Pool** — active connections < max pool size

### Metrics to watch for next 24 hours

| Metric | Location | Alert Threshold |
|---|---|---|
| HTTP 5xx error rate on `/api/freight/*` | Grafana: freight-service-overview | > 2% for 5 min |
| P95 latency on `/api/freight/rate` | Grafana: freight-service-overview | > 800ms |
| Invoice total discrepancy | DB query above | Any row with discrepancy > $0.01 |
| Freight DB connection pool exhaustion | Grafana: freight-db-connections | > 80% pool used |
| JVM heap usage on freight-service | `http://freight-service:8080/actuator/metrics/jvm.memory.used` | > 85% heap max |
| Kafka consumer lag (if event-driven path active) | `http://grafana.internal/d/kafka-consumer-lag` | Lag > 1000 messages |

---

## Rollback Triggers — Abort and Roll Back Immediately

If ANY of these conditions is observed, stop the cutover and execute the Rollback Procedure without delay. These are non-negotiable.

1. **Error rate on `/api/freight/rate` > 2% for 5 consecutive minutes**
   Check: Grafana panel "HTTP Error Rate" or `actuator/metrics` 5xx count growing.

2. **P95 latency > 800ms** (baseline on old path was 200ms)
   Check: Grafana panel "Request Latency P95" on freight-service-overview.

3. **Any invoice total mismatch > $0.01**
   Check: DB query in Verification Steps above — `discrepancy` column.

4. **Any characterization test failure** — even one `[FAIL]` line
   Check: re-run Step 8 command at any time.

5. **New service returning HTTP 5xx for any carrier**
   ```bash
   for carrier in FEDEX_GROUND FEDEX_EXPRESS_SAVER UPS_GROUND UPS_2ND_DAY_AIR UPS_NEXT_DAY_AIR USPS_PRIORITY_MAIL USPS_PARCEL_SELECT DHL_EXPRESS; do
     STATUS=$(curl -s -o /dev/null -w "%{http_code}" -X POST http://freight-service:8080/api/freight/rate \
       -H "Content-Type: application/json" \
       -d "{\"weightLbs\":100,\"originZip\":\"60601\",\"destZip\":\"10001\",\"carrier\":\"$carrier\"}")
     echo "$carrier: HTTP $STATUS"
   done
   ```
   Expected: all `HTTP 200`. Any `5xx` → rollback.

6. **Rate divergence > 5% between old and new path in shadow mode** (Step 3)
   Check: `mismatch_pct` column in the Step 3 divergence query.

7. **Freight-service Postgres DB connection failure**
   ```bash
   curl -s http://freight-service:8080/actuator/health | python3 -c \
     "import sys,json; d=json.load(sys.stdin); print(d.get('components',{}).get('db',{}).get('status','UNKNOWN'))"
   ```
   If output is not `UP` → rollback.

8. **Monolith returns HTTP 5xx on any order invoice path after Step 10** — means a missing `require_once` for `FreightCalc.php` was not caught in testing.
   ```bash
   curl -s -o /dev/null -w "%{http_code}" "http://monolith:8080/?page=order_detail&id=1"
   ```
   Expected: `200`. If `500` → restore FreightCalc.php from `_deprecated/` immediately (see Step 10 "If this fails"), then rollback flag.

9. **MIN_FREIGHT_CHARGE floor not applied** — any invoice showing `freight_amount` < $35.00 in production data.
   ```bash
   docker compose exec db mysql -uroot -proot northwind \
     -e "SELECT id, freight_amount FROM orders WHERE freight_amount < 35.00 AND updated_at > NOW() - INTERVAL 1 HOUR;"
   ```
   Expected: empty result set. Any rows → rollback.

---

## Rollback Procedure

**Target: complete rollback in < 5 minutes.**

Execute these steps in order. Do not skip any step.

---

### Rollback Step 1 — Flip feature flag back to old service

```bash
docker compose stop app && \
  USE_NEW_FREIGHT_SERVICE=false \
  FREIGHT_SHADOW_MODE=false \
  USE_NEW_FREIGHT_SERVICE_PERCENT=0 \
  docker compose up -d app
```

Confirm:
```bash
docker compose exec app php -r "
  define('USE_NEW_FREIGHT_SERVICE', getenv('USE_NEW_FREIGHT_SERVICE') === 'true');
  echo USE_NEW_FREIGHT_SERVICE ? 'FAIL: still on new service' : 'OK: reverted to FreightCalc.php';
"
```

Expected output: `OK: reverted to FreightCalc.php`

If `FreightCalc.php` was already moved to `_deprecated/` in Step 10, restore it first:
```bash
mv /Users/amit.b.ranjan/CladueCodeWorkspace/northwind-monolith/modules/freight/_deprecated/FreightCalc.php \
   /Users/amit.b.ranjan/CladueCodeWorkspace/northwind-monolith/modules/freight/FreightCalc.php
```
Then re-run the docker compose restart above.

---

### Rollback Step 2 — Verify ACL is using old path

```bash
curl -v "http://monolith:8080/?page=freight_estimate&weight=100&origin=60601&dest=10001&carrier=fedex_ground" 2>&1 \
  | grep -E "HTTP/|freight_amount|rate"
```

Expected: HTTP 200 response with a freight rate value. No calls should appear in freight-service logs:
```bash
docker compose logs freight-service --since=30s | grep "Rate calculated" | wc -l
```
Expected: `0` — no new rate calculations in the freight service after the flag flip.

---

### Rollback Step 3 — Run characterization tests against old path

```bash
USE_NEW_FREIGHT_SERVICE=false docker compose exec app \
  php tests/characterization/FreightCalcCharacterizationTest.php 2>&1 | tee /tmp/chartest_rollback.log

tail -3 /tmp/chartest_rollback.log
echo "Exit code: $?"
```

Expected: `Results: 43/43 passed` and `Exit code: 0`

If tests fail here, the old path has a problem unrelated to this cutover. Escalate immediately — do not attempt to re-start the cutover.

---

### Rollback Step 4 — Check error rate has recovered

```bash
# Give it 2 minutes of traffic, then check
sleep 120

curl -s "http://freight-service:8080/actuator/metrics/http.server.requests?tag=status:500" \
  | python3 -c "import sys,json; d=json.load(sys.stdin); print('5xx on new service (now dark):', next((m['value'] for m in d.get('measurements',[]) if m['statistic']=='COUNT'), 0))"

# Check monolith error rate via access log
docker compose exec app tail -20 /var/log/apache2/error.log | grep -c "ERROR" || echo "0 errors in last 20 log lines"
```

Also check Grafana: `http://grafana.internal/d/freight-service-overview` — error rate panel must be returning to 0%.

---

### Rollback Step 5 — Notify #northwind-cutover channel

Post this exact message to #northwind-cutover (fill in bracketed fields):

```
ROLLBACK COMPLETE — Freight Rating Service cutover aborted.

Time: [HH:MM UTC]
Engineer: [your name]
Trigger: [which rollback trigger fired, from the Rollback Triggers list]
Current state: USE_NEW_FREIGHT_SERVICE=false — all traffic on FreightCalc.php
Characterization tests: [43/43 PASS | FAIL — detail]
Invoice totals: [spot-checked N orders, max discrepancy $X.XX]
New service status: dark (deployed, not receiving traffic)

Next steps: [post-mortem | investigation | re-attempt after fix]
```

---

### Rollback Step 6 — Open post-mortem ticket

Create a ticket in the project tracker with the following:

**Title:** `[POST-MORTEM] Freight Rating Service cutover rollback — [date]`

**Include:**
- Time of flag flip and time of rollback decision (total exposure window)
- Which rollback trigger fired (exact metric values that crossed the threshold)
- Divergence log entries from `audit_log` (if shadow mode was active)
- Output of `/tmp/chartest_post_cutover.log` (if Step 8 ran)
- Output of `/tmp/chartest_rollback.log`
- Any error messages from `docker compose logs freight-service --since=[cutover window]`
- Screenshot or export of Grafana panels showing the anomaly
- Link to this runbook + which step failed

---

## The 3AM Decision Tree

```
START: Freight service alert fired
  │
  ├─ Is the new service returning 5xx?
  │   Check:
  │     curl -s -o /dev/null -w "%{http_code}" -X POST http://freight-service:8080/api/freight/rate \
  │       -H "Content-Type: application/json" \
  │       -d '{"weightLbs":100,"originZip":"60601","destZip":"10001","carrier":"FEDEX_GROUND"}'
  │
  │     YES (5xx) → ROLLBACK NOW (Rollback Step 1)
  │                 Save: docker compose logs freight-service --since=10m > /tmp/freight_5xx_$(date +%s).log
  │     NO  ↓
  │
  ├─ Are rates mismatching (> $0.01 on any invoice)?
  │   Check:
  │     docker compose exec db mysql -uroot -proot northwind \
  │       -e "SELECT id, freight_amount, total_amount FROM orders \
  │           WHERE updated_at > NOW() - INTERVAL 15 MINUTE \
  │           ORDER BY updated_at DESC LIMIT 10;"
  │   Compare freight_amount against manual calculation (weight × zone rate × fuel surcharge).
  │
  │     YES → ROLLBACK NOW (Rollback Step 1)
  │            Flag the affected order IDs for billing review before rollback.
  │     NO  ↓
  │
  ├─ Is error rate > 2%?
  │   Check:
  │     curl -s http://freight-service:8080/actuator/metrics/http.server.requests \
  │       | python3 -c "
  │           import sys,json
  │           d=json.load(sys.stdin)
  │           m={x['statistic']:x['value'] for x in d.get('measurements',[])}
  │           total=m.get('COUNT',1)
  │           print('Total requests:', total)
  │         "
  │   Cross-reference against Grafana: http://grafana.internal/d/freight-service-overview
  │   Panel: "HTTP Error Rate"
  │
  │     YES (> 2% for 5+ consecutive minutes) → ROLLBACK NOW (Rollback Step 1)
  │     NO  ↓
  │
  ├─ Is P95 latency > 800ms?
  │   Check Grafana: http://grafana.internal/d/freight-service-overview
  │   Panel: "Request Latency P95"
  │   OR:
  │     time curl -s -X POST http://freight-service:8080/api/freight/rate \
  │       -H "Content-Type: application/json" \
  │       -d '{"weightLbs":500,"originZip":"02101","destZip":"98101","carrier":"UPS_GROUND"}' \
  │       > /dev/null
  │   (Single request; for P95 you need Grafana — use this as a quick sanity check only)
  │
  │     YES (P95 > 800ms sustained) → Check freight-service JVM heap first:
  │           curl -s http://freight-service:8080/actuator/metrics/jvm.memory.used \
  │             | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['measurements'][0]['value']/1e6, 'MB used')"
  │           If heap > 85% → restart service: docker compose restart freight-service
  │           If heap normal → ROLLBACK NOW (Rollback Step 1), latency cause unknown
  │     NO  ↓
  │
  ├─ Is freight-service DB connection healthy?
  │   Check:
  │     curl -s http://freight-service:8080/actuator/health \
  │       | python3 -c "import sys,json; d=json.load(sys.stdin); \
  │           db=d.get('components',{}).get('db',{}).get('status','UNKNOWN'); print('DB:', db)"
  │
  │     DOWN → Check Postgres container: docker compose ps freight-db
  │             If container exited: docker compose restart freight-db
  │             Wait 30s, re-check health.
  │             If still DOWN after 60s: ROLLBACK NOW (Rollback Step 1)
  │     UP  ↓
  │
  └─ False alarm — monitor and document
       1. Check Grafana for any unusual patterns: http://grafana.internal/d/freight-service-overview
       2. Tail freight-service logs for anomalies:
            docker compose logs freight-service --since=10m --follow
       3. Verify characterization tests still pass:
            docker compose exec app php tests/characterization/FreightCalcCharacterizationTest.php
       4. If all green after 10 minutes: post in #northwind-cutover "Alert investigated — false alarm — [details]"
       5. Silence the alert in PagerDuty with a note.
```

---

## Post-Cutover Plan (30 Days)

### Day 1 — Hourly monitoring

Check every hour for the first 8 hours post-cutover:

```bash
# Error rate
curl -s "http://freight-service:8080/actuator/metrics/http.server.requests?tag=status:500" \
  | python3 -c "import sys,json; d=json.load(sys.stdin); print('5xx:', next((m['value'] for m in d.get('measurements',[]) if m['statistic']=='COUNT'), 0))"

# Invoice total discrepancy
docker compose exec db mysql -uroot -proot northwind \
  -e "SELECT COUNT(*) AS suspect_invoices FROM orders o JOIN invoices i ON i.order_id=o.id \
      WHERE ABS(o.total_amount - i.total_amount) > 0.01 \
      AND o.updated_at > NOW() - INTERVAL 1 HOUR;"

# P95 latency — check Grafana panel
echo "Check Grafana: http://grafana.internal/d/freight-service-overview — panel: Request Latency P95"
```

Post hourly status to #northwind-cutover: `Hour [N] check: errors=[N], discrepancies=[N], P95=[Xms] — [CLEAN|ISSUE]`

### Day 3 — Stability checkpoint

```bash
# Compare 3-day error rate against pre-cutover baseline
# Grafana: set time range to "Last 3 days" and compare error rate pre/post cutover timestamp

# Check freight_rates table in new service DB matches MySQL source
docker compose exec db mysql -uroot -proot northwind \
  -e "SELECT COUNT(*) AS mysql_rate_count FROM freight_rates;"

curl -s http://freight-service:8080/api/freight/carriers \
  | python3 -c "import sys,json; d=json.load(sys.stdin); print('Java service carrier count:', len(d))"

# Run full characterization test suite again
docker compose exec app php tests/characterization/FreightCalcCharacterizationTest.php
```

If all clean: post to #northwind-cutover: `Day 3 checkpoint CLEAN — no regressions, proceeding to Day 7.`

If any issue: do not proceed. Investigate and consider rollback.

### Day 7 — FreightCalc.php retirement decision

If the service has been clean for 7 days (zero invoice discrepancies, zero 5xx errors, all characterization tests passing):

1. Run the full characterization test suite one final time against the new service:
   ```bash
   docker compose exec app php tests/characterization/FreightCalcCharacterizationTest.php
   ```
   Must show: `Results: 43/43 passed`

2. Verify `_deprecated/FreightCalc.php` has not been touched:
   ```bash
   ls -lh /Users/amit.b.ranjan/CladueCodeWorkspace/northwind-monolith/modules/freight/_deprecated/FreightCalc.php
   git -C /Users/amit.b.ranjan/CladueCodeWorkspace/northwind-monolith log --oneline -3 -- modules/freight/_deprecated/FreightCalc.php
   ```

3. Post to #northwind-cutover: `Day 7 checkpoint CLEAN — FreightCalc.php ready for decommission at Day 30. File remains in _deprecated/.`

If NOT clean: keep `FreightCalc.php` in `_deprecated/`, open an investigation ticket, reset the 7-day clock after the fix is deployed.

### Day 30 — Decommission checklist

All of the following require explicit sign-off from the freight team lead before execution:

- [ ] Run characterization tests one final time: `Results: 43/43 passed`
- [ ] Run invoice discrepancy check against last 30 days — zero rows
- [ ] Remove ACL code from monolith:
  - Delete `modules/acl/FreightServiceAdapter.php`
  - Remove `FreightServiceAdapter` calls from `OrderManager.php` (restore direct HTTP calls to new service or remove the adapter pattern entirely)
  - Remove `USE_NEW_FREIGHT_SERVICE` flag checks from `config.php`
  - Run characterization tests again — must still pass

- [ ] Back up and drop MySQL `freight_rates` table:
  ```bash
  docker compose exec db mysqldump -uroot -proot --single-transaction northwind freight_rates \
    > /var/backups/northwind/freight_rates_final_$(date +%Y%m%dT%H%M%S).sql
  docker compose exec db mysql -uroot -proot northwind \
    -e "DROP TABLE freight_rates;"
  ```

- [ ] Delete `_deprecated/FreightCalc.php`:
  ```bash
  git -C /Users/amit.b.ranjan/CladueCodeWorkspace/northwind-monolith rm \
    modules/freight/_deprecated/FreightCalc.php
  git -C /Users/amit.b.ranjan/CladueCodeWorkspace/northwind-monolith commit \
    -m "decommission: remove FreightCalc.php after 30-day clean run post-cutover"
  ```

- [ ] Remove `USE_NEW_FREIGHT_SERVICE` and `FREIGHT_SHADOW_MODE` env vars from all configs:
  - `docker-compose.yml`
  - Any `.env` files
  - Kubernetes ConfigMaps / Secrets (if deployed to K8s)
  - CI/CD pipeline env var configuration

- [ ] Close the migration epic in the project tracker. Link to this runbook and the final decommission commit.

---

## Key Contacts and Resources

| Resource | Location / Contact |
|---|---|
| **Grafana: Freight Service Dashboard** | `http://grafana.internal/d/freight-service-overview` |
| **Grafana: Kafka Consumer Lag** | `http://grafana.internal/d/kafka-consumer-lag` |
| **Grafana: Monolith Overview** | `http://grafana.internal/d/monolith-overview` |
| **Incident channel** | #northwind-cutover (Slack) |
| **Freight team lead** | @freight-lead (Slack) — primary escalation |
| **Platform on-call** | PagerDuty: "Northwind Platform" rotation — if rollback itself fails |
| **DB admin on-call** | PagerDuty: "Northwind DBA" rotation — if MySQL or Postgres is down |
| **Characterization tests** | `tests/characterization/FreightCalcCharacterizationTest.php` |
| **ACL adapter** | `modules/acl/FreightServiceAdapter.php` |
| **Architecture decomposition** | `docs/DECOMPOSITION.md` |
| **New service health** | `http://freight-service:8080/actuator/health` |
| **New service API docs** | `http://freight-service:8080/swagger-ui.html` |

### Escalation path if rollback does not work

1. **First call:** Freight team lead (@freight-lead) — knows the ACL code and the Spring Boot service internals.
2. **If freight team lead unreachable:** Page "Northwind Platform" rotation via PagerDuty.
3. **If Postgres DB is down and rollback to MySQL is needed:** Page "Northwind DBA" rotation via PagerDuty. Have the MySQL `freight_rates` dump path ready (`/var/backups/northwind/freight_rates_cutover_*.sql`).
4. **If all else fails and invoices are being generated with wrong freight amounts:** Emergency stop — set `USE_NEW_FREIGHT_SERVICE=false` AND take the freight rate endpoint offline:
   ```bash
   docker compose exec nginx sh -c \
     "sed -i 's|proxy_pass.*freight_service|proxy_pass http://monolith|g' /etc/nginx/conf.d/northwind.conf && nginx -s reload"
   ```
   This forces all `/api/freight/*` traffic back to the monolith. Post the action immediately in #northwind-cutover with timestamp.
