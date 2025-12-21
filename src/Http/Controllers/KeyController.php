<?php

namespace LonelyLights\Prosetta\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use LonelyLights\Prosetta\Facades\Prosetta;
use LonelyLights\Prosetta\Models\TranslationKey;

/**
 * Key Controller
 *
 * Handles translation key management.
 *
 * @package LonelyLights\Prosetta\Http\Controllers
 */
class KeyController extends Controller
{
    /**
     * Display the specified key with all its translations.
     *
     * @param Request $request
     * @param TranslationKey $key
     * @return View
     */
    public function show(Request $request, TranslationKey $key): View
    {
        $key->load(['file', 'translations.reviews' => function ($q) {
            $q->latest()->limit(5);
        }]);

        $locales = Prosetta::locales();

        // Build translations map
        $translations = [];
        foreach ($locales as $locale) {
            $localeCode = $locale->locale_initials;
            $translations[$localeCode] = $key->translations->firstWhere('locale', $localeCode);
        }

        return view('prosetta::keys.show', [
            'key' => $key,
            'locales' => $locales,
            'translations' => $translations,
        ]);
    }

    /**
     * Show the form for editing the key.
     *
     * @param TranslationKey $key
     * @return View
     */
    public function edit(TranslationKey $key): View
    {
        $key->load('file');

        return view('prosetta::keys.edit', [
            'key' => $key,
        ]);
    }

    /**
     * Update the specified key.
     *
     * @param Request $request
     * @param TranslationKey $key
     * @return RedirectResponse
     */
    public function update(Request $request, TranslationKey $key): RedirectResponse
    {
        $validated = $request->validate([
            'description' => 'nullable|string',
            'context' => 'nullable|string',
            'placeholders' => 'nullable|array',
            'max_length' => 'nullable|integer|min:1',
            'is_html' => 'boolean',
            'is_deprecated' => 'boolean',
        ]);

        $key->update($validated);

        return redirect()->route('prosetta.keys.show', $key)
            ->with('success', 'Key updated successfully.');
    }

    /**
     * Remove the specified key.
     *
     * @param TranslationKey $key
     * @return RedirectResponse
     */
    public function destroy(TranslationKey $key): RedirectResponse
    {
        $file = $key->file;
        $keyName = $key->key;
        $key->delete();

        return redirect()->route('prosetta.files.show', $file)
            ->with('success', "Key '{$keyName}' deleted successfully.");
    }
}
