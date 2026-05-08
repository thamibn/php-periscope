.PHONY: extension extension-clean clean test test-phpt smoke help

PHP_CONFIG ?= $(shell which php-config)

help:
	@echo "Targets:"
	@echo "  make extension        Build the C extension (.so/.dylib)"
	@echo "  make extension-clean  Remove build artifacts in extension/"
	@echo "  make clean            Full clean"
	@echo "  make test             Run the test suite (placeholder)"

extension:
	cd extension && phpize --clean >/dev/null 2>&1 || true
	cd extension && phpize
	cd extension && ./configure --enable-periscope --with-php-config=$(PHP_CONFIG)
	cd extension && $(MAKE)
	@echo ""
	@echo "Built: extension/modules/periscope.so"
	@echo "Try:  php -d extension=$$(pwd)/extension/modules/periscope.so -m | grep periscope"

extension-clean:
	cd extension && $(MAKE) clean 2>/dev/null || true
	cd extension && phpize --clean 2>/dev/null || true

clean: extension-clean

test:
	cd extension && NO_INTERACTION=1 REPORT_EXIT_STATUS=1 $(MAKE) test TESTS="tests/"
	@bash scripts/smoke.sh
