---
name: mcp-development
description: "Use this skill for Laravel MCP development only. Trigger when creating or editing MCP tools, resources, prompts, or servers in Laravel projects. Covers: artisan make:mcp-* generators, mcp:inspector, routes/ai.php, Tool/Resource/Prompt classes, schema validation, shouldRegister(), OAuth setup, URI templates, read-only attributes, and MCP debugging. Do not use for non-Laravel MCP projects or generic AI features without MCP."
license: MIT
metadata:
  author: laravel
---

# MCP Development

## Documentation

Use `search-docs` for detailed Laravel MCP patterns and documentation.

## Basic Usage

Register MCP servers in `routes/ai.php`:

<!-- Register MCP Server -->
```php
use Laravel\Mcp\Facades\Mcp;

Mcp::web();
```

### Creating MCP Primitives

Create MCP tools, resources, prompts, and servers using artisan commands:

```bash
php artisan make:mcp-tool ToolName        # Create a tool

php artisan make:mcp-resource ResourceName # Create a resource

php artisan make:mcp-prompt PromptName    # Create a prompt

php artisan make:mcp-server ServerName    # Create a server

```

After creating primitives, register them in your server's `$tools`, `$resources`, or `$prompts` properties.

### Tools

<!-- MCP Tool Example -->
```php
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Request;
use Laravel\Mcp\Server\Response;

class MyTool extends Tool
{
    public function handle(Request $request): Response
    {
        return new Response(['result' => 'success']);
    }
}
```

### Registering Primitives in a Server

Each MCP server must explicitly declare the tools, resources, and prompts it exposes.

<!-- Register Primitives in MCP Server -->
```php
use Laravel\Mcp\Server;

class AppServer extends Server
{
    protected array $tools = [
        \App\Mcp\Tools\MyTool::class,
    ];

    protected array $resources = [
        \App\Mcp\Resources\MyResource::class,
    ];

    protected array $prompts = [
        \App\Mcp\Prompts\MyPrompt::class,
    ];
}
```

## Verification

1. Check `routes/ai.php` for proper registration
2. Test tool via MCP client

## Common Pitfalls

- Running `mcp:start` command (it hangs waiting for input)
- Using HTTPS locally with Node-based MCP clients
- Not using `search-docs` for the latest MCP documentation
- Not registering MCP server routes in `routes/ai.php`
- Do not register `ai.php` in `bootstrap.php`; it is registered automatically.
- OAuth registration supports custom URI schemes (e.g., `cursor://`, `vscode://`) for native desktop clients via `mcp.custom_schemes` config
