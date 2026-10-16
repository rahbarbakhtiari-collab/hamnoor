<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Contract for external notification channels used by the multichannel dispatcher.
 */
interface Hamnoor_Notification_Channel_Interface {
    /** @return string Stable channel key, e.g. web_push, eitaa, bale. */
    public function key();

    /** @return bool Whether this channel is configured and usable on this site. */
    public function is_available();

    /**
     * Whether this user has an active connection for the channel.
     * @param int $user_id
     * @return bool
     */
    public function is_connected($user_id);

    /**
     * Send one notification to the user.
     * @param int    $user_id
     * @param object $notification
     * @param array  $context
     * @return true|WP_Error
     */
    public function send_to_user($user_id, $notification, $context = array());
}
