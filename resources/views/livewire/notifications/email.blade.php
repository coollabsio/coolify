<div>
    <x-slot:title>
        Notifications | Coolify
    </x-slot>

    <x-notification.navbar />

    <div class="flex flex-col gap-6">
        <form wire:submit="submit" class="application-settings-form">
            <x-unsaved-bar action="submit" />
            <x-application.settings-section title="Email delivery">
                <x-slot:actions>
                    @if (auth()->user()->isAdminFromSession())
                        @can('sendTest', $settings)
                            @if ($team->isNotificationEnabled('email'))
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
                                                class="button bg-coollabs/10! text-coollabs! ring-1 ring-coollabs/25 hover:bg-coollabs/15! dark:bg-warning/15! dark:text-warning! dark:ring-warning/25 dark:hover:bg-warning/20!">
                                                Send email
                                            </button>
                                        </div>
                                    </form>
                                </x-modal-input>
                            @else
                                <button type="button" class="button" disabled>Send test</button>
                            @endif
                        @endcan
                    @endif
                </x-slot:actions>

                <div class="grid gap-4 lg:grid-cols-2">
                    <div class="lg:col-span-2">
                        @if (isCloud())
                            <div class="w-full sm:w-72">
                                <x-forms.listbox id="useInstanceEmailSettings" label="Email service"
                                    onChange="instantSave"
                                    :disabled="!auth()->user()->can('update', $settings)" :options="[
                                        ['value' => true, 'label' => 'Use hosted email service'],
                                        ['value' => false, 'label' => 'Use team email settings'],
                                    ]" />
                            </div>
                        @else
                            <div class="w-full sm:w-72">
                                <x-forms.listbox id="useInstanceEmailSettings" label="Email service"
                                    onChange="instantSave"
                                    :disabled="!auth()->user()->can('update', $settings)" :options="[
                                        ['value' => true, 'label' => 'Use system-wide settings'],
                                        ['value' => false, 'label' => 'Use team email settings'],
                                    ]" />
                            </div>
                        @endif
                    </div>

                    @if (!$useInstanceEmailSettings)
                        <x-forms.input canGate="update" :canResource="$settings" required id="smtpFromName"
                            helper="Name used in emails." label="From name" />
                        <x-forms.input canGate="update" :canResource="$settings" required id="smtpFromAddress"
                            helper="Email address used in emails." label="From address" />

                        @if (isInstanceAdmin())
                            <div class="lg:col-span-2">
                                <button type="button" class="button" wire:click="copyFromInstanceSettings">
                                    Copy from instance settings
                                </button>
                            </div>
                        @endif
                    @endif
                </div>
            </x-application.settings-section>
        </form>

        @if (!$useInstanceEmailSettings)
            <div class="application-settings-form">
                <x-application.settings-section title="SMTP server"
                    description="Deliver messages through your own SMTP server.">
                    <div class="grid gap-4 lg:grid-cols-3">
                        <div class="lg:col-span-3">
                            <div class="w-full sm:w-72">
                                <x-forms.listbox id="smtpEnabled" label="SMTP delivery"
                                    onChange="submitSmtp"
                                    :disabled="!auth()->user()->can('update', $settings)" :options="[
                                        ['value' => true, 'label' => 'Enabled'],
                                        ['value' => false, 'label' => 'Disabled'],
                                    ]" />
                            </div>
                        </div>
                        <x-forms.input canGate="update" :canResource="$settings" required id="smtpHost"
                            placeholder="smtp.mailgun.org" label="Host" />
                        <x-forms.input canGate="update" :canResource="$settings" required id="smtpPort"
                            type="number" placeholder="587" label="Port" />
                        <x-forms.listbox id="smtpEncryption" label="Encryption" required
                            :disabled="!auth()->user()->can('update', $settings)" :options="[
                            ['value' => 'starttls', 'label' => 'StartTLS'],
                            ['value' => 'tls', 'label' => 'TLS / SSL'],
                            ['value' => 'none', 'label' => 'None'],
                        ]" />
                        <x-forms.input canGate="update" :canResource="$settings" id="smtpUsername"
                            label="SMTP username" />
                        @can('update', $settings)
                            <x-forms.input canGate="update" :canResource="$settings" id="smtpPassword" type="password"
                                label="SMTP password" />
                        @else
                            <x-forms.input disabled label="SMTP password" value="Hidden (only admins can view)" />
                        @endcan
                        <x-forms.input canGate="update" :canResource="$settings" id="smtpTimeout" type="number"
                            helper="Timeout value for sending emails." label="Timeout" />
                    </div>
                </x-application.settings-section>
            </div>

            <div class="application-settings-form">
                <x-application.settings-section title="Resend"
                    description="Use Resend as an alternative email delivery provider.">
                    <div class="grid gap-4 lg:grid-cols-2">
                        <div class="lg:col-span-2">
                            <div class="w-full sm:w-72">
                                <x-forms.listbox id="resendEnabled" label="Resend delivery"
                                    onChange="submitResend"
                                    :disabled="!auth()->user()->can('update', $settings)" :options="[
                                        ['value' => true, 'label' => 'Enabled'],
                                        ['value' => false, 'label' => 'Disabled'],
                                    ]" />
                            </div>
                        </div>
                        @can('update', $settings)
                            <x-forms.input canGate="update" :canResource="$settings" required type="password"
                                id="resendApiKey" placeholder="API key" label="API key" />
                        @else
                            <x-forms.input disabled label="API key" value="Hidden (only admins can view)" />
                        @endcan
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
                            ['property' => 'statusChangeEmailNotifications', 'label' => 'Container status changes', 'enabled' => $statusChangeEmailNotifications],
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
            </x-application.settings-section>
        </div>
    </div>
</div>
