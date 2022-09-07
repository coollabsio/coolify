<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, params, url, stuff }) => {
		try {
			const { application } = stuff;
			if (application?.buildPack && !url.searchParams.get('from')) {
				return {
					status: 302,
					redirect: `/applications/${params.id}`
				};
			}
			const response = await get(`/applications/${params.id}/configuration/buildpack`);
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
	import { onMount } from 'svelte';

	import { page } from '$app/stores';
	import { get } from '$lib/api';
	import { appSession } from '$lib/store';
	import { t } from '$lib/translations';
	import { buildPacks, findBuildPack, scanningTemplates } from '$lib/templates';
	import { errorNotification } from '$lib/common';
	import BuildPack from './_BuildPack.svelte';

	const { id } = $page.params;

	let scanning = true;
	let foundConfig: any = null;
	let packageManager = 'npm';

	export let apiUrl: any;
	export let projectId: any;
	export let repository: any;
	export let branch: any;
	export let type: any;
	export let application: any;
	export let isPublicRepository: boolean;

	function checkPackageJSONContents({ key, json }: { key: any; json: any }) {
		return json?.dependencies?.hasOwnProperty(key) || json?.devDependencies?.hasOwnProperty(key);
	}
	function checkTemplates({ json, packageManager }: { json: any; packageManager: any }) {
		for (const [key, value] of Object.entries(scanningTemplates)) {
			if (checkPackageJSONContents({ key, json })) {
				foundConfig = findBuildPack(value.buildPack, packageManager);
				break;
			}
		}
	}
	async function scanRepository(): Promise<void> {
		try {
			if (type === 'gitlab') {
				const files = await get(`${apiUrl}/v4/projects/${projectId}/repository/tree`, {
					Authorization: `Bearer ${$appSession.tokens.gitlab}`
				});
				const packageJson = files.find(
					(file: { name: string; type: string }) =>
						file.name === 'package.json' && file.type === 'blob'
				);
				const yarnLock = files.find(
					(file: { name: string; type: string }) =>
						file.name === 'yarn.lock' && file.type === 'blob'
				);
				const pnpmLock = files.find(
					(file: { name: string; type: string }) =>
						file.name === 'pnpm-lock.yaml' && file.type === 'blob'
				);
				const dockerfile = files.find(
					(file: { name: string; type: string }) =>
						file.name === 'Dockerfile' && file.type === 'blob'
				);
				const cargoToml = files.find(
					(file: { name: string; type: string }) =>
						file.name === 'Cargo.toml' && file.type === 'blob'
				);
				const requirementsTxt = files.find(
					(file: { name: string; type: string }) =>
						file.name === 'requirements.txt' && file.type === 'blob'
				);
				const indexHtml = files.find(
					(file: { name: string; type: string }) =>
						file.name === 'index.html' && file.type === 'blob'
				);
				const indexPHP = files.find(
					(file: { name: string; type: string }) =>
						file.name === 'index.php' && file.type === 'blob'
				);
				const composerPHP = files.find(
					(file: { name: string; type: string }) =>
						file.name === 'composer.json' && file.type === 'blob'
				);
				const laravel = files.find(
					(file: { name: string; type: string }) => file.name === 'artisan' && file.type === 'blob'
				);

				if (yarnLock) packageManager = 'yarn';
				if (pnpmLock) packageManager = 'pnpm';

				if (dockerfile) {
					foundConfig = findBuildPack('docker', packageManager);
				} else if (packageJson && !laravel) {
					const path = packageJson.path;
					const data: any = await get(
						`${apiUrl}/v4/projects/${projectId}/repository/files/${path}/raw?ref=${branch}`,
						{
							Authorization: `Bearer ${$appSession.tokens.gitlab}`
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
				} else if ((indexPHP || composerPHP) && !laravel) {
					foundConfig = findBuildPack('php');
				} else if (laravel) {
					foundConfig = findBuildPack('laravel');
				} else {
					foundConfig = findBuildPack('node', packageManager);
				}
			} else if (type === 'github') {
				const files = await get(`${apiUrl}/repos/${repository}/contents?ref=${branch}`, {
					Authorization: `Bearer ${$appSession.tokens.github}`,
					Accept: 'application/vnd.github.v2.json'
				});
				const packageJson = files.find(
					(file: { name: string; type: string }) =>
						file.name === 'package.json' && file.type === 'file'
				);
				const yarnLock = files.find(
					(file: { name: string; type: string }) =>
						file.name === 'yarn.lock' && file.type === 'file'
				);
				const pnpmLock = files.find(
					(file: { name: string; type: string }) =>
						file.name === 'pnpm-lock.yaml' && file.type === 'file'
				);
				const dockerfile = files.find(
					(file: { name: string; type: string }) =>
						file.name === 'Dockerfile' && file.type === 'file'
				);
				const cargoToml = files.find(
					(file: { name: string; type: string }) =>
						file.name === 'Cargo.toml' && file.type === 'file'
				);
				const requirementsTxt = files.find(
					(file: { name: string; type: string }) =>
						file.name === 'requirements.txt' && file.type === 'file'
				);
				const indexHtml = files.find(
					(file: { name: string; type: string }) =>
						file.name === 'index.html' && file.type === 'file'
				);
				const indexPHP = files.find(
					(file: { name: string; type: string }) =>
						file.name === 'index.php' && file.type === 'file'
				);
				const composerPHP = files.find(
					(file: { name: string; type: string }) =>
						file.name === 'composer.json' && file.type === 'file'
				);
				const laravel = files.find(
					(file: { name: string; type: string }) => file.name === 'artisan' && file.type === 'file'
				);

				if (yarnLock) packageManager = 'yarn';
				if (pnpmLock) packageManager = 'pnpm';

				if (dockerfile) {
					foundConfig = findBuildPack('docker', packageManager);
				} else if (packageJson && !laravel) {
					const data: any = await get(`${packageJson.git_url}`, {
						Authorization: `Bearer ${$appSession.tokens.github}`,
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
				} else if ((indexPHP || composerPHP) && !laravel) {
					foundConfig = findBuildPack('php');
				} else if (laravel) {
					foundConfig = findBuildPack('laravel');
				} else {
					foundConfig = findBuildPack('node', packageManager);
				}
			}
		} catch (error: any) {
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
				const { token } = await get(`/applications/${id}/configuration/githubToken`);
				$appSession.tokens.github = token;
				return await scanRepository();
			}
			return errorNotification(error);
		} finally {
			if (!foundConfig) foundConfig = findBuildPack('node', packageManager);
			scanning = false;
		}
	}
	onMount(async () => {
		if (!isPublicRepository) {
			await scanRepository();
		} else {
			scanning = false;
		}
	});
</script>

<div class="flex space-x-1 p-6 font-bold lg:pt-32">
	<div class="mr-4 text-2xl tracking-tight">
		{$t('application.configuration.configure_build_pack')}
	</div>
</div>

{#if scanning}
	<div class="flex justify-center space-x-1 p-6 font-bold">
		<div class="text-xl tracking-tight">
			{$t('application.configuration.scanning_repository_suggest_build_pack')}
		</div>
	</div>
{:else}
	<div class="max-w-5xl mx-auto ">
		<div class="title pb-2">Coolify</div>
		<div class="flex flex-wrap justify-center">
			{#each buildPacks.filter((bp) => bp.isCoolifyBuildPack === true) as buildPack}
				<div class="p-2">
					<BuildPack {packageManager} {buildPack} {scanning} bind:foundConfig />
				</div>
			{/each}
		</div>
	</div>

	<div class="max-w-5xl mx-auto ">
		<div class="title pb-2">Other</div>
		<div class="flex flex-wrap justify-center">
			{#each buildPacks.filter((bp) => bp.isHerokuBuildPack === true) as buildPack}
				<div class="p-2">
					<BuildPack {packageManager} {buildPack} {scanning} bind:foundConfig />
				</div>
			{/each}
		</div>
	</div>
{/if}
