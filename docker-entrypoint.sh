#!/bin/sh
set -e

# Sicherstellen, dass das gemountete moodledata-Volume www-data gehoert.
mkdir -p /var/moodledata
chown -R www-data:www-data /var/moodledata
chmod -R 0770 /var/moodledata

exec "$@"
