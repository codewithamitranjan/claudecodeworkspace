<?php
/**
 * modules/acl/acl_config.php
 *
 * Anti-Corruption Layer — Feature Flag Configuration
 *
 * This file documents the environment variables that control the ACL between the
 * PHP monolith and the new Java Spring Boot freight service.  It does NOT set any
 * PHP constants itself — those live in config.php.  Think of this as the README
 * for operators who need to flip the flag without reading the adapter code.
 *
 * ============================================================================
 * FEATURE FLAG: USE_NEW_FREIGHT_SERVICE
 * ============================================================================
 *
 * Controls whether freight rate requests are routed through FreightServiceAdapter
 * (new Java service) or handled directly by FreightCalc (legacy PHP code).
 *
 *   'true'         => Route through FreightServiceAdapter -> Spring Boot service.
 *                     The adapter translates legacy field names to/from the new
 *                     service contract.  On any HTTP or curl error the adapter
 *                     falls back to FreightCalc automatically — the flag does NOT
 *                     need to be flipped for a single-node failure.
 *
 *   anything else  => FreightCalc::calculateRate() is called directly.
 *                     (This is the safe default — if the env var is missing or
 *                     misspelled the monolith behaves exactly as before.)
 *
 * How it is read in PHP:
 *   FreightServiceAdapter::shouldUseNewService()
 *     returns (getenv('USE_NEW_FREIGHT_SERVICE') === 'true');
 *
 * ============================================================================
 * SERVICE URL: NEW_FREIGHT_SERVICE_URL
 * ============================================================================
 *
 * Base URL of the Spring Boot freight service.
 *
 *   Default: http://localhost:8080
 *
 *   In docker-compose.yml (local dev) set to the service name:
 *     http://freight-service:8080
 *
 *   In Kubernetes set to the ClusterIP service DNS name:
 *     http://freight-service.northwind.svc.cluster.local:8080
 *
 * The adapter appends path segments:
 *   POST {NEW_FREIGHT_SERVICE_URL}/api/freight/rate     — rate calculation
 *        {NEW_FREIGHT_SERVICE_URL}/actuator/health       — health ping
 *
 * ============================================================================
 * HOW TO FLIP THE FLAG IN docker-compose.yml
 * ============================================================================
 *
 * 1. Open docker-compose.yml in the repo root.
 *
 * 2. Find the `app` service environment block.  It should look like:
 *
 *      services:
 *        app:
 *          build: .
 *          environment:
 *            - DB_HOST=db
 *            - DB_NAME=northwind
 *            # ... other vars ...
 *            - USE_NEW_FREIGHT_SERVICE=false
 *            - NEW_FREIGHT_SERVICE_URL=http://freight-service:8080
 *
 * 3. To ENABLE the new service:
 *      Change:  USE_NEW_FREIGHT_SERVICE=false
 *      To:      USE_NEW_FREIGHT_SERVICE=true
 *
 * 4. Restart the app container (DB container does NOT need to restart):
 *      docker compose up -d --no-deps app
 *
 * 5. To verify the flag is live, check the health endpoint from inside the
 *    running app container:
 *      docker compose exec app php -r "echo getenv('USE_NEW_FREIGHT_SERVICE');"
 *    Expected output: true
 *
 * 6. To DISABLE (rollback):
 *      Change:  USE_NEW_FREIGHT_SERVICE=true
 *      To:      USE_NEW_FREIGHT_SERVICE=false
 *      Then:    docker compose up -d --no-deps app
 *
 *    The adapter's built-in fallback (FreightCalc) activates automatically on
 *    HTTP errors, but explicitly setting the flag to false gives a hard cutover
 *    back to the old code path with zero network calls to the new service.
 *
 * ============================================================================
 * MIGRATION PHASE REFERENCE
 * ============================================================================
 *
 * Phase 1 (now)        USE_NEW_FREIGHT_SERVICE=false  — legacy path only
 * Phase 1 (shadow)     USE_NEW_FREIGHT_SERVICE=true   — new service, ACL active,
 *                                                        fallback to FreightCalc on error
 * Phase 3 (cut over)   USE_NEW_FREIGHT_SERVICE=true   — new service only; monitor
 *                                                        for 30 days before decommission
 * Phase 4 (decommission) Remove flag entirely after FreightCalc.php is deleted
 *
 * See CLAUDE.md §Migration Phases for full phase definitions.
 */
