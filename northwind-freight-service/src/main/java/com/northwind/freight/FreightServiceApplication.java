package com.northwind.freight;

import org.springframework.boot.SpringApplication;
import org.springframework.boot.autoconfigure.SpringBootApplication;

/**
 * Entry point for the Northwind Freight Rating microservice.
 *
 * <p>This service is the first component extracted from the PHP 5 monolith using the
 * Strangler Fig pattern. It owns the freight-rate calculation domain and exposes a
 * REST API consumed by the monolith (via an HTTP facade) as well as by new clients.
 *
 * <p>Porting notes:
 * <ul>
 *   <li>Rate logic mirrors {@code FreightCalc.php} exactly, including the Florida (320-349)
 *       zone-4 bug — intentional, pending a separate backlog item.</li>
 *   <li>Fuel-surcharge rate (8.5 %) is kept in {@code FreightRatingService} as a named
 *       constant so it can be promoted to a config property later.</li>
 * </ul>
 */
@SpringBootApplication
public class FreightServiceApplication {

    public static void main(String[] args) {
        SpringApplication.run(FreightServiceApplication.class, args);
    }
}
