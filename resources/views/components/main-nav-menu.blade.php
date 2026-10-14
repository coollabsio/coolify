@props(['backHref', 'backLabel', 'backTitle' => null])
@php
    // The main sidebar replaces its menu with this one on wide screens (see components/navbar.blade.php).
    request()->attributes->set('mainNavContext', [
        'label' => $backLabel,
        'backHref' => $backHref,
        'backTitle' => $backTitle ?? 'Back to '.$backLabel,
    ]);
@endphp
{{-- A page menu, rendered twice: in the page (below xl, CSS hides it on xl), and teleported into the
     main sidebar (xl and up). The slot must not contain Livewire components (they would render twice). --}}
<aside {{ $attributes->class('application-settings-navigation in-main-nav min-w-0 xl:self-start') }}>
    {{ $slot }}
</aside>
@teleport('#main-nav-context')
    <div class="nav-context-menu">
        {{ $slot }}
    </div>
@endteleport
