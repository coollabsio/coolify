<?php

use App\Support\V5\V5Feature;

return [
    'enabled' => V5Feature::enabledForEnvironment((string) env('APP_ENV', 'production')),
];
