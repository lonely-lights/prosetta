<x-prosetta::layout title="Create File">

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
                    <span class="ml-4 text-sm font-medium text-gray-500 dark:text-gray-400">Create</span>
                </div>
            </li>
        </ol>
    </nav>

    <div class="mx-auto max-w-2xl">
        <form action="{{ route('prosetta.files.store') }}" method="POST" class="space-y-6">
            @csrf

            <div class="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
                <div class="px-4 py-5 sm:p-6">
                    <h3 class="text-base font-semibold leading-6 text-gray-900 dark:text-white">Create Translation File</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Add a new translation file to manage.</p>

                    <div class="mt-6 space-y-6">
                        <div>
                            <label for="path" class="block text-sm font-medium leading-6 text-gray-900 dark:text-white">Path</label>
                            <div class="mt-2">
                                <input type="text" name="path" id="path" value="{{ old('path') }}" required placeholder="e.g., auth, profile, common" class="block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-prosetta-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600 sm:text-sm sm:leading-6">
                            </div>
                            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">The path corresponds to the language file name (e.g., "auth" for lang/en/auth.php)</p>
                            @error('path')
                                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="name" class="block text-sm font-medium leading-6 text-gray-900 dark:text-white">Name</label>
                            <div class="mt-2">
                                <input type="text" name="name" id="name" value="{{ old('name') }}" required placeholder="e.g., Authentication" class="block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-prosetta-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600 sm:text-sm sm:leading-6">
                            </div>
                            @error('name')
                                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="description" class="block text-sm font-medium leading-6 text-gray-900 dark:text-white">Description</label>
                            <div class="mt-2">
                                <textarea name="description" id="description" rows="3" placeholder="Describe what translations this file contains..." class="block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-prosetta-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600 sm:text-sm sm:leading-6">{{ old('description') }}</textarea>
                            </div>
                            @error('description')
                                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="category" class="block text-sm font-medium leading-6 text-gray-900 dark:text-white">Category</label>
                            <div class="mt-2">
                                <input type="text" name="category" id="category" value="{{ old('category') }}" list="categories" placeholder="e.g., User Account, Core, Features" class="block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-prosetta-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600 sm:text-sm sm:leading-6">
                                <datalist id="categories">
                                    @foreach($categories as $category)
                                        <option value="{{ $category }}">
                                    @endforeach
                                </datalist>
                            </div>
                            @error('category')
                                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </div>
                <div class="bg-gray-50 px-4 py-3 text-right dark:bg-gray-700 sm:px-6">
                    <a href="{{ route('prosetta.files.index') }}" class="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-600 dark:text-white dark:ring-gray-500">Cancel</a>
                    <button type="submit" class="ml-3 inline-flex justify-center rounded-md bg-prosetta-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-prosetta-500">Create File</button>
                </div>
            </div>
        </form>
    </div>

</x-prosetta::layout>
