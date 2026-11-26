# Web Accessible Image Database System (Group N715)

A photography portfolio and image management web application built with **MariaDB/MySQL** and **PHP 8+**. It organizes photos into a hierarchical structure (**Collection → Category → Album → Image**) alongside camera EXIF metadata, custom key-value tags, role-based user accounts, usage analytics, and CSV/JSON import/export.

---

## Project Structure

```text
├── config/
│   └── db.php              # Centralized PDO database connection helper
├── sql/
│   └── init_database.sql   # Creates database, app user, 11 tables, indexes, and seed data
├── uploads/                # Full-resolution uploaded images (automatically created)
├── thumbs/                 # Auto-generated JPEG thumbnails (automatically created)
└── server.php              # Single front-controller REST API and static media server
```

---

## Quick Setup

### 1. Prerequisites
* **MariaDB** or **MySQL**
* **PHP** with the following extensions enabled: `pdo_mysql`, `gd`, `exif`, and `zip`

### 2. Initialize the Database
Run the initialization script from the project root to create the `n715_image_db` database, configure the `n715_app` database user (`N715_ImgDB_2026!`), create all 11 relational tables, and load sample data:

```bash
mariadb -u root < sql/init_database.sql
```

### 3. Start the Backend Server
Use PHP's built-in development server from the project root. Use the `-d` flag to increase the upload limit to `64M` so higher resolution photos upload:

```bash
php -d upload_max_filesize=64M -d post_max_size=64M -S localhost:3000 server.php
```

The API is now available at `http://localhost:3000/api/...`.

---

## Demo Accounts

| Role | Username | Password | Capabilities |
| :--- | :--- | :--- | :--- |
| **Admin** | `admin_aiden` | `admin123` | Full read/write access (create albums, upload photos, edit/delete content, bulk import) |
| **Admin** | `admin_wesley` | `admin123` | Full read/write access (create albums, upload photos, edit/delete content, bulk import) |
| **General User** | `viewer_claude` | `viewer123` | Browse, search, view EXIF details, like/download photos, add tags, export data |
| **General User** | `viewer_guest` | `viewer123` | Browse, search, view EXIF details, like/download photos, add tags, export data |

---

## How It Works

* **Database Layer (`sql/init_database.sql` & `config/db.php`):**
  The relational schema has 11 InnoDB tables (`COLLECTION`, `CATEGORY`, `ALBUM`, `USER`, `ADMIN`, `GENERAL_USER`, `IMAGE`, `METADATA`, `TAG`, `Album_Tag`, and `Image_Tag`) with foreign key cascades, `CHECK` constraints, and indexes on common search and sort columns. `config/db.php` reads environment variables (`DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`) with fallbacks so credentials stay consolodated to one file.
* **API & Media Routing (`server.php`):**
  All `/api/*` requests route through `server.php` using PDO statements and PHP sessions (`PHPSESSID`). Static files under `/uploads/*` and `/thumbs/*` are provided safely, generating thumbnails on demand via GD if missing.
* **Uploads & EXIF Extraction:**
  When an admin uploads images via `POST /api/upload`, `server.php` saves the original file, generates a 300px thumbnail, reads embedded camera EXIF fields (`camera`, `lens`, `aperture`, `focal_length`, `shutter_speed`, `exposure`, `date_taken`), and commits both `IMAGE` and `METADATA` rows inside a database transaction.

---

## API Overview

* **Auth & Session:** `POST /api/login`, `POST /api/logout`, `POST /api/register`, `GET /api/me`
* **Hierarchy & Browsing:** `GET /api/db`, `GET /api/collections`, `GET /api/categories`, `GET /api/albums`
* **Search, Sort & Details:** `GET /api/search` (supports keyword `q`, `category_id`, `album_id`, `location`, `camera`, `lens`, `tag_value`, focal length/date ranges, and `sort`/`order`), `GET /api/image?id=<image_id>`
* **Interactions, Stats & Export:** `POST /api/interact`, `GET /api/download-album?id=<album_id>`, `GET /api/tags`, `POST /api/tags`, `GET /api/stats`, `GET /api/export?format=csv|json`
* **Admin Operations:** `POST /api/upload`, `POST /api/albums`, `PUT /api/edit`, `DELETE /api/delete`, `POST /api/import`
