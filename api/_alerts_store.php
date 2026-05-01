<?php

if (!function_exists('alerts_store_is_list')) {
    function alerts_store_is_list($value)
    {
        if (!is_array($value)) {
            return false;
        }
        $i = 0;
        foreach (array_keys($value) as $key) {
            if ($key !== $i) {
                return false;
            }
            $i++;
        }
        return true;
    }
}

if (!function_exists('alerts_store_read')) {
    function alerts_store_read($path)
    {
        $raw = json_decode(@file_get_contents($path), true);
        $payload = is_array($raw) ? $raw : [];
        $itemsRaw = [];

        if (isset($payload['items']) && is_array($payload['items'])) {
            $itemsRaw = $payload['items'];
        } elseif (alerts_store_is_list($payload)) {
            $itemsRaw = $payload;
            $payload = [];
        }

        $items = [];
        foreach ($itemsRaw as $key => $it) {
            if (!is_array($it)) {
                continue;
            }
            if ((!isset($it['id']) || $it['id'] === '') && is_string($key) && $key !== '') {
                $it['id'] = $key;
            }
            $items[] = $it;
        }

        unset($payload['items']);
        return ['payload' => $payload, 'items' => array_values($items)];
    }
}

if (!function_exists('alerts_store_write')) {
    function alerts_store_write($path, $payload, $items)
    {
        $base = is_array($payload) ? $payload : [];
        $base['items'] = array_values(is_array($items) ? $items : []);
        write_json_atomic($path, $base);
    }
}
