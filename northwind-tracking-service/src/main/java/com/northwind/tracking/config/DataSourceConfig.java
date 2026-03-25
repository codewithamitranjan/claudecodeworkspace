package com.northwind.tracking.config;

import com.zaxxer.hikari.HikariConfig;
import com.zaxxer.hikari.HikariDataSource;
import lombok.extern.slf4j.Slf4j;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.boot.autoconfigure.jdbc.DataSourceProperties;
import org.springframework.boot.context.properties.ConfigurationProperties;
import org.springframework.boot.autoconfigure.condition.ConditionalOnProperty;
import org.springframework.context.annotation.Bean;
import org.springframework.context.annotation.Configuration;
import org.springframework.context.annotation.Primary;

import javax.sql.DataSource;

/**
 * DataSource configuration.
 *
 * <h3>Primary DataSource (Postgres)</h3>
 * Configured from {@code spring.datasource.*} — used for all JPA/Flyway
 * operations. This is the service's own authoritative store.
 *
 * <h3>Legacy MySQL DataSource</h3>
 * Configured from {@code legacy.mysql.*} — only created when
 * {@code DUAL_WRITE_ENABLED=true} (which maps to {@code dual-write.enabled=true}).
 * Used exclusively by {@link com.northwind.tracking.domain.DualWriteAdapter}
 * to mirror writes into the PHP monolith's MySQL database during the migration.
 *
 * When {@code DUAL_WRITE_ENABLED=false} the bean is never registered, so the
 * MySQL driver and HikariCP pool are never initialised — zero overhead post-migration.
 */
@Configuration
@Slf4j
public class DataSourceConfig {

    // -------------------------------------------------------------------------
    // Primary Postgres DataSource
    // Spring Boot JPA / Flyway autoconfigure picks this up via @Primary.
    // -------------------------------------------------------------------------

    @Bean
    @Primary
    @ConfigurationProperties("spring.datasource")
    public DataSourceProperties primaryDataSourceProperties() {
        return new DataSourceProperties();
    }

    @Bean
    @Primary
    public DataSource dataSource(DataSourceProperties primaryDataSourceProperties) {
        return primaryDataSourceProperties
                .initializeDataSourceBuilder()
                .build();
    }

    // -------------------------------------------------------------------------
    // Legacy MySQL DataSource — only present during the migration window.
    //
    // @ConditionalOnProperty means the bean (and its HikariCP pool) is never
    // created unless dual-write.enabled=true.  DualWriteAdapter injects this
    // with @Autowired(required=false) so it handles the absent-bean case.
    // -------------------------------------------------------------------------

    @Bean(name = "legacyMysqlDataSource")
    @ConditionalOnProperty(name = "dual-write.enabled", havingValue = "true", matchIfMissing = true)
    public DataSource legacyMysqlDataSource(
            @Value("${legacy.mysql.url:jdbc:mysql://localhost:3306/northwind}") String url,
            @Value("${legacy.mysql.username:root}")                             String username,
            @Value("${legacy.mysql.password:root}")                             String password) {

        log.info("[DUAL-WRITE] ENABLED — creating legacy MySQL datasource url={}", url);

        HikariConfig cfg = new HikariConfig();
        cfg.setJdbcUrl(url);
        cfg.setUsername(username);
        cfg.setPassword(password);
        cfg.setDriverClassName("com.mysql.cj.jdbc.Driver");
        cfg.setPoolName("legacy-mysql-pool");
        cfg.setMaximumPoolSize(5);          // small pool — dual-write is a secondary path
        cfg.setConnectionTimeout(3_000);    // fail fast if MySQL is unreachable
        cfg.setValidationTimeout(2_000);
        cfg.setConnectionTestQuery("SELECT 1");

        return new HikariDataSource(cfg);
    }
}
