SELECT u.id, u.name, u.email, r.name as role FROM users u LEFT JOIN model_has_roles mhr ON u.id = mhr.model_id LEFT JOIN roles r ON mhr.role_id = r.id WHERE u.id = 3;
