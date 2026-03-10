<script lang="ts">
	import { page } from "@inertiajs/svelte";
</script>

<svelte:head>
	<title>Dashboard - {$page.props.appName}</title>
	<!-- the TS type of appName is currently incorrect, which is likely an Inertia bug. -->
	<meta name="description" content="The {$page.props.appName} dashboard" />
</svelte:head>

<div class="bg-background text-foreground min-h-screen p-8">
	<h1 class="mb-6 text-3xl font-bold">Dashboard</h1>
</div>
