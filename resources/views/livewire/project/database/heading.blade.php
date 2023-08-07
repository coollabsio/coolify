<nav x-init="$wire.check_status" wire:poll.10000ms="check_status">
    <x-resources.breadcrumbs :resource="$database" :parameters="$parameters" />
    <x-databases.navbar :database="$database" :parameters="$parameters" />
</nav>
