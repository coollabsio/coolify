
# Enhanced Form Components with Authorization

## Overview

Coolify's form components now feature **built-in authorization** that automatically handles permission-based UI control, dramatically reducing code duplication and improving security consistency.

## Enhanced Components

All form components now support the `canGate` authorization system:

- **[Input.php](mdc:app/View/Components/Forms/Input.php)** - Text, password, and other input fields
- **[Select.php](mdc:app/View/Components/Forms/Select.php)** - Dropdown selection components
- **[Textarea.php](mdc:app/View/Components/Forms/Textarea.php)** - Multi-line text areas
- **[Checkbox.php](mdc:app/View/Components/Forms/Checkbox.php)** - Boolean toggle components
- **[Button.php](mdc:app/View/Components/Forms/Button.php)** - Action buttons

## Authorization Parameters

### Core Parameters
```php
public ?string $canGate = null;        // Gate name: 'update', 'view', 'deploy', 'delete'
public mixed $canResource = null;      // Resource model instance to check against
public bool $autoDisable = true;       // Automatically disable if no permission
```

### How It Works
```php
// Automatic authorization logic in each component
if ($this->canGate && $this->canResource && $this->autoDisable) {
    $hasPermission = Gate::allows($this->canGate, $this->canResource);
    
    if (! $hasPermission) {
        $this->disabled = true;
        // For Checkbox: also sets $this->instantSave = false;
    }
}
```

## Usage Patterns

### ✅ Recommended: Single Line Pattern

**Before (Verbose, 6+ lines per element):**
```html
@can('update', $application)
    <x-forms.input id="application.name" label="Name" />
    <x-forms.checkbox instantSave id="application.settings.is_static" label="Static Site" />
    <x-forms.button type="submit">Save</x-forms.button>
@else
    <x-forms.input disabled id="application.name" label="Name" />
    <x-forms.checkbox disabled id="application.settings.is_static" label="Static Site" />
@endcan
```

**After (Clean, 1 line per element):**
```html
<x-forms.input canGate="update" :canResource="$application" id="application.name" label="Name" />
<x-forms.checkbox instantSave canGate="update" :canResource="$application" id="application.settings.is_static" label="Static Site" />
<x-forms.button canGate="update" :canResource="$application" type="submit">Save</x-forms.button>
```

**Result: 90% code reduction!**

### Component-Specific Examples

#### Input Fields
```html
<!-- Basic input with authorization -->
<x-forms.input 
    canGate="update" 
    :canResource="$application" 
    id="application.name" 
    label="Application Name" />

<!-- Password input with authorization -->
<x-forms.input 
    type="password"
    canGate="update" 
    :canResource="$application" 
    id="application.database_password" 
    label="Database Password" />

<!-- Required input with authorization -->
<x-forms.input 
    required
    canGate="update" 
    :canResource="$application" 
    id="application.fqdn" 
    label="Domain" />
```

#### Select Dropdowns
```html
<!-- Build pack selection -->
<x-forms.select 
    canGate="update" 
    :canResource="$application" 
    id="application.build_pack" 
    label="Build Pack" 
    required>
    <option value="nixpacks">Nixpacks</option>
    <option value="static">Static</option>
    <option value="dockerfile">Dockerfile</option>
</x-forms.select>

<!-- Server selection -->
<x-forms.select 
    canGate="createAnyResource" 
    :canResource="auth()->user()->currentTeam" 
    id="server_id" 
    label="Target Server">
    @foreach($servers as $server)
        <option value="{{ $server->id }}">{{ $server->name }}</option>
    @endforeach
</x-forms.select>
```

#### Checkboxes with InstantSave
```html
<!-- Static site toggle -->
<x-forms.checkbox 
    instantSave 
    canGate="update" 
    :canResource="$application" 
    id="application.settings.is_static" 
    label="Is it a static site?" 
    helper="Enable if your application serves static files" />

<!-- Debug mode toggle -->
<x-forms.checkbox 
    instantSave 
    canGate="update" 
    :canResource="$application" 
    id="application.settings.is_debug_enabled" 
    label="Debug Mode" 
    helper="Enable debug logging for troubleshooting" />

<!-- Build server toggle -->
<x-forms.checkbox 
    instantSave 
    canGate="update" 
    :canResource="$application" 
    id="application.settings.is_build_server_enabled" 
    label="Use Build Server" 
    helper="Use a dedicated build server for compilation" />
```

#### Textareas
```html
<!-- Configuration textarea -->
<x-forms.textarea 
    canGate="update" 
    :canResource="$application" 
    id="application.docker_compose_raw" 
    label="Docker Compose Configuration" 
    rows="10" 
    monacoEditorLanguage="yaml" 
    useMonacoEditor />

<!-- Custom commands -->
<x-forms.textarea 
    canGate="update" 
    :canResource="$application" 
    id="application.post_deployment_command" 
    label="Post-Deployment Commands" 
    placeholder="php artisan migrate" 
    helper="Commands to run after deployment" />
```

#### Buttons
```html
<!-- Save button -->
<x-forms.button 
    canGate="update" 
    :canResource="$application" 
    type="submit">
    Save Configuration
</x-forms.button>

<!-- Deploy button -->
<x-forms.button 
    canGate="deploy" 
    :canResource="$application" 
    wire:click="deploy">
    Deploy Application
</x-forms.button>

<!-- Delete button -->
<x-forms.button 
    canGate="delete" 
    :canResource="$application" 
    wire:click="confirmDelete" 
    class="button-danger">
    Delete Application
</x-forms.button>
```

## Advanced Usage

### Custom Authorization Logic
```html
<!-- Disable auto-control for complex permissions -->
<x-forms.input 
    canGate="update" 
    :canResource="$application" 
    autoDisable="false"
    :disabled="$application->is_deployed || !$application->canModifySettings()"
    id="deployment.setting" 
    label="Advanced Setting" />
```

### Multiple Permission Checks
```html
<!-- Combine multiple authorization requirements -->
<x-forms.checkbox 
    canGate="deploy" 
    :canResource="$application" 
    autoDisable="false"
    :disabled="!$application->hasDockerfile() || !Gate::allows('deploy', $application)"
    id="docker.setting" 
    label="Docker-Specific Setting" />
```

### Conditional Resources
```html
<!-- Different resources based on context -->
<x-forms.button 
    :canGate="$isEditing ? 'update' : 'view'"
    :canResource="$resource" 
    type="submit">
    {{ $isEditing ? 'Save Changes' : 'View Details' }}
</x-forms.button>
```

## Supported Gates

### Resource-Level Gates
- `view` - Read access to resource details
- `update` - Modify resource configuration and settings
- `deploy` - Deploy, restart, or manage resource state
- `delete` - Remove or destroy resource
- `clone` - Duplicate resource to another location

### Global Gates
- `createAnyResource` - Create new resources of any type
- `manageTeam` - Team administration permissions
- `accessServer` - Server-level access permissions

## Supported Resources

### Primary Resources
- `$application` - Application instances and configurations
- `$service` - Docker Compose services and components
- `$database` - Database instances (PostgreSQL, MySQL, etc.)
- `$server` - Physical or virtual server instances

### Container Resources
- `$project` - Project containers and environments
- `$environment` - Environment-specific configurations
- `$team` - Team and organization contexts

### Infrastructure Resources
- `$privateKey` - SSH private keys and certificates
- `$source` - Git sources and repositories
- `$destination` - Deployment destinations and targets

## Component Behavior

### Input Components (Input, Select, Textarea)
When authorization fails:
- **disabled = true** - Field becomes non-editable
- **Visual styling** - Opacity reduction and disabled cursor
- **Form submission** - Values are ignored in forms
- **User feedback** - Clear visual indication of restricted access

### Checkbox Components
When authorization fails:
- **disabled = true** - Checkbox becomes non-clickable
- **instantSave = false** - Automatic saving is disabled
- **State preservation** - Current value is maintained but read-only
- **Visual styling** - Disabled appearance with reduced opacity

### Button Components
When authorization fails:
- **disabled = true** - Button becomes non-clickable
- **Event blocking** - Click handlers are ignored
- **Visual styling** - Disabled appearance and cursor
- **Loading states** - Loading indicators are disabled

## Migration Guide

### Converting Existing Forms

**Old Pattern:**
```html
<form wire:submit='submit'>
    @can('update', $application)
        <x-forms.input id="name" label="Name" />
        <x-forms.select id="type" label="Type">...</x-forms.select>
        <x-forms.checkbox instantSave id="enabled" label="Enabled" />
        <x-forms.button type="submit">Save</x-forms.button>
    @else
        <x-forms.input disabled id="name" label="Name" />
        <x-forms.select disabled id="type" label="Type">...</x-forms.select>
        <x-forms.checkbox disabled id="enabled" label="Enabled" />
    @endcan
</form>
```

**New Pattern:**
```html
<form wire:submit='submit'>
    <x-forms.input canGate="update" :canResource="$application" id="name" label="Name" />
    <x-forms.select canGate="update" :canResource="$application" id="type" label="Type">...</x-forms.select>
    <x-forms.checkbox instantSave canGate="update" :canResource="$application" id="enabled" label="Enabled" />
    <x-forms.button canGate="update" :canResource="$application" type="submit">Save</x-forms.button>
</form>
```

### Gradual Migration Strategy

1. **Start with new forms** - Use the new pattern for all new components
2. **Convert high-traffic areas** - Migrate frequently used forms first
3. **Batch convert similar forms** - Group similar authorization patterns
4. **Test thoroughly** - Verify authorization behavior matches expectations
5. **Remove old patterns** - Clean up legacy @can/@else blocks

## Testing Patterns

### Component Authorization Tests
```php
// Test authorization integration in components
test('input component respects authorization', function () {
    $user = User::factory()->member()->create();
    $application = Application::factory()->create();
    
    // Member should see disabled input
    $component = Livewire::actingAs($user)
        ->test(TestComponent::class, [
            'canGate' => 'update',
            'canResource' => $application
        ]);
    
    expect($component->get('disabled'))->toBeTrue();
});

test('checkbox disables instantSave for unauthorized users', function () {
    $user = User::factory()->member()->create();
    $application = Application::factory()->create();
    
    $component = Livewire::actingAs($user)
        ->test(CheckboxComponent::class, [
            'instantSave' => true,
            'canGate' => 'update',
            'canResource' => $application
        ]);
    
    expect($component->get('disabled'))->toBeTrue();
    expect($component->get('instantSave'))->toBeFalse();
});
```

### Integration Tests
```php
// Test full form authorization behavior
test('application form respects member permissions', function () {
    $member = User::factory()->member()->create();
    $application = Application::factory()->create();
    
    $this->actingAs($member)
        ->get(route('application.edit', $application))
        ->assertSee('disabled')
        ->assertDontSee('Save Configuration');
});
```

## Best Practices

### Consistent Gate Usage
- Use `update` for configuration changes
- Use `deploy` for operational actions
- Use `view` for read-only access
- Use `delete` for destructive actions

### Resource Context
- Always pass the specific resource being acted upon
- Use team context for creation permissions
- Consider nested resource relationships

### Error Handling
- Provide clear feedback for disabled components
- Use helper text to explain permission requirements
- Consider tooltips for disabled buttons

### Performance
- Authorization checks are cached per request
- Use eager loading for resource relationships
- Consider query optimization for complex permissions

## Common Patterns

### Application Configuration Forms
```html
<!-- Application settings with consistent authorization -->
<x-forms.input canGate="update" :canResource="$application" id="application.name" label="Name" />
<x-forms.select canGate="update" :canResource="$application" id="application.build_pack" label="Build Pack">...</x-forms.select>
<x-forms.checkbox instantSave canGate="update" :canResource="$application" id="application.settings.is_static" label="Static Site" />
<x-forms.button canGate="update" :canResource="$application" type="submit">Save</x-forms.button>
```

### Service Configuration Forms
```html
<!-- Service stack configuration with authorization -->
<x-forms.input canGate="update" :canResource="$service" id="service.name" label="Service Name" />
<x-forms.input canGate="update" :canResource="$service" id="service.description" label="Description" />
<x-forms.checkbox canGate="update" :canResource="$service" instantSave id="service.connect_to_docker_network" label="Connect To Predefined Network" />
<x-forms.button canGate="update" :canResource="$service" type="submit">Save</x-forms.button>

<!-- Service-specific fields -->
<x-forms.input canGate="update" :canResource="$service" type="{{ data_get($field, 'isPassword') ? 'password' : 'text' }}"
    required="{{ str(data_get($field, 'rules'))?->contains('required') }}"
    id="fields.{{ $serviceName }}.value"></x-forms.input>

<!-- Service restart modal - wrapped with @can -->
@can('update', $service)
    <x-modal-confirmation title="Confirm Service Application Restart?"
        buttonTitle="Restart"
        submitAction="restartApplication({{ $application->id }})" />
@endcan
```

### Server Management Forms
```html
<!-- Server configuration with appropriate gates -->
<x-forms.input canGate="update" :canResource="$server" id="server.name" label="Server Name" />
<x-forms.select canGate="update" :canResource="$server" id="server.type" label="Server Type">...</x-forms.select>
<x-forms.button canGate="delete" :canResource="$server" wire:click="deleteServer">Delete Server</x-forms.button>
```

### Resource Creation Forms
```html
<!-- New resource creation -->
<x-forms.input canGate="createAnyResource" :canResource="auth()->user()->currentTeam" id="name" label="Name" />
<x-forms.select canGate="createAnyResource" :canResource="auth()->user()->currentTeam" id="server_id" label="Server">...</x-forms.select>
<x-forms.button canGate="createAnyResource" :canResource="auth()->user()->currentTeam" type="submit">Create Application</x-forms.button>
```