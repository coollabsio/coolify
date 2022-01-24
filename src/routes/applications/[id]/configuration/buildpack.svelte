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
			const response = await fetch(`${apiUrl}/v4/projects/${projectId}/repository/tree`, {
				method: 'GET',
				headers: {
					Authorization: `Bearer ${$session.gitlabToken}`
				}
			});
			if (!response.ok) {
				scanning = false;
				throw new Error(`Could not load ${apiUrl}/v4/projects/${projectId}/repository/tree`);
			}
			const files = await response.json();
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
				const response = await fetch(
					`${apiUrl}/v4/projects/${projectId}/repository/files/${path}/raw?ref=${branch}`,
					{
						method: 'GET',
						headers: {
							Authorization: `Bearer ${$session.gitlabToken}`
						}
					}
				);
				if (!response.ok) {
					scanning = false;
					throw new Error(
						`Could not load ${apiUrl}/v4/projects/${projectId}/repository/files/${path}`
					);
				}
				const json = await response.json();
				checkTemplates({ json });
			} else if (cargoToml) {
				foundConfig.buildPack = 'rust';
			} else if (requirementsTxt) {
				foundConfig.buildPack = 'python';
			} else if (indexHtml) {
				foundConfig.buildPack = 'static';
			} else if (indexPHP) {
				foundConfig.buildPack = 'php';
			}
			scanning = false;
		} else if (type === 'github') {
			const response = await fetch(`${apiUrl}/repos/${repository}/contents?ref=${branch}`, {
				method: 'GET',
				headers: {
					Authorization: `token ${ghToken}`
				}
			});
			if (!response.ok) {
				console.log(await response.json());
				scanning = false;
				throw new Error(`Could not load ${apiUrl}/repos/${repository}/contents?ref=${branch}`);
			}
			const files = await response.json();
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
				const response = await fetch(`${packageJson.git_url}`, {
					method: 'GET',
					headers: {
						Accept: 'application/vnd.github.v3.raw+json',
						Authorization: `token ${ghToken}`
					}
				});
				if (!response.ok) {
					scanning = false;
					throw new Error(`Could not load ${packageJson.git_url}`);
				}
				const json = await response.json();
				checkTemplates({ json });
			} else if (cargoToml) {
				foundConfig.buildPack = 'rust';
			} else if (requirementsTxt) {
				foundConfig.buildPack = 'python';
			} else if (indexHtml) {
				foundConfig.buildPack = 'static';
			} else if (indexPHP) {
				foundConfig.buildPack = 'php';
			}
			scanning = false;
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
