<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Review;

use Illuminate\Contracts\Auth\Authenticatable;
use LonelyLights\Prosetta\Auth\Authorizer;
use LonelyLights\Prosetta\Contracts\LocaleSource;
use LonelyLights\Prosetta\Data\LocaleDescriptor;
use LonelyLights\Prosetta\Enums\Ability;

/**
 * A person looking at translations, and what they may do: resolved once
 * through the Authorizer, then passed to every query and action so
 * filtering by permission happens in one place.
 */
final readonly class Viewer {
    /**
     * @param list<string> $translates target locales they may see and edit (Review implies Translate)
     * @param list<string> $reviews target locales they may approve and reject
     */
    public function __construct(
        public ?Authenticatable $user,
        public array $translates,
        public array $reviews,
        public bool $manages,
        public bool $isEditable,
    ) {}

    public static function for(?Authenticatable $user): self {
        $authorizer = app(Authorizer::class);
        $codes = array_map(fn (LocaleDescriptor $locale) => $locale->code, app(LocaleSource::class)->targets());

        return new self(
            $user,
            array_values(array_filter($codes, fn (string $code) => $authorizer->allows($user, Ability::Translate, $code))),
            array_values(array_filter($codes, fn (string $code) => $authorizer->allows($user, Ability::Review, $code))),
            $authorizer->allows($user, Ability::Manage),
            self::editable(),
        );
    }

    /** Whether people may change translations in this environment. */
    public static function editable(): bool {
        $configured = config('prosetta.review.editable');

        return $configured === null
            ? app()->environment('local', 'staging')
            : filter_var($configured, FILTER_VALIDATE_BOOL);
    }

    public function canTranslate(string $locale): bool {
        return in_array($locale, $this->translates, true);
    }

    public function canReview(string $locale): bool {
        return in_array($locale, $this->reviews, true);
    }

    /** @return list<string> the locales this viewer sees, in target order */
    public function locales(): array {
        return $this->translates;
    }

    /** @return array{translates: list<string>, reviews: list<string>, manages: bool, editable: bool} */
    public function toArray(): array {
        return ['translates' => $this->translates, 'reviews' => $this->reviews, 'manages' => $this->manages, 'editable' => $this->isEditable];
    }
}
