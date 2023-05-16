<x-layout>
    <div>
        <div>
            <h3>User</h3>
            <p>Name: {{ auth()->user()->name }}</p>
            <p>Id: {{ auth()->user()->id }}</p>
            <p>Uuid: {{ auth()->user()->uuid }}</p>
        </div>
    </div>
</x-layout>
