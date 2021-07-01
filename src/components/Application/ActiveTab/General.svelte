<script>
	import { VITE_GITHUB_APP_NAME } from '$lib/consts';
	import { application, isPullRequestPermissionsGranted, originalDomain } from '$store';
	import { onMount } from 'svelte';
	import TooltipInfo from '$components/TooltipInfo.svelte';
	import { request } from '$lib/request';
	import { page, session } from '$app/stores';
	import { browser } from '$app/env';

	let domainInput;
	let loading = {
		previewDeployment: false
	};
	let howToActivate = false;
	const buildpacks = {
		static: {
			port: {
				active: false,
				number: 80
			},
			build: true,
			start: false
		},
		nodejs: {
			port: {
				active: true,
				number: 3000
			},
			build: true,
			start: true
		},
		nestjs: {
			port: {
				active: true,
				number: 3000
			},
			build: true,
			start: true
		},
		vuejs: {
			port: {
				active: false,
				number: 80
			},
			build: true,
			start: false
		},
		nuxtjs: {
			port: {
				active: true,
				number: 3000
			},
			build: true,
			start: true
		},
		react: {
			port: {
				active: false,
				number: 80
			},
			build: true,
			start: false
		},
		nextjs: {
			port: {
				active: true,
				number: 3000
			},
			build: true,
			start: true
		},
		gatsby: {
			port: {
				active: true,
				number: 3000
			},
			build: true,
			start: false
		},
		svelte: {
			port: {
				active: false,
				number: 80
			},
			build: true,
			start: false
		},
		php: {
			port: {
				active: false,
				number: 80
			},
			build: false,
			start: false
		},
		rust: {
			port: {
				active: true,
				number: 3000
			},
			build: false,
			start: false
		},
		docker: {
			port: {
				active: true,
				number: 3000
			},
			build: false,
			start: false
		},
		python: {
			port: {
				active: true,
				number: 4000
			},
			build: false,
			start: false,
			custom: true
		}
	};
	async function setPreviewDeployment() {
		if ($application.general.isPreviewDeploymentEnabled) {
			const result = window.confirm(
				"DANGER ZONE! It will delete all PR deployments. It's NOT reversible! Are you sure?"
			);
			if (result) {
				loading.previewDeployment = true;
				$application.general.isPreviewDeploymentEnabled =
					!$application.general.isPreviewDeploymentEnabled;
				if ($page.path !== '/application/new') {
					const config = await request(`/api/v1/application/config/previewDeployment`, $session, {
						body: {
							name: $application.repository.name,
							organization: $application.repository.organization,
							branch: $application.repository.branch,
							isPreviewDeploymentEnabled: $application.general.isPreviewDeploymentEnabled
						}
					});
				}
				loading.previewDeployment = false;
			}
		} else {
			loading.previewDeployment = true;
			$application.general.isPreviewDeploymentEnabled =
				!$application.general.isPreviewDeploymentEnabled;
			$application.general.pullRequest = 0;
			if ($page.path !== '/application/new') {
				const config = await request(`/api/v1/application/config/previewDeployment`, $session, {
					body: {
						name: $application.repository.name,
						organization: $application.repository.organization,
						branch: $application.repository.branch,
						isPreviewDeploymentEnabled: $application.general.isPreviewDeploymentEnabled
					}
				});
			}
			loading.previewDeployment = false;
		}
	}
	function selectBuildPack(event) {
		if (event.target.innerText === 'React/Preact') {
			$application.build.pack = 'react';
		} else {
			$application.build.pack = event.target.innerText.replace(/\./g, '').toLowerCase();
		}
	}
	async function openGithub() {
		if (browser) {
			const config = await request(`https://api.github.com/apps/${VITE_GITHUB_APP_NAME}`, $session);

			let url = `https://github.com/settings/apps/${VITE_GITHUB_APP_NAME}/permissions`;
			if (config.owner.type === 'Organization') {
				url = `https://github.com/organizations/${config.owner.login}/settings/apps/${VITE_GITHUB_APP_NAME}/permissions`;
			}

			const left = screen.width / 2 - 1020 / 2;
			const top = screen.height / 2 - 618 / 2;
			const newWindow = open(
				url,
				'Permission Update',
				'resizable=1, scrollbars=1, fullscreen=1, height=1000, width=1220,top=' +
					top +
					', left=' +
					left +
					', toolbar=0, menubar=0, status=0'
			);
			const timer = setInterval(async () => {
				if (newWindow?.closed) {
					clearInterval(timer);
					location.reload();
				}
			}, 100);
		}
	}
	onMount(() => {
		if (!$application.publish.domain) {
			domainInput.focus();
		} else {
			$originalDomain = $application.publish.domain;
		}
	});
</script>

<div>
	<div class="grid grid-cols-1 text-sm max-w-4xl md:mx-auto mx-6 pb-16 auto-cols-max ">
		<div class="text-2xl font-bold border-gradient w-40">Build Packs</div>
		<div class="flex font-bold flex-wrap justify-center pt-10">
			<div
				class={$application.build.pack === 'static'
					? 'buildpack bg-red-500'
					: 'buildpack hover:border-red-500'}
				on:click={selectBuildPack}
			>
				Static
			</div>
			<div
				class={$application.build.pack === 'nodejs'
					? 'buildpack bg-emerald-600'
					: 'buildpack hover:border-emerald-600'}
				on:click={selectBuildPack}
			>
				NodeJS
			</div>
			<div
				class={$application.build.pack === 'vuejs'
					? 'buildpack bg-green-500'
					: 'buildpack hover:border-green-500'}
				on:click={selectBuildPack}
			>
				VueJS
			</div>
			<div
				class={$application.build.pack === 'nuxtjs'
					? 'buildpack bg-green-500'
					: 'buildpack hover:border-green-500'}
				on:click={selectBuildPack}
			>
				NuxtJS
			</div>
			<div
				class={$application.build.pack === 'react'
					? 'buildpack bg-gradient-to-r from-blue-500 to-purple-500'
					: 'buildpack hover:border-blue-500'}
				on:click={selectBuildPack}
			>
				React/Preact
			</div>
			<div
				class={$application.build.pack === 'nextjs'
					? 'buildpack bg-blue-500'
					: 'buildpack hover:border-blue-500'}
				on:click={selectBuildPack}
			>
				NextJS
			</div>
			<div
				class={$application.build.pack === 'gatsby'
					? 'buildpack bg-blue-500'
					: 'buildpack hover:border-blue-500'}
				on:click={selectBuildPack}
			>
				Gatsby
			</div>
			<div
				class={$application.build.pack === 'svelte'
					? 'buildpack bg-orange-600'
					: 'buildpack hover:border-orange-600'}
				on:click={selectBuildPack}
			>
				Svelte
			</div>
			<div
				class={$application.build.pack === 'php'
					? 'buildpack bg-indigo-500'
					: 'buildpack hover:border-indigo-500'}
				on:click={selectBuildPack}
			>
				PHP
			</div>
			<div
				class={$application.build.pack === 'rust'
					? 'buildpack bg-pink-500'
					: 'buildpack hover:border-pink-500'}
				on:click={selectBuildPack}
			>
				Rust
			</div>
			<div
				class={$application.build.pack === 'nestjs'
					? 'buildpack bg-red-500'
					: 'buildpack hover:border-red-500'}
				on:click={selectBuildPack}
			>
				NestJS
			</div>
			<div
				class={$application.build.pack === 'docker'
					? 'buildpack bg-purple-500'
					: 'buildpack hover:border-purple-500'}
				on:click={selectBuildPack}
			>
				Docker
			</div>
			<div
				class={$application.build.pack === 'python'
					? 'buildpack bg-green-500'
					: 'buildpack hover:border-green-500'}
				on:click={selectBuildPack}
			>
				Python
			</div>
		</div>
	</div>
	<div class="text-2xl font-bold border-gradient w-52">General settings</div>
	<div class="grid grid-cols-1 max-w-2xl md:mx-auto mx-6 justify-center items-center pt-10">
		<div>
			<ul class="divide-y divide-warmGray-800">
				<li class="pb-6 flex items-center justify-between">
					<div class="flex flex-col">
						<p class="text-base font-bold text-warmGray-100">Preview deployments</p>
						<p class="text-sm font-medium text-warmGray-400">
							PR's will be deployed so you could review them easily
						</p>
					</div>

					{#if $isPullRequestPermissionsGranted}
						<div
							class="relative"
							class:animate-wiggle={loading.previewDeployment}
							class:opacity-25={loading.previewDeployment}
						>
							<button
								type="button"
								disabled={loading.previewDeployment}
								on:click={setPreviewDeployment}
								aria-pressed="false"
								class="relative inline-flex flex-shrink-0 h-6 w-11 border-2 border-transparent rounded-full cursor-pointer transition-colors ease-in-out duration-200"
								class:bg-green-600={$application.general.isPreviewDeploymentEnabled}
								class:bg-warmGray-700={!$application.general.isPreviewDeploymentEnabled}
								class:cursor-not-allowed={loading.previewDeployment}
							>
								<span class="sr-only">Use setting</span>
								<span
									class="pointer-events-none relative inline-block h-5 w-5 rounded-full bg-white shadow transition ease-in-out duration-200 transform"
									class:translate-x-5={$application.general.isPreviewDeploymentEnabled}
									class:translate-x-0={!$application.general.isPreviewDeploymentEnabled}
								>
									<span
										class=" ease-in duration-200 absolute inset-0 h-full w-full flex items-center justify-center transition-opacity"
										class:opacity-0={$application.general.isPreviewDeploymentEnabled}
										class:opacity-100={!$application.general.isPreviewDeploymentEnabled}
										aria-hidden="true"
									>
										<svg class="bg-white h-3 w-3 text-red-600" fill="none" viewBox="0 0 12 12">
											<path
												d="M4 8l2-2m0 0l2-2M6 6L4 4m2 2l2 2"
												stroke="currentColor"
												stroke-width="2"
												stroke-linecap="round"
												stroke-linejoin="round"
											/>
										</svg>
									</span>
									<span
										class="ease-out duration-100 absolute inset-0 h-full w-full flex items-center justify-center transition-opacity"
										aria-hidden="true"
										class:opacity-100={$application.general.isPreviewDeploymentEnabled}
										class:opacity-0={!$application.general.isPreviewDeploymentEnabled}
									>
										<svg
											class="bg-white h-3 w-3 text-green-600"
											fill="currentColor"
											viewBox="0 0 12 12"
										>
											<path
												d="M3.707 5.293a1 1 0 00-1.414 1.414l1.414-1.414zM5 8l-.707.707a1 1 0 001.414 0L5 8zm4.707-3.293a1 1 0 00-1.414-1.414l1.414 1.414zm-7.414 2l2 2 1.414-1.414-2-2-1.414 1.414zm3.414 2l4-4-1.414-1.414-4 4 1.414 1.414z"
											/>
										</svg>
									</span>
								</span>
							</button>
							{#if loading.previewDeployment}
								<div class="absolute left-0 bottom-0 -mb-4 -ml-2 text-xs font-bold">
									{$application.general.isPreviewDeploymentEnabled ? 'Enabling...' : 'Disabling...'}
								</div>
							{/if}
						</div>
					{:else}
						<div class="relative">
							{#if !howToActivate}
								<button
									class="button py-2 px-2 bg-warmGray-800 hover:bg-warmGray-700 text-white"
									on:click={() => (howToActivate = !howToActivate)}>How to active this?</button
								>
							{:else}
								<button
									class="button py-2 px-2 bg-green-600 hover:bg-green-500 text-white"
									on:click={openGithub}>Open Github</button
								>
							{/if}
							{#if howToActivate}
								<div class="absolute right-0 w-64 z-10">
									<div class="bg-warmGray-800 p-4 my-2 rounded text-white">
										<div
											class="absolute right-0 top-0 p-2 my-3 mx-1 text-xs font-bold cursor-pointer hover:bg-warmGray-700"
											on:click={() => (howToActivate = false)}
										>
											X
										</div>
										<p class="text-sm font-medium text-warmGray-400">
											You need to add <span class="text-white">two new permissions</span> to your GitHub
											App:
										</p>
										<br />
										<p class="text-sm font-medium text-warmGray-400">
											1. In <span class="text-white">Repository permissions</span>, add
											<span class="text-white">Read-only</span>
											access to <span class="text-white">Pull requests</span>.
										</p>
										<br />
										<p class="text-sm font-medium text-warmGray-400">
											2. In <span class="text-white">Subscribe to events</span> section,
											<span class="text-white"> check Pull request</span> field.
										</p>
										<br />
										<p class="text-sm font-medium text-warmGray-400">
											3. You <span class="text-white">receive an email</span> where you need to
											<span class="text-white">accept the new permissions</span>.
										</p>
									</div>
								</div>
							{/if}
						</div>
					{/if}
				</li>
			</ul>
		</div>

		<div class="grid grid-flow-col gap-2 items-center pb-6">
			<div class="grid grid-flow-row">
				<label for="Domain" class="">Domain</label>
				<input
					bind:this={domainInput}
					class="border-2"
					disabled={$page.path !== '/application/new'}
					class:cursor-not-allowed={$page.path !== '/application/new'}
					class:bg-warmGray-900={$page.path !== '/application/new'}
					class:hover:bg-warmGray-900={$page.path !== '/application/new'}
					class:placeholder-red-500={$application.publish.domain == null ||
						$application.publish.domain == ''}
					class:border-red-500={$application.publish.domain == null ||
						$application.publish.domain == ''}
					id="Domain"
					bind:value={$application.publish.domain}
					placeholder="eg: coollabs.io (without www)"
				/>
			</div>
			<div class="grid grid-flow-row">
				<label for="Path"
					>Path <TooltipInfo
						label={`Path to deploy your application on your domain. eg: /api means it will be deployed to -> https://${
							$application.publish.domain || '<yourdomain>'
						}/api`}
					/></label
				>
				<input
					id="Path"
					bind:value={$application.publish.path}
					disabled={$page.path !== '/application/new'}
					class:cursor-not-allowed={$page.path !== '/application/new'}
					class:bg-warmGray-900={$page.path !== '/application/new'}
					class:hover:bg-warmGray-900={$page.path !== '/application/new'}
					placeholder="/"
				/>
			</div>
		</div>
		<label for="Port" class:text-warmGray-800={!buildpacks[$application.build.pack].port.active}
			>Port</label
		>
		<input
			disabled={!buildpacks[$application.build.pack].port.active}
			id="Port"
			class:bg-warmGray-900={!buildpacks[$application.build.pack].port.active}
			class:text-warmGray-900={!buildpacks[$application.build.pack].port.active}
			class:placeholder-warmGray-800={!buildpacks[$application.build.pack].port.active}
			class:hover:bg-warmGray-900={!buildpacks[$application.build.pack].port.active}
			class:cursor-not-allowed={!buildpacks[$application.build.pack].port.active}
			bind:value={$application.publish.port}
			placeholder={buildpacks[$application.build.pack].port.number}
		/>
		<div class="grid grid-flow-col gap-2 items-center pt-6 pb-12">
			<div class="grid grid-flow-row">
				<label for="baseDir"
					>Base Directory <TooltipInfo
						label="The directory to use as base for every command (could be useful if you have a monorepo)."
					/></label
				>
				<input id="baseDir" bind:value={$application.build.directory} placeholder="eg: sourcedir" />
			</div>
			<div class="grid grid-flow-row">
				<label for="publishDir"
					>Publish Directory <TooltipInfo
						label="The directory to deploy after running the build command.  eg: dist, _site, public."
					/></label
				>
				<input
					id="publishDir"
					bind:value={$application.publish.directory}
					placeholder="eg: dist, _site, public"
				/>
			</div>
		</div>
	</div>
	<div class="text-2xl font-bold w-40 border-gradient">Commands</div>
	<div class=" max-w-2xl md:mx-auto mx-6 justify-center items-center pt-10 pb-32">
		<div class="grid grid-flow-col gap-2 items-center">
			<div class="grid grid-flow-row">
				{#if $application.build.pack === 'python'}
					<label for="ModulePackageName"
						>Module/Package Name<TooltipInfo
							label="The module/package name to start (eg: the entry filename [main], without the py extension. See gunicorn.org for more details)"
						/>
					</label>

					<input
						class="mb-6"
						id="ModulePackageName"
						bind:value={$application.build.command.python.module}
						placeholder="main"
					/>
					<label for="ApplicationInstance"
						>Application Instance<TooltipInfo
							label="The instance name (the main function name. See gunicorn.org for more details)"
						/>
					</label>

					<input
						class="mb-6"
						id="ApplicationInstance"
						bind:value={$application.build.command.python.instance}
						placeholder="app"
					/>
				{:else}
					<label
						for="installCommand"
						class:text-warmGray-800={!buildpacks[$application.build.pack].build}
						>Install Command <TooltipInfo
							label="Command to run for installing dependencies. eg: yarn install"
						/>
					</label>

					<input
						class="mb-6"
						class:bg-warmGray-900={!buildpacks[$application.build.pack].build}
						class:text-warmGray-900={!buildpacks[$application.build.pack].build}
						class:placeholder-warmGray-800={!buildpacks[$application.build.pack].build}
						class:hover:bg-warmGray-900={!buildpacks[$application.build.pack].build}
						class:cursor-not-allowed={!buildpacks[$application.build.pack].build}
						id="installCommand"
						bind:value={$application.build.command.installation}
						placeholder="eg: yarn install"
					/>
					<label
						for="buildCommand"
						class:text-warmGray-800={!buildpacks[$application.build.pack].build}
						>Build Command <TooltipInfo
							label="Command to run for building your application. If empty, no build phase initiated in the deploy process."
						/></label
					>
					<input
						class="mb-6"
						class:bg-warmGray-900={!buildpacks[$application.build.pack].build}
						class:text-warmGray-900={!buildpacks[$application.build.pack].build}
						class:placeholder-warmGray-800={!buildpacks[$application.build.pack].build}
						class:hover:bg-warmGray-900={!buildpacks[$application.build.pack].build}
						class:cursor-not-allowed={!buildpacks[$application.build.pack].build}
						id="buildCommand"
						bind:value={$application.build.command.build}
						placeholder="eg: yarn build"
					/>
					<label
						for="startCommand"
						class:text-warmGray-800={!buildpacks[$application.build.pack].start}
						>Start Command <TooltipInfo
							label="Command to start the application. eg: yarn start"
						/></label
					>
					<input
						class="mb-6"
						class:bg-warmGray-900={!buildpacks[$application.build.pack].start}
						class:text-warmGray-900={!buildpacks[$application.build.pack].start}
						class:placeholder-warmGray-800={!buildpacks[$application.build.pack].start}
						class:hover:bg-warmGray-900={!buildpacks[$application.build.pack].start}
						class:cursor-not-allowed={!buildpacks[$application.build.pack].start}
						id="startcommand"
						bind:value={$application.build.command.start}
						placeholder="eg: yarn start"
					/>
				{/if}
			</div>
		</div>
	</div>
</div>

<style lang="postcss">
	.buildpack {
		@apply px-6 py-2 mx-2 my-2 bg-warmGray-800 w-48 ease-in-out hover:scale-105 text-center rounded border-2 border-transparent border-dashed cursor-pointer transition duration-100;
	}
</style>
