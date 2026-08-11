{{--
    Shared explainer for what enabling traffic analytics actually does. Kept in one
    place so the server analytics nudge, the dashboard nudge, and the application
    General-page nudge stay consistent about the side effects (proxy + Sentinel restart).
--}}
<ul class="flex flex-col gap-1.5 text-[12px] text-neutral-600 dark:text-fg-dim">
    <li class="flex items-start gap-2">
        <span class="mt-1.5 h-1 w-1 shrink-0 rounded-full bg-neutral-400 dark:bg-fg-faint"></span>
        <span>Regenerates the proxy config and <span class="font-medium text-black dark:text-fg">restarts the proxy</span> (a brief blip for in-flight connections).</span>
    </li>
    <li class="flex items-start gap-2">
        <span class="mt-1.5 h-1 w-1 shrink-0 rounded-full bg-neutral-400 dark:bg-fg-faint"></span>
        <span><span class="font-medium text-black dark:text-fg">Restarts Sentinel</span> and mounts a <span class="font-medium text-black dark:text-fg">read-only</span> access-log volume.</span>
    </li>
    <li class="flex items-start gap-2">
        <span class="mt-1.5 h-1 w-1 shrink-0 rounded-full bg-neutral-400 dark:bg-fg-faint"></span>
        <span>Adds <span class="font-medium text-black dark:text-fg">visitor geography</span> — which countries your traffic comes from.</span>
    </li>
    <li class="flex items-start gap-2">
        <span class="mt-1.5 h-1 w-1 shrink-0 rounded-full bg-neutral-400 dark:bg-fg-faint"></span>
        <span>Works with <span class="font-medium text-black dark:text-fg">Traefik &amp; Caddy</span>; not available on Swarm or Build-pack servers.</span>
    </li>
</ul>
