package com.ledgerplatform.reconciliation.infrastructure.web;

import java.util.List;

public record ListResponse<T>(List<T> items) {}
