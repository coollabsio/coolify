<div class="flex flex-col items-center justify-center h-screen">
    <span class="text-xl font-bold text-white">You have reached the limit of {{ $name }} you can create.</span>
    <span>Please <a class="text-white underline "href="{{ route('subscription.show') }}">upgrade your
            subscription</a> to create more
        {{ $name }}.</span>
</div>
