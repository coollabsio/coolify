<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	function checkConfiguration(application): string {
		let configurationPhase = null;
		if (!application.gitSourceId) {
			configurationPhase = 'source';
		} else if (!application.destinationDockerId) {
			configurationPhase = 'destination';
		} else if (!application.repository && !application.branch) {
			configurationPhase = 'repository';
		} else if (!application.buildPack) {
			configurationPhase = 'buildpack';
		}
		return configurationPhase;
	}
	export const load: Load = async ({ fetch, page }) => {
		const url = `/applications/${page.params.id}.json`;
		const res = await fetch(url);
		if (res.ok) {
			const { application, githubToken } = await res.json();
			if (!application || Object.entries(application).length === 0) {
				return {
					status: 302,
					redirect: '/applications'
				};
			}
			const configurationPhase = checkConfiguration(application);
			if (
				configurationPhase &&
				page.path !== `/applications/${page.params.id}/configuration/${configurationPhase}`
			) {
				return {
					status: 302,
					redirect: `/applications/${page.params.id}/configuration/${configurationPhase}`
				};
			}

			return {
				props: {
					application
				},
				stuff: {
					githubToken,
					application
				}
			};
		}

		return {
			status: 302,
			redirect: '/applications'
		};
	};
</script>

<script lang="ts">
	import { page } from '$app/stores';
	import { enhance } from '$lib/form';
	import { goto } from '$app/navigation';

	export let application;
	const { id } = $page.params;
</script>

<nav class="nav-side">
	{#if application.domain}
		<form
			action="/applications/{id}/deploy.json"
			method="post"
			use:enhance={{
				result: async (res) => {
					const { buildId } = await res.json();
					window.location.replace(`/applications/${id}/logs?buildId=${buildId}`)
				}
			}}
		>
			<button
				title="Deploy"
				type="submit"
				class:text-green-500={application.gitSource &&
					application.repository &&
					application.destinationDocker}
				class="icons bg-transparent tooltip-bottom text-sm disabled:text-red-500"
				data-tooltip="Queue for deployment"
			>
				<svg
					class="w-6 h-6"
					fill="none"
					stroke="currentColor"
					viewBox="0 0 24 24"
					xmlns="http://www.w3.org/2000/svg"
					><path
						stroke-linecap="round"
						stroke-linejoin="round"
						stroke-width="2"
						d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"
					/><path
						stroke-linecap="round"
						stroke-linejoin="round"
						stroke-width="2"
						d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
					/></svg
				></button
			>
		</form>
	{/if}
	<div class="border border-warmGray-700 h-8" />
	<a
		href="/applications/{id}"
		sveltekit:prefetch
		class="hover:text-yellow-500 rounded"
		class:text-yellow-500={$page.path === `/applications/${id}`}
		class:bg-coolgray-500={$page.path === `/applications/${id}`}
	>
		<button
			title="Configuration"
			class="icons bg-transparent tooltip-bottom text-sm disabled:text-red-500"
			data-tooltip="Configuration"
		>
			<svg
				class="w-6 h-6"
				fill="none"
				stroke="currentColor"
				viewBox="0 0 24 24"
				xmlns="http://www.w3.org/2000/svg"
				><path
					stroke-linecap="round"
					stroke-linejoin="round"
					stroke-width="2"
					d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"
				/></svg
			></button
		></a
	>
	<a
		href="/applications/{id}/logs"
		sveltekit:prefetch
		class="hover:text-sky-500 rounded"
		class:text-sky-500={$page.path === `/applications/${id}/logs`}
		class:bg-coolgray-500={$page.path === `/applications/${id}/logs`}
	>
		<button
			title="Logs"
			class="icons bg-transparent tooltip-bottom text-sm disabled:text-red-500 "
			data-tooltip="Logs"
		>
			<svg
				class="w-6 h-6"
				fill="none"
				stroke="currentColor"
				viewBox="0 0 24 24"
				xmlns="http://www.w3.org/2000/svg"
				><path
					stroke-linecap="round"
					stroke-linejoin="round"
					stroke-width="2"
					d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"
				/></svg
			></button
		></a
	>

	<div class="border border-warmGray-700 h-8" />
	<form
		action="/applications/{id}/delete.json"
		method="post"
		use:enhance={{
			result: async () => {
				window.location.assign('/applications');
			}
		}}
	>
		<button
			title="Delete application"
			type="submit"
			class="icons bg-transparent hover:text-red-500 tooltip-bottom text-sm"
			data-tooltip="Delete application"
			><svg
				class="w-6 h-6"
				fill="none"
				stroke="currentColor"
				viewBox="0 0 24 24"
				xmlns="http://www.w3.org/2000/svg"
			>
				<path
					stroke-linecap="round"
					stroke-linejoin="round"
					stroke-width="2"
					d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
				/>
			</svg></button
		>
	</form>
</nav>
<slot />
