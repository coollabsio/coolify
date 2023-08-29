<?php

test('globals')
    ->expect(['dd', 'dump'])
    ->not->toBeUsed();
