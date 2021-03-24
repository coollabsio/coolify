<script>
  import { createEventDispatcher } from "svelte";
  import { isActive } from "@roxi/routify";
  import { application } from "@store";
  export let repositories;
  const dispatch = createEventDispatcher();
  const loadBranches = () => dispatch("loadBranches");
  const modifyGithubAppConfig = () => dispatch("modifyGithubAppConfig");
</script>

<div class="grid grid-cols-1">
  {#if repositories.length !== 0}
    <label for="repository">Organization / Repository</label>
    <div class="grid grid-cols-3">
      <!-- svelte-ignore a11y-no-onchange -->
      <select
        id="repository"
        class:cursor-not-allowed="{!$isActive('/application/new')}"
        class="col-span-2"
        bind:value="{$application.repository.id}"
        on:change="{loadBranches}"
        disabled="{!$isActive('/application/new')}"
      >
        <option selected disabled>Select a repository</option>
        {#each repositories as repo}
          <option value="{repo.id}" class="font-medium">
            {repo.owner.login}
            /
            {repo.name}
          </option>
        {/each}
      </select>

      <button
        class="button col-span-1 ml-2 bg-warmGray-800 hover:bg-warmGray-700 text-white"
        on:click="{modifyGithubAppConfig}">Configure on Github</button
      >
    </div>
  {:else}
    <button
      class="button col-span-1 ml-2 bg-warmGray-800 hover:bg-warmGray-700 text-white"
      on:click="{modifyGithubAppConfig}">Add repositories on Github</button
    >
  {/if}
</div>
