<script>
	import Docker from './_Docker.svelte';
	import cuid from 'cuid';
	let payload = {};
	let selected = 'docker';

	function setPredefined(type) {
		selected = type;
		switch (type) {
			case 'docker':
				payload = {
					name: 'Local Docker',
					engine: '/var/run/docker.sock',
					remoteEngine: false,
					user: 'root',
					port: 22,
					privateKey: null,
					network: cuid(),
					isCoolifyProxyUsed: true
				};
				break;

			default:
				break;
		}
	}
</script>

<div class="flex space-x-1 p-6 font-bold">
	<div class="mr-4 text-2xl tracking-tight">Add New Destination</div>
</div>
<div class="flex-col space-y-2 pb-10 text-center">
	<div class="text-xl font-bold text-white">Predefined destinations</div>
	<div class="flex justify-center space-x-2">
		<button class="w-32" on:click={() => setPredefined('docker')}>Docker</button>
		<button class="w-32" on:click={() => setPredefined('kubernetes')}>Kubernetes</button>
	</div>
</div>
{#if selected === 'docker'}
	<Docker {payload} />
{:else}
	<div class="text-center font-bold text-4xl py-10">Not implemented yet</div>
{/if}
