<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, params, url, stuff }) => {
		const { application } = stuff;
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
					application
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

	import { buildPacks, findBuildPack, scanningTemplates } from '$lib/components/templates';
	import BuildPack from './_BuildPack.svelte';
	import { page, session } from '$app/stores';
	import { get } from '$lib/api';
	import { errorNotification } from '$lib/form';
	import { gitTokens } from '$lib/store';
	import { browser } from '$app/env';

	const { id } = $page.params;

	let scanning = true;
	let foundConfig = null;
	let packageManager = 'npm';

	export let apiUrl;
	export let projectId;
	export let repository;
	export let branch;
	export let type;
	export let application;

	function checkPackageJSONContents({ key, json }) {
		return json?.dependencies?.hasOwnProperty(key) || json?.devDependencies?.hasOwnProperty(key);
	}
	function checkTemplates({ json, packageManager }) {
		for (const [key, value] of Object.entries(scanningTemplates)) {
			if (checkPackageJSONContents({ key, json })) {
				foundConfig = findBuildPack(value.buildPack, packageManager);
				break;
			}
		}
	}
	async function scanRepository() {
		try {
			if (type === 'gitlab') {
				const files = await get(`${apiUrl}/v4/projects/${projectId}/repository/tree`, {
					Authorization: `Bearer ${$gitTokens.gitlabToken}`
				});
				const packageJson = files.find(
					(file) => file.name === 'package.json' && file.type === 'blob'
				);
				const yarnLock = files.find((file) => file.name === 'yarn.lock' && file.type === 'blob');
				const pnpmLock = files.find(
					(file) => file.name === 'pnpm-lock.yaml' && file.type === 'blob'
				);
				const dockerfile = files.find((file) => file.name === 'Dockerfile' && file.type === 'blob');
				const cargoToml = files.find((file) => file.name === 'Cargo.toml' && file.type === 'blob');
				const requirementsTxt = files.find(
					(file) => file.name === 'requirements.txt' && file.type === 'blob'
				);
				const indexHtml = files.find((file) => file.name === 'index.html' && file.type === 'blob');
				const indexPHP = files.find((file) => file.name === 'index.php' && file.type === 'blob');
				const composerPHP = files.find(
					(file) => file.name === 'composer.json' && file.type === 'blob'
				);

				if (yarnLock) packageManager = 'yarn';
				if (pnpmLock) packageManager = 'pnpm';

				if (dockerfile) {
					foundConfig = findBuildPack('docker', packageManager);
				} else if (packageJson) {
					const path = packageJson.path;
					const data = await get(
						`${apiUrl}/v4/projects/${projectId}/repository/files/${path}/raw?ref=${branch}`,
						{
							Authorization: `Bearer ${$gitTokens.gitlabToken}`
						}
					);
					const json = JSON.parse(data) || {};
					checkTemplates({ json, packageManager });
				} else if (cargoToml) {
					foundConfig = findBuildPack('rust');
				} else if (requirementsTxt) {
					foundConfig = findBuildPack('python');
				} else if (indexHtml) {
					foundConfig = findBuildPack('static', packageManager);
				} else if (indexPHP || composerPHP) {
					foundConfig = findBuildPack('php');
				} else {
					foundConfig = findBuildPack('node', packageManager);
				}
			} else if (type === 'github') {
				const files = await get(`${apiUrl}/repos/${repository}/contents?ref=${branch}`, {
					Authorization: `Bearer ${$gitTokens.githubToken}`,
					Accept: 'application/vnd.github.v2.json'
				});
				const packageJson = files.find(
					(file) => file.name === 'package.json' && file.type === 'file'
				);
				const yarnLock = files.find((file) => file.name === 'yarn.lock' && file.type === 'file');
				const pnpmLock = files.find(
					(file) => file.name === 'pnpm-lock.yaml' && file.type === 'file'
				);
				const dockerfile = files.find((file) => file.name === 'Dockerfile' && file.type === 'file');
				const cargoToml = files.find((file) => file.name === 'Cargo.toml' && file.type === 'file');
				const requirementsTxt = files.find(
					(file) => file.name === 'requirements.txt' && file.type === 'file'
				);
				const indexHtml = files.find((file) => file.name === 'index.html' && file.type === 'file');
				const indexPHP = files.find((file) => file.name === 'index.php' && file.type === 'file');
				const composerPHP = files.find(
					(file) => file.name === 'composer.json' && file.type === 'file'
				);

				if (yarnLock) packageManager = 'yarn';
				if (pnpmLock) packageManager = 'pnpm';

				if (dockerfile) {
					foundConfig = findBuildPack('docker', packageManager);
				} else if (packageJson) {
					const data = await get(`${packageJson.git_url}`, {
						Authorization: `Bearer ${$gitTokens.githubToken}`,
						Accept: 'application/vnd.github.v2.raw'
					});
					const json = JSON.parse(data) || {};
					checkTemplates({ json, packageManager });
				} else if (cargoToml) {
					foundConfig = findBuildPack('rust');
				} else if (requirementsTxt) {
					foundConfig = findBuildPack('python');
				} else if (indexHtml) {
					foundConfig = findBuildPack('static', packageManager);
				} else if (indexPHP || composerPHP) {
					foundConfig = findBuildPack('php');
				} else {
					foundConfig = findBuildPack('node', packageManager);
				}
			}
		} catch (error) {
			scanning = true;
			if (
				error.error === 'invalid_token' ||
				error.error_description ===
					'Token is expired. You can either do re-authorization or token refresh.' ||
				error.message === '401 Unauthorized'
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
			if (error.message === 'Bad credentials') {
				const { token } = await get(`/applications/${id}/configuration/githubToken.json`);
				$gitTokens.githubToken = token;
				browser && window.location.reload();
			}
			return errorNotification(error);
		} finally {
			if (!foundConfig) foundConfig = findBuildPack('node', packageManager);
			scanning = false;
		}
	}
	onMount(async () => {
		await scanRepository();
	});
</script>

<div class="flex space-x-1 p-6 font-bold">
	<div class="mr-4 text-2xl tracking-tight">Configure Build Pack</div>
</div>

{#if scanning}
	<div class="flex justify-center space-x-1 p-6 font-bold">
		<div class="text-xl tracking-tight">Scanning repository to suggest a build pack for you...</div>
	</div>
{:else}
	{#if packageManager === 'yarn' || packageManager === 'pnpm'}
		<div class="flex justify-center p-6">
			Found lock file for <span class="font-bold text-orange-500 pl-1">{packageManager}</span>.
			Using it for predefined commands commands.
		</div>
	{/if}
	<div class="max-w-7xl mx-auto flex flex-wrap justify-center">
		{#each buildPacks as buildPack}
			<div class="p-2">
				<BuildPack {buildPack} {scanning} {packageManager} bind:foundConfig />
			</div>
		{/each}
	</div>
{/if}
