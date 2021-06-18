<script context="module">
	/**
	 * @type {import('@sveltejs/kit').Load}
	 */
	export async function load({ fetch }) {
		try {
			const { hostname, filesystems,dockerReclaimable } = await (await fetch(`/api/v1/servers`)).json();
			return {
				props: {
					hostname,
					filesystems,
                    dockerReclaimable
				}
			};
		} catch (error) {
			return {
				props: {
					hostname: null,
					filesystems: null,
                    dockerReclaimable: null
				}
			};
		}
	}

</script>

<script>
	export let hostname;
	export let filesystems;
    export let dockerReclaimable;
	import { browser } from '$app/env';
	import { session } from '$app/stores';
	import { request } from '$lib/request';
	import { toast } from '@zerodevx/svelte-toast';
	import { fade } from 'svelte/transition';
    async function refetch() {
        const data = await request('/api/v1/servers', $session)
        filesystems = data.filesystems
        dockerReclaimable = data.dockerReclaimable
    }
	async function cleanupVolumes() {
		const { output } = await request('/api/v1/servers/cleanups/volumes', $session, {
			body: {}
		});
		browser && toast.push(output);
        await refetch()
	}
    async function cleanupImages() {
		const { output } = await request('/api/v1/servers/cleanups/images', $session, {
			body: {}
		});
		browser && toast.push(output);
        await refetch()
	}
    async function cleanupBuildCache() {
		const { output } = await request('/api/v1/servers/cleanups/caches', $session, {
			body: {}
		});
		browser && toast.push(output);
        await refetch()
	}
	async function cleanupContainers() {
		const { output } = await request('/api/v1/servers/cleanups/containers', $session, {
			body: {}
		});
		browser && toast.push(output);
        await refetch()
	}

</script>

<div class="min-h-full text-white" in:fade={{ duration: 100 }}>
	<div class="py-5 text-left px-6 text-3xl tracking-tight font-bold flex items-center">
		<div>Servers</div>
	</div>
</div>

<div in:fade={{ duration: 100 }}>
	<div class="max-w-4xl mx-auto px-6 pb-4 h-64 ">
		<div class="text-center font-bold text-xl">{hostname}</div>
		<div class="font-bold">Filesystem Usage</div>
		{#each filesystems as filesystem}
			<!-- <div>{JSON.stringify(filesystem)}</div> -->
			<div class="text-xs">
				{filesystem.mount}:  {(filesystem.available / 1024 / 1024).toFixed()}MB ({filesystem.use}%) free of {(filesystem.size /1024 /1024).toFixed()}MB
			</div>
		{/each}
        <div class="font-bold">Docker Reclaimable</div>
        {#each dockerReclaimable as reclaimable}
        <div class="text-xs">
            {reclaimable.Type}: {reclaimable.Reclaimable} of {reclaimable.Size}
        </div>
        {/each}

		<button class="button hover:bg-warmGray-700 bg-warmGray-800 rounded p-2 font-bold" on:click={cleanupVolumes}>Cleanup unused volumes</button>
        <button class="button hover:bg-warmGray-700 bg-warmGray-800 rounded p-2 font-bold" on:click={cleanupImages}>Cleanup unused images</button>
        <button class="button hover:bg-warmGray-700 bg-warmGray-800 rounded p-2 font-bold" on:click={cleanupBuildCache}>Cleanup build caches</button>
		<button class="button hover:bg-warmGray-700 bg-warmGray-800 rounded p-2 font-bold" on:click={cleanupContainers}>Cleanup containers</button>
	</div>
</div>
