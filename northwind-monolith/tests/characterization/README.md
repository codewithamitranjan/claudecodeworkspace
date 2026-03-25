# Characterization Tests — Northwind Logistics Monolith

## What Characterization Tests Are

Characterization tests record **what the code does today**, not what it should do.

A characterization test:
- Calls the real production code with a known input
- Records the exact output the code produces right now
- Passes permanently as long as the code's behaviour is unchanged
- Fails the moment any change — intentional or accidental — alters the output

They are a **safety net for refactoring and extraction**, not a correctness
specification. If the code has a bug, the characterization test records the
buggy output. That is intentional: you need to know when behaviour changes,
whether the change is a fix or a regression.

---

## How To Run

From the `northwind-monolith/` project root:

```bash
# Start the application container (if not already running)
docker compose up -d

# Run all characterization tests
bash tests/characterization/run_all.sh

# Or run a single test file directly
docker compose exec app php tests/characterization/FreightCalcCharacterizationTest.php
```

Each test prints one line per assertion:

```
[PASS] test_name
[FAIL] test_name: expected X got Y
```

And a summary at the end:

```
Results: 37/37 passed
```

A non-zero exit code means at least one test failed.

---

## Test Files

| File | Module Covered | # Assertions |
|---|---|---|
| `FreightCalcCharacterizationTest.php` | `modules/freight/FreightCalc.php` | 37 |

---

## Bugs Intentionally Pinned

These bugs are recorded as-is. The tests will **pass** with the buggy values.
Do not "fix" a test to produce the correct answer — that defeats the purpose.

### 1. Florida ZIP Zone Underestimate

**Location:** `FreightCalc::calculateZoneRate()`

**Behaviour pinned:**
```
calculateZoneRate('32001', '10001')  =>  zone 5
```

**Why it is wrong:** The algorithm computes zone from the numeric difference of
3-digit ZIP prefixes: `abs(320 - 100) = 220`, which falls in the `<= 250` bucket
(zone 5). Geographically, Florida-to-New-York routes should be zone 6-7. The same
systematic error affects all Florida ZIPs (320-349) and all West Coast ZIPs
(900-994). This was identified in 2011 but never fixed because "it only affects
a few routes." It causes systematic undercharging on these lanes.

**Test name:** `zone_rate_florida320_to_newyork100_BUG_gives_zone5_not_zone6`

---

### 2. Chicago to Los Angeles Zone Underestimate

**Location:** `FreightCalc::calculateZoneRate()`

**Behaviour pinned:**
```
calculateZoneRate('60601', '90001')  =>  zone 6
```

**Why it is wrong:** `abs(606 - 900) = 294`, which falls in the `<= 400` bucket
(zone 6). Chicago to LA is one of the longest domestic lanes and should be zone 8.
The prefix-difference heuristic breaks down for cross-country routes because ZIP
codes are not geographically ordered.

**Test name:** `zone_rate_chicago606_to_losangeles900_BUG_gives_zone6_not_zone8`

---

### 3. Duplicate Freight Calculation — Different Results for Same Input

**Location:** `FreightCalc::calculateZoneRate()` vs `helpers.php::calcFreightEstimate()`

**Behaviour pinned:** Two separate code paths exist for freight calculation and
they produce **different zones and rates for the same input**:

| Input | `FreightCalc::calculateZoneRate()` | `helpers.php::calcFreightEstimate()` |
|---|---|---|
| Chicago (606) → New York (100), diff=506 | zone **7** | zone **6** |
| Chicago (606) → LA (900), diff=294 | zone **6** | zone **4** |
| Florida (320) → NY (100), diff=220 | zone **5** | zone **4** |

Root cause: `calcFreightEstimate()` was copied from `FreightCalc.php` in 2009 with
slightly different bucket boundaries and was never updated when `FreightCalc.php`
was revised in 2010 and 2013. The two copies have been silently diverging for
years. The helpers.php version also has a smaller zone table (only zones 2-7,
no zone 8).

This matters for the microservice extraction: the new Freight Rating Service must
replicate `FreightCalc`'s logic specifically, not `calcFreightEstimate`'s logic.

---

### 4. `applyFuelSurcharge(47.82)` Returns 51.88, Not 51.90

**Location:** `FreightCalc::applyFuelSurcharge()`

**Behaviour pinned:**
```
applyFuelSurcharge(47.82)  =>  51.8847  (round to 2dp: 51.88)
```

The fuel surcharge is 8.5%: `47.82 * 1.085 = 51.8847`. PHP `round(51.8847, 2)`
gives `51.88`. Some internal documentation and spreadsheets round differently
(mid-point rounding to nearest even) and show `51.90`, but the production code
produces `51.88`. This test pins the actual code output.

**Test name:** `fuel_surcharge_47_82_gives_51_88_not_51_90`

---

### 5. Stale Freight Cache — No TTL, No Invalidation

**Location:** `FreightCalc::saveRateToCache()`, `FreightCalc::loadRateFromCache()`

**Behaviour (not directly tested by these unit tests, but pinned in integration tests):**
The flat-file cache under `/tmp/northwind_cache/freight_<orderId>.cache` has no
expiry timestamp check. A rate cached in 2011 will still be returned in 2026.
If order items change after a rate is cached, the cached rate is stale but still
served. This caused incorrect invoice amounts during Q2 2013. The unit tests do
not exercise the cache path because it requires filesystem state setup.

---

## The Golden Rule

> **NEVER change a characterization test to make it pass after a code change.**

If a characterization test fails:

1. Stop. Understand **why** it failed.
2. Was the behaviour change intentional? (e.g., you deliberately fixed the Florida zone bug)
3. If YES and the change is confirmed intentional:
   - Update the test to reflect the new expected value
   - Add a comment in the test explaining what changed and why
   - Update the pinned-bugs table in this README
   - Commit test + code change together with a clear message
4. If NO (you didn't mean to change this behaviour):
   - The test caught a regression. Fix the code, not the test.

---

## Adding New Characterization Tests

When extracting a module or refactoring:

1. Run existing characterization tests first — they must all pass.
2. Add new input/output pairs for any behaviour not yet covered.
3. Run the new tests against the current monolith — they must pass.
4. Commit. Now the new tests form part of the safety net.
5. Make your extraction/refactoring change.
6. Run all characterization tests again. Any failure is a regression to investigate.

---

## Relation to the Modernisation Project

During Strangler Fig extraction of Freight Rating:

- Characterization tests run against the **monolith** (`USE_NEW_FREIGHT_SERVICE=false`)
  and must pass — they prove the old path is unchanged.
- The same input/output pairs are used as **contract fixtures** for the new
  Python/FastAPI Freight Rating Service. The new service must return identical
  results for every pinned input.
- After cutover (`USE_NEW_FREIGHT_SERVICE=true`), characterization tests run
  through the ACL and must still pass — they prove the ACL translation is correct.

See `docs/DECOMPOSITION.md` for extraction strategy and `modules/acl/FreightServiceAdapter.php`
for the Anti-Corruption Layer.
