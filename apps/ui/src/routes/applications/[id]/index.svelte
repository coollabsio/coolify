<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, params, stuff, url }) => {
		try {
			if (stuff?.application?.id) {
				return {
					props: {
						application: stuff.application
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
	import { page } from '$app/stores';
	import { onDestroy, onMount } from 'svelte';
	import Select from 'svelte-select';

	import Explainer from '$lib/components/Explainer.svelte';
	import { toast } from '@zerodevx/svelte-toast';
	import { get, post } from '$lib/api';
	import cuid from 'cuid';
	import { browser } from '$app/env';
	import { appSession, disabledButton, status } from '$lib/store';
	import { t } from '$lib/translations';
	import { errorNotification, getDomain, notNodeDeployments, staticDeployments } from '$lib/common';
	import Setting from './_Setting.svelte';
	const { id } = $page.params;

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

	let nonWWWDomain = application.fqdn && getDomain(application.fqdn).replace(/^www\./, '');
	let isNonWWWDomainOK = false;
	let isWWWDomainOK = false;

	$: isDisabled = !$appSession.isAdmin || $status.application.isRunning;
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
		return 'text-white border border-dashed border-coolgray-300 bg-transparent font-thin px-0';
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
		domainEl.focus();
		await getUsage();
		usageInterval = setInterval(async () => {
			await getUsage();
		}, 1000);
	});

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
		try {
			await post(`/applications/${id}/settings`, {
				previews,
				debug,
				dualCerts,
				autodeploy,
				branch: application.branch,
				projectId: application.projectId
			});
			return toast.push($t('application.settings_saved'));
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
			return errorNotification(error);
		}
	}
	async function handleSubmit() {
		if (loading) return;
		loading = true;
		try {
			nonWWWDomain = application.fqdn && getDomain(application.fqdn).replace(/^www\./, '');
			await post(`/applications/${id}/check`, {
				fqdn: application.fqdn,
				forceSave,
				dualCerts,
				exposePort: application.exposePort
			});
			await post(`/applications/${id}`, { ...application });
			$disabledButton = false;
			forceSave = false;
			return toast.push('Configurations saved.');
		} catch (error) {
			console.log(error);
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

	async function isDNSValid(domain: any, isWWW: any) {
		try {
			await get(`/applications/${id}/check?domain=${domain}`);
			toast.push('DNS configuration is valid.');
			isWWW ? (isWWWDomainOK = true) : (isNonWWWDomainOK = true);
			return true;
		} catch ({ error }) {
			errorNotification(error);
			isWWW ? (isWWWDomainOK = false) : (isNonWWWDomainOK = false);
			return false;
		}
	}
</script>

<div class="flex items-center space-x-2 p-5 px-6 font-bold">
	<div class="-mb-5 flex-col">
		<div class="md:max-w-64 truncate text-base tracking-tight md:text-2xl lg:block">
			Configuration
		</div>
		<span class="text-xs">{application.name} </span>
	</div>

	{#if application.fqdn}
		<a
			href={application.fqdn}
			target="_blank"
			class="icons tooltip-bottom flex items-center bg-transparent text-sm"
			><svg
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
			</svg></a
		>
	{/if}
	<a
		href="{application.gitSource.htmlUrl}/{application.repository}/tree/{application.branch}"
		target="_blank"
		class="w-10"
	>
		{#if application.gitSource?.type === 'gitlab'}
			<svg viewBox="0 0 128 128" class="icons">
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
		{:else if application.gitSource?.type === 'github'}
			<svg viewBox="0 0 128 128" class="icons">
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
	</a>
</div>

<div class="mx-auto max-w-4xl px-6 py-4">
	<div class="text-2xl font-bold">Application Usage</div>
	<div class="mx-auto">
		<dl class="relative mt-5 grid grid-cols-1 gap-5 sm:grid-cols-3">
			<div class="overflow-hidden rounded px-4 py-5 text-center sm:p-6 sm:text-left">
				<dt class=" text-sm font-medium text-white">Used Memory / Memory Limit</dt>
				<dd class="mt-1 text-xl font-semibold text-white">
					{usage?.MemUsage}
				</dd>
			</div>

			<div class="overflow-hidden rounded px-4 py-5 text-center sm:p-6 sm:text-left">
				<dt class="truncate text-sm font-medium text-white">Used CPU</dt>
				<dd class="mt-1 text-xl font-semibold text-white ">
					{usage?.CPUPerc}
				</dd>
			</div>

			<div class="overflow-hidden rounded px-4 py-5 text-center sm:p-6 sm:text-left">
				<dt class="truncate text-sm font-medium text-white">Network IO</dt>
				<dd class="mt-1 text-xl font-semibold text-white ">
					{usage?.NetIO}
				</dd>
			</div>
		</dl>
	</div>
</div>
<div class="mx-auto max-w-4xl px-6">
	<!-- svelte-ignore missing-declaration -->
	<form on:submit|preventDefault={handleSubmit} class="py-4">
		<div class="flex space-x-1 pb-5 font-bold">
			<div class="title">{$t('general')}</div>
			{#if $appSession.isAdmin}
				<button
					type="submit"
					class:bg-green-600={!loading}
					class:bg-orange-600={forceSave}
					class:hover:bg-green-500={!loading}
					class:hover:bg-orange-400={forceSave}
					disabled={loading}
					>{loading
						? $t('forms.saving')
						: forceSave
						? $t('forms.confirm_continue')
						: $t('forms.save')}</button
				>
			{/if}
		</div>
		<div class="grid grid-flow-row gap-2 px-10">
			<div class="mt-2 grid grid-cols-2 items-center">
				<label for="name" class="text-base font-bold text-stone-100">{$t('forms.name')}</label>
				<input
					readonly={!isDisabled}
					name="name"
					id="name"
					bind:value={application.name}
					required
				/>
			</div>
			<div class="grid grid-cols-2 items-center">
				<label for="gitSource" class="text-base font-bold text-stone-100"
					>{$t('application.git_source')}</label
				>
				<a
					href={!isDisabled
						? `/applications/${id}/configuration/source?from=/applications/${id}`
						: ''}
					class="no-underline"
					><input
						value={application.gitSource.name}
						id="gitSource"
						disabled
						class="cursor-pointer hover:bg-coolgray-500"
					/></a
				>
			</div>
			<div class="grid grid-cols-2 items-center">
				<label for="repository" class="text-base font-bold text-stone-100"
					>{$t('application.git_repository')}</label
				>
				<a
					href={!isDisabled
						? `/applications/${id}/configuration/repository?from=/applications/${id}&to=/applications/${id}/configuration/buildpack`
						: ''}
					class="no-underline"
					><input
						value="{application.repository}/{application.branch}"
						id="repository"
						disabled
						class="cursor-pointer hover:bg-coolgray-500"
					/></a
				>
			</div>
			<div class="grid grid-cols-2 items-center">
				<label for="buildPack" class="text-base font-bold text-stone-100"
					>{$t('application.build_pack')}</label
				>
				<a
					href={!isDisabled
						? `/applications/${id}/configuration/buildpack?from=/applications/${id}`
						: ''}
					class="no-underline "
				>
					<input
						value={application.buildPack}
						id="buildPack"
						disabled
						class="cursor-pointer hover:bg-coolgray-500"
					/></a
				>
			</div>
			<div class="grid grid-cols-2 items-center pb-8">
				<label for="destination" class="text-base font-bold text-stone-100"
					>{$t('application.destination')}</label
				>
				<div class="no-underline">
					<input
						value={application.destinationDocker.name}
						id="destination"
						disabled
						class="bg-transparent"
					/>
				</div>
			</div>
			{#if application.buildPack !== 'docker'}
				<div class="grid grid-cols-2 items-center">
					<label for="baseImage" class="text-base font-bold text-stone-100"
						>{$t('application.base_image')}</label
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
					<Explainer text={$t('application.base_image_explainer')} />
				</div>
			{/if}
			{#if application.buildCommand || application.buildPack === 'rust' || application.buildPack === 'laravel'}
				<div class="grid grid-cols-2 items-center pb-8">
					<label for="baseBuildImage" class="text-base font-bold text-stone-100"
						>{$t('application.base_build_image')}</label
					>

					<div class="custom-select-wrapper">
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
					{#if application.buildPack === 'laravel'}
						<Explainer text="For building frontend assets with webpack." />
					{:else}
						<Explainer text={$t('application.base_build_image_explainer')} />
					{/if}
				</div>
			{/if}
		</div>
		<div class="flex space-x-1 py-5 font-bold">
			<div class="title">{$t('application.application')}</div>
		</div>
		<div class="grid grid-flow-row gap-2 px-10">
			<div class="grid grid-cols-2">
				<div class="flex-col">
					<label for="fqdn" class="pt-2 text-base font-bold text-stone-100"
						>{$t('application.url_fqdn')}</label
					>
					{#if browser && window.location.hostname === 'demo.coolify.io'}
						<Explainer
							text="<span class='text-white font-bold'>You can use the predefined random url name or enter your own domain name.</span>"
						/>
					{/if}
					<Explainer text={$t('application.https_explainer')} />
				</div>
				<div>
					<input
						readonly={isDisabled}
						disabled={isDisabled}
						bind:this={domainEl}
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
									class="bg-green-600 hover:bg-green-500"
									on:click|preventDefault={() => isDNSValid(getDomain(nonWWWDomain), false)}
									>DNS settings for {nonWWWDomain} is OK, click to recheck.</button
								>
							{:else}
								<button
									class="bg-red-600 hover:bg-red-500"
									on:click|preventDefault={() => isDNSValid(getDomain(nonWWWDomain), false)}
									>DNS settings for {nonWWWDomain} is invalid, click to recheck.</button
								>
							{/if}
							{#if dualCerts}
								{#if isWWWDomainOK}
									<button
										class="bg-green-600 hover:bg-green-500"
										on:click|preventDefault={() =>
											isDNSValid(getDomain(`www.${nonWWWDomain}`), true)}
										>DNS settings for www.{nonWWWDomain} is OK, click to recheck.</button
									>
								{:else}
									<button
										class="bg-red-600 hover:bg-red-500"
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
			<div class="grid grid-cols-2 items-center pb-8">
				<Setting
					dataTooltip={$t('forms.must_be_stopped_to_modify')}
					disabled={$status.application.isRunning}
					isCenter={false}
					bind:setting={dualCerts}
					title={$t('application.ssl_www_and_non_www')}
					description={$t('application.ssl_explainer')}
					on:click={() => !$status.application.isRunning && changeSettings('dualCerts')}
				/>
			</div>
			{#if application.buildPack === 'python'}
				<div class="grid grid-cols-2 items-center">
					<label for="pythonModule" class="text-base font-bold text-stone-100">WSGI / ASGI</label>
					<div class="custom-select-wrapper">
						<Select id="wsgi" items={wsgis} on:select={selectWSGI} value={application.pythonWSGI} />
					</div>
				</div>

				<div class="grid grid-cols-2 items-center">
					<label for="pythonModule" class="text-base font-bold text-stone-100">Module</label>
					<input
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
						<label for="pythonVariable" class="text-base font-bold text-stone-100">Variable</label>
						<input
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
						<label for="pythonVariable" class="text-base font-bold text-stone-100">Variable</label>
						<input
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
					<label for="port" class="text-base font-bold text-stone-100">{$t('forms.port')}</label>
					<input
						readonly={!$appSession.isAdmin}
						name="port"
						id="port"
						bind:value={application.port}
						placeholder="{$t('forms.default')}: 'python' ? '8000' : '3000'"
					/>
				</div>
			{/if}
			{#if application.buildPack !== 'docker'}
				<div class="grid grid-cols-2 items-center">
					<label for="exposePort" class="text-base font-bold text-stone-100">Exposed Port</label>
					<input
						readonly={!$appSession.isAdmin && !$status.application.isRunning}
						disabled={isDisabled}
						name="exposePort"
						id="exposePort"
						bind:value={application.exposePort}
						placeholder="12345"
					/>
					<Explainer
						text={'You can expose your application to a port on the host system.<br><br>Useful if you would like to use your own reverse proxy or tunnel and also in development mode. Otherwise leave empty.'}
					/>
				</div>
			{/if}
			{#if !notNodeDeployments.includes(application.buildPack)}
				<div class="grid grid-cols-2 items-center pt-4">
					<label for="installCommand" class="text-base font-bold text-stone-100"
						>{$t('application.install_command')}</label
					>
					<input
						readonly={!$appSession.isAdmin}
						name="installCommand"
						id="installCommand"
						bind:value={application.installCommand}
						placeholder="{$t('forms.default')}: yarn install"
					/>
				</div>
				<div class="grid grid-cols-2 items-center">
					<label for="buildCommand" class="text-base font-bold text-stone-100"
						>{$t('application.build_command')}</label
					>
					<input
						readonly={!$appSession.isAdmin}
						name="buildCommand"
						id="buildCommand"
						bind:value={application.buildCommand}
						placeholder="{$t('forms.default')}: yarn build"
					/>
				</div>
				<div class="grid grid-cols-2 items-center">
					<label for="startCommand" class="text-base font-bold text-stone-100"
						>{$t('application.start_command')}</label
					>
					<input
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
					<label for="dockerFileLocation" class="text-base font-bold text-stone-100"
						>Dockerfile Location</label
					>
					<input
						readonly={!$appSession.isAdmin}
						name="dockerFileLocation"
						id="dockerFileLocation"
						bind:value={application.dockerFileLocation}
						placeholder="default: /Dockerfile"
					/>
					<Explainer
						text="Does not rely on Base Directory. <br>Should be absolute path, like <span class='text-green-500 font-bold'>/data/Dockerfile</span> or <span class='text-green-500 font-bold'>/Dockerfile.</span>"
					/>
				</div>
			{/if}
			{#if application.buildPack === 'deno'}
				<div class="grid grid-cols-2 items-center">
					<label for="denoMainFile" class="text-base font-bold text-stone-100">Main File</label>
					<input
						readonly={!$appSession.isAdmin}
						name="denoMainFile"
						id="denoMainFile"
						bind:value={application.denoMainFile}
						placeholder="default: main.ts"
					/>
				</div>
				<div class="grid grid-cols-2 items-center">
					<label for="denoOptions" class="text-base font-bold text-stone-100">Arguments</label>
					<input
						readonly={!$appSession.isAdmin}
						name="denoOptions"
						id="denoOptions"
						bind:value={application.denoOptions}
						placeholder="eg: --allow-net --allow-hrtime --config path/to/file.json"
					/>
					<Explainer
						text="List of arguments to pass to <span class='text-green-500 font-bold'>deno run</span> command. Could include permissions, configurations files, etc."
					/>
				</div>
			{/if}
			{#if application.buildPack !== 'laravel'}
				<div class="grid grid-cols-2 items-center">
					<div class="flex-col">
						<label for="baseDirectory" class="pt-2 text-base font-bold text-stone-100"
							>{$t('forms.base_directory')}</label
						>
						<Explainer text={$t('application.directory_to_use_explainer')} />
					</div>
					<input
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
						<label for="publishDirectory" class="pt-2 text-base font-bold text-stone-100"
							>{$t('forms.publish_directory')}</label
						>
						<Explainer text={$t('application.publish_directory_explainer')} />
					</div>

					<input
						readonly={!$appSession.isAdmin}
						name="publishDirectory"
						id="publishDirectory"
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
	<div class="px-10 pb-10">
		<div class="grid grid-cols-2 items-center">
			<Setting
				isCenter={false}
				bind:setting={autodeploy}
				on:click={() => changeSettings('autodeploy')}
				title={$t('application.enable_automatic_deployment')}
				description={$t('application.enable_auto_deploy_webhooks')}
			/>
		</div>
		<div class="grid grid-cols-2 items-center">
			<Setting
				isCenter={false}
				bind:setting={previews}
				on:click={() => changeSettings('previews')}
				title={$t('application.enable_mr_pr_previews')}
				description={$t('application.enable_preview_deploy_mr_pr_requests')}
			/>
		</div>
		<div class="grid grid-cols-2 items-center">
			<Setting
				isCenter={false}
				bind:setting={debug}
				on:click={() => changeSettings('debug')}
				title={$t('application.debug_logs')}
				description={$t('application.enable_debug_log_during_build')}
			/>
		</div>
	</div>
</div>
