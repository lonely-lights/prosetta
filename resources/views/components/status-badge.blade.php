@props(['status'])

@php
$classes = match($status) {
    'approved' => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300',
    'needs_review' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300',
    'rejected' => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300',
    'draft' => 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300',
    default => 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300',
};

$label = match($status) {
    'approved' => 'Approved',
    'needs_review' => 'Needs Review',
    'rejected' => 'Rejected',
    'draft' => 'Draft',
    default => ucfirst($status),
};
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {$classes}"]) }}>
    {{ $label }}
</span>
