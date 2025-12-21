<x-prosetta::layout title="Edit Key: {{ $key->key }}">

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
                    <a href="{{ route('prosetta.keys.show', $key) }}" class="ml-4 text-sm font-medium text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300">{{ $key->key }}</a>
                </div>
            </li>
            <li>
                <div class="flex items-center">
                    <svg class="h-5 w-5 flex-shrink-0 text-gray-300 dark:text-gray-600" fill="currentColor" viewBox="0 0 20 20">
                        <path d="M5.555 17.776l8-16 .894.448-8 16-.894-.448z" />
                    </svg>
                    <span class="ml-4 text-sm font-medium text-gray-500 dark:text-gray-400">Edit</span>
                </div>
            </li>
        </ol>
    </nav>

    <div class="mx-auto max-w-2xl">
        <form action="{{ route('prosetta.keys.update', $key) }}" method="POST" class="space-y-6">
            @csrf
            @method('PUT')

            <div class="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
                <div class="px-4 py-5 sm:p-6">
                    <h3 class="text-base font-semibold leading-6 text-gray-900 dark:text-white">Edit Key Settings</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Key: <code class="bg-gray-100 dark:bg-gray-700 px-1 rounded">{{ $key->file->path }}.{{ $key->key }}</code></p>

                    <div class="mt-6 space-y-6">
                        <div>
                            <label for="description" class="block text-sm font-medium leading-6 text-gray-900 dark:text-white">Description</label>
                            <div class="mt-2">
                                <textarea name="description" id="description" rows="2" placeholder="Developer notes about this key..." class="block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-prosetta-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600 sm:text-sm sm:leading-6">{{ old('description', $key->description) }}</textarea>
                            </div>
                        </div>

                        <div>
                            <label for="context" class="block text-sm font-medium leading-6 text-gray-900 dark:text-white">Context</label>
                            <div class="mt-2">
                                <textarea name="context" id="context" rows="2" placeholder="Usage context for translators..." class="block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-prosetta-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600 sm:text-sm sm:leading-6">{{ old('context', $key->context) }}</textarea>
                            </div>
                            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Help translators understand where and how this text is used.</p>
                        </div>

                        <div>
                            <label for="max_length" class="block text-sm font-medium leading-6 text-gray-900 dark:text-white">Max Length</label>
                            <div class="mt-2">
                                <input type="number" name="max_length" id="max_length" value="{{ old('max_length', $key->max_length) }}" min="1" placeholder="e.g., 50" class="block w-32 rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-prosetta-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600 sm:text-sm sm:leading-6">
                            </div>
                            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">UI constraint for maximum character length (leave empty for no limit).</p>
                        </div>

                        <div class="flex gap-6">
                            <div class="flex items-center">
                                <input type="checkbox" name="is_html" id="is_html" value="1" {{ old('is_html', $key->is_html) ? 'checked' : '' }} class="h-4 w-4 rounded border-gray-300 text-prosetta-600 focus:ring-prosetta-600 dark:border-gray-600 dark:bg-gray-700">
                                <label for="is_html" class="ml-2 block text-sm text-gray-900 dark:text-white">Contains HTML markup</label>
                            </div>

                            <div class="flex items-center">
                                <input type="checkbox" name="is_deprecated" id="is_deprecated" value="1" {{ old('is_deprecated', $key->is_deprecated) ? 'checked' : '' }} class="h-4 w-4 rounded border-gray-300 text-prosetta-600 focus:ring-prosetta-600 dark:border-gray-600 dark:bg-gray-700">
                                <label for="is_deprecated" class="ml-2 block text-sm text-gray-900 dark:text-white">Mark as deprecated</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="bg-gray-50 px-4 py-3 text-right dark:bg-gray-700 sm:px-6">
                    <a href="{{ route('prosetta.keys.show', $key) }}" class="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-600 dark:text-white dark:ring-gray-500">Cancel</a>
                    <button type="submit" class="ml-3 inline-flex justify-center rounded-md bg-prosetta-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-prosetta-500">Save Changes</button>
                </div>
            </div>
        </form>

        <!-- Danger Zone -->
        <div class="mt-6 overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
            <div class="px-4 py-5 sm:p-6">
                <h3 class="text-base font-semibold leading-6 text-red-600">Danger Zone</h3>
                <div class="mt-2 max-w-xl text-sm text-gray-500 dark:text-gray-400">
                    <p>Deleting this key will also delete all its translations. This action cannot be undone.</p>
                </div>
                <div class="mt-5">
                    <form action="{{ route('prosetta.keys.destroy', $key) }}" method="POST" onsubmit="return confirm('Are you sure you want to delete this key? This action cannot be undone.')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="inline-flex items-center rounded-md bg-red-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500">
                            Delete Key
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

</x-prosetta::layout>
