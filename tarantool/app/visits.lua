local function start()
    box.schema.user.grant('guest', 'read,write,execute', 'universe')

    -- Visits
    box.schema.space.create('visit')

    box.space.visit:create_index('primary',   {type = 'tree', unique = true, parts = {1, 'NUM'}})
    box.space.visit:create_index('location', {type = 'tree', unique = false, parts = {2, 'NUM'}})
    box.space.visit:create_index('user_id', {type = 'tree', unique = false, parts = {3, 'NUM'}})
    box.space.visit:create_index('visited_at', {type = 'tree', unique = false, parts = {4, 'NUM'}})
end

return {
    start = start;
}