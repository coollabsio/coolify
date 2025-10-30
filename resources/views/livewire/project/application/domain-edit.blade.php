<form class="flex flex-col w-full gap-2 rounded-sm" wire:submit='submit'>
    <x-forms.datalist wire:model="scheme" label="Scheme" placeholder="Select or type a scheme" required
        helper="Select from common schemes or type a custom one (e.g., tcp, grpc, ftp)">
        <option value="https">https</option>
        <option value="http">http</option>
        <option value="wss">wss (WebSocket Secure)</option>
        <option value="ws">ws (WebSocket)</option>
    </x-forms.datalist>

    <x-forms.input wire:model="domainName" label="Domain" placeholder="app.example.com" required
        helper="Enter the domain name without protocol or port.<br><br><span class='text-helper'>Example</span><br>app.coolify.io or cloud.coolify.io/dashboard" />

    <x-forms.input wire:model="port" label="Port (Optional)" placeholder="Default: 80 (http) / 443 (https)"
        helper="Leave empty to use default port. Common ports: 80 (HTTP), 443 (HTTPS), 3000, 8080" />

    <x-forms.button @click="modalOpen=false" type="submit">
        Update Domain
    </x-forms.button>
</form>
