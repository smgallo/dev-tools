
# Source Code Discovery Tools

## create-call-map.php

Use the ctags file to discover function definitions in a git repository. For each of thse
functions, use `git grep` to find all instances where this function is called. Note that this
does not mean that each of those calls is for the function definition in questions as multiple
functions with the same name may produce false positives. **Note that `git grep` searches relative
to the current directory. If this is called in a subdirectory it will not search parent
directories.** This is important, especially when identifying functions that have not been called.

Example ctags command line:
```
ctags -R -o php.tags --exclude=.git --exclude=vendor --exclude=.diff --exclude=logs --fields=+KSn --languages=php
```

The configuration file supports the `.ini` format with tags and values. Each tag is expected to have
the same name as the long option. Options that support multiple specification on the command line
are supported by appending the tags with `[]`. **Note that options specified on the command line
override those in the configuration file.**

For example:
```
; Path to search for definitions
source-path = 'classes/DataWarehouse/Query'

; Functions called from these files will be excluded
exclude-def-pattern[] = '/^__construct$/'
exclude-def-pattern[] = '/^__toString$/'
exclude-def-pattern[] = '/^addTable$/'

```

Examples:
```
php create-call-map.php -c create-call-map.config -t php.tags -s classes/User/Elements/QueryDescripter
```

Two files are generated: (1) a call map containing the function definition and all instances found in
the code base and (2) a file containing functions that were not called. Note that the list of
functions not called will be sensitive to the execution directory since `git grep` searches relative
to the current directory.

Example list of function call map:

```
function: addFilter()
definition: classes/DataWarehouse/Query/Query.php() line:1294
    classes/DataWarehouse/Query/Query.php
        933: $f  = $this->addFilter($selectedDimensionId);
    classes/DataWarehouse/Query/Timeseries.php
        143: $f = $this->addFilter($selectedDimensionId);

function: addParameters()
definition: classes/DataWarehouse/Query/Query.php() line:844
    classes/DataWarehouse/Query/Query.php
        1086: $this->addParameters($group_by_instance->pullQueryParameters($param));
        1225: $this->addParameters($group_by_instance->pullQueryParameters($param));

function: addStat()
definition: classes/DataWarehouse/Query/Query.php() line:1300
    classes/DataWarehouse/Access/MetricExplorer.php
        256: $query->addStat($data_description->metric);
        260: $query->addStat('sem_'.$data_description->metric);
```

Example list of functions not called:
```
function: addPdoWhereCondition()
definition: classes/DataWarehouse/Query/Query.php() line:472

function: buildQueriesFromDescripters()
definition: classes/DataWarehouse/QueryBuilder.php() line:112

function: configureForChart()
definition: classes/DataWarehouse/Query/Query.php() line:918
definition: classes/DataWarehouse/Query/Timeseries.php() line:110
```

```
Usage: create-call-map.php

Examine the specified ctags file for function definitions and use "git grep" to determine locations
where those function names are called. Note that a simple string match is used when determining
potential function calls so multiple functions with the same name may result in false positives.

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

    -s, --source-path <STRING>
    Files to be (recursively) examined must start with this string. May be used multiple times.

    -t, --tags-file <FILE>
    Path to the ctags file used to determine function definitions.

    -x, --exclude-pattern
    Functions found in files matching this regex will be excluded when identifying function
    references. May be used multiple times.
```
