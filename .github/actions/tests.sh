#!/bin/bash

set -e

npx cypress run  --headless --browser chrome  --config '{"specPattern":["plugins/generic/whatsAppContributor/cypress/tests/functional/*.cy.js"]}'
