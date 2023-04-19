<x-layout>
    <h1>{{ $title ?? 'NOT SET' }}</h1>
    <x-applications.navbar :applicationId="$applicationId" />
    <div>
        {{ $slot }}
    </div>
</x-layout>
