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
                'error' => 'Bitte mindestens einen Space wählen, damit der Termin im Activity Stream erscheint.',
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
