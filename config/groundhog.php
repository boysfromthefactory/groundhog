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
    | The maximum number of occurrences a single generation pass may produce for
    | one series. A rule or query that needs more fails with
    | OccurrenceLimitExceeded instead of exhausting time or memory.
    |
    */

    'max_occurrences_per_series' => 50000,

    /*
    |--------------------------------------------------------------------------
    | Materialisation ceiling (FR-008)
    |--------------------------------------------------------------------------
    |
    | ISO-8601 duration measured from now. Reads extend the stored occurrence
    | index on demand; this bounds how far ahead a single query may make it
    | generate, so request input cannot trigger unbounded writes. A query that
    | would need occurrences beyond it fails with OccurrenceLimitExceeded.
    |
    */

    'max_materialization_ahead' => 'P10Y',

];
