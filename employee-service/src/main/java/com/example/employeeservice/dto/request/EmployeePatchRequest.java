package com.example.employeeservice.dto.request;

import jakarta.validation.constraints.*;
import lombok.Data;

import java.math.BigDecimal;

/**
 * Used for PATCH — all fields optional; only non-null fields are applied.
 */
@Data
public class EmployeePatchRequest {

    @Size(max = 100, message = "First name must not exceed 100 characters")
    private String firstName;

    @Size(max = 100, message = "Last name must not exceed 100 characters")
    private String lastName;

    @Email(message = "Email must be a valid email address")
    @Size(max = 150, message = "Email must not exceed 150 characters")
    private String email;

    @Size(max = 100, message = "Department must not exceed 100 characters")
    private String department;

    @DecimalMin(value = "0.0", inclusive = false, message = "Salary must be greater than 0")
    @Digits(integer = 10, fraction = 2, message = "Salary format invalid")
    private BigDecimal salary;
}
