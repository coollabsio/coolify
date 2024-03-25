<div>
    <h1>Tags</h1>
    <div class="flex flex-col pb-6 ">
        <div class="subtitle">Tags help you to perform actions on multiple resources.</div>
        <div class="flex flex-wrap gap-2">
            @if ($tags->count() === 0)
                <div>No tags yet defined yet. Go to a resource and add a tag there.</div>
            @else
                <x-forms.select wire:model.live="tag">
                    <option value="null" disabled selected>Select a tag</option>
                    @foreach ($tags as $oneTag)
                        <option value="{{ $oneTag->name }}">{{ $oneTag->name }}</option>
                    @endforeach
                </x-forms.select>
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
                        <div wire:poll.1000ms="get_deployments" class="grid grid-cols-1">
                            @forelse ($deployments_per_tag_per_server as $server_name => $deployments)
                                <h4 class="py-4">{{ $server_name }}</h4>
                                <div class="grid grid-cols-1 gap-2 lg:grid-cols-3">
                                    @foreach ($deployments as $deployment)
                                        <div @class([
                                            'box-without-bg dark:bg-coolgray-100 bg-white gap-2 cursor-pointer group border-l-2 border-dotted',
                                            'dark:border-coolgray-300' => data_get($deployment, 'status') === 'queued',
                                            'border-yellow-500' => data_get($deployment, 'status') === 'in_progress',
                                        ])>
                                            <a href="{{ data_get($deployment, 'deployment_url') }}">
                                                <div class="flex flex-col mx-6">
                                                    <div class="box-title">
                                                        {{ data_get($deployment, 'application_name') }}
                                                    </div>
                                                    <div class="box-description">
                                                        {{ str(data_get($deployment, 'status'))->headline() }}
                                                    </div>
                                                </div>
                                                <div class="flex-1"></div>
                                            </a>
                                        </div>
                                    @endforeach
                                </div>
                            @empty
                                <div>No deployments running.</div>
                            @endforelse
                        </div>
                    </div>
                @endif
            @endif
        </div>
    </div>
</div>
