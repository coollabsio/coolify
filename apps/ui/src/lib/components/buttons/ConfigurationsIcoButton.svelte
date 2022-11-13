<div class="flex flex-row flex-wrap space-x-3 justify-center lg:justify-start lg:py-0">
			
  <div class="border border-stone-700 h-8" />
  <a
    id="configuration"
    href="/databases/{id}"
    sveltekit:prefetch
    class="hover:text-yellow-500 rounded"
    class:text-yellow-500={$page.url.pathname === `/databases/${id}`}
    class:bg-coolgray-500={$page.url.pathname === `/databases/${id}`}
  >
    <button class="icons bg-transparent m text-sm disabled:text-red-500">
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
        <rect x="4" y="8" width="4" height="4" />
        <line x1="6" y1="4" x2="6" y2="8" />
        <line x1="6" y1="12" x2="6" y2="20" />
        <rect x="10" y="14" width="4" height="4" />
        <line x1="12" y1="4" x2="12" y2="14" />
        <line x1="12" y1="18" x2="12" y2="20" />
        <rect x="16" y="5" width="4" height="4" />
        <line x1="18" y1="4" x2="18" y2="5" />
        <line x1="18" y1="9" x2="18" y2="20" />
      </svg></button
    ></a
  >
  <Tooltip triggeredBy="#configuration">{'Configuration'}</Tooltip>