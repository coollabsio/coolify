<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, page, stuff }) => {
		if (stuff?.application?.id) {
			return {
				props: {
					application: stuff.application
				}
			};
		}
		const url = `/applications/${page.params.id}.json`;
		const res = await fetch(url);

		if (res.ok) {
			return {
				props: {
					...(await res.json())
				}
			};
		}

		return {
			status: res.status,
			error: new Error(`Could not load ${url}`)
		};
	};
</script>

<script lang="ts">
	export let application: Prisma.Application;

	import type Prisma from '@prisma/client';

	import { page } from '$app/stores';
	import { enhance } from '$lib/form';
	import { onMount } from 'svelte';

	import Explainer from '$lib/components/Explainer.svelte';

	const { id } = $page.params;

	let autofocus;
	let loading = false;

	onMount(() => {
		autofocus.focus();
	});
</script>

<div class="font-bold flex space-x-1 p-5 text-2xl">
	<div class="tracking-tight">{application.name}</div>
	{#if application.domain}
		<span class="px-1 arrow-right-applications">></span>
		<span class="pr-2"
			><a href="http://{application.domain}" target="_blank">{application.domain}</a></span
		>
	{/if}
</div>

<div class="flex justify-center px-3">
	<form
		action="/applications/{id}.json"
		use:enhance={{
			result: async () => {
				setTimeout(() => {
					loading = false;
					window.location.reload();
				}, 300);
			},
			pending: async () => {
				loading = true;
			}
		}}
		method="post"
		class="grid grid-flow-row gap-2 py-4"
	>
		<div class="flex space-x-2 h-8 items-center">
			<div class="font-bold text-xl text-white">Configuration</div>
			<button type="submit" class="bg-green-700 hover:bg-green-600 " disabled={loading}>Save</button>
		</div>
		<div class="grid grid-cols-3 items-center">
			<label for="gitSource">Git Source</label>
			<div class="col-span-2">
				<a
					href="/applications/{id}/configuration/source?from=/applications/{id}"
					class="no-underline"
					><span class="arrow-right-applications">></span><input
						value={application.gitSource.name}
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
					href="/applications/{id}/configuration/repository?from=/applications/{id}"
					class="no-underline"
					><span class="arrow-right-applications">></span><input
						value="{application.repository}/{application.branch}"
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
					href="/applications/{id}/configuration/destination?from=/applications/{id}"
					class="no-underline"
					><span class="arrow-right-applications">></span><input
						value={application.destinationDocker.name}
						id="destination"
						disabled
						class="bg-transparent hover:bg-coolgray-500 cursor-pointer"
					/></a
				>
			</div>
		</div>
		<div class="grid grid-cols-3 items-center pb-8">
			<label for="buildPack">Build Pack</label>
			<div class="col-span-2">
				<a
					href="/applications/{id}/configuration/buildpack?from=/applications/{id}"
					class="no-underline"
					><span class="arrow-right-applications">></span><input
						value={application.buildPack}
						id="buildPack"
						disabled
						class="bg-transparent hover:bg-coolgray-500 cursor-pointer"
					/></a
				>
			</div>
		</div>
		<div class="grid grid-cols-3 items-center">
			<label for="domain">Domain</label>
			<div class="col-span-2 ">
				<input
					bind:this={autofocus}
					name="domain"
					id="domain"
					value={application.domain || ''}
					pattern="^([a-z0-9]+(-[a-z0-9]+)*\.)+[a-z]{'{'}2,{'}'}$"
					placeholder="eg: coollabs.io"
					required
				/>
			</div>
		</div>

		{#if application.buildPack !== 'static'}
			<div class="grid grid-cols-3 items-center">
				<label for="port">Port</label>
				<div class="col-span-2">
					<input name="port" id="port" value={application.port || ''} placeholder="default: 3000" />
				</div>
			</div>
		{/if}

		<div class="grid grid-cols-3 items-center pt-8">
			<label for="installCommand">Install Command</label>
			<div class="col-span-2">
				<input
					name="installCommand"
					id="installCommand"
					value={application.installCommand || ''}
					placeholder="default: yarn install"
				/>
			</div>
		</div>
		<div class="grid grid-cols-3 items-center">
			<label for="buildCommand">Build Command</label>
			<div class="col-span-2">
				<input
					name="buildCommand"
					id="buildCommand"
					value={application.buildCommand || ''}
					placeholder="default: yarn build"
				/>
			</div>
		</div>
		<div class="grid grid-cols-3 items-center pb-8">
			<label for="startCommand" class="">Start Command</label>
			<div class="col-span-2">
				<input
					name="startCommand"
					id="startcommand"
					value={application.startCommand || ''}
					placeholder="default: yarn start"
				/>
			</div>
		</div>
		<div class="grid grid-cols-3">
			<label for="baseDirectory">Base Directory</label>
			<div class="col-span-2">
				<input
					name="baseDirectory"
					id="baseDirectory"
					value={application.baseDirectory || ''}
					placeholder="default: /"
				/>
				<Explainer
					text="Directory to use as the base of all commands. Could be useful with monorepos."
				/>
			</div>
		</div>
		<div class="grid grid-cols-3">
			<label for="publishDirectory">Publish Directory</label>
			<div class="col-span-2">
				<input
					name="publishDirectory"
					id="publishDirectory"
					value={application.publishDirectory || ''}
					placeholder="eg: dist or _site or public"
				/>
				<Explainer text="Directory containing all the assets for deployment." />
			</div>
		</div>
	</form>
</div>
