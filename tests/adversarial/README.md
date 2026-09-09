# Adversarial text benchmark

This directory measures the **visual text layer only**. It does not prove that a CAPTCHA is human-only or AI-proof. `isAntiAI` also enables other protection such as proof-of-work, which is outside this OCR score.

## Corpus

Generate paired standard / `isAntiAI` samples plus four preprocessing variants:

```powershell
php tests/adversarial/GenerateTextCorpus.php 50
```

The generated `.adversarial-text-corpus/` directory is ignored by Git because its manifest contains expected answers. Never ship that corpus with a runtime package.

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

`EvaluateTextSolver.php` is solver-neutral. Additional OCR/VLM adapters should emit the same prediction shape (`path`, `prediction`, optional `durationMs`) and reuse this evaluator.

Interpret the **highest exact rate across preprocessing variants** as the more defensive metric: an attacker can choose preprocessing. Character accuracy is diagnostic only; the protected-action bypass rate is the ultimate security metric.

A single solver result must never be converted into an `AI-proof` claim. Before promoting a visual profile, add multiple OCR/VLM families plus human solve-success, solve-time, retry, abandonment and accessibility measurements.
