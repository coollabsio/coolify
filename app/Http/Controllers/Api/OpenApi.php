<?php

namespace App\Http\Controllers\Api;

use OpenApi\Attributes as OA;

#[OA\Info(title: 'Coolify', version: '0.1')]
#[OA\Server(url: 'https://coolify.io/api/v1')]
#[OA\SecurityScheme(type: 'http', scheme: 'bearer', bearerFormat: 'JWT', securityScheme: 'bearerAuth')]
class OpenApi
{
    // This class is used to generate OpenAPI documentation
    // for the Coolify API. It is not a controller and does
    // not contain any routes. It is used to define the
    // OpenAPI metadata and security scheme for the API.
}
