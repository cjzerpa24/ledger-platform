package com.ledgerplatform.reconciliation.domain.run;

import com.ledgerplatform.reconciliation.domain.DomainException;
import java.util.UUID;

public final class ItemAlreadyResolvedException extends DomainException {

    public ItemAlreadyResolvedException(UUID id) {
        super("Item %s is not an open exception.".formatted(id));
    }

    @Override
    public String code() {
        return "item-already-resolved";
    }
}
