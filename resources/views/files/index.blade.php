<x-prosetta::layout title="Translation Files" :locales="$locales" :currentLocale="$currentLocale">

    <!-- Header -->
    <div class="sm:flex sm:items-center">
        <div class="sm:flex-auto">
            <h1 class="text-base font-semibold leading-6 text-gray-900 dark:text-white">Translation Files</h1>
            <p class="mt-2 text-sm text-gray-700 dark:text-gray-400">Manage your translation files and their metadata.</p>
        </div>
        <div class="mt-4 sm:ml-16 sm:mt-0 sm:flex-none">
            <a href="{{ route('prosetta.files.create') }}" class="block rounded-md bg-prosetta-600 px-3 py-2 text-center text-sm font-semibold text-white shadow-sm hover:bg-prosetta-500">
                Add File
            </a>
        </div>
    </div>

    <!-- Filters -->
    <div class="mt-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <form method="GET" action="{{ route('prosetta.files.index') }}" class="flex flex-1 gap-4">
            <input type="hidden" name="locale" value="{{ $currentLocale }}">

            <div class="flex-1 max-w-sm">
                <label for="search" class="sr-only">Search</label>
                <div class="relative">
                    <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                        <svg class="h-5 w-5 text-gray-400" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 100 11 5.5 5.5 0 000-11zM2 9a7 7 0 1112.452 4.391l3.328 3.329a.75.75 0 11-1.06 1.06l-3.329-3.328A7 7 0 012 9z" clip-rule="evenodd" />
                        </svg>
                    </div>
                    <input type="text" name="search" id="search" value="{{ $search }}" placeholder="Search files..." class="block w-full rounded-md border-0 py-1.5 pl-10 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-prosetta-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600 sm:text-sm sm:leading-6">
                </div>
            </div>

            @if($categories->isNotEmpty())
            <div>
                <select name="category" onchange="this.form.submit()" class="block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-prosetta-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600 sm:text-sm sm:leading-6">
                    <option value="">All Categories</option>
                    @foreach($categories as $category)
                        <option value="{{ $category }}" {{ $currentCategory === $category ? 'selected' : '' }}>{{ $category }}</option>
                    @endforeach
                </select>
            </div>
            @endif

            <button type="submit" class="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600">
                Filter
            </button>
        </form>
    </div>

    <!-- Files Table -->
    <div class="mt-8 flow-root">
        <div class="-mx-4 -my-2 overflow-x-auto sm:-mx-6 lg:-mx-8">
            <div class="inline-block min-w-full py-2 align-middle sm:px-6 lg:px-8">
                <div class="overflow-hidden shadow ring-1 ring-black ring-opacity-5 sm:rounded-lg dark:ring-gray-700">
                    <table class="min-w-full divide-y divide-gray-300 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-800">
                            <tr>
                                <th scope="col" class="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-gray-900 dark:text-white sm:pl-6">File</th>
                                <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">Category</th>
                                <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">Keys</th>
                                <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">Progress</th>
                                <th scope="col" class="relative py-3.5 pl-3 pr-4 sm:pr-6">
                                    <span class="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-900">
                            @forelse($files as $file)
                                @php
                                    $stats = $fileStats[$file->id] ?? ['total_keys' => 0, 'approved' => 0, 'needs_review' => 0, 'draft' => 0];
                                    $progress = $stats['total_keys'] > 0 ? round(($stats['approved'] / $stats['total_keys']) * 100, 1) : 0;
                                @endphp
                                <tr>
                                    <td class="whitespace-nowrap py-4 pl-4 pr-3 sm:pl-6">
                                        <div class="flex items-center">
                                            <div>
                                                <div class="font-medium text-gray-900 dark:text-white">{{ $file->path }}</div>
                                                <div class="mt-1 text-gray-500 dark:text-gray-400">{{ $file->name }}</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500 dark:text-gray-400">
                                        @if($file->category)
                                            <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-800 dark:bg-gray-700 dark:text-gray-300">
                                                {{ $file->category }}
                                            </span>
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500 dark:text-gray-400">
                                        {{ $stats['total_keys'] }}
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500 dark:text-gray-400">
                                        <div class="flex items-center gap-2">
                                            <div class="w-24">
                                                <x-prosetta::progress-bar :percentage="$progress" size="sm" />
                                            </div>
                                            <span>{{ $progress }}%</span>
                                        </div>
                                        @if($stats['needs_review'] > 0)
                                            <div class="mt-1 text-xs text-yellow-600 dark:text-yellow-400">
                                                {{ $stats['needs_review'] }} need review
                                            </div>
                                        @endif
                                    </td>
                                    <td class="relative whitespace-nowrap py-4 pl-3 pr-4 text-right text-sm font-medium sm:pr-6">
                                        <a href="{{ route('prosetta.files.show', ['file' => $file, 'locale' => $currentLocale]) }}" class="text-prosetta-600 hover:text-prosetta-900 dark:text-prosetta-400 dark:hover:text-prosetta-300">
                                            View<span class="sr-only">, {{ $file->name }}</span>
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-6 py-10 text-center text-gray-500 dark:text-gray-400">
                                        <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                        </svg>
                                        <h3 class="mt-2 text-sm font-semibold text-gray-900 dark:text-white">No files found</h3>
                                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Get started by creating a new file or syncing existing files.</p>
                                        <div class="mt-6 flex justify-center gap-3">
                                            <a href="{{ route('prosetta.files.create') }}" class="inline-flex items-center rounded-md bg-prosetta-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-prosetta-500">
                                                New File
                                            </a>
                                            <form action="{{ route('prosetta.sync') }}" method="POST" class="inline">
                                                @csrf
                                                <button type="submit" class="inline-flex items-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600">
                                                    Sync Files
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

</x-prosetta::layout>
