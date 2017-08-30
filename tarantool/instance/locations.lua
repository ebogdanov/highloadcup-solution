#!/usr/bin/env tarantool

box.cfg {
    listen = 3303;
}

function get_locations(keys, index)
    local list = {}
    local rec
    for key=1, #keys do
        rec = box.space.location.index[index]:select(keys[key])
        if next(rec) then
            table.insert(list, next(rec));
        end
    end
    return list
end

function insert_location(data)
    return box.space.location:auto_increment(data)
end

local m = require('locations').start({...});