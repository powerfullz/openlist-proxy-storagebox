PHP ?= php

.PHONY: test lint package

test:
	$(PHP) public/tests/run.php

lint:
	find public scripts -name '*.php' -print0 | xargs -0 -n1 $(PHP) -l

package:
	./scripts/package.sh
