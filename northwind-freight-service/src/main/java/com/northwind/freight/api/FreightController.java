package com.northwind.freight.api;

import com.northwind.freight.api.dto.FreightRateRequest;
import com.northwind.freight.api.dto.FreightRateResponse;
import com.northwind.freight.domain.CarrierRates;
import com.northwind.freight.domain.FreightRatingService;
import jakarta.validation.Valid;
import org.springframework.http.ResponseEntity;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.RequestBody;
import org.springframework.web.bind.annotation.RequestMapping;
import org.springframework.web.bind.annotation.RestController;

import java.util.List;

/**
 * REST controller exposing freight rating operations.
 *
 * <p>Endpoints:
 * <ul>
 *   <li>{@code POST /api/freight/rate}     — calculate a shipping rate</li>
 *   <li>{@code GET  /api/freight/carriers} — list supported carrier codes</li>
 *   <li>{@code GET  /actuator/health}      — liveness / readiness (Spring Actuator)</li>
 * </ul>
 *
 * <p>The controller is deliberately thin: all business logic lives in
 * {@link FreightRatingService}.  The controller's only responsibilities are
 * HTTP binding, validation triggering, and response wrapping.
 */
@RestController
@RequestMapping("/api/freight")
public class FreightController {

    private final FreightRatingService ratingService;

    public FreightController(FreightRatingService ratingService) {
        this.ratingService = ratingService;
    }

    // -------------------------------------------------------------------------
    // POST /api/freight/rate
    // -------------------------------------------------------------------------

    /**
     * Calculate a freight rate for a single shipment.
     *
     * <p>Request body is validated via Bean Validation ({@code @Valid}).  Constraint
     * violations are translated to 400 responses by
     * {@link com.northwind.freight.exception.GlobalExceptionHandler}.
     *
     * @param request shipment details
     * @return 200 with the calculated rate, or 400/500 on error
     */
    @PostMapping("/rate")
    public ResponseEntity<FreightRateResponse> calculateRate(
            @Valid @RequestBody FreightRateRequest request) {

        FreightRateResponse response = ratingService.calculateRate(request);
        return ResponseEntity.ok(response);
    }

    // -------------------------------------------------------------------------
    // GET /api/freight/carriers
    // -------------------------------------------------------------------------

    /**
     * Return the list of supported carrier codes.
     *
     * <p>Useful for UI drop-downs and for consumer contract verification.
     *
     * @return 200 with a JSON array of carrier code strings
     */
    @GetMapping("/carriers")
    public ResponseEntity<List<String>> listCarriers() {
        return ResponseEntity.ok(CarrierRates.SUPPORTED_CARRIERS);
    }
}
