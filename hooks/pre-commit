#!/bin/sh
# Detect whether /dev/tty is available & functional
if sh -c ": >/dev/tty" >/dev/null 2>/dev/null; then
    exec < /dev/tty
fi

# Get list of stashed PHP files
stashed_files=$(git diff --cached --name-only --diff-filter=ACM -- '*.php')

# If there are no stashed PHP files, exit early
if [ -z "$stashed_files" ]; then
    exit 0
fi

# Set files variable to only include stashed PHP files
files="$stashed_files"

$(pwd)/vendor/bin/pint $files -q
if [ $? -eq 0 ]; then
    git add $files
fi
