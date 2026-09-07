"""Record observed suite results; missing suites and build failures remain visible."""
from pathlib import Path
import json
import os

root = Path(__file__).resolve().parents[1]
results = {"sourceCommit": os.environ.get("GITHUB_SHA", "local"), "runId": os.environ.get("GITHUB_RUN_ID"), "go": {"executed": False}, "web": {"executed": False}, "browser": {"executed": False}}
path = root / "go-test.jsonl"
if path.exists():
    events = [json.loads(line) for line in path.read_text().splitlines() if line.startswith("{")]
    failures = sorted({e.get("Package", "unknown") for e in events if e.get("Action") == "fail" and "Test" not in e})
    build_failures = sorted({e.get("ImportPath", "unknown") for e in events if e.get("Action") == "build-fail"})
    results["go"] = {
        "executed": True,
        "passedTopLevelTests": sum(e.get("Action") == "pass" and "Test" in e and "/" not in e["Test"] for e in events),
        "passedTestsIncludingSubtests": sum(e.get("Action") == "pass" and "Test" in e for e in events),
        "failedTestsIncludingSubtests": sum(e.get("Action") == "fail" and "Test" in e for e in events),
        "failedPackages": failures, "buildFailures": build_failures, "raceDetector": True,
    }
path = root / "web-test.json"
if path.exists():
    obj = json.loads(path.read_text())
    results["web"] = {"executed": True, **{k: obj.get(k) for k in ("numPassedTests", "numFailedTests", "numPendingTests", "success")}}
path = root / "test-results/browser.json"
if path.exists():
    obj = json.loads(path.read_text())
    results["browser"] = {"executed": True, **obj.get("stats", {})}
path = root / "npm-audit.json"
if path.exists():
    results["npmAudit"] = json.loads(path.read_text()).get("metadata", {}).get("vulnerabilities", {})
(root / "validation.json").write_text(json.dumps(results, indent=2) + "\n")
print(json.dumps(results, indent=2))
