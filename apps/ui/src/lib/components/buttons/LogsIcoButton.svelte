<div class="border border-stone-700 h-8" />
<a
  id="databaselogs"
  href={$status.database.isRunning ? `/databases/${id}/logs` : null}
  sveltekit:prefetch
  class="hover:text-pink-500 rounded"
  class:text-pink-500={$page.url.pathname === `/databases/${id}/logs`}
  class:bg-coolgray-500={$page.url.pathname === `/databases/${id}/logs`}
>
  <button disabled={!$status.database.isRunning} class="icons bg-transparent text-sm">
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
      <path d="M3 19a9 9 0 0 1 9 0a9 9 0 0 1 9 0" />
      <path d="M3 6a9 9 0 0 1 9 0a9 9 0 0 1 9 0" />
      <line x1="3" y1="6" x2="3" y2="19" />
      <line x1="12" y1="6" x2="12" y2="19" />
      <line x1="21" y1="6" x2="21" y2="19" />
    </svg></button
  ></a
>
<Tooltip triggeredBy="#databaselogs">{'Logs'}</Tooltip>     