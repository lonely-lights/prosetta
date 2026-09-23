<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Exceptions\Provider;

/**
 * The provider refused this batch's request (context too long, invalid
 * input: an HTTP 400 or 422), but the provider itself is fine. It never
 * touches the circuit or halts: the queued job fails into failed_jobs with
 * its error, and a synchronous run records the chunk as failed and goes on.
 */
final class ProviderBatchRejected extends ProviderException {}
