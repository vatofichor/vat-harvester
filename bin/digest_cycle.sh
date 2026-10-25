#!/bin/sh
# Copyright (c) 2026:
# vatofichor - Sebastian Mass       [>_<]
# & Assisted By Gemini Antigravity /|\

SCRIPT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"

/bin/sh "$SCRIPT_DIR/../harvester.sh" digest "$@"
