<?php

if (!function_exists('setting')) {

    function setting($key, $default = null)
    {
        $settings = config('towmate.settings');

        if (is_array($settings) && array_key_exists($key, $settings)) {
            return $settings[$key];
        }

        return $default;
    }
}
