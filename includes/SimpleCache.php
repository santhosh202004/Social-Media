<?php
/**
 * Simple File-based Caching Utility
 */
class SimpleCache {
    private static $cacheDir = __DIR__ . '/../cache';
    private static $ttl = 3600; // Default 1 hour

    public static function get($key) {
        // Cache is disabled per user request
        return null;
    }

    public static function set($key, $value, $ttl = null) {
        // Cache is disabled per user request
        return;
    }

    public static function clear() {
        if (is_dir(self::$cacheDir)) {
            $files = glob(self::$cacheDir . '/*.cache');
            foreach ($files as $file) {
                unlink($file);
            }
        }
    }
}
