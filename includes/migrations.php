<?php
function migrateApplicationSchema(mysqli $connection): void
{
    $connection->query(
        'CREATE TABLE IF NOT EXISTS schema_migrations (version INT PRIMARY KEY, applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $applied = $connection->query('SELECT MAX(version) AS current_version FROM schema_migrations')->fetch_assoc();
    $currentVersion = (int) ($applied['current_version'] ?? 0);
    if ($currentVersion >= 8) {
        return;
    }
    if ($currentVersion >= 7) {
        migratePermanentDeleteNotificationEvents($connection);
        return;
    }
    if ($currentVersion >= 6) {
        migrateHardDeleteNotificationEvents($connection);
        migratePermanentDeleteNotificationEvents($connection);
        return;
    }
    if ($currentVersion >= 5) {
        migrateNotificationRoutingEvents($connection);
        migrateHardDeleteNotificationEvents($connection);
        migratePermanentDeleteNotificationEvents($connection);
        return;
    }
    if ($currentVersion >= 4) {
        migrateAdminNotificationPreferences($connection);
        migrateNotificationRoutingEvents($connection);
        migrateHardDeleteNotificationEvents($connection);
        migratePermanentDeleteNotificationEvents($connection);
        return;
    }
    if ($currentVersion >= 1) {
        if ($currentVersion < 2) {
            migrateDeletionSchema($connection);
        }
        if ($currentVersion < 3) {
            migrateProjectTranslationSchema($connection);
        }
        migrateOrderTranslationSchema($connection);
        migrateAdminNotificationPreferences($connection);
        migrateNotificationRoutingEvents($connection);
        migrateHardDeleteNotificationEvents($connection);
        migratePermanentDeleteNotificationEvents($connection);
        return;
    }

    $columns = [
        'users' => [
            'username' => "ALTER TABLE users ADD COLUMN username VARCHAR(80) NULL UNIQUE",
            'is_active' => "ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1",
        ],
        'orders' => [
            'order_number' => "ALTER TABLE orders ADD COLUMN order_number VARCHAR(32) NULL UNIQUE",
            'checkout_key' => "ALTER TABLE orders ADD COLUMN checkout_key VARCHAR(64) NULL UNIQUE",
            'guest_token_hash' => "ALTER TABLE orders ADD COLUMN guest_token_hash CHAR(64) NULL",
            'total_amount' => "ALTER TABLE orders ADD COLUMN total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00",
            'payment_status' => "ALTER TABLE orders ADD COLUMN payment_status VARCHAR(30) NOT NULL DEFAULT 'unpaid'",
            'updated_at' => "ALTER TABLE orders ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP",
        ],
        'messages' => [
            'custom_request_id' => "ALTER TABLE messages ADD COLUMN custom_request_id INT NULL",
            'admin_id' => "ALTER TABLE messages ADD COLUMN admin_id INT NULL",
            'sender_type' => "ALTER TABLE messages ADD COLUMN sender_type VARCHAR(20) NOT NULL DEFAULT 'user'",
            'read_by_user' => "ALTER TABLE messages ADD COLUMN read_by_user TINYINT(1) NOT NULL DEFAULT 0",
            'read_by_admin' => "ALTER TABLE messages ADD COLUMN read_by_admin TINYINT(1) NOT NULL DEFAULT 0",
        ],
        'projects' => [
            'is_available' => "ALTER TABLE projects ADD COLUMN is_available TINYINT(1) NOT NULL DEFAULT 1",
        ],
        'custom_requests' => [
            'updated_at' => "ALTER TABLE custom_requests ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP",
        ],
    ];

    foreach ($columns as $table => $tableColumns) {
        foreach ($tableColumns as $column => $sql) {
            $check = $connection->prepare(
                'SELECT COUNT(*) AS total FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
            );
            $check->bind_param('ss', $table, $column);
            $check->execute();
            $exists = (int) $check->get_result()->fetch_assoc()['total'] > 0;
            if (!$exists) {
                $connection->query($sql);
            }
        }
    }

    $connection->query('ALTER TABLE orders MODIFY user_id INT NULL');

    $tables = [
        "CREATE TABLE IF NOT EXISTS order_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            project_id INT NULL,
            project_title VARCHAR(180) NOT NULL,
            unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            is_free TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY order_items_order_idx (order_id),
            CONSTRAINT order_items_order_fk FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS roles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(80) NOT NULL UNIQUE,
            description VARCHAR(255) NULL,
            is_system TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS permissions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            permission_key VARCHAR(100) NOT NULL UNIQUE,
            label VARCHAR(160) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS role_permissions (
            role_id INT NOT NULL,
            permission_id INT NOT NULL,
            PRIMARY KEY (role_id, permission_id),
            CONSTRAINT role_permissions_role_fk FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
            CONSTRAINT role_permissions_permission_fk FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS admin_roles (
            admin_id INT NOT NULL,
            role_id INT NOT NULL,
            PRIMARY KEY (admin_id, role_id),
            CONSTRAINT admin_roles_admin_fk FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT admin_roles_role_fk FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS notifications (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            admin_id INT NULL,
            user_id INT NULL,
            event_key VARCHAR(190) NOT NULL,
            event_type VARCHAR(80) NOT NULL,
            title VARCHAR(180) NOT NULL,
            body VARCHAR(500) NOT NULL,
            target_url VARCHAR(255) NOT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY notification_event_recipient_unique (admin_id, event_key),
            UNIQUE KEY notification_user_event_unique (user_id, event_key),
            KEY notifications_unread_idx (admin_id, is_read, id),
            KEY notifications_user_unread_idx (user_id, is_read, id),
            CONSTRAINT notifications_admin_fk FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT notifications_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS notification_settings (
            event_type VARCHAR(80) PRIMARY KEY,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            recipient_mode VARCHAR(20) NOT NULL DEFAULT 'all'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS notification_recipients (
            event_type VARCHAR(80) NOT NULL,
            admin_id INT NOT NULL,
            PRIMARY KEY (event_type, admin_id),
            CONSTRAINT notification_recipients_admin_fk FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS notification_role_recipients (
            event_type VARCHAR(80) NOT NULL,
            role_id INT NOT NULL,
            PRIMARY KEY (event_type, role_id),
            CONSTRAINT notification_role_recipients_role_fk FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS activity_log (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            actor_id INT NULL,
            action VARCHAR(100) NOT NULL,
            entity_type VARCHAR(80) NOT NULL,
            entity_id BIGINT NULL,
            details VARCHAR(500) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY activity_created_idx (created_at),
            CONSTRAINT activity_actor_fk FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS site_settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value TEXT NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS manual_payments (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            method VARCHAR(80) NOT NULL,
            reference VARCHAR(160) NULL,
            recorded_by INT NOT NULL,
            recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY manual_payments_order_idx (order_id),
            CONSTRAINT manual_payment_order_fk FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
            CONSTRAINT manual_payment_admin_fk FOREIGN KEY (recorded_by) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];

    foreach ($tables as $sql) {
        $connection->query($sql);
    }

    $permissionLabels = [
        'dashboard.view' => 'View dashboard',
        'projects.manage' => 'Manage projects',
        'custom_requests.manage' => 'Manage custom requests',
        'orders.view' => 'Review cart orders',
        'orders.update' => 'Update cart order status',
        'payments.record' => 'Record manual payments',
        'messages.view' => 'View messages',
        'messages.send' => 'Send messages',
        'notifications.manage' => 'Manage notifications',
        'users.manage' => 'Manage users',
        'admins.manage' => 'Manage administrators',
        'roles.manage' => 'Manage roles and permissions',
        'pricing.manage' => 'Manage pricing',
        'content.manage' => 'Manage content settings',
        'activity.view' => 'View activity log',
    ];

    $insertPermission = $connection->prepare('INSERT IGNORE INTO permissions (permission_key, label) VALUES (?, ?)');
    foreach ($permissionLabels as $key => $label) {
        $insertPermission->bind_param('ss', $key, $label);
        $insertPermission->execute();
    }

    foreach (['Super Admin', 'Administrator', 'Cashier'] as $roleName) {
        $insertRole = $connection->prepare('INSERT IGNORE INTO roles (name, description, is_system) VALUES (?, ?, 1)');
        $description = $roleName === 'Cashier' ? 'Can review cart orders, change order status, and record manual payments.' : 'System role managed by Naffezly.';
        $insertRole->bind_param('ss', $roleName, $description);
        $insertRole->execute();
    }

    $roleIds = [];
    $rolesResult = $connection->query("SELECT id, name FROM roles WHERE name IN ('Super Admin', 'Administrator', 'Cashier')");
    while ($role = $rolesResult->fetch_assoc()) {
        $roleIds[$role['name']] = (int) $role['id'];
    }
    $permissionsResult = $connection->query('SELECT id, permission_key FROM permissions');
    $permissionIds = [];
    while ($permission = $permissionsResult->fetch_assoc()) {
        $permissionIds[$permission['permission_key']] = (int) $permission['id'];
    }

    $cashierKeys = ['orders.view', 'orders.update', 'payments.record'];
    foreach ($permissionIds as $key => $permissionId) {
        foreach (['Super Admin', 'Administrator'] as $roleName) {
            $roleId = $roleIds[$roleName];
            $grant = $connection->prepare('INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)');
            $grant->bind_param('ii', $roleId, $permissionId);
            $grant->execute();
        }

        if (in_array($key, $cashierKeys, true)) {
            $roleId = $roleIds['Cashier'];
            $grant = $connection->prepare('INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)');
            $grant->bind_param('ii', $roleId, $permissionId);
            $grant->execute();
        }
    }

    $admins = $connection->query('SELECT id, name, username FROM users WHERE is_admin = 1 ORDER BY id');
    $firstAdmin = true;
    while ($admin = $admins->fetch_assoc()) {
        $adminId = (int) $admin['id'];
        $username = trim((string) $admin['username']);
        if ($username === '') {
            $username = $firstAdmin ? 'Ziad-Word' : 'admin-' . $adminId;
            $update = $connection->prepare('UPDATE users SET username = ? WHERE id = ?');
            $update->bind_param('si', $username, $adminId);
            $update->execute();
        }

        $roleName = $firstAdmin ? 'Super Admin' : 'Administrator';
        $roleId = $roleIds[$roleName];
        $assignment = $connection->prepare('INSERT IGNORE INTO admin_roles (admin_id, role_id) VALUES (?, ?)');
        $assignment->bind_param('ii', $adminId, $roleId);
        $assignment->execute();
        $firstAdmin = false;
    }

    $events = [
        'user.registered', 'user.verified', 'custom_request.created', 'custom_request.updated',
        'custom_request.deleted', 'custom_request.status', 'cart_order.created', 'message.created',
        'project.created', 'project.updated', 'project.published', 'project.hidden', 'admin.role_changed',
        'setting.changed',
    ];
    $insertSetting = $connection->prepare("INSERT IGNORE INTO notification_settings (event_type, enabled, recipient_mode) VALUES (?, 1, 'all')");
    foreach ($events as $event) {
        $insertSetting->bind_param('s', $event);
        $insertSetting->execute();
    }

    $settingDefaults = [
        'site.currency' => 'EGP',
        'site.project_order_enabled' => '1',
        'site.content.home_title_en' => 'Digital projects, built with clarity and trust.',
        'site.content.home_title_ar' => 'مشاريع رقمية واضحة وجاهزة للتنفيذ.',
    ];
    $insertSetting = $connection->prepare('INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES (?, ?)');
    foreach ($settingDefaults as $key => $value) {
        $insertSetting->bind_param('ss', $key, $value);
        $insertSetting->execute();
    }

    $legacyItems = $connection->query(
        'SELECT o.id, o.project_id, COALESCE(o.project_title, p.title, "Project") AS title, o.project_price, COALESCE(p.is_free, o.project_price = 0) AS is_free FROM orders o LEFT JOIN projects p ON p.id = o.project_id WHERE NOT EXISTS (SELECT 1 FROM order_items i WHERE i.order_id = o.id)'
    );
    $insertItem = $connection->prepare('INSERT INTO order_items (order_id, project_id, project_title, unit_price, is_free) VALUES (?, ?, ?, ?, ?)');
    while ($item = $legacyItems->fetch_assoc()) {
        $orderId = (int) $item['id'];
        $projectId = $item['project_id'] === null ? null : (int) $item['project_id'];
        $title = $item['title'];
        $price = (float) $item['project_price'];
        $isFree = (int) $item['is_free'];
        $insertItem->bind_param('iisdi', $orderId, $projectId, $title, $price, $isFree);
        $insertItem->execute();
        $updateOrder = $connection->prepare('UPDATE orders SET total_amount = ? WHERE id = ? AND total_amount = 0');
        $updateOrder->bind_param('di', $price, $orderId);
        $updateOrder->execute();
    }

    $connection->query('INSERT IGNORE INTO schema_migrations (version) VALUES (1)');
    migrateDeletionSchema($connection);
    migrateProjectTranslationSchema($connection);
    migrateOrderTranslationSchema($connection);
    migrateAdminNotificationPreferences($connection);
    migrateNotificationRoutingEvents($connection);
    migrateHardDeleteNotificationEvents($connection);
    migratePermanentDeleteNotificationEvents($connection);
}

function migrateAdminNotificationPreferences(mysqli $connection): void
{
    $connection->query(
        'CREATE TABLE IF NOT EXISTS admin_notification_preferences (
            admin_id INT NOT NULL,
            event_type VARCHAR(80) NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (admin_id, event_type),
            CONSTRAINT admin_notification_preferences_admin_fk FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $connection->query("INSERT IGNORE INTO notification_settings (event_type, enabled, recipient_mode) VALUES ('admin.created', 1, 'all')");
    $connection->query('INSERT IGNORE INTO schema_migrations (version) VALUES (5)');
}

function migrateNotificationRoutingEvents(mysqli $connection): void
{
    $events = [
        'admin.created', 'user.deleted', 'user.restored', 'project.deleted', 'project.restored',
        'custom_request.archived', 'custom_request.restored', 'cart_order.archived', 'cart_order.restored',
    ];
    $insert = $connection->prepare("INSERT IGNORE INTO notification_settings (event_type, enabled, recipient_mode) VALUES (?, 1, 'all')");
    foreach ($events as $event) {
        $insert->bind_param('s', $event);
        $insert->execute();
    }
    $connection->query('INSERT IGNORE INTO schema_migrations (version) VALUES (6)');
}

function migrateHardDeleteNotificationEvents(mysqli $connection): void
{
    $events = ['custom_request.hard_deleted', 'cart_order.hard_deleted'];
    $insert = $connection->prepare("INSERT IGNORE INTO notification_settings (event_type, enabled, recipient_mode) VALUES (?, 1, 'all')");
    foreach ($events as $event) {
        $insert->bind_param('s', $event);
        $insert->execute();
    }
    $connection->query('INSERT IGNORE INTO schema_migrations (version) VALUES (7)');
}

function migratePermanentDeleteNotificationEvents(mysqli $connection): void
{
    $event = 'project.permanently_deleted';
    $insert = $connection->prepare("INSERT IGNORE INTO notification_settings (event_type, enabled, recipient_mode) VALUES (?, 1, 'all')");
    $insert->bind_param('s', $event);
    $insert->execute();
    $connection->query('INSERT IGNORE INTO schema_migrations (version) VALUES (8)');
}

function migrateProjectTranslationSchema(mysqli $connection): void
{
    foreach (['title_ar' => 'VARCHAR(180) NULL', 'summary_ar' => 'TEXT NULL', 'description_ar' => 'LONGTEXT NULL', 'benefits_ar' => 'LONGTEXT NULL'] as $column => $definition) {
        $check = $connection->prepare(
            'SELECT COUNT(*) AS total FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "projects" AND COLUMN_NAME = ?'
        );
        $check->bind_param('s', $column);
        $check->execute();
        if ((int) $check->get_result()->fetch_assoc()['total'] === 0) {
            $connection->query('ALTER TABLE projects ADD COLUMN `' . $column . '` ' . $definition);
        }
    }

    $seedTranslations = [
        [
            'Business Dashboard',
            'A unified system for sales, inventory, and reports.',
            'This dashboard helps owners monitor KPIs, track ongoing tasks, and manage daily operations from a single control center.',
            'لوحة تحكم الأعمال',
            'نظام موحد لإدارة المبيعات والمخزون والتقارير.',
            'تساعد لوحة التحكم أصحاب الأعمال على متابعة مؤشرات الأداء والمهام الجارية وإدارة العمليات اليومية من مكان واحد.',
            '{"Benefits":["بطاقات مؤشرات الأداء المباشرة","تقارير ذكية","تعاون الفريق"]}',
        ],
        [
            'Mobile Inventory App',
            'A flexible inventory management app for field teams.',
            'This project gives teams a mobile-first workflow for stock checks, movement logs, and client status updates.',
            'تطبيق إدارة المخزون للهواتف',
            'تطبيق مرن لإدارة المخزون يناسب فرق العمل الميدانية.',
            'يوفر المشروع للفرق سير عمل يركز على الهاتف لمراجعة المخزون وتسجيل حركته وتحديث حالة العملاء.',
            '{"Benefits":["العمل دون اتصال","تحقق سريع","متابعة المخزون"]}',
        ],
        [
            'CRM Workflow',
            'A structured CRM for sales and customer follow-up.',
            'This CRM makes lead tracking, follow-ups, and conversions easier while improving communication with clients.',
            'سير عمل إدارة علاقات العملاء',
            'نظام منظم لمتابعة المبيعات والعملاء.',
            'يسهّل هذا النظام متابعة العملاء المحتملين والتواصل معهم وتحويلهم إلى عملاء، مع تحسين التواصل مع العملاء.',
            '{"Benefits":["مسار الصفقات","تذكيرات تلقائية","إدارة المهام"]}',
        ],
        [
            'Store',
            'Simple shop like what you want',
            'Give it a try',
            'متجر إلكتروني',
            'متجر بسيط حسب احتياجك.',
            'جرّبه الآن.',
            '',
        ],
    ];
    $update = $connection->prepare(
        'UPDATE projects SET title_ar = COALESCE(NULLIF(title_ar, ""), ?), summary_ar = COALESCE(NULLIF(summary_ar, ""), ?), description_ar = COALESCE(NULLIF(description_ar, ""), ?), benefits_ar = COALESCE(NULLIF(benefits_ar, ""), ?)
         WHERE title = ? AND summary = ? AND description = ?'
    );
    foreach ($seedTranslations as [$title, $summary, $description, $titleAr, $summaryAr, $descriptionAr, $benefitsAr]) {
        $update->bind_param('sssssss', $titleAr, $summaryAr, $descriptionAr, $benefitsAr, $title, $summary, $description);
        $update->execute();
    }
    $connection->query('INSERT IGNORE INTO schema_migrations (version) VALUES (3)');
}

function migrateOrderTranslationSchema(mysqli $connection): void
{
    foreach (['orders' => ['project_title_ar' => 'VARCHAR(180) NULL'], 'order_items' => ['project_title_ar' => 'VARCHAR(180) NULL']] as $table => $columns) {
        foreach ($columns as $column => $definition) {
            $check = $connection->prepare(
                'SELECT COUNT(*) AS total FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
            );
            $check->bind_param('ss', $table, $column);
            $check->execute();
            if ((int) $check->get_result()->fetch_assoc()['total'] === 0) {
                $connection->query('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition);
            }
        }
    }

    $connection->query(
        'UPDATE order_items i JOIN projects p ON p.id = i.project_id
         SET i.project_title_ar = p.title_ar
         WHERE (i.project_title_ar IS NULL OR i.project_title_ar = "") AND p.title_ar IS NOT NULL AND p.title_ar <> ""'
    );
    $connection->query(
        'UPDATE orders o JOIN projects p ON p.id = o.project_id
         SET o.project_title_ar = p.title_ar
         WHERE (o.project_title_ar IS NULL OR o.project_title_ar = "") AND p.title_ar IS NOT NULL AND p.title_ar <> ""'
    );
    $connection->query('INSERT IGNORE INTO schema_migrations (version) VALUES (4)');
}

function migrateDeletionSchema(mysqli $connection): void
{
    $columns = [
        'users' => 'deleted_at',
        'projects' => 'deleted_at',
        'custom_requests' => 'deleted_at',
        'orders' => 'deleted_at',
        'messages' => 'deleted_at',
    ];
    foreach ($columns as $table => $column) {
        $check = $connection->prepare(
            'SELECT COUNT(*) AS total FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $check->bind_param('ss', $table, $column);
        $check->execute();
        if ((int) $check->get_result()->fetch_assoc()['total'] === 0) {
            $connection->query('ALTER TABLE `' . $table . '` ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL');
        }
    }

    $permissions = [
        'orders.delete' => 'Permanently delete cart orders',
        'messages.delete' => 'Permanently delete messages and restore legacy archived messages',
    ];
    $insertPermission = $connection->prepare('INSERT IGNORE INTO permissions (permission_key, label) VALUES (?, ?)');
    foreach ($permissions as $key => $label) {
        $insertPermission->bind_param('ss', $key, $label);
        $insertPermission->execute();
    }

    $events = [
        'project.deleted', 'project.restored', 'custom_request.archived', 'custom_request.restored',
        'cart_order.archived', 'cart_order.restored', 'user.deleted', 'user.restored',
        'message.deleted', 'message.restored',
    ];
    $insertEvent = $connection->prepare("INSERT IGNORE INTO notification_settings (event_type, enabled, recipient_mode) VALUES (?, 1, 'all')");
    foreach ($events as $event) {
        $insertEvent->bind_param('s', $event);
        $insertEvent->execute();
    }

    $grant = $connection->prepare(
        'INSERT IGNORE INTO role_permissions (role_id, permission_id)
         SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
         WHERE r.name IN ("Super Admin", "Administrator") AND p.permission_key IN (?, ?)'
    );
    $orderPermission = 'orders.delete';
    $messagePermission = 'messages.delete';
    $grant->bind_param('ss', $orderPermission, $messagePermission);
    $grant->execute();

    $connection->query('INSERT IGNORE INTO schema_migrations (version) VALUES (2)');
}
