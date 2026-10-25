#!/bin/sh
# Copyright (c) 2026:
# vatofichor - Sebastian Mass       [>_<]
# & Assisted By Gemini Antigravity /|\

SCRIPT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
cd "$SCRIPT_DIR" || exit 1

echo "========================================================"
echo " vat-harvester Simplex GUI Dashboard"
echo "========================================================"
echo " Binding server to http://127.0.0.1:8091 ..."
echo " Press Ctrl+C in this terminal to stop the server."
echo "========================================================"

# Attempt to open default browser in background
if command -v xdg-open >/dev/null 2>&1; then
    xdg-open "http://127.0.0.1:8091" >/dev/null 2>&1 &
elif command -v open >/dev/null 2>&1; then
    open "http://127.0.0.1:8091" >/dev/null 2>&1 &
fi

php -S 127.0.0.1:8091 -t "$SCRIPT_DIR/gui" "$SCRIPT_DIR/gui/index.php"
STATUS=$?

if [ $STATUS -ne 0 ]; then
    echo ""
    echo "[ERROR] Web server terminated with error code $STATUS."
fi

if [ -t 0 ]; then
    printf "\nPress [Enter] to exit..."
    read -r _
fi

exit $STATUS
