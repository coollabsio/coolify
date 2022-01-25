<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, params, url, stuff }) => {
		const { application, ghToken } = stuff;
		if (application?.buildPack && !url.searchParams.get('from')) {
			return {
				status: 302,
				redirect: `/applications/${params.id}`
			};
		}
		const endpoint = `/applications/${params.id}/configuration/buildpack.json`;
		const res = await fetch(endpoint);
		if (res.ok) {
			return {
				props: {
					...(await res.json()),
					ghToken
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
	import { onMount } from 'svelte';

	import templates from '$lib/components/templates';
	import BuildPack from './_BuildPack.svelte';
	import { session } from '$app/stores';
	import { get } from '$lib/api';
	import { errorNotification } from '$lib/form';

	let scanning = true;
	let foundConfig = {
		buildPack: 'node'
	};

	export let buildPacks: BuildPack[];
	export let apiUrl;
	export let projectId;
	export let repository;
	export let branch;
	export let ghToken;
	export let type;

	function checkPackageJSONContents({ dep, json }) {
		return json?.dependencies?.hasOwnProperty(dep) || json?.devDependencies?.hasOwnProperty(dep);
	}
	function checkTemplates({ json }) {
		Object.keys(templates).forEach((dep) => {
			if (checkPackageJSONContents({ dep, json })) {
				foundConfig = templates[dep];
			}
		});
	}

	onMount(async () => {
		if (type === 'gitlab') {
			try {
				const files = await get(`${apiUrl}/v4/projects/${projectId}/repository/tree`, {
					Authorization: `Bearer ${$session.gitlabToken}`
				});
				const packageJson = files.find(
					(file) => file.name === 'package.json' && file.type === 'blob'
				);
				const dockerfile = files.find((file) => file.name === 'Dockerfile' && file.type === 'blob');
				const cargoToml = files.find((file) => file.name === 'Cargo.toml' && file.type === 'blob');
				const requirementsTxt = files.find(
					(file) => file.name === 'requirements.txt' && file.type === 'blob'
				);
				const indexHtml = files.find((file) => file.name === 'index.html' && file.type === 'blob');
				const indexPHP = files.find((file) => file.name === 'index.php' && file.type === 'blob');
				if (dockerfile) {
					foundConfig.buildPack = 'docker';
				} else if (packageJson) {
					const path = packageJson.path;
					try {
						const json = await get(
							`${apiUrl}/v4/projects/${projectId}/repository/files/${path}/raw?ref=${branch}`,
							{
								Authorization: `Bearer ${$session.gitlabToken}`
							}
						);
						return checkTemplates({ json });
					} catch ({ error }) {
						return errorNotification(error);
					} finally {
						scanning = false;
					}
				} else if (cargoToml) {
					foundConfig.buildPack = 'rust';
				} else if (requirementsTxt) {
					foundConfig.buildPack = 'python';
				} else if (indexHtml) {
					foundConfig.buildPack = 'static';
				} else if (indexPHP) {
					foundConfig.buildPack = 'php';
				}
			} catch ({ error }) {
				return errorNotification(error);
			} finally {
				scanning = false;
			}
		} else if (type === 'github') {
			try {
				const files = await get(`${apiUrl}/repos/${repository}/contents?ref=${branch}`);
				const packageJson = files.find(
					(file) => file.name === 'package.json' && file.type === 'file'
				);
				const dockerfile = files.find((file) => file.name === 'Dockerfile' && file.type === 'file');
				const cargoToml = files.find((file) => file.name === 'Cargo.toml' && file.type === 'file');
				const requirementsTxt = files.find(
					(file) => file.name === 'requirements.txt' && file.type === 'file'
				);
				const indexHtml = files.find((file) => file.name === 'index.html' && file.type === 'file');
				const indexPHP = files.find((file) => file.name === 'index.php' && file.type === 'file');
				if (dockerfile) {
					foundConfig.buildPack = 'docker';
				} else if (packageJson) {
					try {
						const json = await get(`${packageJson.git_url}`);
						return checkTemplates({ json });
					} catch ({ error }) {
						return errorNotification(error);
					} finally {
						scanning = false;
					}
				} else if (cargoToml) {
					foundConfig.buildPack = 'rust';
				} else if (requirementsTxt) {
					foundConfig.buildPack = 'python';
				} else if (indexHtml) {
					foundConfig.buildPack = 'static';
				} else if (indexPHP) {
					foundConfig.buildPack = 'php';
				}
			} catch ({ error }) {
				return errorNotification(error);
			} finally {
				scanning = false;
			}
		}
	});
</script>

<div class="font-bold flex space-x-1 py-5 px-6">
	<div class="text-2xl tracking-tight mr-4">Configure Build Pack</div>
</div>

{#if scanning}
	<div class="font-bold flex space-x-1 py-5 px-6 justify-center">
		<div class="text-xl tracking-tight">Scanning repository to suggest a build pack for you...</div>
	</div>
{:else}
	<div class="max-w-5xl mx-auto flex flex-wrap justify-center">
		{#each buildPacks as buildPack}
			<div class="p-2">
				<BuildPack {buildPack} {scanning} {foundConfig} />
			</div>
		{/each}
	</div>
{/if}
