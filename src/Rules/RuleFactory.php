<?php

namespace BoysFromTheFactory\Groundhog\Rules;

use BoysFromTheFactory\Groundhog\Exceptions\InvalidRecurrenceRule;
use Carbon\CarbonInterface;
use DateTime;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use RRule\RRule;

/**
 * Turns rule input into a validated php-rrule `RRule` anchored at a series start.
 *
 * Rules are always built with `new RRule(...)`: `RRule::createFromRfcString()` silently
 * returns an `RSet` for EXDATE/RDATE input, which would break the `RRule` cast contract.
 *
 * @internal
 */
final class RuleFactory
{
    private const ALLOWED_LINES = ['DTSTART', 'RRULE'];

    /**
     * Validates the input and rebuilds it with DTSTART = the series start in the rule's zone.
     *
     * The zone comes from the input's DTSTART when it carries one, otherwise from
     * `$defaultTimezone`; the input's DTSTART date-time itself is discarded, because the
     * series' start column is the single source of the first occurrence.
     *
     * @param  string|array<string, mixed>|RRule  $input  RRULE text (optionally with a DTSTART line), php-rrule parts, or an RRule
     *
     * @throws InvalidRecurrenceRule when the input is malformed, uses an unsupported component, or contains EXDATE/RDATE/EXRULE
     */
    public static function make(string|array|RRule $input, CarbonInterface $seriesStart, string $defaultTimezone): RRule
    {
        $parts = match (true) {
            $input instanceof RRule => $input->getRule(),
            is_array($input) => self::parse($input, $input)->getRule(),
            default => self::parse(self::assertSingleRule($input), $input)->getRule(),
        };

        $parts = array_change_key_case($parts, CASE_UPPER);
        $timezone = is_string($input) ? self::timezoneOfText($input) : self::timezoneOfParts($parts);

        $dtstart = DateTime::createFromInterface($seriesStart)->setTimezone(new DateTimeZone($timezone ?? $defaultTimezone));
        // rfcString() keeps whole seconds only; dropping sub-seconds here keeps stored text and index in step.
        $dtstart->setTime((int) $dtstart->format('H'), (int) $dtstart->format('i'), (int) $dtstart->format('s'));
        $parts['DTSTART'] = $dtstart;

        return self::parse($parts, is_string($input) ? $input : $parts);
    }

    /**
     * Rebuilds a rule from the RFC text stored in `groundhog_recurrences.rule`.
     */
    public static function fromStored(string $rfc): RRule
    {
        $rule = new RRule($rfc);
        $dtstart = $rule->getRule()['DTSTART'] ?? null;

        // rfcString() writes UTC as "...Z", which parses back into the zone "Z"; restore the IANA
        // name so the rule reports the same zone it was stored with.
        if ($dtstart instanceof DateTime && $dtstart->getTimezone()->getName() === 'Z') {
            return new RRule(['DTSTART' => (clone $dtstart)->setTimezone(new DateTimeZone('UTC'))] + $rule->getRule());
        }

        return $rule;
    }

    /**
     * IANA zone the rule's occurrences are computed in.
     */
    public static function timezoneOf(RRule $rule): string
    {
        $dtstart = $rule->getRule()['DTSTART'] ?? null;

        return $dtstart instanceof DateTimeInterface ? $dtstart->getTimezone()->getName() : date_default_timezone_get();
    }

    /**
     * @param  string|array<string, mixed>  $definition
     * @param  string|array<string, mixed>  $original  input echoed in the error message
     */
    private static function parse(string|array $definition, string|array $original): RRule
    {
        try {
            return new RRule($definition);
        } catch (InvalidArgumentException $exception) {
            throw InvalidRecurrenceRule::fromParserError(
                is_string($original) ? $original : (string) json_encode($original),
                $exception,
            );
        }
    }

    /**
     * php-rrule accepts EXRULE as if it were RRULE and only fails late on EXDATE/RDATE, so
     * every line is checked before parsing.
     */
    private static function assertSingleRule(string $input): string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", trim($input)))));

        if (count($lines) === 1 && ! str_contains($lines[0], ':')) {
            return $lines[0];
        }

        foreach ($lines as $line) {
            if (! in_array(self::propertyName($line), self::ALLOWED_LINES, true)) {
                throw InvalidRecurrenceRule::forUnsupportedLine($line);
            }
        }

        return implode("\n", $lines);
    }

    private static function propertyName(string $line): string
    {
        return strtoupper(strtok($line, ';:') ?: '');
    }

    private static function timezoneOfText(string $input): ?string
    {
        foreach (explode("\n", $input) as $line) {
            $line = trim($line);

            if (self::propertyName($line) !== 'DTSTART') {
                continue;
            }

            if (preg_match('/TZID=([^;:]+)/i', $line, $match) === 1) {
                return $match[1];
            }

            return str_ends_with(strtoupper($line), 'Z') ? 'UTC' : null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $parts
     */
    private static function timezoneOfParts(array $parts): ?string
    {
        $dtstart = $parts['DTSTART'] ?? null;

        return $dtstart instanceof DateTimeInterface ? $dtstart->getTimezone()->getName() : null;
    }
}
