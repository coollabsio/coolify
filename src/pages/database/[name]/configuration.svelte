<script>
  import { fetch, database } from "@store";
  import { redirect, params } from "@roxi/routify/runtime";
  import { toast } from "@zerodevx/svelte-toast";
  import { fade } from "svelte/transition";

  import CouchDb from "../../../components/Databases/SVGs/CouchDb.svelte";
  import MongoDb from "../../../components/Databases/SVGs/MongoDb.svelte";
  import Mysql from "../../../components/Databases/SVGs/Mysql.svelte";
  import Postgresql from "../../../components/Databases/SVGs/Postgresql.svelte";
  import Loading from "../../../components/Loading.svelte";
  import PasswordField from "../../../components/PasswordField.svelte";

  $: name = $params.name;

  async function loadDatabaseConfig() {
    if (name) {
      try {
        $database = await $fetch(`/api/v1/databases/${name}`);
      } catch (error) {
        toast.push(`Cannot find database ${name}`);
        $redirect(`/dashboard/databases`);
      }
    }
  }
</script>

{#await loadDatabaseConfig()}
  <Loading />
{:then}
  <div class="min-h-full text-white">
    <div
      class="py-5 text-left px-6 text-3xl tracking-tight font-bold flex items-center"
    >
      <div>{$database.config.general.nickname}</div>
      <div class="px-4">
        {#if $database.config.general.type === "mongodb"}
          <MongoDb customClass="w-8 h-8" />
        {:else if $database.config.general.type === "postgresql"}
          <Postgresql customClass="w-8 h-8" />
        {:else if $database.config.general.type === "mysql"}
          <Mysql customClass="w-8 h-8" />
        {:else if $database.config.general.type === "couchdb"}
          <CouchDb customClass="w-8 h-8 fill-current text-red-600" />
        {/if}
      </div>
    </div>
  </div>
  <div class="text-left max-w-6xl mx-auto px-6" in:fade="{{ duration: 100 }}">
    <div class="pb-2 pt-5 space-y-4">
      <div class="text-2xl font-bold border-gradient w-32">Database</div>
      <div class="flex items-center pt-4">
        <div class="font-bold w-64 text-warmGray-400">Connection string</div>
        {#if $database.config.general.type === "mongodb"}
          <PasswordField
            value="{`mongodb://${$database.envs.MONGODB_USERNAME}:${$database.envs.MONGODB_PASSWORD}@${$database.config.general.deployId}:27017/${$database.envs.MONGODB_DATABASE}`}"
          />
        {:else if $database.config.general.type === "postgresql"}
          <PasswordField
            value="{`postgresql://${$database.envs.POSTGRESQL_USERNAME}:${$database.envs.POSTGRESQL_PASSWORD}@${$database.config.general.deployId}:5432/${$database.envs.POSTGRESQL_DATABASE}`}"
          />
        {:else if $database.config.general.type === "mysql"}
          <PasswordField
            value="{`mysql://${$database.envs.MYSQL_USER}:${$database.envs.MYSQL_PASSWORD}@${$database.config.general.deployId}:3306/${$database.envs.MYSQL_DATABASE}`}"
          />
        {:else if $database.config.general.type === "couchdb"}
          <PasswordField
            value="{`http://${$database.envs.COUCHDB_USER}:${$database.envs.COUCHDB_PASSWORD}@${$database.config.general.deployId}:5984`}"
          />
        {:else if $database.config.general.type === "clickhouse"}
          <!-- {JSON.stringify($database)} -->
          <!-- <textarea
          disabled
          class="w-full"
          value="{`postgresql://${$database.envs.POSTGRESQL_USERNAME}:${$database.envs.POSTGRESQL_PASSWORD}@${$database.config.general.deployId}:5432/${$database.envs.POSTGRESQL_DATABASE}`}"
        ></textarea> -->
        {/if}
      </div>
    </div>
    {#if $database.config.general.type === "mongodb"}
      <div class="flex items-center">
        <div class="font-bold w-64 text-warmGray-400">Root password</div>
        <PasswordField value="{$database.envs.MONGODB_ROOT_PASSWORD}" />
      </div>
    {/if}
  </div>
{/await}
