<?php

namespace App\Enums;

enum ProxyTypes: string
{
    case TRAEFIK_V2 = 'TRAEFIK_V2';
    case NGINX = 'NGINX';
    case CADDY = 'CADDY';
}

enum ProxyStatus: string
{
    case EXITED = 'exited';
    case RUNNING = 'running';
}
