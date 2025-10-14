SELECT u.id, u.name, u.email, l.id as location_id, l.name as location_name FROM users u LEFT JOIN location_user lu ON u.id = lu.user_id LEFT JOIN locations l ON lu.location_id = l.id WHERE u.id = 3;
