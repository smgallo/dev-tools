#!/usr/bin/env php
<?php

/**
 * Query the GitHub API (https://developer.github.com/v3/) for a list of pull requests and prepare a
 * draft list of markdown bullet points for the XDMoD release notes, among other formats.  This can
 * also be used to generate a changelog for the RPM spec file and converted to HTML.
 *
 * The configuration file is expected to be in the following format and contains the user's GitHub
 * access token. All values except for the token can be overriden on the command line. The token can
 * be specified in the GITHUB_ACCESS_TOKEN environment variable, which takes precedence over the
 * value in the configuration file. Note that the token is only required for reporitories or actions
 * that require authentication.
 *
 * {
 *     "github": {
 *         "access_token": "put-your-token-here"
 *         "org": "ubccr",
 *         "repo": "xdmod"
 *     }
 * }
 */

require_once __DIR__ . '/vendor/autoload.php';

// Skip PRs with the following tags as these generally are not included in the release notes

$tagsToSkip = array(
    'autodoc:ignore',
    'maintenance / code quality'
);

// Mapping of tags to names that will be used in output

$tagToVisualMap = array(
    'bug' => "Bug Fixes",
    'enhancement' => "Enhancements",
    'new feature' => "New Features"
);

// Script options and defaults

$options = array(
    // Restrict pull requests to this branch (use the repo default if not specified)
    'branch' => null,
    // For RPM spec file changlog format, this is the description string
    'changelog-desc' => null,
    // Configuration file to read from
    'config-file' => __DIR__ . '/config.json',
    // Display the succinct PR number rather than the URL for each PR
    'pr-base-url' => null,
    // GitHub organization to query
    'github-org' => null,
    // GitHub repository to query
    'github-repo' => null,
    // Optionally include the first N lines of the PR description
    'include-desc' => false,
    // Query only PRs with this merge status
    'merge-status' => 'merged',
    // Output file
    'output-file' => 'php://stdout',
    // Desired output format
    'output-format' => 'user',
    // Display progress information?
    'quiet' => false,
    // Query only PRs in this state
    'state' => 'closed',
    // Filter PRs on this username
    'username' => null,
    // Display only PRs that do not contain all required metadata (categories, tags, etc.)
    'verify-metadata' => false
);

$cliOptions = array(
    'h'   => 'help',
    'b:'  => 'branch:',
    'c:'  => 'config-file:',
    'd::' => 'include-desc::',
    'f:'  => 'output-format:',
    'm:'  => 'merge-status:',
    'o:'  => 'output-file:',
    'O:'  => 'github-org:',
    'q'   => 'quiet',
    'p:'  => 'pr-base-url:',
    'r:'  => 'github-repo:',
    's:'  => 'changelog-desc:',
    'S:'  => 'state:',
    'u:'  => 'username:',
    'v'   => 'verify-metadata'
);

$args = getopt(implode('', array_keys($cliOptions)), $cliOptions);

foreach ($args as $arg => $value) {
    switch ($arg) {

        case 'b':
        case 'branch':
            $options['branch'] = $value;
            break;

        case 'c':
        case 'config-file':
            $options['config-file'] = $value;
            break;

        case 'f':
        case 'output-format':
            $options['output-format'] = $value;
            break;

        case 'd':
        case 'include-desc':
            $options['include-desc'] = ( empty($value) ? 5 : filter_var($value, FILTER_VALIDATE_INT) );
            break;
        case 'm':
        case 'merge-status':
            $options['merge-status'] = $value;
            break;

        case 'o':
        case 'output-file':
            $options['output-file'] = $value;
            break;

        case 'O':
        case 'github-org':
            $options['github-org'] = $value;
            break;

        case 'p':
        case 'pr-base-url':
            $options['pr-base-url'] = $value;
            break;

        case 'q':
        case 'quiet':
            $options['quiet'] = true;
            break;

        case 'r':
        case 'github-repo':
            $options['github-repo'] = $value;
            break;

        case 'S':
        case 'state':
            $options['state'] = $value;
            break;

        case 's':
        case 'changelog-desc':
            $options['changelog-desc'] = $value;
            break;

        case 'u':
        case 'username':
            $options['username'] = $value;
            break;

        case 'v':
        case 'verify-metadata':
            $options['verify-metadata'] = true;
            break;
        case 'h':
        case 'help':
            usage_and_exit();
            break;

    }
}

if ( ! is_file($options['config-file']) ) {
    usage_and_exit(sprintf("Config file not found: '%s'", $options['config-file']));
}


if ( 'changelog' == $options['output-format'] && ! isset($options['changelog-desc']) ) {
    usage_and_exit("A description must be provided when writing a changelog for an RPM specfile");
}

logger(sprintf("Reading config file '%s'", $options['config-file']));

$config = json_decode(file_get_contents($options['config-file']), true);
if ( isset($config['github']['org']) && null === $options['github-org'] ) {
    $options['github-org'] = $config['github']['org'];
}


if ( isset($config['github']['repo']) && null === $options['github-repo'] ) {
    $options['github-repo'] = $config['github']['repo'];
}

logger(sprintf("GitHub organization: '%s'", $options['github-org']));
logger(sprintf("GitHub repository: '%s'", $options['github-repo']));

$client = new Github\Client();

if ( isset($config['github']['access_token']) ) {
    logger("Authenticating to GitHub");
    $token = null;
    if ( false !== ($token = getenv('GITHUB_ACCESS_TOKEN')) ) {
        logger("Using access token from GITHUB_ACCESS_TOKEN environment variable");
    } else {
        $token = $config['github']['access_token'];
    }
    $client->authenticate($token, Github\Client::AUTH_HTTP_TOKEN);
}

if ( null === $options['branch'] ) {
    $repoInfo = $client->api('repo')->show($options['github-org'], $options['github-repo']);
    $options['branch'] = $repoInfo['default_branch'];
}

$paginator = new Github\ResultPager($client);

// Fetch the list of labels and descriptions. Category labels are capitalized while tags are
// lowercase. Note that it should be possible to get the label description but we need to add
// this header to the request:
// Accept: application/vnd.github.symmetra-preview+json

$categories = array();
$tags = array();

$labels = $client->api('issue')->labels()->all($options['github-org'], $options['github-repo']);
foreach ( $labels as $label ) {
    $labelName = $label['name'];
    $first = substr($labelName, 0, 1);
    if ( $first == strtoupper($first) ) {
        $categories[] = ( 0 === strpos($labelName, 'Category:') ? substr($labelName, 9) : $labelName );
    } else {
        $tags[] = $labelName;
    }
}
sort($categories);
sort($tags);

logger(sprintf("Found %d categories", count($categories)));
logger(sprintf("Found %d tags", count($tags)));

// Get the initial set of pull requests. GitHub limits these to 30 by default.

$parameters = array();
if ( isset($options['state']) ) {
    $parameters['state'] = $options['state'];
    logger(sprintf("Searching for PRs in '%s' state", $options['state']));
}
if ( isset($options['branch']) ) {
    $parameters['base'] = $options['branch'];
    logger(sprintf("Searching branch '%s'", $options['branch']));
}

$pullRequests = $paginator->fetch(
    $client->api('pull_request'),
    'all',
    array(
        $options['github-org'],
        $options['github-repo'],
        $parameters
    )
);

if ( null !== $options['username'] ) {
    logger(sprintf("Searching username '%s'", $options['username']));
}

if ( 'merged' == $options['merge-status'] ) {
    logger("Searching merged pull requests only");
}

$prSummaryList = array();
$count = 0;

while ( FALSE !== ($pr = current($pullRequests)) ) {

    // Extract categories and tags

    $prLabelNames = array_map(
        function($a) {
            return ( 0 === strpos($a['name'], 'Category:') ? substr($a['name'], 9) : $a['name'] );
            return $a['name'];
        },
        $pr['labels']
    );

    $categoryList = array_filter(
        $prLabelNames,
        function($a) use ($categories) {
            return in_array($a, $categories);
        }
    );
    $tagList = array_filter(
        $prLabelNames,
        function($a) use ($tags) {
            return in_array($a, $tags);
        }
    );

    if ( 0 == count($categoryList) ) {
        $categoryList[] = "General";
    }

    // Perform filtering

    if (
        ( null !== $options['username'] && $options['username'] != $pr['user']['login'] ) ||
        ( 'merged' == $options['merge-status'] && empty($pr['merged_at']) ) ||
        count(array_intersect($tagsToSkip, $prLabelNames)) > 0 ||
        ( $options['verify-metadata'] && count($tagList) > 0 )
    ) {
        if ( FALSE === ($pr = next($pullRequests)) && $paginator->hasNext() ) {
            $pullRequests = $paginator->fetchNext();
        }
        continue;
    }

    $prSummaryList[] = array(
        'url' => $pr['url'],
        'number' => $pr['number'],
        'title' => $pr['title'],
        'user' => $pr['user']['login'],
        'milestone' => $pr['milestone']['title'],
        'category' => array_values($categoryList),
        'tags' => array_values($tagList),
        'body' => (
            false !== $options['include-desc'] && ! empty($pr['body'])
            ? implode(" ", array_slice(explode("\n", $pr['body']), 0, 9))
            : null
        )
    );
    $count++;

    if ( FALSE === ($pr = next($pullRequests)) && $paginator->hasNext() ) {
        $pullRequests = $paginator->fetchNext();
    }
}

logger(sprintf("Found %d pull requests", $count));
logger(sprintf("Writing output to %s", $options['output-file']));

$outFd = fopen($options['output-file'], 'w');
if ( false == $outFd ) {
    usage_and_exit(sprintf("Error opening output file '%s'", $options['output-file']));
}

switch ( $options['output-format'] ) {
    case 'category':
        summary_by_category($outFd, $prSummaryList);
        break;
    case 'relnotes':
        summary_for_relnotes($outFd, $prSummaryList);
        break;
    case 'xdmod-relnotes':
        summary_for_relnotes(
            $outFd,
            $prSummaryList,
            array(
                'tag'      => '###',
                'category' => '-',
                'title'    => '    -',
                'body'     => '        -',
                'category-spacer' => ''
            )
        );
        break;
    case 'changelog':
        // summary_for_specfile($outFd, $prSummaryList);
        summary_for_relnotes(
            $outFd,
            $prSummaryList,
            array(
                'tag'      => '-',
                'category' => '    -',
                'title'    => '        -',
                'body'     => '            -',
                'tag-spacer'      => '',
                'category-spacer' => ''
            )
        );
        break;
    default:
    case 'user':
        summary_by_user($outFd, $prSummaryList);
        break;
}

fclose($outFd);

exit(0);

/**
 * Display help text with an optional message
 *
 * @param string|null $message Optional message to display with the help.
 */

function usage_and_exit($message = null)
{
    global $argv, $options;

    if ($message !== null) {
        fwrite(STDERR, sprintf("\nERROR: %s\n\n", $message));
    }

    fwrite(
        STDERR,
        <<<"EOMSG"
Usage: {$argv[0]}

Query the GitHub API to provide details about pull requests related to a particular project
and display the information in a variety of formats.

    -h, --help
    Display this help

    -b, --branch
    Restrict the search to this branch. If the branch is not specified, the default
    branch for the repository will be used.

    -c, --config-file (default: {$options['config-file']})
    Configuration file for GitHub API key, organization, and repository names.

    -d, --include-desc (default: {$options['include-desc']})
    Include the first N lines of the PR description if supported by the output format. Useful
    when reviewing the release notes and easy to remove from the final product.

    -f, --output-format (default: {$options['output-format']})
    Output format. Valid values are:
        user - Generate a PR summary grouped by GitHub user
        category - Generate a PR summary grouped by category
        relnotes - Generate release notes in markdown format
        xdmod-relnotes - XDMoD-specific formatting for release notes
        changelog - Generate a changelog suitable for inclusion in an RPM spec file

    -m, --merge-status (default: {$options['merge-status']})
    Only consider pull requests with this merge status. Supported status are merged, unmerged, both.

    -o, --output-file (default: {$options['output-file']})
    File to store the output.

    -O, --github-org (default: {$options['github-org']})
    GitHub organization to query. This value can be specified in the configurationfile or command line.

    -p, --pr-base-url
    Rather than displaying the succinct PR number, display the URL to the GitHub PR page using this base
    URL and the PR number.

    -q, --quiet
    Do not display progress information, useful when piping output to another program.

    -r, --github-repo (default: {$options['github-repo']})
    GitHub repository to query. This value can be specified in the configurationfile or command line.

    -S, --state (default: {$options['state']})
    Query only pull requests in this state. Supported states are open, closed, all.

    -s, --changelog-desc
    Specify a description for the changelog entry in the RPM specfile. This is required
    when specifying an output format of 'changelog'. For example:
    Tue Oct 30 2018 Joe User <joe.user@myinstitution.edu> 8.0.0-1.0

    -u, --username (default: {$options['username']})
    Only consider pull requests from the specified username

    -v, --verify-metadata
    Display only PRs that do not contain the required metadata

Examples:

Verify that closed pull requests for the xdmod8.1 branch contain proper metadata and write
only requests that do not to a file:

list-pull-requests.php -b xdmod8.1 -v -o prs_missing_tags.txt

Generate a markdown version of release notes for branch xdmod8.1:

list-pull-requests.php -b xdmod8.1 -f relnotes -o xdmod_release_notes.md

Generate an HTML version of release notes for only PRs by user "smgallo":

list-pull-requests.php -b xdmod8.1 -u smgallo -f relnotes | pandoc -o smgallo_relnotes.html

EOMSG
    );
    exit(1);
}

/**
 * Log progress information
 */

function logger($message)
{
    global $options;
    if ( $options['quiet'] ) {
        return;
    }
    print $message . PHP_EOL;
}

/**
 * Display a summary of PRs per user
 *
 * @param resource $outFd Output filehandle
 * @param array $prSummaryList List of PRs
 */

function summary_by_user($outFd, $prSummaryList)
{
    $userSummary = array_reduce(
        $prSummaryList,
        function($carry, $item) {
            $carry[$item['user']][] = $item;
            return $carry;
        },
        array()
    );

    foreach ( $userSummary as $user => $prList ) {
        fwrite($outFd, sprintf("----------------------------------------\nUser: %s\n\n", $user));
        foreach ( $prList as $pr ) {
            fwrite(
                $outFd,
                sprintf(
                    "url: %s\ntitle: %s\nmilestone: %s\ncategory: %s\ntags: %s\n",
                    $pr['url'],
                    $pr['title'],
                    ( empty($pr['milestone']) ? "**MISSING**" : $pr['milestone'] ),
                    ( 0 == count($pr['category']) ? "**MISSING**" : implode(', ', $pr['category']) ),
                    ( 0 == count($pr['tags']) ? "**MISSING**" : implode(', ', $pr['tags']) )
                ) . PHP_EOL
            );
        }
    }
}

/**
 * Display a summary of PRs per category. If multiple categories are found, use the first.
 *
 * @param resource $outFd Output filehandle
 * @param array $prSummaryList List of PRs
 */

function summary_by_category($outFd, $prSummaryList)
{
    $orderBy = 'category';

    // Select the first category if there are multiple

    $summary = array_reduce(
        $prSummaryList,
        function($carry, $item) use ($orderBy) {
            $carry[$item[$orderBy][0]][] = $item;
            return $carry;
        },
        array()
    );

    foreach ( $summary as $order => $prList ) {
        fwrite($outFd, sprintf("----------------------------------------\nCategory: %s\n\n", $order));
        foreach ( $prList as $pr ) {
            fwrite($outFd,
                sprintf(
                    "url: %s\ntitle: %s\ntags: %s\n",
                    $pr['url'],
                    $pr['title'],
                    ( 0 == count($pr['tags']) ? "**MISSING**" : implode(', ', $pr['tags']) )
                ) . PHP_EOL
            );
        }
    }
}

/**
 * Display a markdown summary of PRs suitable for inclusion in release notes. The format can be
 * controlled by using the $prefixOverrides array.
 *
 * @param resource $outFd Output filehandle
 * @param array $prSummaryList List of PRs
 * @param array $prefixOverrides Prefixes to use for various parts of the release notes
 */

function summary_for_relnotes($outFd, $prSummaryList, array $prefixOverrides = array())
{
    global $tagToVisualMap, $options;

    // Default prefixes to use when writing the release notes

    $prefixes = array(
        'tag'      => '#',
        'category' => '##',
        'title'    => '-',
        'body'     => '    -',
        'tag-spacer'      => "\n",
        'category-spacer' => "\n"
    );

    // Apply any overrides

    foreach ( $prefixOverrides as $key => $value ) {
        if ( array_key_exists($key, $prefixes) ) {
            $prefixes[$key] = $value;
        }
    }

    // New Features, Enhancements, Bug Fixes
    $orderBy = 'category';

    // Select the first category if there are multiple

    $summary = array_reduce(
        $prSummaryList,
        function($carry, $item) use ($orderBy) {
            $category = $item['category'][0];
            $tag = ( 0 == count($item['tags']) ? "Uncategorized" : $item['tags'][0] );
            $carry[$tag][$category][] = $item;
            return $carry;
        },
        array()
    );

    if ( 'changelog' == $options['output-format'] ) {
        fwrite($outFd, sprintf("* %s\n", $options['changelog-desc']));
    }

    foreach ( $summary as $tag => $categoryList ) {
        fwrite(
            $outFd,
            sprintf("%s %s\n", $prefixes['tag'], ( array_key_exists($tag, $tagToVisualMap) ? $tagToVisualMap[$tag] : ucwords($tag) ))
        );
        fwrite($outFd, $prefixes['tag-spacer']);
        foreach ( $categoryList as $category => $prList ) {
            fwrite($outFd, sprintf("%s %s\n", $prefixes['category'], $category));
            fwrite($outFd, $prefixes['category-spacer']);
            foreach ( $prList as $pr ) {
                if ( null === $options['pr-base-url'] ) {
                    fwrite($outFd, sprintf("%s %s (PR #%d)\n", $prefixes['title'], $pr['title'], $pr['number']));
                } else {
                    fwrite($outFd, sprintf("%s %s (%s/%d)\n", $prefixes['title'], $pr['title'], $options['pr-base-url'], $pr['number']));
                }
                if ( null !== $pr['body'] ) {
                    fwrite($outFd, sprintf("%s %s\n", $prefixes['body'], $pr['body']));
                }
            }
            fwrite($outFd, $prefixes['tag-spacer']);
        }
    }
}
