<?php

use App\Models\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->firstOrCreate(['id' => 0]));
});

it('keeps service deployment copy and handler isolated from destination removal', function () {
    $html = Blade::render(<<<'BLADE'
        <x-modal-confirmation
            title="Confirm Service Deployment?"
            buttonTitle="Deploy"
            submitAction="startEvent"
            :dispatchAction="true"
            :actions="['This service will be deployed.']"
            :confirmWithText="false"
            :confirmWithPassword="false"
            step2ButtonText="Confirm"
        />
    BLADE);

    expect($html)
        ->toContain('Confirm Service Deployment?')
        ->toContain('This service will be deployed.')
        ->toContain("submitAction: 'startEvent'")
        ->toContain('>Confirm</span>')
        ->not->toContain('Remove Destination')
        ->not->toContain('Remove from Destinations')
        ->not->toContain('deleteNetwork');
});

it('renders destination removal copy only when explicitly configured', function () {
    $html = Blade::render(<<<'BLADE'
        <x-modal-confirmation
            title="Remove Destination?"
            buttonTitle="Remove Destination"
            submitAction="removeFromDestinations(42)"
            safeTitle="Remove from Destinations"
            safeButtonTitle="Remove Destination"
            safeMessage="This removes the network from Destinations."
            confirmWithTextAction="deleteNetwork"
            :confirmWithText="true"
            :confirmWithPassword="false"
            :checkboxes="[['id' => 'deleteNetwork', 'label' => 'Delete Docker network permanently.']]"
            :initialActions="[]"
            :inlineActionSelection="true"
        />
    BLADE);

    expect($html)
        ->toContain('Remove Destination?')
        ->toContain('Remove from Destinations')
        ->toContain('Remove Destination</span>')
        ->toContain("submitAction: 'removeFromDestinations(42)'")
        ->toContain("confirmWithTextAction: 'deleteNetwork'");
});

it('keeps multiple confirmation modal contracts independent', function () {
    $html = Blade::render(<<<'BLADE'
        <div>
            <x-modal-confirmation
                title="Confirm Service Deployment?"
                submitAction="startEvent"
                :dispatchAction="true"
                :actions="['This service will be deployed.']"
                :confirmWithText="false"
                :confirmWithPassword="false"
                step2ButtonText="Confirm"
            />
            <x-modal-confirmation
                title="Confirm Database Start?"
                submitAction="startDatabaseEvent"
                :dispatchAction="true"
                :actions="['This database will be started.']"
                :confirmWithText="false"
                :confirmWithPassword="false"
                step2ButtonText="Start"
            />
        </div>
    BLADE);

    expect(substr_count($html, "submitAction: 'startEvent'"))->toBe(1)
        ->and(substr_count($html, "submitAction: 'startDatabaseEvent'"))->toBe(1)
        ->and($html)->not->toContain('Remove Destination')
        ->and($html)->not->toContain('deleteNetwork');
});

it('resets modal-specific action state after close', function () {
    $html = Blade::render(<<<'BLADE'
        <x-modal-confirmation
            title="Remove Destination?"
            submitAction="removeFromDestinations(42)"
            confirmWithTextAction="deleteNetwork"
            :checkboxes="[['id' => 'deleteNetwork', 'label' => 'Delete Docker network permanently.']]"
            :initialActions="[]"
            :inlineActionSelection="true"
        />
    BLADE);

    expect($html)
        ->toContain('this.selectedActions = [...this.initialActions];')
        ->toContain("this.userConfirmationText = '';")
        ->toContain("this.password = '';");
});
