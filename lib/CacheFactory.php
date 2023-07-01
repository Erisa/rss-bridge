<?php

declare(strict_types=1);

class CacheFactory
{
    public function create(string $name = null): CacheInterface
    {
        $name ??= Configuration::getConfig('cache', 'type');
        if (!$name) {
            throw new \Exception('No cache type configured');
        }
        $cacheNames = [];
        foreach (scandir(PATH_LIB_CACHES) as $file) {
            if (preg_match('/^([^.]+)Cache\.php$/U', $file, $m)) {
                $cacheNames[] = $m[1];
            }
        }
        // Trim trailing '.php' if exists
        if (preg_match('/(.+)(?:\.php)/', $name, $matches)) {
            $name = $matches[1];
        }
        // Trim trailing 'Cache' if exists
        if (preg_match('/(.+)(?:Cache)$/i', $name, $matches)) {
            $name = $matches[1];
        }

        $index = array_search(strtolower($name), array_map('strtolower', $cacheNames));
        if ($index === false) {
            throw new \InvalidArgumentException(sprintf('Invalid cache name: "%s"', $name));
        }
        $className = $cacheNames[$index] . 'Cache';
        if (!preg_match('/^[A-Z][a-zA-Z0-9-]*$/', $className)) {
            throw new \InvalidArgumentException(sprintf('Invalid cache classname: "%s"', $className));
        }

        switch ($className) {
            case NullCache::class:
                return new NullCache();
            case FileCache::class:
                $fileCacheConfig = [
                    // Intentionally checking for truthy value because the historic default value is the empty string
                    'path' => Configuration::getConfig('FileCache', 'path') ?: PATH_CACHE,
                    'enable_purge' => Configuration::getConfig('FileCache', 'enable_purge'),
                ];
                if (!is_dir($fileCacheConfig['path'])) {
                    throw new \Exception(sprintf('The FileCache path does not exists: %s', $fileCacheConfig['path']));
                }
                if (!is_writable($fileCacheConfig['path'])) {
                    throw new \Exception(sprintf('The FileCache path is not writable: %s', $fileCacheConfig['path']));
                }
                return new FileCache($fileCacheConfig);
            case SQLiteCache::class:
                return new SQLiteCache();
            case MemcachedCache::class:
                return new MemcachedCache();
            default:
                if (!file_exists(PATH_LIB_CACHES . $className . '.php')) {
                    throw new \Exception('Unable to find the cache file');
                }
                return new $className();
        }
    }
}
