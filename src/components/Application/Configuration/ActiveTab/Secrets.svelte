<script>
  import { configuration } from "@store";

  let secret = {
    name: null,
    value: null,
  };
  let foundSecret = null;
  async function saveSecret() {
    if (secret.name && secret.value) {
      const found = $configuration.publish.secrets.find(
      s => s.name === secret.name,
    );
    if (!found) {
      $configuration.publish.secrets = [
        ...$configuration.publish.secrets,
        {
          name: secret.name,
          value: secret.value,
        },
      ];
      secret = {
        name: null,
        value: null
      }
    } else {
      foundSecret = found;
    }
    }

  }

  async function removeSecret(name) {
    $configuration.publish.secrets = [...$configuration.publish.secrets.filter(s => s.name !== name)]
   
  }
</script>

<div class="space-y-2 max-w-2xl md:mx-auto mx-6 text-center">
  <div class="text-left text-base font-bold tracking-tight text-warmGray-500">New Secret</div>
  <div class="grid md:grid-flow-col grid-flow-row gap-2">
    <input id="secretName" bind:value="{secret.name}" placeholder="Name" />
    <input id="secretValue" bind:value="{secret.value}" placeholder="Value" />
    <button
      class="button p-1 w-20 bg-green-600 hover:bg-green-500 text-white"
      on:click="{saveSecret}">Save</button
    >
  </div>
  {#if $configuration.publish.secrets.length > 0}
    {#each $configuration.publish.secrets as s}
      <div class="grid md:grid-flow-col grid-flow-row gap-2">
        <input
          id="{s.name}"
          value="{s.name}"
          disabled
          class="bg-transparent border-transparent"
          class:border-red-600="{foundSecret && foundSecret.name === s.name}"
        />
        <input
          id="{s.createdAt}"
          value="ENCRYPTED"
          disabled
          class="bg-transparent border-transparent"
        />
        <button
          class="button  w-20 bg-red-600 hover:bg-red-500 text-white"
          on:click="{() => removeSecret(s.name)}">Delete</button
        >
      </div>
    {/each}
  {/if}
</div>
