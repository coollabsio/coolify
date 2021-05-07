<script lang="ts">
	import { goto } from '$app/navigation';
	import { request } from '$lib/fetch';
	import { page, session } from '$app/stores';
	import { dashboard, githubRepositories, application, githubInstallations } from '$store';
	import { onMount } from 'svelte';

	import { fade } from 'svelte/transition';
	import Loading from '$components/Loading.svelte';

	import { browser } from '$app/env';
	import Branches from '$components/Application/Branches.svelte';
	import Tabs from '$components/Application/Tabs.svelte';
	import Repositories from '$components/Application/Repositories.svelte';
	import Login from './Login.svelte';

	const found = $dashboard.applications.deployed.find(
		(c) =>
			c.configuration.repository.branch === $page.params.branch &&
			c.configuration.repository.organization === $page.params.organization &&
			c.configuration.repository.name === $page.params.name
	)?.configuration;
	console.log(found);
	if (found) {
		$application = found;
	} else {
		if ($page.path !== '/application/new') {
			goto('/dashboard/applications', { replaceState: true });
		}
	}

	let loading = {
		github: false,
		branches: false
	};
	let branches = [];
	let relogin = false;

	onMount(async () => {
		await loadGithubRepositories();
	});
	function dashify(str: string, options?: any) {
		if (typeof str !== 'string') return str;
		return str
			.trim()
			.replace(/\W/g, (m) => (/[À-ž]/.test(m) ? m : '-'))
			.replace(/^-+|-+$/g, '')
			.replace(/-{2,}/g, (m) => (options && options.condense ? '-' : m))
			.toLowerCase();
	}
	async function getGithubRepos(id, page) {
		return await request(
			`https://api.github.com/user/installations/${id}/repositories?per_page=100&page=${page}`,
			$session
		);
	}
	async function refreshTokens() {
		if (browser) {
			const left = screen.width / 2 - 1020 / 2;
			const top = screen.height / 2 - 618 / 2;
			const newWindow = open(
				`https://github.com/login/oauth/authorize?client_id=${
					import.meta.env.VITE_GITHUB_APP_CLIENTID
				}`,
				'Authenticate',
				'resizable=1, scrollbars=1, fullscreen=0, height=618, width=1020,top=' +
					top +
					', left=' +
					left +
					', toolbar=0, menubar=0, status=0'
			);
			const timer = setInterval(async () => {
				if (newWindow.closed) {
					clearInterval(timer);
					const coolToken = new URL(newWindow.document.URL).searchParams.get('coolToken');
					const ghToken = new URL(newWindow.document.URL).searchParams.get('ghToken');
					if (ghToken) {
						$session.githubAppToken = ghToken;
					}
					if (coolToken) {
						$session.isLoggedIn = true;
						$session.token = coolToken;
					}
					await loadGithubRepositories();
				}
			}, 100);
		}
	}
	async function loadGithubRepositories() {
		if ($githubRepositories.length > 0) {
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
				relogin = true;
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
				if (newWindow.closed) {
					clearInterval(timer);
					loading.github = true;

					try {
						const config = await request(`/api/v1/application/config`, $session, {
							body: {
								name: $application.repository.name,
								organization: $application.repository.organization,
								branch: $application.repository.branch
							}
						});
						$application = { ...config };
					} catch (error) {
						goto('/dashboard/applications', { replaceState: true });
					}

					branches = [];
					$githubRepositories = [];
					await loadGithubRepositories();
				}
			}, 100);
		}
	}
</script>

<div in:fade={{ duration: 100 }}>
	{#if relogin}
		<Login />
	{:else}
		{#await loadGithubRepositories()}
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
