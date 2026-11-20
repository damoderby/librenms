<?php

namespace App\Console\Commands;

use App\Console\LnmsCommand;
use App\Models\DeviceAttrib;
use App\Models\Eventlog;
use App\Models\Sensor;
use Illuminate\Support\Facades\DB;
use LibreNMS\Enum\Severity;
use LibreNMS\Util\SensorBaseline;
use LibreNMS\Util\SensorBaselinePlan;
use Symfony\Component\Console\Input\InputOption;

class MaintenanceSensorBaseline extends LnmsCommand
{
    /** Device attribute holding the record of what this command changed on that device */
    public const STATE_ATTRIB = 'sensor_baseline';

    protected $name = 'maintenance:sensor-baseline';

    public function __construct()
    {
        parent::__construct();
        $this->addOption('apply', null, InputOption::VALUE_NONE);
        $this->addOption('revert', null, InputOption::VALUE_NONE);
        $this->addOption('only', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, __('commands.maintenance:sensor-baseline.options.only', ['types' => '[phantom, limits]']));
        $this->addOption('min-devices', 'm', InputOption::VALUE_REQUIRED, default: 3);
        $this->addOption('threshold', 't', InputOption::VALUE_REQUIRED, default: 4);
        $this->addOption('class', 'c', InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED);
        $this->addOption('os', null, InputOption::VALUE_REQUIRED);
        $this->addOption('hardware', null, InputOption::VALUE_REQUIRED);
        $this->addOption('merge-numbers', null, InputOption::VALUE_NONE);
    }

    public function handle(): int
    {
        $only = $this->option('only');
        if (array_diff($only, [SensorBaseline::FINDING_PHANTOM, SensorBaseline::FINDING_LIMITS])) {
            $this->error(__('commands.maintenance:sensor-baseline.invalid_only'));

            return 1;
        }

        $state = $this->loadState();

        if ($this->option('revert')) {
            $ids = [];
            foreach ($state as $deviceState) {
                $ids = array_merge($ids, $deviceState['silenced'] ?? [], array_keys($deviceState['limits'] ?? []));
            }
            $existing = $this->sensors($ids);
            $actions = SensorBaselinePlan::revert($existing, $state);

            // sensors that no longer exist have nothing to undo, stop tracking them
            foreach ($state as $deviceId => $deviceState) {
                $state[$deviceId]['silenced'] = array_values(array_filter($deviceState['silenced'] ?? [], fn ($id) => isset($existing[$id])));
                $state[$deviceId]['limits'] = array_filter($deviceState['limits'] ?? [], fn ($id) => isset($existing[$id]), ARRAY_FILTER_USE_KEY);
            }
        } else {
            $sensors = $this->sensors();

            // limits this command set are locked as custom so discovery keeps them; for the analysis they are still ours
            $analysed = $sensors;
            foreach (array_keys(SensorBaselinePlan::ownedLimits($sensors, $state)) as $sensorId) {
                $analysed[$sensorId]['sensor_custom'] = 'No';
            }

            $baseline = new SensorBaseline(
                minDevices: max(2, (int) $this->option('min-devices')),
                outlierThreshold: (float) $this->option('threshold'),
                mergeNumbers: (bool) $this->option('merge-numbers'),
            );
            $actions = SensorBaselinePlan::plan($baseline->analyse($analysed), $sensors, $state, $only);
        }

        $this->printActions($actions);

        if (empty($actions)) {
            if ($this->option('revert') && $this->option('apply')) {
                $this->saveState($state);
            }
            $this->info(__('commands.maintenance:sensor-baseline.nothing'));

            return 0;
        }

        if (! $this->option('apply')) {
            $this->warn(__('commands.maintenance:sensor-baseline.dry_run'));

            return 0;
        }

        $changed = 0;
        foreach ($actions as $action) {
            $changed += (int) $this->applyAction($action, $state);
        }
        $this->saveState($state);
        $this->info(__('commands.maintenance:sensor-baseline.applied', ['count' => $changed]));

        return 0;
    }

    /**
     * Live gauge sensors on devices that are up, keyed by sensor_id.
     * With $ids, exactly those sensors whatever the state of their device (used to revert).
     *
     * @param  array<int|string>|null  $ids
     * @return array<int, array<string, mixed>>
     */
    private function sensors(?array $ids = null): array
    {
        $query = DB::table('sensors')
            ->join('devices', 'devices.device_id', '=', 'sensors.device_id')
            ->where('sensors.sensor_deleted', 0)
            ->orderBy('sensors.sensor_id')
            ->select([
                'sensors.sensor_id',
                'sensors.device_id',
                'sensors.sensor_class',
                'sensors.sensor_descr',
                'sensors.sensor_current',
                'sensors.sensor_limit',
                'sensors.sensor_limit_low',
                'sensors.sensor_custom',
                'sensors.sensor_alert',
                'devices.os',
                'devices.hardware',
                DB::raw('COALESCE(NULLIF(devices.display, \'\'), NULLIF(devices.sysName, \'\'), devices.hostname) AS device'),
            ]);

        if ($ids !== null) {
            $query->whereIn('sensors.sensor_id', $ids);
        } else {
            $query->where('sensors.sensor_class', '!=', 'state')
                ->where('sensors.rrd_type', 'GAUGE')
                ->whereNotNull('sensors.sensor_current')
                ->where('devices.disabled', 0)
                ->where('devices.status', 1)
                ->when($this->option('class'), fn ($query, $classes) => $query->whereIn('sensors.sensor_class', $classes))
                ->when($this->option('os'), fn ($query, $os) => $query->where('devices.os', $os))
                ->when($this->option('hardware'), fn ($query, $hardware) => $query->where('devices.hardware', 'like', $hardware));
        }

        $sensors = [];
        foreach ($query->cursor() as $row) {
            $sensors[$row->sensor_id] = (array) $row;
        }

        return $sensors;
    }

    /**
     * @return array<int, array<string, mixed>> keyed by device_id
     */
    private function loadState(): array
    {
        return DeviceAttrib::query()
            ->where('attrib_type', self::STATE_ATTRIB)
            ->pluck('attrib_value', 'device_id')
            ->map(fn ($value) => json_decode((string) $value, true) ?: [])
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $state
     */
    private function saveState(array $state): void
    {
        foreach ($state as $deviceId => $deviceState) {
            $deviceState['silenced'] = array_values(array_unique($deviceState['silenced'] ?? []));
            $deviceState['limits'] ??= [];

            $attrib = DeviceAttrib::query()->where('device_id', $deviceId)->where('attrib_type', self::STATE_ATTRIB);
            if (empty($deviceState['silenced']) && empty($deviceState['limits'])) {
                $attrib->delete();
            } elseif ($attrib->exists()) {
                $attrib->update(['attrib_value' => json_encode($deviceState)]);
            } else {
                DeviceAttrib::query()->insert(['device_id' => $deviceId, 'attrib_type' => self::STATE_ATTRIB, 'attrib_value' => json_encode($deviceState)]);
            }
        }
    }

    /**
     * Make one change, after checking the sensor is still as it was when the plan was made.
     *
     * @param  array<string, mixed>  $action
     * @param  array<int, array<string, mixed>>  $state
     */
    private function applyAction(array $action, array &$state): bool
    {
        $sensorId = $action['sensor_id'];
        $deviceId = $action['device_id'];
        $state[$deviceId] ??= [];
        $state[$deviceId]['silenced'] ??= [];
        $state[$deviceId]['limits'] ??= [];

        if ($action['action'] === SensorBaselinePlan::FORGET) {
            unset($state[$deviceId]['limits'][$sensorId]);

            return false;
        }

        $sensor = Sensor::find($sensorId);
        if ($sensor === null) {
            return false;
        }

        $name = "$sensor->sensor_class $sensor->sensor_descr";

        switch ($action['action']) {
            case SensorBaselinePlan::SILENCE:
                if ((int) $sensor->sensor_alert !== 1) {
                    return false;
                }
                $sensor->sensor_alert = 0;
                $sensor->save();
                $state[$deviceId]['silenced'][] = $sensorId;
                Eventlog::log("Sensor baseline: alerting turned off for $name ({$action['reason']})", $deviceId, 'sensor', Severity::Notice, $sensorId);

                return true;

            case SensorBaselinePlan::UNSILENCE:
                $state[$deviceId]['silenced'] = array_values(array_diff($state[$deviceId]['silenced'], [$sensorId]));
                if ((int) $sensor->sensor_alert !== 0) {
                    return false;
                }
                $sensor->sensor_alert = 1;
                $sensor->save();
                Eventlog::log("Sensor baseline: alerting turned back on for $name ({$action['reason']})", $deviceId, 'sensor', Severity::Notice, $sensorId);

                return true;

            case SensorBaselinePlan::LIMITS:
                // keep the very first limits we replaced, so a revert goes back to the original
                $old = $state[$deviceId]['limits'][$sensorId]['old'] ?? [$sensor->sensor_limit_low, $sensor->sensor_limit, $sensor->sensor_custom];
                $sensor->sensor_limit_low = $action['limit_low'];
                $sensor->sensor_limit = $action['limit'];
                $sensor->sensor_custom = 'Yes'; // discovery leaves custom limits alone
                $sensor->save();
                $state[$deviceId]['limits'][$sensorId] = ['old' => $old, 'new' => [$action['limit_low'], $action['limit']]];
                Eventlog::log("Sensor baseline: limits for $name set to " . $this->range($action['limit_low'], $action['limit']) . ' (were ' . $this->range($old[0], $old[1]) . ')', $deviceId, 'sensor', Severity::Notice, $sensorId);

                return true;

            case SensorBaselinePlan::RESTORE:
                $sensor->sensor_limit_low = $action['limit_low'];
                $sensor->sensor_limit = $action['limit'];
                $sensor->sensor_custom = $action['custom'] === 'Yes' ? 'Yes' : 'No';
                $sensor->save();
                unset($state[$deviceId]['limits'][$sensorId]);
                Eventlog::log("Sensor baseline: limits for $name put back to " . $this->range($action['limit_low'], $action['limit']), $deviceId, 'sensor', Severity::Notice, $sensorId);

                return true;
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $actions
     */
    private function printActions(array $actions): void
    {
        $rows = array_map(fn ($action) => [
            $action['device'],
            $action['sensor_class'],
            $action['sensor_descr'],
            $action['action'],
            in_array($action['action'], [SensorBaselinePlan::LIMITS, SensorBaselinePlan::RESTORE])
                ? $this->range($action['old_limit_low'], $action['old_limit']) . ' -> ' . $this->range($action['limit_low'], $action['limit'])
                : '',
            $action['reason'],
        ], $actions);

        if ($rows) {
            $this->table(['device', 'class', 'sensor', 'action', 'limits', 'reason'], $rows);
        }
    }

    private function range(mixed $low, mixed $high): string
    {
        return ($low === null ? '-' : round((float) $low, 2)) . ' to ' . ($high === null ? '-' : round((float) $high, 2));
    }
}
