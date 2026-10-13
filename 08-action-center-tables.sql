-- ============================================================
-- 08-action-center-tables.sql   (WordPress)
--
-- Creates the three tables the Action Center's back end needs that
-- were never in this app's schema (they predate the migrations on
-- the older apps and only existed as database state there):
--
--   psi_results          PageSpeed run cache (includes/psi.php)
--   schema_live_results  raw schema-check payloads, cached per URL
--   schema_results       per-run schema summary rows
--
-- Column sets mirror exactly what includes/psi.php and
-- includes/schema_check.php read and write.
--
-- Safe to run more than once: each CREATE is guarded through
-- information_schema.
--
-- Run it against this app's own database:
--   mysql -u USER -p wordpress-googlesearchconsole < 08-action-center-tables.sql
--
-- ============================================================ */

-- ---------- psi_results ----------

SET @s := IF((SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'psi_results') = 0,
  'CREATE TABLE psi_results (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    instance_id    VARCHAR(191) NOT NULL,
    url            VARCHAR(768) NOT NULL,
    strategy       VARCHAR(20) NOT NULL DEFAULT ''mobile'',
    perf_score     INT NULL,
    a11y_score     INT NULL,
    bp_score       INT NULL,
    seo_score      INT NULL,
    lcp_ms         INT NULL,
    cls            DECIMAL(8,3) NULL,
    inp_ms         INT NULL,
    fcp_ms         INT NULL,
    tbt_ms         INT NULL,
    opportunities  MEDIUMTEXT NULL,
    raw_status     VARCHAR(20) NOT NULL DEFAULT ''ok'',
    error_message  TEXT NULL,
    fetched_at     DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_instance_strategy (instance_id, strategy),
    KEY idx_fetched_at (fetched_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
  'SELECT ''psi_results already present'' AS note');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------- schema_live_results ----------

SET @s := IF((SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_live_results') = 0,
  'CREATE TABLE schema_live_results (
    instance_id VARCHAR(191) NOT NULL,
    url_hash    CHAR(40) NOT NULL,
    url         VARCHAR(768) NOT NULL,
    payload     MEDIUMTEXT NOT NULL,
    fetched_at  DATETIME NOT NULL,
    PRIMARY KEY (instance_id, url_hash)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
  'SELECT ''schema_live_results already present'' AS note');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------- schema_results ----------

SET @s := IF((SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_results') = 0,
  'CREATE TABLE schema_results (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    instance_id    VARCHAR(191) NOT NULL,
    url_hash       CHAR(40) NOT NULL,
    url            VARCHAR(768) NOT NULL,
    found_types    TEXT NULL,
    block_count    INT NULL,
    invalid_blocks INT NULL,
    fetched_at     DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_instance_url (instance_id, url_hash)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
  'SELECT ''schema_results already present'' AS note');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------- verify ----------

SELECT 'psi_results' AS what, COUNT(*) AS rows_now FROM psi_results
UNION ALL SELECT 'schema_live_results', COUNT(*) FROM schema_live_results
UNION ALL SELECT 'schema_results', COUNT(*) FROM schema_results;
