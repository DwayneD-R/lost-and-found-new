-- =========================================================
-- Lost and Found Web Application - Holy Child College of Davao
-- Database Schema (bare-minimum working version)
-- =========================================================

CREATE DATABASE IF NOT EXISTS lost_and_found
  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

USE lost_and_found;

-- ---------------------------------------------------------
-- Users (Authentication Module)
-- ---------------------------------------------------------
CREATE TABLE users (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    first_name      VARCHAR(60)  NOT NULL,
    last_name       VARCHAR(60)  NOT NULL,
    email           VARCHAR(120) NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,
    role            ENUM('user','admin') NOT NULL DEFAULT 'user',
    is_verified     TINYINT(1) NOT NULL DEFAULT 0,
    verify_code     VARCHAR(6) DEFAULT NULL,
    verify_expires  DATETIME DEFAULT NULL,
    verify_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0, -- wrong guesses for the current code; at 5 the code is thrown away
    last_active_at  DATETIME DEFAULT NULL, -- stamped while the user is using the site (Manage Users shows it)
    email_matches       TINYINT(1) NOT NULL DEFAULT 1, -- Settings: email me possible matches
    email_resolved      TINYINT(1) NOT NULL DEFAULT 1, -- Settings: email me "has your report been resolved?"
    email_conversations TINYINT(1) NOT NULL DEFAULT 1, -- Settings: email me about new conversations
    email_reports       TINYINT(1) NOT NULL DEFAULT 1, -- Settings: email me when my report is edited / closed / reopened
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Default admin account (password: Admin123! -- CHANGE AFTER FIRST LOGIN)
INSERT INTO users (first_name, last_name, email, password_hash, role, is_verified)
VALUES ('Admin', '', 'lostandfound@holychild.edu.ph',
        '$2b$10$lGHpy/yCxQGeZ3/wdMV1MuAWEI4jxAGse.96xFbqHF3f1OeT39/e.', 'admin', 1);

-- The built-in "System Notifications" account: the sender of every automated message (possible
-- matches, status changes...). Messages and conversation participants may become NULL when a user is
-- permanently deleted; snapshot name columns preserve the historical display name. Its password hash is not a valid hash
-- on purpose, so nobody can ever log in as it. Its email must match SYSTEM_USER_EMAIL in
-- includes/functions.php. The app only looks it up; it never creates it.
INSERT INTO users (first_name, last_name, email, password_hash, role, is_verified)
VALUES ('System', 'Notifications', 'system@lost-and-found.internal', '!', 'user', 1);

-- ---------------------------------------------------------
-- Reports (Reporting Module)
-- ---------------------------------------------------------
CREATE TABLE reports (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NULL,       -- NULL for imported reports without an owner, or after owner deletion
    type            ENUM('lost','found') NOT NULL,
    item_name       VARCHAR(120) NOT NULL,
    category        VARCHAR(60)  NOT NULL,
    description     TEXT,
    location        VARCHAR(150) NOT NULL,
    item_date       DATE NOT NULL,
    image_path      VARCHAR(255) DEFAULT NULL,
    status          ENUM('active','resolved','closed') NOT NULL DEFAULT 'active',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at      DATETIME DEFAULT NULL,
    reopened_at     DATETIME DEFAULT NULL, -- set when a report becomes Active again; its 30 days start over from here
    resolved_asked_at DATETIME DEFAULT NULL, -- when the owner was last asked "has it been resolved?" (automatic or by an admin)
    resolved_by_user_id INT NULL,            -- who found / claimed the item, when that person has an account (see update_status.php)
    resolved_by_name    VARCHAR(120) DEFAULT NULL, -- ...and their name as it was when the report was resolved (kept if the account is later deleted, or if they never had one)
    resolved_at     DATETIME DEFAULT NULL,
    version         INT NOT NULL DEFAULT 1,     -- bumped by every edit of the details; stops two people's edits overwriting each other
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, -- changes on ANY change; the live stream watches it
    INDEX idx_reports_updated (updated_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (resolved_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Who is online: one row per open page (kept fresh by ajax/stream.php). A user is online while any
-- of their rows was refreshed in the last ONLINE_WINDOW_SECONDS. Closing a tab sends a "leave" beacon
-- (ajax/leave.php) that stamps left_at and lets the row go stale at once; if the beacon never arrives
-- the row simply goes stale by itself. Closing every tab of a login session ends that session
-- (see is_logged_in() in includes/functions.php).
CREATE TABLE presence (
    conn       CHAR(16) NOT NULL PRIMARY KEY,
    user_id    INT NOT NULL,
    session_id VARCHAR(64) NOT NULL DEFAULT '',   -- which login session this page belongs to
    last_seen  DATETIME NOT NULL,
    left_at    DATETIME DEFAULT NULL,             -- set by the leave beacon when the page was closed
    INDEX (user_id),
    INDEX (session_id),
    INDEX (last_seen)
) ENGINE=InnoDB;

-- Things that finish after the page has answered (e.g. an email that couldn't be sent) come back as toasts:
CREATE TABLE toasts (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    owner      VARCHAR(80)  NOT NULL,   -- 'u:<user id>' or 's:<session id>' (a guest)
    message    VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (owner)
) ENGINE=InnoDB;

-- deleted_at implements a soft delete: when an admin "deletes" a
-- report it is stamped with deleted_at instead of being removed, so it
-- disappears from Browse/dashboard/my reports but is still recoverable.
-- Only an admin can permanently remove a row (admin/delete_report_permanent.php),
-- and can export a CSV backup first (admin/export_reports.php).
-- Deleting a USER account never deletes their reports: reports.user_id becomes
-- NULL through ON DELETE SET NULL and the report remains available.

-- ---------------------------------------------------------
-- Conversations (Messaging Module)
-- one conversation = two users talking about one report, OR a user
-- contacting the admin directly (report_id NULL, subject set instead)
-- ---------------------------------------------------------
CREATE TABLE conversations (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    report_id       INT NULL,       -- NULL = a direct "Contact Admin" thread, or a system thread
    subject         VARCHAR(150) DEFAULT NULL, -- used only when report_id IS NULL
    user_a_id       INT NULL,       -- report owner, or the admin/system account
    user_b_id       INT NULL,       -- the one who reached out, or who's being notified
    user_a_name     VARCHAR(120) DEFAULT NULL, -- retained display name if the account is deleted
    user_b_name     VARCHAR(120) DEFAULT NULL,
    is_system       TINYINT(1) NOT NULL DEFAULT 0, -- see note below
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_convo (report_id, user_a_id, user_b_id),
    FOREIGN KEY (report_id) REFERENCES reports(id) ON DELETE CASCADE,
    FOREIGN KEY (user_a_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (user_b_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- is_system marks a user's one standing "System Notification" thread (used
-- for possible-match alerts — see notify_possible_matches() in
-- includes/functions.php). Like a "Contact Admin" thread it has report_id
-- NULL and the seeded admin account as user_a_id, but it must stay
-- distinguishable from that: both contact_admin.php and
-- get_or_create_system_conversation() look up a thread by
-- (report_id IS NULL, user_a_id, user_b_id) alone, and without this flag
-- those two lookups could find and reuse each other's thread. The person
-- can't delete this thread or send messages into it (see messages.php and
-- delete_conversation.php).
--
-- Note: MySQL's UNIQUE KEY treats each NULL as distinct, so it will not by
-- itself stop a user from opening more than one "Contact Admin" thread —
-- contact_admin.php checks for an existing one first and reuses it.

CREATE TABLE messages (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT NOT NULL,
    sender_id       INT NULL,
    sender_name     VARCHAR(120) DEFAULT NULL, -- retained display name if the account is deleted
    body            TEXT,
    image_path      VARCHAR(255) DEFAULT NULL,
    is_read         TINYINT(1) NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at      DATETIME DEFAULT NULL,
    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- deleted_at on messages implements a soft delete, same idea as on reports:
-- the sender's "delete" clears body/image_path and stamps deleted_at rather
-- than removing the row, so the other party still sees a "Deleted message"
-- placeholder in the thread instead of a confusing gap.
