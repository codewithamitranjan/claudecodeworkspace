/*
 * Spring Cloud Contract DSL
 * Contract: shouldListCarriers.groovy
 *
 * Verifies that GET /api/freight/carriers always returns the four supported
 * carrier codes.  The PHP ACL uses this endpoint at startup to validate its
 * carrier-mapping table against the service's current capability set.
 *
 * Consumer: PHP monolith FreightServiceAdapter (ACL)
 * Provider: northwind-freight-service Spring Boot app
 */

import org.springframework.cloud.contract.spec.Contract

Contract.make {
    description "Should return list of 4 supported carrier codes"

    request {
        method GET()
        url "/api/freight/carriers"
    }

    response {
        status OK()
        headers {
            contentType applicationJson()
        }
        // Exact carrier list — the ACL's mapping table is hard-coded against these values
        body(["FEDEX", "UPS", "USPS", "DHL"])
    }
}
