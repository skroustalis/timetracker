-- Time Tracker — βάση δεδομένων (MariaDB 10.x / MySQL 5.7+)
-- Το install.php τρέχει αυτό το αρχείο αυτόματα. Αν θέλεις, μπορείς να το περάσεις και από phpMyAdmin (Import).

CREATE TABLE IF NOT EXISTS tt_users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  session_ver INT UNSIGNED NOT NULL DEFAULT 0,
  role ENUM('admin','manager','member') NOT NULL DEFAULT 'member',
  lang VARCHAR(5) NULL,
  theme VARCHAR(10) NULL,
  bg VARCHAR(20) NULL,
  avatar VARCHAR(40) NULL,
  hourly_rate DECIMAL(10,2) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  last_login_at DATETIME NULL,
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tt_groups (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  color VARCHAR(7) NOT NULL DEFAULT '#03a9f4',
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_groups_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tt_group_members (
  group_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (group_id, user_id),
  UNIQUE KEY uq_gm_user (user_id),
  CONSTRAINT fk_gm_group FOREIGN KEY (group_id) REFERENCES tt_groups(id) ON DELETE CASCADE,
  CONSTRAINT fk_gm_user FOREIGN KEY (user_id) REFERENCES tt_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tt_clients (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  archived TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_clients_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tt_projects (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  client_id INT UNSIGNED NULL,
  group_id INT UNSIGNED NULL,
  color VARCHAR(7) NOT NULL DEFAULT '#03a9f4',
  billable TINYINT(1) NOT NULL DEFAULT 1,
  hourly_rate DECIMAL(10,2) NULL,
  budget_hours DECIMAL(10,2) NULL,
  budget_period ENUM('total','month') NOT NULL DEFAULT 'total',
  budget_notify TINYINT(1) NOT NULL DEFAULT 0,
  budget_alert_key VARCHAR(10) NULL,
  budget_alert_level SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  visibility ENUM('public','groups') NOT NULL DEFAULT 'public',
  archived TINYINT(1) NOT NULL DEFAULT 0,
  note TEXT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_projects_client (client_id),
  KEY idx_projects_group (group_id),
  CONSTRAINT fk_projects_client FOREIGN KEY (client_id) REFERENCES tt_clients(id) ON DELETE SET NULL,
  CONSTRAINT fk_projects_group FOREIGN KEY (group_id) REFERENCES tt_groups(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tt_project_groups (
  project_id INT UNSIGNED NOT NULL,
  group_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (project_id, group_id),
  KEY idx_pg_group (group_id),
  CONSTRAINT fk_pg_project FOREIGN KEY (project_id) REFERENCES tt_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_pg_group FOREIGN KEY (group_id) REFERENCES tt_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tt_tasks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL,
  name VARCHAR(150) NOT NULL,
  done TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  KEY idx_tasks_project (project_id),
  CONSTRAINT fk_tasks_project FOREIGN KEY (project_id) REFERENCES tt_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tt_tags (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  group_id INT UNSIGNED NULL,
  name VARCHAR(80) NOT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_tags_group_name (group_id, name),
  CONSTRAINT fk_tags_group FOREIGN KEY (group_id) REFERENCES tt_groups(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tt_entries (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  project_id INT UNSIGNED NULL,
  group_id INT UNSIGNED NULL,
  task_id INT UNSIGNED NULL,
  description VARCHAR(500) NOT NULL DEFAULT '',
  billable TINYINT(1) NOT NULL DEFAULT 0,
  start_at DATETIME NOT NULL,
  end_at DATETIME NULL,
  duration INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY idx_entries_user_start (user_id, start_at),
  KEY idx_entries_start_cov (start_at, user_id, project_id, billable, duration, end_at),
  KEY idx_entries_end (end_at),
  KEY idx_entries_proj_user (project_id, user_id, start_at, duration, end_at),
  KEY idx_entries_running (user_id, end_at),
  KEY idx_entries_group (group_id),
  CONSTRAINT fk_entries_user FOREIGN KEY (user_id) REFERENCES tt_users(id) ON DELETE CASCADE,
  CONSTRAINT fk_entries_project FOREIGN KEY (project_id) REFERENCES tt_projects(id) ON DELETE SET NULL,
  CONSTRAINT fk_entries_task FOREIGN KEY (task_id) REFERENCES tt_tasks(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tt_entry_tags (
  entry_id INT UNSIGNED NOT NULL,
  tag_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (entry_id, tag_id),
  KEY idx_et_tag (tag_id),
  CONSTRAINT fk_et_entry FOREIGN KEY (entry_id) REFERENCES tt_entries(id) ON DELETE CASCADE,
  CONSTRAINT fk_et_tag FOREIGN KEY (tag_id) REFERENCES tt_tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tt_remember_tokens (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  selector CHAR(24) NOT NULL,
  validator_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  UNIQUE KEY uq_rt_selector (selector),
  KEY idx_rt_user (user_id),
  CONSTRAINT fk_rt_user FOREIGN KEY (user_id) REFERENCES tt_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tt_login_attempts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  email VARCHAR(190) NOT NULL DEFAULT '',
  attempted_at DATETIME NOT NULL,
  KEY idx_la_ip (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tt_password_tokens (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  purpose ENUM('invite','reset') NOT NULL DEFAULT 'invite',
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_pt_token (token_hash),
  KEY idx_pt_user (user_id),
  CONSTRAINT fk_pt_user FOREIGN KEY (user_id) REFERENCES tt_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tt_notifications (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  type VARCHAR(30) NOT NULL,
  data TEXT NULL,
  link VARCHAR(255) NULL,
  read_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  KEY idx_notif_user (user_id, read_at, id),
  KEY idx_notif_created (created_at),
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES tt_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tt_settings (
  name VARCHAR(50) NOT NULL PRIMARY KEY,
  value TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tt_budget_seen (
  user_id INT UNSIGNED NOT NULL,
  project_id INT UNSIGNED NOT NULL,
  alert_key VARCHAR(40) NOT NULL,
  level SMALLINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (user_id, project_id, alert_key, level),
  KEY idx_bs_project (project_id),
  CONSTRAINT fk_bs_user FOREIGN KEY (user_id) REFERENCES tt_users(id) ON DELETE CASCADE,
  CONSTRAINT fk_bs_project FOREIGN KEY (project_id) REFERENCES tt_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tt_budget_dismiss (
  user_id INT UNSIGNED NOT NULL,
  project_id INT UNSIGNED NOT NULL,
  tag_id INT UNSIGNED NOT NULL DEFAULT 0,
  alert_key VARCHAR(40) NOT NULL,
  level SMALLINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (user_id, project_id, tag_id),
  KEY idx_bd_project (project_id),
  CONSTRAINT fk_bd_user FOREIGN KEY (user_id) REFERENCES tt_users(id) ON DELETE CASCADE,
  CONSTRAINT fk_bd_project FOREIGN KEY (project_id) REFERENCES tt_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ώρες ανά tag μέσα σε project (budget_hours NULL = μόνο μέτρηση)
CREATE TABLE IF NOT EXISTS tt_project_tag_budgets (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL,
  tag_id INT UNSIGNED NOT NULL,
  budget_hours DECIMAL(10,4) NULL,
  start_at DATETIME NULL,
  carry_secs INT UNSIGNED NOT NULL DEFAULT 0,
  package_no INT UNSIGNED NOT NULL DEFAULT 1,
  alert_key VARCHAR(40) NULL,
  alert_level SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_ptb (project_id, tag_id),
  KEY idx_ptb_tag (tag_id),
  CONSTRAINT fk_ptb_project FOREIGN KEY (project_id) REFERENCES tt_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_ptb_tag FOREIGN KEY (tag_id) REFERENCES tt_tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ιστορικό «πακέτων» ανά tag: κάθε μηδενισμός κλείνει ένα πακέτο
CREATE TABLE IF NOT EXISTS tt_tag_budget_history (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL,
  tag_id INT UNSIGNED NOT NULL,
  package_no INT UNSIGNED NOT NULL,
  budget_hours DECIMAL(10,4) NULL,
  start_at DATETIME NULL,
  end_at DATETIME NOT NULL,
  carry_in_secs INT UNSIGNED NOT NULL DEFAULT 0,
  used_secs INT UNSIGNED NOT NULL DEFAULT 0,
  carry_out_secs INT UNSIGNED NOT NULL DEFAULT 0,
  reset_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  KEY idx_tbh_line (project_id, tag_id, id),
  KEY idx_tbh_tag (tag_id),
  KEY idx_tbh_user (reset_by),
  CONSTRAINT fk_tbh_project FOREIGN KEY (project_id) REFERENCES tt_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_tbh_tag FOREIGN KEY (tag_id) REFERENCES tt_tags(id) ON DELETE CASCADE,
  CONSTRAINT fk_tbh_user FOREIGN KEY (reset_by) REFERENCES tt_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
