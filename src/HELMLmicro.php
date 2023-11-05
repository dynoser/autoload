<?php
namespace dynoser\HELML;

class HELMLmicro
{
    public static $SPEC_TYPE_VALUES = [
        'N' => null,
        'U' => null,
        'T' => true,
        'F' => false,
        'NAN' => NAN,
        'INF' => INF,
        'NIF' =>-INF,
    ];

    public static function decode($src_rows) {
        $lvl_ch = ':';
        $spc_ch = ' ';

        if (\is_array($src_rows)) {
            $str_arr = $src_rows;
        } elseif (\is_string($src_rows)) {
            foreach(["\n", "\r", "~"] as $exploder_ch) {
                if (false !== \strpos($src_rows, $exploder_ch)) {
                    if ("~" === $exploder_ch && \substr($src_rows, -1) === '~') {
                        $lvl_ch = '.';
                        $spc_ch = '_';
                    }
                    break;
                }
            }
            $str_arr = \explode($exploder_ch, $src_rows);
        } else {
            throw new \InvalidArgumentException("Array or String required");
        }
        
        $result = [];
        $stack = [];

        $min_level = -1;
        
        $lines_cnt = \count($str_arr);
        for ($lnum = 0; $lnum < $lines_cnt; $lnum++) {
            $line = \trim($str_arr[$lnum]);

            if (!\strlen($line) || \substr($line, 0, 1) === '#' || \substr($line, 0, 2) === '//') continue;

            $level = 0;
            while (\substr($line, $level, 1) === $lvl_ch) {
                $level++;
            }

            if ($level) {
                $line = \substr($line, $level);
            }

            $parts = \explode($lvl_ch, $line, 2);
            $key = $parts[0] ? $parts[0] : '0';
            $value = isset($parts[1]) ? $parts[1] : null;

            if ($min_level < 0 || $min_level > $level) {
                $min_level = $level;
            }

            $extra_keys_cnt = \count($stack) - $level + $min_level;
            if ($extra_keys_cnt > 0) {
                 while(\count($stack) && $extra_keys_cnt--) {
                     \array_pop($stack);
                 }
            }

            $parent = &$result;
            foreach ($stack as $parentKey) {
                $parent = &$parent[$parentKey];
            }

            if ('-' === \substr($key, 0, 1)) {
                if ($key === '--') {
                    $key = \count($parent);
                } else {
                    $decoded_key = self::base64Udecode(\substr($key, 1));
                    if (false !== $decoded_key) {
                        $key = $decoded_key;
                    }
                }
            }

            if (\is_null($value) || !\strlen($value)) {
                $parent[$key] = [];
                \array_push($stack, $key);
            } else {
                if ($value === '`') {
                    $value = [];
                    for($cln = $lnum + 1; $cln < $lines_cnt; $cln++) {
                        $line = \trim($str_arr[$cln],"\r\n\x00");
                        if ($line === '`') {
                            $value = \implode("\n", $value);
                            $lnum = $cln;
                            break;
                        }
                        $value[] = $line;
                    }
                    if (!\is_string($value)) {
                        $value = '`ERR`';
                    }
                } else {
                    $value = self::valueDecoder($value, $spc_ch);
                }
               
                $parent[$key] = $value;
            }
        }
 
        return $result;
    }

    public static function valueDecoder($encodedValue, $spc_ch = ' ') {
        $first_char = \substr($encodedValue, 0, 1);
        if ($spc_ch === $first_char) {
            if (\substr($encodedValue, 0, 2) !== $spc_ch . $spc_ch) {
                return \substr($encodedValue, 1);
            }
            $slicedValue = \substr($encodedValue, 2);
            if (\is_numeric($slicedValue)) {
                if (\strpos($slicedValue, '.')) {
                    return (double) $slicedValue;
                }
                return (int) $slicedValue;
            } elseif (\array_key_exists($slicedValue, self::$SPEC_TYPE_VALUES)) {
                return self::$SPEC_TYPE_VALUES[$slicedValue];
            }            
            return $encodedValue;
        } elseif ('"' === $first_char || "'" === $first_char) {
            $encodedValue = \substr($encodedValue, 1, -1);
            if ("'" === $first_char) {
                return $encodedValue;
            }
            return \stripcslashes($encodedValue);
        } elseif ('-' === $first_char) {
            return self::base64Udecode(substr($encodedValue, 1));
        }
        
        return self::base64Udecode($encodedValue);
    }

    public static function base64Udecode($str) {
        return \base64_decode(\strtr($str, '-_', '+/'));
    }
}
