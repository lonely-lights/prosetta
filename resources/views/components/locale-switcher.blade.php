@props(['locales', 'current'])

<div class="flex items-center gap-x-4" x-data="{ open: false }">
    <div class="relative">
        <button @click="open = !open" type="button" class="flex items-center gap-x-2 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600">
            <svg class="h-5 w-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 21l5.25-11.25L21 21m-9-3h7.5M3 5.621a48.474 48.474 0 016-.371m0 0c1.12 0 2.233.038 3.334.114M9 5.25V3m3.334 2.364C11.176 10.658 7.69 15.08 3 17.502m9.334-12.138c.896.061 1.785.147 2.666.257m-4.589 8.495a18.023 18.023 0 01-3.827-5.802" />
            </svg>
            <span>{{ strtoupper($current) }}</span>
            <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
            </svg>
        </button>

        <div x-show="open" @click.away="open = false" x-transition x-cloak class="absolute right-0 z-10 mt-2 w-48 origin-top-right rounded-md bg-white py-1 shadow-lg ring-1 ring-black ring-opacity-5 focus:outline-none dark:bg-gray-700">
            @foreach($locales as $locale)
                @php
                    $localeCode = $locale->locale_initials ?? $locale->code ?? $locale->id;
                    $localeName = $locale->english_name ?? $locale->name ?? $localeCode;
                @endphp
                <a href="{{ request()->fullUrlWithQuery(['locale' => $localeCode]) }}" class="{{ $localeCode === $current ? 'bg-gray-100 dark:bg-gray-600' : '' }} block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-600">
                    <span class="font-medium">{{ strtoupper($localeCode) }}</span>
                    <span class="text-gray-500 dark:text-gray-400">- {{ $localeName }}</span>
                </a>
            @endforeach
        </div>
    </div>
</div>
