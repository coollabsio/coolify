<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Exceptions\AmbiguousDestinationException;
use App\Exceptions\DestinationNotOnServerException;
use App\Exceptions\DomainAlreadyInUseException;
use App\Exceptions\EnvironmentAlreadyExistsException;
use App\Exceptions\EnvironmentNotFoundException;
use App\Exceptions\GithubAppNotFoundException;
use App\Exceptions\GitRepositoryNotAccessibleException;
use App\Exceptions\InvalidDockerComposeDomainsException;
use App\Exceptions\NoDestinationsException;
use App\Exceptions\PrivateKeyNotFoundException;
use App\Exceptions\ProjectNotFoundException;
use App\Exceptions\PublicPortAlreadyUsedException;
use App\Exceptions\ResourceCreationException;
use App\Exceptions\ServerCannotHostResourcesException;
use App\Exceptions\ServerNotFoundException;
use App\Exceptions\ServiceTemplateNotFoundException;
use Illuminate\Http\JsonResponse;

trait HandlesResourceCreationErrors
{
    /**
     * Map typed placement/creation exceptions to the legacy API responses.
     */
    protected function creationErrorResponse(\Throwable $e): JsonResponse
    {
        $status = match (true) {
            $e instanceof ProjectNotFoundException,
            $e instanceof EnvironmentNotFoundException,
            $e instanceof ServerNotFoundException,
            $e instanceof GithubAppNotFoundException,
            $e instanceof GitRepositoryNotAccessibleException,
            $e instanceof PrivateKeyNotFoundException,
            $e instanceof ServiceTemplateNotFoundException => 404,
            $e instanceof NoDestinationsException,
            $e instanceof AmbiguousDestinationException,
            $e instanceof PublicPortAlreadyUsedException => 400,
            $e instanceof DomainAlreadyInUseException,
            $e instanceof EnvironmentAlreadyExistsException => 409,
            $e instanceof ServerCannotHostResourcesException,
            $e instanceof DestinationNotOnServerException,
            $e instanceof InvalidDockerComposeDomainsException => 422,
            default => null,
        };

        if ($status === null) {
            throw $e;
        }

        $body = ['message' => $e->getMessage()];
        if ($e instanceof ServerCannotHostResourcesException) {
            $body = ['message' => 'Validation failed.', 'errors' => ['server_uuid' => ['The specified server is configured as a build server and cannot host resources.']]];
        } elseif ($e instanceof DestinationNotOnServerException) {
            $body = ['message' => 'Validation failed.', 'errors' => ['destination_uuid' => $e->getMessage()]];
        } elseif ($e instanceof ResourceCreationException) {
            $body += $e->payload;
        }

        return response()->json($body, $status);
    }
}
