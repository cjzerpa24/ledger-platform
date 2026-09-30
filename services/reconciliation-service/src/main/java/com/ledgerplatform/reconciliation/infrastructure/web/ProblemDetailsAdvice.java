package com.ledgerplatform.reconciliation.infrastructure.web;

import com.ledgerplatform.reconciliation.application.port.InvalidStatementException;
import com.ledgerplatform.reconciliation.application.port.LedgerUnavailableException;
import com.ledgerplatform.reconciliation.domain.DomainException;
import com.ledgerplatform.reconciliation.domain.run.ConcurrentUpdateException;
import com.ledgerplatform.reconciliation.domain.run.DuplicateStatementException;
import com.ledgerplatform.reconciliation.domain.run.InvalidManualMatchException;
import com.ledgerplatform.reconciliation.domain.run.ItemAlreadyResolvedException;
import com.ledgerplatform.reconciliation.domain.run.ItemNotFoundException;
import com.ledgerplatform.reconciliation.domain.run.RunNotFoundException;
import java.net.URI;
import java.util.List;
import java.util.Map;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.http.HttpStatus;
import org.springframework.http.ProblemDetail;
import org.springframework.http.converter.HttpMessageNotReadableException;
import org.springframework.web.HttpMediaTypeNotSupportedException;
import org.springframework.web.HttpRequestMethodNotSupportedException;
import org.springframework.web.bind.MethodArgumentNotValidException;
import org.springframework.web.bind.MissingServletRequestParameterException;
import org.springframework.web.bind.annotation.ExceptionHandler;
import org.springframework.web.bind.annotation.RestControllerAdvice;
import org.springframework.web.method.annotation.MethodArgumentTypeMismatchException;
import org.springframework.web.multipart.MaxUploadSizeExceededException;
import org.springframework.web.multipart.MultipartException;
import org.springframework.web.multipart.support.MissingServletRequestPartException;
import org.springframework.web.servlet.resource.NoResourceFoundException;

/** Renders every error as RFC 9457 application/problem+json. */
@RestControllerAdvice
public class ProblemDetailsAdvice {

    private static final Logger LOG = LoggerFactory.getLogger(ProblemDetailsAdvice.class);
    private static final String TYPE_PREFIX = "urn:reconciliation:problem:";

    /** Domain errors not listed here are business-rule violations: 422. */
    private static final Map<Class<? extends DomainException>, HttpStatus> DOMAIN_STATUS = Map.of(
            RunNotFoundException.class, HttpStatus.NOT_FOUND,
            ItemNotFoundException.class, HttpStatus.NOT_FOUND,
            ItemAlreadyResolvedException.class, HttpStatus.CONFLICT,
            InvalidManualMatchException.class, HttpStatus.CONFLICT,
            DuplicateStatementException.class, HttpStatus.CONFLICT,
            ConcurrentUpdateException.class, HttpStatus.CONFLICT);

    record FieldError(String field, String message) {}

    @ExceptionHandler(DomainException.class)
    ProblemDetail domain(DomainException e) {
        if ("validation-failed".equals(e.code())) {
            return validation(List.of(new FieldError(null, e.getMessage())));
        }
        ProblemDetail problem = problem(
                DOMAIN_STATUS.getOrDefault(e.getClass(), HttpStatus.UNPROCESSABLE_ENTITY), e.code(), e.getMessage());
        if (e instanceof DuplicateStatementException duplicate) {
            problem.setProperty("existingRunId", duplicate.existingRunId());
        }
        return problem;
    }

    @ExceptionHandler(InvalidStatementException.class)
    ProblemDetail invalidStatement(InvalidStatementException e) {
        ProblemDetail problem = problem(HttpStatus.UNPROCESSABLE_ENTITY, "invalid-statement", e.getMessage());
        problem.setProperty("errors", e.errors());
        return problem;
    }

    @ExceptionHandler(LedgerUnavailableException.class)
    ProblemDetail ledgerUnavailable(LedgerUnavailableException e) {
        LOG.warn("Ledger unavailable: {}", e.getMessage());
        return problem(HttpStatus.BAD_GATEWAY, "ledger-unavailable", "The ledger could not be read: " + e.getMessage());
    }

    @ExceptionHandler({StatementTooLargeException.class, MaxUploadSizeExceededException.class})
    ProblemDetail tooLarge() {
        return problem(HttpStatus.PAYLOAD_TOO_LARGE, "statement-too-large", "A statement may be at most 5 MB.");
    }

    @ExceptionHandler(MethodArgumentNotValidException.class)
    ProblemDetail invalidBody(MethodArgumentNotValidException e) {
        return validation(e.getBindingResult().getFieldErrors().stream()
                .map(error -> new FieldError(error.getField(), error.getDefaultMessage()))
                .toList());
    }

    @ExceptionHandler(MissingServletRequestParameterException.class)
    ProblemDetail missingParameter(MissingServletRequestParameterException e) {
        return validation(List.of(new FieldError(e.getParameterName(), "is required")));
    }

    @ExceptionHandler(MissingServletRequestPartException.class)
    ProblemDetail missingPart(MissingServletRequestPartException e) {
        return validation(List.of(new FieldError(e.getRequestPartName(), "is required")));
    }

    @ExceptionHandler(MethodArgumentTypeMismatchException.class)
    ProblemDetail typeMismatch(MethodArgumentTypeMismatchException e) {
        return validation(List.of(new FieldError(e.getName(), "has an invalid value")));
    }

    @ExceptionHandler(HttpMessageNotReadableException.class)
    ProblemDetail unreadable() {
        return validation(List.of(new FieldError(null, "The request body is not valid JSON for this endpoint.")));
    }

    @ExceptionHandler(MultipartException.class)
    ProblemDetail badMultipart() {
        return validation(List.of(new FieldError("file", "is required as a multipart/form-data part")));
    }

    @ExceptionHandler(NoResourceFoundException.class)
    ProblemDetail notFound() {
        return problem(HttpStatus.NOT_FOUND, "not-found", "No such endpoint.");
    }

    @ExceptionHandler(HttpRequestMethodNotSupportedException.class)
    ProblemDetail methodNotAllowed(HttpRequestMethodNotSupportedException e) {
        return problem(HttpStatus.METHOD_NOT_ALLOWED, "method-not-allowed", e.getMessage());
    }

    @ExceptionHandler(HttpMediaTypeNotSupportedException.class)
    ProblemDetail unsupportedMediaType(HttpMediaTypeNotSupportedException e) {
        return problem(HttpStatus.UNSUPPORTED_MEDIA_TYPE, "unsupported-media-type", e.getMessage());
    }

    @ExceptionHandler(Exception.class)
    ProblemDetail unexpected(Exception e) {
        LOG.error("Unexpected error", e);
        return problem(HttpStatus.INTERNAL_SERVER_ERROR, "internal-error", "An unexpected error occurred.");
    }

    private static ProblemDetail validation(List<FieldError> errors) {
        ProblemDetail problem =
                problem(HttpStatus.UNPROCESSABLE_ENTITY, "validation-failed", "The request is invalid.");
        problem.setProperty("errors", errors);
        return problem;
    }

    private static ProblemDetail problem(HttpStatus status, String code, String detail) {
        ProblemDetail problem = ProblemDetail.forStatusAndDetail(status, detail);
        problem.setType(URI.create(TYPE_PREFIX + code));
        problem.setTitle(title(code));
        return problem;
    }

    private static String title(String code) {
        String words = code.replace('-', ' ');
        return Character.toUpperCase(words.charAt(0)) + words.substring(1);
    }
}
