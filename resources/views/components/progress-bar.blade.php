@props(['percentage' => 0, 'size' => 'md'])

@php
$height = match($size) {
    'sm' => 'h-1.5',
    'md' => 'h-2.5',
    'lg' => 'h-4',
    default => 'h-2.5',
};

$color = match(true) {
    $percentage >= 100 => 'bg-green-500',
    $percentage >= 75 => 'bg-prosetta-500',
    $percentage >= 50 => 'bg-yellow-500',
    default => 'bg-red-500',
};
@endphp

<div {{ $attributes->merge(['class' => "w-full bg-gray-200 rounded-full {$height} dark:bg-gray-700"]) }}>
    <div class="{{ $color }} {{ $height }} rounded-full transition-all duration-300" style="width: {{ min($percentage, 100) }}%"></div>
</div>
