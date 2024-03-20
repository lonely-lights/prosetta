<?php

namespace LonelyLights\Prosetta\Jobs;

use Closure;
use Illuminate\Bus\Queueable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;

class ProcessEntries implements ShouldQueue {
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    # Properties
    protected Model $model;
    protected array|string $keys;
    protected string|Closure $path;
    protected array|null|string $affixAttribute;
    protected ?string $localeOperation;
    protected ?string $locale;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(Model $model, $keys, $affixAttribute = null, $localeOperation = null, $locale = null) {
        $this->model = $model;
        $this->keys = $keys;
        $this->affixAttribute = $affixAttribute;
        $this->localeOperation = $localeOperation;
        $this->locale = $locale;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(): void {
        $path = $this->model->getProsettaPath();
        Log::info($path);
        if (method_exists($this->model, 'bootProsetta')) {
            $attributes = [
                'keys' => $this->keys,
                'path' => $path,
                'affixAttribute' => $this->affixAttribute,
                'localeOperation' => $this->localeOperation,
                'locale' => $this->locale
            ];

            Log::info('bootProsetta attributes', $attributes);
            $this->model::bootProsetta($this->keys, $path, $this->affixAttribute, $this->localeOperation, $this->locale);
        } else {
            Log::error("[JOB - CreateProsettaEntries] Method 'bootProsetta' not found in model.", ['model' => get_class($this->model)]);
        }
    }
}
