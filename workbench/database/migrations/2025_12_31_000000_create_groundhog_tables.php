<?php

// The workbench runs the package's own published migration so tests use the exact schema users get.
return require __DIR__.'/../../../database/migrations/create_groundhog_tables.php.stub';
