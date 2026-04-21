<?php

/**
 * Architecture tests to prevent use of insecure PRNGs in application code.
 *
 * mt_rand() and rand() are not cryptographically secure. Use random_int()
 * or random_bytes() instead for any security-sensitive context.
 */
arch('app code must not use mt_rand')
    ->expect('App')
    ->not->toUse(['mt_rand', 'mt_srand']);

arch('app code must not use rand')
    ->expect('App')
    ->not->toUse(['rand', 'srand']);
