<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Orgasmic_Fc_Events_Reminders
{
    public function __construct(private Orgasmic_Fc_Events_Repository $repo)
    {
    }

    public function register(): void
    {
        add_action('orgasmic_fc_events_reminders', [$this, 'run']);
    }

    public function run(): void
    {
        $now = time();
        foreach ($this->repo->upcoming_for_reminders() as $event) {
            $start = strtotime((string) $event['starts_at']) ?: 0;
            if ($start <= 0) {
                continue;
            }

            $offsets = json_decode((string) $event['reminder_minutes'], true);
            if (!is_array($offsets) || $offsets === []) {
                $offsets = (array) get_option(Orgasmic_Fc_Events_Install::OPTION_DEFAULT_REMINDERS, [1440, 60]);
            }

            foreach ($offsets as $minutes) {
                $minutes = (int) $minutes;
                if ($minutes < 1) {
                    continue;
                }
                $fire_at = $start - ($minutes * 60);
                if ($now < $fire_at || $now > $fire_at + (20 * MINUTE_IN_SECONDS)) {
                    continue;
                }
                if ($this->repo->reminder_fired((int) $event['id'], $minutes)) {
                    continue;
                }

                $this->repo->mark_reminder_fired((int) $event['id'], $minutes);
                do_action('orgasmic_fc/event/reminder', $event, $minutes, $this->repo->going_user_ids((int) $event['id']));
            }
        }
    }
}
