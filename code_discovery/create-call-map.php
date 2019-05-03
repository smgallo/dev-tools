<?php

/**
 * Use the ctags file to discover function definitions in a git repository. For each of thse
 * functions, use "git grep" to find all instances where this function is called. Note that this
 * does not mean that each of those calls is for the function definition in questions as multiple
 * functions with the same name may produce false positives.
 *
 * Example ctags command line:
 *
 * ctags -R -o php.tags --exclude=.git --exclude=vendor --exclude=.diff --exclude=logs --fields=+KSn --languages=php
 */

$options = array(
    'config-file' => null,
    'exclude-def-pattern' => array(),
    'exclude-self-references' => false,
    'callmap-file' => 'callmap.out',
    'definitions-only' => false,
    'not-called-file' => 'not-called.out',
    'quiet' => false,
    'source-path-prefix' => array(),
    'tags-file' => '.tags',
    'exclude-ref-pattern' => array()
);

// Used to determine if the default options were changed when processing a config file
$defaultOptions = $options;

$cliOptions = array(
    'c:' => 'config-file:',
    'e:' => 'exclude-def-pattern:',
    'd'  => 'definitions-only',
    'h'  => 'help',
    'm:' => 'callmap-file:',
    'n:' => 'not-called-file:',
    'q'  => 'quiet',
    'r'  => 'exclude-self-references',
    's:' => 'source-path-prefix:',
    't:' => 'tags-file:',
    'x:' => 'exclude-ref-pattern'
);

$args = getopt(implode('', array_keys($cliOptions)), $cliOptions);

foreach ($args as $arg => $value) {
    switch ($arg) {

        case 'h':
        case 'help':
            usage_and_exit();
            break;

        case 'c':
        case 'config-file':
            $options['config-file'] = $value;
            break;

        case 'e':
        case 'exclude-def-pattern':
            // Merge array because long and short options are grouped separately
            $options['exclude-def-pattern'] = array_merge(
                $options['exclude-def-pattern'],
                ( is_array($value) ? $value : array($value) )
            );
            break;

        case 'd':
        case 'definitions-only':
            $options['definitions-only'] = true;
            break;

        case 'm':
        case 'callmap-file':
            $options['callmap-file'] = $value;
            break;

        case 'n':
        case 'not-called-file':
            $options['not-called-file'] = $value;
            break;

        case 'q':
        case 'quiet':
            $options['quiet'] = true;
            break;

        case 'r':
        case 'exclude-self-references':
            $options['exclude-self-references'] = true;
            break;

        case 's':
        case 'source-path-prefix':
            // Merge array because long and short options are grouped separately
            $options['source-path-prefix'] = array_merge(
                $options['source-path-prefix'],
                ( is_array($value) ? $value : array($value) )
            );
            break;

        case 't':
        case 'tags-file':
            $options['tags-file'] = $value;
            break;

        case 'x':
        case 'exclude-pattern':
            // Merge array because long and short options are grouped separately
            $scriptOptions['exclude-pattern'] = array_merge(
                $scriptOptions['exclude-pattern'],
                ( is_array($value) ? $value : array($value) )
            );
            break;
    }
}

// Process config file, if provided. Command line options override config file options.

if ( null !== $options['config-file'] ) {
    $configData = parse_ini_file($options['config-file']);
    if ( false === $configData ) {
        usage_and_exit(sprintf("Unable to parse ini file %s", $options['config-file']));
    }
    foreach ( $configData as $key => $value ) {
        if ( array_key_exists($key, $options) && $options[$key] == $defaultOptions[$key]) {
            $options[$key] = $value;
        }
    }
}

if ( ! is_readable($options['tags-file']) ) {
    usage_and_exit(sprintf("Unable to read tags file: %s", $options['tags-file']));
}

$definedFunctions = array();
$inFd = fopen($options['tags-file'], 'r');

// Construct the list of function definitions

while ( ! feof($inFd) ) {
    // The format of the ctags file is below where {address} is an Ex command
    // {name}<Tab>{file}<Tab>{address};"any additional text
    // The Ex command can contain tabs. For example:
    // getScale  classes/GroupBy.php /^  public function getScale()$/;"    function    line:340
    //
    $parts = explode("\t", fgets($inFd));

    if ( count($parts) < 4 ) {
        // Skip lines with no location information
        continue;
    }

    $name = array_shift($parts);
    $file = array_shift($parts);
    // Account for potential tabs in the Ex command as these are taken from the file and if tabs and
    // not spaces were used they will be present.
    $line = rtrim(substr(array_pop($parts), 5));
    $type = array_pop($parts);

    if ( 'function' != $type ) {
        continue;
    }

    $skip = true;
    foreach ( $options['source-path-prefix'] as $prefix ) {
        if ( 0 === strpos($file, $prefix) ) {
            $skip = false;
            break;
        }
    }
    if ( $skip ) {
        continue;
    }

    $found = false;
    foreach ( $options['exclude-def-pattern'] as $exclude ) {
        if ( 1 == preg_match($exclude, $name) ) {
            $found = true;
            break;
        }
    }
    if ( $found ) {
        continue;
    }

    if ( ! array_key_exists($name, $definedFunctions) ) {
        $definedFunctions[ $name ] = array(
            'name'   => $name,
            'matches' => array(),
            'definitions' => array(
                array(
                    'line' => $line,
                    'file' => $file
                )
            )
        );
    } else {
        $definedFunctions[ $name ]['definitions'][] = array(
            'line' => $line,
            'file' => $file
        );
    }

}

fclose($inFd);

if ( ! $options['definitions-only'] ) {

    // The same function may have been found/defined in multiple files. git grep will show us all
    // instances where that function is in the code but we won't know which instance it is calling.
    // Only call git grep once per function name.

    $count=0;
    $processedFunctions = array();

    foreach ( $definedFunctions as $name => &$function ) {
        if ( ! $options['quiet'] ) {
            fwrite(STDOUT, sprintf("Processing %s\n", $name)); 
        }
        if ( in_array($name, $processedFunctions) ) {
            continue;
        }
        $pipe = popen( sprintf('git grep -n "%s(" | grep -v function', $name), 'r' );
        while ( ! feof($pipe) ) {
            $match = trim(fgets($pipe));
            if ( 0 == strlen($match) ) {
                continue;
            }
            $parts = explode(':', $match);
            $file = array_shift($parts);
            $line = array_shift($parts);
            $code = trim(implode(':', $parts));

            $skip = false;
            foreach ( $options['exclude-ref-pattern'] as $exclude ) {
                if ( 1 === preg_match($exclude, $file) ) {
                    $skip = true;
                    break;
                }
            }

            if (
                $skip ||
                0 === strpos(trim($code), '*') ||
                0 === strpos(trim($code), '//') ||
                false !== strpos($code, sprintf('// %s', $name))
            ) {
                continue;
            }

            $function['matches'][$file][] = array(
                'line' => $line,
                'code' => $code
            );
        }
        pclose($pipe);
        $processedFunctions[] = $name;
    }
    unset($function);
}

// Generate the report

$callMapFd = fopen($options['callmap-file'], 'w');
$notCalledFd = fopen($options['not-called-file'], 'w');

foreach ( $definedFunctions as $name => $functionInfo ) {
    $numMatches = count($functionInfo['matches']);
    $outFd = ( ! $options['definitions-only'] && 0 == $numMatches ? $notCalledFd : $callMapFd );
    fwrite($outFd, sprintf("function: %s()\n", $name));

    sort($functionInfo['definitions']);
    foreach ( $functionInfo['definitions'] as $def ) {
        fwrite($outFd, sprintf("definition: %s::%s() line:%d\n", $def['file'], $name, $def['line']));
    }

    foreach ( $functionInfo['matches'] as $file => $matches ) {
        if ( $options['exclude-self-references'] && $file == $def['file'] ) {
            continue;
        }
        fwrite($callMapFd, sprintf("    %s\n", $file));
        foreach ( $matches as $match ) {
            fwrite($callMapFd, sprintf("        %d: %s\n", $match['line'], $match['code']));
        }
    }
    fwrite($outFd, "\n");
}

fclose($callMapFd);
fclose($notCalledFd);

/** -----------------------------------------------------------------------------------------
 * Display usage text and exit with error status.
 * ------------------------------------------------------------------------------------------
 */

function usage_and_exit($msg = null)
{
    global $argv, $options;

    if ($msg !== null) {
        fwrite(STDERR, "\n$msg\n\n");
    }

    fwrite(
        STDERR,
        <<<"EOMSG"
Usage: {$argv[0]}

Examine the specified ctags file for function definitions and use "git grep" to determine locations
where those function names are called. Note that a simple string match is used when determining
potential function calls so multiple functions with the same name may result in false positives.

Example ctags command line:

ctags -R -o php.tags --exclude=.git --exclude=vendor --exclude=.diff --exclude=logs --fields=+KSn --languages=php

    -h, --help
    Display this help

    -c, --config-file
    Configuration file in .ini format containing options matching the long version of an argument.
    Multiple options are specified using the array syntax (e.g., exclude-function[]).

    -e, --exclude-function <REGEX>
    Regular expression identifying functions to be excluded from the analysis. May be used multiple
    times.

    -d, --definitions-only
    Show only function definitions, not locations where those functions are potentially called.

    -m, --cellmap-file <FILE>
    Name of the file where the call map will be written.

    -n, --not-called-map <FILE>
    Name of the file where the list of functions that were not called at least once will be written.

    -q, --quiet
    Do not display the list of functions as they are processed.

    -r, --exclude-self-references
    Do not report references to a function in the same file as the definition. This is useful for
    determining when a function or method is called outside of a class definition.

    -s, --source-path-prefix <STRING>
    Files to be (recursively) examined must start with this string. May be used multiple times.

    -t, --tags-file <FILE>
    Path to the ctags file used to determine function definitions.

    -x, --exclude-pattern
    Functions found in files matching this regex will be excluded when identifying function
    references. May be used multiple times.


EOMSG
    );

    exit(1);
}
