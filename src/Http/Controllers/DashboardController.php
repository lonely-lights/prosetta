<?php

namespace LonelyLights\Prosetta\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use LonelyLights\Prosetta\Facades\Prosetta;

/**
 * Dashboard Controller
 *
 * Handles the main Prosetta dashboard and global actions.
 *
 * @package LonelyLights\Prosetta\Http\Controllers
 */
class DashboardController extends Controller {
    /**
     * Display the dashboard.
     *
     * @param Request $request
     * @return View
     */
    public function index(Request $request): View {
        $locales = Prosetta::locales();
        $currentLocale = $request->get('locale', $locales->first()?->locale_initials ?? 'en');

        $files = Prosetta::files();
        $statistics = Prosetta::statistics($currentLocale);

        // Get per-file statistics
        $fileStats = [];
        foreach ($files as $file) {
            $fileStats[$file->id] = $file->getStatistics($currentLocale);
        }

        return view('prosetta::dashboard', [
            'locales' => $locales,
            'currentLocale' => $currentLocale,
            'files' => $files,
            'statistics' => $statistics,
            'fileStats' => $fileStats,
        ]);
    }

    /**
     * Sync language files to database.
     *
     * @param Request $request
     * @return RedirectResponse
     */
    public function sync(Request $request): RedirectResponse {
        $locale = $request->input('locale');

        if ($locale) {
            $report = Prosetta::sync($locale);
            $message = "Synced locale '$locale': " .
                count($report['new_files']) . ' new files, ' .
                count($report['new_keys']) . ' new keys, ' .
                count($report['updated_keys']) . ' updated keys.';
        } else {
            $reports = Prosetta::syncAll();
            $totalFiles = 0;
            $totalKeys = 0;
            foreach ($reports as $report) {
                $totalFiles += count($report['new_files']);
                $totalKeys += count($report['new_keys']);
            }
            $message = "Synced all locales: $totalFiles new files, $totalKeys new keys.";
        }

        return redirect()->route('prosetta.dashboard')
            ->with('success', $message);
    }

    /**
     * Export translations to language files.
     *
     * @param Request $request
     * @return RedirectResponse
     */
    public function export(Request $request): RedirectResponse {
        $locale = $request->input('locale');

        if (!$locale) {
            return redirect()->route('prosetta.dashboard')
                ->with('error', 'Please select a locale to export.');
        }

        $results = Prosetta::exportAll($locale);
        $success = count(array_filter($results));
        $failed = count($results) - $success;

        $message = "Exported $success files for locale '$locale'.";
        if ($failed > 0) {
            $message .= " $failed files failed.";
        }

        return redirect()->route('prosetta.dashboard')
            ->with($failed > 0 ? 'warning' : 'success', $message);
    }
}
