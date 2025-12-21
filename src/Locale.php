<?php

namespace LonelyLights\Prosetta;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * @property string $locale_initials
 * @property string $english_name
 * @property string $native_name
 * @property int $total_speakers
 * @property int $native_speakers
 * @property string $origin
 * @property string $script
 * @property bool $rtl
 */
class Locale extends Model {

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'locale_initials';

    /**
     * Indicates if the model's ID is auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * The "type" of the primary key.
     *
     * @var string
     */
    protected $keyType = 'string';

    ###########################################################
    # Model Functions
    ###########################################################

    # Get Active Locales - 60 Minute Cache
    public static function getActiveLocales(): array {
        return Cache::remember('activeLocales', 60, function() {
            try {
                return self::where('active', true)
                    ->select(['locale_initials', 'english_name', 'native_name'])
                    ->get()
                    ->keyBy('locale_initials')
                    ->toArray();
            } catch (Exception $e) {
                Log::channel(config('prosetta.logChannel', 'default'))->error('[Prosetta] Failed to fetch active locales. Defaulting to English.', ['error' => $e->getMessage()]);

                # Fallback to English
                return ['en' => ['english_name' => 'English', 'native_name' => 'English']];
            }
        });
    }


    /**
     * Language File Management
     *
     * Handles updating or deleting entries in a language file.
     * Supports both single and bulk operations by accepting keys and values as either
     * strings or arrays.
     *
     * @param string $type          Type of the operation ('update' or 'destroy').
     * @param string $filePath      Path to the language file relative to the 'lang' directory.
     * @param string $languageCode  Language code representing the target language file.
     * @param array|string $key     Key(s) to be managed.
     * @param array|string $value   Value(s) to be set (optional for 'update').
     * @return void
     * @throws Exception
     *
     * @deprecated Use LangKeyService methods instead. This will be removed in v0.4.
     */

    public static function manageLanguageFileEntry(
        string       $type,
        string       $filePath,
        string       $languageCode,
        array|string $key,
        array|string $value = ''
    ): void {

        # Security Check
        if (!app()->runningInConsole()) {
            $user = auth()->user();
            if (!$user || !$user->hasPermissionTo('access-prosetta')) {
                abort(403, 'Access denied - You do not have permission to access Prosetta');
            }
        }

        # Set the Full and Local Paths
        $localPath = "lang/$languageCode/$filePath.php";
        $fullPath = base_path($localPath);

        # Ensure File and Directory Exist and Create if Necessary
        try {
            if (!file_exists($fullPath)) {
                $directoryPath = dirname($fullPath);
                if (!is_dir($directoryPath)) {
                    mkdir($directoryPath, 0755, true);
                    Log::channel('prosetta')->info('Directory created.', ['directory' => $directoryPath]);
                }
                file_put_contents($fullPath, "<?php\n\nreturn [];");
                Log::channel('prosetta')->info('Language file created.', ['file' => $fullPath]);
            }
        } catch (Exception $e) {
            Log::channel('prosetta')->error('Error managing language file.', [
                'error' => $e->getMessage(),
                'path' => $localPath
            ]);
            throw $e;
        }

        # Load the Existing Language Data
        $langData = include($fullPath);

        # Process Key-Value Pairs
        if (is_array($key) && is_array($value)) {
            foreach ($key as $index => $k) {
                $v = $value[$index] ?? ''; // Default value if not set
                self::processKeyValue($type, $k, $v, $langData, $languageCode, $filePath);
            }
        } else {
            self::processKeyValue($type, $key, $value, $langData, $languageCode, $filePath);
        }

        # Save the Language Data to File
        $output = "<?php\n\nreturn " . var_export($langData, true) . ";\n";
        file_put_contents($fullPath, $output);
    }

    /**
     * Process a single key-value pair.
     *
     *  This function is used internally by manageLanguageFileEntry to handle individual
     *  key-value pairs. It performs the actual update or deletion of the entry based on the
     *  provided type.
     *
     * @param string $type          Operation type ('update' or 'destroy').
     * @param string $key           Key of the entry.
     * @param string $value         Value for the entry (only used for 'update').
     * @param array &$langData      Reference to the language data array.
     * @param string $languageCode  Language code.
     * @param string $filePath      Path to the language file.
     *
     * @return void
     * @throws InvalidArgumentException
     */

    public static function processKeyValue(string $type, string $key, string $value, array &$langData, string $languageCode, string $filePath): void {

        # Validate Key Format
        if (!preg_match('/^[a-zA-Z0-9-._]+$/', $key)) {
            throw new InvalidArgumentException("Invalid key format: $key");
        }

        # Sanitize the Value
        $value = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

        # Perform the Operation
        switch ($type) {
            case 'update':
                if (!array_key_exists($key, $langData)) {
                    $operationPerformed = 'create';
                } else {
                    $operationPerformed = $type;
                }
                $langData[$key] = $value;
                ksort($langData);
                break;
            case 'destroy':
                unset($langData[$key]);
                $operationPerformed = $type;
                break;
            default:
                throw new InvalidArgumentException("Invalid type: $type");
        }

        # Input Log
        Log::channel('prosetta')->info('Language file operation performed.', [
            'operation' => $operationPerformed,
            'language_code' => $languageCode,
            'key' => $key,
            'value' => $value,
            'file_path' => $filePath
        ]);
    }
}
