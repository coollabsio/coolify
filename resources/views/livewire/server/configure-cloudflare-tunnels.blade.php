<form wire:submit.prevent='submit' class="flex flex-col gap-2 w-full">
    <x-forms.input id="cloudflare_token" required label="Cloudflare Token" type="password" />
    <x-forms.input id="ssh_domain" label="Configured SSH Domain" required
        helper="The SSH domain you configured in Cloudflare. Make sure there is no protocol like http(s):// so you provide a FQDN not a URL. <a class='underline dark:text-white' href='https://coolify.io/docs/knowledge-base/cloudflare/tunnels/server-ssh' target='_blank'>Documentation</a>" />
    <x-forms.button type="submit" isHighlighted @click="modalOpen=false">Continue</x-forms.button>
</form>
