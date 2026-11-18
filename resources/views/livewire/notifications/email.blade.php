<div>
    <x-slot:title>
        Notifications | Coolify
    </x-slot>

    <x-notification.settings-layout>
    <div class="flex flex-col gap-6">
        <form wire:submit="submit" class="application-settings-form">
            <x-unsaved-bar action="submit" />
            <x-application.settings-section title="Email delivery">
                <x-slot:actions>
                    @php
                        $canSendTest = auth()->user()->isAdminFromSession() && auth()->user()->can('sendTest', $settings);
                        $sendTestReason = match (true) {
                            ! $canSendTest => 'Only admins can send a test email.',
                            ! $team->isNotificationEnabled('email') => 'Save an email provider first.',
                            default => null,
                        };
                    @endphp
                    @if ($sendTestReason === null)
                        <x-modal-input title="Send Test Email">
                            <x-slot:content>
                                <button type="button" class="button">
                                    <x-reicon name="notifications" class="size-3.5" />
                                    Send test
                                </button>
                            </x-slot:content>
                            <form wire:submit.prevent="sendTestEmail" class="flex w-full flex-col gap-4">
                                <x-forms.input wire:model="testEmailAddress" placeholder="test@example.com"
                                    id="testEmailAddress" label="Recipient" required />
                                <div class="flex justify-end border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
                                    <button type="submit" @click="modalOpen=false"
                                        class="button button-highlighted">
                                        Send email
                                    </button>
                                </div>
                            </form>
                        </x-modal-input>
                    @else
                        <button type="button" class="button" disabled title="{{ $sendTestReason }}">
                            <x-reicon name="notifications" class="size-3.5" />
                            Send test
                        </button>
                    @endif
                </x-slot:actions>

                <div class="grid gap-4 lg:grid-cols-2">
                    <div class="lg:col-span-2">
                        @if (isCloud())
                            <div class="w-full sm:w-72">
                                <x-forms.listbox canGate="update" :canResource="$settings" id="useInstanceEmailSettings" label="Email service"
                                    onChange="instantSave"
                                    :disabled="!auth()->user()->can('update', $settings)" :options="[
                                        ['value' => true, 'label' => 'Use hosted email service'],
                                        ['value' => false, 'label' => 'Use team email settings'],
                                    ]" />
                            </div>
                        @else
                            <div class="w-full sm:w-72">
                                <x-forms.listbox canGate="update" :canResource="$settings" id="useInstanceEmailSettings" label="Email service"
                                    onChange="instantSave"
                                    :disabled="!auth()->user()->can('update', $settings)" :options="[
                                        ['value' => true, 'label' => 'Use system-wide settings'],
                                        ['value' => false, 'label' => 'Use team email settings'],
                                    ]" />
                            </div>
                        @endif
                    </div>
                </div>
            </x-application.settings-section>
        </form>

        @if (!$useInstanceEmailSettings)
            <div class="application-settings-form">
                <x-application.settings-section title="Email provider"
                    description="Only one provider sends email. Select a provider to see its settings.">
                    <x-slot:badges>
                        <div class="w-40">
                            <x-forms.listbox canGate="update" :canResource="$settings" id="emailProvider"
                                onChange="selectEmailProvider"
                                :disabled="!auth()->user()->can('update', $settings)"
                                :options="collect(email_provider_labels())
                                    ->map(fn ($label, $value) => ['value' => $value, 'label' => $label])
                                    ->values()
                                    ->all()" />
                        </div>
                    </x-slot:badges>
                    <div class="grid gap-4 lg:grid-cols-3">
                        @if ($emailProvider === 'none')
                            <p class="text-sm text-neutral-500 lg:col-span-3 dark:text-neutral-400">
                                The team does not get email notifications. Select a provider next to the title to set it up.
                            </p>
                        @endif

                        @if ($emailProvider !== $this->activeEmailProvider)
                            <div class="lg:col-span-3">
                                <x-callout type="warning" title="Not active yet">
                                    Enter the {{ email_provider_labels()[$emailProvider] }} settings and save.
                                    @if ($this->activeEmailProvider === 'none')
                                        Until then, the team does not get email notifications.
                                    @else
                                        Until then, Coolify sends email with
                                        {{ email_provider_labels()[$this->activeEmailProvider] }}.
                                    @endif
                                </x-callout>
                            </div>
                        @endif

                        @if ($emailProvider === 'smtp')
                            @include('livewire.partials.smtp-server-picker')
                        @elseif ($emailProvider === 'resend')
                            <div>
                                @can('update', $settings)
                                    <x-forms.input canGate="update" :canResource="$settings" required type="password"
                                        id="resendApiKey" placeholder="API key" label="Resend API key"
                                        autocomplete="new-password" />
                                @else
                                    <x-forms.input disabled label="Resend API key" value="Hidden (only admins can view)" />
                                @endcan
                            </div>
                            <x-forms.input canGate="update" :canResource="$settings" required id="resendFromName"
                                helper="Name shown in email from Resend." label="From name" />
                            <x-forms.input canGate="update" :canResource="$settings" required id="resendFromAddress"
                                helper="Address used in email from Resend. It must be on a domain verified in Resend."
                                label="From address" />
                        @endif
                    </div>
                </x-application.settings-section>
            </div>
        @endif

        <div class="application-settings-form">
            <x-application.settings-section title="Notification events">
                <div class="grid gap-4 lg:grid-cols-2">
                    <x-notification.event-multiselect :settings="$settings" id="deployment-email-events" label="Deployments"
                        :events="[
                            ['property' => 'deploymentSuccessEmailNotifications', 'label' => 'Deployment success', 'enabled' => $deploymentSuccessEmailNotifications],
                            ['property' => 'deploymentFailureEmailNotifications', 'label' => 'Deployment failure', 'enabled' => $deploymentFailureEmailNotifications],
                        ]" />
                    <x-notification.event-multiselect :settings="$settings" id="resource-email-events" label="Resources"
                        :events="[
                            ['property' => 'statusChangeEmailNotifications', 'label' => 'Resource status changes', 'enabled' => $statusChangeEmailNotifications],
                            ['property' => 'restartLimitReachedEmailNotifications', 'label' => 'Restart limit reached', 'enabled' => $restartLimitReachedEmailNotifications],
                            ['property' => 'imageVulnerabilitiesEmailNotifications', 'label' => 'Image vulnerabilities', 'enabled' => $imageVulnerabilitiesEmailNotifications],
                        ]" />
                    <x-notification.event-multiselect :settings="$settings" id="backup-email-events" label="Backups"
                        :events="[
                            ['property' => 'backupSuccessEmailNotifications', 'label' => 'Backup success', 'enabled' => $backupSuccessEmailNotifications],
                            ['property' => 'backupFailureEmailNotifications', 'label' => 'Backup failure', 'enabled' => $backupFailureEmailNotifications],
                        ]" />
                    <x-notification.event-multiselect :settings="$settings" id="scheduled-task-email-events"
                        label="Scheduled tasks" :events="[
                            ['property' => 'scheduledTaskSuccessEmailNotifications', 'label' => 'Scheduled task success', 'enabled' => $scheduledTaskSuccessEmailNotifications],
                            ['property' => 'scheduledTaskFailureEmailNotifications', 'label' => 'Scheduled task failure', 'enabled' => $scheduledTaskFailureEmailNotifications],
                        ]" />
                    <x-notification.event-multiselect :settings="$settings" id="server-email-events" label="Server"
                        :events="[
                            ['property' => 'dockerCleanupSuccessEmailNotifications', 'label' => 'Docker cleanup success', 'enabled' => $dockerCleanupSuccessEmailNotifications],
                            ['property' => 'dockerCleanupFailureEmailNotifications', 'label' => 'Docker cleanup failure', 'enabled' => $dockerCleanupFailureEmailNotifications],
                            ['property' => 'serverDiskUsageEmailNotifications', 'label' => 'Server disk usage', 'enabled' => $serverDiskUsageEmailNotifications],
                            ['property' => 'serverReachableEmailNotifications', 'label' => 'Server reachable', 'enabled' => $serverReachableEmailNotifications],
                            ['property' => 'serverUnreachableEmailNotifications', 'label' => 'Server unreachable', 'enabled' => $serverUnreachableEmailNotifications],
                            ['property' => 'serverPatchEmailNotifications', 'label' => 'Server patching', 'enabled' => $serverPatchEmailNotifications],
                            ['property' => 'traefikOutdatedEmailNotifications', 'label' => 'Traefik proxy outdated', 'enabled' => $traefikOutdatedEmailNotifications],
                        ]" />
                </div>
                <div class="mt-4">
                    <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="instantSaveDeploymentCommitDetails"
                        id="deploymentCommitDetailsEmailNotifications"
                        label="Include commit details in deployment emails"
                        helper="Adds the commit SHA, branch, and commit message to deployment success and failure emails." />
                </div>
            </x-application.settings-section>
        </div>
    </div>
    </x-notification.settings-layout>
</div>
