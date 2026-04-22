<?php
/**
 * Pattern drift detector.
 *
 * Compares a pattern's embedded class payload against the current site
 * class definitions. Surfaces missing classes, value drift, and clean
 * classes. Runs lazily when admin UI opens pattern detail or when
 * design_pattern(get) is called with include_drift: true.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Services;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class PatternDriftDetector {

    /** Keys ignored during diff (Bricks-assigned metadata, not style-relevant). */
    private const IGNORED_META_KEYS = [ 'id', 'modified', 'user_id', 'color' ];

    /**
     * @param array<string, mixed> $pattern      Full pattern (classes map in $pattern['classes']).
     * @param array<string, mixed> $site_classes Map of class_name => live class def.
     * @return array drift report
     */
    public function detect( array $pattern, array $site_classes ): array {
        $report = [
            'drift_detected'      => false,
            'patterns_checked_at' => gmdate( 'c' ),
            'missing_on_site'     => [],
            'drifted'             => [],
            'clean'               => [],
        ];

        $pattern_classes = $pattern['classes'] ?? [];
        if ( ! is_array( $pattern_classes ) || empty( $pattern_classes ) ) {
            return $report;
        }

        foreach ( $pattern_classes as $name => $pattern_class ) {
            if ( ! isset( $site_classes[ $name ] ) ) {
                $report['missing_on_site'][] = $name;
                continue;
            }
            $pattern_settings = $this->clean_meta( (array) ( $pattern_class['settings'] ?? [] ) );
            $site_settings    = $this->clean_meta( (array) ( $site_classes[ $name ]['settings'] ?? [] ) );
            $diff = $this->deep_diff( $pattern_settings, $site_settings, '' );
            if ( empty( $diff['added_keys'] ) && empty( $diff['removed_keys'] ) && empty( $diff['changed_keys'] ) ) {
                $report['clean'][] = $name;
            } else {
                $report['drifted'][ $name ] = $diff;
            }
        }

        $report['drift_detected'] = ! empty( $report['missing_on_site'] ) || ! empty( $report['drifted'] );
        return $report;
    }

    private function clean_meta( array $settings ): array {
        foreach ( self::IGNORED_META_KEYS as $key ) {
            unset( $settings[ $key ] );
        }
        return $settings;
    }

    /**
     * Recursive diff between two settings trees.
     */
    private function deep_diff( array $pattern, array $site, string $prefix ): array {
        $result = [ 'added_keys' => [], 'removed_keys' => [], 'changed_keys' => [] ];

        foreach ( $pattern as $key => $p_val ) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if ( ! array_key_exists( $key, $site ) ) {
                $result['added_keys'][] = $path;
                continue;
            }
            $s_val = $site[ $key ];
            if ( is_array( $p_val ) && is_array( $s_val ) ) {
                $sub = $this->deep_diff( $p_val, $s_val, $path );
                $result['added_keys']   = array_merge( $result['added_keys'], $sub['added_keys'] );
                $result['removed_keys'] = array_merge( $result['removed_keys'], $sub['removed_keys'] );
                $result['changed_keys'] = array_merge( $result['changed_keys'], $sub['changed_keys'] );
                continue;
            }
            if ( $p_val !== $s_val ) {
                $result['changed_keys'][] = [
                    'path'          => $path,
                    'pattern_value' => $p_val,
                    'site_value'    => $s_val,
                ];
            }
        }

        foreach ( $site as $key => $s_val ) {
            if ( ! array_key_exists( $key, $pattern ) ) {
                $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
                $result['removed_keys'][] = $path;
            }
        }

        return $result;
    }
}
