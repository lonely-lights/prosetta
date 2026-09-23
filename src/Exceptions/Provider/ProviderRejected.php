<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Exceptions\Provider;

/** An invalid key, an unknown or retired model, or a malformed request: retrying won't help. */
final class ProviderRejected extends ProviderException {}
