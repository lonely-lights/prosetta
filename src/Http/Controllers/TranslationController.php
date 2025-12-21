<?php

namespace LonelyLights\Prosetta\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use LonelyLights\Prosetta\Models\Translation;

/**
 * Translation Controller
 *
 * Handles translation value updates and review actions.
 * Supports both AJAX requests and standard form submissions.
 *
 * @package LonelyLights\Prosetta\Http\Controllers
 */
class TranslationController extends Controller {
    /**
     * Update the specified translation.
     *
     * @param Request $request
     * @param Translation $translation
     * @return JsonResponse|RedirectResponse
     */
    public function update(Request $request, Translation $translation): JsonResponse|RedirectResponse {
        $validated = $request->validate([
            'value' => 'required|string',
        ]);

        $previousValue = $translation->value;
        $translation->edit($validated['value'], auth()->id());

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Translation updated.',
                'translation' => [
                    'id' => $translation->id,
                    'value' => $translation->value,
                    'status' => $translation->status,
                    'previous_value' => $previousValue,
                ],
            ]);
        }

        return redirect()->back()->with('success', 'Translation updated.');
    }

    /**
     * Approve the specified translation.
     *
     * @param Request $request
     * @param Translation $translation
     * @return JsonResponse|RedirectResponse
     */
    public function approve(Request $request, Translation $translation): JsonResponse|RedirectResponse {
        $notes = $request->input('notes');

        $translation->approve(auth()->id(), $notes);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Translation approved.',
                'translation' => [
                    'id' => $translation->id,
                    'status' => $translation->status,
                    'reviewed_at' => $translation->reviewed_at?->toIso8601String(),
                ],
            ]);
        }

        return redirect()->back()->with('success', 'Translation approved.');
    }

    /**
     * Reject the specified translation.
     *
     * @param Request $request
     * @param Translation $translation
     * @return JsonResponse|RedirectResponse
     */
    public function reject(Request $request, Translation $translation): JsonResponse|RedirectResponse {
        $validated = $request->validate([
            'notes' => 'required|string|max:1000',
        ]);

        $translation->reject(auth()->id(), $validated['notes']);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Translation rejected.',
                'translation' => [
                    'id' => $translation->id,
                    'status' => $translation->status,
                    'reviewed_at' => $translation->reviewed_at?->toIso8601String(),
                ],
            ]);
        }

        return redirect()->back()->with('success', 'Translation rejected.');
    }
}
