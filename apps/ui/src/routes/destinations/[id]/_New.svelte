<script lang="ts">
	import cuid from 'cuid';
	import { t } from '$lib/translations';
	import NewLocalDocker from './_NewLocalDocker.svelte';
	import NewRemoteDocker from './_NewRemoteDocker.svelte';
	import ContextMenu from '$lib/components/ContextMenu.svelte';
	let payload = {};
	let selected = 'localDocker';
	function setPredefined(type: any) {
		selected = type;
		switch (type) {
			case 'localDocker':
				payload = {
					name: t.get('sources.local_docker'),
					engine: '/var/run/docker.sock',
					remoteEngine: false,
					network: cuid(),
					isCoolifyProxyUsed: true
				};
				break;
			case 'remoteDocker':
				payload = {
					name: $t('sources.remote_docker'),
					remoteEngine: true,
					remoteIpAddress: null,
					remoteUser: 'root',
					remotePort: 22,
					network: cuid(),
					isCoolifyProxyUsed: true
				};
				break;
			default:
				break;
		}
	}
</script>

<ContextMenu>
	<div class="title">{$t('destination.new.add_new_destination')}</div>
</ContextMenu>

<div class="flex-col space-y-2 pb-10 text-center">
	<div class="text-xl font-bold text-white">{$t('destination.new.predefined_destinations')}</div>
	<div class="flex justify-center space-x-2">
		<button class="btn btn-sm" on:click={() => setPredefined('localDocker')}
			>{$t('sources.local_docker')}</button
		>
		<button class="btn btn-sm" on:click={() => setPredefined('remoteDocker')}>Remote Docker</button>
		<!-- <button class="w-32" on:click={() => setPredefined('kubernetes')}>Kubernetes</button> -->
	</div>
</div>
{#if selected === 'localDocker'}
	<NewLocalDocker {payload} />
{:else if selected === 'remoteDocker'}
	<NewRemoteDocker {payload} />
{:else}
	<div class="text-center font-bold text-4xl py-10">{$t('index.not_implemented_yet')}</div>
{/if}
