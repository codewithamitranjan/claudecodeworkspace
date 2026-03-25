package com.northwind.freight.exception;

import org.springframework.http.HttpStatus;
import org.springframework.http.ResponseEntity;
import org.springframework.validation.FieldError;
import org.springframework.web.bind.MethodArgumentNotValidException;
import org.springframework.web.bind.annotation.ExceptionHandler;
import org.springframework.web.bind.annotation.RestControllerAdvice;

import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

/**
 * Centralised HTTP error handling for the freight service.
 *
 * <p>All exceptions that escape the controller layer are caught here and mapped
 * to a consistent JSON error envelope:
 * <pre>
 * {
 *   "error": "human-readable message",
 *   "field": "fieldName"   // present only for field-level validation errors
 * }
 * </pre>
 *
 * <p>For multi-field validation failures the response body contains an
 * {@code "errors"} array instead of a single {@code "field"} entry:
 * <pre>
 * {
 *   "error": "Validation failed",
 *   "errors": [
 *     {"field": "weightLbs", "message": "weightLbs must be at least 0.1"},
 *     {"field": "carrier",   "message": "carrier must be one of: FEDEX, UPS, USPS, DHL"}
 *   ]
 * }
 * </pre>
 */
@RestControllerAdvice
public class GlobalExceptionHandler {

    // -------------------------------------------------------------------------
    // Bean Validation failures (400)
    // -------------------------------------------------------------------------

    /**
     * Handle {@link MethodArgumentNotValidException} thrown by {@code @Valid} on
     * controller method parameters.  Returns 400 with structured field-level errors.
     */
    @ExceptionHandler(MethodArgumentNotValidException.class)
    public ResponseEntity<Map<String, Object>> handleValidationException(
            MethodArgumentNotValidException ex) {

        List<Map<String, String>> fieldErrors = ex.getBindingResult()
                .getFieldErrors()
                .stream()
                .map(GlobalExceptionHandler::toFieldErrorMap)
                .toList();

        Map<String, Object> body = new LinkedHashMap<>();

        if (fieldErrors.size() == 1) {
            // Single-field variant — keep API surface minimal for simple cases
            FieldError fe = ex.getBindingResult().getFieldErrors().get(0);
            body.put("error", fe.getDefaultMessage());
            body.put("field", fe.getField());
        } else {
            body.put("error", "Validation failed");
            body.put("errors", fieldErrors);
        }

        return ResponseEntity.status(HttpStatus.BAD_REQUEST).body(body);
    }

    // -------------------------------------------------------------------------
    // Domain / argument errors (400)
    // -------------------------------------------------------------------------

    /**
     * Handle {@link IllegalArgumentException} raised by the service layer
     * (e.g. unsupported carrier that slipped past Bean Validation).
     */
    @ExceptionHandler(IllegalArgumentException.class)
    public ResponseEntity<Map<String, Object>> handleIllegalArgument(
            IllegalArgumentException ex) {

        Map<String, Object> body = new LinkedHashMap<>();
        body.put("error", ex.getMessage());
        return ResponseEntity.status(HttpStatus.BAD_REQUEST).body(body);
    }

    // -------------------------------------------------------------------------
    // Catch-all (500)
    // -------------------------------------------------------------------------

    /**
     * Catch any unhandled exception and return a generic 500.
     * The raw exception message is <em>not</em> forwarded to the client to avoid
     * leaking internal details; structured logging should capture the stack trace.
     */
    @ExceptionHandler(Exception.class)
    public ResponseEntity<Map<String, Object>> handleGenericException(Exception ex) {
        Map<String, Object> body = new LinkedHashMap<>();
        body.put("error", "An internal error occurred. Please try again later.");
        // In production, correlate with a request-ID header for tracing.
        return ResponseEntity.status(HttpStatus.INTERNAL_SERVER_ERROR).body(body);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private static Map<String, String> toFieldErrorMap(FieldError fe) {
        Map<String, String> m = new LinkedHashMap<>();
        m.put("field",   fe.getField());
        m.put("message", fe.getDefaultMessage());
        return m;
    }
}
