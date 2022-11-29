<script lang="ts">
	import { goto } from '$app/navigation';
	import { post } from '$lib/api';

	async function newApplication() {
		const { id } = await post('/applications/new', {});
		return await goto(`/applications/${id}`, { replaceState: true });
	}
	async function newService() {
		const { id } = await post('/services/new', {});
		return await goto(`/services/${id}`, { replaceState: true });
	}
	async function newDatabase() {
		const { id } = await post('/databases/new', {});
		return await goto(`/databases/${id}`, { replaceState: true });
	}
</script>

<!-- svelte-ignore a11y-no-noninteractive-tabindex -->
<div class="dropdown dropdown-bottom">
	<slot>
		<label for="new" tabindex="0" class="btn btn-sm text-sm bg-coollabs hover:bg-coollabs-100 w-64">
			<svg
				class="h-6 w-6"
				xmlns="http://www.w3.org/2000/svg"
				fill="none"
				viewBox="0 0 24 24"
				stroke="currentColor"
				><path
					stroke-linecap="round"
					stroke-linejoin="round"
					stroke-width="2"
					d="M12 6v6m0 0v6m0-6h6m-6 0H6"
				/></svg
			> Create New Resource
		</label>
	</slot>

	<ul id="new" tabindex="0" class="dropdown-content menu p-2 shadow bg-coolgray-300 rounded w-64">
		<li>
			<button on:click={newApplication} class="no-underline hover:bg-applications tracking-wide font-bold">
				<svg
					xmlns="http://www.w3.org/2000/svg"
					class="h-6 w-6"
					viewBox="0 0 24 24"
					stroke-width="1.5"
					stroke="currentcolor"
					fill="none"
					stroke-linecap="round"
					stroke-linejoin="round"
				>
					<path stroke="none" d="M0 0h24v24H0z" fill="none" />
					<rect x="4" y="4" width="6" height="6" rx="1" />
					<rect x="4" y="14" width="6" height="6" rx="1" />
					<rect x="14" y="14" width="6" height="6" rx="1" />
					<line x1="14" y1="7" x2="20" y2="7" />
					<line x1="17" y1="4" x2="17" y2="10" />
				</svg>Application</button
			>
		</li>
		<li>
			<button on:click={newService} class="no-underline hover:bg-services tracking-wide font-bold">
				<svg
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
					<path d="M7 18a4.6 4.4 0 0 1 0 -9a5 4.5 0 0 1 11 2h1a3.5 3.5 0 0 1 0 7h-12" />
				</svg>Service</button
			>
		</li>
		<li>
			<button on:click={newDatabase} class="no-underline hover:bg-databases  tracking-wide font-bold">
				<svg
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
					<ellipse cx="12" cy="6" rx="8" ry="3" />
					<path d="M4 6v6a8 3 0 0 0 16 0v-6" />
					<path d="M4 12v6a8 3 0 0 0 16 0v-6" />
				</svg>Database</button
			>
		</li>
		<li>
			<a href="/sources/new" class="no-underline hover:bg-sources tracking-wide font-bold">
				<svg
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
					<circle cx="6" cy="6" r="2" />
					<circle cx="18" cy="18" r="2" />
					<path d="M11 6h5a2 2 0 0 1 2 2v8" />
					<polyline points="14 9 11 6 14 3" />
					<path d="M13 18h-5a2 2 0 0 1 -2 -2v-8" />
					<polyline points="10 15 13 18 10 21" />
				</svg>Git Source</a
			>
		</li>
		<li>
			<a href="/destinations/new" class="no-underline hover:bg-destinations tracking-wide font-bold">
				<svg
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
					<path
						d="M22 12.54c-1.804 -.345 -2.701 -1.08 -3.523 -2.94c-.487 .696 -1.102 1.568 -.92 2.4c.028 .238 -.32 1.002 -.557 1h-14c0 5.208 3.164 7 6.196 7c4.124 .022 7.828 -1.376 9.854 -5c1.146 -.101 2.296 -1.505 2.95 -2.46z"
					/>
					<path d="M5 10h3v3h-3z" />
					<path d="M8 10h3v3h-3z" />
					<path d="M11 10h3v3h-3z" />
					<path d="M8 7h3v3h-3z" />
					<path d="M11 7h3v3h-3z" />
					<path d="M11 4h3v3h-3z" />
					<path d="M4.571 18c1.5 0 2.047 -.074 2.958 -.78" />
					<line x1="10" y1="16" x2="10" y2="16.01" />
				</svg>Destination</a
			>
		</li>
	</ul>
</div>
