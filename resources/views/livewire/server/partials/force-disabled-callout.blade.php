@if ($server->canBeReEnabled())
    <x-callout type="danger" title="Server disabled" class="mb-4">
        Coolify disabled this server because it was unreachable for more than 7 days. Coolify does not connect to it.
        Correct the connection settings, then click <strong>Validate connection</strong>. When the connection works,
        the server is enabled again.
    </x-callout>
@endif
