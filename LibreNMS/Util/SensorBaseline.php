<?php

/**
 * SensorBaseline.php
 *
 * Compare sensors against their peers (same os, hardware, class and description)
 * to find sensors that do not really exist, limits that sit inside the normal
 * range, and devices that read differently from the rest of the fleet.
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

class SensorBaseline
{
    public const FINDING_PHANTOM = 'phantom';
    public const FINDING_LIMITS = 'limits';
    public const FINDING_OUTLIERS = 'outliers';

    /** Scale factor that makes the median absolute deviation comparable to a standard deviation */
    private const MAD_SCALE = 1.4826;

    public function __construct(
        private readonly int $minDevices = 3,
        private readonly int $minOutlierSamples = 5,
        private readonly float $outlierThreshold = 4.0,
        private readonly bool $mergeNumbers = false,
    ) {
    }

    /**
     * Make descriptions comparable between devices by replacing the parts that are
     * specific to one port, so "Te1/0/3 Rx Power" and "Te2/0/1 Rx Power" group together.
     * With $mergeNumbers every remaining number is replaced as well ("Fan 1" and "Fan 2").
     */
    public static function normaliseDescr(?string $descr, bool $mergeNumbers = false): string
    {
        $descr = trim((string) $descr);

        // interface style names: Te1/0/3, Ethernet1/1.100, xe-0/0/1, 1/1/4
        $descr = preg_replace('~\b[A-Za-z-]*\d+(?:/\d+)+(?:\.\d+)?\b~', '<port>', $descr);

        if ($mergeNumbers) {
            $descr = preg_replace('~\d+(?:\.\d+)?~', '#', $descr);
        }

        return preg_replace('~\s+~', ' ', $descr);
    }

    /**
     * @param  iterable<array<string, mixed>|object>  $sensors  rows with: device_id, os, hardware, sensor_id, sensor_class,
     *                                                           sensor_descr, sensor_current, sensor_limit, sensor_limit_low, sensor_custom
     * @return array<int, array<string, mixed>> one entry per group, largest groups first
     */
    public function analyse(iterable $sensors): array
    {
        $groups = [];
        foreach ($sensors as $sensor) {
            $sensor = (array) $sensor;
            if ($sensor['sensor_current'] === null) {
                continue;
            }

            $descr = self::normaliseDescr($sensor['sensor_descr'] ?? '', $this->mergeNumbers);
            $key = implode("\0", [$sensor['os'], $sensor['hardware'], $sensor['sensor_class'], $descr]);
            $groups[$key] ??= [
                'os' => (string) $sensor['os'],
                'hardware' => (string) $sensor['hardware'],
                'sensor_class' => (string) $sensor['sensor_class'],
                'sensor_descr' => $descr,
                'rows' => [],
            ];
            $groups[$key]['rows'][] = $sensor;
        }

        $results = array_map($this->analyseGroup(...), array_values($groups));
        usort($results, fn ($a, $b) => [$b['devices'], $a['hardware'], $a['sensor_class'], $a['sensor_descr']]
            <=> [$a['devices'], $b['hardware'], $b['sensor_class'], $b['sensor_descr']]);

        return $results;
    }

    /**
     * @param  array<string, mixed>  $group
     * @return array<string, mixed>
     */
    private function analyseGroup(array $group): array
    {
        $rows = $group['rows'];
        unset($group['rows']);

        $values = array_map(fn ($row) => (float) $row['sensor_current'], $rows);
        $devices = count(array_unique(array_column($rows, 'device_id')));
        $median = self::median($values);
        $enough = $devices >= $this->minDevices;

        $result = $group + [
            'devices' => $devices,
            'sensors' => count($rows),
            'judged' => $enough,
            'sensor_ids' => array_column($rows, 'sensor_id'),
            'min' => min($values),
            'median' => $median,
            'max' => max($values),
            'findings' => [],
            'tight_limits' => 0,
            'over_limit' => 0,
            'suggest_low' => null,
            'suggest_high' => null,
            'outliers' => [],
            'tight' => [],
        ];

        // every peer reads zero: the sensor is reported by the device but is not real
        if ($enough && $result['min'] == 0.0 && $result['max'] == 0.0) {
            $result['findings'][] = self::FINDING_PHANTOM;

            return $result;
        }

        // peers that sit far away from the rest
        $inliers = $values;
        if ($enough && count($values) >= $this->minOutlierSamples) {
            $deviations = array_map(fn ($value) => abs($value - $median), $values);
            $spread = max(self::MAD_SCALE * self::median($deviations), abs($median) * 0.02, 0.1);
            $inliers = [];
            foreach ($rows as $index => $row) {
                $score = ($values[$index] - $median) / $spread;
                if (abs($score) > $this->outlierThreshold) {
                    $result['outliers'][] = [
                        'device_id' => $row['device_id'],
                        'device' => $row['device'] ?? $row['device_id'],
                        'sensor_id' => $row['sensor_id'],
                        'sensor_descr' => $row['sensor_descr'],
                        'value' => $values[$index],
                        'score' => round($score, 1),
                    ];
                } else {
                    $inliers[] = $values[$index];
                }
            }
            if ($result['outliers']) {
                $result['findings'][] = self::FINDING_OUTLIERS;
            }
        }

        // limits that sit inside the range the healthy peers actually read
        if ($enough && $inliers) {
            $low = min($inliers);
            $high = max($inliers);
            foreach ($rows as $index => $row) {
                $limit = $row['sensor_limit'] ?? null;
                $limitLow = $row['sensor_limit_low'] ?? null;
                if (($row['sensor_custom'] ?? 'No') === 'Yes') {
                    continue; // set by hand, leave it to the operator
                }
                $tightHigh = $limit !== null && (float) $limit < $high;
                $tightLow = $limitLow !== null && (float) $limitLow > $low;
                if ($tightHigh || $tightLow) {
                    $result['tight_limits']++;
                    $result['tight'][$row['sensor_id']] = ['high' => $tightHigh, 'low' => $tightLow];
                }
                if (($limit !== null && $values[$index] > (float) $limit) || ($limitLow !== null && $values[$index] < (float) $limitLow)) {
                    $result['over_limit']++;
                }
            }
            if ($result['tight_limits'] > 0) {
                $margin = max(($high - $low) * 0.1, max(abs($low), abs($high)) * 0.05);
                $result['findings'][] = self::FINDING_LIMITS;
                $result['suggest_low'] = round($low - $margin, 2);
                $result['suggest_high'] = round($high + $margin, 2);
            }
        }

        return $result;
    }

    /**
     * @param  float[]  $values
     */
    private static function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }
}
