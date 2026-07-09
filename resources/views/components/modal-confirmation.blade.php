@props([
    'title' => 'Are you sure?',
    'isErrorButton' => false,
    'isHighlightedButton' => false,
    'buttonTitle' => 'Confirm Action',
    'buttonFullWidth' => false,
    'customButton' => null,
    'disabled' => false,
    'dispatchAction' => false,
    'submitAction' => 'delete',
    'content' => null,
    'checkboxes' => [],
    'actions' => [],
    'warningMessage' => null,
    'confirmWithText' => true,
    'confirmationText' => 'Confirm Deletion',
    'confirmationLabel' => 'Please confirm the execution of the actions by entering the Name below',
    'shortConfirmationLabel' => 'Name',
    'confirmWithPassword' => true,
    'step1ButtonText' => 'Continue',
    'step2ButtonText' => 'Continue',
    'step3ButtonText' => 'Confirm',
    'dispatchEvent' => false,
    'dispatchEventType' => 'success',
    'dispatchEventMessage' => '',
    'ignoreWire' => true,
    'temporaryDisableTwoStepConfirmation' => false,
])

@php
    use App\Models\InstanceSettings;
    $disableTwoStepConfirmation = data_get(InstanceSettings::find(0), 'disable_two_step_confirmation', false);
    $skipPasswordConfirmation = shouldSkipPasswordConfirmation();
    if ($temporaryDisableTwoStepConfirmation) {
        $disableTwoStepConfirmation = false;
    }
    $effectiveStep2ButtonText = ($skipPasswordConfirmation && $step2ButtonText === 'Continue') ? 'Confirm' : $step2ButtonText;
@endphp

<div {{ $ignoreWire ? 'wire:ignore' : '' }} x-data="{ modalOpen: false }">
    {{-- full modal template unchanged on remote --}}
</div>
