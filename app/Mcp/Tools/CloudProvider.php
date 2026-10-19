<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(false)]
#[IsDestructive]
#[IsOpenWorld]
class CloudProvider extends RestActionTool
{
    protected string $name = 'cloud_provider';

    protected string $description = 'Manage cloud provider API tokens of the authenticated team (Hetzner, DigitalOcean, Vultr, Hostinger), read provider catalogs, and provision new paid servers. Provider tokens are never returned. Requires read ability for list_tokens/get_token/catalog, write ability for the rest (provision_server with a cloud-init or post-install script or extra SSH keys also needs deploy). Same validation and permissions as the REST API.';

    private const CATALOG_PATHS = [
        'hetzner_locations' => 'hetzner/locations',
        'hetzner_server_types' => 'hetzner/server-types',
        'hetzner_images' => 'hetzner/images',
        'hetzner_ssh_keys' => 'hetzner/ssh-keys',
        'hetzner_firewalls' => 'hetzner/firewalls',
        'hetzner_networks' => 'hetzner/networks',
        'digitalocean_regions' => 'digitalocean/regions',
        'digitalocean_sizes' => 'digitalocean/sizes',
        'digitalocean_images' => 'digitalocean/images',
        'digitalocean_ssh_keys' => 'digitalocean/ssh-keys',
        'vultr_regions' => 'vultr/regions',
        'vultr_plans' => 'vultr/plans',
        'vultr_os' => 'vultr/os',
        'vultr_ssh_keys' => 'vultr/ssh-keys',
        'hostinger_data_centers' => 'hostinger/data-centers',
        'hostinger_catalog' => 'hostinger/catalog',
        'hostinger_templates' => 'hostinger/templates',
        'hostinger_ssh_keys' => 'hostinger/ssh-keys',
        'hostinger_post_install_scripts' => 'hostinger/post-install-scripts',
    ];

    private const PROVISION_PATHS = [
        'hetzner' => 'servers/hetzner',
        'digitalocean' => 'servers/digitalocean',
        'vultr' => 'servers/vultr',
        'hostinger' => 'servers/hostinger',
    ];

    protected function actions(): array
    {
        return [
            'list_tokens' => ['method' => 'GET', 'path' => 'cloud-tokens', 'ability' => 'read', 'description' => 'cloud provider tokens of the team (no token values)'],
            'get_token' => ['method' => 'GET', 'path' => 'cloud-tokens/{uuid}', 'ability' => 'read', 'description' => 'one cloud provider token (no token value)'],
            'create_token' => ['method' => 'POST', 'path' => 'cloud-tokens', 'ability' => 'write', 'description' => 'add a provider token; it is checked with the provider first (data: provider hetzner|digitalocean|vultr|hostinger, token, name)'],
            'update_token' => ['method' => 'PATCH', 'path' => 'cloud-tokens/{uuid}', 'ability' => 'write', 'description' => 'rename a provider token (data: name)'],
            'delete_token' => ['method' => 'DELETE', 'path' => 'cloud-tokens/{uuid}', 'ability' => 'write', 'confirm' => true, 'description' => 'delete a provider token that no server uses'],
            'validate_token' => ['method' => 'POST', 'path' => 'cloud-tokens/{uuid}/validate', 'ability' => 'write', 'description' => 'check a stored provider token with the provider'],
            'catalog' => ['method' => 'GET', 'paths' => self::CATALOG_PATHS, 'ability' => 'read', 'description' => 'read a provider catalog; resource is <provider>_<catalog>, for example hetzner_locations (data: cloud_provider_token_uuid)'],
            'provision_server' => ['method' => 'POST', 'paths' => self::PROVISION_PATHS, 'ability' => 'write', 'confirm' => true, 'description' => 'create a new paid server at the provider and add it to Coolify; resource is the provider (data: cloud_provider_token_uuid, private_key_uuid, name, and the provider fields, for example location, server_type, image for hetzner; a cloud-init or post-install script or extra SSH keys need deploy ability)'],
        ];
    }

    protected function pathParams(): array
    {
        return [
            'uuid' => 'Cloud provider token UUID (get_token, update_token, delete_token, validate_token).',
        ];
    }

    protected function dataDescription(): string
    {
        return 'Request body or catalog query, same fields as the REST API. Catalogs and provision_server need cloud_provider_token_uuid.';
    }
}
