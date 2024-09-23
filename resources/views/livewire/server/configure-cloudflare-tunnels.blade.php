<form wire:submit.prevent='submit' class="flex flex-col w-full gap-2">
    <x-forms.input id="cloudflare_token" required label="Cloudflare Token" />
    <x-forms.input id="ssh_domain" label="Configured SSH Domain" required
        helper="The SSH Domain you configured in Cloudflare. Make sure there is no protocol like http(s):// so you provide a FQDN not a URL." />
    <x-forms.button type="submit" isHighlighted @click="modalOpen=false">Automated Configuration</x-forms.button>
</form>
