<?php

namespace LibreNMS\Tests\Unit\Util;

use LibreNMS\Tests\TestCase;
use LibreNMS\Util\SensorBaseline;
use LibreNMS\Util\SensorBaselinePlan;

final class SensorBaselinePlanTest extends TestCase
{
    public function testPhantomSensorsAreSilencedOnce(): void
    {
        $sensors = [];
        foreach (range(1, 3) as $device) {
            $sensors += $this->sensor($device, $device, 'fanspeed', 'Fan 3', 0, alert: $device == 3 ? 0 : 1);
        }

        $actions = $this->plan($sensors);

        $this->assertSame([SensorBaselinePlan::SILENCE, SensorBaselinePlan::SILENCE], array_column($actions, 'action'));
        $this->assertSame([1, 2], array_column($actions, 'sensor_id')); // the third is already quiet
    }

    public function testSilencedSensorComesBackWhenAPeerReadsSomething(): void
    {
        $sensors = [];
        foreach (range(1, 3) as $device) {
            $sensors += $this->sensor($device, $device, 'fanspeed', 'Fan 3', $device == 2 ? 4000 : 0, alert: 0);
        }
        // we silenced sensors 1 and 2; sensor 3 was turned off by an operator
        $state = [1 => ['silenced' => [1]], 2 => ['silenced' => [2]]];

        $actions = $this->plan($sensors, $state);

        $this->assertSame([SensorBaselinePlan::UNSILENCE, SensorBaselinePlan::UNSILENCE], array_column($actions, 'action'));
        $this->assertSame([1, 2], array_column($actions, 'sensor_id'));
    }

    public function testSilencedSensorStaysQuietWhenTheGroupIsTooSmallToJudge(): void
    {
        $sensors = $this->sensor(1, 1, 'fanspeed', 'Fan 3', 0, alert: 0) + $this->sensor(2, 2, 'fanspeed', 'Fan 3', 900, alert: 0);

        $this->assertSame([], $this->plan($sensors, [1 => ['silenced' => [1]], 2 => ['silenced' => [2]]]));
    }

    public function testLimitsOnlyMoveOutwardsAndOnlyOnTheTightSide(): void
    {
        $sensors = [];
        foreach ([40, 41, 42, 43, 44] as $index => $value) {
            $sensors += $this->sensor($index + 1, $index + 1, 'temperature', 'Outlet', $value, limit: 43.5, limitLow: 5);
        }
        $sensors[2]['sensor_custom'] = 'Yes'; // set by an operator

        $actions = $this->plan($sensors);

        $this->assertSame([1, 3, 4, 5], array_column($actions, 'sensor_id'));
        foreach ($actions as $action) {
            $this->assertSame(SensorBaselinePlan::LIMITS, $action['action']);
            $this->assertGreaterThan(44, $action['limit']);
            $this->assertEquals(5, $action['limit_low']); // the low side was fine and is left alone
        }
    }

    public function testOwnLimitsAreStillOursButHandEditsAreNot(): void
    {
        $sensors = [];
        foreach ([40, 41, 42, 43, 47] as $index => $value) {
            // already widened by this job to 46.2 and locked as custom
            $sensors += $this->sensor($index + 1, $index + 1, 'temperature', 'Outlet', $value, limit: 46.2, limitLow: 5, custom: 'Yes');
        }
        $state = [];
        foreach (range(1, 5) as $id) {
            $state[$id] = ['limits' => [$id => ['old' => [5, 43.5, 'No'], 'new' => [5, 46.2]]]];
        }
        $sensors[4]['sensor_limit'] = 60; // an operator changed this one afterwards

        $this->assertSame([1, 2, 3, 5], array_keys(SensorBaselinePlan::ownedLimits($sensors, $state)));

        $actions = $this->plan($sensors, $state);
        $byId = array_column($actions, 'action', 'sensor_id');

        $this->assertSame(SensorBaselinePlan::FORGET, $byId[4]);
        foreach ([1, 2, 3, 5] as $id) {
            $this->assertSame(SensorBaselinePlan::LIMITS, $byId[$id]); // the range grew to 47, so ours are widened again
        }
    }

    public function testOnlyRestrictsThePlan(): void
    {
        $sensors = [];
        foreach ([40, 41, 42, 43, 44] as $index => $value) {
            $sensors += $this->sensor($index + 1, $index + 1, 'temperature', 'Outlet', $value, limit: 43.5);
            $sensors += $this->sensor($index + 11, $index + 1, 'fanspeed', 'Fan 3', 0);
        }

        $this->assertSame([SensorBaselinePlan::SILENCE], array_values(array_unique(array_column($this->plan($sensors, [], ['phantom']), 'action'))));
        $this->assertSame([SensorBaselinePlan::LIMITS], array_values(array_unique(array_column($this->plan($sensors, [], ['limits']), 'action'))));
        $this->assertCount(10, $this->plan($sensors));
    }

    public function testRevertUndoesOnlyWhatIsStillOurs(): void
    {
        $sensors = $this->sensor(1, 1, 'fanspeed', 'Fan 3', 0, alert: 0)
            + $this->sensor(2, 1, 'fanspeed', 'Fan 4', 0, alert: 1) // an operator turned this one back on
            + $this->sensor(3, 1, 'temperature', 'Outlet', 41, limit: 46.2, limitLow: 5, custom: 'Yes')
            + $this->sensor(4, 1, 'temperature', 'Inlet', 30, limit: 70, limitLow: 5, custom: 'Yes'); // edited by hand since
        $state = [1 => [
            'silenced' => [1, 2, 99], // 99 no longer exists
            'limits' => [
                3 => ['old' => [5, 43.5, 'No'], 'new' => [5, 46.2]],
                4 => ['old' => [5, 33, 'No'], 'new' => [5, 36]],
            ],
        ]];

        $actions = array_column(SensorBaselinePlan::revert($sensors, $state), null, 'sensor_id');

        $this->assertSame([1, 3, 4], array_keys($actions));
        $this->assertSame(SensorBaselinePlan::UNSILENCE, $actions[1]['action']);
        $this->assertSame(SensorBaselinePlan::RESTORE, $actions[3]['action']);
        $this->assertEquals(43.5, $actions[3]['limit']);
        $this->assertSame('No', $actions[3]['custom']);
        $this->assertSame(SensorBaselinePlan::FORGET, $actions[4]['action']);
    }

    private function plan(array $sensors, array $state = [], array $only = []): array
    {
        $analysed = $sensors;
        foreach (array_keys(SensorBaselinePlan::ownedLimits($sensors, $state)) as $id) {
            $analysed[$id]['sensor_custom'] = 'No';
        }

        return SensorBaselinePlan::plan((new SensorBaseline)->analyse($analysed), $sensors, $state, $only);
    }

    private function sensor(int $id, int $device, string $class, string $descr, float $value, ?float $limit = null, ?float $limitLow = null, string $custom = 'No', int $alert = 1): array
    {
        return [$id => [
            'sensor_id' => $id,
            'device_id' => $device,
            'device' => "sw$device",
            'os' => 'iosxe',
            'hardware' => 'C9300-48P',
            'sensor_class' => $class,
            'sensor_descr' => $descr,
            'sensor_current' => $value,
            'sensor_limit' => $limit,
            'sensor_limit_low' => $limitLow,
            'sensor_custom' => $custom,
            'sensor_alert' => $alert,
        ]];
    }
}
