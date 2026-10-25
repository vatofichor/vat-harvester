#!/bin/sh
# Copyright (c) 2026:
# vatofichor - Sebastian Mass       [>_<]
# & Assisted By Gemini Antigravity /|\

SCRIPT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"

php "$SCRIPT_DIR/harvester.php" "$@"
STATUS=$?

if [ -t 0 ]; then
    printf "\nPress [Enter] to exit..."
    read -r _
fi

exit $STATUS
