<?php

test('globals')
    ->expect(['dd', 'dump', 'ray'])
    ->not->toBeUsed();
