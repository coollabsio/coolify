# Coolify Frontend Architecture & Patterns

## Frontend Philosophy

Coolify uses a **server-side first** approach with minimal JavaScript, leveraging Livewire for reactivity and Alpine.js for lightweight client-side interactions.

## Core Frontend Stack

### Livewire 3.5+ (Primary Framework)
- **Server-side rendering** with reactive components
- **Real-time updates** without page refreshes
- **State management** handled on the server
- **WebSocket integration** for live updates

### Alpine.js (Client-Side Interactivity)
- **Lightweight JavaScript** for DOM manipulation
- **Declarative directives** in HTML
- **Component-like behavior** without build steps
- **Perfect companion** to Livewire

### Tailwind CSS 4.1+ (Styling)
- **Utility-first** CSS framework
- **Custom design system** for deployment platform
- **Responsive design** built-in
- **Dark mode support**

## Livewire Component Structure

### Location: [app/Livewire/](mdc:app/Livewire)

#### Core Application Components
- **[Dashboard.php](mdc:app/Livewire/Dashboard.php)** - Main dashboard interface
- **[ActivityMonitor.php](mdc:app/Livewire/ActivityMonitor.php)** - Real-time activity tracking
- **[MonacoEditor.php](mdc:app/Livewire/MonacoEditor.php)** - Code editor component

#### Server Management
- **Server/** directory - Server configuration and monitoring
- Real-time server status updates
- SSH connection management
- Resource monitoring

#### Project & Application Management
- **Project/** directory - Project organization
- Application deployment interfaces
- Environment variable management
- Service configuration

#### Settings & Configuration
- **Settings/** directory - System configuration
- **[SettingsEmail.php](mdc:app/Livewire/SettingsEmail.php)** - Email notification setup
- **[SettingsOauth.php](mdc:app/Livewire/SettingsOauth.php)** - OAuth provider configuration
- **[SettingsBackup.php](mdc:app/Livewire/SettingsBackup.php)** - Backup configuration

#### User & Team Management
- **Team/** directory - Team collaboration features
- **Profile/** directory - User profile management
- **Security/** directory - Security settings

## Blade Template Organization

### Location: [resources/views/](mdc:resources/views)

#### Layout Structure
- **layouts/** - Base layout templates
- **components/** - Reusable UI components
- **livewire/** - Livewire component views

#### Feature-Specific Views
- **server/** - Server management interfaces
- **auth/** - Authentication pages
- **emails/** - Email templates
- **errors/** - Error pages

## Interactive Components

### Monaco Editor Integration
- **Code editing** for configuration files
- **Syntax highlighting** for multiple languages
- **Live validation** and error detection
- **Integration** with deployment process

### Terminal Emulation (XTerm.js)
- **Real-time terminal** access to servers
- **WebSocket-based** communication
- **Multi-session** support
- **Secure connection** through SSH

### Real-Time Updates
- **WebSocket connections** via Laravel Echo
- **Live deployment logs** streaming
- **Server monitoring** with live metrics
- **Activity notifications** in real-time

## Alpine.js Patterns

### Common Directives Used
```html
<!-- State management -->
<div x-data="{ open: false }">

<!-- Event handling -->
<button x-on:click="open = !open">

<!-- Conditional rendering -->
<div x-show="open">

<!-- Data binding -->
<input x-model="searchTerm">

<!-- Component initialization -->
<div x-init="initializeComponent()">
```

### Integration with Livewire
```html
<!-- Livewire actions with Alpine state -->
<button 
    x-data="{ loading: false }"
    x-on:click="loading = true"
    wire:click="deploy"
    wire:loading.attr="disabled"
    wire:target="deploy"
>
    <span x-show="!loading">Deploy</span>
    <span x-show="loading">Deploying...</span>
</button>
```

## Tailwind CSS Patterns

### Design System
- **Consistent spacing** using Tailwind scale
- **Color palette** optimized for deployment platform
- **Typography** hierarchy for technical content
- **Component classes** for reusable elements

### Responsive Design
```html
<!-- Mobile-first responsive design -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3">
    <!-- Content adapts to screen size -->
</div>
```

### Dark Mode Support
```html
<!-- Dark mode variants -->
<div class="bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100">
    <!-- Automatic dark mode switching -->
</div>
```

## Build Process

### Vite Configuration ([vite.config.js](mdc:vite.config.js))
- **Fast development** with hot module replacement
- **Optimized production** builds
- **Asset versioning** for cache busting
- **CSS processing** with PostCSS

### Asset Compilation
```bash
# Development
npm run dev

# Production build
npm run build
```

## State Management Patterns

### Server-Side State (Livewire)
- **Component properties** for persistent state
- **Session storage** for user preferences
- **Database models** for application state
- **Cache layer** for performance

### Client-Side State (Alpine.js)
- **Local component state** for UI interactions
- **Form validation** and user feedback
- **Modal and dropdown** state management
- **Temporary UI states** (loading, hover, etc.)

## Real-Time Features

### WebSocket Integration
```php
// Livewire component with real-time updates
class ActivityMonitor extends Component
{
    public function getListeners()
    {
        return [
            'deployment.started' => 'refresh',
            'deployment.finished' => 'refresh',
            'server.status.changed' => 'updateServerStatus',
        ];
    }
}
```

### Event Broadcasting
- **Laravel Echo** for client-side WebSocket handling
- **Pusher protocol** for real-time communication
- **Private channels** for user-specific events
- **Presence channels** for collaborative features

## Performance Patterns

### Lazy Loading
```php
// Livewire lazy loading
class ServerList extends Component
{
    public function placeholder()
    {
        return view('components.loading-skeleton');
    }
}
```

### Caching Strategies
- **Fragment caching** for expensive operations
- **Image optimization** with lazy loading
- **Asset bundling** and compression
- **CDN integration** for static assets

## Enhanced Form Components

### Built-in Authorization System
Coolify features **enhanced form components** with automatic authorization handling:

```html
<!-- ✅ New Pattern: Single line with built-in authorization -->
<x-forms.input canGate="update" :canResource="$application" id="application.name" label="Name" />
<x-forms.checkbox instantSave canGate="update" :canResource="$application" id="application.settings.is_static" label="Static Site" />
<x-forms.button canGate="update" :canResource="$application" type="submit">Save</x-forms.button>

<!-- ❌ Old Pattern: Verbose @can/@else blocks (deprecated) -->
@can('update', $application)
    <x-forms.input id="application.name" label="Name" />
@else
    <x-forms.input disabled id="application.name" label="Name" />
@endcan
```

### Authorization Parameters
```php
// Available on all form components (Input, Select, Textarea, Checkbox, Button)
public ?string $canGate = null;        // Gate name: 'update', 'view', 'deploy', 'delete'
public mixed $canResource = null;      // Resource model instance to check against
public bool $autoDisable = true;       // Automatically disable if no permission (default: true)
```

### Benefits
- **90% code reduction** for authorization-protected forms
- **Consistent security** across all form components
- **Automatic disabling** for unauthorized users
- **Smart behavior** (disables instantSave on checkboxes for unauthorized users)

For complete documentation, see **[form-components.md](.ai/patterns/form-components.md)**

## Form Handling Patterns

### Livewire Component Data Synchronization Pattern

**IMPORTANT**: All Livewire components must use the **manual `syncData()` pattern** for synchronizing component properties with Eloquent models.

#### Property Naming Convention
- **Component properties**: Use camelCase (e.g., `$gitRepository`, `$isStatic`)
- **Database columns**: Use snake_case (e.g., `git_repository`, `is_static`)
- **View bindings**: Use camelCase matching component properties (e.g., `id="gitRepository"`)

#### The syncData() Method Pattern

```php
use Livewire\Attributes\Validate;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class MyComponent extends Component
{
    use AuthorizesRequests;

    public Application $application;

    // Properties with validation attributes
    #[Validate(['required'])]
    public string $name;

    #[Validate(['string', 'nullable'])]
    public ?string $description = null;

    #[Validate(['boolean', 'required'])]
    public bool $isStatic = false;

    public function mount()
    {
        $this->authorize('view', $this->application);
        $this->syncData(); // Load from model
    }

    public function syncData(bool $toModel = false): void
    {
        if ($toModel) {
            $this->validate();

            // Sync TO model (camelCase → snake_case)
            $this->application->name = $this->name;
            $this->application->description = $this->description;
            $this->application->is_static = $this->isStatic;

            $this->application->save();
        } else {
            // Sync FROM model (snake_case → camelCase)
            $this->name = $this->application->name;
            $this->description = $this->application->description;
            $this->isStatic = $this->application->is_static;
        }
    }

    public function submit()
    {
        $this->authorize('update', $this->application);
        $this->syncData(toModel: true); // Save to model
        $this->dispatch('success', 'Saved successfully.');
    }
}
```

#### Validation with #[Validate] Attributes

All component properties should have `#[Validate]` attributes:

```php
// Boolean properties
#[Validate(['boolean'])]
public bool $isEnabled = false;

// Required strings
#[Validate(['string', 'required'])]
public string $name;

// Nullable strings
#[Validate(['string', 'nullable'])]
public ?string $description = null;

// With constraints
#[Validate(['integer', 'min:1'])]
public int $timeout;
```

#### Benefits of syncData() Pattern

- **Explicit Control**: Clear visibility of what's being synchronized
- **Type Safety**: #[Validate] attributes provide compile-time validation info
- **Easy Debugging**: Single method to check for data flow issues
- **Maintainability**: All sync logic in one place
- **Flexibility**: Can add custom logic (encoding, transformations, etc.)

#### Creating New Form Components with syncData()

#### Step-by-Step Component Creation Guide

**Step 1: Define properties in camelCase with #[Validate] attributes**
```php
use Livewire\Attributes\Validate;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class MyFormComponent extends Component
{
    use AuthorizesRequests;

    // The model we're syncing with
    public Application $application;

    // Component properties in camelCase with validation
    #[Validate(['string', 'required'])]
    public string $name;

    #[Validate(['string', 'nullable'])]
    public ?string $gitRepository = null;

    #[Validate(['string', 'nullable'])]
    public ?string $installCommand = null;

    #[Validate(['boolean'])]
    public bool $isStatic = false;
}
```

**Step 2: Implement syncData() method**
```php
public function syncData(bool $toModel = false): void
{
    if ($toModel) {
        $this->validate();

        // Sync TO model (component camelCase → database snake_case)
        $this->application->name = $this->name;
        $this->application->git_repository = $this->gitRepository;
        $this->application->install_command = $this->installCommand;
        $this->application->is_static = $this->isStatic;

        $this->application->save();
    } else {
        // Sync FROM model (database snake_case → component camelCase)
        $this->name = $this->application->name;
        $this->gitRepository = $this->application->git_repository;
        $this->installCommand = $this->application->install_command;
        $this->isStatic = $this->application->is_static;
    }
}
```

**Step 3: Implement mount() to load initial data**
```php
public function mount()
{
    $this->authorize('view', $this->application);
    $this->syncData(); // Load data from model to component properties
}
```

**Step 4: Implement action methods with authorization**
```php
public function instantSave()
{
    try {
        $this->authorize('update', $this->application);
        $this->syncData(toModel: true); // Save component properties to model
        $this->dispatch('success', 'Settings saved.');
    } catch (\Throwable $e) {
        return handleError($e, $this);
    }
}

public function submit()
{
    try {
        $this->authorize('update', $this->application);
        $this->syncData(toModel: true); // Save component properties to model
        $this->dispatch('success', 'Changes saved successfully.');
    } catch (\Throwable $e) {
        return handleError($e, $this);
    }
}
```

**Step 5: Create Blade view with camelCase bindings**
```blade
<div>
    <form wire:submit="submit">
        <x-forms.input
            canGate="update"
            :canResource="$application"
            id="name"
            label="Name"
            required />

        <x-forms.input
            canGate="update"
            :canResource="$application"
            id="gitRepository"
            label="Git Repository" />

        <x-forms.input
            canGate="update"
            :canResource="$application"
            id="installCommand"
            label="Install Command" />

        <x-forms.checkbox
            instantSave
            canGate="update"
            :canResource="$application"
            id="isStatic"
            label="Static Site" />

        <x-forms.button
            canGate="update"
            :canResource="$application"
            type="submit">
            Save Changes
        </x-forms.button>
    </form>
</div>
```

**Key Points**:
- Use `wire:model="camelCase"` and `id="camelCase"` in Blade views
- Component properties are camelCase, database columns are snake_case
- Always include authorization checks (`authorize()`, `canGate`, `canResource`)
- Use `instantSave` for checkboxes that save immediately without form submission

#### Special Patterns

**Pattern 1: Related Models (e.g., Application → Settings)**
```php
public function syncData(bool $toModel = false): void
{
    if ($toModel) {
        $this->validate();

        // Sync main model
        $this->application->name = $this->name;
        $this->application->save();

        // Sync related model
        $this->application->settings->is_static = $this->isStatic;
        $this->application->settings->save();
    } else {
        // From main model
        $this->name = $this->application->name;

        // From related model
        $this->isStatic = $this->application->settings->is_static;
    }
}
```

**Pattern 2: Custom Encoding/Decoding**
```php
public function syncData(bool $toModel = false): void
{
    if ($toModel) {
        $this->validate();

        // Encode before saving
        $this->application->custom_labels = base64_encode($this->customLabels);
        $this->application->save();
    } else {
        // Decode when loading
        $this->customLabels = $this->application->parseContainerLabels();
    }
}
```

**Pattern 3: Error Rollback**
```php
public function submit()
{
    $this->authorize('update', $this->resource);
    $original = $this->model->getOriginal();

    try {
        $this->syncData(toModel: true);
        $this->dispatch('success', 'Saved successfully.');
    } catch (\Throwable $e) {
        // Rollback on error
        $this->model->setRawAttributes($original);
        $this->model->save();
        $this->syncData(); // Reload from model
        return handleError($e, $this);
    }
}
```

#### Property Type Patterns

**Required Strings**
```php
#[Validate(['string', 'required'])]
public string $name;  // No ?, no default, always has value
```

**Nullable Strings**
```php
#[Validate(['string', 'nullable'])]
public ?string $description = null;  // ?, = null, can be empty
```

**Booleans**
```php
#[Validate(['boolean'])]
public bool $isEnabled = false;  // Always has default value
```

**Integers with Constraints**
```php
#[Validate(['integer', 'min:1'])]
public int $timeout;  // Required

#[Validate(['integer', 'min:1', 'nullable'])]
public ?int $port = null;  // Nullable
```

#### Testing Checklist

After creating a new component with syncData(), verify:

- [ ] All checkboxes save correctly (especially `instantSave` ones)
- [ ] All form inputs persist to database
- [ ] Custom encoded fields (like labels) display correctly if applicable
- [ ] Form validation works for all fields
- [ ] No console errors in browser
- [ ] Authorization checks work (`@can` directives and `authorize()` calls)
- [ ] Error rollback works if exceptions occur
- [ ] Related models save correctly if applicable (e.g., Application + ApplicationSetting)

#### Common Pitfalls to Avoid

1. **snake_case in component properties**: Always use camelCase for component properties (e.g., `$gitRepository` not `$git_repository`)
2. **Missing #[Validate] attributes**: Every property should have validation attributes for type safety
3. **Forgetting to call syncData()**: Must call `syncData()` in `mount()` to load initial data
4. **Missing authorization**: Always use `authorize()` in methods and `canGate`/`canResource` in views
5. **View binding mismatch**: Use camelCase in Blade (e.g., `id="gitRepository"` not `id="git_repository"`)
6. **wire:model vs wire:model.live**: Use `.live` for `instantSave` checkboxes to avoid timing issues
7. **Validation sync**: If using `rules()` method, keep it in sync with `#[Validate]` attributes
8. **Related models**: Don't forget to save both main and related models in syncData() method

### Livewire Forms
```php
class ServerCreateForm extends Component
{
    public $name;
    public $ip;

    protected $rules = [
        'name' => 'required|min:3',
        'ip' => 'required|ip',
    ];

    public function save()
    {
        $this->validate();
        // Save logic
    }
}
```

### Real-Time Validation
- **Live validation** as user types
- **Server-side validation** rules
- **Error message** display
- **Success feedback** patterns

## Component Communication

### Parent-Child Communication
```php
// Parent component
$this->emit('serverCreated', $server->id);

// Child component
protected $listeners = ['serverCreated' => 'refresh'];
```

### Cross-Component Events
- **Global events** for application-wide updates
- **Scoped events** for feature-specific communication
- **Browser events** for JavaScript integration

## Error Handling & UX

### Loading States
- **Skeleton screens** during data loading
- **Progress indicators** for long operations
- **Optimistic updates** with rollback capability

### Error Display
- **Toast notifications** for user feedback
- **Inline validation** errors
- **Global error** handling
- **Retry mechanisms** for failed operations

## Accessibility Patterns

### ARIA Labels and Roles
```html
<button
    aria-label="Deploy application"
    aria-describedby="deploy-help"
    wire:click="deploy"
>
    Deploy
</button>
```

### Keyboard Navigation
- **Tab order** management
- **Keyboard shortcuts** for power users
- **Focus management** in modals and forms
- **Screen reader** compatibility

## Mobile Optimization

### Touch-Friendly Interface
- **Larger tap targets** for mobile devices
- **Swipe gestures** where appropriate
- **Mobile-optimized** forms and navigation

### Progressive Enhancement
- **Core functionality** works without JavaScript
- **Enhanced experience** with JavaScript enabled
- **Offline capabilities** where possible
