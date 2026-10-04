<?php

arch('it will not use debugging functions')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->each->not->toBeUsed();

arch('package code does not depend on the workbench')
    ->expect('BoysFromTheFactory\Groundhog')
    ->not->toUse('Workbench');
