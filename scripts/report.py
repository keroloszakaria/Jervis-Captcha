"""Create factual, machine-readable validation evidence from completed commands."""
from pathlib import Path
import json
import os

root = Path(__file__).resolve().parents[1]
results = {"sourceCommit": os.environ.get("GITHUB_SHA", "local"), "runId": os.environ.get("GITHUB_RUN_ID"), "go": {}, "web": {}, "browser": {}}
path = root / "go-test.jsonl"
if path.exists():
    events = [json.loads(line) for line in path.read_text().splitlines() if line.startswith("{")]
    results["go"] = {"passedTestsIncludingSubtests": sum(e.get("Action") == "pass" and "Test" in e for e in events), "failedTestsIncludingSubtests": sum(e.get("Action") == "fail" and "Test" in e for e in events), "raceDetector": True}
path = root / "web-test.json"
if path.exists():
    obj = json.loads(path.read_text())
    results["web"] = {k: obj.get(k) for k in ("numPassedTests", "numFailedTests", "numPendingTests", "success")}
path = root / "test-results/browser.json"
if path.exists():
    obj = json.loads(path.read_text())
    results["browser"] = obj.get("stats", {})
(root / "validation.json").write_text(json.dumps(results, indent=2) + "\n")
print(json.dumps(results, indent=2))
