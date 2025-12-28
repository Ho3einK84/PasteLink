<?php
declare(strict_types=1);

class Cache {
    private static array $cache = [];
    private static array $timestamps = [];
    private static int $defaultTtl = 300; // 5 minutes
    
    public static function enabled(): bool {
        return CACHE_ENABLED;
    }
    
    public static function set(string $key, mixed $value, int $ttl = null): void {
        if (!self::enabled()) {
            return;
        }
        
        $ttl = $ttl ?? self::$defaultTtl;
        self::$cache[$key] = $value;
        self::$timestamps[$key] = time() + $ttl;
    }
    
    public static function get(string $key, mixed $default = null): mixed {
        if (!self::enabled()) {
            return $default;
        }
        
        if (!isset(self::$cache[$key])) {
            return $default;
        }
        
        if (isset(self::$timestamps[$key]) && time() > self::$timestamps[$key]) {
            self::delete($key);
            return $default;
        }
        
        return self::$cache[$key];
    }
    
    public static function has(string $key): bool {
        if (!self::enabled()) {
            return false;
        }
        
        return self::get($key) !== null;
    }
    
    public static function delete(string $key): void {
        if (!self::enabled()) {
            return;
        }
        
        unset(self::$cache[$key]);
        unset(self::$timestamps[$key]);
    }
    
    public static function clear(): void {
        if (!self::enabled()) {
            return;
        }
        
        self::$cache = [];
        self::$timestamps = [];
    }
    
    public static function cleanup(): void {
        if (!self::enabled()) {
            return;
        }
        
        $now = time();
        foreach (self::$timestamps as $key => $timestamp) {
            if ($now > $timestamp) {
                self::delete($key);
            }
        }
    }
    
    public static function remember(string $key, callable $callback, int $ttl = null): mixed {
        if (!self::enabled()) {
            return $callback();
        }
        
        $value = self::get($key);
        
        if ($value === null) {
            $value = $callback();
            self::set($key, $value, $ttl);
        }
        
        return $value;
    }
    
    public static function increment(string $key, int $step = 1): int {
        if (!self::enabled()) {
            return 0;
        }
        
        $current = self::get($key, 0);
        $new = $current + $step;
        self::set($key, $new);
        
        return $new;
    }
    
    public static function decrement(string $key, int $step = 1): int {
        return self::increment($key, -$step);
    }
    
    public static function getStats(): array {
        return [
            'enabled' => self::enabled(),
            'items' => count(self::$cache),
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true)
        ];
    }
}