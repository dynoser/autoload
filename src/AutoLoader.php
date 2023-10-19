<?php
namespace dynoser\autoload;

class AutoLoader
{
    // [ClassName] => Files
    public static $classesArr = [
        'ext' => '&/ext/@',
        'dynoser/autoload' => '*' . __DIR__ . '/*'
    ];
    
    // if classesArr changed set true (see ::addNameSpaceBase) 
    public static $changed = false;

    // directory prefixes [PrefixChar] => left part of path
    public static $classesBaseDirArr = ['*' => '', '?' => ''];
    
    // if directory prefix not specified, try get class with this prefixes:
    public static $defaultPrefixArr = ['*'];

    public static function init($classesBaseDirs, $classesArr)
    {
        self::$classesBaseDirArr = \array_merge(self::$classesBaseDirArr, $classesBaseDirs);
        self::$classesArr = $classesArr;
    }
    
    /**
     * This function is registered with 'spl_autoload_register'
     * @see autoload.php
     * 
     * @param string $classFullName
     * @return true|null
     */
    public static function autoLoadSpl($classFullName) {
        $returnStatus = self::autoLoad($classFullName);

        // Class may contain the static method __onLoad to initialize on load, check it
        if ($returnStatus && \class_exists($classFullName, false) && \method_exists($classFullName, '__onLoad')) {
            $classFullName::__onLoad();
        }

        return $returnStatus;
    }

    /**
     * The function looks for matching files for the specified class name and either loads them or checks for their existence.
     * 
     * @param string $classFullName
     * @param bool $realyLoad false = only check existence, true = realy load
     * @return true|null
     */
    public static function autoLoad($classFullName, $realyLoad = true)
    {
        // if class not defined, return NULL for try another autoloader
        $returnStatus = NULL;

        // Let's divide $classFullName to $nameSpaceDir and $classShortName
        // $nameSpaceDir is namespace with "/" dividers instead "\"
        $i = strrpos($classFullName, '\\');
        $classShortName = $i ? \substr($classFullName, $i + 1) : $classFullName;
        $nameSpaceDir = $i ? \substr(strtr($classFullName, '\\', '/'), 0, $i) : '';

        // Try to find class in array
        if (isset(self::$classesArr[$classFullName])) {
            $nameSpaceKey = $classFullName;
            $starPath = $classShortName;
        } else {
            // Search first defined namespace in $classesArr (from end to root)
            $nameSpaceKey = $nameSpaceDir;
            while ($i && empty(self::$classesArr[$nameSpaceKey])) {
                $i = \strrpos($nameSpaceKey, '/');
                $nameSpaceKey = $i ? substr($nameSpaceKey, 0, $i) : '';
            }
            if (empty(self::$classesArr[$nameSpaceKey])) {
                // Class or namespace is not defined
                return $returnStatus;
            }
            $starPath = $i ? \substr($nameSpaceDir, $i) : $nameSpaceDir;
        }

        // convert string to array (if need)
        if (!\is_array(self::$classesArr[$nameSpaceKey])) {
            self::$classesArr[$nameSpaceKey] = [self::$classesArr[$nameSpaceKey]];
        }

        foreach(self::$classesArr[$nameSpaceKey] as $numKey => $filePathString) {
            if (!\is_string($filePathString)) {
                if (true === $filePathString) {
                    $filePathString = '~' . $classShortName . '/' . $classShortName . '.php';
                } else {
                    continue;
                }
            }

            $firstChar = \substr($filePathString, 0, 1);
            if (\array_key_exists($firstChar, self::$classesBaseDirArr)) {
                $pathPrefixVariantsArr = [$firstChar];
                $filePathString = \substr($filePathString, 1);
                if (!\strlen($filePathString)) {
                    $filePathString = '/';
                }
            } else {
                $pathPrefixVariantsArr = self::$defaultPrefixArr;
            }
            if ($firstChar === '?') {
                // alias
                $setAliasFrom = \strtr($filePathString, '/', '\\');
                $filePathString = self::autoLoad(\strtr($filePathString, '/', '\\'), false);
                if (!$filePathString) {
                    return false;
                }
            }
            $lc2 = \substr($filePathString, -2);
            if ($lc2 === '/*') {
                $filePathString = \substr($filePathString, 0, -2) . $starPath . '/';
            } elseif ($lc2 === '/@') {
                $classFolder = empty($starPath) ? $classShortName : 'classes';
                $filePathString = substr($filePathString, 0, -2) . $starPath . '/' . $classFolder . '/';
            }
            if (\substr($filePathString, -1) === '/') {
                $filePathString .= $classShortName . '.php';
            } elseif ('?' !== $firstChar) {
                // Remove rule for one-specified-file
                unset(self::$classesArr[$nameSpaceKey][$numKey]);
            }

            foreach($pathPrefixVariantsArr as $firstChar) {
                $fileFullName = self::$classesBaseDirArr[$firstChar] . $filePathString;
                if (\is_null($realyLoad)) {
                    return $fileFullName;
                }
                if (!\is_file($fileFullName)) {
                    continue;
                }
                if (!$realyLoad) {
                    return $fileFullName;
                }
                include_once $fileFullName;
                if (!empty($setAliasFrom) && !\class_exists($classFullName, false) && \class_exists($setAliasFrom, false)) {
                    \class_alias($setAliasFrom, $classFullName);
                }
                $returnStatus = true;
                break;
            }
        }

        return $returnStatus;
    }
    
    public static function getPathPrefix($filePathString) {
        $firstChar = \substr($filePathString, 0, 1);
        $firstCharNeedRemove = isset(self::$classesBaseDirArr[$firstChar]);
        if ($firstCharNeedRemove !== false) {
            $filePathString = \substr($filePathString, 1);
            return self::$classesBaseDirArr[$firstChar] . $filePathString;
        }
    }
    
    public static function addNameSpaceBase($nameSpace, $linkedPath, $ifNotExist = true) {
        $nameSpace = \trim($nameSpace, "/\\ \n\r\v\t");
        if ($ifNotExist && !empty(self::$classesArr[$nameSpace])) {
            return false;
        }
        if (\is_string($linkedPath)) {
            $linkedPath = \strtr($linkedPath, '\\', '/');
            $lastChar = \substr($linkedPath, -1);
            if ($lastChar !== '*') {
                $linkedPath .= (($lastChar === '/') ? '*' : '/*');
            }
        }
        if (!isset(self::$classesArr[$nameSpace]) || self::$classesArr[$nameSpace] !== $linkedPath) {
            if (isset(self::$classesArr[$nameSpace]) && \is_string(self::$classesArr[$nameSpace])
                && \is_array($linkedPath) && \count($linkedPath) === 1 && self::$classesArr[$nameSpace] === reset($linkedPath)
            ) {
                return true;
            }
            self::$classesArr[$nameSpace] = $linkedPath;
            self::$changed = true;
        }
        return true;
    }
}