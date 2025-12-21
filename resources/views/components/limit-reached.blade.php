<div class="flex flex-col items-center justify-center h-32">
    <span class="text-xl font-bold dark:text-white">{{ str_replace(':name', $name, __('common.you_have_reached_limit')) }}</span>
    <span>{{ __('common.please_upgrade_subscription') }} <a class="dark:text-white underline" {{ wireNavigate() }} href="{{ route('subscription.show') }}">{{ __('common.upgrade_subscription') }}</a> {{ __('common.to_create_more') }}
        {{ $name }}。</span>
</div>
