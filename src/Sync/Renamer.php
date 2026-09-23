<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Sync;

use Illuminate\Support\Facades\DB;
use LonelyLights\Prosetta\Exceptions\ProsettaException;
use LonelyLights\Prosetta\Models\TranslationKey;
use LonelyLights\Prosetta\Queries\KeyFinder;
use LonelyLights\Prosetta\Support\Settings;
use Throwable;

/**
 * Carries translations across a rename. Rename the key in the source file,
 * run sync (old key obsolete, new key added), then rename: the translations
 * and their review history move to the new key and the old key is deleted.
 */
final readonly class Renamer {
    public function __construct(private KeyFinder $finder) {}

    /** @throws Throwable when the transaction fails */
    public function rename(string $from, string $to): TranslationKey {
        $old = $this->finder->find($from) ?? throw new ProsettaException("No key [$from]. Run prosetta:sync first if you only just renamed it.");
        $new = $this->finder->find($to) ?? throw new ProsettaException("No key [$to]. Rename it in the source file and run prosetta:sync first.");

        if ($old->is($new)) {
            throw new ProsettaException('Both references name the same key.');
        }

        if ($new->translations()->exists()) {
            throw new ProsettaException("[$to] already has translations; refusing to overwrite them.");
        }

        DB::transaction(function () use ($old, $new): void {
            DB::table(Settings::table('translations'))
                ->where('key_id', $old->getKey())
                ->update(['key_id' => $new->getKey(), 'updated_at' => now()]);
            $old->delete();
        });

        return $new->fresh(['file', 'translations']);
    }
}
