<?php

namespace BoysFromTheFactory\Groundhog\Query;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;

/**
 * A fixed SQL template whose `%s` slots are identifiers, wrapped (and table-prefixed) by the
 * grammar of the connection that compiles it.
 *
 * The derived table needs computed columns built from the model's own table and column
 * names; wrapping them at compile time keeps every value out of the SQL text without
 * resorting to unchecked raw strings.
 *
 * @internal
 */
final class IdentifierSql implements Expression
{
    /**
     * @param  literal-string  $template  SQL with one `%s` per identifier
     * @param  list<string>  $identifiers  column or `table.column` names
     */
    public function __construct(
        private readonly string $template,
        private readonly array $identifiers,
    ) {}

    public function getValue(Grammar $grammar): string
    {
        return vsprintf($this->template, array_map(fn (string $identifier): string => $grammar->wrap($identifier), $this->identifiers));
    }
}
