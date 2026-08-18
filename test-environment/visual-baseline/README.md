# Multi-viewport visual test environment

This laboratory enforces the agreed real-view matrix: 360×800, 430×932, 768×1024, 1440×1000, and 1920×1080 across home, shop, product, cart, checkout, and account. It captures viewport, full-page, and required component screenshots; responsive hashes; console/page errors; failed/HTTP-error requests; broken images; overflow; and five cold plus five warm performance samples with medians.

The runner is read-only by default and blocks every request method except GET, HEAD, and OPTIONS. Use a disposable staging clone with representative data. Never point an authenticated configuration at production checkout or account flows.

Install the pinned Python dependencies in the test environment before the first run:

```powershell
python -m pip install --requirement .\test-environment\visual-baseline\requirements.txt
```

The runner may use an existing Chrome/Chromium executable through `--chromium`; it does not require downloading another browser.

## Prove the laboratory itself

The bundled fixture is deterministic and is not evidence about the real site:

```powershell
python .\test-environment\visual-baseline\visual-matrix.py capture `
  --config .\test-environment\visual-baseline\config.fixture.json `
  --output .\test-environment\visual-baseline\artifacts\fixture-baseline

python .\test-environment\visual-baseline\visual-matrix.py capture `
  --config .\test-environment\visual-baseline\config.fixture.json `
  --output .\test-environment\visual-baseline\artifacts\fixture-candidate

python .\test-environment\visual-baseline\visual-matrix.py compare `
  --config .\test-environment\visual-baseline\config.fixture.json `
  --baseline .\test-environment\visual-baseline\artifacts\fixture-baseline `
  --candidate .\test-environment\visual-baseline\artifacts\fixture-candidate
```

## Real before/after gate

Copy `config.staging.example.json`, set the actual staging origin, product slug, and selectors, then capture `before`. No rendering change is authorized until that manifest is complete and green. After the change, capture `after` with the exact same config and compare it against `before`.

LCP, CLS, FCP, TTFB, load timing, transfer size, and DOM size are sampled. INP is deliberately reported as unmeasured because a read-only screenshot pass does not perform a representative interaction; Core Web Vitals must not be presented as complete when INP lacks a valid interaction trace.

Live WordPress, WooCommerce session state, Browser Agent pairing, OAuth, and production CWV remain pending until the runner is executed against the real test installation. The fixture never makes those gates pass.
