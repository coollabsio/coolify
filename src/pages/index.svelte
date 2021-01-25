<script>
    import { goto } from "@roxi/routify";
    import { session, loggedIn } from "../store";
    function login() {
        const left = screen.width / 2 - 1020 / 2;
        const top = screen.height / 2 - 618 / 2;
        const newWindow = open(
            `https://github.com/login/oauth/authorize?client_id=${
                import.meta.env.VITE_GITHUB_OAUTH_CLIENTID
            }&scope=user:email`,
            "Authenticate",
            "resizable=1, scrollbars=1, fullscreen=0, height=618, width=1020,top=" +
                top +
                ", left=" +
                left +
                ", toolbar=0, menubar=0, status=0"
        );
        const timer = setInterval(() => {
            if (newWindow.closed) {
                clearInterval(timer);
                const token = new URL(newWindow.document.URL).searchParams.get(
                    "token"
                );
                if (token) {
                    $session.token = token;
                    localStorage.setItem("token", token);
                    $goto("/dashboard");
                }
            }
        }, 100);
    }
</script>

<div class="flex justify-center items-center h-screen w-full bg-coolgray-100">
    <div class="max-w-7xl mx-auto  px-4 sm:py-24  sm:px-6 lg:px-8">
        <div class="text-center">
            <p
                class="mt-1 pb-8 text-4xl font-extrabold text-white text-5xl sm:tracking-tight lg:text-6xl text-center"
            >Coolify</p>
            <h2 class="text-2xl md:text-3xl font-extrabold text-white">
                An open-source, hassle-free, self-hostable<br />
                <span class="text-indigo-400">Heroku</span>
                & <span class="text-green-400">Netlify</span> alternative
            </h2>
            <div class="text-center py-10">
                {#if !$loggedIn}
                    <button class="p-2 px-10" on:click={login}
                        >Login with Github</button
                    >
                {:else}
                    <button
                        class="p-2 px-10"
                        on:click={() => $goto("/dashboard")}>Get Started</button
                    >
                {/if}
            </div>
        </div>
    </div>
</div>

<style lang="postcss">
    button {
        @apply border border-black rounded-md text-sm font-medium bg-coolgray-300 text-white;
        &:hover {
            @apply bg-coolgray-200;
        }
    }
</style>
