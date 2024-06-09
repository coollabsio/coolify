<div>
    <x-slot:title>
        Tags | Coolify
    </x-slot>
    <h1>Tags</h1>
    <div class="flex flex-col pb-6 ">
        <div class="subtitle">Tags help you to perform actions on multiple resources.</div>
        <div class="">
            @if ($tags->count() === 0)
                <div>No tags yet defined yet. Go to a resource and add a tag there.</div>
            @else
                <x-forms.datalist wire:model="tag" onUpdate='tag_updated'>
                    @foreach ($tags as $oneTag)
                        <option value="{{ $oneTag->name }}">{{ $oneTag->name }}</option>
                    @endforeach
                </x-forms.datalist>
                @if ($tag)
                    <div class="pt-5">
                        <div class="flex items-end gap-2 ">
                            <div class="w-[500px]">
                                <x-forms.input readonly label="Deploy Webhook URL" id="webhook" />
                            </div>
                            <x-modal-confirmation isHighlighted buttonTitle="Redeploy All" action="redeploy_all">
                                All resources will be redeployed.
                            </x-modal-confirmation>
                        </div>
                        <div class="grid grid-cols-1 gap-2 pt-4 lg:grid-cols-2 xl:grid-cols-3">
                            @foreach ($applications as $application)
                                <div class="box group">
                                    <a href="{{ $application->link() }}" class="flex flex-col ">
                                        <div class="box-title">{{ $application->name }}</div>
                                        <div class="box-description">
                                            {{ $application->project()->name }}/{{ $application->environment->name }}
                                        </div>
                                        <div class="box-description">{{ $application->description }}</div>
                                    </a>
                                </div>
                            @endforeach
                            @foreach ($services as $service)
                                <div class="box group">
                                    <a href="{{ $service->link() }}" class="flex flex-col ">
                                        <div class="box-title">{{ $service->name }}</div>
                                        <div class="box-description">
                                            {{ $service->project()->name }}/{{ $service->environment->name }}</div>
                                        <div class="box-description">{{ $service->description }}</div>
                                    </a>
                                </div>
                            @endforeach
                        </div>
                        <div class="flex items-center gap-2">
                            <h3 class="py-4">Deployments</h3>
                            @if (count($deployments_per_tag_per_server) > 0)
                                <x-loading />
                            @endif
                        </div>
                        <livewire:tags.deployments :deployments_per_tag_per_server="$deployments_per_tag_per_server" :resource_ids="$applications->pluck('id')" />
                    </div>
                @endif
            @endif
        </div>
    </div>
</div>
