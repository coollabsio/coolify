<nav x-init="$wire.check_status" wire:poll.10000ms="check_status">
    @persist($application->uuid)
    <x-resources.breadcrumbs :resource="$application" :parameters="$parameters" />
    <x-applications.navbar :application="$application" :parameters="$parameters" />
    @endpersist
</nav>
