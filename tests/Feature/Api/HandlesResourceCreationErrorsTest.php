<?php

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
use App\Exceptions\ServerCannotHostResourcesException;
use App\Exceptions\ServerNotFoundException;
use App\Exceptions\ServiceTemplateNotFoundException;
use App\Http\Controllers\Api\Concerns\HandlesResourceCreationErrors;

function creationErrorMapper(): object
{
    return new class
    {
        use HandlesResourceCreationErrors;

        public function map(Throwable $e)
        {
            return $this->creationErrorResponse($e);
        }
    };
}

it('maps placement and creation exceptions to the legacy API status codes', function (Throwable $e, int $status) {
    $response = creationErrorMapper()->map($e);

    expect($response->getStatusCode())->toBe($status)
        ->and($response->getData(true)['message'])->toBe($e->getMessage());
})->with([
    'project' => [new ProjectNotFoundException('p'), 404],
    'environment' => [new EnvironmentNotFoundException, 404],
    'server' => [new ServerNotFoundException('s'), 404],
    'no destinations' => [new NoDestinationsException, 400],
    'ambiguous' => [new AmbiguousDestinationException, 400],
    'domain conflict' => [new DomainAlreadyInUseException(['a.example.com'], 'warn'), 409],
    'github app' => [new GithubAppNotFoundException, 404],
    'repo access' => [new GitRepositoryNotAccessibleException, 404],
    'private key' => [new PrivateKeyNotFoundException, 404],
    'template' => [new ServiceTemplateNotFoundException(['a', 'b']), 404],
    'public port' => [new PublicPortAlreadyUsedException, 400],
    'env exists' => [new EnvironmentAlreadyExistsException, 409],
]);

it('maps validation-style exceptions to 422 with a Validation failed message', function (Throwable $e) {
    $response = creationErrorMapper()->map($e);

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true)['message'])->toBe('Validation failed.');
})->with([
    'cannot host' => [new ServerCannotHostResourcesException],
    'not on server' => [new DestinationNotOnServerException],
    'compose domains' => [new InvalidDockerComposeDomainsException(['Invalid URL: x'])],
]);

it('includes payload keys for conflict, compose-domain, template, and destination errors', function () {
    $mapper = creationErrorMapper();

    expect($mapper->map(new DomainAlreadyInUseException(['a'], 'w'))->getData(true))
        ->toMatchArray(['conflicts' => ['a'], 'warning' => 'w'])
        ->and($mapper->map(new InvalidDockerComposeDomainsException(['bad']))->getData(true)['errors'])
        ->toBe(['docker_compose_domains' => ['bad']])
        ->and($mapper->map(new ServiceTemplateNotFoundException(['x']))->getData(true)['valid_service_types'])
        ->toBe(['x'])
        ->and($mapper->map(new DestinationNotOnServerException)->getData(true)['errors'])
        ->toBe(['destination_uuid' => 'Provided destination_uuid does not belong to the specified server.'])
        ->and($mapper->map(new ServerCannotHostResourcesException)->getData(true)['errors'])
        ->toBe(['server_uuid' => ['The specified server is configured as a build server and cannot host resources.']]);
});

it('rethrows unknown throwables', function () {
    creationErrorMapper()->map(new RuntimeException('boom'));
})->throws(RuntimeException::class, 'boom');
