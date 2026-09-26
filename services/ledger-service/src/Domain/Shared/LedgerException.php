<?php

declare(strict_types=1);

namespace App\Domain\Shared;

/**
 * Base class for business-rule violations. Carries no transport concerns:
 * the HTTP layer decides how each subtype is presented.
 */
abstract class LedgerException extends \DomainException {}
