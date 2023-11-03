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
    $storageDir = \defined('STORAGE_DIR')? \constant('STORAGE_DIR') : $rootDir . '/storage';

    if (!\class_exists('dynoser\\autoload\\AutoLoadSetup', false)) {
        require_once __DIR__ . "/src/AutoLoadSetup.php";
    }

    (new \dynoser\autoload\AutoLoadSetup($rootDir, $vendorDir, $classesDir, $extDir, $storageDir));
    
    // *** temporary updating code for debugging, will removed in next versions ***
    $updRequest = $GLOBALS['argv'][1] ?? $_REQUEST['dynoupdate'] ?? '';
    if ($updRequest && 'da8be698d805f74da997ac7ad381b5aaa76384c9e27f78ae5d5688be95e39d92' === \hash('sha256', $updRequest)) {
        $updClass = '\\dynoser\\nsmupdate\\UpdateByNSMaps';
        if (\class_exists($updClass)) {
            echo "<pre>Try update all...";
            $updObj = new $updClass(false, true);
            $updObj->removeCache();
            $changesArr = $updObj->lookForDifferences();
            echo ($changesArr) ? "Differences: " . \print_r($changesArr, true) : "No difference";
            echo "\n\nRun update ... ";
            $updatedResultsArr = $updObj->update();
            echo ($updatedResultsArr) ? "Update results: " . \print_r($updatedResultsArr, true) : "Empty update results";
            die("\nFinished\n");
        }
    }
    // *** end of temporary dbg code ***

})($file ?? '');// $file is Composer value
