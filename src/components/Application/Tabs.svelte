<script>
	import { toast } from '@zerodevx/svelte-toast';
	import templates from '$lib/api/applications/packs/templates';
	import { application, dashboard, initConf, prApplication } from '$store';
	import General from '$components/Application/ActiveTab/General.svelte';
	import Secrets from '$components/Application/ActiveTab/Secrets.svelte';
	import Loading from '$components/Loading.svelte';
	import { goto } from '$app/navigation';
	import { page, session } from '$app/stores';
	import { request } from '$lib/request';
	import { browser } from '$app/env';
	import PullRequests from './ActiveTab/PullRequests.svelte';

	let activeTab = {
		general: true,
		secrets: false,
		pullRequests: false
	};
	function activateTab(tab) {
		if (activeTab.hasOwnProperty(tab)) {
			activeTab = {
				general: false,
				pullRequests: false,
				secrets: false
			};
			activeTab[tab] = true;
		}
	}
	async function load() {
		const found = $dashboard?.applications?.deployed.find((deployment) => {
			if (
				deployment.configuration.repository.organization === $application.repository.organization &&
				deployment.configuration.repository.name === $application.repository.name &&
				deployment.configuration.repository.branch === $application.repository.branch
			) {
				return deployment;
			}
		});
		if (found) {
			$application = { ...found.configuration };
			if ($page.path === '/application/new') {
				if (browser) {
					toast.push('This repository & branch is already defined. Redirecting...');
					goto(
						`/application/${$application.repository.organization}/${$application.repository.name}/${$application.repository.branch}/configuration`,
						{ replaceState: true }
					);
				}
			}
			return;
		}
		if ($page.path === '/application/new') {
			// 	const { configuration } = await request(`/api/v1/application/config`, $session, {
			// 		body: {
			// 			name: $application.repository.name,
			// 			organization: $application.repository.organization,
			// 			branch: $application.repository.branch
			// 		}
			// 	});
			// 	$prApplication = configuration.filter((c) => c.repository.pullRequest !== 0);
			// 	for (const config of configuration) {
			// 		if (config.repository.pullRequest === 0) {
			// 			$application = { ...config };
			// 			$initConf = JSON.parse(JSON.stringify($application));
			// 		}
			// 	}
			// } else {
			try {
				const dir = await request(
					`https://api.github.com/repos/${$application.repository.organization}/${$application.repository.name}/contents/?ref=${$application.repository.branch}`,
					$session
				);
				const packageJson = dir.find((f) => f.type === 'file' && f.name === 'package.json');
				const Dockerfile = dir.find((f) => f.type === 'file' && f.name === 'Dockerfile');
				const CargoToml = dir.find((f) => f.type === 'file' && f.name === 'Cargo.toml');
				const requirementsTXT = dir.find((f) => f.type === 'file' && f.name === 'requirements.txt');
				if (packageJson) {
					const { content } = await request(packageJson.git_url, $session);
					const packageJsonContent = JSON.parse(atob(content));
					const checkPackageJSONContents = (dep) => {
						return (
							packageJsonContent?.dependencies?.hasOwnProperty(dep) ||
							packageJsonContent?.devDependencies?.hasOwnProperty(dep)
						);
					};
					Object.keys(templates).map((dep) => {
						if (checkPackageJSONContents(dep)) {
							const config = templates[dep];
							$application.build.pack = config.pack;
							if (config.installation) {
								$application.build.command.installation = config.installation;
							}
							if (config.start) {
								$application.build.command.start = config.start;
							}
							if (config.port) {
								$application.publish.port = config.port;
							}
							if (config.directory) {
								$application.publish.directory = config.directory;
							}

							if (packageJsonContent.scripts.hasOwnProperty('build') && config.build) {
								$application.build.command.build = config.build;
							}
							browser && toast.push(`${config.name} detected. Default values set.`);
						}
					});
				} else if (CargoToml) {
					$application.build.pack = 'rust';
					browser && toast.push(`Rust language detected. Default values set.`);
				} else if (requirementsTXT) {
					$application.build.pack = 'python';
					browser && toast.push('Python language detected. Default values set.');
				} else if (Dockerfile) {
					$application.build.pack = 'docker';
					browser && toast.push('Custom Dockerfile found. Build pack set to docker.');
				}
			} catch (error) {
				// Nothing detected
			}
		}
	}

</script>

{#await load()}
	<Loading github githubLoadingText="Scanning repository..." />
{:then}
	<div class="block text-center py-8">
		<nav class="flex space-x-4 justify-center font-bold text-md text-white" aria-label="Tabs">
			<div
				on:click={() => activateTab('general')}
				class:text-green-500={activeTab.general}
				class="px-3 py-2 cursor-pointer hover:bg-warmGray-700 rounded-lg transition duration-100"
			>
				General
			</div>
			<div
				on:click={() => activateTab('secrets')}
				class:text-green-500={activeTab.secrets}
				class="px-3 py-2 cursor-pointer hover:bg-warmGray-700 rounded-lg transition duration-100"
			>
				Secrets
			</div>
			{#if $application.general.isPreviewDeploymentEnabled}
				<div
					on:click={() => activateTab('pullRequests')}
					class:text-green-500={activeTab.pullRequests}
					class="px-3 py-2 cursor-pointer hover:bg-warmGray-700 rounded-lg transition duration-100"
				>
					Pull Requests
				</div>
			{/if}
		</nav>
	</div>
	<div class="max-w-4xl mx-auto">
		<div class="h-full">
			{#if activeTab.general}
				<General />
			{:else if activeTab.secrets}
				<Secrets />
			{:else if activeTab.pullRequests && $page.path !== '/application/new'}
				<PullRequests />
			{/if}
		</div>
	</div>
{/await}
