<style lang="postcss">
  .loader {
    width: 8px;
    height: 40px;
    border-radius: 4px;
    display: block;
    margin: 20px auto;
    position: relative;
    background: currentColor;
    color: #fff;
    box-sizing: border-box;
    animation: animloader 0.3s 0.3s linear infinite alternate;
  }

  .loader::after,
  .loader::before {
    content: "";
    width: 8px;
    height: 40px;
    border-radius: 4px;
    background: currentColor;
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    left: 20px;
    box-sizing: border-box;
    animation: animloader 0.3s 0.45s linear infinite alternate;
  }
  .loader::before {
    left: -20px;
    animation-delay: 0s;
  }

  @keyframes animloader {
    0% {
      height: 48px;
    }
    100% {
      height: 4px;
    }
  }
  .github {
    display: block;
    margin: 20px auto;
    position: relative;
    transform: translateY(-50%);
  }
</style>

<script>
  export let github = false;
  export let fullscreen = true;
</script>

{#if fullscreen}
  {#if github}
    <div class="fixed top-0 flex flex-wrap content-center h-full w-full">
      <div class="github animate-bounce">
        <svg
          class="w-28"
          xmlns="http://www.w3.org/2000/svg"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          stroke-width="2"
          stroke-linecap="round"
          stroke-linejoin="round"
          ><path
            d="M9 19c-5 1.5-5-2.5-7-3m14 6v-3.87a3.37 3.37 0 0 0-.94-2.61c3.14-.35 6.44-1.54 6.44-7A5.44 5.44 0 0 0 20 4.77 5.07 5.07 0 0 0 19.91 1S18.73.65 16 2.48a13.38 13.38 0 0 0-7 0C6.27.65 5.09 1 5.09 1A5.07 5.07 0 0 0 5 4.77a5.44 5.44 0 0 0-1.5 3.78c0 5.42 3.3 6.61 6.44 7A3.37 3.37 0 0 0 9 18.13V22"
          ></path></svg
        >
        <div class="w-28 text-xs font-bold text-center">Loading GitHub...</div>
      </div>
    </div>
  {:else}
    <div class="fixed top-0 flex flex-wrap content-center h-full w-full">
      <span class="loader"></span>
    </div>
  {/if}
{/if}
