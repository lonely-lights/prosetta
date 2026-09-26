<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use LonelyLights\Prosetta\Enums\FileFormat;
use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Support\KeyRef;
use LonelyLights\Prosetta\Support\Settings;
use Throwable;

/**
 * Production's interface-text approvals, for prosetta:pull in development.
 * Answers only with prosetta.pull.token set and given as a bearer token;
 * anything else is a 404, so an unconfigured or probed site reveals nothing.
 */
final readonly class ApprovalsController {
    public const int PAGE = 500;

    public function __invoke(Request $request): JsonResponse {
        $token = config('prosetta.pull.token');

        if (! is_string($token) || $token === '' || ! hash_equals($token, (string) $request->bearerToken())) {
            abort(404);
        }

        $since = $this->time($request->query('since'));
        $after = (int) $request->query('after', 0);
        $t = Settings::table('translations');
        $k = Settings::table('keys');
        $f = Settings::table('files');
        $model = Settings::model('translation');

        $rows = $model::query()->toBase()
            ->join($k, "$k.id", '=', "$t.key_id")
            ->join($f, "$f.id", '=', "$k.file_id")
            ->where("$f.format", '!=', FileFormat::Database->value)
            ->where("$t.status", TranslationStatus::Approved->value)
            ->whereNotNull("$t.approved_value")
            ->whereNotNull("$t.reviewed_at")
            ->when($since !== null, fn ($query) => $query->where(fn ($query) => $query
                ->where("$t.reviewed_at", '>', $since)
                ->orWhere(fn ($query) => $query->where("$t.reviewed_at", '=', $since)->where("$t.id", '>', $after))))
            ->orderBy("$t.reviewed_at")->orderBy("$t.id")
            ->limit(self::PAGE + 1)
            ->get(["$t.id", "$t.locale", "$t.approved_value", "$t.approved_source_hash", "$t.reviewed_by", "$t.reviewed_at", "$k.key", "$f.namespace", "$f.group"]);

        $more = $rows->count() > self::PAGE;
        $rows = $rows->take(self::PAGE);
        $last = $rows->last();

        return response()->json([
            'approvals' => $rows->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'ref' => (new KeyRef((string) $row->namespace, (string) $row->group, (string) $row->key))->toString(),
                'locale' => (string) $row->locale,
                'value' => (string) $row->approved_value,
                'source_hash' => (string) $row->approved_source_hash,
                'reviewed_by' => $row->reviewed_by === null ? null : (string) $row->reviewed_by,
                'reviewed_at' => Carbon::parse((string) $row->reviewed_at)->toIso8601String(),
            ])->values()->all(),
            'next' => $more && $last !== null
                ? ['since' => Carbon::parse((string) $last->reviewed_at)->toIso8601String(), 'after' => (int) $last->id]
                : null,
        ]);
    }

    private function time(mixed $value): ?Carbon {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
