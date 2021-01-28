<script>
  import { fly } from "svelte/transition";
  export let notification;
</script>

<div
  in:fly={{ y: -200, duration: 100 }}
  out:fly={{ x: 200, duration: 600 }}
  class="fixed inset-0 flex items-start justify-center px-4 py-6 pointer-events-none sm:p-6 sm:items-start sm:justify-end"
>
  <div
    class="max-w-md w-full border-2 border-black rounded-lg pointer-events-auto bg-gray-700 "
  >
    <div class="p-4 ">
      <div class="flex items-start">
        <div class="flex-shrink-0">
          <svg
            class="h-6 w-6 text-green-400"
            xmlns="http://www.w3.org/2000/svg"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
            aria-hidden="true">
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"
            />
          </svg>
        </div>
        <div class="ml-3 w-0 flex-1 pt-0.5">
          <p class="text-sm font-medium text-white">{notification.message}</p>
        </div>
      </div>
    </div>
  </div>
</div>
