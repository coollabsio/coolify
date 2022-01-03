<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, params, stuff }) => {
		if (stuff?.application?.id) {
			return {
				props: {
					application: stuff.application
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
	export let application: Application & {
		settings: ApplicationSettings;
	};
	import { page, session } from '$app/stores';
	import { enhance } from '$lib/form';
	import { onMount } from 'svelte';

	import Explainer from '$lib/components/Explainer.svelte';
	import { appConfiguration } from '$lib/store';
	import Setting from '$lib/components/Setting.svelte';
	import type { Application, ApplicationSettings } from '@prisma/client';
	const { id } = $page.params;

	let domainEl: HTMLInputElement;

	let loading = false;
	let debug = application.settings.debug;
	let previews = application.settings.previews;
	let forceSSL = application.settings.forceSSL;

	onMount(() => {
		domainEl.focus();
	});

	async function changeSettings(name) {
		const form = new FormData();
		let forceSSLChanged = false;
		if (name === 'debug') {
			debug = !debug;
		}
		if (name === 'previews') {
			previews = !previews;
		}
		if (name === 'forceSSL') {
			if (!forceSSL) forceSSLChanged = true;
			forceSSL = !forceSSL;
		}
		form.append('previews', previews.toString());
		form.append('debug', debug.toString());
		form.append('forceSSL', forceSSL.toString());
		form.append('forceSSLChanged', forceSSLChanged.toString());

		try {
			await fetch(`/applications/${id}/settings.json`, {
				method: 'POST',
				body: form
			});
		} catch (e) {
			console.error(e);
		}
	}
</script>

<div class="font-bold flex space-x-1 p-5 px-6 text-2xl items-center">
	<div class="tracking-tight truncate md:max-w-64 md:block hidden">
		{$appConfiguration.configuration.name}
	</div>
	{#if $appConfiguration.configuration.domain}
		<span class="px-1 arrow-right-applications md:block hidden">></span>
		<span class="pr-2"
			><a href="http://{$appConfiguration.configuration.domain}" target="_blank"
				>{$appConfiguration.configuration.domain}</a
			></span
		>
	{/if}
	<a
		href="{$appConfiguration.configuration.gitSource.htmlUrl}/{$appConfiguration.configuration
			.repository}/tree/{$appConfiguration.configuration.branch}"
		target="_blank"
		class="w-10"
	>
		{#if $appConfiguration.configuration.gitSource.type === 'gitlab'}
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
		{:else if $appConfiguration.configuration.gitSource.type === 'github'}
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

<div class="max-w-4xl mx-auto px-6">
	<form
		action="/applications/{id}.json"
		use:enhance={{
			result: async () => {
				setTimeout(() => {
					loading = false;
					window.location.reload();
				}, 200);
			},
			pending: async () => {
				loading = true;
			},
			final: async () => {
				loading = false;
			}
		}}
		method="post"
		class=" py-4"
	>
		<div class="font-bold flex space-x-1 pb-5">
			<div class="text-xl tracking-tight mr-4">Configurations</div>
			{#if $session.isAdmin}
				<button
					type="submit"
					class:bg-green-600={!loading}
					class:hover:bg-green-500={!loading}
					disabled={loading}>{loading ? 'Saving...' : 'Save'}</button
				>
			{/if}
		</div>
		<div class="grid grid-flow-row gap-2 px-10">
			<div class="grid grid-cols-3 items-center mt-2">
				<label for="gitSource">Git Source</label>
				<div class="col-span-2">
					<a
						href={$session.isAdmin
							? `/applications/${id}/configuration/source?from=/applications/${id}`
							: ''}
						class="no-underline"
						><span class="arrow-right-applications">></span><input
							value={$appConfiguration.configuration.gitSource.name}
							id="gitSource"
							disabled
							class="bg-transparent hover:bg-coolgray-500 cursor-pointer"
						/></a
					>
				</div>
			</div>
			<div class="grid grid-cols-3 items-center">
				<label for="repository">Git Repository</label>
				<div class="col-span-2">
					<a
						href={$session.isAdmin
							? `/applications/${id}/configuration/repository?from=/applications/${id}`
							: ''}
						class="no-underline"
						><span class="arrow-right-applications">></span><input
							value="{$appConfiguration.configuration.repository}/{$appConfiguration.configuration
								.branch}"
							id="repository"
							disabled
							class="bg-transparent hover:bg-coolgray-500 cursor-pointer"
						/></a
					>
				</div>
			</div>
			<div class="grid grid-cols-3 items-center">
				<label for="destination">Destination</label>
				<div class="col-span-2">
					<a
						href={$session.isAdmin
							? `/applications/${id}/configuration/destination?from=/applications/${id}`
							: ''}
						class="no-underline"
						><span class="arrow-right-applications">></span><input
							value={$appConfiguration.configuration.destinationDocker.name}
							id="destination"
							disabled
							class="bg-transparent hover:bg-coolgray-500 cursor-pointer"
						/></a
					>
				</div>
			</div>
			<div class="grid grid-cols-3 items-center py-8">
				<label for="buildPack">Build Pack</label>
				<div class="col-span-2">
					<a
						href={$session.isAdmin
							? `/applications/${id}/configuration/buildpack?from=/applications/${id}`
							: ''}
						class="no-underline "
						><span class="arrow-right-applications">></span>
						<input
							value={$appConfiguration.configuration.buildPack}
							id="buildPack"
							disabled
							class="bg-transparent hover:bg-coolgray-500 cursor-pointer -ml-1"
						/></a
					>
				</div>
			</div>
			<div class="grid grid-cols-3 items-center pb-8">
				<label for="domain">Domain</label>
				<div class="col-span-2 ">
					<input
						readonly={!$session.isAdmin}
						bind:this={domainEl}
						name="domain"
						id="domain"
						value={$appConfiguration.configuration.domain}
						pattern="^([a-z0-9]+(-[a-z0-9]+)*\.)+[a-z]{'{'}2,{'}'}$"
						placeholder="eg: coollabs.io"
						required
					/>
				</div>
			</div>

			{#if $appConfiguration.configuration.buildPack !== 'static'}
				<div class="grid grid-cols-3 items-center">
					<label for="port">Port</label>
					<div class="col-span-2">
						<input
							readonly={!$session.isAdmin}
							name="port"
							id="port"
							bind:value={$appConfiguration.configuration.port}
							placeholder="default: 3000"
						/>
					</div>
				</div>
			{/if}
			{#if $appConfiguration.configuration.buildPack !== 'docker'}
				<div class="grid grid-cols-3 items-center">
					<label for="installCommand">Install Command</label>
					<div class="col-span-2">
						<input
							readonly={!$session.isAdmin}
							name="installCommand"
							id="installCommand"
							bind:value={$appConfiguration.configuration.installCommand}
							placeholder="default: yarn install"
						/>
					</div>
				</div>
				<div class="grid grid-cols-3 items-center">
					<label for="buildCommand">Build Command</label>
					<div class="col-span-2">
						<input
							readonly={!$session.isAdmin}
							name="buildCommand"
							id="buildCommand"
							bind:value={$appConfiguration.configuration.buildCommand}
							placeholder="default: yarn build"
						/>
					</div>
				</div>
				<div class="grid grid-cols-3 items-center pb-8">
					<label for="startCommand" class="">Start Command</label>
					<div class="col-span-2">
						<input
							readonly={!$session.isAdmin}
							name="startCommand"
							id="startCommand"
							bind:value={$appConfiguration.configuration.startCommand}
							placeholder="default: yarn start"
						/>
					</div>
				</div>
			{/if}
			<div class="grid grid-cols-3">
				<label for="baseDirectory">Base Directory</label>
				<div class="col-span-2">
					<input
						readonly={!$session.isAdmin}
						name="baseDirectory"
						id="baseDirectory"
						bind:value={$appConfiguration.configuration.baseDirectory}
						placeholder="default: /"
					/>
					<Explainer
						text="Directory to use as the base of all commands. <br> Could be useful with monorepos."
					/>
				</div>
			</div>
			<div class="grid grid-cols-3">
				<label for="publishDirectory">Publish Directory</label>
				<div class="col-span-2">
					<input
						readonly={!$session.isAdmin}
						name="publishDirectory"
						id="publishDirectory"
						bind:value={$appConfiguration.configuration.publishDirectory}
						placeholder=" default: /"
					/>
					<Explainer
						text="Directory containing all the assets for deployment. <br> For example: dist or _site or public"
					/>
				</div>
			</div>
		</div>
	</form>
	<div class="font-bold flex space-x-1 pb-5">
		<div class="text-xl tracking-tight mr-4">Features</div>
	</div>
	<div class="px-4 sm:px-6 pb-10">
		<ul class="mt-2 divide-y divide-warmGray-800">
			<Setting
				bind:setting={forceSSL}
				on:click={() => changeSettings('forceSSL')}
				title="Force SSL"
				description=""
			/>
		</ul>
		<ul class="mt-2 divide-y divide-warmGray-800">
			<Setting
				bind:setting={previews}
				on:click={() => changeSettings('previews')}
				title="Enable MR/PR Previews"
				description="Creates previews from pull and merge requests."
			/>
		</ul>
		<ul class="mt-2 divide-y divide-warmGray-800">
			<Setting
				bind:setting={debug}
				on:click={() => changeSettings('debug')}
				title="Debug Logs"
				description="Enable debug logs during build phase. <br>(<span class='text-red-500'>sensitive information</span> could be visible in logs)"
			/>
		</ul>
	</div>
</div>
