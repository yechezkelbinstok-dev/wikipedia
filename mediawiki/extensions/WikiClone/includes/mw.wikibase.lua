--- mw.wikibase, as Wikipedia's modules expect to find it.
--
-- Only the surface those modules actually use is implemented. Anything absent
-- upstream returns nil or an empty table, which is what the real client does
-- for an article with no Wikidata item — so a module takes its "no data" path
-- instead of failing.

local wikibase = {}
local php

function wikibase.setupInterface()
	wikibase.setupInterface = nil
	php = mw_interface
	mw_interface = nil

	mw = mw or {}
	mw.wikibase = wikibase
	package.loaded['mw.wikibase'] = wikibase
end

function wikibase.getEntityIdForCurrentPage()
	return php.getEntityIdForCurrentPage()
end

function wikibase.getEntityIdForTitle()
	-- Resolving an arbitrary title would be a request per call; callers get
	-- nil, which they treat as "not connected to an item".
	return nil
end

--- The methods modules call on an entity. Kept on a metatable rather than
--- copied onto each entity, so pairs() over one still sees only its data.
local entityMethods = {}
entityMethods.__index = entityMethods

function entityMethods:getId()
	return self.id
end

function entityMethods:getLabel( lang )
	return ( php.getLabel( self.id, lang or 'en' ) )
end

function entityMethods:getLabelWithLang( lang )
	return php.getLabel( self.id, lang or 'en' )
end

function entityMethods:getDescription( lang )
	return ( php.getDescription( self.id, lang or 'en' ) )
end

function entityMethods:getDescriptionWithLang( lang )
	return php.getDescription( self.id, lang or 'en' )
end

function entityMethods:getSitelink( site )
	return php.getSitelink( self.id, site )
end

function entityMethods:getBestStatements( property )
	return php.getBestStatements( self.id, property )
end

function entityMethods:getAllStatements( property )
	return php.getAllStatements( self.id, property )
end

--- Formatted values for a property, with the property's own label.
--- Modules use this rather than walking statements themselves.
function entityMethods:formatPropertyValues( property )
	return php.formatPropertyValues( self.id, property )
end

function entityMethods:formatStatements( property )
	return php.formatPropertyValues( self.id, property )
end

function entityMethods:getProperties()
	local properties = {}
	for property in pairs( self.claims or {} ) do
		properties[#properties + 1] = property
	end
	return properties
end

--- getEntity and getEntityObject are the same function in the real client:
--- both return the object form. Modules such as Taxonbar call methods on what
--- getEntity returns, so a bare table fails them with "attempt to call method".
function wikibase.getEntity( id )
	if id == nil then
		id = php.getEntityIdForCurrentPage()
	end
	if id == nil then
		return nil
	end

	local entity = php.getEntity( id )
	if entity == nil then
		return nil
	end
	return setmetatable( entity, entityMethods )
end

wikibase.getEntityObject = wikibase.getEntity

function wikibase.getLabel( id )
	if id == nil then
		id = php.getEntityIdForCurrentPage()
	end
	if id == nil then
		return nil
	end
	return ( php.getLabel( id, 'en' ) )
end

function wikibase.getLabelWithLang( id )
	if id == nil then
		id = php.getEntityIdForCurrentPage()
	end
	if id == nil then
		return nil, nil
	end
	return php.getLabel( id, 'en' )
end

function wikibase.getDescription( id )
	if id == nil then
		id = php.getEntityIdForCurrentPage()
	end
	if id == nil then
		return nil
	end
	return ( php.getDescription( id, 'en' ) )
end

function wikibase.getDescriptionWithLang( id )
	if id == nil then
		id = php.getEntityIdForCurrentPage()
	end
	if id == nil then
		return nil, nil
	end
	return php.getDescription( id, 'en' )
end

function wikibase.getSitelink( id, site )
	if id == nil then
		return nil
	end
	return php.getSitelink( id, site )
end

function wikibase.getBestStatements( id, property )
	if id == nil or property == nil then
		return {}
	end
	return php.getBestStatements( id, property )
end

function wikibase.getAllStatements( id, property )
	if id == nil or property == nil then
		return {}
	end
	return php.getAllStatements( id, property )
end

function wikibase.entityExists( id )
	if id == nil then
		return false
	end
	return php.entityExists( id )
end

function wikibase.getEntityUrl( id )
	if id == nil then
		id = php.getEntityIdForCurrentPage()
	end
	if id == nil then
		return nil
	end
	return php.getEntityUrl( id )
end

--- Snak formatting. A snak is a single value inside a statement; these turn
--- one into text, resolving references to other entities to their labels.
function wikibase.renderSnak( snak )
	if snak == nil then
		return ''
	end
	return php.renderSnak( snak )
end

function wikibase.formatValue( snak )
	return wikibase.renderSnak( snak )
end

function wikibase.renderSnaks( snaks )
	if type( snaks ) ~= 'table' then
		return ''
	end

	local rendered = {}
	for _, group in pairs( snaks ) do
		for _, snak in ipairs( group ) do
			local text = php.renderSnak( snak )
			if text ~= '' then
				rendered[#rendered + 1] = text
			end
		end
	end

	return table.concat( rendered, ', ' )
end

function wikibase.formatValues( snaks )
	return wikibase.renderSnaks( snaks )
end

function wikibase.formatPropertyValues( id, property )
	if id == nil then
		id = php.getEntityIdForCurrentPage()
	end
	if id == nil or property == nil then
		return { value = '', label = '' }
	end
	return php.formatPropertyValues( id, property )
end

function wikibase.isValidEntityId( id )
	return type( id ) == 'string' and string.match( id, '^[QPL]%d+$' ) ~= nil
end

--- Property ordering is a Wikidata maintenance feature; modules that ask fall
--- back to their own order when it is absent.
function wikibase.getPropertyOrder()
	return nil
end

function wikibase.orderProperties( properties )
	return properties
end

function wikibase.getReferencedEntityId()
	return nil
end

return wikibase
