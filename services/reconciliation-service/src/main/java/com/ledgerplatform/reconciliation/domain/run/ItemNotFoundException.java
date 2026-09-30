package com.ledgerplatform.reconciliation.domain.run;

import com.ledgerplatform.reconciliation.domain.DomainException;
import java.util.UUID;

public final class ItemNotFoundException extends DomainException {

    public ItemNotFoundException(UUID id) {
        super("Item %s is not part of this run.".formatted(id));
    }

    @Override
    public String code() {
        return "item-not-found";
    }
}
