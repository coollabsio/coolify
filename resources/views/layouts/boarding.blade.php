@extends('layouts.base')
@section('body')
    <x-modal noSubmit modalId="installDocker">
        <x-slot:modalBody>
            <livewire:activity-monitor header="Docker Installation Logs" />
        </x-slot:modalBody>
        <x-slot:modalSubmit>
            <x-forms.button onclick="installDocker.close()" type="submit">
                Close
            </x-forms.button>
        </x-slot:modalSubmit>
    </x-modal>
    <main class="min-h-screen hero">
        <div class="hero-content">
            {{ $slot }}
        </div>
    </main>
    @parent
@endsection
