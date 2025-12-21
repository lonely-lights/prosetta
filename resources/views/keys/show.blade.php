<x-prosetta::layout title="{{ $key->key }}">

    <!-- Breadcrumb -->
    <nav class="mb-4 flex" aria-label="Breadcrumb">
        <ol role="list" class="flex items-center space-x-4">
            <li>
                <a href="{{ route('prosetta.files.index') }}" class="text-gray-400 hover:text-gray-500 dark:text-gray-500 dark:hover:text-gray-400">Files</a>
            </li>
            <li>
                <div class="flex items-center">
                    <svg class="h-5 w-5 flex-shrink-0 text-gray-300 dark:text-gray-600" fill="currentColor" viewBox="0 0 20 20">
                        <path d="M5.555 17.776l8-16 .894.448-8 16-.894-.448z" />
                    </svg>
                    <a href="{{ route('prosetta.files.show', $key->file) }}" class="ml-4 text-sm font-medium text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300">{{ $key->file->path }}</a>
                </div>
            </li>
            <li>
                <div class="flex items-center">
                    <svg class="h-5 w-5 flex-shrink-0 text-gray-300 dark:text-gray-600" fill="currentColor" viewBox="0 0 20 20">
                        <path d="M5.555 17.776l8-16 .894.448-8 16-.894-.448z" />
                    </svg>
                    <span class="ml-4 text-sm font-medium text-gray-500 dark:text-gray-400">{{ $key->key }}</span>
                </div>
            </li>
        </ol>
    </nav>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <!-- Key Info -->
        <div class="lg:col-span-1">
            <div class="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
                <div class="px-4 py-5 sm:p-6">
                    <h3 class="text-base font-semibold leading-6 text-gray-900 dark:text-white">Key Details</h3>

                    <dl class="mt-4 space-y-4">
                        <div>
                            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Full Key</dt>
                            <dd class="mt-1 text-sm text-gray-900 dark:text-white font-mono">{{ $key->file->path }}.{{ $key->key }}</dd>
                        </div>

                        @if($key->description)
                        <div>
                            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Description</dt>
                            <dd class="mt-1 text-sm text-gray-900 dark:text-white">{{ $key->description }}</dd>
                        </div>
                        @endif

                        @if($key->context)
                        <div>
                            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Context</dt>
                            <dd class="mt-1 text-sm text-gray-900 dark:text-white">{{ $key->context }}</dd>
                        </div>
                        @endif

                        @if($key->placeholders)
                        <div>
                            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Placeholders</dt>
                            <dd class="mt-1 flex flex-wrap gap-1">
                                @foreach($key->placeholders as $placeholder)
                                    <span class="inline-flex items-center rounded-full bg-blue-100 px-2.5 py-0.5 text-xs font-medium text-blue-800 dark:bg-blue-900 dark:text-blue-300">{{ $placeholder }}</span>
                                @endforeach
                            </dd>
                        </div>
                        @endif

                        @if($key->max_length)
                        <div>
                            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Max Length</dt>
                            <dd class="mt-1 text-sm text-gray-900 dark:text-white">{{ $key->max_length }} characters</dd>
                        </div>
                        @endif

                        <div class="flex gap-2">
                            @if($key->is_html)
                                <span class="inline-flex items-center rounded-full bg-purple-100 px-2.5 py-0.5 text-xs font-medium text-purple-800 dark:bg-purple-900 dark:text-purple-300">HTML</span>
                            @endif
                            @if($key->is_deprecated)
                                <span class="inline-flex items-center rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-medium text-red-800 dark:bg-red-900 dark:text-red-300">Deprecated</span>
                            @endif
                        </div>
                    </dl>

                    <div class="mt-6">
                        <a href="{{ route('prosetta.keys.edit', $key) }}" class="text-sm font-semibold text-prosetta-600 hover:text-prosetta-500 dark:text-prosetta-400">
                            Edit key settings &rarr;
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Translations -->
        <div class="lg:col-span-2">
            <div class="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
                <div class="px-4 py-5 sm:p-6">
                    <h3 class="text-base font-semibold leading-6 text-gray-900 dark:text-white">Translations</h3>

                    <div class="mt-4 space-y-6" x-data="{ editing: null }">
                        @foreach($locales as $locale)
                            @php
                                $localeCode = $locale->locale_initials ?? $locale->code;
                                $localeName = $locale->english_name ?? $locale->name ?? $localeCode;
                                $translation = $translations[$localeCode] ?? null;
                            @endphp
                            <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                                <div class="flex items-center justify-between mb-2">
                                    <div class="flex items-center gap-2">
                                        <span class="font-medium text-gray-900 dark:text-white">{{ strtoupper($localeCode) }}</span>
                                        <span class="text-sm text-gray-500 dark:text-gray-400">{{ $localeName }}</span>
                                    </div>
                                    @if($translation)
                                        <x-prosetta::status-badge :status="$translation->status" />
                                    @else
                                        <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-800 dark:bg-gray-700 dark:text-gray-300">Missing</span>
                                    @endif
                                </div>

                                @if($translation)
                                    <div x-show="editing !== '{{ $localeCode }}'" class="mt-2">
                                        <p class="text-gray-700 dark:text-gray-300">{{ $translation->value }}</p>
                                        <div class="mt-3 flex gap-2">
                                            <button @click="editing = '{{ $localeCode }}'" class="text-sm text-prosetta-600 hover:text-prosetta-500 dark:text-prosetta-400">Edit</button>
                                            @if($translation->status !== 'approved')
                                                <form action="{{ route('prosetta.translations.approve', $translation) }}" method="POST" class="inline">
                                                    @csrf
                                                    <button type="submit" class="text-sm text-green-600 hover:text-green-500">Approve</button>
                                                </form>
                                            @endif
                                            @if($translation->status !== 'rejected')
                                                <button @click="editing = '{{ $localeCode }}_reject'" class="text-sm text-red-600 hover:text-red-500">Reject</button>
                                            @endif
                                        </div>

                                        @if($translation->source === 'ai' && $translation->confidence)
                                            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                                AI generated ({{ round($translation->confidence * 100) }}% confidence)
                                            </p>
                                        @endif
                                    </div>

                                    <!-- Edit Form -->
                                    <form x-show="editing === '{{ $localeCode }}'" x-cloak action="{{ route('prosetta.translations.update', $translation) }}" method="POST" class="mt-2">
                                        @csrf
                                        @method('PUT')
                                        <textarea name="value" rows="3" class="block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-prosetta-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600 sm:text-sm sm:leading-6">{{ $translation->value }}</textarea>
                                        <div class="mt-2 flex gap-2">
                                            <button type="submit" class="rounded-md bg-prosetta-600 px-2.5 py-1.5 text-sm font-semibold text-white shadow-sm hover:bg-prosetta-500">Save</button>
                                            <button type="button" @click="editing = null" class="rounded-md bg-white px-2.5 py-1.5 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-600 dark:text-white dark:ring-gray-500">Cancel</button>
                                        </div>
                                    </form>

                                    <!-- Reject Form -->
                                    <form x-show="editing === '{{ $localeCode }}_reject'" x-cloak action="{{ route('prosetta.translations.reject', $translation) }}" method="POST" class="mt-2">
                                        @csrf
                                        <textarea name="notes" rows="2" placeholder="Reason for rejection..." required class="block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-red-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600 sm:text-sm sm:leading-6"></textarea>
                                        <div class="mt-2 flex gap-2">
                                            <button type="submit" class="rounded-md bg-red-600 px-2.5 py-1.5 text-sm font-semibold text-white shadow-sm hover:bg-red-500">Reject</button>
                                            <button type="button" @click="editing = null" class="rounded-md bg-white px-2.5 py-1.5 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-600 dark:text-white dark:ring-gray-500">Cancel</button>
                                        </div>
                                    </form>
                                @else
                                    <p class="mt-2 italic text-gray-400">No translation for this locale.</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>

</x-prosetta::layout>
