# Luwes Weather Station

Platform monitoring stasiun cuaca: ingest telemetri dari perangkat, simpan time-series, agregasi, dan tampilkan di dashboard.

| Komponen | Stack |
|---|---|
| `backend/` | Laravel 12 (PHP 8.3, Nginx + PHP-FPM via `serversideup/php`), Sanctum |
| `frontend/` | Next.js 16 (App Router, React 19), TanStack Query, ECharts, Tailwind 4, react-hook-form + zod |
| `simulator/` | Node.js 20 (ESM, tanpa dependency, `fetch` bawaan) |
| Database | PostgreSQL 16 + TimescaleDB 2.30 (`timescaledb`, `btree_gist`) |
| Cache/limiter | Redis 7 |

Dokumen detail: [`BACKEND.md`](BACKEND.md), [`DATABASE.md`](DATABASE.md), [`FRONTEND.md`](FRONTEND.md), [`JAWABAN.md`](JAWABAN.md), [`PANDUAN_IMPLEMENTASI.md`](PANDUAN_IMPLEMENTASI.md). Koleksi API Bruno: `docs/bruno/`.

---

## 1. Setup

### Docker (disarankan)

```bash
cp .env.example .env
docker compose up --build
```

| Service | URL / port host |
|---|---|
| Frontend | http://localhost:3000 (`FRONTEND_PORT_FORWARD`) |
| Backend API | http://localhost:8080/api/v1 (`BACKEND_PORT_FORWARD`) |
| Health check | http://localhost:8080/healthz |
| PostgreSQL | `localhost:5433` (`DB_PORT_FORWARD`) |
| Redis | `localhost:6380` (`REDIS_PORT_FORWARD`) |

> Windows: port 8080 bisa masuk rentang reserved Hyper-V. Jika bentrok, set `BACKEND_PORT_FORWARD=18080` di `.env` (environment Bruno memakai 18080).

Saat start, container `backend` (`APP_INIT=true`) menjalankan `php artisan migrate --force`, lalu `db:seed --force` bila tabel `devices` kosong. Seeder mengisi sensor type, lokasi, 4 device (`WS-GRT-001`, `WS-DPK-001`, `WS-BGR-001` aktif; `WS-SBY-001` provisioned), sensor, 7 hari data historis, lalu `aggregates:rebuild --days=8`.

Urutan start: `db` + `redis` healthy, lalu `backend` healthy, lalu `scheduler`, `frontend`, `simulator`.

Perintah berguna:

```bash
docker compose down -v                                   # reset total (hapus volume DB)
docker compose exec backend php artisan migrate:fresh --seed
docker compose exec backend php artisan aggregates:refresh
docker compose exec backend php artisan aggregates:rebuild --days=8
docker compose exec backend php artisan test
```

### Simulator

```bash
docker compose run --rm simulator
docker compose run --rm -e SIM_SCENARIO=offline -e SIM_OFFLINE_MINUTES=180 simulator
```

Skenario `SIM_SCENARIO`: `normal`, `offline`, `duplicate`, `restart`, `clock-future`, `invalid`, `unknown-device`, `big-batch`. Selain `normal`, skenario berjalan sekali lalu keluar.

### Lokal (tanpa Docker)

Butuh PHP 8.2+, Composer, Node 20.6+, PostgreSQL 16 (+TimescaleDB opsional), Redis.

```bash
# backend
cd backend
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate --seed
php artisan serve --port=8080
php artisan schedule:work          # terminal terpisah

# frontend
cd frontend
cp .env.example .env.local         # BACKEND_INTERNAL_URL=http://localhost:8080
npm install
npm run dev

# simulator
cd simulator
cp .env.example .env
npm start
```

Tanpa TimescaleDB, migrasi melewati pembuatan hypertable dan policy; aplikasi tetap jalan di PostgreSQL biasa.

### Test & lint

```bash
cd backend  && php artisan test          # butuh database weather_test
cd frontend && npm test && npm run lint
cd simulator && npm run check
```

`backend/phpunit.xml` memakai `DB_HOST=127.0.0.1`, user `postgres/root`, DB `weather_test`. Di Docker, sesuaikan env DB (host `db`, user `weather`). `docker/db/init/01-test-db.sql` membuat `weather_test` saat volume DB pertama kali dibuat.

### Environment penting

| Variabel | Fungsi |
|---|---|
| `APP_KEY` | Wajib; entrypoint gagal jika kosong |
| `DEVICE_KEY_PEPPER` | Pepper HMAC untuk hash API key device |
| `SEED_DEVICE_KEYS` | Pasangan device/key untuk seeder dan simulator (dev only) |
| `INGEST_MAX_BATCH` | Maks paket per batch (default 500) |
| `INGEST_FUTURE_TOLERANCE_S` | Toleransi timestamp masa depan (default 300) |
| `READINGS_MAX_POINTS` | Maks titik per series per query (default 2000) |
| `CONNECTIVITY_ONLINE_S` / `CONNECTIVITY_OFFLINE_S` | Ambang status online/offline (300 / 900) |
| `BACKEND_INTERNAL_URL` | URL backend yang dipakai proxy Next.js |
| `AUTH_COOKIE_NAME` | Nama cookie token (`wsm_token`) |

> Nilai `APP_KEY`, `DEVICE_KEY_PEPPER`, dan `SEED_DEVICE_KEYS` di `.env.example` hanya untuk development. Ganti semuanya sebelum deploy.

---

## 2. Arsitektur

```
 ┌───────────┐  HTTP POST /api/v1/ingest/*         ┌──────────────────────┐
 │ Simulator │  Bearer <device key>, X-Request-Id  │ Backend (Laravel)    │
 │ / Device  ├────────────────────────────────────►│ Nginx + PHP-FPM:8080 │
 └───────────┘                                     │                      │
                                                   │  ┌────────────────┐  │      ┌─────────────────┐
 ┌─────────┐  /api/backend/*  ┌──────────────┐     │  │ Ingestion      │  ├─────►│ PostgreSQL 16 + │
 │ Browser ├─────────────────►│ Next.js :3000├────►│  │ Query/Readings │  │      │ TimescaleDB     │
 └─────────┘   (same-origin)  │ BFF proxy    │     │  │ Management     │  │      └─────────────────┘
                              └──────────────┘     │  └────────────────┘  │      ┌─────────────────┐
                                                   │                      ├─────►│ Redis 7         │
 ┌──────────────────────────┐                      └──────────────────────┘      │ cache/throttle  │
 │ Scheduler (schedule:work)│ aggregates:refresh tiap menit ─────────────────────┴─────────────────┘
 └──────────────────────────┘
```

### Alur ingest

1. Device kirim `POST /api/v1/ingest/telemetry`, `/telemetry/batch` (buffer saat offline), atau `/heartbeat`.
2. Middleware berurutan: `throttle:ingest-ip` (300/menit/IP), `GuardIngestPayload` (413 jika >1 MB, 400 `MALFORMED_JSON`), `AuthenticateDevice` (401/403), `throttle:ingest-device` (30/menit/device).
3. Normalisasi paket, resolusi channel ke sensor terpasang berdasarkan waktu device, kalibrasi (`value = raw_value * scale + offset`), quality flag.
4. Insert dalam satu transaksi dengan `ON CONFLICT DO NOTHING RETURNING`; respons melaporkan jumlah accepted vs duplicate.
5. Bucket yang tersentuh masuk `aggregate_refresh_queue`; scheduler menghitung ulang `reading_aggregates` (1h, 1d).

Status code ingest: `201` semua diterima, `200` semua duplikat, `207` campuran, `422` semua ditolak / validasi / `BATCH_TOO_LARGE`, `429` + `Retry-After`, `503` saat DB/Redis down.

### Alur dashboard

Browser hanya bicara ke Next.js. Route `frontend/app/api/backend/[...path]/route.ts` meneruskan request ke `${BACKEND_INTERNAL_URL}/api/v1/...`, meneruskan `X-Request-Id`, dan menambahkan `Authorization: Bearer` dari cookie httpOnly bila ada. Data di-refresh dengan polling TanStack Query.

### Endpoint utama (`/api/v1`)

| Grup | Endpoint |
|---|---|
| Ingest | `POST /ingest/telemetry`, `POST /ingest/telemetry/batch`, `POST /ingest/heartbeat` |
| Lokasi | `apiResource /locations` |
| Device | `GET/POST /devices`, `GET/PATCH/DELETE /devices/{id}`, `POST /devices/{id}/credentials/rotate`, `GET /devices/{id}/health`, `GET/POST /devices/{id}/sensors`, `DELETE /devices/{id}/sensors/{sensor}` |
| Sensor | `GET/POST/PATCH /sensor-types`, `apiResource /sensors`, `GET/POST /sensors/{id}/calibrations` |
| Readings | `GET /devices/{id}/readings/latest`, `GET /readings`, `GET /readings/summary` |
| Dashboard | `GET /dashboard/overview` |
| Health | `GET /healthz` (di luar prefix) |

Format respons: sukses `{data, meta, request_id}`, error `{error: {code, message, details[]}, request_id}`. Waktu API dalam ISO 8601 UTC; payload device memakai epoch detik; `/readings` mengembalikan epoch milidetik dalam format kolom.

### Skema data (ringkas)

```
locations 1─* devices 1─* device_channels *─1 sensor_types
                 │              │
                 │              └─* sensor_installations *─1 sensors 1─* sensor_calibrations
                 ├─* device_credentials
                 ├─* device_status_history
                 ├─* device_packets      (hypertable, chunk 7 hari)
                 ├─* device_heartbeats   (hypertable, chunk 30 hari)
                 └─* sensor_readings     (hypertable, chunk 7 hari)  ──► reading_aggregates (1h, 1d)
```

| Tabel | Kunci penting |
|---|---|
| `sensor_readings` | UNIQUE `(channel_id, time)`; simpan `raw_value`, `value`, `quality_flags`, `sensor_id` |
| `device_packets` | UNIQUE `(device_id, time)`; `seq`, `source` (live/batch), battery, RSSI |
| `sensor_installations` | Range waktu `[installed_at, removed_at)` dengan GiST `EXCLUDE` agar tidak overlap per channel maupun per sensor |
| `reading_aggregates` | PK `(channel_id, bucket_interval, bucket_start)`; avg/min/max/sum, `sum_x`/`sum_y` untuk arah angin |
| `device_credentials` | `key_hash` (HMAC-SHA256) unik, partial index kredensial aktif |

Policy TimescaleDB: kompresi `sensor_readings` & `device_packets` setelah 7 hari (retensi 2 tahun), `device_heartbeats` setelah 30 hari (retensi 1 tahun).

Quality flag (bitmask): `1 OUT_OF_RANGE`, `2 SENSOR_ERROR`, `4 CLOCK_FUTURE`, `8 MAINTENANCE`. Hanya baris dengan flag `0` yang diagregasi.

### Struktur direktori

```
backend/
  app/Domain/{Aggregation,Calibration,Devices,Ingestion,Quality,Rain}/   logika domain
  app/Http/Controllers/Api/V1/                                          controller (+ Ingest/)
  app/Http/Middleware/                                                  AssignRequestId, AuthenticateDevice, GuardIngestPayload
  app/Http/Requests/                                                    FormRequest validasi
  app/Console/Commands/                                                 aggregates:refresh, aggregates:rebuild
  config/weather.php                                                    konfigurasi domain
  database/{migrations,seeders,factories}/
  tests/{Unit,Feature}/
frontend/
  app/(dashboard)/            overview, stations/[id], manage/devices, manage/sensors
  app/api/backend/[...path]/  BFF proxy
  components/                 charts, dashboard, manage, states, stations
  lib/api/                    client, hooks, types, errors
  lib/{ranges,series,time}.ts range-interval, gap handling, konversi WIB
simulator/src/                device, scenarios, weather, client
docker/db/init/               init SQL (database test)
docs/bruno/                   koleksi API
```

---

## 3. Keputusan desain

### Data model

- **Narrow table** (satu baris per sensor per waktu), bukan wide table. Tipe sensor baru atau sensor kedua dengan tipe sama tidak butuh `ALTER TABLE`; sensor yang tidak mengirim berarti tidak ada baris, bukan `NULL`; flag dan `sensor_id` melekat per nilai. Overhead baris ditekan kompresi Timescale yang di-segment per `channel_id`.
- **Lapisan `device_channels`.** Kunci `s` di payload adalah slot di device, bukan sensor fisik. Pemasangan sensor dicatat sebagai range waktu dengan exclusion constraint, dan `sensor_id` di-resolve saat ingest memakai waktu device. Sensor yang dipindah ke device lain tetap meninggalkan data lama di device lama.
- **`raw_value` tidak pernah diubah.** Kalibrasi menghasilkan `value`; kalibrasi tidak boleh backdate (`effective_from` ≥ sekarang − 5 menit) supaya data historis konsisten.

### Ingest

- **Idempoten via unique key**, bukan `seq`: `(device_id, time)` untuk paket dan `(channel_id, time)` untuk reading. `seq` reset saat reboot sehingga tidak bisa jadi kunci. Timestamp sama dengan `seq` berbeda menghasilkan warning `TS_COLLISION`.
- **Sinkron, satu transaksi per request.** Device butuh ACK setelah commit dan angka accepted/duplicate yang akurat; retry aman karena insert idempoten. Bulk insert di-chunk (1000 reading, 500 paket) agar di bawah batas 65.535 parameter PostgreSQL. Jalur scale-out yang direncanakan: Redis Stream + `COPY`.
- **Waktu device jadi acuan series**, `received_at` untuk status konektivitas dan `clock_offset_s`. Timestamp >5 menit di masa depan tetap disimpan dengan flag `CLOCK_FUTURE`; timestamp sebelum 2020 ditolak.
- **Partial accept.** Satu nilai invalid tidak membuang seluruh paket; respons `207` merinci per item.

### Agregasi

- **Dirty-bucket queue, bukan continuous aggregate Timescale.** Delta counter hujan butuh `LAG` lintas baris, dan data terlambat (batch setelah offline) harus memicu hitung ulang bucket lama. Untuk channel counter, bucket jam berikutnya ikut di-enqueue.
- **`1d` diturunkan dari 24 baris `1h`** per hari WIB, sehingga total hujan harian selalu sama dengan jumlah bar per jam.
- **Counter hujan:** delta = nilai baru jika counter atau `seq` turun (reset/reboot); tidak pernah negatif; 0,2 mm per tip.
- **Arah angin** memakai circular mean dari `sum_x`/`sum_y`, bukan rata-rata aritmetika derajat (rata-rata 350° dan 10° harus 0°, bukan 180°).

### Keamanan

- **API key device**: `wsk_` + 40 karakter base62 (~238 bit), disimpan sebagai HMAC-SHA256 dengan pepper dari environment. Bcrypt tidak dipakai karena key sudah high-entropy dan dicek tiap request. Rotasi memberi grace period 24 jam.
- **Rate limit per device dipasang setelah autentikasi**, supaya penyerang tidak bisa menghabiskan kuota device korban dengan request tanpa key valid.
- **BFF proxy + cookie httpOnly**: token tidak pernah terekspos ke JavaScript browser, dan backend tidak perlu CORS.
- **Decommission** mencabut kredensial dan menutup instalasi aktif, tapi data historis dipertahankan.

### Query & frontend

- **Batas 2000 titik per series.** Interval eksplisit yang melebihi batas dijawab `422 RANGE_TOO_LARGE` dengan `suggested_interval`; tanpa interval, backend memilih interval terhalus yang muat. Respons format kolom (`timestamps` bersama + `values` per series) agar payload kecil.
- **UTC di semua lapisan penyimpanan dan API.** Konversi ke WIB hanya di `frontend/lib/time.ts` dan untuk batas hari WIB di backend.
- **Gap ditampilkan jujur.** Jika jarak antar titik > 1,5× interval, disisipkan `null` (`connectNulls: false`) dan area diarsir abu-abu. Tidak ada interpolasi atau isi nol.
- **State per panel** (loading, empty, error) independen; pesan error menampilkan `request_id` untuk tracing ke log backend.
- **Polling** via TanStack Query, bukan WebSocket/SSE: cukup untuk interval data 1 menit dan lebih sederhana dioperasikan.

---

## 4. Batasan yang diketahui

- Autentikasi user (Sanctum + role admin/operator/viewer) sudah dirancang di skema, tapi route login dan middleware `auth:sanctum` belum dipasang. Endpoint manajemen dan query saat ini terbuka.
- Belum ada: MQTT, SSE/realtime push, endpoint wind-rose/metrics/export, deteksi sensor stuck, alert hujan, job reprocessing kalibrasi, preset 1 tahun di UI.
- Tidak ada worker queue terpisah; agregasi berjalan lewat scheduler.
