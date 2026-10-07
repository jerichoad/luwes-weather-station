# Dokumentasi API — Luwes Weather Station

REST API backend (Laravel 12) untuk platform monitoring stasiun cuaca. Dokumen ini disusun dari koleksi Bruno di `docs/bruno/Luwes Weather Station API/` dan disesuaikan dengan implementasi di `backend/` (routes, controller, FormRequest).

Isi:

1. [Konvensi umum](#1-konvensi-umum)
2. [Autentikasi & rate limit](#2-autentikasi--rate-limit)
3. [Daftar endpoint](#3-daftar-endpoint)
4. [Ingestion](#4-ingestion)
5. [Lokasi](#5-lokasi)
6. [Device](#6-device)
7. [Sensor type](#7-sensor-type)
8. [Sensor, pemasangan & kalibrasi](#8-sensor-pemasangan--kalibrasi)
9. [Query data](#9-query-data)
10. [Dashboard & health](#10-dashboard--health)
11. [Contoh JSON Bagian F (dengan penjelasan field)](#11-contoh-json-bagian-f)
12. [Koleksi Bruno](#12-koleksi-bruno)

---

## 1. Konvensi umum

### 1.1 Base URL

| Item | Nilai |
|---|---|
| Base URL | `http://localhost:8080` (atur lewat `BACKEND_PORT_FORWARD`; env Bruno `Local` memakai `http://localhost:18080`) |
| Prefix API | `/api/v1` |
| Health check | `GET /healthz` (tanpa prefix) |
| Content type | `application/json` (request & response) |

### 1.2 Envelope

Semua response memakai envelope yang sama.

Sukses:

```json
{ "data": {}, "meta": {}, "request_id": "01J9ZB1Q6N8E4V3K2T5R7W9XYZ" }
```

Error:

```json
{
  "error": { "code": "VALIDATION_FAILED", "message": "Payload tidak valid.", "details": [] },
  "request_id": "01J9ZB1Q6N8E4V3K2T5R7W9XYZ"
}
```

| Field | Keterangan |
|---|---|
| `data` | Objek atau array hasil. `null` untuk DELETE sukses. |
| `meta` | Opsional. Info pagination atau info query. |
| `request_id` | ID request. Diambil dari header `X-Request-Id` jika valid (`[\w\-.]{1,64}`), selain itu dibuat ULID baru. Juga dikirim di header response `X-Request-Id` dan dicatat di setiap baris log. |
| `error.code` | Kode machine-readable, `UPPER_SNAKE`. |
| `error.message` | Pesan untuk manusia (Bahasa Indonesia). |
| `error.details` | Array detail per field (boleh kosong). Tiap item: `field` (dot-notation), `code`, `message`, kadang field tambahan (`allowed_transitions`, `suggested_interval`). |

Semua nama field `snake_case`.

### 1.3 Waktu

- Request & response memakai **ISO 8601 UTC**, mis. `2026-10-07T03:00:00Z`.
- Pengecualian: array kolom time-series (`timestamps` di `/readings`, `battery_trend_24h`) memakai **epoch milidetik**.
- Payload device memakai **epoch detik** (`ts`).
- Backend tidak mengonversi ke WIB, kecuali batas hari untuk agregat `1d` dan `/readings/summary` (hari = 00:00–24:00 WIB, `Asia/Jakarta`).

### 1.4 Pagination

| Jenis | Dipakai di | Parameter | `meta` |
|---|---|---|---|
| Offset | `locations`, `devices`, `sensors`, `sensor-types`, `calibrations`, `readings/summary` | `page` (default 1), `per_page` (default 20, maks 100) | `page`, `per_page`, `total`, `last_page` |
| Cursor (keyset) | `/readings` | `cursor` (epoch ms), `limit` (maks 2000) | `next_cursor` (`null` jika habis) |

### 1.5 Status code

| Code | Arti |
|---|---|
| 200 | GET/PATCH/DELETE sukses; ingestion yang seluruhnya duplikat |
| 201 | Create sukses; ingestion yang seluruhnya diterima |
| 207 | Batch campuran (diterima + duplikat/ditolak) |
| 400 | Body bukan JSON valid (`MALFORMED_JSON`, hanya `/ingest/*`) |
| 401 | Credential device salah / device tidak terdaftar |
| 403 | Device `decommissioned` |
| 404 | Resource tidak ada |
| 405 | Method tidak diizinkan |
| 409 | Konflik state (transisi status ilegal, sensor/channel terpakai, resource masih dipakai) |
| 413 | Body > 1 MB |
| 422 | Validasi gagal, batch terlalu besar, rentang query terlalu besar |
| 429 | Rate limit (`Retry-After`) |
| 500 | Error tak terduga (detail hanya di log) |
| 503 | DB/Redis tidak tersedia (`Retry-After: 30`) |

### 1.6 Katalog error code

| Code | HTTP | Kapan |
|---|---|---|
| `VALIDATION_FAILED` | 422 | Field tidak valid (lihat `details`) |
| `MALFORMED_JSON` | 400 | Body ingestion tidak bisa di-parse |
| `INVALID_DEVICE_CREDENTIALS` | 401 | API key salah, kosong, atau `device_id` tidak terdaftar (sengaja tidak dibedakan) |
| `DEVICE_DECOMMISSIONED` | 403 | Device sudah `decommissioned` |
| `FORBIDDEN` | 403 | Akses ditolak |
| `NOT_FOUND` | 404 | Resource tidak ada |
| `METHOD_NOT_ALLOWED` | 405 | Method HTTP salah |
| `INVALID_STATUS_TRANSITION` | 409 | Transisi status device tidak diizinkan |
| `SENSOR_ALREADY_INSTALLED` | 409 | Sensor masih terpasang di tempat lain |
| `CHANNEL_OCCUPIED` | 409 | Channel sudah terisi sensor lain |
| `SENSOR_TYPE_MISMATCH` | 422 | Tipe sensor ≠ tipe channel |
| `RESOURCE_IN_USE` | 409 | Hapus/retire resource yang masih dipakai |
| `BATCH_TOO_LARGE` | 422 | Batch > 500 item |
| `PAYLOAD_TOO_LARGE` | 413 | Body > 1 MB |
| `RANGE_TOO_LARGE` | 422 | Jumlah titik query > 2000 |
| `INVALID_AGGREGATION` | 422 | `agg=sum` untuk sensor non-counter |
| `TIMESTAMP_INVALID` | 422 | `ts` < 2020-01-01 (jam device belum tersinkron) |
| `RATE_LIMITED` | 429 | Melewati rate limit |
| `SERVICE_UNAVAILABLE` | 503 | DB/Redis down |
| `INTERNAL_ERROR` | 500 | Error tak terduga |

Kode `details[].code` per field: `REQUIRED`, `NOT_NUMERIC`, `NOT_INTEGER`, `NOT_STRING`, `NOT_ARRAY`, `NOT_BOOLEAN`, `INVALID_DATE`, `OUT_OF_BOUNDS`, `INVALID_VALUE`, `NOT_FOUND`, `ALREADY_EXISTS`, `INVALID_FORMAT`, `TIMESTAMP_INVALID`, `BACKDATED_NOT_SUPPORTED`.

**Warning ingestion** (bukan error, muncul di `warnings[]` response ingestion):

| Code | Arti |
|---|---|
| `CHANNEL_NOT_MAPPED` | `s` tidak punya channel, atau tidak ada sensor terpasang pada waktu bacaan. Bacaan dilewati. |
| `DUPLICATE_CHANNEL_IN_PACKET` | `s` muncul > 1× dalam satu paket; yang pertama dipakai. |
| `SENSOR_ERROR_CODE` | Nilai = kode error sensor (mis. `-999`); disimpan `value = null`, flag `SENSOR_ERROR`. |
| `OUT_OF_RANGE` | Nilai terkalibrasi di luar `min_value..max_value`; disimpan dengan flag. |
| `CLOCK_FUTURE` | `ts` > waktu server + 5 menit; disimpan dengan flag, dikecualikan dari agregat. |
| `TS_COLLISION` | Paket duplikat (`ts` sama) tapi `seq` berbeda dari yang tersimpan. |

### 1.7 Quality flag (bitmask)

| Bit | Nama | Arti |
|---|---|---|
| 1 | `OUT_OF_RANGE` | Di luar rentang fisik tipe sensor |
| 2 | `SENSOR_ERROR` | Kode error sensor, `value = null` |
| 4 | `CLOCK_FUTURE` | Jam device di depan server > 5 menit |
| 8 | `MAINTENANCE` | Device sedang `maintenance` |

Bacaan dengan flag ≠ 0 tetap disimpan (beserta `raw_value`) tetapi tidak ikut agregat, latest, maupun `/readings` default.

---

## 2. Autentikasi & rate limit

### 2.1 Device (endpoint `/ingest/*`)

```
Authorization: Bearer wsk_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

- `device_id` (kode device, mis. `WS-GRT-001`) dikirim di body.
- Key format `wsk_` + 40 karakter acak. Disimpan sebagai `HMAC-SHA256(key, DEVICE_KEY_PEPPER)`, dibandingkan dengan `hash_equals`.
- Key didapat **sekali** dari `POST /devices` atau `POST /devices/{id}/credentials/rotate`.
- Saat rotasi, key lama tetap berlaku sampai `previous_keys_expire_at` (grace 24 jam).
- Device tidak terdaftar dan key salah sama-sama `401 INVALID_DEVICE_CREDENTIALS`.

### 2.2 Endpoint manajemen

> Versi saat ini **belum** memasang `auth:sanctum` pada endpoint manajemen dan query. Semua endpoint selain `/ingest/*` terbuka. Rencana: Sanctum token + role `admin`/`operator`/`viewer` (lihat `BACKEND.md` §5.2). Kolom "Role (rencana)" di tabel endpoint menunjukkan role minimum yang direncanakan.

### 2.3 Rate limit ingestion (Redis)

| Limiter | Kunci | Batas | Urutan |
|---|---|---|---|
| `ingest-ip` | IP | 300 req/menit | Sebelum auth |
| `ingest-device` | `device.id` terautentikasi | 30 req/menit | Setelah auth |

Lewat batas: `429 RATE_LIMITED` + header `Retry-After`.

Urutan middleware `/ingest/*`: `AssignRequestId` → `throttle:ingest-ip` → `GuardIngestPayload` (413/400) → `AuthenticateDevice` (401/403) → `throttle:ingest-device` → FormRequest (422).

---

## 3. Daftar endpoint

| Method | Path | Auth | Role (rencana) | Bruno |
|---|---|---|---|---|
| GET | `/healthz` | – | publik | Health/Healthz |
| POST | `/api/v1/ingest/telemetry` | Device | – | Ingest/Telemetry Single |
| POST | `/api/v1/ingest/telemetry/batch` | Device | – | Ingest/Telemetry Batch |
| POST | `/api/v1/ingest/heartbeat` | Device | – | Ingest/Heartbeat |
| GET | `/api/v1/locations` | – | viewer | Locations/List Locations |
| POST | `/api/v1/locations` | – | operator | Locations/Create Location |
| GET | `/api/v1/locations/{id}` | – | viewer | Locations/Get Location |
| PATCH | `/api/v1/locations/{id}` | – | operator | Locations/Update Location |
| DELETE | `/api/v1/locations/{id}` | – | admin | Locations/Delete Location |
| GET | `/api/v1/devices` | – | viewer | Devices/List Devices |
| POST | `/api/v1/devices` | – | operator | Devices/Create Device |
| GET | `/api/v1/devices/{id}` | – | viewer | Devices/Get Device |
| PATCH | `/api/v1/devices/{id}` | – | operator | Devices/Update Device, Change Device Status |
| DELETE | `/api/v1/devices/{id}` | – | admin | Devices/Delete Device |
| POST | `/api/v1/devices/{id}/credentials/rotate` | – | admin | Devices/Rotate Credential |
| GET | `/api/v1/devices/{id}/health` | – | viewer | Devices/Device Health |
| GET | `/api/v1/devices/{id}/sensors` | – | viewer | Devices/List Device Sensors |
| POST | `/api/v1/devices/{id}/sensors` | – | operator | Devices/Install Sensor |
| DELETE | `/api/v1/devices/{id}/sensors/{sensor_id}` | – | operator | Devices/Uninstall Sensor |
| GET | `/api/v1/sensor-types` | – | viewer | Sensor Types/List Sensor Types |
| POST | `/api/v1/sensor-types` | – | admin | Sensor Types/Create Sensor Type |
| PATCH | `/api/v1/sensor-types/{id}` | – | admin | Sensor Types/Update Sensor Type |
| GET | `/api/v1/sensors` | – | viewer | Sensors/List Sensors |
| POST | `/api/v1/sensors` | – | operator | Sensors/Create Sensor |
| GET | `/api/v1/sensors/{id}` | – | viewer | Sensors/Get Sensor |
| PATCH | `/api/v1/sensors/{id}` | – | operator | Sensors/Update Sensor |
| DELETE | `/api/v1/sensors/{id}` | – | admin | Sensors/Delete Sensor |
| GET | `/api/v1/sensors/{id}/calibrations` | – | viewer | Sensors/List Calibrations |
| POST | `/api/v1/sensors/{id}/calibrations` | – | operator | Sensors/Create Calibration |
| GET | `/api/v1/devices/{id}/readings/latest` | – | viewer | Readings/Latest Readings |
| GET | `/api/v1/readings` | – | viewer | Readings/Query Readings, Rain Readings |
| GET | `/api/v1/readings/summary` | – | viewer | Readings/Daily Summary |
| GET | `/api/v1/dashboard/overview` | – | viewer | Dashboard/Overview |

---

## 4. Ingestion

### 4.1 `POST /api/v1/ingest/telemetry`

Kirim satu paket bacaan.

**Body**

| Field | Tipe | Wajib | Keterangan |
|---|---|---|---|
| `device_id` | string ≤ 32 | ya | Kode device (`WS-GRT-001`) |
| `fw` | string ≤ 32 | ya | Versi firmware |
| `ts` | integer | ya | Epoch detik device time, ≥ `1577836800` (2020-01-01) |
| `seq` | integer ≥ 0 | ya | Nomor urut paket (reset saat reboot) |
| `battery_v` | number 0–10 | tidak | Tegangan baterai (V) |
| `rssi` | integer -150–0 | tidak | Kekuatan sinyal (dBm) |
| `readings` | array ≤ 32 | ya (boleh `[]`) | Daftar bacaan |
| `readings[].s` | string ≤ 32 | ya | `channel_key` (mis. `temp_air`) |
| `readings[].v` | number | ya | Nilai mentah (belum dikalibrasi) |

**Contoh request**

```bash
curl -X POST http://localhost:8080/api/v1/ingest/telemetry \
  -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" \
  -d '{"device_id":"WS-GRT-001","fw":"1.4.2","ts":1791342000,"seq":10432,
       "battery_v":3.92,"rssi":-71,
       "readings":[{"s":"temp_air","v":26.4},{"s":"humidity","v":78.2}]}'
```

**Response**

| Status | Kondisi |
|---|---|
| 201 | Paket baru (`status: accepted`) |
| 200 | Paket sudah ada (`status: duplicate`), data tidak digandakan |
| 400 / 401 / 403 / 413 / 422 / 429 / 503 | Lihat §1.5 |

Contoh lengkap + penjelasan field: [§11.1](#111-ingestion).

### 4.2 `POST /api/v1/ingest/telemetry/batch`

Kirim 1–500 paket sekaligus (flush buffer setelah offline).

**Body**

| Field | Tipe | Wajib | Keterangan |
|---|---|---|---|
| `device_id` | string ≤ 32 | ya | Kode device |
| `fw` | string ≤ 32 | ya | Versi firmware (berlaku untuk semua item) |
| `batch` | array 1–500 | ya | Daftar paket. > 500 → `422 BATCH_TOO_LARGE` |
| `batch[].ts`, `seq`, `battery_v`, `rssi`, `readings` | – | – | Sama seperti telemetry tunggal; divalidasi **per item** |

Perilaku:

- Item invalid ditolak (`rejected`), item valid tetap diproses.
- Batch diurutkan berdasarkan `ts`; `ts` sama di dalam batch → yang pertama diterima, sisanya `duplicate`.
- Semua item ditulis dalam satu transaksi DB. Jika DB gagal, seluruh request di-rollback dan device menerima 503; kirim ulang aman karena insert idempoten.

**Status code**

| Kondisi | Status |
|---|---|
| Semua `accepted` | 201 |
| Semua `duplicate` | 200 |
| Semua `rejected` | 422 (envelope tetap `data`, berisi `summary` + `items`) |
| Campuran | 207 |

Device boleh menghapus item `accepted` **dan** `duplicate` dari buffer. Item `rejected` tidak akan pernah valid, sehingga dibuang dan dicatat.

Contoh 207: [§11.1.3](#1113-batch-sukses-sebagian-207).

### 4.3 `POST /api/v1/ingest/heartbeat`

Laporan kesehatan tanpa data sensor.

| Field | Tipe | Wajib | Keterangan |
|---|---|---|---|
| `device_id` | string ≤ 32 | ya | Kode device |
| `ts` | integer ≥ 1577836800 | ya | Epoch detik |
| `fw` | string ≤ 32 | ya | Versi firmware |
| `battery_v` | number 0–10 | tidak | |
| `rssi` | integer -150–0 | tidak | |
| `uptime_s` | integer ≥ 0 | ya | Detik sejak boot; dipakai menghitung `last_boot_at` |

**Response 201 / 200**

```json
{
  "data": { "status": "accepted", "server_time": "2026-10-07T03:00:01Z" },
  "request_id": "01J9ZB1Q6N8E4V3K2T5R7W9XYZ"
}
```

`status`: `accepted` (201) atau `duplicate` (200, heartbeat dengan `ts` sama sudah ada). `last_seen_at` device tetap diperbarui pada keduanya.

---

## 5. Lokasi

### 5.1 `GET /api/v1/locations`

Query: `q` (cari di `code`/`name`, ILIKE), `page`, `per_page`.

```json
{
  "data": [
    { "id": 1, "code": "GRT", "name": "Garut", "address": "Kabupaten Garut, Jawa Barat",
      "latitude": -7.2279, "longitude": 107.9087, "altitude_m": 717.0 }
  ],
  "meta": { "page": 1, "per_page": 20, "total": 4, "last_page": 1 },
  "request_id": "..."
}
```

### 5.2 `POST /api/v1/locations`

| Field | Tipe | Wajib | Keterangan |
|---|---|---|---|
| `code` | string ≤ 20 | ya | Unik |
| `name` | string ≤ 150 | ya | |
| `address` | string | tidak | |
| `latitude` | number -90–90 | ya | |
| `longitude` | number -180–180 | ya | |
| `altitude_m` | number | tidak | Ketinggian (m) |

Response 201: `{ "id", "code", "name" }`. Error: 422 `VALIDATION_FAILED` (mis. `code` → `ALREADY_EXISTS`).

### 5.3 `GET /api/v1/locations/{id}`

Semua field lokasi + `created_at`, `updated_at`. 404 jika tidak ada.

### 5.4 `PATCH /api/v1/locations/{id}`

Field sama dengan create, semuanya opsional. Response 200 = detail lokasi.

### 5.5 `DELETE /api/v1/locations/{id}`

200 `data: null`. 409 `RESOURCE_IN_USE` jika masih dipakai device (termasuk device yang sudah soft delete).

---

## 6. Device

### 6.1 `GET /api/v1/devices`

| Query | Keterangan |
|---|---|
| `status` | `provisioned` \| `active` \| `maintenance` \| `decommissioned` |
| `location_id` | ID lokasi |
| `q` | Cari di `code`/`name` |
| `connectivity` | `online` (≤ 5 menit) \| `stale` (5–15 menit) \| `offline` (> 15 menit) \| `never` |
| `silent_minutes` | Device yang tidak mengirim apa pun > N menit (termasuk yang belum pernah) |
| `page`, `per_page` | Offset pagination |

### 6.2 `POST /api/v1/devices`

| Field | Tipe | Wajib | Keterangan |
|---|---|---|---|
| `code` | string ≤ 32, `^[A-Z0-9-]+$` | ya | Unik; dipakai sebagai `device_id` di payload ingestion |
| `name` | string ≤ 150 | ya | |
| `location_id` | integer | ya | Harus ada di `locations` |
| `create_default_channels` | boolean | tidak (default `true`) | Buat satu channel per sensor type dengan `channel_key` = kode tipe |

Device baru berstatus `provisioned`. Response 201 memuat `credential.api_key` **satu kali**.

### 6.3 `GET /api/v1/devices/{id}`

Detail + `allowed_transitions`, `connectivity`, `health`, `channels[]` beserta sensor terpasang.

### 6.4 `PATCH /api/v1/devices/{id}`

| Field | Tipe | Wajib | Keterangan |
|---|---|---|---|
| `name` | string ≤ 150 | tidak | |
| `location_id` | integer | tidak | |
| `status` | enum | tidak | Diproses lewat state machine |
| `status_reason` | string ≤ 255 | wajib jika `status` dikirim | Dicatat di `device_status_history` |

State machine:

| Dari | Ke yang diizinkan |
|---|---|
| `provisioned` | `active`, `decommissioned` |
| `active` | `maintenance`, `decommissioned` |
| `maintenance` | `active`, `decommissioned` |
| `decommissioned` | – |

- Transisi ilegal → 409 `INVALID_STATUS_TRANSITION`, `details[0].allowed_transitions` berisi tujuan yang sah.
- Status sama dengan sekarang → no-op, 200.
- `decommissioned` mencabut semua credential dan menutup semua instalasi sensor aktif (`removal_reason = device_decommissioned`). Data historis tetap.
- `provisioned` otomatis menjadi `active` saat telemetry valid pertama diterima.

Response 200 = detail device (sama dengan GET).

### 6.5 `DELETE /api/v1/devices/{id}`

Soft delete. Hanya untuk device `decommissioned`; selain itu 409 `RESOURCE_IN_USE`.

### 6.6 `POST /api/v1/devices/{id}/credentials/rotate`

Tanpa body. Response 201:

```json
{
  "data": {
    "api_key": "wsk_7Hc2mQp9Lx...",
    "key_prefix": "wsk_7Hc2mQp9",
    "previous_keys_expire_at": "2026-10-08T03:00:00Z"
  },
  "request_id": "..."
}
```

| Field | Keterangan |
|---|---|
| `api_key` | Key baru, hanya ditampilkan sekali |
| `key_prefix` | 12 karakter pertama, untuk identifikasi |
| `previous_keys_expire_at` | Key lama masih berlaku sampai waktu ini (grace 24 jam) |

403 `DEVICE_DECOMMISSIONED` untuk device yang sudah decommissioned.

### 6.7 `GET /api/v1/devices/{id}/health`

```json
{
  "data": {
    "connectivity": "stale",
    "last_seen_at": "2026-10-07T02:58:00Z",
    "silent_for_s": 720,
    "last_reading_at": "2026-10-07T02:57:00Z",
    "battery_v": 3.71,
    "rssi": -88,
    "firmware_version": "1.4.2",
    "uptime_s": 864321,
    "last_boot_at": "2026-09-27T02:52:39Z",
    "clock_offset_s": 2,
    "channels_without_data": ["solar_rad"],
    "battery_trend_24h": {
      "interval": "1h",
      "timestamps": [1791255600000, 1791259200000],
      "values": [3.74, 3.72]
    }
  },
  "request_id": "..."
}
```

| Field | Keterangan |
|---|---|
| `connectivity` | `online` / `stale` / `offline` / `never`, dihitung dari `last_seen_at` (waktu server) |
| `last_seen_at` | Request terakhir apa pun (telemetry/heartbeat) diterima server |
| `silent_for_s` | Detik sejak `last_seen_at`; `null` jika belum pernah |
| `last_reading_at` | Device time bacaan terbaru (tanpa `CLOCK_FUTURE`) |
| `battery_v`, `rssi`, `firmware_version` | Nilai terakhir yang dilaporkan |
| `uptime_s`, `last_boot_at` | Dari heartbeat terakhir; `last_boot_at = ts - uptime_s` |
| `clock_offset_s` | `waktu server - ts` paket live terakhir; positif = jam device tertinggal |
| `channels_without_data` | `channel_key` tanpa bacaan valid > 15 menit |
| `battery_trend_24h` | Rata-rata `battery_v` per jam, 24 jam terakhir (epoch ms) |

---

## 7. Sensor type

### 7.1 `GET /api/v1/sensor-types`

Offset pagination. Item: `id`, `code`, `name`, `unit`, `kind`, `min_value`, `max_value`, `precision`, `counter_factor`, `derived_unit`, `error_codes`, `default_agg`.

Data seed:

| `code` | `unit` | `kind` | Rentang | `error_codes` | Catatan |
|---|---|---|---|---|---|
| `temp_air` | °C | gauge | -40..60 | `[-999]` | |
| `humidity` | % | gauge | 0..100 | `[-999]` | |
| `pressure` | hPa | gauge | 800..1100 | `[-999]` | |
| `wind_speed` | m/s | gauge | 0..75 | – | |
| `wind_dir` | ° | angle | 0..359 | – | Rata-rata sirkular |
| `rain_counter` | tip | counter | 0..1e6 | – | `counter_factor = 0.2`, `derived_unit = mm` |
| `solar_rad` | W/m² | gauge | 0..1500 | – | |

### 7.2 `POST /api/v1/sensor-types`

| Field | Tipe | Wajib | Keterangan |
|---|---|---|---|
| `code` | string ≤ 32 | ya | Unik |
| `name` | string ≤ 100 | ya | |
| `unit` | string ≤ 16 | ya | |
| `kind` | `gauge` \| `counter` \| `angle` | ya | Menentukan cara agregasi |
| `min_value`, `max_value` | number | ya | `max_value > min_value` |
| `precision` | integer 0–6 | tidak | Digit desimal tampilan |
| `counter_factor` | number | tidak | Konversi tip → satuan turunan |
| `derived_unit` | string ≤ 16 | tidak | Satuan setelah konversi counter |
| `error_codes` | number[] | tidak | Nilai yang berarti sensor error |
| `default_agg` | `avg` \| `min` \| `max` \| `sum` | tidak | |

Response 201: `{ "id", "code" }`.

### 7.3 `PATCH /api/v1/sensor-types/{id}`

Field sama dengan create kecuali `code` dan `kind` (tidak bisa diubah). Perubahan rentang tidak mengubah flag data lama. Response 200: `{ "id", "code" }`.

---

## 8. Sensor, pemasangan & kalibrasi

### 8.1 `GET /api/v1/sensors`

| Query | Keterangan |
|---|---|
| `sensor_type` | Kode tipe |
| `installed` | `true` / `false` (sedang terpasang atau tidak) |
| `retired` | `true` / `false` |
| `page`, `per_page` | |

Item: `id`, `serial_number`, `sensor_type`, `manufacturer`, `model`, `retired_at`.

### 8.2 `POST /api/v1/sensors`

| Field | Tipe | Wajib | Keterangan |
|---|---|---|---|
| `serial_number` | string ≤ 64 | ya | Unik |
| `sensor_type` | string | ya | **Kode** sensor type (bukan ID) |
| `manufacturer` | string ≤ 100 | tidak | |
| `model` | string ≤ 100 | tidak | |
| `notes` | string | tidak | |

Response 201: `{ "id", "serial_number", "sensor_type" }`.

### 8.3 `GET /api/v1/sensors/{id}`

Detail + riwayat `installations[]` (termasuk yang sudah dilepas) + `calibrations[]`.

### 8.4 `PATCH /api/v1/sensors/{id}`

Field opsional: `manufacturer`, `model`, `notes`, `retired` (boolean). `retired: true` saat masih terpasang → 409 `RESOURCE_IN_USE`. Response 200: `{ "id", "serial_number" }`.

### 8.5 `DELETE /api/v1/sensors/{id}`

Soft delete. 409 `RESOURCE_IN_USE` jika masih terpasang.

### 8.6 `GET /api/v1/devices/{id}/sensors`

Instalasi aktif. `?include_history=true` menyertakan yang sudah dilepas. Item: `installation_id`, `sensor{}`, `channel{}`, `installed_at`, `removed_at`, `removal_reason`.

### 8.7 `POST /api/v1/devices/{id}/sensors` — pasang sensor

| Field | Tipe | Wajib | Keterangan |
|---|---|---|---|
| `sensor_id` | integer | ya | Harus ada |
| `channel_key` | string ≤ 32 | tidak | Default = kode tipe sensor. Channel dibuat jika belum ada. |
| `installed_at` | datetime ISO 8601 | tidak | Default sekarang |

| Error | Kondisi |
|---|---|
| 403 `DEVICE_DECOMMISSIONED` | Device sudah decommissioned |
| 409 `RESOURCE_IN_USE` | Sensor sudah retired |
| 409 `SENSOR_ALREADY_INSTALLED` | Sensor masih terpasang di device/channel lain |
| 409 `CHANNEL_OCCUPIED` | Channel sudah berisi sensor lain |
| 422 `SENSOR_TYPE_MISMATCH` | Tipe sensor ≠ tipe channel |

Memindahkan sensor = lepas dari device lama, lalu pasang di device baru (dua langkah, tercatat di riwayat).

### 8.8 `DELETE /api/v1/devices/{id}/sensors/{sensor_id}` — lepas sensor

| Field body | Tipe | Wajib | Keterangan |
|---|---|---|---|
| `removed_at` | datetime | tidak | Default sekarang; harus > `installed_at` |
| `reason` | string | tidak | Default `Dilepas` |

Response 200: `{ "installation_id", "removed_at", "removal_reason" }`. 404 jika sensor tidak sedang terpasang di device itu.

### 8.9 `GET /api/v1/sensors/{id}/calibrations`

Offset pagination, urut `effective_from` terbaru. Item: `id`, `scale_factor`, `offset_value`, `effective_from`, `note`, `created_at`.

### 8.10 `POST /api/v1/sensors/{id}/calibrations`

| Field | Tipe | Wajib | Keterangan |
|---|---|---|---|
| `scale_factor` | number ≠ 0 | ya | `value = raw × scale_factor + offset_value` |
| `offset_value` | number | ya | |
| `effective_from` | datetime | tidak | Default sekarang. Tidak boleh < sekarang − 5 menit (`BACKDATED_NOT_SUPPORTED`) |
| `note` | string ≤ 255 | tidak | |

Kalibrasi diterapkan saat ingestion berdasarkan **device time** bacaan; `raw_value` selalu disimpan.

---

## 9. Query data

### 9.1 `GET /api/v1/devices/{id}/readings/latest`

Nilai valid terbaru tiap channel (7 hari ke belakang, `quality_flags = 0`).

```json
{
  "data": [
    { "channel_id": 11, "channel_key": "temp_air", "label": "Suhu Udara", "sensor_type": "temp_air",
      "unit": "°C", "precision": 1, "value": 26.1, "raw_value": 26.4,
      "time": "2026-10-07T02:59:00Z", "sensor": { "id": 1, "serial_number": "TA-0001" } },
    { "channel_id": 16, "channel_key": "rain_counter", "label": "Curah Hujan (Tipping Bucket)", "sensor_type": "rain_counter",
      "unit": "tip", "precision": 0, "value": 42.0, "raw_value": 42.0,
      "time": "2026-10-07T02:59:00Z", "sensor": { "id": 6, "serial_number": "RC-0001" },
      "rain_today_mm": 3.4, "rain_today_unit": "mm" }
  ],
  "request_id": "..."
}
```

Channel `counter` mendapat `rain_today_mm` (akumulasi sejak 00:00 WIB). Channel tanpa data: `value`, `raw_value`, `time` = `null`.

### 9.2 `GET /api/v1/readings`

| Param | Wajib | Keterangan |
|---|---|---|
| `device_id` | ya | ID device |
| `sensor_type` | ya | Kode tipe, dipisah koma, maks 4 (mis. `temp_air,humidity`). Mengembalikan **semua channel** bertipe itu |
| `from`, `to` | tidak | ISO 8601. Default `to` = sekarang, `from` = `to` − 24 jam |
| `interval` | tidak | `raw` \| `1m` \| `1h` \| `1d`. Kosong → dipilih otomatis (interval terkecil yang ≤ 2000 titik) |
| `agg` | tidak | `avg` \| `min` \| `max` \| `sum`. Default `default_agg` tipe. `sum` hanya untuk counter (selain itu 422 `INVALID_AGGREGATION`). Channel counter selalu `sum` |
| `include_flagged` | tidak | Default `false`. Hanya berlaku untuk `interval=raw`; menambah `quality_flags[]` per seri |
| `cursor` | tidak | Epoch ms dari `meta.next_cursor` |
| `limit` | tidak | 1–2000 titik per halaman |

Batas: `ceil((to − from) / interval_seconds) ≤ 2000`. Melebihi → 422 `RANGE_TOO_LARGE` dengan `details[0].suggested_interval`. Akibatnya `raw`/`1m` ≤ ±33 jam, `1h` ≤ ±83 hari, `1d` ≤ ±5 tahun.

Sumber data: `raw` & `1m` dari `sensor_readings`; `1h` & `1d` dari `reading_aggregates` (hari WIB untuk `1d`).

Contoh lengkap + penjelasan: [§11.4](#114-time-series-readings).

### 9.3 `GET /api/v1/readings/summary`

| Param | Wajib | Keterangan |
|---|---|---|
| `device_id` | ya | |
| `from`, `to` | ya | `Y-m-d`, tanggal kalender WIB (inklusif) |
| `page`, `per_page` | tidak | `per_page` default 31, maks 100 |

```json
{
  "data": [
    { "date": "2026-10-01",
      "temp_air": { "min": 21.3, "max": 30.8, "avg": 25.6 },
      "humidity": { "avg": 80.1 },
      "rain_mm": 12.4,
      "wind_speed_max": 7.9,
      "coverage": 0.981 }
  ],
  "meta": { "page": 1, "per_page": 31, "total": 7, "last_page": 1, "timezone": "Asia/Jakarta" },
  "request_id": "..."
}
```

| Field | Keterangan |
|---|---|
| `date` | Tanggal WIB |
| `temp_air` | Min/max/avg suhu harian; `null` jika tidak ada data |
| `humidity.avg` | Rata-rata kelembapan |
| `rain_mm` | Total hujan hari itu (mm), dari selisih counter dengan deteksi reset |
| `wind_speed_max` | Kecepatan angin maksimum |
| `coverage` | `good_count / 1440` untuk suhu; < 1 berarti data hari itu tidak lengkap |

---

## 10. Dashboard & health

### 10.1 `GET /api/v1/dashboard/overview`

Tidak dipaginasi; maks 200 device non-decommissioned.

```json
{
  "data": {
    "totals": { "devices": 4, "active": 3, "maintenance": 0, "provisioned": 1,
                "online": 2, "stale": 0, "offline": 1, "never": 1 },
    "stations": [
      { "id": 1, "code": "WS-GRT-001", "name": "Stasiun Garut", "location": "Garut",
        "status": "active", "connectivity": "online", "last_seen_at": "2026-10-07T02:59:12Z",
        "latest": {
          "temp_air": { "value": 22.4, "unit": "°C", "time": "2026-10-07T02:59:00Z" },
          "humidity": { "value": 88.0, "unit": "%", "time": "2026-10-07T02:59:00Z" }
        } }
    ],
    "generated_at": "2026-10-07T03:00:00Z"
  },
  "request_id": "..."
}
```

`latest` dikunci per `channel_key`, hanya bacaan valid 24 jam terakhir; `{}` jika tidak ada.

### 10.2 `GET /healthz`

```json
{ "data": { "status": "ok", "db": "ok", "redis": "ok" }, "request_id": "..." }
```

200 jika semua `ok`; 503 dengan `status: "degraded"` jika salah satu `fail`.

---

## 11. Contoh JSON Bagian F

Setiap contoh disertai tabel penjelasan field. Nilai waktu memakai contoh `ts = 1791342000` (= `2026-10-07T03:00:00Z`).

### 11.1 Ingestion

#### 11.1.1 Telemetry sukses (201)

Request:

```json
{
  "device_id": "WS-GRT-001",
  "fw": "1.4.2",
  "ts": 1791342000,
  "seq": 10432,
  "battery_v": 3.92,
  "rssi": -71,
  "readings": [
    { "s": "temp_air", "v": 26.4 },
    { "s": "humidity", "v": 78.2 },
    { "s": "pressure", "v": 1009.8 },
    { "s": "wind_speed", "v": 3.1 },
    { "s": "wind_dir", "v": 135 },
    { "s": "rain_counter", "v": 1043 },
    { "s": "solar_rad", "v": 512.3 }
  ]
}
```

Response `201 Created`:

```json
{
  "data": {
    "status": "accepted",
    "packet": { "ts": 1791342000, "seq": 10432 },
    "readings": { "stored": 7, "flagged": 0, "skipped": 0 },
    "warnings": [],
    "server_time": "2026-10-07T03:00:01Z"
  },
  "request_id": "01J9ZB1Q6N8E4V3K2T5R7W9XYZ"
}
```

| Field | Tipe | Penjelasan |
|---|---|---|
| `data.status` | string | `accepted` = paket baru tersimpan; `duplicate` = paket dengan `(device_id, ts)` sama sudah ada, tidak ada yang ditulis ulang |
| `data.packet.ts` | integer | `ts` paket (epoch detik), di-echo untuk dicocokkan firmware dengan buffer |
| `data.packet.seq` | integer | `seq` paket |
| `data.readings.stored` | integer | Jumlah bacaan yang ditulis ke `sensor_readings` (termasuk yang berflag) |
| `data.readings.flagged` | integer | Bagian dari `stored` yang punya quality flag (`OUT_OF_RANGE`, `SENSOR_ERROR`, `CLOCK_FUTURE`, `MAINTENANCE`) |
| `data.readings.skipped` | integer | Bacaan yang tidak disimpan karena `s` tidak punya channel / sensor terpasang |
| `data.warnings` | array | Peringatan non-fatal (`code`, `message`), lihat §1.6 |
| `data.server_time` | string | Waktu server UTC, untuk mendeteksi drift jam device |
| `request_id` | string | ID request untuk penelusuran log |

#### 11.1.2 Telemetry diterima dengan warning (201) & duplikat (200)

Kasus `temp_air = -999` (kode error) dan `humidity = 150` (di luar rentang):

```json
{
  "data": {
    "status": "accepted",
    "packet": { "ts": 1791342060, "seq": 10433 },
    "readings": { "stored": 2, "flagged": 2, "skipped": 0 },
    "warnings": [
      { "code": "SENSOR_ERROR_CODE", "message": "Sensor 'temp_air' mengirim kode error -999." },
      { "code": "OUT_OF_RANGE", "message": "Nilai 'humidity' = 150 di luar rentang 0..100." }
    ],
    "server_time": "2026-10-07T03:01:01Z"
  },
  "request_id": "01J9ZB2A0C3D4E5F6G7H8J9K0L"
}
```

| Field | Penjelasan |
|---|---|
| `readings.flagged = 2` | Kedua bacaan tetap disimpan (raw tidak hilang) tetapi ditandai, tidak ikut agregat |
| `warnings[].code` | `SENSOR_ERROR_CODE` → `value = null`, flag `SENSOR_ERROR`; `OUT_OF_RANGE` → flag `OUT_OF_RANGE` |
| `warnings[].message` | Penjelasan untuk manusia |

Payload yang sama dikirim ulang → `200 OK`:

```json
{
  "data": {
    "status": "duplicate",
    "packet": { "ts": 1791342060, "seq": 10433 },
    "readings": { "stored": 0, "flagged": 0, "skipped": 0 },
    "warnings": [],
    "server_time": "2026-10-07T03:01:05Z"
  },
  "request_id": "01J9ZB2B..."
}
```

`stored = 0` karena tidak ada yang ditulis; device tetap boleh menghapus paket dari buffer.

#### 11.1.3 Batch sukses sebagian (207)

Batch 10 paket: 8 baru, 2 sudah pernah dikirim (index 3 dan 7).

Request (dipersingkat):

```json
{
  "device_id": "WS-GRT-001",
  "fw": "1.4.2",
  "batch": [
    { "ts": 1791342000, "seq": 10432, "battery_v": 3.90, "rssi": -72,
      "readings": [ { "s": "temp_air", "v": 25.9 }, { "s": "humidity", "v": 80.1 } ] },
    { "ts": 1791342060, "seq": 10433, "battery_v": 3.90, "rssi": -72,
      "readings": [ { "s": "temp_air", "v": 26.0 }, { "s": "humidity", "v": 79.8 } ] }
  ]
}
```

Response `207 Multi-Status`:

```json
{
  "data": {
    "summary": { "received": 10, "accepted": 8, "duplicate": 2, "rejected": 0 },
    "items": [
      { "index": 0, "ts": 1791342000, "seq": 10432, "status": "accepted", "readings_stored": 2, "readings_flagged": 0, "readings_skipped": 0, "warnings": [] },
      { "index": 1, "ts": 1791342060, "seq": 10433, "status": "accepted", "readings_stored": 2, "readings_flagged": 0, "readings_skipped": 0, "warnings": [] },
      { "index": 2, "ts": 1791342120, "seq": 10434, "status": "accepted", "readings_stored": 2, "readings_flagged": 0, "readings_skipped": 0, "warnings": [] },
      { "index": 3, "ts": 1791342180, "seq": 10435, "status": "duplicate", "warnings": [] },
      { "index": 4, "ts": 1791342240, "seq": 10436, "status": "accepted", "readings_stored": 2, "readings_flagged": 0, "readings_skipped": 0, "warnings": [] },
      { "index": 5, "ts": 1791342300, "seq": 10437, "status": "accepted", "readings_stored": 2, "readings_flagged": 0, "readings_skipped": 0, "warnings": [] },
      { "index": 6, "ts": 1791342360, "seq": 10438, "status": "accepted", "readings_stored": 2, "readings_flagged": 0, "readings_skipped": 0, "warnings": [] },
      { "index": 7, "ts": 1791342420, "seq": 10439, "status": "duplicate", "warnings": [] },
      { "index": 8, "ts": 1791342480, "seq": 10440, "status": "accepted", "readings_stored": 2, "readings_flagged": 0, "readings_skipped": 0, "warnings": [] },
      { "index": 9, "ts": 1791342540, "seq": 10441, "status": "accepted", "readings_stored": 2, "readings_flagged": 0, "readings_skipped": 0, "warnings": [] }
    ],
    "server_time": "2026-10-07T03:10:01Z"
  },
  "request_id": "01J9ZB3C..."
}
```

| Field | Tipe | Penjelasan |
|---|---|---|
| `summary.received` | integer | Jumlah item di `batch` |
| `summary.accepted` | integer | Paket baru yang tersimpan |
| `summary.duplicate` | integer | Paket yang sudah ada di DB atau `ts`-nya kembar di dalam batch yang sama |
| `summary.rejected` | integer | Item gagal validasi (tidak akan pernah diterima) |
| `items[]` | array | Satu entri per item kiriman, urut sesuai `index` |
| `items[].index` | integer | Posisi item di array `batch` (0-based) |
| `items[].ts`, `items[].seq` | integer | Di-echo dari item; firmware memakai `ts` untuk menghapus dari buffer |
| `items[].status` | string | `accepted` \| `duplicate` \| `rejected` |
| `items[].readings_stored` / `_flagged` / `_skipped` | integer | Hanya pada `accepted`; arti sama dengan §11.1.1 |
| `items[].warnings` | array | Warning per item; pada `duplicate` bisa berisi `TS_COLLISION` |
| `items[].error` | object | Hanya pada `rejected`: `code`, `message`, `details[]` |
| `server_time` | string | Waktu server UTC |

Aturan status: semua accepted → 201, semua duplicate → 200, semua rejected → 422, campuran → 207.

Contoh item `rejected` di dalam batch (mis. `ts` dari jam RTC 1970):

```json
{
  "index": 4, "ts": 3600, "seq": 12, "status": "rejected",
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "Item validasi gagal.",
    "details": [
      { "field": "batch.4.ts", "code": "TIMESTAMP_INVALID", "message": "The ts field must be at least 1577836800." }
    ]
  }
}
```

#### 11.1.4 Gagal validasi (422)

Request telemetry dengan `ts` tidak tersinkron dan nilai bukan angka:

```json
{
  "device_id": "WS-GRT-001",
  "fw": "1.4.2",
  "ts": 3600,
  "seq": 1,
  "readings": [ { "s": "temp_air", "v": 26.4 }, { "s": "humidity", "v": "abc" } ]
}
```

Response `422 Unprocessable Entity`:

```json
{
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "Payload tidak valid.",
    "details": [
      { "field": "ts", "code": "TIMESTAMP_INVALID", "message": "The ts field must be at least 1577836800." },
      { "field": "readings.1.v", "code": "NOT_NUMERIC", "message": "The readings.1.v field must be a number." }
    ]
  },
  "request_id": "01J9ZB4D..."
}
```

| Field | Penjelasan |
|---|---|
| `error.code` | `VALIDATION_FAILED` = struktur/tipe payload salah |
| `error.details[].field` | Path field dot-notation; `readings.1.v` = nilai bacaan ke-2 |
| `error.details[].code` | Kode per field: `TIMESTAMP_INVALID` (ts < 2020-01-01), `NOT_NUMERIC` (bukan angka) |
| `error.details[].message` | Pesan validator |

Batch > 500 item:

```json
{
  "error": { "code": "BATCH_TOO_LARGE", "message": "Batch terlalu besar, maksimum 500 item per request.", "details": [] },
  "request_id": "01J9ZB5E..."
}
```

### 11.2 Device CRUD

#### 11.2.1 Create — `POST /api/v1/devices` (201)

Request:

```json
{
  "code": "WS-SMG-001",
  "name": "Stasiun Semarang Pelabuhan",
  "location_id": 5,
  "create_default_channels": true
}
```

Response:

```json
{
  "data": {
    "id": 5,
    "code": "WS-SMG-001",
    "name": "Stasiun Semarang Pelabuhan",
    "status": "provisioned",
    "location": { "id": 5, "code": "SMG", "name": "Semarang", "latitude": -6.9667, "longitude": 110.4167, "altitude_m": 3.0 },
    "credential": {
      "api_key": "wsk_Q2x9aB7cK1mN4pR8sT2vW6yZ0dF3gH5jL7nP9qS1",
      "key_prefix": "wsk_Q2x9aB7c",
      "notice": "Simpan sekarang, key tidak akan ditampilkan lagi."
    },
    "created_at": "2026-10-07T03:10:00Z"
  },
  "request_id": "01J9ZB6F..."
}
```

| Field | Tipe | Penjelasan |
|---|---|---|
| `id` | integer | ID internal, dipakai di URL manajemen (`/devices/5`) |
| `code` | string | Kode unik, dipakai device sebagai `device_id` saat ingestion |
| `name` | string | Nama tampilan |
| `status` | string | Selalu `provisioned` untuk device baru; berubah ke `active` saat telemetry valid pertama |
| `location` | object | Lokasi lengkap (`id`, `code`, `name`, `latitude`, `longitude`, `altitude_m`) |
| `credential.api_key` | string | API key plaintext, **hanya muncul sekali**; server hanya menyimpan HMAC-nya |
| `credential.key_prefix` | string | 12 karakter pertama untuk identifikasi key di log/UI |
| `credential.notice` | string | Pengingat untuk menyimpan key |
| `created_at` | string | Waktu registrasi (UTC) |

#### 11.2.2 List — `GET /api/v1/devices?status=active&page=1&per_page=20` (200)

```json
{
  "data": [
    {
      "id": 1,
      "code": "WS-GRT-001",
      "name": "Stasiun Garut",
      "status": "active",
      "connectivity": "online",
      "location": { "id": 1, "name": "Garut" },
      "last_seen_at": "2026-10-07T03:09:12Z",
      "firmware_version": "1.4.2"
    }
  ],
  "meta": { "page": 1, "per_page": 20, "total": 3, "last_page": 1 },
  "request_id": "01J9ZB7G..."
}
```

| Field | Penjelasan |
|---|---|
| `data[].status` | Status lifecycle (`provisioned`/`active`/`maintenance`/`decommissioned`) |
| `data[].connectivity` | Dihitung dari `last_seen_at`: `online` ≤ 5 menit, `stale` 5–15 menit, `offline` > 15 menit, `never` belum pernah kirim |
| `data[].location` | Ringkasan lokasi (`id`, `name`) |
| `data[].last_seen_at` | Waktu server saat request terakhir dari device diterima; `null` jika belum pernah |
| `data[].firmware_version` | Firmware terakhir yang dilaporkan |
| `meta.page` / `per_page` | Halaman sekarang & ukuran halaman |
| `meta.total` | Total device yang cocok dengan filter |
| `meta.last_page` | Nomor halaman terakhir |

#### 11.2.3 Detail — `GET /api/v1/devices/1` (200)

```json
{
  "data": {
    "id": 1,
    "code": "WS-GRT-001",
    "name": "Stasiun Garut",
    "status": "active",
    "allowed_transitions": ["maintenance", "decommissioned"],
    "connectivity": "online",
    "location": { "id": 1, "code": "GRT", "name": "Garut", "latitude": -7.2279, "longitude": 107.9087, "altitude_m": 717.0 },
    "health": {
      "last_seen_at": "2026-10-07T03:09:12Z",
      "last_reading_at": "2026-10-07T03:09:00Z",
      "battery_v": 3.92,
      "rssi": -71,
      "firmware_version": "1.4.2"
    },
    "channels": [
      {
        "id": 11,
        "channel_key": "temp_air",
        "label": "Suhu Udara",
        "sensor_type": { "code": "temp_air", "unit": "°C" },
        "current_sensor": { "id": 1, "serial_number": "TA-0001", "installed_at": "2026-09-30T00:00:00Z" }
      },
      {
        "id": 17,
        "channel_key": "solar_rad",
        "label": "Radiasi Matahari",
        "sensor_type": { "code": "solar_rad", "unit": "W/m²" },
        "current_sensor": null
      }
    ],
    "created_at": "2026-09-30T00:00:00Z",
    "updated_at": "2026-10-07T03:09:12Z"
  },
  "request_id": "01J9ZB8H..."
}
```

| Field | Penjelasan |
|---|---|
| `allowed_transitions` | Status tujuan yang sah dari status sekarang (dipakai UI untuk menampilkan tombol) |
| `health.last_seen_at` | Waktu server request terakhir |
| `health.last_reading_at` | Device time bacaan terbaru yang valid |
| `health.battery_v`, `rssi`, `firmware_version` | Nilai terakhir yang dilaporkan |
| `channels[]` | Slot pengukuran logis di device |
| `channels[].id` | ID channel; seri time-series terikat ke channel, bukan ke sensor fisik |
| `channels[].channel_key` | Nilai `s` di payload ingestion |
| `channels[].label` | Label tampilan |
| `channels[].sensor_type` | Kode & satuan tipe sensor channel |
| `channels[].current_sensor` | Sensor fisik yang sedang terpasang; `null` = kosong (bacaan untuk channel ini akan di-skip dengan `CHANNEL_NOT_MAPPED`) |

#### 11.2.4 Update status — `PATCH /api/v1/devices/1`

Request:

```json
{ "status": "maintenance", "status_reason": "Penggantian sensor kelembapan" }
```

Response 200 = detail device (§11.2.3) dengan `status: "maintenance"` dan `allowed_transitions: ["active", "decommissioned"]`.

### 11.3 Sensor, pemasangan & kalibrasi

#### 11.3.1 Create sensor — `POST /api/v1/sensors` (201)

Request:

```json
{ "serial_number": "TA-0101", "sensor_type": "temp_air", "manufacturer": "Sensirion", "model": "SHT45", "notes": "Stok gudang Bandung" }
```

Response:

```json
{
  "data": { "id": 101, "serial_number": "TA-0101", "sensor_type": "temp_air" },
  "request_id": "01J9ZB9J..."
}
```

| Field | Penjelasan |
|---|---|
| `id` | ID sensor fisik |
| `serial_number` | Nomor seri unik dari pabrik |
| `sensor_type` | Kode tipe; menentukan channel mana yang boleh dipasangi |

#### 11.3.2 Pasang sensor — `POST /api/v1/devices/1/sensors` (201)

Request:

```json
{ "sensor_id": 101, "channel_key": "temp_air", "installed_at": "2026-10-07T02:00:00Z" }
```

Response:

```json
{
  "data": {
    "installation_id": 77,
    "sensor": { "id": 101, "serial_number": "TA-0101", "sensor_type": "temp_air" },
    "device": { "id": 1, "code": "WS-GRT-001" },
    "channel": { "id": 11, "channel_key": "temp_air" },
    "installed_at": "2026-10-07T02:00:00Z",
    "removed_at": null
  },
  "request_id": "01J9ZBAK..."
}
```

| Field | Penjelasan |
|---|---|
| `installation_id` | ID riwayat pemasangan |
| `sensor` | Sensor fisik yang dipasang |
| `device` | Device tujuan |
| `channel` | Channel yang diisi (dibuat otomatis jika belum ada) |
| `installed_at` | Mulai berlaku; bacaan dengan device time ≥ nilai ini dikaitkan ke sensor ini |
| `removed_at` | `null` = masih terpasang |

Contoh konflik (channel sudah berisi sensor lain) — `409`:

```json
{
  "error": { "code": "CHANNEL_OCCUPIED", "message": "Channel 'temp_air' sudah terisi sensor lain.", "details": [] },
  "request_id": "01J9ZBBL..."
}
```

#### 11.3.3 Lepas sensor — `DELETE /api/v1/devices/1/sensors/101` (200)

Request:

```json
{ "reason": "Dilepas untuk kalibrasi" }
```

Response:

```json
{
  "data": { "installation_id": 77, "removed_at": "2026-10-07T04:00:00Z", "removal_reason": "Dilepas untuk kalibrasi" },
  "request_id": "01J9ZBCM..."
}
```

#### 11.3.4 Tambah kalibrasi — `POST /api/v1/sensors/101/calibrations` (201)

Request:

```json
{ "scale_factor": 1.0, "offset_value": -0.3, "effective_from": "2026-10-07T03:00:00Z", "note": "Dibandingkan dengan termometer referensi" }
```

Response:

```json
{
  "data": {
    "id": 12,
    "scale_factor": 1.0,
    "offset_value": -0.3,
    "effective_from": "2026-10-07T03:00:00Z",
    "note": "Dibandingkan dengan termometer referensi",
    "preview": { "raw": 27.4, "value": 27.1 }
  },
  "request_id": "01J9ZBDN..."
}
```

| Field | Penjelasan |
|---|---|
| `id` | ID kalibrasi |
| `scale_factor` | Pengali; `value = raw × scale_factor + offset_value` |
| `offset_value` | Koreksi tambahan dalam satuan sensor |
| `effective_from` | Berlaku untuk bacaan dengan device time ≥ nilai ini; bacaan lama tidak diubah |
| `note` | Catatan teknisi |
| `preview` | Contoh penerapan pada `raw_value` valid terakhir sensor ini; `null` jika sensor belum punya bacaan |

Kalibrasi mundur ditolak — `422`:

```json
{
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "Kalibrasi mundur belum didukung.",
    "details": [ { "field": "effective_from", "code": "BACKDATED_NOT_SUPPORTED", "message": "effective_from harus >= sekarang - 5 menit." } ]
  },
  "request_id": "01J9ZBEP..."
}
```

### 11.4 Time-series `/readings`

Request:

```
GET /api/v1/readings?device_id=1&sensor_type=temp_air,humidity&from=2026-10-07T00:00:00Z&to=2026-10-07T03:00:00Z&interval=1h&agg=avg
```

Response `200`:

```json
{
  "data": {
    "device_id": 1,
    "interval": "1h",
    "agg": "avg",
    "timestamps": [1791331200000, 1791334800000, 1791338400000],
    "series": [
      {
        "channel_id": 11,
        "channel_key": "temp_air",
        "label": "Suhu Udara",
        "sensor_type": "temp_air",
        "unit": "°C",
        "precision": 1,
        "agg": "avg",
        "values": [26.1, 27.4, null]
      },
      {
        "channel_id": 12,
        "channel_key": "humidity",
        "label": "Kelembapan Relatif",
        "sensor_type": "humidity",
        "unit": "%",
        "precision": 1,
        "agg": "avg",
        "values": [84.0, 81.2, 79.5]
      }
    ]
  },
  "meta": {
    "from": "2026-10-07T00:00:00Z",
    "to": "2026-10-07T03:00:00Z",
    "interval_seconds": 3600,
    "points": 3,
    "max_points": 2000,
    "next_cursor": null
  },
  "request_id": "01J9ZBFQ..."
}
```

| Field | Tipe | Penjelasan |
|---|---|---|
| `data.device_id` | integer | Device yang di-query |
| `data.interval` | string | Interval efektif (`raw`/`1m`/`1h`/`1d`); terisi otomatis jika param kosong |
| `data.agg` | string | Agregasi yang diminta; `default` jika param kosong (tiap seri memakai `default_agg` tipenya) |
| `data.timestamps` | int[] | Awal bucket dalam **epoch milidetik** UTC, dipakai bersama oleh semua seri |
| `data.series[]` | array | Satu seri per channel yang cocok dengan `sensor_type` |
| `series[].channel_id` / `channel_key` / `label` | – | Identitas channel; dua sensor suhu di satu device = dua seri |
| `series[].sensor_type` | string | Kode tipe |
| `series[].unit` | string | Satuan; untuk `rain_counter` = `mm` (sudah dikonversi dari tip) |
| `series[].precision` | integer | Digit desimal; nilai sudah dibulatkan |
| `series[].agg` | string | Agregasi efektif seri ini (counter selalu `sum`) |
| `series[].values` | (number\|null)[] | Sejajar dengan `timestamps`; `null` = channel ini tidak punya data valid di titik itu |
| `series[].quality_flags` | int[] | Hanya jika `interval=raw&include_flagged=true` |
| `meta.from` / `meta.to` | string | Rentang efektif (UTC) |
| `meta.interval_seconds` | integer | Lebar bucket; frontend menyisipkan gap jika jarak antar titik > 1,5× nilai ini |
| `meta.points` | integer | Jumlah titik di halaman ini |
| `meta.max_points` | integer | Batas titik per seri (2000) |
| `meta.next_cursor` | integer\|null | Epoch ms untuk halaman berikutnya (`?cursor=`); `null` = data habis |

Format kolom dipilih karena tidak mengulang nama field di tiap titik (± 2–3× lebih kecil dari array of object) dan langsung cocok untuk dataset chart. Backend tidak mengisi gap; hanya titik yang ada datanya yang dikembalikan.

Curah hujan per jam (`sensor_type=rain_counter&interval=1h&agg=sum`):

```json
{
  "data": {
    "device_id": 1, "interval": "1h", "agg": "sum",
    "timestamps": [1791331200000, 1791334800000],
    "series": [
      { "channel_id": 16, "channel_key": "rain_counter", "label": "Curah Hujan (Tipping Bucket)",
        "sensor_type": "rain_counter", "unit": "mm", "precision": 2, "agg": "sum",
        "values": [0.0, 1.2] }
    ]
  },
  "meta": { "from": "...", "to": "...", "interval_seconds": 3600, "points": 2, "max_points": 2000, "next_cursor": null },
  "request_id": "..."
}
```

`values` = mm per jam, dihitung dari selisih counter dengan deteksi reset (counter turun atau `seq` turun → selisih = nilai baru). Tidak pernah negatif.

Rentang terlalu besar (`interval=raw`, 7 hari) — `422`:

```json
{
  "error": {
    "code": "RANGE_TOO_LARGE",
    "message": "Rentang terlalu besar untuk interval raw (10080 titik, maks 2000).",
    "details": [
      { "field": "interval", "code": "RANGE_TOO_LARGE", "message": "Gunakan interval 1h atau perkecil rentang.", "suggested_interval": "1h" }
    ]
  },
  "request_id": "..."
}
```

### 11.5 Format error standar

Semua error memakai bentuk yang sama:

```json
{
  "error": {
    "code": "INVALID_STATUS_TRANSITION",
    "message": "Transisi status decommissioned → active tidak diizinkan.",
    "details": [
      {
        "field": "status",
        "code": "INVALID_STATUS_TRANSITION",
        "message": "Transisi yang diizinkan: -",
        "allowed_transitions": []
      }
    ]
  },
  "request_id": "01J9ZBGR..."
}
```

| Field | Tipe | Penjelasan |
|---|---|---|
| `error` | object | Ada hanya pada response gagal; tidak ada `data` |
| `error.code` | string | Kode stabil `UPPER_SNAKE` untuk logika client (katalog §1.6) |
| `error.message` | string | Pesan untuk manusia; boleh berubah, jangan di-parse |
| `error.details` | array | Detail per field; `[]` jika tidak relevan |
| `error.details[].field` | string | Path field (dot-notation, mis. `batch.4.ts`) |
| `error.details[].code` | string | Kode per field (`REQUIRED`, `NOT_NUMERIC`, `OUT_OF_BOUNDS`, ...) |
| `error.details[].message` | string | Pesan per field |
| `error.details[].*` | – | Field tambahan kontekstual: `allowed_transitions`, `suggested_interval` |
| `request_id` | string | Sama dengan header `X-Request-Id`; sertakan saat melapor bug |

Contoh lain:

`401` — credential device salah / device tidak terdaftar:

```json
{ "error": { "code": "INVALID_DEVICE_CREDENTIALS", "message": "Credential device tidak valid.", "details": [] }, "request_id": "..." }
```

`404`:

```json
{ "error": { "code": "NOT_FOUND", "message": "Resource tidak ditemukan.", "details": [] }, "request_id": "..." }
```

`429` (header `Retry-After: 12`):

```json
{ "error": { "code": "RATE_LIMITED", "message": "Terlalu banyak request, coba lagi dalam 12 detik.", "details": [] }, "request_id": "..." }
```

`503` (header `Retry-After: 30`; device tetap menyimpan buffer dan mengirim ulang):

```json
{ "error": { "code": "SERVICE_UNAVAILABLE", "message": "Layanan sementara tidak tersedia.", "details": [] }, "request_id": "..." }
```

`500` (detail hanya di log, cari dengan `request_id`):

```json
{ "error": { "code": "INTERNAL_ERROR", "message": "Terjadi kesalahan internal.", "details": [] }, "request_id": "..." }
```

---

## 12. Koleksi Bruno

Lokasi: `docs/bruno/Luwes Weather Station API/` (format YAML OpenCollection, Bruno ≥ 3.1).

1. Buka Bruno → *Open Collection* → pilih folder di atas.
2. Pilih environment `Local`, sesuaikan `baseUrl` (default `http://localhost:18080`; ganti ke `http://localhost:8080` jika memakai port default).
3. Isi `deviceApiKey` (secret) dengan key seed dari `SEED_DEVICE_KEYS`, atau jalankan `Devices/Create Device` / `Rotate Credential` — key otomatis disimpan ke env.

Variabel env: `baseUrl`, `apiUrl` (`{{baseUrl}}/api/v1`), `deviceCode`, `deviceApiKey`, `deviceId`, `locationId`, `sensorId`, `sensorTypeId`. Request `Create *` mengisi variabel ID secara otomatis lewat script `after-response`. Request ingestion mengisi `ts`/`seq` dari waktu sekarang lewat script `before-request`.

Menjalankan semua test lewat CLI:

```bash
npm install -g @usebruno/cli
cd "docs/bruno/Luwes Weather Station API"
bru run --env Local
```
