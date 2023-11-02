<?php
namespace dynoser\autoload;

class AutoLoadSetup
{
    public static $rootDir;
    public static $vendorDir;
    public static $classesDir;
    public static $extDir;
    public static $storageDir;
    
    public static $dynoObj = null;
    
    public static $composerAutoLoaderLoaded = false;

    public function __construct($rootDir, $vendorDir = null, $classesDir = null, $extDir = null, $storageDir = null) {
        self::$rootDir = $rootDir;
        $vendorDir  = self::$vendorDir  = $vendorDir  ? $vendorDir  : $rootDir . '/vendor';
        $classesDir = self::$classesDir = $classesDir ? $classesDir : $rootDir . '/includes/classes';
        $extDir     = self::$extDir     = $extDir     ? $extDir     : $rootDir . '/ext';
        $storageDir = self::$storageDir = $storageDir ? $storageDir : $rootDir . '/storage';

        if (!\defined('DYNO_FILE')) {
            \define('DYNO_FILE', $storageDir . '/namespaces/dynoload.php');
        }

        if (\class_exists('dynoser\\autoload\\AutoLoader', false)) {
            \spl_autoload_unregister(['\\dynoser\\autoload\\AutoLoader','autoLoadSpl']);
        } else {
            require_once __DIR__ . '/AutoLoader.php';

            AutoLoader::$classesBaseDirArr = [
                // 1-char prefixes to specify the left part of the path
                '*' => '',          // prefix '*' to specify an absolute path of class
                '?' => '',          // prefix '?' for aliases
                '&' => $rootDir,    // prefix '&' for rootDir
                '~' => $classesDir, // prefix '~' for classes in includes/classes
                '@' => $vendorDir,  // prefix '@' for classes in vendor (Composer)
                '$' => $extDir,     // prefix '$' for classes in ext (modules)
            ];
        }

        \spl_autoload_register(['\\dynoser\\autoload\\AutoLoader','autoLoadSpl'], true, true);

        // quick-load class without autoloader (if possible)
        if (DYNO_FILE && !\class_exists('dynoser\\autoload\\DynoLoader', false)) {
            $chkFile = __DIR__. '/DynoLoader.php';
            if (\is_file($chkFile)) {
                include_once $chkFile;
            }
        }

        if (DYNO_FILE && \class_exists('dynoser\\autoload\\DynoLoader')) {
            // check sodium polyfill
            if (!\function_exists('sodium_crypto_sign_verify_detached')) {
                $chkFile = $vendorDir . '/paragonie/sodium_compat/autoload.php';
                if (\is_file($chkFile)) {
                    require_once $chkFile;
                }
            }
            self::$dynoObj = new DynoLoader($vendorDir);
            if (\defined('DYNO_WRITELOG') && \class_exists('\\dynoser\\writelog\\WriteLog')) {
                self::$dynoObj->writeLogObj = new \dynoser\writelog\WriteLog(self::$dynoObj->dynoDir, \constant('DYNO_WRITELOG'));
            }
            if (\defined('GIT_AUTO_BRANCH') && \class_exists('CzProject\\GitPhp\\Git') && \class_exists('dynoser\\autoload\\GitAutoCommiter')) {
                AutoLoader::$commiterObj = new GitAutoCommiter($rootDir);
            }
        }
    }
    
    public static function loadComposerAutoLoader($alwaysLoad = false) {
        if (!self::$composerAutoLoaderLoaded || $alwaysLoad) {
            $composerAutoLoaderFile = self::$vendorDir . '/autoload.php';
            self::$composerAutoLoaderLoaded = \is_file($composerAutoLoaderFile);
            if (self::$composerAutoLoaderLoaded) {
                require $composerAutoLoaderFile;
                // set our autoloader as first
                \spl_autoload_unregister(['\\dynoser\\autoload\\AutoLoader','autoLoadSpl']);
                \spl_autoload_register(['\\dynoser\\autoload\\AutoLoader','autoLoadSpl'], true, true);
            }
        }
        return self::$composerAutoLoaderLoaded;
    }

    public static function updateFromComposer() {
        if (self::$dynoObj) {
            try {
                return self::$dynoObj->updateFromComposer(self::$vendorDir);
            } catch (\Throwable $e) {
                return false;
            }
        }
    }
}