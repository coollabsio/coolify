<div>
    <div class="flex items-start gap-2">
        <div>
            <h1>Tag: {{ $tag->name }}</h1>
            <div class="pt-2">Tag details</div>
        </div>
    </div>
    <div class="pt-4">
        <div class="flex items-end gap-2 ">
            <div class="w-[500px]">
                <x-forms.input readonly label="Deploy Webhook URL" id="webhook" />
            </div>
            <x-new-modal buttonTitle="Redeploy All" action="redeploy_all" class="mt-1">
                All resources will be redeployed.
            </x-new-modal>
        </div>
        <div class="grid grid-cols-1 gap-2 pt-4 lg:grid-cols-2 xl:grid-cols-3">
            @foreach ($applications as $application)
                <a href="{{ $application->link() }}" class="flex flex-col box group">
                    <span class="font-bold text-white">{{ $application->project()->name }}/{{$application->environment->name}}</span>
                    <span class="text-white ">{{ $application->name }}</span>
                    <span class="description">{{ $application->description }}</span>
                </a>
            @endforeach
            @foreach ($services as $service)
                <a href="{{ $service->link() }}" class="flex flex-col box group">
                    <span class="font-bold text-white">{{ $service->project()->name }}/{{$service->environment->name}}</span>
                    <span class="text-white ">{{ $service->name }}</span>
                    <span class="description">{{ $service->description }}</span>
                </a>
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
                        <a href="{{ data_get($deployment, 'deployment_url') }}" @class([
                            'gap-2 cursor-pointer box group border-l-2 border-dotted',
                            'border-coolgray-500' => data_get($deployment, 'status') === 'queued',
                            'border-yellow-500' => data_get($deployment, 'status') === 'in_progress',
                        ])>
                            <div class="flex flex-col mx-6">
                                <div class="font-bold text-white">
                                    {{ data_get($deployment, 'application_name') }}
                                </div>
                                <div class="description">
                                    {{ str(data_get($deployment, 'status'))->headline() }}
                                </div>
                            </div>
                            <div class="flex-1"></div>
                        </a>
                    @endforeach
                </div>
            @empty
                <div>No deployments running.</div>
            @endforelse
        </div>
    </div>
</div>
