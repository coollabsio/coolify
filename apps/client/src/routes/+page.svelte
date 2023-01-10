<script lang="ts">
	import type { PageData } from './$types';

	export let data: PageData;
	import { dev } from '$app/environment';
	import { onMount } from 'svelte';

	import { asyncSleep, errorNotification, getRndInteger } from '$lib/common';
	import { appSession, search, trpc } from '$lib/store';
	import ApplicationsIcons from '$lib/components/icons/applications/ApplicationIcons.svelte';
	import DatabaseIcons from '$lib/components/icons/databases/DatabaseIcons.svelte';
	import ServiceIcons from '$lib/components/icons/services/ServiceIcons.svelte';
	import * as Icons from '$lib/components/icons';
	import NewResource from './components/NewResource.svelte';

	const {
		applications,
		foundUnconfiguredApplication,
		foundUnconfiguredService,
		foundUnconfiguredDatabase,
		databases,
		services,
		gitSources,
		destinations,
		settings
	} = data;
	let filtered: any = setInitials();
	let numberOfGetStatus = 0;
	let status: any = {};
	let noInitialStatus: any = {
		applications: false,
		services: false,
		databases: false
	};
	let loading = {
		applications: false,
		services: false,
		databases: false
	};
	let searchInput: HTMLInputElement;
	doSearch();
	onMount(() => {
		setTimeout(() => {
			searchInput.focus();
		}, 100);
	});
	async function refreshStatusApplications() {
		noInitialStatus.applications = false;
		numberOfGetStatus = 0;
		for (const application of applications) {
			status[application.id] = 'loading';
			getStatus(application, true);
		}
	}
	async function refreshStatusServices() {
		noInitialStatus.services = false;
		numberOfGetStatus = 0;
		for (const service of services) {
			status[service.id] = 'loading';
			getStatus(service, true);
		}
	}
	async function refreshStatusDatabases() {
		noInitialStatus.databases = false;
		numberOfGetStatus = 0;
		for (const database of databases) {
			status[database.id] = 'loading';
			getStatus(database, true);
		}
	}
	function setInitials(onlyOthers: boolean = false) {
		return {
			applications:
				!onlyOthers &&
				applications.filter(
					(application: any) =>
						application?.teams.length > 0 && application.teams[0].id === $appSession.teamId
				),
			otherApplications: applications.filter(
				(application: any) =>
					application?.teams.length > 0 && application.teams[0].id !== $appSession.teamId
			),
			databases:
				!onlyOthers &&
				databases.filter(
					(database: any) =>
						database?.teams.length > 0 && database.teams[0].id === $appSession.teamId
				),
			otherDatabases: databases.filter(
				(database: any) => database?.teams.length > 0 && database.teams[0].id !== $appSession.teamId
			),
			services:
				!onlyOthers &&
				services.filter(
					(service: any) => service?.teams.length > 0 && service.teams[0].id === $appSession.teamId
				),
			otherServices: services.filter(
				(service: any) => service?.teams.length > 0 && service.teams[0].id !== $appSession.teamId
			),
			gitSources:
				!onlyOthers &&
				gitSources.filter(
					(gitSource: any) =>
						gitSource?.teams.length > 0 && gitSource.teams[0].id === $appSession.teamId
				),
			otherGitSources: gitSources.filter(
				(gitSource: any) =>
					gitSource?.teams.length > 0 && gitSource.teams[0].id !== $appSession.teamId
			),
			destinations:
				!onlyOthers &&
				destinations.filter(
					(destination: any) =>
						destination?.teams.length > 0 && destination.teams[0].id === $appSession.teamId
				),
			otherDestinations: destinations.filter(
				(destination: any) =>
					destination?.teams.length > 0 && destination.teams[0].id !== $appSession.teamId
			)
		};
	}
	function clearFiltered() {
		filtered.applications = [];
		filtered.otherApplications = [];
		filtered.databases = [];
		filtered.otherDatabases = [];
		filtered.services = [];
		filtered.otherServices = [];
		filtered.gitSources = [];
		filtered.otherGitSources = [];
		filtered.destinations = [];
		filtered.otherDestinations = [];
	}

	async function getStatus(resources: any, force: boolean = false) {
		const { id, buildPack, dualCerts, type, simpleDockerfile } = resources;
		if (buildPack && applications.length + filtered.otherApplications.length > 10 && !force) {
			noInitialStatus.applications = true;
			return;
		}
		if (type && services.length + filtered.otherServices.length > 10 && !force) {
			noInitialStatus.services = true;
			return;
		}
		if (databases.length + filtered.otherDatabases.length > 10 && !force) {
			noInitialStatus.databases = true;
			return;
		}
		if (status[id] && !force) return status[id];
		while (numberOfGetStatus > 1) {
			await asyncSleep(getRndInteger(100, 500));
		}
		try {
			numberOfGetStatus++;
			let isRunning = false;
			let isDegraded = false;
			if (buildPack || simpleDockerfile) {
				const response = await trpc.applications.status.query({ id });
				if (response.length === 0) {
					isRunning = false;
				} else if (response.length === 1) {
					isRunning = response[0].status.isRunning;
				} else {
					let overallStatus = false;
					for (const oneStatus of response) {
						if (oneStatus.status.isRunning) {
							overallStatus = true;
						} else {
							isDegraded = true;
							break;
						}
					}
					if (overallStatus) {
						isRunning = true;
					} else {
						isRunning = false;
					}
				}
			} else if (typeof dualCerts !== 'undefined') {
				const response = await trpc.services.status.query({ id });
				if (Object.keys(response).length === 0) {
					isRunning = false;
				} else {
					let overallStatus = false;
					for (const oneStatus of Object.keys(response)) {
						if (response[oneStatus].status.isRunning) {
							overallStatus = true;
						} else {
							isDegraded = true;
							break;
						}
					}
					if (overallStatus) {
						isRunning = true;
					} else {
						isRunning = false;
					}
				}
			} else {
				const response = await trpc.databases.status.query({ id });
				isRunning = response.isRunning;
			}

			if (isRunning) {
				status[id] = 'running';
				return 'running';
			} else if (isDegraded) {
				status[id] = 'degraded';
				return 'degraded';
			} else {
				status[id] = 'stopped';
				return 'stopped';
			}
		} catch (error) {
			status[id] = 'error';
			return 'error';
		} finally {
			status = { ...status };
			numberOfGetStatus--;
		}
	}
	function filterState(state: string) {
		clearFiltered();
		filtered.applications = applications.filter((application: any) => {
			if (status[application.id] === state && application.teams[0].id === $appSession.teamId)
				return application;
		});
		filtered.otherApplications = applications.filter((application: any) => {
			if (status[application.id] === state && application.teams[0].id !== $appSession.teamId)
				return application;
		});
		filtered.databases = databases.filter((database: any) => {
			if (status[database.id] === state && database.teams[0].id === $appSession.teamId)
				return database;
		});
		filtered.otherDatabases = databases.filter((database: any) => {
			if (status[database.id] === state && database.teams[0].id !== $appSession.teamId)
				return database;
		});
		filtered.services = services.filter((service: any) => {
			if (status[service.id] === state && service.teams[0].id === $appSession.teamId)
				return service;
		});
		filtered.otherServices = services.filter((service: any) => {
			if (status[service.id] === state && service.teams[0].id !== $appSession.teamId)
				return service;
		});
	}
	function filterSpecific(type: any) {
		clearFiltered();
		const otherType = 'other' + type[0].toUpperCase() + type.substring(1);
		filtered[type] = eval(type).filter(
			(resource: any) => resource.teams[0].id === $appSession.teamId
		);
		filtered[otherType] = eval(type).filter(
			(resource: any) => resource.teams[0].id !== $appSession.teamId
		);
	}
	function applicationFilters(application: any) {
		return (
			(application.id && application.id.toLowerCase().includes($search.toLowerCase())) ||
			(application.name && application.name.toLowerCase().includes($search.toLowerCase())) ||
			(application.fqdn && application.fqdn.toLowerCase().includes($search.toLowerCase())) ||
			(application.dockerComposeConfiguration &&
				application.dockerComposeConfiguration.toLowerCase().includes($search.toLowerCase())) ||
			(application.repository &&
				application.repository.toLowerCase().includes($search.toLowerCase())) ||
			(application.buildpack &&
				application.buildpack.toLowerCase().includes($search.toLowerCase())) ||
			(application.branch && application.branch.toLowerCase().includes($search.toLowerCase())) ||
			(application.destinationDockerId &&
				application.destinationDocker.name.toLowerCase().includes($search.toLowerCase())) ||
			('bot'.includes($search) && application.settings.isBot)
		);
	}
	function databaseFilters(database: any) {
		return (
			(database.id && database.id.toLowerCase().includes($search.toLowerCase())) ||
			(database.name && database.name.toLowerCase().includes($search.toLowerCase())) ||
			(database.type && database.type.toLowerCase().includes($search.toLowerCase())) ||
			(database.version && database.version.toLowerCase().includes($search.toLowerCase())) ||
			(database.destinationDockerId &&
				database.destinationDocker.name.toLowerCase().includes($search.toLowerCase()))
		);
	}
	function serviceFilters(service: any) {
		return (
			(service.id && service.id.toLowerCase().includes($search.toLowerCase())) ||
			(service.name && service.name.toLowerCase().includes($search.toLowerCase())) ||
			(service.type && service.type.toLowerCase().includes($search.toLowerCase())) ||
			(service.fqdn && service.fqdn.toLowerCase().includes($search.toLowerCase())) ||
			(service.version && service.version.toLowerCase().includes($search.toLowerCase())) ||
			(service.destinationDockerId &&
				service.destinationDocker.name.toLowerCase().includes($search.toLowerCase()))
		);
	}
	function gitSourceFilters(source: any) {
		return (
			(source.id && source.id.toLowerCase().includes($search.toLowerCase())) ||
			(source.name && source.name.toLowerCase().includes($search.toLowerCase())) ||
			(source.type && source.type.toLowerCase().includes($search.toLowerCase())) ||
			(source.htmlUrl && source.htmlUrl.toLowerCase().includes($search.toLowerCase())) ||
			(source.apiUrl && source.apiUrl.toLowerCase().includes($search.toLowerCase()))
		);
	}
	function destinationFilters(destination: any) {
		return (
			(destination.id && destination.id.toLowerCase().includes($search.toLowerCase())) ||
			(destination.name && destination.name.toLowerCase().includes($search.toLowerCase())) ||
			(destination.type && destination.type.toLowerCase().includes($search.toLowerCase()))
		);
	}
	function doSearch(bang?: string) {
		if (bang || bang === '') $search = bang;
		if ($search) {
			filtered = setInitials();
			if ($search.startsWith('!')) {
				if ($search === '!running') {
					filterState('running');
				} else if ($search === '!stopped') {
					filterState('stopped');
				} else if ($search === '!error') {
					filterState('error');
				} else if ($search === '!app') {
					filterSpecific('applications');
				} else if ($search === '!db') {
					filterSpecific('databases');
				} else if ($search === '!service') {
					filterSpecific('services');
				} else if ($search === '!git') {
					filterSpecific('gitSources');
				} else if ($search === '!destination') {
					filterSpecific('destinations');
				} else if ($search === '!bot') {
					clearFiltered();
					filtered.applications = applications.filter((application: any) => {
						return application.settings.isBot;
					});
					filtered.otherApplications = applications.filter((application: any) => {
						return application.settings.isBot && application.teams[0].id !== $appSession.teamId;
					});
				} else if ($search === '!notmine') {
					clearFiltered();
					filtered = setInitials(true);
				}
			} else {
				filtered.applications = filtered.applications.filter((application: any) =>
					applicationFilters(application)
				);
				filtered.otherApplications = filtered.otherApplications.filter((application: any) =>
					applicationFilters(application)
				);
				filtered.databases = filtered.databases.filter((database: any) =>
					databaseFilters(database)
				);
				filtered.otherDatabases = filtered.otherDatabases.filter((database: any) =>
					databaseFilters(database)
				);
				filtered.services = filtered.services.filter((service: any) => serviceFilters(service));
				filtered.otherServices = filtered.otherServices.filter((service: any) =>
					serviceFilters(service)
				);
				filtered.gitSources = filtered.gitSources.filter((source: any) => gitSourceFilters(source));
				filtered.otherGitSources = filtered.otherGitSources.filter((source: any) =>
					gitSourceFilters(source)
				);
				filtered.destinations = filtered.destinations.filter((destination: any) =>
					destinationFilters(destination)
				);
				filtered.otherDestinations = filtered.otherDestinations.filter((destination: any) =>
					destinationFilters(destination)
				);
			}
		} else {
			filtered = setInitials();
		}
	}
	async function cleanupApplications() {
		try {
			const sure = confirm(
				'Are you sure? This will delete all UNCONFIGURED applications and their data.'
			);
			if (sure) {
				await trpc.applications.cleanup.query();
				return window.location.reload();
			}
		} catch (error) {
			return errorNotification(error);
		}
	}
	async function cleanupServices() {
		try {
			const sure = confirm(
				'Are you sure? This will delete all UNCONFIGURED services and their data.'
			);
			if (sure) {
				await trpc.services.cleanup.query();
				return window.location.reload();
			}
		} catch (error) {
			return errorNotification(error);
		}
	}
	async function cleanupDatabases() {
		try {
			const sure = confirm(
				'Are you sure? This will delete all UNCONFIGURED databases and their data.'
			);
			if (sure) {
				await trpc.databases.cleanup.query();
				return window.location.reload();
			}
		} catch (error) {
			return errorNotification(error);
		}
	}
	async function deleteApplication(id: string) {
		try {
			const sure = confirm('Are you sure? This will delete this application!');
			if (sure) {
				await trpc.applications.delete.mutate({ id, force: true });
				return window.location.reload();
			}
		} catch (error) {
			return errorNotification(error);
		}
	}
	async function deleteService(id: string) {
		try {
			const sure = confirm('Are you sure? This will delete this service!');
			if (sure) {
				await trpc.services.delete.mutate({ id });
				// await del(`/services/${id}`, {});
				return window.location.reload();
			}
		} catch (error) {
			return errorNotification(error);
		}
	}
	async function deleteDatabase(id: string) {
		try {
			const sure = confirm('Are you sure? This will delete this database!');
			if (sure) {
				await trpc.databases.delete.mutate({ id, force: true });
				return window.location.reload();
			}
		} catch (error) {
			return errorNotification(error);
		}
	}
</script>

<nav class="header">
	<h1 class="mr-4 text-2xl font-bold">Dashboard</h1>
	{#if $appSession.isAdmin && (applications.length !== 0 || destinations.length !== 0 || databases.length !== 0 || services.length !== 0 || gitSources.length !== 0 || destinations.length !== 0)}
		<NewResource />
	{/if}
</nav>
<div class="container lg:mx-auto lg:p-0 px-8 pt-5">
	{#if applications.length !== 0 || destinations.length !== 0 || databases.length !== 0 || services.length !== 0 || gitSources.length !== 0 || destinations.length !== 0}
		<div class="space-x-2 lg:flex lg:justify-center text-center mb-4 ">
			<button
				class="btn btn-sm btn-ghost"
				class:bg-applications={$search === '!app'}
				class:hover:bg-coollabs={$search !== '!app'}
				on:click={() => doSearch('!app')}
			>
				<svg
					xmlns="http://www.w3.org/2000/svg"
					class="h-6 w-6 mr-2 hidden lg:block "
					viewBox="0 0 24 24"
					stroke-width="1.5"
					stroke="currentcolor"
					fill="none"
					stroke-linecap="round"
					stroke-linejoin="round"
				>
					<path stroke="none" d="M0 0h24v24H0z" fill="none" />
					<rect x="4" y="4" width="6" height="6" rx="1" />
					<rect x="4" y="14" width="6" height="6" rx="1" />
					<rect x="14" y="14" width="6" height="6" rx="1" />
					<line x1="14" y1="7" x2="20" y2="7" />
					<line x1="17" y1="4" x2="17" y2="10" />
				</svg> Applications</button
			>
			<button
				class="btn btn-sm btn-ghost"
				class:bg-services={$search === '!service'}
				class:hover:bg-coollabs={$search !== '!service'}
				on:click={() => doSearch('!service')}
				><svg
					xmlns="http://www.w3.org/2000/svg"
					class="h-6 w-6 mr-2 hidden lg:block"
					viewBox="0 0 24 24"
					stroke-width="1.5"
					stroke="currentColor"
					fill="none"
					stroke-linecap="round"
					stroke-linejoin="round"
				>
					<path stroke="none" d="M0 0h24v24H0z" fill="none" />
					<path d="M7 18a4.6 4.4 0 0 1 0 -9a5 4.5 0 0 1 11 2h1a3.5 3.5 0 0 1 0 7h-12" />
				</svg> Services</button
			>
			<button
				class="btn btn-sm btn-ghost "
				class:bg-databases={$search === '!db'}
				class:hover:bg-coollabs={$search !== '!db'}
				on:click={() => doSearch('!db')}
			>
				<svg
					xmlns="http://www.w3.org/2000/svg"
					class="h-6 w-6 mr-2 hidden lg:block"
					viewBox="0 0 24 24"
					stroke-width="1.5"
					stroke="currentColor"
					fill="none"
					stroke-linecap="round"
					stroke-linejoin="round"
				>
					<path stroke="none" d="M0 0h24v24H0z" fill="none" />
					<ellipse cx="12" cy="6" rx="8" ry="3" />
					<path d="M4 6v6a8 3 0 0 0 16 0v-6" />
					<path d="M4 12v6a8 3 0 0 0 16 0v-6" />
				</svg> Databases</button
			>
			<button
				class="btn btn-sm btn-ghost"
				class:bg-sources={$search === '!git'}
				class:hover:bg-coollabs={$search !== '!git'}
				on:click={() => doSearch('!git')}
			>
				<svg
					xmlns="http://www.w3.org/2000/svg"
					class="h-6 w-6 mr-2 hidden lg:block"
					viewBox="0 0 24 24"
					stroke-width="1.5"
					stroke="currentColor"
					fill="none"
					stroke-linecap="round"
					stroke-linejoin="round"
				>
					<path stroke="none" d="M0 0h24v24H0z" fill="none" />
					<circle cx="6" cy="6" r="2" />
					<circle cx="18" cy="18" r="2" />
					<path d="M11 6h5a2 2 0 0 1 2 2v8" />
					<polyline points="14 9 11 6 14 3" />
					<path d="M13 18h-5a2 2 0 0 1 -2 -2v-8" />
					<polyline points="10 15 13 18 10 21" />
				</svg> Git Sources</button
			>
			<button
				class="btn btn-sm btn-ghost"
				class:bg-destinations={$search === '!destination'}
				class:hover:bg-coollabs={$search !== '!destination'}
				on:click={() => doSearch('!destination')}
			>
				<svg
					xmlns="http://www.w3.org/2000/svg"
					class="h-6 w-6 mr-2 hidden lg:block"
					viewBox="0 0 24 24"
					stroke-width="1.5"
					stroke="currentColor"
					fill="none"
					stroke-linecap="round"
					stroke-linejoin="round"
				>
					<path stroke="none" d="M0 0h24v24H0z" fill="none" />
					<path
						d="M22 12.54c-1.804 -.345 -2.701 -1.08 -3.523 -2.94c-.487 .696 -1.102 1.568 -.92 2.4c.028 .238 -.32 1.002 -.557 1h-14c0 5.208 3.164 7 6.196 7c4.124 .022 7.828 -1.376 9.854 -5c1.146 -.101 2.296 -1.505 2.95 -2.46z"
					/>
					<path d="M5 10h3v3h-3z" />
					<path d="M8 10h3v3h-3z" />
					<path d="M11 10h3v3h-3z" />
					<path d="M8 7h3v3h-3z" />
					<path d="M11 7h3v3h-3z" />
					<path d="M11 4h3v3h-3z" />
					<path d="M4.571 18c1.5 0 2.047 -.074 2.958 -.78" />
					<line x1="10" y1="16" x2="10" y2="16.01" />
				</svg>Destinations</button
			>
		</div>
		<div class="form-control">
			<div class="input-group flex w-full">
				<!-- svelte-ignore a11y-click-events-have-key-events -->
				<div
					class="btn btn-square cursor-default no-animation hover:bg-error"
					on:click={() => doSearch('')}
				>
					<svg
						xmlns="http://www.w3.org/2000/svg"
						class="w-6 h-6"
						viewBox="0 0 24 24"
						stroke-width="1.5"
						stroke="currentcolor"
						fill="none"
						stroke-linecap="round"
						stroke-linejoin="round"
					>
						<path stroke="none" d="M0 0h24v24H0z" fill="none" />
						<line x1="18" y1="6" x2="6" y2="18" />
						<line x1="6" y1="6" x2="18" y2="18" />
					</svg>
				</div>

				<input
					bind:this={searchInput}
					id="search"
					type="text"
					placeholder="Search: You can search for names, domains, types, database types, version, servers etc..."
					class="w-full input input-bordered input-primary"
					bind:value={$search}
					on:input={() => doSearch()}
				/>
			</div>
			<label for="search" class="label w-full mt-3">
				<span class="label-text text-xs flex flex-wrap gap-2 items-center">
					<button
						class:bg-coollabs={$search === '!bot'}
						class="badge badge-lg text-white text-xs rounded"
						on:click={() => doSearch('!bot')}>Bots</button
					>

					<button
						class:bg-coollabs={$search === '!notmine'}
						class="badge badge-lg text-white text-xs rounded"
						on:click={() => doSearch('!notmine')}>Other Teams</button
					>
					<button
						class:bg-coollabs={$search === '!running'}
						class="badge badge-lg text-white text-xs rounded"
						on:click={() => doSearch('!running')}>Running</button
					>
					<button
						class:bg-coollabs={$search === '!stopped'}
						class="badge badge-lg text-white text-xs rounded"
						on:click={() => doSearch('!stopped')}>Stopped</button
					>
					<button
						class:bg-coollabs={$search === '!error'}
						class="badge badge-lg text-white text-xs rounded"
						on:click={() => doSearch('!error')}>Error</button
					>
				</span>
			</label>
		</div>
	{/if}
	{#if (filtered.applications.length > 0 && applications.length > 0) || filtered.otherApplications.length > 0}
		<div class="flex items-center mt-10 space-x-2">
			<h1 class="title lg:text-3xl">Applications</h1>
			<button class="btn btn-sm btn-primary" on:click={refreshStatusApplications}
				>{noInitialStatus.applications ? 'Load Status' : 'Refresh Status'}</button
			>
			{#if foundUnconfiguredApplication}
				<button
					class="btn btn-sm"
					class:loading={loading.applications}
					disabled={loading.applications}
					on:click={cleanupApplications}>Cleanup Unconfigured Resources</button
				>
			{/if}
		</div>
	{/if}
	{#if filtered.applications.length > 0 && applications.length > 0}
		<div class="divider" />
		<div
			class="grid grid-col gap-2 lg:gap-8 auto-cols-max grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 p-4"
		>
			{#if filtered.applications.length > 0}
				{#each filtered.applications as application}
					<a class="no-underline mb-5" href={`/applications/${application.id}`}>
						<div
							class="w-full rounded p-5 bg-coolgray-200 hover:bg-green-600 indicator duration-150"
						>
							{#await getStatus(application)}
								<span class="indicator-item badge bg-yellow-300 badge-sm" />
							{:then}
								{#if !noInitialStatus.applications}
									{#if status[application.id] === 'loading'}
										<span class="indicator-item badge bg-yellow-300 badge-sm" />
									{:else if status[application.id] === 'running'}
										<span class="indicator-item badge bg-success badge-sm" />
									{:else if status[application.id] === 'degraded'}
										<span
											class="indicator-item indicator-middle indicator-center badge bg-warning  text-black font-bold badge-xl"
											>Degraded</span
										>
									{:else}
										<span class="indicator-item badge bg-error badge-sm" />
									{/if}
								{/if}
							{/await}
							<div class="w-full flex flex-row">
								<ApplicationsIcons {application} isAbsolute={true} />
								<div class="w-full flex flex-col">
									<h1 class="font-bold text-base truncate">
										{application.name}
										{#if application.settings?.isBot}
											<span class="text-xs badge bg-coolblack border-none text-applications"
												>BOT</span
											>
										{/if}
									</h1>
									<div class="h-10 text-xs">
										{#if application?.fqdn}
											<h2>{application?.fqdn.replace('https://', '').replace('http://', '')}</h2>
										{:else if !application.settings?.isBot && !application?.fqdn && application.buildPack !== 'compose'}
											<h2 class="text-red-500">Not configured</h2>
										{/if}
										{#if application.destinationDocker?.name}
											<div class="truncate">{application.destinationDocker?.name}</div>
										{/if}
										{#if application.teams.length > 0 && application.teams[0]?.name}
											<div class="truncate">{application.teams[0]?.name}</div>
										{/if}
									</div>

									<div class="flex justify-end items-end space-x-2 h-10">
										{#if application?.fqdn}
											<a
												href={application?.fqdn}
												target="_blank noreferrer"
												class="icons hover:bg-green-500"
											>
												<svg
													xmlns="http://www.w3.org/2000/svg"
													class="h-6 w-6"
													viewBox="0 0 24 24"
													stroke-width="1.5"
													stroke="currentColor"
													fill="none"
													stroke-linecap="round"
													stroke-linejoin="round"
												>
													<path stroke="none" d="M0 0h24v24H0z" fill="none" />
													<path d="M11 7h-5a2 2 0 0 0 -2 2v9a2 2 0 0 0 2 2h9a2 2 0 0 0 2 -2v-5" />
													<line x1="10" y1="14" x2="20" y2="4" />
													<polyline points="15 4 20 4 20 9" />
												</svg>
											</a>
										{/if}

										{#if application.settings?.isBot && application.exposePort}
											<a
												href={`http://${dev ? 'localhost' : settings.ipv4}:${
													application.exposePort
												}`}
												target="_blank noreferrer"
												class="icons hover:bg-green-500"
											>
												<svg
													xmlns="http://www.w3.org/2000/svg"
													class="h-6 w-6"
													viewBox="0 0 24 24"
													stroke-width="1.5"
													stroke="currentColor"
													fill="none"
													stroke-linecap="round"
													stroke-linejoin="round"
												>
													<path stroke="none" d="M0 0h24v24H0z" fill="none" />
													<path d="M11 7h-5a2 2 0 0 0 -2 2v9a2 2 0 0 0 2 2h9a2 2 0 0 0 2 -2v-5" />
													<line x1="10" y1="14" x2="20" y2="4" />
													<polyline points="15 4 20 4 20 9" />
												</svg>
											</a>
										{/if}
										<button
											class="icons hover:bg-green-500"
											on:click|stopPropagation|preventDefault={() =>
												deleteApplication(application.id)}><Icons.Delete /></button
										>
									</div>
								</div>
							</div>
						</div>
					</a>
				{/each}
			{:else}
				<h1 class="">Nothing here.</h1>
			{/if}
		</div>
	{/if}
	{#if filtered.otherApplications.length > 0}
		{#if filtered.applications.length > 0}
			<div class="divider w-32 mx-auto" />
		{/if}
	{/if}
	{#if filtered.otherApplications.length > 0}
		<div
			class="grid grid-col gap-8 auto-cols-max grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 p-4"
		>
			{#each filtered.otherApplications as application}
				<a class="no-underline mb-5" href={`/applications/${application.id}`}>
					<div class="w-full rounded p-5 bg-coolgray-200 hover:bg-green-600 indicator duration-150">
						{#await getStatus(application)}
							<span class="indicator-item badge bg-yellow-300 badge-sm" />
						{:then}
							{#if !noInitialStatus.applications}
								{#if status[application.id] === 'loading'}
									<span class="indicator-item badge bg-yellow-300 badge-sm" />
								{:else if status[application.id] === 'running'}
									<span class="indicator-item badge bg-success badge-sm" />
								{:else}
									<span class="indicator-item badge bg-error badge-sm" />
								{/if}
							{/if}
						{/await}
						<div class="w-full flex flex-row">
							<ApplicationsIcons {application} isAbsolute={true} />
							<div class="w-full flex flex-col">
								<h1 class="font-bold text-base truncate">
									{application.name}
									{#if application.settings?.isBot}
										<span class="text-xs badge bg-coolblack border-none text-applications">BOT</span
										>
									{/if}
								</h1>
								<div class="h-10 text-xs">
									{#if application?.fqdn}
										<h2>{application?.fqdn.replace('https://', '').replace('http://', '')}</h2>
									{:else if !application.settings?.isBot && !application?.fqdn}
										<h2 class="text-red-500">Not configured</h2>
									{/if}
									{#if application.destinationDocker?.name}
										<div class="truncate">{application.destinationDocker?.name}</div>
									{/if}
									{#if application.teams.length > 0 && application.teams[0]?.name}
										<div class="truncate">{application.teams[0]?.name}</div>
									{/if}
								</div>

								<div class="flex justify-end items-end space-x-2 h-10">
									{#if application?.fqdn}
										<a
											href={application?.fqdn}
											target="_blank noreferrer"
											class="icons hover:bg-green-500"
										>
											<svg
												xmlns="http://www.w3.org/2000/svg"
												class="h-6 w-6"
												viewBox="0 0 24 24"
												stroke-width="1.5"
												stroke="currentColor"
												fill="none"
												stroke-linecap="round"
												stroke-linejoin="round"
											>
												<path stroke="none" d="M0 0h24v24H0z" fill="none" />
												<path d="M11 7h-5a2 2 0 0 0 -2 2v9a2 2 0 0 0 2 2h9a2 2 0 0 0 2 -2v-5" />
												<line x1="10" y1="14" x2="20" y2="4" />
												<polyline points="15 4 20 4 20 9" />
											</svg>
										</a>
									{/if}

									{#if application.settings?.isBot && application.exposePort}
										<a
											href={`http://${dev ? 'localhost' : settings.ipv4}:${application.exposePort}`}
											target="_blank noreferrer"
											class="icons hover:bg-green-500"
										>
											<svg
												xmlns="http://www.w3.org/2000/svg"
												class="h-6 w-6"
												viewBox="0 0 24 24"
												stroke-width="1.5"
												stroke="currentColor"
												fill="none"
												stroke-linecap="round"
												stroke-linejoin="round"
											>
												<path stroke="none" d="M0 0h24v24H0z" fill="none" />
												<path d="M11 7h-5a2 2 0 0 0 -2 2v9a2 2 0 0 0 2 2h9a2 2 0 0 0 2 -2v-5" />
												<line x1="10" y1="14" x2="20" y2="4" />
												<polyline points="15 4 20 4 20 9" />
											</svg>
										</a>
									{/if}
									<button
										class="icons hover:bg-green-500"
										on:click|stopPropagation|preventDefault={() =>
											deleteApplication(application.id)}><Icons.Delete /></button
									>
								</div>
							</div>
						</div>
					</div>
				</a>
			{/each}
		</div>
	{/if}
	{#if (filtered.services.length > 0 && services.length > 0) || filtered.otherServices.length > 0}
		<div class="flex items-center mt-10 space-x-2">
			<h1 class="title lg:text-3xl">Services</h1>
			<button class="btn btn-sm btn-primary" on:click={refreshStatusServices}
				>{noInitialStatus.services ? 'Load Status' : 'Refresh Status'}</button
			>
			{#if foundUnconfiguredService}
				<button
					class="btn btn-sm"
					class:loading={loading.services}
					disabled={loading.services}
					on:click={cleanupServices}>Cleanup Unconfigured Resources</button
				>
			{/if}
		</div>
	{/if}
	{#if filtered.services.length > 0 && services.length > 0}
		<div class="divider" />
		<div
			class="grid grid-col gap-8 auto-cols-max grid-cols-1 md:grid-cols-2 lg:md:grid-cols-3 xl:grid-cols-4 p-4"
		>
			{#if filtered.services.length > 0}
				{#each filtered.services as service}
					{#key service.id}
						<a class="no-underline mb-5" href={`/services/${service.id}`}>
							<div
								class="w-full rounded p-5 bg-coolgray-200 hover:bg-pink-600 indicator duration-150"
							>
								{#await getStatus(service)}
									<span class="indicator-item badge bg-yellow-300 badge-sm" />
								{:then}
									{#if !noInitialStatus.services}
										{#if status[service.id] === 'loading'}
											<span class="indicator-item badge bg-yellow-300 badge-sm" />
										{:else if status[service.id] === 'running'}
											<span class="indicator-item badge bg-success badge-sm" />
										{:else}
											<span class="indicator-item badge bg-error badge-sm" />
										{/if}
									{/if}
								{/await}
								<div class="w-full flex flex-row">
									<ServiceIcons type={service.type} isAbsolute={true} />
									<div class="w-full flex flex-col">
										<h1 class="font-bold text-base truncate">{service.name}</h1>
										<div class="h-10 text-xs">
											{#if service?.fqdn}
												<h2>{service?.fqdn.replace('https://', '').replace('http://', '')}</h2>
											{:else}
												<h2 class="text-red-500">URL not configured</h2>
											{/if}
											{#if service.destinationDocker?.name}
												<div class="truncate">{service.destinationDocker?.name}</div>
											{/if}
											{#if service.teams.length > 0 && service.teams[0]?.name}
												<div class="truncate">{service.teams[0]?.name}</div>
											{/if}
										</div>
										<div class="flex justify-end items-end space-x-2 h-10">
											{#if service?.fqdn}
												<a
													href={service?.fqdn}
													target="_blank noreferrer"
													class="icons hover:bg-pink-500"
												>
													<svg
														xmlns="http://www.w3.org/2000/svg"
														class="h-6 w-6"
														viewBox="0 0 24 24"
														stroke-width="1.5"
														stroke="currentColor"
														fill="none"
														stroke-linecap="round"
														stroke-linejoin="round"
													>
														<path stroke="none" d="M0 0h24v24H0z" fill="none" />
														<path d="M11 7h-5a2 2 0 0 0 -2 2v9a2 2 0 0 0 2 2h9a2 2 0 0 0 2 -2v-5" />
														<line x1="10" y1="14" x2="20" y2="4" />
														<polyline points="15 4 20 4 20 9" />
													</svg>
												</a>
											{/if}
											<button
												class="icons hover:bg-pink-500"
												on:click|stopPropagation|preventDefault={() => deleteService(service.id)}
												><Icons.Delete /></button
											>
										</div>
									</div>
								</div>
							</div>
						</a>
					{/key}
				{/each}
			{:else}
				<h1 class="">Nothing here.</h1>
			{/if}
		</div>
	{/if}
	{#if filtered.otherServices.length > 0}
		{#if filtered.services.length > 0}
			<div class="divider w-32 mx-auto" />
		{/if}
	{/if}
	{#if filtered.otherServices.length > 0}
		<div
			class="grid grid-col gap-8 auto-cols-max grid-cols-1 md:grid-cols-2 lg:md:grid-cols-3 xl:grid-cols-4 p-4"
		>
			{#each filtered.otherServices as service}
				{#key service.id}
					<a class="no-underline mb-5" href={`/services/${service.id}`}>
						<div
							class="w-full rounded p-5 bg-coolgray-200 hover:bg-pink-600 indicator duration-150"
						>
							{#await getStatus(service)}
								<span class="indicator-item badge bg-yellow-300 badge-sm" />
							{:then}
								{#if !noInitialStatus.services}
									{#if status[service.id] === 'loading'}
										<span class="indicator-item badge bg-yellow-300 badge-sm" />
									{:else if status[service.id] === 'running'}
										<span class="indicator-item badge bg-success badge-sm" />
									{:else}
										<span class="indicator-item badge bg-error badge-sm" />
									{/if}
								{/if}
							{/await}
							<div class="w-full flex flex-row">
								<ServiceIcons type={service.type} isAbsolute={true} />
								<div class="w-full flex flex-col">
									<h1 class="font-bold text-base truncate">{service.name}</h1>
									<div class="h-10 text-xs">
										{#if service?.fqdn}
											<h2>{service?.fqdn.replace('https://', '').replace('http://', '')}</h2>
										{:else}
											<h2 class="text-red-500">URL not configured</h2>
										{/if}
										{#if service.destinationDocker?.name}
											<div class="truncate">{service.destinationDocker?.name}</div>
										{/if}
										{#if service.teams.length > 0 && service.teams[0]?.name}
											<div class="truncate">{service.teams[0]?.name}</div>
										{/if}
									</div>
									<div class="flex justify-end items-end space-x-2 h-10">
										{#if service?.fqdn}
											<a
												href={service?.fqdn}
												target="_blank noreferrer"
												class="icons hover:bg-pink-500"
											>
												<svg
													xmlns="http://www.w3.org/2000/svg"
													class="h-6 w-6"
													viewBox="0 0 24 24"
													stroke-width="1.5"
													stroke="currentColor"
													fill="none"
													stroke-linecap="round"
													stroke-linejoin="round"
												>
													<path stroke="none" d="M0 0h24v24H0z" fill="none" />
													<path d="M11 7h-5a2 2 0 0 0 -2 2v9a2 2 0 0 0 2 2h9a2 2 0 0 0 2 -2v-5" />
													<line x1="10" y1="14" x2="20" y2="4" />
													<polyline points="15 4 20 4 20 9" />
												</svg>
											</a>
										{/if}
										<button
											class="icons hover:bg-pink-500"
											on:click|stopPropagation|preventDefault={() => deleteService(service.id)}
											><Icons.Delete /></button
										>
									</div>
								</div>
							</div>
						</div>
					</a>
				{/key}
			{/each}
		</div>
	{/if}
	{#if (filtered.databases.length > 0 && databases.length > 0) || filtered.otherDatabases.length > 0}
		<div class="flex items-center mt-10 space-x-2">
			<h1 class="title lg:text-3xl">Databases</h1>
			<button class="btn btn-sm btn-primary" on:click={refreshStatusDatabases}
				>{noInitialStatus.databases ? 'Load Status' : 'Refresh Status'}</button
			>
			{#if foundUnconfiguredDatabase}
				<button
					class="btn btn-sm"
					class:loading={loading.databases}
					disabled={loading.databases}
					on:click={cleanupDatabases}>Cleanup Unconfigured Resources</button
				>
			{/if}
		</div>
	{/if}
	{#if filtered.databases.length > 0 && databases.length > 0}
		<div class="divider" />
		<div
			class="grid grid-col gap-8 auto-cols-max grid-cols-1 md:grid-cols-2 lg:md:grid-cols-3 xl:grid-cols-4 p-4"
		>
			{#if filtered.databases.length > 0}
				{#each filtered.databases as database}
					{#key database.id}
						<a class="no-underline mb-5" href={`/databases/${database.id}`}>
							<div
								class="w-full rounded p-5 bg-coolgray-200 hover:bg-databases indicator duration-150"
							>
								{#await getStatus(database)}
									<span class="indicator-item badge bg-yellow-300 badge-sm" />
								{:then}
									{#if !noInitialStatus.databases}
										{#if status[database.id] === 'loading'}
											<span class="indicator-item badge bg-yellow-300 badge-sm" />
										{:else if status[database.id] === 'running'}
											<span class="indicator-item badge bg-success badge-sm" />
										{:else}
											<span class="indicator-item badge bg-error badge-sm" />
										{/if}
									{/if}
								{/await}
								<div class="w-full flex flex-row">
									<DatabaseIcons type={database.type} isAbsolute={true} />
									<div class="w-full flex flex-col">
										<div class="h-10">
											<h1 class="font-bold text-base truncate">{database.name}</h1>
											<div class="h-10 text-xs">
												{#if database?.version}
													<h2 class="">{database?.version}</h2>
												{:else}
													<h2 class="text-red-500">Not version not configured</h2>
												{/if}
												{#if database.destinationDocker?.name}
													<div class="truncate">{database.destinationDocker?.name}</div>
												{/if}
												{#if database.teams.length > 0 && database.teams[0]?.name}
													<div class="truncate">{database.teams[0]?.name}</div>
												{/if}
											</div>
										</div>
										<div class="flex justify-end items-center space-x-2 h-10">
											{#if database.settings?.isPublic}
												<div title="Public" class="icons hover:bg-transparent">
													<svg
														xmlns="http://www.w3.org/2000/svg"
														class="h-6 w-6 "
														viewBox="0 0 24 24"
														stroke-width="1.5"
														stroke="currentColor"
														fill="none"
														stroke-linecap="round"
														stroke-linejoin="round"
													>
														<path stroke="none" d="M0 0h24v24H0z" fill="none" />
														<circle cx="12" cy="12" r="9" />
														<line x1="3.6" y1="9" x2="20.4" y2="9" />
														<line x1="3.6" y1="15" x2="20.4" y2="15" />
														<path d="M11.5 3a17 17 0 0 0 0 18" />
														<path d="M12.5 3a17 17 0 0 1 0 18" />
													</svg>
												</div>
											{/if}
											<button
												class="icons hover:bg-databases-100"
												on:click|stopPropagation|preventDefault={() => deleteDatabase(database.id)}
												><Icons.Delete /></button
											>
										</div>
									</div>
								</div>
							</div>
						</a>
					{/key}
				{/each}
			{:else}
				<h1 class="">Nothing here.</h1>
			{/if}
		</div>
	{/if}
	{#if filtered.otherDatabases.length > 0}
		{#if filtered.databases.length > 0}
			<div class="divider w-32 mx-auto" />
		{/if}
	{/if}
	{#if filtered.otherDatabases.length > 0}
		<div
			class="grid grid-col gap-8 auto-cols-max grid-cols-1 md:grid-cols-2 lg:md:grid-cols-3 xl:grid-cols-4 p-4"
		>
			{#each filtered.otherDatabases as database}
				{#key database.id}
					<a class="no-underline mb-5" href={`/databases/${database.id}`}>
						<div
							class="w-full rounded p-5 bg-coolgray-200 hover:bg-databases indicator duration-150"
						>
							{#await getStatus(database)}
								<span class="indicator-item badge bg-yellow-300 badge-sm" />
							{:then}
								{#if !noInitialStatus.databases}
									{#if status[database.id] === 'loading'}
										<span class="indicator-item badge bg-yellow-300 badge-sm" />
									{:else if status[database.id] === 'running'}
										<span class="indicator-item badge bg-success badge-sm" />
									{:else}
										<span class="indicator-item badge bg-error badge-sm" />
									{/if}
								{/if}
							{/await}
							<div class="w-full flex flex-row">
								<DatabaseIcons type={database.type} isAbsolute={true} />
								<div class="w-full flex flex-col">
									<div class="h-10">
										<h1 class="font-bold text-base truncate">{database.name}</h1>
										<div class="h-10 text-xs">
											{#if database?.version}
												<h2 class="">{database?.version}</h2>
											{:else}
												<h2 class="text-red-500">Not version not configured</h2>
											{/if}
											{#if database.destinationDocker?.name}
												<div class="truncate">{database.destinationDocker?.name}</div>
											{/if}
											{#if database.teams.length > 0 && database.teams[0]?.name}
												<div class="truncate">{database.teams[0]?.name}</div>
											{/if}
										</div>
									</div>
									<div class="flex justify-end items-end space-x-2 h-10">
										{#if database.settings?.isPublic}
											<div title="Public">
												<svg
													xmlns="http://www.w3.org/2000/svg"
													class="h-6 w-6 "
													viewBox="0 0 24 24"
													stroke-width="1.5"
													stroke="currentColor"
													fill="none"
													stroke-linecap="round"
													stroke-linejoin="round"
												>
													<path stroke="none" d="M0 0h24v24H0z" fill="none" />
													<circle cx="12" cy="12" r="9" />
													<line x1="3.6" y1="9" x2="20.4" y2="9" />
													<line x1="3.6" y1="15" x2="20.4" y2="15" />
													<path d="M11.5 3a17 17 0 0 0 0 18" />
													<path d="M12.5 3a17 17 0 0 1 0 18" />
												</svg>
											</div>
										{/if}
										<button
											class="icons hover:bg-databases"
											on:click|stopPropagation|preventDefault={() => deleteDatabase(database.id)}
											><Icons.Delete /></button
										>
									</div>
								</div>
							</div>
						</div>
					</a>
				{/key}
			{/each}
		</div>
	{/if}
	{#if (filtered.gitSources.length > 0 && gitSources.length > 0) || filtered.otherGitSources.length > 0}
		<div class="flex items-center mt-10">
			<h1 class="title lg:text-3xl">Git Sources</h1>
		</div>
	{/if}
	{#if filtered.gitSources.length > 0 && gitSources.length > 0}
		<div class="divider" />
		<div
			class="grid grid-col gap-8 auto-cols-max grid-cols-1 md:grid-cols-2 lg:md:grid-cols-3 xl:grid-cols-4 p-4"
		>
			{#if filtered.gitSources.length > 0}
				{#each filtered.gitSources as source}
					{#key source.id}
						<a class="no-underline mb-5" href={`/sources/${source.id}`}>
							<div
								class="w-full rounded p-5 bg-coolgray-200 hover:bg-sources indicator duration-150"
							>
								<div class="w-full flex flex-row">
									<div class="absolute top-0 left-0 -m-5 flex">
										{#if source?.type === 'gitlab'}
											<svg viewBox="0 0 128 128" class="h-10 w-10">
												<path
													fill="#FC6D26"
													d="M126.615 72.31l-7.034-21.647L105.64 7.76c-.716-2.206-3.84-2.206-4.556 0l-13.94 42.903H40.856L26.916 7.76c-.717-2.206-3.84-2.206-4.557 0L8.42 50.664 1.385 72.31a4.792 4.792 0 001.74 5.358L64 121.894l60.874-44.227a4.793 4.793 0 001.74-5.357"
												/><path
													fill="#E24329"
													d="M64 121.894l23.144-71.23H40.856L64 121.893z"
												/><path
													fill="#FC6D26"
													d="M64 121.894l-23.144-71.23H8.42L64 121.893z"
												/><path
													fill="#FCA326"
													d="M8.42 50.663L1.384 72.31a4.79 4.79 0 001.74 5.357L64 121.894 8.42 50.664z"
												/><path
													fill="#E24329"
													d="M8.42 50.663h32.436L26.916 7.76c-.717-2.206-3.84-2.206-4.557 0L8.42 50.664z"
												/><path
													fill="#FC6D26"
													d="M64 121.894l23.144-71.23h32.437L64 121.893z"
												/><path
													fill="#FCA326"
													d="M119.58 50.663l7.035 21.647a4.79 4.79 0 01-1.74 5.357L64 121.894l55.58-71.23z"
												/><path
													fill="#E24329"
													d="M119.58 50.663H87.145l13.94-42.902c.717-2.206 3.84-2.206 4.557 0l13.94 42.903z"
												/>
											</svg>
										{:else if source?.type === 'github'}
											<svg viewBox="0 0 128 128" class="h-10 w-10">
												<g fill="#ffffff"
													><path
														fill-rule="evenodd"
														clip-rule="evenodd"
														d="M64 5.103c-33.347 0-60.388 27.035-60.388 60.388 0 26.682 17.303 49.317 41.297 57.303 3.017.56 4.125-1.31 4.125-2.905 0-1.44-.056-6.197-.082-11.243-16.8 3.653-20.345-7.125-20.345-7.125-2.747-6.98-6.705-8.836-6.705-8.836-5.48-3.748.413-3.67.413-3.67 6.063.425 9.257 6.223 9.257 6.223 5.386 9.23 14.127 6.562 17.573 5.02.542-3.903 2.107-6.568 3.834-8.076-13.413-1.525-27.514-6.704-27.514-29.843 0-6.593 2.36-11.98 6.223-16.21-.628-1.52-2.695-7.662.584-15.98 0 0 5.07-1.623 16.61 6.19C53.7 35 58.867 34.327 64 34.304c5.13.023 10.3.694 15.127 2.033 11.526-7.813 16.59-6.19 16.59-6.19 3.287 8.317 1.22 14.46.593 15.98 3.872 4.23 6.215 9.617 6.215 16.21 0 23.194-14.127 28.3-27.574 29.796 2.167 1.874 4.097 5.55 4.097 11.183 0 8.08-.07 14.583-.07 16.572 0 1.607 1.088 3.49 4.148 2.897 23.98-7.994 41.263-30.622 41.263-57.294C124.388 32.14 97.35 5.104 64 5.104z"
													/><path
														d="M26.484 91.806c-.133.3-.605.39-1.035.185-.44-.196-.685-.605-.543-.906.13-.31.603-.395 1.04-.188.44.197.69.61.537.91zm2.446 2.729c-.287.267-.85.143-1.232-.28-.396-.42-.47-.983-.177-1.254.298-.266.844-.14 1.24.28.394.426.472.984.17 1.255zM31.312 98.012c-.37.258-.976.017-1.35-.52-.37-.538-.37-1.183.01-1.44.373-.258.97-.025 1.35.507.368.545.368 1.19-.01 1.452zm3.261 3.361c-.33.365-1.036.267-1.552-.23-.527-.487-.674-1.18-.343-1.544.336-.366 1.045-.264 1.564.23.527.486.686 1.18.333 1.543zm4.5 1.951c-.147.473-.825.688-1.51.486-.683-.207-1.13-.76-.99-1.238.14-.477.823-.7 1.512-.485.683.206 1.13.756.988 1.237zm4.943.361c.017.498-.563.91-1.28.92-.723.017-1.308-.387-1.315-.877 0-.503.568-.91 1.29-.924.717-.013 1.306.387 1.306.88zm4.598-.782c.086.485-.413.984-1.126 1.117-.7.13-1.35-.172-1.44-.653-.086-.498.422-.997 1.122-1.126.714-.123 1.354.17 1.444.663zm0 0"
													/></g
												>
											</svg>
										{/if}

										{#if source.isSystemWide}
											<svg
												xmlns="http://www.w3.org/2000/svg"
												class="h-10 w-10"
												viewBox="0 0 24 24"
												stroke-width="1.5"
												stroke="currentColor"
												fill="none"
												stroke-linecap="round"
												stroke-linejoin="round"
											>
												<path stroke="none" d="M0 0h24v24H0z" fill="none" />
												<circle cx="12" cy="12" r="9" />
												<line x1="3.6" y1="9" x2="20.4" y2="9" />
												<line x1="3.6" y1="15" x2="20.4" y2="15" />
												<path d="M11.5 3a17 17 0 0 0 0 18" />
												<path d="M12.5 3a17 17 0 0 1 0 18" />
											</svg>
										{/if}
									</div>
									<div class="w-full flex flex-col">
										<div class="h-10">
											<h1 class="font-bold text-base truncate">{source.name}</h1>
											{#if source.teams.length > 0 && source.teams[0]?.name}
												<div class="truncate text-xs">{source.teams[0]?.name}</div>
											{/if}
										</div>

										<div class="flex justify-end items-end space-x-2 h-10" />
									</div>
								</div>
							</div>
						</a>
					{/key}
				{/each}
			{:else}
				<h1 class="">Nothing here.</h1>
			{/if}
		</div>
	{/if}
	{#if filtered.otherGitSources.length > 0}
		{#if filtered.gitSources.length > 0}
			<div class="divider w-32 mx-auto" />
		{/if}
	{/if}
	{#if filtered.otherGitSources.length > 0}
		<div
			class="grid grid-col gap-8 auto-cols-max grid-cols-1 md:grid-cols-2 lg:md:grid-cols-3 xl:grid-cols-4 p-4"
		>
			{#each filtered.otherGitSources as source}
				{#key source.id}
					<a class="no-underline mb-5" href={`/sources/${source.id}`}>
						<div class="w-full rounded p-5 bg-coolgray-200 hover:bg-sources indicator duration-150">
							<div class="w-full flex flex-row">
								<div class="absolute top-0 left-0 -m-5 flex">
									{#if source?.type === 'gitlab'}
										<svg viewBox="0 0 128 128" class="h-10 w-10">
											<path
												fill="#FC6D26"
												d="M126.615 72.31l-7.034-21.647L105.64 7.76c-.716-2.206-3.84-2.206-4.556 0l-13.94 42.903H40.856L26.916 7.76c-.717-2.206-3.84-2.206-4.557 0L8.42 50.664 1.385 72.31a4.792 4.792 0 001.74 5.358L64 121.894l60.874-44.227a4.793 4.793 0 001.74-5.357"
											/><path fill="#E24329" d="M64 121.894l23.144-71.23H40.856L64 121.893z" /><path
												fill="#FC6D26"
												d="M64 121.894l-23.144-71.23H8.42L64 121.893z"
											/><path
												fill="#FCA326"
												d="M8.42 50.663L1.384 72.31a4.79 4.79 0 001.74 5.357L64 121.894 8.42 50.664z"
											/><path
												fill="#E24329"
												d="M8.42 50.663h32.436L26.916 7.76c-.717-2.206-3.84-2.206-4.557 0L8.42 50.664z"
											/><path fill="#FC6D26" d="M64 121.894l23.144-71.23h32.437L64 121.893z" /><path
												fill="#FCA326"
												d="M119.58 50.663l7.035 21.647a4.79 4.79 0 01-1.74 5.357L64 121.894l55.58-71.23z"
											/><path
												fill="#E24329"
												d="M119.58 50.663H87.145l13.94-42.902c.717-2.206 3.84-2.206 4.557 0l13.94 42.903z"
											/>
										</svg>
									{:else if source?.type === 'github'}
										<svg viewBox="0 0 128 128" class="h-10 w-10">
											<g fill="#ffffff"
												><path
													fill-rule="evenodd"
													clip-rule="evenodd"
													d="M64 5.103c-33.347 0-60.388 27.035-60.388 60.388 0 26.682 17.303 49.317 41.297 57.303 3.017.56 4.125-1.31 4.125-2.905 0-1.44-.056-6.197-.082-11.243-16.8 3.653-20.345-7.125-20.345-7.125-2.747-6.98-6.705-8.836-6.705-8.836-5.48-3.748.413-3.67.413-3.67 6.063.425 9.257 6.223 9.257 6.223 5.386 9.23 14.127 6.562 17.573 5.02.542-3.903 2.107-6.568 3.834-8.076-13.413-1.525-27.514-6.704-27.514-29.843 0-6.593 2.36-11.98 6.223-16.21-.628-1.52-2.695-7.662.584-15.98 0 0 5.07-1.623 16.61 6.19C53.7 35 58.867 34.327 64 34.304c5.13.023 10.3.694 15.127 2.033 11.526-7.813 16.59-6.19 16.59-6.19 3.287 8.317 1.22 14.46.593 15.98 3.872 4.23 6.215 9.617 6.215 16.21 0 23.194-14.127 28.3-27.574 29.796 2.167 1.874 4.097 5.55 4.097 11.183 0 8.08-.07 14.583-.07 16.572 0 1.607 1.088 3.49 4.148 2.897 23.98-7.994 41.263-30.622 41.263-57.294C124.388 32.14 97.35 5.104 64 5.104z"
												/><path
													d="M26.484 91.806c-.133.3-.605.39-1.035.185-.44-.196-.685-.605-.543-.906.13-.31.603-.395 1.04-.188.44.197.69.61.537.91zm2.446 2.729c-.287.267-.85.143-1.232-.28-.396-.42-.47-.983-.177-1.254.298-.266.844-.14 1.24.28.394.426.472.984.17 1.255zM31.312 98.012c-.37.258-.976.017-1.35-.52-.37-.538-.37-1.183.01-1.44.373-.258.97-.025 1.35.507.368.545.368 1.19-.01 1.452zm3.261 3.361c-.33.365-1.036.267-1.552-.23-.527-.487-.674-1.18-.343-1.544.336-.366 1.045-.264 1.564.23.527.486.686 1.18.333 1.543zm4.5 1.951c-.147.473-.825.688-1.51.486-.683-.207-1.13-.76-.99-1.238.14-.477.823-.7 1.512-.485.683.206 1.13.756.988 1.237zm4.943.361c.017.498-.563.91-1.28.92-.723.017-1.308-.387-1.315-.877 0-.503.568-.91 1.29-.924.717-.013 1.306.387 1.306.88zm4.598-.782c.086.485-.413.984-1.126 1.117-.7.13-1.35-.172-1.44-.653-.086-.498.422-.997 1.122-1.126.714-.123 1.354.17 1.444.663zm0 0"
												/></g
											>
										</svg>
									{/if}

									{#if source.isSystemWide}
										<svg
											xmlns="http://www.w3.org/2000/svg"
											class="h-10 w-10"
											viewBox="0 0 24 24"
											stroke-width="1.5"
											stroke="currentColor"
											fill="none"
											stroke-linecap="round"
											stroke-linejoin="round"
										>
											<path stroke="none" d="M0 0h24v24H0z" fill="none" />
											<circle cx="12" cy="12" r="9" />
											<line x1="3.6" y1="9" x2="20.4" y2="9" />
											<line x1="3.6" y1="15" x2="20.4" y2="15" />
											<path d="M11.5 3a17 17 0 0 0 0 18" />
											<path d="M12.5 3a17 17 0 0 1 0 18" />
										</svg>
									{/if}
								</div>
								<div class="w-full flex flex-col">
									<div class="h-10">
										<h1 class="font-bold text-base truncate">{source.name}</h1>
										{#if source.teams.length > 0 && source.teams[0]?.name}
											<div class="truncate text-xs">{source.teams[0]?.name}</div>
										{/if}
									</div>
									<div class="flex justify-end items-end space-x-2 h-10" />
								</div>
							</div>
						</div>
					</a>
				{/key}
			{/each}
		</div>
	{/if}
	{#if (filtered.destinations.length > 0 && destinations.length > 0) || filtered.otherDestinations.length > 0}
		<div class="flex items-center mt-10">
			<h1 class="title lg:text-3xl">Destinations</h1>
		</div>
	{/if}
	{#if filtered.destinations.length > 0 && destinations.length > 0}
		<div class="divider" />
		<div
			class="grid grid-col gap-8 auto-cols-max grid-cols-1 md:grid-cols-2 lg:md:grid-cols-3 xl:grid-cols-4 p-4 "
		>
			{#if filtered.destinations.length > 0}
				{#each filtered.destinations as destination}
					{#key destination.id}
						<a class="no-underline mb-5" href={`/destinations/${destination.id}`}>
							<div
								class="w-full rounded p-5 bg-coolgray-200 hover:bg-destinations indicator duration-150"
							>
								<div class="w-full flex flex-row">
									<div class="absolute top-0 left-0 -m-5 h-10 w-10">
										<svg
											xmlns="http://www.w3.org/2000/svg"
											class="absolute top-0 left-0 -m-2 h-12 w-12 text-sky-500"
											viewBox="0 0 24 24"
											stroke-width="1.5"
											stroke="currentColor"
											fill="none"
											stroke-linecap="round"
											stroke-linejoin="round"
										>
											<path stroke="none" d="M0 0h24v24H0z" fill="none" />
											<path
												d="M22 12.54c-1.804 -.345 -2.701 -1.08 -3.523 -2.94c-.487 .696 -1.102 1.568 -.92 2.4c.028 .238 -.32 1.002 -.557 1h-14c0 5.208 3.164 7 6.196 7c4.124 .022 7.828 -1.376 9.854 -5c1.146 -.101 2.296 -1.505 2.95 -2.46z"
											/>
											<path d="M5 10h3v3h-3z" />
											<path d="M8 10h3v3h-3z" />
											<path d="M11 10h3v3h-3z" />
											<path d="M8 7h3v3h-3z" />
											<path d="M11 7h3v3h-3z" />
											<path d="M11 4h3v3h-3z" />
											<path d="M4.571 18c1.5 0 2.047 -.074 2.958 -.78" />
											<line x1="10" y1="16" x2="10" y2="16.01" />
										</svg>
										{#if destination.remoteEngine}
											<svg
												xmlns="http://www.w3.org/2000/svg"
												class="absolute top-0 left-9 -m-2 h-6 w-6 text-sky-500 rotate-45"
												viewBox="0 0 24 24"
												stroke-width="3"
												stroke="currentColor"
												fill="none"
												stroke-linecap="round"
												stroke-linejoin="round"
											>
												<path stroke="none" d="M0 0h24v24H0z" fill="none" />
												<line x1="12" y1="18" x2="12.01" y2="18" />
												<path d="M9.172 15.172a4 4 0 0 1 5.656 0" />
												<path d="M6.343 12.343a8 8 0 0 1 11.314 0" />
												<path d="M3.515 9.515c4.686 -4.687 12.284 -4.687 17 0" />
											</svg>
										{/if}
									</div>
									<div class="w-full flex flex-col">
										<h1 class="font-bold text-base truncate">{destination.name}</h1>
										<div class="h-10 text-xs">
											{#if $appSession.teamId === '0' && destination.remoteVerified === false && destination.remoteEngine}
												<h2 class="text-red-500">Not verified yet</h2>
											{/if}
											{#if destination.remoteEngine && !destination.sshKeyId}
												<h2 class="text-red-500">SSH key missing</h2>
											{/if}
											{#if destination.teams.length > 0 && destination.teams[0]?.name}
												<div class="truncate">{destination.teams[0]?.name}</div>
											{/if}
										</div>
									</div>
								</div>
							</div>
						</a>
					{/key}
				{/each}
			{:else}
				<h1 class="">Nothing here.</h1>
			{/if}
		</div>
	{/if}
	{#if filtered.otherDestinations.length > 0}
		{#if filtered.destinations.length > 0}
			<div class="divider w-32 mx-auto" />
		{/if}
	{/if}
	{#if filtered.otherDestinations.length > 0}
		<div
			class="grid grid-col gap-8 auto-cols-max grid-cols-1 md:grid-cols-2 lg:md:grid-cols-3 xl:grid-cols-4 p-4"
		>
			{#each filtered.otherDestinations as destination}
				{#key destination.id}
					<a class="no-underline mb-5" href={`/destinations/${destination.id}`}>
						<div
							class="w-full rounded p-5 bg-coolgray-200 hover:bg-destinations indicator duration-150"
						>
							<div class="w-full flex flex-row">
								<div class="absolute top-0 left-0 -m-5 h-10 w-10">
									<svg
										xmlns="http://www.w3.org/2000/svg"
										class="absolute top-0 left-0 -m-2 h-12 w-12 text-sky-500"
										viewBox="0 0 24 24"
										stroke-width="1.5"
										stroke="currentColor"
										fill="none"
										stroke-linecap="round"
										stroke-linejoin="round"
									>
										<path stroke="none" d="M0 0h24v24H0z" fill="none" />
										<path
											d="M22 12.54c-1.804 -.345 -2.701 -1.08 -3.523 -2.94c-.487 .696 -1.102 1.568 -.92 2.4c.028 .238 -.32 1.002 -.557 1h-14c0 5.208 3.164 7 6.196 7c4.124 .022 7.828 -1.376 9.854 -5c1.146 -.101 2.296 -1.505 2.95 -2.46z"
										/>
										<path d="M5 10h3v3h-3z" />
										<path d="M8 10h3v3h-3z" />
										<path d="M11 10h3v3h-3z" />
										<path d="M8 7h3v3h-3z" />
										<path d="M11 7h3v3h-3z" />
										<path d="M11 4h3v3h-3z" />
										<path d="M4.571 18c1.5 0 2.047 -.074 2.958 -.78" />
										<line x1="10" y1="16" x2="10" y2="16.01" />
									</svg>
									{#if destination.remoteEngine}
										<svg
											xmlns="http://www.w3.org/2000/svg"
											class="absolute top-0 left-9 -m-2 h-6 w-6 text-sky-500 rotate-45"
											viewBox="0 0 24 24"
											stroke-width="3"
											stroke="currentColor"
											fill="none"
											stroke-linecap="round"
											stroke-linejoin="round"
										>
											<path stroke="none" d="M0 0h24v24H0z" fill="none" />
											<line x1="12" y1="18" x2="12.01" y2="18" />
											<path d="M9.172 15.172a4 4 0 0 1 5.656 0" />
											<path d="M6.343 12.343a8 8 0 0 1 11.314 0" />
											<path d="M3.515 9.515c4.686 -4.687 12.284 -4.687 17 0" />
										</svg>
									{/if}
								</div>
								<div class="w-full flex flex-col">
									<h1 class="font-bold text-base truncate">{destination.name}</h1>
									<div class="h-10 text-xs">
										{#if $appSession.teamId === '0' && destination.remoteVerified === false && destination.remoteEngine}
											<h2 class="text-red-500">Not verified yet</h2>
										{/if}
										{#if destination.remoteEngine && !destination.sshKeyId}
											<h2 class="text-red-500">SSH key missing</h2>
										{/if}
										{#if destination.teams.length > 0 && destination.teams[0]?.name}
											<div class="truncate">{destination.teams[0]?.name}</div>
										{/if}
									</div>
								</div>
							</div>
						</div>
					</a>
				{/key}
			{/each}
		</div>
	{/if}

	{#if filtered.applications.length === 0 && filtered.destinations.length === 0 && filtered.databases.length === 0 && filtered.services.length === 0 && filtered.gitSources.length === 0 && filtered.destinations.length === 0 && $search}
		<div class="flex flex-col items-center justify-center h-full pt-20">
			<h1 class="text-2xl font-bold pb-4">
				Nothing found with <span class="text-error font-bold">{$search}</span>.
			</h1>
		</div>
	{/if}
	{#if applications.length === 0 && destinations.length === 0 && databases.length === 0 && services.length === 0 && gitSources.length === 0 && destinations.length === 0}
		<div class="hero">
			<div class="hero-content text-center">
				<div class="">
					<h1 class="text-5xl font-bold">
						Hey <svg
							xmlns="http://www.w3.org/2000/svg"
							class="w-20 h-20 inline-flex"
							viewBox="0 0 36 36"
							><path
								fill="#EF9645"
								d="M4.861 9.147c.94-.657 2.357-.531 3.201.166l-.968-1.407c-.779-1.111-.5-2.313.612-3.093 1.112-.777 4.263 1.312 4.263 1.312-.786-1.122-.639-2.544.483-3.331 1.122-.784 2.67-.513 3.456.611l10.42 14.72L25 31l-11.083-4.042L4.25 12.625c-.793-1.129-.519-2.686.611-3.478z"
							/><path
								fill="#FFDC5D"
								d="M2.695 17.336s-1.132-1.65.519-2.781c1.649-1.131 2.78.518 2.78.518l5.251 7.658c.181-.302.379-.6.6-.894L4.557 11.21s-1.131-1.649.519-2.78c1.649-1.131 2.78.518 2.78.518l6.855 9.997c.255-.208.516-.417.785-.622L7.549 6.732s-1.131-1.649.519-2.78c1.649-1.131 2.78.518 2.78.518l7.947 11.589c.292-.179.581-.334.871-.498L12.238 4.729s-1.131-1.649.518-2.78c1.649-1.131 2.78.518 2.78.518l7.854 11.454 1.194 1.742c-4.948 3.394-5.419 9.779-2.592 13.902.565.825 1.39.26 1.39.26-3.393-4.949-2.357-10.51 2.592-13.903L24.515 8.62s-.545-1.924 1.378-2.47c1.924-.545 2.47 1.379 2.47 1.379l1.685 5.004c.668 1.984 1.379 3.961 2.32 5.831 2.657 5.28 1.07 11.842-3.94 15.279-5.465 3.747-12.936 2.354-16.684-3.11L2.695 17.336z"
							/><g fill="#5DADEC"
								><path
									d="M12 32.042C8 32.042 3.958 28 3.958 24c0-.553-.405-1-.958-1s-1.042.447-1.042 1C1.958 30 6 34.042 12 34.042c.553 0 1-.489 1-1.042s-.447-.958-1-.958z"
								/><path
									d="M7 34c-3 0-5-2-5-5 0-.553-.447-1-1-1s-1 .447-1 1c0 4 3 7 7 7 .553 0 1-.447 1-1s-.447-1-1-1zM24 2c-.552 0-1 .448-1 1s.448 1 1 1c4 0 8 3.589 8 8 0 .552.448 1 1 1s1-.448 1-1c0-5.514-4-10-10-10z"
								/><path
									d="M29 .042c-.552 0-1 .406-1 .958s.448 1.042 1 1.042c3 0 4.958 2.225 4.958 4.958 0 .552.489 1 1.042 1s.958-.448.958-1C35.958 3.163 33 .042 29 .042z"
								/></g
							></svg
						>
					</h1>
					<p class="py-6 text-xl">It looks like you did not configure anything yet.</p>
					<NewResource><button class="btn btn-primary">Let's Get Started</button></NewResource>
				</div>
			</div>
		</div>
	{/if}
</div>
<div class="mb-20" />
