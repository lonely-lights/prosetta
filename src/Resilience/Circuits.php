<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Resilience;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Str;
use LonelyLights\Prosetta\Support\Settings;

/** Hands out circuits by name and remembers the names, for prosetta:circuit status. */
final readonly class Circuits {
    private const string NAMES = 'prosetta:circuits';

    public function __construct(private Dispatcher $events) {}

    public function for(string $name): Circuit {
        $cache = Settings::cache();
        $names = (array) $cache->get(self::NAMES, []);

        if (! in_array($name, $names, true)) {
            $cache->forever(self::NAMES, [...$names, $name]);
        }

        return new Circuit($name, $cache, $this->events);
    }

    /** @return list<string> */
    public function names(): array {
        return array_values((array) Settings::cache()->get(self::NAMES, []));
    }

    /** "fake-translation-driver:gpt-x": the driver's class in kebab case, then the model (or "default"). */
    public static function nameFor(object $driver, ?string $model): string {
        return Str::kebab(class_basename($driver)).':'.($model ?? 'default');
    }
}
