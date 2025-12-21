<div class="flex grow flex-col gap-y-5 overflow-y-auto bg-prosetta-700 px-6 pb-4">
    <div class="flex h-16 shrink-0 items-center">
        <span class="text-2xl font-bold text-white">Prosetta</span>
    </div>
    <nav class="flex flex-1 flex-col">
        <ul role="list" class="flex flex-1 flex-col gap-y-7">
            <li>
                <ul role="list" class="-mx-2 space-y-1">
                    <li>
                        <a href="{{ route('prosetta.dashboard') }}" class="{{ request()->routeIs('prosetta.dashboard') ? 'bg-prosetta-800 text-white' : 'text-prosetta-200 hover:text-white hover:bg-prosetta-800' }} group flex gap-x-3 rounded-md p-2 text-sm leading-6 font-semibold">
                            <svg class="h-6 w-6 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
                            </svg>
                            Dashboard
                        </a>
                    </li>
                    <li>
                        <a href="{{ route('prosetta.files.index') }}" class="{{ request()->routeIs('prosetta.files.*') ? 'bg-prosetta-800 text-white' : 'text-prosetta-200 hover:text-white hover:bg-prosetta-800' }} group flex gap-x-3 rounded-md p-2 text-sm leading-6 font-semibold">
                            <svg class="h-6 w-6 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 014.5 9.75h15A2.25 2.25 0 0121.75 12v.75m-8.69-6.44l-2.12-2.12a1.5 1.5 0 00-1.061-.44H4.5A2.25 2.25 0 002.25 6v12a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9a2.25 2.25 0 00-2.25-2.25h-5.379a1.5 1.5 0 01-1.06-.44z" />
                            </svg>
                            Files
                        </a>
                    </li>
                </ul>
            </li>

            <li class="mt-auto">
                <div class="text-xs font-semibold leading-6 text-prosetta-300">Quick Actions</div>
                <ul role="list" class="-mx-2 mt-2 space-y-1">
                    <li>
                        <form action="{{ route('prosetta.sync') }}" method="POST" class="inline w-full">
                            @csrf
                            <button type="submit" class="text-prosetta-200 hover:text-white hover:bg-prosetta-800 group flex w-full gap-x-3 rounded-md p-2 text-sm leading-6 font-semibold">
                                <svg class="h-6 w-6 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
                                </svg>
                                Sync All
                            </button>
                        </form>
                    </li>
                </ul>
            </li>

            <li class="-mx-6 mt-auto">
                <a href="{{ url('/') }}" class="flex items-center gap-x-4 px-6 py-3 text-sm font-semibold leading-6 text-prosetta-200 hover:bg-prosetta-800 hover:text-white">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3" />
                    </svg>
                    Back to App
                </a>
            </li>
        </ul>
    </nav>
</div>
