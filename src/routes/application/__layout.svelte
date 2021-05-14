<script>
	import { application, initialApplication, initConf, dashboard } from '$store';
	import { onDestroy } from 'svelte';
	import Loading from '$components/Loading.svelte';
	import Navbar from '$components/Application/Navbar.svelte';
	import { page, session } from '$app/stores';
	import { goto } from '$app/navigation';
	import { browser } from '$app/env';
	import { request } from '$lib/api/request';

	$application.repository.organization = $page.params.organization;
	$application.repository.name = $page.params.name;
	$application.repository.branch = $page.params.branch;

	async function setConfiguration() {
		try {
			const config = await request(`/api/v1/application/config`, $session, {
				body: {
					name: $application.repository.name,
					organization: $application.repository.organization,
					branch: $application.repository.branch
				}
			});
			$application = { ...config };
			$initConf = JSON.parse(JSON.stringify($application));
		} catch (error) {
			browser && goto('/dashboard/applications');
		}
	}
	async function loadConfiguration() {
		if ($page.path !== '/application/new') {
			if (!$dashboard) {
				await setConfiguration();
			} else {
				const found = $dashboard.applications.deployed.find((app) => {
					const { organization, name, branch } = app.configuration.repository;
					if (
						organization === $application.repository.organization &&
						name === $application.repository.name &&
						branch === $application.repository.branch
					) {
						return app;
					}
				});
				if (found) {
					$application = { ...found.configuration };
					$initConf = JSON.parse(JSON.stringify($application));
				} else {
					await setConfiguration();
				}
			}
		} else {
			$application = JSON.parse(JSON.stringify(initialApplication));
		}
	}

	onDestroy(() => {
		$application = JSON.parse(JSON.stringify(initialApplication));
	});

</script>

{#await loadConfiguration()}
	<Loading />
{:then}
	<Navbar />
	<div class="text-white">
		{#if $page.path.endsWith('configuration')}
			<div class="min-h-full text-white">
				<div class="py-5 text-left px-6 text-3xl tracking-tight font-bold flex items-center">
					{$application.publish.domain
						? `${$application.publish.domain}${
								$application.publish.path !== '/' ? $application.publish.path : ''
						  }`
						: 'example.com'}
					<a
						target="_blank"
						class="icon mx-2"
						href={'https://' + $application.publish.domain + $application.publish.path}
					>
						<svg
							xmlns="http://www.w3.org/2000/svg"
							class="h-6 w-6"
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

					<a
						target="_blank"
						class="icon"
						href={`https://github.com/${$application.repository.organization}/${$application.repository.name}`}
					>
						<svg
							class="w-6"
							xmlns="http://www.w3.org/2000/svg"
							viewBox="0 0 24 24"
							fill="none"
							stroke="currentColor"
							stroke-width="2"
							stroke-linecap="round"
							stroke-linejoin="round"
							><path
								d="M9 19c-5 1.5-5-2.5-7-3m14 6v-3.87a3.37 3.37 0 0 0-.94-2.61c3.14-.35 6.44-1.54 6.44-7A5.44 5.44 0 0 0 20 4.77 5.07 5.07 0 0 0 19.91 1S18.73.65 16 2.48a13.38 13.38 0 0 0-7 0C6.27.65 5.09 1 5.09 1A5.07 5.07 0 0 0 5 4.77a5.44 5.44 0 0 0-1.5 3.78c0 5.42 3.3 6.61 6.44 7A3.37 3.37 0 0 0 9 18.13V22"
							/></svg
						></a
					>
				</div>
			</div>
		{:else if $page.path === '/application/new'}
			<div class="min-h-full text-white">
				<div class="py-5 text-left px-6 text-3xl tracking-tight font-bold flex items-center">
					New Application
				</div>
			</div>
		{/if}
		<slot />
	</div>
{/await}
