FROM php:8.3-cli-alpine

# Enable PHP cURL so the app can forward uploads to the upstream file API.
RUN apk add --no-cache curl-dev \
    && docker-php-ext-install curl

WORKDIR /var/www/html
COPY . /var/www/html

# Render provides PORT at runtime; 10000 is the local/default fallback.
ENV PORT=10000
EXPOSE 10000

CMD ["sh", "-c", "php -d file_uploads=On -d upload_max_filesize=100M -d post_max_size=110M -d max_file_uploads=20 -d max_execution_time=300 -S 0.0.0.0:${PORT:-10000} -t /var/www/html"]
