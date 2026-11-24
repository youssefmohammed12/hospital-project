-- v2.0.6: separate public review visibility from the retained rating record.
-- Existing V2 rows default to public; the migration runner/ETL maps V1 is_hidden.
ALTER TABLE ratings
    ADD COLUMN is_public TINYINT(1) NOT NULL DEFAULT 1 AFTER created_at,
    ADD CONSTRAINT chk_ratings_is_public CHECK (is_public IN (0,1)),
    ADD KEY idx_ratings_doctor_public_created (doctor_user_id,is_public,created_at);

CREATE OR REPLACE VIEW v_doctor_rating_summary AS
SELECT doctor_user_id, ROUND(AVG(stars), 1) AS average_rating, COUNT(*) AS rating_count
FROM ratings
WHERE is_public = 1
GROUP BY doctor_user_id;
