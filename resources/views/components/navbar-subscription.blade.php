@auth
    <nav class="fixed h-full overflow-hidden overflow-y-auto scrollbar">
        <ul class="flex flex-col h-full gap-4 menu flex-nowrap">
            <li class="pb-6" title="Logout">
                <form action="/logout" method="POST" class=" hover:bg-transparent">
                    @csrf
                    <button class="flex items-center gap-2 rounded-none hover:text-white hover:bg-transparent">
                        <svg xmlns="http://www.w3.org/2000/svg" class="icon" viewBox="0 0 24 24" stroke-width="1.5"
                            stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                            <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                            <path d="M13 12v.01" />
                            <path d="M3 21h18" />
                            <path d="M5 21v-16a2 2 0 0 1 2 -2h7.5m2.5 10.5v7.5" />
                            <path d="M14 7h7m-3 -3l3 3l-3 3" />
                        </svg>
                    </button>
                </form>
            </li>
        </ul>
    </nav>
@endauth
