FROM wordpress:php8.3-apache

RUN apt-get update \
	&& apt-get install -y --no-install-recommends libgmp-dev \
	&& docker-php-ext-install gmp \
	&& rm -rf /var/lib/apt/lists/*
