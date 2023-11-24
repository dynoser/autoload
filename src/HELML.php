<?php
namespace dynoser\HELML;
/*
 * This code represents a PHP implementation of the HELML class without dependencies.
 * 
 * The class provides functions for encoding and decoding HELML-formated data.
 * 
 */

class HELML {
    // *** This class was automaticaly extracted from HELML class source by SectMan Sections Manager.
    // *** This class contains full functionality HELML encoding and decoding, but is not source code
    // *** source code of this file you can see here: https://github.com/dynoser/HELML/blob/master/PHP/HELML.php

    // Custom user-specified values may be added here:
    public static $SPEC_TYPE_VALUES = [
        'N' => null,
        'U' => null,
        'T' => true,
        'F' => false,
        'NAN' => NAN,
        'INF' => INF,
        'NIF' =>-INF,
    ];

    //Custom hooks below (set callable if need)
    
    // Hook for decode "  Value"
    public static $CUSTOM_FORMAT_DECODER = null;

    // Default value decoder is self::decodeValue, may be replace here
    public static $CUSTOM_VALUE_DECODER = null;
    
    // Enable auto-create array when key already exists
    public static $ENABLE_DBL_KEY_ARR = false;
        
    // Default value encoder is self::encodeValue, may be replaced here
    public static $CUSTOM_VALUE_ENCODER = null;

    public static $ENABLE_BONES = true;
    public static $ENABLE_SPC_IDENT = 1;
    public static $ENABLE_KEY_UPLINES = true; //add empty line before create-array-keys
    public static $ENABLE_HASHSYMBOLS = false; // adding # after nested-blocks

    /**
     * Encode array to HELML string
     *
     * @param array $arr
     * @param integer $one_line_mode 0-multi-lines, 1-URL-mode, 2-oneLine-mode
     * @return string
     * @throws InvalidArgumentException
     */
    public static function encode($arr, $one_line_mode = 0) {
        $results_arr = [];
        if (!\is_array($arr)) {
            throw new \InvalidArgumentException("Array required");
        }

        $url_mode = ($one_line_mode == 1);
        $str_imp = $one_line_mode ? "~" : "\n";
        $lvl_ch = $url_mode ? '.' : ':';
        $spc_ch = $url_mode ? '_' : ' ';

        self::_encode($arr, $results_arr, 0, $lvl_ch, $spc_ch, self::isArrayList($arr));

        if ($url_mode) {
            $results_arr[] = '';
        } elseif ($one_line_mode) {
            \array_map('trim', $results_arr); // remove left spaces
            \array_filter($results_arr, function($el) { return \strlen($el) > 0 && $el[0] !== '#'; }); // remove empty and comment lines
        }
        return \implode($str_imp, $results_arr);
    }

    /**
     * Recursive helper function to process each key-value pair in the input array
     * 
     * @param array $arr
     * @param array &$results_arr
     * @param int $level
     * @param string $lvl_ch
     * @param string $spc_ch
     * @param int $is_list
     */
    public static function _encode(
        $arr,
        &$results_arr,
        $level = 0,
        $lvl_ch = ':',
        $spc_ch = ' ',
        $is_list = 0
    ) {
        foreach ($arr as $key => $value) {

            // Use auto-increment key index if possible
            if ($is_list && self::$ENABLE_BONES) {
                $key = '--';
            } else if (!$is_list) {
                // Encode $key in base64url if it contains unwanted characters
                if (\strlen($key)) {
                    $fc = \substr($key, 0, 1);
                    $lc = \substr($key, -1, 1);
                    if (('#' === $fc && !$level) || $fc === $spc_ch || $fc === ' ' || $lc === $spc_ch || $lc === ' ' || false !== \strpos($key, $lvl_ch)) {
                        $fc = '-';
                    } else {
                        $pattern = ($spc_ch == '_') ? '/^[ -}]+$/' : '/^[^\x00-\x1F\x7E-\xFF]+$/u';
                        if (!\preg_match($pattern, $key)) {
                            $fc = '-';
                        }
                    }
                    if ('-' === $fc) {
                        // Add "-" to the beginning of the key to indicate it's in base64url
                        $key = '-' . self::base64Uencode($key);
                    }
                } else {
                    $key = '-';
                }
                
            }

            // Add the appropriate number of colons to the left of the key, based on the current level
            $key = \str_repeat($lvl_ch, $level) . $key;
            
            // add space-ident to the left of the key (if need)
            if (self::$ENABLE_SPC_IDENT && ' ' === $spc_ch) {
                $key = \str_repeat($spc_ch, $level * self::$ENABLE_SPC_IDENT) . $key;
            }

            if (\is_array($value)) {
                // Add empty line before create-key
                if (self::$ENABLE_KEY_UPLINES && ' ' === $spc_ch) {
                    $results_arr[] = '';
                }

                $is_num_keys = self::isArrayList($value);
                if (!$is_num_keys) {
                    $key .= $lvl_ch;
                }

                // If the value is an array, call this function recursively and increase the level
                $results_arr[] = $key;
                self::_encode($value, $results_arr, $level + 1, $lvl_ch, $spc_ch, $is_num_keys);

                if (self::$ENABLE_KEY_UPLINES && ' ' === $spc_ch) {
                    $results_arr[] = \str_repeat($spc_ch, $level) . '#';
                }
            } else {
                // If the value is not an array, run it through a value encoding function, if one is specified
                $value = null === self::$CUSTOM_VALUE_ENCODER ? self::valueEncoder($value, $spc_ch) : \call_user_func(self::$CUSTOM_VALUE_ENCODER, $value);
                // Add the key:value pair to the output
                $results_arr[] = $key . $lvl_ch . $value;
            }
        }
    }

    /**
     * Decode a HELML-formatted string or array into an associative array
     * 
     * @param string|array $src_rows
     * @return array
     * @throws InvalidArgumentException
     */
    public static function decode($src_rows, $layers_list = [0]) {
        // If the input is an array, use it. Otherwise, split the input string into an array.
        $lvl_ch = ':';
        $spc_ch = ' ';
        
        $layer_init = 0;
        $layer_curr = $layer_init;
        $layers_list = \array_flip($layers_list); // move values into keys
        $all_layers = [$layer_init => 1];

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
        
        // Initialize result array and stack for keeping track of current array nesting
        $result = [];
        $stack = [];

        $min_level = -1;
        
        // Loop through each line in the input array
        $lines_cnt = \count($str_arr);
        for ($lnum = 0; $lnum < $lines_cnt; $lnum++) {
            $line = \trim($str_arr[$lnum]);

            // Skip empty lines and comment lines starting with '#'
            if (!\strlen($line) || \substr($line, 0, 1) === '#' || \substr($line, 0, 2) === '//') continue;

            // Calculate the level of nesting for the current line by counting the number of colons at the beginning
            $level = 0;
            while (\substr($line, $level, 1) === $lvl_ch) {
                $level++;
            }

            // If the line has colons at the beginning, remove them from the line
            if ($level) {
                $line = \substr($line, $level);
            }

            // Split the line into a key and a value (or null if the line starts a new array)
            $parts = \explode($lvl_ch, $line, 2);
            $key = $parts[0] ? $parts[0] : '0';
            $value = isset($parts[1]) ? $parts[1] : null;

            // check min_level
            if ($min_level < 0 || $min_level > $level) {
                $min_level = $level;
            }

            // Remove keys from the stack if level decreased
            $extra_keys_cnt = \count($stack) - $level + $min_level;
            if ($extra_keys_cnt > 0) {
                // removing extra keys from stack
                 while(\count($stack) && $extra_keys_cnt--) {
                     \array_pop($stack);
                 }
                $layer_curr = $layer_init;
            }

            // Find the parent element in the result array for the current key
            $parent = &$result;
            foreach ($stack as $parentKey) {
                $parent = &$parent[$parentKey];
            }
            
            // Decode the key if it starts with an equals sign
            if ('-' === \substr($key, 0, 1)) {
                if ($key === '--') {
                    // auto-create next numeric key
                    $key = \count($parent);
                } elseif ($key === '-+') {
                    $layer_curr = ($value  || $value === '0') ? $value : (\is_numeric($layer_curr) ? ($layer_curr + 1) : 0);
                    if (empty($all_layers[$layer_curr])) {
                        $all_layers[$layer_curr] = 1;
                    }
                    continue;
                } else {
                    $decoded_key = self::base64Udecode(\substr($key, 1));
                    if (false !== $decoded_key) {
                        $key = $decoded_key;
                    }
                }
            }

            // If the value is null, start a new array and add it to the parent array
            if (\is_null($value) || !\strlen($value)) {
                $parent[$key] = [];
                \array_push($stack, $key);
            } elseif (\array_key_exists($layer_curr, $layers_list)) {
                // multistring literal
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
                    // Use default valueDecoder or custom decoder function is specified
                    $value = \is_null(self::$CUSTOM_VALUE_DECODER) ? self::valueDecoder($value, $spc_ch) : \call_user_func(self::$CUSTOM_VALUE_DECODER, $value, $spc_ch);
                }
               
                if (self::$ENABLE_DBL_KEY_ARR && \array_key_exists($key, $parent)) {
                    // If DBL_KEY_ARR enabled and key already exist
                    if (\is_array($parent[$key])) {
                        $parent[$key][] = $value;
                    } else {
                        $parent[$key] = [$parent[$key], $value];
                    }
                } else {
                    // Add the key-value pair to the current array
                    $parent[$key] = $value;
                }
            }
        }
        
        if (\count($all_layers) > 1) {
            $result['_layers'] = \array_keys($all_layers);
        }

        // Return the result array
        return $result;
    }

    /**
     * Encode a value based on its type and add any necessary prefixes
     * 
     * @param string $value
     * @param string $spc_ch
     * @return any
     * @throws InvalidArgumentException
     */
    public static function valueEncoder($value, $spc_ch = ' ') {
        $type = \gettype($value);
        switch ($type) {
            case 'string':
                if (!\strlen($value)) {
                    return '-';
                }
                $fc = $value[0];
                $lc = \substr($value, -1);

                // try multi-string literal
                if (false !== \strpos($value, "\n") && $lc !== '`'  && \function_exists('mb_check_encoding')
                    && \mb_check_encoding($value, 'UTF-8') && !\preg_match("/`\x0d|`\x0a/", $value)) {
                        return "`\n" . $value . "\n`";
                }

                if (!\preg_match(($spc_ch === '_') ? '/^[ -}]+$/' : '/^[^\x00-\x1F\x7E-\xFF]+$/u', $value)) {
                    $fc = '-';
                }
                if ($fc === '-') {
                    return '-' . self::base64Uencode($value);
                } elseif ($spc_ch === $fc || ' ' === $fc ||  $spc_ch === $lc || \ctype_space($lc)) {
                    // for empty strings or those that have spaces at the beginning or end
                    return "'" . $value . "'";
                }
                // if the value is simple, just add one space at the beginning
                return $spc_ch . $value;

            case 'boolean':
                return $spc_ch . $spc_ch . ($value ? 'T' : 'F');

            case 'NULL':
                return $spc_ch . $spc_ch . 'N';

            case 'double':
            case 'float':
                if (\is_nan($value)) {
                    return $spc_ch . $spc_ch . 'NAN';
                } elseif (\is_infinite($value)) {
                    return $spc_ch . $spc_ch . ($value > 0 ? 'INF' : 'NIF');
                }
                if ('_' === $spc_ch) {
                    // for url-mode because dot-inside
                    return '-' . self::base64Uencode((string)$value);
                }
                // if not url mode, go below
            case 'integer':
                return $spc_ch . $spc_ch . $value;
            default:
                throw new \InvalidArgumentException("Cannot encode value of type $type");
        }
    }

    /**
     * Decode an encoded value based on its prefix
     * 
     * @param string $encodedValue
     * @param string $spc_ch
     * @return any
     */
    public static function valueDecoder($encodedValue, $spc_ch = ' ') {
        $first_char = \substr($encodedValue, 0, 1);
        if ($spc_ch === $first_char) {
            if (\substr($encodedValue, 0, 2) !== $spc_ch . $spc_ch) {
                // if the string starts with only one space, return the string after it
                return \substr($encodedValue, 1);
            }
            // if the string starts with two spaces, then it encodes a non-string value
            $slicedValue = \substr($encodedValue, 2); // strip left spaces
            if (\is_numeric($slicedValue)) {
                // it's probably a numeric value
                if (\strpos($slicedValue, '.')) {
                    // if there's a decimal point, it's a floating point number
                    return (double) $slicedValue;
                }
                // if there's no decimal point, it's an integer
                return (int) $slicedValue;
            } elseif (\array_key_exists($slicedValue, self::$SPEC_TYPE_VALUES)) {
                return self::$SPEC_TYPE_VALUES[$slicedValue];
            } elseif (self::$CUSTOM_FORMAT_DECODER) {
                return \call_user_func(self::$CUSTOM_FORMAT_DECODER, $encodedValue);
            }            
            return $encodedValue;
        } elseif ('"' === $first_char || "'" === $first_char) {
            $encodedValue = \substr($encodedValue, 1, -1); // trim the presumed quotes at the edges and return the interior
            if ("'" === $first_char) {
                return $encodedValue;
            }
            return \stripcslashes($encodedValue);
        } elseif ('-' === $first_char) {
            return self::base64Udecode(\substr($encodedValue, 1));
        }

        // if there are no spaces or quotes or "-" at the beginning
        if (self::$CUSTOM_FORMAT_DECODER) {
            return \call_user_func(self::$CUSTOM_FORMAT_DECODER, $encodedValue);
        }
        
        return self::base64Udecode($encodedValue);
    }

    /**
     * if the array is a list with keys 0,1,2..,
     * then returns the number of elements
     * otherwise returns 0
     * 
     * @param array $arr
     * @param int $expected_count
     * @return int
     */
    public static function isArrayList(&$arr, $expected_count = false)
    {
        if (!\array_key_exists(0, $arr))
            return 0;
        $el_count = \count($arr);
        if ($expected_count && ($el_count != $expected_count))
            return 0;
        for($i = 1; $i < $el_count; $i++) {
            if (!\array_key_exists($i, $arr))
                return 0;
        }
        return $el_count;
    }

    /**
     * Encode a string using the base64url encoding scheme
     * 
     * @param string $str
     * @return string
     */
    public static function base64Uencode($str) {
        $enc = \base64_encode($str);
        return \rtrim(\strtr($enc, '+/', '-_'), '=');
    }
    
    /**
     * Decode a base64url encoded string
     * 
     * @param string $str
     * @return string
     */
    public static function base64Udecode($str) {
        return \base64_decode(\strtr($str, '-_', '+/'));
    }
}
