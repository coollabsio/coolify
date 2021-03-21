<script>
  import { deployments } from "@store";
  import { fade } from "svelte/transition";
  import { goto } from "@roxi/routify/runtime";

  function switchTo(application) {
    const { branch, name, organization } = application;
    $goto(`/application/:organization/:name/:branch`, {
      name,
      organization,
      branch,
    });
  }
</script>

<div
  in:fade="{{ duration: 100 }}"
  class="py-5 text-left px-6 text-3xl tracking-tight font-bold flex items-center"
>
  <div>Applications</div>
  <button
    class="icon p-1 ml-4 bg-green-500 hover:bg-green-400"
    on:click="{() => $goto('/application/new')}"
  >
    <svg
      class="w-6"
      xmlns="http://www.w3.org/2000/svg"
      fill="none"
      viewBox="0 0 24 24"
      stroke="currentColor"
    >
      <path
        stroke-linecap="round"
        stroke-linejoin="round"
        stroke-width="2"
        d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
    </svg>
  </button>
</div>
<div in:fade="{{ duration: 100 }}">
  {#if $deployments.applications?.deployed.length > 0}
    <div class="px-4 mx-auto py-5">
      <div class="flex items-center justify-center flex-wrap">
        {#each $deployments.applications.deployed as application}
          <div class="px-4 pb-4">
            <div
              class="relative rounded-xl py-6 w-52 h-32 bg-warmGray-800 hover:bg-green-500 text-white shadow-md cursor-pointer ease-in-out transform hover:scale-105 duration-200 hover:rotate-1 group"
              on:click="{() =>
                switchTo({
                  branch:
                    application.Spec.Labels.configuration.repository.branch,
                  name: application.Spec.Labels.configuration.repository.name,
                  organization:
                    application.Spec.Labels.configuration.repository
                      .organization,
                })}"
            >
              <div class="flex items-center ">
                {#if application.Spec.Labels.configuration.build.pack === "static"}
                  <svg
                    class="text-white w-10 h-10 absolute top-0 left-0 -m-4"
                    viewBox="0 0 32 32"
                    fill="none"
                    xmlns="http://www.w3.org/2000/svg"
                    ><g clip-path="url(#HTML5_Clip0_4)"
                      ><path
                        d="M30.216 0L27.6454 28.7967L16.0907 32L4.56783 28.8012L2 0H30.216Z"
                        fill="#E44D26"></path><path
                        d="M16.108 29.5515L25.4447 26.963L27.6415 2.35497H16.108V29.5515Z"
                        fill="#F16529"></path><path
                        d="M11.1109 9.4197H16.108V5.88731H7.25053L7.33509 6.83499L8.20327 16.5692H16.108V13.0369H11.4338L11.1109 9.4197Z"
                        fill="#EBEBEB"></path><path
                        d="M11.907 18.3354H8.36111L8.856 23.8818L16.0917 25.8904L16.108 25.8859V22.2108L16.0925 22.2149L12.1585 21.1527L11.907 18.3354Z"
                        fill="#EBEBEB"></path><path
                        d="M16.0958 16.5692H20.4455L20.0354 21.1504L16.0958 22.2138V25.8887L23.3373 23.8817L23.3904 23.285L24.2205 13.9855L24.3067 13.0369H16.0958V16.5692Z"
                        fill="white"></path><path
                        d="M16.0958 9.41105V9.41969H24.6281L24.6989 8.62572L24.8599 6.83499L24.9444 5.88731H16.0958V9.41105Z"
                        fill="white"></path></g
                    ><defs
                      ><clipPath id="HTML5_Clip0_4"
                        ><rect width="32" height="32" fill="white"
                        ></rect></clipPath
                      ></defs
                    ></svg
                  >
                {:else if application.Spec.Labels.configuration.build.pack === "nodejs"}
                  <svg
                    class="text-white w-10 h-10 absolute top-0 left-0 -m-4"
                    xmlns="http://www.w3.org/2000/svg"
                    aria-hidden="true"
                    focusable="false"
                    data-prefix="fab"
                    data-icon="node-js"
                    role="img"
                    viewBox="0 0 448 512"
                    ><path
                      fill="currentColor"
                      d="M224 508c-6.7 0-13.5-1.8-19.4-5.2l-61.7-36.5c-9.2-5.2-4.7-7-1.7-8 12.3-4.3 14.8-5.2 27.9-12.7 1.4-.8 3.2-.5 4.6.4l47.4 28.1c1.7 1 4.1 1 5.7 0l184.7-106.6c1.7-1 2.8-3 2.8-5V149.3c0-2.1-1.1-4-2.9-5.1L226.8 37.7c-1.7-1-4-1-5.7 0L36.6 144.3c-1.8 1-2.9 3-2.9 5.1v213.1c0 2 1.1 4 2.9 4.9l50.6 29.2c27.5 13.7 44.3-2.4 44.3-18.7V167.5c0-3 2.4-5.3 5.4-5.3h23.4c2.9 0 5.4 2.3 5.4 5.3V378c0 36.6-20 57.6-54.7 57.6-10.7 0-19.1 0-42.5-11.6l-48.4-27.9C8.1 389.2.7 376.3.7 362.4V149.3c0-13.8 7.4-26.8 19.4-33.7L204.6 9c11.7-6.6 27.2-6.6 38.8 0l184.7 106.7c12 6.9 19.4 19.8 19.4 33.7v213.1c0 13.8-7.4 26.7-19.4 33.7L243.4 502.8c-5.9 3.4-12.6 5.2-19.4 5.2zm149.1-210.1c0-39.9-27-50.5-83.7-58-57.4-7.6-63.2-11.5-63.2-24.9 0-11.1 4.9-25.9 47.4-25.9 37.9 0 51.9 8.2 57.7 33.8.5 2.4 2.7 4.2 5.2 4.2h24c1.5 0 2.9-.6 3.9-1.7s1.5-2.6 1.4-4.1c-3.7-44.1-33-64.6-92.2-64.6-52.7 0-84.1 22.2-84.1 59.5 0 40.4 31.3 51.6 81.8 56.6 60.5 5.9 65.2 14.8 65.2 26.7 0 20.6-16.6 29.4-55.5 29.4-48.9 0-59.6-12.3-63.2-36.6-.4-2.6-2.6-4.5-5.3-4.5h-23.9c-3 0-5.3 2.4-5.3 5.3 0 31.1 16.9 68.2 97.8 68.2 58.4-.1 92-23.2 92-63.4z"
                    ></path>
                  </svg>
                {/if}
                <div
                  class="text-xs font-bold text-center w-full text-warmGray-300  group-hover:text-white"
                >
                  {application.Spec.Labels.configuration.publish
                    .domain}{application.Spec.Labels.configuration.publish
                    .path !== "/"
                    ? application.Spec.Labels.configuration.publish.path
                    : ""}
                </div>
              </div>
            </div>
          </div>
        {/each}
      </div>
    </div>
  {:else}
    <div class="text-2xl font-bold text-center">No applications found</div>
  {/if}
</div>
