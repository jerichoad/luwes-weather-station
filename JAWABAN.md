# JAWABAN.md: Lembar Jawaban Desain & Esai Teknis

Tes Teknis Fullstack Developer, Platform Monitoring Stasiun Cuaca (PT Luwes Inovasi Mandiri )   
Nama: Jericho Alfa Dio  

---

## Bagian 1: Jawaban Pertanyaan Desain (A–G)

### BAGIAN A: Device Management

#### A.1 Apa yang terjadi pada data historis ketika sebuah device di-decommission? Kenapa Anda memilih pendekatan itu?
Data historis di `device_packets`, `sensor_readings`, dan `reading_aggregates` tetap utuh. Tidak ada yang dihapus atau dipindahkan. `DeviceService::transition()` hanya mengubah metadata operasional dalam satu transaksi:
1. Status device menjadi `decommissioned`, kolom `decommissioned_at` terisi, dan transisinya tercatat di `device_status_history`.
2. Semua kredensial aktif di `device_credentials` dicabut (`revoked_at = now()`). Request berikutnya dari device itu ditolak `AuthenticateDevice` dengan HTTP 403 `DEVICE_DECOMMISSIONED`.
3. Pemasangan sensor yang masih aktif di channel device ditutup (`removed_at = now()`, `removal_reason = 'device_decommissioned'`).

Alasannya:
- Data pengukuran adalah catatan kejadian di masa lalu. Nilainya untuk analisis jangka panjang tidak hilang hanya karena perangkatnya pensiun.
- Menghapus data mentah akan membuat agregat harian dan bulanan yang sudah tersimpan tidak bisa diaudit lagi, dan `DELETE` besar memicu beban `VACUUM` di PostgreSQL.
- Data fisik hanya dibuang lewat retention policy TimescaleDB yang berlaku global (chunk `sensor_readings` di-drop setelah 2 tahun), bukan karena siklus hidup satu perangkat.

#### A.2 Bagaimana Anda membedakan "device mati" dengan "device hidup tapi jaringan putus"?
Selama device diam, server tidak bisa membedakan keduanya. Dashboard menandai keduanya `offline` jika `now() - last_seen_at` melewati 15 menit (`connectivity.offline_s = 900`). Perbedaannya baru terlihat saat device tersambung lagi:

| Bukti | Device mati (listrik habis / hardware rusak) | Device hidup, jaringan putus |
|---|---|---|
| Pola data | Data live dimulai lagi sejak device menyala. Periode mati meninggalkan gap permanen karena sensor tidak membaca apa pun. | Device mengirim batch besar dari buffer lokal yang menambal seluruh periode offline. |
| Nilai `seq` | Kembali ke angka kecil karena MCU reboot. | Melanjutkan urutan sebelum offline. |
| `uptime_s` di heartbeat | Kecil (detik atau menit), dan `last_boot_at` bergeser ke waktu baru. | Tetap besar dan bertambah sesuai lama device hidup. |
| Tren baterai | Tegangan biasanya turun tajam menjelang padam. | Tegangan berubah wajar mengikuti siklus panel surya. |

---

### BAGIAN B: Sensor Management

#### B.1 Sensor suhu pada device A dipindah ke device B pada 1 Juni. Bagaimana skema Anda menjamin data sebelum 1 Juni tetap terhubung ke device A?
Kuncinya ada di tabel `device_channels`:
- Payload device menyebut `"s": "temp_air"`. String ini dipetakan ke titik ukur logis di stasiun (`device_channels`), bukan ke ID fisik sensor.
- Hubungan sensor fisik dengan channel dicatat di `sensor_installations` dengan rentang `installed_at` sampai `removed_at`. Constraint `EXCLUDE USING gist` mencegah satu sensor terpasang di dua channel pada waktu yang tumpang tindih.
- Setiap baris di `sensor_readings` menyimpan `channel_id`, `device_id`, dan `sensor_id` sesuai keadaan saat pembacaan terjadi.
- Saat ingest, `DeviceContext` mencari sensor yang terpasang berdasarkan waktu device (`time`), bukan waktu request tiba di server:
  $$\text{installed\_at} \le \text{device\_time} < \text{COALESCE}(\text{removed\_at}, \infty)$$

Contoh: sensor dilepas dari device A (`removed_at = '2026-06-01 00:00:00Z'`) lalu dipasang di device B (`installed_at = '2026-06-01 00:00:00Z'`).
1. Semua pembacaan sebelum 1 Juni tetap menunjuk `channel_id` milik device A.
2. Jika device A offline di akhir Mei dan baru mengirim data buffer tanggal 3 Juni, paket bertanggal 30 Mei tetap masuk ke device A karena waktu device-nya jatuh sebelum sensor dicabut.

#### B.2 Nilai kalibrasi diubah hari ini. Apakah data lama ikut berubah? Jelaskan konsekuensi dari pilihan Anda.
Tidak. Data lama tidak berubah otomatis.
- Kalibrasi disimpan di tabel append-only `sensor_calibrations` dengan kolom `effective_from`.
- `SensorCalibrationController` menolak `effective_from` yang lebih tua dari 5 menit lalu, jadi kalibrasi tidak bisa berlaku mundur.
- Saat ingest, `raw_value` disimpan apa adanya, dan kolom `value` dihitung memakai kalibrasi yang berlaku pada waktu device:
  $$\text{value} = \text{raw\_value} \times \text{scale\_factor} + \text{offset\_value}$$

Konsekuensinya:
- Keuntungan: query baca dan agregasi tidak perlu join ke riwayat kalibrasi untuk ratusan juta baris. Agregat yang sudah tersimpan di `reading_aggregates` tetap konsisten.
- Kerugian: jika kalibrasi baru dibuat untuk mengoreksi sensor yang ternyata sudah drift sejak minggu lalu, koreksinya tidak sampai ke data lama. Perbaikannya butuh job hitung ulang terpisah yang membaca `raw_value`, menerapkan kalibrasi baru, lalu memasukkan bucket yang terdampak ke `aggregate_refresh_queue`. Job ini belum ada di repositori.

---

### BAGIAN C: ERD & Skala Pertumbuhan Data

#### C.1 Tabel mana yang akan tumbuh paling cepat? Perkirakan jumlah row per tahun jika ada 50 device × 7 sensor × 1 pembacaan/menit. Tunjukkan perhitungannya.
Tabel yang tumbuh paling cepat adalah `sensor_readings` (format narrow, satu baris per sensor per waktu).

Perhitungan:
- Jumlah device: $50$
- Frekuensi: $1\text{ paket / menit} = 60\text{ paket / jam}$
- Bacaan per paket: $7$
- Per menit: $50 \times 7 = 350\text{ row}$
- Per jam: $350 \times 60 = 21.000\text{ row}$
- Per hari: $21.000 \times 24 = 504.000\text{ row}$
- Per tahun (365 hari):
  $$504.000 \times 365 = 183.960.000\text{ row / tahun}\ (\approx 184\text{ juta})$$

Tabel lain sebagai pembanding:
- `device_packets`: $50 \times 60 \times 24 \times 365 = 26.280.000\text{ row / tahun}$
- `device_heartbeats` (tiap 5 menit): $50 \times 12 \times 24 \times 365 = 5.256.000\text{ row / tahun}$
- `reading_aggregates` bucket 1 jam: $50 \times 7 \times 24 \times 365 = 3.066.000\text{ row / tahun}$
- `reading_aggregates` bucket 1 hari: $50 \times 7 \times 365 = 127.750\text{ row / tahun}$

#### C.2 Strategi Anda menghadapi pertumbuhan itu: pilih satu dan jelaskan trade-off-nya.
Saya memilih hypertable TimescaleDB, lengkap dengan compression policy dan retention policy (migrasi `2026_10_06_101500_add_timescale_policies.php`):
1. Hypertable dengan chunk 7 hari. Satu chunk berisi sekitar $504.000 \times 7 \approx 3,5\text{ juta}$ baris. Query rentang pendek hanya membuka chunk yang relevan (chunk exclusion).
2. Chunk yang lebih tua dari 7 hari dikompresi ke format kolom dengan `compress_segmentby = 'channel_id'` dan `compress_orderby = 'time DESC'`. Data satu channel tersimpan berdampingan dan berurutan waktu, sehingga nilai yang mirip mudah dipadatkan. Rasio kompresi untuk dataset ini belum saya ukur.
3. Chunk mentah yang lebih tua dari 2 tahun dibuang lewat `add_retention_policy`. Membuang chunk berarti menghapus tabel anak, jadi tidak ada `DELETE` baris per baris dan tidak ada beban `VACUUM`.
4. Agregat 1 jam dan 1 hari disimpan di `reading_aggregates` tanpa retensi, sehingga analisis multi-tahun tetap bisa dilakukan setelah data per menit dibuang.

Trade-off:
- Data buffer yang datang sangat terlambat (lebih dari 7 hari) masuk ke chunk yang sudah terkompresi. TimescaleDB mendukung ini, tetapi biaya tulisnya lebih tinggi daripada menulis ke chunk aktif.
- Setelah 2 tahun, data mentah hilang. Jika rumus kalibrasi diperbaiki di kemudian hari, data setua itu tidak bisa dihitung ulang.

#### C.3 Apakah Anda menyimpan pembacaan dalam format wide (satu row berisi semua sensor) atau narrow/long (satu row per sensor)? Bandingkan keduanya dan pertahankan pilihan Anda.
Saya memakai format narrow: satu baris per sensor per waktu.

| Aspek | Wide (1 row = semua sensor) | Narrow (1 row = 1 sensor), dipilih |
|---|---|---|
| Tipe sensor baru | Butuh `ALTER TABLE ADD COLUMN` pada tabel ratusan juta baris. | Cukup tambah baris di `device_channels`. Struktur tabel tidak berubah. |
| Dua sensor bertipe sama (misalnya 2 suhu) | Harus menambah kolom seperti `temp_air_2`. | Tambah channel baru dengan `channel_key` berbeda bertipe `temp_air`. |
| Sensor rusak atau hilang | Kolomnya diisi `NULL`, dan tidak jelas apakah sensor membaca 0 atau tidak mengirim. | Barisnya tidak ada. Ketiadaan data terbaca jelas. |
| Quality flag dan kalibrasi per sensor | Butuh 7 pasang kolom flag dan 7 FK sensor. | Setiap baris sudah membawa `sensor_id` dan `quality_flags`. |
| Volume baris | 7× lebih sedikit (≈26 juta row/tahun). | 7× lebih banyak (≈184 juta row/tahun). |

Kelemahan format narrow ada di jumlah baris dan overhead metadata per baris. Kompresi dengan `segmentby = channel_id` mengurangi biaya ini karena baris-baris satu channel dipadatkan bersama. Imbalannya, skema database tidak perlu diubah saat payload berisi kombinasi sensor yang berbeda, saat sensor dicopot dan dipasang ulang, atau saat satu device punya dua sensor suhu.

---

### BAGIAN D: Skema Alur Data & Kasus Batas

#### D.1 Idempotensi: Device mengirim ulang payload yang sama (tidak menerima ACK). Bagaimana sistem memastikan data tidak dobel?
Ada dua constraint unik di database, dan insert memakai `ON CONFLICT DO NOTHING`:
1. `device_packets (device_id, time)`. Kunci dedup memakai timestamp device, bukan `seq`, karena `seq` bisa berulang setelah MCU reboot.
   ```sql
   INSERT INTO device_packets (...)
   ON CONFLICT (device_id, time) DO NOTHING
   RETURNING extract(epoch FROM time)::bigint AS ts;
   ```
2. `sensor_readings (channel_id, time)`. Hanya paket yang timestamp-nya dikembalikan `RETURNING` (artinya benar-benar baru) yang bacaannya disisipkan. Insert bacaan juga memakai `ON CONFLICT (channel_id, time) DO NOTHING` sebagai pengaman kedua.
3. Respons per item. Paket yang sudah ada dilaporkan dengan status `duplicate`. Firmware membaca status ini sebagai tanda server sudah punya datanya, lalu menghapus paket dari buffer lokal. Jika timestamp sama tetapi `seq` berbeda, respons menyertakan warning `TS_COLLISION`.

#### D.2 Data terlambat & tidak berurutan: Device offline 3 jam lalu mengirim 180 record sekaligus. Bagaimana ini diproses, dan apa efeknya pada agregat jam yang sudah terlanjur dihitung?
1. Penerimaan. 180 paket masuk lewat `/api/v1/ingest/telemetry/batch`. `PacketNormalizer` mengurutkannya berdasarkan `ts`, lalu `TelemetryIngestor` menyisipkannya dalam satu transaksi dengan multi-row insert (paket per 500, bacaan per 1.000).
2. Penandaan bucket. Untuk setiap bacaan yang tersimpan, awal jam UTC-nya dicatat ke antrian:
   ```sql
   INSERT INTO aggregate_refresh_queue (channel_id, bucket_start)
   VALUES (?, ?) ON CONFLICT DO NOTHING;
   ```
   Untuk sensor bertipe `counter` (`rain_counter`), jam berikutnya ($H+1$) ikut ditandai karena delta bacaan pertama di jam $H+1$ bergantung pada bacaan terakhir jam $H$.
3. Efek pada agregat. Perintah `aggregates:refresh` dijadwalkan tiap menit. Perintah ini mengambil antrian dengan `SELECT ... FOR UPDATE SKIP LOCKED`, menghitung ulang setiap bucket 1 jam yang tersentuh dari data mentah, lalu melakukan upsert (`ON CONFLICT (channel_id, bucket_interval, bucket_start) DO UPDATE`) ke `reading_aggregates`. Agregat harian WIB yang mencakup jam-jam tersebut ikut dihitung ulang. Agregat jam yang sebelumnya dihitung tanpa data offline itu terkoreksi pada putaran worker berikutnya.

#### D.3 Backpressure: Bagaimana jika 50 device mengirim bersamaan dan proses insert lebih lambat dari laju data?
Ada beberapa lapis penahan:
1. Bulk insert. Bacaan tidak disisipkan satu per satu. Tujuh bacaan dalam satu paket masuk dalam satu statement, dan batch besar dipecah per 1.000 baris. Jumlah round-trip ke database jauh lebih sedikit.
2. Batas proses PHP-FPM. Jumlah worker PHP-FPM dibatasi (`pm.max_children`). Saat semua worker sibuk, request menunggu sebentar di antrian Nginx.
3. Rate limiting. Jika batas per IP (300 request/menit) atau per device (30 request/menit) terlampaui, server langsung menjawab HTTP 429 dengan header `Retry-After`.
4. Buffer di firmware. Firmware menyimpan data di memori non-volatile dan mengirim ulang dengan exponential backoff setiap kali menerima respons selain 2xx.
5. Jalur skala berikutnya (belum diimplementasikan). Jika jumlah device naik ke ribuan, endpoint HTTP cukup menulis payload ke Redis Stream, lalu worker terpisah membaca stream dan memasukkannya ke TimescaleDB dengan `COPY`.

#### D.4 Perbedaan waktu: Bedakan `device_time` dan `server_time`. Yang mana yang jadi acuan time-series, dan bagaimana menangani clock drift device?
- `device_time` (`ts` di payload): waktu sensor mengambil sampel, menurut RTC di perangkat.
- `server_time` (`received_at`): waktu paket HTTP tiba di server.

Aturannya:
1. Time-series memakai `device_time`. Untuk paket yang tertahan offline berjam-jam, `server_time` hanya menunjukkan kapan jaringan pulih, bukan kapan cuaca diukur.
2. Penanganan clock drift:
   - Untuk telemetry live, server menghitung `server_time - device_time` dan menyimpannya sebagai `clock_offset_s` di tabel `devices`. Nilai ini tampil di panel health stasiun sehingga teknisi bisa melihat device yang jamnya melenceng.
   - Jika `device_time` lebih dari 5 menit di depan server (`ts > now + 300s`), data tetap disimpan dengan flag `QualityFlag::CLOCK_FUTURE`. Bacaan dengan flag apa pun tidak ikut dihitung dalam agregat, dan paket ini tidak dipakai untuk memperbarui status terkini device.
   - Jika `device_time` lebih tua dari `2020-01-01`, paket ditolak. Nilai seperti itu menandakan RTC kembali ke epoch 1970 setelah reset.

#### D.5 Timezone: Semua timestamp disimpan dalam UTC, ditampilkan dalam WIB. Tunjukkan di mana konversi dilakukan.
Database dan REST API sepenuhnya memakai UTC.
1. Penyimpanan. Semua kolom waktu bertipe `timestamptz`. Container backend berjalan dengan `APP_TIMEZONE=UTC` dan `PHP_DATE_TIMEZONE=UTC`.
2. Tampilan. Konversi ke WIB (`Asia/Jakarta`, UTC+7) hanya terjadi di `frontend/lib/time.ts`:
   ```ts
   const wibDateTime = new Intl.DateTimeFormat('id-ID', {
     timeZone: 'Asia/Jakarta',
     dateStyle: 'medium',
     timeStyle: 'short',
   });
   ```
   Fungsi `formatWib`, `formatWibTime`, dan `formatWibDate` dipakai di semua komponen yang menampilkan waktu.
3. Satu pengecualian di backend: batas hari untuk agregat `1d` dan endpoint `/readings/summary`. Hari kalender di Indonesia dimulai pukul 00:00 WIB, yaitu 17:00 UTC hari sebelumnya. `Time::wibDayStart()` menghitung batas ini supaya total hujan "hari ini" sesuai dengan tanggal lokal.

#### D.6 Kegagalan: Apa yang terjadi jika DB down saat payload masuk? Apakah data hilang?
1. Exception handler di `bootstrap/app.php` menangkap `PDOException`, `QueryException` dengan SQLSTATE kelas `08` (gagal koneksi), dan `RedisException`, lalu mengembalikan HTTP 503 `SERVICE_UNAVAILABLE` dengan header `Retry-After: 30`.
2. Transaksi di-rollback. Tidak ada data setengah jadi yang tersimpan.
3. Karena firmware tidak menerima respons 2xx, firmware tidak menghapus data dari buffer.
4. Data tidak hilang dan dikirim ulang setelah database pulih. Data baru bisa hilang jika buffer di mikrokontroler penuh sebelum server kembali normal.

---

### BAGIAN E: Desain REST API

#### E.1 Bagaimana Anda mencegah response `GET /api/v1/readings` membengkak ketika user meminta rentang 1 tahun?
`ReadingQueryService` punya tiga pengaman:
1. Batas jumlah titik (`readings.max_points = 2000`). Server menghitung $\text{points} = \lceil (\text{to} - \text{from}) / \text{interval\_seconds} \rceil$. Jika hasilnya lebih dari 2.000, server menjawab HTTP 422 `RANGE_TOO_LARGE` beserta interval yang aman:
   ```json
   {
     "error": {
       "code": "RANGE_TOO_LARGE",
       "message": "Rentang terlalu besar untuk interval 1h (8760 titik, maks 2000).",
       "details": [{ "field": "interval", "code": "RANGE_TOO_LARGE", "suggested_interval": "1d" }]
     }
   }
   ```
2. Interval otomatis. Jika `interval` tidak diisi, server memilih interval terhalus yang masih di bawah 2.000 titik: `1m` sampai sekitar 33 jam, `1h` sampai sekitar 83 hari, selebihnya `1d`. Rentang 1 tahun otomatis memakai `1d` (365 titik).
3. Format kolom. Respons tidak berupa array objek yang mengulang nama field di setiap titik. Isinya satu array `timestamps` dan satu array `values` per seri, jadi data harian setahun hanya berisi 365 timestamp dan 365 nilai per seri.

#### E.2 Autentikasi device vs autentikasi user dashboard: apakah memakai mekanisme yang sama? Jelaskan.
Tidak. Ancaman dan pola aksesnya berbeda, jadi mekanismenya juga berbeda:

| Aspek | Device (IoT) | User dashboard |
|---|---|---|
| Mekanisme | API key per device, dikirim sebagai Bearer token. | Token Laravel Sanctum. Proxy Next.js mengambil token dari cookie `wsm_token` dan meneruskannya sebagai Bearer. |
| Bentuk kredensial | String acak `wsk_` + 40 karakter (≈238 bit entropi), berumur panjang. | Email dan password yang dibuat manusia. |
| Penyimpanan hash | `HMAC-SHA256(key, pepper)`. Cepat diverifikasi di setiap request, dan pepper disimpan di environment, bukan di database. | Bcrypt (`BCRYPT_ROUNDS=12`), sengaja lambat supaya brute force offline terhadap password mahal. |
| Hak akses | Hanya bisa mengirim data untuk device-nya sendiri. Tidak bisa memanggil API manajemen. | Dirancang berbasis peran (`admin`, `operator`, `viewer`) untuk melihat data, merotasi key, dan mengelola sensor. |
| Siklus hidup | Rotasi dengan grace period 24 jam, supaya key lama tetap berlaku sampai firmware selesai diperbarui lewat OTA. | Token dicabut saat logout. |

Alasan hash-nya berbeda: API key sudah acak dengan entropi tinggi, jadi brute force tidak praktis dan hash cepat cukup aman. Password manusia mudah ditebak, jadi butuh hash yang lambat.

#### E.3 Rancang rate limiting untuk endpoint ingestion. Apa kuncinya (per device? per IP?) dan apa response-nya?
Ada dua lapis limiter berbasis Redis, dipasang di `routes/api.php` dengan urutan `throttle:ingest-ip`, `GuardIngestPayload`, `AuthenticateDevice`, `throttle:ingest-device`.

1. Sebelum autentikasi, kunci per IP: 300 request/menit. Lapis ini menahan banjir request sebelum server menghabiskan CPU untuk memverifikasi key.
2. Setelah autentikasi, kunci per `device.id` internal: 30 request/menit. Limiter ini sengaja dipasang setelah autentikasi. Jika dipasang sebelumnya, penyerang bisa mengirim request palsu berisi `device_id` stasiun lain dan menghabiskan kuota device asli. Kuota 30/menit masih menyisakan ruang untuk paket live, heartbeat, retry, dan flush batch.

Respons saat limit terlampaui:
```http
HTTP/1.1 429 Too Many Requests
Content-Type: application/json
Retry-After: 12
X-Request-Id: 01J9X2K5M...

{
  "error": {
    "code": "RATE_LIMITED",
    "message": "Terlalu banyak request, coba lagi dalam 12 detik.",
    "details": []
  },
  "request_id": "01J9X2K5M..."
}
```

---

### BAGIAN G: Visualisasi Data (Frontend)

#### G.1 Berapa titik data yang wajar dirender dalam satu chart? Bagaimana Anda menanganinya jika user memilih rentang 1 tahun?
- Jumlah wajar: sekitar 500 sampai 1.500 titik per seri.
- Alasannya: lebar area chart di desktop berkisar 1.000 sampai 1.400 piksel. Di atas 1.500 titik, beberapa titik jatuh di piksel yang sama. Mata tidak mendapat informasi tambahan, sementara browser tetap harus menggambar semuanya.
- Untuk rentang 1 tahun:
  1. Frontend meminta interval `1d`, atau mengosongkan `interval` supaya backend memilih sendiri. Hasilnya 365 titik.
  2. Jika rentang 1 tahun diminta dengan interval `1m` (525.600 titik), backend menolaknya dengan 422. Browser tidak pernah menerima ratusan ribu titik.
  3. Seri garis memakai `sampling: 'lttb'` (Largest-Triangle-Three-Buckets). Jika jumlah titik melebihi lebar piksel chart, misalnya di layar ponsel, ECharts menyaring titik tanpa menghilangkan puncak dan lembah.

  Catatan: preset rentang di dashboard saat ini adalah 24 jam, 7 hari, dan 30 hari (`frontend/lib/ranges.ts`). Preset 1 tahun belum ada di UI.

#### G.2 Bagaimana Anda menampilkan gap data (device offline 3 jam)? Garis putus, nol, atau interpolasi? Kenapa?
Gap ditampilkan sebagai garis yang terputus, dengan area abu-abu (`markArea`) di rentang yang kosong.
- `insertGaps()` di `frontend/lib/series.ts` memeriksa jarak dua titik berurutan. Jika $\Delta t > 1,5 \times \text{interval\_seconds}$, fungsi ini menyisipkan `null` di antaranya.
- Seri garis memakai `connectNulls: false`, jadi garis berhenti di titik `null`.
- Di bar chart curah hujan, gap tampil sebagai area tanpa batang, bukan batang setinggi 0.

Kenapa bukan nol atau interpolasi:
1. Nol adalah hasil ukur yang sah (suhu 0 °C, atau tidak ada hujan). Mengisi periode offline dengan 0 berarti mengklaim pengukuran yang tidak pernah terjadi.
2. Interpolasi garis lurus membuat angka yang tidak pernah diukur dan menyembunyikan fakta bahwa stasiun sempat mati.
3. Garis putus dengan area abu-abu menunjukkan kapan dan berapa lama stasiun tidak mengirim data, dan itu informasi yang dibutuhkan operator.

---

## Bagian 2: Jawaban Soal Esai Singkat (Nomor 1–8)

### Esai 1: Kenapa data time-series sebaiknya tidak di-UPDATE, dan lebih baik append-only?
Pembacaan sensor mencatat kejadian di masa lalu. Jika suhu terukur 27,4 °C pukul 10:00, angka itu tidak akan berubah menjadi 28 °C di kemudian hari. Di PostgreSQL, `UPDATE` tidak menimpa baris di tempat. Karena MVCC, PostgreSQL menulis baris baru dan menandai baris lama sebagai dead tuple. Pada tabel ratusan juta baris, update massal menyebabkan tabel dan index membengkak, lalu memberi beban I/O besar pada `VACUUM`. Chunk lama di TimescaleDB juga sudah dikompresi ke format kolom, dan mengubah beberapa baris di sana berarti mendekompresi chunk itu dulu. Dengan append-only, insert bisa dibuat idempoten, penulisan tidak saling mengunci baris, dan setiap nilai turunan (nilai terkalibrasi, agregat) bisa diaudit dan dihitung ulang dari `raw_value`.

### Esai 2: Apa itu hypertable dan continuous aggregate di TimescaleDB? Kalau Anda hanya pakai PostgreSQL biasa, bagaimana Anda mencapai efek yang sama?
Hypertable adalah tabel TimescaleDB yang otomatis dipecah menjadi partisi fisik berdasarkan rentang waktu (chunk). Query ditulis seperti ke satu tabel biasa, tetapi database hanya membaca chunk yang relevan. Continuous aggregate adalah materialized view yang diperbarui secara inkremental: hanya rentang waktu yang datanya berubah yang dihitung ulang. Fitur real-time aggregation bisa menggabungkan hasil materialisasi dengan data mentah terbaru yang belum ter-materialisasi. Di PostgreSQL biasa, efek hypertable bisa dicapai dengan declarative partitioning (`PARTITION BY RANGE (time)`), dengan `pg_partman` untuk membuat partisi baru otomatis dan `DROP TABLE` partisi lama untuk retensi. Pengganti continuous aggregate adalah tabel ringkasan yang di-upsert oleh worker terjadwal berdasarkan antrian bucket yang berubah, seperti `aggregate_refresh_queue` di proyek ini, atau `REFRESH MATERIALIZED VIEW CONCURRENTLY` jika menghitung ulang seluruh view masih bisa diterima.

### Esai 3: Jelaskan perbedaan menghitung rata-rata arah angin dengan rata-rata suhu. Bagaimana cara yang benar?
Suhu berada di skala linear, jadi rata-rata aritmetika $(\sum T / n)$ sudah benar. Arah angin berada di skala melingkar: $0^\circ$ dan $360^\circ$ sama-sama berarti utara. Rata-rata aritmetika dari $350^\circ$ dan $10^\circ$ menghasilkan $(350 + 10) / 2 = 180^\circ$, yaitu selatan, padahal kedua angin datang dari sekitar utara. Cara yang benar adalah rata-rata vektor. Setiap sudut $\theta_i$ diubah menjadi vektor satuan $x_i = \cos(\theta_i)$ dan $y_i = \sin(\theta_i)$. Komponennya dirata-ratakan terpisah: $\bar{x} = \frac{1}{n}\sum \cos(\theta_i)$ dan $\bar{y} = \frac{1}{n}\sum \sin(\theta_i)$. Arah rata-ratanya $\theta_{\text{avg}} = \text{atan2}(\bar{y}, \bar{x})$, dinormalisasi ke $0^\circ \le \theta < 360^\circ$. Jika panjang resultan $R = \sqrt{\bar{x}^2 + \bar{y}^2}$ mendekati nol, arah angin tersebar ke segala arah dan arah rata-ratanya dianggap tidak terdefinisi (`null`). Di proyek ini, `reading_aggregates` menyimpan `sum_x` dan `sum_y` per jam, sehingga agregat harian dihitung dari jumlah vektor, bukan dari rata-rata sudut per jam.

### Esai 4: Data masuk 50 device × 7 sensor tiap menit. Bandingkan insert satu per satu vs bulk insert/batching. Kira-kira berapa besar bedanya dan kenapa?
Insert satu per satu membayar biaya tetap untuk setiap baris: satu round-trip jaringan, parsing dan perencanaan query, serta commit dan flush WAL jika tiap insert berjalan di transaksinya sendiri. Bulk insert menggabungkan banyak baris dalam satu statement (`INSERT INTO ... VALUES (...), (...), ...`), sehingga biaya tetap itu dibayar sekali per batch. Pada laju normal 50 device (350 bacaan per menit, sekitar 6 baris per detik), kedua cara masih tertangani. Perbedaannya terasa saat beberapa device yang sempat offline mengirim buffer bersamaan. Contoh: 10 device masing-masing mengirim 180 paket berisi 7 bacaan, total 12.600 baris. Dengan insert satu per satu, itu berarti 12.600 statement dan 12.600 round-trip. Dengan chunk 1.000 baris seperti di `TelemetryIngestor`, cukup 13 statement. Selisih jumlah round-trip sekitar 1.000 kali lipat. Selisih waktu totalnya lebih kecil karena menulis data tetap butuh waktu, tetapi tetap bisa mencapai beberapa orde besaran. Angka pastinya belum saya ukur di proyek ini. Tanpa batching, request batch yang lama memegang koneksi database lebih lama, connection pool cepat habis, dan request lain mulai timeout.

### Esai 5: Index apa yang Anda buat di tabel `sensor_readings`, dan urutan kolomnya bagaimana? Kenapa urutan itu penting?
Hanya satu index: unique index komposit `(channel_id, time)`. Index ini sekaligus menjadi kunci dedup, melayani query chart, dan dipakai untuk mencari nilai terkini. Urutan kolomnya penting karena B-tree hanya efektif untuk prefix paling kiri. Kolom yang difilter dengan kesamaan (`channel_id = ?`) harus di depan, lalu kolom yang difilter dengan rentang (`time >= ? AND time < ?`) di belakangnya. Dengan `(channel_id, time)`, semua entri satu channel berdampingan di leaf B-tree dan sudah urut waktu, sehingga data chart satu sensor dibaca dalam satu range scan. Jika urutannya dibalik menjadi `(time, channel_id)`, query seminggu untuk satu sensor harus melewati entri dari 350 channel lain dalam rentang waktu yang sama lalu membuang sebagian besar hasilnya, sehingga I/O yang dibutuhkan naik berkali lipat.

### Esai 6: Bagaimana Anda mendeteksi sensor yang "macet": mengirim data terus tapi nilainya identik selama 6 jam?
Fitur ini belum diimplementasikan di repositori. Rancangan saya:

Job terjadwal memeriksa `reading_aggregates` dengan `bucket_interval = '1h'`, bukan tabel mentah per menit. Satu channel dianggap macet jika enam bucket jam berturut-turut memenuhi `min_value = max_value` dengan nilai yang sama di keenam bucket, dan setiap bucket punya cukup sampel valid (misalnya `good_count` minimal 50 dari 60) supaya jam yang sebagian besar kosong tidak ikut terhitung. Aturan ini perlu disesuaikan per tipe sensor. `rain_counter` yang konstan saat tidak hujan adalah normal. `solar_rad` bernilai 0 W/m² sepanjang malam juga normal. Arah angin bisa tetap saat udara tenang. Pemeriksaan ini paling berguna untuk sensor yang secara fisik selalu berfluktuasi, seperti `temp_air`, `humidity`, dan `pressure`. Jika kriteria terpenuhi, job membuat entri peringatan di status operasional device untuk ditinjau teknisi. Data historis tidak diubah.

### Esai 7: Ada permintaan menambah alert: kirim notifikasi jika curah hujan > 20 mm/jam. Di lapisan mana Anda menaruh logika ini, dan kenapa di situ?
Logika ini saya taruh di worker agregasi (`aggregates:refresh`), dijalankan setelah bucket 1 jam selesai dihitung ulang. Tidak di API ingestion, dan tidak di frontend. API ingestion harus tetap ringan, dan satu request hanya membawa sebagian data, padahal curah hujan per jam butuh selisih counter sepanjang satu jam. Frontend jelas salah tempat karena alert hanya berjalan jika ada orang yang sedang membuka dashboard. Di worker agregasi, selisih `rain_counter` sudah dihitung oleh `RainCalculator` termasuk penanganan counter yang reset saat device restart, dan bucket yang menerima data terlambat juga sudah dihitung ulang. Dari situ worker membandingkan total mm per jam dengan aturan alert, mencegah notifikasi ganda untuk bucket yang sama (misalnya dengan unique key `(rule_id, channel_id, bucket_start)`), lalu mencatat riwayat alert. Fitur alert ini belum diimplementasikan di repositori.

### Esai 8: Apa saja risiko keamanan pada endpoint ingestion yang terbuka ke internet, dan bagaimana mitigasinya?
1. Pencurian atau tebakan kredensial device. Mitigasi: key acak `wsk_` + 40 karakter (≈238 bit), disimpan sebagai `HMAC-SHA256` dengan pepper rahasia di server. Setiap kegagalan autentikasi dicatat di log beserta IP, dan rate limit per IP membatasi jumlah percobaan.
2. Spoofing dan replay. Penyerang menyadap lalu mengirim ulang payload sebuah stasiun. Mitigasi: wajib HTTPS di seluruh jalur, dan dedup `(device_id, time)` di database membuat paket yang di-replay hanya dicatat sebagai `duplicate`.
3. DoS dan pemakaian resource berlebihan. Penyerang mengirim payload raksasa atau batch tanpa batas. Mitigasi: `GuardIngestPayload` menolak body di atas 1 MB, batch dibatasi 500 item (`ingest.max_batch`), dan rate limit dipasang dua lapis (per IP sebelum autentikasi, per device setelahnya).
4. Data palsu atau nilai ekstrem. Mitigasi: validasi skema di FormRequest, parameter binding di semua query SQL untuk mencegah injection, dan quality flag (`OUT_OF_RANGE`, `SENSOR_ERROR`) supaya nilai ekstrem tetap tersimpan untuk audit tetapi tidak ikut dihitung dalam agregat.
