<?php
namespace dynoser\autoload;

class DynoImporter extends DynoLoader
{
    /**
     * Get remote-nsmap urls from cache-nsmap-file + this->dynoNSmapURLarr + DYNO_NSMAP_URL
     *
     * @return array
     */
    public function getCachedRemoteNSMapURLs(): array {
        // get current nsmap (only to get links to remote nsmap)
         $nsMapArr = $this->loadNSMapFile();
         // get current remote-nsmap url list
         $remoteNSMapURLs = \array_merge($this->dynoNSmapURLArr, $nsMapArr[self::REMOTE_NSMAP_KEY] ?? []);
         if (\defined('DYNO_NSMAP_URL')) {
             $remoteNSMapURLs[] = \constant('DYNO_NSMAP_URL');
         }
         return $remoteNSMapURLs;
    }
    
    public function rebuildDynoCache($subTimeSecOnErr = 30): ?array {
        try {
            $this->checkCreateDynoDir($this->vendorDir);

            // get remote-nsmap urls from cache-nsmap-file + this->dynoNSmapURLarr + DYNO_NSMAP_URL
            $remoteNSMapURLs = $this->getCachedRemoteNSMapURLs();

            // load nsmap-s from local folders
            $dlMapArr = $this->scanLoadNSMaps($this->vendorDir);
            $nsMapArr = $dlMapArr['nsMapArr'];
            $specArrArr = $dlMapArr['specArrArr'];
            if (isset($specArrArr[self::REMOTE_NSMAP_KEY])) {
                $remoteNSMapURLs = \array_merge($remoteNSMapURLs, $specArrArr[self::REMOTE_NSMAP_KEY]);
            }
            $remoteNSMapURLs = \array_unique($remoteNSMapURLs);
            
            $dlMapArr = $this->downLoadNSMaps($remoteNSMapURLs);
            if ($dlMapArr) {
                $nsMapArr += $dlMapArr['nsMapArr'];
                $this->dynoArr = AutoLoader::$classesArr;
                $this->updateFromComposer($this->vendorDir);
                $this->dynoArr = \array_merge($this->dynoArr, $nsMapArr);
                $this->saveDynoFile(DYNO_FILE);
                $nsMapArr[self::REMOTE_NSMAP_KEY] = $remoteNSMapURLs;
                $this->saveNSMapFile($this->dynoNSmapFile, $nsMapArr);
                return $nsMapArr;
            }
        } catch(\Throwable $e) {
            $newMTime = \time() - $subTimeSecOnErr;
            if (\is_file($this->dynoNSmapFile)) {
                \touch($this->dynoNSmapFile, $newMTime);
            }
        }
        return null;
    }
        
    public function scanLoadNSMaps(string $vendorDir): array {
        $nsMapArr = [];   // [namespace] => path (like AutoLoader::$classes)
        $specArrArr = []; // [spec-keys] => [array of strings]
        // get All vendor-nsmap.helml files
        $allNSMapFilesArr = self::getSubSubDirFilesArr($vendorDir, '/nsmap.helml', true, true);
        // walk all vendor-nsmap.helml files and parse
        foreach($allNSMapFilesArr as $pkgName => $nsMapFullFile) {
            $fileDataStr = \file_get_contents($nsMapFullFile);
            $addArr = self::parseNSMapHELMLStr($fileDataStr);
            $nsMapArr += $addArr['nsMapArr'];
            foreach($addArr['specArr'] as $specKey => $vStr) {
                if (\array_key_exists($specKey, $specArrArr)) {
                    $specArrArr[$specKey][] = $vStr;
                } else {
                    $specArrArr[$specKey] = [$vStr];
                }
            }
        }
        return \compact('nsMapArr', 'specArrArr');
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
    
    public function loadNSMapFile(): ?array {
        if ($this->dynoNSmapFile && \is_file($this->dynoNSmapFile)) {
            $nsMapArr = (require $this->dynoNSmapFile);
            if (\is_array($nsMapArr)) {
                return $nsMapArr;
            }
        }
        return null;
    }
    public function saveNSMapFile(string $nsMapFile, array $nsMapArr) {
        $dynoStr = '<' . "?php\n" . 'return ';
        $dynoStr .= \var_export($nsMapArr, true) . ";\n";
        $wb = \file_put_contents($nsMapFile, $dynoStr);
        if (!$wb) {
            throw new \Exception("Can't write nsMap-file (downloaded namespaces)\nFile: " . $nsMapFile);
        }
    }

    public function resetRemoteNSMapURLs(array $remoteNSMapURLs): ?array {
        if (!$this->dynoNSmapFile) {
            throw new \Exception("dynoNSmapFile undefined");
        }
        $nsMapArr = $this->loadNSMapFile();
        if (\is_array($nsMapArr)) {
            $nsMapArr[self::REMOTE_NSMAP_KEY] = $remoteNSMapURLs;
            $this->saveNSMapFile($this->dynoNSmapFile, $nsMapArr);
        }
        return $nsMapArr;
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
 
    public static function getSubSubDirFilesArr(string $baseDir, string $fileMask = '/composer.json', bool $ifExist = true, bool $addSubDir = false): ?array {
        $foundSubSubFilesArr = []; // [subDir/subSubDir] => FullFileName
        $subDirArr = self::getFoldersArr($baseDir, false);
        if ($subDirArr) {
            foreach($subDirArr as $subDir) {
                $subSubDirArr = self::getFoldersArr($baseDir . '/' . $subDir, false);
                if ($subSubDirArr) {
                    foreach($subSubDirArr as $subSubDir) {
                        $FullFileName = $baseDir . '/' . $subDir . '/' . $subSubDir . $fileMask;
                        if (!$ifExist || \is_file($FullFileName)) {
                            $foundSubSubFilesArr[$subDir . '/' . $subSubDir] = $FullFileName;
                        }
                    }
                }
                if ($addSubDir) {
                    $FullFileName = $baseDir . '/' . $subDir . $fileMask;
                    if (!$ifExist || \is_file($FullFileName)) {
                        $foundSubSubFilesArr[$subDir . '/'] = $FullFileName;
                    }
                }
            }
        }
        if ($addSubDir) {
            $FullFileName = $baseDir . $fileMask;
            if (!$ifExist || \is_file($FullFileName)) {
                $foundSubSubFilesArr['/'] = $FullFileName;
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
        $allVendorComposerJSONFilesArr = self::getSubSubDirFilesArr($vendorDir, '/composer.json', true, false);
        // walk all vendor-composer.json files and remove [psr-4] if have [files]
        foreach($allVendorComposerJSONFilesArr as $pkgName => $composerFullFile) {
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
