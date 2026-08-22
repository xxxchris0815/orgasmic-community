<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Orgasmic_Fc_Hooks
{
    public function __construct(
        private Orgasmic_Fc_Store $store,
        private Orgasmic_Fc_Webhook $webhook
    ) {
    }

    public function register(): void
    {
        if (!$this->fluent_community_active()) {
            return;
        }

        // Courses
        add_action('fluent_community/course/enrolled', [$this, 'on_course_enrolled'], 20, 4);
        add_action('fluent_community/course/student_left', [$this, 'on_course_student_left'], 20, 3);
        add_action('fluent_community/course/lesson_completed', [$this, 'on_lesson_completed'], 20, 2);
        add_action('fluent_community/course/lesson_marked_incomplete', [$this, 'on_lesson_incomplete'], 20, 2);
        add_action('fluent_community/course/topic_completed', [$this, 'on_topic_completed'], 20, 3);
        add_action('fluent_community/course/completed', [$this, 'on_course_completed'], 20, 2);
        add_action('fluent_community/course/progress_reset', [$this, 'on_progress_reset'], 20, 2);

        // Feeds — only the generic hook to avoid duplicates with space_feed/profile_feed
        add_action('fluent_community/feed/created', [$this, 'on_feed_created'], 20, 1);
        add_action('fluent_community/feed/updated', [$this, 'on_feed_updated'], 20, 2);
        add_action('fluent_community/feed/deleted', [$this, 'on_feed_deleted'], 20, 1);
        add_action('fluent_community/feed_mentioned', [$this, 'on_feed_mentioned'], 20, 2);
        add_action('fluent_community/feed/cast_survey_vote', [$this, 'on_survey_vote'], 20, 3);

        // Comments
        add_action('fluent_community/comment_added', [$this, 'on_comment_added'], 20, 3);
        add_action('fluent_community/comment_updated', [$this, 'on_comment_updated'], 20, 2);
        add_action('fluent_community/comment_deleted', [$this, 'on_comment_deleted'], 20, 2);

        // Reactions
        add_action('fluent_community/feed/react_added', [$this, 'on_feed_react_added'], 20, 2);
        add_action('fluent_community/feed/react_removed', [$this, 'on_feed_react_removed'], 20, 1);
        add_action('fluent_community/comment/react_added', [$this, 'on_comment_react_added'], 20, 3);
        add_action('fluent_community/comment/react_removed', [$this, 'on_comment_react_removed'], 20, 2);

        // Spaces
        add_action('fluent_community/space/joined', [$this, 'on_space_joined'], 20, 3);
        add_action('fluent_community/space/join_requested', [$this, 'on_space_join_requested'], 20, 2);
        add_action('fluent_community/space/user_left', [$this, 'on_space_left'], 20, 3);
        add_action('fluent_community/space/created', [$this, 'on_space_created'], 20, 2);

        // Members
        add_action('fluent_community/profile_deactivated', [$this, 'on_profile_deactivated'], 20, 1);
        add_action('fluent_community/reactivate_account', [$this, 'on_profile_reactivated'], 20, 1);
        add_action('fluent_community/user_points_updated', [$this, 'on_points_updated'], 20, 2);
        add_action('fluent_community/user_level_upgraded', [$this, 'on_level_upgraded'], 20, 3);
        add_action('fluent_community/followed_user', [$this, 'on_followed_user'], 20, 2);

        // Community calendar (ORGAMSIC Events plugin)
        add_action('orgasmic_fc/event/created', [$this, 'on_cal_created'], 20, 2);
        add_action('orgasmic_fc/event/updated', [$this, 'on_cal_updated'], 20, 2);
        add_action('orgasmic_fc/event/deleted', [$this, 'on_cal_deleted'], 20, 2);
        add_action('orgasmic_fc/event/viewed', [$this, 'on_cal_viewed'], 20, 2);
        add_action('orgasmic_fc/event/rsvp', [$this, 'on_cal_rsvp'], 20, 4);
        add_action('orgasmic_fc/event/reminder', [$this, 'on_cal_reminder'], 20, 3);
    }

    public function on_course_enrolled($course, $user_id, $by = 'self', $created = null): void
    {
        $this->record('courses', 'course.enrolled', (int) $user_id, [
            'object_type' => 'course',
            'object_id' => $this->id($course),
            'data' => [
                'course' => $this->model($course, ['id', 'title', 'slug', 'privacy', 'status']),
                'by' => (string) $by,
                'reactivated' => $created === null,
            ],
        ]);
    }

    public function on_course_student_left($course, $user_id, $by = 'self'): void
    {
        $this->record('courses', 'course.student_left', (int) $user_id, [
            'object_type' => 'course',
            'object_id' => $this->id($course),
            'data' => [
                'course' => $this->model($course, ['id', 'title', 'slug']),
                'by' => (string) $by,
            ],
        ]);
    }

    public function on_lesson_completed($lesson, $user_id): void
    {
        $course_id = $this->prop($lesson, 'course_id') ?: $this->prop($lesson, 'space_id');

        $this->record('courses', 'course.lesson_completed', (int) $user_id, [
            'object_type' => 'lesson',
            'object_id' => $this->id($lesson),
            'parent_type' => $course_id ? 'course' : null,
            'parent_id' => $course_id ?: null,
            'data' => [
                'lesson' => $this->model($lesson, ['id', 'title', 'slug', 'course_id', 'space_id', 'parent_id', 'section_id', 'type', 'status']),
                'course' => $this->related_course($lesson),
            ],
        ]);
    }

    public function on_lesson_incomplete($lesson, $user_id): void
    {
        $course_id = $this->prop($lesson, 'course_id') ?: $this->prop($lesson, 'space_id');

        $this->record('courses', 'course.lesson_incomplete', (int) $user_id, [
            'object_type' => 'lesson',
            'object_id' => $this->id($lesson),
            'parent_type' => $course_id ? 'course' : null,
            'parent_id' => $course_id ?: null,
            'data' => [
                'lesson' => $this->model($lesson, ['id', 'title', 'slug', 'course_id', 'space_id', 'section_id']),
            ],
        ]);
    }

    public function on_topic_completed($topic, $user_id, $lesson = null): void
    {
        $this->record('courses', 'course.topic_completed', (int) $user_id, [
            'object_type' => 'topic',
            'object_id' => $this->id($topic),
            'parent_type' => 'course',
            'parent_id' => $this->prop($topic, 'space_id') ?: $this->prop($topic, 'course_id') ?: $this->prop($lesson, 'course_id'),
            'data' => [
                'topic' => $this->model($topic, ['id', 'title', 'slug']),
                'lesson' => $this->model($lesson, ['id', 'title', 'slug']),
            ],
        ]);
    }

    public function on_course_completed($course, $user_id): void
    {
        $this->record('courses', 'course.completed', (int) $user_id, [
            'object_type' => 'course',
            'object_id' => $this->id($course),
            'data' => [
                'course' => $this->model($course, ['id', 'title', 'slug']),
            ],
        ]);
    }

    public function on_progress_reset($course, $user_id): void
    {
        $this->record('courses', 'course.progress_reset', (int) $user_id, [
            'object_type' => 'course',
            'object_id' => $this->id($course),
            'data' => [
                'course' => $this->model($course, ['id', 'title', 'slug']),
            ],
        ]);
    }

    public function on_feed_created($feed): void
    {
        $space_id = $this->prop($feed, 'space_id');

        $this->record('feeds', 'feed.created', (int) $this->prop($feed, 'user_id'), [
            'object_type' => 'feed',
            'object_id' => $this->id($feed),
            'parent_type' => $space_id ? 'space' : 'profile',
            'parent_id' => $space_id ?: null,
            'data' => [
                'feed' => $this->feed_summary($feed),
            ],
        ]);
    }

    public function on_feed_updated($feed, $update_data = []): void
    {
        $this->record('feeds', 'feed.updated', (int) $this->prop($feed, 'user_id'), [
            'object_type' => 'feed',
            'object_id' => $this->id($feed),
            'parent_type' => $this->prop($feed, 'space_id') ? 'space' : 'profile',
            'parent_id' => $this->prop($feed, 'space_id') ?: null,
            'data' => [
                'feed' => $this->feed_summary($feed),
                'changed_keys' => is_array($update_data) ? array_keys($update_data) : [],
            ],
        ]);
    }

    public function on_feed_deleted($feed_id): void
    {
        $this->record('feeds', 'feed.deleted', get_current_user_id() ?: null, [
            'object_type' => 'feed',
            'object_id' => (int) $feed_id,
            'data' => [
                'feed_id' => (int) $feed_id,
            ],
        ]);
    }

    public function on_feed_mentioned($feed, $mentioned_users = null): void
    {
        $this->record('feeds', 'feed.mentioned', (int) $this->prop($feed, 'user_id'), [
            'object_type' => 'feed',
            'object_id' => $this->id($feed),
            'data' => [
                'mentioned_user_ids' => $this->collection_ids($mentioned_users),
            ],
        ]);
    }

    public function on_survey_vote($indexes, $feed, $user_id): void
    {
        $this->record('feeds', 'feed.survey_vote', (int) $user_id, [
            'object_type' => 'feed',
            'object_id' => $this->id($feed),
            'data' => [
                'options' => is_array($indexes) ? $indexes : [],
            ],
        ]);
    }

    public function on_comment_added($comment, $feed, $mentioned_users = null): void
    {
        $this->record('comments', 'comment.added', (int) $this->prop($comment, 'user_id'), [
            'object_type' => 'comment',
            'object_id' => $this->id($comment),
            'parent_type' => 'feed',
            'parent_id' => $this->id($feed),
            'data' => [
                'comment' => $this->comment_summary($comment),
                'feed' => $this->feed_summary($feed),
                'mentioned_user_ids' => $this->collection_ids($mentioned_users),
                'is_reply' => (bool) $this->prop($comment, 'parent_id'),
            ],
        ]);
    }

    public function on_comment_updated($comment, $feed): void
    {
        $this->record('comments', 'comment.updated', (int) $this->prop($comment, 'user_id'), [
            'object_type' => 'comment',
            'object_id' => $this->id($comment),
            'parent_type' => 'feed',
            'parent_id' => $this->id($feed),
            'data' => [
                'comment' => $this->comment_summary($comment),
            ],
        ]);
    }

    public function on_comment_deleted($comment_id, $feed = null): void
    {
        $this->record('comments', 'comment.deleted', get_current_user_id() ?: null, [
            'object_type' => 'comment',
            'object_id' => (int) $comment_id,
            'parent_type' => 'feed',
            'parent_id' => $this->id($feed),
            'data' => [
                'comment_id' => (int) $comment_id,
                'feed_id' => $this->id($feed),
            ],
        ]);
    }

    public function on_feed_react_added($reaction, $feed): void
    {
        $this->record('reactions', 'feed.react_added', (int) $this->prop($reaction, 'user_id'), [
            'object_type' => 'feed',
            'object_id' => $this->id($feed),
            'data' => [
                'reaction' => $this->model($reaction, ['id', 'type', 'user_id', 'object_id']),
                'feed_id' => $this->id($feed),
            ],
        ]);
    }

    public function on_feed_react_removed($feed): void
    {
        $this->record('reactions', 'feed.react_removed', get_current_user_id() ?: null, [
            'object_type' => 'feed',
            'object_id' => $this->id($feed),
            'data' => [
                'feed_id' => $this->id($feed),
            ],
        ]);
    }

    public function on_comment_react_added($reaction, $comment, $feed): void
    {
        $this->record('reactions', 'comment.react_added', (int) $this->prop($reaction, 'user_id'), [
            'object_type' => 'comment',
            'object_id' => $this->id($comment),
            'parent_type' => 'feed',
            'parent_id' => $this->id($feed),
            'data' => [
                'reaction' => $this->model($reaction, ['id', 'type', 'user_id']),
                'comment_id' => $this->id($comment),
                'feed_id' => $this->id($feed),
            ],
        ]);
    }

    public function on_comment_react_removed($comment, $feed): void
    {
        $this->record('reactions', 'comment.react_removed', get_current_user_id() ?: null, [
            'object_type' => 'comment',
            'object_id' => $this->id($comment),
            'parent_type' => 'feed',
            'parent_id' => $this->id($feed),
        ]);
    }

    public function on_space_joined($space, $user_id, $by = 'self'): void
    {
        $this->record('spaces', 'space.joined', (int) $user_id, [
            'object_type' => 'space',
            'object_id' => $this->id($space),
            'data' => [
                'space' => $this->model($space, ['id', 'title', 'slug', 'privacy', 'type']),
                'by' => (string) $by,
                'role' => $this->space_role($space),
            ],
        ]);
    }

    public function on_space_join_requested($space, $user_id): void
    {
        $this->record('spaces', 'space.join_requested', (int) $user_id, [
            'object_type' => 'space',
            'object_id' => $this->id($space),
            'data' => [
                'space' => $this->model($space, ['id', 'title', 'slug', 'privacy']),
            ],
        ]);
    }

    public function on_space_left($space, $user_id, $by = 'self'): void
    {
        $this->record('spaces', 'space.left', (int) $user_id, [
            'object_type' => 'space',
            'object_id' => $this->id($space),
            'data' => [
                'space' => $this->model($space, ['id', 'title', 'slug']),
                'by' => (string) $by,
            ],
        ]);
    }

    public function on_space_created($space, $data = []): void
    {
        $this->record('spaces', 'space.created', get_current_user_id() ?: (int) $this->prop($space, 'user_id'), [
            'object_type' => 'space',
            'object_id' => $this->id($space),
            'data' => [
                'space' => $this->model($space, ['id', 'title', 'slug', 'privacy', 'type']),
            ],
        ]);
    }

    public function on_profile_deactivated($xprofile): void
    {
        $this->record('members', 'member.deactivated', (int) $this->prop($xprofile, 'user_id'), [
            'object_type' => 'xprofile',
            'object_id' => $this->id($xprofile),
        ]);
    }

    public function on_profile_reactivated($xprofile): void
    {
        $this->record('members', 'member.reactivated', (int) $this->prop($xprofile, 'user_id'), [
            'object_type' => 'xprofile',
            'object_id' => $this->id($xprofile),
        ]);
    }

    public function on_points_updated($xprofile, $old_points = 0): void
    {
        $this->record('members', 'member.points_updated', (int) $this->prop($xprofile, 'user_id'), [
            'object_type' => 'xprofile',
            'object_id' => $this->id($xprofile),
            'data' => [
                'old_points' => (int) $old_points,
                'new_points' => (int) $this->prop($xprofile, 'total_points'),
            ],
        ]);
    }

    public function on_level_upgraded($xprofile, $new_level = [], $old_level = []): void
    {
        $this->record('members', 'member.level_upgraded', (int) $this->prop($xprofile, 'user_id'), [
            'object_type' => 'xprofile',
            'object_id' => $this->id($xprofile),
            'data' => [
                'new_level' => is_array($new_level) ? $new_level : [],
                'old_level' => is_array($old_level) ? $old_level : [],
            ],
        ]);
    }

    public function on_followed_user($follow, $xprofile = null): void
    {
        $follower_id = (int) $this->prop($follow, 'follower_id') ?: get_current_user_id();

        $this->record('members', 'member.followed', $follower_id ?: null, [
            'object_type' => 'user',
            'object_id' => (int) $this->prop($follow, 'following_id') ?: (int) $this->prop($xprofile, 'user_id'),
            'data' => [
                'follow' => $this->model($follow, ['id', 'follower_id', 'following_id', 'status']),
            ],
        ]);
    }

    public function on_cal_created($event, $user_id): void
    {
        $this->record('events', 'event.created', (int) $user_id, $this->calendar_context($event));
    }

    public function on_cal_updated($event, $user_id): void
    {
        $this->record('events', 'event.updated', (int) $user_id, $this->calendar_context($event));
    }

    public function on_cal_deleted($event, $user_id): void
    {
        $this->record('events', 'event.deleted', (int) $user_id, $this->calendar_context($event));
    }

    public function on_cal_viewed($event, $user_id): void
    {
        $this->record('events', 'event.viewed', (int) $user_id, $this->calendar_context($event));
    }

    public function on_cal_rsvp($event, $user_id, $status = '', $previous = null): void
    {
        $ctx = $this->calendar_context($event);
        $ctx['data']['rsvp'] = (string) $status;
        $ctx['data']['previous'] = $previous;
        $this->record('events', 'event.rsvp', (int) $user_id, $ctx);
    }

    public function on_cal_reminder($event, $minutes = 0, $user_ids = []): void
    {
        $ctx = $this->calendar_context($event);
        $ctx['data']['minutes_before'] = (int) $minutes;
        $ctx['data']['going_user_ids'] = array_values(array_map('intval', (array) $user_ids));
        $this->record('events', 'event.reminder', null, $ctx);
    }

    private function calendar_context($event): array
    {
        $row = is_array($event) ? $event : [];
        return [
            'object_type' => 'calendar_event',
            'object_id' => (int) ($row['id'] ?? 0),
            'data' => [
                'title' => $row['title'] ?? '',
                'starts_at' => $row['starts_at'] ?? null,
                'visibility' => $row['visibility'] ?? null,
                'space_ids' => $row['space_ids'] ?? null,
            ],
        ];
    }

    private function record(string $group, string $event, ?int $user_id, array $context = []): void
    {
        if (!$this->store->is_group_enabled($group)) {
            return;
        }

        $occurred_at = current_time('mysql', true);
        $payload = $this->build_payload($event, $group, $user_id, $context, $occurred_at);

        $this->store->insert_event([
            'occurred_at' => gmdate('Y-m-d H:i:s', strtotime($occurred_at) ?: time()),
            'event' => $event,
            'category' => $group,
            'user_id' => $user_id ?: null,
            'object_type' => $context['object_type'] ?? null,
            'object_id' => $context['object_id'] ?? null,
            'parent_type' => $context['parent_type'] ?? null,
            'parent_id' => $context['parent_id'] ?? null,
            'payload' => $payload,
        ]);

        $this->webhook->send($payload);
    }

    private function build_payload(string $event, string $group, ?int $user_id, array $context, string $occurred_at): array
    {
        $include_pii = (bool) get_option(Orgasmic_Fc_Store::OPTION_INCLUDE_PII, 1);
        $user = $user_id ? get_userdata($user_id) : null;

        $payload = [
            'source' => 'orgasmic-fc-tracker',
            'event' => $event,
            'category' => $group,
            'user_id' => $user_id,
            'object_type' => $context['object_type'] ?? null,
            'object_id' => $context['object_id'] ?? null,
            'parent_type' => $context['parent_type'] ?? null,
            'parent_id' => $context['parent_id'] ?? null,
            'data' => $context['data'] ?? new stdClass(),
            'site' => home_url(),
            'occurred_at' => gmdate('c', strtotime($occurred_at) ?: time()),
        ];

        if ($include_pii && $user) {
            $payload['user'] = [
                'id' => $user->ID,
                'email' => $user->user_email,
                'display_name' => $user->display_name,
                'login' => $user->user_login,
            ];
        } elseif ($user) {
            $payload['user'] = [
                'id' => $user->ID,
            ];
        }

        return $payload;
    }

    private function feed_summary($feed): array
    {
        $summary = $this->model($feed, ['id', 'title', 'type', 'user_id', 'space_id', 'status', 'message_rendered']);

        if (!get_option(Orgasmic_Fc_Store::OPTION_INCLUDE_CONTENT, 0)) {
            unset($summary['message_rendered']);
            $message = $this->prop($feed, 'message') ?: $this->prop($feed, 'title');
            $summary['excerpt'] = is_string($message) ? wp_trim_words(wp_strip_all_tags($message), 16) : '';
        } else {
            $message = $this->prop($feed, 'message');
            $summary['message'] = is_string($message) ? wp_strip_all_tags($message) : '';
        }

        return $summary;
    }

    private function comment_summary($comment): array
    {
        $summary = $this->model($comment, ['id', 'user_id', 'parent_id', 'post_id', 'feed_id']);

        if (get_option(Orgasmic_Fc_Store::OPTION_INCLUDE_CONTENT, 0)) {
            $message = $this->prop($comment, 'message') ?: $this->prop($comment, 'content');
            $summary['message'] = is_string($message) ? wp_strip_all_tags($message) : '';
        } else {
            $message = $this->prop($comment, 'message') ?: $this->prop($comment, 'content');
            $summary['excerpt'] = is_string($message) ? wp_trim_words(wp_strip_all_tags($message), 12) : '';
        }

        return $summary;
    }

    private function related_course($lesson): array
    {
        if (is_object($lesson) && isset($lesson->course) && is_object($lesson->course)) {
            return $this->model($lesson->course, ['id', 'title', 'slug']);
        }

        $course_id = $this->prop($lesson, 'course_id') ?: $this->prop($lesson, 'space_id');
        return $course_id ? ['id' => $course_id] : [];
    }

    private function space_role($space): ?string
    {
        if (is_object($space) && isset($space->membership) && is_object($space->membership)) {
            $role = $this->prop($space->membership, 'role');
            return is_string($role) ? $role : null;
        }

        return null;
    }

    private function model($model, array $keys): array
    {
        if (!is_object($model) && !is_array($model)) {
            return [];
        }

        $out = [];
        foreach ($keys as $key) {
            $value = $this->prop($model, $key);
            if ($value !== null && $value !== '') {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    private function prop($model, string $key)
    {
        if (is_array($model) && array_key_exists($key, $model)) {
            return $model[$key];
        }

        if (!is_object($model)) {
            return null;
        }

        if (isset($model->{$key})) {
            return $model->{$key};
        }

        if (method_exists($model, 'getAttribute')) {
            try {
                return $model->getAttribute($key);
            } catch (Throwable $e) {
                return null;
            }
        }

        return null;
    }

    private function id($model): ?int
    {
        $id = $this->prop($model, 'id');
        if ($id === null || $id === '') {
            return is_numeric($model) ? (int) $model : null;
        }

        return (int) $id;
    }

    private function collection_ids($collection): array
    {
        if ($collection === null) {
            return [];
        }

        if (is_object($collection) && method_exists($collection, 'pluck')) {
            return array_map('intval', $collection->pluck('ID')->all() ?: $collection->pluck('id')->all() ?: []);
        }

        if (is_array($collection)) {
            return array_values(array_filter(array_map(static function ($item) {
                if (is_numeric($item)) {
                    return (int) $item;
                }
                if (is_object($item) && isset($item->ID)) {
                    return (int) $item->ID;
                }
                if (is_object($item) && isset($item->id)) {
                    return (int) $item->id;
                }
                if (is_array($item) && isset($item['id'])) {
                    return (int) $item['id'];
                }
                return 0;
            }, $collection)));
        }

        return [];
    }

    private function fluent_community_active(): bool
    {
        return defined('FLUENT_COMMUNITY_PLUGIN_VERSION')
            || class_exists('FluentCommunity\\App\\App')
            || in_array('fluent-community/fluent-community.php', (array) get_option('active_plugins', []), true);
    }
}
