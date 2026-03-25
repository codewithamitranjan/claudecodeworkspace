package com.example.employeeservice.config;

import com.example.employeeservice.dto.request.EmployeeRequest;
import com.example.employeeservice.dto.response.EmployeeResponse;
import com.example.employeeservice.entity.Employee;
import org.mapstruct.*;

@Mapper(componentModel = "spring", unmappedTargetPolicy = ReportingPolicy.IGNORE)
public interface EmployeeMapper {

    Employee toEntity(EmployeeRequest request);

    EmployeeResponse toResponse(Employee employee);

    @BeanMapping(nullValuePropertyMappingStrategy = NullValuePropertyMappingStrategy.IGNORE)
    void updateEntityFromRequest(EmployeeRequest request, @MappingTarget Employee employee);

    /**
     * Partial update — skips null fields (used for PATCH).
     */
    @BeanMapping(nullValuePropertyMappingStrategy = NullValuePropertyMappingStrategy.IGNORE)
    void patchEntityFromRequest(Object patchRequest, @MappingTarget Employee employee);
}
