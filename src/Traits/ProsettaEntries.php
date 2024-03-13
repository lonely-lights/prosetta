<?php

namespace LonelyLights\Prosetta\Traits;

use Closure;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LonelyLights\Prosetta\Services\LangKeyService;

# Runs During Form Updates

/**
 * @method static updated(Closure $param)
 * @method static deleting(Closure $param)
 * @method static created(Closure $param)
 */
trait ProsettaEntries {
    protected static string|array $languageKeys;
    protected static Closure|string $languagePath;
    protected static string|array|null $languageAffixAttribute = null;
    protected static string $localeOperation;
    protected static string $languageLocale;

    /**
     * Bootstraps the trait functionalities.
     */
    protected static function initProsetta(): void {
        static::created(function ($model) {
            try {
                static::handleLanguageEntry($model, 'create');
            } catch (Exception $e) {
                Log::channel(config('prosetta.logChannel', 'default'))->error("Exception in created event.", ['error' => $e->getMessage()]);
            }
        });

        static::updated(function ($model) {
            try {
                static::handleLanguageEntry($model, 'update');
            } catch (Exception $e) {
                Log::channel(config('prosetta.logChannel', 'default'))->error("Exception in updated event.", ['error' => $e->getMessage()]);
            }
        });

        static::deleting(function ($model) {
            try {
                static::handleLanguageEntry($model, 'remove');
            } catch (Exception $e) {
                Log::channel(config('prosetta.logChannel', 'default'))->error("Exception in deleting event.", ['error' => $e->getMessage()]);
            }
        });
    }


    /**
     * Builds an affix for language keys based on model attributes and configuration.
     *
     * This method constructs an affix (like a prefix or suffix) that is used with language keys.
     * The affix construction is based on `static::$languageAffixAttribute`. If this attribute is an array,
     * the method processes it to determine the affix type (e.g., 'column', 'string', 'only') and constructs
     * the affix accordingly. If it's a string, the method simply uses the corresponding model attribute.
     * The method logs a warning and returns null if it encounters any invalid configuration.
     *
     * @param Model $model The model instance used to derive certain types of affixes, especially when the affix type is 'column'.
     * @return string|array|null The constructed affix, which could be a string, an array, or null if the affix cannot be constructed. */
    private static function buildAffix(Model $model): string|array|null {
        if (is_array(static::$languageAffixAttribute) && count(static::$languageAffixAttribute) >= 2) {

            # Extract First Pair
            $paramOne = static::$languageAffixAttribute[0];
            $type = static::$languageAffixAttribute[1];

            # Check Type
            if (!in_array($type, [config('prosetta.column', 'column'), config('prosetta.string', 'string'), config('prosetta.only', 'only')])) {
                Log::channel(config('prosetta.logChannel', 'default'))->warning("Invalid affix type in buildAffix.", [
                    'type' => $type,
                    'model' => get_class($model),
                    'affixAttribute' => static::$languageAffixAttribute
                ]);
                return null;
            }

            # Determine the Base Affix
            if ($type === config('prosetta.only', 'only')) {
                $affix = [$model->{$paramOne}, config('prosetta.only', 'only')];
            } else {
                # Column or String
                $affix = $type === config('prosetta.column', 'column') ? ($model->{$paramOne} ?? null) : $paramOne;

                # Handle Optional Remaining Values for 'column' or 'string' types
                if (count(static::$languageAffixAttribute) >= 3) {
                    $paramThree = static::$languageAffixAttribute[2];
                    $fourthOption = static::$languageAffixAttribute[3] ?? null;

                    if (in_array($paramThree, [config('prosetta.prefix', 'prefix'), config('prosetta.suffix', 'suffix')])) {
                        $affix = [$affix, $paramThree];
                    } elseif ($fourthOption) {
                        if (!in_array($fourthOption, [config('prosetta.column', 'column'), config('prosetta.string', 'string')])) {
                            Log::channel(config('prosetta.logChannel', 'default'))->warning("Invalid fourth option in languageAffixAttribute. Expected '" . config('prosetta.column', 'column') . "' or '" . config('prosetta.string', 'string') . "', got: $fourthOption");
                            return null;
                        }
                        $affix = $fourthOption === config('prosetta.column', 'column')
                            ? [$affix, $model->{$paramThree} ?? null]
                            : [$affix, $paramThree];
                    }
                }
            }

        } else {
            # String
            $affix = $model->{static::$languageAffixAttribute} ?? null;
        }

        # Return
        return $affix;
    }


    /**
     * Configures the Prosetta settings for a model.
     *
     * @param array|string $keys The language keys to be used.
     * @param string|Closure $path The path or closure determining the language file path.
     * @param array|string|null $affixAttribute The attribute to be used as a prefix or suffix for language keys.
     * @param string|null $localeOperation Determines the operation on other locales (all-keep, all-clear, current-only).
     * @param string|null $locale The language locale.
     */
    public static function bootProsetta(array|string $keys, string|Closure $path, array|string $affixAttribute = null, ?string $localeOperation = null, ?string $locale = null): void {

        # Load Config
        $localeOperation = $localeOperation ?? config('prosetta.defaultBehavior', 'all-keep');

        if (!is_array($keys) && !is_string($keys)) {
            Log::channel(config('prosetta.logChannel', 'default'))->error('Invalid language keys type.', [
                'model' => static::class,
                'keys' => $keys
            ]);
            throw new InvalidArgumentException('Language keys must be a string or an array.');
        }

        if (!($path instanceof Closure) && !is_string($path)) {
            Log::channel(config('prosetta.logChannel', 'default'))->error('Invalid language path type.', [
                'model' => static::class,
                'path' => $path
            ]);
            throw new InvalidArgumentException('Language path must be a string or a Closure.');
        }

        if ($affixAttribute !== null && !is_array($affixAttribute) && !is_string($affixAttribute)) {
            Log::channel(config('prosetta.logChannel', 'default'))->error('Invalid affix attribute type.', [
                'model' => static::class,
                'affixAttribute' => $affixAttribute
            ]);
            throw new InvalidArgumentException('Affix attribute must be a string, an array, or null.');
        }

        # Set Config
        static::$languageKeys = $keys;
        static::$languagePath = $path;
        static::$languageAffixAttribute = $affixAttribute;
        static::$localeOperation = $localeOperation;
        static::$languageLocale = $locale ?? app()->getLocale();

        # Pass to LangKeyService
        LangKeyService::setConfig([
            'affixAttribute' => static::$languageAffixAttribute,
        ]);

        # Initiate
        static::initProsetta();
    }


    /**
     * Evaluates the language file path for a given model.
     *
     * This method determines the path to the language file based on the model instance.
     * If `static::$languagePath` is a Closure, it calls this Closure with the model as an argument;
     * otherwise, it uses `static::$languagePath` as a static path.
     *
     * @param Model $model The model instance for which the language path is being evaluated.
     * @return string The evaluated language file path. */
    private static function evaluatePath(Model $model): string {
        return static::$languagePath instanceof Closure ? call_user_func(static::$languagePath, $model) : static::$languagePath;
    }


    /**
     * Handles language entry operations based on the model event.
     *
     * @param Model $model The model instance being processed.
     * @param string $method The operation to be performed ('create', 'update', 'remove').
     * @throws Exception If an error occurs during the language entry handling. */
    protected static function handleLanguageEntry(Model $model, string $method): void {

        # Build Affix and Path
        $affix = static::buildAffix($model);
        $evaluatedPath = static::evaluatePath($model);

        # Build Old Affix
        if ($method === 'update') {
            $oldModel = clone $model;
            foreach ($model->getDirty() as $key => $value) {
                $oldModel->$key = $model->getOriginal($key);
            }
            $oldAffix = static::buildAffix($oldModel);
        }

        # Process Each Key
        foreach (static::$languageKeys as $key) {
            try {
                if (is_null($model->$key)) {
                    # Key is Null, Remove Potential Keys
                    LangKeyService::removeKeys($evaluatedPath, static::$languageLocale, [$key], $affix);
                } else {
                    # Proceed as Normal
                    if ($method === 'update') {
                        static::processLanguageKey($model, $method, $evaluatedPath, $key, $affix, $oldAffix);
                    } else
                        static::processLanguageKey($model, $method, $evaluatedPath, $key, $affix);
                }
            } catch (Exception $e) {
                # Log the exception
                Log::channel(config('prosetta.logChannel', 'default'))->error("Exception in handleLanguageEntry method.", [
                    'error' => $e->getMessage(),
                    'model' => get_class($model),
                    'method' => $method,
                    'modelData' => $model->toArray()
                ]);
            }
        }
    }


    /**
     * Processes a language key based on a specified method (create, update, remove).
     *
     * This method determines the operation to perform on a language key, such as creating, updating,
     * or removing entries in a language file, depending on the specified method. It delegates the
     * actual operations to the LangKeyService. In case of an unexpected method, it logs an error.
     *
     * @param Model $model The model instance being processed. This model provides the data for language entries.
     * @param string $method The operation to be performed on the language key. Expected values are 'create', 'update', or 'remove'.
     * @param string $evaluatedPath The evaluated path to the language file. This path is used to locate the correct language file for operations.
     * @param string $key The language key to be processed. This key identifies the specific language entry in the language file.
     * @param mixed $affix The affix (prefix, suffix, or both) to be applied to the language key, if any. The affix can be a string, an array, or null.
     * @param mixed|null $oldAffix The affix (prefix, suffix, or both) that was previously used.
     *
     * @return void
     * @throws Exception */
    private static function processLanguageKey(Model $model, string $method, string $evaluatedPath, string $key, mixed $affix, mixed $oldAffix = null): void {
        switch ($method) {
            case 'create':
                LangKeyService::manageLanguageFileEntry($model, $evaluatedPath, static::$languageLocale, [$key], static::$localeOperation, $method, $affix);
                break;
            case 'update':
                LangKeyService::updateLangEntries($model, $evaluatedPath, static::$languageLocale, [$key], static::$localeOperation, $method, $affix, $oldAffix);
                break;
            case 'remove':
                LangKeyService::removeKeys($evaluatedPath, static::$languageLocale, [$key], $affix, null, static::$localeOperation);
                break;
            default:
                Log::channel(config('prosetta.logChannel', 'default'))->error("Unexpected method name in processLanguageKey.", [
                    'method' => $method,
                    'model' => get_class($model)
                ]);
                break;
        }
    }
}
