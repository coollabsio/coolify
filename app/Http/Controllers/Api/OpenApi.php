<?php

namespace App\Http\Controllers\Api;

use OpenApi\Attributes as OA;

#[OA\Info(title: 'Coolify', version: '0.1')]
#[OA\Server(url: 'https://app.coolify.io/api/v1', description: 'Coolify Cloud API. Change the host to your own instance if you are self-hosting.')]
#[OA\SecurityScheme(
    type: 'http',
    scheme: 'bearer',
    securityScheme: 'bearerAuth',
    description: 'Go to `Keys & Tokens` / `API tokens` and create a new token. Use the token as the bearer token.')]
#[OA\Components(
    responses: [
        new OA\Response(
            response: 400,
            description: 'Invalid token.',
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(property: 'message', type: 'string', example: 'Invalid token.'),
                ]
            )),
        new OA\Response(
            response: 401,
            description: 'Unauthenticated.',
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(property: 'message', type: 'string', example: 'Unauthenticated.'),
                ]
            )),
        new OA\Response(
            response: 404,
            description: 'Resource not found.',
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(property: 'message', type: 'string', example: 'Resource not found.'),
                ]
            )),
        new OA\Response(
            response: 422,
            description: 'Validation error.',
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(property: 'message', type: 'string', example: 'Validation error.'),
                    new OA\Property(
                        property: 'errors',
                        type: 'object',
                        additionalProperties: new OA\AdditionalProperties(
                            type: 'array',
                            items: new OA\Items(type: 'string')
                        ),
                        example: [
                            'name' => ['The name field is required.'],
                            'api_url' => ['The api url field is required.', 'The api url format is invalid.'],
                        ]
                    ),
                ]
            )),
        new OA\Response(
            response: 429,
            description: 'Rate limit exceeded.',
            headers: [
                new OA\Header(
                    header: 'Retry-After',
                    description: 'Number of seconds to wait before retrying.',
                    schema: new OA\Schema(type: 'integer', example: 60)
                ),
            ],
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(property: 'message', type: 'string', example: 'Rate limit exceeded. Please try again later.'),
                ]
            )),
    ],
)]
class OpenApi
{
    // This class is used to generate OpenAPI documentation
    // for the Coolify API. It is not a controller and does
    // not contain any routes. It is used to define the
    // OpenAPI metadata and security scheme for the API.
}
