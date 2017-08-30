#!/usr/bin/env tarantool

box.cfg {
    listen = 3302;
}

function get_visit(id)
    return box.space.visit.index.user_id:select(id);
end

function insert_visit(data)
    return box.space.visit:auto_increment(data)
end

local m = require('visits').start({...});