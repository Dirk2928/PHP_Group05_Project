USE brewski_db;

ALTER TABLE users
    DROP COLUMN locked_until;

DELETE FROM settings
 WHERE setting_key = 'lockout_duration_minutes';
