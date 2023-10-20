<?php
(function($file) {
    if (\defined('ROOT_DIR')) {
        $rootDir = ROOT_DIR;
    } else {
        $rootDir = \strtr($file ? $file : \getcwd(), '\\', '/');
        $i = \strpos($rootDir, '/vendor/');
        if (!$i) {
            $rootDir = \strtr(__FILE__, '\\', '/');
            $i = \strpos($rootDir, '/vendor/');
            if (!$i) {
                throw new \Exception("Can't auto-detect rootDir");
            }
        }
        $rootDir = \substr($rootDir, 0, $i);
    }
    $rootDir    = \rtrim(strtr($rootDir, '\\', '/'), '/');
    $vendorDir  = \defined('VENDOR_DIR') ? \constant('VENDOR_DIR')  : $rootDir . '/vendor';
    $classesDir = \defined('CLASSES_DIR')? \constant('CLASSES_DIR') : $rootDir . '/includes/classes';
    $extDir     = \defined('EXT_FS_DIR') ? \constant('EXT_FS_DIR')  : $rootDir . '/ext';
    if (\defined('ROOT_DIR') && \is_dir(ROOT_DIR) && !\is_dir($vendorDir)) {
        \mkdir($vendorDir, 0777, true);
    }
    if (!\class_exists('dynoser\\autoload\\AutoLoadSetup', false)) {
        require_once __DIR__ . "/src/AutoLoadSetup.php";
    }

    (new \dynoser\autoload\AutoLoadSetup($rootDir, $vendorDir, $classesDir, $extDir));
})($file ?? '');// $file is Composer value
