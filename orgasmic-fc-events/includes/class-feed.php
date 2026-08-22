<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Orgasmic_Fc_Events_Feed
{
    public function share(array $event, Orgasmic_Fc_Events_Access $access): array
    {
        if (!class_exists('FluentCommunity\\App\\Models\\Feed')) {
            return [];
        }

        $space_ids = $access->decode_ids($event['space_ids'] ?? '[]');
        if (($event['visibility'] ?? '') === 'all' || $space_ids === []) {
            $space_ids = [0];
        }

        $created = [];
        $feed_class = 'FluentCommunity\\App\\Models\\Feed';
        $excerpt = wp_trim_words(wp_strip_all_tags((string) ($event['description'] ?? '')), 40);
        $portal = $this->portal_url() . '#orgasmic-event-' . (int) $event['id'];
        $when = $this->format_when($event);
        $html = '<p><strong>' . esc_html((string) $event['title']) . '</strong></p>'
            . '<p>' . esc_html($when) . '</p>'
            . ($excerpt !== '' ? '<p>' . esc_html($excerpt) . '</p>' : '')
            . '<p><a href="' . esc_url($portal) . '">Im Kalender öffnen</a></p>';

        $image = !empty($event['image_id']) ? wp_get_attachment_url((int) $event['image_id']) : '';

        foreach ($space_ids as $space_id) {
            $payload = [
                'user_id' => (int) ($event['created_by'] ?: get_current_user_id()),
                'title' => (string) $event['title'],
                'message' => $html,
                'message_rendered' => $html,
                'type' => 'feed',
                'content_type' => 'text',
                'status' => 'published',
                'privacy' => $space_id ? 'private' : 'public',
            ];
            if ($space_id) {
                $payload['space_id'] = $space_id;
            }
            if ($image) {
                $payload['featured_image'] = $image;
            }

            try {
                $feed = $feed_class::create($payload);
                do_action('fluent_community/feed/created', $feed);
                if ($space_id) {
                    do_action('fluent_community/space_feed/created', $feed);
                }
                $created[] = (int) ($feed->id ?? 0);
            } catch (Throwable $e) {
                continue;
            }
        }

        return array_values(array_filter($created));
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
