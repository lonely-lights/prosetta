<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use LonelyLights\Prosetta\Enums\FileFormat;
use LonelyLights\Prosetta\Enums\ReviewAction;
use LonelyLights\Prosetta\Support\KeyRef;
use LonelyLights\Prosetta\Support\Settings;

/**
 * Production's interface-text approvals, for prosetta:pull in development:
 * each approval in the review trail that is still the live wording, in the
 * order they happened (by the trail's own id, so clocks and time zones
 * never matter). Answers only with prosetta.pull.token set and given as a
 * bearer token; anything else is a 404.
 */
final readonly class ApprovalsController {
    public const int PAGE = 500;

    public function __invoke(Request $request): JsonResponse {
        $token = config('prosetta.pull.token');

        if (! is_string($token) || $token === '' || ! hash_equals($token, (string) $request->bearerToken())) {
            abort(404);
        }

        $after = max(0, (int) $request->query('after', 0));
        $r = Settings::table('reviews');
        $t = Settings::table('translations');
        $k = Settings::table('keys');
        $f = Settings::table('files');
        $model = Settings::model('review');

        # Still Live: the Approval's Wording Is What the Row Serves Now; a Later Approval Supersedes It and Is Served Itself
        $rows = $model::query()->toBase()
            ->join($t, "$t.id", '=', "$r.translation_id")
            ->join($k, "$k.id", '=', "$t.key_id")
            ->join($f, "$f.id", '=', "$k.file_id")
            ->where("$r.action", ReviewAction::Approved->value)
            ->where("$r.id", '>', $after)
            ->where("$f.format", '!=', FileFormat::Database->value)
            ->whereColumn("$r.new_value", "$t.approved_value")
            ->orderBy("$r.id")
            ->limit(self::PAGE + 1)
            ->get(["$r.id", "$r.reviewer_id", "$r.created_at", "$t.locale", "$t.approved_value", "$t.approved_source_hash", "$k.key", "$f.namespace", "$f.group"]);

        $more = $rows->count() > self::PAGE;
        $rows = $rows->take(self::PAGE);

        return response()->json([
            'approvals' => $rows->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'ref' => (new KeyRef((string) $row->namespace, (string) $row->group, (string) $row->key))->toString(),
                'locale' => (string) $row->locale,
                'value' => (string) $row->approved_value,
                'source_hash' => (string) $row->approved_source_hash,
                'reviewed_by' => $row->reviewer_id === null ? null : (string) $row->reviewer_id,
                'reviewed_at' => Carbon::parse((string) $row->created_at)->toIso8601String(),
            ])->values()->all(),
            'next' => $more ? ['after' => (int) $rows->last()->id] : null,
        ]);
    }
}
