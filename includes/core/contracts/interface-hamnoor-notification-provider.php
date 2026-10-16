<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Lightweight contract for notification add-ons.
 *
 * Providers are registered through Hamnoor_Addons::register_notification_provider().
 */
interface Hamnoor_Notification_Provider_Interface {
    /**
     * Return whether this provider supports a notification channel/type.
     *
     * @param string $type Notification type such as sms or in_app.
     * @return bool
     */
    public function supports($type);

    /**
     * Send a notification.
     *
     * @param string $type
     * @param string $recipient
     * @param string $message
     * @param array  $context
     * @return true|WP_Error
     */
    public function send($type, $recipient, $message, $context = array());
}
