local function start()
    box.schema.user.grant('guest', 'read,write,execute', 'universe')

    -- Users
    box.schema.space.create('user')
    box.space.user:create_index('primary',   {type = 'tree', unique = true, parts = {1, 'NUM'}})
    box.space.user:create_index('email', {type = 'tree', unique = true, parts = {2, 'STR'}})

    -- Import data from JSON files
end

return {
    start = start;
}