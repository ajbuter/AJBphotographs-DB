-- CS 2300 Database Project (Phase 3) — Group N715
-- Web Accessible Image Database System (MariaDB / MySQL Compatible)
-- Authors: Aiden Buter, Claude Brown, Wesley Oxman
-- File: sql/init_database.sql
--
-- PURPOSE:
--   Standalone initialization script that instantiates a fresh database with:
--     1. Database creation (UTF-8 / utf8mb4)
--     2. Application database user credentials & least-privilege grants
--     3. All 11 relational tables (InnoDB) with PKs, FKs, CHECKs, and Cascades
--     4. Functional dependency & query performance indexes
--     5. Realistic populated seed data across all 11 relations
--
-- USAGE:
--   mariadb -u root -p < sql/init_database.sql
--   or 
--   mysql -u root -p < sql/init_database.sql
--
-- DEMO LOGIN CREDENTIALS (for application login via POST /api/login):
--   Admins:        admin_aiden / admin123   |  admin_wesley / admin123
--   General Users: viewer_claude / viewer123 | viewer_guest / viewer123

-- -----------------------------------------------------------------------------
-- DATABASE & APPLICATION USER CREDENTIALS
-- -----------------------------------------------------------------------------
DROP DATABASE IF EXISTS n715_image_db;
CREATE DATABASE n715_image_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

-- Create application DB user
CREATE USER IF NOT EXISTS 'n715_app'@'localhost' IDENTIFIED BY 'N715_ImgDB_2026!';
CREATE USER IF NOT EXISTS 'n715_app'@'127.0.0.1' IDENTIFIED BY 'N715_ImgDB_2026!';

GRANT SELECT, INSERT, UPDATE, DELETE ON n715_image_db.* TO 'n715_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON n715_image_db.* TO 'n715_app'@'127.0.0.1';
FLUSH PRIVILEGES;

USE n715_image_db;

-- COLLECTION
CREATE TABLE COLLECTION (
    collection_id VARCHAR(80)  NOT NULL,
    name          VARCHAR(120) NOT NULL,
    description   TEXT         NULL,
    PRIMARY KEY (collection_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CATEGORY (1:N from COLLECTION)
CREATE TABLE CATEGORY (
    category_id   VARCHAR(80)  NOT NULL,
    name          VARCHAR(120) NOT NULL,
    description   TEXT         NULL,
    collection_id VARCHAR(80)  NOT NULL,
    PRIMARY KEY (category_id),
    INDEX idx_category_collection (collection_id),
    CONSTRAINT fk_category_collection
        FOREIGN KEY (collection_id) REFERENCES COLLECTION (collection_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ALBUM (1:N from CATEGORY)
CREATE TABLE ALBUM (
    album_id    VARCHAR(80)  NOT NULL,
    title       VARCHAR(160) NOT NULL,
    location    VARCHAR(160) NULL,
    event_date  BIGINT       NULL COMMENT 'UNIX timestamp (seconds)',
    category_id VARCHAR(80)  NOT NULL,
    PRIMARY KEY (album_id),
    INDEX idx_album_category (category_id),
    INDEX idx_album_event_date (event_date),
    INDEX idx_album_location (location),
    CONSTRAINT fk_album_category
        FOREIGN KEY (category_id) REFERENCES CATEGORY (category_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- USER (Superclass for ADMIN and GENERAL_USER)
CREATE TABLE `USER` (
    user_id  VARCHAR(80)  NOT NULL,
    username VARCHAR(80)  NOT NULL,
    password VARCHAR(255) NOT NULL,
    PRIMARY KEY (user_id),
    UNIQUE KEY uq_user_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ADMIN (Subclass of USER)
CREATE TABLE ADMIN (
    user_id VARCHAR(80) NOT NULL,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_admin_user
        FOREIGN KEY (user_id) REFERENCES `USER` (user_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- GENERAL_USER (Subclass of USER)
CREATE TABLE GENERAL_USER (
    user_id VARCHAR(80) NOT NULL,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_general_user_user
        FOREIGN KEY (user_id) REFERENCES `USER` (user_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- IMAGE (1:N from ALBUM [total participation], 1:N from USER)
CREATE TABLE IMAGE (
    image_id    VARCHAR(80)  NOT NULL,
    file_name   VARCHAR(160) NOT NULL,
    file_path   VARCHAR(255) NOT NULL,
    file_type   VARCHAR(60)  NOT NULL,
    file_size   INT          NOT NULL,
    upload_date BIGINT       NOT NULL COMMENT 'UNIX timestamp (seconds)',
    like_count  INT          NOT NULL DEFAULT 0,
    dl_count    INT          NOT NULL DEFAULT 0,
    album_id    VARCHAR(80)  NOT NULL,
    user_id     VARCHAR(80)  NULL,
    PRIMARY KEY (image_id),
    INDEX idx_image_album (album_id),
    INDEX idx_image_user (user_id),
    INDEX idx_image_likes (like_count),
    INDEX idx_image_downloads (dl_count),
    INDEX idx_image_upload_date (upload_date),
    CONSTRAINT chk_image_file_size CHECK (file_size > 0),
    CONSTRAINT chk_image_like_count CHECK (like_count >= 0),
    CONSTRAINT chk_image_dl_count CHECK (dl_count >= 0),
    CONSTRAINT fk_image_album
        FOREIGN KEY (album_id) REFERENCES ALBUM (album_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_image_user
        FOREIGN KEY (user_id) REFERENCES `USER` (user_id)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- METADATA (Weak entity of IMAGE via Has 1:1 identifying relationship)
CREATE TABLE METADATA (
    image_id      VARCHAR(80)  NOT NULL,
    meta_id       VARCHAR(80)  NOT NULL,
    camera        VARCHAR(120) NULL,
    lens          VARCHAR(160) NULL,
    exposure      VARCHAR(60)  NULL,
    aperture      VARCHAR(40)  NULL,
    focal_length  INT          NULL,
    shutter_speed VARCHAR(40)  NULL,
    date_taken    BIGINT       NULL COMMENT 'UNIX timestamp (seconds)',
    PRIMARY KEY (image_id, meta_id),
    UNIQUE KEY uq_metadata_image (image_id),
    INDEX idx_metadata_camera (camera),
    INDEX idx_metadata_lens (lens),
    INDEX idx_metadata_date_taken (date_taken),
    INDEX idx_metadata_focal_length (focal_length),
    CONSTRAINT fk_metadata_image
        FOREIGN KEY (image_id) REFERENCES IMAGE (image_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- TAG (Custom key-value labels for albums and images)
CREATE TABLE TAG (
    tag_id    VARCHAR(80)  NOT NULL,
    tag_name  VARCHAR(80)  NOT NULL,
    tag_value VARCHAR(120) NOT NULL,
    PRIMARY KEY (tag_id),
    UNIQUE KEY uq_tag_name_value (tag_name, tag_value),
    INDEX idx_tag_value (tag_value)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Album_Tag (M:N ALBUM:TAG)
CREATE TABLE Album_Tag (
    album_id VARCHAR(80) NOT NULL,
    tag_id   VARCHAR(80) NOT NULL,
    PRIMARY KEY (album_id, tag_id),
    INDEX idx_album_tag_tag (tag_id),
    CONSTRAINT fk_album_tag_album
        FOREIGN KEY (album_id) REFERENCES ALBUM (album_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_album_tag_tag
        FOREIGN KEY (tag_id) REFERENCES TAG (tag_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Image_Tag (M:N IMAGE:TAG)
CREATE TABLE Image_Tag (
    image_id VARCHAR(80) NOT NULL,
    tag_id   VARCHAR(80) NOT NULL,
    PRIMARY KEY (image_id, tag_id),
    INDEX idx_image_tag_tag (tag_id),
    CONSTRAINT fk_image_tag_image
        FOREIGN KEY (image_id) REFERENCES IMAGE (image_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_image_tag_tag
        FOREIGN KEY (tag_id) REFERENCES TAG (tag_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- SEED DATA POPULATION
-- -----------------------------------------------------------------------------

-- Users (Passwords hashed with salted SHA-256; server.php upgrades them to PHP password_hash() bcrypt upon first login)
INSERT INTO `USER` (user_id, username, password) VALUES
('usr_admin_1', 'admin_aiden',   'sha256$n715aiden$ab24d0059315cc384cb2b8cd376037b3f22e1d851b1bacdd01da59e45e790d89'),
('usr_admin_2', 'admin_wesley',  'sha256$n715wesley$95d0703f7b805952a37fa9fa5fb3cf93118706ea892dbd59e712ce7cca59a530'),
('usr_gen_1',   'viewer_claude', 'sha256$n715claude$ef89e9c915a7d67031cea39954f6c0a7ef31559b9d4a2aadcabc56ef329765c9'),
('usr_gen_2',   'viewer_guest',  'sha256$n715guest$e631b8d9b1d5b198b1c5f7a9aff7fe635bb9cbaa2e4b7cc5a04ef9405e4b5c83');

INSERT INTO ADMIN (user_id) VALUES
('usr_admin_1'),
('usr_admin_2');

INSERT INTO GENERAL_USER (user_id) VALUES
('usr_gen_1'),
('usr_gen_2');

-- Collections
INSERT INTO COLLECTION (collection_id, name, description) VALUES
('col_athletics', 'Missouri S&T Athletics', 'Dummy Description'),
('col_portfolio', 'Personal Portfolio',     'Dummy Description');

-- Categories (aligns cleanly with ajbphotographs.com)
INSERT INTO CATEGORY (category_id, name, description, collection_id) VALUES
('sports',        'Sports',        'Dummy Description', 'col_athletics'),
('automotive',    'Automotive',    'Dummy Description', 'col_portfolio'),
('portraits',     'Portraits',     'Dummy Description', 'col_portfolio'),
('environmental', 'Environmental', 'Dummy Description', 'col_portfolio'),
('events',        'Events',        'Dummy Description', 'col_portfolio');

-- Albums (event_date stored as UNIX timestamps)
INSERT INTO ALBUM (album_id, title, location, event_date, category_id) VALUES
('album_1', 'Dummy Title 1', 'Rolla, MO',           1760745600, 'sports'),
('album_2', 'Dummy Title 2', 'Rolla, MO',           1759536000, 'sports'),
('album_3', 'Dummy Title 3', 'Rolla, MO',           1762992000, 'sports'),
('album_4', 'Dummy Title 4', 'Kansas City, KS',     1758326400, 'automotive'),
('album_5', 'Dummy Title 5', 'Kansas City, KS',     1760140800, 'automotive'),
('album_6', 'Dummy Title 6', 'Rolla, MO',           1761350400, 'portraits'),
('album_7', 'Dummy Title 7', 'Rolla, MO',           1761955200, 'environmental'),
('album_8', 'Dummy Title 8', 'Rolla, MO',           1762560000, 'events');

-- Images (28 images across all 8 albums with realistic sizes, likes, downloads)
INSERT INTO IMAGE (image_id, file_name, file_path, file_type, file_size, upload_date, like_count, dl_count, album_id, user_id) VALUES
-- Album 1: Football (5 images)
('img_001', 'fb_01.jpg',  '/uploads/fb_01.jpg', 'image/jpeg', 6420100, 1760760000, 84, 52, 'album_1',  'usr_admin_1'),
('img_002', 'fb_02.jpg',  '/uploads/fb_02.jpg', 'image/jpeg', 7150400, 1760760120, 96, 67, 'album_1',  'usr_admin_1'),
('img_003', 'fb_03.jpg',  '/uploads/fb_03.jpg', 'image/jpeg', 5310800, 1760760240, 41, 19, 'album_1',  'usr_admin_1'),
('img_004', 'fb_04.jpg',  '/uploads/fb_04.jpg', 'image/jpeg', 6890200, 1760760360, 63, 34, 'album_1',  'usr_admin_1'),
('img_005', 'fb_05.jpg',  '/uploads/fb_05.jpg', 'image/jpeg', 5980500, 1760760480, 72, 44, 'album_1',  'usr_admin_1'),

-- Album 2: Soccer (4 images)
('img_006', 'soc_01.jpg', '/uploads/soc_01.jpg', 'image/jpeg', 5480000, 1759550000, 58, 31, 'album_2',      'usr_admin_1'),
('img_007', 'soc_02.jpg', '/uploads/soc_02.jpg', 'image/jpeg', 6120900, 1759550100, 77, 45, 'album_2',      'usr_admin_1'),
('img_008', 'soc_03.jpg', '/uploads/soc_03.jpg', 'image/jpeg', 4950300, 1759550200, 29, 14, 'album_2',      'usr_admin_1'),
('img_009', 'soc_04.jpg', '/uploads/soc_04.jpg', 'image/jpeg', 5210000, 1759550300, 35, 22, 'album_2',      'usr_admin_1'),

-- Album 3: Basketball (4 images)
('img_010', 'bb_01.jpg',  '/uploads/bb_01.jpg',  'image/jpeg', 5890100, 1763005000, 49, 28, 'album_3',   'usr_admin_1'),
('img_011', 'bb_02.jpg',  '/uploads/bb_02.jpg',  'image/jpeg', 6740200, 1763005120, 88, 53, 'album_3',   'usr_admin_1'),
('img_012', 'bb_03.jpg',  '/uploads/bb_03.jpg',  'image/jpeg', 5630400, 1763005240, 44, 21, 'album_3',   'usr_admin_1'),
('img_013', 'bb_04.jpg',  '/uploads/bb_04.jpg',  'image/jpeg', 4870600, 1763005360, 19,  8, 'album_3',   'usr_admin_1'),

-- Album 4: Subaru BRZ Automotive (4 images)
('img_014', 'brz_01.jpg',  '/uploads/brz_01.jpg',  'image/jpeg', 8240000, 1758340000, 91, 61, 'album_4',     'usr_admin_1'),
('img_015', 'brz_02.jpg',  '/uploads/brz_02.jpg',  'image/jpeg', 7910500, 1758340150, 85, 57, 'album_4',     'usr_admin_1'),
('img_016', 'brz_03.jpg',  '/uploads/brz_03.jpg',  'image/jpeg', 6840300, 1758340300, 54, 29, 'album_4',     'usr_admin_1'),
('img_017', 'brz_04.jpg',  '/uploads/brz_04.jpg',  'image/jpeg', 6190700, 1758340450, 38, 17, 'album_4',     'usr_admin_1'),

-- Album 5: Cars & Coffee (3 images)
('img_018', 'cc_01.jpg',   '/uploads/cc_01.jpg',   'image/jpeg', 7620100, 1760155000, 67, 39, 'album_5',    'usr_admin_2'),
('img_019', 'cc_02.jpg',   '/uploads/cc_02.jpg',   'image/jpeg', 7110900, 1760155120, 52, 26, 'album_5',    'usr_admin_2'),
('img_020', 'cc_03.jpg',   '/uploads/cc_03.jpg',   'image/jpeg', 5340200, 1760155240, 24, 11, 'album_5',    'usr_admin_2'),

-- Album 6: Portraits (3 images)
('img_021', 'port_01.jpg',   '/uploads/port_01.jpg',  'image/jpeg', 7450800, 1761365000, 61, 48, 'album_6', 'usr_admin_2'),
('img_022', 'port_02.jpg',   '/uploads/port_02.jpg',  'image/jpeg', 8120400, 1761365180, 79, 55, 'album_6', 'usr_admin_2'),
('img_023', 'port_03.png',   '/uploads/port_03.png',  'image/png',  9350000, 1761365360, 47, 33, 'album_6', 'usr_admin_2'),

-- Album 7: Environmental (3 images)
('img_024', 'envir_01.jpg',   '/uploads/envir_01.jpg',   'image/jpeg', 9180200, 1761970000, 94, 71, 'album_7',  'usr_admin_2'),
('img_025', 'envir_02.jpg',   '/uploads/envir_02.jpg',   'image/jpeg', 8640100, 1761970200, 65, 42, 'album_7',  'usr_admin_2'),
('img_026', 'envir_03.jpg',   '/uploads/envir_03.jpg',   'image/jpeg', 7990600, 1761970400, 31, 16, 'album_7',  'usr_admin_2'),

-- Album 8: Events (2 images)
('img_027', 'event_01.jpg',     '/uploads/event_01.jpg',     'image/jpeg', 6540300, 1762575000, 56, 30, 'album_8', 'usr_admin_2'),
('img_028', 'event_02.jpg',     '/uploads/event_02.jpg',     'image/jpeg', 5720800, 1762575180, 15,  6, 'album_8', 'usr_admin_2');

-- Metadata (1-to-1 EXIF records for all 28 images)
INSERT INTO METADATA (image_id, meta_id, camera, lens, exposure, aperture, focal_length, shutter_speed, date_taken) VALUES
('img_001', 'meta_001', 'Sony a9',     'Sony FE 70-200mm f/4 G OSS',    '0 EV',    'f/4.0', 200, '1/2000', 1760749200),
('img_002', 'meta_002', 'Sony a9',     'Sony FE 70-200mm f/4 G OSS',    '+0.3 EV', 'f/4.0', 185, '1/2500', 1760751000),
('img_003', 'meta_003', 'Sony a9',     'Sigma 24-70mm f/2.8 DG DN Art', '0 EV',    'f/2.8',  50, '1/1000', 1760752800),
('img_004', 'meta_004', 'Sony a9',     'Sony FE 70-200mm f/4 G OSS',    '0 EV',    'f/4.0', 200, '1/2000', 1760754600),
('img_005', 'meta_005', 'Sony a7 III', 'Sigma 24-70mm f/2.8 DG DN Art', '+0.3 EV', 'f/2.8',  35, '1/800',  1760756400),

('img_006', 'meta_006', 'Sony a9',     'Sony FE 70-200mm f/4 G OSS',    '-0.3 EV', 'f/4.0', 200, '1/1250', 1759541400),
('img_007', 'meta_007', 'Sony a9',     'Sony FE 70-200mm f/4 G OSS',    '0 EV',    'f/4.0', 160, '1/1600', 1759543200),
('img_008', 'meta_008', 'Sony a9',     'Sigma 24-70mm f/2.8 DG DN Art', '0 EV',    'f/2.8',  70, '1/1000', 1759545000),
('img_009', 'meta_009', 'Sony a7 III', 'Sigma 24-70mm f/2.8 DG DN Art', '+0.3 EV', 'f/2.8',  28, '1/500',  1759546800),

('img_010', 'meta_010', 'Sony a9',     'Sigma 24-70mm f/2.8 DG DN Art', '0 EV',    'f/2.8',  45, '1/1000', 1762995600),
('img_011', 'meta_011', 'Sony a9',     'Sigma 85mm f/1.4 DG DN Art',    '+0.3 EV', 'f/1.8',  85, '1/1600', 1762997400),
('img_012', 'meta_012', 'Sony a9',     'Sony FE 70-200mm f/4 G OSS',    '0 EV',    'f/4.0', 135, '1/1000', 1762999200),
('img_013', 'meta_013', 'Sony a7 III', 'Sigma 24-70mm f/2.8 DG DN Art', '0 EV',    'f/2.8',  35, '1/640',  1763001000),

('img_014', 'meta_014', 'Sony a9',     'Sigma 85mm f/1.4 DG DN Art',    '-0.3 EV', 'f/1.4',  85, '1/1600', 1758331800),
('img_015', 'meta_015', 'Sony a9',     'Sigma 24-70mm f/2.8 DG DN Art', '0 EV',    'f/5.6',  35, '1/60',   1758333600),
('img_016', 'meta_016', 'Sony a9',     'Sigma 85mm f/1.4 DG DN Art',    '-0.3 EV', 'f/2.0',  85, '1/800',  1758335400),
('img_017', 'meta_017', 'Sony a9',     'Sigma 24-70mm f/2.8 DG DN Art', '0 EV',    'f/2.8',  24, '1/250',  1758337200),

('img_018', 'meta_018', 'Sony a9',     'Sigma 24-70mm f/2.8 DG DN Art', '0 EV',    'f/2.8',  50, '1/1000', 1760146200),
('img_019', 'meta_019', 'Sony a9',     'Sigma 24-70mm f/2.8 DG DN Art', '0 EV',    'f/4.0',  28, '1/640',  1760148000),
('img_020', 'meta_020', 'Sony a7 III', 'Sigma 85mm f/1.4 DG DN Art',    '-0.3 EV', 'f/1.8',  85, '1/1250', 1760149800),

('img_021', 'meta_021', 'Sony a9',     'Sigma 85mm f/1.4 DG DN Art',    '+0.3 EV', 'f/1.4',  85, '1/2000', 1761355800),
('img_022', 'meta_022', 'Sony a9',     'Sigma 85mm f/1.4 DG DN Art',    '0 EV',    'f/1.4',  85, '1/2500', 1761357600),
('img_023', 'meta_023', 'Sony a9',     'Sigma 85mm f/1.4 DG DN Art',    '0 EV',    'f/2.0',  85, '1/500',  1761359400),

('img_024', 'meta_024', 'Sony a9',     'Sigma 24-70mm f/2.8 DG DN Art', '-0.7 EV', 'f/8.0',  24, '1/125',  1761958800),
('img_025', 'meta_025', 'Sony a9',     'Sigma 24-70mm f/2.8 DG DN Art', '-0.3 EV', 'f/8.0',  35, '1/200',  1761960600),
('img_026', 'meta_026', 'Sony a7 III', 'Sony FE 70-200mm f/4 G OSS',    '0 EV',    'f/5.6', 120, '1/320',  1761962400),

('img_027', 'meta_027', 'Sony a9',     'Sigma 24-70mm f/2.8 DG DN Art', '0 EV',    'f/2.8',  35, '1/250',  1762565400),
('img_028', 'meta_028', 'Sony a7 III', 'Sigma 24-70mm f/2.8 DG DN Art', '+0.3 EV', 'f/2.8',  24, '1/160',  1762567200);

-- Tags
INSERT INTO TAG (tag_id, tag_name, tag_value) VALUES
('tag_01', 'sport',    'Football'),
('tag_02', 'sport',    'Soccer'),
('tag_03', 'sport',    'Basketball'),
('tag_04', 'season',   'Fall 2026'),
('tag_05', 'lighting', 'Golden Hour'),
('tag_06', 'lighting', 'Night Stadium'),
('tag_07', 'lighting', 'Indoor Arena'),
('tag_08', 'subject',  'Action'),
('tag_09', 'subject',  'Car'),
('tag_10', 'subject',  'Portrait'),
('tag_11', 'subject',  'Landscape'),
('tag_12', 'vehicle',  'Subaru BRZ'),
('tag_13', 'featured', 'Yes'),
('tag_14', 'role',     'Cover'),
('tag_15', 'style',    'Black & White'),
('tag_16', 'event',    'Homecoming'),
('tag_17', 'event',    'NCAA'),
('tag_18', 'event',    'Campus Showcase');

-- Album_Tag Associations
INSERT INTO Album_Tag (album_id, tag_id) VALUES
('album_fb_homecoming',  'tag_01'),
('album_fb_homecoming',  'tag_04'),
('album_fb_homecoming',  'tag_16'),
('album_fb_homecoming',  'tag_17'),
('album_soc_night',      'tag_02'),
('album_soc_night',      'tag_06'),
('album_soc_night',      'tag_17'),
('album_bball_opener',   'tag_03'),
('album_bball_opener',   'tag_07'),
('album_bball_opener',   'tag_17'),
('album_brz_golden',     'tag_05'),
('album_brz_golden',     'tag_09'),
('album_brz_golden',     'tag_12'),
('album_cars_coffee',    'tag_04'),
('album_cars_coffee',    'tag_09'),
('album_grad_portraits', 'tag_04'),
('album_grad_portraits', 'tag_05'),
('album_grad_portraits', 'tag_10'),
('album_ozark_sunrise',  'tag_05'),
('album_ozark_sunrise',  'tag_11'),
('album_miner_showcase', 'tag_04'),
('album_miner_showcase', 'tag_18');

-- Image_Tag Associations (including role:Cover on album cover shots)
INSERT INTO Image_Tag (image_id, tag_id) VALUES
('img_001', 'tag_01'), ('img_001', 'tag_08'), ('img_001', 'tag_14'),
('img_002', 'tag_01'), ('img_002', 'tag_08'), ('img_002', 'tag_13'),
('img_004', 'tag_01'), ('img_004', 'tag_08'),
('img_005', 'tag_16'), ('img_005', 'tag_13'),
('img_006', 'tag_02'), ('img_006', 'tag_06'), ('img_006', 'tag_14'),
('img_007', 'tag_02'), ('img_007', 'tag_08'), ('img_007', 'tag_13'),
('img_010', 'tag_03'), ('img_010', 'tag_07'), ('img_010', 'tag_14'),
('img_011', 'tag_03'), ('img_011', 'tag_08'), ('img_011', 'tag_13'),
('img_014', 'tag_05'), ('img_014', 'tag_09'), ('img_014', 'tag_12'), ('img_014', 'tag_13'), ('img_014', 'tag_14'),
('img_015', 'tag_08'), ('img_015', 'tag_09'), ('img_015', 'tag_12'), ('img_015', 'tag_13'),
('img_016', 'tag_09'), ('img_016', 'tag_12'),
('img_018', 'tag_09'), ('img_018', 'tag_14'),
('img_019', 'tag_09'),
('img_021', 'tag_05'), ('img_021', 'tag_10'), ('img_021', 'tag_14'),
('img_022', 'tag_05'), ('img_022', 'tag_10'), ('img_022', 'tag_13'),
('img_023', 'tag_10'), ('img_023', 'tag_15'),
('img_024', 'tag_05'), ('img_024', 'tag_11'), ('img_024', 'tag_13'), ('img_024', 'tag_14'),
('img_025', 'tag_11'),
('img_027', 'tag_14'), ('img_027', 'tag_18');
