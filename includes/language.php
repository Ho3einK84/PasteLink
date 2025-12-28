<?php
declare(strict_types=1);

class Language {
    private static ?array $translations = null;
    private static string $currentLang = 'fa';
    
    public static function init(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        self::$currentLang = $_SESSION['lang'] ?? 
                           $_COOKIE['pastelink_lang'] ?? 
                           self::detectLanguage() ?? 
                           'fa';
        
        self::loadTranslations();
    }
    
    private static function detectLanguage(): ?string {
        $acceptLang = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
        $langs = ['en', 'fa'];
        
        foreach ($langs as $lang) {
            if (stripos($acceptLang, $lang) !== false) {
                return $lang;
            }
        }
        
        return null;
    }
    
    private static function loadTranslations(): void {
        $file = __DIR__ . "/../i18n/" . self::$currentLang . ".php";
        if (file_exists($file)) {
            self::$translations = require $file;
        } else {
            self::$translations = [];
        }
    }
    
    public static function get(string $key, array $params = []): string {
        if (self::$translations === null) {
            self::init();
        }
        
        $text = self::$translations[$key] ?? $key;
        
        if (!empty($params)) {
            foreach ($params as $placeholder => $value) {
                $text = str_replace('{' . $placeholder . '}', $value, $text);
            }
        }
        
        return $text;
    }
    
    public static function setLanguage(string $lang): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (in_array($lang, ['en', 'fa'])) {
            $_SESSION['lang'] = $lang;
            setcookie('pastelink_lang', $lang, [
                'expires' => time() + (365 * 24 * 60 * 60),
                'path' => '/',
                'secure' => SESSION_SECURE,
                'httponly' => true,
                'samesite' => 'Strict'
            ]);
            
            self::$currentLang = $lang;
            self::loadTranslations();
        }
    }
    
    public static function getCurrentLang(): string {
        return self::$currentLang;
    }
    
    public static function isRTL(): bool {
        return self::$currentLang === 'fa';
    }
    
    public static function getDirection(): string {
        return self::isRTL() ? 'rtl' : 'ltr';
    }
}