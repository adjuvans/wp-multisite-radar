# Raccourcis de développement de Multisite Radar. « make » seul affiche l'aide.

SLUG     := multisite-radar
VERSION   = $(shell sed -n 's/^ \* Version: *//p' $(SLUG).php)
DIST_DIR := dist
ZIP       = $(SLUG)-$(VERSION).zip

# Entrées autorisées à la racine du paquet ; toute autre entrée fait échouer « make dist ».
DIST_ALLOWED := LICENSE build includes languages $(SLUG).php readme.txt uninstall.php

# Port wp-env des tests de bout en bout : make e2e WP_ENV_PORT=8890 si 8888 est occupé.
WP_ENV_PORT       ?= 8888
WP_ENV_TESTS_PORT ?= $(shell echo $$(( $(WP_ENV_PORT) + 1 )))
WP_BASE_URL       ?= http://localhost:$(WP_ENV_PORT)
export WP_ENV_PORT WP_ENV_TESTS_PORT WP_BASE_URL

.DEFAULT_GOAL := help
.PHONY: help install build i18n dist plugin-check deploy-test deploy-prod lint test check e2e e2e-stop bench version clean

help: ## Affiche cette aide
	@grep -E '^[a-z0-9-]+:.*## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*## "} {printf "  %-12s %s\n", $$1, $$2}'

node_modules: package.json package-lock.json
	npm ci
	@touch $@

vendor: composer.json
	composer install
	@touch $@

install: node_modules vendor ## Installe les dépendances npm et Composer

build: node_modules ## Compile l'interface dans build/
	npm run build

i18n: build ## Régénère le catalogue de traductions, vérifie le français et le compile (WP-CLI)
	bin/i18n.sh

dist: node_modules ## Produit dist/multisite-radar-<version>.zip, traductions comprises, prêt à installer
	npm run version:check
	npm run build
	bin/i18n.sh
	rm -rf $(DIST_DIR)
	mkdir -p $(DIST_DIR)/$(SLUG)
	rsync -a --exclude-from=.distignore ./ $(DIST_DIR)/$(SLUG)/
	@extra=$$(ls -A $(DIST_DIR)/$(SLUG) | grep -vxF $(addprefix -e ,$(DIST_ALLOWED))); \
	if [ -n "$$extra" ]; then \
		echo "Entrées inattendues dans le paquet (à exclure dans .distignore) :" >&2; \
		echo "$$extra" >&2; \
		exit 1; \
	fi
	cd $(DIST_DIR) && if command -v zip >/dev/null 2>&1; then zip -qr $(ZIP) $(SLUG); else python3 -m zipfile -c $(ZIP) $(SLUG); fi
	@echo "Paquet : $(DIST_DIR)/$(ZIP)"

plugin-check: build ## Plugin Check sur les fichiers du paquet, dans wp-env (démarré) ; échoue sur toute erreur ou tout avertissement
	rm -rf $(DIST_DIR)/plugin-check
	mkdir -p $(DIST_DIR)/plugin-check/$(SLUG)
	rsync -a --exclude-from=.distignore ./ $(DIST_DIR)/plugin-check/$(SLUG)/
	npm run --silent wp-env -- run tests-cli wp plugin install plugin-check --activate
	npm run --silent wp-env -- run tests-cli wp plugin check wp-content/plugins/$(SLUG)/$(DIST_DIR)/plugin-check/$(SLUG) \
		--format=csv --fields=file,line,type,code,message > $(DIST_DIR)/plugin-check.csv || true
	node bin/plugin-check-report.mjs $(DIST_DIR)/plugin-check.csv

.env:
	@echo "Fichier .env absent : cp .env.example .env, puis le remplir." >&2; exit 1

deploy-test: .env dist ## Envoie le plugin sur le serveur de test (FTP, .env) ; DRY_RUN=1 pour simuler
	bin/deploy.sh test $(if $(DRY_RUN),--dry-run)

deploy-prod: .env dist ## Envoie le plugin en production, après confirmation ; DRY_RUN=1 pour simuler
	bin/deploy.sh prod $(if $(DRY_RUN),--dry-run)

lint: node_modules vendor ## PHPCS, PHPStan, ESLint, Stylelint et cohérence des versions
	composer lint
	composer analyse
	npm run lint:js
	npm run lint:css
	npm run version:check
	npm run readme:check

test: node_modules vendor ## Tests PHPUnit (base locale) et Vitest
	bin/test.sh
	npm run test:unit

check: lint test ## Lint et tests, avant un commit ou une publication

e2e: build ## Tests de bout en bout sur wp-env (Docker), laisse wp-env démarré
	npm run wp-env -- start
	npm run e2e:setup
	npm run test:e2e

e2e-stop: node_modules ## Arrête wp-env
	npm run wp-env -- stop

bench: build ## Banc de performance sur un multisite jetable (BENCH_DB_* requis ; BENCH_SITES=1000 par défaut)
	bin/bench.sh

version: node_modules ## Change la version partout : make version VERSION=x.y.z
	@test -n "$(filter command line,$(origin VERSION))" || { echo "Usage : make version VERSION=x.y.z" >&2; exit 1; }
	npm run version:set -- $(VERSION)
	npm install --package-lock-only --ignore-scripts
	npm run version:check

clean: ## Supprime build/ et dist/
	rm -rf build $(DIST_DIR)
