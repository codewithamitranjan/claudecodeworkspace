package com.example.employeeservice.controller;

import com.example.employeeservice.dto.request.EmployeePatchRequest;
import com.example.employeeservice.dto.request.EmployeeRequest;
import com.example.employeeservice.dto.response.ApiResponse;
import com.example.employeeservice.dto.response.EmployeeResponse;
import com.example.employeeservice.service.EmployeeService;
import io.swagger.v3.oas.annotations.Operation;
import io.swagger.v3.oas.annotations.tags.Tag;
import jakarta.validation.Valid;
import lombok.RequiredArgsConstructor;
import org.springframework.data.domain.Page;
import org.springframework.data.domain.Pageable;
import org.springframework.data.web.PageableDefault;
import org.springframework.http.HttpStatus;
import org.springframework.http.ResponseEntity;
import org.springframework.web.bind.annotation.*;

import java.util.UUID;

@RestController
@RequestMapping("/api/v1/employees")
@RequiredArgsConstructor
@Tag(name = "Employee", description = "Employee management APIs")
public class EmployeeController {

    private final EmployeeService service;

    @PostMapping
    @Operation(summary = "Create a new employee")
    public ResponseEntity<ApiResponse<EmployeeResponse>> create(
            @Valid @RequestBody EmployeeRequest request) {
        EmployeeResponse response = service.create(request);
        return ResponseEntity
                .status(HttpStatus.CREATED)
                .body(ApiResponse.success("Employee created successfully", response));
    }

    @GetMapping
    @Operation(summary = "Get all employees (paginated)")
    public ResponseEntity<ApiResponse<Page<EmployeeResponse>>> findAll(
            @PageableDefault(size = 20, sort = "lastName") Pageable pageable) {
        return ResponseEntity.ok(ApiResponse.success(service.findAll(pageable)));
    }

    @GetMapping("/{id}")
    @Operation(summary = "Get employee by ID")
    public ResponseEntity<ApiResponse<EmployeeResponse>> findById(@PathVariable UUID id) {
        return ResponseEntity.ok(ApiResponse.success(service.findById(id)));
    }

    @PutMapping("/{id}")
    @Operation(summary = "Full update of an employee")
    public ResponseEntity<ApiResponse<EmployeeResponse>> update(
            @PathVariable UUID id,
            @Valid @RequestBody EmployeeRequest request) {
        return ResponseEntity.ok(
                ApiResponse.success("Employee updated successfully", service.update(id, request)));
    }

    @PatchMapping("/{id}")
    @Operation(summary = "Partial update of an employee")
    public ResponseEntity<ApiResponse<EmployeeResponse>> patch(
            @PathVariable UUID id,
            @Valid @RequestBody EmployeePatchRequest request) {
        return ResponseEntity.ok(
                ApiResponse.success("Employee patched successfully", service.patch(id, request)));
    }

    @DeleteMapping("/{id}")
    @Operation(summary = "Delete an employee")
    public ResponseEntity<ApiResponse<Void>> delete(@PathVariable UUID id) {
        service.delete(id);
        return ResponseEntity.ok(ApiResponse.success("Employee deleted successfully", null));
    }
}
