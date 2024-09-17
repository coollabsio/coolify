<div>
    <x-slot:title>
        Terminal | Coolify
    </x-slot>
    <h1>Terminal</h1>
    <div class="flex gap-2 items-end subtitle">
        <div>Execute commands on your servers and containers without leaving the browser.</div>
        <x-helper
            helper="If you're having trouble connecting to your server, make sure that the port is open.<br><br><a class='underline' href='https://coolify.io/docs/knowledge-base/server/firewall/#terminal' target='_blank'>Documentation</a>"></x-helper>
    </div>
    <livewire:run-command />
</div>
