<?php
(function($file) {
    if (\defined('ROOT_DIR')) {
        $rootDir = ROOT_DIR;
    } else {
        $rootDir = $file ? \dirname($file) : \getcwd();
        if (!\is_dir($rootDir . '/vendor')) {
            $i = \strpos(\strtr($rootDir, '\\', '/'), '/vendor/');
            if (!$i) {
                $rootDir = \strtr(__FILE__, '\\', '/');
                $i = \strpos($rootDir, '/vendor/');
                if (!$i) {
                    throw new \Exception("Can't auto-detect rootDir");
                }
            }
            $rootDir = \substr($rootDir, 0, $i);
        }
    }
    $rootDir    = \rtrim(strtr($rootDir, '\\', '/'), '/');

    if (!\class_exists('dynoser\\autoload\\AutoLoadSetup', false)) {
        require_once __DIR__ . "/src/AutoLoadSetup.php";
    }

    (new \dynoser\autoload\AutoLoadSetup($rootDir));

})($file ?? '');// $file is Composer value
