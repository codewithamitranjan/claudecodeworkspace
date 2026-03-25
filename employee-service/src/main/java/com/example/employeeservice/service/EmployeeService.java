package com.example.employeeservice.service;

import com.example.employeeservice.dto.request.EmployeePatchRequest;
import com.example.employeeservice.dto.request.EmployeeRequest;
import com.example.employeeservice.dto.response.EmployeeResponse;
import org.springframework.data.domain.Page;
import org.springframework.data.domain.Pageable;

import java.util.UUID;

public interface EmployeeService {

    EmployeeResponse create(EmployeeRequest request);

    Page<EmployeeResponse> findAll(Pageable pageable);

    EmployeeResponse findById(UUID id);

    EmployeeResponse update(UUID id, EmployeeRequest request);

    EmployeeResponse patch(UUID id, EmployeePatchRequest request);

    void delete(UUID id);
}
