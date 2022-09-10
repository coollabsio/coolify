<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async () => {
		try {
			const response = await get(`/services`);
			return {
				props: {
					...response
				}
			};
		} catch (error: any) {
			return {
				status: 500,
				error: new Error(error)
			};
		}
	};
</script>

<script lang="ts">
	export let services: any = [];
	import { post, get } from '$lib/api';
	import { goto } from '$app/navigation';
	import { t } from '$lib/translations';
	import { appSession } from '$lib/store';

	import { getDomain } from '$lib/common';
	import ServiceIcons from '$lib/components/svg/services/ServiceIcons.svelte';

	async function newService() {
		const { id } = await post('/services/new', {});
		return await goto(`/services/${id}`, { replaceState: true });
	}
	const ownServices = services.filter((service: any) => {
		if (service.teams[0].id === $appSession.teamId) {
			return service;
		}
	});
	const otherServices = services.filter((service: any) => {
		if (service.teams[0].id !== $appSession.teamId) {
			return service;
		}
	});
</script>

<nav class="header">
	<h1 class="mr-4 text-2xl font-bold">{$t('index.services')}</h1>
	<button on:click={newService} class="btn btn-square btn-sm bg-services">
		<svg
			class="h-6 w-6"
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
	</button>
</nav>
<br />
<div class="flex-col justify-center mt-10 pb-12 sm:pb-16 lg:pt-16">
	{#if !services || ownServices.length === 0}
		<div class="flex-col">
			<div class="text-center text-xl font-bold">{$t('service.no_service')}</div>
		</div>
	{/if}
	{#if ownServices.length > 0 || otherServices.length > 0}
		<div class="flex flex-col">
			<div class="flex flex-col flex-wrap justify-center px-2 md:flex-row">
				{#each ownServices as service}
					<a href="/services/{service.id}" class=" p-2 no-underline">
						<div class="box-selection group relative hover:bg-pink-600">
							<ServiceIcons type={service.type} />
							<div class="truncate text-center text-xl font-bold">
								{service.name}
							</div>
							{#if $appSession.teamId === '0' && otherServices.length > 0}
								<div class="truncate text-center">{service.teams[0].name}</div>
							{/if}
							{#if service.fqdn}
								<div class="truncate text-center">{getDomain(service.fqdn) || ''}</div>
							{/if}
							{#if service.destinationDocker?.name}
								<div class="truncate text-center">{service.destinationDocker.name}</div>
							{/if}
							{#if !service.type || !service.fqdn}
								<div class="truncate text-center font-bold text-red-500 group-hover:text-white">
									{$t('application.configuration.configuration_missing')}
								</div>
							{/if}
						</div>
					</a>
				{/each}
			</div>
			{#if otherServices.length > 0 && $appSession.teamId === '0'}
				<div class="px-6 pb-5 pt-10 text-2xl font-bold text-center">Other Services</div>
				<div class="flex flex-col flex-wrap justify-center px-2 md:flex-row">
					{#each otherServices as service}
						<a href="/services/{service.id}" class="p-2 no-underline">
							<div class="box-selection group relative hover:bg-pink-600">
								<ServiceIcons type={service.type} />
								<div class="truncate text-center text-xl font-bold">
									{service.name}
								</div>
								{#if $appSession.teamId === '0'}
									<div class="truncate text-center">{service.teams[0].name}</div>
								{/if}
								{#if service.fqdn}
									<div class="truncate text-center">{getDomain(service.fqdn) || ''}</div>
								{/if}
								{#if !service.type || !service.fqdn}
									<div class="truncate text-center font-bold text-red-500 group-hover:text-white">
										Configuration missing
									</div>
								{:else}
									<div class="text-center truncate">{service.type}</div>
								{/if}
							</div>
						</a>
					{/each}
				</div>
			{/if}
		</div>
	{/if}
</div>
