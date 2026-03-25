# Employee Service — Architecture Diagram

## System Architecture

```mermaid
graph TB
    subgraph Client["Client Layer"]
        C1[REST Client / Postman]
        C2[Swagger UI<br/>/swagger-ui.html]
        C3[Monitoring / Ops<br/>/actuator/*]
    end

    subgraph API["Presentation Layer — EmployeeController"]
        direction TB
        EP1["POST /api/v1/employees"]
        EP2["GET /api/v1/employees"]
        EP3["GET /api/v1/employees/{id}"]
        EP4["PUT /api/v1/employees/{id}"]
        EP5["PATCH /api/v1/employees/{id}"]
        EP6["DELETE /api/v1/employees/{id}"]
    end

    subgraph CrossCut["Cross-Cutting Concerns"]
        VAL["Bean Validation<br/>(@Valid)"]
        EXH["GlobalExceptionHandler<br/>(@RestControllerAdvice)"]
        MAP["EmployeeMapper<br/>(MapStruct)"]
        API_RESP["ApiResponse&lt;T&gt;<br/>Wrapper"]
    end

    subgraph SVC["Business Logic — EmployeeService"]
        direction TB
        S1["create()  →  duplicate email check"]
        S2["findAll()  →  paginated query"]
        S3["findById()  →  404 if missing"]
        S4["update()  →  full replace + email guard"]
        S5["patch()  →  null-safe partial update"]
        S6["delete()  →  404 if missing"]
    end

    subgraph Repo["Data Access Layer"]
        R["EmployeeRepository<br/>JpaRepository&lt;Employee, UUID&gt;"]
    end

    subgraph Entity["Domain Model"]
        E["Employee<br/>──────────────────<br/>id: UUID (PK)<br/>firstName: String<br/>lastName: String<br/>email: String (unique)<br/>department: String<br/>salary: BigDecimal<br/>createdAt: LocalDateTime<br/>updatedAt: LocalDateTime"]
    end

    subgraph DB["Database Layer"]
        PG[("PostgreSQL<br/>employeedb<br/>(production)")]
        H2[("H2 In-Memory<br/>employeedb<br/>(dev profile)")]
    end

    subgraph DTOs["Data Transfer Objects"]
        REQ["EmployeeRequest<br/>EmployeePatchRequest"]
        RESP["EmployeeResponse"]
    end

    subgraph Infra["Infrastructure / Config"]
        BOOT["Spring Boot 3.2.3<br/>Java 17"]
        JPA["Spring Data JPA<br/>+ Hibernate + @EnableJpaAuditing"]
        ACTUATOR["Spring Actuator<br/>health · info · metrics"]
        OPENAPI["SpringDoc OpenAPI 2.3.0<br/>/api-docs  ·  /swagger-ui.html"]
    end

    %% Client → API
    C1 -->|HTTP requests| API
    C2 -->|browser| OPENAPI
    C3 -->|HTTP| ACTUATOR

    %% API → Cross-cut
    API --> VAL
    API --> EXH
    API --> DTOs

    %% API → Service
    API --> SVC

    %% Service → Mapper + Repo
    SVC --> MAP
    SVC --> Repo

    %% Repo → Entity → DB
    Repo --> Entity
    Entity --> JPA
    JPA --> PG
    JPA -.->|dev profile| H2

    %% Mapper connects DTOs ↔ Entity
    MAP <-->|converts| DTOs
    MAP <-->|converts| Entity

    %% Response path
    SVC --> API_RESP
    API_RESP -->|JSON response| Client

    %% Styling
    classDef layer fill:#1e3a5f,stroke:#4a90d9,color:#fff
    classDef db fill:#2d5a27,stroke:#5cb85c,color:#fff
    classDef dto fill:#4a3728,stroke:#c07850,color:#fff
    classDef infra fill:#3b2d5a,stroke:#9b59b6,color:#fff
    classDef cross fill:#5a3a1a,stroke:#e67e22,color:#fff

    class API,SVC,Repo layer
    class PG,H2 db
    class DTOs,REQ,RESP dto
    class Infra,BOOT,JPA,ACTUATOR,OPENAPI infra
    class CrossCut,VAL,EXH,MAP,API_RESP cross
```

---

## Request / Response Flow

```mermaid
sequenceDiagram
    participant Client
    participant Controller as EmployeeController
    participant Validator as Bean Validation
    participant Service as EmployeeServiceImpl
    participant Mapper as EmployeeMapper
    participant Repo as EmployeeRepository
    participant DB as PostgreSQL / H2

    Client->>Controller: HTTP Request (e.g. POST /api/v1/employees)
    Controller->>Validator: @Valid — validate EmployeeRequest
    alt Validation fails
        Validator-->>Controller: ConstraintViolationException
        Controller-->>Client: 400 Bad Request (ApiResponse with errors)
    end
    Controller->>Service: create(EmployeeRequest)
    Service->>Repo: existsByEmail(email)
    alt Email exists
        Repo-->>Service: true
        Service-->>Controller: DuplicateEmailException
        Controller-->>Client: 409 Conflict
    end
    Service->>Mapper: toEntity(EmployeeRequest)
    Mapper-->>Service: Employee entity
    Service->>Repo: save(employee)
    Repo->>DB: INSERT INTO employees ...
    DB-->>Repo: saved Employee (with generated UUID + timestamps)
    Repo-->>Service: Employee
    Service->>Mapper: toResponse(employee)
    Mapper-->>Service: EmployeeResponse
    Service-->>Controller: EmployeeResponse
    Controller-->>Client: 201 Created — ApiResponse<EmployeeResponse>
```

---

## Component Overview

| Layer | Class | Responsibility |
|-------|-------|----------------|
| **Controller** | `EmployeeController` | HTTP routing, request validation, OpenAPI docs |
| **Service Interface** | `EmployeeService` | Contract for business operations |
| **Service Impl** | `EmployeeServiceImpl` | Business logic, transactions, email uniqueness |
| **Repository** | `EmployeeRepository` | Spring Data JPA — CRUD + custom queries |
| **Entity** | `Employee` | JPA entity, audit timestamps |
| **DTOs** | `EmployeeRequest/Response` | Input/output contracts |
| **Mapper** | `EmployeeMapper` | MapStruct — Entity ↔ DTO conversion |
| **Exception Handler** | `GlobalExceptionHandler` | Centralized error responses |
| **Config** | `application.yml` | Profiles (dev/prod), DB, actuator, logging |

---

## Technology Stack

| Concern | Technology |
|---------|-----------|
| Language | Java 17 |
| Framework | Spring Boot 3.2.3 |
| Web | Spring MVC (REST) |
| Persistence | Spring Data JPA + Hibernate |
| Database (prod) | PostgreSQL 5432 |
| Database (dev) | H2 In-Memory |
| Validation | Jakarta Bean Validation |
| Mapping | MapStruct 1.5.5 + Lombok |
| API Docs | SpringDoc OpenAPI 2.3.0 |
| Monitoring | Spring Actuator |
| Build | Maven |
| Testing | JUnit 5 + Mockito |
