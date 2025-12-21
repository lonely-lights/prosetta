<x-prosetta::layout title="{{ $file->name }}" :locales="$locales" :currentLocale="$currentLocale">

    <!-- Breadcrumb -->
    <nav class="mb-4 flex" aria-label="Breadcrumb">
        <ol role="list" class="flex items-center space-x-4">
            <li>
                <a href="{{ route('prosetta.files.index') }}" class="text-gray-400 hover:text-gray-500 dark:text-gray-500 dark:hover:text-gray-400">
                    <svg class="h-5 w-5 flex-shrink-0" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M2 4.75A.75.75 0 012.75 4h14.5a.75.75 0 010 1.5H2.75A.75.75 0 012 4.75zM2 10a.75.75 0 01.75-.75h14.5a.75.75 0 010 1.5H2.75A.75.75 0 012 10zm0 5.25a.75.75 0 01.75-.75h14.5a.75.75 0 010 1.5H2.75a.75.75 0 01-.75-.75z" clip-rule="evenodd" />
                    </svg>
                    <span class="sr-only">Files</span>
                </a>
            </li>
            <li>
                <div class="flex items-center">
                    <svg class="h-5 w-5 flex-shrink-0 text-gray-300 dark:text-gray-600" fill="currentColor" viewBox="0 0 20 20">
                        <path d="M5.555 17.776l8-16 .894.448-8 16-.894-.448z" />
                    </svg>
                    <span class="ml-4 text-sm font-medium text-gray-500 dark:text-gray-400">{{ $file->path }}</span>
                </div>
            </li>
        </ol>
    </nav>

    <!-- File Header -->
    <div class="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
        <div class="px-4 py-5 sm:px-6">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-lg font-medium leading-6 text-gray-900 dark:text-white">{{ $file->name }}</h2>
                    <p class="mt-1 max-w-2xl text-sm text-gray-500 dark:text-gray-400">{{ $file->description ?? 'No description' }}</p>
                </div>
                <div class="flex gap-2">
                    <a href="{{ route('prosetta.files.edit', $file) }}" class="inline-flex items-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600">
                        Edit
                    </a>
                    <form action="{{ route('prosetta.export') }}" method="POST" class="inline">
                        @csrf
                        <input type="hidden" name="locale" value="{{ $currentLocale }}">
                        <input type="hidden" name="file" value="{{ $file->path }}">
                        <button type="submit" class="inline-flex items-center rounded-md bg-prosetta-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-prosetta-500">
                            Export
                        </button>
                    </form>
                </div>
            </div>

            <!-- Stats -->
            <div class="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Keys</dt>
                    <dd class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">{{ $statistics['total_keys'] }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Approved</dt>
                    <dd class="mt-1 text-2xl font-semibold text-green-600">{{ $statistics['approved'] }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Needs Review</dt>
                    <dd class="mt-1 text-2xl font-semibold text-yellow-600">{{ $statistics['needs_review'] }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Draft</dt>
                    <dd class="mt-1 text-2xl font-semibold text-gray-600">{{ $statistics['draft'] }}</dd>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="mt-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <form method="GET" action="{{ route('prosetta.files.show', $file) }}" class="flex flex-1 flex-wrap gap-4">
            <input type="hidden" name="locale" value="{{ $currentLocale }}">

            <div class="flex-1 max-w-sm">
                <input type="text" name="search" value="{{ $search }}" placeholder="Search keys..." class="block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-prosetta-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600 sm:text-sm sm:leading-6">
            </div>

            <select name="status" onchange="this.form.submit()" class="block rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-prosetta-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600 sm:text-sm sm:leading-6">
                <option value="">All Status</option>
                <option value="missing" {{ $status === 'missing' ? 'selected' : '' }}>Missing</option>
                <option value="needs_review" {{ $status === 'needs_review' ? 'selected' : '' }}>Needs Review</option>
                <option value="approved" {{ $status === 'approved' ? 'selected' : '' }}>Approved</option>
                <option value="draft" {{ $status === 'draft' ? 'selected' : '' }}>Draft</option>
            </select>

            <select name="compare" onchange="this.form.submit()" class="block rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-prosetta-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600 sm:text-sm sm:leading-6">
                <option value="">Compare with...</option>
                @foreach($locales as $locale)
                    @php $code = $locale->locale_initials ?? $locale->code; @endphp
                    @if($code !== $currentLocale)
                        <option value="{{ $code }}" {{ $compareLocale === $code ? 'selected' : '' }}>{{ strtoupper($code) }}</option>
                    @endif
                @endforeach
            </select>

            <button type="submit" class="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600">
                Filter
            </button>
        </form>
    </div>

    <!-- Keys Table -->
    <div class="mt-6 overflow-hidden shadow ring-1 ring-black ring-opacity-5 sm:rounded-lg dark:ring-gray-700">
        <table class="min-w-full divide-y divide-gray-300 dark:divide-gray-700">
            <thead class="bg-gray-50 dark:bg-gray-800">
                <tr>
                    <th scope="col" class="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-gray-900 dark:text-white sm:pl-6">Key</th>
                    <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">{{ strtoupper($currentLocale) }}</th>
                    @if($compareLocale)
                        <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">{{ strtoupper($compareLocale) }}</th>
                    @endif
                    <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">Status</th>
                    <th scope="col" class="relative py-3.5 pl-3 pr-4 sm:pr-6">
                        <span class="sr-only">Actions</span>
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-900">
                @forelse($keys as $key)
                    @php
                        $translation = $key->translations->firstWhere('locale', $currentLocale);
                        $compareTranslation = $compareLocale ? $key->translations->firstWhere('locale', $compareLocale) : null;
                    @endphp
                    <tr>
                        <td class="whitespace-nowrap py-4 pl-4 pr-3 text-sm sm:pl-6">
                            <div class="font-medium text-gray-900 dark:text-white">{{ $key->key }}</div>
                            @if($key->description)
                                <div class="mt-1 text-gray-500 dark:text-gray-400 text-xs">{{ Str::limit($key->description, 50) }}</div>
                            @endif
                        </td>
                        <td class="px-3 py-4 text-sm text-gray-500 dark:text-gray-400">
                            @if($translation)
                                <div class="max-w-xs truncate" title="{{ $translation->value }}">{{ Str::limit($translation->value, 60) }}</div>
                            @else
                                <span class="italic text-gray-400">Missing</span>
                            @endif
                        </td>
                        @if($compareLocale)
                            <td class="px-3 py-4 text-sm text-gray-500 dark:text-gray-400">
                                @if($compareTranslation)
                                    <div class="max-w-xs truncate" title="{{ $compareTranslation->value }}">{{ Str::limit($compareTranslation->value, 60) }}</div>
                                @else
                                    <span class="italic text-gray-400">Missing</span>
                                @endif
                            </td>
                        @endif
                        <td class="whitespace-nowrap px-3 py-4 text-sm">
                            @if($translation)
                                <x-prosetta::status-badge :status="$translation->status" />
                            @else
                                <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-800 dark:bg-gray-700 dark:text-gray-300">Missing</span>
                            @endif
                        </td>
                        <td class="relative whitespace-nowrap py-4 pl-3 pr-4 text-right text-sm font-medium sm:pr-6">
                            <a href="{{ route('prosetta.keys.show', $key) }}" class="text-prosetta-600 hover:text-prosetta-900 dark:text-prosetta-400">Edit</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $compareLocale ? 5 : 4 }}" class="px-6 py-10 text-center text-gray-500 dark:text-gray-400">
                            No keys found matching your criteria.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    @if($keys->hasPages())
        <div class="mt-4">
            {{ $keys->withQueryString()->links() }}
        </div>
    @endif

</x-prosetta::layout>
