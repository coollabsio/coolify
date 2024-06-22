<?php
/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
*/

use App\Domain\Deployment\DeploymentContext;
use App\Domain\Deployment\DeploymentDockerConfig;
use App\Models\ApplicationDeploymentQueue;
use App\Services\Deployment\DeploymentProvider;
use App\Services\Docker\DockerProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)
    ->in('Integration');

uses(Tests\TestCase::class)->in('Feature');
uses(Tests\TestCase::class)->in('Integration');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

// expect()->extend('toBeOne', function () {
//     return $this->toBe(1);
// });

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

// function something()
// {
//     // ..
// }

// @TODO: Improve the string, feels dirty and leaves room for error
function deploymentDockerConfigMock(string $addHosts = ''): DeploymentDockerConfig {
   $mockedConfig = Mockery::mock(DeploymentDockerConfig::class);
   $mockedConfig->shouldReceive('getAddHosts')->andReturn($addHosts);

   return $mockedConfig;
}


function getContextForApplicationDeployment(ApplicationDeploymentQueue $applicationDeploymentQueue): DeploymentContext
{
    // This could be improved, but for now it's fine
    $dockerProvider = app(DockerProvider::class);
    $deploymentProvider = app(DeploymentProvider::class);

    // This can be improved
    $mockedDockerConfig = deploymentDockerConfigMock('--add-host coolify-proxy:127.0.0.1');

    return new DeploymentContext($applicationDeploymentQueue, $mockedDockerConfig, $dockerProvider, $deploymentProvider);
}
