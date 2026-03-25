package com.northwind.tracking.api.dto;

import jakarta.validation.constraints.NotBlank;
import lombok.*;

/**
 * Request body for the manual poll endpoint.
 * Carrier is optional — if omitted, the service will auto-detect it
 * from the tracking number format (porting the PHP regex logic).
 */
@Getter
@Setter
@NoArgsConstructor
@AllArgsConstructor
public class PollRequest {

    @NotBlank(message = "trackingNumber is required")
    private String trackingNumber;

    /** Optional. When blank, carrier is auto-detected from trackingNumber. */
    private String carrier;
}
