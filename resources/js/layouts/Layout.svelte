<script lang="ts">
	import type { LayoutProps } from "@inertiajs/core";
	import type { Snippet } from "svelte";
	import { page } from "@inertiajs/svelte";

	let { title, children }: LayoutProps & { children: Snippet } = $props();
</script>

<svelte:head>
	<title>{title ? `${title} - ` : ""}{page.props.appName}</title>
</svelte:head>

<main>
	{@render children()}
</main>
