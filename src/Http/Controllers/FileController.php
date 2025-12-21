<?php

namespace LonelyLights\Prosetta\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use LonelyLights\Prosetta\Facades\Prosetta;
use LonelyLights\Prosetta\Models\TranslationFile;

/**
 * File Controller
 *
 * Handles translation file management.
 *
 * @package LonelyLights\Prosetta\Http\Controllers
 */
class FileController extends Controller {
    /**
     * Display a listing of translation files.
     *
     * @param Request $request
     * @return View
     */
    public function index(Request $request): View {
        $locales = Prosetta::locales();
        $currentLocale = $request->get('locale', $locales->first()?->locale_initials ?? 'en');

        $query = TranslationFile::query()->withCount('keys');

        // Filter by category
        if ($category = $request->get('category')) {
            $query->where('category', $category);
        }

        // Search
        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('path', 'like', "%$search%")
                    ->orWhere('name', 'like', "%$search%")
                    ->orWhere('description', 'like', "%$search%");
            });
        }

        $files = $query->orderBy('path')->get();

        // Get categories for filter
        $categories = TranslationFile::distinct()->whereNotNull('category')->pluck('category');

        // Get statistics per file
        $fileStats = [];
        foreach ($files as $file) {
            $fileStats[$file->id] = $file->getStatistics($currentLocale);
        }

        return view('prosetta::files.index', [
            'files' => $files,
            'locales' => $locales,
            'currentLocale' => $currentLocale,
            'categories' => $categories,
            'currentCategory' => $category,
            'search' => $search,
            'fileStats' => $fileStats,
        ]);
    }

    /**
     * Show the form for creating a new file.
     *
     * @return View
     */
    public function create(): View {
        $categories = TranslationFile::distinct()->whereNotNull('category')->pluck('category');

        return view('prosetta::files.create', [
            'categories' => $categories,
        ]);
    }

    /**
     * Store a newly created file.
     *
     * @param Request $request
     * @return RedirectResponse
     */
    public function store(Request $request): RedirectResponse {
        $validated = $request->validate([
            'path' => 'required|string|max:255|unique:' . config('prosetta.tableNames.files', 'prosetta_files') . ',path',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'category' => 'nullable|string|max:255',
        ]);

        $file = TranslationFile::create($validated);

        return redirect()->route('prosetta.files.show', $file)
            ->with('success', "File '$file->name' created successfully.");
    }

    /**
     * Display the specified file with its keys.
     *
     * @param Request $request
     * @param TranslationFile $file
     * @return View
     */
    public function show(Request $request, TranslationFile $file): View {
        $locales = Prosetta::locales();
        $currentLocale = $request->get('locale', $locales->first()?->locale_initials ?? 'en');
        $compareLocale = $request->get('compare');

        $query = $file->keys()->with(['translations' => function ($q) use ($currentLocale, $compareLocale) {
            $locales = [$currentLocale];
            if ($compareLocale) {
                $locales[] = $compareLocale;
            }
            $q->whereIn('locale', $locales);
        }]);

        // Filter by status
        if ($status = $request->get('status')) {
            switch ($status) {
                case 'missing':
                    $query->missingTranslation($currentLocale);
                    break;
                case 'needs_review':
                    $query->whereHas('translations', fn($q) => $q->where('locale', $currentLocale)->where('status', 'needs_review'));
                    break;
                case 'approved':
                    $query->whereHas('translations', fn($q) => $q->where('locale', $currentLocale)->where('status', 'approved'));
                    break;
                case 'draft':
                    $query->whereHas('translations', fn($q) => $q->where('locale', $currentLocale)->where('status', 'draft'));
                    break;
            }
        }

        // Search keys
        if ($search = $request->get('search')) {
            $query->where('key', 'like', "%$search%");
        }

        $keys = $query->orderBy('key')->paginate(50);

        $statistics = $file->getStatistics($currentLocale);

        return view('prosetta::files.show', [
            'file' => $file,
            'keys' => $keys,
            'locales' => $locales,
            'currentLocale' => $currentLocale,
            'compareLocale' => $compareLocale,
            'statistics' => $statistics,
            'status' => $status,
            'search' => $search,
        ]);
    }

    /**
     * Show the form for editing the file.
     *
     * @param TranslationFile $file
     * @return View
     */
    public function edit(TranslationFile $file): View {
        $categories = TranslationFile::distinct()->whereNotNull('category')->pluck('category');

        return view('prosetta::files.edit', [
            'file' => $file,
            'categories' => $categories,
        ]);
    }

    /**
     * Update the specified file.
     *
     * @param Request $request
     * @param TranslationFile $file
     * @return RedirectResponse
     */
    public function update(Request $request, TranslationFile $file): RedirectResponse {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'category' => 'nullable|string|max:255',
        ]);

        $file->update($validated);

        return redirect()->route('prosetta.files.show', $file)
            ->with('success', "File '$file->name' updated successfully.");
    }

    /**
     * Remove the specified file.
     *
     * @param TranslationFile $file
     * @return RedirectResponse
     */
    public function destroy(TranslationFile $file): RedirectResponse {
        $name = $file->name;
        $file->delete();

        return redirect()->route('prosetta.files.index')
            ->with('success', "File '$name' deleted successfully.");
    }
}
