<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Orgasmic_Fc_Rest
{
    public function __construct(private Orgasmic_Fc_Store $store)
    {
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
    }

    public function routes(): void
    {
        register_rest_route('orgasmic-fc/v1', '/summary', [
            'methods' => 'GET',
            'permission_callback' => [$this, 'can_manage'],
            'callback' => function (WP_REST_Request $request) {
                return rest_ensure_response($this->store->get_summary((int) $request->get_param('days') ?: 7));
            },
        ]);

        register_rest_route('orgasmic-fc/v1', '/members', [
            'methods' => 'GET',
            'permission_callback' => [$this, 'can_manage'],
            'callback' => function () {
                return rest_ensure_response($this->store->get_member_stats(200));
            },
        ]);

        register_rest_route('orgasmic-fc/v1', '/courses', [
            'methods' => 'GET',
            'permission_callback' => [$this, 'can_manage'],
            'callback' => function () {
                return rest_ensure_response($this->store->get_course_progress());
            },
        ]);

        register_rest_route('orgasmic-fc/v1', '/events', [
            'methods' => 'GET',
            'permission_callback' => [$this, 'can_manage'],
            'callback' => function (WP_REST_Request $request) {
                $args = [
                    'user_id' => (int) $request->get_param('user_id'),
                    'category' => (string) $request->get_param('category'),
                    'event' => (string) $request->get_param('event'),
                    'limit' => min(200, (int) $request->get_param('limit') ?: 50),
                    'offset' => (int) $request->get_param('offset'),
                ];

                return rest_ensure_response([
                    'total' => $this->store->count_events($args),
                    'items' => $this->store->get_events($args),
                ]);
            },
        ]);
    }

    public function can_manage(): bool
    {
        return current_user_can('manage_options');
    }
}
