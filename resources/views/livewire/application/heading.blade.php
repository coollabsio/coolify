<nav x-init="$wire.check_status" wire:poll.10000ms="check_status">
    <x-applications.breadcrumbs :application="$application" :parameters="$parameters" />
    <x-applications.navbar :application="$application" :parameters="$parameters" />
</nav>
