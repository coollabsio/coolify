
	{#if applications.length !== 0 || destinations.length !== 0 || databases.length !== 0 || services.length !== 0 || gitSources.length !== 0 || destinations.length !== 0}
  <div class="space-x-2 lg:flex lg:justify-center text-center mb-4 ">
    <button
      class="btn btn-sm btn-ghost"
      class:bg-applications={$search === '!app'}
      class:hover:bg-coollabs={$search !== '!app'}
      on:click={() => doSearch('!app')}
    >
      <svg
        xmlns="http://www.w3.org/2000/svg"
        class="h-6 w-6 mr-2 hidden lg:block "
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
      </svg> Applications</button
    >
    <button
      class="btn btn-sm btn-ghost"
      class:bg-services={$search === '!service'}
      class:hover:bg-coollabs={$search !== '!service'}
      on:click={() => doSearch('!service')}
      ><svg
        xmlns="http://www.w3.org/2000/svg"
        class="h-6 w-6 mr-2 hidden lg:block"
        viewBox="0 0 24 24"
        stroke-width="1.5"
        stroke="currentColor"
        fill="none"
        stroke-linecap="round"
        stroke-linejoin="round"
      >
        <path stroke="none" d="M0 0h24v24H0z" fill="none" />
        <path d="M7 18a4.6 4.4 0 0 1 0 -9a5 4.5 0 0 1 11 2h1a3.5 3.5 0 0 1 0 7h-12" />
      </svg> Services</button
    >
    <button
      class="btn btn-sm btn-ghost "
      class:bg-databases={$search === '!db'}
      class:hover:bg-coollabs={$search !== '!db'}
      on:click={() => doSearch('!db')}
    >
      <svg
        xmlns="http://www.w3.org/2000/svg"
        class="h-6 w-6 mr-2 hidden lg:block"
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
      </svg> Databases</button
    >
    <button
      class="btn btn-sm btn-ghost"
      class:bg-sources={$search === '!git'}
      class:hover:bg-coollabs={$search !== '!git'}
      on:click={() => doSearch('!git')}
    >
      <svg
        xmlns="http://www.w3.org/2000/svg"
        class="h-6 w-6 mr-2 hidden lg:block"
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
      </svg> Git Sources</button
    >
    <button
      class="btn btn-sm btn-ghost"
      class:bg-destinations={$search === '!destination'}
      class:hover:bg-coollabs={$search !== '!destination'}
      on:click={() => doSearch('!destination')}
    >
      <svg
        xmlns="http://www.w3.org/2000/svg"
        class="h-6 w-6 mr-2 hidden lg:block"
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
      </svg>Destinations</button
    >
  </div>
  <div class="form-control">
    <div class="input-group flex w-full">
      <!-- svelte-ignore a11y-click-events-have-key-events -->
      <div
        class="btn btn-square cursor-default no-animation hover:bg-error"
        on:click={() => doSearch('')}
      >
        <svg
          xmlns="http://www.w3.org/2000/svg"
          class="w-6 h-6"
          viewBox="0 0 24 24"
          stroke-width="1.5"
          stroke="currentcolor"
          fill="none"
          stroke-linecap="round"
          stroke-linejoin="round"
        >
          <path stroke="none" d="M0 0h24v24H0z" fill="none" />
          <line x1="18" y1="6" x2="6" y2="18" />
          <line x1="6" y1="6" x2="18" y2="18" />
        </svg>
      </div>

      <input
        bind:this={searchInput}
        id="search"
        type="text"
        placeholder="Search: You can search for names, domains, types, database types, version, servers etc..."
        class="w-full input input-bordered input-primary"
        bind:value={$search}
        on:input={() => doSearch()}
      />
    </div>
    <label for="search" class="label w-full mt-3">
      <span class="label-text text-xs flex flex-wrap gap-2 items-center">
        <button
          class:bg-coollabs={$search === '!bot'}
          class="badge badge-lg text-white text-xs rounded"
          on:click={() => doSearch('!bot')}>Bots</button
        >

        <button
          class:bg-coollabs={$search === '!notmine'}
          class="badge badge-lg text-white text-xs rounded"
          on:click={() => doSearch('!notmine')}>Other Teams</button
        >
        <button
          class:bg-coollabs={$search === '!running'}
          class="badge badge-lg text-white text-xs rounded"
          on:click={() => doSearch('!running')}>Running</button
        >
        <button
          class:bg-coollabs={$search === '!stopped'}
          class="badge badge-lg text-white text-xs rounded"
          on:click={() => doSearch('!stopped')}>Stopped</button
        >
        <button
          class:bg-coollabs={$search === '!error'}
          class="badge badge-lg text-white text-xs rounded"
          on:click={() => doSearch('!error')}>Error</button
        >
      </span>
    </label>
  </div>
{/if}