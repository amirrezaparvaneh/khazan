#!/usr/bin/env bash

# Stop script execution on error
set -e

PLUGIN_SLUG="wp-internal-link-analyzer"
MAIN_FILE="${PLUGIN_SLUG}.php"

# Extract version from the main PHP file header
VERSION=$(grep -i "Version:" "$MAIN_FILE" | head -n 1 | awk '{print $NF}' | tr -d '\r')

if [ -z "$VERSION" ]; then
    VERSION="1.0.0"
fi

ZIP_NAME="${PLUGIN_SLUG}-v${VERSION}.zip"
TMP_DIR="/tmp/${PLUGIN_SLUG}_build_$$"

echo "🚀 Building production package for ${PLUGIN_SLUG} (v${VERSION})..."

# Check if zip package is installed
if ! command -v zip &> /dev/null; then
    echo "❌ Error: 'zip' utility is not installed. Run 'sudo apt install zip' first."
    exit 1
fi

# Clean up older zip packages in the current directory
rm -f "${PLUGIN_SLUG}-v*.zip"

# Create temporary directory matching standard WordPress plugin folder structure
mkdir -p "${TMP_DIR}/${PLUGIN_SLUG}"

# Copy runtime files and assets
echo "📦 Packaging plugin files..."
cp -r assets/ includes/ "${MAIN_FILE}" README.md "${TMP_DIR}/${PLUGIN_SLUG}/"

# Build zip archive inside the temporary directory
echo "🗜️  Compressing into ${ZIP_NAME}..."
cd "${TMP_DIR}"
zip -r -q "${ZIP_NAME}" "${PLUGIN_SLUG}"

# Move archive back to project root and remove temp folder
cd - > /dev/null
mv "${TMP_DIR}/${ZIP_NAME}" ./
rm -rf "${TMP_DIR}"

echo "✅ Production package generated successfully: ${ZIP_NAME}"
ls -lh "${ZIP_NAME}"