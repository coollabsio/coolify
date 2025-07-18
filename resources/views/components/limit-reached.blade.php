<div class="flex flex-col items-center justify-center h-32">
    <span class="text-xl font-bold dark:text-white">You have reached the limit of {{ $name }} you can create.</span>
    <span>Please <a class="dark:text-white underline "href="{{ route('subscription.show') }}">upgrade your
            subscription</a> to create more
        {{ $name }}.</span>
</div>
