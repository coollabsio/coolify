<script lang="ts">
	import type { PageData } from './$types';

	export let data: PageData;
	const application = data.application.data;
	const settings = data.settings.data;

	import yaml from 'js-yaml';
	import { page } from '$app/stores';
	import { onMount } from 'svelte';
	import Select from 'svelte-select';
	import cuid from 'cuid';
	import {
		addToast,
		appSession,
		checkIfDeploymentEnabledApplications,
		setLocation,
		status,
		isDeploymentEnabled,
		features,
		trpc
	} from '$lib/store';
	import {
		errorNotification,
		get,
		getAPIUrl,
		getDomain,
		notNodeDeployments,
		staticDeployments
	} from '$lib/common';
	import Setting from '$lib/components/Setting.svelte';
	import Explainer from '$lib/components/Explainer.svelte';
	import { goto } from '$app/navigation';
	import Beta from '$lib/components/Beta.svelte';
	import { saveForm } from './utils';

	const { id } = $page.params;
	$: isDisabled =
		!$appSession.isAdmin ||
		$status.application.overallStatus === 'degraded' ||
		$status.application.overallStatus === 'healthy' ||
		$status.application.initialLoading;
	$isDeploymentEnabled = checkIfDeploymentEnabledApplications($appSession.isAdmin, application);
	let statues: any = {};
	let loading = {
		save: false,
		reloadCompose: false
	};
	let isSimpleDockerfile = !!application.simpleDockerfile;
	let fqdnEl: any = null;
	let forceSave = false;
	let isPublicRepository = application.settings?.isPublicRepository;
	let apiUrl = application.gitSource?.apiUrl;
	let branch = application.branch;
	let repository = application.repository;
	let debug = application.settings?.debug;
	let previews = application.settings?.previews;
	let dualCerts = application.settings?.dualCerts;
	let isCustomSSL = application.settings?.isCustomSSL;
	let autodeploy = application.settings?.autodeploy;
	let isBot = application.settings?.isBot;
	let isDBBranching = application.settings?.isDBBranching;
	let htmlUrl = application.gitSource?.htmlUrl;

	let dockerComposeFile = JSON.parse(application.dockerComposeFile) || null;
	let dockerComposeServices: any[] = [];
	let dockerComposeConfiguration = JSON.parse(application.dockerComposeConfiguration) || {};
	let originalDockerComposeFileLocation = application.dockerComposeFileLocation;

	let baseDatabaseBranch: any = application?.connectedDatabase?.hostedDatabaseDBName || null;
	let nonWWWDomain = application.fqdn && getDomain(application.fqdn).replace(/^www\./, '');
	let isHttps = application.fqdn && application.fqdn.startsWith('https://');
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
	function normalizeDockerServices(services: any[]) {
		const tempdockerComposeServices = [];
		for (const [name, data] of Object.entries(services)) {
			tempdockerComposeServices.push({
				name,
				data
			});
		}
		for (const service of tempdockerComposeServices) {
			if (!dockerComposeConfiguration[service.name]) {
				dockerComposeConfiguration[service.name] = {};
			}
		}
		return tempdockerComposeServices;
	}
	if (dockerComposeFile?.services) {
		dockerComposeServices = normalizeDockerServices(dockerComposeFile.services);
	}

	function containerClass() {
		return 'text-white bg-transparent font-thin px-0 w-full border border-dashed border-coolgray-200';
	}

	onMount(async () => {
		if (window.location.hostname === 'demo.coolify.io' && !application.fqdn) {
			application.fqdn = `http://${cuid()}.demo.coolify.io`;
			await handleSubmit();
		}
		await getBaseBuildImages();
		if (!application.fqdn && fqdnEl) fqdnEl.focus();
	});
	async function getBaseBuildImages() {
		const { data } = await trpc.applications.getImages.query({
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
		if (application.deploymentType === 'static' && application.port !== 80) {
			application.port = data.port;
		}
		if (application.deploymentType === 'node' && application.port === 80) {
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
		if (name === 'isCustomSSL') {
			isCustomSSL = !isCustomSSL;
		}
		if (name === 'isBot') {
			if ($status.application.overallStatus !== 'stopped') return;
			isBot = !isBot;
			application.settings.isBot = isBot;
			application.fqdn = null;
			setLocation(application, settings);
		}
		if (name === 'isDBBranching') {
			isDBBranching = !isDBBranching;
		}
		try {
			await trpc.applications.saveSettings.mutate({
				id: application.id,
				previews,
				debug,
				dualCerts,
				isBot,
				autodeploy,
				isDBBranching,
				isCustomSSL
			});
			return addToast({
				message: 'Settings updated',
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
			if (name === 'isCustomSSL') {
				isCustomSSL = !isCustomSSL;
			}
			return errorNotification(error);
		} finally {
			$isDeploymentEnabled = checkIfDeploymentEnabledApplications($appSession.isAdmin, application);
		}
	}
	async function handleSubmit(toast: boolean = true) {
		if (loading.save) return;
		if (toast) loading.save = true;
		try {
			nonWWWDomain = application.fqdn && getDomain(application.fqdn).replace(/^www\./, '');
			if (application.deploymentType) {
				application.deploymentType = application.deploymentType.toLowerCase();
			}
			if (originalDockerComposeFileLocation !== application.dockerComposeFileLocation) {
				await reloadCompose();
			}
			if (!isBot) {
				await trpc.applications.checkDNS.mutate({
					id,
					fqdn: application.fqdn,
					forceSave,
					dualCerts,
					exposePort: application.exposePort
				});
				for (const service of dockerComposeServices) {
					if (dockerComposeConfiguration[service.name].fqdn) {
						await trpc.applications.checkDNS.mutate({
							id,
							fqdn: dockerComposeConfiguration[service.name].fqdn,
							forceSave,
							dualCerts,
							exposePort: application.exposePort
						});
					}
				}
			}
			await saveForm(id, application, baseDatabaseBranch, dockerComposeConfiguration);
			setLocation(application, settings);
			$isDeploymentEnabled = checkIfDeploymentEnabledApplications($appSession.isAdmin, application);

			forceSave = false;
			if (toast) {
				addToast({
					message: 'Configuration saved.',
					type: 'success'
				});
			}

			if (application.fqdn && application.fqdn.startsWith('https')) {
				isHttps = true;
			} else {
				isHttps = false;
			}
		} catch (error) {
			//@ts-ignore
			if (error?.message.startsWith('DNS not set')) {
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
			loading.save = false;
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
			await trpc.applications.checkDomain.query({
				id,
				domain
			});
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
	async function getGitlabToken() {
		return await new Promise<void>((resolve, reject) => {
			const left = screen.width / 2 - 1020 / 2;
			const top = screen.height / 2 - 618 / 2;
			const newWindow = open(
				`${htmlUrl}/oauth/authorize?client_id=${
					application.gitSource.gitlabApp.appId
				}&redirect_uri=${getAPIUrl()}/webhooks/gitlab&response_type=code&scope=api+email+read_repository&state=${
					$page.params.id
				}`,
				'GitLab',
				'resizable=1, scrollbars=1, fullscreen=0, height=618, width=1020,top=' +
					top +
					', left=' +
					left +
					', toolbar=0, menubar=0, status=0'
			);
			const timer = setInterval(() => {
				if (newWindow?.closed) {
					clearInterval(timer);
					$appSession.tokens.gitlab = localStorage.getItem('gitLabToken');
					localStorage.removeItem('gitLabToken');
					resolve();
				}
			}, 100);
		});
	}
	async function reloadCompose() {
		if (loading.reloadCompose) return;
		loading.reloadCompose = true;
		try {
			if (application.gitSource.type === 'github') {
				const composeLocation = application.dockerComposeFileLocation.startsWith('/')
				? application.dockerComposeFileLocation
				: `/${application.dockerComposeFileLocation}`;
				
				const headers = isPublicRepository
					? {}
					: {
							Authorization: `token ${$appSession.tokens.github}`
					  };
				const data = await get(
					`${apiUrl}/repos/${repository}/contents/${composeLocation}?ref=${branch}`,
					{
						...headers,
						'If-None-Match': '',
						Accept: 'application/vnd.github.v2.json'
					}
				);
				if (data?.content) {
					const content = atob(data.content);
					let dockerComposeFileContent = JSON.stringify(yaml.load(content) || null);
					let dockerComposeFileContentJSON = JSON.parse(dockerComposeFileContent);
					dockerComposeServices = normalizeDockerServices(dockerComposeFileContentJSON?.services);
					application.dockerComposeFile = dockerComposeFileContent;
					await handleSubmit(false);
				}
			}
			if (application.gitSource.type === 'gitlab') {
				if (!$appSession.tokens.gitlab) {
					await getGitlabToken();
				}
				
				const composeLocation = application.dockerComposeFileLocation.startsWith('/')
				? application.dockerComposeFileLocation.substring(1) // Remove the '/' from the start
				: application.dockerComposeFileLocation;
				
				// If the file is in a subdirectory, lastIndex will be > 0
				// Otherwise it will be -1 and path will be an empty string
				const lastIndex = composeLocation.lastIndexOf('/') + 1
				const path = composeLocation.substring(0, lastIndex)
				const fileName = composeLocation.substring(lastIndex)
				
				const headers = isPublicRepository
					? {}
					: {
							Authorization: `Bearer ${$appSession.tokens.gitlab}`
					  };
				const url = isPublicRepository
					? ``
					: `/v4/projects/${application.projectId}/repository/tree?path=${path}`; // Use path param to find file in a subdirectory
				const files = await get(`${apiUrl}${url}`, {
					...headers
				});
				const dockerComposeFileYml = files.find(
					(file: { name: string; type: string }) =>
						file.name === fileName && file.type === 'blob'
				);
				const id = dockerComposeFileYml.id;

				const data = await get(
					`${apiUrl}/v4/projects/${application.projectId}/repository/blobs/${id}`,
					{
						...headers
					}
				);
				if (data?.content) {
					const content = atob(data.content);
					let dockerComposeFileContent = JSON.stringify(yaml.load(content) || null);
					let dockerComposeFileContentJSON = JSON.parse(dockerComposeFileContent);
					dockerComposeServices = normalizeDockerServices(dockerComposeFileContentJSON?.services);
					application.dockerComposeFile = dockerComposeFileContent;
					await handleSubmit(false);
				}
			}
			originalDockerComposeFileLocation = application.dockerComposeFileLocation;
			addToast({
				message: 'Compose file reloaded.',
				type: 'success'
			});
		} catch (error: any) {
			if (error.message === 'Not Found') {
				error.message = `Can't find ${application.dockerComposeFileLocation} file.`;
				errorNotification(error);
				throw error;
			}
			errorNotification(error);
		} finally {
			loading.reloadCompose = false;
		}
	}
	$: if ($status.application.statuses) {
		for (const service of dockerComposeServices) {
			getStatus(service);
		}
	}
	function getStatus(service: any) {
		let foundStatus = null;
		const foundService = $status.application.statuses.find(
			(s: any) => s.name === `${application.id}-${service.name}`
		);
		if (foundService) {
			const statusText = foundService?.status;
			if (statusText?.isRunning) {
				foundStatus = 'Running';
			}
			if (statusText?.isExited) {
				foundStatus = 'Exited';
			}
			if (statusText?.isRestarting) {
				foundStatus = 'Restarting';
			}
		}
		statues[service.name] = foundStatus || 'Stopped';
	}
</script>

<div class="w-full">
	<form id="saveForm" on:submit|preventDefault={() => handleSubmit()}>
		<div class="mx-auto w-full">
			<div class="flex flex-row border-b border-coolgray-500 mb-6 space-x-2">
				<div class="title font-bold pb-3">General</div>
				{#if $appSession.isAdmin}
					<button
						class="btn btn-sm  btn-primary"
						type="submit"
						class:loading={loading.save}
						class:bg-orange-600={forceSave}
						class:hover:bg-orange-400={forceSave}
						disabled={loading.save}>Save</button
					>
				{/if}
			</div>
			<div class="grid grid-flow-row gap-2 px-4">
				<div class="mt-2 grid grid-cols-2 items-center">
					<label for="name">Name</label>
					<input name="name" id="name" class="w-full" bind:value={application.name} required />
				</div>
				{#if !isSimpleDockerfile}
					<div class="grid grid-cols-2 items-center">
						<label for="gitSource">Git Source</label>
						{#if isDisabled || application.settings?.isPublicRepository}
							<input
								disabled={isDisabled || application.settings?.isPublicRepository}
								class="w-full"
								value={application.gitSource?.name}
							/>
						{:else}
							<a
								href={`/applications/${id}/configuration/source?from=/applications/${id}`}
								class="no-underline"
								><input
									value={application.gitSource?.name}
									id="gitSource"
									class="cursor-pointer hover:bg-coolgray-500 w-full"
								/></a
							>
						{/if}
					</div>
					<div class="grid grid-cols-2 items-center">
						<label for="repository">Git commit</label>
						<div class="flex gap-2">
							<input
								id="commit"
								name="commit"
								class="w-full"
								disabled={isDisabled}
								placeholder="default: latest commit"
								bind:value={application.gitCommitHash}
							/>
							<a
								href="{application.gitSource
									?.htmlUrl}/{application.repository}/commits/{application.branch}"
								target="_blank noreferrer"
								class="btn btn-primary text-xs"
								>Commits<svg
									xmlns="http://www.w3.org/2000/svg"
									fill="currentColor"
									viewBox="0 0 24 24"
									stroke-width="3"
									stroke="currentColor"
									class="w-3 h-3 text-white ml-2"
								>
									<path
										stroke-linecap="round"
										stroke-linejoin="round"
										d="M4.5 19.5l15-15m0 0H8.25m11.25 0v11.25"
									/>
								</svg></a
							>
						</div>
					</div>
					<div class="grid grid-cols-2 items-center">
						<label for="repository">Repository</label>
						{#if isDisabled || application.settings?.isPublicRepository}
							<input
								class="w-full"
								disabled={isDisabled || application.settings?.isPublicRepository}
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
				{/if}
				<div class="grid grid-cols-2 items-center">
					<label for="registry">Docker Registry</label>
					{#if isDisabled}
						<input
							class="capitalize w-full"
							disabled={isDisabled}
							value={application.dockerRegistry?.name || 'DockerHub (unauthenticated)'}
						/>
					{:else}
						<a
							href={`/applications/${id}/configuration/registry?from=/applications/${id}`}
							class="no-underline"
						>
							<input
								value={application.dockerRegistry?.name || 'DockerHub (unauthenticated)'}
								id="registry"
								class="cursor-pointer hover:bg-coolgray-500 capitalize w-full"
							/></a
						>
					{/if}
				</div>
				{#if application.dockerRegistry?.id && application.gitSourceId}
					<div class="grid grid-cols-2 items-center">
						<label for="registry"
							>Push Image to Registry <Explainer
								explanation="Push the build image to the specific Docker Registry.<br><br>This is useful if you want to use the image in other places. If you don't fill this the image will be only available on the server.<br><br>Tag is optional. If you don't fill it, the tag will be the same as the git commit hash."
							/></label
						>
						<input
							name="dockerRegistryImageName"
							id="dockerRegistryImageName"
							readonly={isDisabled}
							disabled={isDisabled}
							class="w-full"
							placeholder="e.g. coollabsio/myimage (tag will be commit sha) or coollabsio/myimage:tag"
							bind:value={application.dockerRegistryImageName}
						/>
					</div>
				{/if}
				{#if !isSimpleDockerfile}
					<div class="grid grid-cols-2 items-center">
						<label for="buildPack">BuildPack</label>
						{#if isDisabled}
							<input
								class="capitalize w-full"
								disabled={isDisabled}
								value={application.buildPack}
							/>
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
				{/if}
				<div class="grid grid-cols-2 items-center">
					<label for="destination">Destination</label>
					<div class="no-underline">
						<input
							value={application.destinationDocker?.name}
							id="destination"
							disabled
							class="bg-transparent w-full"
						/>
					</div>
				</div>
				{#if application.buildPack !== 'compose'}
					<div class="grid grid-cols-2 items-center">
						<Setting
							id="isBot"
							isCenter={false}
							bind:setting={isBot}
							on:click={() => changeSettings('isBot')}
							title="Is your application a bot?"
							description="You can deploy applications without domains or make them to listen on the <span class='text-settings font-bold'>Exposed Port</span>.<br></Setting><br>Useful to host <span class='text-settings font-bold'>Twitch bots, regular jobs, or anything that does not require an incoming HTTP connection.</span>"
							disabled={isDisabled}
						/>
					</div>
				{/if}
				{#if !isBot && application.buildPack !== 'compose'}
					<div class="grid grid-cols-2 items-center">
						<label for="fqdn"
							>URL (fqdn)
							<Explainer
								explanation={"If you specify <span class='text-settings font-bold'>https</span>, the application will be accessible only over https.<br>SSL certificate will be generated automatically.<br><br>If you specify <span class='text-settings font-bold'>www</span>, the application will be redirected (302) from non-www and vice versa.<br><br>To modify the domain, you must first stop the application.<br><br><span class='text-settings font-bold'>You must set your DNS to point to the server IP in advance.</span>"}
							/>
						</label>
						<div>
							<input
								bind:this={fqdnEl}
								class="w-full"
								required={!application.settings?.isBot}
								readonly={isDisabled}
								disabled={isDisabled}
								name="fqdn"
								id="fqdn"
								class:border={!application.settings?.isBot && !application.fqdn}
								class:border-red-500={!application.settings?.isBot && !application.fqdn}
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
					<div class="grid grid-cols-2 items-center pb-4">
						<Setting
							id="dualCerts"
							dataTooltip="Must be stopped to modify."
							disabled={isDisabled}
							isCenter={false}
							bind:setting={dualCerts}
							title="Generate Dual Certificates"
							description="Generate certificates for both www and non-www. <br>You need to have <span class='font-bold text-settings'>both DNS entries</span> set in advance.<br><br>Useful if you expect to have visitors on both."
							on:click={() => !isDisabled && changeSettings('dualCerts')}
						/>
					</div>
					{#if isHttps && application.buildPack !== 'compose'}
						<div class="grid grid-cols-2 items-center pb-4">
							<Setting
								id="isCustomSSL"
								isCenter={false}
								bind:setting={isCustomSSL}
								title="Use Custom SSL Certificate"
								description="Use Custom SSL Certificated added in the Settings/SSL Certificates section. <br><br>By default, the SSL certificate is generated automatically through Let's Encrypt"
								on:click={() => changeSettings('isCustomSSL')}
							/>
						</div>
					{/if}
				{/if}
			</div>
			{#if isSimpleDockerfile}
				<div class="title font-bold pb-3 pt-10 border-b border-coolgray-500 mb-6">
					Configuration
				</div>

				<div class="grid grid-flow-row gap-2 px-4 pr-5">
					<div class="grid grid-cols-2 items-center  pt-4">
						<label for="simpleDockerfile">Dockerfile</label>
						<div class="flex gap-2">
							<textarea
								rows="10"
								id="simpleDockerfile"
								name="simpleDockerfile"
								class="w-full"
								disabled={isDisabled}
								bind:value={application.simpleDockerfile}
							/>
						</div>
					</div>
					<div class="grid grid-cols-2 items-center">
						<label for="port"
							>Port
							<Explainer
								explanation={'The port your application listens inside the docker container.'}
							/></label
						>
						<input
							class="w-full"
							disabled={isDisabled}
							readonly={!$appSession.isAdmin}
							name="port"
							id="port"
							bind:value={application.port}
							placeholder="Default: 3000"
						/>
					</div>
				</div>
			{:else if application.buildPack !== 'compose'}
				<div class="title font-bold pb-3 pt-10 border-b border-coolgray-500 mb-6">
					Configuration
				</div>
				<div class="grid grid-flow-row gap-2 px-4 pr-5">
					{#if application.buildCommand || application.buildPack === 'rust' || application.buildPack === 'laravel'}
						<div class="grid grid-cols-2 items-center">
							<label for="baseBuildImage">
								Base Build Image
								<Explainer
									explanation={application.buildPack === 'laravel'
										? 'For building frontend assets with webpack.'
										: 'Image that will be used during the build process.'}
								/>
							</label>
							<div class="custom-select-wrapper">
								<Select
									{isDisabled}
									containerClasses={isDisabled && containerClass()}
									id="baseBuildImages"
									showIndicator={!isDisabled}
									items={application.baseBuildImages}
									on:select={selectBaseBuildImage}
									value={application.baseBuildImage}
									isClearable={false}
								/>
							</div>
						</div>
					{/if}
					{#if application.buildPack !== 'docker'}
						<div class="grid grid-cols-2 items-center">
							<label for="baseImage">
								Base Image
								<Explainer explanation={'Image that will be used for the deployment.'} /></label
							>
							<div class="custom-select-wrapper">
								<Select
									{isDisabled}
									containerClasses={isDisabled && containerClass()}
									id="baseImages"
									showIndicator={!isDisabled}
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
									showIndicator={!isDisabled}
									items={['static', 'node']}
									on:select={selectDeploymentType}
									value={application.deploymentType}
									isClearable={false}
								/>
							</div>
						</div>
					{/if}
					{#if $features.beta}
						{#if !application.settings?.isBot && !application.settings?.isPublicRepository}
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
										Connected to {application.connectedDatabase?.databaseId}
									</div>
								{/if}
							{/if}
						{/if}
					{/if}

					{#if application.buildPack === 'python'}
						<div class="grid grid-cols-2 items-center">
							<label for="pythonModule">WSGI / ASGI</label>
							<div class="custom-select-wrapper">
								<Select
									id="wsgi"
									items={wsgis}
									on:select={selectWSGI}
									value={application.pythonWSGI}
								/>
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
								class="w-full"
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
									class="w-full"
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
									class="w-full"
									bind:value={application.pythonVariable}
									placeholder="default: app"
								/>
							</div>
						{/if}
					{/if}
					{#if !staticDeployments.includes(application.buildPack)}
						<div class="grid grid-cols-2 items-center pt-4">
							<label for="port">
								Port
								<Explainer
									explanation={'The port your application listens inside the docker container.'}
								/></label
							>
							<input
								class="w-full"
								disabled={isDisabled}
								readonly={!$appSession.isAdmin}
								name="port"
								id="port"
								bind:value={application.port}
								placeholder="Default: 'python' ? '8000' : '3000'"
							/>
						</div>
					{/if}
					<div class="grid grid-cols-2 items-center pb-4">
						<label for="exposePort"
							>Exposed Port <Explainer
								explanation={'You can expose your application to a port on the host system.<br><br>Useful if you would like to use your own reverse proxy or tunnel and also in development mode. Otherwise leave empty.'}
							/></label
						>
						<input
							class="w-full"
							disabled={isDisabled}
							readonly={!$appSession.isAdmin}
							name="exposePort"
							id="exposePort"
							bind:value={application.exposePort}
							placeholder="12345"
						/>
					</div>
					{#if !notNodeDeployments.includes(application.buildPack)}
						<div class="grid grid-cols-2 items-center">
							<label for="installCommand">Install Command</label>
							<input
								class="w-full"
								disabled={isDisabled}
								readonly={!$appSession.isAdmin}
								name="installCommand"
								id="installCommand"
								bind:value={application.installCommand}
								placeholder="Default yarn install"
							/>
						</div>
						<div class="grid grid-cols-2 items-center">
							<label for="buildCommand">Build Command</label>
							<input
								class="w-full"
								disabled={isDisabled}
								readonly={!$appSession.isAdmin}
								name="buildCommand"
								id="buildCommand"
								bind:value={application.buildCommand}
								placeholder="Default yarn build"
							/>
						</div>
						<div class="grid grid-cols-2 items-center pb-8">
							<label for="startCommand">Start Command</label>
							<input
								class="w-full"
								disabled={isDisabled}
								readonly={!$appSession.isAdmin}
								name="startCommand"
								id="startCommand"
								bind:value={application.startCommand}
								placeholder="Default yarn start"
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
					{#if application.buildPack !== 'laravel'}
						<div class="grid grid-cols-2 items-center">
							<div class="flex-col">
								<label for="baseDirectory"
									>Base Directory
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
								placeholder="Default /"
							/>
						</div>
					{/if}
					{#if application.buildPack === 'docker'}
						<div class="grid grid-cols-2 items-center pb-4">
							<label for="dockerFileLocation" class=""
								>Dockerfile Location <Explainer
									explanation={"Should be absolute path, like <span class='text-settings font-bold'>/data/Dockerfile</span> or <span class='text-settings font-bold'>/Dockerfile.</span>"}
								/></label
							>
							<div class="form-control w-full">
								<input
									class="w-full input"
									disabled={isDisabled}
									readonly={!$appSession.isAdmin}
									name="dockerFileLocation"
									id="dockerFileLocation"
									bind:value={application.dockerFileLocation}
									placeholder="default: /Dockerfile"
								/>
								{#if application.baseDirectory}
									<!-- svelte-ignore a11y-label-has-associated-control -->
									<label class="label">
										<span class="label-text-alt text-xs"
											>Path: {application.baseDirectory.replace(
												/^\/$/,
												''
											)}{application.dockerFileLocation}</span
										>
									</label>
								{/if}
							</div>
						</div>
					{/if}
					{#if !notNodeDeployments.includes(application.buildPack)}
						<div class="grid grid-cols-2 items-center">
							<div class="flex-col">
								<label for="publishDirectory"
									>Publish Directory
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
								placeholder=" Default /"
							/>
						</div>
					{/if}
				</div>
			{:else}
				<div class="title font-bold pb-3 pt-10 border-b border-coolgray-500 mb-6">
					Stack <Beta />
					{#if $appSession.isAdmin}
						<button
							class="btn btn-sm btn-primary"
							class:loading={loading.reloadCompose}
							disabled={loading.reloadCompose}
							on:click|preventDefault={reloadCompose}>Reload Docker Compose File</button
						>
					{/if}
				</div>
				<div class="grid grid-flow-row gap-2">
					<div class="grid grid-cols-2 items-center px-8 pb-4">
						<label for="dockerComposeFileLocation"
							>Docker Compose File Location
							<Explainer
								explanation="You can specify a custom docker compose file location. <br> Should be absolute path, like <span class='text-settings font-bold'>/data/docker-compose.yml</span> or <span class='text-settings font-bold'>/docker-compose.yml.</span>"
							/>
						</label>
						<div>
							<input
								class="w-full"
								disabled={isDisabled}
								readonly={!$appSession.isAdmin}
								name="dockerComposeFileLocation"
								id="dockerComposeFileLocation"
								bind:value={application.dockerComposeFileLocation}
								placeholder="eg: /docker-compose.yml"
							/>
						</div>
					</div>
					{#each dockerComposeServices as service}
						<div
							class="grid items-center bg-coolgray-100 rounded border border-coolgray-300 p-2 px-4"
						>
							<div class="text-xl font-bold uppercase">
								{service.name}
								<span
									class="badge rounded text-white"
									class:text-red-500={statues[service.name] === 'Exited' ||
										statues[service.name] === 'Stopped'}
									class:text-yellow-400={statues[service.name] === 'Restarting'}
									class:text-green-500={statues[service.name] === 'Running'}
									>{statues[service.name] || 'Loading...'}</span
								>
							</div>
							<div class="text-xs">{application.id}-{service.name}</div>
						</div>

						<div class="grid grid-cols-2 items-center px-8">
							<label for="fqdn"
								>URL(fqdn)
								<Explainer
									explanation={"If you specify <span class='text-settings font-bold'>https</span>, the application will be accessible only over https.<br>SSL certificate will be generated automatically.<br><br>If you specify <span class='text-settings font-bold'>www</span>, the application will be redirected (302) from non-www and vice versa.<br><br>To modify the domain, you must first stop the application.<br><br><span class='text-settings font-bold'>You must set your DNS to point to the server IP in advance.</span>"}
								/>
							</label>
							<div>
								<input
									class="w-full"
									disabled={isDisabled}
									readonly={!$appSession.isAdmin}
									name="fqdn"
									id="fqdn"
									bind:value={dockerComposeConfiguration[service.name].fqdn}
									pattern="^https?://([a-z0-9]+(-[a-z0-9]+)*\.)+[a-z]{'{'}2,{'}'}$"
									placeholder="eg: https://coollabs.io"
								/>
							</div>
						</div>
						<div class="grid grid-cols-2 items-center px-8">
							<label for="destinationdns"
								>Internal DNS on the deployed Destination
								<Explainer
									explanation={'You can use these DNS names to access the application from other resources in your Destination.'}
								/>
							</label>
							<input
								for="destinationdns"
								class="w-full"
								disabled
								readonly
								value={`${application.id}-${service.name}`}
							/>
						</div>
						<div class="grid grid-cols-2 items-center px-8">
							<label for="stackdns"
								>Internal DNS in the current stack
								<Explainer
									explanation={'You can use these DNS names to access the application from this stack.'}
								/>
							</label>
							<input for="stackdns" class="w-full" disabled readonly value={service.name} />
						</div>
						<div class="grid grid-cols-2 items-center px-8 pb-4">
							<label for="port"
								>Port
								<Explainer
									explanation={'The port your application listens inside the docker container.'}
								/></label
							>
							<input
								class="w-full"
								disabled={isDisabled}
								readonly={!$appSession.isAdmin}
								name="port"
								id="port"
								required={!!dockerComposeConfiguration[service.name]?.fqdn}
								bind:value={dockerComposeConfiguration[service.name].port}
							/>
						</div>
					{/each}
				</div>
			{/if}
		</div>
	</form>
</div>
