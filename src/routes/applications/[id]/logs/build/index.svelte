<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, params, url, stuff }) => {
		let endpoint = `/applications/${params.id}/logs/build.json?skip=0`;
		const res = await fetch(endpoint);
		if (res.ok) {
			return {
				props: {
					application: stuff.application,
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
	import { page } from '$app/stores';
	import { changeQueryParams, dateOptions, getDomain } from '$lib/components/common';

	import BuildLog from './_BuildLog.svelte';
	import { get } from '$lib/api';
	import { errorNotification } from '$lib/form';
	import { goto } from '$app/navigation';

	export let builds;
	export let application;
	export let buildCount;

	let buildId;

	let skip = 0;
	let noMoreBuilds = buildCount < 5 || buildCount <= skip;
	const { id } = $page.params;
	let preselectedBuildId = $page.url.searchParams.get('buildId');
	if (preselectedBuildId) buildId = preselectedBuildId;

	async function updateBuildStatus({ detail }) {
		const { status } = detail;
		if (status !== 'running') {
			try {
				const data = await get(`/applications/${id}/logs/build.json?buildId=${buildId}`);
				builds = builds.filter((build) => {
					if (build.id === data.builds[0].id) {
						build.status = data.builds[0].status;
						build.took = data.builds[0].took;
						build.since = data.builds[0].since;
					}

					return build;
				});
			} catch ({ error }) {
				return errorNotification(error);
			}
		} else {
			builds = builds.filter((build) => {
				if (build.id === buildId) build.status = status;
				return build;
			});
		}
	}
	async function loadMoreBuilds() {
		if (buildCount >= skip) {
			skip = skip + 5;
			noMoreBuilds = buildCount >= skip;
			try {
				const data = await get(`/applications/${id}/logs/build.json?skip=${skip}`);
				builds = builds.concat(data.builds);
				return;
			} catch ({ error }) {
				return errorNotification(error);
			}
		} else {
			noMoreBuilds = true;
		}
	}
	function loadBuild(build) {
		buildId = build;
		return changeQueryParams(buildId);
	}
</script>

<div class="flex items-center space-x-2 p-5 px-6 font-bold">
	<div class="-mb-5 flex-col">
		<div class="md:max-w-64 truncate text-base tracking-tight md:text-2xl lg:block">Build Logs</div>
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
<div class="block flex-row justify-start space-x-2 px-5 pt-6 sm:px-10 md:flex">
	<div class="mb-4 min-w-[16rem] space-y-2 md:mb-0 ">
		<div class="top-4 md:sticky">
			{#each builds as build, index (build.id)}
				<div
					data-tooltip={new Intl.DateTimeFormat('default', dateOptions).format(
						new Date(build.createdAt)
					) + `\n${build.status}`}
					on:click={() => loadBuild(build.id)}
					class:rounded-tr={index === 0}
					class:rounded-br={index === builds.length - 1}
					class="tooltip-top flex cursor-pointer items-center justify-center border-l-2 border-transparent py-4 no-underline transition-all duration-100 hover:bg-coolgray-400 hover:shadow-xl "
					class:bg-coolgray-400={buildId === build.id}
					class:border-red-500={build.status === 'failed'}
					class:border-green-500={build.status === 'success'}
					class:border-yellow-500={build.status === 'running'}
				>
					<div class="flex-col px-2">
						<div class="text-sm font-bold">
							{application.branch}
						</div>
						<div class="text-xs">
							{build.type}
						</div>
					</div>
					<div class="flex-1" />

					<div class="w-48 text-center text-xs">
						{#if build.status === 'running'}
							<div class="font-bold">Running</div>
						{:else if build.status === 'queued'}
							<div class="font-bold">Queued</div>
						{:else}
							<div>{build.since}</div>
							<div>Finished in <span class="font-bold">{build.took}s</span></div>
						{/if}
					</div>
				</div>
			{/each}
		</div>
		{#if !noMoreBuilds}
			{#if buildCount > 5}
				<div class="flex space-x-2">
					<button disabled={noMoreBuilds} class="w-full" on:click={loadMoreBuilds}>Load More</button
					>
				</div>
			{/if}
		{/if}
	</div>
	<div class="flex-1 md:w-96">
		{#if buildId}
			{#key buildId}
				<svelte:component this={BuildLog} {buildId} on:updateBuildStatus={updateBuildStatus} />
			{/key}
		{/if}
	</div>
</div>
{#if buildCount === 0}
	<div class="text-center text-xl font-bold">No logs found</div>
{/if}
