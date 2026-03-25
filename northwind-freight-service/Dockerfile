FROM eclipse-temurin:21-jre-alpine

# ----------------------------------------------------------------
# Non-root user for security hardening
# ----------------------------------------------------------------
RUN addgroup -S freight && adduser -S freight -G freight

WORKDIR /app

# Copy the fat-jar produced by spring-boot-maven-plugin
COPY target/freight-service-*.jar app.jar

# Own the working directory
RUN chown -R freight:freight /app

USER freight

EXPOSE 8080

# Use exec form so that SIGTERM is delivered directly to the JVM
# and Spring Boot's graceful-shutdown hook fires correctly.
ENTRYPOINT ["java", \
            "-XX:+UseContainerSupport", \
            "-XX:MaxRAMPercentage=75.0", \
            "-Djava.security.egd=file:/dev/./urandom", \
            "-jar", "app.jar"]
