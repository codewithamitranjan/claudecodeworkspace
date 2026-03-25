#!/bin/bash
# run_all.sh — Run all characterization tests inside the Docker container.
#
# Characterization tests pin the EXISTING behaviour of the Northwind monolith,
# including known bugs. They must pass before AND after every code change.
# If a test fails after a change, investigate before modifying the test.
#
# Usage:
#   bash tests/characterization/run_all.sh
#
# Run from the northwind-monolith project root (where docker-compose.yml lives).

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

echo "============================================"
echo " Northwind Characterization Test Suite"
echo "============================================"
echo ""

cd "$PROJECT_ROOT"

# ---------------------------------------------------------------------------
# FreightCalc characterization tests
# ---------------------------------------------------------------------------
echo "--- FreightCalc ---"
docker compose exec app php tests/characterization/FreightCalcCharacterizationTest.php
echo ""

# ---------------------------------------------------------------------------
# Summary
# ---------------------------------------------------------------------------
echo "============================================"
echo " All characterization test files executed."
echo " Non-zero exit code above = failures found."
echo "============================================"
