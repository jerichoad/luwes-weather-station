# Device Simulator

Node.js 20 (fetch bawaan, tanpa dependency runtime). Mensimulasikan device stasiun cuaca yang mengirim ke `POST /api/v1/ingest/telemetry`, `/telemetry/batch`, `/heartbeat`.

## Jalankan

```bash
cd simulator
cp .env.example .env
npm start
```

Atau via Docker Compose (service `simulator` di `docker-compose.yml` root):

```bash
docker compose run --rm simulator
docker compose run --rm -e SIM_SCENARIO=offline -e SIM_OFFLINE_MINUTES=180 simulator
```

## Env

| Var | Default | Keterangan |
|---|---|---|
| `API_URL` | `http://localhost:8080` | base URL backend |
| `SIM_DEVICES` | — | format `KODE=key;KODE=key`, sama dengan `SEED_DEVICE_KEYS` |
| `SIM_SCENARIO` | `normal` | lihat tabel di bawah |
| `SIM_TARGET` | device pertama | device yang dipakai skenario single-shot |
| `SIM_INTERVAL_S` | `60` | interval kirim telemetry |
| `SIM_HEARTBEAT_S` | `300` | interval heartbeat (skenario `normal`) |
| `SIM_OFFLINE_MINUTES` | `180` | lama offline sebelum flush batch |
| `SIM_OFFLINE_REALTIME` | `false` | `true` = benar-benar tunggu N menit; `false` = langsung isi buffer lalu flush |
| `SIM_UNKNOWN_DEVICE` | `WS-XXX-999` | device_id dipakai skenario `unknown-device` |
| `SIM_BUFFER_MAX` | `20000` | kapasitas buffer per device sebelum item tertua dibuang |
| `SIM_BATCH_MAX` | `500` | ukuran batch per request saat flush |
| `SIM_VERBOSE` | `false` | log debug tambahan |

## Skenario (`SIM_SCENARIO`)

| Skenario | Perilaku |
|---|---|
| `normal` | Semua device di `SIM_DEVICES` berjalan paralel: 1 paket/interval + heartbeat tiap `SIM_HEARTBEAT_S` |
| `offline` | `SIM_TARGET` diam `SIM_OFFLINE_MINUTES` menit sambil buffer, lalu kirim `/batch` dipecah per `SIM_BATCH_MAX` |
| `duplicate` | Payload identik dikirim 3× ke `/telemetry` |
| `restart` | Kirim paket `seq` besar, lalu reboot (`seq`/`rain_counter` → 0) dan kirim lagi |
| `clock-future` | `ts` + 2 jam |
| `invalid` | `temp_air=-999`, `humidity=150`, `solar_rad` dihilangkan |
| `unknown-device` | `device_id` yang tidak terdaftar |
| `big-batch` | Kirim batch 500 (harus diterima) lalu 501 (harus `422 BATCH_TOO_LARGE`) |

Semua skenario selain `normal` berjalan sekali lalu proses keluar (exit code 0), cocok untuk `docker compose run --rm`.

## Retry & buffer

Response non-2xx (kecuali 400/401/403/413/422 yang dianggap permanen) membuat paket tetap di buffer dan dikirim ulang dengan backoff eksponensial (basis 2s, maks 300s), menghormati header `Retry-After` jika ada. Item dengan status `accepted`/`duplicate` dari response dihapus dari buffer; `rejected` dibuang dan dicatat sebagai warning.
