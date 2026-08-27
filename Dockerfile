# =====================================================================
#  Moodle – Einstellungstest, Verbandsgemeinde Kirchen
#  Eigenes, versioniertes Image aus offiziellem Moodle-Quellcode.
#  Herstellerunabhaengig und auditierbar.
# =====================================================================

# MOODLE_405_STABLE = Moodle 4.5 LTS (Stabilitaet vor Features -> richtig
# fuer ein Pruefungssystem). Aktuell unterstuetzte/LTS-Branches vorher pruefen:
# https://moodledev.io/general/releases
ARG MOODLE_BRANCH=MOODLE_405_STABLE
ARG PHP_VERSION=8.3

FROM php:${PHP_VERSION}-apache

# --- Systempakete zum Bauen der von Moodle benoetigten PHP-Erweiterungen ---
RUN apt-get update && apt-get install -y --no-install-recommends \
      git unzip ca-certificates \
      libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
      libicu-dev libxml2-dev libzip-dev libonig-dev \
      libcurl4-openssl-dev libsodium-dev libpq-dev \
    && rm -rf /var/lib/apt/lists/*

# --- Von Moodle geforderte / empfohlene PHP-Erweiterungen ---
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
       gd intl mysqli pgsql pdo_mysql pdo_pgsql soap zip exif opcache \
    && a2enmod rewrite headers

# --- Empfohlene php.ini-Werte fuer Moodle ---
RUN { \
      echo "max_input_vars = 5000"; \
      echo "memory_limit = 256M"; \
      echo "upload_max_filesize = 64M"; \
      echo "post_max_size = 64M"; \
      echo "max_execution_time = 300"; \
      echo "opcache.enable = 1"; \
      echo "opcache.enable_cli = 0"; \
      echo "opcache.memory_consumption = 128"; \
      echo "opcache.max_accelerated_files = 10000"; \
      echo "opcache.revalidate_freq = 60"; \
      echo "opcache.use_cwd = 1"; \
      echo "opcache.validate_timestamps = 1"; \
      echo "opcache.save_comments = 1"; \
    } > /usr/local/etc/php/conf.d/moodle.ini

# ServerName-Warnung unterdruecken
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# --- Moodle-Quellcode, versions-gepinnt ins Image gebacken ---
ARG MOODLE_BRANCH
RUN git clone --depth 1 --branch ${MOODLE_BRANCH} \
      https://github.com/moodle/moodle.git /var/www/html \
    && rm -rf /var/www/html/.git \
    && chown -R www-data:www-data /var/www/html

# moodledata liegt AUSSERHALB des Webroots (Volume)
RUN mkdir -p /var/moodledata && chown -R www-data:www-data /var/moodledata

# config.php ins Image backen statt per Bind-Mount einhaengen.
# Vermeidet das Portainer-Pfadproblem bei relativen Host-Bind-Mounts
# (das geklonte Repo liegt in Portainers Volume, der Host-Docker-Dienst
# findet den relativen Pfad nicht). config.php enthaelt keine Secrets —
# die kommen zur Laufzeit aus den Umgebungsvariablen.
COPY config.php /var/www/html/config.php
RUN chown www-data:www-data /var/www/html/config.php

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
