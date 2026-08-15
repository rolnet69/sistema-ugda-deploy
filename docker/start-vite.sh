#!/bin/sh

set -eu

cd /var/www/html

if [ ! -x node_modules/.bin/vite ]; then
    npm install
fi

npm run dev -- --host 0.0.0.0 --port 5173
