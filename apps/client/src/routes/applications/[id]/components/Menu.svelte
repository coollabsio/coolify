<script lang="ts">
	export let application: any;
	import { status } from '$lib/store';
	import { page } from '$app/stores';
	import * as Icons from '$lib/components/icons';
</script>

<ul class="menu border bg-coolgray-100 border-coolgray-200 rounded p-2 space-y-2 sticky top-4">
	<li class="menu-title">
		<span>General</span>
	</li>
	{#if application.gitSource?.htmlUrl && application.repository && application.branch}
		<li>
			<a
				id="git"
				href="{application.gitSource.htmlUrl}/{application.repository}/tree/{application.branch}"
				target="_blank noreferrer"
				class="no-underline"
			>
				{#if application.gitSource?.type === 'gitlab'}
					<Icons.Sources.GitHub small={true} />
				{:else if application.gitSource?.type === 'github'}
					<Icons.Sources.GitLab small={true} />
				{/if}
				Open on Git <Icons.RemoteLink />
			</a>
		</li>
	{/if}

	<li class="rounded" class:bg-coollabs={$page.url.pathname === `/applications/${$page.params.id}`}>
		<a href={`/applications/${$page.params.id}`} class="no-underline w-full"
			><svg
				xmlns="http://www.w3.org/2000/svg"
				class="w-6 h-6"
				viewBox="0 0 24 24"
				stroke-width="1.5"
				stroke="currentColor"
				fill="none"
				stroke-linecap="round"
				stroke-linejoin="round"
			>
				<path stroke="none" d="M0 0h24v24H0z" fill="none" />
				<path
					d="M7 10h3v-3l-3.5 -3.5a6 6 0 0 1 8 8l6 6a2 2 0 0 1 -3 3l-6 -6a6 6 0 0 1 -8 -8l3.5 3.5"
				/>
			</svg>Configuration</a
		>
	</li>
	<li
		class="rounded"
		class:bg-coollabs={$page.url.pathname === `/applications/${$page.params.id}/secrets`}
	>
		<a href={`/applications/${$page.params.id}/secrets`} class="no-underline w-full"
			><svg
				xmlns="http://www.w3.org/2000/svg"
				class="w-6 h-6"
				viewBox="0 0 24 24"
				stroke-width="1.5"
				stroke="currentColor"
				fill="none"
				stroke-linecap="round"
				stroke-linejoin="round"
			>
				<path stroke="none" d="M0 0h24v24H0z" fill="none" />
				<path
					d="M12 3a12 12 0 0 0 8.5 3a12 12 0 0 1 -8.5 15a12 12 0 0 1 -8.5 -15a12 12 0 0 0 8.5 -3"
				/>
				<circle cx="12" cy="11" r="1" />
				<line x1="12" y1="12" x2="12" y2="14.5" />
			</svg>Secrets</a
		>
	</li>
	<li
		class="rounded"
		class:bg-coollabs={$page.url.pathname === `/applications/${$page.params.id}/storages`}
	>
		<a href={`/applications/${$page.params.id}/storages`} class="no-underline w-full"
			><svg
				xmlns="http://www.w3.org/2000/svg"
				class="w-6 h-6"
				viewBox="0 0 24 24"
				stroke-width="1.5"
				stroke="currentColor"
				fill="none"
				stroke-linecap="round"
				stroke-linejoin="round"
			>
				<path stroke="none" d="M0 0h24v24H0z" fill="none" />
				<ellipse cx="12" cy="6" rx="8" ry="3" />
				<path d="M4 6v6a8 3 0 0 0 16 0v-6" />
				<path d="M4 12v6a8 3 0 0 0 16 0v-6" />
			</svg>Persistent Volumes</a
		>
	</li>
	{#if !application.simpleDockerfile}
		<li
			class="rounded"
			class:bg-coollabs={$page.url.pathname === `/applications/${$page.params.id}/features`}
		>
			<a href={`/applications/${$page.params.id}/features`} class="no-underline w-full"
				><svg
					xmlns="http://www.w3.org/2000/svg"
					class="w-6 h-6"
					viewBox="0 0 24 24"
					stroke-width="1.5"
					stroke="currentColor"
					fill="none"
					stroke-linecap="round"
					stroke-linejoin="round"
				>
					<path stroke="none" d="M0 0h24v24H0z" fill="none" />
					<polyline points="13 3 13 10 19 10 11 21 11 14 5 14 13 3" />
				</svg>Features</a
			>
		</li>
	{/if}

	<li class="menu-title">
		<span>Logs</span>
	</li>
	<li
		class:text-stone-600={$status.application.overallStatus === 'stopped'}
		class="rounded"
		class:bg-coollabs={$page.url.pathname === `/applications/${$page.params.id}/logs`}
	>
		<a
			href={$status.application.overallStatus !== 'stopped'
				? `/applications/${$page.params.id}/logs`
				: ''}
			class="no-underline w-full"
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
				<path d="M3 19a9 9 0 0 1 9 0a9 9 0 0 1 9 0" />
				<path d="M3 6a9 9 0 0 1 9 0a9 9 0 0 1 9 0" />
				<line x1="3" y1="6" x2="3" y2="19" />
				<line x1="12" y1="6" x2="12" y2="19" />
				<line x1="21" y1="6" x2="21" y2="19" />
			</svg>Application</a
		>
	</li>
	<li
		class="rounded"
		class:bg-coollabs={$page.url.pathname === `/applications/${$page.params.id}/builds`}
	>
		<a href={`/applications/${$page.params.id}/builds`} class="no-underline w-full"
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
				<circle cx="19" cy="13" r="2" />
				<circle cx="4" cy="17" r="2" />
				<circle cx="13" cy="17" r="2" />
				<line x1="13" y1="19" x2="4" y2="19" />
				<line x1="4" y1="15" x2="13" y2="15" />
				<path d="M8 12v-5h2a3 3 0 0 1 3 3v5" />
				<path d="M5 15v-2a1 1 0 0 1 1 -1h7" />
				<path d="M19 11v-7l-6 7" />
			</svg>Build</a
		>
	</li>
	<li class="menu-title">
		<span>Advanced</span>
	</li>
	{#if application.gitSourceId}
		<li
			class="rounded"
			class:bg-coollabs={$page.url.pathname === `/applications/${$page.params.id}/revert`}
		>
			<a href={`/applications/${$page.params.id}/revert`} class="no-underline w-full">
				<svg
					xmlns="http://www.w3.org/2000/svg"
					class="w-6 h-6"
					viewBox="0 0 24 24"
					stroke-width="1.5"
					stroke="currentColor"
					fill="none"
					stroke-linecap="round"
					stroke-linejoin="round"
				>
					<path stroke="none" d="M0 0h24v24H0z" fill="none" />
					<path d="M20 5v14l-12 -7z" />
					<line x1="4" y1="5" x2="4" y2="19" />
				</svg>
				Revert</a
			>
		</li>
	{/if}
	<li
		class="rounded"
		class:text-stone-600={$status.application.overallStatus !== 'healthy'}
		class:bg-coollabs={$page.url.pathname === `/applications/${$page.params.id}/usage`}
	>
		<a
			href={$status.application.overallStatus === 'healthy'
				? `/applications/${$page.params.id}/usage`
				: ''}
			class="no-underline w-full"
			><svg
				xmlns="http://www.w3.org/2000/svg"
				class="w-6 h-6"
				viewBox="0 0 24 24"
				stroke-width="1.5"
				stroke="currentColor"
				fill="none"
				stroke-linecap="round"
				stroke-linejoin="round"
			>
				<path stroke="none" d="M0 0h24v24H0z" fill="none" />
				<path d="M3 12h4l3 8l4 -16l3 8h4" />
			</svg>Monitoring</a
		>
	</li>
	{#if !application.settings.isBot && application.gitSourceId}
		<li
			class="rounded"
			class:bg-coollabs={$page.url.pathname === `/applications/${$page.params.id}/previews`}
		>
			<a href={`/applications/${$page.params.id}/previews`} class="no-underline w-full"
				><svg
					xmlns="http://www.w3.org/2000/svg"
					class="w-6 h-6"
					viewBox="0 0 24 24"
					stroke-width="1.5"
					stroke="currentColor"
					fill="none"
					stroke-linecap="round"
					stroke-linejoin="round"
				>
					<path stroke="none" d="M0 0h24v24H0z" fill="none" />
					<circle cx="7" cy="18" r="2" />
					<circle cx="7" cy="6" r="2" />
					<circle cx="17" cy="12" r="2" />
					<line x1="7" y1="8" x2="7" y2="16" />
					<path d="M7 8a4 4 0 0 0 4 4h4" />
				</svg>Preview Deployments</a
			>
		</li>
	{/if}
	<li
		class="rounded"
		class:bg-coollabs={$page.url.pathname === `/applications/${$page.params.id}/danger`}
	>
		<a href={`/applications/${$page.params.id}/danger`} class="no-underline w-full"
			><svg
				xmlns="http://www.w3.org/2000/svg"
				class="w-6 h-6"
				viewBox="0 0 24 24"
				stroke-width="1.5"
				stroke="currentColor"
				fill="none"
				stroke-linecap="round"
				stroke-linejoin="round"
			>
				<path stroke="none" d="M0 0h24v24H0z" fill="none" />
				<path d="M12 9v2m0 4v.01" />
				<path
					d="M5 19h14a2 2 0 0 0 1.84 -2.75l-7.1 -12.25a2 2 0 0 0 -3.5 0l-7.1 12.25a2 2 0 0 0 1.75 2.75"
				/>
			</svg>Danger Zone</a
		>
	</li>
</ul>
