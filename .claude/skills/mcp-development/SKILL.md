---
name: mcp-development
description: "Use this skill for Laravel MCP development. Trigger when creating or editing MCP tools, resources, prompts, servers, or UI apps in Laravel projects. Covers: artisan make:mcp-* generators, routes/ai.php, Tool/Resource/Prompt/AppResource classes, schema validation, shouldRegister(), OAuth setup, URI templates, read-only attributes, MCP debugging, MCP UI apps, the x-mcp::app Blade component, createMcpApp(), default AppResource handle() auto-infers view from class name, Response::view(), AppMeta/Csp/Permissions/appMeta() configuration, #[RendersApp] attribute, Library enum for CDN libraries (Tailwind, Alpine), and host theming via CSS variables. Use this whenever the user mentions MCP apps, MCP UI, interactive MCP resources, styling MCP apps with Tailwind or Alpine, or building visual interfaces for AI agents."
license: MIT
metadata:
  author: laravel
---

# MCP Development

## Documentation

Use `search-docs` for detailed Laravel MCP patterns and documentation.

For MCP UI apps (interactive HTML resources), read `references/app.md` — it covers the full architecture, host theming CSS variables, tool-to-UI linking patterns, library scripts (Tailwind, Alpine via `Library`), and real-world examples.

## Basic Usage

Register MCP servers in `routes/ai.php`:

<!-- Register MCP Server -->
```php
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp/demo', \App\Mcp\Servers\AppServer::class);
```

### Creating MCP Primitives

```bash
php artisan make:mcp-tool ToolName            # Create a tool
php artisan make:mcp-resource ResourceName     # Create a resource
php artisan make:mcp-prompt PromptName        # Create a prompt
php artisan make:mcp-server ServerName        # Create a server
php artisan make:mcp-app-resource DashboardApp # Create a UI app (2 files)
```

After creating primitives, register them in your server's `$tools`, `$resources`, or `$prompts` properties.

### Tools

<!-- MCP Tool Example -->
```php
use Illuminate\Json\Schema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class MyTool extends Tool
{
    protected string $description = 'Describe what this tool does';

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('The name parameter')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $request->validate(['name' => 'required|string']);

        return Response::text('Hello, '.$request->get('name'));
    }
}
```

### Registering Primitives in a Server

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

## MCP UI Apps

For MCP UI apps, read `references/app.md` — it covers quick start examples, full architecture, AppMeta/Csp/Permissions, `#[RendersApp]` tool linking, library scripts (Tailwind/Alpine via `Library`), host theming CSS variables, and real-world patterns.

## Verification

1. Check `routes/ai.php` for proper registration
2. Test tool via MCP client

## Common Pitfalls

- Running `mcp:start` command (it hangs waiting for input)
- Using HTTPS locally with Node-based MCP clients
- Not using `search-docs` for the latest MCP documentation
- Not registering MCP server routes in `routes/ai.php`
- Do not register `ai.php` in `bootstrap.php`; it is registered automatically
- OAuth registration supports custom URI schemes (e.g., `cursor://`, `vscode://`) for native desktop clients via `mcp.custom_schemes` config
