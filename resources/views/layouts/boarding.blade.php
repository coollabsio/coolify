@extends('layouts.base')
@section('body')
    <main class="min-h-screen hero">
        <div class="hero-content">
            <x-modal modalId="installDocker">
                <x-slot:modalBody>
                    <livewire:activity-monitor header="Docker Installation Logs" />
                </x-slot:modalBody>
                <x-slot:modalSubmit>
                    <x-forms.button onclick="installDocker.close()" type="submit">
                        Close
                    </x-forms.button>
                </x-slot:modalSubmit>
            </x-modal>
            {{ $slot }}
        </div>
    </main>
    @parent
@endsection
