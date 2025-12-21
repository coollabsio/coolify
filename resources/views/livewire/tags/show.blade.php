<div>
    <div class="flex items-start gap-2 pb-10">
        <div>
            <h1 class="pb-2">{{ __('tags.title') }}</h1>
            <div>{{ __('tags.tags_help_desc') }}</div>
        </div>
    </div>
    <div class="flex flex-wrap gap-2 ">
        @forelse ($tags as $oneTag)
            <a :class="{{ $tag?->id == $oneTag->id }} && 'dark:bg-coollabs'"
                class="min-w-32 coolbox dark:text-white font-bold flex justify-center items-center"
                {{ wireNavigate() }}
                href="{{ route('tags.show', ['tagName' => $oneTag->name]) }}">{{ data_get_str($oneTag, 'name')->limit(30) }}</a>
        @empty
            <div>{{ __('tags.no_tags_defined') }}</div>
        @endforelse
    </div>
    @if (isset($tag))
        <div>
            <h3 class="py-4">{{ __('tags.tag_details') }}</h3>
            <div class="flex items-end gap-2 ">
                <div class="w-[500px]">
                    <x-forms.input readonly label="{{ __('tags.deploy_webhook_url') }}" id="webhook" />
                </div>
                <x-modal-confirmation title="{{ __('tags.redeploy_all_title') }}" isHighlighted
                    buttonTitle="{{ __('tags.redeploy_all') }}" submitAction="redeployAll" :actions="[
                        __('tags.redeploy_all_action_1'),
                        __('tags.redeploy_all_action_2'),
                    ]"
                    confirmationText="{{ $tag->name }}"
                    confirmationLabel="{{ __('tags.confirm_tag_name_label') }}"
                    shortConfirmationLabel="{{ __('tags.tag_name') }}" :confirmWithPassword="false" step2ButtonText="{{ __('tags.redeploy_all') }}" />
            </div>

            <div class="grid grid-cols-1 gap-2 pt-4 lg:grid-cols-2 xl:grid-cols-3">
                @if (isset($applications) && count($applications) > 0)
                    @foreach ($applications as $application)
                        <a {{ wireNavigate() }} href="{{ $application->link() }}" class="coolbox group">
                            <div class="flex flex-col justify-center">
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
                        <a {{ wireNavigate() }} href="{{ $service->link() }}" class="flex flex-col coolbox group">
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
                <h3 class="py-4">{{ __('tags.deployments') }}</h3>
                @if (count($deploymentsPerTagPerServer) > 0)
                    <x-loading />
                @endif
            </div>
            <div wire:poll="getDeployments" class="grid grid-cols-1">
                @forelse ($deploymentsPerTagPerServer as $serverName => $deployments)
                    <h4 class="py-4">{{ $serverName }}</h4>
                    <div class="grid grid-cols-1 gap-2 lg:grid-cols-3">
                        @foreach ($deployments as $deployment)
                            <a {{ wireNavigate() }} href="{{ data_get($deployment, 'deployment_url') }}" @class([
                                'gap-2 cursor-pointer coolbox group border-l-2 border-dotted',
                                'dark:border-coolgray-300' => data_get($deployment, 'status') === 'queued',
                                'border-warning-500' => data_get($deployment, 'status') === 'in_progress',
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
                    <div>{{ __('tags.no_deployments_running') }}</div>
                @endforelse
            </div>
        </div>
    @endif
</div>
