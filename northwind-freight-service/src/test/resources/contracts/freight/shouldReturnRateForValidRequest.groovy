/*
 * Spring Cloud Contract DSL
 * Contract: shouldReturnRateForValidRequest.groovy
 *
 * Verifies that the freight service returns a well-formed rate response for a
 * valid FEDEX shipment from Chicago (60601) to New York City (10001).
 *
 * This contract is the source of truth for the interaction between:
 *   Consumer: PHP monolith FreightServiceAdapter (ACL)
 *   Provider: northwind-freight-service Spring Boot app
 *
 * Spring Cloud Contract generates:
 *   - A WireMock stub (for consumer-side tests in FreightServiceAdapter)
 *   - A JUnit/RestAssured test run against the live provider (FreightContractBase)
 *
 * Response body uses matchers (anyNumber, anyPositiveInt, anyNonBlankString)
 * rather than exact values because the rate calculation is deterministic given
 * the inputs, but we want the contract to survive rate table updates without
 * breaking the contract test.  The TYPE of each field is what the consumer
 * depends on, not the exact value.
 */

import org.springframework.cloud.contract.spec.Contract

Contract.make {
    description "Should return freight rate for valid FEDEX shipment"

    request {
        method POST()
        url "/api/freight/rate"

        headers {
            contentType applicationJson()
        }

        body([
            // weightLbs: the ACL translates wt_lbs (monolith) -> weightLbs (service)
            weightLbs : 100.0,
            // originZip: 60601 = Chicago, IL — the most common origin in Northwind test data
            originZip : "60601",
            // destZip: 10001 = New York City, NY — cross-country route, exercises zone 5+
            destZip   : "10001",
            // carrier: FEDEX — the ACL maps legacy 'FDX' to this standard identifier
            carrier   : "FEDEX"
        ])
    }

    response {
        status OK()

        headers {
            contentType applicationJson()
        }

        body([
            // carrier echoed back so the ACL can verify routing correctness
            carrier       : "FEDEX",

            // zone is an integer (2–8); exact value depends on zip-distance calculation
            zone          : $(anyNumber()),

            // totalRate is the dollar amount (e.g. 47.82); converted to cents by ACL
            totalRate     : $(anyNumber()),

            // fuelSurcharge is itemised so callers can display it separately
            fuelSurcharge : $(anyNumber()),

            // estimatedDays is a positive integer; ACL maps this to est_days
            estimatedDays : $(anyPositiveInt())
        ])
    }
}
