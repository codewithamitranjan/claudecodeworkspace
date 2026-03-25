package com.northwind.freight.domain;

import com.northwind.freight.api.dto.FreightRateRequest;
import com.northwind.freight.api.dto.FreightRateResponse;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.DisplayName;
import org.junit.jupiter.api.Nested;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.params.ParameterizedTest;
import org.junit.jupiter.params.provider.CsvSource;

import java.math.BigDecimal;

import static org.assertj.core.api.Assertions.assertThat;
import static org.assertj.core.api.Assertions.assertThatThrownBy;
import static org.assertj.core.api.Assertions.within;

/**
 * Unit tests for {@link FreightRatingService}.
 *
 * <p>No Spring context is loaded — the service has no I/O dependencies and can be
 * instantiated directly.  These are <em>characterisation tests</em>: they lock in the
 * behaviour of the ported PHP logic so that any accidental regression is caught
 * immediately.
 */
class FreightRatingServiceTest {

    private FreightRatingService service;

    @BeforeEach
    void setUp() {
        service = new FreightRatingService();
    }

    // =========================================================================
    // Zone calculation
    // =========================================================================

    @Nested
    @DisplayName("calculateZone — ported from FreightCalc.php")
    class ZoneCalculationTests {

        /**
         * Characterisation matrix.  Each row: originZip, destZip, expectedZone.
         *
         * The 12 cases cover every zone boundary plus the Florida (320-349) edge case.
         */
        @ParameterizedTest(name = "origin={0}, dest={1} → zone {2}")
        @CsvSource({
            // Zone 1: same prefix
            "10001, 10099, 1",
            // Zone 2: diff 1-50
            "10001, 10501, 2",   // diff = |100 - 105| = 5
            "20001, 24999, 2",   // diff = |200 - 249| = 49
            // Zone 3: diff 51-100
            "30001, 38001, 3",   // diff = |300 - 380| = 80
            // Zone 4: diff 101-200
            "10001, 22001, 4",   // diff = |100 - 220| = 120
            // Zone 4 Florida bug — Florida ZIP prefix 320; origin 130-prefix gives diff=190 → zone 4
            // The PHP monolith bug is that Florida (320-349) routes shorter-than-expected
            // because some origin prefixes produce a diff ≤200, landing them in zone 4 instead
            // of zone 5.  This is the case being preserved (NW-FREIGHT-42).
            "13001, 32099, 4",   // diff = |130 - 320| = 190  ← intentional PHP bug preserved
            // Zone 5: diff 201-300
            "10001, 30201, 5",   // diff = |100 - 302| = 202 → zone 5
            // Zone 6: diff 301-400
            "10001, 44001, 6",   // diff = |100 - 440| = 340
            // Zone 7: diff 401-500
            "10001, 55001, 7",   // diff = |100 - 550| = 450
            // Zone 8: diff > 500
            "10001, 99801, 8",   // diff = |100 - 998| = 898
            // Zone 8: cross-country (NYC → Hawaii)
            "10001, 96801, 8"    // diff = |100 - 968| = 868
        })
        void testZoneCalculation(String originZip, String destZip, int expectedZone) {
            assertThat(service.calculateZone(originZip, destZip)).isEqualTo(expectedZone);
        }

        @Test
        @DisplayName("Zone 1: identical ZIPs should give zone 1")
        void sameZip_shouldBeZone1() {
            assertThat(service.calculateZone("90210", "90210")).isEqualTo(1);
        }

        @Test
        @DisplayName("Zone 1: same 3-digit prefix, different last 2 digits")
        void samePrefix_shouldBeZone1() {
            assertThat(service.calculateZone("90200", "90299")).isEqualTo(1);
        }

        @Test
        @DisplayName("Zone calculation is symmetric (origin ↔ dest)")
        void zoneCalculation_isSymmetric() {
            assertThat(service.calculateZone("10001", "99801"))
                    .isEqualTo(service.calculateZone("99801", "10001"));
        }
    }

    // =========================================================================
    // Fuel surcharge
    // =========================================================================

    @Nested
    @DisplayName("applyFuelSurcharge — 8.5 % on top of base rate")
    class FuelSurchargeTests {

        @Test
        @DisplayName("100.00 * 1.085 = 108.50")
        void hundredDollars_shouldGive108_50() {
            BigDecimal result = service.applyFuelSurcharge(new BigDecimal("100.00"));
            assertThat(result).isEqualByComparingTo(new BigDecimal("108.50"));
        }

        @Test
        @DisplayName("0.00 surcharge on zero base")
        void zeroDollars_shouldGiveZero() {
            BigDecimal result = service.applyFuelSurcharge(BigDecimal.ZERO);
            assertThat(result).isEqualByComparingTo(BigDecimal.ZERO);
        }

        @Test
        @DisplayName("Rounding: 10.00 * 1.085 = 10.85 (no rounding needed)")
        void tenDollars_shouldGive10_85() {
            BigDecimal result = service.applyFuelSurcharge(new BigDecimal("10.00"));
            assertThat(result).isEqualByComparingTo(new BigDecimal("10.85"));
        }

        @Test
        @DisplayName("Rounding: 11.00 * 1.085 = 11.94 (rounds HALF_UP from 11.935)")
        void elevenDollars_shouldRoundCorrectly() {
            BigDecimal result = service.applyFuelSurcharge(new BigDecimal("11.00"));
            // 11.00 * 1.085 = 11.935 → rounds to 11.94 (HALF_UP)
            assertThat(result).isEqualByComparingTo(new BigDecimal("11.94"));
        }
    }

    // =========================================================================
    // Full rate calculation — all four carriers
    // =========================================================================

    @Nested
    @DisplayName("calculateRate — all carriers produce a valid rate")
    class CalculateRateTests {

        private static final String ORIGIN_ZIP = "10001";
        private static final String DEST_ZIP   = "90210";
        private static final double WEIGHT_LBS = 10.0;

        @Test
        @DisplayName("FEDEX: zone 8 (diff=|100-902|=802), produces positive totalRate")
        void fedex_shouldReturnPositiveRate() {
            FreightRateResponse resp = service.calculateRate(buildRequest("FEDEX"));
            assertThat(resp.carrier()).isEqualTo("FEDEX");
            assertThat(resp.zone()).isEqualTo(8);
            assertThat(resp.totalRate()).isGreaterThan(BigDecimal.ZERO);
            assertThat(resp.fuelSurcharge()).isGreaterThan(BigDecimal.ZERO);
        }

        @Test
        @DisplayName("UPS: zone 8, produces positive totalRate less than FEDEX")
        void ups_shouldReturnLowerRateThanFedex() {
            FreightRateResponse fedex = service.calculateRate(buildRequest("FEDEX"));
            FreightRateResponse ups   = service.calculateRate(buildRequest("UPS"));
            assertThat(ups.totalRate()).isLessThan(fedex.totalRate());
        }

        @Test
        @DisplayName("USPS: cheapest carrier for zone 8")
        void usps_shouldBeCheapest() {
            FreightRateResponse usps = service.calculateRate(buildRequest("USPS"));
            FreightRateResponse ups  = service.calculateRate(buildRequest("UPS"));
            assertThat(usps.totalRate()).isLessThan(ups.totalRate());
        }

        @Test
        @DisplayName("DHL: most expensive carrier for zone 8")
        void dhl_shouldBeMostExpensive() {
            FreightRateResponse dhl   = service.calculateRate(buildRequest("DHL"));
            FreightRateResponse fedex = service.calculateRate(buildRequest("FEDEX"));
            assertThat(dhl.totalRate()).isGreaterThan(fedex.totalRate());
        }

        @Test
        @DisplayName("All carriers: response echoes back request fields")
        void responseEchoes_requestFields() {
            for (String carrier : CarrierRates.SUPPORTED_CARRIERS) {
                FreightRateResponse resp = service.calculateRate(buildRequest(carrier));
                assertThat(resp.originZip()).isEqualTo(ORIGIN_ZIP);
                assertThat(resp.destZip()).isEqualTo(DEST_ZIP);
                assertThat(resp.weightLbs()).isEqualTo(WEIGHT_LBS);
                assertThat(resp.carrier()).isEqualTo(carrier);
            }
        }

        @Test
        @DisplayName("totalRate equals baseRate + fuelSurcharge (precision check)")
        void totalRate_equalsBaseRatePlusSurcharge() {
            FreightRateResponse resp = service.calculateRate(buildRequest("FEDEX"));
            BigDecimal expected = resp.baseRate().add(resp.fuelSurcharge());
            assertThat(resp.totalRate()).isEqualByComparingTo(expected);
        }

        @Test
        @DisplayName("calculatedAt is set and recent")
        void calculatedAt_isSetAndRecent() {
            FreightRateResponse resp = service.calculateRate(buildRequest("UPS"));
            assertThat(resp.calculatedAt()).isNotNull();
            // Should be within the last 5 seconds
            long secondsAgo = java.time.Instant.now().getEpochSecond()
                    - resp.calculatedAt().getEpochSecond();
            assertThat(secondsAgo).isLessThan(5);
        }

        @Test
        @DisplayName("estimatedDays is positive for all carriers")
        void estimatedDays_isPositive() {
            for (String carrier : CarrierRates.SUPPORTED_CARRIERS) {
                FreightRateResponse resp = service.calculateRate(buildRequest(carrier));
                assertThat(resp.estimatedDays()).isGreaterThan(0);
            }
        }

        private FreightRateRequest buildRequest(String carrier) {
            return FreightRateRequest.builder()
                    .carrier(carrier)
                    .originZip(ORIGIN_ZIP)
                    .destZip(DEST_ZIP)
                    .weightLbs(WEIGHT_LBS)
                    .build();
        }
    }

    // =========================================================================
    // Validation guard tests
    // =========================================================================

    @Nested
    @DisplayName("calculateRate — guard conditions")
    class ValidationGuardTests {

        @Test
        @DisplayName("Unknown carrier throws IllegalArgumentException")
        void unknownCarrier_throwsIllegalArgumentException() {
            FreightRateRequest req = FreightRateRequest.builder()
                    .carrier("BADCARRIER")
                    .originZip("10001")
                    .destZip("90210")
                    .weightLbs(5.0)
                    .build();

            assertThatThrownBy(() -> service.calculateRate(req))
                    .isInstanceOf(IllegalArgumentException.class)
                    .hasMessageContaining("BADCARRIER");
        }

        @Test
        @DisplayName("calculateZone handles leading-zero ZIPs (e.g. 00501 — Holtsville NY)")
        void leadingZeroZip_handledCorrectly() {
            // 005 vs 100 → diff = 95 → zone 3
            int zone = service.calculateZone("00501", "10001");
            assertThat(zone).isEqualTo(3);
        }

        @Test
        @DisplayName("Zone 1: same ZIP for both origin and dest")
        void sameOriginAndDest_returnsZone1() {
            assertThat(service.calculateZone("77001", "77001")).isEqualTo(1);
        }
    }

    // =========================================================================
    // Exact value smoke tests (lock in rate formula against PHP reference values)
    // =========================================================================

    @Nested
    @DisplayName("Exact value smoke tests — match PHP reference output")
    class ExactValueTests {

        /**
         * Reference: PHP FreightCalc.php with FEDEX, weight=1lb, zone=1
         * baseRate = 5.99 + (1 * 1 * 0.05) = 6.04
         * surcharge = 6.04 * 0.085 = 0.5134 → 0.51
         * total = 6.04 + 0.51 = 6.55
         */
        @Test
        @DisplayName("FEDEX zone 1, 1 lb: totalRate = 6.55")
        void fedex_zone1_1lb() {
            // origin/dest same prefix → zone 1
            FreightRateRequest req = FreightRateRequest.builder()
                    .carrier("FEDEX").originZip("10001").destZip("10099").weightLbs(1.0)
                    .build();

            FreightRateResponse resp = service.calculateRate(req);
            assertThat(resp.zone()).isEqualTo(1);
            assertThat(resp.baseRate()).isEqualByComparingTo(new BigDecimal("6.04"));
            assertThat(resp.totalRate()).isEqualByComparingTo(new BigDecimal("6.55"));
        }

        /**
         * Reference: USPS, weight=10lb, zone=3
         * diff = |300 - 380| = 80 → zone 3
         * baseRate = 9.99 + (10 * 3 * 0.05) = 9.99 + 1.50 = 11.49
         * surcharge = 11.49 * 0.085 = 0.9766... → 0.98
         * total = 11.49 + 0.98 = 12.47
         */
        @Test
        @DisplayName("USPS zone 3, 10 lb: totalRate = 12.47")
        void usps_zone3_10lb() {
            FreightRateRequest req = FreightRateRequest.builder()
                    .carrier("USPS").originZip("30001").destZip("38001").weightLbs(10.0)
                    .build();

            FreightRateResponse resp = service.calculateRate(req);
            assertThat(resp.zone()).isEqualTo(3);
            assertThat(resp.baseRate()).isEqualByComparingTo(new BigDecimal("11.49"));
            assertThat(resp.totalRate()).isEqualByComparingTo(new BigDecimal("12.47"));
        }
    }
}
