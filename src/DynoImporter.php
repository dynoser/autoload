<?php
namespace dynoser\autoload;

use dynoser\autoload\AutoLoadSetup;
use dynoser\autoload\AutoLoader;
use dynoser\HELML\HELML;

class DynoImporter extends DynoLoader
{
    public const NSMAP_LOCAL_FILES_KEY = 'nsmap-local-files';
    public const    COMPOSER_LOCK_HASH = 'composer-hash';
    public const    COMPOSER_REAL_HASH = 'composer-real';
    /**
     * Get remote-nsmap urls from cache-nsmap-file + this->dynoNSmapURLarr + DYNO_NSMAP_URL
     *
     * @return array
     */
    public function getCachedRemoteNSMapURLs(): array {
        // get current nsmap (only to get links to remote nsmap)
        $nsMapArr = $this->loadNSMapFile();
        // get current remote-nsmap url list
        $remoteNSMapURLs = \array_merge(self::$dynoNSmapURLArr, $nsMapArr[self::REMOTE_NSMAP_KEY] ?? []);
        if (\defined('DYNO_NSMAP_URL')) {
            $remoteNSMapURLs[] = \constant('DYNO_NSMAP_URL');
        }
        return \array_unique($remoteNSMapURLs);
    }
    
    public function rebuildDynoCache($subTimeSecOnErr = 30): ?array {
        try {
            $this->checkCreateDynoDir(self::$vendorDir);

            // 0. get pre-loaded classesArr
            AutoLoadSetup::$dynoArr = AutoLoader::$classesArr;

            // 1. add namespaces from vendor (composer) to dynoArr
            $specArrArr = $this->addNameSpacesFromComposer(self::$vendorDir);
            AutoLoadSetup::$dynoArr[self::COMPOSER_LOCK_HASH] = self::getComposerLockHash();
            AutoLoadSetup::$dynoArr[self::COMPOSER_REAL_HASH] = self::getComposerRealHash();
            // 2. scan nsmap-s from local folders
            $locNsSpecArr = $this->addNameSpacesFromLocalNSMaps();
            
            foreach($locNsSpecArr as $k => $v) {
                if (\array_key_exists($k, $specArrArr)) {
                    $specArrArr[$k] += $v;
                } else {
                    $specArrArr[$k] = $v;
                }
            }
            
            // get old remote-nsmap urls from cache-nsmap-file + this->dynoNSmapURLarr + DYNO_NSMAP_URL
            $remoteNSMapURLs = \array_unique(\array_merge($specArrArr[self::REMOTE_NSMAP_KEY] ?? [], $this->getCachedRemoteNSMapURLs()));

            // 3. add remote nsmap (if remote nsmaps enabled)
            $nsMapArr = [];
            if (self::$dynoNSmapFile && $remoteNSMapURLs) {
                $dlMapArr = $this->downLoadNSMaps($remoteNSMapURLs);
                if ($dlMapArr) {
                    $nsMapArr = $dlMapArr['nsMapArr'];
                    // add nsMapArr to dynoArr
                    foreach($nsMapArr as $nameSpace => $xpath) {
                        if (empty(AutoLoadSetup::$dynoArr[$nameSpace])) {
                            // if pkg exist then change path from remote to local
                            if (substr($xpath, 0, 1) === ':') {
                                $unArr = $this->unpackXPath($xpath);
                                if (!$unArr) {
                                    continue;
                                }
                                if (\is_file($unArr['checkFile'])) {
                                    $xpath = $unArr['replaceNameSpace'];
                                }
                            }
                            AutoLoadSetup::$dynoArr[$nameSpace] = $xpath;
                        }
                    }
                    // add specArr
                    foreach($dlMapArr['specArrArr'] as $k => $v) {
                        if (!isset($specArrArr[$k])) {
                            $specArrArr[$k] = [];
                        }
                        assert(\is_array($v));
                        $specArrArr[$k] = \array_merge($specArrArr[$k], $v);
                    }
                }
            }

            $this->saveDynoFile(DYNO_FILE);

            if (self::$dynoNSmapFile) {
                // add specArrArr ti nsMapArr
                foreach($specArrArr as $k => $v) {
                    if (!\is_array($v)) {
                        $v = [$v];
                    }
                    if (\array_key_exists($k, $nsMapArr)) {
                        if (!\is_array($nsMapArr[$k])) {
                            $nsMapArr[$k] = [$nsMapArr[$k]];
                        }
                        $nsMapArr[$k] += $v;
                    }
                }
                
                $nsMapArr[self::REMOTE_NSMAP_KEY] = $remoteNSMapURLs;
                $this->saveNSMapFile(self::$dynoNSmapFile, $nsMapArr);
            }
        } catch(\Throwable $e) {
            $newMTime = \time() - $subTimeSecOnErr;
            if (\is_file(self::$dynoNSmapFile)) {
                \touch(self::$dynoNSmapFile, $newMTime);
            }
            $nsMapArr = null;
        }
        return $nsMapArr;
    }
    
    public function scanLoadLocalNSMaps(string $prefixChar = '@'): array {
        $searchInDir = AutoLoader::getPathPrefix($prefixChar);
        if (!$searchInDir) {
            throw new \Exception("Prefix char is not defined: $prefixChar");
        }
        $nsMapArr = [];   // [namespace] => path (like AutoLoader::$classes)
        $specArrArr = [self::NSMAP_LOCAL_FILES_KEY => []]; // [spec-keys] => [array of strings]
        // get All vendor-nsmap.helml files
        $allNSMapFilesArr = self::getSubSubDirFilesArr($searchInDir, '/nsmap.helml', true, true);
        $vlen = \strlen($searchInDir);
        // walk all vendor-nsmap.helml files and parse
        foreach($allNSMapFilesArr as $pkgName => $nsMapFullFile) {
            $prefixedShortName = $prefixChar . \substr($nsMapFullFile, $vlen);
            $fileDataStr = \file_get_contents($nsMapFullFile);
            $addArr = $fileDataStr ? self::parseNSMapHELMLStr($fileDataStr) : null;
            if ($addArr) {
                $ref = 'Ns:' . \count($addArr['nsMapArr']) . ', Spec:' . \count($addArr['specArr']);
                $nsMapArr += $addArr['nsMapArr'];
                foreach($addArr['specArr'] as $specKey => $vStr) {
                    if (\array_key_exists($specKey, $specArrArr)) {
                        $specArrArr[$specKey] = \array_merge($specArrArr[$specKey], $vStr);
                    } else {
                        $specArrArr[$specKey] = $vStr;
                    }
                }
            } else {
                $ref = "ERROR";
            }
            $specArrArr[self::NSMAP_LOCAL_FILES_KEY][$prefixedShortName] = $ref;
        }
        return \compact('nsMapArr', 'specArrArr');
    }
    
    public function isComposerLockEqual() {
        $result = false;
        if (DYNO_FILE && \file_exists(DYNO_FILE)) {
            if (empty(AutoLoadSetup::$dynoArr[self::COMPOSER_LOCK_HASH])) {
                AutoLoadSetup::$dynoArr = (require DYNO_FILE);
            }
            $oldComposerLockHash = AutoLoadSetup::$dynoArr[self::COMPOSER_LOCK_HASH] ?? null;
            if ($oldComposerLockHash && (self::getComposerLockHash() === $oldComposerLockHash)) {
                $result = true;
            }
        }
        return $result;
    }
    
    public function updateFromComposer(bool $alwaysUpdate) {
        if (DYNO_FILE) {
            $changed = true;
            $currComposerLockHash = self::getComposerLockHash();
            // reload last version of dynoFile
            if (\file_exists(DYNO_FILE)) {
                AutoLoadSetup::$dynoArr = (require DYNO_FILE);
                if (!empty(AutoLoadSetup::$dynoArr[self::COMPOSER_LOCK_HASH])
                    && (AutoLoadSetup::$dynoArr[self::COMPOSER_LOCK_HASH] === $currComposerLockHash)) {
                        $changed = false;
                }
            }
            if (!\is_array(AutoLoadSetup::$dynoArr)) {
                AutoLoadSetup::$dynoArr = [];
            }
            if ($changed || $alwaysUpdate) {
                $specArrArr = $this->addNameSpacesFromComposer(AutoLoadSetup::$vendorDir);
                AutoLoadSetup::$dynoArr[self::COMPOSER_LOCK_HASH] = $currComposerLockHash;
                AutoLoadSetup::$dynoArr[self::COMPOSER_REAL_HASH] = self::getComposerRealHash();

                $this->checkCreateDynoDir(AutoLoadSetup::$vendorDir);
                $this->saveDynoFile(DYNO_FILE);
            }
        }
        return $changed;
    }
    
    public function loadNSMapFile(): ?array {
        $nsMapArr = null;
        if (self::$dynoNSmapFile && \is_file(self::$dynoNSmapFile)) {
            if (self::$useHELMLforNSmap) {
                $dataStr = \file_get_contents(self::$dynoNSmapFile);
                $nsMapArr = HELML::decode($dataStr);
            } else {
                $nsMapArr = (require self::$dynoNSmapFile);
            }
        }
        return \is_array($nsMapArr) ? $nsMapArr : null;
    }
    public function saveNSMapFile(string $nsMapFile, array $nsMapArr) {
        if (self::$useHELMLforNSmap) {
            $dynoStr = HELML::encode($nsMapArr);            
        } else {
            $dynoStr = '<' . "?php\n" . 'return ';
            $dynoStr .= \var_export($nsMapArr, true) . ";\n";
        }
        $wb = \file_put_contents($nsMapFile, $dynoStr);
        if (!$wb) {
            throw new \Exception("Can't write nsmap-file: " . $nsMapFile);
        }
    }

    public function resetRemoteNSMapURLs(array $remoteNSMapURLs): ?array {
        if (!self::$dynoNSmapFile) {
            throw new \Exception("dynoNSmapFile undefined");
        }
        $nsMapArr = $this->loadNSMapFile();
        if (\is_array($nsMapArr)) {
            $nsMapArr[self::REMOTE_NSMAP_KEY] = $remoteNSMapURLs;
            $this->saveNSMapFile(self::$dynoNSmapFile, $nsMapArr);
        }
        return $nsMapArr;
    }
    
    /**
     * Сonverting absolute paths in array that begin with the prefix "*"
     *  to relative paths, with substitution of their prefixes, when possible.
     *
     * @param string $keyToPathArr [namespace] => *path
     * @param bool $starPrefixRequired
     * @return string
     */
    public static function convertAbsPathesToPrefixed(array & $keyToPathArr, bool $starPrefixRequired = true): void {
        // get current baseDir and remove special-prefixes
        $baseDirArr = AutoLoader::$classesBaseDirArr;
        foreach(['*','?',':'] as $k) {
            unset($baseDirArr[$k]);
        }

        // prepare pathes array
        $pathesArr = [];
        foreach($baseDirArr as $prefixChar => $path) {
            $l = \strlen($path);
            if (!$l) {
                continue;
            }
            $baseDirArr[$prefixChar] = \strtr($path, '\\', '/');
            $pathesArr[$prefixChar] = $l;
        }
        unset($pathesArr['~']);
        \arsort($pathesArr);
        foreach($pathesArr as $prefixChar => $len) {
            $pathesArr[$prefixChar] = [$len, $baseDirArr[$prefixChar]];
        }

        // try replace *pathAbs to prefixed-relative pathes
        foreach($keyToPathArr as $key => $pathAbs) {
            if (!\is_string($pathAbs)) {
                continue;
            }
            if (\substr($pathAbs, 0, 1) !== '*') {
                if ($starPrefixRequired) {
                    continue;
                }
            } else {
                $pathAbs = \substr($pathAbs, 1);
            }
            $pathAbs = \strtr($pathAbs, '\\', '/');
            foreach($pathesArr as $prefixChar => $lenPath) {
                $len = $lenPath[0];
                if (\substr($pathAbs, 0, $len) === $lenPath[1]) {
                    $keyToPathArr[$key] = $prefixChar . \substr($pathAbs, $len);
                    break;
                }
            }
        }
    }

    public function saveDynoFile(string $dynoFile): bool {
        // get current dynoArr
        $dynoArr = AutoLoadSetup::$dynoArr;
        self::convertAbsPathesToPrefixed($dynoArr, true);        
        
        // save baseDir-prefiexs
        $baseDirArr = AutoLoader::$classesBaseDirArr;
        // do not save special-prefixes
        foreach(['*','?',':'] as $k) {
            unset($baseDirArr[$k]);
        }
        $dynoArr[AutoLoadSetup::BASE_DIRS_KEY] = $baseDirArr;

        $dynoStr = '<' . "?php\n" . 'return ' . \var_export($dynoArr, true) . ";\n";
        $wb = \file_put_contents($dynoFile, $dynoStr);
        return $wb ? true: false;
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
    
    public function addNameSpacesFromLocalNSMaps(): ?array {
        $dynoArr = & AutoLoadSetup::$dynoArr;
        assert(\is_array($dynoArr));

        $dlMapArr = $this->scanLoadLocalNSMaps('@');
        if (\is_array($dlMapArr)) {
            $specArrArr = $dlMapArr['specArrArr'];
            $nsMapArr = $dlMapArr['nsMapArr'];
            self::convertAbsPathesToPrefixed($nsMapArr, true);
            foreach($nsMapArr as $nameSpace => $srcFolders) {
                if (!\array_key_exists($nameSpace, $dynoArr) || $dynoArr[$nameSpace] !== $srcFolders) {
                    $dynoArr[$nameSpace] = $srcFolders;
                }
            }
        } else {
            $specArrArr = null;
        }
        return $specArrArr;
    }

    public function loadComposerNameSpaces(string $vendorDirIn): ?array {
        $nsMapArr = [];
        $specArrArr = [];

        // try import namespaces from this file:
        $composersPSR4file = $vendorDirIn . '/composer/autoload_psr4.php';
        if (\is_file($composersPSR4file)) {
            $composerPSR4arr = (require $composersPSR4file);
            $vendorDir = \rtrim(\strtr($vendorDir, '\\', '/'), '/');
            if ($vendorDir !== $vendorDirIn) {
                throw new \Exception("Vendor Dir is different: $vendorDir != $vendorDirIn  , file=$composersPSR4file");
            }
            if (\is_array($composerPSR4arr)) {
                foreach($composerPSR4arr as $nameSpace => $srcFoldersArr) {
                    foreach($srcFoldersArr as $n => $path) {
                        $srcFoldersArr[$n] = '*' . \strtr($path, '\\', '/') . '/*';
                    }
                    $nameSpace = \strtr($nameSpace, '\\', '/');
                    if (\substr($nameSpace, -1) === '/') {
                        $nameSpace = \substr($nameSpace, 0, -1);
                        if (\is_array($srcFoldersArr) && \count($srcFoldersArr) === 1) {
                            $nsMapArr[$nameSpace] = \reset($srcFoldersArr);
                        } else {
                            $nsMapArr[$nameSpace] = $srcFoldersArr;
                        }
                    }
                }
            }
        }
        $vlen = \strlen($vendorDirIn);

        // check composer autoload_files
        $composerFilesFile = $vendorDirIn . '/composer/autoload_files.php';
        if (\is_file($composerFilesFile)) {
            $composerAutoLoadFilesArr = (include $composerFilesFile);
            if (\is_array($composerAutoLoadFilesArr) && $composerAutoLoadFilesArr) {
                foreach($composerAutoLoadFilesArr as $key => $file) {
                     $composerAutoLoadFilesArr[$key] = \strtr($file, '\\', '/');
                }
                self::convertAbsPathesToPrefixed($composerAutoLoadFilesArr, false);
                $specArrArr['composer-autoload-files'] = \array_values($composerAutoLoadFilesArr);
            }
        }

        // walk all vendor/*/composer.json files
        $forwardToComposer = [];
        $allVendorComposerJSONFilesArr = self::getSubSubDirFilesArr($vendorDirIn, '/composer.json', true, false);        
        foreach($allVendorComposerJSONFilesArr as $pkgName => $composerFullFile) {
            $JsonDataStr = \file_get_contents($composerFullFile);
            if (!$JsonDataStr) {
                continue;
            }
            $JsonDataArr = \json_decode($JsonDataStr, true);
            if (!\is_array($JsonDataArr)) {
                continue;
            }
            $psr4nsArr = [];
            if (!empty($JsonDataArr['autoload']['psr-4'])) {
                $foundPSR4arr = $JsonDataArr['autoload']['psr-4'];
                if (\is_array($foundPSR4arr)) {
                    foreach($foundPSR4arr as $nsPrefix => $inVendorPath) {
                        $nsKey = \strtr($nsPrefix, '\\', '/');
                        $psr4nsArr[$nsKey] = $inVendorPath;
                        if (\substr($nsKey, -1) === '/') {
                            $nsKey = \substr($nsKey, 0, -1);
                            if (!\array_key_exists($nsKey, $nsMapArr)) {
                                $chkPath = $vendorDirIn . '/'. \trim(\strtr($inVendorPath, '\\','/'), '/ ');
                                if (\is_dir($chkPath)) {
                                    $nsMapArr[$nsKey] = '*' . $chkPath . '/*';
                                }
                            }
                        }
                    }
                }
            }
            if (!empty($JsonDataArr['autoload']['files']) && \substr($pkgName, 0, 8) !== 'dynoser/') {
                $specArrArr['autoload-files'][$pkgName] = $JsonDataArr['autoload']['files'];
                foreach($psr4nsArr as $nsKey => $inVendorPath) {
                    $nsKey = \trim($nsKey, '/ ');
                    if (isset($nsMapArr[$nsKey])) {
                        // remove this namespace from our autoloader, let composer's autoloader load this namespace
                        $forwardToComposer[$nsKey] = \strlen($nsKey);
                    }
                }
            }
            if (!empty($JsonDataArr['extra']) && \is_array($JsonDataArr['extra'])) {
                $extraArr = $JsonDataArr['extra'];
                foreach(['dyno-aliases'] as $key) {
                    if (\array_key_exists($key, $extraArr)) {
                        $specArrArr[$key][$pkgName] = $extraArr[$key];
                    }
                }
            }
        }
        
        // classMap analyze
        $composerClassMapFile = $vendorDirIn . '/composer/autoload_classmap.php';
        if (\is_file($composerClassMapFile)) {
            $composerClassMapArr = (include $composerClassMapFile);
            if (\is_array($composerClassMapArr) && $composerClassMapArr) {
                //prepare array: get only vendorDir-classes, convert classnames to /-slashed, skip forwardToComposer
                $compMapArr = [];
                foreach($composerClassMapArr as $classFullName => $fileFullPath) {
                    $comparePath = \strtr(\substr($fileFullPath, 0, $vlen), '\\', '/');
                    if ($comparePath === $vendorDirIn) {
                        $newNs = \strtr($classFullName, '\\', '/');
                        $forward = false;
                        foreach($forwardToComposer as $nsKey => $nslen) {
                            if (\substr($newNs, 0, $nslen) === $nsKey) {
                                $forward = true;
                                break;
                            }
                        }
                        if (!$forward) {
                            $compMapArr[$newNs] = '@' . \substr($fileFullPath, $vlen);
                        }
                    }
                }
                
                // convert nsMapArr pathes to prefixed and walk all
                self::convertAbsPathesToPrefixed($nsMapArr, true);
                // remove known namespaces from composerClassMap
                foreach($nsMapArr as $nameSpace => $pathArr) {
                    if (\strpos($nameSpace, '\\')) {
                        // skip direct-class definition
                        continue;
                    }
                    $scanNs = $nameSpace . '/';
                    $lns = strlen($scanNs);
                    if (\is_string($pathArr)) {
                        $pathArr = [$pathArr];
                    }
                    foreach($pathArr as $pathStr) {
                        if (!\is_string($pathStr) || \substr($pathStr, 0, 1) !== '@') {
                            continue;
                        }
                        foreach($compMapArr as $classFullName => $prefixedPath) {
                            if (\substr($classFullName, 0, $lns) === $scanNs) {
                                $addPath = \substr($classFullName, $lns);
                                if (\substr($pathStr, -1) === '*') {
                                    $pathStr = substr($pathStr, 0, -1);
                                }
                                if (\substr($pathStr, -1) === '/') {
                                    $expectedPath = $pathStr . $addPath . '.php';
                                    if ($expectedPath === $prefixedPath) {
                                        unset($compMapArr[$classFullName]);
                                    }
                                }
                            }
                        }
                    }
                }
                
                // transfer compMapArr to nsMapArr
                foreach($compMapArr as $classFullName => $prefixedPath) {
                    $classFullName = \strtr($classFullName, '/', '\\');
                    if (empty($nsMapArr[$classFullName])) {
                        $nsMapArr[$classFullName] = $prefixedPath;
                    }
                }
                
                // make composer-autoload-files key
                foreach($composerAutoLoadFilesArr as $key => $file) {
                     $composerAutoLoadFilesArr[$key] = \strtr($file, '\\', '/');
                }
                self::convertAbsPathesToPrefixed($composerAutoLoadFilesArr, false);
                $specArrArr['composer-autoload-files'] = \array_values($composerAutoLoadFilesArr);
            }
        }
        

        // import dyno-aliases
        if (!empty($specArrArr['dyno-aliases'])) {
            foreach($specArrArr['dyno-aliases'] as $currPkg => $aliasesArr) {
                if (\is_string($aliasesArr)) {
                    $aliasesArr = [$aliasesArr];
                }
                if (\is_array($aliasesArr)) {
                    foreach($aliasesArr as $toClassName => $fromClassName) {
                        if (\is_string($fromClassName)) {
                            $toClassName = \trim(\strtr($toClassName, '/', '\\'), ' \\');
                            if (empty($nsMapArr[$toClassName])) {// do not overwrite
                                $nsMapArr[$toClassName] = '?' . \strtr($fromClassName, '/', '\\');
                            }
                        }
                    }
                }
            }
        }
        
        // remove forwardToComposer namespaces from nsMapArr
        foreach($forwardToComposer as $nameSpace => $v) {
            unset($nsMapArr[$nameSpace]);
        }
        
        return \compact('nsMapArr', 'specArrArr');
    }
    
    public static function getComposerLockHash(): ?string {
        return self::getHexFromFile(AutoLoadSetup::$rootDir . '/composer.lock', '"content-hash": "', 32);
    }
    
    public static function getComposerRealHash(): ?string {
        return self::getHexFromFile(AutoLoadSetup::$vendorDir . '/composer/autoload_real.php', 'class ComposerAutoloaderInit', 32);
    }
    
    public static function getHexFromFile($fullFileName, $findStr, $getLen = 32): ?string {
        $result = null;
        if (\is_file($fullFileName)) {
            $dataStr = \file_get_contents($fullFileName);
            $i = \strpos($dataStr, $findStr);
            if (false !== $i) {
                $chkResult = \substr($dataStr, $i + \strlen($findStr), $getLen);
                if ((\strlen($chkResult) === $getLen) && \ctype_xdigit($chkResult)) {
                    $result = $chkResult;
                }
            }
        }
        return $result;
    }
    
    public function addNameSpacesFromComposer(string $vendorDir): ?array {
        $dynoArr = & AutoLoadSetup::$dynoArr;
        assert(\is_array($dynoArr));

        $compScanArr = $this->loadComposerNameSpaces($vendorDir);
        if (\is_array($compScanArr)) {
            $specArrArr = $compScanArr['specArrArr'];
            $nsMapArr = $compScanArr['nsMapArr'];
            self::convertAbsPathesToPrefixed($nsMapArr, true);
            foreach($nsMapArr as $nameSpace => $srcFolders) {
                if (!\array_key_exists($nameSpace, $dynoArr) || $dynoArr[$nameSpace] !== $srcFolders) {
                    $dynoArr[$nameSpace] = $srcFolders;
                }
            }
        } else {
            $specArrArr = null;
        }
        return $specArrArr;
    }
    
    public function getAliases($firstChar = '?'): array {
        $aliasesArr = []; // [aliasTO] => [classFROM]
        foreach(AutoLoadSetup::$dynoArr as $nameSpace => $pathes) {
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
