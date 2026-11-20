<?php

namespace App\Console\Commands;

use App\Console\LnmsCommand;
use Illuminate\Support\Facades\DB;
use LibreNMS\Util\SensorBaseline;
use Symfony\Component\Console\Input\InputOption;

class ReportSensorBaseline extends LnmsCommand
{
    protected $name = 'report:sensor-baseline';

    public function __construct()
    {
        parent::__construct();
        $this->addOption('min-devices', 'm', InputOption::VALUE_REQUIRED, default: 3);
        $this->addOption('threshold', 't', InputOption::VALUE_REQUIRED, default: 4);
        $this->addOption('class', 'c', InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED);
        $this->addOption('os', null, InputOption::VALUE_REQUIRED);
        $this->addOption('hardware', null, InputOption::VALUE_REQUIRED);
        $this->addOption('show', 's', InputOption::VALUE_REQUIRED, __('commands.report:sensor-baseline.options.show', ['types' => '[findings, phantom, limits, outliers, all]']), 'findings');
        $this->addOption('output', 'o', InputOption::VALUE_REQUIRED, __('commands.report:sensor-baseline.options.output', ['types' => '[table, csv, json]']), 'table');
        $this->addOption('merge-numbers', null, InputOption::VALUE_NONE);
        $this->addOption('include-down', null, InputOption::VALUE_NONE);
    }

    public function handle(): int
    {
        $show = $this->option('show');
        if (! in_array($show, ['findings', 'phantom', 'limits', 'outliers', 'all'])) {
            $this->error(__('commands.report:sensor-baseline.invalid_show', ['show' => $show]));

            return 1;
        }

        $baseline = new SensorBaseline(
            minDevices: max(2, (int) $this->option('min-devices')),
            outlierThreshold: (float) $this->option('threshold'),
            mergeNumbers: (bool) $this->option('merge-numbers'),
        );

        $groups = array_values(array_filter($baseline->analyse($this->sensors()), fn ($group) => match ($show) {
            'all' => true,
            'findings' => ! empty($group['findings']),
            default => in_array($show, $group['findings']),
        }));

        if ($this->option('output') == 'json') {
            $this->line(json_encode($groups, JSON_PRETTY_PRINT));

            return 0;
        }

        $headers = ['os', 'hardware', 'class', 'sensor', 'devices', 'sensors', 'min', 'median', 'max', 'findings', 'detail'];
        $rows = array_map(fn ($group) => [
            $group['os'],
            $group['hardware'],
            $group['sensor_class'],
            $group['sensor_descr'],
            $group['devices'],
            $group['sensors'],
            round($group['min'], 2),
            round($group['median'], 2),
            round($group['max'], 2),
            implode(',', $group['findings']),
            $this->detail($group),
        ], $groups);

        if ($this->option('output') == 'csv') {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers, escape: '\\');
            foreach ($rows as $row) {
                fputcsv($out, $row, escape: '\\');
            }
            fclose($out);

            return 0;
        }

        $this->table($headers, $rows);
        $this->printOutliers($groups);

        return 0;
    }

    /**
     * Current reading of every live gauge sensor with the fields needed to group it with its peers.
     * State sensors are left out (0 is a valid state) and so are counters (the value is a running total).
     *
     * @return iterable<object>
     */
    private function sensors(): iterable
    {
        return DB::table('sensors')
            ->join('devices', 'devices.device_id', '=', 'sensors.device_id')
            ->where('sensors.sensor_deleted', 0)
            ->where('sensors.sensor_class', '!=', 'state')
            ->where('sensors.rrd_type', 'GAUGE')
            ->whereNotNull('sensors.sensor_current')
            ->where('devices.disabled', 0)
            ->when(! $this->option('include-down'), fn ($query) => $query->where('devices.status', 1))
            ->when($this->option('class'), fn ($query, $classes) => $query->whereIn('sensors.sensor_class', $classes))
            ->when($this->option('os'), fn ($query, $os) => $query->where('devices.os', $os))
            ->when($this->option('hardware'), fn ($query, $hardware) => $query->where('devices.hardware', 'like', $hardware))
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
                'devices.os',
                'devices.hardware',
                DB::raw('COALESCE(NULLIF(devices.display, \'\'), NULLIF(devices.sysName, \'\'), devices.hostname) AS device'),
            ])
            ->cursor();
    }

    /**
     * @param  array<string, mixed>  $group
     */
    private function detail(array $group): string
    {
        $detail = [];
        if (in_array(SensorBaseline::FINDING_PHANTOM, $group['findings'])) {
            $detail[] = __('commands.report:sensor-baseline.detail.phantom', ['devices' => $group['devices']]);
        }
        if (in_array(SensorBaseline::FINDING_LIMITS, $group['findings'])) {
            $detail[] = __('commands.report:sensor-baseline.detail.limits', [
                'tight' => $group['tight_limits'],
                'over' => $group['over_limit'],
                'low' => $group['suggest_low'],
                'high' => $group['suggest_high'],
            ]);
        }
        if (in_array(SensorBaseline::FINDING_OUTLIERS, $group['findings'])) {
            $detail[] = __('commands.report:sensor-baseline.detail.outliers', ['count' => count($group['outliers'])]);
        }

        return implode('; ', $detail);
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     */
    private function printOutliers(array $groups): void
    {
        $rows = [];
        foreach ($groups as $group) {
            foreach ($group['outliers'] as $outlier) {
                $rows[] = [
                    $outlier['device'],
                    $group['hardware'],
                    $group['sensor_class'],
                    $outlier['sensor_descr'],
                    round($outlier['value'], 2),
                    round($group['median'], 2),
                    $outlier['score'],
                ];
            }
        }

        if ($rows) {
            $this->info(__('commands.report:sensor-baseline.outlier_heading'));
            $this->table(['device', 'hardware', 'class', 'sensor', 'value', 'peer median', 'score'], $rows);
        }
    }
}
