# Command Entrypoint (`asCommand`)

## Scope

Use this reference when exposing actions as Artisan commands.

## Recap

- Documents command execution via `asCommand(...)` and fallback to `handle(...)`.
- Covers command metadata via methods/properties (signature, description, help, hidden).
- Includes registration example and focused artisan test pattern.
- Reinforces separation between console I/O and domain logic.

## Recommended pattern

- Define `$commandSignature` and `$commandDescription`.
- Implement `asCommand(Command $command)` for console I/O.
- Keep business logic in `handle(...)`.

## Methods used (`CommandDecorator`)

### `asCommand`

Called when executed as a command. If missing, it falls back to `handle(...)`.

```php
use Illuminate\Console\Command;

class UpdateUserRole
{
    use AsAction;

    public string $commandSignature = 'users:update-role {user_id} {role}';

    public function handle(User $user, string $newRole): void
    {
        $user->update(['role' => $newRole]);
    }

    public function asCommand(Command $command): void
    {
        $this->handle(
            User::findOrFail($command->argument('user_id')),
            $command->argument('role')
        );

        $command->info('Done!');
    }
}
```

### `getCommandSignature`

Defines the command signature. Required when registering an action as a command if no `$commandSignature` property is set.

```php
public function getCommandSignature(): string
{
    return 'users:update-role {user_id} {role}';
}
```

### `$commandSignature`

Property alternative to `getCommandSignature`.

```php
public string $commandSignature = 'users:update-role {user_id} {role}';
```

### `getCommandDescription`

Provides command description.

```php
public function getCommandDescription(): string
{
    return 'Updates the role of a given user.';
}
```

### `$commandDescription`

Property alternative to `getCommandDescription`.

```php
public string $commandDescription = 'Updates the role of a given user.';
```

### `getCommandHelp`

Provides additional help text shown with `--help`.

```php
public function getCommandHelp(): string
{
    return 'My help message.';
}
```

### `$commandHelp`

Property alternative to `getCommandHelp`.

```php
public string $commandHelp = 'My help message.';
```

### `isCommandHidden`

Defines whether command should be hidden from artisan list. Default is `false`.

```php
public function isCommandHidden(): bool
{
    return true;
}
```

### `$commandHidden`

Property alternative to `isCommandHidden`.

```php
public bool $commandHidden = true;
```

## Examples

### Register in console kernel

```php
// app/Console/Kernel.php
protected $commands = [
    UpdateUserRole::class,
];
```

### Focused command test

```php
$this->artisan('users:update-role 1 admin')
    ->expectsOutput('Done!')
    ->assertSuccessful();
```

## Checklist

- `use Illuminate\Console\Command;` is imported.
- Signature/options/arguments are documented.
- Command test verifies invocation and output.

## Common pitfalls

- Mixing command I/O with domain logic in `handle(...)`.
- Missing/ambiguous command signature.

## References

- https://www.laravelactions.com/2.x/as-command.html