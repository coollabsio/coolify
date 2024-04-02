<form wire:submit.prevent='submit' class="flex flex-col w-full gap-2">
    <x-forms.input id="cloudflare_token" required label="Cloudflare Token" />
    <x-forms.input id="ssh_domain" label="Configured SSH Domain" required
        helper="The SSH Domain you configured in Cloudflare" />
    <x-forms.button type="submit" isHighlighted @click="modalOpen=false">Automated Configuration (experimental)</x-forms.button>
    <h3 class="text-center">Or</h3>
    <x-forms.button  wire:click.prevent='alreadyConfigured' @click="modalOpen=false">I have already set up the tunnel manually on the server.</x-forms.button>
</form>
