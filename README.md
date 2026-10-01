# Multisite Radar

Audit réseau pour WordPress Multisite (successeur de Network Plugin Utilities 1.x).

- Spec : `docs/superpowers/specs/2026-10-01-multisite-radar-v2-design.md`
- Plans : `docs/superpowers/plans/`

## Développement

```bash
composer install
bin/test-db.sh        # une fois : crée la base wordpress_test
bin/test.sh           # tests PHPUnit (multisite)
composer lint         # WPCS
composer analyse      # PHPStan niveau 6
```
