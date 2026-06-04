<?php

declare(strict_types=1);

namespace Nexph\Runtime\Config;

/**
 * Runtime configuration doctor - diagnose and report config issues
 */
final class ConfigDoctor
{
    public static function check(?RuntimeConfig $config = null): array
    {
        $config = $config ?? RuntimeConfig::defaults();
        $validator = new RuntimeConfigValidator();
        
        $report = [
            'valid' => $validator->validate($config),
            'errors' => $validator->errors(),
            'warnings' => $validator->warnings(),
            'config' => $config->toArray(),
            'environment' => self::checkEnvironment($config),
        ];

        return $report;
    }

    public static function checkAndPrint(?RuntimeConfig $config = null): int
    {
        $report = self::check($config);
        
        echo "\n=== Nexph Runtime Config Doctor ===\n\n";
        
        // Config values
        echo "Configuration:\n";
        foreach ($report['config'] as $key => $value) {
            if (is_array($value)) {
                $value = json_encode($value);
            } elseif (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
            echo "  {$key}: {$value}\n";
        }
        echo "\n";
        
        // Environment checks
        echo "Environment:\n";
        foreach ($report['environment'] as $check) {
            $status = $check['status'] === 'ok' ? '✓' : '✗';
            echo "  {$status} {$check['message']}\n";
        }
        echo "\n";
        
        // Warnings
        if (!empty($report['warnings'])) {
            echo "Warnings:\n";
            foreach ($report['warnings'] as $warning) {
                echo "  ⚠ {$warning}\n";
            }
            echo "\n";
        }
        
        // Errors
        if (!empty($report['errors'])) {
            echo "Errors:\n";
            foreach ($report['errors'] as $error) {
                echo "  ✗ {$error}\n";
            }
            echo "\n";
        }
        
        if ($report['valid'] && empty($report['warnings'])) {
            echo "✓ Configuration is valid\n\n";
            return 0;
        } elseif ($report['valid']) {
            echo "⚠ Configuration is valid but has warnings\n\n";
            return 0;
        } else {
            echo "✗ Configuration has errors\n\n";
            return 1;
        }
    }

    private static function checkEnvironment(RuntimeConfig $config): array
    {
        $checks = [];
        
        // PHP version
        $checks[] = [
            'name' => 'php_version',
            'status' => version_compare(PHP_VERSION, '8.1.0', '>=') ? 'ok' : 'error',
            'message' => 'PHP ' . PHP_VERSION . (version_compare(PHP_VERSION, '8.1.0', '>=') ? ' (OK)' : ' (requires 8.1+)'),
        ];
        
        // PCNTL
        $checks[] = [
            'name' => 'ext_pcntl',
            'status' => extension_loaded('pcntl') ? 'ok' : 'error',
            'message' => 'ext-pcntl ' . (extension_loaded('pcntl') ? 'loaded' : 'not loaded'),
        ];
        
        // POSIX
        $checks[] = [
            'name' => 'ext_posix',
            'status' => extension_loaded('posix') ? 'ok' : 'error',
            'message' => 'ext-posix ' . (extension_loaded('posix') ? 'loaded' : 'not loaded'),
        ];
        
        // Fiber support
        $checks[] = [
            'name' => 'fiber_support',
            'status' => class_exists('Fiber') ? 'ok' : 'error',
            'message' => 'Fiber support ' . (class_exists('Fiber') ? 'available' : 'not available'),
        ];
        
        // APCu (if configured)
        if ($config->queueDriver() === 'apcu' || $config->apcuEnabled()) {
            $checks[] = [
                'name' => 'ext_apcu',
                'status' => extension_loaded('apcu') && ini_get('apc.enabled') ? 'ok' : 'error',
                'message' => 'ext-apcu ' . (extension_loaded('apcu') ? (ini_get('apc.enabled') ? 'loaded and enabled' : 'loaded but disabled') : 'not loaded'),
            ];
        }
        
        // Redis (if configured)
        if ($config->queueDriver() === 'redis') {
            $checks[] = [
                'name' => 'ext_redis',
                'status' => extension_loaded('redis') ? 'ok' : 'error',
                'message' => 'ext-redis ' . (extension_loaded('redis') ? 'loaded' : 'not loaded'),
            ];
        }
        
        return $checks;
    }
}
