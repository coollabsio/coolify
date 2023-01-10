<script lang="ts">
	export let application: any;
	import { appSession, status } from '$lib/store';
	import { page } from '$app/stores';
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
					<svg viewBox="0 0 128 128" class="w-6 h-6">
						<path
							fill="#FC6D26"
							d="M126.615 72.31l-7.034-21.647L105.64 7.76c-.716-2.206-3.84-2.206-4.556 0l-13.94 42.903H40.856L26.916 7.76c-.717-2.206-3.84-2.206-4.557 0L8.42 50.664 1.385 72.31a4.792 4.792 0 001.74 5.358L64 121.894l60.874-44.227a4.793 4.793 0 001.74-5.357"
						/><path fill="#E24329" d="M64 121.894l23.144-71.23H40.856L64 121.893z" /><path
							fill="#FC6D26"
							d="M64 121.894l-23.144-71.23H8.42L64 121.893z"
						/><path
							fill="#FCA326"
							d="M8.42 50.663L1.384 72.31a4.79 4.79 0 001.74 5.357L64 121.894 8.42 50.664z"
						/><path
							fill="#E24329"
							d="M8.42 50.663h32.436L26.916 7.76c-.717-2.206-3.84-2.206-4.557 0L8.42 50.664z"
						/><path fill="#FC6D26" d="M64 121.894l23.144-71.23h32.437L64 121.893z" /><path
							fill="#FCA326"
							d="M119.58 50.663l7.035 21.647a4.79 4.79 0 01-1.74 5.357L64 121.894l55.58-71.23z"
						/><path
							fill="#E24329"
							d="M119.58 50.663H87.145l13.94-42.902c.717-2.206 3.84-2.206 4.557 0l13.94 42.903z"
						/>
					</svg>
				{:else if application.gitSource?.type === 'github'}
					<svg viewBox="0 0 128 128" class="w-6 h-6">
						<g fill="#ffffff"
							><path
								fill-rule="evenodd"
								clip-rule="evenodd"
								d="M64 5.103c-33.347 0-60.388 27.035-60.388 60.388 0 26.682 17.303 49.317 41.297 57.303 3.017.56 4.125-1.31 4.125-2.905 0-1.44-.056-6.197-.082-11.243-16.8 3.653-20.345-7.125-20.345-7.125-2.747-6.98-6.705-8.836-6.705-8.836-5.48-3.748.413-3.67.413-3.67 6.063.425 9.257 6.223 9.257 6.223 5.386 9.23 14.127 6.562 17.573 5.02.542-3.903 2.107-6.568 3.834-8.076-13.413-1.525-27.514-6.704-27.514-29.843 0-6.593 2.36-11.98 6.223-16.21-.628-1.52-2.695-7.662.584-15.98 0 0 5.07-1.623 16.61 6.19C53.7 35 58.867 34.327 64 34.304c5.13.023 10.3.694 15.127 2.033 11.526-7.813 16.59-6.19 16.59-6.19 3.287 8.317 1.22 14.46.593 15.98 3.872 4.23 6.215 9.617 6.215 16.21 0 23.194-14.127 28.3-27.574 29.796 2.167 1.874 4.097 5.55 4.097 11.183 0 8.08-.07 14.583-.07 16.572 0 1.607 1.088 3.49 4.148 2.897 23.98-7.994 41.263-30.622 41.263-57.294C124.388 32.14 97.35 5.104 64 5.104z"
							/><path
								d="M26.484 91.806c-.133.3-.605.39-1.035.185-.44-.196-.685-.605-.543-.906.13-.31.603-.395 1.04-.188.44.197.69.61.537.91zm2.446 2.729c-.287.267-.85.143-1.232-.28-.396-.42-.47-.983-.177-1.254.298-.266.844-.14 1.24.28.394.426.472.984.17 1.255zM31.312 98.012c-.37.258-.976.017-1.35-.52-.37-.538-.37-1.183.01-1.44.373-.258.97-.025 1.35.507.368.545.368 1.19-.01 1.452zm3.261 3.361c-.33.365-1.036.267-1.552-.23-.527-.487-.674-1.18-.343-1.544.336-.366 1.045-.264 1.564.23.527.486.686 1.18.333 1.543zm4.5 1.951c-.147.473-.825.688-1.51.486-.683-.207-1.13-.76-.99-1.238.14-.477.823-.7 1.512-.485.683.206 1.13.756.988 1.237zm4.943.361c.017.498-.563.91-1.28.92-.723.017-1.308-.387-1.315-.877 0-.503.568-.91 1.29-.924.717-.013 1.306.387 1.306.88zm4.598-.782c.086.485-.413.984-1.126 1.117-.7.13-1.35-.172-1.44-.653-.086-.498.422-.997 1.122-1.126.714-.123 1.354.17 1.444.663zm0 0"
							/></g
						>
					</svg>
				{/if}
				Open on Git
				<svg
					xmlns="http://www.w3.org/2000/svg"
					fill="currentColor"
					viewBox="0 0 24 24"
					stroke-width="3"
					stroke="currentColor"
					class="w-3 h-3  text-white"
				>
					<path
						stroke-linecap="round"
						stroke-linejoin="round"
						d="M4.5 19.5l15-15m0 0H8.25m11.25 0v11.25"
					/>
				</svg>
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
		class:bg-coollabs={$page.url.pathname === `/applications/${$page.params.id}/logs/build`}
	>
		<a href={`/applications/${$page.params.id}/logs/build`} class="no-underline w-full"
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
	{#if application.gitSourceId && $appSession.isAdmin}
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
	{#if $appSession.isAdmin}
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
	{/if}
</ul>
