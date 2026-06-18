---
name: livewire-development
description: "Use for any task or question involving Livewire. Activate if user mentions Livewire, wire: directives, or Livewire-specific concepts like wire:model, wire:click, invoke this skill. Covers building new components, debugging reactivity issues, real-time form validation, loading states, migrating from Livewire 2 to 3, converting component formats (SFC/MFC/class-based), and performance optimization. Do not use for non-Livewire reactive UI (React, Vue, Alpine-only, Inertia.js) or standard Laravel forms without Livewire."
license: MIT
metadata:
  author: laravel
---

# Livewire Development

## Documentation

Use `search-docs` for detailed Livewire 3 patterns and documentation.

## Basic Usage

### Creating Components

Use the `php artisan make:livewire [Posts\CreatePost]` Artisan command to create new components.

### Fundamental Concepts

- State should live on the server, with the UI reflecting it.
- All Livewire requests hit the Laravel backend; they're like regular HTTP requests. Always validate form data and run authorization checks in Livewire actions.

## Livewire 3 Specifics

### Key Changes From Livewire 2

These things changed in Livewire 3, but may not have been updated in this application. Verify this application's setup to ensure you follow existing conventions.
- Use `wire:model.live` for real-time updates, `wire:model` is now deferred by default.
- Components now use the `App\Livewire` namespace (not `App\Http\Livewire`).
- Use `$this->dispatch()` to dispatch events (not `emit` or `dispatchBrowserEvent`).
- Use the `components.layouts.app` view as the typical layout path (not `layouts.app`).

### New Directives

- `wire:show`, `wire:transition`, `wire:cloak`, `wire:offline`, `wire:target` are available for use.

### Alpine Integration

- Alpine is now included with Livewire; don't manually include Alpine.js.
- Plugins included with Alpine: persist, intersect, collapse, and focus.

## Best Practices

### Component Structure

- Livewire components require a single root element.
- Use `wire:loading` and `wire:dirty` for delightful loading states.

### Using Keys in Loops

<!-- Wire Key in Loops -->
```blade
@foreach ($items as $item)
    <div wire:key="item-{{ $item->id }}">
        {{ $item->name }}
    </div>
@endforeach
```

### Lifecycle Hooks

Prefer lifecycle hooks like `mount()`, `updatedFoo()` for initialization and reactive side effects:

<!-- Lifecycle Hook Examples -->
```php
public function mount(User $user) { $this->user = $user; }
public function updatedSearch() { $this->resetPage(); }
```

## JavaScript Hooks

You can listen for `livewire:init` to hook into Livewire initialization:

<!-- Livewire Init Hook Example -->
```js
document.addEventListener('livewire:init', function () {
    Livewire.hook('request', ({ fail }) => {
        if (fail && fail.status === 419) {
            alert('Your session expired');
        }
    });

    Livewire.hook('message.failed', (message, component) => {
        console.error(message);
    });
});
```

## Testing

<!-- Example Livewire Component Test -->
```php
Livewire::test(Counter::class)
    ->assertSet('count', 0)
    ->call('increment')
    ->assertSet('count', 1)
    ->assertSee(1)
    ->assertStatus(200);
```

<!-- Testing Livewire Component Exists on Page -->
```php
$this->get('/posts/create')
    ->assertSeeLivewire(CreatePost::class);
```

## Common Pitfalls

- Forgetting `wire:key` in loops causes unexpected behavior when items change
- Using `wire:model` expecting real-time updates (use `wire:model.live` instead in v3)
- Not validating/authorizing in Livewire actions (treat them like HTTP requests)
- Including Alpine.js separately when it's already bundled with Livewire 3