<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Orgasmic_Fc_App_Notify
{
    private bool $dirty = false;

    public function __construct(
        private Orgasmic_Fc_App_Access $access,
        private Orgasmic_Fc_App_Store $store,
        private Orgasmic_Fc_App_WebPush $push,
        private Orgasmic_Fc_App_Fcm $fcm
    ) {
    }

    public function register(): void
    {
        add_action('orgasmic_fc_app_send', [$this, 'flush']);
        add_action('shutdown', [$this, 'flush_light']);

        add_action('orgasmic_fc/chat/message', [$this, 'on_chat'], 20, 3);
        add_action('fluent_community/feed/created', [$this, 'on_feed'], 30, 1);
        add_action('fluent_community/comment_added', [$this, 'on_comment'], 30, 3);
        add_action('orgasmic_fc/event/reminder', [$this, 'on_event_reminder'], 20, 3);
        add_action('orgasmic_fc/event/created', [$this, 'on_event_created'], 20, 2);
    }

    public function on_chat($message, $space_id, $actor_id): void
    {
        if (!$this->enabled(Orgasmic_Fc_App_Install::OPTION_CHAT)) {
            return;
        }

        $space_id = (int) $space_id;
        $actor_id = (int) $actor_id;
        $preview = '';
        if (is_array($message)) {
            $preview = trim((string) ($message['body'] ?? ''));
            if ($preview === '' && !empty($message['attachment'])) {
                $att = $message['attachment'];
                $mime = is_array($att) ? (string) ($att['mime'] ?? '') : '';
                $kind = is_array($att) ? (string) ($att['kind'] ?? '') : '';
                $preview = ($kind === 'audio' || str_starts_with($mime, 'audio/')) ? 'Sprachnachricht' : 'Bild';
            }
        }
        $author = '';
        if (is_array($message) && isset($message['author']) && is_array($message['author'])) {
            $author = trim((string) ($message['author']['display_name'] ?? ''));
        }
        if ($author === '') {
            $author = $this->actor_name($actor_id);
        }
        $title = $this->heading($this->access->space_title($space_id), 'Chat');
        $body = $this->line($author, $preview, 'Neue Chat-Nachricht');
        $recipients = array_values(array_diff($this->access->space_member_ids($space_id), [$actor_id]));
        $recipients = $this->filter_prefs($recipients, 'chat');
        $this->store->enqueue(
            $recipients,
            'chat',
            $title,
            $body,
            $this->url('#orgasmic-chat-' . $space_id),
            'chat-' . $space_id,
            ['space_id' => $space_id]
        );
        $this->kick();
    }

    public function on_feed($feed): void
    {
        if (!$this->enabled(Orgasmic_Fc_App_Install::OPTION_FEED)) {
            return;
        }

        $space_id = (int) $this->access->prop($feed, 'space_id');
        if ($space_id < 1) {
            return;
        }

        $actor = (int) $this->access->prop($feed, 'user_id');
        $feed_id = $this->access->model_id($feed);
        $excerpt = wp_strip_all_tags((string) ($this->access->prop($feed, 'title') ?: $this->access->prop($feed, 'message') ?: ''));
        $title = $this->heading($this->access->space_title($space_id), 'Beitrag');
        $body = $this->line($this->actor_name($actor), $excerpt, 'Neuer Beitrag');
        $recipients = array_values(array_diff($this->access->space_member_ids($space_id), [$actor]));
        $recipients = $this->filter_prefs($recipients, 'feed');
        $this->store->enqueue(
            $recipients,
            'feed',
            $title,
            $body,
            $this->url($feed_id ? '?orgasmic_feed=' . $feed_id : ''),
            'feed-' . $feed_id,
            ['space_id' => $space_id, 'feed_id' => $feed_id]
        );
        $this->kick();
    }

    public function on_comment($comment, $feed, $mentioned = null): void
    {
        if (!$this->enabled(Orgasmic_Fc_App_Install::OPTION_COMMENT)) {
            return;
        }

        $actor = (int) $this->access->prop($comment, 'user_id');
        $feed_author = (int) $this->access->prop($feed, 'user_id');
        $feed_id = $this->access->model_id($feed);
        $space_id = (int) $this->access->prop($feed, 'space_id');
        $recipients = [];
        if ($feed_author && $feed_author !== $actor) {
            $recipients[] = $feed_author;
        }
        if (is_array($mentioned)) {
            foreach ($mentioned as $item) {
                if (is_numeric($item)) {
                    $recipients[] = (int) $item;
                } elseif (is_object($item) && isset($item->ID)) {
                    $recipients[] = (int) $item->ID;
                } elseif (is_object($item) && isset($item->id)) {
                    $recipients[] = (int) $item->id;
                }
            }
        }
        $recipients = array_values(array_diff(array_unique($recipients), [$actor]));
        if ($space_id > 0) {
            $allowed = $this->access->space_member_ids($space_id);
            $recipients = array_values(array_intersect($recipients, $allowed));
        }
        $recipients = $this->filter_prefs($recipients, 'comment');

        $excerpt = wp_strip_all_tags((string) ($this->access->prop($comment, 'message') ?: $this->access->prop($comment, 'content') ?: ''));
        $this->store->enqueue(
            $recipients,
            'comment',
            $this->heading($space_id > 0 ? $this->access->space_title($space_id) : '', 'Kommentar'),
            $this->line($this->actor_name($actor), $excerpt, 'Neuer Kommentar'),
            $this->url($feed_id ? '?orgasmic_feed=' . $feed_id : ''),
            'comment-' . $feed_id,
            ['feed_id' => $feed_id, 'space_id' => $space_id]
        );
        $this->kick();
    }

    public function on_event_reminder($event, $minutes = 0, $user_ids = []): void
    {
        if (!$this->enabled(Orgasmic_Fc_App_Install::OPTION_EVENT)) {
            return;
        }

        $event = is_array($event) ? $event : [];
        $id = (int) ($event['id'] ?? 0);
        $event_title = trim((string) ($event['title'] ?? ''));
        $minutes = (int) $minutes;
        $heading = $this->heading($this->event_space_title($event), 'Termin');
        if ($minutes <= 5) {
            $body = $event_title !== '' ? $event_title . ' — Veranstaltung beginnt' : 'Veranstaltung beginnt';
        } elseif ($minutes >= 1440) {
            $body = ($event_title !== '' ? $event_title : 'Termin') . ' beginnt morgen';
        } else {
            $body = ($event_title !== '' ? $event_title : 'Termin') . ' beginnt in ' . $minutes . ' Minuten';
        }
        $recipients = array_values(array_unique(array_map('intval', (array) $user_ids)));
        $recipients = $this->filter_prefs($recipients, 'event');
        $this->store->enqueue(
            $recipients,
            'event',
            $heading,
            $this->clip($body, 160),
            $this->url($id ? '#orgasmic-event-' . $id : '#orgasmic-calendar'),
            'event-' . $id . '-' . $minutes,
            ['event_id' => $id]
        );
        $this->kick();
    }

    public function on_event_created($event, $actor_id = 0): void
    {
        if (!$this->enabled(Orgasmic_Fc_App_Install::OPTION_EVENT)) {
            return;
        }

        $event = is_array($event) ? $event : [];
        $id = (int) ($event['id'] ?? 0);
        $event_title = trim((string) ($event['title'] ?? ''));
        $space_ids = $this->access->decode_ids($event['space_ids'] ?? []);
        $recipients = [];
        foreach ($space_ids as $space_id) {
            $recipients = array_merge($recipients, $this->access->space_member_ids($space_id));
        }
        $recipients = array_values(array_diff(array_unique($recipients), [(int) $actor_id]));
        $recipients = $this->filter_prefs($recipients, 'event');
        $this->store->enqueue(
            $recipients,
            'event',
            $this->heading($this->event_space_title($event), 'Termin'),
            $this->clip($event_title !== '' ? 'Neuer Termin: ' . $event_title : 'Neuer Termin in deinem Kreis', 160),
            $this->url($id ? '#orgasmic-event-' . $id : '#orgasmic-calendar'),
            'event-new-' . $id,
            ['event_id' => $id]
        );
        $this->kick();
    }

    public function flush(): void
    {
        $rows = $this->store->pending(40);
        foreach ($rows as $row) {
            $this->deliver($row);
        }
    }

    public function flush_light(): void
    {
        if (!$this->dirty) {
            return;
        }
        $this->flush();
    }

    private function kick(): void
    {
        $this->dirty = true;
    }

    private function deliver(array $row): void
    {
        $subs = $this->store->subscriptions_for_users([(int) $row['user_id']]);
        if ($subs === []) {
            $this->store->mark_sent((int) $row['id']);
            return;
        }

        $payload = [
            'title' => (string) $row['title'],
            'body' => (string) $row['body'],
            'url' => (string) $row['url'],
            'tag' => (string) $row['tag'],
            'kind' => (string) $row['kind'],
        ];

        $ok_any = false;
        $last_error = '';
        $last_status = 0;
        foreach ($subs as $sub) {
            $channel = (string) ($sub['channel'] ?? 'web');
            $result = ($channel === 'fcm' || $channel === 'apns')
                ? $this->fcm->send($sub, $payload)
                : $this->push->send($sub, $payload);
            if (!empty($result['ok'])) {
                $ok_any = true;
                continue;
            }
            $last_status = (int) ($result['status'] ?? 0);
            $last_error = (string) ($result['error'] ?? 'send failed');
            if (in_array($last_status, [404, 410], true) || str_contains($last_error, 'UNREGISTERED')) {
                $this->store->delete_endpoint((string) $sub['endpoint']);
            }
        }

        if ($ok_any) {
            $this->store->mark_sent((int) $row['id']);
            return;
        }

        $this->store->mark_retry((int) $row['id'], $last_error ?: 'kein Gerät', $last_status);
    }

    private function filter_prefs(array $user_ids, string $kind): array
    {
        $user_ids = array_values(array_unique(array_filter(array_map('intval', $user_ids))));
        if ($user_ids === []) {
            return [];
        }
        update_meta_cache('user', $user_ids);
        $out = [];
        foreach ($user_ids as $id) {
            $prefs = Orgasmic_Fc_App_Install::prefs_for($id);
            if (!empty($prefs[$kind])) {
                $out[] = $id;
            }
        }

        return $out;
    }

    private function enabled(string $option): bool
    {
        return (bool) get_option(Orgasmic_Fc_App_Install::OPTION_ENABLED, 1)
            && (bool) get_option($option, 1);
    }

    private function actor_name(int $user_id): string
    {
        if ($user_id < 1) {
            return '';
        }
        $user = get_userdata($user_id);
        $name = $user ? trim((string) $user->display_name) : '';

        return $name !== '' ? $name : 'Mitglied';
    }

    private function heading(string $space, string $kind): string
    {
        $space = trim($space);

        return $space !== '' ? $space . ' · ' . $kind : $kind;
    }

    private function line(string $author, string $preview, string $generic): string
    {
        $preview = $this->clip($preview, 100);
        $include = (bool) get_option(Orgasmic_Fc_App_Install::OPTION_INCLUDE_BODY, 1);
        if ($include && $preview !== '') {
            return $this->clip($author !== '' ? $author . ': ' . $preview : $preview, 160);
        }
        if ($author !== '') {
            return $this->clip($author . ' · ' . $generic, 160);
        }

        return $generic;
    }

    private function event_space_title(array $event): string
    {
        $ids = $this->access->decode_ids($event['space_ids'] ?? []);
        if ($ids === []) {
            $sid = (int) ($event['space_id'] ?? 0);
            $ids = $sid > 0 ? [$sid] : [];
        }

        return $ids !== [] ? $this->access->space_title($ids[0]) : '';
    }

    private function clip(string $text, int $max): string
    {
        $text = trim((string) (preg_replace('/\s+/u', ' ', $text) ?? $text));
        if ($text === '') {
            return '';
        }
        if (function_exists('mb_strlen') && mb_strlen($text) > $max) {
            return rtrim(mb_substr($text, 0, $max - 1)) . '…';
        }
        if (strlen($text) > $max) {
            return rtrim(substr($text, 0, $max - 1)) . '…';
        }

        return $text;
    }

    private function url(string $suffix): string
    {
        $start = (string) get_option(Orgasmic_Fc_App_Install::OPTION_START_URL, '/');
        if ($start === '') {
            $start = '/';
        }
        $base = $start[0] === '/' ? home_url($start) : $start;
        if ($suffix === '') {
            return $base;
        }
        if ($suffix[0] === '#') {
            return $base . $suffix;
        }
        return rtrim($base, '/') . '/' . ltrim($suffix, '/');
    }
}
