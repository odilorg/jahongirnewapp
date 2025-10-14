SELECT cd.id, cd.name, cd.location_id, cd.is_active, l.name as location_name FROM cash_drawers cd LEFT JOIN locations l ON cd.location_id = l.id;
