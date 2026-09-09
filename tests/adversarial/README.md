# Adversarial text benchmark

This directory measures the **visual text layer only**. It does not prove that a CAPTCHA is human-only or AI-proof. `isAntiAI` also enables other protection such as proof-of-work, which is outside this OCR score.

## Corpus

Generate paired standard / `isAntiAI` samples plus four preprocessing variants:

```powershell
php tests/adversarial/GenerateTextCorpus.php 50
```

The generated `.adversarial-text-corpus/` directory is ignored by Git. `manifest.json` contains expected answers and is evaluator-only; `solver-manifest.json` carries the same random `corpusId` plus profile/variant/path metadata **without answers** and is the only manifest that should be supplied to solver adapters. Never ship either benchmark corpus or prediction output with a runtime package.

Variants currently cover:

- original PNG
- 2x resampled PNG
- JPEG quality 65
- grayscale + contrast adjustment

Random seeds are intentionally not fixed: repeated benchmark runs should use fresh challenges rather than overfitting one corpus.

## Windows OCR adapter

On Windows with the `en-US` OCR language installed:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File tests/adversarial/WindowsOcrBenchmark.ps1
php tests/adversarial/EvaluateTextSolver.php `
  .adversarial-text-corpus/manifest.json `
  .adversarial-text-corpus/windows-ocr-predictions.json
```

`EvaluateTextSolver.php` is solver-neutral and now validates **exact corpus coverage** before calculating a score: missing, duplicate, unknown or profile/variant-mismatched predictions fail closed instead of producing a partial benchmark.

## Local command adapters and multi-solver comparison

A second local OCR/VLM can be wrapped without adding it to Jervis runtime dependencies. The adapter config is JSON and the command is executed as an argv array (no shell interpolation). Exactly one plain-text prediction must be written to stdout for each input image; diagnostics belong on stderr. Solver children receive only a small operational environment allowlist (`PATH`, OS/temp/home/locale variables) plus any explicitly declared config `environment`; arbitrary parent secrets are not inherited. Per-image execution is time-bounded and stdout/stderr capture is size-bounded.

```powershell
php tests/adversarial/CommandTextSolver.php `
  .adversarial-text-corpus/solver-manifest.json `
  tests/adversarial/tesseract.example.json `
  .adversarial-text-corpus/tesseract-predictions.json
```

The Tesseract file is an **example configuration only**; Tesseract is not bundled, downloaded or required by Jervis Captcha. Local model/VLM tools can use the same protocol through a small wrapper CLI that accepts `{input}` and prints only its prediction.

### Local LM Studio vision adapter

`LmStudioVisionSolver.php` talks only to LM Studio's native v1 API on the numeric loopback addresses `127.0.0.1` or `::1`. DNS names (including `localhost`), remote endpoints, HTTPS URLs, redirects and manifests containing expected answers are rejected. The request sets `reasoning: off`, uses a fixed low-token OCR prompt, and bounds image size, response size and request time. LM Studio and the chosen VLM are development tools only; neither is a Jervis Captcha runtime dependency.

```powershell
php tests/adversarial/LmStudioVisionSolver.php `
  .adversarial-text-corpus/solver-manifest.json `
  .adversarial-text-corpus/lmstudio-predictions.json `
  <loaded-model-id> `
  http://127.0.0.1:1234
```

Evaluate the VLM output with the same private `manifest.json`, then include it in `CompareTextSolvers.php`. A small VLM smoke corpus can be useful during development, but it must be reported as a smaller cohort rather than silently compared with a larger OCR corpus.

Compare two or more complete solver outputs against the same corpus:

```powershell
php tests/adversarial/CompareTextSolvers.php `
  .adversarial-text-corpus/manifest.json `
  .adversarial-text-corpus/windows-ocr-predictions.json `
  .adversarial-text-corpus/tesseract-predictions.json `
  --output=.adversarial-text-corpus/multi-solver-report.json
```

The comparison reports the **attacker-best solver and preprocessing variant per profile**. Interpret the highest exact rate across solvers/variants as the more defensive metric: an attacker can choose both. Character accuracy is diagnostic only; the protected-action bypass rate is the ultimate security metric.

A single solver result must never be converted into an `AI-proof` claim. Before promoting a visual profile, add multiple OCR/VLM families plus human solve-success, solve-time, retry, abandonment and accessibility measurements.
