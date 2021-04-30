<script>
  import { application } from "@store";

  let secret = {
    name: null,
    value: null,
  };
  let foundSecret = null;
  async function saveSecret() {
    if (secret.name && secret.value) {
      const found = $application.publish.secrets.find(
        s => s.name === secret.name,
      );
      if (!found) {
        $application.publish.secrets = [
          ...$application.publish.secrets,
          {
            name: secret.name,
            value: secret.value,
          },
        ];
        secret = {
          name: null,
          value: null,
        };
      } else {
        foundSecret = found;
      }
    }
  }

  async function removeSecret(name) {
    foundSecret = null
    $application.publish.secrets = [
      ...$application.publish.secrets.filter(s => s.name !== name),
    ];
  }
</script>
<div class="text-2xl font-bold border-gradient w-24">Secrets</div>
<div class="max-w-xl mx-auto text-center pt-4">
  <div class="text-left text-base font-bold tracking-tight text-warmGray-400">
    New Secret
  </div>
  <div class="flex space-x-4">
    <input id="secretName" bind:value="{secret.name}" placeholder="Name" class="w-64 border-2 border-transparent" />
    <input id="secretValue" bind:value="{secret.value}" placeholder="Value" class="w-64 border-2 border-transparent" />
    <button class="icon hover:text-green-500" on:click="{saveSecret}">
      <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3m0 0v3m0-3h3m-3 0H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z" />
      </svg>
    </button>
  </div>
  {#if $application.publish.secrets.length > 0}
    <div class="py-4">
      {#each $application.publish.secrets as s}
        <div class="flex space-x-4">
          <input
            id="{s.name}"
            value="{s.name}"
            disabled
            class="border-2 bg-transparent border-transparent w-64"
            class:border-red-600="{foundSecret && foundSecret.name === s.name}"
          />
          <input
            id="{s.createdAt}"
            value="SAVED"
            disabled
            class="border-2 bg-transparent border-transparent w-64"
          />
          <button class="icon hover:text-red-500" on:click="{() => removeSecret(s.name)}">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
          </button>

        </div>
      {/each}
    </div>
  {/if}
</div>
