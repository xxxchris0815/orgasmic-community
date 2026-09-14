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
        add_action('orgasmic_fc/event/rsvp', [$this, 'on_event_rsvp'], 20, 4);
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
        $recipients = array_values(array_diff($this->access->space_notify_ids($space_id), [$actor_id]));
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
        $flags = $this->announce_flags();
        $space_id = (int) $this->access->prop($feed, 'space_id');
        $actor = (int) $this->access->prop($feed, 'user_id');
        $feed_id = $this->access->model_id($feed);
        $excerpt = wp_strip_all_tags((string) ($this->access->prop($feed, 'title') ?: $this->access->prop($feed, 'message') ?: ''));
        $url = $this->url($feed_id ? '?orgasmic_feed=' . $feed_id : '');

        if ($flags['push'] || $flags['email']) {
            $this->announce($feed, $flags, $space_id, $actor, $feed_id, $excerpt, $url);
        }

        if ($flags['push'] || !$this->enabled(Orgasmic_Fc_App_Install::OPTION_FEED) || $space_id < 1) {
            return;
        }

        $title = $this->heading($this->access->space_title($space_id), 'Beitrag');
        $body = $this->line($this->actor_name($actor), $excerpt, 'Neuer Beitrag');
        $recipients = array_values(array_diff($this->access->space_notify_ids($space_id), [$actor]));
        $recipients = $this->filter_prefs($recipients, 'feed');
        $this->store->enqueue(
            $recipients,
            'feed',
            $title,
            $body,
            $url,
            'feed-' . $feed_id,
            ['space_id' => $space_id, 'feed_id' => $feed_id]
        );
        $this->kick();
    }

    public function announce_feed($feed, bool $push, bool $email): array
    {
        if (!$this->access->can_announce()) {
            return ['ok' => false, 'error' => 'Keine Berechtigung.'];
        }
        $space_id = (int) $this->access->prop($feed, 'space_id');
        $actor = (int) $this->access->prop($feed, 'user_id');
        $feed_id = $this->access->model_id($feed);
        if ($feed_id < 1) {
            return ['ok' => false, 'error' => 'Beitrag nicht gefunden.'];
        }
        $excerpt = wp_strip_all_tags((string) ($this->access->prop($feed, 'title') ?: $this->access->prop($feed, 'message') ?: ''));
        $url = $this->url('?orgasmic_feed=' . $feed_id);
        $this->announce($feed, ['push' => $push, 'email' => $email], $space_id, $actor, $feed_id, $excerpt, $url);

        return ['ok' => true, 'push' => $push, 'email' => $email];
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
            $allowed = $this->access->space_notify_ids($space_id);
            $recipients = array_values(array_intersect($recipients, $allowed));
        }
        $recipients = $this->filter_prefs($recipients, 'comment');
        if ($recipients === []) {
            return;
        }

        $excerpt = wp_strip_all_tags((string) ($this->access->prop($comment, 'message') ?: $this->access->prop($comment, 'content') ?: ''));
        $title = $this->heading($space_id > 0 ? $this->access->space_title($space_id) : '', 'Kommentar');
        $url = $this->url($feed_id ? '?orgasmic_feed=' . $feed_id : '');
        $fallback = $this->line($this->actor_name($actor), $excerpt, 'Neuer Kommentar');
        $post_label = $this->post_label($feed);
        foreach ($recipients as $uid) {
            $commenters = $this->commenter_ids($feed_id, $uid);
            if ($commenters === []) {
                $commenters = $actor > 0 ? [$actor] : [];
            }
            $this->store->enqueue(
                [$uid],
                'comment',
                $title,
                $this->stack_members_line(
                    $commenters,
                    $fallback,
                    'haben „' . $post_label . '“ kommentiert',
                    'haben „' . $post_label . '“ kommentiert'
                ),
                $url,
                'comment-' . $feed_id,
                ['feed_id' => $feed_id, 'space_id' => $space_id, 'count' => count($commenters)]
            );
        }
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
        $heading = $this->heading($this->event_space_title($event), 'Event');
        if ($minutes <= 5) {
            $body = $event_title !== '' ? $event_title . ' — Event beginnt' : 'Event beginnt';
        } elseif ($minutes >= 1440) {
            $body = ($event_title !== '' ? $event_title : 'Event') . ' beginnt morgen';
        } else {
            $body = ($event_title !== '' ? $event_title : 'Event') . ' beginnt in ' . $minutes . ' Minuten';
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
            $recipients = array_merge($recipients, $this->access->space_notify_ids($space_id));
        }
        $recipients = array_values(array_diff(array_unique($recipients), [(int) $actor_id]));
        $recipients = $this->filter_prefs($recipients, 'event');
        $this->store->enqueue(
            $recipients,
            'event',
            $this->heading($this->event_space_title($event), 'Event'),
            $this->clip($event_title !== '' ? 'Neues Event: ' . $event_title : 'Neues Event', 160),
            $this->url($id ? '#orgasmic-event-' . $id : '#orgasmic-calendar'),
            'event-new-' . $id,
            ['event_id' => $id]
        );
        $this->kick();
    }

    public function on_event_rsvp($event, $user_id = 0, $status = '', $previous = null): void
    {
        if (!$this->enabled(Orgasmic_Fc_App_Install::OPTION_EVENT)) {
            return;
        }
        if ((string) $status !== 'going' || (string) $previous === 'going') {
            return;
        }

        $event = is_array($event) ? $event : [];
        $id = (int) ($event['id'] ?? 0);
        $host = (int) ($event['created_by'] ?? 0);
        $actor = (int) $user_id;
        if ($id < 1 || $host < 1 || $host === $actor) {
            return;
        }

        $recipients = $this->filter_prefs([$host], 'event');
        if ($recipients === []) {
            return;
        }

        $going = array_values(array_diff($this->event_going_ids($id), [$host]));
        if ($going === []) {
            $going = $actor > 0 ? [$actor] : [];
        }
        $label = $this->event_title_label($event);
        $fallback = $this->clip($this->actor_name($actor) . ' kommt zu „' . $label . '“', 160);
        $this->store->enqueue(
            $recipients,
            'event',
            $this->heading($this->event_space_title($event), 'Event'),
            $this->stack_members_line(
                $going,
                $fallback,
                'kommen zu „' . $label . '“',
                'kommen zu „' . $label . '“'
            ),
            $this->url('#orgasmic-event-' . $id),
            'event-rsvp-' . $id,
            ['event_id' => $id, 'count' => count($going)]
        );
        $this->kick();
    }

    public function flush(): void
    {
        $rows = $this->store->pending(40);
        foreach ($rows as $row) {
            $this->deliver($row);
        }
        $mails = $this->store->pending_mail(20);
        foreach ($mails as $row) {
            $this->deliver_mail($row);
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

        $extra = json_decode((string) ($row['payload'] ?? ''), true);
        $count = is_array($extra) ? (int) ($extra['count'] ?? 0) : 0;
        $payload = [
            'title' => (string) $row['title'],
            'body' => (string) $row['body'],
            'url' => (string) $row['url'],
            'tag' => (string) $row['tag'],
            'kind' => (string) $row['kind'],
            'count' => $count,
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

    private function announce($feed, array $flags, int $space_id, int $actor, int $feed_id, string $excerpt, string $url): void
    {
        $recipients = array_values(array_diff($this->access->audience_ids($space_id), [$actor]));
        if ($recipients === []) {
            return;
        }
        $space = $space_id > 0 ? $this->access->space_title($space_id) : '';
        $author = $this->actor_name($actor);
        $title = $this->heading($space, 'Ankündigung');
        $body = $this->line($author, $excerpt, 'Neuer Beitrag');

        if (!empty($flags['push'])) {
            $this->store->enqueue(
                $recipients,
                'announce',
                $title,
                $body,
                $url,
                'announce-' . $feed_id,
                ['space_id' => $space_id, 'feed_id' => $feed_id]
            );
        }
        if (!empty($flags['email'])) {
            $this->store->enqueue_mail(
                $recipients,
                $feed_id,
                $this->mail_subject($space, $author, $excerpt),
                $this->mail_body($author, $space, $excerpt, $url),
                $url
            );
        }
        $this->kick();
        unset($feed);
    }

    /**
     * Honor the composer checkboxes. Header from the intercepted feed POST, user-meta as fallback.
     * Meta is only consumed on a feed HTTP request so a leftover tick cannot blast an Event-Post.
     */
    private function announce_flags(): array
    {
        $header = strtolower((string) ($_SERVER['HTTP_X_ORGASMIC_ANNOUNCE'] ?? ''));
        $push = str_contains($header, 'push');
        $email = str_contains($header, 'email') || str_contains($header, 'mail');

        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $is_feed_http = (bool) preg_match('/feed/i', $uri)
            || (bool) preg_match('/fluent-community/i', $uri);
        $uid = get_current_user_id();
        if ($uid > 0 && $is_feed_http) {
            $meta = get_user_meta($uid, Orgasmic_Fc_App_Install::META_ANNOUNCE, true);
            if (is_array($meta) && (time() - (int) ($meta['at'] ?? 0)) <= 180) {
                $push = $push || !empty($meta['push']);
                $email = $email || !empty($meta['email']);
                delete_user_meta($uid, Orgasmic_Fc_App_Install::META_ANNOUNCE);
            }
        }

        if ((!$push && !$email) || !$this->access->can_announce()) {
            return ['push' => false, 'email' => false];
        }

        return ['push' => $push, 'email' => $email];
    }

    private function mail_subject(string $space, string $author, string $excerpt): string
    {
        $space = trim($space);
        if ($space !== '' && strcasecmp($space, 'Kreis') !== 0) {
            $head = $space;
        } else {
            $head = 'LO Community';
        }
        $preview = $this->clip($excerpt !== '' ? $excerpt : ($author !== '' ? $author . ' hat einen Beitrag geschrieben' : 'Neuer Beitrag'), 80);

        return $this->clip($head . ': ' . $preview, 120);
    }

    private function mail_body(string $author, string $space, string $excerpt, string $url): string
    {
        $who = $author !== '' ? $author : 'Ein Mitglied';
        $where = ($space !== '' && strcasecmp($space, 'Kreis') !== 0) ? ' in ' . $space : '';
        $text = $excerpt !== '' ? $excerpt : 'Neuer Beitrag';
        $open = $url !== '' ? $url : home_url('/');

        $html = '<p>' . esc_html($who) . ' hat einen neuen Beitrag veröffentlicht' . esc_html($where) . ':</p>';
        $html .= '<p>' . nl2br(esc_html($text)) . '</p>';
        $html .= '<p><a href="' . esc_url($open) . '">Beitrag öffnen</a></p>';
        $html .= '<p style="color:#6b6575;font-size:12px">Du erhältst diese E-Mail, weil ein Admin diesen Beitrag an alle Mitglieder gesendet hat.</p>';

        return $html;
    }

    private function deliver_mail(array $row): void
    {
        $user = get_userdata((int) ($row['user_id'] ?? 0));
        $to = $user && is_email((string) $user->user_email) ? (string) $user->user_email : '';
        if ($to === '') {
            $this->store->mark_mail_sent((int) $row['id']);
            return;
        }
        $subject = (string) ($row['subject'] ?? 'Neuer Beitrag');
        $body = (string) ($row['body'] ?? '');
        $ok = wp_mail($to, $subject, $body, ['Content-Type: text/html; charset=UTF-8']);
        if ($ok) {
            $this->store->mark_mail_sent((int) $row['id']);
            return;
        }
        $this->store->mark_mail_retry((int) $row['id'], 'wp_mail fehlgeschlagen');
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
        if ($space === '' || strcasecmp($space, 'Kreis') === 0) {
            return $kind;
        }

        return $space . ' · ' . $kind;
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
        // Several rooms → just "Event", not a generic "Kreis".
        if (count($ids) !== 1) {
            return '';
        }
        $title = $this->access->space_title($ids[0]);

        return ($title !== '' && strcasecmp($title, 'Kreis') !== 0) ? $title : '';
    }

    private function event_title_label(array $event): string
    {
        $title = trim(wp_strip_all_tags((string) ($event['title'] ?? '')));

        return $title !== '' ? $this->clip($title, 60) : 'dieses Event';
    }

    private function post_label($feed): string
    {
        $title = trim(wp_strip_all_tags((string) ($this->access->prop($feed, 'title') ?: '')));
        if ($title !== '') {
            return $this->clip($title, 60);
        }
        $message = trim(wp_strip_all_tags((string) ($this->access->prop($feed, 'message') ?: '')));
        if ($message !== '') {
            return $this->clip($message, 40);
        }

        return 'diesen Beitrag';
    }

    /**
     * @param int[] $actor_ids
     */
    private function stack_members_line(array $actor_ids, string $one_fallback, string $few_suffix, string $many_suffix): string
    {
        $actor_ids = array_values(array_unique(array_filter(array_map('intval', $actor_ids))));
        $count = count($actor_ids);
        if ($count <= 1) {
            return $one_fallback;
        }
        if ($count <= 3) {
            $names = [];
            foreach ($actor_ids as $id) {
                $names[] = $this->actor_name($id);
            }

            return $this->clip($this->join_names($names) . ' ' . $few_suffix, 160);
        }

        return $this->clip($count . ' Mitglieder ' . $many_suffix, 160);
    }

    /**
     * @param string[] $names
     */
    private function join_names(array $names): string
    {
        $names = array_values(array_filter(array_map('trim', $names)));
        if ($names === []) {
            return 'Mitglieder';
        }
        if (count($names) === 1) {
            return $names[0];
        }
        $last = array_pop($names);

        return implode(', ', $names) . ' und ' . $last;
    }

    /**
     * @return int[]
     */
    private function commenter_ids(int $feed_id, int $exclude_user_id): array
    {
        if ($feed_id < 1) {
            return [];
        }
        global $wpdb;
        $table = $wpdb->prefix . 'fcom_post_comments';
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($found !== $table) {
            return [];
        }
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT user_id FROM {$table}
             WHERE post_id = %d AND user_id != %d
               AND (status IS NULL OR status = '' OR status IN ('published','approved'))
             GROUP BY user_id
             ORDER BY MAX(created_at) DESC",
            $feed_id,
            $exclude_user_id
        ));

        return array_values(array_unique(array_filter(array_map('intval', $ids ?: []))));
    }

    /**
     * @return int[]
     */
    private function event_going_ids(int $event_id): array
    {
        if ($event_id < 1) {
            return [];
        }
        global $wpdb;
        $table = $wpdb->prefix . 'orgasmic_fc_cal_rsvps';
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($found !== $table) {
            return [];
        }
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT user_id FROM {$table} WHERE event_id = %d AND status = 'going' ORDER BY updated_at DESC",
            $event_id
        ));

        return array_values(array_unique(array_filter(array_map('intval', $ids ?: []))));
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
