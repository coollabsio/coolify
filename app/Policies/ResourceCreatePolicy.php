<?php

namespace App\Policies;

use App\Models\Application;
use App\Models\Service;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\User;

class ResourceCreatePolicy
{
    /**
     * List of resource classes that can be created
     */
    public const CREATABLE_RESOURCES = [
        StandalonePostgresql::class,
        StandaloneRedis::class,
        StandaloneMongodb::class,
        StandaloneMysql::class,
        StandaloneMariadb::class,
        StandaloneKeydb::class,
        StandaloneDragonfly::class,
        StandaloneClickhouse::class,
        Service::class,
        Application::class,
        GithubApp::class,
    ];

    /**
     * Determine whether the user can create any resource.
     */
    public function createAny(User $user): bool
    {
        // return $user->isAdmin();
        return true;
    }

    /**
     * Determine whether the user can create a specific resource type.
     */
    public function create(User $user, string $resourceClass): bool
    {
        if (! in_array($resourceClass, self::CREATABLE_RESOURCES)) {
            return false;
        }

        //  return $user->isAdmin();
        return true;
    }

    /**
     * Authorize creation of all supported resource types.
     */
    public function authorizeAllResourceCreation(User $user): bool
    {
        return $this->createAny($user);
    }
}
