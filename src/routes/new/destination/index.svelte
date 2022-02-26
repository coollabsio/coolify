<script>
	import LocalDocker from './_LocalDocker.svelte';
	import cuid from 'cuid';
	import RemoteDocker from './_RemoteDocker.svelte';
	let payload = {};
	let selected = 'localDocker';

	function setPredefined(type) {
		selected = type;
		switch (type) {
			case 'localDocker':
				payload = {
					name: 'Local Docker',
					engine: '/var/run/docker.sock',
					remoteEngine: false,
					network: cuid(),
					isCoolifyProxyUsed: true
				};
				break;
			case 'remoteDocker':
				payload = {
					name: 'Remote Docker',
					remoteEngine: true,
					ipAddress: null,
					user: 'root',
					port: 22,
					sshPrivateKey: null,
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
		<button class="w-32" on:click={() => setPredefined('localDocker')}>Local Docker</button>
		<button class="w-32" on:click={() => setPredefined('remoteDocker')}>Remote Docker</button>
		<button class="w-32" on:click={() => setPredefined('kubernetes')}>Kubernetes</button>
	</div>
</div>
{#if selected === 'localDocker'}
	<LocalDocker {payload} />
{:else if selected === 'remoteDocker'}
	<RemoteDocker {payload} />
{:else}
	<div class="text-center font-bold text-4xl py-10">Not implemented yet</div>
{/if}
