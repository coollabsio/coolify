<script>
  import { session } from "@store";
  function login() {
    const left = screen.width / 2 - 1020 / 2;
    const top = screen.height / 2 - 618 / 2;
    const newWindow = open(
      `https://github.com/login/oauth/authorize?client_id=${
        import.meta.env.VITE_GITHUB_APP_CLIENTID
      }`,
      "Authenticate",
      "resizable=1, scrollbars=1, fullscreen=0, height=618, width=1020,top=" +
        top +
        ", left=" +
        left +
        ", toolbar=0, menubar=0, status=0",
    );
    const timer = setInterval(() => {
      if (newWindow.closed) {
        clearInterval(timer);
        const ghToken = new URL(newWindow.document.URL).searchParams.get(
          "ghToken",
        );
        if (ghToken) {
          $session.githubAppToken = ghToken;
        }
      }
    }, 100);
  }
</script>

<div class="text-center">
  <div class="text-xl font-bold tracking-tighter text-center">
    Choose your Git provider
  </div>
  <button on:click="{login}">
    <svg
      class="h-16 w-16"
      fill="currentColor"
      viewBox="0 0 20 20"
      aria-hidden="true"
    >
      <path
        fill-rule="evenodd"
        d="M10 0C4.477 0 0 4.484 0 10.017c0 4.425 2.865 8.18 6.839 9.504.5.092.682-.217.682-.483 0-.237-.008-.868-.013-1.703-2.782.605-3.369-1.343-3.369-1.343-.454-1.158-1.11-1.466-1.11-1.466-.908-.62.069-.608.069-.608 1.003.07 1.531 1.032 1.531 1.032.892 1.53 2.341 1.088 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.113-4.555-4.951 0-1.093.39-1.988 1.029-2.688-.103-.253-.446-1.272.098-2.65 0 0 .84-.27 2.75 1.026A9.564 9.564 0 0110 4.844c.85.004 1.705.115 2.504.337 1.909-1.296 2.747-1.027 2.747-1.027.546 1.379.203 2.398.1 2.651.64.7 1.028 1.595 1.028 2.688 0 3.848-2.339 4.695-4.566 4.942.359.31.678.921.678 1.856 0 1.338-.012 2.419-.012 2.747 0 .268.18.58.688.482A10.019 10.019 0 0020 10.017C20 4.484 15.522 0 10 0z"
        clip-rule="evenodd"></path>
    </svg>
  </button>
</div>
