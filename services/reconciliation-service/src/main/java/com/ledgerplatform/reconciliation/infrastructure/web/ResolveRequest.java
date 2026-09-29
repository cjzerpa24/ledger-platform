package com.ledgerplatform.reconciliation.infrastructure.web;

import com.ledgerplatform.reconciliation.domain.run.ResolutionKind;
import jakarta.validation.constraints.NotNull;
import jakarta.validation.constraints.Size;
import java.util.UUID;

public record ResolveRequest(
        @NotNull ResolutionKind resolution,
        UUID counterpartItemId,
        @Size(max = 500) String note) {}
