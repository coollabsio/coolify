<?php

use App\Http\Controllers\Api\ServicesController;
use App\Models\Service;

it('does not treat service URLs with different path casing as duplicates', function () {
    $controller = new ServicesController;
    $method = new ReflectionMethod($controller, 'applyServiceUrls');
    $service = new class extends Service
    {
        public function applications()
        {
            return new class
            {
                public function where(string $column, mixed $value): self
                {
                    return $this;
                }

                public function first(): null
                {
                    return null;
                }
            };
        }
    };

    $result = $method->invoke($controller, $service, [
        ['name' => 'web', 'url' => 'https://example.com/Route'],
        ['name' => 'api', 'url' => 'HTTPS://EXAMPLE.COM/route'],
    ], '1');

    expect($result['errors'] ?? [])->toBe([
        "Service container with 'web' not found.",
        "Service container with 'api' not found.",
    ]);
});
