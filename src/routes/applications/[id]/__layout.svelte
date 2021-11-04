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
					goto(`/applications/${id}/buildLogs?buildId=${buildId}`);
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
