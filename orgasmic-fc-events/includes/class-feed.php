<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Orgasmic_Fc_Events_Feed
{
    /**
     * @return array{ids: int[], error: string}
     */
    public function share(array $event, Orgasmic_Fc_Events_Access $access): array
    {
        $space_ids = $access->decode_ids($event['space_ids'] ?? '[]');
        if (($event['visibility'] ?? '') === 'all' && $space_ids === []) {
            $space_ids = array_map(static fn(array $space) => (int) $space['id'], $access->list_spaces());
        }

        if ($space_ids === []) {
            return [
                'ids' => [],
                'error' => 'Bitte mindestens einen Space wählen, damit das Event im Activity Stream erscheint.',
            ];
        }

        $excerpt = wp_trim_words(wp_strip_all_tags((string) ($event['description'] ?? '')), 40);
        $portal = $this->portal_url() . '#orgasmic-event-' . (int) $event['id'];
        $when = $this->format_when($event);
        $message = '**' . (string) $event['title'] . '**' . "\n\n"
            . $when . "\n\n"
            . ($excerpt !== '' ? $excerpt . "\n\n" : '')
            . '[Im Kalender öffnen](' . $portal . ')';

        $user_id = (int) ($event['created_by'] ?: get_current_user_id());
        $image = !empty($event['image_id']) ? wp_get_attachment_url((int) $event['image_id']) : '';
        $created = [];
        $errors = [];

        foreach (array_unique(array_map('intval', $space_ids)) as $space_id) {
            if ($space_id <= 0) {
                continue;
            }

            $result = $this->create_in_space($space_id, $user_id, (string) $event['title'], $message, $image);
            if (is_wp_error($result)) {
                $errors[] = $result->get_error_message();
                continue;
            }
            $created[] = $result;
        }

        if ($created === [] && $errors === []) {
            $errors[] = 'Activity-Stream-Post konnte nicht erstellt werden.';
        }

        return [
            'ids' => array_values(array_filter($created)),
            'error' => $created === [] ? implode(' ', array_unique($errors)) : '',
        ];
    }

    private function create_in_space(int $space_id, int $user_id, string $title, string $message, string $image): int|WP_Error
    {
        $payload = [
            'title' => $title,
            'message' => $message,
            'space_id' => $space_id,
            'user_id' => $user_id,
        ];
        if ($image !== '') {
            $payload['featured_image'] = $image;
        }

        if (class_exists('FluentCommunity\\App\\Services\\FeedsHelper')
            && method_exists('FluentCommunity\\App\\Services\\FeedsHelper', 'createFeed')
        ) {
            try {
                $feed = FluentCommunity\App\Services\FeedsHelper::createFeed($payload);
            } catch (Throwable $e) {
                error_log('orgasmic-fc-events feed share: ' . $e->getMessage());
                return new WP_Error('feed_share', $e->getMessage());
            }

            if (is_wp_error($feed)) {
                error_log('orgasmic-fc-events feed share: ' . $feed->get_error_message());
                return $feed;
            }

            return (int) ($feed->id ?? 0);
        }

        if (!class_exists('FluentCommunity\\App\\Models\\Feed')) {
            return new WP_Error('feed_share', 'FluentCommunity Feed-API ist nicht verfügbar.');
        }

        try {
            $feed = FluentCommunity\App\Models\Feed::create([
                'user_id' => $user_id,
                'title' => $title,
                'message' => $message,
                'message_rendered' => wpautop(esc_html($message)),
                'type' => 'feed',
                'content_type' => 'text',
                'status' => 'published',
                'privacy' => 'private',
                'space_id' => $space_id,
            ] + ($image !== '' ? ['featured_image' => $image] : []));
            do_action('fluent_community/feed/created', $feed);
            do_action('fluent_community/space_feed/created', $feed);
            return (int) ($feed->id ?? 0);
        } catch (Throwable $e) {
            error_log('orgasmic-fc-events feed share: ' . $e->getMessage());
            return new WP_Error('feed_share', $e->getMessage());
        }
    }

    /**
     * @return array{ids: int[], primary: int, error: string}
     */
    public function ensure_discussion(array $event, Orgasmic_Fc_Events_Access $access, int $user_id): array
    {
        $existing = $access->decode_ids($event['feed_ids'] ?? '[]');
        $primary = $this->primary_feed_id($event, $access, $user_id);
        if ($primary > 0) {
            return ['ids' => $existing, 'primary' => $primary, 'error' => ''];
        }
        if ($existing !== []) {
            return [
                'ids' => $existing,
                'primary' => 0,
                'error' => 'Diese Unterhaltung liegt in einem Kreis, dem du nicht angehörst.',
            ];
        }

        $shared = $this->share($event, $access);
        return [
            'ids' => $shared['ids'],
            'primary' => $shared['ids'][0] ?? 0,
            'error' => $shared['error'],
        ];
    }

    public function discussion(array $event, Orgasmic_Fc_Events_Access $access, int $user_id): array
    {
        $feed_id = $this->primary_feed_id($event, $access, $user_id);
        $feed = $feed_id ? $this->load_feed($feed_id) : null;
        $comments = $feed_id ? $this->list_comments($feed_id) : [];

        return [
            'feed_id' => $feed_id,
            'space_id' => $feed ? (int) ($feed['space_id'] ?? 0) : 0,
            'permalink' => $feed ? $this->feed_permalink($feed) : '',
            'comments' => $comments,
            'count' => count($comments),
            'can_comment' => $user_id > 0 && $access->can_view_event($event, $user_id),
        ];
    }

    public function add_comment(int $feed_id, int $user_id, string $message): array|WP_Error
    {
        $message = trim(wp_strip_all_tags($message));
        if ($message === '') {
            return new WP_Error('invalid', 'Bitte einen Kommentar schreiben.', ['status' => 400]);
        }
        if ($feed_id <= 0 || $user_id <= 0) {
            return new WP_Error('invalid', 'Kommentar konnte nicht gespeichert werden.', ['status' => 400]);
        }

        $rendered = wpautop(esc_html($message));
        $feed = $this->load_feed_model($feed_id);

        if (class_exists('FluentCommunity\\App\\Models\\Comment')) {
            try {
                $comment = FluentCommunity\App\Models\Comment::create([
                    'user_id' => $user_id,
                    'post_id' => $feed_id,
                    'parent_id' => 0,
                    'message' => $message,
                    'message_rendered' => $rendered,
                    'type' => 'comment',
                    'content_type' => 'text',
                    'status' => 'published',
                ]);
                if ($feed) {
                    if (isset($feed->comment_count)) {
                        $feed->comment_count = (int) $feed->comment_count + 1;
                        $feed->save();
                    }
                    do_action('fluent_community/comment_added', $comment, $feed, []);
                }
                return $this->present_comment($comment);
            } catch (Throwable $e) {
                error_log('orgasmic-fc-events comment: ' . $e->getMessage());
            }
        }

        global $wpdb;
        $table = $wpdb->prefix . 'fcom_post_comments';
        $now = current_time('mysql');
        $ok = $wpdb->insert($table, [
            'user_id' => $user_id,
            'post_id' => $feed_id,
            'parent_id' => 0,
            'message' => $message,
            'message_rendered' => $rendered,
            'type' => 'comment',
            'content_type' => 'text',
            'status' => 'published',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if (!$ok) {
            return new WP_Error('comment', 'Kommentar konnte nicht gespeichert werden.', ['status' => 500]);
        }

        return $this->present_comment_row([
            'id' => (int) $wpdb->insert_id,
            'user_id' => $user_id,
            'message' => $message,
            'message_rendered' => $rendered,
            'created_at' => $now,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list_comments(int $feed_id): array
    {
        if ($feed_id <= 0) {
            return [];
        }

        if (class_exists('FluentCommunity\\App\\Models\\Comment')) {
            try {
                $query = FluentCommunity\App\Models\Comment::query()
                    ->where('post_id', $feed_id)
                    ->where('status', 'published')
                    ->orderBy('id', 'asc')
                    ->limit(80);
                $rows = $query->get();
                $out = [];
                foreach ($rows as $row) {
                    $parent = (int) ($row->parent_id ?? 0);
                    if ($parent > 0) {
                        continue;
                    }
                    $out[] = $this->present_comment($row);
                }
                return $out;
            } catch (Throwable $e) {
                error_log('orgasmic-fc-events comments: ' . $e->getMessage());
            }
        }

        global $wpdb;
        $table = $wpdb->prefix . 'fcom_post_comments';
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE post_id = %d AND (parent_id IS NULL OR parent_id = 0) AND (status IS NULL OR status = %s) ORDER BY id ASC LIMIT 80",
                $feed_id,
                'published'
            ),
            ARRAY_A
        ) ?: [];

        return array_map([$this, 'present_comment_row'], $rows);
    }

    private function primary_feed_id(array $event, Orgasmic_Fc_Events_Access $access, int $user_id): int
    {
        $ids = $access->decode_ids($event['feed_ids'] ?? '[]');
        if ($ids === []) {
            return 0;
        }

        $owned = $user_id ? $access->user_space_ids($user_id) : [];
        $is_manager = $access->can_manage($user_id);
        foreach ($ids as $id) {
            $feed = $this->load_feed($id);
            if (!$feed) {
                continue;
            }
            $space = (int) ($feed['space_id'] ?? 0);
            if ($space <= 0 || $is_manager || in_array($space, $owned, true)) {
                return (int) $id;
            }
        }

        return 0;
    }

    private function load_feed(int $feed_id): ?array
    {
        $model = $this->load_feed_model($feed_id);
        if ($model) {
            return [
                'id' => (int) ($model->id ?? $feed_id),
                'space_id' => (int) ($model->space_id ?? 0),
                'title' => (string) ($model->title ?? ''),
                'slug' => (string) ($model->slug ?? ''),
            ];
        }

        global $wpdb;
        $table = $wpdb->prefix . 'fcom_posts';
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT id, space_id, title, slug FROM {$table} WHERE id = %d", $feed_id),
            ARRAY_A
        );
        return $row ? [
            'id' => (int) $row['id'],
            'space_id' => (int) ($row['space_id'] ?? 0),
            'title' => (string) ($row['title'] ?? ''),
            'slug' => (string) ($row['slug'] ?? ''),
        ] : null;
    }

    private function load_feed_model(int $feed_id): ?object
    {
        if (!class_exists('FluentCommunity\\App\\Models\\Feed')) {
            return null;
        }
        try {
            return FluentCommunity\App\Models\Feed::query()->find($feed_id);
        } catch (Throwable $e) {
            return null;
        }
    }

    private function feed_permalink(array $feed): string
    {
        $base = $this->portal_url();
        $space_id = (int) ($feed['space_id'] ?? 0);
        if ($space_id > 0) {
            $space = $this->space_slug($space_id);
            if ($space !== '') {
                return $base . '/space/' . rawurlencode($space);
            }
        }
        return $base;
    }

    private function space_slug(int $space_id): string
    {
        global $wpdb;
        $table = $wpdb->prefix . 'fcom_spaces';
        $slug = $wpdb->get_var($wpdb->prepare("SELECT slug FROM {$table} WHERE id = %d", $space_id));
        return is_string($slug) ? $slug : '';
    }

    private function present_comment(object $comment): array
    {
        $user_id = (int) ($comment->user_id ?? 0);
        $created = (string) ($comment->created_at ?? '');
        $html = (string) ($comment->message_rendered ?? '');
        if ($html === '') {
            $html = wpautop(esc_html((string) ($comment->message ?? '')));
        }

        $name = '';
        if (isset($comment->user) && is_object($comment->user)) {
            $name = (string) ($comment->user->display_name ?? $comment->user->user_login ?? '');
        }

        return $this->present_comment_row([
            'id' => (int) ($comment->id ?? 0),
            'user_id' => $user_id,
            'display_name' => $name,
            'message' => (string) ($comment->message ?? ''),
            'message_rendered' => $html,
            'created_at' => $created,
        ]);
    }

    private function present_comment_row(array $row): array
    {
        $user_id = (int) ($row['user_id'] ?? 0);
        $user = $user_id ? get_userdata($user_id) : null;
        $created = (string) ($row['created_at'] ?? '');
        $ts = $created !== '' ? strtotime($created) : false;

        return [
            'id' => (int) ($row['id'] ?? 0),
            'user_id' => $user_id,
            'display_name' => ($row['display_name'] ?? '') !== ''
                ? (string) $row['display_name']
                : ($user ? $user->display_name : 'Mitglied'),
            'avatar' => $user_id ? get_avatar_url($user_id, ['size' => 64]) : '',
            'message' => (string) ($row['message'] ?? ''),
            'message_html' => (string) ($row['message_rendered'] ?? wpautop(esc_html((string) ($row['message'] ?? '')))),
            'when' => $ts ? human_time_diff($ts) . ' zuvor' : '',
        ];
    }

    private function portal_url(): string
    {
        if (class_exists('FluentCommunity\\App\\Services\\Helper') && method_exists('FluentCommunity\\App\\Services\\Helper', 'baseUrl')) {
            return rtrim((string) FluentCommunity\App\Services\Helper::baseUrl('/'), '/');
        }
        return home_url('/portal');
    }

    private function format_when(array $event): string
    {
        try {
            $tz = new DateTimeZone((string) ($event['timezone'] ?: 'Europe/Berlin'));
        } catch (Exception $e) {
            $tz = new DateTimeZone('Europe/Berlin');
        }
        $start = new DateTimeImmutable((string) $event['starts_at'] . ' UTC');
        return $start->setTimezone($tz)->format('d.m.Y H:i') . ' Uhr';
    }
}
