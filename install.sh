#!/bin/sh
# Finish installing ELX Chatbot Connector.
# This file is inside the connector zip. Do not download it separately.
# The zip must already be at packages/elx-chatbot/elx-chatbot-connector-1.0.0.zip.
# Run this from the Maho project root (the folder that contains composer.json and ./maho):
#
#   unzip -p packages/elx-chatbot/elx-chatbot-connector-1.0.0.zip install.sh | sh

set -eu

ZIP="packages/elx-chatbot/elx-chatbot-connector-1.0.0.zip"

if [ ! -f composer.json ] || [ ! -f ./maho ]; then
    echo "Run this from the Maho project root." >&2
    exit 1
fi

if [ ! -f "$ZIP" ]; then
    echo "Copy elx-chatbot-connector-1.0.0.zip into packages/elx-chatbot/ first. Leave the file zipped." >&2
    exit 1
fi

composer config repositories.elx-chatbot '{"type":"artifact","url":"packages/elx-chatbot"}'
composer require elx/chatbot-connector:1.0.0
./maho migrate
./maho cache:flush

echo
echo "Installed. Open the Maho admin. The menu item is ELX Chatbot Connector."
echo "If View JSON Feed does not return JSON, allow /feeds/*.json in nginx. See INSTALLATION.md section 3."
