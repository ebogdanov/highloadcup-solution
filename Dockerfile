FROM ubuntu:16.04

RUN apt-get update && apt-get install -y aptitude && apt-get install -y software-properties-common
RUN LC_ALL=C.UTF-8 add-apt-repository -y ppa:ondrej/php && apt-get update

RUN apt-get -y install zip unzip wget git libpcre3-dev gcc zlib1g-dev curl libjson-c-dev libxml2 libxml2-dev

RUN apt-get install -y libevent-2.0.5 libevent-dev

RUN apt-get install -y libssl-dev libssl1.1 autoconf g++ make openssl libssl-dev libcurl4-openssl-dev \
    && apt-get install -y libcurl4-openssl-dev pkg-config \
    && apt-get install -y libsasl2-dev

RUN apt-get install -y php7.1 php7.1-dev

RUN wget 'https://pecl.php.net/get/event-2.3.0.tgz' \
    && tar -xzf event-2.3.0.tgz \
    && ( \
        cd event-2.3.0 \
        && phpize \
        && ./configure \
        && make -j$(nproc) \
        && make install \
    ) \
    && rm event-2.3.0.tgz \
    && rm -r event-2.3.0 \
    && echo "extension=event.so" > /etc/php/7.1/cli/conf.d/event.ini

WORKDIR /var/www/app/

COPY php/app/ /var/www/app/
COPY data/ /tmp/data/

EXPOSE 80

ENV NAME HighloadContestPHP

CMD ["/usr/bin/php", "/var/www/app/start.php"]