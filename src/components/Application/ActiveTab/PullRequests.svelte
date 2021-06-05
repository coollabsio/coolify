<script>
	import { browser } from '$app/env';
	import { goto } from '$app/navigation';
	import { onDestroy, onMount } from 'svelte';
	import { session } from '$app/stores';
	import { request } from '$lib/request';
	import { toast } from '@zerodevx/svelte-toast';
	import { application, prApplication } from '$store';
	let loadPRDeployments = null;
	onMount(async () => {
		await getPRDeployments();
		loadPRDeployments = setInterval(async () => {
			await getPRDeployments();
		}, 1000);
	});
	onDestroy(() => {
		clearInterval(loadPRDeployments);
	});
	async function getPRDeployments() {
		const { configuration } = await request(`/api/v1/application/config`, $session, {
			body: {
				name: $application.repository.name,
				organization: $application.repository.organization,
				branch: $application.repository.branch
			}
		});

		// $prApplication = configuration.filter((c) => c.repository.pullRequest && c.repository.pullRequest !== 0);
		$prApplication = configuration.filter((c) => c.repository.pullRequest !== 0);
	}
	async function removePR(prConfiguration) {
		const result = window.confirm("Are you sure? It's NOT reversible!");
		if (result) {
			await request(`/api/v1/application/remove`, $session, {
				body: {
					organization: prConfiguration.repository.organization,
					name: prConfiguration.repository.name,
					branch: prConfiguration.repository.branch,
					domain: prConfiguration.publish.domain
				}
			});

			browser && toast.push('PR deployment removed.');
			const { configuration } = await request(`/api/v1/application/config`, $session, {
				body: {
					name: prConfiguration.repository.name,
					organization: prConfiguration.repository.organization,
					branch: prConfiguration.repository.branch
				}
			});

			// $prApplication = configuration.filter((c) => c.repository.pullRequest && c.repository.pullRequest !== 0);
			$prApplication = configuration.filter((c) => c.repository.pullRequest !== 0);
		}
	}

</script>

<div class="text-2xl font-bold border-gradient w-48">Pull Requests</div>
<div class="text-center pt-4">
	{#if $prApplication.length > 0}
		<div class="py-4 ">
			{#each $prApplication as pr}
				<div class="flex space-x-4 justify-center items-center">
					<div class="text-left  font-bold tracking-tight  ">
						{pr.publish.domain}
					</div>
					<a
						target="_blank"
						class="icon mx-2 "
						href={'https://' + pr.publish.domain + pr.publish.path}
					>
						<svg
							xmlns="http://www.w3.org/2000/svg"
							class="h-5 w-5"
							fill="none"
							viewBox="0 0 24 24"
							stroke="currentColor"
						>
							<path
								stroke-linecap="round"
								stroke-linejoin="round"
								stroke-width="2"
								d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"
							/>
						</svg></a
					>
					<!-- <div class="flex-1" /> -->
					<button
						class="icon hover:text-red-500 hover:bg-warmGray-800"
						on:click={() => removePR(pr)}
					>
						<svg
							xmlns="http://www.w3.org/2000/svg"
							class="h-5 w-5"
							fill="none"
							viewBox="0 0 24 24"
							stroke="currentColor"
						>
							<path
								stroke-linecap="round"
								stroke-linejoin="round"
								stroke-width="2"
								d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
							/>
						</svg>
					</button>
				</div>
			{/each}
		</div>
	{:else}
		<div class="font-bold text-center">No PR deployments found</div>
	{/if}
</div>
