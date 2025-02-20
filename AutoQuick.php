<?php
declare(strict_types=1);
/**
 * Это одно-файловый быстрый загрузчик, не имеющий никаких зависимостей.
 * Достаточно включить этот файл в проект при помощи require_once из любого места.
 * Откуда грузить файлы для какого пространства имён можно задавать либо при помощи
 *  \AutoQuick::addNameSpaceBase('пространсто имен', 'шаблон имени файла')
 * либо добавляя элементы напрямую в массив \AutoQuick::$classesArr[fullClassName] = filePath
 * Обратим внимание:
 *  чтобы указать прямое соответствие полного имени класса и имени файла,
 *   в ключе должны быть разделителлями обратные слэши, т.е. точно так же как в namespace
 *   иными словами, при указании полного имени класса требуется точное соответствие каждого символа имени.
 *  если же хотим указать левую часть пространства имён, к которой будут "пристыковываться" более длинные имена
 *   то тогда в ключе должны быть разделителями прямые слэши '/', т.е. не как в namespace.
 *   поиск ключей для пространств имён ведётся от более длинных к более коротким, т.е. справа налево.
 * 
 * Шаблоны имени файла имеют следующую структуру:
 *  1. первый символ (слева) - специальный, может заменяться на путь, указанный в $classesBaseDirArr
 *   Предустановленные первые символы:
 *   '*' - указание абсолютного пути (символ заменяется на пустую строку), далее может быть указан любой путь
 *   '~' - корневой путь папки классов, может быть предустановлен в константе CLASSES_DIR
 *   '?' - зарезервирован для создания алиасов классов
 *  Если первый символ не определен в массиве $classesBaseDirArr он ни на что не заменяется и остаётся как есть.
 *
 *  2. Все остальные символы шаблона, кроме двух специальных '*' и '?' трактуются такими как есть.
 *   '?' - заменяется на короткое имя класса (крайне правая часть, без остального namespace)
 *   '*' - заменяется на то, что левее короткого имени класса, но правее текущего ключа пространства имён
 *  3. Не боимся добавлять лишние слэши на стыках, они будут преобразованы к одному слешу.
 *
 *  По умолчанию, если никакие namespace не добавлены:
 *   шаблон имени файла класса будет соответствовать полному namespace + .php
 *   считая от той папки, которая будет определена как '~' (то есть CLASSES_DIR).
 */

class AutoQuick {
    public static bool $needInit = true;

    // [namespace] => pattern
    public static $classesArr = [
        '' => '~/*/?' // шаблон загрузки для "всех остальных" классов
    ];

    // directory prefixes [PrefixChar] => left part of path
    public static $classesBaseDirArr = [
        '*' => '', // Зарезервирован для указания абсолютных путей
        '?' => '' // Зарезервирован для присвоения алиасов
    ];
    
    public function __construct(string $nameSpace = '', string $file = '') {
        self::$needInit = false;

        self::$classesBaseDirArr['~'] = self::scanClassesDir($file);

        if ($nameSpace) {
            self::addNameSpaceBase($nameSpace);
        }
    }

    /**
     * This function is registered with 'spl_autoload_register'
     */
    public static function autoLoadSpl(string $classFullName): void {
        if (self::$needInit) {
            new \AutoQuick();
        }
        $fullFileName = self::autoLoad($classFullName);

        // Class may contain the static method __onLoad to initialize on load, check it
        if ($fullFileName && \class_exists($classFullName, false) && \method_exists($classFullName, '__onLoad')) {
            $classFullName::__onLoad();
        }
    }

    /**
     * The function looks for matching files for the specified class name and either loads them or checks for their existence.
     * 
     * @param string $classFullName
     * @param bool $realyLoad false = return FileName only, true = realy load
     * @return string
     */
    public static function autoLoad($classFullName, $realyLoad = true): string
    {
        // Let's divide $classFullName to $nameSpaceDir and $classShortName
        // $nameSpaceDir is namespace with "/" dividers instead "\"
        $i = \strrpos($classFullName, '\\');
        $classShortName = $i ? \substr($classFullName, $i + 1) : $classFullName;
        $nameSpaceDir = $i ? \substr(strtr($classFullName, '\\', '/'), 0, $i) : '';

        // Try to find class in array
        if (isset(self::$classesArr[$classFullName])) {
            $nameSpaceKey = $classFullName;
        } else {
            // Search first defined namespace in $classesArr (from end to root)
            $nameSpaceKey = $nameSpaceDir;
            while ($i && empty(self::$classesArr[$nameSpaceKey])) {
                $i = \strrpos($nameSpaceKey, '/');
                $nameSpaceKey = $i ? substr($nameSpaceKey, 0, $i) : '';
            }
            if (empty(self::$classesArr[$nameSpaceKey])) {
                // Class or namespace is not defined
                return '';
            }
        }
        $starPath = $i ? \substr($nameSpaceDir, $i) : $nameSpaceDir;

        $setAliasFrom = '';

        $filePathString = self::$classesArr[$nameSpaceKey];

        $firstChar = \substr($filePathString, 0, 1);
        if (\array_key_exists($firstChar, self::$classesBaseDirArr)) {
            $filePathString = self::$classesBaseDirArr[$firstChar] . \substr($filePathString, 1);
            if ('?' === $firstChar) {
                // alias
                $setAliasFrom = \strtr($filePathString, '/', '\\');
                // если класс, из которого устанавливается алиас, не определён, то определяем имя исходного файла рекурсивно
                $filePathString = \class_exists($setAliasFrom, false) ? '' : self::autoLoad(\strtr($filePathString, '/', '\\'), false);
            } else {
                // алгоритмы преобразования пути:
                // 1. '*' заменяем на $starPath
                // 2. '?' заменяем на $classShortName
                $filePathString = \str_replace(['*', '?'], [$starPath, $classShortName], $filePathString);
                
                if (\substr($filePathString, -4) !== '.php') {
                    $filePathString .= '.php';
                }
            }
            // Удалим двойные слэши везде, кроме самого начала строки
            while($i = \strrpos($filePathString, '//')) {
               $filePathString = \substr($filePathString, 0, $i) . \substr($filePathString, $i + 1); 
            }
        }

        if (!$realyLoad) {
            return $filePathString;
        }
        if ($filePathString && \is_file($filePathString)) {
            include_once $filePathString;
        }
        if ($setAliasFrom && !\class_exists($classFullName, false) && \class_exists($setAliasFrom, false)) {
            \class_alias($setAliasFrom, $classFullName);
        }
        return \class_exists($classFullName, false) ? $filePathString : '';
    }
    
    public static function addNameSpaceBase(string $nameSpaceSrc, string $linkedPath = '~/*', bool $ifNotExist = true): bool {
        if (self::$needInit) {
            new AutoQuick();
        }
        
        // удаляем в начале и в конце пробельные символы и слэши
        $nameSpace = \trim($nameSpaceSrc, "/\\ \n\r\v\t");
        if ($ifNotExist && isset(self::$classesArr[$nameSpace])) {
            // если уже определено и флаг включен, то не переопределяем
            return false;
        }

        // выпрямляем слэши в пути
        $linkedPath = \strtr($linkedPath, '\\', '/');
        $llen = \strlen($linkedPath);
        
        // налиие знака ? внутри расценивается как указание детального пути и отменяет финальные модификации
        $haveQ = (false !== \strpos($linkedPath, '?'));
        // имелись ли спецсимволы ? или * внутри пути
        $haveSpec = (false !== \strpos($linkedPath, '*')) || $haveQ;
        // является ли указанный путь просто директорией
        $isDir = !$haveSpec && \is_dir($linkedPath);

        $fc = \substr($linkedPath, 0, 1); //берём первый символ пути
        if (empty(self::$classesBaseDirArr[$fc])) {
            //если такой символ не определён в списке, выполним поиск по символа по директории
            foreach(self::$classesBaseDirArr as $firstChar => $leftPath) {
                $clen = \strlen($leftPath);
                if ($clen && $clen <= $llen && \substr($linkedPath, 0, $clen) === $leftPath) {
                    $linkedPath = $firstChar . \substr($linkedPath, $clen);
                    break;
                }
            }
        }
        if ($haveQ) {
            // Удаляем удвоенные ?? и это отменяет финальные модификации
            $linkedPath = \str_replace('??', '', $linkedPath);
        } else {
            // Финальные модификации
            if ($isDir) {
                // если на входе была директория (и без спецсимволов), добавляем
                $linkedPath .= '/*';
            }
            if (\substr($linkedPath, -1) === '*') {
                // Если в конце есть * и внутри нет ?, добавляем
                $linkedPath .= '/?';
            }
        }
        self::$classesArr[$nameSpace] = $linkedPath;
        return true;
    }

    public static function scanClassesDir(string $file): string {
        if (\defined('CLASSES_DIR')) {
            // Максимальный приоритет у константы.
            $classesDir = \CLASSES_DIR;
        } else {
            // Берём за основу либо путь от указанного файла, либо текущую папку.
            $classesDir = $file ? \dirname($file) : \getcwd();
            // попробуем найти папку /vendor/ внутри этого пути
            $i = \strpos(\strtr($classesDir . '/', '\\', '/'), '/vendor/');
            if ($i) {
                // если папка /vendor найдена, берём всё что слева от неё
                $classesDir = \substr($classesDir, 0, $i);
            } else {
                // если /vendor в пути отсутствует, возьмём путь файла, из которого был вызов
                $backtrace = \debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, 5);
                $calledFrom = \end($backtrace);
                if (isset($calledFrom['file'])) {
                    $classesDir = \dirname($calledFrom['file']);
                } else {
                    throw new \Exception("Can't auto-detect classesDir");
                }
            }
        }
        // выпрямляем слэши и удаляем слэш в конце
        return \rtrim(\strtr($classesDir, '\\', '/'), '/');
    }
}

\spl_autoload_register('\AutoQuick::autoLoadSpl', true, true);
