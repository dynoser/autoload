<?php
namespace dynoser\autoload;

class AutoLoadSetup
{
    public static $rootDir = null;
    public static $vendorDir;
    public static $classesDir;
    public static $extDir;
    public static $storageDir = null;
    
    public static $dynoArr = [];
    public static $dynoObj = null;
    
    public const BASE_DIRS_KEY = 'base-dirs';
    
    public static $composerAutoLoaderLoaded = false;

    public function __construct($rootDirSet = null, $vendorDir = null, $classesDir = null, $extDir = null, $storageDirSet = null) {
        $myOwnDir   = \strtr(__DIR__, '\\', '/');

        // set rootDir
        if ($rootDirSet) {
            self::$rootDir = \strtr($rootDirSet, '\\', '/');
        } elseif (!self::$rootDir) {
            throw new \Exception('Undefined rootDir');
        }
        $rootDir = self::$rootDir;

        // set storageDir and DYNO_FILE
        if ($storageDirSet) {
            self::$storageDir = \rtrim(\strtr($storageDirSet, '\\', '/'), '/ *');
        } elseif (!self::$storageDir) {
            self::$storageDir = \defined('DYNO_FILE') ? \strtr(\dirname(DYNO_FILE, 2), '\\', '/') : ($rootDir . '/storage');
        }
        
        // constant DYNO_FILE must be defined
        if (!\defined('DYNO_FILE')) {
              \define('DYNO_FILE', self::$storageDir . '/namespaces/dynofile.php');
        }
 
        if (\class_exists('dynoser\\autoload\\AutoLoader', false)) {
            \spl_autoload_unregister(['\\dynoser\\autoload\\AutoLoader','autoLoadSpl']);
        } else {
            require_once $myOwnDir . '/AutoLoader.php';

            if (DYNO_FILE && \is_file(DYNO_FILE)) {
                self::$dynoArr = (require DYNO_FILE);
            }
            if (!\is_array(self::$dynoArr)) {
                self::$dynoArr = [];
            }

            $vendorDir  = $vendorDir  ? $vendorDir  : $rootDir . '/vendor';
            $classesDir = $classesDir ? $classesDir : $rootDir . '/includes/classes';
            $extDir     = $extDir     ? $extDir     : $rootDir . '/ext';

            if (empty(self::$dynoArr[self::BASE_DIRS_KEY]['@'])) {
                AutoLoader::$classesBaseDirArr = [
                    // 1-char prefixes to specify the left part of the path
                    '*' => '',          // prefix '*' to specify an absolute path of class
                    '?' => '',          // prefix '?' for aliases
                    '&' => $rootDir,    // prefix '&' for rootDir
                    '~' => $classesDir, // prefix '~' for classes in includes/classes
                    '@' => $vendorDir,  // prefix '@' for classes in vendor (Composer)
                    '$' => $extDir,     // prefix '$' for classes in ext (modules)
                ];
            } else {
                AutoLoader::$classesBaseDirArr = $baseDirsArr = self::$dynoArr[self::BASE_DIRS_KEY];

                $vendorDir = $baseDirsArr['@'] ?? $vendorDir;
                $classesDir = $baseDirsArr['~'] ?? $classesDir;
                $extDir = $baseDirsArr['$'] ?? $extDir;
            }

            self::$vendorDir  = $vendorDir;
            self::$classesDir = $classesDir;
            self::$extDir     = $extDir;

            if (\defined('DYNO_AUTO_INSTALL')) {
                AutoLoader::$autoInstall = \constant('DYNO_AUTO_INSTALL') ? true : false;
            } elseif (\array_key_exists('auto-install', self::$dynoArr)) {
                AutoLoader::$autoInstall = self::$dynoArr['auto-install'] ? true : false;
            }
        }

        \spl_autoload_register(['\\dynoser\\autoload\\AutoLoader','autoLoadSpl'], true, true);
        
        if (DYNO_FILE) {

            // quick-load DynoLoader class without autoloader (if possible)
            if (!\class_exists('dynoser\\autoload\\DynoLoader', false)) {
                $chkFile = $myOwnDir . '/DynoLoader.php';
                if (\is_file($chkFile)) {
                    include_once $chkFile;
                }
            }

            if (\class_exists('dynoser\\autoload\\DynoLoader')) {
                // check sodium (required for HashSig)
                if (!\function_exists('sodium_crypto_sign_verify_detached')) {
                    // try sodium polyfill
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