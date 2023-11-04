<?php
namespace dynoser\autoload;

class DynoLoader
{
    public $dynoDir = '';
    public $dynoNSmapFile = '';
    public $dynoNSmapURLArr = [
        'https://raw.githubusercontent.com/dynoser/nsmap/main/nsmap.hashsig.zip|EkDohf20jN/9kXW/WL3ZXo245ggek9TiTWzzmBriMTU=',
        ];
    public $dynoArr = []; // [namespace] => sourcepath (see $classesArr in AutoLoader)
    public $dynoArrChanged = false; // if the dynoArr differs from what is saved in the file

    public const REMOTE_NSMAP_KEY = 'remote-nsmap';
    public string $vendorDir;
    
    public bool $forceDownloads = false;

    public function __construct(string $vendorDir) {
        if (! DYNO_FILE || !$vendorDir) {
            return;
        }
        $this->vendorDir = $vendorDir;

        $needUpdateNSMap = !\file_exists(DYNO_FILE);
        if (!$needUpdateNSMap) {
            $this->dynoArr = (require DYNO_FILE);
            if (\is_array($this->dynoArr)) {
                AutoLoader::$classesArr = \array_merge(AutoLoader::$classesArr, $this->dynoArr);
            } else {
                $needUpdateNSMap = true;
            }
        }

        if (!$needUpdateNSMap && \defined('DYNO_NSMAP_TIMEOUT')) {
            $this->checkCreateDynoDir($vendorDir); //calc $this->dynoNSmapFile
            $needUpdateNSMap = !\is_file($this->dynoNSmapFile);
            if (!$needUpdateNSMap && (\time() - \filemtime($this->dynoNSmapFile) > \DYNO_NSMAP_TIMEOUT)) {
                $needUpdateNSMap = true;
                \touch($this->dynoNSmapFile, \time() - \DYNO_NSMAP_TIMEOUT + 30);
            }
        }
        
        // setup remote-autoload
        if (!\class_exists('dynoser\hashsig\HashSigBase', false)) {
            $chkFile = __DIR__ . '/HashSigBase.php';
            if (\is_file($chkFile)) {
                include_once $chkFile;
            }
        }
        if (\class_exists('dynoser\hashsig\HashSigBase', false)) {
            // prepare nsmap for self-load (if need)
            if ($needUpdateNSMap && !isset($this->dynoArr['dynoser/walkdir'])) {
                $this->quickPrepareDynoArr($vendorDir);
            }
            
            AutoLoader::$optionalObj = $this;
        }

        // rebuild dyno-cached files if need
        if ($needUpdateNSMap) {
            $this->nsMapUp();
        }
    }
    
    public function nsMapUp() {
        $dynoImporterObj = $this->makeDynoImporterObj();
        if (!$dynoImporterObj) {
            throw new \Exception("Can't rebuild dyno-maps because not found DynoImporter class");
        }
        $dynoImporterObj->rebuildDynoCache();
        $this->dynoArr = $dynoImporterObj->dynoArr;
        AutoLoader::$classesArr = $this->dynoArr;
        return $dynoImporterObj;
    }
    
    public function makeDynoImporterObj() {
        if (!\class_exists('dynoser\\autoload\\DynoImporter', false)) {
            $chkFile = __DIR__ . '/DynoImporter.php';
            if (\is_file($chkFile)) {
                include_once $chkFile;
            }
        }
        if (!\class_exists('dynoser\\autoload\\DynoImporter')) {
            return null;
        }
        $this->checkCreateDynoDir($this->vendorDir);
        $dynoImporterObj = new DynoImporter('');
        foreach($this as $k => $v) {
            $dynoImporterObj->$k = $v;
        }
        return $dynoImporterObj;
    }
    
    public function quickPrepareDynoArr($vendorDir) {
        $this->checkCreateDynoDir($vendorDir);
        $dynoNSmapURLArr = $this->dynoNSmapURLArr;
        $this->dynoArr = AutoLoader::$classesArr;
        if (\is_file($this->dynoNSmapFile)) {
            $dlMapArr = (require $this->dynoNSmapFile);
            if (\is_array($dlMapArr)) {
                $this->dynoArr += $dlMapArr;
                if (isset($dlMapArr[self::REMOTE_NSMAP_KEY])) {
                    $dynoNSmapURLArr += $dlMapArr[self::REMOTE_NSMAP_KEY];
                }
            }
        }
        $dlMapArr = $this->downLoadNSMaps(\array_unique($dynoNSmapURLArr));
        if (!$dlMapArr['nsMapArr']) {
            throw new \Exception("Can't download self-code from nsmap URLs:" . \print_r($this->dynoNSmapURLArr, true));
        }
        $this->dynoArr += $dlMapArr['nsMapArr'];
    }

    public function downLoadNSMaps(array $remoteNSMapURLs): array {
        $nsMapArr = [];
        $specArrArr = []; // [spec-keys] => [array of strings]
        $errMapArr = [];
        foreach($remoteNSMapURLs as $nsMapURL) {
            try {
                $dlMapArr = $this->downLoadNSMapFromURL($nsMapURL);// nsMapArr specArrArr
                $nsMapArr += $dlMapArr['nsMapArr'];
                foreach($dlMapArr['specArrArr'] as $specKey => $specArr) {
                    if (\array_key_exists($specKey, $specArrArr)) {
                        $specArrArr[$specKey] += $specArr;
                    } else {
                        $specArrArr[$specKey] = $specArr;
                    }
                }
            } catch (\Throwable $e) {
                $errMapArr[$nsMapURL] = $e->getMessage();
            }
        }
        return \compact('nsMapArr', 'specArrArr', 'errMapArr');
    }

    public function downLoadNSMapFromURL(string $nsMapURL, bool $getTargetMaps  = false): array {
        if (!\class_exists('dynoser\hashsig\HashSigBase', false)) {
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
                    $addArr = self::parseNSMapHELMLStr($fileDataStr);
                    $nsMapArr += $addArr['nsMapArr'];
                    foreach($addArr['specArr'] as $specKey => $vStr) {
                        if (\array_key_exists($specKey, $specArrArr)) {
                            $specArrArr[$specKey][] = $vStr;
                        } else {
                            $specArrArr[$specKey] = [$vStr];
                        }
                    }
                } elseif ($getTargetMaps && (false !== \strpos($fileName, 'targetmap.helml'))) {
                    $targetMapsArr[$fileName] = $fileDataStr;
                }
            }
        }
        return \compact('nsMapArr', 'specArrArr', 'targetMapsArr');
    }

    public static function parseNSMapHELMLStr(string $DataStr): array {
        $nsMapArr = [];
        $specArr = [];
        $rows = \explode("\n", $DataStr);
        foreach($rows as $st) {
            $i = \strpos($st, ':');
            if ($i) {
                $namespace = \trim(\substr($st, 0, $i));
                if (\substr($namespace, 0, 1) === '#' || \substr($namespace, 0, 2) === '//') {
                    continue;
                }
                $value = \trim(\substr($st, $i + 1));
                if (\strpos($namespace, '-')) {
                    $specArr[$namespace] = $value;
                } else {
                    $nsMapArr[$namespace] = $value;
                }
            }
        }
        return compact('nsMapArr', 'specArr');
    }
    
    public function checkCreateDynoDir(string $vendorDir): string {
        if (!$this->dynoDir) {
            $chkDir = \dirname(DYNO_FILE);
            if (!\is_dir($chkDir)) {
                //if (\is_dir($vendorDir) && (\dirname($chkDir, 2) === \dirname($vendorDir)) && !\mkdir($chkDir, 0777, true)) {
                if (\is_dir($vendorDir) && !\mkdir($chkDir, 0777, true)) {
                    throw new \Exception("Can't create sub-dir to save DYNO_FILE: $chrDir \n vendorDir=$vendorDir");                    
                }
                if (!\is_dir($chkDir)) {
                    throw new \Exception("Not found folder to storage DYNO_FILE=" . DYNO_FILE . "\n vendorDir=$vendorDir \n dir=$chkDir");
                }
            }
            $this->dynoDir = $chkDir;
            $this->dynoNSmapFile = $chkDir . '/nsmap.php';
        }
        return $this->dynoDir;
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
    public function resolve(string $filePathString, string $classFullName, string $nameSpaceKey): string {
        // "fromURL [optional parameters] replaceNameSpace checkFiles"
        $unArr = self::pasreNsMapStr($filePathString);
        if (!$unArr) {
            return '';
        }
        $fullTargetPath = AutoLoader::getPathPrefix($unArr['targetUnpackDir']);
        if (!$fullTargetPath) {
            return '';
        }
        $fullTargetPath = \strtr($fullTargetPath, '\\', '/');

        $oneFileMode = (\substr($fullTargetPath, -4) === '.php');
        if ($oneFileMode) {
            $fullTargetPath = \dirname($fullTargetPath) . '/';
        }
        if (!$fullTargetPath || (\substr($fullTargetPath, -1) !== '/')) {
            throw new \Exception("Incorrect target namespace-folder: '$fullTargetPath', must specified folder with prefix-char");
        }
        $lk = \strlen($nameSpaceKey);
        $addPath = \substr($classFullName, $lk, \strlen($classFullName) - $lk);
        $addPath = $addPath ? \strtr(\substr($addPath, 1), '\\', '/') : \basename($classFullName);
        $pkgChkFile = $addPath . '.php';

        $replaceNameSpace = \strtr($unArr['replaceNameSpace'], '\\', '/');

        //$classFile = $fullTargetPath . $pkgChkFile;
        $checkFile = $fullTargetPath . $unArr['checkFilesStr'];

        if (!\is_file($checkFile) || $this->forceDownloads) {
            // File not found - try load
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
                $this->nsMapUp();
            }
            if (AutoLoader::$commiterObj) {
                AutoLoader::$commiterObj->addFiles($res['successArr'], $nameSpaceKey, $replaceNameSpace, $classFullName);
            }
        }
        return $replaceNameSpace;
    }
}