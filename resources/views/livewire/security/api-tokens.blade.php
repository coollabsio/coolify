<div>
    <x-slot:title>
        API Tokens | Coolify
    </x-slot>
    <x-security.navbar />
    <div class="pb-4">
        <h2>API Tokens</h2>
        @if (!$isApiEnabled)
            <div>API is disabled. If you want to use the API, please enable it in the <a
                    href="{{ route('settings.advanced') }}" class="underline dark:text-white" {{ wireNavigate() }}>Settings</a> menu.</div>
        @else
            <div>Tokens are created with the current team as scope.</div>
    </div>
    <h3>New Token</h3>
    @can('create', App\Models\PersonalAccessToken::class)
        <form class="flex flex-col gap-2" wire:submit='addNewToken'>
            <div class="flex gap-2 items-end w-lg">
                <x-forms.input class="w-64" required id="description" label="Description" />
                <x-forms.select id="expiresInDays" label="Expires in" wire:model="expiresInDays">
                    @foreach ($expirationOptions as $days => $label)
                        <option value="{{ $days }}">{{ $label }}</option>
                    @endforeach
                    <option value="">Never</option>
                </x-forms.select>
                <x-forms.button type="submit">Create</x-forms.button>
            </div>
            <div class="flex">
                Permissions
                <span
                    class="pr-1">:</span>
                <div class="flex gap-1 font-bold dark:text-white">
                    @if ($permissions)
                        @foreach ($permissions as $permission)
                            <div>{{ $permission }}</div>
                        @endforeach
                    @endif
                </div>
            </div>

            <div class="w-64">
                @if ($canUseRootPermissions)
                    <x-forms.checkbox label="root" wire:model.live="permissions" domValue="root"
                        helper="Root access, be careful!" :checked="in_array('root', $permissions)"></x-forms.checkbox>
                @else
                    <x-forms.checkbox label="root (admin/owner only)" disabled domValue="root"
                        helper="Root access requires admin or owner role" :checked="false"></x-forms.checkbox>
                @endif

                @if (!in_array('root', $permissions))
                    @if ($canUseWritePermissions)
                        <x-forms.checkbox label="write" wire:model.live="permissions" domValue="write"
                            helper="Write access to all resources." :checked="in_array('write', $permissions)"></x-forms.checkbox>
                    @else
                        <x-forms.checkbox label="write (admin/owner only)" disabled domValue="write"
                            helper="Write access requires admin or owner role" :checked="false"></x-forms.checkbox>
                    @endif

                    <x-forms.checkbox label="deploy" wire:model.live="permissions" domValue="deploy"
                        helper="Can trigger deploy webhooks." :checked="in_array('deploy', $permissions)"></x-forms.checkbox>
                    <x-forms.checkbox label="read" domValue="read" wire:model.live="permissions" domValue="read"
                        :checked="in_array('read', $permissions)"></x-forms.checkbox>
                    <x-forms.checkbox label="read:sensitive" wire:model.live="permissions" domValue="read:sensitive"
                        helper="Responses will include secrets, logs, passwords, and compose file contents."
                        :checked="in_array('read:sensitive', $permissions)"></x-forms.checkbox>
                @endif
            </div>
            @if (in_array('root', $permissions))
                <div class="font-bold dark:text-warning">Root access, be careful!</div>
            @endif
        </form>
    @endcan
    @if (session()->has('token'))
        <div class="py-4 font-bold dark:text-warning">Please copy this token now. For your security, it won't be shown
            again.
        </div>
        <div class="pb-4 font-bold dark:text-white"> {{ session('token') }}</div>
    @endif
    <h3 class="py-4">Issued Tokens</h3>
    <div class="flex flex-col">
        <div class="flex flex-col">
            <div class="overflow-x-auto">
                <div class="inline-block min-w-full">
                    <div class="overflow-hidden">
                        <table class="min-w-full">
                            <thead>
                                <tr>
                                    <th class="px-5 py-3 text-xs font-medium text-left uppercase">Description</th>
                                    <th class="px-5 py-3 text-xs font-medium text-left uppercase">Permissions</th>
                                    <th class="px-5 py-3 text-xs font-medium text-left uppercase">Last used</th>
                                    <th class="px-5 py-3 text-xs font-medium text-left uppercase">Created</th>
                                    <th class="px-5 py-3 text-xs font-medium text-left uppercase">Expires</th>
                                    <th class="px-5 py-3 text-xs font-medium text-left uppercase">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($tokens as $token)
                                    <tr wire:key="token-{{ $token->id }}">
                                        <td class="px-5 py-4 text-sm whitespace-nowrap">{{ $token->name }}</td>
                                        <td class="px-5 py-4 text-sm whitespace-nowrap">
                                            @if ($token->abilities)
                                                <div class="flex gap-1">
                                                    @foreach ($token->abilities as $ability)
                                                        <div class="font-bold dark:text-white">{{ $ability }}</div>
                                                    @endforeach
                                                </div>
                                            @endif
                                        </td>
                                        <td class="px-5 py-4 text-sm whitespace-nowrap">
                                            {{ $token->last_used_at ? $token->last_used_at->diffForHumans() : 'Never' }}
                                        </td>
                                        <td class="px-5 py-4 text-sm whitespace-nowrap">
                                            {{ $token->created_at->diffForHumans() }}
                                        </td>
                                        <td class="px-5 py-4 text-sm whitespace-nowrap">
                                            @if (! $token->expires_at)
                                                Never
                                            @elseif ($token->expires_at->isPast())
                                                <span class="font-bold dark:text-error">Expired
                                                    {{ $token->expires_at->format('Y-m-d H:i:s') }}</span>
                                            @else
                                                {{ $token->expires_at->format('Y-m-d H:i:s') }}
                                            @endif
                                        </td>
                                        <td class="px-5 py-4 text-sm font-medium whitespace-nowrap">
                                            @if (auth()->id() === $token->tokenable_id)
                                                <x-modal-confirmation title="Confirm API Token Revocation?" isErrorButton
                                                    buttonTitle="Revoke token"
                                                    submitAction="revoke({{ data_get($token, 'id') }})" :actions="[
                                                        'This API Token will be revoked and permanently deleted.',
                                                        'Any API call made with this token will fail.',
                                                    ]"
                                                    confirmationText="{{ $token->name }}"
                                                    confirmationLabel="Please confirm the execution of the actions by entering the API Token Description below"
                                                    shortConfirmationLabel="API Token Description" :confirmWithPassword="false"
                                                    step2ButtonText="Revoke API Token" />
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td class="px-5 py-4 text-sm whitespace-nowrap" colspan="6">No API tokens found.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endif
</div>
