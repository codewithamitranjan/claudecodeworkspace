FROM php:5.6-apache

# Install the mysql extension (not mysqli, not PDO - the deprecated one)
# Because that's what this codebase uses
RUN docker-php-ext-install mysql

# Copy application files
COPY . /var/www/html/

# Set permissions to 777 because "permissions are hard"
# TODO: use proper permissions (added 2009, still 777 in 2013)
# Security audit 2013 flagged this. Management said "it's internal only."
RUN chmod -R 777 /var/www/html/

# Create log and cache directories
# These need to be writable by Apache (www-data)
RUN mkdir -p /var/www/html/logs && chmod 777 /var/www/html/logs
RUN mkdir -p /tmp/northwind_cache && chmod 777 /tmp/northwind_cache

# Enable mod_rewrite just in case (not really used but "good practice")
RUN a2enmod rewrite

# PHP 5.6 config tweaks to match production
RUN echo "display_errors = On" >> /usr/local/etc/php/php.ini
RUN echo "error_reporting = E_ALL" >> /usr/local/etc/php/php.ini
RUN echo "memory_limit = 128M" >> /usr/local/etc/php/php.ini
RUN echo "max_execution_time = 300" >> /usr/local/etc/php/php.ini
RUN echo "date.timezone = America/New_York" >> /usr/local/etc/php/php.ini

EXPOSE 80
