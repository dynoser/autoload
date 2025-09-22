<?php
declare(strict_types=1);
/**
 * Это одно-файловый быстрый загрузчик, не имеющий никаких зависимостей.
 * Достаточно включить этот файл в проект при помощи require_once из любого места.
 * Откуда грузить файлы классов задаётся в массиве \AutoQuick::$classesArr
 * Простейший метод запуска:
 *  require_once 'AutoQuick.php'; // подключаем файл и вызываем 
 *  new \AutoQuick([... соответствия ...], __DIR__);
 * 
 * Подробнее см. AutoQuick.md
 */

class AutoQuick {

    // [namespace] => pattern
    public static $classesArr = [];

    public static string $lang_cur = 'ru'; // на что будет заменен знак # в первую очередь
    public static string $lang_def = 'en'; // на что будет заменен знак # если не найдется файла по первой замене.

    // directory prefixes [PrefixChar] => left part of path
    public static $classesBaseDirArr = [
        '*' => '', // Зарезервирован для указания абсолютных путей
        '?' => '', // Зарезервирован для присвоения алиасов
        '&' => '', // Зарезервирован для вызова функций по имени
    ];

    /**
     * Constructor for AutoQuick class
     * 
     * @param array $classesArr Array of class mappings
     * @param string $file File path for directory detection
     */
    public function __construct(array $classesArr = [], string $fileORdir = '') {
        if (!isset($classesArr[''])) {
             // пустой ключ обязателен, это шаблон для "всех остальных" классов
            $classesArr[''] = '~/?/?';
        }
        self::$classesArr = $classesArr;

        // корневая директория классов по умолчанию, лучше задать в CLASSES_DIR
        $classesDir = self::scanClassesDir($fileORdir);
        if ($classesDir) {
            self::$classesBaseDirArr['~'] = $classesDir;
        }
    }

    /**
     * This function is registered with 'spl_autoload_register'
     */
    public static function autoLoadSpl(string $classFullName): void {
        if (self::$classesArr) {
            // если определен массив соответствий, тогда работаем.
            self::autoLoad($classFullName);
        }
    }

    /**
     * The function looks for matching files for the specified class name and either loads them or checks for their existence.
     * 
     * @param string $classFullName
     * @param bool $reallyLoad false = return FileName only, true = really load
     * @return string
     */
    public static function autoLoad(string $classFullName, bool $reallyLoad = true): string
    {
        // Let's divide $classFullName to $nameSpaceDir and $classShortName
        // $nameSpaceDir is namespace with "/" dividers instead "\"
        $bs = '\\';
        $i = strrpos($classFullName, $bs);
        if (false ===$i) {
            $classFullName = $bs . $classFullName;
            $i = 0;
        }
        $classShortName = $i ? substr($classFullName, $i + 1) : substr($classFullName, 1);
        $nameSpaceDir = $i ? substr(strtr($classFullName, $bs, '/'), 0, $i) : '/';
        
        // Try to find class in array
        if (isset(self::$classesArr[$classFullName])) {
            $nameSpaceKey = $classFullName;
        } else {
            // Search longest namespace key in $classesArr (from end to root)
            $nameSpaceKey = $nameSpaceDir;
            while ($i && empty(self::$classesArr[$nameSpaceKey])) {
                $i = strrpos($nameSpaceKey, '/');
                $nameSpaceKey = $i ? substr($nameSpaceKey, 0, $i) : '';
            }
            // проверка существования итогового ключа пространства имён
            if (!isset(self::$classesArr[$nameSpaceKey])) {
                // Undefined namespace
                return '';
            }
        }
        $starPath = $i ? substr($nameSpaceDir, $i) : $nameSpaceDir;

        $setAliasFrom = '';

        $filePathString = self::$classesArr[$nameSpaceKey];

        $firstChar = substr($filePathString, 0, 1);
        if (array_key_exists($firstChar, self::$classesBaseDirArr)) {
            $filePathString = self::$classesBaseDirArr[$firstChar] . substr($filePathString, 1);
            switch ($firstChar) {
            case '?':
                // если указан alias
                $setAliasFrom = strtr($filePathString, '/', $bs);
                // если класс, из которого устанавливается алиас, не определен, то определяем имя его файла рекурсивно
                $filePathString = class_exists($setAliasFrom, false) ? '' : self::autoLoad(strtr($filePathString, '/', $bs), false);
                break;
            case '&':
                // если указан вызов функции
                $i = strpos($filePathString, '&');
                $funcName = $i ? substr($filePathString, 0, $i) : $filePathString;
                $filePathString = $i ? substr($filePathString, $i + 1) : '';
                $filePathString = call_user_func($funcName, compact(
                    'filePathString',
                    'classFullName',
                    'starPath',
                    'classShortName',
                    'nameSpaceDir',
                    'nameSpaceKey'
                ));
                break;
            default:
                // алгоритмы преобразования пути:
                // 1. '*' заменяем на $starPath
                // 2. '?' заменяем на $classShortName
                $filePathString = str_replace(['*', '?'], [$starPath, $classShortName], $filePathString);
                
                // 3. добавляем .php если его нет
                if (substr($filePathString, -4) !== '.php') {
                    $filePathString .= '.php';
                }
            }
        }

        // обработка # как vari_*
        $i = strpos($filePathString, '#');
        if ($i) {
            $fileVariCur = substr($filePathString, 0, $i) . self::$lang_cur . substr($filePathString, $i + 1);
            if (is_file($fileVariCur)) {
                $filePathString = $fileVariCur;
            } else {
                $filePathString = substr($filePathString, 0, $i) . self::$lang_def . substr($filePathString, $i + 1);
            }
        }

        // пост-обработка: удалим двойные слэши везде, кроме начала строки
        // двойные слэши в начале строки важны для сетевых путей Windows
        while($i = strrpos($filePathString, '//')) {
            $filePathString = substr($filePathString, 0, $i) . substr($filePathString, $i + 1); 
        }

        if (!$reallyLoad) {
            return $filePathString;
        }
        if ($filePathString && is_file($filePathString)) {
            include_once $filePathString;
        }
        if ($setAliasFrom && !class_exists($classFullName, false) && class_exists($setAliasFrom, false)) {
            class_alias($setAliasFrom, $classFullName);
        }
        return class_exists($classFullName, false) ? $filePathString : '';
    }

    public static function scanClassesDir(string $fileORdir): string {
        if (defined('CLASSES_DIR')) {
            // Максимальный приоритет у константы.
            $classesDir = \CLASSES_DIR;
        } else {
            if ($fileORdir) {
                // если указан путь и это папка, то её и возьмём.
                // иначе считаем что это файл, и возьмём папку, в которой он лежит.
                $classesDir = realpath(is_dir($fileORdir) ? $fileORdir : dirname($fileORdir));
                // если realpath не нашел такой путь, то выбрасываем исключение
                if (!$classesDir) {
                    throw new \Exception("Can't auto-detect classesDir");
                }
            } else {
                // если путь не указан, будем считать что этот файл лежит в папке,
                // которая как раз и является корневой директорией классов
                $classesDir = __DIR__;
            }
        }
        // выпрямляем слэши и удаляем слэш в конце
        return rtrim(strtr($classesDir, '\\', '/'), '/');
    }
}

spl_autoload_register('AutoQuick::autoLoadSpl', true, true);
