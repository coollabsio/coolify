<script>
	import { application, dateOptions } from '$store';
	import { fade } from 'svelte/transition';

	import { onDestroy, onMount } from 'svelte';

	import Loading from '$components/Loading.svelte';
	import { request } from '$lib/request';
	import { session } from '$app/stores';
	import { goto } from '$app/navigation';

	let loadDeploymentsInterval = null;
	let loadLogsInterval = null;
	let deployments = [];
	let logs = [];
	let page = 1;

	onMount(async () => {
		loadApplicationLogs();
		loadLogsInterval = setInterval(() => {
			loadApplicationLogs();
		}, 3000);
		loadDeploymentsInterval = setInterval(() => {
			loadDeploymentLogs();
		}, 1000);
	});
	onDestroy(() => {
		clearInterval(loadDeploymentsInterval);
		clearInterval(loadLogsInterval);
	});
	async function loadMoreDeploymentLogs() {
		page = page + 1;
		await loadDeploymentLogs();
	}
	async function loadDeploymentLogs() {
		deployments = (
			await request(
				`/api/v1/application/deploy/logs?repoId=${$application.repository.id}&branch=${$application.repository.branch}&page=${page}`,
				$session
			)
		).logs;
	}
	async function loadApplicationLogs() {
		logs = (
			await request(`/api/v1/application/logs?name=${$application.build.container.name}`, $session)
		).logs;
	}
</script>

<div
	class="py-5 text-left px-6 text-3xl tracking-tight font-bold flex items-center"
	in:fade={{ duration: 100 }}
>
	<div>Logs</div>
</div>
{#await loadDeploymentLogs()}
	<Loading />
{:then}
	<div class="text-center px-6" in:fade={{ duration: 100 }}>
		<div class="flex pt-2 space-x-4 w-full">
			<div class="w-full">
				<div class="font-bold text-left pb-2 text-xl">Application logs</div>
				{#if logs.length === 0}
					<div class="text-xs font-semibold tracking-tighter">Waiting for the logs...</div>
				{:else}
					<pre
						class="leading-4 text-left text-sm font-semibold tracking-tighter rounded-lg bg-black p-6 whitespace-pre-wrap w-full">
            {#each logs as log}
              {log + '\n'}
            {/each}
          </pre>
				{/if}
			</div>
			<div>
				<div class="font-bold text-left pb-2 text-xl w-300">Deployment logs</div>
				{#if deployments.length > 0}
					<div class="space-y-2">
						{#each deployments as deployment}
							<div
								in:fade={{ duration: 100 }}
								class="flex space-x-4 text-md py-4 hover:shadow mx-auto cursor-pointer transition-all duration-100 border-l-4 border-transparent rounded hover:bg-warmGray-700"
								class:hover:border-green-500={deployment.progress === 'done'}
								class:border-yellow-300={deployment.progress !== 'done' &&
									deployment.progress !== 'failed'}
								class:bg-warmGray-800={deployment.progress !== 'done' &&
									deployment.progress !== 'failed'}
								class:hover:bg-red-200={deployment.progress === 'failed'}
								class:hover:border-red-500={deployment.progress === 'failed'}
								on:click={() => goto(`./logs/${deployment.deployId}`)}
							>
								<div class="font-bold text-sm px-3 flex justify-center items-center">
									{deployment.branch}
								</div>
								<div class="flex-1" />
								<div class="px-3 w-48">
									<div
										class="text-xs"
										title={new Intl.DateTimeFormat('default', dateOptions).format(
											new Date(deployment.createdAt)
										)}
									>
										{deployment.since}
									</div>
									{#if deployment.progress === 'done'}
										<div class="text-xs">
											Deployed in <span class="font-bold">{deployment.took}s</span>
										</div>
									{:else if deployment.progress === 'failed'}
										<div class="text-xs text-red-500">Failed</div>
									{:else}
										<div class="text-xs">Deploying...</div>
									{/if}
								</div>
							</div>
						{/each}
					</div>
					<button
						class="text-xs bg-green-600 hover:bg-green-500 p-1 rounded text-white px-2 font-medium my-6"
						on:click={loadMoreDeploymentLogs}>Show more</button
					>
				{:else}
					<div class="text-left text-sm tracking-tight">No deployments found</div>
				{/if}
			</div>
		</div>
	</div>
{:catch}
	<div class="text-center font-bold tracking-tight text-xl">No logs found</div>
{/await}

<style lang="postcss">
	.w-300 {
		width: 300px !important;
	}
</style>
