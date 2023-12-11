<nav wire:poll.30000ms="check_status">
    <x-resources.breadcrumbs :resource="$application" :parameters="$parameters" />
    <x-applications.navbar :application="$application" :parameters="$parameters" />
</nav>
