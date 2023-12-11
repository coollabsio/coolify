<nav wire:poll.30000ms="check_status">
    <x-resources.breadcrumbs :resource="$database" :parameters="$parameters" />
    <x-databases.navbar :database="$database" :parameters="$parameters" />
</nav>
