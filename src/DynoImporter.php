<?php
namespace dynoser\autoload;

class DynoImporter {
    
    public $dynoDir = '';
    public $dynoNSmapFile = '';
    public $dynoNSmapURL = '';
    public $dynoArr = []; // [namespace] => sourcepath (see $classesArr in AutoLoader)
    public $dynoArrChanged = false; // if the dynoArr differs from what is saved in the file
    
    public function __construct(string $vendorDir) {
        if (DYNO_FILE) {
            if (\defined('DYNO_NSMAP_URL')) {
                $this->dynoNSmapURL = \constant('DYNO_NSMAP_URL');
            }
            if (!\class_exists('dynoser\hashsig\HashSigBase', false)) {
                $chkFile = __DIR__ . '/HashSigBase.php';
                if (\is_file($chkFile)) {
                    require_once $chkFile;
                }
            }

            if (\defined('DYNO_NSMAP_TIMEOUT')) {
                $this->checkCreateDynoDir($vendorDir);
                $goodNSmap = \is_file($this->dynoNSmapFile);
                if ($goodNSmap) {
                    if (\time() - \filemtime($this->dynoNSmapFile) > \DYNO_NSMAP_TIMEOUT) {
                        $goodNSmap = false;
                        \touch($this->dynoNSmapFile, \time() - 30);    
                    }
                }
                if (!$goodNSmap) {        
                    $this->tryUpdateNSMap($vendorDir, \DYNO_NSMAP_TIMEOUT);
                }
            }


            if (\file_exists(DYNO_FILE)) {
                $this->dynoArr = (require DYNO_FILE);
            }

            if ($this->dynoArr && \is_array($this->dynoArr)) {
                AutoLoader::$classesArr = \array_merge(AutoLoader::$classesArr, $this->dynoArr);
            } else {
                $this->checkCreateDynoDir($vendorDir);
                $this->dynoArr = AutoLoader::$classesArr;
                $this->importComposersPSR4($vendorDir);
                $this->applyNSMap($vendorDir);
                $this->saveDynoFile(DYNO_FILE);
            }

            if (\class_exists('dynoser\hashsig\HashSigBase', false)) {
                AutoLoader::$optionalObj = new class {
                    public function resolve(string $filePathString, string $classFullName, string $nameSpaceKey): string {
                        // "fromURL [optional parameters] replaceNameSpace checkFiles"
                        $i = \strpos($filePathString, ' ');
                        $j = \strrpos($filePathString, ' ');
                        if (!($j > $i)) {
                            return '';
                        }
                        $fromURL = \substr($filePathString, 1, $i - 1);
                        if (!\filter_var($fromURL, \FILTER_VALIDATE_URL)) {
                            return '';
                        }
                        $checkFilesStr = \substr($filePathString, $j + 1);

                        $midStr = \trim(\substr($filePathString, $i, $j - $i));
                        $midArr = \explode(' ', $midStr);

                        $replaceNameSpaceDir = \array_pop($midArr);
                        $lc2 = \substr($replaceNameSpaceDir, -2);
                        $lc = \substr($lc2, -1);
                        if ($lc === '*' || $lc === '@') {
                            $replaceNameSpaceDir = \substr($replaceNameSpaceDir, 0, -1);
                        }
                        
                        $fullTargetPath = AutoLoader::getPathPrefix($replaceNameSpaceDir);
                        $fullTargetPath = \strtr($fullTargetPath, '\\', '/');
                        if (!$fullTargetPath || \substr($fullTargetPath, -1) !== '/') {
                            throw new \Exception("Incorrect target namespace-folder: '$replaceNameSpaceDir', must specified folder with prefix-char");
                        }
                        $lk = \strlen($nameSpaceKey);
                        $addPath = \substr($classFullName, $lk, \strlen($classFullName) - $lk);
                        $addPath = $addPath ? \strtr(\substr($addPath, 1), '\\', '/') : \basename($classFullName);
                        $classFile = $fullTargetPath . $addPath . '.php';
                        $checkFile = $fullTargetPath . $checkFilesStr;
                        
                        if (!\is_file($classFile) || !\is_file($checkFile)) {
                            // File not found - try load
                            if (!\is_dir($fullTargetPath) && !mkdir($fullTargetPath, 0777, true)) {
                                throw new \Exception("Can't create target path for download package: $fullTargetPath , foor class=$classFullName");
                            }
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
                            if (!\in_array($classFile, $res['successArr'])) {
                                throw new \Exception("Successful downloaded hashsig-package, but not found target class file: $classFile");
                            }
                        }
                        return \strtr($replaceNameSpaceDir, '\\', '/') . '*';
                    }
                };
            }            
        }
    }
    
    public function tryUpdateNSMap($vendorDir, $nsmapTimeOut = 60) {
        try {
            $nsMapArr = $this->downLoadNSMap();
            if ($nsMapArr) {
                $this->saveNSMapFile($this->dynoNSmapFile, $nsMapArr);
                $this->dynoArr = AutoLoader::$classesArr;
                $this->updateFromComposer($vendorDir);
                $this->dynoArr = \array_merge($this->dynoArr, $nsMapArr);
                $this->saveDynoFile(DYNO_FILE);
            }
        } catch(\Throwable $e) {
            $newMTime = \time() - $nsmapTimeOut + 30;
            \touch($this->dynoNSmapFile, $newMTime);
        }
    }
    
    public function applyNSMap(string $vendorDir) {
        $mapFile = $this->dynoNSmapFile;
        if (\is_file($mapFile)) {
            $nsMapArr = (require $mapFile);
        }
        if (empty($nsMapArr) || !\is_array($nsMapArr)) {
            $nsMapArr = $this->downLoadNSMap();
            $this->saveNSMapFile($mapFile, $nsMapArr);
        }
        $this->dynoArr += $nsMapArr;
    }
    
    public function downLoadNSMap($nsMapURL = null) {
        if (!$nsMapURL) {
            $nsMapURL = $this->dynoNSmapURL;
        }
        if (!$nsMapURL || !\class_exists('dynoser\hashsig\HashSigBase', false)) {
            return [];
        }
        $hashSigBaseObj = new \dynoser\hashsig\HashSigBase();
        $res = $hashSigBaseObj->getFilesByHashSig(
            $nsMapURL,
            null,
            null,  //array $baseURLs
            true,  //bool $doNotSaveFiles
            false  //bool $doNotOverWrite
        );
        if (empty($res['successArr'])) {
            throw new \Exception("nsmap download problem from url=$nsMapURL, unsuccess results");
        }
        $nsMapArr = [];
        foreach($res['successArr'] as $fileName => $fileDataStr) {
            $i = \strrpos($fileName, '.nsmap');
            if (false !== $i) {
                $rows = \explode("\n", $fileDataStr);
                foreach($rows as $st) {
                    $i = \strpos($st, ':');
                    if ($i) {
                        $namespace = \trim(\substr($st, 0, $i));
                        $nsPath = \trim(\substr($st, $i + 1));
                        if (\substr($namespace, 0, 1) === '#' || \substr($namespace, 0, 2) === '//') {
                            continue;
                        }
                        $nsMapArr[$namespace] = $nsPath;
                    }
                }
            }
        }
        if (!$nsMapArr) {
            throw new \Exception("nsmap downloaded not contain namespace-definitions, url=$nsMapURL");
        }
        return $nsMapArr;
    }
    
    public function updateFromComposer(string $vendorDir) {
        if (DYNO_FILE) {
            // reload last version of dynoFile
            if (($this->dynoArrChanged || empty($this->dynoArr)) && \file_exists(DYNO_FILE)) {
                $this->dynoArr = (require DYNO_FILE);
                $this->dynoArrChanged = false;
            }
            $changed = $this->importComposersPSR4($vendorDir);
            $this->checkCreateDynoDir($vendorDir);
            $this->saveDynoFile(DYNO_FILE);
        }
        return $this->dynoArrChanged;
    }
    
    public function checkCreateDynoDir(string $vendorDir): string {
        if (!$this->dynoDir) {
            $chkDir = \dirname(DYNO_FILE);
            if (!\is_dir($chkDir)) {
                if (\is_dir($vendorDir) && (\dirname($chkDir, 2) === \dirname($vendorDir)) && !\mkdir($chkDir, 0777, true)) {
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
    
    public function saveNSMapFile(string $nsMapFile, array $nsMapArr) {
        $dynoStr = '<' . "?php\n" . 'return ';
        $dynoStr .= \var_export($nsMapArr, true) . ";\n";
        $wb = \file_put_contents($nsMapFile, $dynoStr);
        if (!$wb) {
            throw new \Exception("Can't write nsMap-file (downloaded namespaces)\nFile: " . $nsMapFile);
        }
    }
    public function saveDynoFile(string $dynoFile) {
        $dynoStr = '<' . "?php\n" . 'return ';
        $dynoStr .= \var_export($this->dynoArr, true) . ";\n";
        $wb = \file_put_contents($dynoFile, $dynoStr);
        if (!$wb) {
            throw new \Exception("Can't write dyno-file (psr4-namespaces imported from composer)\nFile: " . $dynoFile);
        }
        $this->dynoArrChanged = false;
    }

    public static function getFoldersArr(string $baseDir, $retFullPath = false): array {
        $foldersArr = [];
        $realBaseDir = \realpath($baseDir);
        if (!$realBaseDir) {
            return [];
        }
        $dirNamesArr = \glob(\strtr($realBaseDir, '\\', '/') . '/*', \GLOB_ONLYDIR | \GLOB_NOSORT);
        if (!\is_array($dirNamesArr)) {
            throw new \Exception("Can't read directory: " . $baseDir);
        }
        foreach($dirNamesArr as $dirName) {
            if ($retFullPath) {
                $dirName = \strtr($dirName, '\\', '/');
            } else {
                $i = \strrpos($dirName, '/');
                $j = \strrpos($dirName, '\\');
                if (!$i || $j > $i) {
                    $i = $j;
                }
                if (false !== $i) {
                    $dirName = \substr($dirName, $i+1);
                }
            }
            $foldersArr[] = $dirName;                
        }
        return $foldersArr;
    }
 
    public static function getSubSubDirFilesArr(string $baseDir, string $fileMask = '/composer.json'): ?array {
        $foundSubSubFilesArr = []; // [subDir/subSubDir] => FullFileName
        $subDirArr = self::getFoldersArr($baseDir, false);
        if ($subDirArr) {
            foreach($subDirArr as $subDir) {
                $subSubDirArr = self::getFoldersArr($baseDir . '/' . $subDir, false);
                if ($subSubDirArr) {
                    foreach($subSubDirArr as $subSubDir) {
                        $FullFileName = $baseDir . '/' . $subDir . '/' . $subSubDir . $fileMask;
                        $foundSubSubFilesArr[$subDir . '/' . $subSubDir] = $FullFileName;
                    }
                }
            }
        }
        return $foundSubSubFilesArr;
    }

    public function convertComposersPSR4toDynoArr(string $vendorDir): ?array {
        $composersPSR4file = $vendorDir . '/composer/autoload_psr4.php';
        if (!\is_file($composersPSR4file)) {
            return null;
        }
        $composerPSR4arr = (require $composersPSR4file);
        if (!\is_array($composerPSR4arr)) {
            return null;
        }
        $dynoArr = [];
        foreach($composerPSR4arr as $nameSpace => $srcFoldersArr) {
            foreach($srcFoldersArr as $n => $path) {
                $srcFoldersArr[$n] = '*' . \strtr($path, '\\', '/') . '/*';
            }
            $nameSpace = \trim(\strtr($nameSpace, '\\', '/'), "/ \n\r\v\t");
            if (\is_array($srcFoldersArr) && \count($srcFoldersArr) === 1) {
                $dynoArr[$nameSpace] = \reset($srcFoldersArr);
            } else {
                $dynoArr[$nameSpace] = $srcFoldersArr;
            }
        }
        
        // check composer autoload_files
        $composerFilesFile = $vendorDir . '/composer/autoload_files.php';
        if (\is_file($composerFilesFile)) {
            $composerAutoLoadFilesArr = (include $composerFilesFile);
            if (\is_array($composerAutoLoadFilesArr) && $composerAutoLoadFilesArr) {
                foreach($composerAutoLoadFilesArr as $key => $file) {
                     $composerAutoLoadFilesArr[$key] = \strtr($file, '\\', '/');
                }
            }
        }        
        // $dynoArr['autoload-files'] = $composerAutoLoadFilesArr ? $composerAutoLoadFilesArr : [];
        $dynoArr['autoload-files'] = [];
        $dynoArr['dyno-aliases'] = [];
        $dynoArr['dyno-update'] = [];
        $dynoArr['dyno-requires'] = [];

        // get All vendor-composer.json files
        $allVendorComposerJSONFilesArr = self::getSubSubDirFilesArr($vendorDir);
        // walk all vendor-composer.json files and remove [psr-4] if have [files]
        foreach($allVendorComposerJSONFilesArr as $pkgName => $composerFullFile) {
            if (!\is_file($composerFullFile)) {
                continue;
            }
            $JsonDataStr = \file_get_contents($composerFullFile);
            if (!$JsonDataStr) {
                continue;
            }
            $JsonDataArr = \json_decode($JsonDataStr, true);
            if (!\is_array($JsonDataArr)) {
                continue;
            }
            if (!empty($JsonDataArr['autoload']['files']) && \substr($pkgName, 0, 8) !== 'dynoser/') {
                $dynoArr['autoload-files'][$pkgName] = $JsonDataArr['autoload']['files'];

                if (!empty($JsonDataArr['autoload']['psr-4']) && \is_array($JsonDataArr['autoload']['psr-4'])) {
                    foreach($JsonDataArr['autoload']['psr-4'] as $psr4 => $path) {
                        $psr4 = \trim($psr4, '\\/ ');
                        $psr4 = \strtr($psr4, '\\', '/');
                        unset($dynoArr[$psr4]);
                    }
                }
            }
            if (!empty($JsonDataArr['extra']) && \is_array($JsonDataArr['extra'])) {
                $extraArr = $JsonDataArr['extra'];
                foreach(['dyno-update', 'dyno-requires', 'dyno-aliases'] as $key) {
                    if (array_key_exists($key, $extraArr)) {
                        $dynoArr[$key][$pkgName] = $extraArr[$key];
                    }
                }
            }
        }

        unset($dynoArr['autoload-files']); // no need more
        // import dyno-aliases
        foreach($dynoArr['dyno-aliases'] as $currPkg => $aliasesArr) {
            if (\is_string($aliasesArr)) {
                $aliasesArr = [$aliasesArr];
            }
            if (\is_array($aliasesArr)) {
                foreach($aliasesArr as $toClassName => $fromClassName) {
                    if (\is_string($fromClassName)) {
                        $toClassName = \trim(\strtr($toClassName, '/', '\\'), ' \\');
                        $dynoArr[$toClassName] = '?' . \strtr($fromClassName, '/', '\\');
                    }
                }
                unset($dynoArr['dyno-aliases'][$currPkg]); //already imported
            }
        }
        if (empty($dynoArr['dyno-aliases'])) {
            unset($dynoArr['dyno-aliases']);
        }
        return $dynoArr;
    }

    public function importComposersPSR4(string $vendorDir): bool {
        if (!is_array($this->dynoArr)) {
            $this->dynoArr = [];
        }
        $this->dynoArr += AutoLoader::$classesArr;
        $dynoArr = $this->convertComposersPSR4toDynoArr($vendorDir);
        if (\is_array($dynoArr)) {
            foreach($dynoArr as $nameSpace => $srcFoldersArr) {
                if (!\array_key_exists($nameSpace, $this->dynoArr) || $this->dynoArr[$nameSpace] !== $srcFoldersArr) {
                    $this->dynoArr[$nameSpace] = $srcFoldersArr;
                    $this->dynoArrChanged = true;
                }
                if (\is_string($srcFoldersArr)) {
                    $srcFoldersArr = [$srcFoldersArr];
                }
                AutoLoader::addNameSpaceBase($nameSpace, $srcFoldersArr, false);
            }
        }
        return $this->dynoArrChanged;
    }
    
    public function getAliases($firstChar = '?'): array {
        $aliasesArr = []; // [aliasTO] => [classFROM]
        foreach($this->dynoArr as $nameSpace => $pathes) {
            if (\is_string($pathes)) {
                $pathes = [$pathes];
            }
            if (\is_array($pathes)) {
                foreach($pathes as $path) {
                    if (\is_string($path) && \substr($path, 0, 1) === $firstChar) {
                        $aliasesArr[$nameSpace] = \substr($path, 1);
                        break;
                    }
                }
            }
        }
        return $aliasesArr;
    }
}
