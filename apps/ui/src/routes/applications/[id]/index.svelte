<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, params, stuff, url }) => {
		try {
			if (stuff?.application?.id) {
				return {
					props: {
						application: stuff.application,
						settings: stuff.settings
					}
				};
			}
			const response = await get(`/applications/${params.id}`);
			return {
				props: {
					...response
				}
			};
		} catch (error) {
			return {
				status: 500,
				error: new Error(`Could not load ${url}`)
			};
		}
	};
</script>

<script lang="ts">
	export let application: any;
	export let settings: any;
	import { page } from '$app/stores';
	import { onDestroy, onMount } from 'svelte';
	import Select from 'svelte-select';
	import { get, post } from '$lib/api';
	import cuid from 'cuid';
	import {
		addToast,
		appSession,
		checkIfDeploymentEnabledApplications,
		setLocation,
		status,
		isDeploymentEnabled,
		features
	} from '$lib/store';
	import { t } from '$lib/translations';
	import { errorNotification, getDomain, notNodeDeployments, staticDeployments } from '$lib/common';
	import Setting from '$lib/components/Setting.svelte';
	import Tooltip from '$lib/components/Tooltip.svelte';
	import Explainer from '$lib/components/Explainer.svelte';
	import { goto } from '$app/navigation';
	import { fade } from 'svelte/transition';

	const { id } = $page.params;

	$: isDisabled =
		!$appSession.isAdmin || $status.application.isRunning || $status.application.initialLoading;

	let domainEl: HTMLInputElement;

	let loading = false;

	let usageLoading = false;
	let usage = {
		MemUsage: 0,
		CPUPerc: 0,
		NetIO: 0
	};
	let usageInterval: any;

	let forceSave = false;
	let debug = application.settings.debug;
	let previews = application.settings.previews;
	let dualCerts = application.settings.dualCerts;
	let autodeploy = application.settings.autodeploy;
	let isBot = application.settings.isBot;
	let isDBBranching = application.settings.isDBBranching;

	let baseDatabaseBranch: any = application?.connectedDatabase?.hostedDatabaseDBName || null;
	let nonWWWDomain = application.fqdn && getDomain(application.fqdn).replace(/^www\./, '');
	let isNonWWWDomainOK = false;
	let isWWWDomainOK = false;

	let wsgis = [
		{
			value: 'None',
			label: 'None'
		},
		{
			value: 'Gunicorn',
			label: 'Gunicorn'
		},
		{
			value: 'Uvicorn',
			label: 'Uvicorn'
		}
	];
	function containerClass() {
		return 'text-white bg-transparent font-thin px-0 w-full border-dashed border-coolgray-300';
	}

	async function getUsage() {
		if (usageLoading) return;
		if (!$status.application.isRunning) return;
		usageLoading = true;
		const data = await get(`/applications/${id}/usage`);
		usage = data.usage;
		usageLoading = false;
	}
	onDestroy(() => {
		clearInterval(usageInterval);
	});
	onMount(async () => {
		if (window.location.hostname === 'demo.coolify.io' && !application.fqdn) {
			application.fqdn = `http://${cuid()}.demo.coolify.io`;
			await handleSubmit();
		}
		// !isBot && domainEl.focus();
		await getUsage();
		usageInterval = setInterval(async () => {
			await getUsage();
		}, 1000);
		await getBaseBuildImages();
	});
	async function getBaseBuildImages() {
		const data = await post(`/applications/images`, {
			buildPack: application.buildPack,
			deploymentType: application.deploymentType
		});
		const baseImageCorrect = data.baseImages.filter(
			(image: any) => image.value === application.baseImage
		);
		if (baseImageCorrect.length === 0) {
			application.baseImage = data.baseImage;
		}
		application.baseImages = data.baseImages;

		const baseBuildImageCorrect = data.baseBuildImages.filter(
			(image: any) => image.value === application.baseBuildImage
		);
		if (baseBuildImageCorrect.length === 0) {
			application.baseBuildImage = data.baseBuildImage;
		}
		application.baseBuildImages = data.baseBuildImages;
		if (application.deploymentType === 'static' && application.port !== '80') {
			application.port = data.port;
		}
		if (application.deploymentType === 'node' && application.port === '80') {
			application.port = data.port;
		}
		if (application.deploymentType === 'static' && !application.publishDirectory) {
			application.publishDirectory = data.publishDirectory;
		}
		if (application.deploymentType === 'node' && application.publishDirectory === 'out') {
			application.publishDirectory = data.publishDirectory;
		}
	}
	async function changeSettings(name: any) {
		if (name === 'debug') {
			debug = !debug;
		}
		if (name === 'previews') {
			previews = !previews;
		}
		if (name === 'dualCerts') {
			dualCerts = !dualCerts;
		}
		if (name === 'autodeploy') {
			autodeploy = !autodeploy;
		}
		if (name === 'isBot') {
			if ($status.application.isRunning) return;
			isBot = !isBot;
			application.settings.isBot = isBot;
			application.fqdn = null;
			setLocation(application, settings);
		}
		if (name === 'isDBBranching') {
			isDBBranching = !isDBBranching;
		}
		try {
			await post(`/applications/${id}/settings`, {
				previews,
				debug,
				dualCerts,
				isBot,
				autodeploy,
				isDBBranching,
				branch: application.branch,
				projectId: application.projectId
			});
			return addToast({
				message: $t('application.settings_saved'),
				type: 'success'
			});
		} catch (error) {
			if (name === 'debug') {
				debug = !debug;
			}
			if (name === 'previews') {
				previews = !previews;
			}
			if (name === 'dualCerts') {
				dualCerts = !dualCerts;
			}
			if (name === 'autodeploy') {
				autodeploy = !autodeploy;
			}
			if (name === 'isBot') {
				isBot = !isBot;
			}
			if (name === 'isDBBranching') {
				isDBBranching = !isDBBranching;
			}
			return errorNotification(error);
		} finally {
			$isDeploymentEnabled = checkIfDeploymentEnabledApplications($appSession.isAdmin, application);
		}
	}
	async function handleSubmit() {
		if (loading) return;
		loading = true;
		try {
			nonWWWDomain = application.fqdn && getDomain(application.fqdn).replace(/^www\./, '');
			if (application.deploymentType)
				application.deploymentType = application.deploymentType.toLowerCase();
			!isBot &&
				(await post(`/applications/${id}/check`, {
					fqdn: application.fqdn,
					forceSave,
					dualCerts,
					exposePort: application.exposePort
				}));
			await post(`/applications/${id}`, { ...application, baseDatabaseBranch });
			setLocation(application, settings);
			$isDeploymentEnabled = checkIfDeploymentEnabledApplications($appSession.isAdmin, application);

			forceSave = false;

			addToast({
				message: 'Configuration saved.',
				type: 'success'
			});
		} catch (error) {
			//@ts-ignore
			if (error?.message.startsWith($t('application.dns_not_set_partial_error'))) {
				forceSave = true;
				if (dualCerts) {
					isNonWWWDomainOK = await isDNSValid(getDomain(nonWWWDomain), false);
					isWWWDomainOK = await isDNSValid(getDomain(`www.${nonWWWDomain}`), true);
				} else {
					const isWWW = getDomain(application.fqdn).includes('www.');
					if (isWWW) {
						isWWWDomainOK = await isDNSValid(getDomain(`www.${nonWWWDomain}`), true);
					} else {
						isNonWWWDomainOK = await isDNSValid(getDomain(nonWWWDomain), false);
					}
				}
			}
			return errorNotification(error);
		} finally {
			loading = false;
		}
	}
	async function selectWSGI(event: any) {
		application.pythonWSGI = event.detail.value;
	}
	async function selectBaseImage(event: any) {
		application.baseImage = event.detail.value;
		await handleSubmit();
	}
	async function selectBaseBuildImage(event: any) {
		application.baseBuildImage = event.detail.value;
		await handleSubmit();
	}
	async function selectDeploymentType(event: any) {
		application.deploymentType = event.detail.value;
		await getBaseBuildImages();
		await handleSubmit();
	}

	async function isDNSValid(domain: any, isWWW: any) {
		try {
			await get(`/applications/${id}/check?domain=${domain}`);
			addToast({
				message: 'DNS configuration is valid.',
				type: 'success'
			});
			isWWW ? (isWWWDomainOK = true) : (isNonWWWDomainOK = true);
			return true;
		} catch (error) {
			errorNotification(error);
			isWWW ? (isWWWDomainOK = false) : (isNonWWWDomainOK = false);
			return false;
		}
	}
</script>

<div class="mx-auto max-w-6xl px-6 lg:my-0 my-4 lg:pt-0 pt-4 rounded" in:fade>
	<div class="text-center">
		<div class="stat w-64">
			<div class="stat-title">Used Memory / Memory Limit</div>
			<div class="stat-value text-xl">{usage?.MemUsage}</div>
		</div>

		<div class="stat w-64">
			<div class="stat-title">Used CPU</div>
			<div class="stat-value text-xl">{usage?.CPUPerc}</div>
		</div>

		<div class="stat w-64">
			<div class="stat-title">Network IO</div>
			<div class="stat-value text-xl">{usage?.NetIO}</div>
		</div>
	</div>
</div>
<div class="mx-auto max-w-6xl px-6 pb-12">
	<!-- svelte-ignore missing-declaration -->
	<form on:submit|preventDefault={handleSubmit} class="py-4">
		<div class="flex space-x-1 pb-5  items-center">
			<h1 class="title">{$t('general')}</h1>
			{#if $appSession.isAdmin}
				<button
					class="btn btn-sm"
					type="submit"
					class:bg-applications={!loading}
					class:loading
					class:bg-orange-600={forceSave}
					class:hover:bg-orange-400={forceSave}
					disabled={loading}>{$t('forms.save')}</button
				>
			{/if}
		</div>

		<div class="grid grid-flow-row gap-2 lg:px-10 px-2 pr-5">
			<div class="mt-2 grid grid-cols-2 items-center">
				<label for="name">{$t('forms.name')}</label>
				<input name="name" id="name" class="w-full" bind:value={application.name} required />
			</div>
			<div class="grid grid-cols-2 items-center">
				<label for="gitSource">{$t('application.git_source')}</label>
				{#if isDisabled || application.settings.isPublicRepository}
					<input
						disabled={isDisabled || application.settings.isPublicRepository}
						class="w-full"
						value={application.gitSource.name}
					/>
				{:else}
					<a
						href={`/applications/${id}/configuration/source?from=/applications/${id}`}
						class="no-underline"
						><input
							value={application.gitSource.name}
							id="gitSource"
							class="cursor-pointer hover:bg-coolgray-500 w-full"
						/></a
					>
				{/if}
			</div>
			<div class="grid grid-cols-2 items-center">
				<label for="repository">{$t('application.git_repository')}</label>
				{#if isDisabled || application.settings.isPublicRepository}
					<input
						class="w-full"
						disabled={isDisabled || application.settings.isPublicRepository}
						value="{application.repository}/{application.branch}"
					/>
				{:else}
					<a
						href={`/applications/${id}/configuration/repository?from=/applications/${id}&to=/applications/${id}/configuration/buildpack`}
						class="no-underline"
						><input
							value="{application.repository}/{application.branch}"
							id="repository"
							class="cursor-pointer hover:bg-coolgray-500 w-full"
						/></a
					>
				{/if}
			</div>
			<div class="grid grid-cols-2 items-center">
				<label for="buildPack">{$t('application.build_pack')}</label>
				{#if isDisabled}
					<input class="uppercase w-full" disabled={isDisabled} value={application.buildPack} />
				{:else}
					<a
						href={`/applications/${id}/configuration/buildpack?from=/applications/${id}`}
						class="no-underline"
					>
						<input
							value={application.buildPack}
							id="buildPack"
							class="cursor-pointer hover:bg-coolgray-500 capitalize w-full"
						/></a
					>
				{/if}
			</div>
			<div class="grid grid-cols-2 items-center">
				<label for="destination">{$t('application.destination')}</label>
				<div class="no-underline">
					<input
						value={application.destinationDocker.name}
						id="destination"
						disabled
						class="bg-transparent w-full"
					/>
				</div>
			</div>
			{#if application.buildCommand || application.buildPack === 'rust' || application.buildPack === 'laravel'}
				<div class="grid grid-cols-2 items-center">
					<label for="baseBuildImage"
						>{$t('application.base_build_image')}
						<Explainer
							explanation={application.buildPack === 'laravel'
								? 'For building frontend assets with webpack.'
								: 'Image that will be used during the build process.'}
						/>
					</label>
					<Select
						{isDisabled}
						containerClasses={isDisabled && containerClass()}
						id="baseBuildImages"
						showIndicator={!$status.application.isRunning}
						items={application.baseBuildImages}
						on:select={selectBaseBuildImage}
						value={application.baseBuildImage}
						isClearable={false}
					/>
				</div>
			{/if}
			{#if application.buildPack !== 'docker'}
				<div class="grid grid-cols-2 items-center">
					<label for="baseImage"
						>{$t('application.base_image')}
						<Explainer explanation={'Image that will be used for the deployment.'} /></label
					>
					<div class="custom-select-wrapper">
						<Select
							{isDisabled}
							containerClasses={isDisabled && containerClass()}
							id="baseImages"
							showIndicator={!$status.application.isRunning}
							items={application.baseImages}
							on:select={selectBaseImage}
							value={application.baseImage}
							isClearable={false}
						/>
					</div>
				</div>
			{/if}
			{#if application.buildPack !== 'docker' && (application.buildPack === 'nextjs' || application.buildPack === 'nuxtjs')}
				<div class="grid grid-cols-2 items-center pb-8">
					<label for="deploymentType"
						>Deployment Type
						<Explainer
							explanation={"Defines how to deploy your application. <br><br><span class='text-green-500 font-bold'>Static</span> is for static websites, <span class='text-green-500 font-bold'>node</span> is for server-side applications."}
						/></label
					>
					<div class="custom-select-wrapper">
						<Select
							{isDisabled}
							containerClasses={isDisabled && containerClass()}
							id="deploymentTypes"
							showIndicator={!$status.application.isRunning}
							items={['static', 'node']}
							on:select={selectDeploymentType}
							value={application.deploymentType}
							isClearable={false}
						/>
					</div>
				</div>
			{/if}
			{#if $features.beta}
				{#if !application.settings.isBot && !application.settings.isPublicRepository}
					<div class="grid grid-cols-2 items-center">
						<Setting
							id="isDBBranching"
							isCenter={false}
							bind:setting={isDBBranching}
							on:click={() => changeSettings('isDBBranching')}
							title="Enable DB Branching"
							description="Enable DB Branching"
						/>
					</div>
					{#if isDBBranching}
						<button
							on:click|stopPropagation|preventDefault={() =>
								goto(`/applications/${id}/configuration/database`)}
							class="btn btn-sm">Configure Connected Database</button
						>
						{#if application.connectedDatabase}
							<div class="grid grid-cols-2 items-center">
								<label for="baseImage"
									>Base Database
									<Explainer
										explanation={'The name of the database that will be used as base when branching.'}
									/></label
								>
								<input
									name="baseDatabaseBranch"
									required
									id="baseDatabaseBranch"
									bind:value={baseDatabaseBranch}
								/>
							</div>
							<div class="text-center bg-green-600 rounded">
								Connected to {application.connectedDatabase.databaseId}
							</div>
						{/if}
					{/if}
				{/if}
			{/if}
		</div>
		<div class="flex space-x-1 py-5 font-bold">
			<h1 class="title">{$t('application.application')}</h1>
		</div>
		<div class="grid grid-flow-row gap-2 lg:px-10 px-2 pr-5">
			<div class="grid grid-cols-2 items-center">
				<Setting
					id="isBot"
					isCenter={false}
					bind:setting={isBot}
					on:click={() => changeSettings('isBot')}
					title="Is your application a bot?"
					description="You can deploy applications without domains or make them to listen on the <span class='text-settings font-bold'>Exposed Port</span>.<br></Setting><br>Useful to host <span class='text-settings font-bold'>Twitch bots, regular jobs, or anything that does not require an incoming HTTP connection.</span>"
					disabled={$status.application.isRunning}
				/>
			</div>
			<div class="grid grid-cols-2 items-center">
				<Setting
					id="dualCerts"
					dataTooltip={$t('forms.must_be_stopped_to_modify')}
					disabled={$status.application.isRunning}
					isCenter={false}
					bind:setting={dualCerts}
					title={$t('application.ssl_www_and_non_www')}
					description="Generate certificates for both www and non-www. <br>You need to have <span class='font-bold text-settings'>both DNS entries</span> set in advance.<br><br>Useful if you expect to have visitors on both."
					on:click={() => !$status.application.isRunning && changeSettings('dualCerts')}
				/>
			</div>
			{#if !isBot}
				<div class="grid grid-cols-2 items-center pb-8">
					<label for="fqdn"
						>{$t('application.url_fqdn')}
						<Explainer
							explanation={"If you specify <span class='text-settings font-bold'>https</span>, the application will be accessible only over https.<br>SSL certificate will be generated automatically.<br><br>If you specify <span class='text-settings font-bold'>www</span>, the application will be redirected (302) from non-www and vice versa.<br><br>To modify the domain, you must first stop the application.<br><br><span class='text-settings font-bold'>You must set your DNS to point to the server IP in advance.</span>"}
						/>
					</label>
					<div>
						<input
							class="w-full"
							required={!application.settings.isBot}
							readonly={isDisabled}
							disabled={isDisabled}
							name="fqdn"
							id="fqdn"
							bind:value={application.fqdn}
							pattern="^https?://([a-z0-9]+(-[a-z0-9]+)*\.)+[a-z]{'{'}2,{'}'}$"
							placeholder="eg: https://coollabs.io"
						/>
						{#if forceSave}
							<div class="flex-col space-y-2 pt-4 text-center">
								{#if isNonWWWDomainOK}
									<button
										class="btn btn-sm bg-green-600 hover:bg-green-500"
										on:click|preventDefault={() => isDNSValid(getDomain(nonWWWDomain), false)}
										>DNS settings for {nonWWWDomain} is OK, click to recheck.</button
									>
								{:else}
									<button
										class="btn btn-sm bg-red-600 hover:bg-red-500"
										on:click|preventDefault={() => isDNSValid(getDomain(nonWWWDomain), false)}
										>DNS settings for {nonWWWDomain} is invalid, click to recheck.</button
									>
								{/if}
								{#if dualCerts}
									{#if isWWWDomainOK}
										<button
											class="btn btn-sm bg-green-600 hover:bg-green-500"
											on:click|preventDefault={() =>
												isDNSValid(getDomain(`www.${nonWWWDomain}`), true)}
											>DNS settings for www.{nonWWWDomain} is OK, click to recheck.</button
										>
									{:else}
										<button
											class="btn btn-sm bg-red-600 hover:bg-red-500"
											on:click|preventDefault={() =>
												isDNSValid(getDomain(`www.${nonWWWDomain}`), true)}
											>DNS settings for www.{nonWWWDomain} is invalid, click to recheck.</button
										>
									{/if}
								{/if}
							</div>
						{/if}
					</div>
				</div>
			{/if}
			{#if application.buildPack === 'python'}
				<div class="grid grid-cols-2 items-center">
					<label for="pythonModule">WSGI / ASGI</label>
					<div class="custom-select-wrapper">
						<Select id="wsgi" items={wsgis} on:select={selectWSGI} value={application.pythonWSGI} />
					</div>
				</div>

				<div class="grid grid-cols-2 items-center">
					<label for="pythonModule">Module</label>
					<input
						disabled={isDisabled}
						readonly={!$appSession.isAdmin}
						name="pythonModule"
						id="pythonModule"
						required
						bind:value={application.pythonModule}
						placeholder={application.pythonWSGI?.toLowerCase() !== 'none' ? 'main' : 'main.py'}
					/>
				</div>
				{#if application.pythonWSGI?.toLowerCase() === 'gunicorn'}
					<div class="grid grid-cols-2 items-center">
						<label for="pythonVariable">Variable</label>
						<input
							disabled={isDisabled}
							readonly={!$appSession.isAdmin}
							name="pythonVariable"
							id="pythonVariable"
							required
							bind:value={application.pythonVariable}
							placeholder="default: app"
						/>
					</div>
				{/if}
				{#if application.pythonWSGI?.toLowerCase() === 'uvicorn'}
					<div class="grid grid-cols-2 items-center">
						<label for="pythonVariable">Variable</label>
						<input
							disabled={isDisabled}
							readonly={!$appSession.isAdmin}
							name="pythonVariable"
							id="pythonVariable"
							required
							bind:value={application.pythonVariable}
							placeholder="default: app"
						/>
					</div>
				{/if}
			{/if}
			{#if !staticDeployments.includes(application.buildPack)}
				<div class="grid grid-cols-2 items-center">
					<label for="port"
						>{$t('forms.port')}
						<Explainer explanation={'The port your application listens on.'} /></label
					>
					<input
						class="w-full"
						disabled={isDisabled}
						readonly={!$appSession.isAdmin}
						name="port"
						id="port"
						bind:value={application.port}
						placeholder="{$t('forms.default')}: 'python' ? '8000' : '3000'"
					/>
				</div>
			{/if}
			<div class="grid grid-cols-2 items-center">
				<label for="exposePort"
					>Exposed Port <Explainer
						explanation={'You can expose your application to a port on the host system.<br><br>Useful if you would like to use your own reverse proxy or tunnel and also in development mode. Otherwise leave empty.'}
					/></label
				>
				<input
					class="w-full"
					readonly={!$appSession.isAdmin && !$status.application.isRunning}
					disabled={isDisabled}
					name="exposePort"
					id="exposePort"
					bind:value={application.exposePort}
					placeholder="12345"
				/>
			</div>
			{#if !notNodeDeployments.includes(application.buildPack)}
				<div class="grid grid-cols-2 items-center">
					<label for="installCommand">{$t('application.install_command')}</label>
					<input
						class="w-full"
						disabled={isDisabled}
						readonly={!$appSession.isAdmin}
						name="installCommand"
						id="installCommand"
						bind:value={application.installCommand}
						placeholder="{$t('forms.default')}: yarn install"
					/>
				</div>
				<div class="grid grid-cols-2 items-center">
					<label for="buildCommand">{$t('application.build_command')}</label>
					<input
						class="w-full"
						disabled={isDisabled}
						readonly={!$appSession.isAdmin}
						name="buildCommand"
						id="buildCommand"
						bind:value={application.buildCommand}
						placeholder="{$t('forms.default')}: yarn build"
					/>
				</div>
				<div class="grid grid-cols-2 items-center pb-8">
					<label for="startCommand">{$t('application.start_command')}</label>
					<input
						class="w-full"
						disabled={isDisabled}
						readonly={!$appSession.isAdmin}
						name="startCommand"
						id="startCommand"
						bind:value={application.startCommand}
						placeholder="{$t('forms.default')}: yarn start"
					/>
				</div>
			{/if}
			{#if application.buildPack === 'docker'}
				<div class="grid grid-cols-2 items-center pt-4">
					<label for="dockerFileLocation"
						>Dockerfile Location <Explainer
							explanation={"Should be absolute path, like <span class='text-settings font-bold'>/data/Dockerfile</span> or <span class='text-settings font-bold'>/Dockerfile.</span>"}
						/></label
					>
					<input
						class="w-full"
						disabled={isDisabled}
						readonly={!$appSession.isAdmin}
						name="dockerFileLocation"
						id="dockerFileLocation"
						bind:value={application.dockerFileLocation}
						placeholder="default: /Dockerfile"
					/>
				</div>
			{/if}
			{#if application.buildPack === 'deno'}
				<div class="grid grid-cols-2 items-center">
					<label for="denoMainFile">Main File</label>
					<input
						class="w-full"
						disabled={isDisabled}
						readonly={!$appSession.isAdmin}
						name="denoMainFile"
						id="denoMainFile"
						bind:value={application.denoMainFile}
						placeholder="default: main.ts"
					/>
				</div>
				<div class="grid grid-cols-2 items-center">
					<label for="denoOptions"
						>Arguments <Explainer
							explanation={"List of arguments to pass to <span class='text-settings font-bold'>deno run</span> command. Could include permissions, configurations files, etc."}
						/></label
					>
					<input
						class="w-full"
						disabled={isDisabled}
						readonly={!$appSession.isAdmin}
						name="denoOptions"
						id="denoOptions"
						bind:value={application.denoOptions}
						placeholder="eg: --allow-net --allow-hrtime --config path/to/file.json"
					/>
				</div>
			{/if}
			{#if application.buildPack !== 'laravel' && application.buildPack !== 'heroku'}
				<div class="grid grid-cols-2 items-center">
					<div class="flex-col">
						<label for="baseDirectory"
							>{$t('forms.base_directory')}
							<Explainer
								explanation={"Directory to use as the base for all commands.<br>Could be useful with <span class='text-settings font-bold'>monorepos</span>."}
							/></label
						>
					</div>
					<input
						class="w-full"
						disabled={isDisabled}
						readonly={!$appSession.isAdmin}
						name="baseDirectory"
						id="baseDirectory"
						bind:value={application.baseDirectory}
						placeholder="{$t('forms.default')}: /"
					/>
				</div>
			{/if}
			{#if !notNodeDeployments.includes(application.buildPack)}
				<div class="grid grid-cols-2 items-center">
					<div class="flex-col">
						<label for="publishDirectory"
							>{$t('forms.publish_directory')}
							<Explainer
								explanation={"Directory containing all the assets for deployment. <br> For example: <span class='text-settings font-bold'>dist</span>,<span class='text-settings font-bold'>_site</span> or <span class='text-settings font-bold'>public</span>."}
							/></label
						>
					</div>

					<input
						class="w-full"
						disabled={isDisabled}
						readonly={!$appSession.isAdmin}
						name="publishDirectory"
						id="publishDirectory"
						required={application.deploymentType === 'static'}
						bind:value={application.publishDirectory}
						placeholder=" {$t('forms.default')}: /"
					/>
				</div>
			{/if}
		</div>
	</form>
	<div class="flex space-x-1 pb-5 font-bold">
		<div class="title">{$t('application.features')}</div>
	</div>
	<div class="lg:px-10 px-2 lg:pb-10 pb-6">
		{#if !application.settings.isPublicRepository}
			<div class="grid grid-cols-2 items-center">
				<Setting
					id="autodeploy"
					isCenter={false}
					bind:setting={autodeploy}
					on:click={() => changeSettings('autodeploy')}
					title={$t('application.enable_automatic_deployment')}
					description={$t('application.enable_auto_deploy_webhooks')}
				/>
			</div>
		{/if}
		{#if !application.settings.isBot && !application.settings.isPublicRepository}
			<div class="grid grid-cols-2 items-center">
				<Setting
					id="previews"
					isCenter={false}
					bind:setting={previews}
					on:click={() => changeSettings('previews')}
					title={$t('application.enable_mr_pr_previews')}
					description={$t('application.enable_preview_deploy_mr_pr_requests')}
				/>
			</div>
		{/if}
		<div class="grid grid-cols-2 items-center w-full">
			<Setting
				id="debug"
				isCenter={false}
				bind:setting={debug}
				on:click={() => changeSettings('debug')}
				title={$t('application.debug_logs')}
				description={$t('application.enable_debug_log_during_build')}
			/>
		</div>
	</div>
</div>
