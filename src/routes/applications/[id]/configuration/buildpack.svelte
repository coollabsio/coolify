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
					application,
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

	import { buildPacks, scanningTemplates } from '$lib/components/templates';
	import BuildPack from './_BuildPack.svelte';
	import { page, session } from '$app/stores';
	import { get } from '$lib/api';
	import { errorNotification } from '$lib/form';

	let scanning = true;
	let foundConfig = null;

	export let apiUrl;
	export let projectId;
	export let repository;
	export let branch;
	export let ghToken;
	export let type;
	export let application;

	function checkPackageJSONContents({ key, json }) {
		return json?.dependencies?.hasOwnProperty(key) || json?.devDependencies?.hasOwnProperty(key);
	}
	function checkTemplates({ json }) {
		Object.entries(scanningTemplates).forEach(([key, value]) => {
			if (checkPackageJSONContents({ key, json })) {
				return buildPacks.find((bp) => bp.name === value.buildPack);
			}
		});
	}
	async function scanRepository() {
		try {
			if (type === 'gitlab') {
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
					const data = await get(
						`${apiUrl}/v4/projects/${projectId}/repository/files/${path}/raw?ref=${branch}`,
						{
							Authorization: `Bearer ${$session.gitlabToken}`
						}
					);
					const json = JSON.parse(data) || {};
					foundConfig = checkTemplates({ json });
				} else if (cargoToml) {
					foundConfig = buildPacks.find((bp) => bp.name === 'rust');
				} else if (requirementsTxt) {
					foundConfig = buildPacks.find((bp) => bp.name === 'python');
				} else if (indexHtml) {
					foundConfig = buildPacks.find((bp) => bp.name === 'static');
				} else if (indexPHP) {
					foundConfig = buildPacks.find((bp) => bp.name === 'php');
				}
			} else if (type === 'github') {
				const files = await get(`${apiUrl}/repos/${repository}/contents?ref=${branch}`, {
					Authorization: `Bearer ${ghToken}`,
					Accept: 'application/vnd.github.v2.json'
				});
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
					const data = await get(`${packageJson.git_url}`, {
						Authorization: `Bearer ${ghToken}`,
						Accept: 'application/vnd.github.v2.raw'
					});
					const json = JSON.parse(data) || {};
					foundConfig = checkTemplates({ json });
				} else if (cargoToml) {
					foundConfig = buildPacks.find((bp) => bp.name === 'rust');
				} else if (requirementsTxt) {
					foundConfig = buildPacks.find((bp) => bp.name === 'python');
				} else if (indexHtml) {
					foundConfig = buildPacks.find((bp) => bp.name === 'static');
				} else if (indexPHP) {
					foundConfig = buildPacks.find((bp) => bp.name === 'php');
				}
			}
		} catch (error) {
			if (
				error.error === 'invalid_token' &&
				error.error_description ===
					'Token is expired. You can either do re-authorization or token refresh.'
			) {
				if (application.gitSource.gitlabAppId) {
					let htmlUrl = application.gitSource.htmlUrl;
					const left = screen.width / 2 - 1020 / 2;
					const top = screen.height / 2 - 618 / 2;
					const newWindow = open(
						`${htmlUrl}/oauth/authorize?client_id=${application.gitSource.gitlabApp.appId}&redirect_uri=${window.location.origin}/webhooks/gitlab&response_type=code&scope=api+email+read_repository&state=${$page.params.id}`,
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
							window.location.reload();
						}
					}, 100);
				}
			}
			return errorNotification(error);
		} finally {
			if (!foundConfig) foundConfig = buildPacks.find((bp) => bp.name === 'node');
			scanning = false;
		}
	}
	onMount(async () => {
		await scanRepository();
	});
</script>

<div class="flex space-x-1 py-5 px-6 font-bold">
	<div class="mr-4 text-2xl tracking-tight">Configure Build Pack</div>
</div>

{#if scanning}
	<div class="flex justify-center space-x-1 py-5 px-6 font-bold">
		<div class="text-xl tracking-tight">Scanning repository to suggest a build pack for you...</div>
	</div>
{:else}
	<div class="max-w-7xl mx-auto flex flex-wrap justify-center">
		{#each buildPacks as buildPack}
			<div class="p-2">
				<BuildPack {buildPack} {scanning} bind:foundConfig />
			</div>
		{/each}
	</div>
{/if}
