<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, params, stuff }) => {
		if (stuff?.application?.id) {
			return {
				props: {
					application: stuff.application,
					isRunning: stuff.isRunning
				}
			};
		}
		const endpoint = `/applications/${params.id}.json`;
		const res = await fetch(endpoint);

		if (res.ok) {
			return {
				props: {
					...(await res.json())
				}
			};
		}

		return {
			status: res.status,
			error: new Error(`Could not load ${endpoint}`)
		};
	};
</script>

<script lang="ts">
	export let application: Prisma.Application & {
		settings: Prisma.ApplicationSettings;
		gitlabApp: Prisma.GitlabApp;
		gitSource: Prisma.GitSource;
		destinationDocker: Prisma.DestinationDocker;
	};
	export let isRunning;
	import { page, session } from '$app/stores';
	import { errorNotification } from '$lib/form';
	import { onMount } from 'svelte';
	import Select from 'svelte-select';

	import Explainer from '$lib/components/Explainer.svelte';
	import Setting from '$lib/components/Setting.svelte';
	import type Prisma from '@prisma/client';
	import { notNodeDeployments, staticDeployments } from '$lib/components/common';
	import { toast } from '@zerodevx/svelte-toast';
	import { post } from '$lib/api';
	import cuid from 'cuid';
	import { browser } from '$app/env';
	const { id } = $page.params;

	let domainEl: HTMLInputElement;

	let loading = false;
	let forceSave = false;
	let debug = application.settings.debug;
	let previews = application.settings.previews;
	let dualCerts = application.settings.dualCerts;
	let autodeploy = application.settings.autodeploy;

	let wsgis = [
		{
			value: 'None',
			label: 'None'
		},
		{
			value: 'Gunicorn',
			label: 'Gunicorn'
		}
		// },
		// {
		// 	value: 'uWSGI',
		// 	label: 'uWSGI'
		// }
	];

	if (browser && window.location.hostname === 'demo.coolify.io' && !application.fqdn) {
		application.fqdn = `http://${cuid()}.demo.coolify.io`;
	}

	onMount(() => {
		domainEl.focus();
	});

	async function changeSettings(name) {
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
			await post(`/applications/${id}/settings.json`, {
				previews,
				debug,
				dualCerts,
				autodeploy,
				branch: application.branch,
				projectId: application.projectId
			});
			return toast.push('Settings saved.');
		} catch ({ error }) {
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
		loading = true;
		try {
			await post(`/applications/${id}/check.json`, { fqdn: application.fqdn, forceSave });
			await post(`/applications/${id}.json`, { ...application });
			return window.location.reload();
		} catch (error) {
			if (error?.startsWith('DNS not set')) {
				forceSave = true;
			}
			return errorNotification(error);
		} finally {
			loading = false;
		}
	}
	async function selectWSGI(event) {
		application.pythonWSGI = event.detail.value;
	}
</script>

<div class="flex items-center space-x-2 p-5 px-6 font-bold">
	<div class="-mb-6 flex-col">
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

<div class="mx-auto max-w-4xl px-6">
	<!-- svelte-ignore missing-declaration -->
	<form on:submit|preventDefault={handleSubmit} class="py-4">
		<div class="flex space-x-1 pb-5 font-bold">
			<div class="title">General</div>
			{#if $session.isAdmin}
				<button
					type="submit"
					class:bg-green-600={!loading}
					class:bg-orange-600={forceSave}
					class:hover:bg-green-500={!loading}
					class:hover:bg-orange-400={forceSave}
					disabled={loading}
					>{loading ? 'Saving...' : forceSave ? 'Are you sure to continue?' : 'Save'}</button
				>
			{/if}
		</div>
		<div class="grid grid-flow-row gap-2 px-10">
			<div class="mt-2 grid grid-cols-2 items-center">
				<label for="name" class="text-base font-bold text-stone-100">Name</label>
				<input
					readonly={!$session.isAdmin}
					name="name"
					id="name"
					bind:value={application.name}
					required
				/>
			</div>
			<div class="grid grid-cols-2 items-center">
				<label for="gitSource" class="text-base font-bold text-stone-100">Git Source</label>
				<a
					href={$session.isAdmin
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
				<label for="repository" class="text-base font-bold text-stone-100">Git Repository</label>
				<a
					href={$session.isAdmin
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
				<label for="buildPack" class="text-base font-bold text-stone-100">Build Pack</label>
				<a
					href={$session.isAdmin
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
				<label for="destination" class="text-base font-bold text-stone-100">Destination</label>
				<div class="no-underline">
					<input
						value={application.destinationDocker.name}
						id="destination"
						disabled
						class="bg-transparent "
					/>
				</div>
			</div>
		</div>
		<div class="flex space-x-1 py-5 font-bold">
			<div class="title">Application</div>
		</div>
		<div class="grid grid-flow-row gap-2 px-10">
			<div class="grid grid-cols-2">
				<div class="flex-col">
					<label for="fqdn" class="pt-2 text-base font-bold text-stone-100">URL (FQDN)</label>
					{#if browser && window.location.hostname === 'demo.coolify.io'}
						<Explainer
							text="<span class='text-white font-bold'>You can use the predefined random url name or enter your own domain name.</span>"
						/>
					{/if}
					<Explainer
						text="If you specify <span class='text-green-500 font-bold'>https</span>, the application will be accessible only over https. SSL certificate will be generated for you.<br>If you specify <span class='text-green-500 font-bold'>www</span>, the application will be redirected (302) from non-www and vice versa.<br><br>To modify the url, you must first stop the application.<br><br><span class='text-white font-bold'>You must set your DNS to point to the server IP in advance.</span>"
					/>
				</div>
				<input
					readonly={!$session.isAdmin || isRunning}
					disabled={!$session.isAdmin || isRunning}
					bind:this={domainEl}
					name="fqdn"
					id="fqdn"
					bind:value={application.fqdn}
					pattern="^https?://([a-z0-9]+(-[a-z0-9]+)*\.)+[a-z]{'{'}2,{'}'}$"
					placeholder="eg: https://coollabs.io"
					required
				/>
			</div>
			<div class="grid grid-cols-2 items-center pb-8">
				<Setting
					dataTooltip="Must be stopped to modify."
					disabled={isRunning}
					isCenter={false}
					bind:setting={dualCerts}
					title="Generate SSL for www and non-www?"
					description="It will generate certificates for both www and non-www. <br>You need to have <span class='font-bold text-green-500'>both DNS entries</span> set in advance.<br><br>Useful if you expect to have visitors on both."
					on:click={() => !isRunning && changeSettings('dualCerts')}
				/>
			</div>
			{#if application.buildPack === 'python'}
				<div class="grid grid-cols-2 items-center">
					<label for="pythonModule" class="text-base font-bold text-stone-100">WSGI</label>
					<div class="custom-select-wrapper">
						<Select id="wsgi" items={wsgis} on:select={selectWSGI} value={application.pythonWSGI} />
					</div>
				</div>

				<div class="grid grid-cols-2 items-center">
					<label for="pythonModule" class="text-base font-bold text-stone-100">Module</label>
					<input
						readonly={!$session.isAdmin}
						name="pythonModule"
						id="pythonModule"
						required
						bind:value={application.pythonModule}
						placeholder={application.pythonWSGI?.toLowerCase() !== 'gunicorn' ? 'main.py' : 'main'}
					/>
				</div>
				{#if application.pythonWSGI?.toLowerCase() === 'gunicorn'}
					<div class="grid grid-cols-2 items-center">
						<label for="pythonVariable" class="text-base font-bold text-stone-100">Variable</label>
						<input
							readonly={!$session.isAdmin}
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
					<label for="port" class="text-base font-bold text-stone-100">Port</label>
					<input
						readonly={!$session.isAdmin}
						name="port"
						id="port"
						bind:value={application.port}
						placeholder={application.buildPack === 'python' ? '8000' : '3000'}
					/>
				</div>
			{/if}

			{#if !notNodeDeployments.includes(application.buildPack)}
				<div class="grid grid-cols-2 items-center">
					<label for="installCommand" class="text-base font-bold text-stone-100"
						>Install Command</label
					>
					<input
						readonly={!$session.isAdmin}
						name="installCommand"
						id="installCommand"
						bind:value={application.installCommand}
						placeholder="default: yarn install"
					/>
				</div>
				<div class="grid grid-cols-2 items-center">
					<label for="buildCommand" class="text-base font-bold text-stone-100">Build Command</label>
					<input
						readonly={!$session.isAdmin}
						name="buildCommand"
						id="buildCommand"
						bind:value={application.buildCommand}
						placeholder="default: yarn build"
					/>
				</div>
				<div class="grid grid-cols-2 items-center">
					<label for="startCommand" class="text-base font-bold text-stone-100">Start Command</label>
					<input
						readonly={!$session.isAdmin}
						name="startCommand"
						id="startCommand"
						bind:value={application.startCommand}
						placeholder="default: yarn start"
					/>
				</div>
			{/if}
			<div class="grid grid-cols-2 items-center">
				<div class="flex-col">
					<label for="baseDirectory" class="pt-2 text-base font-bold text-stone-100"
						>Base Directory</label
					>
					<Explainer
						text="Directory to use as the base for all commands.<br>Could be useful with <span class='text-green-500 font-bold'>monorepos</span>."
					/>
				</div>
				<input
					readonly={!$session.isAdmin}
					name="baseDirectory"
					id="baseDirectory"
					bind:value={application.baseDirectory}
					placeholder="default: /"
				/>
			</div>
			{#if !notNodeDeployments.includes(application.buildPack)}
				<div class="grid grid-cols-2 items-center">
					<div class="flex-col">
						<label for="publishDirectory" class="pt-2 text-base font-bold text-stone-100"
							>Publish Directory</label
						>
						<Explainer
							text="Directory containing all the assets for deployment. <br> For example: <span class='text-green-500 font-bold'>dist</span>,<span class='text-green-500 font-bold'>_site</span> or <span class='text-green-500 font-bold'>public</span>."
						/>
					</div>

					<input
						readonly={!$session.isAdmin}
						name="publishDirectory"
						id="publishDirectory"
						bind:value={application.publishDirectory}
						placeholder=" default: /"
					/>
				</div>
			{/if}
		</div>
	</form>
	<div class="flex space-x-1 pb-5 font-bold">
		<div class="title">Features</div>
	</div>
	<div class="px-10 pb-10">
		<div class="grid grid-cols-2 items-center">
			<Setting
				isCenter={false}
				bind:setting={autodeploy}
				on:click={() => changeSettings('autodeploy')}
				title="Enable Automatic Deployment"
				description="Enable automatic deployment through webhooks."
			/>
		</div>
		<div class="grid grid-cols-2 items-center">
			<Setting
				isCenter={false}
				bind:setting={previews}
				on:click={() => changeSettings('previews')}
				title="Enable MR/PR Previews"
				description="Enable preview deployments from pull or merge requests."
			/>
		</div>
		<div class="grid grid-cols-2 items-center">
			<Setting
				isCenter={false}
				bind:setting={debug}
				on:click={() => changeSettings('debug')}
				title="Debug Logs"
				description="Enable debug logs during build phase.<br><span class='text-red-500 font-bold'>Sensitive information</span> could be visible and saved in logs."
			/>
		</div>
	</div>
</div>
