<?php
namespace dynoser\autoload;

use dynoser\autoload\AutoLoader;
use dynoser\autoload\AutoLoadSetup;
use dynoser\HELML\HELML;

class DynoLoader
{
    public static $useHELMLforNSmap = true;
    public static $vendorDir = '';
    public static $dynoDir = ''; // AutoLoadSetup::$storageDir /namespaces
    public static $dynoNSmapFile = ''; // dynoDir /nsmap
    public static $dynoNSmapURLArr = [
        'https://raw.githubusercontent.com/dynoser/nsmap/main/nsmap.hashsig.zip|EkDohf20jN/9kXW/WL3ZXo245ggek9TiTWzzmBriMTU=',
        ];

    public const REMOTE_NSMAP_KEY = 'remote-nsmap';
    public const REMOTE_NSMAP_USED = 'remote-nsmap-loaded';

    public static bool $forceDownloads = false;

    public function __construct(string $vendorDir) {
        if (! DYNO_FILE || !$vendorDir) {
            return;
        }
        self::$vendorDir = $vendorDir;

        $needUpdateDynoFile = empty(AutoLoadSetup::$dynoArr);

        if (!$needUpdateDynoFile) {
            if (\is_array(AutoLoadSetup::$dynoArr)) {
                AutoLoader::$classesArr = \array_merge(AutoLoader::$classesArr, AutoLoadSetup::$dynoArr);
            } else {
                $needUpdateDynoFile = true;
            }
        }

        // check and load HELML
        $classHELML = 'dynoser\\HELML\\HELML';
        if (!\class_exists($classHELML, false)) {
            $chkFile = __DIR__ .'/HELML.php';
            if (\is_file($chkFile)) {
                include_once $chkFile;
            } else {
                $chkFile = self::$vendorDir . '/dynoser/helml/src/HELML.php';
                if (\is_file($chkFile)) {
                    include_once $chkFile;
                }
            }
        }

        if (\class_exists($classHELML)) {
            $aliasHELML = 'dynoser\\tools\\HELML';
            if (!\class_exists($aliasHELML, false)) {
                \class_alias($classHELML, $aliasHELML, false);
            }
 
            // check and load HashSigBase
            $classHashSigBase = 'dynoser\\hashsig\\HashSigBase';
            if (!\class_exists($classHashSigBase, false)) {
                $chkFile = __DIR__ . '/HashSigBase.php';
                if (\is_file($chkFile)) {
                    include_once $chkFile;
                } else {
                    $chkFile = self::$vendorDir . '/dynoser/hashsig/HashSigBase.php';
                    if (\is_file($chkFile)) {
                        include_once $chkFile;
                    }
                }
            }
        }

        if (!\class_exists($classHashSigBase)) {
            AutoLoader::$enableRemoteInstall = false;
        }

        if (AutoLoader::$enableRemoteInstall) {

            if (!$needUpdateDynoFile && \defined('DYNO_NSMAP_TIMEOUT')) {
                self::checkCreateDynoDir($vendorDir);
                $needUpdateDynoFile = !\is_file(self::$dynoNSmapFile);
                if (!$needUpdateDynoFile && (\time() - \filemtime(self::$dynoNSmapFile) > \DYNO_NSMAP_TIMEOUT)) {
                    $needUpdateDynoFile = true;
                    \touch(self::$dynoNSmapFile, \time() - \DYNO_NSMAP_TIMEOUT + 30);
                }
            }

            if (\class_exists($classHashSigBase, false)) {

                AutoLoader::$optionalObj = $this;
            }
        }

        // rebuild dyno-cached files if need
        if ($needUpdateDynoFile) {
            $this->nsMapUp();
        }
    }
    
    public function nsMapUp() {
        $dynoImporterObj = $this->makeDynoImporterObj();
        if (!$dynoImporterObj) {
            throw new \Exception("Can't rebuild dyno-maps because not found DynoImporter class");
        }
        $dynoImporterObj->rebuildDynoCache();
        AutoLoader::$classesArr = AutoLoadSetup::$dynoArr;
        return $dynoImporterObj;
    }
    
    public function makeDynoImporterObj() {
        if (!\class_exists('dynoser\\autoload\\DynoImporter', false)) {
            $chkFile = __DIR__ . '/DynoImporter.php';
            if (\is_file($chkFile)) {
                include_once $chkFile;
            }
        }
        if (\class_exists('dynoser\\autoload\\DynoImporter')) {
            $this->checkCreateDynoDir(self::$vendorDir);
            $dynoImporterObj = new DynoImporter('');
//        Uncomment to use non-static object-properties
//        foreach($this as $k => $v) {
//            $dynoImporterObj->$k = $v;
//        }
        } else {
            $dynoImporterObj = null;
        }
        return $dynoImporterObj;
    }
    
    public function quickPrepareDynoArr($vendorDir) {
        $this->checkCreateDynoDir($vendorDir);
        $dynoNSmapURLArr = self::$dynoNSmapURLArr;
        $dynoArr = AutoLoader::$classesArr;
        if (\is_file(self::$dynoNSmapFile)) {
            $dlMapArr = (require self::$dynoNSmapFile);
            if (\is_array($dlMapArr)) {
                $dynoArr += $dlMapArr;
                if (isset($dlMapArr[self::REMOTE_NSMAP_KEY])) {
                    $dynoNSmapURLArr += $dlMapArr[self::REMOTE_NSMAP_KEY];
                }
            }
        }
        $dlMapArr = $this->downLoadNSMaps(\array_unique($dynoNSmapURLArr));
        if (!empty($dlMapArr['nsMapArr'])) {
            $dynoArr += $dlMapArr['nsMapArr'];
        }
        AutoLoadSetup::$dynoArr = $dynoArr;
    }

    public function downLoadNSMaps(array $remoteNSMapURLs): array {
        $nsMapArr = [];
        $specArrArr = [self::REMOTE_NSMAP_USED => []]; // [spec-keys] => [array of strings]
        foreach($remoteNSMapURLs as $nsMapURL) {
            try {
                $dlMapArr = $this->downLoadNSMapFromURL($nsMapURL);// nsMapArr specArrArr
                $nsMapArr   += $dlMapArr['nsMapArr'];
                $specArrArr += $dlMapArr['specArrArr'];
                $ref = 'Ns: ' . \count($dlMapArr['nsMapArr']) . ', Spec: ' . \count($dlMapArr['specArrArr']);
            } catch (\Throwable $e) {
                $err = $e->getMessage();
                $ref = 'ERROR: '. $err;
            }
            $specArrArr[self::REMOTE_NSMAP_USED][$nsMapURL] = $ref;
        }
        return \compact('nsMapArr', 'specArrArr');
    }

    public function downLoadNSMapFromURL(string $nsMapURL, bool $getTargetMaps  = false): array {
        if (!\class_exists('dynoser\\hashsig\\HashSigBase', false)) {
            throw new \Exception("No HashSigBase classs for remote-nsmap loading");
        }
        $hashSigBaseObj = new \dynoser\hashsig\HashSigBase();
        $res = $hashSigBaseObj->getFilesByHashSig(
            $nsMapURL,
            null,
            null,
            true   //$doNotSaveFiles
        );
        $nsMapArr = [];      // [namespace] => path (like AutoLoader::$classes)
        $specArrArr = [];    // [spec-keys] => [array of strings]
        $targetMapsArr = []; // [fileName] => fileDataStr
        if ($res['successArr']) {
            foreach($res['successArr'] as $fileName => $fileDataStr) {
                if (false !== \strrpos($fileName, 'nsmap.helml')) {
                    $addArr = $this->parseNSMapHELMLStr($fileDataStr);
                    if ($addArr) {
                        $nsMapArr += $addArr['nsMapArr'];
                        foreach($addArr['specArr'] as $specKey => $vStr) {
                            if (\array_key_exists($specKey, $specArrArr)) {
                                $specArrArr[$specKey] = \array_merge($specArrArr[$specKey],$vStr);
                            } else {
                                $specArrArr[$specKey] = $vStr;
                            }
                        }
                    }
                } elseif ($getTargetMaps && (false !== \strpos($fileName, 'targetmap.helml'))) {
                    $targetMapsArr[$fileName] = $fileDataStr;
                }
            }
        }
        return \compact('nsMapArr', 'specArrArr', 'targetMapsArr');
    }

    public static function parseNSMapHELMLStr(string $DataStr): ?array {
        $specArr = [];
        HELML::$ENABLE_DBL_KEY_ARR = true;
        $nsMapArr = HELML::decode($DataStr);
        if (!\is_array($nsMapArr)) {
            return null;
        }
        foreach($nsMapArr as $nameSpace => $v) {
            if (false !== \strpos($nameSpace, '-')) {
                if (!\is_array($v)) {
                    $v = [$v];
                }
                $specArr[$nameSpace] = $v;
                unset($nsMapArr[$nameSpace]);
            }
        }
        return \compact('nsMapArr', 'specArr');
    }
    
    public function checkCreateDynoDir(string $vendorDir = null): string {
        if (!self::$dynoDir) {
            if (!$vendorDir) {
                $vendorDir = self::$vendorDir;
                if (!$vendorDir) {
                    throw new \Exception("undefined VendorDir");
                }
            }
            $chkDir = \dirname(DYNO_FILE);
            if (!\is_dir($chkDir)) {
                if (\is_dir($vendorDir) && !\mkdir($chkDir, 0777, true)) {
                    throw new \Exception("Can't create sub-dir to save DYNO_FILE: $chrDir \n vendorDir=$vendorDir");                    
                }
                if (!\is_dir($chkDir)) {
                    throw new \Exception("Not found folder to storage DYNO_FILE=" . DYNO_FILE . "\n vendorDir=$vendorDir \n dir=$chkDir");
                }
            }
            self::$dynoDir = $chkDir;
            self::$dynoNSmapFile = $chkDir . '/dyno-nsmap' . (self::$useHELMLforNSmap ? '.helml' : '.php');
        }
        return self::$dynoDir;
    }
    
    public static function pasreNsMapStr(string $filePathString): ?array {
        $i = \strpos($filePathString, ' ');
        $j = \strrpos($filePathString, ' ');
        if (!($j > $i)) {
            return null;
        }
        $fromURL = \substr($filePathString, 1, $i - 1);
        if (!\filter_var($fromURL, \FILTER_VALIDATE_URL)) {
            return null;
        }
        $checkFilesStr = \substr($filePathString, $j + 1);

        $midStr = \trim(\substr($filePathString, $i, $j - $i));
        $midArr = \explode(' ', $midStr);

        $replaceNameSpace = \array_pop($midArr);
        if ($midArr) {
            $targetUnpackDir = \array_pop($midArr);
        } else {
            $targetUnpackDir = $replaceNameSpace;
        }
        if (\substr($targetUnpackDir, -1) === '*') {
            $targetUnpackDir = \substr($targetUnpackDir, 0, -1);
        }
        return \compact(
            'fromURL',
            'targetUnpackDir',
            'replaceNameSpace',
            'checkFilesStr',
            'midArr'
        );
    }

    public function unpackXPath($filePathString): ?array {
        $unArr = $this->pasreNsMapStr($filePathString);
        if (!$unArr) {
            return null;
        }
        $fullTargetPath = AutoLoader::getPathPrefix($unArr['targetUnpackDir']);
        if (!$fullTargetPath) {
            return null;
        }
        $fullTargetPath = \strtr($fullTargetPath, '\\', '/');
        $oneFileMode = (\substr($fullTargetPath, -4) === '.php');
        if ($oneFileMode) {
            $fullTargetPath = \dirname($fullTargetPath) . '/';
        }
        if (!$fullTargetPath || (\substr($fullTargetPath, -1) !== '/')) {
            return null;
        }
        $unArr['fullTargetPath'] = $fullTargetPath;
        $unArr['replaceNameSpace'] = \strtr($unArr['replaceNameSpace'], '\\', '/');
        $unArr['checkFile'] = $fullTargetPath . $unArr['checkFilesStr'];
        return $unArr;
    }

    public function resolve(string $filePathString, string $classFullName, string $nameSpaceKey): string {
        // "fromURL [optional parameters] replaceNameSpace checkFiles"
        $unArr = $this->unpackXPath($filePathString);
        if (!$unArr) {
            $replaceNameSpace = '';
        } else {
            $replaceNameSpace = $unArr['replaceNameSpace'];
            $fullTargetPath = $unArr['fullTargetPath'];
            $checkFile = $unArr['checkFile'];

//            $lk = \strlen($nameSpaceKey);
//            $addPath = \substr($classFullName, $lk, \strlen($classFullName) - $lk);
//            $addPath = $addPath ? \strtr(\substr($addPath, 1), '\\', '/') : \basename($classFullName);
//            $pkgChkFile = $addPath . '.php';

            if ((!\is_file($checkFile) || self::$forceDownloads) && AutoLoader::$enableRemoteInstall) {
                // File not found - try load and install
                if (!\is_dir($fullTargetPath) && !mkdir($fullTargetPath, 0777, true)) {
                    throw new \Exception("Can't create target path for download package: $fullTargetPath , foor class=$classFullName");
                }
                if (AutoLoader::$commiterObj) {
                    AutoLoader::$commiterObj->setRepository();
                }

                $fromURL = $unArr['fromURL'];
                $hashSigBaseObj = new \dynoser\hashsig\HashSigBase();
                $res = $hashSigBaseObj->getFilesByHashSig(
                    $fromURL,
                    $fullTargetPath,
                    null,  //array $baseURLs
                    false, //bool $doNotSaveFiles
                    false  //bool $doNotOverWrite
                );
                if (empty($res['successArr']) || !empty($res['errorsArr'])) {
                    throw new \Exception("Download problem for class $classFullName , package url=$fromURL");
                }
                if (!\in_array($checkFile, $res['successArr'])) {
                    throw new \Exception("Successful downloaded hashsig-package, but not found target class file: $classFullName");
                }
                if (isset($res['successArr']['nsmap.helml'])) {
                    // if local nsmap added, rescan nsmaps
                    $this->nsMapUp();
                }
                if (AutoLoader::$commiterObj) {
                    AutoLoader::$commiterObj->addFiles($res['successArr'], $nameSpaceKey, $replaceNameSpace, $classFullName);
                }
            }
        }
        return $replaceNameSpace;
    }
}