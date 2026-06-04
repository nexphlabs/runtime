<?php

namespace Nexph\Runtime;

class OptimizeLoader
{
    private static bool $initialized = false;
    private static bool $opcacheEnabled = false;
    private static bool $apcuEnabled = false;
    private static string $cacheKey = 'nexph_classmap';

    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;
        self::$opcacheEnabled = function_exists('opcache_get_status') && opcache_get_status() !== false;
        self::$apcuEnabled = function_exists('apcu_enabled') && apcu_enabled();
    }

    public static function opcacheEnabled(): bool
    {
        self::init();
        return self::$opcacheEnabled;
    }

    public static function apcuEnabled(): bool
    {
        self::init();
        return self::$apcuEnabled;
    }

    public static function preloadClasses(array $classes): int
    {
        if (!self::opcacheEnabled()) {
            return 0;
        }
        $loaded = 0;
        foreach ($classes as $class) {
            if (class_exists($class, true)) {
                $loaded++;
            }
        }
        return $loaded;
    }

    public static function cacheClassmap(array $classmap, int $ttl = 3600): bool
    {
        if (!self::apcuEnabled()) {
            return false;
        }
        return apcu_store(self::$cacheKey, $classmap, $ttl);
    }

    public static function getCachedClassmap(): ?array
    {
        if (!self::apcuEnabled()) {
            return null;
        }
        $result = apcu_fetch(self::$cacheKey, $success);
        return $success ? $result : null;
    }

    public static function clearCache(): bool
    {
        if (!self::apcuEnabled()) {
            return false;
        }
        return apcu_delete(self::$cacheKey);
    }

    public static function warmup(array $classes): array
    {
        self::init();
        return [
            'opcache' => self::$opcacheEnabled,
            'apcu' => self::$apcuEnabled,
            'preloaded' => self::preloadClasses($classes),
        ];
    }
}
