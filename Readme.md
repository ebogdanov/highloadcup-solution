#Concepts

Task can be found here:
https://github.com/sat2707/hlcupdocs/blob/master/TECHNICAL_TASK.md

Main idea was to check ne stack of technologies (mainly Tarantool+PHP)
Application works on nginx+Memcached+PHP 5.6+Tarantool+Phalcon

1. insert.php works on eio and insert data in memcached+Tarantool
2. index.php served by Phalcon 3
3. nginx: 
    * query is "GET" - check memcached, if not found - redirect request to 
    /index.php
    * all POST queries are sent to /index.php    

# Results
    * Failed due not using timestamp from options.txt

# TODO
    * Fix timestamp issue
    * Add queue for POST operations    
    
# Install
    docker build contest-php .
    docker run contest-php