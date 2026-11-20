{{--
    Confirms and runs removeUnusedPackages() of the enclosing Livewire component (ManagesServerPackageUpdates).
    Shows nothing when the package manager of the last patch check is not supported. With $triggerId, the dialog
    opens from a hidden button with that id (for a menu item), not from a visible button.
--}}
@props(['packageManager' => null, 'buttonTitle' => 'Remove unused packages', 'triggerId' => null])

@if (\App\Actions\Server\RemoveUnusedPackages::supports($packageManager))
    <x-modal-confirmation title="Remove unused packages?" :buttonTitle="$buttonTitle"
        submitAction="removeUnusedPackages" :actions="[
            'Removes packages that nothing needs anymore, and old kernels.',
            'The running kernel and the newest kernel stay installed.',
        ]"
        warningMessage="Coolify cannot restore removed packages. Check the logs when it is done."
        :confirmWithText="false" :confirmWithPassword="false" step2ButtonText="Remove">
        @if ($triggerId)
            <x-slot:trigger>
                <button id="{{ $triggerId }}" type="button">{{ $buttonTitle }}</button>
            </x-slot:trigger>
        @endif
    </x-modal-confirmation>
@endif
