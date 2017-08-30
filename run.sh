#!/usr/bin/env bash

mkdir /run/php

# Start PHP FPM
/etc/init.d/php5.6-fpm restart
# Start nginx
/etc/init.d/nginx restart
# Start memcache
/etc/init.d/memcached restart
# Start tarantool
mkdir -p /var/lib/tarantool/
chown tarantool:tarantool /var/lib/tarantool

/etc/init.d/tarantool restart

# Wait for file - unpack it and insert data into system
echo "Wait for file"
while [ ! -f /tmp/data/data.zip ]
do
  sleep 1
done
unzip /tmp/data/data.zip -d /var/data/

# Loop
date && php /var/www/app/insert.php && date

# Warm up app
for i in 1 2 3
do
   echo "Welcome $i times"
   curl "http://127.0.0.1/users/$i" -H "Host: travel.com"
   curl "http://127.0.0.1/visits/$i" -H "Host: travel.com"
   curl "http://127.0.0.1/locations/$i" -H "Host: travel.com"
done


trap 'echo "Caught SIGUSR1"' SIGUSR1

echo "Waiting for SIGUSR signal"
while :
do
   sleep 60 &
   wait
   echo -n "."
done