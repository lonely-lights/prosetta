<x-prosetta::layout title="Dashboard" :locales="$locales" :currentLocale="$currentLocale">

    <!-- Stats Overview -->
    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
        <x-prosetta::stat-card title="Total Keys" :value="$statistics['total']" color="prosetta">
            <x-slot:icon>
                <svg class="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z" />
                </svg>
            </x-slot:icon>
        </x-prosetta::stat-card>

        <x-prosetta::stat-card title="Translated" :value="$statistics['translated']" color="green">
            <x-slot:icon>
                <svg class="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </x-slot:icon>
        </x-prosetta::stat-card>

        <x-prosetta::stat-card title="Missing" :value="$statistics['missing']" color="red">
            <x-slot:icon>
                <svg class="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                </svg>
            </x-slot:icon>
        </x-prosetta::stat-card>

        <x-prosetta::stat-card title="Needs Review" :value="$statistics['needs_review']" color="yellow">
            <x-slot:icon>
                <svg class="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
            </x-slot:icon>
        </x-prosetta::stat-card>
    </div>

    <!-- Overall Progress -->
    <div class="mt-8">
        <div class="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
            <div class="px-4 py-5 sm:p-6">
                <h3 class="text-base font-semibold leading-6 text-gray-900 dark:text-white">Overall Progress for {{ strtoupper($currentLocale) }}</h3>
                <div class="mt-4">
                    <div class="flex items-center justify-between text-sm text-gray-600 dark:text-gray-400">
                        <span>{{ $statistics['completion_percentage'] }}% Complete</span>
                        <span>{{ $statistics['translated'] }} / {{ $statistics['total'] }} keys</span>
                    </div>
                    <div class="mt-2">
                        <x-prosetta::progress-bar :percentage="$statistics['completion_percentage']" size="lg" />
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="mt-8">
        <div class="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
            <div class="px-4 py-5 sm:p-6">
                <h3 class="text-base font-semibold leading-6 text-gray-900 dark:text-white">Quick Actions</h3>
                <div class="mt-4 flex flex-wrap gap-3">
                    <form action="{{ route('prosetta.sync') }}" method="POST" class="inline">
                        @csrf
                        <input type="hidden" name="locale" value="{{ $currentLocale }}">
                        <button type="submit" class="inline-flex items-center rounded-md bg-prosetta-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-prosetta-500">
                            <svg class="-ml-0.5 mr-1.5 h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
                            </svg>
                            Sync {{ strtoupper($currentLocale) }}
                        </button>
                    </form>

                    <form action="{{ route('prosetta.export') }}" method="POST" class="inline">
                        @csrf
                        <input type="hidden" name="locale" value="{{ $currentLocale }}">
                        <button type="submit" class="inline-flex items-center rounded-md bg-green-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-green-500">
                            <svg class="-ml-0.5 mr-1.5 h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
                            </svg>
                            Export {{ strtoupper($currentLocale) }}
                        </button>
                    </form>

                    <a href="{{ route('prosetta.files.create') }}" class="inline-flex items-center rounded-md bg-gray-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-gray-500">
                        <svg class="-ml-0.5 mr-1.5 h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        New File
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Files List -->
    <div class="mt-8">
        <div class="sm:flex sm:items-center">
            <div class="sm:flex-auto">
                <h2 class="text-base font-semibold leading-6 text-gray-900 dark:text-white">Translation Files</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Overview of all translation files and their progress.</p>
            </div>
            <div class="mt-4 sm:ml-16 sm:mt-0 sm:flex-none">
                <a href="{{ route('prosetta.files.index') }}" class="text-sm font-semibold text-prosetta-600 hover:text-prosetta-500 dark:text-prosetta-400">
                    View all <span aria-hidden="true">&rarr;</span>
                </a>
            </div>
        </div>

        <div class="mt-4 overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
            <ul role="list" class="divide-y divide-gray-200 dark:divide-gray-700">
                @forelse($files as $file)
                    @php
                        $stats = $fileStats[$file->id] ?? ['total_keys' => 0, 'approved' => 0, 'needs_review' => 0];
                        $progress = $stats['total_keys'] > 0 ? round(($stats['approved'] / $stats['total_keys']) * 100, 1) : 0;
                    @endphp
                    <li>
                        <a href="{{ route('prosetta.files.show', ['file' => $file, 'locale' => $currentLocale]) }}" class="block hover:bg-gray-50 dark:hover:bg-gray-700">
                            <div class="px-4 py-4 sm:px-6">
                                <div class="flex items-center justify-between">
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-x-3">
                                            <p class="truncate text-sm font-medium text-prosetta-600 dark:text-prosetta-400">{{ $file->path }}</p>
                                            @if($file->category)
                                                <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-800 dark:bg-gray-700 dark:text-gray-300">
                                                    {{ $file->category }}
                                                </span>
                                            @endif
                                        </div>
                                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $file->name }}</p>
                                    </div>
                                    <div class="ml-4 flex flex-shrink-0 items-center gap-x-4">
                                        <div class="text-right">
                                            <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $stats['total_keys'] }} keys</p>
                                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $progress }}% complete</p>
                                        </div>
                                        <div class="w-24">
                                            <x-prosetta::progress-bar :percentage="$progress" size="sm" />
                                        </div>
                                        <svg class="h-5 w-5 text-gray-400" viewBox="0 0 20 20" fill="currentColor">
                                            <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" />
                                        </svg>
                                    </div>
                                </div>
                            </div>
                        </a>
                    </li>
                @empty
                    <li class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">
                        No translation files found. Run <code class="rounded bg-gray-100 px-1 dark:bg-gray-700">php artisan prosetta:sync</code> to import existing files.
                    </li>
                @endforelse
            </ul>
        </div>
    </div>

</x-prosetta::layout>
