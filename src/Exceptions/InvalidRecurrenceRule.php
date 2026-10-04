<?php

namespace BoysFromTheFactory\Groundhog\Exceptions;

use InvalidArgumentException;
use Throwable;

/**
 * Thrown when rule input assigned to `recurrence_rule` cannot become a single RFC 5545 RRULE.
 * The message names the offending part so the caller can correct the input (FR-006).
 */
final class InvalidRecurrenceRule extends InvalidArgumentException
{
    /**
     * Wraps a php-rrule parser error, keeping its message because it names the invalid component.
     */
    public static function fromParserError(string $input, Throwable $previous): self
    {
        return new self(
            sprintf('Invalid recurrence rule "%s": %s', $input, $previous->getMessage()),
            previous: $previous,
        );
    }

    /**
     * EXDATE, RDATE and EXRULE would turn the rule into a set; exclusions come only from
     * cancelling or replacing occurrences (FR-005).
     */
    public static function forUnsupportedLine(string $line): self
    {
        return new self(sprintf(
            'Invalid recurrence rule: unsupported line "%s"; only DTSTART and RRULE are accepted.',
            $line,
        ));
    }
}
