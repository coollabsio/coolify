@props([
    'database',
    'label',
    'dbUrl' => null,
    'dbUrlPublic' => null,
    'supportsSsl' => true,
    'enableSsl' => false,
    'sslMode' => null,
    'sslModeOptions' => null,
    'sslModeHelper' => null,
    'certificateValidUntil' => null,
    'isExited' => false,
    'showPublicUrlPlaceholder' => false,
])

@php
    $urlHelper = 'If you change the user/password/port, this could be different. This is with the default values.';
@endphp

<div class="flex flex-col gap-2">
    <x-forms.input :label="$label . ' URL (internal)'" :helper="$urlHelper" type="password" readonly
        wire:model="dbUrl" canGate="update" :canResource="$database" />
    @if ($dbUrlPublic)
        <x-forms.input :label="$label . ' URL (public)'" :helper="$urlHelper" type="password" readonly
            wire:model="dbUrlPublic" canGate="update" :canResource="$database" />
    @elseif ($showPublicUrlPlaceholder)
        <x-forms.input :label="$label . ' URL (public)'" :helper="$urlHelper" readonly
            value="Starting the database will generate this." canGate="update" :canResource="$database" />
    @endif

    @if ($supportsSsl)
        <div class="flex flex-col gap-2 pt-4">
            <div class="flex items-center justify-between py-2">
                <div class="flex items-center justify-between w-full">
                    <h3>SSL Configuration</h3>
                    @if ($enableSsl && $certificateValidUntil)
                        <x-modal-confirmation title="Regenerate SSL Certificates"
                            buttonTitle="Regenerate SSL Certificates" :actions="[
                                'The SSL certificate of this database will be regenerated.',
                                'You must restart the database after regenerating the certificate to start using the new certificate.',
                            ]"
                            submitAction="regenerateSslCertificate" :confirmWithText="false" :confirmWithPassword="false" />
                    @endif
                </div>
            </div>
            @if ($enableSsl && $certificateValidUntil)
                <span class="text-sm">Valid until:
                    @if (now()->gt($certificateValidUntil))
                        <span class="text-red-500">{{ $certificateValidUntil->format('d.m.Y H:i:s') }} - Expired</span>
                    @elseif(now()->addDays(30)->gt($certificateValidUntil))
                        <span class="text-red-500">{{ $certificateValidUntil->format('d.m.Y H:i:s') }} - Expiring
                            soon</span>
                    @else
                        <span>{{ $certificateValidUntil->format('d.m.Y H:i:s') }}</span>
                    @endif
                </span>
            @endif
            <div class="flex flex-col gap-2">
                <div class="w-64" wire:key="enable_ssl">
                    @if ($isExited)
                        <x-forms.checkbox id="enableSsl" label="Enable SSL" wire:model.live="enableSsl"
                            instantSave="instantSaveSSL" canGate="update" :canResource="$database" />
                    @else
                        <x-forms.checkbox id="enableSsl" label="Enable SSL" wire:model.live="enableSsl"
                            instantSave="instantSaveSSL" disabled
                            helper="Database should be stopped to change this setting." canGate="update"
                            :canResource="$database" />
                    @endif
                </div>
                @if ($sslModeOptions && $enableSsl)
                    <div class="mx-2">
                        @if ($isExited)
                            <x-forms.select id="sslMode" label="SSL Mode" wire:model.live="sslMode"
                                instantSave="instantSaveSSL" :helper="$sslModeHelper" canGate="update"
                                :canResource="$database">
                                @foreach ($sslModeOptions as $value => $option)
                                    <option value="{{ $value }}" title="{{ $option['title'] ?? '' }}">{{ $option['label'] }}</option>
                                @endforeach
                            </x-forms.select>
                        @else
                            <x-forms.select id="sslMode" label="SSL Mode" instantSave="instantSaveSSL" disabled
                                helper="Database should be stopped to change this setting." canGate="update"
                                :canResource="$database">
                                @foreach ($sslModeOptions as $value => $option)
                                    <option value="{{ $value }}" title="{{ $option['title'] ?? '' }}">{{ $option['label'] }}</option>
                                @endforeach
                            </x-forms.select>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
