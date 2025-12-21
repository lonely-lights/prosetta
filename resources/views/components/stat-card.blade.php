@props(['title', 'value', 'icon' => null, 'color' => 'prosetta'])

@php
$colorClasses = match($color) {
    'green' => 'bg-green-500',
    'yellow' => 'bg-yellow-500',
    'red' => 'bg-red-500',
    'blue' => 'bg-blue-500',
    'prosetta' => 'bg-prosetta-500',
    default => 'bg-gray-500',
};
@endphp

<div class="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
    <div class="p-5">
        <div class="flex items-center">
            @if($icon)
            <div class="flex-shrink-0">
                <div class="{{ $colorClasses }} rounded-md p-3">
                    {{ $icon }}
                </div>
            </div>
            @endif
            <div class="{{ $icon ? 'ml-5' : '' }} w-0 flex-1">
                <dl>
                    <dt class="truncate text-sm font-medium text-gray-500 dark:text-gray-400">{{ $title }}</dt>
                    <dd class="text-lg font-semibold text-gray-900 dark:text-white">{{ $value }}</dd>
                </dl>
            </div>
        </div>
    </div>
    @if(isset($footer))
    <div class="bg-gray-50 px-5 py-3 dark:bg-gray-700">
        {{ $footer }}
    </div>
    @endif
</div>
