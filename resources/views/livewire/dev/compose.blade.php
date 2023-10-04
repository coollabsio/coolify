<div class="pb-10" x-data>
    <h1>Compose</h1>
    <div>All kinds of compose files.</div>
    <h3 class="pt-4">Services</h3>
    @foreach ($services as $serviceName => $value)
        <x-forms.button wire:click="setService('{{ $serviceName }}')">{{ Str::headline($serviceName) }}</x-forms.button>
    @endforeach
    <h3 class="pt-4">Base64 En/Decode</h3>
    <x-forms.button x-on:click="copyToClipboard('{{ $base64 }}')">Copy Base64 Compose</x-forms.button>
    <div class="pt-4">
        <x-forms.textarea realtimeValidation rows="40" id="compose"></x-forms.textarea>
    </div>
</div>
