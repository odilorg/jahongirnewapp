UPDATE cashier_shifts SET status = " closed\, closed_at = NOW() WHERE status = \open\ AND (user_id = 3 OR cash_drawer_id = 1);
