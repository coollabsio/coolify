<script lang="ts">
	import { goto } from '$app/navigation';
	import { request } from '$lib/request';
	import { session } from '$app/stores';
	import {
		githubRepositories,
		application,
		githubInstallations,
		prApplication,
		initConf,
		isPullRequestPermissionsGranted
	} from '$store';

	import { fade } from 'svelte/transition';
	import Loading from '$components/Loading.svelte';

	import { browser } from '$app/env';
	import Branches from '$components/Application/Branches.svelte';
	import Tabs from '$components/Application/Tabs.svelte';
	import Repositories from '$components/Application/Repositories.svelte';
	import Login from '$components/Application/Login.svelte';
import { dashify } from '$lib/common';
	let loading = {
		github: false,
		branches: false
	};
	let branches = [];
	let relogin = false;
	let permissions = {};

	async function getGithubRepos(id, page) {
		return await request(
			`https://api.github.com/user/installations/${id}/repositories?per_page=100&page=${page}`,
			$session
		);
	}
	async function loadGithubRepositories(force) {
		if ($githubRepositories.length > 0 && !force) {
			$application.github.installation.id = $githubInstallations.id;
			$application.github.app.id = $githubInstallations.app_id;
			const foundRepositoryOnGithub = $githubRepositories.find(
				(r) =>
					r.full_name === `${$application.repository.organization}/${$application.repository.name}`
			);

			if (foundRepositoryOnGithub) {
				$application.repository.id = foundRepositoryOnGithub.id;
				$application.repository.organization = foundRepositoryOnGithub.owner.login;
				$application.repository.name = foundRepositoryOnGithub.name;
			}
			return;
		} else {
			loading.github = true;
			let installations = [];
			try {
				const data = await request('https://api.github.com/user/installations', $session);
				installations = data.installations;
			} catch (error) {
				relogin = true;
				console.log(error);
				return false;
			}
			if (installations.length === 0) {
				loading.github = false;
				return false;
			}
			$application.github.installation.id = installations[0].id;
			$application.github.app.id = installations[0].app_id;
			$githubInstallations = installations[0];

			try {
				let page = 1;
				let userRepos = 0;
				const data = await getGithubRepos($application.github.installation.id, page);
				$githubRepositories = $githubRepositories.concat(data.repositories);
				userRepos = data.total_count;
				if (userRepos > $githubRepositories.length) {
					while (userRepos > $githubRepositories.length) {
						page = page + 1;
						const repos = await getGithubRepos($application.github.installation.id, page);
						$githubRepositories = $githubRepositories.concat(repos.repositories);
					}
				}
				const foundRepositoryOnGithub = $githubRepositories.find(
					(r) =>
						r.full_name ===
						`${$application.repository.organization}/${$application.repository.name}`
				);
				if (foundRepositoryOnGithub) {
					$application.repository.id = foundRepositoryOnGithub.id;
					await loadBranches();
				}
			} catch (error) {
				return false;
			} finally {
				loading.github = false;
			}
		}
	}
	async function loadBranches() {
		loading.branches = true;
		const selectedRepository = $githubRepositories.find((r) => r.id === $application.repository.id);

		if (selectedRepository) {
			$application.repository.organization = selectedRepository.owner.login;
			$application.repository.name = selectedRepository.name;
		}

		branches = await request(
			`https://api.github.com/repos/${$application.repository.organization}/${$application.repository.name}/branches`,
			$session
		);
		loading.branches = false;
		await loadPermissions();
	}
	async function loadPermissions() {
		const config = await request(
			`https://api.github.com/apps/${dashify(import.meta.env.VITE_GITHUB_APP_NAME)}`,
			$session
		);
		if (config.permissions['pull_requests'] && config.events.includes('pull_request')) {
			$isPullRequestPermissionsGranted = true;
		}
	}
	async function modifyGithubAppConfig() {
		if (browser) {
			const left = screen.width / 2 - 1020 / 2;
			const top = screen.height / 2 - 618 / 2;
			const newWindow = open(
				`https://github.com/apps/${dashify(
					import.meta.env.VITE_GITHUB_APP_NAME
				)}/installations/new`,
				'Install App',
				'resizable=1, scrollbars=1, fullscreen=0, height=1000, width=1020,top=' +
					top +
					', left=' +
					left +
					', toolbar=0, menubar=0, status=0'
			);
			const timer = setInterval(async () => {
				if (newWindow?.closed) {
					clearInterval(timer);
					loading.github = true;

					if ($application.repository.name) {
						try {
							const { configuration } = await request(`/api/v1/application/config`, $session, {
								body: {
									name: $application.repository.name,
									organization: $application.repository.organization,
									branch: $application.repository.branch
								}
							});

							$prApplication = configuration.filter((c) => c.general.pullRequest !== 0);
							$application = configuration.find((c) => c.general.pullRequest === 0);
							$initConf = JSON.parse(JSON.stringify($application));
						} catch (error) {
							browser && goto('/dashboard/applications', { replaceState: true });
						}
					}

					branches = [];
					$githubRepositories = [];
					await loadGithubRepositories(true);
				}
			}, 100);
		}
	}
</script>

<div in:fade={{ duration: 100 }}>
	{#if relogin}
		<Login />
	{:else}
		{#await loadGithubRepositories(false)}
			<Loading github githubLoadingText="Loading repositories..." />
		{:then}
			{#if loading.github}
				<Loading github githubLoadingText="Loading repositories..." />
			{:else}
				<div class="space-y-2 max-w-4xl mx-auto px-6" in:fade={{ duration: 100 }}>
					<Repositories
						on:loadBranches={loadBranches}
						on:modifyGithubAppConfig={modifyGithubAppConfig}
					/>
					{#if $application.repository.organization}
						<Branches loading={loading.branches} {branches} />
					{/if}

					{#if $application.repository.branch}
						<Tabs />
					{/if}
				</div>
			{/if}
		{/await}
	{/if}
</div>
