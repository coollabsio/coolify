<div class="w-full">
    @if ($limit_reached)
        <x-limit-reached name="servers" />
    @else
        @if ($current_step === 1)
            <div class="flex flex-col w-full gap-4">
                @if ($available_tokens->count() > 0)
                    <div class="flex gap-2">
                        <div class="flex-1">
                            <x-forms.select label="Select OpenStack Credential" id="selected_token_id"
                                wire:change="selectToken($event.target.value)" required>
                                <option value="">Select a saved credential...</option>
                                @foreach ($available_tokens as $token)
                                    <option value="{{ $token->id }}">
                                        {{ $token->name ?? 'OpenStack Credential' }}
                                    </option>
                                @endforeach
                            </x-forms.select>
                        </div>
                        <div class="flex items-end">
                            <x-forms.button canGate="create" :canResource="App\Models\Server::class" wire:click="nextStep"
                                :disabled="!$selected_token_id">
                                Continue
                            </x-forms.button>
                        </div>
                    </div>

                    <div class="text-center text-sm dark:text-neutral-500">OR</div>
                @endif

                <x-modal-input isFullWidth
                    buttonTitle="{{ $available_tokens->count() > 0 ? '+ Add New Credential' : 'Add OpenStack Credential' }}"
                    title="Add OpenStack Credential">
                    <livewire:security.cloud-provider-token-form :modal_mode="true" provider="openstack" />
                </x-modal-input>
            </div>
        @elseif ($current_step === 2)
            @if ($loading_data)
                <div class="flex items-center justify-center py-8">
                    <div class="text-center">
                        <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-primary mx-auto"></div>
                        <p class="mt-4 text-sm dark:text-neutral-400">Loading OpenStack data...</p>
                    </div>
                </div>
            @else
                <form class="flex flex-col w-full gap-2" wire:submit='submit'>
                    <div>
                        <x-forms.input id="server_name" label="Server Name" helper="A friendly name for your server." />
                    </div>

                    <div>
                        <x-forms.input id="server_user" label="SSH User"
                            helper="The login user for the selected image. Common defaults: ubuntu, debian, centos, cloud-user, or root." />
                    </div>

                    <div>
                        <x-forms.select label="Availability Zone (optional)" id="selected_availability_zone"
                            wire:model="selected_availability_zone"
                            helper="Leave empty to let OpenStack choose an availability zone.">
                            <option value="">Let OpenStack decide...</option>
                            @foreach ($availabilityZones as $zone)
                                <option value="{{ $zone['zoneName'] }}">{{ $zone['zoneName'] }}</option>
                            @endforeach
                        </x-forms.select>
                    </div>

                    <div>
                        <x-forms.select label="Flavor (Server Type)" id="selected_flavor"
                            wire:model.live="selected_flavor" required>
                            <option value="">Select a flavor...</option>
                            @foreach ($flavors as $flavor)
                                <option value="{{ $flavor['id'] }}">
                                    {{ $flavor['name'] }} - {{ $flavor['vcpus'] }} vCPU,
                                    {{ $flavor['ram'] }}MB RAM,
                                    {{ ($flavor['disk'] ?? 0) > 0 ? $flavor['disk'] . 'GB disk' : 'no local disk (boot from volume)' }}
                                </option>
                            @endforeach
                        </x-forms.select>
                    </div>

                    <div>
                        <x-forms.input type="number" id="volume_size" label="Root Volume Size (GB)"
                            :required="$this->selectedFlavorIsDiskless()"
                            helper="Required for flavors without a local disk: the instance boots from a new volume of this size (deleted with the server). Leave empty to use the flavor's local disk." />
                    </div>

                    <div>
                        <x-forms.select label="Image" id="selected_image" wire:model="selected_image" required>
                            <option value="">Select an image...</option>
                            @foreach ($images as $image)
                                <option value="{{ $image['id'] }}">
                                    {{ $image['name'] }}
                                </option>
                            @endforeach
                        </x-forms.select>
                    </div>

                    <div>
                        <x-forms.select label="Network" id="selected_network" wire:model="selected_network" required>
                            <option value="">Select a network...</option>
                            @foreach ($networks as $network)
                                <option value="{{ $network['id'] }}">{{ $network['name'] }}</option>
                            @endforeach
                        </x-forms.select>
                        <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                            The private network your instance attaches to.
                        </p>
                    </div>

                    <div class="flex flex-col gap-2">
                        <x-forms.checkbox id="assign_floating_ip" label="Assign a floating IP"
                            helper="Allocate and attach a floating IP so Coolify can reach the server. Disable only if the selected network is already routable from Coolify." />
                        @if ($assign_floating_ip)
                            <x-forms.select label="External Network (floating IP pool)" id="selected_external_network"
                                wire:model="selected_external_network" :required="$assign_floating_ip"
                                :disabled="count($externalNetworks) === 0">
                                <option value="">
                                    {{ count($externalNetworks) > 0 ? 'Select an external network...' : 'No external networks found' }}
                                </option>
                                @foreach ($externalNetworks as $network)
                                    <option value="{{ $network['id'] }}">{{ $network['name'] }}</option>
                                @endforeach
                            </x-forms.select>
                        @endif
                    </div>

                    <div>
                        @if ($private_keys->count() === 0)
                            <div class="flex flex-col gap-2">
                                <label class="flex gap-1 items-center mb-1 text-sm font-medium">
                                    Private Key
                                    <x-highlighted text="*" />
                                </label>
                                <div
                                    class="p-4 border border-warning-500 dark:border-warning-600 rounded bg-warning-50 dark:bg-warning-900/10">
                                    <p class="text-sm mb-3 text-neutral-700 dark:text-neutral-300">
                                        No private keys found. You need to create a private key to continue.
                                    </p>
                                    <x-modal-input buttonTitle="Create New Private Key" title="New Private Key" isHighlightedButton>
                                        <livewire:security.private-key.create :modal_mode="true" from="server" />
                                    </x-modal-input>
                                </div>
                            </div>
                        @else
                            <x-forms.select label="Private Key" id="private_key_id" required>
                                <option value="">Select a private key...</option>
                                @foreach ($private_keys as $key)
                                    <option value="{{ $key->id }}">
                                        {{ $key->name }}
                                    </option>
                                @endforeach
                            </x-forms.select>
                            <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                                This SSH key will be uploaded to OpenStack as a keypair and used to access the server.
                            </p>
                        @endif
                    </div>

                    <div class="flex flex-col gap-2">
                        <div class="flex justify-between items-center gap-2">
                            <label class="text-sm font-medium w-32">Cloud-Init Script</label>
                            @if ($saved_cloud_init_scripts->count() > 0)
                                <div class="flex items-center gap-2 flex-1">
                                    <x-forms.select wire:model.live="selected_cloud_init_script_id" label="" helper="">
                                        <option value="">Load saved script...</option>
                                        @foreach ($saved_cloud_init_scripts as $script)
                                            <option value="{{ $script->id }}">{{ $script->name }}</option>
                                        @endforeach
                                    </x-forms.select>
                                    <x-forms.button type="button" wire:click="clearCloudInitScript">
                                        Clear
                                    </x-forms.button>
                                </div>
                            @endif
                        </div>
                        <x-forms.textarea id="cloud_init_script" label=""
                            helper="Add a cloud-init script to run when the server is created."
                            rows="8" />

                        <div class="flex items-center gap-2">
                            <x-forms.checkbox id="save_cloud_init_script" label="Save this script for later use" />
                            <div class="flex-1">
                                <x-forms.input id="cloud_init_script_name" label="" placeholder="Script name..." />
                            </div>
                        </div>
                    </div>

                    <div class="flex gap-2 justify-between">
                        <x-forms.button type="button" wire:click="previousStep">
                            Back
                        </x-forms.button>
                        <x-forms.button isHighlighted canGate="create" :canResource="App\Models\Server::class" type="submit"
                            :disabled="!$private_key_id">
                            Create Server
                        </x-forms.button>
                    </div>
                </form>
            @endif
        @endif
    @endif
</div>
