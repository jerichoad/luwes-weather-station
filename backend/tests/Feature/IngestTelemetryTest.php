<?php

namespace Tests\Feature;

use App\Domain\Devices\DeviceStatus;
use App\Models\Device;
use App\Models\Location;
use App\Models\Sensor;
use App\Models\SensorInstallation;
use App\Services\DeviceService;
use Database\Seeders\SensorTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IngestTelemetryTest extends TestCase
{
    use RefreshDatabase;

    private Device $device;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SensorTypeSeeder::class);

        $loc = Location::create(['code' => 'TST', 'name' => 'Test', 'latitude' => -6.0, 'longitude' => 106.0]);
        $res = (new DeviceService)->register(['code' => 'WS-TST-001', 'name' => 'Test', 'location_id' => $loc->id]);
        $this->device = $res['device'];
        $this->key = $res['api_key'];

        foreach ($this->device->channels as $ch) {
            $sensor = Sensor::create(['serial_number' => 'S-'.$ch->channel_key, 'sensor_type_id' => $ch->sensor_type_id]);
            SensorInstallation::create(['sensor_id' => $sensor->id, 'device_channel_id' => $ch->id, 'installed_at' => '2020-01-01 00:00:00+00']);
        }
    }

    private function payload(array $override = []): array
    {
        return array_merge([
            'device_id' => 'WS-TST-001', 'fw' => '1.4.2', 'ts' => 1757308800, 'seq' => 10432,
            'battery_v' => 3.92, 'rssi' => -71,
            'readings' => [
                ['s' => 'temp_air', 'v' => 27.4],
                ['s' => 'humidity', 'v' => 81.2],
            ],
        ], $override);
    }

    private function ingest(array $body, ?string $key = null)
    {
        return $this->withHeader('Authorization', 'Bearer '.($key ?? $this->key))->postJson('/api/v1/ingest/telemetry', $body);
    }

    public function test_accepted_lalu_duplicate(): void
    {
        $this->ingest($this->payload())->assertStatus(201)->assertJsonPath('data.status', 'accepted')->assertJsonPath('data.readings.stored', 2);
        $this->ingest($this->payload())->assertStatus(200)->assertJsonPath('data.status', 'duplicate');
        $this->ingest($this->payload())->assertStatus(200);

        $this->assertSame(1, DB::table('device_packets')->count());
        $this->assertSame(2, DB::table('sensor_readings')->count());
    }

    public function test_provisioned_menjadi_active(): void
    {
        $this->ingest($this->payload())->assertStatus(201);
        $this->assertSame(DeviceStatus::Active, $this->device->fresh()->status);
    }

    public function test_device_tidak_dikenal_401(): void
    {
        $this->ingest($this->payload(['device_id' => 'WS-XXX-999']))
            ->assertStatus(401)->assertJsonPath('error.code', 'INVALID_DEVICE_CREDENTIALS');
    }

    public function test_key_salah_401(): void
    {
        $this->ingest($this->payload(), 'wsk_wrong')->assertStatus(401);
    }

    public function test_decommissioned_403(): void
    {
        (new DeviceService)->transition($this->device, DeviceStatus::Decommissioned, 'test');
        $this->ingest($this->payload())->assertStatus(403);
    }

    public function test_validasi_per_field_422(): void
    {
        $this->ingest($this->payload(['ts' => 1000, 'readings' => [['s' => 'temp_air', 'v' => 'abc']]]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonFragment(['field' => 'ts', 'code' => 'TIMESTAMP_INVALID'])
            ->assertJsonFragment(['field' => 'readings.0.v', 'code' => 'NOT_NUMERIC']);
    }

    public function test_sensor_error_dan_out_of_range_diflag(): void
    {
        $this->ingest($this->payload(['readings' => [
            ['s' => 'temp_air', 'v' => -999],
            ['s' => 'humidity', 'v' => 150],
        ]]))->assertStatus(201)->assertJsonPath('data.readings.flagged', 2);

        $temp = DB::table('sensor_readings')->where('raw_value', -999)->first();
        $this->assertNull($temp->value);
        $this->assertSame(2, (int) $temp->quality_flags);

        $hum = DB::table('sensor_readings')->where('raw_value', 150)->first();
        $this->assertSame(1, (int) $hum->quality_flags);
    }

    public function test_clock_future(): void
    {
        $this->ingest($this->payload(['ts' => time() + 7200]))
            ->assertStatus(201)->assertJsonFragment(['code' => 'CLOCK_FUTURE']);
        $this->assertSame(4, (int) DB::table('sensor_readings')->value('quality_flags'));
    }

    public function test_sensor_hilang_tidak_bikin_row(): void
    {
        $this->ingest($this->payload(['readings' => [['s' => 'temp_air', 'v' => 25]]]))->assertStatus(201);
        $this->assertSame(1, DB::table('sensor_readings')->count());
    }

    public function test_malformed_json_400(): void
    {
        $this->call('POST', '/api/v1/ingest/telemetry', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->key, 'CONTENT_TYPE' => 'application/json',
        ], '{bad json')->assertStatus(400)->assertJsonPath('error.code', 'MALFORMED_JSON');
    }

    public function test_batch_207_dan_batch_terlalu_besar(): void
    {
        $this->ingest($this->payload(['ts' => 1757308800]));
        $this->ingest($this->payload(['ts' => 1757308860]));

        $batch = [];
        for ($i = 0; $i < 10; $i++) {
            $batch[] = ['ts' => 1757308800 + $i * 60, 'seq' => $i, 'readings' => [['s' => 'temp_air', 'v' => 25 + $i]]];
        }

        $this->withHeader('Authorization', 'Bearer '.$this->key)
            ->postJson('/api/v1/ingest/telemetry/batch', ['device_id' => 'WS-TST-001', 'fw' => '1.4.2', 'batch' => $batch])
            ->assertStatus(207)
            ->assertJsonPath('data.summary.accepted', 8)
            ->assertJsonPath('data.summary.duplicate', 2);

        $big = array_fill(0, 501, ['ts' => 1757308800, 'seq' => 0, 'readings' => []]);
        $this->withHeader('Authorization', 'Bearer '.$this->key)
            ->postJson('/api/v1/ingest/telemetry/batch', ['device_id' => 'WS-TST-001', 'fw' => '1.4.2', 'batch' => $big])
            ->assertStatus(422)->assertJsonPath('error.code', 'BATCH_TOO_LARGE');
    }

    public function test_rain_counter_reset_tidak_minus(): void
    {
        $base = 1757308800;
        $this->ingest($this->payload(['ts' => $base, 'seq' => 10432, 'readings' => [['s' => 'rain_counter', 'v' => 1043]]]));
        $this->ingest($this->payload(['ts' => $base + 60, 'seq' => 0, 'readings' => [['s' => 'rain_counter', 'v' => 5]]]));

        $this->artisan('aggregates:refresh')->assertSuccessful();

        $sum = (float) DB::table('reading_aggregates')->where('bucket_interval', '1h')->sum('sum_value');
        $this->assertEqualsWithDelta(1.0, $sum, 0.001);
    }

    public function test_pindah_sensor_data_lama_tetap(): void
    {
        $this->ingest($this->payload(['ts' => 1757308800]))->assertStatus(201);

        $sensor = Sensor::where('serial_number', 'S-temp_air')->first();
        $loc = Location::first();
        $other = (new DeviceService)->register(['code' => 'WS-TST-002', 'name' => 'B', 'location_id' => $loc->id])['device'];

        $this->deleteJson("/api/v1/devices/{$this->device->id}/sensors/{$sensor->id}", ['reason' => 'pindah'])->assertOk();
        $this->postJson("/api/v1/devices/{$other->id}/sensors", ['sensor_id' => $sensor->id])->assertStatus(201);
        $this->postJson("/api/v1/devices/{$other->id}/sensors", ['sensor_id' => Sensor::where('serial_number', 'S-humidity')->value('id'), 'channel_key' => 'temp_air'])
            ->assertStatus(422)->assertJsonPath('error.code', 'SENSOR_TYPE_MISMATCH');

        $this->assertSame($this->device->id, (int) DB::table('sensor_readings')->where('sensor_id', $sensor->id)->value('device_id'));
    }

    public function test_readings_query_range_too_large(): void
    {
        $this->getJson("/api/v1/readings?device_id={$this->device->id}&sensor_type=temp_air&interval=raw&from=2026-01-01T00:00:00Z&to=2026-02-01T00:00:00Z")
            ->assertStatus(422)->assertJsonPath('error.code', 'RANGE_TOO_LARGE');
    }

    public function test_readings_format_kolom(): void
    {
        $this->ingest($this->payload(['ts' => 1757308800]));
        $this->getJson("/api/v1/readings?device_id={$this->device->id}&sensor_type=temp_air,humidity&interval=raw&from=2025-09-08T00:00:00Z&to=2025-09-08T12:00:00Z")
            ->assertOk()
            ->assertJsonPath('data.timestamps.0', 1757308800000)
            ->assertJsonPath('data.series.0.values.0', 27.4)
            ->assertJsonCount(2, 'data.series');
    }
}
