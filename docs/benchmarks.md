# Multisite Radar — banc de performance

Mesures de `bin/bench.sh` (`make bench`, spec §11.3), à refaire avant chaque version.

- Critère de la spec §1.4 n° 2 : `GET /sites` en moins de 300 ms au p95 sur 5 000 sites, et aucune requête d'analyse de plus de 20 s.
- Les routes sont mesurées dans le processus (`rest_do_request`) : cache objet vidé avant chaque appel, sans le chargement de WordPress (écart E1 du plan M7). Chaque route est appelée 20 fois, après un premier appel non compté.

Lancement :

```bash
BENCH_DB_NAME=… BENCH_DB_USER=… BENCH_DB_PASSWORD=… BENCH_DB_HOST=… BENCH_SITES=5000 make bench
```

## 2026-10-05 — 2.0.0-beta.6

### 1 000 sites

- Sites: 1000 plus the main site, created in 44.3 s.
- Full scan (wp multisite-radar scan --all): 15.6 s, 64.1 sites/s.
- WordPress 7.1.2, PHP 8.2.34, database 11.8.9-MariaDB-ubu2404.
- Machine: aarch64, 4 CPU.

| Route | Median (ms) | p95 (ms) | Max (ms) |
|---|---:|---:|---:|
| GET /sites | 2.4 | 2.6 | 2.7 |
| GET /sites?search=Bench site 42 | 2.9 | 3.0 | 3.1 |
| GET /sites?orderby=content_count&order=desc | 2.2 | 2.4 | 2.4 |
| GET /sites?theme=twentytwentyfive&page=10 | 2.3 | 2.4 | 2.5 |
| GET /alerts/summary | 3.5 | 3.7 | 3.7 |
| GET /inventory/summary | 3.4 | 3.6 | 3.6 |
| GET /scan/status | 2.0 | 2.2 | 2.2 |

- One interface batch (POST /scan/batch): 522 site(s) in 8.0 s (limit: 20 s).

### 5 000 sites

- Sites: 5000 plus the main site, created in 254.2 s.
- Full scan (wp multisite-radar scan --all): 250.3 s, 20.0 sites/s.
- WordPress 7.1.2, PHP 8.2.34, database 11.8.9-MariaDB-ubu2404.
- Machine: aarch64, 4 CPU.

| Route | Median (ms) | p95 (ms) | Max (ms) |
|---|---:|---:|---:|
| GET /sites | 8.3 | 8.8 | 8.9 |
| GET /sites?search=Bench site 42 | 11.4 | 12.6 | 17.7 |
| GET /sites?orderby=content_count&order=desc | 7.3 | 7.4 | 7.4 |
| GET /sites?theme=twentytwentyfive&page=10 | 8.5 | 8.7 | 9.6 |
| GET /alerts/summary | 11.2 | 11.7 | 21.8 |
| GET /inventory/summary | 8.7 | 9.1 | 10.1 |
| GET /scan/status | 5.5 | 5.6 | 5.6 |

- One interface batch (POST /scan/batch): 171 site(s) in 8.0 s (limit: 20 s).
