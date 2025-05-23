<?php
namespace Helix\Core\Config;

use Helix\Core\Contracts\ConfigInterface;
use Helix\Core\Exceptions\ConfigException;
use Psr\SimpleCache\CacheInterface;

/**
 * Configuration loader for environment variables and PHP config files.
 * 
 * Supports:
 * - Loading .env files with environment variable parsing
 * - Loading PHP configuration files (returning arrays)
 * - Environment-specific configuration overrides
 * - Nested configuration access using dot notation
 * - Automatic type conversion of values
 * - Configuration caching via PSR-16 cache
 * - Validation of required configuration keys
 * 
 * @package Helix\Core\Config
 */
final class ConfigLoader implements ConfigInterface
{
    /** @var array Loaded configuration values */
    private array $config = [];
    
    /** @var string Base path for relative file resolution */
    private string $basePath;
    
    /** @var CacheInterface|null Cache instance for configuration */
    private ?CacheInterface $cache = null;
    
    /** @var bool Whether to automatically convert string values to proper types */
    private bool $typedValues = true;

    /**
     * Constructor.
     * 
     * @param string|null $basePath Base directory path for relative file resolution.
     *                              Defaults to 3 levels up from src/Core/Config.
     */
    public function __construct(?string $basePath = null)
    {
        $this->basePath = $basePath ?? dirname(__DIR__, 3);
    }

    /**
     * Load configuration from files.
     * 
     * @param string $envFile Path to environment file (default: '.env')
     * @param array $configFiles Array of PHP config files to load
     * @param bool $useEnvironmentSuffix Whether to load environment-specific variants
     * @param bool $overwriteExisting Whether new values overwrite existing ones
     * @return self
     * @throws ConfigException If files cannot be loaded or parsed
     * 
     * @example
     * $config->load(
     *     envFile: '.env',
     *     configFiles: ['config/database.php'],
     *     useEnvironmentSuffix: true
     * );
     */
    public function load(
        string $envFile = '.env',
        array $configFiles = [],
        bool $useEnvironmentSuffix = false,
        bool $overwriteExisting = true
    ): self {
        $this->loadEnv($envFile, $overwriteExisting);

        if ($useEnvironmentSuffix) {
            $env = $this->get('APP_ENV', 'production');
            $this->loadEnvFileWithSuffix($envFile, $env, $overwriteExisting);
            $this->loadConfigFilesWithSuffix($configFiles, $env, $overwriteExisting);
        }

        foreach ($configFiles as $file) {
            $this->loadConfigFile($file, $overwriteExisting);
        }

        return $this;
    }

    /**
     * Enable or disable automatic type conversion of values.
     * 
     * @param bool $enabled Whether to enable type conversion
     * @return self
     */
    public function setTypedValues(bool $enabled): self
    {
        $this->typedValues = $enabled;
        return $this;
    }

    /**
     * Set cache instance for configuration caching.
     * 
     * @param CacheInterface $cache PSR-16 compatible cache instance
     * @return self
     */
    public function setCache(?CacheInterface $cache = null): self
    {
        $this->cache = $cache;
        return $this;
    }

    /**
     * Load environment variables from file.
     * 
     * @param string $filename Path to environment file
     * @param bool $overwrite Whether to overwrite existing values
     * @throws ConfigException If file cannot be read
     */
    public function loadEnv(string $filename, bool $overwrite = true): void
    {
        $filePath = $this->resolvePath($filename);
        
        if (!file_exists($filePath)) {
            throw new ConfigException("Environment file not found: {$filePath}");
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            $this->processEnvLine($line, $overwrite);
        }
    }

    /**
     * Load configuration from PHP file.
     * 
     * @param string $filename Path to PHP config file
     * @param bool $overwrite Whether to overwrite existing values
     * @throws ConfigException If file cannot be loaded or doesn't return array
     */
    public function loadConfigFile(string $filename, bool $overwrite = true): void
    {
        $filePath = $this->resolvePath($filename);
        $cacheKey = 'config_'.md5($filePath);

        if ($this->cache && $cached = $this->cache->get($cacheKey)) {
            $config = $cached;
        } else {
            if (!file_exists($filePath)) {
                throw new ConfigException("Config file not found: {$filePath}");
            }

            $config = require $filePath;

            if (!is_array($config)) {
                throw new ConfigException("Config file must return an array: {$filePath}");
            }

            if ($this->cache) {
                $this->cache->set($cacheKey, $config);
            }
        }

        $this->mergeConfig($config, $overwrite);
    }

    /**
     * Get configuration value with dot notation support.
     * 
     * @param string $key Configuration key (e.g. 'database.host')
     * @param mixed $default Default value if key not found
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $keys = explode('.', $key);
        $value = $this->config;

        foreach ($keys as $k) {
            if (!is_array($value) || !array_key_exists($k, $value)) {
                return $default;
            }
            $value = $value[$k];
        }

        return $value;
    }

    /**
     * Check if configuration key exists (supports dot notation).
     * 
     * @param string $key Configuration key to check
     * @return bool
     */
    public function has(string $key): bool
    {
        $keys = explode('.', $key);
        $value = $this->config;

        foreach ($keys as $k) {
            if (!is_array($value) || !array_key_exists($k, $value)) {
                return false;
            }
            $value = $value[$k];
        }

        return true;
    }

    /**
     * Validate that required configuration keys exist.
     * 
     * @param array $requiredKeys Array of keys to validate
     * @throws ConfigException If any required key is missing
     */
    public function validateRequiredKeys(array $requiredKeys): void
    {
        foreach ($requiredKeys as $key) {
            if (!$this->has($key)) {
                throw new ConfigException("Missing required configuration key: {$key}");
            }
        }
    }

    /**
     * Get all loaded configuration as array.
     * 
     * @return array
     */
    public function all(): array
    {
        return $this->config;
    }

    /**
     * Load environment-specific .env file variant.
     */
    private function loadEnvFileWithSuffix(string $envFile, string $env, bool $overwrite): void
    {
        $envFilePath = pathinfo($envFile);
        $envFilename = $envFilePath['dirname'] . DIRECTORY_SEPARATOR . 
                       $envFilePath['filename'] . '.' . $env . 
                       (isset($envFilePath['extension']) ? '.' . $envFilePath['extension'] : '');

        if (file_exists($this->resolvePath($envFilename))) {
            $this->loadEnv($envFilename, $overwrite);
        }
    }

    /**
     * Load environment-specific PHP config file variants.
     */
    private function loadConfigFilesWithSuffix(array $configFiles, string $env, bool $overwrite): void
    {
        foreach ($configFiles as $file) {
            $envConfig = preg_replace('/\.php$/', ".{$env}.php", $file);
            if (file_exists($this->resolvePath($envConfig))) {
                $this->loadConfigFile($envConfig, $overwrite);
            }
        }
    }

    /**
     * Process a single line from environment file.
     */
    private function processEnvLine(string $line, bool $overwrite): void
    {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            return;
        }

        $line = preg_replace('/^export\s+/', '', $line);

        if (strpos($line, '=') !== false) {
            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);

            if (preg_match('/^"(.*)"$/', $value, $matches)) {
                $value = $matches[1];
            } elseif (preg_match('/^\'(.*)\'$/', $value, $matches)) {
                $value = $matches[1];
            }

            $value = $this->typedValues ? $this->convertValue($value) : $value;

            if ($overwrite || !array_key_exists($name, $this->config)) {
                $this->config[$name] = $value;
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
                putenv("$name=$value");
            }
        }
    }

    /**
     * Convert string values to proper types.
     */
    private function convertValue(string $value): mixed
    {
        if ($value === '') {
            return null;
        }

        $lowerValue = strtolower($value);
        if (in_array($lowerValue, ['true', 'false'])) {
            return $lowerValue === 'true';
        }

        if (is_numeric($value)) {
            return str_contains($value, '.') ? (float)$value : (int)$value;
        }

        if (str_starts_with($value, '{') || str_starts_with($value, '[')) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return $value;
    }

    /**
     * Merge new configuration with existing values.
     */
    private function mergeConfig(array $config, bool $overwrite): void
    {
        if ($overwrite) {
            $this->config = array_merge($this->config, $config);
        } else {
            $this->config = array_merge($config, $this->config);
        }
    }

    /**
     * Resolve relative paths to absolute paths.
     */
    private function resolvePath(string $path): string
    {
        if (str_starts_with($path, '/') || preg_match('#^[a-zA-Z]:[\\\\/]#', $path)) {
            return $path;
        }

        return rtrim($this->basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
    }
}