<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Exceptions\Provider;

/** Down, overloaded, unreachable, timed out or a 5xx: worth retrying later. */
final class ProviderUnavailable extends ProviderException {}
