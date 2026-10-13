<?php
declare(strict_types=1);

/**
 * Αυτόματες αναβαθμίσεις βάσης. Τρέχει μία φορά ανά συνεδρία όταν αλλάζει η έκδοση.
 * Κάθε βήμα πρέπει να είναι ασφαλές να ξανατρέξει.
 */
const SCHEMA_VERSION = 21;

function run_migrations(): void
{
    if (($_SESSION['schema_v'] ?? 0) === SCHEMA_VERSION) return;

    // v2: γλώσσα ανά χρήστη
    if (!q_one("SHOW COLUMNS FROM tt_users LIKE 'lang'")) {
        db()->exec("ALTER TABLE tt_users ADD COLUMN lang VARCHAR(5) NULL AFTER role");
    }

    // v3: budget ανά περίοδο + ειδοποιήσεις
    if (!q_one("SHOW COLUMNS FROM tt_projects LIKE 'budget_period'")) {
        db()->exec("ALTER TABLE tt_projects
            ADD COLUMN budget_period ENUM('total','month') NOT NULL DEFAULT 'total' AFTER budget_hours,
            ADD COLUMN budget_notify TINYINT(1) NOT NULL DEFAULT 0 AFTER budget_period,
            ADD COLUMN budget_alert_key VARCHAR(10) NULL AFTER budget_notify,
            ADD COLUMN budget_alert_level TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER budget_alert_key");
    }

    // v4: θέμα εμφάνισης ανά χρήστη
    if (!q_one("SHOW COLUMNS FROM tt_users LIKE 'theme'")) {
        db()->exec("ALTER TABLE tt_users ADD COLUMN theme VARCHAR(10) NULL AFTER lang");
    }

    // v5: φόντο ανά χρήστη
    if (!q_one("SHOW COLUMNS FROM tt_users LIKE 'bg'")) {
        db()->exec("ALTER TABLE tt_users ADD COLUMN bg VARCHAR(20) NULL AFTER theme");
    }

    // v6: φωτογραφία προφίλ
    if (!q_one("SHOW COLUMNS FROM tt_users LIKE 'avatar'")) {
        db()->exec("ALTER TABLE tt_users ADD COLUMN avatar VARCHAR(40) NULL AFTER bg");
    }

    // v7: covering index για γρήγορα σύνολα ανά project / χρήστη
    if (!q_one("SHOW INDEX FROM tt_entries WHERE Key_name = 'idx_entries_proj_user'")) {
        db()->exec("ALTER TABLE tt_entries ADD INDEX idx_entries_proj_user (project_id, user_id, start_at, duration, end_at)");
        try { db()->exec("ALTER TABLE tt_entries DROP INDEX idx_entries_project"); } catch (Throwable $e) { /* δεν πειράζει */ }
    }

    // v8: covering index για αναφορές ανά περίοδο + index για ό,τι τρέχει
    if (!q_one("SHOW INDEX FROM tt_entries WHERE Key_name = 'idx_entries_start_cov'")) {
        db()->exec("ALTER TABLE tt_entries ADD INDEX idx_entries_start_cov (start_at, user_id, project_id, billable, duration, end_at)");
        try { db()->exec("ALTER TABLE tt_entries DROP INDEX idx_entries_start"); } catch (Throwable $e) { /* δεν πειράζει */ }
    }
    if (!q_one("SHOW INDEX FROM tt_entries WHERE Key_name = 'idx_entries_end'")) {
        db()->exec("ALTER TABLE tt_entries ADD INDEX idx_entries_end (end_at)");
    }

    // v9: links ορισμού κωδικού (πρόσκληση / νέος κωδικός)
    db()->exec("CREATE TABLE IF NOT EXISTS tt_password_tokens (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        token_hash CHAR(64) NOT NULL,
        purpose ENUM('invite','reset') NOT NULL DEFAULT 'invite',
        expires_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY uq_pt_token (token_hash),
        KEY idx_pt_user (user_id),
        CONSTRAINT fk_pt_user FOREIGN KEY (user_id) REFERENCES tt_users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // v10: ρόλος manager, project ανά ομάδα, ένας χρήστης σε μία μόνο ομάδα
    $roleCol = q_one("SHOW COLUMNS FROM tt_users LIKE 'role'");
    if ($roleCol && !str_contains((string)$roleCol['Type'], 'manager')) {
        db()->exec("ALTER TABLE tt_users MODIFY role ENUM('admin','manager','member') NOT NULL DEFAULT 'member'");
    }
    if (!q_one("SHOW COLUMNS FROM tt_projects LIKE 'group_id'")) {
        db()->exec("ALTER TABLE tt_projects ADD COLUMN group_id INT UNSIGNED NULL AFTER client_id, ADD KEY idx_projects_group (group_id),
                    ADD CONSTRAINT fk_projects_group FOREIGN KEY (group_id) REFERENCES tt_groups(id) ON DELETE SET NULL");
    }
    if (!q_one("SHOW INDEX FROM tt_group_members WHERE Key_name = 'uq_gm_user'")) {
        // όποιος ήταν σε πολλές ομάδες κρατά την παλαιότερη (μικρότερο id)
        db()->exec("DELETE gm FROM tt_group_members gm JOIN tt_group_members g2 ON g2.user_id = gm.user_id AND g2.group_id < gm.group_id");
        db()->exec("ALTER TABLE tt_group_members ADD UNIQUE KEY uq_gm_user (user_id)");
    }

    // v11: ειδοποιήσεις (καμπανάκι)
    db()->exec("CREATE TABLE IF NOT EXISTS tt_notifications (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // v12: ρυθμίσεις εφαρμογής (π.χ. αυτόματο σταμάτημα) + αφαίρεση της ημερήσιας υπενθύμισης
    db()->exec("CREATE TABLE IF NOT EXISTS tt_settings (
        name VARCHAR(50) NOT NULL PRIMARY KEY,
        value TEXT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("DELETE FROM tt_notifications WHERE type = 'reminder'");

    // v13: ποιος έχει ήδη ειδοποιηθεί για ποιο όριο ωρών (για να μη διπλοστέλνεται)
    db()->exec("CREATE TABLE IF NOT EXISTS tt_budget_seen (
        user_id INT UNSIGNED NOT NULL,
        project_id INT UNSIGNED NOT NULL,
        alert_key VARCHAR(40) NOT NULL,
        level TINYINT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (user_id, project_id, alert_key, level),
        KEY idx_bs_project (project_id),
        CONSTRAINT fk_bs_user FOREIGN KEY (user_id) REFERENCES tt_users(id) ON DELETE CASCADE,
        CONSTRAINT fk_bs_project FOREIGN KEY (project_id) REFERENCES tt_projects(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // v14: ειδοποίηση ανά «βήμα» πάνω από το όριο (επίπεδα 101, 102…) + καθαρισμός από τον πίνακα ορίων
    $lv = q_one("SHOW COLUMNS FROM tt_budget_seen LIKE 'level'");
    if ($lv && str_contains(strtolower((string)$lv['Type']), 'tinyint')) {
        db()->exec("ALTER TABLE tt_budget_seen MODIFY level SMALLINT UNSIGNED NOT NULL");
    }
    $lv = q_one("SHOW COLUMNS FROM tt_projects LIKE 'budget_alert_level'");
    if ($lv && str_contains(strtolower((string)$lv['Type']), 'tinyint')) {
        db()->exec("ALTER TABLE tt_projects MODIFY budget_alert_level SMALLINT UNSIGNED NOT NULL DEFAULT 0");
    }
    db()->exec("CREATE TABLE IF NOT EXISTS tt_budget_dismiss (
        user_id INT UNSIGNED NOT NULL,
        project_id INT UNSIGNED NOT NULL,
        alert_key VARCHAR(40) NOT NULL,
        level SMALLINT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (user_id, project_id),
        KEY idx_bd_project (project_id),
        CONSTRAINT fk_bd_user FOREIGN KEY (user_id) REFERENCES tt_users(id) ON DELETE CASCADE,
        CONSTRAINT fk_bd_project FOREIGN KEY (project_id) REFERENCES tt_projects(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // v15: καταργούνται οι πελάτες. Κάθε πελάτης γίνεται project (αν δεν υπάρχει ήδη project με το ίδιο όνομα),
    // τα projects μένουν όπως είναι χωρίς πελάτη. Ασφαλές να ξανατρέξει: όταν δεν υπάρχουν πελάτες δεν κάνει τίποτα.
    if (q_val('SELECT 1 FROM tt_clients LIMIT 1')) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $n = (int)q_val('SELECT COUNT(*) FROM tt_projects');
            foreach (q_all('SELECT id, name, archived FROM tt_clients ORDER BY id') as $c) {
                if (!q_val('SELECT 1 FROM tt_projects WHERE name = ? LIMIT 1', [$c['name']])) {
                    q('INSERT INTO tt_projects (name, color, billable, archived, created_at) VALUES (?, ?, 1, ?, ?)',
                        [$c['name'], PALETTE[$n++ % count(PALETTE)], (int)$c['archived'], now_sql()]);
                }
            }
            $pdo->exec('UPDATE tt_projects SET client_id = NULL WHERE client_id IS NOT NULL');
            $pdo->exec('DELETE FROM tt_clients');
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // v16: όρια ωρών ανά tag μέσα σε project (π.χ. «support» 5 ώρες, «bridge» χωρίς όριο, μόνο μέτρηση)
    db()->exec("CREATE TABLE IF NOT EXISTS tt_project_tag_budgets (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        project_id INT UNSIGNED NOT NULL,
        tag_id INT UNSIGNED NOT NULL,
        budget_hours DECIMAL(10,2) NULL,
        alert_key VARCHAR(40) NULL,
        alert_level SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        UNIQUE KEY uq_ptb (project_id, tag_id),
        KEY idx_ptb_tag (tag_id),
        CONSTRAINT fk_ptb_project FOREIGN KEY (project_id) REFERENCES tt_projects(id) ON DELETE CASCADE,
        CONSTRAINT fk_ptb_tag FOREIGN KEY (tag_id) REFERENCES tt_tags(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // ο «καθαρισμός» από το dashboard κρατιέται και ανά tag
    if (!q_one("SHOW COLUMNS FROM tt_budget_dismiss LIKE 'tag_id'")) {
        db()->exec("ALTER TABLE tt_budget_dismiss
            ADD COLUMN tag_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER project_id,
            DROP PRIMARY KEY,
            ADD PRIMARY KEY (user_id, project_id, tag_id)");
    }

    // v17: τα tags ανήκουν σε ομάδα (Developers / Marketing κ.λπ.: πλήρης διαχωρισμός)
    if (!q_one("SHOW COLUMNS FROM tt_tags LIKE 'group_id'")) {
        db()->exec("ALTER TABLE tt_tags
            ADD COLUMN group_id INT UNSIGNED NULL AFTER id,
            DROP INDEX uq_tags_name,
            ADD UNIQUE KEY uq_tags_group_name (group_id, name),
            ADD CONSTRAINT fk_tags_group FOREIGN KEY (group_id) REFERENCES tt_groups(id) ON DELETE SET NULL");
    }
    // Μία φορά: ό,τι υπάρχει σήμερα ανήκει στους Developers (χρήστες χωρίς ομάδα, projects, tags).
    // Άλλες ομάδες εκτός από Developers / Marketing ενώνονται στους Developers.
    if (setting('dept_setup') === null) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $dev = (int)q_val("SELECT id FROM tt_groups WHERE LOWER(name) = 'developers' LIMIT 1");
            if (!$dev) $dev = (int)q_insert('INSERT INTO tt_groups (name, color, created_at) VALUES (?, ?, ?)', ['Developers', '#e91e63', now_sql()]);
            foreach (q_all("SELECT id FROM tt_groups WHERE LOWER(name) NOT IN ('developers', 'marketing')") as $g) {
                q('INSERT IGNORE INTO tt_group_members (group_id, user_id) SELECT ?, user_id FROM tt_group_members WHERE group_id = ?', [$dev, (int)$g['id']]);
                q('UPDATE tt_projects SET group_id = ? WHERE group_id = ?', [$dev, (int)$g['id']]);
                q('DELETE FROM tt_groups WHERE id = ?', [(int)$g['id']]);
            }
            q('INSERT INTO tt_group_members (group_id, user_id) SELECT ?, u.id FROM tt_users u WHERE NOT EXISTS (SELECT 1 FROM tt_group_members gm WHERE gm.user_id = u.id)', [$dev]);
            q('UPDATE tt_projects SET group_id = ? WHERE group_id IS NULL', [$dev]);
            q("UPDATE tt_projects SET visibility = 'groups'");
            $pdo->exec('DELETE FROM tt_project_groups');
            $pdo->exec('INSERT INTO tt_project_groups (project_id, group_id) SELECT id, group_id FROM tt_projects WHERE group_id IS NOT NULL');
            q('UPDATE tt_tags SET group_id = ? WHERE group_id IS NULL', [$dev]);
            set_setting('dept_setup', '1');
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // v18: καταχωρήσεις χωρίς project κρατούν την ομάδα όπου γράφτηκαν, όταν ο χρήστης αλλάξει ομάδα
    if (!q_one("SHOW COLUMNS FROM tt_entries LIKE 'group_id'")) {
        db()->exec("ALTER TABLE tt_entries ADD COLUMN group_id INT UNSIGNED NULL AFTER project_id, ADD KEY idx_entries_group (group_id)");
    }

    // v19: αλλαγή κωδικού κλείνει τις συνδέσεις στις άλλες συσκευές
    if (!q_one("SHOW COLUMNS FROM tt_users LIKE 'session_ver'")) {
        db()->exec("ALTER TABLE tt_users ADD COLUMN session_ver INT UNSIGNED NOT NULL DEFAULT 0 AFTER password_hash");
    }

    // v20: «πακέτα» ωρών ανά tag: μετράει από μια ημερομηνία, μεταφορά υπέρβασης, ιστορικό μηδενισμών.
    // Μόνο προσθήκες: καμία καταχώρηση χρόνου δεν αλλάζει.
    if (!q_one("SHOW COLUMNS FROM tt_project_tag_budgets LIKE 'start_at'")) {
        db()->exec("ALTER TABLE tt_project_tag_budgets
            MODIFY budget_hours DECIMAL(10,4) NULL,
            ADD COLUMN start_at DATETIME NULL AFTER budget_hours,
            ADD COLUMN carry_secs INT UNSIGNED NOT NULL DEFAULT 0 AFTER start_at,
            ADD COLUMN package_no INT UNSIGNED NOT NULL DEFAULT 1 AFTER carry_secs");
    }
    db()->exec("CREATE TABLE IF NOT EXISTS tt_tag_budget_history (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // v21: μία φορά, μηδενίζει τις μεταφορές που έγιναν από πακέτο «από την αρχή» (v1.9.25 μετέφερε όλο το παλιό ιστορικό)
    if (setting('tag_carry_fix') === null) {
        db()->exec('UPDATE tt_project_tag_budgets SET carry_secs = 0, alert_key = NULL, alert_level = 0 WHERE carry_secs > 0');
        set_setting('tag_carry_fix', '1');
    }

    $_SESSION['schema_v'] = SCHEMA_VERSION;
}
