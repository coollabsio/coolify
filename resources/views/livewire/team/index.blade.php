<div>
    <x-slot:title>
        Teams | Coolify
    </x-slot>

    <x-team.settings-layout>
    <div class="flex flex-col gap-6">
        <form wire:submit="submit" class="application-settings-form">
            <x-unsaved-bar action="submit" />
            <x-application.settings-section title="General"
                description="Manage this team's identity and shared API access.">
                <x-slot:actions>
                    <x-modal-input buttonTitle="New team" title="New Team">
                        <livewire:team.create />
                    </x-modal-input>
                </x-slot:actions>
                <div class="grid gap-4 lg:grid-cols-2">
                    <div class="lg:col-span-2" x-data="{
                        processing: false,
                        uploadError: null,
                        async prepareAvatar(event) {
                            const file = event.target.files?.[0];
                            if (!file) return;
                            this.processing = true;
                            this.uploadError = null;

                            try {
                                const image = await new Promise((resolve, reject) => {
                                    const element = new Image();
                                    element.onload = () => resolve(element);
                                    element.onerror = reject;
                                    element.src = URL.createObjectURL(file);
                                });
                                const cropSize = Math.min(image.naturalWidth, image.naturalHeight);
                                const canvas = document.createElement('canvas');
                                canvas.width = 256;
                                canvas.height = 256;
                                const context = canvas.getContext('2d');
                                context.fillStyle = '#ffffff';
                                context.fillRect(0, 0, 256, 256);
                                context.drawImage(image, (image.naturalWidth - cropSize) / 2, (image.naturalHeight - cropSize) / 2, cropSize, cropSize, 0, 0, 256, 256);
                                const blob = await new Promise((resolve, reject) => canvas.toBlob(value => value ? resolve(value) : reject(new Error('JPEG compression failed')), 'image/jpeg', 0.8));
                                const compressed = new File([blob], 'team-avatar.jpg', { type: 'image/jpeg' });
                                this.$wire.upload('avatar', compressed, async () => {
                                    await this.$wire.uploadAvatar();
                                    this.processing = false;
                                }, () => {
                                    this.processing = false;
                                    this.uploadError = 'The image could not be uploaded.';
                                });
                            } catch (error) {
                                this.processing = false;
                                this.uploadError = 'The image could not be processed in this browser.';
                            }
                            event.target.value = '';
                        },
                    }">
                        <div class="mb-2 text-sm font-medium leading-4">Avatar</div>
                        <div class="flex items-center gap-4">
                            <x-team.avatar :team="$team" class="size-16 rounded-xl! text-2xl" />
                            @can('update', $team)
                                <div class="flex min-w-0 flex-1 flex-col gap-2">
                                    <div class="flex flex-wrap gap-2">
                                        <input x-ref="avatarInput" type="file" x-on:change="prepareAvatar($event)" accept="image/jpeg,image/png,image/webp" class="hidden">
                                        <x-forms.button type="button" x-on:click="$refs.avatarInput.click()" x-bind:disabled="processing">
                                            <span x-text="processing ? 'Uploading…' : 'Browse…'"></span>
                                        </x-forms.button>
                                        @if ($team->avatar_path)
                                            <x-forms.button type="button" wire:click="removeAvatar" x-bind:disabled="processing" isError>Remove</x-forms.button>
                                        @endif
                                    </div>
                                    <p class="text-xs text-neutral-500 dark:text-fg-dim">JPG, PNG, or WebP. Shown in the team switcher.</p>
                                    <p x-cloak x-show="uploadError" x-text="uploadError" class="text-xs text-red-500"></p>
                                    @error('avatar') <p class="text-xs text-red-500">{{ $message }}</p> @enderror
                                </div>
                            @endcan
                        </div>
                    </div>
                    <x-forms.input id="name" label="Name" required canGate="update" :canResource="$team" />
                    <x-forms.input id="description" label="Description" canGate="update" :canResource="$team" />
                    <div class="lg:col-span-2">
                        <x-forms.listbox canGate="update" :canResource="$team" id="is_mcp_server_enabled" label="MCP server"
                            helper="Controls whether this team's API tokens can use the instance MCP endpoint."
                            :disabled="! auth()->user()->can('update', $team)" :options="[
                                ['value' => false, 'label' => 'Disabled for this team'],
                                ['value' => true, 'label' => 'Enabled for this team'],
                            ]" />
                    </div>
                    <div class="lg:col-span-2">
                        <x-forms.listbox canGate="update" :canResource="$team"
                            id="is_build_server_fallback_enabled" label="Build server fallback"
                            helper="Controls what happens when an application is configured to use a dedicated build server, but no usable build server is available."
                            :disabled="! auth()->user()->can('update', $team)" :options="[
                                ['value' => true, 'label' => 'Build on the deployment server'],
                                ['value' => false, 'label' => 'Fail the deployment'],
                            ]" />
                    </div>
                    <div class="lg:col-span-2">
                        <x-forms.listbox canGate="update" :canResource="$team" id="is_two_factor_required"
                            label="Two-factor authentication"
                            helper="When required, members without two-factor authentication must configure it before they can use this team."
                            :disabled="! auth()->user()->can('update', $team)" :options="[
                                ['value' => false, 'label' => 'Optional for members'],
                                ['value' => true, 'label' => 'Required for all members'],
                            ]" />
                    </div>
                </div>
            </x-application.settings-section>
        </form>

    </div>
    </x-team.settings-layout>
</div>
