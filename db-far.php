#!/usr/bin/php
<?php
/**
 * PHP script that perform a find and replace in a database dump (tested with
 * MySQL) with adjustments of the PHP serialize founded.
 *
 * Don't forget to escape your "special characters":
 *   "a$b" -> "a\$b"
 *   "a"b" -> "a\"b"
 *   "a`b" -> "a\`b"
 *   "a!b" -> "a"'!'"b" or 'a!b'
 *
 * Usage:
 *   $ db-far [options] [search] [replace] [file]
 *
 * Example:
 *   Domain replacement with a backup file "dump.sql.old".
 *   $ db-far --backup-ext=".old" "http://old.domain.ext" "http://new.domain.ext" backup-dumps/dump.sql
 *
 *   String replacement with quotes and exclamation mark.
 *   $ db-far "My \"special\" string" "My awesome string"'!' backup-dumps/dump.sql
 */

ini_set('memory_limit','2048M');

// Options.
$options = array(
    'backup-ext' => array(
        'help'  => 'The extension of the backup file that will be produce.',
        'type'  => 'string',
        'value' => '.bak',
    ),
    'encoding' => array(
        'help'  => 'Encoding with which length of string are calculated for PHP serialize conversion. You can find the complete list at this URL: http://www.php.net/manual/en/mbstring.supported-encodings.php',
        'type'  => 'string',
        'value' => 'UTF-8',
    ),
    'source-type' => array(
        'help'  => '(backslashed | raw) Use "backslashed" for replacements of PHP serialized that contain backslashed double quotes to delimit strings, ex: s:5:\\"hello\\"; Commonly founded in MySQL dump. Use "raw" for replacements in raw PHP serialized, ex: s:5:"hello";',
        'type'  => 'string',
        'value' => 'backslashed',
    ),
    'preview' => array(
        'help'  => 'Like "verbose" but without executing the replacement.',
        'type'  => 'boolean',
        'value' => false,
    ),
    'verbose' => array(
        'help'  => 'Show the different options and arguments.',
        'type'  => 'boolean',
        'value' => false,
    ),
);

// Return the value of an option formatted to be print.
function format_option_value($option) {
    switch ($option['type']) {
        case 'boolean':
            return ($option['value']?'true':'false');
            break;
        default:
            if (strstr($option['value'], ' ') === false) {
                return $option['value'];
            }
            else{
                return '"'.$option['value'].'"';
            }
            break;
    }
}

// "echo" in Terminal and add "new line" when 80 caracters is reach.
function e($txt = '', $indentation = 0) {
    $max_length = 80;
    $indentation = $indentation*4;
    while ((mb_strlen($txt, 'UTF-8')+$indentation) > $max_length) {
        $line = substr($txt, 0, $max_length);
        $pos_space = strrpos($line, ' ');
        if ($pos_space === false) {
            $pos_space = $max_length;
        }
        echo str_repeat(' ', $indentation).trim(substr($line, 0, $pos_space)).PHP_EOL;
        $txt = substr($txt, $pos_space+1);
    }
    if (mb_strlen(trim($txt), 'UTF-8') > 0) {
        echo str_repeat(' ', $indentation).trim($txt).PHP_EOL;
    }
}

function show_help() {
    global $options;
    e('Usage');
    e('db-far [options] [search] [replace] [file]', 1);
    e();
    e("Options");
    foreach ($options as $key => $option) {
        e("--".$key." (".$option['type']."), default: --".$key."=".format_option_value($option), 1);
        e($option['help'], 2);
    }
}

// Delete the first argument (the command).
array_shift($argv);
// Arguments (contain raw options + arguments at this time).
$arguments = $argv;
// For each argument found in the command.
for ($k=0;$k<count($argv);$k++) {
    // If the command arg is an option.
    if (preg_match('/^--([^=]+)=(.*)$/', $argv[$k], $matches)) {
        // If the option is not valid.
        if (!array_key_exists($matches[1], $options)) {
            die('Invalid option: "'.$matches[1].'".');
        }
        else{
            // Override the option.
            switch ($options[$matches[1]]['type']) {
                case 'boolean':
                    $options[$matches[1]]['value'] = (strtolower($matches[2]) == 'true');
                    break;
                default:
                    $options[$matches[1]]['value'] = $matches[2];
                    break;
            }
            // Delete this "option" entry from the "arguments" array.
            array_shift($arguments);
        }
    }
    // No more options, the rest are arguments.
    else{
        break;
    }
}

// Check if encoding is supported.
$supported_encodings = mb_list_encodings();
if (!in_array($options['encoding']['value'], $supported_encodings)) {
    die('The encoding is not supported. See this page: http://www.php.net/manual/en/mbstring.supported-encodings.php');
}

// If the count of arguments is incorrect.
if (count($arguments) != 3) {
    show_help();exit;
}

// Arguments.
$search     = $arguments[0];
$replace    = $arguments[1];
$file       = $arguments[2];

// If a "backup-ext" option is provided, do a backup.
if (
    empty($options['backup-ext']['value'])
    || (!empty($options['backup-ext']['value']) && copy($file, $file.$options['backup-ext']['value']))
) {
    // If option "preview" is set to "false".
    if ($options['preview']['value'] === false) {
        // Database
        $new_dump_sql = str_replace($search, $replace, file_get_contents($file));
        // Correcting of lenght of string in PHP serialized
        if ($options['source-type']['value'] == 'raw') {
            $pattern = '/(s:)([0-9]*)(:\\")([^"]*'.str_replace('/', '\/', preg_quote($replace)).'[^"]*)(\\")/';
        } else {
            $pattern = '/(s:)([0-9]*)(:\\\\")([^"]*'.str_replace('/', '\/', preg_quote($replace)).'((?!\\\\\\").)*)(\\\\")/';
        }

        $new_dump_sql = preg_replace_callback($pattern, function ($m){
            global $options;
            if ($options['source-type']['value'] == 'raw') {
                return($m[1].mb_strlen($m[4], $options['encoding']['value']).$m[3].$m[4].$m[5]);
            } else {
                return($m[1].mb_strlen($m[4], $options['encoding']['value']).$m[3].$m[4].$m[6]);
            }
        }, $new_dump_sql);
        file_put_contents($file, $new_dump_sql);
    }
}
else {
    die('The backup file could not be created. Replacement aborted.');
}

// If we have to show verbose.
if ($options['preview']['value'] || $options['verbose']['value']) {
    e("Options");
    foreach ($options as $key => $option) {
        e(str_pad($key, 12, ' ')."= ".format_option_value($option), 1);
    }
    e("Arguments");
    e(str_pad("search", 12, ' ')."= ".$search, 1);
    e(str_pad("replace", 12, ' ')."= ".$replace, 1);
    e(str_pad("file", 12, ' ')."= ".$file, 1);
}
?>
