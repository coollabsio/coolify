# Blade and View Best Practices

## Use `$attributes->merge()` in Component Templates

Use the component attribute bag so callers can add attributes. `merge()` combines default attributes with caller-provided values; class values receive special merging behavior.

```blade
<div {{ $attributes->merge(['class' => 'alert alert-'.$type]) }}>
    {{ $message }}
</div>
```

## Use `@pushOnce` for Per-Component Scripts

If a component renders repeatedly, `@push` adds its script on every render. Use a consistently named `@pushOnce` block to add that content once per rendered response.

## Prefer Components for Explicit Interfaces

Use a Blade component when a reusable interface benefits from explicit props, an attribute bag, or slots. An include remains suitable for a small partial that intentionally uses the current view data; pass an explicit data array when implicit variable sharing would obscure its dependencies.

## Share Compatible View Data with a View Composer

Use a view composer to centralize data needed whenever one or more named Blade views are rendered. Keep the composer compatible with every view it targets, and avoid broad wildcards when views require different data shapes. A view composer runs when Laravel renders the matching view; it does not supply data to JSON, streamed, or other non-view responses.

## Return Blade Fragments for Partial Rendering

A route can return either a full view or a named fragment for clients such as htmx or Turbo.

```php
return view('dashboard', compact('users'))
    ->fragmentIf($request->hasHeader('HX-Request'), 'user-list');
```

## Share Parent Component Props with `@aware`

Use `@aware` when a nested component needs a prop explicitly passed to an ancestor component. It does not expose an ancestor's default prop value unless that value was passed through the attribute bag.
