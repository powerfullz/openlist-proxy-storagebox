PHP ?= php

.PHONY: test lint package

test:
	$(PHP) tests/run.php

lint:
	$(PHP) -l dl.php
	$(PHP) -l bootstrap.php
	$(PHP) -l config.sample.php
	find src scripts tests -name '*.php' -print0 | xargs -0 -n1 $(PHP) -l

package:
	./scripts/package.sh
