<script>
  import { deployments, configuration } from "@store";
  import { goto } from "@roxi/routify/runtime";
  import Underdeployment from "../../components/Dashboard/Underdeployment.svelte";

  function switchTo(application) {
    const { branch, name, organization } = application;
    $goto(`/application/:organization/:name/:branch`, {
      name,
      organization,
      branch,
    });
  }
</script>

{#if $deployments.applications?.deployed.length > 0}
  <div class="max-w-4xl mx-auto px-2 lg:px-0">
    {#each $deployments.applications.deployed as application}
      <div
        class="hover:bg-green-700 rounded transition-all hover:text-white duration-100 cursor-pointer flex justify-center items-center px-2"
      >
        <div
          class="flex py-4 mx-auto w-full justify-center items-center space-x-2"
          on:click="{() =>
            switchTo({
              branch: application.Spec.Labels.config.repository.branch,
              name: application.Spec.Labels.config.repository.name,
              organization:
                application.Spec.Labels.config.repository.organization,
            })}"
        >
          <div>
            {#if application.Spec.Labels.config.build.pack === "static"}
              <svg
                class="text-red-500 w-6 h-6"
                xmlns="http://www.w3.org/2000/svg"
                aria-hidden="true"
                focusable="false"
                data-prefix="fab"
                data-icon="html5"
                role="img"
                viewBox="0 0 384 512"
                ><path
                  fill="currentColor"
                  d="M0 32l34.9 395.8L191.5 480l157.6-52.2L384 32H0zm308.2 127.9H124.4l4.1 49.4h175.6l-13.6 148.4-97.9 27v.3h-1.1l-98.7-27.3-6-75.8h47.7L138 320l53.5 14.5 53.7-14.5 6-62.2H84.3L71.5 112.2h241.1l-4.4 47.7z"
                ></path></svg
              >
            {:else}
              <svg
                class="text-green-500 w-6 h-6"
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
                ></path></svg
              >
            {/if}
          </div>
          <div class="text-sm font-bold tracking-tight">
            {application.Spec.Labels.config.publish.domain}{application.Spec
              .Labels.config.publish.path !== "/"
              ? application.Spec.Labels.config.publish.path
              : ""}
          </div>
          <div class="flex-1"></div>
        </div>
        <div class="inline-flex">
          <a
            href="{'https://github.com/' +
              application.Spec.Labels.config.repository.organization +
              '/' +
              application.Spec.Labels.config.repository.name}"
            target="_blank"
            class="text-xs hover:underline"
            ><svg
              on
              class="w-6 inline-block hover:text-black"
              fill="currentColor"
              viewBox="0 0 20 20"
              aria-hidden="true"
            >
              <path
                fill-rule="evenodd"
                d="M10 0C4.477 0 0 4.484 0 10.017c0 4.425 2.865 8.18 6.839 9.504.5.092.682-.217.682-.483 0-.237-.008-.868-.013-1.703-2.782.605-3.369-1.343-3.369-1.343-.454-1.158-1.11-1.466-1.11-1.466-.908-.62.069-.608.069-.608 1.003.07 1.531 1.032 1.531 1.032.892 1.53 2.341 1.088 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.113-4.555-4.951 0-1.093.39-1.988 1.029-2.688-.103-.253-.446-1.272.098-2.65 0 0 .84-.27 2.75 1.026A9.564 9.564 0 0110 4.844c.85.004 1.705.115 2.504.337 1.909-1.296 2.747-1.027 2.747-1.027.546 1.379.203 2.398.1 2.651.64.7 1.028 1.595 1.028 2.688 0 3.848-2.339 4.695-4.566 4.942.359.31.678.921.678 1.856 0 1.338-.012 2.419-.012 2.747 0 .268.18.58.688.482A10.019 10.019 0 0020 10.017C20 4.484 15.522 0 10 0z"
                clip-rule="evenodd"></path>
            </svg></a
          >
        </div>
      </div>
    {/each}
  </div>
{:else}
  <div class="text-center font-bold tracking-tight">No applications found</div>
{/if}
{#if $deployments.applications?.underDeployment.length > 0}
  <div class="flex flex-col items-center pb-6 px-5 justify-center">
    <div class="text-base font-bold tracking-tight px-5 py-3 text-center">
      Running deployments
    </div>
    <div
      class="flex flex-col flex-wrap gap-4 justify-center items-center mx-6 pb-6"
    >
      {#each $deployments.applications.underDeployment as deployment}
        <Underdeployment deployment="{deployment}" />
      {/each}
    </div>
  </div>
{/if}
