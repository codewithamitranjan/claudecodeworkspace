package com.example.employeeservice.dto.response;

import lombok.Data;

import java.math.BigDecimal;
import java.time.LocalDateTime;
import java.util.UUID;

@Data
public class EmployeeResponse {
    private UUID id;
    private String firstName;
    private String lastName;
    private String email;
    private String department;
    private BigDecimal salary;
    private LocalDateTime createdAt;
    private LocalDateTime updatedAt;
}
