<?php
if (!defined('ABSPATH')) { exit; }
class Hamnoor_Notification_Service {
    public static function sms_provider() { return apply_filters('hamnoor_sms_provider', null); }
    public static function send($type, $recipient, $message, $context = array()) {
        $type = sanitize_key($type);
        $recipient = sanitize_text_field($recipient);
        $message = (string) $message;
        $context = is_array($context) ? $context : array();
        do_action('hamnoor_notification_requested', $type, $recipient, $message, $context);

        $filtered = apply_filters('hamnoor_send_notification', null, $type, $recipient, $message, $context);
        if (null !== $filtered) {
            return $filtered;
        }

        $providers = Hamnoor_Addons::notification_providers();
        if ('sms' === $type) {
            $legacy = self::sms_provider();
            if ($legacy) { $providers = array('legacy_sms' => $legacy) + $providers; }
        }
        foreach ($providers as $provider) {
            if ($provider instanceof Hamnoor_Notification_Provider_Interface && $provider->supports($type)) {
                return $provider->send($type, $recipient, $message, $context);
            }
            if (is_callable($provider)) {
                return call_user_func($provider, $type, $recipient, $message, $context);
            }
        }
        return new WP_Error('hamnoor_notification_not_configured', __('سرویس اعلان هنوز پیکربندی نشده است.', 'hamnoor'));
    }
}
