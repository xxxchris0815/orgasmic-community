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
        private Orgasmic_Fc_App_WebPush $push
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
        $title = $this->access->space_title($space_id);
        $preview = '';
        if (is_array($message)) {
            $preview = trim((string) ($message['body'] ?? ''));
            if ($preview === '' && !empty($message['attachment'])) {
                $preview = 'Neues Bild';
            }
        }
        $body = $this->with_body('Neue Nachricht', $preview);
        $recipients = array_diff($this->access->space_member_ids($space_id), [$actor_id]);
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
        $title = $this->access->space_title($space_id);
        $excerpt = wp_strip_all_tags((string) ($this->access->prop($feed, 'title') ?: $this->access->prop($feed, 'message') ?: ''));
        $body = $this->with_body('Neuer Beitrag', $excerpt);
        $recipients = array_diff($this->access->space_member_ids($space_id), [$actor]);
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
        $parent = (int) $this->access->prop($comment, 'parent_id');
        unset($parent);
        $recipients = array_diff(array_unique($recipients), [$actor]);
        if ($space_id > 0) {
            $allowed = $this->access->space_member_ids($space_id);
            $recipients = array_values(array_intersect($recipients, $allowed));
        }

        $excerpt = wp_strip_all_tags((string) ($this->access->prop($comment, 'message') ?: $this->access->prop($comment, 'content') ?: ''));
        $this->store->enqueue(
            $recipients,
            'comment',
            'Neuer Kommentar',
            $this->with_body('Jemand hat geantwortet', $excerpt),
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
        $title = (string) ($event['title'] ?? 'Event');
        $minutes = (int) $minutes;
        $when = $minutes >= 1440 ? 'morgen' : 'in ' . $minutes . ' Minuten';
        $recipients = array_values(array_unique(array_map('intval', (array) $user_ids)));
        $this->store->enqueue(
            $recipients,
            'event',
            $title,
            'Startet ' . $when,
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
        $title = (string) ($event['title'] ?? 'Neues Event');
        $space_ids = $this->access->decode_ids($event['space_ids'] ?? []);
        $recipients = [];
        foreach ($space_ids as $space_id) {
            $recipients = array_merge($recipients, $this->access->space_member_ids($space_id));
        }
        $recipients = array_diff(array_unique($recipients), [(int) $actor_id]);
        $this->store->enqueue(
            $recipients,
            'event',
            $title,
            'Neuer Termin in deinem Kreis',
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
            $result = $this->push->send($sub, $payload);
            if (!empty($result['ok'])) {
                $ok_any = true;
                continue;
            }
            $last_status = (int) ($result['status'] ?? 0);
            $last_error = (string) ($result['error'] ?? 'send failed');
            if (in_array($last_status, [404, 410], true)) {
                $this->store->delete_endpoint((string) $sub['endpoint']);
            }
        }

        if ($ok_any) {
            $this->store->mark_sent((int) $row['id']);
            return;
        }

        $this->store->mark_retry((int) $row['id'], $last_error ?: 'kein Gerät', $last_status);
    }

    private function enabled(string $option): bool
    {
        return (bool) get_option(Orgasmic_Fc_App_Install::OPTION_ENABLED, 1)
            && (bool) get_option($option, 1);
    }

    private function with_body(string $fallback, string $preview): string
    {
        $preview = trim(preg_replace('/\s+/', ' ', $preview) ?? $preview);
        if ($preview === '' || !(bool) get_option(Orgasmic_Fc_App_Install::OPTION_INCLUDE_BODY, 0)) {
            return $fallback;
        }
        if (function_exists('mb_substr')) {
            return mb_substr($preview, 0, 120);
        }
        return substr($preview, 0, 120);
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
