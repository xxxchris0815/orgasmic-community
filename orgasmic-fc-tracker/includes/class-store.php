<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Orgasmic_Fc_Store
{
    public const OPTION_WEBHOOK_URL = 'orgasmic_fc_webhook_url';
    public const OPTION_WEBHOOK_SECRET = 'orgasmic_fc_webhook_secret';
    public const OPTION_INCLUDE_PII = 'orgasmic_fc_include_pii';
    public const OPTION_INCLUDE_CONTENT = 'orgasmic_fc_include_content';
    public const OPTION_RETENTION_DAYS = 'orgasmic_fc_retention_days';
    public const OPTION_ENABLED_GROUPS = 'orgasmic_fc_enabled_groups';

    public static function table_name(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'orgasmic_fc_events';
    }

    public static function event_groups(): array
    {
        return [
            'courses' => 'Kurse & Lektionen',
            'feeds' => 'Feed / Posts',
            'comments' => 'Kommentare',
            'reactions' => 'Reaktionen',
            'spaces' => 'Spaces',
            'members' => 'Mitglieder & Punkte',
        ];
    }

    public function install(): void
    {
        global $wpdb;

        $table = self::table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            occurred_at DATETIME NOT NULL,
            event VARCHAR(80) NOT NULL,
            category VARCHAR(32) NOT NULL,
            user_id BIGINT UNSIGNED NULL,
            object_type VARCHAR(32) NULL,
            object_id BIGINT UNSIGNED NULL,
            parent_type VARCHAR(32) NULL,
            parent_id BIGINT UNSIGNED NULL,
            payload LONGTEXT NULL,
            PRIMARY KEY  (id),
            KEY user_event (user_id, event),
            KEY category_time (category, occurred_at),
            KEY object_lookup (object_type, object_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        if (get_option(self::OPTION_ENABLED_GROUPS, null) === null) {
            update_option(self::OPTION_ENABLED_GROUPS, array_keys(self::event_groups()));
        }

        if (get_option(self::OPTION_RETENTION_DAYS, null) === null) {
            update_option(self::OPTION_RETENTION_DAYS, 365);
        }

        if (get_option(self::OPTION_INCLUDE_PII, null) === null) {
            update_option(self::OPTION_INCLUDE_PII, 1);
        }
    }

    public function is_group_enabled(string $group): bool
    {
        $enabled = get_option(self::OPTION_ENABLED_GROUPS, array_keys(self::event_groups()));

        return in_array($group, (array) $enabled, true);
    }

    public function insert_event(array $row): int
    {
        global $wpdb;

        $data = [
            'occurred_at' => $row['occurred_at'],
            'event' => $row['event'],
            'category' => $row['category'],
            'payload' => wp_json_encode($row['payload'] ?? new stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        $formats = ['%s', '%s', '%s', '%s'];

        foreach (['user_id' => '%d', 'object_id' => '%d', 'parent_id' => '%d'] as $key => $format) {
            if (!empty($row[$key])) {
                $data[$key] = (int) $row[$key];
                $formats[] = $format;
            }
        }

        foreach (['object_type', 'parent_type'] as $key) {
            if (!empty($row[$key])) {
                $data[$key] = (string) $row[$key];
                $formats[] = '%s';
            }
        }

        $wpdb->insert(self::table_name(), $data, $formats);

        return (int) $wpdb->insert_id;
    }

    public function cleanup_old_events(): void
    {
        global $wpdb;

        $days = (int) get_option(self::OPTION_RETENTION_DAYS, 365);
        if ($days < 1) {
            return;
        }

        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));
        $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM ' . self::table_name() . ' WHERE occurred_at < %s',
                $cutoff
            )
        );
    }

    public function get_events(array $args = []): array
    {
        global $wpdb;

        $defaults = [
            'user_id' => 0,
            'category' => '',
            'event' => '',
            'limit' => 50,
            'offset' => 0,
        ];
        $args = wp_parse_args($args, $defaults);

        $where = ['1=1'];
        $params = [];

        if ((int) $args['user_id'] > 0) {
            $where[] = 'user_id = %d';
            $params[] = (int) $args['user_id'];
        }

        if ($args['category'] !== '') {
            $where[] = 'category = %s';
            $params[] = $args['category'];
        }

        if ($args['event'] !== '') {
            $where[] = 'event = %s';
            $params[] = $args['event'];
        }

        $sql = 'SELECT * FROM ' . self::table_name()
            . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY occurred_at DESC, id DESC'
            . ' LIMIT %d OFFSET %d';
        $params[] = max(1, (int) $args['limit']);
        $params[] = max(0, (int) $args['offset']);

        return $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A) ?: [];
    }

    public function count_events(array $args = []): int
    {
        global $wpdb;

        $where = ['1=1'];
        $params = [];

        if (!empty($args['user_id'])) {
            $where[] = 'user_id = %d';
            $params[] = (int) $args['user_id'];
        }

        if (!empty($args['category'])) {
            $where[] = 'category = %s';
            $params[] = (string) $args['category'];
        }

        $sql = 'SELECT COUNT(*) FROM ' . self::table_name() . ' WHERE ' . implode(' AND ', $where);

        if ($params === []) {
            return (int) $wpdb->get_var($sql);
        }

        return (int) $wpdb->get_var($wpdb->prepare($sql, $params));
    }

    public function get_summary(int $days = 7): array
    {
        global $wpdb;

        $since = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));
        $table = self::table_name();

        $totals = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT event, COUNT(*) AS total
                 FROM {$table}
                 WHERE occurred_at >= %s
                 GROUP BY event
                 ORDER BY total DESC",
                $since
            ),
            ARRAY_A
        ) ?: [];

        $daily = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DATE(occurred_at) AS day, category, COUNT(*) AS total
                 FROM {$table}
                 WHERE occurred_at >= %s
                 GROUP BY day, category
                 ORDER BY day ASC",
                $since
            ),
            ARRAY_A
        ) ?: [];

        $active_users = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT user_id) FROM {$table} WHERE occurred_at >= %s AND user_id IS NOT NULL",
                $since
            )
        );

        return [
            'days' => $days,
            'active_users' => $active_users,
            'totals' => $totals,
            'daily' => $daily,
            'stored_events' => $this->count_events(),
        ];
    }

    public function get_member_stats(int $limit = 50): array
    {
        global $wpdb;

        $table = self::table_name();
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    user_id,
                    MAX(occurred_at) AS last_seen,
                    SUM(event = 'course.lesson_completed') AS lessons_completed,
                    SUM(event = 'course.completed') AS courses_completed,
                    SUM(event = 'course.enrolled') AS courses_enrolled,
                    SUM(event = 'feed.created') AS posts,
                    SUM(event = 'comment.added') AS comments,
                    SUM(event IN ('feed.react_added','comment.react_added')) AS reactions,
                    SUM(event = 'space.joined') AS spaces_joined,
                    COUNT(*) AS events
                 FROM {$table}
                 WHERE user_id IS NOT NULL
                 GROUP BY user_id
                 ORDER BY last_seen DESC
                 LIMIT %d",
                $limit
            ),
            ARRAY_A
        ) ?: [];

        foreach ($rows as &$row) {
            $user = get_userdata((int) $row['user_id']);
            $row['display_name'] = $user ? $user->display_name : '(gelöscht)';
            $row['email'] = $user ? $user->user_email : '';
            $row['engagement_score'] = $this->engagement_score($row);
        }

        return $rows;
    }

    public function get_course_progress(): array
    {
        global $wpdb;

        $table = self::table_name();
        $lessons = $wpdb->get_results(
            "SELECT
                parent_id AS course_id,
                user_id,
                object_id AS lesson_id,
                MAX(occurred_at) AS completed_at
             FROM {$table}
             WHERE event = 'course.lesson_completed'
               AND parent_id IS NOT NULL
               AND user_id IS NOT NULL
             GROUP BY parent_id, user_id, object_id
             ORDER BY completed_at DESC",
            ARRAY_A
        ) ?: [];

        $courses = [];
        foreach ($lessons as $lesson) {
            $course_id = (int) $lesson['course_id'];
            $user_id = (int) $lesson['user_id'];

            if (!isset($courses[$course_id])) {
                $courses[$course_id] = [
                    'course_id' => $course_id,
                    'title' => $this->course_title($course_id),
                    'unique_students' => [],
                    'lesson_completions' => 0,
                    'students' => [],
                ];
            }

            $courses[$course_id]['unique_students'][$user_id] = true;
            $courses[$course_id]['lesson_completions']++;

            if (!isset($courses[$course_id]['students'][$user_id])) {
                $user = get_userdata($user_id);
                $courses[$course_id]['students'][$user_id] = [
                    'user_id' => $user_id,
                    'display_name' => $user ? $user->display_name : '(gelöscht)',
                    'lessons_completed' => 0,
                    'last_completed_at' => $lesson['completed_at'],
                ];
            }

            $courses[$course_id]['students'][$user_id]['lessons_completed']++;
            $courses[$course_id]['students'][$user_id]['last_completed_at'] = $lesson['completed_at'];
        }

        foreach ($courses as &$course) {
            $course['unique_student_count'] = count($course['unique_students']);
            unset($course['unique_students']);
            $course['students'] = array_values($course['students']);
        }

        return array_values($courses);
    }

    private function course_title(int $course_id): string
    {
        global $wpdb;

        $spaces = $wpdb->prefix . 'fcom_spaces';
        if ($this->table_exists($spaces)) {
            $title = $wpdb->get_var(
                $wpdb->prepare("SELECT title FROM {$spaces} WHERE id = %d", $course_id)
            );
            if (is_string($title) && $title !== '') {
                return $title;
            }
        }

        return 'Kurs #' . $course_id;
    }

    private function table_exists(string $table): bool
    {
        global $wpdb;

        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

        return $found === $table;
    }

    private function engagement_score(array $row): int
    {
        return (int) $row['lessons_completed'] * 5
            + (int) $row['courses_completed'] * 20
            + (int) $row['posts'] * 4
            + (int) $row['comments'] * 3
            + (int) $row['reactions']
            + (int) $row['spaces_joined'] * 2;
    }
}
