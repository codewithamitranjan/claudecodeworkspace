package com.example.employeeservice.service.impl;

import com.example.employeeservice.config.EmployeeMapper;
import com.example.employeeservice.dto.request.EmployeePatchRequest;
import com.example.employeeservice.dto.request.EmployeeRequest;
import com.example.employeeservice.dto.response.EmployeeResponse;
import com.example.employeeservice.entity.Employee;
import com.example.employeeservice.exception.DuplicateEmailException;
import com.example.employeeservice.exception.ResourceNotFoundException;
import com.example.employeeservice.repository.EmployeeRepository;
import com.example.employeeservice.service.EmployeeService;
import lombok.RequiredArgsConstructor;
import lombok.extern.slf4j.Slf4j;
import org.springframework.data.domain.Page;
import org.springframework.data.domain.Pageable;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;

import java.util.UUID;

@Service
@RequiredArgsConstructor
@Slf4j
@Transactional(readOnly = true)
public class EmployeeServiceImpl implements EmployeeService {

    private final EmployeeRepository repository;
    private final EmployeeMapper mapper;

    @Override
    @Transactional
    public EmployeeResponse create(EmployeeRequest request) {
        if (repository.existsByEmail(request.getEmail())) {
            throw new DuplicateEmailException(request.getEmail());
        }
        Employee employee = mapper.toEntity(request);
        Employee saved = repository.save(employee);
        log.info("Created employee with id={}", saved.getId());
        return mapper.toResponse(saved);
    }

    @Override
    public Page<EmployeeResponse> findAll(Pageable pageable) {
        return repository.findAll(pageable).map(mapper::toResponse);
    }

    @Override
    public EmployeeResponse findById(UUID id) {
        return mapper.toResponse(findOrThrow(id));
    }

    @Override
    @Transactional
    public EmployeeResponse update(UUID id, EmployeeRequest request) {
        Employee employee = findOrThrow(id);
        if (repository.existsByEmailAndIdNot(request.getEmail(), id)) {
            throw new DuplicateEmailException(request.getEmail());
        }
        mapper.updateEntityFromRequest(request, employee);
        Employee saved = repository.save(employee);
        log.info("Updated employee id={}", id);
        return mapper.toResponse(saved);
    }

    @Override
    @Transactional
    public EmployeeResponse patch(UUID id, EmployeePatchRequest request) {
        Employee employee = findOrThrow(id);
        if (request.getEmail() != null && repository.existsByEmailAndIdNot(request.getEmail(), id)) {
            throw new DuplicateEmailException(request.getEmail());
        }
        // Apply only non-null fields manually (safe for PATCH)
        if (request.getFirstName() != null)  employee.setFirstName(request.getFirstName());
        if (request.getLastName() != null)   employee.setLastName(request.getLastName());
        if (request.getEmail() != null)      employee.setEmail(request.getEmail());
        if (request.getDepartment() != null) employee.setDepartment(request.getDepartment());
        if (request.getSalary() != null)     employee.setSalary(request.getSalary());

        Employee saved = repository.save(employee);
        log.info("Patched employee id={}", id);
        return mapper.toResponse(saved);
    }

    @Override
    @Transactional
    public void delete(UUID id) {
        Employee employee = findOrThrow(id);
        repository.delete(employee);
        log.info("Deleted employee id={}", id);
    }

    private Employee findOrThrow(UUID id) {
        return repository.findById(id)
                .orElseThrow(() -> new ResourceNotFoundException("Employee", id));
    }
}
