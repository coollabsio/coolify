@props(['backLabel', 'backTitle' => 'Back to the main menu', 'depth' => 0, 'navOnly' => false])
@php
    // The main sidebar and the mobile drawer replace their menu with this one (see components/navbar.blade.php).
    registerMainNavContext($depth, $backLabel, $backTitle);
@endphp
{{-- A page menu, rendered twice: in the page (CSS hides it), and teleported into the
     main sidebar. The slot must not contain Livewire components (they would render twice).
     navOnly renders only the sidebar copy: a deeper page uses it to keep its parent menu one Back away. --}}
@unless ($navOnly)
    <aside {{ $attributes->class('application-settings-navigation in-main-nav min-w-0') }}>
        {{ $slot }}
    </aside>
@endunless
@teleport('#main-nav-context-'.$depth)
    <div class="nav-context-menu">
        {{ $slot }}
    </div>
@endteleport
