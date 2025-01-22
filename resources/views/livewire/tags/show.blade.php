<div>
    <div class="flex items-start gap-2 pb-10">
        <div>
            <h1>Tags</h1>
            <div>Tags help you to perform actions on multiple resources.</div>
        </div>
    </div>
    <div class="flex flex-wrap gap-2 ">
        @forelse ($tags as $oneTag)
            <a wire:navigate :class="{{ $tag?->id == $oneTag->id }} && 'dark:bg-coollabs hover:bg-coollabs-100'"
                class="w-64 box-without-bg dark:bg-coolgray-100 dark:text-white font-bold"
                href="{{ route('tags.show', ['tagName' => $oneTag->name]) }}">{{ $oneTag->name }}</a>
        @empty
            <div>No tags yet defined yet. Go to a resource and add a tag there.</div>
        @endforelse
    </div>
    @if (isset($tag))
        <div>
            <h3 class="py-4">Details</h3>
            <div class="flex items-end gap-2 ">
                <div class="w-[500px]">
                    <x-forms.input readonly label="Deploy Webhook URL" id="webhook" />
                </div>
                <x-modal-confirmation title="Redeploy all resources with this tag?" isHighlighted
                    buttonTitle="Redeploy All" submitAction="redeployAll" :actions="[
                        'All resources with this tag will be redeployed.',
                        'During redeploy resources will be temporarily unavailable.',
                    ]"
                    confirmationText="{{ $tag->name }}"
                    confirmationLabel="Please confirm the execution of the actions by entering the Tag Name below"
                    shortConfirmationLabel="Tag Name" :confirmWithPassword="false" step2ButtonText="Redeploy All" />
            </div>

            <div class="grid grid-cols-1 gap-2 pt-4 lg:grid-cols-2 xl:grid-cols-3">
                @if (isset($applications) && count($applications) > 0)
                    @foreach ($applications as $application)
                        <a wire:navigate href="{{ $application->link() }}" class="box group">
                            <div class="flex flex-col">
                                <div class="box-title">
                                    {{ $application->project()->name }}/{{ $application->environment->name }}
                                </div>
                                <div class="box-description">{{ $application->name }}</div>
                                <div class="box-description">{{ $application->description }}</div>
                            </div>
                        </a>
                    @endforeach
                @endif
                @if (isset($services) && count($services) > 0)
                    @foreach ($services as $service)
                        <a wire:navigate href="{{ $service->link() }}" class="flex flex-col box group">
                            <div class="flex flex-col">
                                <div class="box-title">
                                    {{ $service->project()->name }}/{{ $service->environment->name }}
                                </div>
                                <div class="box-description">{{ $service->name }}</div>
                                <div class="box-description">{{ $service->description }}</div>
                            </div>
                        </a>
                    @endforeach
                @endif
            </div>
            <div class="flex items-center gap-2">
                <h3 class="py-4">Deployments</h3>
                @if (count($deploymentsPerTagPerServer) > 0)
                    <x-loading />
                @endif
            </div>
            <div wire:poll="getDeployments" class="grid grid-cols-1">
                @forelse ($deploymentsPerTagPerServer as $serverName => $deployments)
                    <h4 class="py-4">{{ $serverName }}</h4>
                    <div class="grid grid-cols-1 gap-2 lg:grid-cols-3">
                        @foreach ($deployments as $deployment)
                            <a wire:navigate href="{{ data_get($deployment, 'deployment_url') }}" @class([
                                'gap-2 cursor-pointer box group border-l-2 border-dotted',
                                'dark:border-coolgray-300' => data_get($deployment, 'status') === 'queued',
                                'border-yellow-500' => data_get($deployment, 'status') === 'in_progress',
                            ])>
                                <div class="flex flex-col mx-6">
                                    <div class="font-bold dark:text-white">
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
    @endif
</div>
