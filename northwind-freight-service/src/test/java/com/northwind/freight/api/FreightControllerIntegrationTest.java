package com.northwind.freight.api;

import com.fasterxml.jackson.databind.ObjectMapper;
import com.northwind.freight.domain.CarrierRates;
import org.junit.jupiter.api.DisplayName;
import org.junit.jupiter.api.Nested;
import org.junit.jupiter.api.Test;
import org.springframework.beans.factory.annotation.Autowired;
import org.springframework.boot.test.autoconfigure.web.servlet.AutoConfigureMockMvc;
import org.springframework.boot.test.context.SpringBootTest;
import org.springframework.http.MediaType;
import org.springframework.test.context.TestPropertySource;
import org.springframework.test.web.servlet.MockMvc;
import org.springframework.test.web.servlet.ResultActions;

import java.util.Map;

import static org.hamcrest.Matchers.containsInAnyOrder;
import static org.hamcrest.Matchers.greaterThan;
import static org.hamcrest.Matchers.hasSize;
import static org.hamcrest.Matchers.is;
import static org.hamcrest.Matchers.notNullValue;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.get;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.post;
import static org.springframework.test.web.servlet.result.MockMvcResultHandlers.print;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.content;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.jsonPath;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.status;

/**
 * Integration tests for {@link FreightController}.
 *
 * <p>Uses {@code @SpringBootTest} with a full Spring context but disables JPA
 * DDL validation and Flyway so the tests run without a live PostgreSQL instance.
 * The rate calculation itself is pure in-memory logic, so there is nothing to stub.
 *
 * <p>Test coverage:
 * <ul>
 *   <li>Happy-path rate calculation</li>
 *   <li>Carrier listing</li>
 *   <li>400 on missing required fields</li>
 *   <li>400 on invalid carrier</li>
 *   <li>400 on weight below minimum</li>
 * </ul>
 */
@SpringBootTest
@AutoConfigureMockMvc
@TestPropertySource(properties = {
        // Disable JPA schema validation so tests don't need a real DB
        "spring.jpa.hibernate.ddl-auto=none",
        // Disable Flyway migrations in test
        "spring.flyway.enabled=false",
        // Use H2 in-memory instead of PostgreSQL
        "spring.datasource.url=jdbc:h2:mem:testdb;DB_CLOSE_DELAY=-1",
        "spring.datasource.driver-class-name=org.h2.Driver",
        "spring.datasource.username=sa",
        "spring.datasource.password=",
        "spring.jpa.database-platform=org.hibernate.dialect.H2Dialect"
})
class FreightControllerIntegrationTest {

    @Autowired
    private MockMvc mockMvc;

    @Autowired
    private ObjectMapper objectMapper;

    // =========================================================================
    // POST /api/freight/rate — happy path
    // =========================================================================

    @Nested
    @DisplayName("POST /api/freight/rate")
    class PostRateTests {

        @Test
        @DisplayName("Valid request returns 200 with all expected fields")
        void validRequest_returns200() throws Exception {
            String requestBody = objectMapper.writeValueAsString(Map.of(
                    "weightLbs",  10.5,
                    "originZip",  "10001",
                    "destZip",    "90210",
                    "carrier",    "FEDEX"
            ));

            performPost(requestBody)
                    .andDo(print())
                    .andExpect(status().isOk())
                    .andExpect(content().contentType(MediaType.APPLICATION_JSON))
                    .andExpect(jsonPath("$.carrier",       is("FEDEX")))
                    .andExpect(jsonPath("$.originZip",     is("10001")))
                    .andExpect(jsonPath("$.destZip",       is("90210")))
                    .andExpect(jsonPath("$.weightLbs",     is(10.5)))
                    .andExpect(jsonPath("$.zone",          is(greaterThan(0))))
                    .andExpect(jsonPath("$.baseRate",      notNullValue()))
                    .andExpect(jsonPath("$.fuelSurcharge", notNullValue()))
                    .andExpect(jsonPath("$.totalRate",     notNullValue()))
                    .andExpect(jsonPath("$.estimatedDays", greaterThan(0)))
                    .andExpect(jsonPath("$.calculatedAt",  notNullValue()));
        }

        @Test
        @DisplayName("UPS request returns 200 with carrier=UPS")
        void upsRequest_returns200() throws Exception {
            String requestBody = objectMapper.writeValueAsString(Map.of(
                    "weightLbs",  5.0,
                    "originZip",  "30301",
                    "destZip",    "60601",
                    "carrier",    "UPS"
            ));

            performPost(requestBody)
                    .andExpect(status().isOk())
                    .andExpect(jsonPath("$.carrier", is("UPS")));
        }

        @Test
        @DisplayName("Same-ZIP request returns zone 1")
        void sameZip_returnsZone1() throws Exception {
            String requestBody = objectMapper.writeValueAsString(Map.of(
                    "weightLbs",  2.0,
                    "originZip",  "77001",
                    "destZip",    "77099",
                    "carrier",    "USPS"
            ));

            performPost(requestBody)
                    .andExpect(status().isOk())
                    .andExpect(jsonPath("$.zone", is(1)));
        }

        // -------------------------------------------------------------------------
        // 400 — missing carrier
        // -------------------------------------------------------------------------

        @Test
        @DisplayName("Missing carrier field returns 400 with field=carrier in error body")
        void missingCarrier_returns400WithFieldError() throws Exception {
            String requestBody = objectMapper.writeValueAsString(Map.of(
                    "weightLbs",  10.5,
                    "originZip",  "10001",
                    "destZip",    "90210"
                    // carrier intentionally omitted
            ));

            performPost(requestBody)
                    .andDo(print())
                    .andExpect(status().isBadRequest())
                    .andExpect(jsonPath("$.error",  notNullValue()))
                    .andExpect(jsonPath("$.field",  is("carrier")));
        }

        @Test
        @DisplayName("Invalid carrier value returns 400")
        void invalidCarrier_returns400() throws Exception {
            String requestBody = objectMapper.writeValueAsString(Map.of(
                    "weightLbs",  10.5,
                    "originZip",  "10001",
                    "destZip",    "90210",
                    "carrier",    "AMAZON"
            ));

            performPost(requestBody)
                    .andExpect(status().isBadRequest())
                    .andExpect(jsonPath("$.error", notNullValue()));
        }

        // -------------------------------------------------------------------------
        // 400 — invalid weight
        // -------------------------------------------------------------------------

        @Test
        @DisplayName("Weight=0 returns 400 with field=weightLbs in error body")
        void weightZero_returns400WithFieldError() throws Exception {
            String requestBody = objectMapper.writeValueAsString(Map.of(
                    "weightLbs",  0.0,
                    "originZip",  "10001",
                    "destZip",    "90210",
                    "carrier",    "FEDEX"
            ));

            performPost(requestBody)
                    .andDo(print())
                    .andExpect(status().isBadRequest())
                    .andExpect(jsonPath("$.error",  notNullValue()))
                    .andExpect(jsonPath("$.field",  is("weightLbs")));
        }

        @Test
        @DisplayName("Weight exceeding 70000 returns 400")
        void weightTooHigh_returns400() throws Exception {
            String requestBody = objectMapper.writeValueAsString(Map.of(
                    "weightLbs",  70001.0,
                    "originZip",  "10001",
                    "destZip",    "90210",
                    "carrier",    "FEDEX"
            ));

            performPost(requestBody)
                    .andExpect(status().isBadRequest())
                    .andExpect(jsonPath("$.field", is("weightLbs")));
        }

        // -------------------------------------------------------------------------
        // 400 — missing zip
        // -------------------------------------------------------------------------

        @Test
        @DisplayName("Missing originZip returns 400 with field=originZip")
        void missingOriginZip_returns400() throws Exception {
            String requestBody = objectMapper.writeValueAsString(Map.of(
                    "weightLbs",  10.0,
                    "destZip",    "90210",
                    "carrier",    "UPS"
            ));

            performPost(requestBody)
                    .andExpect(status().isBadRequest())
                    .andExpect(jsonPath("$.field", is("originZip")));
        }

        @Test
        @DisplayName("ZIP with letters returns 400")
        void alphaZip_returns400() throws Exception {
            String requestBody = objectMapper.writeValueAsString(Map.of(
                    "weightLbs",  10.0,
                    "originZip",  "ABCDE",
                    "destZip",    "90210",
                    "carrier",    "UPS"
            ));

            performPost(requestBody)
                    .andExpect(status().isBadRequest())
                    .andExpect(jsonPath("$.field", is("originZip")));
        }

        @Test
        @DisplayName("4-digit ZIP returns 400 (must be exactly 5)")
        void fourDigitZip_returns400() throws Exception {
            String requestBody = objectMapper.writeValueAsString(Map.of(
                    "weightLbs",  10.0,
                    "originZip",  "1234",
                    "destZip",    "90210",
                    "carrier",    "DHL"
            ));

            performPost(requestBody)
                    .andExpect(status().isBadRequest());
        }

        // -------------------------------------------------------------------------
        // 400 — empty body
        // -------------------------------------------------------------------------

        @Test
        @DisplayName("Empty body returns 400")
        void emptyBody_returns400() throws Exception {
            mockMvc.perform(post("/api/freight/rate")
                            .contentType(MediaType.APPLICATION_JSON)
                            .content("{}"))
                    .andExpect(status().isBadRequest());
        }
    }

    // =========================================================================
    // GET /api/freight/carriers
    // =========================================================================

    @Nested
    @DisplayName("GET /api/freight/carriers")
    class GetCarriersTests {

        @Test
        @DisplayName("Returns 200 with list of exactly 4 carriers")
        void returns200WithFourCarriers() throws Exception {
            mockMvc.perform(get("/api/freight/carriers")
                            .accept(MediaType.APPLICATION_JSON))
                    .andDo(print())
                    .andExpect(status().isOk())
                    .andExpect(content().contentType(MediaType.APPLICATION_JSON))
                    .andExpect(jsonPath("$", hasSize(4)))
                    .andExpect(jsonPath("$", containsInAnyOrder("FEDEX", "UPS", "USPS", "DHL")));
        }

        @Test
        @DisplayName("Carrier list matches CarrierRates.SUPPORTED_CARRIERS constant")
        void carrierListMatchesConstant() throws Exception {
            String expectedJson = objectMapper.writeValueAsString(CarrierRates.SUPPORTED_CARRIERS);

            mockMvc.perform(get("/api/freight/carriers")
                            .accept(MediaType.APPLICATION_JSON))
                    .andExpect(status().isOk())
                    .andExpect(content().json(expectedJson));
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private ResultActions performPost(String body) throws Exception {
        return mockMvc.perform(post("/api/freight/rate")
                .contentType(MediaType.APPLICATION_JSON)
                .content(body));
    }
}
