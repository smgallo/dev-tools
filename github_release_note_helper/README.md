# GitHub Release Notes Helper

Generating release notes is a tedious task. This tool assists in this task by query the pull
requests for a particular repo in GitHub and generate a list in various formats to assist in
generating release notes.  This tool requires a GitHub personal access token.

Supported options are:
```
Usage: ./list-pull-requests.php

Query the GitHub API to provide details about pull requests related to a particular project
and display the information in a variety of formats.

    -h, --help
    Display this help

    -b, --branch
    Restrict the search to this branch. If the branch is not specified, the default
    branch for the repository will be used.

    -c, --config-file (default: /Users/smgallo/src/ccr-private-xdmod/scripts/github_release_notes/config.json)
    Configuration file for GitHub API key, organization, and repository names.

    -d, --include-desc (default: )
    Include the first N lines of the PR description if supported by the output format. Useful
    when reviewing the release notes and easy to remove from the final product.

    -f, --output-format (default: user)
    Output format. Valid values are:
        user - Generate a PR summary grouped by GitHub user
        category - Generate a PR summary grouped by category
        relnotes - Generate release notes in markdown format
        xdmod-relnotes - XDMoD-specific formatting for release notes
        changelog - Generate a changelog suitable for inclusion in an RPM spec file

    -m, --merge-status (default: merged)
    Only consider pull requests with this merge status. Supported status are merged, unmerged, both.

    -o, --output-file (default: php://stdout)
    File to store the output.

    -O, --github-org (default: )
    GitHub organization to query. This value can be specified in the configurationfile or command line.

    -q, --quiet
    Do not display progress information, useful when piping output to another program.

    -r, --github-repo (default: )
    GitHub repository to query. This value can be specified in the configurationfile or command line.

    -S, --state (default: closed)
    Query only pull requests in this state. Supported states are open, closed, all.

    -s, --changelog-desc
    Specify a description for the changelog entry in the RPM specfile. This is required
    when specifying an output format of 'changelog'. For example:
    Tue Oct 30 2018 Joe User <joe.user@myinstitution.edu> 8.0.0-1.0

    -u, --username (default: )
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
```
