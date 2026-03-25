package com.example.employeeservice;

import com.example.employeeservice.config.EmployeeMapper;
import com.example.employeeservice.dto.request.EmployeeRequest;
import com.example.employeeservice.dto.response.EmployeeResponse;
import com.example.employeeservice.entity.Employee;
import com.example.employeeservice.exception.DuplicateEmailException;
import com.example.employeeservice.exception.ResourceNotFoundException;
import com.example.employeeservice.repository.EmployeeRepository;
import com.example.employeeservice.service.impl.EmployeeServiceImpl;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.extension.ExtendWith;
import org.mockito.InjectMocks;
import org.mockito.Mock;
import org.mockito.junit.jupiter.MockitoExtension;

import java.math.BigDecimal;
import java.util.Optional;
import java.util.UUID;

import static org.assertj.core.api.Assertions.*;
import static org.mockito.ArgumentMatchers.any;
import static org.mockito.Mockito.*;

@ExtendWith(MockitoExtension.class)
class EmployeeServiceTest {

    @Mock EmployeeRepository repository;
    @Mock EmployeeMapper mapper;
    @InjectMocks EmployeeServiceImpl service;

    @Test
    void create_success() {
        EmployeeRequest req = request();
        Employee entity = new Employee();
        EmployeeResponse resp = new EmployeeResponse();

        when(repository.existsByEmail(req.getEmail())).thenReturn(false);
        when(mapper.toEntity(req)).thenReturn(entity);
        when(repository.save(entity)).thenReturn(entity);
        when(mapper.toResponse(entity)).thenReturn(resp);

        EmployeeResponse result = service.create(req);

        assertThat(result).isSameAs(resp);
        verify(repository).save(entity);
    }

    @Test
    void create_duplicateEmail_throws() {
        EmployeeRequest req = request();
        when(repository.existsByEmail(req.getEmail())).thenReturn(true);

        assertThatThrownBy(() -> service.create(req))
                .isInstanceOf(DuplicateEmailException.class);
        verify(repository, never()).save(any());
    }

    @Test
    void findById_notFound_throws() {
        UUID id = UUID.randomUUID();
        when(repository.findById(id)).thenReturn(Optional.empty());

        assertThatThrownBy(() -> service.findById(id))
                .isInstanceOf(ResourceNotFoundException.class);
    }

    @Test
    void delete_success() {
        UUID id = UUID.randomUUID();
        Employee entity = new Employee();
        when(repository.findById(id)).thenReturn(Optional.of(entity));

        service.delete(id);

        verify(repository).delete(entity);
    }

    private EmployeeRequest request() {
        EmployeeRequest r = new EmployeeRequest();
        r.setFirstName("John");
        r.setLastName("Doe");
        r.setEmail("john.doe@example.com");
        r.setDepartment("Engineering");
        r.setSalary(BigDecimal.valueOf(75000));
        return r;
    }
}
