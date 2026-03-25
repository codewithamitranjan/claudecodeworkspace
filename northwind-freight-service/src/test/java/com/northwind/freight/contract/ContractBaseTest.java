package com.northwind.freight.contract;

import com.northwind.freight.api.FreightController;
import com.northwind.freight.domain.FreightRatingService;
import io.restassured.module.mockmvc.RestAssuredMockMvc;
import org.junit.jupiter.api.BeforeEach;
import org.springframework.beans.factory.annotation.Autowired;
import org.springframework.boot.test.context.SpringBootTest;
import org.springframework.test.context.TestPropertySource;

/**
 * Base class for Spring Cloud Contract-generated tests.
 *
 * <p>The {@code spring-cloud-contract-maven-plugin} generates test classes that
 * extend this base class.  It is responsible for setting up the RestAssured
 * MockMvc context so that the generated tests can call the controllers without
 * starting a full HTTP server.
 *
 * <p>The same datasource overrides used in
 * {@link com.northwind.freight.api.FreightControllerIntegrationTest} are applied
 * here so that contract tests also run without a PostgreSQL instance.
 */
@SpringBootTest
@TestPropertySource(properties = {
        "spring.jpa.hibernate.ddl-auto=none",
        "spring.flyway.enabled=false",
        "spring.datasource.url=jdbc:h2:mem:contractdb;DB_CLOSE_DELAY=-1",
        "spring.datasource.driver-class-name=org.h2.Driver",
        "spring.datasource.username=sa",
        "spring.datasource.password=",
        "spring.jpa.database-platform=org.hibernate.dialect.H2Dialect"
})
public abstract class ContractBaseTest {

    @Autowired
    private FreightController freightController;

    @Autowired
    private FreightRatingService freightRatingService;

    @BeforeEach
    public void setUp() {
        RestAssuredMockMvc.standaloneSetup(freightController);
    }
}
