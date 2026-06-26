#!/bin/bash
# Compliance Center Installer
# Validates and installs required dependencies for PUI Gestor's Compliance Center

echo "Starting Compliance Center Installer..."
echo "---------------------------------------"

function check_command() {
    if command -v "$1" >/dev/null 2>&1; then
        echo "[OK] $1 is installed."
    else
        echo "[MISSING] $1 is not installed."
        return 1
    fi
}

echo "1. Validating Base Dependencies (Node, NPM, Java, PHP, Composer)..."
check_command node
check_command npm
check_command java
check_command php
check_command composer

echo "2. Validating Security Tools..."
if ! check_command semgrep; then
    echo "  -> To install semgrep: python3 -m pip install semgrep"
fi

if ! check_command dependency-check; then
    echo "  -> To install OWASP Dependency Check: Download from https://owasp.org/www-project-dependency-check/ and add to PATH."
fi

if ! check_command zap-cli; then
    echo "  -> To install zap-cli: pip install --upgrade zapcli"
    echo "  -> Also requires OWASP ZAP to be installed and added to PATH."
fi

echo "3. Installing Node Dependencies (Playwright, etc.)..."
npm install --prefix ../../ playwright archiver

echo "4. Installing Playwright Chromium..."
npx --prefix ../../ playwright install chromium

echo "---------------------------------------"
echo "Installation validation complete."
echo "Please ensure any [MISSING] tools are installed manually as required by your OS."
