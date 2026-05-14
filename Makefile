.PHONY: extension extension-clean clean test test-phpt test-laravel smoke smoke-laravel proto-gen trace-clean install uninstall help

PERISCOPE_TRACE_DIR ?= /tmp/periscope

PHP_CONFIG ?= $(shell which php-config)
CAPNP      ?= $(shell which capnp)

help:
	@echo "Targets:"
	@echo "  make extension        Build the C extension (.so/.dylib)"
	@echo "  make proto-gen        Regenerate Cap'n Proto C++ from proto/trace.capnp"
	@echo "  make extension-clean  Remove build artifacts in extension/"
	@echo "  make clean            Full clean"
	@echo "  make test             Run the test suite"
	@echo "  make trace-clean      Delete all .cptrace files in PERISCOPE_TRACE_DIR ($(PERISCOPE_TRACE_DIR))"
	@echo "  make install          One-shot install — build + drop the extension and daemon binaries"
	@echo "  make uninstall        Reverse make install"

install:
	@bash scripts/install.sh $(INSTALL_FLAGS)

uninstall:
	@bash scripts/uninstall.sh $(UNINSTALL_FLAGS)

proto-gen:
	@if [ -z "$(CAPNP)" ]; then \
	  echo "ERROR: capnp not found in PATH. Install via 'brew install capnp'." >&2; exit 1; \
	fi
	$(CAPNP) compile -oc++:extension --src-prefix=proto proto/trace.capnp
	@# capnp emits trace.capnp.c++ which trips up libtool object naming; rename to .cpp
	@mv extension/trace.capnp.c++ extension/trace.capnp.cpp
	@echo "Generated: extension/trace.capnp.h, extension/trace.capnp.cpp"

extension: proto-gen
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
	cd extension && NO_INTERACTION=1 REPORT_EXIT_STATUS=1 $(MAKE) test TESTS="-d periscope.verbose=1 tests/"
	@bash scripts/smoke.sh

test-laravel:
	@bash scripts/smoke-laravel-adapter.sh

smoke-laravel: test-laravel

trace-clean:
	@if [ -d "$(PERISCOPE_TRACE_DIR)" ]; then \
	  count=$$(find "$(PERISCOPE_TRACE_DIR)" -maxdepth 1 -name '*.cptrace' | wc -l | tr -d ' '); \
	  find "$(PERISCOPE_TRACE_DIR)" -maxdepth 1 -name '*.cptrace' -delete; \
	  echo "removed $$count trace(s) from $(PERISCOPE_TRACE_DIR)"; \
	else \
	  echo "no trace dir at $(PERISCOPE_TRACE_DIR)"; \
	fi
