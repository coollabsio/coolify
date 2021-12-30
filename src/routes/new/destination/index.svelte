<script>
	import Docker from './_Docker.svelte';

	let payload = {};
	let selected = 'docker';

	function setPredefined(type) {
		selected = type;
		switch (type) {
			case 'docker':
				payload = {
					name: 'Docker',
					isSwarm: false,
					engine: '/var/run/docker.sock',
					network: 'coolify',
					isCoolifyProxyUsed: true
				};
				break;

			default:
				break;
		}
	}
</script>

<div class="font-bold flex space-x-1 py-5 px-6">
	<div class="text-2xl tracking-tight mr-4">Add New Destination</div>
</div>
<div class="flex-col text-center space-y-2 pb-10">
	<div class="font-bold text-xl text-white">Predefined destinations</div>
	<div class="flex space-x-2 justify-center">
		<button class="w-32" on:click={() => setPredefined('docker')}>Docker</button>
		<button class="w-32" on:click={() => setPredefined('kubernetes')}>Kubernetes</button>
	</div>
</div>
{#if selected === 'docker'}
	<Docker {payload} />
{:else}
	<div class="text-center font-bold text-4xl py-10">Not implemented yet</div>
{/if}
