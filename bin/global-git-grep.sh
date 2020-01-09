#!/bin/bash
#
# Global git grep search over multiple repositories

gitopts=
search=

while getopts ":i" opt; do
    case ${opt} in
        i)
            gitopts="$gitopts -i"
            ;;
        \?)
            echo "Invalid option: $OPTARG" 1>&2
            ;;
        :)
            echo "Search: $OPTARG"
            search="$search $OPTARG"
            ;;
    esac
done

# Clear command line options
shift $((OPTIND -1))

# Anything left is the search string
search=$@

echo "gitopts: $gitopts"
echo "search: $search"

# Find all repos in the current directory, exclude vendor directories
REPO_LIST=$(find . -type d -name ".git" |grep -v vendor)

for repo in $REPO_LIST; do
    repo_dir=$(dirname $repo)
    echo "Searching repo: $repo_dir"
    (cd $repo_dir && git grep ${gitopts} "${search}")
    echo
done
