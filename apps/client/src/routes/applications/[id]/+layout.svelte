<script lang="ts">
	import { page } from '$app/stores';
	import { appSession, status, t } from '$lib/store';
	import { onDestroy, onMount } from 'svelte';
	import type { LayoutData } from './$types';
	import * as Buttons from './_components/Buttons';
	import Degraded from './_components/States/Degraded.svelte';
	import Healthy from './_components/States/Healthy.svelte';
	import Menu from './_components/Menu.svelte';

	export let data: LayoutData;
	const id = $page.params.id;
	const application = data.application.data;

	let currentPage = 'main';
	let stopping = false;
	let statusInterval: NodeJS.Timeout;

	if ($page.url.pathname.startsWith(`/applications/${id}/configuration/`)) {
		currentPage = 'configuration';
	}
	onMount(async () => {
		await getStatus();
		statusInterval = setInterval(async () => {
			await getStatus();
		}, 2000);
	});
	onDestroy(() => {
		$status.application.initialLoading = true;
		$status.application.loading = false;
		$status.application.statuses = [];
		$status.application.overallStatus = 'stopped';
		clearInterval(statusInterval);
	});
	async function getStatus() {
		if (($status.application.loading && stopping) || $status.application.restarting === true)
			return;
		$status.application.loading = true;
		$status.application.statuses = await t.applications.status.query({ id });

		let numberOfApplications = 0;
		if (application.dockerComposeConfiguration) {
			numberOfApplications =
				application.buildPack === 'compose'
					? Object.entries(JSON.parse(application.dockerComposeConfiguration)).length
					: 1;
		} else {
			numberOfApplications = 1;
		}

		if ($status.application.statuses.length === 0) {
			$status.application.overallStatus = 'stopped';
		} else {
			for (const oneStatus of $status.application.statuses) {
				if (oneStatus.status.isExited || oneStatus.status.isRestarting) {
					$status.application.overallStatus = 'degraded';
					break;
				}
				if (oneStatus.status.isRunning) {
					$status.application.overallStatus = 'healthy';
				}
				if (
					!oneStatus.status.isExited &&
					!oneStatus.status.isRestarting &&
					!oneStatus.status.isRunning
				) {
					$status.application.overallStatus = 'stopped';
				}
			}
		}
		$status.application.loading = false;
		$status.application.initialLoading = false;
	}
</script>

<div class="mx-auto max-w-screen-2xl px-6 grid grid-cols-1 lg:grid-cols-2">
	<nav class="header flex flex-row order-2 lg:order-1 px-0 lg:px-4 items-start">
		<div class="title lg:pb-10">
			<div class="flex justify-center items-center space-x-2">
				<div>Configurations</div>
			</div>
		</div>
		{#if currentPage === 'configuration'}
			<Buttons.Delete {id} name={application.name} />
		{/if}
	</nav>
	<div
		class="pt-4 flex flex-row items-start justify-center lg:justify-end space-x-2 order-1 lg:order-2"
	>
		{#if $status.application.initialLoading}
			<Buttons.Loading />
		{:else if $status.application.overallStatus === 'degraded'}
			<Degraded {id} on:stopping={() => (stopping = true)} on:stopped={() => (stopping = false)} />
		{:else if $status.application.overallStatus === 'healthy'}
			<Healthy {id} isComposeBuildPack={application.buildPack === 'compose'} />
		{:else if $status.application.overallStatus === 'stopped'}
			<Buttons.Deploy {id} />
		{/if}
	</div>
</div>
<div
	class="mx-auto max-w-screen-2xl px-0 lg:px-10 grid grid-cols-1"
	class:lg:grid-cols-4={!$page.url.pathname.startsWith(`/applications/${id}/configuration/`)}
>
	{#if !$page.url.pathname.startsWith(`/applications/${id}/configuration/`)}
		<nav class="header flex flex-col lg:pt-0 ">
			<Menu {application} />
		</nav>
	{/if}
	<div class="pt-0 col-span-0 lg:col-span-3 pb-24">
		<slot />
	</div>
</div>
