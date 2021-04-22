<style lang="postcss">
  .gradient-border {
    --border-width: 2px;
    position: relative;
    display: flex;
    justify-content: center;
    width: 208px;
    height: 126px;
    background: #222;
    border-radius: 0.75rem;
  }
  .gradient-border::after {
    position: absolute;
    content: "";
    top: calc(-1 * var(--border-width));
    left: calc(-1 * var(--border-width));
    z-index: -1;
    width: calc(100% + var(--border-width) * 2);
    height: calc(100% + var(--border-width) * 2);
    background: linear-gradient(
      60deg,
      hsl(224, 85%, 66%),
      hsl(269, 85%, 66%),
      hsl(314, 85%, 66%),
      hsl(359, 85%, 66%),
      hsl(44, 85%, 66%),
      hsl(89, 85%, 66%),
      hsl(134, 85%, 66%),
      hsl(179, 85%, 66%)
    );
    background-size: 300% 300%;
    background-position: 0 50%;
    border-radius: calc(2 * var(--border-width));
    animation: moveGradient 1s alternate infinite;
  }

  @keyframes moveGradient {
    50% {
      background-position: 100% 50%;
    }
  }
</style>

<script>
  import { deployments, dbInprogress } from "@store";
  import { fade } from "svelte/transition";
  import { goto } from "@roxi/routify/runtime";
  import MongoDb from "../../components/Databases/SVGs/MongoDb.svelte";
  import Postgresql from "../../components/Databases/SVGs/Postgresql.svelte";
  import Mysql from "../../components/Databases/SVGs/Mysql.svelte";
  import CouchDb from "../../components/Databases/SVGs/CouchDb.svelte";
  import Clickhouse from "../../components/Databases/SVGs/Clickhouse.svelte";
  const initialNumberOfDBs = $deployments.databases?.deployed.length;
  $: if ($deployments.databases?.deployed.length) {
    if (initialNumberOfDBs !== $deployments.databases?.deployed.length) {
      $dbInprogress = false;
    }
  }
</script>

<div
  class="py-5 text-left px-6 text-3xl tracking-tight font-bold flex items-center"
>
  <div in:fade="{{ duration: 100 }}">Databases</div>
  <button
    class="icon p-1 ml-4 bg-purple-500 hover:bg-purple-400"
    on:click="{() => $goto('/database/new')}"
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
  {#if $deployments.databases?.deployed.length > 0}
    <div class="px-4 mx-auto py-5">
      <div class="flex items-center justify-center flex-wrap">
        {#each $deployments.databases.deployed as database}
          <div
            in:fade="{{ duration: 200 }}"
            class="px-4 pb-4"
            on:click="{() =>
              $goto(
                `/database/${database.Spec.Labels.configuration.general.deployId}/configuration`,
              )}"
          >
            <div
              class="relative rounded-xl p-6 bg-warmGray-800 border-2 border-dashed border-transparent hover:border-purple-500 text-white shadow-md cursor-pointer ease-in-out transform hover:scale-105 duration-100 group"
            >
              <div class="flex items-center">
                {#if database.Spec.Labels.configuration.general.type == "mongodb"}
                  <MongoDb customClass="w-10 h-10 absolute top-0 left-0 -m-4" />
                {:else if database.Spec.Labels.configuration.general.type == "postgresql"}
                  <Postgresql
                    customClass="w-10 h-10 absolute top-0 left-0 -m-4"
                  />
                {:else if database.Spec.Labels.configuration.general.type == "mysql"}
                  <Mysql customClass="w-10 h-10 absolute top-0 left-0 -m-4" />
                {:else if database.Spec.Labels.configuration.general.type == "couchdb"}
                  <CouchDb
                    customClass="w-10 h-10 fill-current text-red-600 absolute top-0 left-0 -m-4"
                  />
                {:else if database.Spec.Labels.configuration.general.type == "clickhouse"}
                  <Clickhouse
                    customClass="w-10 h-10 fill-current text-red-600 absolute top-0 left-0 -m-4"
                  />
                {/if}
                <div class="text-center w-full">
                  <div
                    class="text-base font-bold text-white group-hover:text-white"
                  >
                    {database.Spec.Labels.configuration.general.nickname}
                  </div>
                  <div class="text-xs font-bold text-warmGray-300 ">
                    ({database.Spec.Labels.configuration.general.type})
                  </div>
                </div>
              </div>
            </div>
          </div>
        {/each}
        {#if $dbInprogress}
          <div class=" px-4 pb-4">
            <div
              class="gradient-border text-xs font-bold text-warmGray-300 pt-6"
            >
              Working...
            </div>
          </div>
        {/if}
      </div>
    </div>
  {:else if $dbInprogress}
    <div class="px-4 mx-auto py-5">
      <div class="flex items-center justify-center flex-wrap">
        <div class=" px-4 pb-4">
          <div class="gradient-border text-xs font-bold text-warmGray-300 pt-6">
            Working...
          </div>
        </div>
      </div>
    </div>
  {:else}
    <div class="text-2xl font-bold text-center">No databases found</div>
  {/if}
</div>
