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
    'isPasswordHiddenForMember' => false,
])

@php
    $urlHelper = 'If you change the user/password/port, this could be different. This is with the default values.';
@endphp

<div class="space-y-5">
    @if ($isPasswordHiddenForMember)
        <x-forms.input :label="$label . ' URL (internal)'" disabled value="Hidden (only admins can view)" />
        <x-forms.input :label="$label . ' URL (public)'" disabled value="Hidden (only admins can view)" />
    @else
        <x-forms.input :label="$label . ' URL (internal)'" :helper="$urlHelper" type="password" readonly
            wire:model="dbUrl" canGate="update" :canResource="$database" />
        @if ($dbUrlPublic)
            <x-forms.input :label="$label . ' URL (public)'" :helper="$urlHelper" type="password" readonly
                wire:model="dbUrlPublic" canGate="update" :canResource="$database" />
        @elseif ($showPublicUrlPlaceholder)
            <x-forms.input :label="$label . ' URL (public)'" :helper="$urlHelper" readonly
                value="Starting the database will generate this." canGate="update" :canResource="$database" />
        @endif
    @endif

    @if ($supportsSsl)
        <div class="border-t border-neutral-200 pt-5 dark:border-white/[0.06]">
            <div class="mb-4 flex items-center justify-between gap-3">
                    <div>
                        <h3 class="text-sm font-semibold text-black dark:text-fg">SSL configuration</h3>
                        <p class="mt-1 text-xs text-neutral-500 dark:text-fg-dim">
                            Encryption settings can only be changed while the database is stopped.
                        </p>
                    </div>
                    @if ($enableSsl && $certificateValidUntil)
                        <x-modal-confirmation title="Regenerate SSL Certificates"
                            buttonTitle="Regenerate SSL Certificates" :actions="[
                                'The SSL certificate of this database will be regenerated.',
                                'You must restart the database after regenerating the certificate to start using the new certificate.',
                            ]"
                            submitAction="regenerateSslCertificate" :confirmWithText="false" :confirmWithPassword="false" />
                    @endif
            </div>
            @if ($enableSsl && $certificateValidUntil)
                <div class="mb-4 text-sm text-neutral-600 dark:text-fg-dim">Valid until:
                    @if (now()->gt($certificateValidUntil))
                        <span class="text-red-500">{{ $certificateValidUntil->format('d.m.Y H:i:s') }} - Expired</span>
                    @elseif(now()->addDays(30)->gt($certificateValidUntil))
                        <span class="text-red-500">{{ $certificateValidUntil->format('d.m.Y H:i:s') }} - Expiring
                            soon</span>
                    @else
                        <span>{{ $certificateValidUntil->format('d.m.Y H:i:s') }}</span>
                    @endif
                </div>
            @endif
            <div class="grid gap-4 sm:grid-cols-2">
                <x-forms.listbox id="enableSsl" label="SSL"
                    onChange="instantSaveSSL"
                    :disabled="! $isExited || ! auth()->user()?->can('update', $database)"
                    :options="[
                        ['value' => true, 'label' => 'Enabled'],
                        ['value' => false, 'label' => 'Disabled'],
                    ]" />
                @if ($sslModeOptions && $enableSsl)
                    <x-forms.listbox id="sslMode" label="SSL mode" :helper="$sslModeHelper"
                        onChange="instantSaveSSL"
                        :disabled="! $isExited || ! auth()->user()?->can('update', $database)"
                        :options="collect($sslModeOptions)->map(fn ($option, $value) => [
                            'value' => $value,
                            'label' => $option['label'],
                        ])->values()->all()" />
                @endif
            </div>
        </div>
    @endif
</div>
