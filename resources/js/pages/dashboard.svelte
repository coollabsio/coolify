<script lang="ts">
import { page } from '@inertiajs/svelte'
</script>

<svelte:head>
    <title>Dashboard - {$page.props.appName}</title> <!-- the TS type of appName is currently incorrect, which is likely an Inertia bug. -->
    <meta name="description" content="The {$page.props.appName} dashboard" />
</svelte:head>

<div class="min-h-screen bg-background text-foreground p-8">
    <h1 class="text-3xl font-bold mb-6">Dashboard</h1>
</div>
