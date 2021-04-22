<script>
  import { fetch } from "@store";
  import { params } from "@roxi/routify/runtime";
  import { fade } from "svelte/transition";
  import { toast } from "@zerodevx/svelte-toast";
  import Loading from "../Loading.svelte";
  import TooltipInfo from "../Tooltip/TooltipInfo.svelte";
  import PasswordField from "../PasswordField.svelte";
  import Tooltip from "../Tooltip/Tooltip.svelte";
  export let service;

  $: name = $params.name;
  let loading = false;
  async function activate() {
    try {
      loading = true;
      await $fetch(`/api/v1/services/deploy/${name}/activate`, {
        method: "PATCH",
        body: {},
      });
      toast.push(`All users are activated for Plausible.`);
    } catch (error) {
      console.log(error);
      toast.push(`Ooops, there was an error activating users for Plausible?!`);
    } finally {
      loading = false;
    }
  }
</script>

{#if loading}
  <Loading />
{:else}
  <div class="text-left max-w-5xl mx-auto px-6" in:fade="{{ duration: 100 }}">
    <div class="pb-2 pt-5 space-y-4">
      <div class="flex space-x-5 items-center">
        <div class="text-2xl font-bold py-4 border-gradient">General</div>
        <div class="flex-1"></div>
        <Tooltip
          position="bottom"
          size="large"
          label="Activate all users in Plausible database, so you can login without the email verification."
        >
          <button
            class="button bg-blue-500 hover:bg-blue-400 px-2"
            on:click="{activate}">Activate All Users</button
          >
        </Tooltip>
      </div>

      <div class="flex items-center">
        <div class="font-bold w-64 text-warmGray-400">Domain</div>
        <input class="w-full" value="{service.config.baseURL}" disabled />
      </div>
      <div class="flex items-center">
        <div class="font-bold w-64 text-warmGray-400">Email address</div>
        <input class="w-full" value="{service.config.email}" disabled />
      </div>
      <div class="flex items-center">
        <div class="font-bold w-64 text-warmGray-400">Username</div>
        <input class="w-full" value="{service.config.userName}" disabled />
      </div>
      <div class="flex items-center">
        <div class="font-bold w-64 text-warmGray-400">Password</div>
        <PasswordField value="{service.config.userPassword}" />
      </div>
      <div class="text-2xl font-bold py-4 border-gradient w-32">PostgreSQL</div>
      <div class="flex items-center">
        <div class="font-bold w-64 text-warmGray-400">Username</div>
        <input class="w-full" value="{service.config.generateEnvsPostgres.POSTGRESQL_USERNAME}" disabled />
      </div>
      <div class="flex items-center">
        <div class="font-bold w-64 text-warmGray-400">Password</div>
        <PasswordField value="{service.config.generateEnvsPostgres.POSTGRESQL_PASSWORD}" />
      </div>
      <div class="flex items-center">
        <div class="font-bold w-64 text-warmGray-400">Database</div>
        <input class="w-full" value="{service.config.generateEnvsPostgres.POSTGRESQL_DATABASE}" disabled />
      </div>
    </div>
  </div>
{/if}
