local function start()
    box.schema.user.grant('guest', 'read,write,execute', 'universe')

    -- Locations
    box.schema.space.create('location')
    box.space.location:create_index('primary',   {type = 'tree', unique = true, parts = {1, 'NUM'}})
    box.space.location:create_index('country', {type = 'tree', unique = false, parts = {3, 'STR'}})
    box.space.location:create_index('with_country', {type = 'tree', unique = false, parts = {1, 'NUM', 3, 'STR'}})
    box.space.location:create_index('distance', {type = 'tree', unique = false, parts = {5, 'NUM'}})
end

return {
    start = start;
}