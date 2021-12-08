<script lang="ts">
	export let app;
	import { onMount } from 'svelte';
    import { page } from '$app/stores';
	const { id } = $page.params;
	let loading = true
    async function checkApp() {
		const form = new FormData();
		form.append('name',app.name);
        form.append('domain',app.domain);
		const response = await fetch(`/destinations/${id}/scan.json`, {
			method: 'POST',
			headers: {
				accept: 'application/json'
			},
			body: form
		});
		if (response.ok) {
            app.found = true
		}
	}
	onMount(async () => {
        await checkApp();
		loading = false
	});
	async function addToCoolify() {
		console.log(app)
	}
</script>

<div class="box-selection hover:scale-100 hover:bg-coolgray-200 hover:border-transparent">
	<div class="font-bold text-xl text-center truncate pb-2">{app.domain}</div>
	{#if loading}
	<div class="font-bold w-full text-center">Loading...</div>
	{:else}
	{#if app.found}
	<button disabled class="w-full bg-coolgray-200">Already saved in Coolify</button>
{:else}
	<button class="bg-green-600 hover:bg-green-500 w-full" on:click={addToCoolify}>Add to Coolify</button>
{/if}
	{/if}

</div>
