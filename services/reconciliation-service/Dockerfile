# syntax=docker/dockerfile:1

FROM eclipse-temurin:21-jdk-alpine AS deps
ARG UID=1000
ARG GID=1000
RUN addgroup -g "${GID}" app && adduser -D -u "${UID}" -G app app && mkdir -p /home/app/.m2 && chown -R app:app /home/app
WORKDIR /app
RUN chown app:app /app
USER app
COPY --chown=app:app mvnw pom.xml ./
COPY --chown=app:app .mvn .mvn
RUN ./mvnw -B -q dependency:go-offline

# Local development: the source is bind-mounted over /app and ~/.m2 lives in a named volume.
FROM deps AS dev
COPY --chown=app:app . .
EXPOSE 8080
CMD ["./mvnw", "-B", "spring-boot:run"]

FROM deps AS build
COPY --chown=app:app checkstyle.xml ./
COPY --chown=app:app src src
RUN ./mvnw -B -q -DskipTests -Dspotless.check.skip=true -Dcheckstyle.skip=true package \
    && java -Djarmode=tools -jar target/app.jar extract --destination target/extracted

FROM eclipse-temurin:21-jre-alpine AS runtime
RUN addgroup -S app && adduser -S -G app app
WORKDIR /app
COPY --from=build --chown=app:app /app/target/extracted/ ./
USER app
EXPOSE 8080
HEALTHCHECK --interval=10s --timeout=3s --retries=6 \
  CMD wget -qO- http://localhost:8080/actuator/health/liveness >/dev/null || exit 1
ENTRYPOINT ["java", "-jar", "app.jar"]
