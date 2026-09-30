package com.ledgerplatform.reconciliation.infrastructure.persistence;

import jakarta.persistence.LockModeType;
import java.time.LocalDate;
import java.util.List;
import java.util.Optional;
import java.util.UUID;
import org.springframework.data.jpa.repository.JpaRepository;
import org.springframework.data.jpa.repository.Lock;
import org.springframework.data.jpa.repository.Query;
import org.springframework.data.repository.query.Param;

public interface RunJpaRepository extends JpaRepository<RunEntity, UUID> {

    /** Loads a run so that saving it always bumps and checks the version, even if only an item changed. */
    @Lock(LockModeType.OPTIMISTIC_FORCE_INCREMENT)
    @Query("select r from RunEntity r where r.id = :id")
    Optional<RunEntity> findForUpdate(@Param("id") UUID id);

    Optional<RunEntity> findByCurrencyAndPeriodFromAndPeriodToAndStatementSha256(
            String currency, LocalDate periodFrom, LocalDate periodTo, String statementSha256);

    List<RunEntity> findTop50ByOrderByCreatedAtDesc();

    List<RunEntity> findTop50ByCurrencyOrderByCreatedAtDesc(String currency);

    List<RunEntity> findTop50ByStatusOrderByCreatedAtDesc(String status);

    List<RunEntity> findTop50ByCurrencyAndStatusOrderByCreatedAtDesc(String currency, String status);
}
