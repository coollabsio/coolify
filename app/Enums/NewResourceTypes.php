<?php

namespace App\Enums;

enum NewResourceTypes: string
{
    case PUBLIC = 'public';
    case PRIVATE_GH_APP = 'private-gh-app';
    case PRIVATE_DEPLOY_KEY = 'private-deploy-key';
    case DOCKERFILE = 'dockerfile';
    case DOCKERCOMPOSE = 'dockercompose';
    case DOCKER_IMAGE = 'docker-image';
    case SERVICE = 'service';
    case POSTGRESQL = 'postgresql';
    case MYSQL = 'mysql';
    case MONGODB = 'mongodb';
    case REDIS = 'redis';
    case MARIADB = 'mariadb';
    case KEYDB = 'keydb';
    case DRAGONFLY = 'dragonfly';
    case CLICKHOUSE = 'clickhouse';
}
