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

    public function __construct($rootDirSet = null) {
        $myOwnDir   = \strtr(__DIR__, '\\', '/');

        // set rootDir
        if ($rootDirSet) {
            self::$rootDir = \strtr($rootDirSet, '\\', '/');
        } elseif (!self::$rootDir) {
            throw new \Exception('Undefined rootDir');
        }
        $rootDir = self::$rootDir;

        // set storageDir and DYNO_FILE
        if (\defined('STORAGE_DIR')) {
            self::$storageDir = \rtrim(\strtr(\constant('STORAGE_DIR'), '\\', '/'), "/ *\n\r\t");
        } elseif (!self::$storageDir) {
            self::$storageDir = \defined('DYNO_FILE') ? \strtr(\dirname(DYNO_FILE, 2), '\\', '/') : ($rootDir . '/storage');
        }
        
        // constant DYNO_FILE must be defined
        if (!\defined('DYNO_FILE')) {
              \define('DYNO_FILE', self::$storageDir . '/namespaces/dynofile.php');
        }
        
        $classAutoLoader = '\\dynoser\\autoload\\AutoLoader';
        if (\class_exists($classAutoLoader, false)) {
            \spl_autoload_unregister([$classAutoLoader,'autoLoadSpl']);
        } else {
            require_once $myOwnDir . '/AutoLoader.php';

            if (DYNO_FILE && \is_file(DYNO_FILE)) {
                self::$dynoArr = (require DYNO_FILE);
            }
            if (!\is_array(self::$dynoArr)) {
                self::$dynoArr = [];
            }

            if (empty(self::$dynoArr[self::BASE_DIRS_KEY]['@'])) {
                $vendorDir  = \defined('VENDOR_DIR') ? \constant('VENDOR_DIR')  : $rootDir . '/vendor';
                $classesDir = \defined('CLASSES_DIR')? \constant('CLASSES_DIR') : $rootDir . '/includes/classes';
                $extDir     = \defined('EXT_FS_DIR') ? \constant('EXT_FS_DIR')  : $rootDir . '/ext';

                AutoLoader::$classesBaseDirArr = [
                    // 1-char prefixes to specify the left part of the path
                    '&' => $rootDir,    // prefix '&' for rootDir
                    '+' => $classesDir, // prefix '+' for classes in includes/classes
                    '~' => $classesDir, // legacy '~' (deprecated, will removed)
                    '@' => $vendorDir,  // prefix '@' for classes in vendor (Composer)
                    '$' => $extDir,     // prefix '$' for classes in ext (modules)
                    '_' => self::$storageDir,
                ];
            } else {
                AutoLoader::$classesBaseDirArr = $baseDirsArr = self::$dynoArr[self::BASE_DIRS_KEY];

                $vendorDir =  \defined('VENDOR_DIR') ? \constant('VENDOR_DIR')  : ($baseDirsArr['@'] ?? $rootDir . '/vendor');
                $classesDir = \defined('CLASSES_DIR')? \constant('CLASSES_DIR') : ($baseDirsArr['+'] ?? $rootDir . '/includes/classes');
                $extDir =     \defined('EXT_FS_DIR') ? \constant('EXT_FS_DIR')  : ($baseDirsArr['$'] ?? $rootDir . '/ext');
            }
            // set empty special prefixes '*'
            foreach(['*','?',':'] as $k) {
                AutoLoader::$classesBaseDirArr[$k] = '';
            }

            self::$vendorDir  = $vendorDir;
            self::$classesDir = $classesDir;
            self::$extDir     = $extDir;
        }

        if (!empty(self::$dynoArr['no-remote'])) {
            AutoLoader::$enableRemoteInstall = false;
        }

        \spl_autoload_register([$classAutoLoader,'autoLoadSpl'], true, true);
        
        if (DYNO_FILE) {
            $classDynoLoader = '\\dynoser\\autoload\\DynoLoader';

            // quick-load DynoLoader class without autoloader (if possible)
            if (!\class_exists($classDynoLoader, false)) {
                $chkFile = $myOwnDir . '/DynoLoader.php';
                if (\is_file($chkFile)) {
                    include_once $chkFile;
                }
            }

            if (\class_exists($classDynoLoader)) {
                // check sodium (required for HashSig)
                if (!\function_exists('sodium_crypto_sign_verify_detached')) {
                    // try sodium polyfill
                    $chkFile = self::$vendorDir . '/paragonie/sodium_compat/autoload.php';
                    if (\is_file($chkFile)) {
                        require_once $chkFile;
                    }
                }
                self::$dynoObj = new $classDynoLoader(self::$vendorDir);
                if (\defined('DYNO_WRITELOG') && \class_exists('\\dynoser\\writelog\\WriteLog')) {
                    self::$dynoObj->writeLogObj = new \dynoser\writelog\WriteLog(self::$dynoObj->dynoDir, \constant('DYNO_WRITELOG'));
                }
                if (\defined('GIT_AUTO_BRANCH') && \class_exists('CzProject\\GitPhp\\Git') && \class_exists('dynoser\\autoload\\GitAutoCommiter')) {
                    AutoLoader::$commiterObj = new GitAutoCommiter($rootDir);
                }
            }
        }
    }
    
    public static function loadComposerAutoLoader($alwaysLoad = false, $setMeFirst = true) {
        if (!self::$composerAutoLoaderLoaded || $alwaysLoad) {
            if (\class_exists('\\Composer\\Autoload\\ClassLoader', false)) {
                self::$composerAutoLoaderLoaded = true;
            } else {
                $composerAutoLoaderFile = self::$vendorDir . '/autoload.php';
                self::$composerAutoLoaderLoaded = \is_file($composerAutoLoaderFile);
                if (self::$composerAutoLoaderLoaded) {
                    require $composerAutoLoaderFile;
                }
            }
        }
        if (self::$composerAutoLoaderLoaded && $setMeFirst) {
            // set our autoloader as first
            \spl_autoload_unregister(['\\dynoser\\autoload\\AutoLoader','autoLoadSpl']);
            \spl_autoload_register(  ['\\dynoser\\autoload\\AutoLoader','autoLoadSpl'], true, true);
        }
        return self::$composerAutoLoaderLoaded;
    }

    public static function updateFromComposer($alwaysUpdate = false) {
        $changed = false;
        if (self::$dynoObj) {
            try {
                self::$dynoObj = self::$dynoObj->makeDynoImporterObj();
                $changed = self::$dynoObj->updateFromComposer($alwaysUpdate);
            } catch (\Throwable $e) {
            }
        }
        return $changed;
    }
}