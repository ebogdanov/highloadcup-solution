#!/usr/bin/env tarantool

box.cfg {
    listen = 3301;
}

function insert_user(data)
    return box.space.user:auto_increment(data)
end

local m = require('users').start({...});