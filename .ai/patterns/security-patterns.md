# Coolify Security Architecture & Patterns

## Security Philosophy

Coolify implements **defense-in-depth security** with multiple layers of protection including authentication, authorization, encryption, network isolation, and secure deployment practices.

## Authentication Architecture

### Multi-Provider Authentication
- **[Laravel Fortify](mdc:config/fortify.php)** - Core authentication scaffolding (4.9KB, 149 lines)
- **[Laravel Sanctum](mdc:config/sanctum.php)** - API token authentication (2.4KB, 69 lines)
- **[Laravel Socialite](mdc:config/services.php)** - OAuth provider integration

### OAuth Integration
- **[OauthSetting.php](mdc:app/Models/OauthSetting.php)** - OAuth provider configurations
- **Supported Providers**:
  - Google OAuth
  - Microsoft Azure AD
  - Clerk
  - Authentik
  - Discord
  - GitHub (via GitHub Apps)
  - GitLab

### Authentication Models
```php
// User authentication with team-based access
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;
    
    protected $fillable = [
        'name', 'email', 'password'
    ];
    
    protected $hidden = [
        'password', 'remember_token'
    ];
    
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];
    
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class)
            ->withPivot('role')
            ->withTimestamps();
    }
    
    public function currentTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'current_team_id');
    }
}
```

## Authorization & Access Control

### Enhanced Form Component Authorization System

Coolify now features a **centralized authorization system** built into all form components (`Input`, `Select`, `Textarea`, `Checkbox`, `Button`) that automatically handles permission-based UI control.

#### Component Authorization Parameters
```php
// Available on all form components
public ?string $canGate = null;        // Gate name (e.g., 'update', 'view', 'delete')
public mixed $canResource = null;      // Resource to check against (model instance)
public bool $autoDisable = true;       // Auto-disable if no permission (default: true)
```

#### Smart Authorization Logic
```php
// Automatic authorization handling in component constructor
if ($this->canGate && $this->canResource && $this->autoDisable) {
    $hasPermission = Gate::allows($this->canGate, $this->canResource);
    
    if (! $hasPermission) {
        $this->disabled = true;
        // For Checkbox: also disables instantSave
    }
}
```

#### Usage Examples

**✅ Recommended Pattern (Single Line):**
```html
<!-- Input with automatic authorization -->
<x-forms.input 
    canGate="update" 
    :canResource="$application" 
    id="application.name" 
    label="Application Name" />

<!-- Select with automatic authorization -->
<x-forms.select 
    canGate="update" 
    :canResource="$application" 
    id="application.build_pack" 
    label="Build Pack">
    <option value="nixpacks">Nixpacks</option>
    <option value="static">Static</option>
</x-forms.select>

<!-- Checkbox with automatic instantSave control -->
<x-forms.checkbox 
    instantSave 
    canGate="update" 
    :canResource="$application" 
    id="application.settings.is_static" 
    label="Is Static Site?" />

<!-- Button with automatic disable -->
<x-forms.button 
    canGate="update" 
    :canResource="$application" 
    type="submit">
    Save Configuration
</x-forms.button>
```

**❌ Old Pattern (Verbose, Deprecated):**
```html
<!-- DON'T use this repetitive pattern anymore -->
@can('update', $application)
    <x-forms.input id="application.name" label="Application Name" />
    <x-forms.button type="submit">Save</x-forms.button>
@else
    <x-forms.input disabled id="application.name" label="Application Name" />
@endcan
```

#### Advanced Usage with Custom Control

**Custom Authorization Logic:**
```html
<!-- Disable auto-control, use custom logic -->
<x-forms.input 
    canGate="update" 
    :canResource="$application" 
    autoDisable="false"
    :disabled="$application->is_deployed || !Gate::allows('update', $application)"
    id="advanced.setting" 
    label="Advanced Setting" />
```

**Multiple Permission Checks:**
```html
<!-- Complex permission requirements -->
<x-forms.checkbox 
    canGate="deploy" 
    :canResource="$application" 
    autoDisable="false"
    :disabled="!$application->canDeploy() || !auth()->user()->hasAdvancedPermissions()"
    id="deployment.setting" 
    label="Advanced Deployment Setting" />
```

#### Supported Gates and Resources

**Common Gates:**
- `view` - Read access to resource
- `update` - Modify resource configuration
- `deploy` - Deploy/restart resource
- `delete` - Remove resource
- `createAnyResource` - Create new resources

**Resource Types:**
- `Application` - Application instances
- `Service` - Docker Compose services  
- `Server` - Server instances
- `Project` - Project containers
- `Environment` - Environment contexts
- `Database` - Database instances

#### Benefits

**🔥 Massive Code Reduction:**
- **90% less code** for authorization-protected forms
- **Single line** instead of 6-12 lines per form element
- **No more @can/@else blocks** cluttering templates

**🛡️ Consistent Security:**
- **Unified authorization logic** across all form components
- **Automatic disabling** for unauthorized users
- **Smart behavior** (like disabling instantSave on checkboxes)

**🎨 Better UX:**
- **Consistent disabled styling** across all components
- **Proper visual feedback** for restricted access
- **Clean, professional interface**

#### Implementation Details

**Component Enhancement:**
```php
// Enhanced in all form components
use Illuminate\Support\Facades\Gate;

public function __construct(
    // ... existing parameters
    public ?string $canGate = null,
    public mixed $canResource = null,
    public bool $autoDisable = true,
) {
    // Handle authorization-based disabling
    if ($this->canGate && $this->canResource && $this->autoDisable) {
        $hasPermission = Gate::allows($this->canGate, $this->canResource);
        
        if (! $hasPermission) {
            $this->disabled = true;
            // For Checkbox: $this->instantSave = false;
        }
    }
}
```

**Backward Compatibility:**
- All existing form components continue to work unchanged
- New authorization parameters are optional
- Legacy @can/@else patterns still function but are discouraged

### Custom Component Authorization Patterns

When dealing with **custom Alpine.js components** or complex UI elements that don't use the standard `x-forms.*` components, manual authorization protection is required since the automatic `canGate` system only applies to enhanced form components.

#### Common Custom Components Requiring Manual Protection

**⚠️ Custom Components That Need Manual Authorization:**
- Custom dropdowns/selects with Alpine.js
- Complex form widgets with JavaScript interactions
- Multi-step wizards or dynamic forms
- Third-party component integrations
- Custom date/time pickers
- File upload components with drag-and-drop

#### Manual Authorization Pattern

**✅ Proper Manual Authorization:**
```html
<!-- Custom timezone dropdown example -->
<div class="w-full">
    <div class="flex items-center mb-1">
        <label for="customComponent">Component Label</label>
        <x-helper helper="Component description" />
    </div>
    @can('update', $resource)
        <!-- Full interactive component for authorized users -->
        <div x-data="{
            open: false,
            value: '{{ $currentValue }}',
            options: @js($options),
            init() { /* Alpine.js initialization */ }
        }">
            <input x-model="value" @focus="open = true" 
                   wire:model="propertyName" class="w-full input">
            <div x-show="open">
                <!-- Interactive dropdown content -->
                <template x-for="option in options" :key="option">
                    <div @click="value = option; open = false; $wire.submit()"
                         x-text="option"></div>
                </template>
            </div>
        </div>
    @else
        <!-- Read-only version for unauthorized users -->
        <div class="relative">
            <input readonly disabled autocomplete="off"
                   class="w-full input opacity-50 cursor-not-allowed" 
                   value="{{ $currentValue ?: 'No value set' }}">
            <svg class="absolute right-0 mr-2 w-4 h-4 opacity-50">
                <!-- Disabled icon -->
            </svg>
        </div>
    @endcan
</div>
```

#### Implementation Checklist

When implementing authorization for custom components:

**🔍 1. Identify Custom Components:**
- Look for Alpine.js `x-data` declarations
- Find components not using `x-forms.*` prefix
- Check for JavaScript-heavy interactions
- Review complex form widgets

**🛡️ 2. Wrap with Authorization:**
- Use `@can('gate', $resource)` / `@else` / `@endcan` structure
- Provide full functionality in the `@can` block
- Create disabled/readonly version in the `@else` block

**🎨 3. Design Disabled State:**
- Apply `readonly disabled` attributes to inputs
- Add `opacity-50 cursor-not-allowed` classes for visual feedback
- Remove interactive JavaScript behaviors
- Show current value or appropriate placeholder

**🔒 4. Backend Protection:**
- Ensure corresponding Livewire methods check authorization
- Add `$this->authorize('gate', $resource)` in relevant methods
- Validate permissions before processing any changes

#### Real-World Examples

**Custom Date Range Picker:**
```html
@can('update', $application)
    <div x-data="dateRangePicker()" class="date-picker">
        <!-- Interactive date picker with calendar -->
    </div>
@else
    <div class="flex gap-2">
        <input readonly disabled value="{{ $startDate }}" class="input opacity-50">
        <input readonly disabled value="{{ $endDate }}" class="input opacity-50">
    </div>
@endcan
```

**Multi-Select Component:**
```html
@can('update', $server)
    <div x-data="multiSelect({ options: @js($options) })">
        <!-- Interactive multi-select with checkboxes -->
    </div>
@else
    <div class="space-y-2">
        @foreach($selectedValues as $value)
            <div class="px-3 py-1 bg-gray-100 rounded text-sm opacity-50">
                {{ $value }}
            </div>
        @endforeach
    </div>
@endcan
```

**File Upload Widget:**
```html
@can('update', $application)
    <div x-data="fileUploader()" @drop.prevent="handleDrop">
        <!-- Drag-and-drop file upload interface -->
    </div>
@else
    <div class="border-2 border-dashed border-gray-300 rounded-lg p-6 text-center opacity-50">
        <p class="text-gray-500">File upload restricted</p>
        @if($currentFile)
            <p class="text-sm">Current: {{ $currentFile }}</p>
        @endif
    </div>
@endcan
```

#### Key Principles

**🎯 Consistency:**
- Maintain similar visual styling between enabled/disabled states
- Use consistent disabled patterns across the application
- Apply the same opacity and cursor styling

**🔐 Security First:**
- Always implement backend authorization checks
- Never rely solely on frontend hiding/disabling
- Validate permissions on every server action

**💡 User Experience:**
- Show current values in disabled state when appropriate
- Provide clear visual feedback about restricted access
- Maintain layout stability between states

**🚀 Performance:**
- Minimize Alpine.js initialization for disabled components
- Avoid loading unnecessary JavaScript for unauthorized users
- Use simple HTML structures for read-only states

### Team-Based Multi-Tenancy
- **[Team.php](mdc:app/Models/Team.php)** - Multi-tenant organization structure (8.9KB, 308 lines)
- **[TeamInvitation.php](mdc:app/Models/TeamInvitation.php)** - Secure team collaboration
- **Role-based permissions** within teams
- **Resource isolation** by team ownership

### Authorization Patterns
```php
// Team-scoped authorization middleware
class EnsureTeamAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $teamId = $request->route('team');
        
        if (!$user->teams->contains('id', $teamId)) {
            abort(403, 'Access denied to team resources');
        }
        
        // Set current team context
        $user->switchTeam($teamId);
        
        return $next($request);
    }
}

// Resource-level authorization policies
class ApplicationPolicy
{
    public function view(User $user, Application $application): bool
    {
        return $user->teams->contains('id', $application->team_id);
    }
    
    public function deploy(User $user, Application $application): bool
    {
        return $this->view($user, $application) && 
               $user->hasTeamPermission($application->team_id, 'deploy');
    }
    
    public function delete(User $user, Application $application): bool
    {
        return $this->view($user, $application) && 
               $user->hasTeamRole($application->team_id, 'admin');
    }
}
```

### Global Scopes for Data Isolation
```php
// Automatic team-based filtering
class Application extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope('team', function (Builder $builder) {
            if (auth()->check() && auth()->user()->currentTeam) {
                $builder->whereHas('environment.project', function ($query) {
                    $query->where('team_id', auth()->user()->currentTeam->id);
                });
            }
        });
    }
}
```

## API Security

### Token-Based Authentication
```php
// Sanctum API token management
class PersonalAccessToken extends Model
{
    protected $fillable = [
        'name', 'token', 'abilities', 'expires_at'
    ];
    
    protected $casts = [
        'abilities' => 'array',
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];
    
    public function tokenable(): MorphTo
    {
        return $this->morphTo();
    }
    
    public function hasAbility(string $ability): bool
    {
        return in_array('*', $this->abilities) || 
               in_array($ability, $this->abilities);
    }
}
```

### API Rate Limiting
```php
// Rate limiting configuration
RateLimiter::for('api', function (Request $request) {
    return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
});

RateLimiter::for('deployments', function (Request $request) {
    return Limit::perMinute(10)->by($request->user()->id);
});

RateLimiter::for('webhooks', function (Request $request) {
    return Limit::perMinute(100)->by($request->ip());
});
```

### API Input Validation
```php
// Comprehensive input validation
class StoreApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Application::class);
    }
    
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255|regex:/^[a-zA-Z0-9\-_]+$/',
            'git_repository' => 'required|url|starts_with:https://',
            'git_branch' => 'required|string|max:100|regex:/^[a-zA-Z0-9\-_\/]+$/',
            'server_id' => 'required|exists:servers,id',
            'environment_id' => 'required|exists:environments,id',
            'environment_variables' => 'array',
            'environment_variables.*' => 'string|max:1000',
        ];
    }
    
    public function prepareForValidation(): void
    {
        $this->merge([
            'name' => strip_tags($this->name),
            'git_repository' => filter_var($this->git_repository, FILTER_SANITIZE_URL),
        ]);
    }
}
```

## SSH Security

### Private Key Management
- **[PrivateKey.php](mdc:app/Models/PrivateKey.php)** - Secure SSH key storage (6.5KB, 247 lines)
- **Encrypted key storage** in database
- **Key rotation** capabilities
- **Access logging** for key usage

### SSH Connection Security
```php
class SshConnection
{
    private string $host;
    private int $port;
    private string $username;
    private PrivateKey $privateKey;
    
    public function __construct(Server $server)
    {
        $this->host = $server->ip;
        $this->port = $server->port;
        $this->username = $server->user;
        $this->privateKey = $server->privateKey;
    }
    
    public function connect(): bool
    {
        $connection = ssh2_connect($this->host, $this->port);
        
        if (!$connection) {
            throw new SshConnectionException('Failed to connect to server');
        }
        
        // Use private key authentication
        $privateKeyContent = decrypt($this->privateKey->private_key);
        $publicKeyContent = decrypt($this->privateKey->public_key);
        
        if (!ssh2_auth_pubkey_file($connection, $this->username, $publicKeyContent, $privateKeyContent)) {
            throw new SshAuthenticationException('SSH authentication failed');
        }
        
        return true;
    }
    
    public function execute(string $command): string
    {
        // Sanitize command to prevent injection
        $command = escapeshellcmd($command);
        
        $stream = ssh2_exec($this->connection, $command);
        
        if (!$stream) {
            throw new SshExecutionException('Failed to execute command');
        }
        
        return stream_get_contents($stream);
    }
}
```

## Container Security

### Docker Security Patterns
```php
class DockerSecurityService
{
    public function createSecureContainer(Application $application): array
    {
        return [
            'image' => $this->validateImageName($application->docker_image),
            'user' => '1000:1000', // Non-root user
            'read_only' => true,
            'no_new_privileges' => true,
            'security_opt' => [
                'no-new-privileges:true',
                'apparmor:docker-default'
            ],
            'cap_drop' => ['ALL'],
            'cap_add' => ['CHOWN', 'SETUID', 'SETGID'], // Minimal capabilities
            'tmpfs' => [
                '/tmp' => 'rw,noexec,nosuid,size=100m',
                '/var/tmp' => 'rw,noexec,nosuid,size=50m'
            ],
            'ulimits' => [
                'nproc' => 1024,
                'nofile' => 1024
            ]
        ];
    }
    
    private function validateImageName(string $image): string
    {
        // Validate image name against allowed registries
        $allowedRegistries = ['docker.io', 'ghcr.io', 'quay.io'];
        
        $parser = new DockerImageParser();
        $parsed = $parser->parse($image);
        
        if (!in_array($parsed['registry'], $allowedRegistries)) {
            throw new SecurityException('Image registry not allowed');
        }
        
        return $image;
    }
}
```

### Network Isolation
```yaml
# Docker Compose security configuration
version: '3.8'
services:
  app:
    image: ${APP_IMAGE}
    networks:
      - app-network
    security_opt:
      - no-new-privileges:true
      - apparmor:docker-default
    read_only: true
    tmpfs:
      - /tmp:rw,noexec,nosuid,size=100m
    cap_drop:
      - ALL
    cap_add:
      - CHOWN
      - SETUID
      - SETGID

networks:
  app-network:
    driver: bridge
    internal: true
    ipam:
      config:
        - subnet: 172.20.0.0/16
```

## SSL/TLS Security

### Certificate Management
- **[SslCertificate.php](mdc:app/Models/SslCertificate.php)** - SSL certificate automation
- **Let's Encrypt** integration for free certificates
- **Automatic renewal** and monitoring
- **Custom certificate** upload support

### SSL Configuration
```php
class SslCertificateService
{
    public function generateCertificate(Application $application): SslCertificate
    {
        $domains = $this->validateDomains($application->getAllDomains());
        
        $certificate = SslCertificate::create([
            'application_id' => $application->id,
            'domains' => $domains,
            'provider' => 'letsencrypt',
            'status' => 'pending'
        ]);
        
        // Generate certificate using ACME protocol
        $acmeClient = new AcmeClient();
        $certData = $acmeClient->generateCertificate($domains);
        
        $certificate->update([
            'certificate' => encrypt($certData['certificate']),
            'private_key' => encrypt($certData['private_key']),
            'chain' => encrypt($certData['chain']),
            'expires_at' => $certData['expires_at'],
            'status' => 'active'
        ]);
        
        return $certificate;
    }
    
    private function validateDomains(array $domains): array
    {
        foreach ($domains as $domain) {
            if (!filter_var($domain, FILTER_VALIDATE_DOMAIN)) {
                throw new InvalidDomainException("Invalid domain: {$domain}");
            }
            
            // Check domain ownership
            if (!$this->verifyDomainOwnership($domain)) {
                throw new DomainOwnershipException("Domain ownership verification failed: {$domain}");
            }
        }
        
        return $domains;
    }
}
```

## Environment Variable Security

### Secure Configuration Management
```php
class EnvironmentVariable extends Model
{
    protected $fillable = [
        'key', 'value', 'is_secret', 'application_id'
    ];
    
    protected $casts = [
        'is_secret' => 'boolean',
        'value' => 'encrypted' // Automatic encryption for sensitive values
    ];
    
    public function setValueAttribute($value): void
    {
        // Automatically encrypt sensitive environment variables
        if ($this->isSensitiveKey($this->key)) {
            $this->attributes['value'] = encrypt($value);
            $this->attributes['is_secret'] = true;
        } else {
            $this->attributes['value'] = $value;
        }
    }
    
    public function getValueAttribute($value): string
    {
        if ($this->is_secret) {
            return decrypt($value);
        }
        
        return $value;
    }
    
    private function isSensitiveKey(string $key): bool
    {
        $sensitivePatterns = [
            'PASSWORD', 'SECRET', 'KEY', 'TOKEN', 'API_KEY',
            'DATABASE_URL', 'REDIS_URL', 'PRIVATE', 'CREDENTIAL',
            'AUTH', 'CERTIFICATE', 'ENCRYPTION', 'SALT', 'HASH',
            'OAUTH', 'JWT', 'BEARER', 'ACCESS', 'REFRESH'
        ];
        
        foreach ($sensitivePatterns as $pattern) {
            if (str_contains(strtoupper($key), $pattern)) {
                return true;
            }
        }
        
        return false;
    }
}
```

## Webhook Security

### Webhook Signature Verification
```php
class WebhookSecurityService
{
    public function verifyGitHubSignature(Request $request, string $secret): bool
    {
        $signature = $request->header('X-Hub-Signature-256');
        
        if (!$signature) {
            return false;
        }
        
        $expectedSignature = 'sha256=' . hash_hmac('sha256', $request->getContent(), $secret);
        
        return hash_equals($expectedSignature, $signature);
    }
    
    public function verifyGitLabSignature(Request $request, string $secret): bool
    {
        $signature = $request->header('X-Gitlab-Token');
        
        return hash_equals($secret, $signature);
    }
    
    public function validateWebhookPayload(array $payload): array
    {
        // Sanitize and validate webhook payload
        $validator = Validator::make($payload, [
            'repository.clone_url' => 'required|url|starts_with:https://',
            'ref' => 'required|string|max:255',
            'head_commit.id' => 'required|string|size:40', // Git SHA
            'head_commit.message' => 'required|string|max:1000'
        ]);
        
        if ($validator->fails()) {
            throw new InvalidWebhookPayloadException('Invalid webhook payload');
        }
        
        return $validator->validated();
    }
}
```

## Input Sanitization & Validation

### XSS Prevention
```php
class SecurityMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        // Sanitize input data
        $input = $request->all();
        $sanitized = $this->sanitizeInput($input);
        $request->merge($sanitized);
        
        return $next($request);
    }
    
    private function sanitizeInput(array $input): array
    {
        foreach ($input as $key => $value) {
            if (is_string($value)) {
                // Remove potentially dangerous HTML tags
                $input[$key] = strip_tags($value, '<p><br><strong><em>');
                
                // Escape special characters
                $input[$key] = htmlspecialchars($input[$key], ENT_QUOTES, 'UTF-8');
            } elseif (is_array($value)) {
                $input[$key] = $this->sanitizeInput($value);
            }
        }
        
        return $input;
    }
}
```

### SQL Injection Prevention
```php
// Always use parameterized queries and Eloquent ORM
class ApplicationRepository
{
    public function findByName(string $name): ?Application
    {
        // Safe: Uses parameter binding
        return Application::where('name', $name)->first();
    }
    
    public function searchApplications(string $query): Collection
    {
        // Safe: Eloquent handles escaping
        return Application::where('name', 'LIKE', "%{$query}%")
            ->orWhere('description', 'LIKE', "%{$query}%")
            ->get();
    }
    
    // NEVER do this - vulnerable to SQL injection
    // public function unsafeSearch(string $query): Collection
    // {
    //     return DB::select("SELECT * FROM applications WHERE name LIKE '%{$query}%'");
    // }
}
```

## Audit Logging & Monitoring

### Activity Logging
```php
// Using Spatie Activity Log package
class Application extends Model
{
    use LogsActivity;
    
    protected static $logAttributes = [
        'name', 'git_repository', 'git_branch', 'fqdn'
    ];
    
    protected static $logOnlyDirty = true;
    
    public function getDescriptionForEvent(string $eventName): string
    {
        return "Application {$this->name} was {$eventName}";
    }
}

// Custom security events
class SecurityEventLogger
{
    public function logFailedLogin(string $email, string $ip): void
    {
        activity('security')
            ->withProperties([
                'email' => $email,
                'ip' => $ip,
                'user_agent' => request()->userAgent()
            ])
            ->log('Failed login attempt');
    }
    
    public function logSuspiciousActivity(User $user, string $activity): void
    {
        activity('security')
            ->causedBy($user)
            ->withProperties([
                'activity' => $activity,
                'ip' => request()->ip(),
                'timestamp' => now()
            ])
            ->log('Suspicious activity detected');
    }
}
```

### Security Monitoring
```php
class SecurityMonitoringService
{
    public function detectAnomalousActivity(User $user): bool
    {
        // Check for unusual login patterns
        $recentLogins = $user->activities()
            ->where('description', 'like', '%login%')
            ->where('created_at', '>=', now()->subHours(24))
            ->get();
        
        // Multiple failed attempts
        $failedAttempts = $recentLogins->where('description', 'Failed login attempt')->count();
        if ($failedAttempts > 5) {
            $this->triggerSecurityAlert($user, 'Multiple failed login attempts');
            return true;
        }
        
        // Login from new location
        $uniqueIps = $recentLogins->pluck('properties.ip')->unique();
        if ($uniqueIps->count() > 3) {
            $this->triggerSecurityAlert($user, 'Login from multiple IP addresses');
            return true;
        }
        
        return false;
    }
    
    private function triggerSecurityAlert(User $user, string $reason): void
    {
        // Send security notification
        $user->notify(new SecurityAlertNotification($reason));
        
        // Log security event
        activity('security')
            ->causedBy($user)
            ->withProperties(['reason' => $reason])
            ->log('Security alert triggered');
    }
}
```

## Backup Security

### Encrypted Backups
```php
class SecureBackupService
{
    public function createEncryptedBackup(ScheduledDatabaseBackup $backup): void
    {
        $database = $backup->database;
        $dumpPath = $this->createDatabaseDump($database);
        
        // Encrypt backup file
        $encryptedPath = $this->encryptFile($dumpPath, $backup->encryption_key);
        
        // Upload to secure storage
        $this->uploadToSecureStorage($encryptedPath, $backup->s3Storage);
        
        // Clean up local files
        unlink($dumpPath);
        unlink($encryptedPath);
    }
    
    private function encryptFile(string $filePath, string $key): string
    {
        $data = file_get_contents($filePath);
        $encryptedData = encrypt($data, $key);
        
        $encryptedPath = $filePath . '.encrypted';
        file_put_contents($encryptedPath, $encryptedData);
        
        return $encryptedPath;
    }
}
```

## Security Headers & CORS

### Security Headers Configuration
```php
// Security headers middleware
class SecurityHeadersMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-XSS-Protection', '1; mode=block');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');
        
        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        
        return $response;
    }
}
```

### CORS Configuration
```php
// CORS configuration for API endpoints
return [
    'paths' => ['api/*', 'webhooks/*'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
    'allowed_origins' => [
        'https://app.coolify.io',
        'https://*.coolify.io'
    ],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];
```

## Security Testing

### Security Test Patterns
```php
// Security-focused tests
test('prevents SQL injection in search', function () {
    $user = User::factory()->create();
    $maliciousInput = "'; DROP TABLE applications; --";
    
    $response = $this->actingAs($user)
        ->getJson("/api/v1/applications?search={$maliciousInput}");
    
    $response->assertStatus(200);
    
    // Verify applications table still exists
    expect(Schema::hasTable('applications'))->toBeTrue();
});

test('prevents XSS in application names', function () {
    $user = User::factory()->create();
    $xssPayload = '<script>alert("xss")</script>';
    
    $response = $this->actingAs($user)
        ->postJson('/api/v1/applications', [
            'name' => $xssPayload,
            'git_repository' => 'https://github.com/user/repo.git',
            'server_id' => Server::factory()->create()->id
        ]);
    
    $response->assertStatus(422);
});

test('enforces team isolation', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    
    $team1 = Team::factory()->create();
    $team2 = Team::factory()->create();
    
    $user1->teams()->attach($team1);
    $user2->teams()->attach($team2);
    
    $application = Application::factory()->create(['team_id' => $team1->id]);
    
    $response = $this->actingAs($user2)
        ->getJson("/api/v1/applications/{$application->id}");
    
    $response->assertStatus(403);
});
```
