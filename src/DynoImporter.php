<?php
namespace dynoser\autoload;

class DynoImporter {
    
    public $dynoArr = []; // [namespace] => sourcepath (see $classesArr in AutoLoader)
    public $dynoArrChanged = false; // if the dynoArr differs from what is saved in the file
    
    public function __construct(string $vendorDir) {
        if (DYNO_FILE) {
            if (\file_exists(DYNO_FILE)) {
                $this->dynoArr = (require DYNO_FILE);
            }
            if ($this->dynoArr && \is_array($this->dynoArr)) {
                AutoLoader::$classesArr = \array_merge(AutoLoader::$classesArr, $this->dynoArr);
            } else {
                $this->dynoArr = AutoLoader::$classesArr;
                $this->importComposersPSR4($vendorDir);
                $this->saveDynoFile($vendorDir);
            }
        }
    }
    
    public function updateFromComposer(string $vendorDir) {
        if (DYNO_FILE) {
            // reload last version of dynoFile
            if (($this->dynoArrChanged || empty($this->dynoArr)) && \file_exists(DYNO_FILE)) {
                $this->dynoArr = (require DYNO_FILE);
                $this->dynoArrChanged = false;
            }
            $changed = $this->importComposersPSR4($vendorDir);
            $this->saveDynoFile($vendorDir);
        }
        return $this->dynoArrChanged;
    }
    
    public function saveDynoFile(string $vendorDir) {
        $dynoStr = '<' . "?php\n" . 'return ';
        $dynoStr .= \var_export($this->dynoArr, true) . ";\n";
        $chkDir = \dirname(DYNO_FILE);
        if (!\is_dir($chkDir)) {
            if (\is_dir($vendorDir) && \dirname($chkDir, 2) === \dirname($vendorDir)) {
                \mkdir($chkDir,0777, true);
            }
            if (!\is_dir($chkDir)) {
                throw new \Exception("Not found folder to storage DYNO_FILE=" . DYNO_FILE);
            }
        }
        $wb = \file_put_contents(DYNO_FILE, $dynoStr);
        if (!$wb) {
            throw new \Exception("Can't write dyno-file (psr4-namespaces imported from composer)\nFile: " . DYNO_FILE);
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
 
    public static function getAllVendorComposerJsonFilesArr(string $vendorDir): ?array {
        $pkgJSONfilesArr = [];
        $vendorsArr = self::getFoldersArr($vendorDir, false);
        if ($vendorsArr) {
            foreach($vendorsArr as $currVendorName) {
                $pkgDirArr = self::getFoldersArr($vendorDir . '/' . $currVendorName, false);
                if ($pkgDirArr) {
                    foreach($pkgDirArr as $currPkgDir) {
                        $pkgComposerJSONFile = $vendorDir . '/' . $currVendorName . '/' . $currPkgDir . '/composer.json';
                        $pkgJSONfilesArr[$currVendorName . '/' . $currPkgDir] = $pkgComposerJSONFile;
                    }
                }
            }
        }
        return $pkgJSONfilesArr;
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
        $allVendorComposerJSONFilesArr = self::getAllVendorComposerJsonFilesArr($vendorDir);
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
    
    public function getAliases(): array {
        $aliasesArr = []; // [aliasTO] => [classFROM]
        foreach($this->dynoArr as $nameSpace => $pathes) {
            if (\is_string($pathes)) {
                $pathes = [$pathes];
            }
            if (\is_array($pathes)) {
                foreach($pathes as $path) {
                    if (\is_string($path) && \substr($path, 0, 1) === '?') {
                        $aliasesArr[$nameSpace] = \substr($path, 1);
                        break;
                    }
                }
            }
        }
        return $aliasesArr;
    }
}
