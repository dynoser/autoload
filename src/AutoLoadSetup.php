<?php
namespace dynoser\autoload;

class AutoLoadSetup
{
    public static $rootDir;
    public static $vendorDir;
    public static $classesDir;
    public static $extDir;
    
    public static $dynoObj = null;
    
    public static $composerAutoLoaderLoaded = false;

    public function __construct($rootDir, $vendorDir = null, $classesDir = null, $extDir = null) {
        self::$rootDir = $rootDir;
        $vendorDir  = self::$vendorDir  = $vendorDir  ? $vendorDir  : $rootDir . '/vendor';
        $classesDir = self::$classesDir = $classesDir ? $classesDir : $rootDir . '/includes/classes';
        $extDir     = self::$extDir     = $extDir     ? $extDir     : $rootDir . '/ext';

        if (!\defined('DYNO_FILE')) {
            \define('DYNO_FILE', $rootDir . '/storage/int/dynoload.php');
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
            ];
        }

        \spl_autoload_register(['\\dynoser\\autoload\\AutoLoader','autoLoadSpl'], true, true);

        if (DYNO_FILE && \class_exists('dynoser\\autoload\\DynoImporter')) {
            self::$dynoObj = new DynoImporter($vendorDir);
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
            return self::$dynoObj->updateFromComposer(self::$vendorDir);
        }
    }
}