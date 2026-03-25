/*
 * Spring Cloud Contract DSL
 * Contract: shouldReturn400ForInvalidCarrier.groovy
 *
 * Verifies that the freight service rejects requests that contain a carrier
 * identifier it does not recognise, returning HTTP 400 with an error body.
 *
 * WHY THIS CONTRACT EXISTS
 * ------------------------
 * The ACL in FreightServiceAdapter maps legacy carrier codes (FDX, UPS, USP, DHL)
 * to the service's standard identifiers (FEDEX, UPS, USPS, DHL) BEFORE sending
 * the request.  If the mapping table is wrong or a new legacy code is introduced
 * without a corresponding mapping entry, the adapter might forward an invalid
 * carrier value.  This contract pins the 400 behaviour so:
 *
 *   1. The provider test confirms the service rejects unmapped codes.
 *   2. The consumer stub confirms the ACL handles a 400 gracefully (fallback
 *      to FreightCalc rather than surfacing a raw HTTP error to the user).
 *
 * The "error" field in the response body is the minimum contract — the ACL only
 * reads this to log the rejection reason; it does not parse the message text.
 *
 * Consumer: PHP monolith FreightServiceAdapter (ACL)
 * Provider: northwind-freight-service Spring Boot app
 */

import org.springframework.cloud.contract.spec.Contract

Contract.make {
    description "Should return 400 Bad Request when carrier is not a recognised identifier"

    request {
        method POST()
        url "/api/freight/rate"

        headers {
            contentType applicationJson()
        }

        body([
            // All other fields are valid — the carrier alone causes the rejection.
            // This isolates carrier validation as the single failure mode tested here.
            weightLbs : 50.0,
            originZip : "60601",
            destZip   : "10001",
            // "INVALID" is never produced by the ACL's carrier map, but represents
            // any unknown string that could reach the service if the map is bypassed.
            carrier   : "INVALID"
        ])
    }

    response {
        // 400 Bad Request — the service must not attempt a rate calculation for
        // an unrecognised carrier; doing so would return a silently wrong rate.
        status BAD_REQUEST()

        headers {
            contentType applicationJson()
        }

        body([
            // "error" is the minimum field the contract requires.
            // The ACL logs this value; the exact message text is NOT part of
            // the contract (message wording may change with service updates).
            error: $(regex('.+'))
        ])
    }
}
