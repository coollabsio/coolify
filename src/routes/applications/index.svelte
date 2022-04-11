<script lang="ts">
	export let applications: Array<Application>;
	import { session } from '$app/stores';
	import { post } from '$lib/api';
	import { goto } from '$app/navigation';

	import Rust from '$lib/components/svg/applications/Rust.svelte';
	import Nodejs from '$lib/components/svg/applications/Nodejs.svelte';
	import React from '$lib/components/svg/applications/React.svelte';
	import Svelte from '$lib/components/svg/applications/Svelte.svelte';
	import Vuejs from '$lib/components/svg/applications/Vuejs.svelte';
	import PHP from '$lib/components/svg/applications/PHP.svelte';
	import Python from '$lib/components/svg/applications/Python.svelte';
	import Static from '$lib/components/svg/applications/Static.svelte';
	import Nestjs from '$lib/components/svg/applications/Nestjs.svelte';
	import Nuxtjs from '$lib/components/svg/applications/Nuxtjs.svelte';
	import Nextjs from '$lib/components/svg/applications/Nextjs.svelte';
	import Gatsby from '$lib/components/svg/applications/Gatsby.svelte';
	import Docker from '$lib/components/svg/applications/Docker.svelte';
	import Astro from '$lib/components/svg/applications/Astro.svelte';
	import Eleventy from '$lib/components/svg/applications/Eleventy.svelte';
	import { getDomain } from '$lib/components/common';

	async function newApplication() {
		const { id } = await post('/applications/new', {});
		return await goto(`/applications/${id}`, { replaceState: true });
	}
	const ownApplications = applications.filter((application) => {
		if (application.teams[0].id === $session.teamId) {
			return application;
		}
	});
	const otherApplications = applications.filter((application) => {
		if (application.teams[0].id !== $session.teamId) {
			return application;
		}
	});
</script>

<div class="flex space-x-1 p-6 font-bold">
	<div class="mr-4 text-2xl ">Applications</div>
	{#if $session.isAdmin}
		<div on:click={newApplication} class="add-icon cursor-pointer bg-green-600 hover:bg-green-500">
			<svg
				class="w-6"
				xmlns="http://www.w3.org/2000/svg"
				fill="none"
				viewBox="0 0 24 24"
				stroke="currentColor"
				><path
					stroke-linecap="round"
					stroke-linejoin="round"
					stroke-width="2"
					d="M12 6v6m0 0v6m0-6h6m-6 0H6"
				/></svg
			>
		</div>
	{/if}
</div>
<div class="flex flex-col flex-wrap justify-center">
	{#if !applications || ownApplications.length === 0}
		<div class="flex-col">
			<div class="text-center text-xl font-bold">No applications found</div>
		</div>
	{/if}
	{#if ownApplications.length > 0 || otherApplications.length > 0}
		<div class="flex flex-col">
			<div class="flex flex-col flex-wrap justify-center px-2 md:flex-row">
				{#each ownApplications as application}
					<a href="/applications/{application.id}" class="w-96 p-2 no-underline">
						<div class="box-selection group relative hover:bg-green-600">
							{#if application.buildPack}
								{#if application.buildPack.toLowerCase() === 'rust'}
									<Rust />
								{:else if application.buildPack.toLowerCase() === 'node'}
									<Nodejs />
								{:else if application.buildPack.toLowerCase() === 'react'}
									<React />
								{:else if application.buildPack.toLowerCase() === 'svelte'}
									<Svelte />
								{:else if application.buildPack.toLowerCase() === 'vuejs'}
									<Vuejs />
								{:else if application.buildPack.toLowerCase() === 'php'}
									<PHP />
								{:else if application.buildPack.toLowerCase() === 'python'}
									<Python />
								{:else if application.buildPack.toLowerCase() === 'static'}
									<Static />
								{:else if application.buildPack.toLowerCase() === 'nestjs'}
									<Nestjs />
								{:else if application.buildPack.toLowerCase() === 'nuxtjs'}
									<Nuxtjs />
								{:else if application.buildPack.toLowerCase() === 'nextjs'}
									<Nextjs />
								{:else if application.buildPack.toLowerCase() === 'gatsby'}
									<Gatsby />
								{:else if application.buildPack.toLowerCase() === 'docker'}
									<Docker />
								{:else if application.buildPack.toLowerCase() === 'astro'}
									<Astro />
								{:else if application.buildPack.toLowerCase() === 'eleventy'}
									<Eleventy />
								{/if}
							{/if}

							<div class="truncate text-center text-xl font-bold">{application.name}</div>
							{#if $session.teamId === '0' && otherApplications.length > 0}
								<div class="truncate text-center">Team {application.teams[0].name}</div>
							{/if}
							{#if application.fqdn}
								<div class="truncate text-center">{getDomain(application.fqdn) || ''}</div>
							{/if}
							{#if !application.gitSourceId || !application.destinationDockerId || !application.fqdn}
								<div class="truncate text-center font-bold text-red-500 group-hover:text-white">
									Configuration missing
								</div>
							{/if}
						</div>
					</a>
				{/each}
			</div>
			{#if otherApplications.length > 0 && $session.teamId === '0'}
				<div class="px-6 pb-5 pt-10 text-xl font-bold">Other Applications</div>
				<div class="flex flex-col flex-wrap justify-center px-2 md:flex-row">
					{#each otherApplications as application}
						<a href="/applications/{application.id}" class="w-96 p-2 no-underline">
							<div class="box-selection group relative hover:bg-green-600">
								{#if application.buildPack}
									{#if application.buildPack.toLowerCase() === 'rust'}
										<Rust />
									{:else if application.buildPack.toLowerCase() === 'node'}
										<Nodejs />
									{:else if application.buildPack.toLowerCase() === 'react'}
										<React />
									{:else if application.buildPack.toLowerCase() === 'svelte'}
										<Svelte />
									{:else if application.buildPack.toLowerCase() === 'vuejs'}
										<Vuejs />
									{:else if application.buildPack.toLowerCase() === 'php'}
										<PHP />
									{:else if application.buildPack.toLowerCase() === 'python'}
										<Python />
									{:else if application.buildPack.toLowerCase() === 'static'}
										<Static />
									{:else if application.buildPack.toLowerCase() === 'nestjs'}
										<Nestjs />
									{:else if application.buildPack.toLowerCase() === 'nuxtjs'}
										<Nuxtjs />
									{:else if application.buildPack.toLowerCase() === 'nextjs'}
										<Nextjs />
									{:else if application.buildPack.toLowerCase() === 'gatsby'}
										<Gatsby />
									{:else if application.buildPack.toLowerCase() === 'docker'}
										<Docker />
									{:else if application.buildPack.toLowerCase() === 'astro'}
										<Astro />
									{:else if application.buildPack.toLowerCase() === 'eleventy'}
										<Eleventy />
									{/if}
								{/if}

								<div class="truncate text-center text-xl font-bold">{application.name}</div>
								{#if $session.teamId === '0'}
									<div class="truncate text-center">Team {application.teams[0].name}</div>
								{/if}
								{#if application.fqdn}
									<div class="truncate text-center">{getDomain(application.fqdn) || ''}</div>
								{/if}
								{#if !application.gitSourceId || !application.destinationDockerId || !application.fqdn}
									<div class="truncate text-center font-bold text-red-500 group-hover:text-white">
										Configuration missing
									</div>
								{/if}
							</div>
						</a>
					{/each}
				</div>
			{/if}
		</div>
	{/if}
</div>
