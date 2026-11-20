<?php

/**
 * SensorBaselinePlan.php
 *
 * Turns the findings of SensorBaseline into the changes to make to sensors.
 * It only decides; it reads and writes nothing itself.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * @link       https://www.librenms.org
 */

namespace LibreNMS\Util;

/**
 * State is kept per device and records only what this job changed, so that it can
 * tell its own work from an operator's and undo it:
 *
 *   ['silenced' => [sensor_id, ...],
 *    'limits' => [sensor_id => ['old' => [low, high, custom], 'new' => [low, high]]]]
 */
class SensorBaselinePlan
{
    public const SILENCE = 'silence';
    public const UNSILENCE = 'unsilence';
    public const LIMITS = 'limits';
    public const RESTORE = 'restore';
    public const FORGET = 'forget';

    /**
     * Limits this job wrote and nobody has touched since. They are locked as custom in
     * the database so discovery keeps them, but they are still ours to adjust.
     *
     * @param  array<int|string, array<string, mixed>>  $sensors  rows keyed by sensor_id
     * @param  array<int|string, array<string, mixed>>  $state  keyed by device_id
     * @return array<int|string, true> sensor ids
     */
    public static function ownedLimits(array $sensors, array $state): array
    {
        $owned = [];
        foreach ($state as $deviceState) {
            foreach ($deviceState['limits'] ?? [] as $sensorId => $record) {
                $sensor = $sensors[$sensorId] ?? null;
                if ($sensor !== null
                    && ($sensor['sensor_custom'] ?? 'No') === 'Yes'
                    && self::same($sensor['sensor_limit_low'] ?? null, $record['new'][0] ?? null)
                    && self::same($sensor['sensor_limit'] ?? null, $record['new'][1] ?? null)) {
                    $owned[$sensorId] = true;
                }
            }
        }

        return $owned;
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups  result of SensorBaseline::analyse()
     * @param  array<int|string, array<string, mixed>>  $sensors  the analysed rows keyed by sensor_id
     * @param  array<int|string, array<string, mixed>>  $state  keyed by device_id
     * @param  string[]  $only  limit the plan to these findings (phantom, limits); empty means both
     * @return array<int, array<string, mixed>>
     */
    public static function plan(array $groups, array $sensors, array $state, array $only = []): array
    {
        $doPhantom = empty($only) || in_array(SensorBaseline::FINDING_PHANTOM, $only);
        $doLimits = empty($only) || in_array(SensorBaseline::FINDING_LIMITS, $only);
        $owned = self::ownedLimits($sensors, $state);
        $silenced = [];
        foreach ($state as $deviceState) {
            $silenced += array_fill_keys($deviceState['silenced'] ?? [], true);
        }

        $actions = [];

        // limits recorded as ours that an operator has since changed or reset: stop tracking them
        foreach ($state as $deviceId => $deviceState) {
            foreach (array_keys($deviceState['limits'] ?? []) as $sensorId) {
                if (isset($sensors[$sensorId]) && ! isset($owned[$sensorId])) {
                    $actions[] = self::action(self::FORGET, $sensors[$sensorId], 'limits were changed by hand since this job set them');
                }
            }
        }

        foreach ($groups as $group) {
            $phantom = in_array(SensorBaseline::FINDING_PHANTOM, $group['findings']);

            if ($doPhantom) {
                foreach ($group['sensor_ids'] as $sensorId) {
                    $sensor = $sensors[$sensorId];
                    if ($phantom && (int) $sensor['sensor_alert'] === 1) {
                        $actions[] = self::action(self::SILENCE, $sensor, "reads 0 on all {$group['devices']} devices of this hardware");
                    } elseif (! $phantom && $group['judged'] && isset($silenced[$sensorId]) && (int) $sensor['sensor_alert'] === 0) {
                        // a peer now reads something, so the sensor is real after all
                        $actions[] = self::action(self::UNSILENCE, $sensor, 'peers no longer all read 0');
                    }
                }
            }

            if ($doLimits && in_array(SensorBaseline::FINDING_LIMITS, $group['findings'])) {
                foreach ($group['tight'] as $sensorId => $tight) {
                    $sensor = $sensors[$sensorId];
                    $actions[] = self::action(self::LIMITS, $sensor, 'limit is inside the range healthy peers read', [
                        // only ever moved outwards, and only the side that is too tight
                        'limit_low' => $tight['low'] ? $group['suggest_low'] : self::number($sensor['sensor_limit_low'] ?? null),
                        'limit' => $tight['high'] ? $group['suggest_high'] : self::number($sensor['sensor_limit'] ?? null),
                    ]);
                }
            }
        }

        return $actions;
    }

    /**
     * Undo everything recorded in the state that has not been changed by hand since.
     *
     * @param  array<int|string, array<string, mixed>>  $sensors  every sensor named in the state that still exists, keyed by sensor_id
     * @param  array<int|string, array<string, mixed>>  $state  keyed by device_id
     * @return array<int, array<string, mixed>>
     */
    public static function revert(array $sensors, array $state): array
    {
        $owned = self::ownedLimits($sensors, $state);
        $actions = [];

        foreach ($state as $deviceState) {
            foreach ($deviceState['silenced'] ?? [] as $sensorId) {
                if (isset($sensors[$sensorId]) && (int) $sensors[$sensorId]['sensor_alert'] === 0) {
                    $actions[] = self::action(self::UNSILENCE, $sensors[$sensorId], 'revert');
                }
            }
            foreach ($deviceState['limits'] ?? [] as $sensorId => $record) {
                if (! isset($sensors[$sensorId])) {
                    continue;
                }
                if (isset($owned[$sensorId])) {
                    $actions[] = self::action(self::RESTORE, $sensors[$sensorId], 'revert', [
                        'limit_low' => self::number($record['old'][0] ?? null),
                        'limit' => self::number($record['old'][1] ?? null),
                        'custom' => $record['old'][2] ?? 'No',
                    ]);
                } else {
                    $actions[] = self::action(self::FORGET, $sensors[$sensorId], 'limits were changed by hand since this job set them');
                }
            }
        }

        return $actions;
    }

    /**
     * @param  array<string, mixed>  $sensor
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private static function action(string $action, array $sensor, string $reason, array $extra = []): array
    {
        return [
            'action' => $action,
            'sensor_id' => $sensor['sensor_id'],
            'device_id' => $sensor['device_id'],
            'device' => $sensor['device'] ?? $sensor['device_id'],
            'sensor_class' => $sensor['sensor_class'],
            'sensor_descr' => $sensor['sensor_descr'],
            'reason' => $reason,
            'old_limit_low' => self::number($sensor['sensor_limit_low'] ?? null),
            'old_limit' => self::number($sensor['sensor_limit'] ?? null),
            'old_custom' => $sensor['sensor_custom'] ?? 'No',
        ] + $extra;
    }

    private static function number(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    private static function same(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        return abs((float) $a - (float) $b) < 0.000001;
    }
}
