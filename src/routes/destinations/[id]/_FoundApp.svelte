<script lang="ts">
	export let app;
	import { onMount } from 'svelte';
	import { page } from '$app/stores';
	const { id } = $page.params;
	let loading = true;
	async function checkApp() {
		const form = new FormData();
		form.append('name', app.name);
		form.append('domain', app.domain);
		form.append('projectId', app.projectId);
		form.append('repository', app.repository);
		form.append('branch', app.branch);
		const response = await fetch(`/destinations/${id}/scan.json`, {
			method: 'POST',
			headers: {
				accept: 'application/json'
			},
			body: form
		});
		if (response.ok) {
			const { by, name } = await response.json();
			if (by === 'domain') {
				app.foundByDomain = true;
			} else if (by === 'repository') {
				app.foundByRepository = true;
			}
			app.foundName = name;
		}
	}
	onMount(async () => {
		await checkApp();
		loading = false;
	});
	async function addToCoolify() {
		const form = new FormData();
		form.append('name', app.name);
		form.append('domain', app.domain);
		if (app.port) form.append('port', app.port);
		if (app.buildCommand) form.append('buildCommand', app.buildCommand);
		if (app.startCommand) form.append('startCommand', app.startCommand);
		if (app.installCommand) form.append('installCommand', app.installCommand);

		const response = await fetch(`/new/application/import.json`, {
			method: 'POST',
			headers: {
				accept: 'application/json'
			},
			body: form
		});
		if (response.ok) {
			const { id } = await response.json();
			window.location.replace(`/applications/${id}`);
		}
	}
</script>

<div class="box-selection hover:border-transparent hover:bg-coolgray-200">
	<div class="truncate pb-2 text-center text-xl font-bold">{app.domain}</div>
	{#if loading}
		<div class="w-full text-center font-bold">Loading...</div>
	{:else if app.foundByDomain}
		<div class="w-full bg-coolgray-200 text-xs">
			<span class="text-red-500">Domain</span> already configured for
			<span class="text-red-500">{app.foundName}</span>
		</div>
	{:else if app.foundByRepository}
		<div class="w-full bg-coolgray-200 text-xs">
			<span class="text-red-500">Repository</span> already configured for
			<span class="text-red-500">{app.foundName}</span>
		</div>
	{:else}
		<button class="bg-green-600 hover:bg-green-500 w-full" on:click={addToCoolify}
			>Add to Coolify</button
		>
	{/if}
</div>
