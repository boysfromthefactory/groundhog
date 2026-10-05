<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Expansion horizon (FR-008)
    |--------------------------------------------------------------------------
    |
    | ISO-8601 duration. A rule without COUNT or UNTIL never ends, so when a query
    | gives no upper bound on the start or end column, its occurrences are only
    | expanded up to this distance after the query's lower start bound, or after
    | now when there is no lower bound either. Finite rules are never capped.
    |
    */

    'horizon' => 'P1Y',

    /*
    |--------------------------------------------------------------------------
    | Per-series generation limit (SC-005)
    |--------------------------------------------------------------------------
    |
    | The maximum number of occurrences one series may generate for one query,
    | or on save (a finite rule in full, an infinite rule up to now + horizon).
    | A rule or query that needs more fails with OccurrenceLimitExceeded instead
    | of exhausting time or memory.
    |
    */

    'max_occurrences_per_series' => 50000,

];
