USE brewski_db;

ALTER TABLE users
    ADD COLUMN is_locked TINYINT(1) NOT NULL DEFAULT 0;

UPDATE users
   SET is_locked = 1
 WHERE locked_until IS NOT NULL
   AND locked_until > NOW();
