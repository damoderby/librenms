<?php

namespace LibreNMS\Tests\Unit\Util;

use LibreNMS\Tests\TestCase;
use LibreNMS\Util\SensorBaseline;

final class SensorBaselineTest extends TestCase
{
    public function testNormaliseDescr(): void
    {
        $this->assertSame('<port> Rx Power', SensorBaseline::normaliseDescr('Te1/0/3 Rx Power'));
        $this->assertSame('<port> Rx Power', SensorBaseline::normaliseDescr('TenGigabitEthernet2/1/14  Rx Power'));
        $this->assertSame('<port> Bias', SensorBaseline::normaliseDescr('xe-0/0/1.100 Bias'));
        $this->assertSame('Fan 1', SensorBaseline::normaliseDescr('Fan 1'));
        $this->assertSame('Fan #', SensorBaseline::normaliseDescr('Fan 1', true));
        $this->assertSame('', SensorBaseline::normaliseDescr(null));
    }

    public function testPhantomNeedsEveryPeerAtZero(): void
    {
        $sensors = [];
        foreach (range(1, 4) as $device) {
            $sensors[] = $this->sensor($device, 'fanspeed', 'Fan 3', 0);
            $sensors[] = $this->sensor($device, 'fanspeed', 'Fan 1', $device == 4 ? 0 : 5400);
        }

        $groups = $this->byDescr((new SensorBaseline)->analyse($sensors));

        $this->assertSame([SensorBaseline::FINDING_PHANTOM], $groups['Fan 3']['findings']);
        $this->assertNotContains(SensorBaseline::FINDING_PHANTOM, $groups['Fan 1']['findings']);
    }

    public function testSmallGroupsAreNotJudged(): void
    {
        $sensors = [$this->sensor(1, 'fanspeed', 'Fan 3', 0), $this->sensor(2, 'fanspeed', 'Fan 3', 0)];

        $groups = (new SensorBaseline(minDevices: 3))->analyse($sensors);

        $this->assertSame([], $groups[0]['findings']);
    }

    public function testHardwareIsNotMixed(): void
    {
        $sensors = [];
        foreach (range(1, 3) as $device) {
            $sensors[] = $this->sensor($device, 'temperature', 'Inlet', 0, hardware: 'C9300-48P');
            $sensors[] = $this->sensor($device + 10, 'temperature', 'Inlet', 31, hardware: 'C9500-24Y4C');
        }

        $groups = (new SensorBaseline)->analyse($sensors);

        $this->assertCount(2, $groups);
        $findings = array_column($groups, 'findings', 'hardware');
        $this->assertSame([SensorBaseline::FINDING_PHANTOM], $findings['C9300-48P']);
        $this->assertSame([], $findings['C9500-24Y4C']);
    }

    public function testOutlierAndTightLimits(): void
    {
        $sensors = [];
        foreach ([40, 41, 42, 43, 44, 45, 71] as $device => $value) {
            // limit guessed too low on every device, device 3 has been set by hand
            $sensors[] = $this->sensor($device, 'temperature', 'Outlet', $value, limit: 43.5, custom: $device == 3 ? 'Yes' : 'No');
        }

        $group = (new SensorBaseline)->analyse($sensors)[0];

        $this->assertSame([SensorBaseline::FINDING_OUTLIERS, SensorBaseline::FINDING_LIMITS], $group['findings']);
        $this->assertCount(1, $group['outliers']);
        $this->assertEquals(71, $group['outliers'][0]['value']);
        $this->assertSame(6, $group['tight_limits']); // seven sensors, one set by hand
        $this->assertSame(3, $group['over_limit']); // 44, 45 and 71
        $this->assertGreaterThan(45, $group['suggest_high']); // the outlier does not stretch the range
        $this->assertLessThan(60, $group['suggest_high']);
        $this->assertLessThan(40, $group['suggest_low']);
    }

    public function testHealthyGroupHasNoFindings(): void
    {
        $sensors = [];
        foreach ([40, 41, 42, 43, 44] as $device => $value) {
            $sensors[] = $this->sensor($device, 'temperature', 'Outlet', $value, limit: 60, limitLow: 5);
        }

        $this->assertSame([], (new SensorBaseline)->analyse($sensors)[0]['findings']);
    }

    private function sensor(int $device, string $class, string $descr, float $value, ?float $limit = null, ?float $limitLow = null, string $custom = 'No', string $hardware = 'C9300-48P'): array
    {
        static $id = 0;

        return [
            'sensor_id' => ++$id,
            'device_id' => $device,
            'device' => "sw$device",
            'os' => 'iosxe',
            'hardware' => $hardware,
            'sensor_class' => $class,
            'sensor_descr' => $descr,
            'sensor_current' => $value,
            'sensor_limit' => $limit,
            'sensor_limit_low' => $limitLow,
            'sensor_custom' => $custom,
        ];
    }

    private function byDescr(array $groups): array
    {
        return array_column($groups, null, 'sensor_descr');
    }
}
