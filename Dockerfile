FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libcurl4-openssl-dev \
        libonig-dev \
    && docker-php-ext-install -j"$(nproc)" \
        mysqli \
        pdo_mysql \
        mbstring \
        curl \
    && php -m | grep -qi '^mysqli$' \
    && php -m | grep -qi '^pdo_mysql$' \
    && php -m | grep -qi '^mbstring$' \
    && php -m | grep -qi '^curl$' \
    && php -m | grep -qi '^openssl$' \
    && rm -rf /var/lib/apt/lists/*

RUN printf '%s\n' \
        '<VirtualHost *:80>' \
        '    DocumentRoot /var/www/html/brewski' \
        '    DirectoryIndex Start-screen/start.php index.php index.html' \
        '    <Directory /var/www/html/brewski>' \
        '        Options -Indexes' \
        '        AllowOverride None' \
        '        Require all granted' \
        '    </Directory>' \
        '    ErrorLog /dev/stderr' \
        '    CustomLog /dev/stdout combined' \
        '</VirtualHost>' \
        > /etc/apache2/sites-available/000-default.conf \
    && printf '%s\n' \
        'upload_max_filesize = 8M' \
        'post_max_size = 12M' \
        'memory_limit = 256M' \
        'session.gc_maxlifetime = 28800' \
        > /usr/local/etc/php/conf.d/brewski.ini

COPY brewski/ /var/www/html/brewski/

COPY docker-entrypoint.sh /usr/local/bin/brewski-entrypoint

RUN chmod +x /usr/local/bin/brewski-entrypoint \
    && chown -R www-data:www-data /var/www/html/brewski

EXPOSE 8080

CMD ["brewski-entrypoint"]