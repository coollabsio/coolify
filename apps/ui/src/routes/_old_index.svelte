<script>
	import { cleanup } from "$lib/api/cleanup";
	import { refreshStatus } from "$lib/api/status";
</script>

	{#if (filtered.applications.length > 0 && applications.length > 0) || filtered.otherApplications.length > 0}
  <div class="flex items-center mt-10 space-x-2">
    <h1 class="title lg:text-3xl">Applications</h1>
    <button class="btn btn-sm btn-primary" on:click={()=>refreshStatus('applications', applications)}
    
    >
    {#if resources.foundUnconfiguredApplication}
      <button
        class="btn btn-sm"
        class:loading={loading.applications}
        disabled={loading.applications}
        on:click={() => cleanup('applications')}>Cleanup Unconfigured Resources</button
      >
    {/if}
  </div>
{/if}
{#if filtered.applications.length > 0 && applications.length > 0}
  <div class="divider" />
  <div
    class="grid grid-col gap-2 lg:gap-8 auto-cols-max grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 p-4"
  >
    {#if filtered.applications.length > 0}
      {#each filtered.applications as application}
        <a class="no-underline mb-5" href={`/applications/${application.id}`}>
          <div
            class="w-full rounded p-5 bg-coolgray-200 hover:bg-green-600 indicator duration-150"
          >
            {#await getStatus(application)}
              <span class="indicator-item badge bg-yellow-300 badge-sm" />
            {:then}
              
              {/if}
            {/await}
            <div class="w-full flex flex-row">
              <ApplicationsIcons {application} isAbsolute={true} />
              <div class="w-full flex flex-col">
                <h1 class="font-bold text-base truncate">
                  {application.name}
                  {#if application.settings?.isBot}
                    <span class="text-xs badge bg-coolblack border-none text-applications"
                      >BOT</span
                    >
                  {/if}
                </h1>
                <div class="h-10 text-xs">
                  {#if application?.fqdn}
                    <h2>{application?.fqdn.replace('https://', '').replace('http://', '')}</h2>
                  {:else if !application.settings?.isBot && !application?.fqdn && application.buildPack !== 'compose'}
                    <h2 class="text-red-500">Not configured</h2>
                  {/if}
                  {#if application.destinationDocker?.name}
                    <div class="truncate">{application.destinationDocker?.name}</div>
                  {/if}
                  {#if application.teams.length > 0 && application.teams[0]?.name}
                    <div class="truncate">{application.teams[0]?.name}</div>
                  {/if}
                </div>

                <div class="flex justify-end items-end space-x-2 h-10">
                  {#if application?.fqdn}
                    <a
                      href={application?.fqdn}
                      target="_blank noreferrer"
                      class="icons hover:bg-green-500"
                    >
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
                        <path d="M11 7h-5a2 2 0 0 0 -2 2v9a2 2 0 0 0 2 2h9a2 2 0 0 0 2 -2v-5" />
                        <line x1="10" y1="14" x2="20" y2="4" />
                        <polyline points="15 4 20 4 20 9" />
                      </svg>
                    </a>
                  {/if}

                  {#if application.settings?.isBot && application.exposePort}
                    <a
                      href={`http://${dev ? 'localhost' : settings.ipv4}:${
                        application.exposePort
                      }`}
                      target="_blank noreferrer"
                      class="icons hover:bg-green-500"
                    >
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
                        <path d="M11 7h-5a2 2 0 0 0 -2 2v9a2 2 0 0 0 2 2h9a2 2 0 0 0 2 -2v-5" />
                        <line x1="10" y1="14" x2="20" y2="4" />
                        <polyline points="15 4 20 4 20 9" />
                      </svg>
                    </a>
                  {/if}
                </div>
              </div>
            </div>
          </div>
        </a>
      {/each}
    {:else}
      <h1 class="">Nothing here.</h1>
    {/if}
  </div>
{/if}
{#if filtered.otherApplications.length > 0}
  {#if filtered.applications.length > 0}
    <div class="divider w-32 mx-auto" />
  {/if}
{/if}
{#if filtered.otherApplications.length > 0}
  <div
    class="grid grid-col gap-8 auto-cols-max grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 p-4"
  >
    {#each filtered.otherApplications as application}
      <a class="no-underline mb-5" href={`/applications/${application.id}`}>
        <div class="w-full rounded p-5 bg-coolgray-200 hover:bg-green-600 indicator duration-150">
          {#await getStatus(application)}
            <span class="indicator-item badge bg-yellow-300 badge-sm" />
          {:then}
            RefreshStatus 
            {/if}
          {/await}
          <div class="w-full flex flex-row">
            <ApplicationsIcons {application} isAbsolute={true} />
            <div class="w-full flex flex-col">
              <h1 class="font-bold text-base truncate">
                {application.name}
                {#if application.settings?.isBot}
                  <span class="text-xs badge bg-coolblack border-none text-applications">BOT</span
                  >
                {/if}
              </h1>
              <div class="h-10 text-xs">
                {#if application?.fqdn}
                  <h2>{application?.fqdn.replace('https://', '').replace('http://', '')}</h2>
                {:else if !application.settings?.isBot && !application?.fqdn}
                  <h2 class="text-red-500">Not configured</h2>
                {/if}
                {#if application.destinationDocker?.name}
                  <div class="truncate">{application.destinationDocker?.name}</div>
                {/if}
                {#if application.teams.length > 0 && application.teams[0]?.name}
                  <div class="truncate">{application.teams[0]?.name}</div>
                {/if}
              </div>

              <div class="flex justify-end items-end space-x-2 h-10">
                {#if application?.fqdn}
                  <a
                    href={application?.fqdn}
                    target="_blank noreferrer"
                    class="icons hover:bg-green-500"
                  >
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
                      <path d="M11 7h-5a2 2 0 0 0 -2 2v9a2 2 0 0 0 2 2h9a2 2 0 0 0 2 -2v-5" />
                      <line x1="10" y1="14" x2="20" y2="4" />
                      <polyline points="15 4 20 4 20 9" />
                    </svg>
                  </a>
                {/if}

                {#if application.settings?.isBot && application.exposePort}
                  <a
                    href={`http://${dev ? 'localhost' : settings.ipv4}:${application.exposePort}`}
                    target="_blank noreferrer"
                    class="icons hover:bg-green-500"
                  >
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
                      <path d="M11 7h-5a2 2 0 0 0 -2 2v9a2 2 0 0 0 2 2h9a2 2 0 0 0 2 -2v-5" />
                      <line x1="10" y1="14" x2="20" y2="4" />
                      <polyline points="15 4 20 4 20 9" />
                    </svg>
                  </a>
                {/if}
              </div>
            </div>
          </div>
        </div>
      </a>
    {/each}
  </div>
{/if}
{#if (filtered.services.length > 0 && services.length > 0) || filtered.otherServices.length > 0}
  <div class="flex items-center mt-10 space-x-2">
    <h1 class="title lg:text-3xl">Services</h1>
    <button class="btn btn-sm btn-primary" on:click={refreshStatus('services', services)}
      
    >
    {#if resources.foundUnconfiguredService}
      <button
        class="btn btn-sm"
        class:loading={loading.services}
        disabled={loading.services}
        on:click={() => cleanup('services'}>Cleanup Unconfigured Resources</button
      >
    {/if}
  </div>
{/if}
{#if filtered.services.length > 0 && services.length > 0}
  <div class="divider" />
  <div
    class="grid grid-col gap-8 auto-cols-max grid-cols-1 md:grid-cols-2 lg:md:grid-cols-3 xl:grid-cols-4 p-4"
  >
    {#if filtered.services.length > 0}
      {#each filtered.services as service}
        <a class="no-underline mb-5" href={`/services/${service.id}`}>
          <div
            class="w-full rounded p-5 bg-coolgray-200 hover:bg-pink-600 indicator duration-150"
          >
            {#await getStatus(service)}
              <span class="indicator-item badge bg-yellow-300 badge-sm" />
            {:then}
              RefreshStatus
              {/if}
            {/await}
            <div class="w-full flex flex-row">
              <ServiceIcons type={service.type} isAbsolute={true} />
              <div class="w-full flex flex-col">
                <h1 class="font-bold text-base truncate">{service.name}</h1>
                <div class="h-10 text-xs">
                  {#if service?.fqdn}
                    <h2>{service?.fqdn.replace('https://', '').replace('http://', '')}</h2>
                  {:else}
                    <h2 class="text-red-500">URL not configured</h2>
                  {/if}
                  {#if service.destinationDocker?.name}
                    <div class="truncate">{service.destinationDocker?.name}</div>
                  {/if}
                  {#if service.teams.length > 0 && service.teams[0]?.name}
                    <div class="truncate">{service.teams[0]?.name}</div>
                  {/if}
                </div>
                <div class="flex justify-end items-end space-x-2 h-10">
                  {#if service?.fqdn}
                    <a
                      href={service?.fqdn}
                      target="_blank noreferrer"
                      class="icons hover:bg-pink-500"
                    >
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
                        <path d="M11 7h-5a2 2 0 0 0 -2 2v9a2 2 0 0 0 2 2h9a2 2 0 0 0 2 -2v-5" />
                        <line x1="10" y1="14" x2="20" y2="4" />
                        <polyline points="15 4 20 4 20 9" />
                      </svg>
                    </a>
                  {/if}
                </div>
              </div>
            </div>
          </div>
        </a>
      {/each}
    {:else}
      <h1 class="">Nothing here.</h1>
    {/if}
  </div>
{/if}
{#if filtered.otherServices.length > 0}
  {#if filtered.services.length > 0}
    <div class="divider w-32 mx-auto" />
  {/if}
{/if}
{#if filtered.otherServices.length > 0}
  <div
    class="grid grid-col gap-8 auto-cols-max grid-cols-1 md:grid-cols-2 lg:md:grid-cols-3 xl:grid-cols-4 p-4"
  >
    {#each filtered.otherServices as service}
      <a class="no-underline mb-5" href={`/services/${service.id}`}>
        <div class="w-full rounded p-5 bg-coolgray-200 hover:bg-pink-600 indicator duration-150">
          {#await getStatus(service)}
            <span class="indicator-item badge bg-yellow-300 badge-sm" />
          {:then}
            
            {/if}
          {/await}
          <div class="w-full flex flex-row">
            <ServiceIcons type={service.type} isAbsolute={true} />
            <div class="w-full flex flex-col">
              <h1 class="font-bold text-base truncate">{service.name}</h1>
              <div class="h-10 text-xs">
                {#if service?.fqdn}
                  <h2>{service?.fqdn.replace('https://', '').replace('http://', '')}</h2>
                {:else}
                  <h2 class="text-red-500">URL not configured</h2>
                {/if}
                {#if service.destinationDocker?.name}
                  <div class="truncate">{service.destinationDocker?.name}</div>
                {/if}
                {#if service.teams.length > 0 && service.teams[0]?.name}
                  <div class="truncate">{service.teams[0]?.name}</div>
                {/if}
              </div>
              <div class="flex justify-end items-end space-x-2 h-10">
                {#if service?.fqdn}
                  <a
                    href={service?.fqdn}
                    target="_blank noreferrer"
                    class="icons hover:bg-pink-500"
                  >
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
                      <path d="M11 7h-5a2 2 0 0 0 -2 2v9a2 2 0 0 0 2 2h9a2 2 0 0 0 2 -2v-5" />
                      <line x1="10" y1="14" x2="20" y2="4" />
                      <polyline points="15 4 20 4 20 9" />
                    </svg>
                  </a>
                {/if}
              </div>
            </div>
          </div>
        </div>
      </a>
    {/each}
  </div>
{/if}



{#if filtered.applications.length === 0 && filtered.destinations.length === 0 && filtered.databases.length === 0 && filtered.services.length === 0 && filtered.gitSources.length === 0 && filtered.destinations.length === 0 && $search}
  <AppsNothingFound searched={$search}/>
{/if}
{#if applications.length === 0 && destinations.length === 0 && databases.length === 0 && services.length === 0 && gitSources.length === 0 && destinations.length === 0}
  <AppsBlank>
    <NewResource><button class="btn btn-primary">Let's Get Started</button></NewResource>
  </AppsBlank>
{/if}

<div class="mb-20" />
