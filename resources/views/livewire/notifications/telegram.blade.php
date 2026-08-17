<div>
    <x-slot:title>
        Telegram Notifications | Coolify
    </x-slot>

    <x-notification.settings-layout>
    <div class="application-settings-form flex flex-col gap-6">
        <form wire:submit="submit">
            <x-unsaved-bar action="submit" />
            <x-application.settings-section title="Telegram"
                description="Deliver team notifications through a Telegram bot and chat.">
                <x-slot:actions>
                    <x-notification.channel-actions :enabled="$telegramEnabled" enabledProperty="telegramEnabled"
                        toggleMethod="instantSaveTelegramEnabled" :canUpdate="auth()->user()->can('update', $settings)" />
                </x-slot:actions>

                <div class="grid gap-4 lg:grid-cols-2">
                    @can('update', $settings)
                        <x-forms.input type="password" autocomplete="new-password" required id="telegramToken"
                            label="Bot API token" helper="Create a bot with BotFather to obtain this token." />
                        <x-forms.input type="password" autocomplete="new-password" required id="telegramChatId"
                            label="Chat ID" helper="Add the bot to your chat, then enter that chat ID." />
                    @else
                        <x-forms.input disabled label="Bot API token" value="Hidden (only admins can view)" />
                        <x-forms.input disabled label="Chat ID" value="Hidden (only admins can view)" />
                    @endcan
                </div>
            </x-application.settings-section>
        </form>

        <x-notification.event-grid :settings="$settings" channel="telegram" threaded />
    </div>
    </x-notification.settings-layout>
</div>
