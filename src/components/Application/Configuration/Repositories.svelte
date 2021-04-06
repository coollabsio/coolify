<script>
  import { createEventDispatcher } from "svelte";
  import { isActive } from "@roxi/routify";
  import { application } from "@store";
  import Select from "svelte-select";

  function handleSelect(event) {
    $application.repository.id = parseInt(event.detail.value, 10);
    dispatch("loadBranches");
  }

  export let repositories;
  let items = repositories.map(repo => ({
    label: `${repo.owner.login}/${repo.name}`,
    value: repo.id.toString(),
  }));

  const selectedValue =
    !$isActive("/application/new") &&
    `${$application.repository.organization}/${$application.repository.name}`;

  const dispatch = createEventDispatcher();
  const modifyGithubAppConfig = () => dispatch("modifyGithubAppConfig");
</script>

<div class="grid grid-cols-1">
  {#if repositories.length !== 0}
    <label for="repository">Organization / Repository</label>
    <div class="grid grid-cols-3 ">
      <div class="repository-select-search col-span-2">
        <Select
          containerClasses="w-full border-none bg-transparent"
          on:select="{handleSelect}"
          selectedValue="{selectedValue}"
          isClearable="{false}"
          items="{items}"
          showIndicator="{$isActive('/application/new')}"
          noOptionsMessage="No Repositories found"
          placeholder="Select a Repository"
          isDisabled="{!$isActive('/application/new')}"
        />
      </div>
      <button
        class="button col-span-1 ml-2 bg-warmGray-800 hover:bg-warmGray-700 text-white"
        on:click="{modifyGithubAppConfig}">Configure on Github</button
      >
    </div>
  {:else}
    <button
      class="button col-span-1 ml-2 bg-warmGray-800 hover:bg-warmGray-700 text-white py-2"
      on:click="{modifyGithubAppConfig}">Add repositories on Github</button
    >
  {/if}
</div>
