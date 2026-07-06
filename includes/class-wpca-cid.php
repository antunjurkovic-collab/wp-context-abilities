<?php
if (!defined('ABSPATH')) { exit; }

class WPCA_CID {
    /**
     * Compute strong CID (sha256-<hex>) over canonical JSON of the provided associative array.
     * Excludes volatile keys and enforces stable key ordering at all levels.
     */
    public static function compute(array $payload, array $exclude_keys = array('cid','links')): string {
        $exclude_keys = apply_filters('wpca_cid_exclude_keys', $exclude_keys, $payload);
        $clean = self::deep_exclude($payload, $exclude_keys);
        $canonical = self::canonicalize($clean);
        $json = wp_json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) { $json = json_encode($canonical); }
        $hex = hash('sha256', $json);
        return 'sha256-' . $hex;
    }

    private static function deep_exclude($data, $exclude_keys){
        if (is_array($data)){
            $is_assoc = array_keys($data) !== range(0, count($data) - 1);
            if ($is_assoc){
                $out = array();
                foreach ($data as $k => $v){
                    if (in_array($k, $exclude_keys, true)) continue;
                    $out[$k] = self::deep_exclude($v, $exclude_keys);
                }
                return $out;
            } else {
                $out = array();
                foreach ($data as $v){ $out[] = self::deep_exclude($v, $exclude_keys); }
                return $out;
            }
        }
        return $data;
    }

    /** Sort associative array keys lexicographically at all levels */
    private static function canonicalize($data){
        if (is_array($data)){
            $is_assoc = array_keys($data) !== range(0, count($data) - 1);
            if ($is_assoc){
                ksort($data);
                $res = array();
                foreach ($data as $k=>$v){ $res[$k] = self::canonicalize($v); }
                return $res;
            } else {
                $res = array();
                foreach ($data as $v){ $res[] = self::canonicalize($v); }
                return $res;
            }
        }
        return $data;
    }
}

