FROM ubuntu:16.04

RUN apt-get update && apt-get install -y software-properties-common python-software-properties curl

RUN apt-get -y install zip unzip wget git libpcre3-dev gcc zlib1g-dev

RUN LC_ALL=C.UTF-8 add-apt-repository -y ppa:ondrej/php

RUN apt-get update && apt-get install -y php5.6 php5.6-dev

RUN apt-get install -y php5.6-fpm \
    && apt-get install -y php5.6-cli \
    && apt-get install -y php5.6-xml

RUN apt-get install -y nginx memcached
RUN dpkg --remove apache2

RUN apt-get install -y libevent-2.0.5

RUN apt-get update \
    && apt-get install -y tarantool \
    && apt-get remove -y tarantool-common \
    && curl http://launchpadlibrarian.net/235454367/tarantool-common_1.6.7.588.g76bbd9c-1build1_all.deb --output tarantool-common_1.6.7.588.g76bbd9c-1build1_all.deb --silent \
    && dpkg -i tarantool-common_1.6.7.588.g76bbd9c-1build1_all.deb

RUN curl -s https://packagecloud.io/install/repositories/phalcon/stable/script.deb.sh | bash \
    && apt-get update && apt-get install php5.6-phalcon \
    && echo "extension=phalcon.so" >> /etc/php/5.6/fpm/conf.d/30-phalcon.ini

RUN curl -fsSL 'https://pecl.php.net/get/eio-2.0.2.tgz' -o eio.tar.gz \
    && mkdir -p eio \
    && tar -xf eio.tar.gz -C eio --strip-components=1 \
    && rm eio.tar.gz \
    && ( \
        cd eio \
        && phpize \
        && ./configure \
        && make -j$(nproc) \
        && make install \
    ) \
    && rm -r eio \
    && echo "extension=eio.so" >> /etc/php/5.6/cli/conf.d/30-eio.ini

RUN apt-get install -y php5.6-memcached

RUN git clone https://github.com/tarantool/tarantool-php.git \
    && ( \
        cd tarantool-php \
        && phpize \
        && ./configure \
        && make -j$(nproc) \
        && make install \
    ) \
    && rm -r tarantool-php \
    && echo "extension=tarantool.so" >> /etc/php/5.6/fpm/conf.d/30-tarantool.ini \
    && echo "extension=tarantool.so" >> /etc/php/5.6/cli/conf.d/30-tarantool.ini \
    && echo "always_populate_raw_post_data = -1" >> /etc/php/5.6/fpm/php.ini

RUN apt -y autoremove

RUN  wget http://pkgs.fedoraproject.org/repo/pkgs/php-pecl-memcache/memcache-3.0.9-4991c2f.tar.gz/dc3b9fce0f59db26b6c926df3de20251/memcache-3.0.9-4991c2f.tar.gz \
    && tar -xzvf memcache-3.0.9-4991c2f.tar.gz \
    && ( \
        cd pecl-memcache-4991c2fff22d00dc81014cc92d2da7077ef4bc86 \
        && phpize \
        && ./configure \
        && make -j$(nproc) \
        && make install \
    ) \
    && rm -r pecl-memcache-4991c2fff22d00dc81014cc92d2da7077ef4bc86 \
    && echo "extension=memcache.so" >> /etc/php/5.6/fpm/conf.d/30-memcache.ini \
    && echo "extension=memcache.so" >> /etc/php/5.6/cli/conf.d/30-memcache.ini

RUN rm /etc/nginx/sites-enabled/*

WORKDIR /var/www/app/

COPY php/app/ /var/www/app/

COPY conf/travel.conf /etc/nginx/sites-enabled/
COPY conf/www.conf /etc/php/5.6/fpm/pool.d/

COPY tarantool/instance /etc/tarantool/instances.enabled/
COPY tarantool/instance /etc/tarantool/instances.available/
COPY tarantool/app /usr/share/tarantool/

COPY data/ /tmp/data/

COPY run.sh /tmp/run.sh
RUN chmod 744 /tmp/run.sh

EXPOSE 80

ENV NAME HighloadContestPHP

CMD ["/tmp/run.sh"]