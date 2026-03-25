package com.northwind.freight.api.dto;

import jakarta.validation.constraints.DecimalMax;
import jakarta.validation.constraints.DecimalMin;
import jakarta.validation.constraints.NotBlank;
import jakarta.validation.constraints.NotNull;
import jakarta.validation.constraints.Pattern;
import lombok.Builder;
import lombok.Data;

/**
 * Inbound payload for {@code POST /api/freight/rate}.
 *
 * <p>Constraints mirror the PHP {@code validate_request()} guard in {@code FreightCalc.php}:
 * weight is capped at 70,000 lb (max FedEx freight), ZIP codes must be exactly 5 ASCII digits,
 * and carrier must be one of the four supported values.
 */
@Data
@Builder
public class FreightRateRequest {

    /**
     * Shipment weight in pounds.
     * Min 0.1 lb (envelope); max 70,000 lb (full freight pallet stack).
     */
    @NotNull(message = "weightLbs is required")
    @DecimalMin(value = "0.1", message = "weightLbs must be at least 0.1")
    @DecimalMax(value = "70000.0", message = "weightLbs must not exceed 70000")
    private Double weightLbs;

    /** 5-digit US ZIP code for the shipment origin. */
    @NotBlank(message = "originZip is required")
    @Pattern(regexp = "\\d{5}", message = "originZip must be exactly 5 digits")
    private String originZip;

    /** 5-digit US ZIP code for the shipment destination. */
    @NotBlank(message = "destZip is required")
    @Pattern(regexp = "\\d{5}", message = "destZip must be exactly 5 digits")
    private String destZip;

    /**
     * Carrier code.  Must be one of {@code FEDEX}, {@code UPS}, {@code USPS}, {@code DHL}.
     * Validated programmatically in {@link com.northwind.freight.domain.FreightRatingService}
     * so that the error message references the actual unsupported value.
     */
    @NotBlank(message = "carrier is required")
    @Pattern(regexp = "FEDEX|UPS|USPS|DHL",
             message = "carrier must be one of: FEDEX, UPS, USPS, DHL")
    private String carrier;
}
