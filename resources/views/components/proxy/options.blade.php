@props(['proxy_settings'])
<div class="mt-4">
    <label>
        <div>Edit config file</div>
        <textarea cols="45" rows="6"></textarea>
    </label>
</div>

<div class="mt-4">
    <label>
        Enable dashboard?
        <input type="checkbox" />
        (auto-save)
    </label>
</div>

<div class="mt-4">
    <a href="#">Visit Dashboard</a>
</div>

<div class="mt-4">
    <label>
        <div>Setup hostname for Dashboard</div>
        <div class="mt-2"></div>
        <label>
            <div>Hostname <span class="text-xs"> Eg: dashboard.example.com </span></div>
            <input type="text" />
        </label>
        <button>Update</button>
    </label>
</div>

<div class="mt-4">
    <label>
        <div>Dashboard credentials</div>
        <div class="mt-2"></div>
        <label>
            Username
            <input type="text" />
        </label>
        <label>
            Password
            <input type="password" />
        </label>
        <button>Update</button>
    </label>
</div>
