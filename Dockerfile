FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y cups-client sane-utils sane-airscan \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

RUN { \
        echo 'display_errors = Off'; \
        echo 'display_startup_errors = Off'; \
        echo 'log_errors = On'; \
        echo 'error_reporting = E_ALL'; \
        echo 'max_execution_time = 180'; \
    } > /usr/local/etc/php/conf.d/webprint.ini

# sane-airscan ships /etc/sane.d/airscan.conf owned by root; www-data (PHP)
# needs to rewrite it from the configured scanner list before each scan.
RUN touch /etc/sane.d/airscan.conf \
    && chown www-data:www-data /etc/sane.d/airscan.conf

WORKDIR /var/www/html

COPY . /var/www/html

RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
